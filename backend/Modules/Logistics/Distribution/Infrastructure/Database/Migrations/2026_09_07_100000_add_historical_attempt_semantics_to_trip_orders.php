<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-ECOS-POST-DRIVER-RETURN-WAREHOUSE-RETURNS-FINAL-IMPLEMENTATION-002 §7.
 *
 * Evolves `distribution_trip_orders` from "one Trip per Order, ever" (a raw
 * single-column UNIQUE(order_id)) to "many historical rows per Order, at most
 * one ACTIVE at a time" — the CTO-approved invariant (architecture report §25):
 *
 *   A. One Order may have MULTIPLE historical Trip associations over time.
 *   B. One Order may have AT MOST ONE ACTIVE delivery execution at a time.
 *   C. Previous dispatched Trip/Order associations are NEVER deleted to make a
 *      retry possible — TripService::releaseOrder() supersedes, never deletes.
 *
 * WHY A GENERATED COLUMN, NOT A PARTIAL UNIQUE INDEX. MySQL has no `WHERE`
 * clause on a UNIQUE INDEX (unlike PostgreSQL). This codebase already hit the
 * identical shape of problem for the single-active-custody invariant
 * (TripService::assertDriverHasNoOtherOpenCustody) and rejected a partial
 * index there too, in favour of an application-level lock — but that
 * invariant has no "zero rows yet" case (a driver's pairing rows always
 * exist). Here the very FIRST assignment for an order also starts from zero
 * rows, so an app-only lock cannot close the race (nothing exists yet to
 * lock). A generated column collapses every superseded row's key to NULL —
 * and SQL treats NULL as never equal to NULL for uniqueness — so MySQL itself
 * refuses a second concurrent INSERT for the same order while leaving
 * historical rows completely unconstrained. This is the same "simple,
 * portable, MySQL-native" bar the custody invariant was held to (§8).
 *
 * VIRTUAL, NOT STORED (FAST-CLOSURE CORRECTION). `order_id` carries
 * `->cascadeOnDelete()` against `orders.id` (see the original migration).
 * MySQL/InnoDB raises ER_CANNOT_ADD_FOREIGN_BASE_COL_STORED
 * ("Cannot add foreign key on the base column of a stored generated column")
 * for exactly this shape: a cascading FK (CASCADE/SET NULL on UPDATE or
 * DELETE) on a column that is also a base column of a STORED generated
 * column on the same table — because a cascade would need to rewrite the
 * materialized value, which InnoDB does not support. A VIRTUAL generated
 * column is never materialized (computed on read), so no such rewrite is
 * ever needed and the restriction does not apply — while still fully
 * supporting a secondary UNIQUE index in InnoDB (supported since MySQL
 * 5.7.8, unchanged in 8.0). The uniqueness guarantee itself — NULL never
 * equals NULL — is identical either way; only where MySQL stores the
 * computed value differs. No business-logic or query-behavior change.
 *
 * WHY THESE THREE COLUMNS AND NO OTHERS (§7 — "document exactly why every
 * schema field is required"):
 *   - `superseded_at` IS the active/historical flag. NULL = active; non-null =
 *     released, preserved as history. Nothing else can carry this meaning
 *     without overloading an existing column.
 *   - `release_reason` records WHY (a FailureReason value such as
 *     'no_answer'/'customer_rescheduled', or 'manual' for an operator-driven
 *     release) — required by §7's "release reason/outcome reference" and by
 *     §11's auditability requirement. Free-string, matching the existing
 *     `trip_returns.reason` and `distribution_delivery_actions.reason`
 *     convention in this same module (neither is an enum FK either).
 *   - `released_by` records WHO/WHAT closed it (nullable — a system-triggered
 *     release from the retryable-outcome listener has no human actor).
 *
 * WHAT WAS DELIBERATELY NOT ADDED. An "attempt sequence number" was
 * considered (§7) and rejected: `assigned_at` (existing) plus `id` (existing,
 * auto-increment — see the original migration's `$table->id()`) already give
 * a strictly deterministic historical order with zero new columns. Adding a
 * redundant counter would violate §7's "do NOT add fields blindly".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distribution_trip_orders', function (Blueprint $table): void {
            $table->timestamp('superseded_at')->nullable()->after('assigned_at');
            $table->string('release_reason', 50)->nullable()->after('superseded_at');
            $table->foreignId('released_by')->nullable()->after('release_reason')
                ->constrained('users')->nullOnDelete();
        });

        // Drop the old "one Trip per Order, ever" backstop before it can conflict
        // with the new one below — MySQL does not allow two unique indexes that
        // would both reject the same insert to coexist meaningfully, and the old
        // one is exactly what this migration is retiring.
        Schema::table('distribution_trip_orders', function (Blueprint $table): void {
            $table->dropUnique('distribution_trip_orders_order_unique');
        });

        // The NEW backstop: NULL while superseded (any number of historical rows
        // may share order_id), the real order_id while active (at most one row).
        // `orders.id` is a UUID (see the original migration's own comment), so this
        // mirrors that type exactly. VIRTUAL, not STORED — order_id carries an
        // ON DELETE CASCADE foreign key, and MySQL/InnoDB refuses a STORED
        // generated column whose base column has a cascading FK action
        // (ER_CANNOT_ADD_FOREIGN_BASE_COL_STORED) — see this file's docblock.
        DB::statement(<<<'SQL'
            ALTER TABLE distribution_trip_orders
            ADD COLUMN active_order_id CHAR(36)
                GENERATED ALWAYS AS (CASE WHEN superseded_at IS NULL THEN order_id ELSE NULL END) VIRTUAL
        SQL);

        Schema::table('distribution_trip_orders', function (Blueprint $table): void {
            $table->unique('active_order_id', 'distribution_trip_orders_active_order_unique');

            // Non-unique lookup index: history queries (Order::tripOrderHistory(),
            // reporting) still filter/join by order_id across every historical row.
            $table->index('order_id', 'distribution_trip_orders_order_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('distribution_trip_orders', function (Blueprint $table): void {
            $table->dropUnique('distribution_trip_orders_active_order_unique');
            $table->dropIndex('distribution_trip_orders_order_id_index');
            $table->dropColumn('active_order_id');
        });

        Schema::table('distribution_trip_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('released_by');
            $table->dropColumn(['superseded_at', 'release_reason']);
        });

        Schema::table('distribution_trip_orders', function (Blueprint $table): void {
            $table->unique('order_id', 'distribution_trip_orders_order_unique');
        });
    }
};

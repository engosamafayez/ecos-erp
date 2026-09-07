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
 * portable, MySQL-native" bar the custody invariant was held to (§8). See fix
 * note 3 below for why this column is VIRTUAL rather than STORED.
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
 *
 * TASK-ECOS-UNIFIED-PREFINAL-MIGRATION-REMEDIATION-AND-ROLLOUT-CONTINUATION-002
 * — two fixes to this file, no change to the approved schema design above.
 *
 * 1. ORDERING DEFECT (the actual first-run failure — MySQL 1553): `order_id`
 *    already carries `distribution_trip_orders_order_id_foreign` (→ orders.id).
 *    InnoDB requires SOME index on a foreign-keyed column at all times, and
 *    the old unique index was the ONLY one covering `order_id`. Dropping it
 *    before the replacement plain index existed left that instant with none,
 *    which MySQL correctly refuses. Fix: the plain `order_id` index is now
 *    created FIRST, so the FK always has a supporting index; the old unique
 *    index is dropped only afterward.
 *
 * 2. RESUMABILITY: `up()` partially succeeded on that failed first run (the
 *    three new nullable columns + the released_by FK committed as one
 *    ALTER before the ordering defect above was reached) but was never
 *    recorded in the `migrations` table, so Laravel re-runs the WHOLE method
 *    from the top on the next `migrate`. Every step is now individually
 *    guarded with Laravel's own `Schema::hasColumn()`/`Schema::hasIndex()` (no
 *    custom/generic idempotency layer) so `up()` is safe to run once from a
 *    fully fresh schema, once from exactly the partially-applied state this
 *    task found on DEV, or a second time from a fully-applied state.
 *
 * 3. GENERATED COLUMN STORAGE MODE (the SECOND failure, reached only once fix
 *    1 let the migration get this far — MySQL 1215 "Cannot add foreign key
 *    constraint"): `order_id` carries `distribution_trip_orders_order_id_foreign
 *    ... ON DELETE CASCADE` (the pre-existing, approved FK — unchanged here).
 *    InnoDB forbids a STORED generated column from depending on a column whose
 *    foreign key uses a cascading action (CASCADE/SET NULL on UPDATE or
 *    DELETE), because a cascaded change is applied at the storage-engine level
 *    and would leave a STORED value un-recomputed. Confirmed empirically in an
 *    isolated scratch database: the identical `ADD COLUMN ... STORED` fails
 *    with 1215 whenever `order_id`'s FK carries `ON DELETE CASCADE`, and
 *    succeeds immediately once the column is declared VIRTUAL instead — with
 *    the FK's cascade action untouched. VIRTUAL vs STORED is a physical
 *    storage detail only: the expression, column name, and unique index are
 *    unchanged, InnoDB still supports (and here carries) a UNIQUE index on a
 *    VIRTUAL generated column, and the one-active-execution invariant was
 *    re-verified against the VIRTUAL column in the same rehearsal (a second
 *    concurrent active row for one order is rejected with 1062 on
 *    `distribution_trip_orders_active_order_unique`; historical/superseded
 *    rows remain unconstrained). No application code reads this column's
 *    value directly — it exists solely to back the unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('distribution_trip_orders', 'superseded_at')) {
            Schema::table('distribution_trip_orders', function (Blueprint $table): void {
                $table->timestamp('superseded_at')->nullable()->after('assigned_at');
                $table->string('release_reason', 50)->nullable()->after('superseded_at');
                $table->foreignId('released_by')->nullable()->after('release_reason')
                    ->constrained('users')->nullOnDelete();
            });
        }

        // The replacement supporting index for `order_id` MUST exist before the
        // old unique index (order_id's only index, and the one InnoDB is using to
        // satisfy distribution_trip_orders_order_id_foreign) is ever dropped — see
        // fix note 1 above. Created first, deliberately, not merely reordered
        // cosmetically: this is the actual fix for the original 1553 error.
        if (! Schema::hasIndex('distribution_trip_orders', 'distribution_trip_orders_order_id_index')) {
            Schema::table('distribution_trip_orders', function (Blueprint $table): void {
                // Non-unique lookup index: history queries (Order::tripOrderHistory(),
                // reporting) still filter/join by order_id across every historical row.
                $table->index('order_id', 'distribution_trip_orders_order_id_index');
            });
        }

        // Drop the old "one Trip per Order, ever" backstop now that order_id's FK
        // is already supported by the plain index created immediately above.
        if (Schema::hasIndex('distribution_trip_orders', 'distribution_trip_orders_order_unique')) {
            Schema::table('distribution_trip_orders', function (Blueprint $table): void {
                $table->dropUnique('distribution_trip_orders_order_unique');
            });
        }

        // The NEW backstop: NULL while superseded (any number of historical rows
        // may share order_id), the real order_id while active (at most one row).
        // `orders.id` is a UUID (see the original migration's own comment), so this
        // mirrors that type exactly. VIRTUAL, not STORED — see fix note 3 above:
        // order_id's FK uses ON DELETE CASCADE, which InnoDB refuses to combine
        // with a STORED (but not VIRTUAL) generated column that depends on it.
        if (! Schema::hasColumn('distribution_trip_orders', 'active_order_id')) {
            DB::statement(<<<'SQL'
                ALTER TABLE distribution_trip_orders
                ADD COLUMN active_order_id CHAR(36)
                    GENERATED ALWAYS AS (CASE WHEN superseded_at IS NULL THEN order_id ELSE NULL END) VIRTUAL
            SQL);
        }

        if (! Schema::hasIndex('distribution_trip_orders', 'distribution_trip_orders_active_order_unique')) {
            Schema::table('distribution_trip_orders', function (Blueprint $table): void {
                $table->unique('active_order_id', 'distribution_trip_orders_active_order_unique');
            });
        }
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

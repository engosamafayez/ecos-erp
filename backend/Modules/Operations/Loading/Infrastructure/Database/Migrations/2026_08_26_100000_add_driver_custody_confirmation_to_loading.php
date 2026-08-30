<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-LOADING-WAREHOUSE-DRIVER-CUSTODY-IMPLEMENTATION-001 (owner-approved #1).
 *
 * ┌─ WHAT THIS ADDS, AND WHAT IT DELIBERATELY DOES NOT ──────────────────────┐
 * │ ADDS   three nullable driver columns on `loading_tasks`, and one          │
 * │        append-only adjustment log.                                        │
 * │ DOES NOT add a persisted workflow status column. The product's state is    │
 * │        DERIVED from the quantities and the two confirmation timestamps —   │
 * │        a stored status would be a second source of truth able to           │
 * │        contradict the very numbers it describes.                          │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * WAREHOUSE CONFIRMATION NEEDS NO COLUMN. `loading_tasks.confirmed_by` and
 * `confirmed_at` already exist (created 2026_07_05, nullable) and are written by
 * nothing today. They sit on the warehouse-owned row, so the warehouse half of the
 * contract is satisfied without touching the schema at all.
 *
 * THE DRIVER PAIR MIRRORS AN EXISTING ECOS SHAPE. `distribution_trip_custody`
 * already models "handed out" vs "counted back" as `quantity` / `received_quantity`
 * plus `driver_confirmed_at` / `driver_confirmed_by`. That table is equipment and
 * cash float and carries no product_id, so it cannot host product quantities — but
 * its SHAPE is the precedent, and these columns are the same idea at product grain.
 *
 * WHY THE ADJUSTMENT HISTORY IS A TABLE AND NOT COLUMNS. An `adjustment_qty` column
 * would be overwritten by the second round, destroying the first — precisely the
 * "no overwrite without trace" rule this workflow exists to honour. The table is
 * APPEND-ONLY: a decision adds a row, it never edits one.
 *
 * NAMING FOLLOWS THE SIBLING IN THIS SAME MODULE. `vehicle_plan_adjustment_log`
 * already uses `action_type / actor_id / *_before / *_after / reason / recorded_at`,
 * and the dormant `AllocationAdjusted` event already carries
 * `quantityBefore, quantityAfter, actorType, actorId, reason`. No parallel audit
 * mechanism is introduced; this is the same convention at a new grain.
 *
 * ADDITIVE AND BACKWARD COMPATIBLE. Every new column is nullable with no default, so
 * every existing `loading_tasks` row stays valid and NO BACKFILL IS PERFORMED.
 * `driver_received_qty` remains NULL until a real driver acts — a fabricated zero
 * would be indistinguishable from "the driver counted none".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('loading_tasks') && ! Schema::hasColumn('loading_tasks', 'driver_received_qty')) {
            Schema::table('loading_tasks', function (Blueprint $table): void {
                // NULL is meaningful: "the driver has not counted this yet". It is NOT 0,
                // which would mean "the driver counted none". decimal(18,4) matches
                // quantity_loaded exactly so the two can be compared without conversion.
                $table->decimal('driver_received_qty', 18, 4)->nullable()->after('confirmed_at');
                $table->timestampTz('driver_confirmed_at')->nullable()->after('driver_received_qty');
                $table->uuid('driver_confirmed_by')->nullable()->after('driver_confirmed_at');
            });
        }

        if (! Schema::hasTable('loading_task_adjustment_log')) {
            Schema::create('loading_task_adjustment_log', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('company_id');

                // cascadeOnDelete, unlike loading_tasks' own restrictOnDelete parents:
                // this row is history ABOUT a task and has no meaning without it, whereas
                // a session/assignment is a parent whose deletion must be prevented.
                $table->foreignUuid('loading_task_id')
                    ->constrained('loading_tasks')
                    ->cascadeOnDelete();

                // driver_requested | warehouse_accepted | warehouse_revised | warehouse_rejected
                $table->string('action_type', 50);

                // warehouse | driver — the field pair AllocationAdjusted already uses, and
                // the structural expression of "one writer per fact".
                $table->string('actor_type', 20);
                $table->uuid('actor_id');

                // The warehouse Loaded quantity as it stood when the act happened, and what
                // it became. NULL `quantity_after` on a request: a request changes nothing.
                $table->decimal('quantity_before', 18, 4)->nullable();
                $table->decimal('quantity_after', 18, 4)->nullable();

                // What the driver says they physically received. Kept separate from
                // quantity_* so a request can never be mistaken for a quantity change.
                $table->decimal('driver_reported_qty', 18, 4)->nullable();

                $table->string('reason', 255)->nullable();

                // open | accepted | revised | rejected. Only `open` is actionable, and at
                // most one open row per task may exist — enforced under lockForUpdate,
                // because MySQL 8.4 has no partial unique index.
                $table->string('status', 20)->default('open');

                $table->uuid('resolved_by')->nullable();
                $table->timestampTz('resolved_at')->nullable();
                $table->timestampTz('recorded_at');
                $table->timestampsTz();

                // "the history of this product", the only per-task query.
                $table->index(['loading_task_id', 'recorded_at'], 'idx_lta_task_recorded');
                // "open adjustment requests for this company" — the warehouse review queue,
                // and the read the one-open-per-task guard runs under its lock.
                $table->index(['company_id', 'status'], 'idx_lta_company_status');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('loading_task_adjustment_log');

        if (Schema::hasTable('loading_tasks') && Schema::hasColumn('loading_tasks', 'driver_received_qty')) {
            Schema::table('loading_tasks', function (Blueprint $table): void {
                $table->dropColumn(['driver_received_qty', 'driver_confirmed_at', 'driver_confirmed_by']);
            });
        }
    }
};

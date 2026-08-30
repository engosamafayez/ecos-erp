<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-OPERATIONS-GROUP-LOADING-PREPARATION-IMPLEMENTATION-001 — Group Prepared.
 *
 * ┌─ THE ONE FACT THIS TABLE OWNS ───────────────────────────────────────────┐
 * │ "For THIS Distribution Group, how many units of THIS Product has the      │
 * │  warehouse physically separated?"                                        │
 * │                                                                          │
 * │ Grain: (Group, Product). Not (Group, Order, Product) — Actual Loading     │
 * │ already re-derives order attribution itself, from `order_lines.quantity`, │
 * │ at allocation time (AutoAllocationService → allocation_records, unique on │
 * │ vehicle_assignment_id + order_line_id). Nothing downstream consumes an    │
 * │ upstream per-order prepared figure, and Preparation's own certified       │
 * │ contract is explicitly product-level.                                    │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * WHY A NEW TABLE RATHER THAN AN EXISTING ONE. Every candidate was checked and
 * disproved:
 *   • `wave_product_demand`        unique(wave, product) — no Group dimension, and a
 *                                  Group can span waves. Writing it is a Preparation change.
 *   • `preparation_wave_items`     same grain, Preparation-owned, and dead (0 rows).
 *   • `prepared_products_pool`     (wave, product, warehouse) — no Group, and it is
 *                                  Actual Loading's INPUT; writing it would inject
 *                                  un-loaded stock into the loading pipeline.
 *   • `loading_tasks` / `allocation_records`  vehicle_assignment_id NOT NULL — cannot
 *                                  exist before a vehicle.
 *   • `distribution_virtual_slots` one row per Group; no product dimension.
 *   • `distribution_window_orders` order grain; no product column, no quantity.
 *   • `order_lines.prepared_qty`   order+product, no Group; a `float`; and an Orders
 *                                  column already exposed by OrderResource.
 *
 * WHAT IT DELIBERATELY DOES NOT STORE:
 *   • Required   — canonical and LIVE, from DistributionAggregationService::
 *                  productAggregation(). Storing it would be a second engine.
 *   • Remaining  — always max(0, Required − Prepared), derived at read time. The
 *                  stored `wave_product_demand.remaining_qty` is live-inconsistent
 *                  today (required 3, prepared 2, stored remaining 0), which is the
 *                  measured proof that a stored derived quantity drifts.
 *   • warehouse  — it lives on `distribution_virtual_slots.warehouse_id` (NOT NULL).
 *                  A copy here could disagree with Group ownership, the exact defect
 *                  Part 5B closed.
 *   • preparation_wave_id — a Group's orders can span several active waves, so
 *                  binding a Group fact to one wave would be false.
 *   • status     — derived from the quantities (not started / in progress / prepared /
 *                  over-prepared). No status column is added for convenience.
 *
 * ── CONVENTIONS FOLLOWED (this module's, not another's) ──────────────────────
 *
 * NO FOREIGN KEYS. `2026_08_21_100000_add_warehouse_ownership_to_distribution_groups`
 * states the rule verbatim: "NO FOREIGN KEYS, MATCHING THIS MODULE. Neither table
 * carries an FK today (`company_id` and `distribution_window_id` are plain uuids with
 * indexes), so adding one here would be a new convention rather than a followed one."
 * The whole 2026_08_11 windows/slots/window_orders wave declares zero FKs. This table
 * follows that, and the trade-off is stated rather than hidden: there is no DB-level
 * bar on orphaning a row by deleting its Group. No Group delete path exists today
 * (`storeSlot` has no `destroy` sibling), so nothing can currently produce that orphan.
 *
 * NO CHECK CONSTRAINTS. The Distribution directory contains zero `ADD CONSTRAINT`
 * statements — the trips migration records that the prior schema's CHECK ALTERs were
 * deliberately removed in favour of Schema-Builder-only, MySQL-portable DDL.
 * Non-negativity is therefore enforced where the ceiling already is: inside the
 * transaction, under the row lock, in GroupPreparationService — plus request
 * validation in front of it. `unsignedDecimal` is not used anywhere in this codebase
 * and is deprecated in MySQL 8.0.17+, so it is not introduced here.
 *
 * PRECISION: decimal(12,4), matching `order_lines.quantity` — the column Required is
 * summed from. Deliberately NOT Distribution's usual decimal(12,3): a 3-decimal
 * column cannot represent a 4-decimal Required, which would leave the ceiling
 * `Prepared <= Required` unsatisfiable for a fractional line. Deliberately NOT
 * Preparation's decimal(18,4) either — that is another module's scale, and this
 * quantity is only ever compared against a decimal(12,4) one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('distribution_group_product_preparation')) {
            return;
        }

        Schema::create('distribution_group_product_preparation', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Tenant. Plain uuid + index, per module convention (no FK).
            $table->uuid('company_id');

            // Retention / cleanup scope. The Group already carries it, but a Window-scoped
            // sweep must not have to join to find its rows.
            $table->uuid('distribution_window_id');

            // THE OWNER — the Distribution Group. The physical table is
            // `distribution_virtual_slots`; `virtual_slot_id` is the column name every
            // other table in this module uses for it (distribution_slot_zones,
            // distribution_window_orders), so it is used here too rather than inventing
            // a second name for the same reference.
            $table->uuid('virtual_slot_id');

            $table->uuid('product_id');

            // The one fact. Absolute value, never an accumulator.
            $table->decimal('prepared_qty', 12, 4)->default(0);

            // Who last set it, and when. Durable on the row itself, because AuditService
            // and TimelineService both swallow their own failures by design and are
            // therefore a best-effort trail rather than a ledger. Users have a bigint PK,
            // matching `distribution_window_orders.assigned_by`.
            $table->unsignedBigInteger('last_recorded_by')->nullable();
            $table->timestamp('last_recorded_at')->nullable();

            $table->timestamps();

            // ONE row per Group per Product. This is simultaneously the correctness
            // guarantee (no duplicate Group+Product record), the concurrency guard (two
            // racing creates cannot both win) and the idempotency guard (a retried
            // absolute set resolves to the same single row).
            $table->unique(['virtual_slot_id', 'product_id'], 'dist_group_prep_slot_product_unique');

            $table->index(['company_id', 'distribution_window_id'], 'dist_group_prep_company_window_idx');
            $table->index('product_id', 'dist_group_prep_product_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distribution_group_product_preparation');
    }
};

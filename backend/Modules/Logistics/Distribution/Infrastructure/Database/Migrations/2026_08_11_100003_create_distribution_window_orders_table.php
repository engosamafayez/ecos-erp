<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-SHIPPING-DISTRIBUTION-CORE-001 — Order ↔ Window assignment.
 *
 * This table is the whole of an Order's Distribution state. It deliberately
 * carries NOTHING about the Order's lifecycle: `orders.status` is never read
 * from here and never written by Distribution (business contract §19). Moving an
 * Order between Zones or Slots changes rows here and nothing else.
 *
 * `order_id` is globally unique, not unique-per-window. An Order belongs to
 * exactly one Distribution Window at a time, so a Manual Late-Order Assignment
 * is an UPDATE that moves the row from the next Window to the current one — not
 * a second row. This is what makes re-running automatic collection idempotent
 * (§20) and what makes two concurrent collectors resolve to one effective
 * assignment (§21) without any additional locking.
 *
 * `virtual_slot_id` is denormalised from the Zone→Slot mapping so that Slot
 * aggregation is a single indexed read. It is kept in step whenever Zone or Slot
 * membership changes; the Zone mapping remains the source of truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('distribution_window_orders')) {
            return;
        }

        Schema::create('distribution_window_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('distribution_window_id');
            $table->uuid('order_id');

            // Nullable: an Order whose city carries no Zone is still collected,
            // and surfaces as unzoned work rather than being silently dropped.
            $table->unsignedBigInteger('distribution_zone_id')->nullable();
            $table->uuid('virtual_slot_id')->nullable();

            // auto | manual_late | manual_move — how this assignment came about.
            $table->string('assignment_source', 32);

            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->timestamp('assigned_at');

            // Retained for audit when a manager moves an Order between Windows.
            $table->uuid('previous_window_id')->nullable();
            $table->string('assignment_reason', 255)->nullable();

            $table->timestamps();

            $table->unique('order_id', 'dist_window_orders_order_unique');
            $table->index(['distribution_window_id', 'distribution_zone_id'], 'dist_window_orders_window_zone_idx');
            $table->index(['distribution_window_id', 'virtual_slot_id'], 'dist_window_orders_window_slot_idx');
            $table->index(['company_id', 'distribution_window_id'], 'dist_window_orders_company_window_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distribution_window_orders');
    }
};

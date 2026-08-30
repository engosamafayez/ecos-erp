<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-SHIPPING-DISTRIBUTION-CORE-001 — Virtual Capacity Slot.
 *
 * A Slot is PLANNING capacity, not a Vehicle (business contract §11–§14). No
 * vehicle_id or driver_id column exists here, and that absence is deliberate:
 * a real Vehicle becomes operationally attached only when a Driver is assigned,
 * which is the next task's boundary. Making the column absent rather than
 * nullable means the boundary cannot be crossed by accident.
 *
 * Capacity is multi-dimensional, mirroring the vocabulary already established by
 * Modules\Logistics\Network\Domain\Models\CapacitySlot (orders / stops /
 * weight_kg / volume_m3). That model is the CARRIER network capacity ledger —
 * a different bounded context, keyed by capacity_plan + service_level — so it is
 * not reused as the storage here, but its dimensions are preserved verbatim so
 * the two never disagree about what "capacity" means.
 *
 * Every dimension is nullable: a null dimension is "not constrained on this
 * axis", which is not the same as a capacity of zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('distribution_virtual_slots')) {
            return;
        }

        Schema::create('distribution_virtual_slots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('distribution_window_id');

            $table->string('code', 50);
            $table->string('name', 100)->nullable();

            $table->unsignedInteger('capacity_orders')->nullable();
            $table->unsignedInteger('capacity_stops')->nullable();
            $table->decimal('capacity_weight_kg', 12, 2)->nullable();
            $table->decimal('capacity_volume_m3', 12, 3)->nullable();

            $table->timestamps();

            $table->unique(['distribution_window_id', 'code'], 'dist_slots_window_code_unique');
            $table->index(['company_id', 'distribution_window_id'], 'dist_slots_company_window_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distribution_virtual_slots');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-SHIPPING-DISTRIBUTION-CORE-001 — Slot ↔ Zone membership.
 *
 * A Slot may hold ONE OR MORE Zones (business contract §9). The reverse is
 * constrained: within one Window a Zone belongs to at most one Slot. That is
 * what `dist_slot_zones_window_zone_unique` enforces, and it is the reason
 * Orders can inherit their Slot from their Zone without a per-Order Slot write —
 * the mapping is unambiguous by construction rather than by convention.
 *
 * `distribution_window_id` is denormalised onto this row purely so that
 * uniqueness can be expressed at the database level. Without it the constraint
 * would have to be enforced in application code, which is exactly where this
 * class of rule goes wrong under concurrency.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('distribution_slot_zones')) {
            return;
        }

        Schema::create('distribution_slot_zones', function (Blueprint $table): void {
            $table->id();
            $table->uuid('distribution_window_id');
            $table->uuid('virtual_slot_id');
            $table->unsignedBigInteger('distribution_zone_id');

            $table->timestamps();

            $table->unique(
                ['distribution_window_id', 'distribution_zone_id'],
                'dist_slot_zones_window_zone_unique',
            );
            $table->index('virtual_slot_id', 'dist_slot_zones_slot_idx');
            $table->index('distribution_zone_id', 'dist_slot_zones_zone_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distribution_slot_zones');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-DRIVER-WAVE-1-GROUP-LOADING-IMPLEMENTATION-001 (Option 1, owner-approved).
 *
 * Group-as-Shipment driver loading records a load keyed by (vehicle_assignment,
 * product) with `quantity_planned` = the Group's live Required — it has NO
 * Preparation-pool provenance because the Group grain does not carry one
 * (`prepared_products_pool` is a different fact at a different grain). The
 * existing pool-based operator loading is UNCHANGED and still supplies both
 * identifiers; this migration only ADDS the possibility of NULL so the
 * group-grain path is not forced to fabricate a `pool_entry_id` /
 * `preparation_wave_id` to satisfy a NOT NULL column.
 *
 * There is no foreign key on either column (verified against the create
 * migrations), so this is a pure nullability relaxation — no FK to drop, no
 * existing (non-null) row invalidated. Columns are NOT renamed or dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        // char(36) is Laravel's storage type for uuid(); MODIFY keeps the exact
        // type and only relaxes NULL. Guarded so a partial re-run is safe.
        if (Schema::hasColumn('loading_tasks', 'pool_entry_id')) {
            DB::statement('ALTER TABLE loading_tasks MODIFY pool_entry_id CHAR(36) NULL');
        }
        if (Schema::hasColumn('loading_tasks', 'preparation_wave_id')) {
            DB::statement('ALTER TABLE loading_tasks MODIFY preparation_wave_id CHAR(36) NULL');
        }
        if (Schema::hasColumn('vehicle_inventory_items', 'pool_entry_id')) {
            DB::statement('ALTER TABLE vehicle_inventory_items MODIFY pool_entry_id CHAR(36) NULL');
        }
    }

    public function down(): void
    {
        // Best-effort revert to NOT NULL. This will only succeed when no
        // group-grain (null-provenance) rows exist — which is the correct
        // guard: you cannot re-impose the pool-provenance requirement while
        // group-grain loads are present.
        if (Schema::hasColumn('loading_tasks', 'pool_entry_id')) {
            DB::statement('ALTER TABLE loading_tasks MODIFY pool_entry_id CHAR(36) NOT NULL');
        }
        if (Schema::hasColumn('loading_tasks', 'preparation_wave_id')) {
            DB::statement('ALTER TABLE loading_tasks MODIFY preparation_wave_id CHAR(36) NOT NULL');
        }
        if (Schema::hasColumn('vehicle_inventory_items', 'pool_entry_id')) {
            DB::statement('ALTER TABLE vehicle_inventory_items MODIFY pool_entry_id CHAR(36) NOT NULL');
        }
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-OPERATIONS-GROUP-TRIP-VEHICLE-DRIVER-LOADING-DISPATCH-IMPLEMENTATION-001 — Part 1.
 *
 * ┌─ THE APPROVED RELATION ──────────────────────────────────────────────────┐
 * │ Group = planning / preparation unit    (distribution_virtual_slots)       │
 * │ Trip  = transport execution unit       (distribution_trips)               │
 * │                                                                          │
 * │   1 Group → 1..N Trips        normal case 1:1; N only when Trip.capacity  │
 * │   1 Trip  → exactly 1 Group   enforced BY CONSTRUCTION — see below        │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ONE GROUP PER TRIP IS A STRUCTURAL GUARANTEE, NOT A RULE TO REMEMBER.
 * Because the reference is a single-valued column ON THE TRIP, a Trip cannot name
 * two Groups — there is no shape in which it could. That is why this is a column
 * and not a junction table: a junction would ALLOW many-Groups-per-Trip and then
 * need a unique index to forbid it again, which is a weaker guarantee expressed in
 * more moving parts. The architecture decision rejected many-Groups→one-Trip
 * outright (it would silently mix warehouses, because TripService::assignOrder
 * never reads the order's assigned_warehouse_id), so the shape that cannot express
 * it is the correct shape.
 *
 * WHY NOT `distribution_zone_id` — the column that already exists and looks close.
 * The architecture decision rejected it explicitly: it is nullable, purely
 * descriptive (populated from a free-text zone_code the operator types; the one
 * consumer, AssignmentScoringService::zoneAffinity(), is a hard-coded
 * `return 0.5;` placeholder), and its GRAIN IS WRONG. A Group is
 * (window, warehouse, zone) and holds MANY zones, so one zone id can neither name
 * a multi-zone Group nor distinguish two warehouses planning the same zone —
 * which is precisely the defect `dist_slot_zones_window_wh_zone_unique` was added
 * to close. Reusing it would silently reintroduce that defect.
 *
 * `preparation_wave_id` is rejected for the mirror reason: a Group's orders can
 * span several active waves, so binding a Group fact to one wave would be false.
 *
 * WAREHOUSE IS DERIVED, NOT COPIED. `distribution_trips` has no warehouse column
 * and does not gain one here. A Trip's operational warehouse is
 * `virtual_slot_id → distribution_virtual_slots.warehouse_id` (CHAR(36) NOT NULL
 * since Part 5B). Copying it onto the Trip would create a second place for
 * warehouse ownership to disagree with itself — the exact class of defect Part 5B
 * closed. The invariant "Trip executes from its Group's warehouse" is therefore
 * true by construction rather than by synchronisation.
 *
 * VP-1 IS NOT TOUCHED. `distribution_virtual_slots.id` is CHAR(36) and this column
 * is CHAR(36) — same type, direct reference, no conversion, no compatibility key,
 * no mapping table. The uuid/bigint divergence recorded as BLOCKER VP-1 concerns
 * `Operations\Loading.vehicle_assignments.vehicle_id` (uuid) against
 * `logistics_vehicles.id` (bigint) — a different relation entirely, untouched here.
 *
 * ── CONVENTION ───────────────────────────────────────────────────────────────
 * FOLLOWS THE TABLE BEING ALTERED, not the Group table. `distribution_trips` (the
 * 2026_07_28 wave) declares real foreign keys, two-step for uuid parents
 * (`$table->uuid(col)` then `$table->foreign(col)->references(...)`), with
 * cascadeOnDelete for the owning company and nullOnDelete for optional parents.
 * `distribution_virtual_slots` (the 2026_08_11 wave) uses NO foreign keys at all.
 * Mixing the two would be inventing a third convention.
 *
 * nullOnDelete matches how this table already treats its optional parents
 * (preparation_wave_id, distribution_zone_id). It cannot fire today in any case:
 * no Group delete path exists — `DistributionWindowController` has `storeSlot`
 * with no `destroy` sibling.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('distribution_trips')) {
            return;
        }

        if (Schema::hasColumn('distribution_trips', 'virtual_slot_id')) {
            return;
        }

        Schema::table('distribution_trips', function (Blueprint $table): void {
            // NULLABLE, deliberately. A Trip may legitimately exist without a Group:
            // every one of the 272 trips this table has already carried was created
            // that way, and ad-hoc or externally-sourced trips remain valid. The
            // Group link is an ADDITIONAL, optional ownership, not a new precondition
            // for a Trip existing.
            $table->uuid('virtual_slot_id')->nullable()->after('company_id');

            $table->foreign('virtual_slot_id')
                ->references('id')
                ->on('distribution_virtual_slots')
                ->nullOnDelete();

            // "All trips of this Group" is the query Finalize and the Group card both
            // run, and it must not table-scan.
            $table->index('virtual_slot_id', 'distribution_trips_virtual_slot_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('distribution_trips')) {
            return;
        }

        if (! Schema::hasColumn('distribution_trips', 'virtual_slot_id')) {
            return;
        }

        Schema::table('distribution_trips', function (Blueprint $table): void {
            $table->dropForeign(['virtual_slot_id']);
            $table->dropIndex('distribution_trips_virtual_slot_idx');
            $table->dropColumn('virtual_slot_id');
        });
    }
};

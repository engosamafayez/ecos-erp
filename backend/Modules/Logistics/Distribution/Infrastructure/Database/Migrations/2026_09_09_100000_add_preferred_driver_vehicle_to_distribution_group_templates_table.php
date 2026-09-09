<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-ECOS-OPERATIONS-DISTRIBUTION-AND-LOADING-FINAL-022 §C — a template's
 * Preferred Driver and Preferred Vehicle.
 *
 * ┌─ WHY THIS DOES NOT REOPEN THE "NO VEHICLE/DRIVER" DECISION ────────────────┐
 * │ The original migration (`2026_08_22_100000`) forbids a template from       │
 * │ holding vehicle/driver identity, reasoning: "the canonical pairing is      │
 * │ `driver_vehicle_assignments`... copying either here would be the duplicate │
 * │ vehicle source the architecture forbids." That reasoning is about a        │
 * │ template carrying a LIVE ASSIGNMENT FACT — a second place that claims      │
 * │ "this Group's driver/vehicle IS X", able to drift from the real ledger.    │
 * │                                                                            │
 * │ A preferred_driver_id/preferred_vehicle_id column is not that. It is a     │
 * │ PREFERENCE — an input to the SAME canonical assignment path              │
 * │ (`GroupVehicleAssignmentService::assign()`) a human uses, attempted only   │
 * │ at generation time and discarded the moment it is not canonically         │
 * │ available. It never becomes the Group's assignment by itself: the Group's │
 * │ actual driver/vehicle still lives nowhere but the Trip's                  │
 * │ `driver_vehicle_assignment_id`, exactly as before. Nothing here reads      │
 * │ these columns to answer "who is running this Group" after generation —    │
 * │ only the ledger answers that, unchanged.                                  │
 * │                                                                            │
 * │ This is the same distinction the pre-existing `recommendedDrivers` pivot   │
 * │ already draws (a plural, passive suggestion) — this is its singular,      │
 * │ active-attempt counterpart, extended to also cover Vehicle, which had no  │
 * │ recommendation concept at all before this migration.                      │
 * └────────────────────────────────────────────────────────────────────────────┘
 *
 * BIGINT, NOT UUID — matching `logistics_drivers.id` / `logistics_vehicles.id`
 * (both `$table->id()`), the same convention `distribution_group_template_drivers.
 * logistics_driver_id` already follows. Resolution to/from the cross-module uuid
 * contract goes through `FleetIdentityResolver`, exactly as every other fleet
 * reference in this codebase does.
 *
 * NULLABLE, INDEPENDENTLY. A template may prefer a Driver with no Vehicle, a
 * Vehicle with no Driver, both, or neither — generation falls back to the
 * entity's own CURRENT active pairing (`driver_vehicle_assignments`) to fill in
 * whichever half is missing, and skips the attempt entirely if that leaves no
 * pair to try.
 *
 * NO FOREIGN KEYS, matching every table in this module (see `2026_08_22_100000`'s
 * own "CONVENTIONS FOLLOWED" note).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('distribution_group_templates', 'preferred_driver_id')) {
            return;
        }

        Schema::table('distribution_group_templates', function (Blueprint $table): void {
            $table->unsignedBigInteger('preferred_driver_id')->nullable()->after('capacity_orders');
            $table->unsignedBigInteger('preferred_vehicle_id')->nullable()->after('preferred_driver_id');

            $table->index('preferred_driver_id', 'dist_group_tpl_preferred_driver_idx');
            $table->index('preferred_vehicle_id', 'dist_group_tpl_preferred_vehicle_idx');
        });
    }

    public function down(): void
    {
        Schema::table('distribution_group_templates', function (Blueprint $table): void {
            $table->dropIndex('dist_group_tpl_preferred_driver_idx');
            $table->dropIndex('dist_group_tpl_preferred_vehicle_idx');
            $table->dropColumn(['preferred_driver_id', 'preferred_vehicle_id']);
        });
    }
};

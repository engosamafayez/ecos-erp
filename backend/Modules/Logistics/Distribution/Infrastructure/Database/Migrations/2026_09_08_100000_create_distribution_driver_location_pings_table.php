<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-ECOS-SHIPPING-OS-REDESIGN-004 §13/§14 — the smallest canonical
 * persistence needed for Live Driver Map + Route History/Replay.
 *
 * Confirmed by direct audit (this task's own §2, re-verifying Task 001's
 * original finding): no location-history table exists anywhere in the
 * platform. `DriverRuntimeController::gps()` validates lat/lng but discards
 * the payload by design; only one-off snapshots exist elsewhere (Trip
 * start/finish, DeliveryStop's own single gps_lat/lng, overwritten on
 * completion) — none is a time series. This table is that time series, and
 * ONLY that — it does not replace or duplicate any of those existing
 * snapshot columns.
 *
 * Scope is deliberately OPERATIONAL, not personal: `trip_id` is NOT
 * nullable. A ping only exists tied to a specific Trip's execution — see
 * DriverRuntimeController::gps()'s own write-path guard (added alongside
 * this migration) for the enforcement that a ping is only ever persisted
 * while that Trip is in a trackable custody+execution state (task §3/§14 —
 * "trackable active trip/custody -> location samples may be associated with
 * that operational Trip; trip/custody complete -> no new operational route
 * samples for that Trip"). This migration only creates the storage; it does
 * not itself decide when a ping should be written.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('distribution_driver_location_pings')) {
            return;
        }

        Schema::create('distribution_driver_location_pings', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // ── Tenancy + scope (resolved server-side from the authenticated driver's
            //    owned Trip — never a client-supplied driver/company id) ──
            $table->uuid('company_id');
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();

            $table->foreignId('driver_id')->constrained('logistics_drivers')->cascadeOnDelete();
            $table->foreignId('trip_id')->constrained('distribution_trips')->cascadeOnDelete();

            // ── The sample ────────────────────────────────────────────────────
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            // Only stored when the reporting device actually provided it — never
            // backfilled or estimated (task §13's "optional accuracy only if
            // provided canonically").
            $table->decimal('accuracy_meters', 8, 2)->nullable();
            $table->decimal('speed_kph', 8, 2)->nullable();
            $table->timestamp('recorded_at');

            $table->timestamps();

            // Route-history reads are always "this Trip, in time order" (task §8/§27);
            // the live-map read is always "this company's latest ping per Trip".
            $table->index(['trip_id', 'recorded_at'], 'distribution_driver_pings_trip_time_idx');
            $table->index(['company_id', 'recorded_at'], 'distribution_driver_pings_company_time_idx');
            $table->index(['driver_id', 'recorded_at'], 'distribution_driver_pings_driver_time_idx');

            // Sample dedup (task §16) is enforced in the write path (a config-driven
            // minimum interval, not a hard DB constraint — two legitimate samples can
            // share a timestamp at second resolution from different devices/retries),
            // so no unique constraint is added here.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distribution_driver_location_pings');
    }
};

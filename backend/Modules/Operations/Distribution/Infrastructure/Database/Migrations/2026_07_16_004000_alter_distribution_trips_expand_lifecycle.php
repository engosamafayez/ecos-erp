<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Expand the status CHECK constraint to include DIST-004A lifecycle statuses.
        // The original enum-based column was created with Laravel's blueprint and PostgreSQL
        // generates a CHECK constraint named distribution_trips_status_check.
        DB::statement("ALTER TABLE distribution_trips DROP CONSTRAINT IF EXISTS distribution_trips_status_check");

        DB::statement("ALTER TABLE distribution_trips ADD CONSTRAINT distribution_trips_status_check CHECK (status IN (
            'planning',
            'loading',
            'loading_completed',
            'driver_accepted',
            'dispatch_blocked',
            'ready_for_dispatch',
            'out_for_delivery',
            'dispatched',
            'completed',
            'settlement_pending',
            'closed',
            'cancelled'
        ))");

        // Formal driver acceptance fields (3 separate confirmations)
        DB::statement("ALTER TABLE distribution_trips ADD COLUMN IF NOT EXISTS driver_accepted_products  BOOLEAN NOT NULL DEFAULT false");
        DB::statement("ALTER TABLE distribution_trips ADD COLUMN IF NOT EXISTS driver_accepted_custody   BOOLEAN NOT NULL DEFAULT false");
        DB::statement("ALTER TABLE distribution_trips ADD COLUMN IF NOT EXISTS driver_accepted_equipment BOOLEAN NOT NULL DEFAULT false");
        DB::statement("ALTER TABLE distribution_trips ADD COLUMN IF NOT EXISTS driver_acceptance_at      TIMESTAMP");
        DB::statement("ALTER TABLE distribution_trips ADD COLUMN IF NOT EXISTS driver_acceptance_by      BIGINT");
        DB::statement("ALTER TABLE distribution_trips ADD COLUMN IF NOT EXISTS has_discrepancy           BOOLEAN NOT NULL DEFAULT false");
        DB::statement("ALTER TABLE distribution_trips ADD COLUMN IF NOT EXISTS discrepancy_notes         TEXT");

        // Departure / dispatch-vehicle fields
        DB::statement("ALTER TABLE distribution_trips ADD COLUMN IF NOT EXISTS departure_at              TIMESTAMP");
        DB::statement("ALTER TABLE distribution_trips ADD COLUMN IF NOT EXISTS departure_by              BIGINT");
        DB::statement("ALTER TABLE distribution_trips ADD COLUMN IF NOT EXISTS odometer_start            INTEGER");
        DB::statement("ALTER TABLE distribution_trips ADD COLUMN IF NOT EXISTS fuel_level                DECIMAL(5,2)");
        DB::statement("ALTER TABLE distribution_trips ADD COLUMN IF NOT EXISTS gps_tracking_started      BOOLEAN NOT NULL DEFAULT false");
        DB::statement("ALTER TABLE distribution_trips ADD COLUMN IF NOT EXISTS gps_tracking_started_at   TIMESTAMP");
    }

    public function down(): void
    {
        // Restore original constraint
        DB::statement("ALTER TABLE distribution_trips DROP CONSTRAINT IF EXISTS distribution_trips_status_check");
        DB::statement("ALTER TABLE distribution_trips ADD CONSTRAINT distribution_trips_status_check CHECK (status IN (
            'planning','loading','ready_for_dispatch','dispatched','completed','cancelled'
        ))");

        DB::statement("ALTER TABLE distribution_trips DROP COLUMN IF EXISTS driver_accepted_products");
        DB::statement("ALTER TABLE distribution_trips DROP COLUMN IF EXISTS driver_accepted_custody");
        DB::statement("ALTER TABLE distribution_trips DROP COLUMN IF EXISTS driver_accepted_equipment");
        DB::statement("ALTER TABLE distribution_trips DROP COLUMN IF EXISTS driver_acceptance_at");
        DB::statement("ALTER TABLE distribution_trips DROP COLUMN IF EXISTS driver_acceptance_by");
        DB::statement("ALTER TABLE distribution_trips DROP COLUMN IF EXISTS has_discrepancy");
        DB::statement("ALTER TABLE distribution_trips DROP COLUMN IF EXISTS discrepancy_notes");
        DB::statement("ALTER TABLE distribution_trips DROP COLUMN IF EXISTS departure_at");
        DB::statement("ALTER TABLE distribution_trips DROP COLUMN IF EXISTS departure_by");
        DB::statement("ALTER TABLE distribution_trips DROP COLUMN IF EXISTS odometer_start");
        DB::statement("ALTER TABLE distribution_trips DROP COLUMN IF EXISTS fuel_level");
        DB::statement("ALTER TABLE distribution_trips DROP COLUMN IF EXISTS gps_tracking_started");
        DB::statement("ALTER TABLE distribution_trips DROP COLUMN IF EXISTS gps_tracking_started_at");
    }
};

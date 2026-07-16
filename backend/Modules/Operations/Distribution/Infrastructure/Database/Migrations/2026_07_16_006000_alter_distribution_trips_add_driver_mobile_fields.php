<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Expand status check constraint to include 'in_progress' for Driver Mobile OS
        DB::statement("ALTER TABLE distribution_trips DROP CONSTRAINT IF EXISTS distribution_trips_status_check");

        DB::statement("ALTER TABLE distribution_trips ADD CONSTRAINT distribution_trips_status_check CHECK (status IN (
            'planning',
            'loading',
            'loading_completed',
            'driver_accepted',
            'dispatch_blocked',
            'ready_for_dispatch',
            'out_for_delivery',
            'in_progress',
            'dispatched',
            'completed',
            'settlement_pending',
            'closed',
            'cancelled'
        ))");

        // Driver Mobile OS — trip execution tracking fields
        DB::statement("ALTER TABLE distribution_trips ADD COLUMN IF NOT EXISTS trip_started_at       TIMESTAMP");
        DB::statement("ALTER TABLE distribution_trips ADD COLUMN IF NOT EXISTS trip_start_lat        DECIMAL(10,7)");
        DB::statement("ALTER TABLE distribution_trips ADD COLUMN IF NOT EXISTS trip_start_lng        DECIMAL(10,7)");
        DB::statement("ALTER TABLE distribution_trips ADD COLUMN IF NOT EXISTS trip_finished_at      TIMESTAMP");
        DB::statement("ALTER TABLE distribution_trips ADD COLUMN IF NOT EXISTS trip_finish_lat       DECIMAL(10,7)");
        DB::statement("ALTER TABLE distribution_trips ADD COLUMN IF NOT EXISTS trip_finish_lng       DECIMAL(10,7)");
        DB::statement("ALTER TABLE distribution_trips ADD COLUMN IF NOT EXISTS odometer_end          INTEGER");
        DB::statement("ALTER TABLE distribution_trips ADD COLUMN IF NOT EXISTS total_cash_collected  DECIMAL(12,2) NOT NULL DEFAULT 0");
        DB::statement("ALTER TABLE distribution_trips ADD COLUMN IF NOT EXISTS total_bank_transfers  DECIMAL(12,2) NOT NULL DEFAULT 0");
        DB::statement("ALTER TABLE distribution_trips ADD COLUMN IF NOT EXISTS total_already_paid    DECIMAL(12,2) NOT NULL DEFAULT 0");
    }

    public function down(): void
    {
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

        DB::statement("ALTER TABLE distribution_trips DROP COLUMN IF EXISTS trip_started_at");
        DB::statement("ALTER TABLE distribution_trips DROP COLUMN IF EXISTS trip_start_lat");
        DB::statement("ALTER TABLE distribution_trips DROP COLUMN IF EXISTS trip_start_lng");
        DB::statement("ALTER TABLE distribution_trips DROP COLUMN IF EXISTS trip_finished_at");
        DB::statement("ALTER TABLE distribution_trips DROP COLUMN IF EXISTS trip_finish_lat");
        DB::statement("ALTER TABLE distribution_trips DROP COLUMN IF EXISTS trip_finish_lng");
        DB::statement("ALTER TABLE distribution_trips DROP COLUMN IF EXISTS odometer_end");
        DB::statement("ALTER TABLE distribution_trips DROP COLUMN IF EXISTS total_cash_collected");
        DB::statement("ALTER TABLE distribution_trips DROP COLUMN IF EXISTS total_bank_transfers");
        DB::statement("ALTER TABLE distribution_trips DROP COLUMN IF EXISTS total_already_paid");
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE TABLE IF NOT EXISTS driver_gps_waypoints (
                id                   BIGSERIAL PRIMARY KEY,
                distribution_trip_id UUID NOT NULL REFERENCES distribution_trips(id) ON DELETE CASCADE,
                lat                  DECIMAL(10,7) NOT NULL,
                lng                  DECIMAL(10,7) NOT NULL,
                speed                DECIMAL(6,2),
                accuracy             DECIMAL(6,2),
                recorded_at          TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ");

        DB::statement("CREATE INDEX IF NOT EXISTS driver_gps_waypoints_trip_time_idx
            ON driver_gps_waypoints (distribution_trip_id, recorded_at DESC)");
    }

    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS driver_gps_waypoints");
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE TABLE IF NOT EXISTS driver_delivery_stops (
                id                   UUID NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY,
                distribution_trip_id UUID NOT NULL REFERENCES distribution_trips(id) ON DELETE CASCADE,
                order_id             BIGINT NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
                sequence             INT NOT NULL DEFAULT 0,
                status               VARCHAR(30) NOT NULL DEFAULT 'pending',
                delivery_type        VARCHAR(40),
                collected_amount     DECIMAL(12,2) DEFAULT 0,
                payment_method       VARCHAR(30),
                attempted_at         TIMESTAMP,
                completed_at         TIMESTAMP,
                gps_lat              DECIMAL(10,7),
                gps_lng              DECIMAL(10,7),
                notes                TEXT,
                created_at           TIMESTAMP NOT NULL DEFAULT NOW(),
                updated_at           TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ");

        DB::statement("ALTER TABLE driver_delivery_stops ADD CONSTRAINT driver_delivery_stops_status_check
            CHECK (status IN ('pending','in_progress','delivered','partial','failed','returned','skipped'))");

        DB::statement("CREATE INDEX IF NOT EXISTS driver_delivery_stops_trip_idx    ON driver_delivery_stops (distribution_trip_id)");
        DB::statement("CREATE INDEX IF NOT EXISTS driver_delivery_stops_order_idx   ON driver_delivery_stops (order_id)");
        DB::statement("CREATE INDEX IF NOT EXISTS driver_delivery_stops_status_idx  ON driver_delivery_stops (status)");
    }

    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS driver_delivery_stops");
    }
};

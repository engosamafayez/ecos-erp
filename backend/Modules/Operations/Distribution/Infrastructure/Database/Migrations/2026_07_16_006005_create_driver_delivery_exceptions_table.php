<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE TABLE IF NOT EXISTS driver_delivery_exceptions (
                id                   BIGSERIAL PRIMARY KEY,
                distribution_trip_id UUID NOT NULL REFERENCES distribution_trips(id) ON DELETE CASCADE,
                stop_id              UUID REFERENCES driver_delivery_stops(id) ON DELETE SET NULL,
                order_id             BIGINT NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
                exception_type       VARCHAR(50) NOT NULL,
                description          TEXT NOT NULL,
                photos               JSONB NOT NULL DEFAULT '[]',
                synced_to_cs         BOOLEAN NOT NULL DEFAULT FALSE,
                resolved_at          TIMESTAMP,
                resolved_by          BIGINT REFERENCES users(id) ON DELETE SET NULL,
                resolution_notes     TEXT,
                reported_by          BIGINT REFERENCES users(id) ON DELETE SET NULL,
                created_at           TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ");

        DB::statement("ALTER TABLE driver_delivery_exceptions ADD CONSTRAINT driver_delivery_exceptions_type_check
            CHECK (exception_type IN ('damaged','missing','wrong_product','complaint','packaging','other'))");

        DB::statement("CREATE INDEX IF NOT EXISTS driver_delivery_exceptions_trip_idx  ON driver_delivery_exceptions (distribution_trip_id)");
        DB::statement("CREATE INDEX IF NOT EXISTS driver_delivery_exceptions_order_idx ON driver_delivery_exceptions (order_id)");
    }

    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS driver_delivery_exceptions");
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE TABLE IF NOT EXISTS driver_payment_collections (
                id                   BIGSERIAL PRIMARY KEY,
                stop_id              UUID NOT NULL REFERENCES driver_delivery_stops(id) ON DELETE CASCADE,
                distribution_trip_id UUID NOT NULL REFERENCES distribution_trips(id) ON DELETE CASCADE,
                payment_type         VARCHAR(20) NOT NULL,
                amount               DECIMAL(12,2) NOT NULL DEFAULT 0,
                reference_number     VARCHAR(100),
                notes                TEXT,
                image_path           VARCHAR(500),
                status               VARCHAR(20) NOT NULL DEFAULT 'recorded',
                verified_at          TIMESTAMP,
                verified_by          BIGINT REFERENCES users(id) ON DELETE SET NULL,
                created_at           TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ");

        DB::statement("ALTER TABLE driver_payment_collections ADD CONSTRAINT driver_payment_collections_type_check
            CHECK (payment_type IN ('cash','bank_transfer','already_paid'))");

        DB::statement("ALTER TABLE driver_payment_collections ADD CONSTRAINT driver_payment_collections_status_check
            CHECK (status IN ('recorded','pending_verification','verified','rejected'))");

        DB::statement("CREATE INDEX IF NOT EXISTS driver_payment_collections_trip_idx    ON driver_payment_collections (distribution_trip_id)");
        DB::statement("CREATE INDEX IF NOT EXISTS driver_payment_collections_stop_idx    ON driver_payment_collections (stop_id)");
        DB::statement("CREATE INDEX IF NOT EXISTS driver_payment_collections_type_idx    ON driver_payment_collections (payment_type)");
    }

    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS driver_payment_collections");
    }
};

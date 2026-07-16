<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE TABLE IF NOT EXISTS driver_trip_settlements (
                id                      BIGSERIAL PRIMARY KEY,
                distribution_trip_id    UUID NOT NULL REFERENCES distribution_trips(id) ON DELETE CASCADE,
                cash_collected          DECIMAL(12,2) NOT NULL DEFAULT 0,
                bank_transfers_pending  DECIMAL(12,2) NOT NULL DEFAULT 0,
                already_paid            DECIMAL(12,2) NOT NULL DEFAULT 0,
                total_collected         DECIMAL(12,2) NOT NULL DEFAULT 0,
                cash_expected           DECIMAL(12,2) NOT NULL DEFAULT 0,
                driver_cash_submitted   DECIMAL(12,2),
                discrepancy             DECIMAL(12,2),
                status                  VARCHAR(20) NOT NULL DEFAULT 'draft',
                finalized_at            TIMESTAMP,
                finalized_by            BIGINT REFERENCES users(id) ON DELETE SET NULL,
                notes                   TEXT,
                created_at              TIMESTAMP NOT NULL DEFAULT NOW(),
                updated_at              TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ");

        DB::statement("ALTER TABLE driver_trip_settlements ADD CONSTRAINT driver_trip_settlements_status_check
            CHECK (status IN ('draft','submitted','verified','closed'))");

        DB::statement("CREATE INDEX IF NOT EXISTS driver_trip_settlements_trip_idx ON driver_trip_settlements (distribution_trip_id)");
    }

    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS driver_trip_settlements");
    }
};

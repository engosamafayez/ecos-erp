<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE TABLE IF NOT EXISTS driver_custody_returns (
                id                   BIGSERIAL PRIMARY KEY,
                distribution_trip_id UUID NOT NULL REFERENCES distribution_trips(id) ON DELETE CASCADE,
                custody_type         VARCHAR(50) NOT NULL,
                dispatched_qty       INT NOT NULL DEFAULT 0,
                returned_qty         INT,
                driver_liable        BOOLEAN NOT NULL DEFAULT FALSE,
                notes                TEXT,
                confirmed_by         BIGINT REFERENCES users(id) ON DELETE SET NULL,
                confirmed_at         TIMESTAMP,
                created_at           TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ");

        DB::statement("CREATE INDEX IF NOT EXISTS driver_custody_returns_trip_idx ON driver_custody_returns (distribution_trip_id)");
    }

    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS driver_custody_returns");
    }
};

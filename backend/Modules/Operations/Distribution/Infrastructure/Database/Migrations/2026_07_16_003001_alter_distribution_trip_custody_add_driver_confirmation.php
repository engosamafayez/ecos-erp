<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE distribution_trip_custody ADD COLUMN IF NOT EXISTS received_quantity SMALLINT");
        DB::statement("ALTER TABLE distribution_trip_custody ADD COLUMN IF NOT EXISTS is_driver_confirmed BOOLEAN NOT NULL DEFAULT false");
        DB::statement("ALTER TABLE distribution_trip_custody ADD COLUMN IF NOT EXISTS driver_confirmed_at TIMESTAMP");
        DB::statement("ALTER TABLE distribution_trip_custody ADD COLUMN IF NOT EXISTS driver_confirmed_by BIGINT");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE distribution_trip_custody DROP COLUMN IF EXISTS received_quantity");
        DB::statement("ALTER TABLE distribution_trip_custody DROP COLUMN IF EXISTS is_driver_confirmed");
        DB::statement("ALTER TABLE distribution_trip_custody DROP COLUMN IF EXISTS driver_confirmed_at");
        DB::statement("ALTER TABLE distribution_trip_custody DROP COLUMN IF EXISTS driver_confirmed_by");
    }
};

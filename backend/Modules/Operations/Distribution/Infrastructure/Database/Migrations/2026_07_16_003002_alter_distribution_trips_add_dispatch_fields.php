<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE distribution_trips ADD COLUMN IF NOT EXISTS dispatched_at TIMESTAMP");
        DB::statement("ALTER TABLE distribution_trips ADD COLUMN IF NOT EXISTS dispatched_by BIGINT");
        DB::statement("ALTER TABLE distribution_trips ADD COLUMN IF NOT EXISTS driver_notified_at TIMESTAMP");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE distribution_trips DROP COLUMN IF EXISTS dispatched_at");
        DB::statement("ALTER TABLE distribution_trips DROP COLUMN IF EXISTS dispatched_by");
        DB::statement("ALTER TABLE distribution_trips DROP COLUMN IF EXISTS driver_notified_at");
    }
};

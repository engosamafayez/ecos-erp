<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE distribution_loading_manifest_items ADD COLUMN IF NOT EXISTS driver_received_qty DECIMAL(14,3)");
        DB::statement("ALTER TABLE distribution_loading_manifest_items ADD COLUMN IF NOT EXISTS driver_status VARCHAR(30) NOT NULL DEFAULT 'pending'");
        DB::statement("ALTER TABLE distribution_loading_manifest_items ADD COLUMN IF NOT EXISTS driver_confirmed_at TIMESTAMP");
        DB::statement("ALTER TABLE distribution_loading_manifest_items ADD COLUMN IF NOT EXISTS driver_confirmed_by BIGINT");

        DB::statement("ALTER TABLE distribution_loading_manifest_items DROP CONSTRAINT IF EXISTS dlmi_driver_status_check");
        DB::statement("ALTER TABLE distribution_loading_manifest_items ADD CONSTRAINT dlmi_driver_status_check CHECK (driver_status IN ('pending','confirmed','discrepancy','accepted'))");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE distribution_loading_manifest_items DROP COLUMN IF EXISTS driver_received_qty");
        DB::statement("ALTER TABLE distribution_loading_manifest_items DROP COLUMN IF EXISTS driver_status");
        DB::statement("ALTER TABLE distribution_loading_manifest_items DROP COLUMN IF EXISTS driver_confirmed_at");
        DB::statement("ALTER TABLE distribution_loading_manifest_items DROP COLUMN IF EXISTS driver_confirmed_by");
    }
};

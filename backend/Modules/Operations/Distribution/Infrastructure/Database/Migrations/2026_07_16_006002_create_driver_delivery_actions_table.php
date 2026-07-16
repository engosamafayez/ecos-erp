<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE TABLE IF NOT EXISTS driver_delivery_actions (
                id               BIGSERIAL PRIMARY KEY,
                stop_id          UUID NOT NULL REFERENCES driver_delivery_stops(id) ON DELETE CASCADE,
                action_type      VARCHAR(40) NOT NULL,
                reason           VARCHAR(255),
                notes            TEXT,
                new_delivery_date DATE,
                corrected_lat    DECIMAL(10,7),
                corrected_lng    DECIMAL(10,7),
                performed_by     BIGINT REFERENCES users(id) ON DELETE SET NULL,
                created_at       TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ");

        DB::statement("CREATE INDEX IF NOT EXISTS driver_delivery_actions_stop_idx ON driver_delivery_actions (stop_id)");
    }

    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS driver_delivery_actions");
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE TABLE IF NOT EXISTS distribution_order_sync_events (
                id               BIGSERIAL    PRIMARY KEY,
                order_id         BIGINT       NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
                distribution_trip_id UUID    REFERENCES distribution_trips(id) ON DELETE SET NULL,
                action           VARCHAR(60)  NOT NULL,
                trip_stage       VARCHAR(40),
                changed_fields   JSONB,
                previous_values  JSONB,
                new_values       JSONB,
                performed_by     BIGINT       REFERENCES users(id) ON DELETE SET NULL,
                manifest_regenerated BOOLEAN  NOT NULL DEFAULT FALSE,
                notes            TEXT,
                synced_at        TIMESTAMP    NOT NULL DEFAULT NOW()
            )
        ");

        DB::statement('CREATE INDEX IF NOT EXISTS idx_dist_order_sync_order ON distribution_order_sync_events(order_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_dist_order_sync_trip  ON distribution_order_sync_events(distribution_trip_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_dist_order_sync_at    ON distribution_order_sync_events(synced_at DESC)');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS distribution_order_sync_events');
    }
};

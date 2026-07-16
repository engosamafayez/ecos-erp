<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE TABLE IF NOT EXISTS driver_delivery_returns (
                id                      BIGSERIAL PRIMARY KEY,
                distribution_trip_id    UUID NOT NULL REFERENCES distribution_trips(id) ON DELETE CASCADE,
                order_id                BIGINT NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
                product_id              BIGINT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
                product_name            VARCHAR(255),
                return_type             VARCHAR(20) NOT NULL DEFAULT 'full',
                returned_qty            DECIMAL(12,3) NOT NULL,
                reason                  TEXT,
                photos                  JSONB NOT NULL DEFAULT '[]',
                warehouse_confirmed_qty DECIMAL(12,3),
                warehouse_confirmed_at  TIMESTAMP,
                warehouse_confirmed_by  BIGINT REFERENCES users(id) ON DELETE SET NULL,
                discrepancy_qty         DECIMAL(12,3),
                driver_liability        BOOLEAN NOT NULL DEFAULT FALSE,
                reported_by             BIGINT REFERENCES users(id) ON DELETE SET NULL,
                created_at              TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ");

        DB::statement("ALTER TABLE driver_delivery_returns ADD CONSTRAINT driver_delivery_returns_type_check
            CHECK (return_type IN ('full','partial'))");

        DB::statement("CREATE INDEX IF NOT EXISTS driver_delivery_returns_trip_idx  ON driver_delivery_returns (distribution_trip_id)");
        DB::statement("CREATE INDEX IF NOT EXISTS driver_delivery_returns_order_idx ON driver_delivery_returns (order_id)");
    }

    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS driver_delivery_returns");
    }
};

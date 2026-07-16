<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE TABLE IF NOT EXISTS driver_delivery_proofs (
                id             BIGSERIAL PRIMARY KEY,
                stop_id        UUID NOT NULL REFERENCES driver_delivery_stops(id) ON DELETE CASCADE,
                signature_path VARCHAR(500),
                photos         JSONB NOT NULL DEFAULT '[]',
                notes          TEXT,
                captured_at    TIMESTAMP NOT NULL DEFAULT NOW(),
                captured_by    BIGINT REFERENCES users(id) ON DELETE SET NULL
            )
        ");

        DB::statement("CREATE INDEX IF NOT EXISTS driver_delivery_proofs_stop_idx ON driver_delivery_proofs (stop_id)");
    }

    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS driver_delivery_proofs");
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE TABLE IF NOT EXISTS distribution_trip_audit_trail (
            id           BIGSERIAL PRIMARY KEY,
            distribution_trip_id UUID NOT NULL REFERENCES distribution_trips(id) ON DELETE CASCADE,
            action       VARCHAR(50)  NOT NULL,
            from_status  VARCHAR(30),
            to_status    VARCHAR(30),
            performed_by BIGINT       REFERENCES users(id) ON DELETE SET NULL,
            notes        TEXT,
            metadata     JSONB,
            performed_at TIMESTAMP    NOT NULL DEFAULT NOW()
        )");

        DB::statement("CREATE INDEX IF NOT EXISTS dtgt_trip_performed_idx ON distribution_trip_audit_trail (distribution_trip_id, performed_at DESC)");
    }

    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS distribution_trip_audit_trail");
    }
};

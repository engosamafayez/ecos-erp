<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_sessions', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('terminal_id');
            $table->uuid('cashier_id');
            $table->string('status', 50)->default('open');
            $table->string('device_fingerprint', 255);
            $table->string('device_type', 50)->default('browser');
            $table->string('ip_address', 45);
            $table->timestamp('opened_at');
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index('terminal_id');
            $table->index('cashier_id');
            $table->index('status');
        });

        // Partial unique index: only one Open session per terminal at a time.
        DB::statement(
            "CREATE UNIQUE INDEX pos_sessions_one_open_per_terminal ON pos_sessions (terminal_id) WHERE status = 'open'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_sessions');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_sessions', static function (Blueprint $table): void {
            // Operational context replaces the Terminal dependency.
            // terminal_id column is retained for backward compat with shifts/carts/receipts
            // but is now populated with cashier_id (stable per-user UUID) instead of
            // a physical terminal UUID. No FK constraints per ADR-POS-001.
            $table->uuid('company_id')->nullable()->after('cashier_id');
            $table->uuid('channel_id')->nullable()->after('company_id');
            $table->uuid('warehouse_id')->nullable()->after('channel_id');

            $table->index('company_id');
            $table->index('warehouse_id');
        });
    }

    public function down(): void
    {
        Schema::table('pos_sessions', static function (Blueprint $table): void {
            $table->dropIndex(['company_id']);
            $table->dropIndex(['warehouse_id']);
            $table->dropColumn(['company_id', 'channel_id', 'warehouse_id']);
        });
    }
};

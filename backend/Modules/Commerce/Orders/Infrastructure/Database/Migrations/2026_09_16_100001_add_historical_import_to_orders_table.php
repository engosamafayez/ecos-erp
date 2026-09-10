<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-ECOS-WOOCOMMERCE-SYNC-CONTROLS-AND-HISTORICAL-IMPORT-025 (W3/W12).
 *
 * The explicit boundary between LIVE operational ingestion and HISTORICAL state import.
 * `is_historical_import = true` is written ONLY by a deliberately-invoked historical import
 * call (never by the live import/webhook path) and is the durable signal every current and
 * future fulfilment-surface consumer can filter on, instead of relying on the accidental
 * (assigned_warehouse_id-is-always-null) gap TASK-...-024 found and flagged as unsafe to trust.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', static function (Blueprint $table): void {
            $table->boolean('is_historical_import')->default(false)->after('external_order_id');
            $table->uuid('historical_import_batch_id')->nullable()->after('is_historical_import');
        });
    }

    public function down(): void
    {
        Schema::table('orders', static function (Blueprint $table): void {
            $table->dropColumn(['is_historical_import', 'historical_import_batch_id']);
        });
    }
};

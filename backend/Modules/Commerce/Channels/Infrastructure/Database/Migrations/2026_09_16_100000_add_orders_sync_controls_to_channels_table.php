<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-ECOS-WOOCOMMERCE-SYNC-CONTROLS-AND-HISTORICAL-IMPORT-025 (W5/W6/W9/W10).
 *
 * `sync_orders` defaults TRUE — deliberately, to preserve today's actual behaviour (Orders
 * ingestion has no gate at all today) for every existing channel. This introduces the ability
 * to pause; it must not silently pause anyone on deploy.
 *
 * `orders_sync_watermark_at` defaults NULL — "no watermark yet" for channels that predate this
 * feature. `WooCommerceOrderImporter::import()` treats NULL as "no `after` filter" (today's
 * exact unbounded-scan behaviour, unchanged) and starts writing a real watermark from the first
 * successful run onward, so existing channels evolve into incremental sync automatically instead
 * of being retroactively cut off from orders they haven't seen yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', static function (Blueprint $table): void {
            $table->boolean('sync_orders')->default(true)->after('sync_customers');
            $table->timestamp('orders_sync_watermark_at')->nullable()->after('sync_orders');
            $table->string('orders_initial_import_policy', 30)->nullable()->after('orders_sync_watermark_at');
            $table->timestamp('orders_initial_import_cutoff_at')->nullable()->after('orders_initial_import_policy');
            $table->timestamp('orders_sync_activated_at')->nullable()->after('orders_initial_import_cutoff_at');
            $table->uuid('orders_sync_activated_by')->nullable()->after('orders_sync_activated_at');
        });
    }

    public function down(): void
    {
        Schema::table('channels', static function (Blueprint $table): void {
            $table->dropColumn([
                'sync_orders',
                'orders_sync_watermark_at',
                'orders_initial_import_policy',
                'orders_initial_import_cutoff_at',
                'orders_sync_activated_at',
                'orders_sync_activated_by',
            ]);
        });
    }
};

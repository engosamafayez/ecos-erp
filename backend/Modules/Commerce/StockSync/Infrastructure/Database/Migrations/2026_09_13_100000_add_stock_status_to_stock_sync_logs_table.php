<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-...-CONSOLIDATED-REMEDIATION-001-R1/R2 (CTO business-rule correction) — WooCommerce
 * stock synchronization is now an absolute availability STATE (instock/outofstock), never a
 * finished-product quantity. Additive: `stock_quantity` is widened to nullable rather than
 * dropped, so every historical row (written before this correction) stays intact and
 * queryable exactly as before; new rows populate `stock_status` instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_sync_logs', function (Blueprint $table): void {
            $table->string('stock_status')->nullable()->after('stock_quantity');
        });

        Schema::table('stock_sync_logs', function (Blueprint $table): void {
            $table->decimal('stock_quantity', 15, 4)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('stock_sync_logs', function (Blueprint $table): void {
            $table->dropColumn('stock_status');
        });
    }
};

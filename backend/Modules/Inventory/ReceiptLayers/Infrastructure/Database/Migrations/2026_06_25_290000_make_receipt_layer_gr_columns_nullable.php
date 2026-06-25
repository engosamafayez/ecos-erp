<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adjustment-in receipt layers (from stock count approval) have no goods receipt.
 * Making these nullable allows the FIFO layer table to be used for both
 * purchase-receipt layers and inventory-adjustment layers uniformly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_receipt_layers', function (Blueprint $table): void {
            // Drop FK constraints, then re-add as nullable (adjustment-in layers have no GR/supplier)
            $table->dropForeign(['supplier_id']);
            $table->dropForeign(['goods_receipt_id']);
            $table->dropForeign(['goods_receipt_line_id']);

            $table->uuid('supplier_id')->nullable()->change();
            $table->uuid('goods_receipt_id')->nullable()->change();
            $table->uuid('goods_receipt_line_id')->nullable()->change();

            $table->foreign('supplier_id')
                ->references('id')->on('suppliers')->cascadeOnDelete();
            $table->foreign('goods_receipt_id')
                ->references('id')->on('goods_receipts')->cascadeOnDelete();
            $table->foreign('goods_receipt_line_id')
                ->references('id')->on('goods_receipt_lines')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_receipt_layers', function (Blueprint $table): void {
            $table->dropForeign(['supplier_id']);
            $table->dropForeign(['goods_receipt_id']);
            $table->dropForeign(['goods_receipt_line_id']);

            $table->uuid('supplier_id')->nullable(false)->change();
            $table->uuid('goods_receipt_id')->nullable(false)->change();
            $table->uuid('goods_receipt_line_id')->nullable(false)->change();

            $table->foreign('supplier_id')
                ->references('id')->on('suppliers')->cascadeOnDelete();
            $table->foreign('goods_receipt_id')
                ->references('id')->on('goods_receipts')->cascadeOnDelete();
            $table->foreign('goods_receipt_line_id')
                ->references('id')->on('goods_receipt_lines')->cascadeOnDelete();
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-OPERATIONAL-FULFILLMENT-RETURNS-RECONCILIATION-001 — warehouse return-receipt
 * classification on the shift reconciliation line.
 *
 * The line already carries loaded / delivered / quantity_returned_expected /
 * quantity_returned_actual / variance. The warehouse receipt additionally splits the
 * physically-received quantity into accepted (good, restocked) vs damaged (never admitted
 * to good stock), records an optional damage reason, and stamps warehouse_receipt_at as
 * the idempotency marker for the receipt. Shortage stays derived (= variance =
 * expected − actual) and is not stored, so there is a single source for it.
 *
 * Additive columns only — no existing column is altered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_shift_reconciliation_lines', function (Blueprint $table): void {
            $table->decimal('quantity_accepted', 18, 4)->default(0)->after('quantity_returned_actual');
            $table->decimal('quantity_damaged', 18, 4)->default(0)->after('quantity_accepted');
            $table->string('damage_reason')->nullable()->after('quantity_damaged');
            $table->timestamp('warehouse_receipt_at')->nullable()->after('damage_reason');
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_shift_reconciliation_lines', function (Blueprint $table): void {
            $table->dropColumn(['quantity_accepted', 'quantity_damaged', 'damage_reason', 'warehouse_receipt_at']);
        });
    }
};

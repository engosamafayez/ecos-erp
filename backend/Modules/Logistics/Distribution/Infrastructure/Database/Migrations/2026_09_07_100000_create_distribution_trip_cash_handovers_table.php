<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-ECOS-DRIVER-SETTLEMENT-TREASURY-FINAL-IMPLEMENTATION-002.
 *
 * Treasury's physical receipt of a driver's handed-back cash for one trip
 * settlement. Exactly one per TripSettlement (unique trip_settlement_id) —
 * the append-only idempotency record for the Cash Handover confirmation,
 * mirroring distribution_trip_settlements' own "one per trip" convention and
 * Modules\Finance\Posting's finance_posted_event_receipts append-only pattern.
 *
 * cash_account_id / cash_transaction_id are PLAIN reference columns, not hard
 * foreign keys — no existing migration under Modules/Logistics declares a
 * cross-module FK against a Finance table (verified before writing this file),
 * and this preserves that modular-monolith boundary rather than introducing a
 * new one. Tenancy and existence are enforced in application code
 * (CashHandoverService), exactly like every other cross-module reference in
 * this module (e.g. VehicleInventoryItem, VehicleShiftReconciliationLine are
 * read the same way from Modules\Operations\Loading).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('distribution_trip_cash_handovers')) {
            return;
        }

        Schema::create('distribution_trip_cash_handovers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->uuid('company_id');
            $table->foreignId('trip_settlement_id')->constrained('distribution_trip_settlements')->restrictOnDelete();
            $table->foreignId('trip_id')->constrained('distribution_trips')->restrictOnDelete();

            // A. Driver Declared Cash (snapshot; may be null — the driver may not have
            //    declared through TripSettlement::driver_cash_submitted).
            $table->decimal('driver_declared_cash', 12, 2)->nullable();
            // B. System Expected Cash (snapshot of the canonical Net Cash formula,
            //    computed at confirmation time — never recomputed retroactively).
            $table->decimal('expected_cash', 12, 2);
            // C. Treasury Physically Received Cash — the ONLY figure ever posted to Finance.
            $table->decimal('received_cash', 12, 2);
            // received_cash − expected_cash. Positive = over; negative = short.
            $table->decimal('difference', 12, 2);

            // Soft cross-module references — see class docblock.
            $table->unsignedBigInteger('cash_account_id');
            $table->unsignedBigInteger('cash_transaction_id')->nullable();

            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('confirmed_at');

            $table->text('notes')->nullable();
            $table->timestamps();

            // One handover per settlement — the database-level half of the idempotency
            // guarantee (the application-level half is CashHandoverService's
            // lockForUpdate on the settlement row, taken BEFORE this constraint is ever
            // reached, so a concurrent second request never gets far enough to race the
            // Finance posting call — see the service's docblock for why this ordering
            // matters: CashService::recordTransaction() mints its own uuid per call and
            // is not itself idempotent across repeated calls).
            $table->unique('trip_settlement_id', 'distribution_cash_handovers_settlement_unique');
            $table->index(['company_id', 'trip_id']);
            $table->index('cash_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distribution_trip_cash_handovers');
    }
};

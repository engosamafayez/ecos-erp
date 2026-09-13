<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-ECOS-V1.1-OPS-03-TASK1-BOSTA — the bounded external-carrier execution
 * record this task's own reconciliation found missing (OPS-03 §8: "no suitable
 * authority exists ... create the smallest bounded external-carrier execution
 * record linked to the canonical Trip/Shipping Order, not a replacement
 * Shipping Order"). Two tables:
 *
 *  - carrier_shipments: one row per DeliveryStop tendered to an external
 *    carrier — the external reference/tracking/label facts a real Bosta
 *    integration produces. NOT a second Shipping Order: it has no line items,
 *    no pricing, no customer data of its own — every business fact still
 *    reads through the canonical Trip/DeliveryStop/Order.
 *  - carrier_webhook_events: the idempotency/replay ledger the carrier
 *    integration architecture requires (docs/logistics-v2/06-EXTERNAL-CARRIER-
 *    PLATFORM.md §6.5: "persist raw, then acknowledge, then process" /
 *    "deduplicate by the carrier's own event id" / "replayable").
 *
 * No carrier_cost / insurance_amount column is added here: neither of the two
 * verified Bosta contract references consulted for this task (INTEGRATION-
 * CATALOG.md §3.4, ANTI-CORRUPTION-LAYER.md §5) document Bosta returning a
 * real payable-cost or insurance figure, and this task's own instructions
 * forbid inventing one. Add the column in a later migration if and when a
 * verified Bosta response field is confirmed — not before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carrier_shipments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->uuid('company_id');
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();

            $table->foreignId('trip_id')->constrained('distribution_trips')->cascadeOnDelete();

            // One carrier tender per stop — a stop is tendered to exactly one
            // carrier at a time; re-tendering after a cancellation is a new row.
            $table->foreignId('delivery_stop_id')
                ->constrained('distribution_delivery_stops')->cascadeOnDelete();

            $table->foreignId('carrier_account_id')
                ->constrained('carrier_accounts')->cascadeOnDelete();

            // The carrier's own shipment identity — "bosta_reference" in the
            // Bosta ACL doc, generalised here since this table is not Bosta-
            // specific (any tendering adapter populates the same columns).
            $table->string('external_reference', 120)->nullable();
            $table->string('tracking_number', 120)->nullable();
            $table->string('label_url', 500)->nullable();

            // Last raw carrier status string — recorded even when unmapped, so
            // an integration gap is visible rather than silently absent.
            $table->string('raw_status', 80)->nullable();
            $table->timestamp('last_event_at')->nullable();

            // What ECOS told the carrier to collect on delivery (COD orders
            // only) — a factual echo of what was sent, never a settlement
            // record. See OPS-03 §23: no cash custody / ledger built on this.
            $table->decimal('cod_amount', 12, 2)->nullable();
            $table->string('currency', 3)->default('EGP');

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique('delivery_stop_id', 'carrier_shipment_stop_unique');
            $table->index('external_reference', 'carrier_shipment_external_ref_idx');
            $table->index(['carrier_account_id', 'raw_status'], 'carrier_shipment_account_status_idx');
        });

        Schema::create('carrier_webhook_events', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('carrier_account_id')
                ->constrained('carrier_accounts')->cascadeOnDelete();

            // The carrier's own event id — the true idempotency key. Duplicate
            // delivery of the same event is normal carrier behaviour, not an error.
            $table->string('carrier_event_id', 150);

            // Persisted immediately, before processing — replayable if a
            // mapping bug is later fixed (06-EXTERNAL-CARRIER-PLATFORM.md §6.5).
            $table->json('raw_payload');
            $table->timestamp('processed_at')->nullable();
            $table->text('processing_error')->nullable();

            $table->timestamps();

            $table->unique(['carrier_account_id', 'carrier_event_id'], 'carrier_webhook_event_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carrier_webhook_events');
        Schema::dropIfExists('carrier_shipments');
    }
};

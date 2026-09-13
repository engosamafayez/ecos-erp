<?php

declare(strict_types=1);

namespace Modules\Logistics\Carriers\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Logistics\Carriers\Application\Services\ApplyCarrierDeliveryOutcomeService;
use Modules\Logistics\Carriers\Domain\Models\CarrierAccount;
use Modules\Logistics\Carriers\Domain\Models\CarrierShipment;
use Modules\Logistics\Carriers\Domain\Models\CarrierWebhookEvent;
use Modules\Logistics\Carriers\Domain\Services\CarrierAdapterFactory;

/**
 * The authenticated per-account carrier webhook receiver (TASK-ECOS-V1.1-
 * OPS-03-TASK1-BOSTA, Section 11/12), matching the exact route/flow shape
 * docs/logistics-v2/06-EXTERNAL-CARRIER-PLATFORM.md §6.5 already specifies:
 * resolve CarrierAccount by its account uuid -> adapter signature
 * verification -> persist raw (idempotent) -> parseWebhook() -> canonical
 * consumer. Deliberately OUTSIDE auth:sanctum — the caller is the carrier,
 * not an ECOS user; the adapter's own signature verification is the only
 * trust boundary, and it fails closed by default (Section 27: "Do not
 * silently fall back... Do not create fake success").
 *
 * Processed synchronously within the request rather than queued: the design
 * doc's own "persist raw, then acknowledge, then process" principle is still
 * honoured (the raw payload is persisted before any parsing is attempted),
 * but a real background queue stage was judged out of this task's scope —
 * signature verification is not yet verifiable for Bosta (see
 * BostaCarrierAdapter::verifyWebhookSignature()), so no live traffic can
 * reach processing yet regardless. Converting this to a queued job is a
 * small, later change that touches only this controller.
 */
final class CarrierWebhookController extends Controller
{
    public function __construct(
        private readonly CarrierAdapterFactory $adapters,
        private readonly ApplyCarrierDeliveryOutcomeService $outcomes,
    ) {}

    public function handle(Request $request, string $carrier, string $accountUuid): JsonResponse
    {
        $account = CarrierAccount::where('uuid', $accountUuid)
            ->where('adapter_key', $carrier)
            ->where('status', CarrierAccount::STATUS_ACTIVE)
            ->first();

        // Deliberately a generic 404 — never confirm/deny account existence
        // to an unauthenticated caller beyond "this endpoint has nothing for
        // you," matching the same posture as every other not-found response
        // in this codebase (never a distinguishable 403 that leaks existence).
        if ($account === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $adapter = $this->adapters->for($account);
        $payload = (array) $request->json()->all();

        if (! $adapter->verifyWebhookSignature($account, $payload, $request->headers->all())) {
            Log::channel('daily')->warning('[CarrierWebhookController] Signature verification failed or unimplemented', [
                'carrier' => $carrier,
                'carrier_account_id' => $account->id,
            ]);

            return response()->json(['message' => 'Signature verification failed.'], 401);
        }

        $event = $adapter->parseWebhook($account, $payload);

        // Persist raw + dedupe FIRST, acknowledge fast — Section 12 / design
        // doc §6.5. The unique index is the actual idempotency guard: a
        // second insert for the same (account, event id) fails silently into
        // firstOrCreate's "already exists" branch, never a duplicate effect.
        [$webhookEvent, $isNew] = DB::transaction(function () use ($account, $event, $payload) {
            $existing = CarrierWebhookEvent::where('carrier_account_id', $account->id)
                ->where('carrier_event_id', $event->carrierEventId)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return [$existing, false];
            }

            return [
                CarrierWebhookEvent::create([
                    'carrier_account_id' => $account->id,
                    'carrier_event_id' => $event->carrierEventId,
                    'raw_payload' => $payload,
                ]),
                true,
            ];
        });

        if (! $isNew) {
            // Repeated webhook -> one canonical effect (Section 12): already
            // recorded and (if it reached that point) already processed.
            return response()->json(['status' => 'duplicate_ignored'], 200);
        }

        $trackingNumber = $event->trackingNumber;
        $shipment = $trackingNumber !== null
            ? CarrierShipment::where('carrier_account_id', $account->id)
                ->where('external_reference', $trackingNumber)
                ->first()
            : null;

        if ($shipment === null) {
            $webhookEvent->update([
                'processing_error' => 'No matching CarrierShipment for this event\'s tracking/reference number.',
            ]);

            return response()->json(['status' => 'no_matching_shipment'], 200);
        }

        $result = $this->outcomes->apply($shipment, $event);

        $webhookEvent->update(['processed_at' => now()]);

        return response()->json(['status' => 'processed', 'applied' => $result['applied'], 'reason' => $result['reason']]);
    }
}

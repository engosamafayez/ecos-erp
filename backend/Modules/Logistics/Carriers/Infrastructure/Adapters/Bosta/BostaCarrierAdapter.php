<?php

declare(strict_types=1);

namespace Modules\Logistics\Carriers\Infrastructure\Adapters\Bosta;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Modules\Logistics\Carriers\Domain\Contracts\CarrierAdapterInterface;
use Modules\Logistics\Carriers\Domain\Contracts\TenderingCarrierAdapterInterface;
use Modules\Logistics\Carriers\Domain\Exceptions\CarrierException;
use Modules\Logistics\Carriers\Domain\Models\CarrierAccount;
use Modules\Logistics\Carriers\Domain\ValueObjects\CarrierCapabilitySet;
use Modules\Logistics\Carriers\Domain\ValueObjects\NormalizedCarrierEvent;

/**
 * Bosta — the first real external carrier (TASK-ECOS-V1.1-OPS-03-TASK1-BOSTA).
 *
 * ┌─ CONTRACT SOURCE (Section 3 compliance) ────────────────────────────────┐
 * │ This adapter implements ONLY what two pre-existing, real repository       │
 * │ documents verify — nothing was invented from general knowledge:           │
 * │                                                                            │
 * │  - docs/contracts/INTEGRATION-CATALOG.md §3.4 "Bosta (Logistics /          │
 * │    Shipping)": API Key auth, POST /deliveries (create), GET /deliveries/  │
 * │    {id} (status), webhook for status updates, API version "/v2/" (path    │
 * │    pinned), 3 retries with backoff, idempotency via a stored reference.   │
 * │  - docs/contracts/ANTI-CORRUPTION-LAYER.md §5 "Bosta (Logistics           │
 * │    Provider) ACL": the exact outbound field mapping (shipment.id ->       │
 * │    business_reference / order.delivery_address -> receiver / order.      │
 * │    total_amount -> cod_amount when COD / warehouse.address ->             │
 * │    pickup_address / order.lines -> order_description) and the exact       │
 * │    inbound status vocabulary (Delivered / Not Available / Refused / In    │
 * │    Transit / Returned) with their ECOS-side effect.                       │
 * │                                                                            │
 * │ NEITHER document specifies: the exact host domain, the exact response     │
 * │ JSON field names, a cancellation endpoint, a webhook signature scheme, or │
 * │ a carrier-payable-cost / insurance field. None of those is implemented —  │
 * │ see the PARTIAL notes on each method below, per this task's own           │
 * │ instruction to return PARTIAL rather than fabricate.                      │
 * └────────────────────────────────────────────────────────────────────────┘
 *
 * CREDENTIALS: CarrierAccount.provider_reference is retained as the per-
 * account pointer/label this architecture already expects, but the only
 * concrete "Provider Platform" implementation found in this codebase
 * (Modules\Marketing\ProviderConfig\...\ProviderCredentialService) is
 * OAuth-app-id/app-secret machinery built for Meta/Google Ads-style
 * connectors — it does not fit a plain API-key carrier and reusing it would
 * be a real architectural mismatch, not a genuine reuse. Rather than force
 * that fit or build a second bespoke vault (both forbidden by this task),
 * the API key is resolved through Laravel's OWN standard encrypted
 * config/env layer (config('services.bosta.*')) — the platform's existing,
 * standard secret mechanism, not a new one.
 */
final class BostaCarrierAdapter implements CarrierAdapterInterface, TenderingCarrierAdapterInterface
{
    public const KEY = 'bosta';

    /** Bosta's own status vocabulary, verified in ANTI-CORRUPTION-LAYER.md §5. Recommended seed values for CarrierStatusMapping — applied via the existing upsertStatusMapping endpoint, never auto-seeded silently. */
    public const RECOMMENDED_STATUS_MAPPINGS = [
        // carrier_status => [delivery_status (Distribution\DeliveryStopStatus value), failure_reason (Delivery\FailureReason value)]
        'Delivered' => ['delivered', null],
        'Not Available' => ['failed', 'customer_unavailable'],
        'Refused' => ['failed', 'customer_refused'],
        'Returned' => ['returned', null],
        // "In Transit" is deliberately NOT a settling status — no ECOS transition,
        // recorded as the shipment's raw_status only (see ApplyCarrierDeliveryOutcomeService).
        'In Transit' => [null, null],
    ];

    public function key(): string
    {
        return self::KEY;
    }

    public function displayName(): string
    {
        return 'Bosta';
    }

    /**
     * Only what the verified contract actually supports. Deliberately excludes
     * RATING (no rate-shopping endpoint verified), LABEL_GENERATION (no
     * response field for a label URL verified), CANCELLATION (no cancel
     * endpoint verified), PROOF_OF_DELIVERY (not verified) and MULTI_PIECE
     * (not verified) — declaring any of these would violate Section 5's "must
     * match implemented behaviour."
     */
    public function capabilities(CarrierAccount $account): CarrierCapabilitySet
    {
        return CarrierCapabilitySet::of([
            CarrierCapabilitySet::TRACKING,
            CarrierCapabilitySet::WEBHOOKS,
            CarrierCapabilitySet::COD,
        ]);
    }

    /**
     * PARTIAL: no dedicated Bosta health/ping endpoint is verified in either
     * source document, so this cannot be a genuine round-trip connectivity
     * test without risking an invented endpoint. It verifies only that a key
     * is configured — real reachability is proven the first time
     * createShipment()/getShipmentStatus() actually runs.
     */
    public function testConnection(CarrierAccount $account): array
    {
        if (! $this->hasApiKey()) {
            return [
                'ok' => false,
                'message' => 'No Bosta API key configured (services.bosta.api_key). Store it before connecting an account.',
                'checked_at' => Carbon::now()->toIso8601String(),
            ];
        }

        return [
            'ok' => true,
            'message' => 'A Bosta API key is configured. No verified health/ping endpoint exists to confirm live reachability — the first real shipment or status call is the actual connectivity proof.',
            'checked_at' => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * Outbound: ECOS shipment context -> Bosta POST /deliveries.
     *
     * Field mapping verified in ANTI-CORRUPTION-LAYER.md §5. $shipmentContext
     * is built by CreateExternalCarrierShipmentAction from the canonical
     * Order/DeliveryStop/Trip — this adapter never reads those models itself
     * (Directive 9: an adapter returns/consumes data, never reaches into
     * another context's domain).
     *
     * @param  array<string, mixed>  $shipmentContext
     * @return array{external_reference: string, tracking_number: ?string, label_url: ?string, raw_status: ?string, raw_response: array<string, mixed>}
     */
    public function createShipment(CarrierAccount $account, array $shipmentContext): array
    {
        $payload = [
            'business_reference' => $shipmentContext['business_reference'],
            'receiver' => [
                'name' => $shipmentContext['receiver_name'],
                'phone' => $shipmentContext['receiver_phone'],
                'address' => $shipmentContext['receiver_address'],
            ],
            'pickup_address' => $shipmentContext['pickup_address'],
            'order_description' => $shipmentContext['order_description'],
        ];

        if ($shipmentContext['cod_amount'] !== null) {
            $payload['cod_amount'] = $shipmentContext['cod_amount'];
        }

        $response = $this->request($account)->post($this->endpoint('/deliveries'), $payload);

        if ($response->failed()) {
            throw CarrierException::requestFailed(
                $this->displayName(),
                "POST /deliveries returned HTTP {$response->status()}: ".$this->safeBody($response),
            );
        }

        $body = (array) $response->json();

        // Response field names are NOT verified by either source document —
        // Section 3 only confirms the request-side mapping. Read defensively
        // across the plausible key shapes a REST delivery API commonly uses,
        // rather than asserting one guessed schema.
        $externalReference = $this->firstPresent($body, ['_id', 'id', 'trackingNumber', 'business_reference'])
            ?? $shipmentContext['business_reference'];

        return [
            'external_reference' => (string) $externalReference,
            'tracking_number' => $this->firstPresent($body, ['trackingNumber', 'tracking_number']),
            'label_url' => $this->firstPresent($body, ['labelUrl', 'label_url', 'awbUrl']),
            'raw_status' => $this->firstPresent($body, ['state', 'status']),
            'raw_response' => $body,
        ];
    }

    /** Synchronous status check: GET /deliveries/{id} (verified in INTEGRATION-CATALOG.md §3.4). */
    public function getShipmentStatus(CarrierAccount $account, string $externalReference): array
    {
        $response = $this->request($account)->get($this->endpoint('/deliveries/'.$externalReference));

        if ($response->failed()) {
            throw CarrierException::requestFailed(
                $this->displayName(),
                "GET /deliveries/{$externalReference} returned HTTP {$response->status()}: ".$this->safeBody($response),
            );
        }

        $body = (array) $response->json();

        return [
            'raw_status' => $this->firstPresent($body, ['state', 'status']),
            'raw_response' => $body,
        ];
    }

    /**
     * Inbound: Bosta webhook payload -> ECOS vocabulary, via the account's own
     * CarrierStatusMapping rows (never hardcoded here — the mapping table is
     * the single source of truth per Section 13).
     *
     * ┌─ WHY THIS ALWAYS RETURNS ::unmapped() ──────────────────────────────┐
     * │ NormalizedCarrierEvent's $deliveryStatus is typed as                  │
     * │ Modules\Logistics\Delivery\Domain\Enums\DeliveryStatus — the          │
     * │ SUPERSEDED module's own enum (confirmed zero live consumers in the    │
     * │ OPS-02 reconciliation). Forcing a value into it to satisfy the type   │
     * │ would either silently mis-cast or require a fake fallback value —     │
     * │ both are worse than being explicit. The live authority is             │
     * │ Modules\Logistics\Distribution\Domain\Enums\DeliveryStopStatus, so    │
     * │ the mapping TABLE is still reused exactly as instructed (Section 13), │
     * │ but the resolved LIVE value travels in $metadata                      │
     * │ ('live_delivery_stop_status'), which ApplyCarrierDeliveryOutcomeService│
     * │ — the only consumer — reads authoritatively. isUnmapped() on the      │
     * │ returned event is therefore not meaningful for Bosta and must not be  │
     * │ read by any future caller; metadata is the real signal.               │
     * └────────────────────────────────────────────────────────────────────┘
     *
     * The exact webhook JSON shape is NOT verified by either source document
     * (only the STATUS VALUES are — Delivered/Not Available/Refused/In
     * Transit/Returned). This reads defensively across plausible key names
     * for the status and the shipment/event identifiers rather than assuming
     * one unverified schema, and that defensiveness is itself the PARTIAL
     * flag: confirm the real Bosta webhook payload shape before relying on
     * this in production.
     *
     * @param  array<string, mixed>  $payload
     */
    public function parseWebhook(CarrierAccount $account, array $payload): NormalizedCarrierEvent
    {
        $rawStatus = (string) ($this->firstPresent($payload, ['state', 'status', 'delivery_state']) ?? '');
        $trackingNumber = $this->firstPresent($payload, ['trackingNumber', 'tracking_number', '_id', 'id']);
        $occurredAt = $this->firstPresent($payload, ['occurred_at', 'timestamp', 'updatedAt', 'updated_at']);

        $eventId = $this->firstPresent($payload, ['eventId', 'event_id', '_eventId'])
            // No verified event-id field exists — fall back to a deterministic
            // hash of (shipment id + raw status + timestamp) so the SAME event
            // replayed twice still dedupes, without inventing a Bosta field.
            ?? hash('sha256', ($trackingNumber ?? '').'|'.$rawStatus.'|'.($occurredAt ?? ''));

        $mapping = $account->statusMappings()->where('carrier_status', $rawStatus)->first();

        $metadata = [
            'adapter' => $this->key(),
            'mapping_exists' => $mapping !== null,
            'live_delivery_stop_status' => null,
            'live_failure_reason' => null,
        ];

        if ($mapping !== null && $mapping->delivery_status !== null) {
            // Validated against the LIVE enum, not the model's own
            // toDeliveryStatus() helper (see the class-level note above).
            $liveStatus = \Modules\Logistics\Distribution\Domain\Enums\DeliveryStopStatus::tryFrom($mapping->delivery_status);
            $metadata['live_delivery_stop_status'] = $liveStatus?->value;
            $metadata['live_failure_reason'] = $mapping->toFailureReason()?->value;
        }

        return NormalizedCarrierEvent::unmapped(
            (string) $eventId,
            $rawStatus,
            $trackingNumber !== null ? (string) $trackingNumber : null,
            $occurredAt !== null ? (string) $occurredAt : null,
            $metadata,
        );
    }

    /**
     * PARTIAL — NOT IMPLEMENTED. No Bosta webhook signature scheme (header
     * name, algorithm, shared-secret format) is verified in either source
     * document. Failing closed (matching AbstractExternalCarrierAdapter's own
     * default and Section 27's "do not create fake success") means this
     * webhook cannot yet accept live Bosta traffic — the route and pipeline
     * are wired and ready, gated behind this single, honestly-unresolved
     * piece. Confirm Bosta's real signing mechanism before enabling in
     * production.
     */
    public function verifyWebhookSignature(CarrierAccount $account, array $payload, array $headers = []): bool
    {
        return false;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function request(CarrierAccount $account): \Illuminate\Http\Client\PendingRequest
    {
        if (! $this->hasApiKey()) {
            throw CarrierException::credentialsMissing();
        }

        return Http::withToken((string) config('services.bosta.api_key'))
            ->timeout((int) config('services.bosta.timeout', 15))
            ->retry(3, 500);
    }

    private function endpoint(string $path): string
    {
        $base = rtrim((string) config('services.bosta.base_url', ''), '/');

        if ($base === '') {
            throw CarrierException::requestFailed(
                $this->displayName(),
                'services.bosta.base_url is not configured — the verified contract confirms the "/v2/" API version path but not the host domain, so it must be set explicitly (BOSTA_BASE_URL) rather than guessed.',
            );
        }

        return $base.$path;
    }

    private function hasApiKey(): bool
    {
        return ! empty(config('services.bosta.api_key'));
    }

    /** @param array<string, mixed> $body */
    private function firstPresent(array $body, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $body) && $body[$key] !== null && $body[$key] !== '') {
                return $body[$key];
            }
        }

        return null;
    }

    private function safeBody(\Illuminate\Http\Client\Response $response): string
    {
        // Truncated — never let an unbounded carrier error body flood logs/exceptions.
        return substr($response->body(), 0, 500);
    }
}

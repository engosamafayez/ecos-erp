<?php

declare(strict_types=1);

namespace Modules\Logistics\Carriers\Domain\Contracts;

use Modules\Logistics\Carriers\Domain\Models\CarrierAccount;

/**
 * TASK-ECOS-V1.1-OPS-03-TASK1-BOSTA — outbound shipment tendering.
 *
 * Deliberately a SEPARATE, additive interface rather than new methods on
 * {@see CarrierAdapterInterface}: that interface is implemented by
 * `InternalFleetAdapter`, which has no "tender a shipment to a third party"
 * concept at all (its carrier IS the application). Adding tendering methods
 * there would force an irrelevant implementation onto the internal-fleet path
 * for zero benefit — exactly the kind of change Directive 9 (`CarrierAdapterInterface`'s
 * own docblock) warns against. A carrier adapter that genuinely tenders
 * shipments to a third party implements BOTH interfaces; `InternalFleetAdapter`
 * is untouched by this task.
 */
interface TenderingCarrierAdapterInterface
{
    /**
     * Create (tender) a shipment with the carrier.
     *
     * Implementations must be safe to call twice for the same
     * $shipmentContext['idempotency_key'] — the canonical ECOS-side guard is
     * the caller's own idempotent persistence (see
     * CreateExternalCarrierShipmentAction), but an adapter whose carrier API
     * itself accepts an idempotency key should also pass it through.
     *
     * @param  array<string, mixed>  $shipmentContext
     * @return array{external_reference: string, tracking_number: ?string, label_url: ?string, raw_status: ?string, raw_response: array<string, mixed>}
     */
    public function createShipment(CarrierAccount $account, array $shipmentContext): array;

    /**
     * A synchronous status check against the carrier (the polling/manual-refresh
     * fallback for when a webhook is missed or not supported).
     *
     * @return array{raw_status: ?string, raw_response: array<string, mixed>}
     */
    public function getShipmentStatus(CarrierAccount $account, string $externalReference): array;
}

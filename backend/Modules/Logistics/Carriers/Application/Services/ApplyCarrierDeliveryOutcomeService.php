<?php

declare(strict_types=1);

namespace Modules\Logistics\Carriers\Application\Services;

use Illuminate\Support\Facades\Log;
use Modules\Logistics\Carriers\Domain\Models\CarrierShipment;
use Modules\Logistics\Carriers\Domain\ValueObjects\NormalizedCarrierEvent;
use Modules\Logistics\Distribution\Domain\Enums\DeliveryStopStatus;
use Modules\Logistics\Distribution\Domain\Enums\TripType;
use Modules\Logistics\Distribution\Domain\Exceptions\DistributionException;
use Modules\Logistics\Distribution\Domain\Services\DeliveryService;

/**
 * The driver-independent carrier-outcome path (TASK-ECOS-V1.1-OPS-03-TASK1-
 * BOSTA, Section 14). ExternalCarrier trips have no ECOS Driver, so a carrier
 * event cannot flow through DriverRuntimeController — this service is the
 * canonical alternative entry point into the SAME canonical Delivery
 * transition logic (DeliveryService::completeStop()), never a parallel one.
 *
 * Every safety property this class relies on is inherited, not reinvented:
 *  - Section 15/16 (canonical outcome semantics, no direct Order/Finance/
 *    Inventory mutation): completeStop() dispatches DeliveryStopCompleted,
 *    the SAME event the driver path dispatches — every existing listener
 *    (retry-on-failure, Order status, etc.) fires identically regardless of
 *    which path produced the outcome.
 *  - Section 12 (out-of-order events must not regress a finalized state):
 *    completeStop() itself throws when the stop is already settled — this
 *    service catches that specific, expected condition and treats it as a
 *    no-op rather than inventing new ordering rules.
 *  - Section 17 (carrier status is evidence only, never restores Inventory):
 *    this service never touches VehicleInventoryItem/ReceiveVehicleReturnAction
 *    — a "Returned" outcome only settles the DeliveryStop; the existing,
 *    separate Warehouse Receipt process remains the sole Inventory authority,
 *    unchanged and uncalled here.
 */
final class ApplyCarrierDeliveryOutcomeService
{
    public function __construct(private readonly DeliveryService $delivery) {}

    /**
     * @return array{applied: bool, reason: string}
     */
    public function apply(CarrierShipment $shipment, NormalizedCarrierEvent $event): array
    {
        $stop = $shipment->deliveryStop;
        $trip = $shipment->trip;

        if ($stop === null || $trip === null) {
            return ['applied' => false, 'reason' => 'carrier_shipment_missing_stop_or_trip'];
        }

        if ($trip->type !== TripType::ExternalCarrier) {
            // Defensive: this path must never be reachable for an internal
            // trip — the driver runtime already owns those exclusively.
            return ['applied' => false, 'reason' => 'trip_is_not_external_carrier'];
        }

        // Always record the raw fact, whether or not it settles anything —
        // "carrier raw status may be stored" (Section 13) independent of the
        // canonical-state decision below.
        $shipment->update([
            'raw_status' => $event->rawStatus,
            'last_event_at' => now(),
        ]);

        $liveStatusValue = $event->metadata['live_delivery_stop_status'] ?? null;

        if ($liveStatusValue === null) {
            // Either genuinely unmapped (no CarrierStatusMapping row at all —
            // an integration gap to surface) or a recognised-but-intentionally
            // -non-settling status (e.g. Bosta's "In Transit" — recorded
            // above, correctly never a Delivery transition). Both cases stop
            // here; the raw fact is already saved.
            if (($event->metadata['mapping_exists'] ?? false) !== true) {
                Log::channel('daily')->warning('[ApplyCarrierDeliveryOutcomeService] Unmapped carrier status', [
                    'carrier_shipment_id' => $shipment->id,
                    'raw_status' => $event->rawStatus,
                ]);
            }

            return ['applied' => false, 'reason' => 'no_settling_transition_for_this_status'];
        }

        $outcome = DeliveryStopStatus::tryFrom($liveStatusValue);

        if ($outcome === null || ! $outcome->isSettled()) {
            // Defensive: a CarrierStatusMapping row was mis-seeded with a
            // non-outcome value (e.g. 'pending'). Never applied blindly.
            Log::channel('daily')->error('[ApplyCarrierDeliveryOutcomeService] Mapping row resolved to a non-settling DeliveryStopStatus', [
                'carrier_shipment_id' => $shipment->id,
                'resolved_value' => $liveStatusValue,
            ]);

            return ['applied' => false, 'reason' => 'mapped_value_is_not_a_settling_outcome'];
        }

        $failureReasonValue = $event->metadata['live_failure_reason'] ?? null;
        $actor = 'carrier:'.$shipment->carrierAccount?->adapter_key;

        try {
            // Same two-step sequence DriverRuntimeController::stopAction() uses:
            // record the ACTION (action_type/reason — DeliveryAction's own
            // columns; failure_reason is NOT a DeliveryStop column) first, as
            // the audit trail, THEN complete the stop with only real
            // DeliveryStop attributes. 'carrier_' prefix on action_type keeps
            // this visibly distinct from a driver-recorded action without a
            // second action-type vocabulary — action_type is a free string
            // column, not a closed enum (confirmed in the OPS-02
            // reconciliation).
            $this->delivery->recordAction($stop, [
                'action_type' => 'carrier_'.$outcome->value,
                'reason' => $failureReasonValue,
                'notes' => 'Reported by '.($shipment->carrierAccount?->name ?? 'external carrier').' via webhook.',
            ]);

            $this->delivery->completeStop($stop, $outcome, [], $actor);
        } catch (DistributionException $e) {
            // "already settled" for the SAME or an earlier outcome is the
            // expected no-op for a duplicate/out-of-order event (Section 12) —
            // never a regression, and never surfaced as an error to the caller.
            return ['applied' => false, 'reason' => 'stop_already_settled'];
        }

        return ['applied' => true, 'reason' => $outcome->value];
    }
}

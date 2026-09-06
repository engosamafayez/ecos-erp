<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Application\Listeners;

use Illuminate\Support\Facades\Log;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Logistics\Delivery\Domain\Enums\FailureReason;
use Modules\Logistics\Distribution\Domain\Enums\DeliveryStopStatus;
use Modules\Logistics\Distribution\Domain\Events\DeliveryStopCompleted;
use Modules\Logistics\Distribution\Domain\Services\TripService;
use Modules\Operations\Fulfillment\Application\FulfillmentEngine;
use Modules\Operations\Fulfillment\Application\Workflows\ReleaseForReplanningWorkflow;
use Throwable;

/**
 * TASK-ECOS-POST-DRIVER-RETURN-WAREHOUSE-RETURNS-FINAL-IMPLEMENTATION-002 §3/§4/§13/§14.
 *
 * THE retryable-release trigger — bridges a canonically-closed delivery
 * attempt to (a) freeing the stale Trip/Order association and (b) making the
 * order eligible for a new Preparation/Distribution cycle, immediately, with
 * no wait for physical goods return.
 *
 * WHY THIS EVENT. DeliveryStopCompleted already fires — synchronously, from
 * DeliveryService::completeStop(), which is the ONE place a stop settles —
 * for every settled outcome (Delivered, Partial, Failed, Returned, Skipped).
 * No new event was needed (§1: reuse, don't invent a second engine).
 *
 * RETRYABILITY IS DERIVED, NEVER LABEL-MATCHED (§3: "Do NOT derive
 * retryability from UI labels"). `outcome` narrows to Failed first (§17: never
 * broaden retry to Delivered/Cancelled/Partial/Returned/Skipped), then the
 * stop's most recent DeliveryAction.reason — the exact value the driver app
 * already records from FailureReason::catalogue() (DriverRuntimeController::
 * failureReasons(), which states plainly "the driver UI records ONE OF THESE
 * VALUES") — is resolved back to its FailureReason case and its OWN
 * `isRetryable()` decides. "No Answer" (FailureReason::NoAnswer) and
 * "Postponed" (FailureReason::CustomerRescheduled, the closest canonical
 * analogue — see the architecture report §12) are both retryable by
 * construction; a hard refusal or product fault (CustomerRefused,
 * ProductDamaged, WrongItem, ItemMissing) is not, and is left completely
 * alone — the order stays Delivered/whatever CustomerReturn's own workflow
 * decides, untouched by this listener.
 *
 * ORDER OF OPERATIONS: release the Trip/Order association FIRST, then bridge
 * into Fulfillment. If the order was already moved on by another path (its
 * status is no longer OutForDelivery — e.g. an operator already intervened
 * manually), the Fulfillment step is skipped as a no-op; the TripOrder
 * release still happened and is itself idempotent (TripService::
 * releaseOrder()), so a duplicate delivery of this event is always safe.
 *
 * FAILS SAFE, NEVER FAILS THE DELIVERY OUTCOME: this listener runs
 * synchronously, after the stop's own transaction has already committed
 * (DeliveryService::completeStop() dispatches only post-commit). An
 * unexpected exception here must not turn into a 500 for the driver who just
 * successfully recorded "No Answer" — it is logged and swallowed, mirroring
 * ActivateScheduledOrdersCommand's own per-item catch-and-log discipline.
 */
final class ReleaseOrderOnRetryableOutcomeListener
{
    public function __construct(
        private readonly TripService $trips,
        private readonly FulfillmentEngine $fulfillment,
    ) {}

    public function handle(DeliveryStopCompleted $event): void
    {
        if ($event->outcome !== DeliveryStopStatus::Failed) {
            return; // §17 — only a settled Failed outcome is ever a retry candidate
        }

        $stop = $event->stop;

        $latestAction = $stop->actions()->first(); // DeliveryStop::actions() is already ->latest()
        $reason = $latestAction?->reason !== null ? FailureReason::tryFrom($latestAction->reason) : null;

        if ($reason === null || ! $reason->isRetryable()) {
            return; // no canonical reason recorded, or a non-retryable one (e.g. CustomerRefused) — leave as-is
        }

        $actorId = is_numeric($event->actor) ? (int) $event->actor : null;

        try {
            $trip = $stop->trip;
            if ($trip === null) {
                return;
            }

            // 1. Free the stale Trip/Order association — preserves history (superseded, not deleted).
            $this->trips->releaseOrder($trip, $stop->order_id, $reason->value, $actorId);

            // 2. Bridge into the canonical OrderStatus engine — immediate, no Scheduled detour.
            $order = Order::query()->where('id', $stop->order_id)->first();
            if ($order === null || $order->status !== OrderStatus::OutForDelivery) {
                return; // already moved on by another path — idempotent no-op
            }

            $this->fulfillment->run(
                new ReleaseForReplanningWorkflow(),
                $order,
                ['reason' => $reason->value, 'trip_id' => $trip->id],
                $event->actor,
            );
        } catch (Throwable $e) {
            Log::channel('daily')->error('[ReleaseOrderOnRetryableOutcome] Failed to release order for replanning', [
                'stop_id' => $stop->id,
                'order_id' => $stop->order_id,
                'reason' => $reason->value,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}

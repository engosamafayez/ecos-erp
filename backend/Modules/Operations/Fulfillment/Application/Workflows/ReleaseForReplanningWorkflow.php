<?php

declare(strict_types=1);

namespace Modules\Operations\Fulfillment\Application\Workflows;

use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Operations\Fulfillment\Application\DTOs\FulfillmentContext;
use Modules\Operations\Fulfillment\Application\DTOs\FulfillmentResult;
use Modules\Operations\Fulfillment\Domain\Contracts\FulfillmentWorkflowInterface;
use Modules\Operations\Fulfillment\Domain\Exceptions\WorkflowPreconditionException;

/**
 * TASK-ECOS-POST-DRIVER-RETURN-WAREHOUSE-RETURNS-FINAL-IMPLEMENTATION-002 §3/§4/§13.
 *
 * Releases an OutForDelivery order straight back to In Progress after a
 * canonically-closed, retryable delivery outcome (No Answer / Postponed) —
 * immediately, with no future-dated wait. Called ONLY from
 * Distribution\Application\Listeners\ReleaseOrderOnRetryableOutcomeListener,
 * never directly over HTTP (mirrors ActivateScheduledOrdersCommand calling
 * ProcessOrderWorkflow programmatically — an established pattern for
 * listener/command-triggered workflows in this engine).
 *
 * WHY NOT AN EXISTING WORKFLOW. None of the 23 pre-existing workflows accept
 * OutForDelivery as a source state and land directly at InProgress:
 *   - RescheduleOrderWorkflow DOES accept OutForDelivery, but lands at
 *     Scheduled with a required future next_delivery_date — for a genuinely
 *     date-deferred redelivery (its own scenario D). Scheduled is explicitly
 *     NOT in config('distribution.eligible_order_statuses'), so landing
 *     there would leave the order ineligible for Preparation, not eligible —
 *     the opposite of §4's requirement. There is also no confirmed automatic
 *     resume path from Scheduled for this shape (resume_from_status has no
 *     reader outside ResumeOrderWorkflow, which itself refuses to resume
 *     FROM Scheduled — only from OnHold/AwaitingStock).
 *   - MarkRescheduledWorkflow, SetEarlyStatusWorkflow and ReturnToPendingWorkflow
 *     all explicitly BLOCK OutForDelivery as a source status.
 *   - ReturnToProcessingWorkflow (ReadyForDispatch only) and
 *     ReturnToConfirmedWorkflow (Returned only) guard the wrong FROM state.
 * This is therefore a genuinely new transition, not a duplicate — added to
 * the SAME engine, guarded the SAME way, audited the SAME way (§1: "do NOT
 * invent a second OrderStatus engine" is honoured by extending this one).
 *
 * NO INVENTORY ACTION HERE, DELIBERATELY (§5): the failed attempt's units are
 * already shipped out of the warehouse ledger (ShipStockAction ran at load
 * time) and now live only in VehicleInventoryItem — there is no warehouse-side
 * reservation left to release. Preparation performs a fresh
 * shortage/availability check for the new attempt when it next runs; this
 * workflow only frees the ORDER, never touches inventory or the old Trip's
 * custody (§5: "Do NOT transfer the old units to the new Trip").
 */
final class ReleaseForReplanningWorkflow implements FulfillmentWorkflowInterface
{
    public function guard(FulfillmentContext $ctx): void
    {
        if ($ctx->order->status !== OrderStatus::OutForDelivery) {
            throw new WorkflowPreconditionException(
                "Order [{$ctx->order->id}] must be Out For Delivery to release for replanning. Current: [{$ctx->order->status->value}].",
            );
        }
    }

    public function execute(FulfillmentContext $ctx): FulfillmentResult
    {
        $order = $ctx->order;
        $reason = $ctx->get('reason');

        $order->update([
            'status' => OrderStatus::InProgress,
            'rescheduled_at' => now(),
            'reschedule_reason' => $reason,
        ]);

        $order->refresh();

        return FulfillmentResult::success(
            $order,
            "Order #{$order->order_number} released for replanning (In Progress)."
                .($reason !== null ? " Reason: {$reason}." : ''),
            [
                'actor_id' => $ctx->actorId,
                'reason' => $reason,
                'trip_id' => $ctx->get('trip_id'),
            ],
        );
    }

    /** @return list<object> */
    public function events(FulfillmentResult $result): array
    {
        return [];
    }

    public function name(): string
    {
        return 'release_for_replanning';
    }
}

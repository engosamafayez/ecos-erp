<?php

declare(strict_types=1);

namespace Modules\Operations\Fulfillment\Application\Workflows;

use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Operations\Fulfillment\Application\DTOs\FulfillmentContext;
use Modules\Operations\Fulfillment\Application\DTOs\FulfillmentResult;
use Modules\Operations\Fulfillment\Domain\Contracts\FulfillmentWorkflowInterface;
use Modules\Operations\Fulfillment\Domain\Exceptions\WorkflowPreconditionException;

/**
 * TASK-ECOS-OPERATIONS-PREPARATION-DRIVER-EOD-FINAL-023 §E — places an
 * Out-for-Delivery order On Hold after a canonically-closed, NON-retryable
 * delivery-attempt outcome (e.g. the customer refused / a product fault), for
 * office review — called ONLY from
 * `Modules\Logistics\Distribution\Domain\Services\DeliveryAttemptClosureService`
 * at Wave/operational-day closure, never directly over HTTP (same restraint as
 * {@see ReleaseForReplanningWorkflow}, its retryable sibling).
 *
 * WHY NOT THE EXISTING `MoveToReviewWorkflow`. Its guard explicitly BLOCKS
 * `OutForDelivery` as a source ("locked in execution chain") — correct for
 * every one of its existing pre-fulfilment/creation/customer-block callers,
 * every one of which runs BEFORE dispatch. This is a genuinely new transition
 * (mirroring how `ReleaseForReplanningWorkflow` was added as a new
 * `OutForDelivery -> InProgress` edge rather than reusing
 * `ReturnToProcessingWorkflow`), reusing the SAME `OnHold` status value and the
 * SAME engine — not a second status, not a second workflow contract.
 *
 * NO INVENTORY ACTION HERE, for the identical reason `ReleaseForReplanningWorkflow`
 * has none: the attempt's units left the warehouse ledger at load time and now
 * live only in `VehicleInventoryItem` (vehicle custody) — there is nothing on the
 * warehouse-reservation side left to release. Physical custody stays with the
 * Driver/Vehicle until an actual warehouse receipt (§F) moves it — this workflow
 * only ever decides the ORDER's own execution state.
 */
final class MoveToReviewFromDeliveryWorkflow implements FulfillmentWorkflowInterface
{
    public function guard(FulfillmentContext $ctx): void
    {
        if ($ctx->order->status !== OrderStatus::OutForDelivery) {
            throw new WorkflowPreconditionException(
                "Order [{$ctx->order->id}] must be Out For Delivery to move On Hold from a delivery-attempt outcome. Current: [{$ctx->order->status->value}].",
            );
        }
    }

    public function execute(FulfillmentContext $ctx): FulfillmentResult
    {
        $order = $ctx->order;
        $reason = $ctx->get('reason');

        $order->update([
            'status' => OrderStatus::OnHold,
            'hold_reason_code' => $reason,
        ]);
        $order->refresh();

        return FulfillmentResult::success(
            $order,
            "Order #{$order->order_number} placed On Hold after a closed, non-retryable delivery attempt."
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
        return 'move_to_review_from_delivery';
    }
}

<?php

declare(strict_types=1);

namespace Modules\Operations\Fulfillment\Application\Workflows;

use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Operations\Fulfillment\Application\DTOs\FulfillmentContext;
use Modules\Operations\Fulfillment\Application\DTOs\FulfillmentResult;
use Modules\Operations\Fulfillment\Domain\Contracts\FulfillmentWorkflowInterface;
use Modules\Operations\Fulfillment\Domain\Exceptions\WorkflowPreconditionException;

/**
 * Moves an order into the Review state for manual intervention.
 *
 * Review is a generic hold state — not terminal. Valid exits:
 *   → Processing (via ResumeOrderWorkflow)
 *   → Confirmed  (via ConfirmOrderWorkflow)
 *   → Cancelled  (via CancelOrderWorkflow)
 *   → Rescheduled (via RescheduleOrderWorkflow)
 *
 * ADR Part 3 — Review is a manual intervention state, not terminal.
 */
final class MoveToReviewWorkflow implements FulfillmentWorkflowInterface
{
    public function guard(FulfillmentContext $ctx): void
    {
        $order = $ctx->order;

        // V2: Cancelled is no longer blocked — cancelled orders may be placed into Review.
        // Execution states (Preparing onward) and terminal/handled states are blocked.
        $blocked = [
            OrderStatus::Review,          // already in review
            OrderStatus::Preparing,       // locked in execution chain
            OrderStatus::OutForDelivery,  // locked in execution chain
            OrderStatus::Delivered,       // locked in execution chain
            OrderStatus::Returned,        // handled by Returns workflow
            OrderStatus::Completed,       // terminal
        ];

        if (in_array($order->status, $blocked, true)) {
            throw new WorkflowPreconditionException(
                "Order [{$order->id}] cannot be moved to Review from status [{$order->status->value}]."
            );
        }
    }

    public function execute(FulfillmentContext $ctx): FulfillmentResult
    {
        $order  = $ctx->order;
        $reason = $ctx->get('reason');

        $order->update(['status' => OrderStatus::Review]);
        $order->refresh();

        return FulfillmentResult::success(
            $order,
            "Order #{$order->order_number} placed under review.",
            ['reason' => $reason, 'actor_id' => $ctx->actorId],
        );
    }

    /** @return list<object> */
    public function events(FulfillmentResult $result): array
    {
        return [];
    }

    public function name(): string
    {
        return 'move_to_review';
    }
}

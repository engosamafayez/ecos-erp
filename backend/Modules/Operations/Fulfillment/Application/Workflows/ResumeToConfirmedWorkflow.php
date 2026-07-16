<?php

declare(strict_types=1);

namespace Modules\Operations\Fulfillment\Application\Workflows;

use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Operations\Fulfillment\Application\DTOs\FulfillmentContext;
use Modules\Operations\Fulfillment\Application\DTOs\FulfillmentResult;
use Modules\Operations\Fulfillment\Domain\Contracts\FulfillmentWorkflowInterface;
use Modules\Operations\Fulfillment\Domain\Exceptions\WorkflowPreconditionException;

/**
 * Transitions a Delivered order back to Confirmed status.
 *
 * Used when: a delivery was marked as complete in error (e.g. driver mis-scanned),
 * and the order needs to be re-confirmed for another delivery attempt.
 */
final class ResumeToConfirmedWorkflow implements FulfillmentWorkflowInterface
{
    public function guard(FulfillmentContext $ctx): void
    {
        if ($ctx->order->status !== OrderStatus::Delivered) {
            throw new WorkflowPreconditionException(
                "Order [{$ctx->order->id}] must be in Delivered status to resume-to-confirmed. Current: [{$ctx->order->status->value}]."
            );
        }
    }

    public function execute(FulfillmentContext $ctx): FulfillmentResult
    {
        $order = $ctx->order;

        $order->update([
            'status' => OrderStatus::Confirmed,
        ]);

        $order->refresh();

        return FulfillmentResult::success(
            $order,
            "Order #{$order->order_number} resumed to Confirmed.",
            ['actor_id' => $ctx->actorId],
        );
    }

    /** @return list<object> */
    public function events(FulfillmentResult $result): array
    {
        return [];
    }

    public function name(): string
    {
        return 'resume_to_confirmed';
    }
}

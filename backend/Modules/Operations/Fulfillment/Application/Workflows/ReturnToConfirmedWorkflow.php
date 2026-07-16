<?php

declare(strict_types=1);

namespace Modules\Operations\Fulfillment\Application\Workflows;

use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Operations\Fulfillment\Application\DTOs\FulfillmentContext;
use Modules\Operations\Fulfillment\Application\DTOs\FulfillmentResult;
use Modules\Operations\Fulfillment\Domain\Contracts\FulfillmentWorkflowInterface;
use Modules\Operations\Fulfillment\Domain\Exceptions\WorkflowPreconditionException;

/**
 * Transitions a Returned order back to Confirmed status for re-delivery scheduling.
 *
 * Used when: customer wants the order re-delivered after an initial return,
 * or the return was logged in error and the order should continue normally.
 */
final class ReturnToConfirmedWorkflow implements FulfillmentWorkflowInterface
{
    public function guard(FulfillmentContext $ctx): void
    {
        if ($ctx->order->status !== OrderStatus::Returned) {
            throw new WorkflowPreconditionException(
                "Order [{$ctx->order->id}] must be in Returned status to return-to-confirmed. Current: [{$ctx->order->status->value}]."
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
            "Order #{$order->order_number} returned to Confirmed.",
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
        return 'return_to_confirmed';
    }
}

<?php

declare(strict_types=1);

namespace Modules\Operations\Fulfillment\Application\Workflows;

use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Operations\Fulfillment\Application\DTOs\FulfillmentContext;
use Modules\Operations\Fulfillment\Application\DTOs\FulfillmentResult;
use Modules\Operations\Fulfillment\Domain\Contracts\FulfillmentWorkflowInterface;
use Modules\Operations\Fulfillment\Domain\Exceptions\WorkflowPreconditionException;

/**
 * Direct dispatch: preparing → out_for_delivery (simple path, no loading OS).
 *
 * Sets inventory_shipped_at = NOW() so CompleteDeliveryWorkflow guard passes.
 * Use when orders are dispatched directly without the full loading/vehicle workflow.
 */
final class DispatchOrderWorkflow implements FulfillmentWorkflowInterface
{
    public function guard(FulfillmentContext $ctx): void
    {
        if ($ctx->order->status !== OrderStatus::Preparing) {
            throw new WorkflowPreconditionException(
                "Order [{$ctx->order->id}] can only be dispatched from Preparing. Current: [{$ctx->order->status->value}]."
            );
        }
    }

    public function execute(FulfillmentContext $ctx): FulfillmentResult
    {
        $order = $ctx->order;

        $order->update([
            'status'               => OrderStatus::OutForDelivery,
            'inventory_shipped_at' => now(),
        ]);

        $order->refresh();

        return FulfillmentResult::success(
            $order,
            "Order #{$order->order_number} dispatched for delivery.",
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
        return 'dispatch_order';
    }
}

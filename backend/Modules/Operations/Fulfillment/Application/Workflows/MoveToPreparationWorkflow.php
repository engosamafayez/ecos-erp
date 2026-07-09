<?php

declare(strict_types=1);

namespace Modules\Operations\Fulfillment\Application\Workflows;

use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Operations\Fulfillment\Application\DTOs\FulfillmentContext;
use Modules\Operations\Fulfillment\Application\DTOs\FulfillmentResult;
use Modules\Operations\Fulfillment\Domain\Contracts\FulfillmentWorkflowInterface;
use Modules\Operations\Fulfillment\Domain\Exceptions\WorkflowPreconditionException;

/**
 * Moves an order into the preparation queue.
 *
 * Enforces the invariant: preparation cannot begin without a confirmed inventory reservation.
 * Physical stock is NOT consumed here — it remains reserved until vehicle dispatch.
 */
final class MoveToPreparationWorkflow implements FulfillmentWorkflowInterface
{
    public function guard(FulfillmentContext $ctx): void
    {
        $order = $ctx->order;

        if ($order->inventory_reserved_at === null) {
            throw new WorkflowPreconditionException(
                "Order [{$order->id}] cannot move to preparation: inventory has not been reserved. Confirm the order first."
            );
        }

        $allowed = [OrderStatus::ConfirmOrder, OrderStatus::Processing, OrderStatus::InProgress];

        if (! in_array($order->status, $allowed, true)) {
            throw new WorkflowPreconditionException(
                "Order [{$order->id}] cannot move to preparation from status [{$order->status->value}]."
            );
        }
    }

    public function execute(FulfillmentContext $ctx): FulfillmentResult
    {
        $order = $ctx->order;

        $order->update(['status' => OrderStatus::Preparing]);
        $order->refresh();

        return FulfillmentResult::success(
            $order,
            "Order #{$order->order_number} moved to preparation.",
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
        return 'move_to_preparation';
    }
}

<?php

declare(strict_types=1);

namespace Modules\Operations\Fulfillment\Application\Workflows;

use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Operations\Fulfillment\Application\DTOs\FulfillmentContext;
use Modules\Operations\Fulfillment\Application\DTOs\FulfillmentResult;
use Modules\Operations\Fulfillment\Domain\Contracts\FulfillmentWorkflowInterface;
use Modules\Operations\Fulfillment\Domain\Exceptions\WorkflowPreconditionException;

/**
 * Financially completes an order: delivered → completed.
 *
 * Triggered once accounting review, return window, and settlement are done.
 * Order becomes permanently closed.
 */
final class CompleteOrderWorkflow implements FulfillmentWorkflowInterface
{
    public function guard(FulfillmentContext $ctx): void
    {
        $order = $ctx->order;

        if ($order->status !== OrderStatus::Delivered) {
            throw new WorkflowPreconditionException(
                "Order [{$order->id}] must be in delivered status before completion. Current: [{$order->status->value}]."
            );
        }
    }

    public function execute(FulfillmentContext $ctx): FulfillmentResult
    {
        $order = $ctx->order;

        $order->update(['status' => OrderStatus::Completed]);
        $order->refresh();

        return FulfillmentResult::success(
            $order,
            "Order #{$order->order_number} completed and permanently closed.",
            [
                'revenue'        => $order->total,
                'cogs_amount'    => $order->actual_cogs_amount,
                'margin_amount'  => $order->actual_margin_amount,
                'margin_percent' => $order->actual_margin_percent,
                'actor_id'       => $ctx->actorId,
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
        return 'complete_order';
    }
}

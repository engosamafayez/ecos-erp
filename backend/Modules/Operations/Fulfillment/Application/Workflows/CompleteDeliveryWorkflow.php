<?php

declare(strict_types=1);

namespace Modules\Operations\Fulfillment\Application\Workflows;

use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Operations\Fulfillment\Application\DTOs\FulfillmentContext;
use Modules\Operations\Fulfillment\Application\DTOs\FulfillmentResult;
use Modules\Operations\Fulfillment\Domain\Contracts\FulfillmentWorkflowInterface;
use Modules\Operations\Fulfillment\Domain\Events\OrderDeliveredEvent;
use Modules\Operations\Fulfillment\Domain\Exceptions\WorkflowPreconditionException;

/**
 * Marks an order as delivered.
 *
 * Inventory was already deducted by LoadVehicleWorkflow at vehicle dispatch.
 * This workflow only advances order status and fires the revenue event.
 */
final class CompleteDeliveryWorkflow implements FulfillmentWorkflowInterface
{
    public function guard(FulfillmentContext $ctx): void
    {
        $order = $ctx->order;

        $allowed = [OrderStatus::OutForDelivery, OrderStatus::ReadyForLoading];

        if (! in_array($order->status, $allowed, true)) {
            throw new WorkflowPreconditionException(
                "Order [{$order->id}] cannot be completed from status [{$order->status->value}]."
            );
        }

        if ($order->inventory_shipped_at === null) {
            throw new WorkflowPreconditionException(
                "Order [{$order->id}] cannot be completed: inventory has not been shipped."
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
            "Order #{$order->order_number} delivered and completed.",
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
        $order = $result->order;
        $meta  = $result->meta;

        return [
            new OrderDeliveredEvent(
                orderId:       $order->id,
                orderNumber:   $order->order_number,
                companyId:     $order->company_id ?? '',
                revenue:       (float) ($meta['revenue'] ?? 0),
                cogsAmount:    (float) ($meta['cogs_amount'] ?? 0),
                marginAmount:  (float) ($meta['margin_amount'] ?? 0),
                marginPercent: (float) ($meta['margin_percent'] ?? 0),
                deliveredAt:   now()->toIso8601String(),
                actorId:       $meta['actor_id'] ?? null,
            ),
        ];
    }

    public function name(): string
    {
        return 'complete_delivery';
    }
}

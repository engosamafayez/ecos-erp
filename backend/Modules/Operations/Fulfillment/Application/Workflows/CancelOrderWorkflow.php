<?php

declare(strict_types=1);

namespace Modules\Operations\Fulfillment\Application\Workflows;

use Modules\Commerce\Orders\Application\Actions\ReleaseOrderInventoryAction;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Operations\Fulfillment\Application\DTOs\FulfillmentContext;
use Modules\Operations\Fulfillment\Application\DTOs\FulfillmentResult;
use Modules\Operations\Fulfillment\Domain\Contracts\FulfillmentWorkflowInterface;
use Modules\Operations\Fulfillment\Domain\Events\OrderCancelledEvent;
use Modules\Operations\Fulfillment\Domain\Exceptions\WorkflowPreconditionException;

/**
 * Cancels an order and releases any held inventory reservation.
 *
 * ADR Part 6 — Unified Cancel Workflow:
 * - Pending / AwaitingPayment / Processing / AwaitingStock / Confirmed / Preparing:
 *   cancel preparation, release reservation, audit, cancel.
 * - OutForDelivery: BLOCKED — must execute Return Workflow first.
 * - Delivered / Completed: BLOCKED — already fulfilled.
 * - Returned / Cancelled: already terminal.
 */
final class CancelOrderWorkflow implements FulfillmentWorkflowInterface
{
    public function __construct(
        private readonly ReleaseOrderInventoryAction $releaseInventory,
    ) {}

    public function guard(FulfillmentContext $ctx): void
    {
        $order = $ctx->order;

        if ($order->status === OrderStatus::OutForDelivery) {
            throw new WorkflowPreconditionException(
                "Order [{$order->id}] is out for delivery. Execute Return Workflow first before cancelling."
            );
        }

        // Block permanently: terminal states and already-delivered orders
        $blocked = [
            OrderStatus::Cancelled,
            OrderStatus::Completed,
            OrderStatus::Delivered,
        ];

        if (in_array($order->status, $blocked, true)) {
            throw new WorkflowPreconditionException(
                "Order [{$order->id}] is in state [{$order->status->value}] and cannot be cancelled."
            );
        }
    }

    public function execute(FulfillmentContext $ctx): FulfillmentResult
    {
        $order   = $ctx->order;
        $reason  = $ctx->get('reason');
        $released = false;

        if ($order->assigned_warehouse_id !== null && $order->inventory_released_at === null) {
            $this->releaseInventory->execute($order);
            $released = true;
        }

        $order->update(['status' => OrderStatus::Cancelled]);
        $order->refresh();

        return FulfillmentResult::success(
            $order,
            "Order #{$order->order_number} cancelled." . ($released ? ' Inventory reservation released.' : ''),
            [
                'inventory_released' => $released,
                'reason'             => $reason,
                'actor_id'           => $ctx->actorId,
            ],
        );
    }

    /** @return list<object> */
    public function events(FulfillmentResult $result): array
    {
        return [
            new OrderCancelledEvent(
                orderId:           $result->order->id,
                orderNumber:       $result->order->order_number,
                companyId:         $result->order->company_id ?? '',
                inventoryReleased: (bool) ($result->meta['inventory_released'] ?? false),
                reason:            $result->meta['reason'] ?? null,
                cancelledAt:       now()->toIso8601String(),
                actorId:           $result->meta['actor_id'] ?? null,
            ),
        ];
    }

    public function name(): string
    {
        return 'cancel_order';
    }
}

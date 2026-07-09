<?php

declare(strict_types=1);

namespace Modules\Operations\Fulfillment\Application\Workflows;

use Modules\Commerce\Orders\Application\Actions\ReserveOrderInventoryAction;
use Modules\Commerce\Orders\Application\Services\CreateOrderSnapshotService;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Operations\Fulfillment\Application\DTOs\FulfillmentContext;
use Modules\Operations\Fulfillment\Application\DTOs\FulfillmentResult;
use Modules\Operations\Fulfillment\Domain\Contracts\FulfillmentWorkflowInterface;
use Modules\Operations\Fulfillment\Domain\Events\OrderConfirmedEvent;
use Modules\Operations\Fulfillment\Domain\Exceptions\WorkflowPreconditionException;

/**
 * Confirms an order: reserves warehouse inventory and creates the financial snapshot.
 *
 * Closes GAP-01 — manual orders no longer skip inventory reservation.
 * Trigger: PatchOrderAction routes confirm_order transitions here.
 */
final class ConfirmOrderWorkflow implements FulfillmentWorkflowInterface
{
    public function __construct(
        private readonly ReserveOrderInventoryAction $reserveInventory,
        private readonly CreateOrderSnapshotService  $snapshot,
    ) {}

    public function guard(FulfillmentContext $ctx): void
    {
        $order = $ctx->order;

        if ($order->assigned_warehouse_id === null) {
            throw new WorkflowPreconditionException(
                "Order [{$order->id}] cannot be confirmed: no warehouse assigned."
            );
        }

        if ($order->inventory_reserved_at !== null) {
            throw new WorkflowPreconditionException(
                "Order [{$order->id}] inventory is already reserved."
            );
        }

        $allowed = [
            OrderStatus::Pending,
            OrderStatus::InProgress,
            OrderStatus::Processing,
            OrderStatus::AwaitingPayment,
        ];

        if (! in_array($order->status, $allowed, true)) {
            throw new WorkflowPreconditionException(
                "Order [{$order->id}] cannot be confirmed from status [{$order->status->value}]."
            );
        }
    }

    public function execute(FulfillmentContext $ctx): FulfillmentResult
    {
        $order = $ctx->order;

        // Reserve stock — runs in a nested savepoint inside FulfillmentEngine's transaction
        $this->reserveInventory->execute($order);

        // Snapshot — idempotent; returns null if one already exists
        $snapshot = $this->snapshot->createIfAbsent($order);

        $order->update(['status' => OrderStatus::ConfirmOrder]);
        $order->refresh();

        return FulfillmentResult::success(
            $order,
            "Order #{$order->order_number} confirmed. Inventory reserved.",
            [
                'snapshot_created' => $snapshot !== null,
                'actor_id'         => $ctx->actorId,
            ],
        );
    }

    /** @return list<object> */
    public function events(FulfillmentResult $result): array
    {
        return [
            new OrderConfirmedEvent(
                orderId:         $result->order->id,
                orderNumber:     $result->order->order_number,
                companyId:       $result->order->company_id ?? '',
                warehouseId:     $result->order->assigned_warehouse_id ?? '',
                reservedAt:      $result->order->inventory_reserved_at?->toIso8601String() ?? now()->toIso8601String(),
                snapshotCreated: (bool) ($result->meta['snapshot_created'] ?? false),
                actorId:         $result->meta['actor_id'] ?? null,
            ),
        ];
    }

    public function name(): string
    {
        return 'confirm_order';
    }
}

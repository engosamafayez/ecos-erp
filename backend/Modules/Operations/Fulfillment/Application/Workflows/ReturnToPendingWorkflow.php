<?php

declare(strict_types=1);

namespace Modules\Operations\Fulfillment\Application\Workflows;

use Modules\Commerce\Orders\Application\Actions\ReleaseOrderInventoryAction;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Operations\Fulfillment\Application\DTOs\FulfillmentContext;
use Modules\Operations\Fulfillment\Application\DTOs\FulfillmentResult;
use Modules\Operations\Fulfillment\Domain\Contracts\FulfillmentWorkflowInterface;
use Modules\Operations\Fulfillment\Domain\Exceptions\WorkflowPreconditionException;

/**
 * Returns a Confirmed order back to Pending, releasing inventory reservation.
 *
 * Used when: the operator needs to unlock the order for structural edits
 * (product changes, address changes, customer reassignment). Only valid from
 * Confirmed status — once preparation has started the order is locked.
 */
final class ReturnToPendingWorkflow implements FulfillmentWorkflowInterface
{
    public function __construct(
        private readonly ReleaseOrderInventoryAction $releaseInventory,
    ) {}

    public function guard(FulfillmentContext $ctx): void
    {
        // V2: any pre-execution state (including Cancelled) may return to Pending.
        // execute() handles inventory conditionally — releases only if reserved.
        $allowed = [
            OrderStatus::AwaitingPayment,
            OrderStatus::Confirmed,
            OrderStatus::Processing,
            OrderStatus::AwaitingStock,
            OrderStatus::Review,
            OrderStatus::Rescheduled,
            OrderStatus::Cancelled,
        ];

        if (! in_array($ctx->order->status, $allowed, true)) {
            throw new WorkflowPreconditionException(
                "Order [{$ctx->order->id}] cannot return to Pending from status [{$ctx->order->status->value}]."
            );
        }
    }

    public function execute(FulfillmentContext $ctx): FulfillmentResult
    {
        $order    = $ctx->order;
        $released = false;

        if ($order->assigned_warehouse_id !== null && $order->inventory_released_at === null && $order->inventory_reserved_at !== null) {
            $this->releaseInventory->execute($order);
            $released = true;
        }

        // DRIFT-009 fix: clear reservation_status so it does not stay stale as 'Released'
        // H-4 fix: also clear partial_reservation_approved_at — a manager approved a specific
        // shortage at a specific point in time. After returning to Pending and re-reserving,
        // the shortage profile may change and must be re-approved.
        $order->update([
            'status'                          => OrderStatus::Pending,
            'inventory_reserved_at'           => null,
            'inventory_released_at'           => null,
            'reservation_status'              => null,
            'reservation_failure_reason'      => null,
            'partial_reservation_approved_at' => null,
        ]);
        $order->refresh();

        return FulfillmentResult::success(
            $order,
            "Order #{$order->order_number} returned to Pending." . ($released ? ' Inventory reservation released.' : ''),
            [
                'inventory_released' => $released,
                'actor_id'           => $ctx->actorId,
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
        return 'return_to_pending';
    }
}

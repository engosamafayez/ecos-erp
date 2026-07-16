<?php

declare(strict_types=1);

namespace Modules\Operations\Fulfillment\Application\Workflows;

use Modules\Commerce\Orders\Application\Actions\ReserveOrderInventoryAction;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Operations\Fulfillment\Application\DTOs\FulfillmentContext;
use Modules\Operations\Fulfillment\Application\DTOs\FulfillmentResult;
use Modules\Operations\Fulfillment\Domain\Contracts\FulfillmentWorkflowInterface;
use Modules\Operations\Fulfillment\Domain\Exceptions\WorkflowPreconditionException;

/**
 * Moves an order into the preparation queue.
 *
 * Automatic Reservation Guard (ADR-015 / Phase 8):
 * If a reservation is missing (e.g. auto_reserve_inventory was OFF at order creation),
 * one is created on-the-fly inside execute() before the status transition.
 * Physical stock is NOT consumed here — it remains reserved until vehicle dispatch.
 */
final class MoveToPreparationWorkflow implements FulfillmentWorkflowInterface
{
    public function __construct(
        private readonly ReserveOrderInventoryAction $reserveInventory,
    ) {}

    public function guard(FulfillmentContext $ctx): void
    {
        $order = $ctx->order;

        $allowed = [OrderStatus::Confirmed, OrderStatus::Processing];

        if (! in_array($order->status, $allowed, true)) {
            throw new WorkflowPreconditionException(
                "Order [{$order->id}] cannot move to preparation from status [{$order->status->value}]."
            );
        }

        // When no reservation exists yet, a warehouse must be assigned so the
        // auto-reservation guard in execute() can create one.
        if ($order->inventory_reserved_at === null && $order->assigned_warehouse_id === null) {
            throw new WorkflowPreconditionException(
                "Order [{$order->id}] cannot move to preparation: no warehouse is assigned and inventory has not been reserved."
            );
        }
    }

    public function execute(FulfillmentContext $ctx): FulfillmentResult
    {
        $order = $ctx->order;
        $reservationCreated = false;

        // Automatic Reservation Guard — create the reservation on-the-fly when
        // auto_reserve_inventory was OFF at order creation time.
        if ($order->inventory_reserved_at === null) {
            $this->reserveInventory->execute($order);
            $order->refresh();
            $reservationCreated = true;
        }

        $order->update(['status' => OrderStatus::Preparing]);
        $order->refresh();

        $message = "Order #{$order->order_number} moved to preparation.";
        if ($reservationCreated) {
            $message .= ' Inventory reserved automatically before preparation.';
        }

        return FulfillmentResult::success(
            $order,
            $message,
            [
                'actor_id'            => $ctx->actorId,
                'reservation_created' => $reservationCreated,
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
        return 'move_to_preparation';
    }
}

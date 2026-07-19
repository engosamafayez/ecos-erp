<?php

declare(strict_types=1);

namespace Modules\Operations\Fulfillment\Application\Workflows;

use Modules\Commerce\Orders\Application\Actions\ReserveOrderInventoryAction;
use Modules\Commerce\Orders\Application\Actions\UpdateReservationStatusAction;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Enums\ReservationStatus;
use Modules\Operations\Fulfillment\Application\DTOs\FulfillmentContext;
use Modules\Operations\Fulfillment\Application\DTOs\FulfillmentResult;
use Modules\Operations\Fulfillment\Domain\Contracts\FulfillmentWorkflowInterface;
use Modules\Operations\Fulfillment\Domain\Events\OrderPreparationStartedEvent;
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
        private readonly UpdateReservationStatusAction $updateReservationStatus,
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

        // Block terminal reservation states: Released/Consumed/Transferred mean the inventory
        // commitment has ended. Letting these through causes ReserveOrderInventoryAction to
        // return early (idempotency guard) and the order enters Preparing with zero stock (H-2 fix).
        $terminalReservationStates = [
            ReservationStatus::Released,
            ReservationStatus::Consumed,
            ReservationStatus::Transferred,
        ];
        if (in_array($order->reservation_status, $terminalReservationStates, true)) {
            throw new WorkflowPreconditionException(
                "Order [{$order->id}] has reservation_status [{$order->reservation_status?->value}] and cannot enter " .
                'preparation. Release and re-reserve inventory before moving to preparation.'
            );
        }

        // Must have Reserved OR PartialReserved (or Allow Negative Stock path with warehouse)
        $activeReservation = in_array($order->reservation_status, [
            ReservationStatus::Reserved,
            ReservationStatus::PartialReserved,
        ], true);

        if (! $activeReservation && $order->assigned_warehouse_id === null) {
            throw new WorkflowPreconditionException(
                "Order [{$order->id}] cannot move to preparation: no warehouse is assigned and inventory has not been reserved."
            );
        }

        // P1-002 — PartialReserved orders require explicit manager approval before preparation.
        // This prevents silently packing partial orders without business awareness of the shortage.
        if ($order->reservation_status === ReservationStatus::PartialReserved
            && $order->partial_reservation_approved_at === null
        ) {
            throw new WorkflowPreconditionException(
                "Order [{$order->id}] has a partial reservation and requires manager approval before preparation. " .
                'Use the approve-partial-reservation endpoint to grant approval.'
            );
        }
    }

    public function execute(FulfillmentContext $ctx): FulfillmentResult
    {
        $order = $ctx->order;
        $reservationCreated = false;

        // Automatic Reservation Guard — create reservation on-the-fly when not yet reserved.
        // DRIFT-005 fix: capture the result and abort to AwaitingStock if reservation failed.
        $activeStates = [ReservationStatus::Reserved, ReservationStatus::PartialReserved];
        if (! in_array($order->reservation_status, $activeStates, true)) {
            $reservationResult = $this->reserveInventory->execute($order);
            $order->refresh();
            $reservationCreated = true;

            if ($reservationResult === ReservationStatus::AwaitingStock) {
                $order->update(['status' => OrderStatus::AwaitingStock]);
                $order->refresh();

                return FulfillmentResult::success(
                    $order,
                    "Order #{$order->order_number} cannot enter preparation — insufficient stock. Moved to AwaitingStock.",
                    [
                        'actor_id'            => $ctx->actorId,
                        'reservation_created' => true,
                        'reservation_status'  => $order->reservation_status?->value,
                        'started_at'          => now()->toIso8601String(),
                    ],
                );
            }
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
                'warehouse_id'        => $order->assigned_warehouse_id,
                'reservation_status'  => $order->reservation_status?->value,
                'started_at'          => now()->toIso8601String(),
            ],
        );
    }

    /** @return list<object> */
    public function events(FulfillmentResult $result): array
    {
        $order = $result->order;

        return [
            new OrderPreparationStartedEvent(
                orderId:           $order->id,
                orderNumber:       $order->order_number,
                companyId:         $order->company_id ?? '',
                warehouseId:       $result->meta['warehouse_id'] ?? null,
                reservationStatus: $result->meta['reservation_status'] ?? '',
                startedAt:         $result->meta['started_at'] ?? now()->toIso8601String(),
                actorId:           $result->meta['actor_id'] ?? null,
            ),
        ];
    }

    public function name(): string
    {
        return 'move_to_preparation';
    }
}

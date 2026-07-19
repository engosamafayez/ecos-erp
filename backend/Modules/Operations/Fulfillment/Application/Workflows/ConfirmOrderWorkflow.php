<?php

declare(strict_types=1);

namespace Modules\Operations\Fulfillment\Application\Workflows;

use Modules\Commerce\Orders\Application\Actions\ReserveOrderInventoryAction;
use Modules\Commerce\Orders\Application\Actions\UpdateReservationStatusAction;
use Modules\Commerce\Orders\Application\Services\CreateOrderSnapshotService;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Enums\ReservationStatus;
use Modules\Commerce\Orders\Domain\Models\OrderEvent;
use Modules\Operations\Fulfillment\Application\DTOs\FulfillmentContext;
use Modules\Operations\Fulfillment\Application\DTOs\FulfillmentResult;
use Modules\Operations\Fulfillment\Domain\Contracts\FulfillmentWorkflowInterface;
use Modules\Operations\Fulfillment\Domain\Events\OrderConfirmedEvent;
use Modules\Operations\Fulfillment\Domain\Exceptions\WorkflowPreconditionException;

/**
 * Confirms an order: reserves warehouse inventory and creates the financial snapshot.
 *
 * Inventory Reservation Contract (TASK-ORDER-RESERVATION-WORKFLOW-INTEGRITY-001):
 * - Reservation is ALWAYS attempted when confirming.
 * - If reservation cannot be performed (no warehouse or insufficient stock),
 *   the order is automatically routed to AwaitingStock instead of Confirmed.
 * - The state "Confirmed + Not Reserved" is architecturally invalid.
 *
 * Idempotent: orders arriving from AwaitingStock/Review/Rescheduled with an
 * active reservation skip the reservation step and proceed directly to Confirmed.
 */
final class ConfirmOrderWorkflow implements FulfillmentWorkflowInterface
{
    public function __construct(
        private readonly ReserveOrderInventoryAction   $reserveInventory,
        private readonly CreateOrderSnapshotService    $snapshot,
        private readonly UpdateReservationStatusAction $updateReservationStatus,
    ) {}

    public function guard(FulfillmentContext $ctx): void
    {
        $order = $ctx->order;

        $allowed = [
            OrderStatus::Pending,
            OrderStatus::AwaitingPayment,
            OrderStatus::Processing,
            OrderStatus::AwaitingStock,
            OrderStatus::Review,
            OrderStatus::Rescheduled,
            OrderStatus::Returned,
            OrderStatus::Cancelled,
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

        // For returned orders, inventory was released during return — reset all reservation
        // lifecycle fields before re-reserving. Critically: reservation_status must be cleared
        // here (not just inventory timestamps) because ReserveOrderInventoryAction has an
        // early-return idempotency guard that skips execution when reservation_status = Released.
        // Without this clear, the order would be confirmed with zero inventory held.
        if ($order->status === OrderStatus::Returned) {
            $order->update([
                'inventory_reserved_at'      => null,
                'inventory_released_at'      => null,
                'inventory_shipped_at'       => null,
                'reservation_status'         => null,
                'reservation_failure_reason' => null,
            ]);
            $order->refresh();
        }

        // Clear reschedule / cancel metadata on re-activation
        if (in_array($order->status, [OrderStatus::Rescheduled, OrderStatus::Cancelled], true)) {
            $order->update([
                'rescheduled_at'     => null,
                'next_delivery_date' => null,
                'resume_from_status' => null,
                'reschedule_reason'  => null,
            ]);
            $order->refresh();
        }

        // Idempotent: inventory already held (Reserved OR PartialReserved) — go straight to Confirmed
        $activeStates    = [ReservationStatus::Reserved, ReservationStatus::PartialReserved];
        $alreadyReserved = in_array($order->reservation_status, $activeStates, true);

        $snapshotCreated = false;

        if (! $alreadyReserved) {
            // No warehouse assigned → cannot reserve; route to AwaitingStock
            if ($order->assigned_warehouse_id === null) {
                $order->update(['status' => OrderStatus::AwaitingStock]);
                $order->refresh();

                $this->updateReservationStatus->execute(
                    $order,
                    ReservationStatus::AwaitingStock,
                    'Warehouse Not Assigned',
                );

                OrderEvent::log(
                    orderId:     $order->id,
                    type:        'reservation_awaiting_stock',
                    description: "Reservation pending for order #{$order->order_number}: no warehouse assigned.",
                    payload:     ['reason' => 'no_warehouse_assigned'],
                    module:      'fulfillment',
                );

                return FulfillmentResult::success(
                    $order,
                    "Order #{$order->order_number} awaiting stock — no warehouse assigned.",
                    ['snapshot_created' => false, 'actor_id' => $ctx->actorId, 'reservation_failed' => true],
                );
            }

            // Attempt reservation — returns status directly; does NOT throw for insufficient stock
            $reservationStatus = $this->reserveInventory->execute($order);
            $order->refresh();

            if ($reservationStatus === ReservationStatus::AwaitingStock) {
                $order->update(['status' => OrderStatus::AwaitingStock]);
                $order->refresh();

                return FulfillmentResult::success(
                    $order,
                    "Order #{$order->order_number} awaiting stock — insufficient inventory.",
                    ['snapshot_created' => false, 'actor_id' => $ctx->actorId, 'reservation_failed' => true],
                );
            }

            // Reserved OR PartialReserved — proceed to Confirmed
            $snapshot        = $this->snapshot->createIfAbsent($order);
            $snapshotCreated = $snapshot !== null;
        }

        $order->update(['status' => OrderStatus::Confirmed]);
        $order->refresh();

        $message = $alreadyReserved
            ? "Order #{$order->order_number} confirmed."
            : "Order #{$order->order_number} confirmed. Inventory reserved. Ready for preparation.";

        return FulfillmentResult::success(
            $order,
            $message,
            ['snapshot_created' => $snapshotCreated, 'actor_id' => $ctx->actorId],
        );
    }

    /** @return list<object> */
    public function events(FulfillmentResult $result): array
    {
        // No OrderConfirmedEvent when reservation failed and order routed to AwaitingStock
        if ($result->meta['reservation_failed'] ?? false) {
            return [];
        }

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

<?php

declare(strict_types=1);

namespace Modules\Operations\Fulfillment\Application\Workflows;

use Modules\Commerce\Orders\Application\Actions\ReserveOrderInventoryAction;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Models\OrderEvent;
use Modules\Operations\Fulfillment\Application\DTOs\FulfillmentContext;
use Modules\Operations\Fulfillment\Application\DTOs\FulfillmentResult;
use Modules\Operations\Fulfillment\Domain\Contracts\FulfillmentWorkflowInterface;
use Modules\Operations\Fulfillment\Domain\Exceptions\WorkflowPreconditionException;

/**
 * Moves an order into Processing status.
 *
 * Inventory Reservation Contract (TASK-ORDER-RESERVATION-WORKFLOW-INTEGRITY-001):
 * - Reservation is ALWAYS attempted when entering Processing.
 * - If reservation cannot be performed (no warehouse or insufficient stock),
 *   the order is automatically routed to AwaitingStock instead of Processing.
 * - The state "Processing + Not Reserved" is architecturally invalid.
 *
 * Idempotent: if inventory is already reserved (e.g. Confirmed → Processing
 * de-escalation), the existing reservation is preserved and not duplicated.
 */
final class ProcessOrderWorkflow implements FulfillmentWorkflowInterface
{
    public function __construct(
        private readonly ReserveOrderInventoryAction $reserveInventory,
    ) {}

    public function guard(FulfillmentContext $ctx): void
    {
        $order = $ctx->order;

        $allowed = [
            OrderStatus::Pending,
            OrderStatus::AwaitingPayment,
            OrderStatus::AwaitingStock,
            OrderStatus::Rescheduled,
            OrderStatus::Review,
            OrderStatus::Cancelled,
        ];

        if (! in_array($order->status, $allowed, true)) {
            throw new WorkflowPreconditionException(
                "Order [{$order->id}] cannot move to Processing from status [{$order->status->value}]."
            );
        }
    }

    public function execute(FulfillmentContext $ctx): FulfillmentResult
    {
        $order = $ctx->order;

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

        // Idempotent: inventory already held from a prior reservation — go straight to Processing
        $alreadyReserved = $order->inventory_reserved_at !== null
            && $order->inventory_released_at === null;

        if (! $alreadyReserved) {
            // No warehouse assigned → cannot reserve; route to AwaitingStock
            if ($order->assigned_warehouse_id === null) {
                $order->update(['status' => OrderStatus::AwaitingStock]);
                $order->refresh();

                OrderEvent::log(
                    orderId:     $order->id,
                    type:        'inventory_reservation_failed',
                    description: "Reservation failed for order #{$order->order_number}: no warehouse assigned.",
                    payload:     ['reason' => 'no_warehouse_assigned'],
                    module:      'fulfillment',
                );

                return FulfillmentResult::success(
                    $order,
                    "Order #{$order->order_number} awaiting stock — no warehouse assigned.",
                    ['actor_id' => $ctx->actorId, 'reservation_failed' => true],
                );
            }

            // Attempt inventory reservation; insufficient stock → AwaitingStock
            try {
                $this->reserveInventory->execute($order);
            } catch (\Throwable $e) {
                $order->update(['status' => OrderStatus::AwaitingStock]);
                $order->refresh();

                OrderEvent::log(
                    orderId:     $order->id,
                    type:        'inventory_reservation_failed',
                    description: "Reservation failed for order #{$order->order_number}: {$e->getMessage()}",
                    payload:     ['reason' => 'reservation_error', 'error' => $e->getMessage()],
                    module:      'fulfillment',
                );

                return FulfillmentResult::success(
                    $order,
                    "Order #{$order->order_number} awaiting stock — insufficient inventory.",
                    ['actor_id' => $ctx->actorId, 'reservation_failed' => true],
                );
            }
        }

        $order->update(['status' => OrderStatus::Processing]);
        $order->refresh();

        return FulfillmentResult::success(
            $order,
            $alreadyReserved
                ? "Order #{$order->order_number} moved to Processing."
                : "Order #{$order->order_number} moved to Processing. Inventory reserved.",
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
        return 'process_order';
    }
}

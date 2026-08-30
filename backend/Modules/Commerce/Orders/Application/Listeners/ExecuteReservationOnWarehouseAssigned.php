<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Application\Listeners;

use Illuminate\Support\Facades\Log;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Enums\ReservationStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Operations\Fulfillment\Application\FulfillmentEngine;
use Modules\Operations\Fulfillment\Application\Workflows\ProcessOrderWorkflow;
use Modules\Operations\Preparation\Domain\Events\WarehouseAssigned;
use Throwable;

/**
 * ADR-027 §15 H3 — Post-Warehouse-Assignment Reservation Execution Retry.
 *
 * ADR-027 §2 separates the reservation DECISION from its EXECUTION. The decision is
 * made the moment an order enters an active commercial state and is recorded as
 * `reservation_status = pending`; execution is postponed until a warehouse exists:
 *
 *   > As soon as a `WarehouseAssigned` event fires for the order, execution must
 *   > automatically retry.
 *
 * This listener is that retry. It was specified in ADR-027 v1.1 and recorded there
 * as missing; until now nothing resumed a postponed reservation, so an order that
 * failed warehouse assignment stayed blocked forever.
 *
 * TARGETED, not "retry everything". Only orders whose reservation is genuinely
 * *postponed* are picked up:
 *   - `reservation_status = pending`  → the postponed-execution marker of §2.
 *     An order sitting at `awaiting_stock` is a FINISHED-GOOD SHORTAGE (§3 Case 4),
 *     a different blocker with a different recovery path
 *     (RetryReservationOnStockAvailableListener), and is deliberately NOT handled here.
 *   - status is one of the active commercial states of §2.
 *
 * IDEMPOTENT on three levels:
 *   1. The `pending` guard — once execution succeeds the status is no longer
 *      `pending`, so a replayed event is a no-op.
 *   2. `ProcessOrderWorkflow` skips re-reservation when reservation_status is
 *      already Reserved/PartialReserved.
 *   3. `ReserveOrderInventoryAction` itself skips Reserved/Transferred/Consumed/Released.
 *
 * Status is never written here. The workflow owns every lifecycle transition.
 */
final class ExecuteReservationOnWarehouseAssigned
{
    /**
     * Active commercial states from ADR-027 §2, mapped to the canonical lifecycle
     * of ADR-042 (`new` removed; `confirmed` restored as a real state).
     *
     * @var list<OrderStatus>
     */
    private const RETRYABLE_STATUSES = [
        OrderStatus::InProgress,
        OrderStatus::Confirmed,
        OrderStatus::AwaitingPayment,
    ];

    public function __construct(
        private readonly FulfillmentEngine $fulfillmentEngine,
        private readonly ProcessOrderWorkflow $processWorkflow,
    ) {}

    public function handle(WarehouseAssigned $event): void
    {
        $order = Order::whereKey($event->orderId)->whereNull('deleted_at')->first();

        if ($order === null) {
            return;
        }

        // A warehouse must actually be present. The event can also be emitted by a
        // re-assignment that resolved to nothing useful; reservation would throw.
        if ($order->assigned_warehouse_id === null) {
            return;
        }

        if ($order->reservation_status !== ReservationStatus::Pending) {
            return;
        }

        if (! in_array($order->status, self::RETRYABLE_STATUSES, true)) {
            return;
        }

        try {
            $result = $this->fulfillmentEngine->run(
                $this->processWorkflow,
                $order,
                [],
                null, // system actor
            );

            Log::info("[H3] Reservation executed for order #{$order->order_number} after warehouse assignment.", [
                'order_id' => $order->id,
                'warehouse_id' => $event->warehouseId,
                'source' => $event->source->value,
                'new_status' => $result->order->status->value,
                'reservation_status' => $result->order->reservation_status?->value,
            ]);
        } catch (Throwable $e) {
            // Assignment itself must not fail because the retry did. The exception is
            // contained but reported — never silently swallowed.
            report($e);

            Log::error("[H3] Reservation retry failed for order #{$order->order_number}.", [
                'order_id' => $order->id,
                'warehouse_id' => $event->warehouseId,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

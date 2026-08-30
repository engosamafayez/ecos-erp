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
 * Marks an order as Ready for Dispatch — all engines have completed.
 *
 * V3 (TASK-ORDERS-LIFECYCLE-ARCH-002): Previously moved order to Preparing status.
 * In V3, Preparing is an invisible engine state — orders stay In Progress while being
 * prepared. This workflow is called by the Preparation OS when all work is done,
 * transitioning the order to Ready for Dispatch so it can be dispatched.
 *
 * Automatic Reservation Guard (ADR-015 / Phase 8):
 * If a reservation is missing, one is created on-the-fly before the status transition.
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

        // ADR-042 §7 — both fulfilment-eligible states may enter Preparation.
        // B3 makes `confirmed` operationally eligible, so gating on InProgress alone
        // would leave every confirmed order unable to reach Ready for Dispatch.
        if (! in_array($order->status, OrderStatus::fulfilmentEligible(), true)) {
            throw new WorkflowPreconditionException(
                "Order [{$order->id}] must be In Progress or Confirmed to become Ready for Dispatch. Current: [{$order->status->value}].",
            );
        }

        // Block terminal reservation states: Released/Consumed/Transferred mean the inventory
        // commitment has ended. H-2 fix: prevents entering dispatch with zero stock.
        $terminalReservationStates = [
            ReservationStatus::Released,
            ReservationStatus::Consumed,
            ReservationStatus::Transferred,
        ];
        if (in_array($order->reservation_status, $terminalReservationStates, true)) {
            throw new WorkflowPreconditionException(
                "Order [{$order->id}] has reservation_status [{$order->reservation_status?->value}] and cannot become Ready for Dispatch. ".
                'Release and re-reserve inventory before moving to dispatch.',
            );
        }

        // PartialReserved orders require explicit manager approval before dispatch.
        if ($order->reservation_status === ReservationStatus::PartialReserved
            && $order->partial_reservation_approved_at === null
        ) {
            throw new WorkflowPreconditionException(
                "Order [{$order->id}] has a partial reservation and requires manager approval before dispatch. ".
                'Use the approve-partial-reservation endpoint to grant approval.',
            );
        }

        // A missing warehouse is a PRECONDITION FAILURE for this workflow, not a success.
        //
        // This is an operator explicitly asserting "this order is ready for dispatch". It is
        // not, and it cannot be: with no warehouse there is nowhere to reserve from, so the
        // order will not reach ready_for_dispatch no matter what this method returns.
        //
        // It belongs in guard() with the three preconditions above, for four reasons:
        //   1. RC-10 certification (committed) asserts 422 for exactly this request.
        //   2. `FulfillmentResult` cannot express a refusal — the only channel the API has
        //      for refusing an operator-requested transition is WorkflowPreconditionException.
        //   3. The dispatch path already returns 422 on this same condition, so returning
        //      200 here made the tree internally inconsistent.
        //   4. FulfillmentEngine writes its audit OrderEvent AFTER execute(); returning
        //      success recorded a `ready_for_dispatch` event for an order that never became
        //      ready. guard() runs before the transaction, so refusing here writes no event.
        //
        // ADR-027 §2/§10 are untouched by this: they govern the DOMAIN state (decision stays
        // active, execution postponed, reservation_status `pending`, no lifecycle change, and
        // never `awaiting_stock`) and say nothing about the HTTP outcome. The reservation
        // decision is stamped at order entry by ProcessOrderWorkflow / ConfirmOrderWorkflow,
        // not here. The automatic path stays a success; only this operator-initiated one refuses.
        if ($order->assigned_warehouse_id === null) {
            $activeReservationStates = [ReservationStatus::Reserved, ReservationStatus::PartialReserved];

            if (! in_array($order->reservation_status, $activeReservationStates, true)) {
                throw new WorkflowPreconditionException(
                    "Order [{$order->id}] has no assigned warehouse and cannot become Ready for Dispatch. ".
                    'Reservation execution is postponed until a warehouse is assigned; '.
                    'the order remains recoverable and its status is unchanged.',
                );
            }
        }
    }

    public function execute(FulfillmentContext $ctx): FulfillmentResult
    {
        $order = $ctx->order;
        $reservationCreated = false;

        // Automatic Reservation Guard — create reservation on-the-fly when not yet reserved.
        $activeStates = [ReservationStatus::Reserved, ReservationStatus::PartialReserved];
        if (! in_array($order->reservation_status, $activeStates, true)) {
            // The null-warehouse case never reaches here — guard() refuses it with a 422
            // before the transaction opens. See the precondition block above.
            $reservationResult = $this->reserveInventory->execute($order);
            $order->refresh();
            $reservationCreated = true;

            if ($reservationResult === ReservationStatus::AwaitingStock) {
                // Same rule as ProcessOrderWorkflow: the shortage is recorded on
                // `reservation_status`, and only a status that yields to a stock block
                // is rewritten. This guard admits InProgress and Confirmed
                // (OrderStatus::fulfilmentEligible), so the unconditional write here
                // used to silently un-confirm a Confirmed order — the very thing
                // ADR-042 §6 forbids and which this workflow's sibling already avoided.
                //
                // Either way the order does NOT become Ready for Dispatch: it cannot,
                // with nothing reserved.
                $statusChanged = $order->status->yieldsToStockBlock();

                if ($statusChanged) {
                    $order->update(['status' => OrderStatus::AwaitingStock]);
                    $order->refresh();
                }

                return FulfillmentResult::success(
                    $order,
                    $statusChanged
                        ? "Order #{$order->order_number} cannot become Ready for Dispatch — insufficient stock. Moved to Awaiting Stock."
                        : "Order #{$order->order_number} cannot become Ready for Dispatch — insufficient stock. Status [{$order->status->value}] preserved.",
                    [
                        'actor_id' => $ctx->actorId,
                        'reservation_created' => true,
                        'reservation_status' => $order->reservation_status?->value,
                        'status_preserved' => ! $statusChanged,
                        'started_at' => now()->toIso8601String(),
                    ],
                );
            }
        }

        $order->update(['status' => OrderStatus::ReadyForDispatch]);
        $order->refresh();

        $message = "Order #{$order->order_number} is Ready for Dispatch.";
        if ($reservationCreated) {
            $message .= ' Inventory reserved automatically.';
        }

        return FulfillmentResult::success(
            $order,
            $message,
            [
                'actor_id' => $ctx->actorId,
                'reservation_created' => $reservationCreated,
                'warehouse_id' => $order->assigned_warehouse_id,
                'reservation_status' => $order->reservation_status?->value,
                'started_at' => now()->toIso8601String(),
            ],
        );
    }

    /** @return list<object> */
    public function events(FulfillmentResult $result): array
    {
        $order = $result->order;

        if ($order->status !== OrderStatus::ReadyForDispatch) {
            return [];
        }

        return [
            new OrderPreparationStartedEvent(
                orderId: $order->id,
                orderNumber: $order->order_number,
                companyId: $order->company_id ?? '',
                warehouseId: $order->assigned_warehouse_id ?? '',
                // handle() already publishes this into meta alongside actor_id and
                // started_at. It was populated but never read, so the constructor
                // received six of its seven required arguments and always threw.
                reservationStatus: $result->meta['reservation_status'] ?? '',
                actorId: $result->meta['actor_id'] ?? null,
                startedAt: $result->meta['started_at'] ?? now()->toIso8601String(),
            ),
        ];
    }

    public function name(): string
    {
        return 'ready_for_dispatch';
    }
}

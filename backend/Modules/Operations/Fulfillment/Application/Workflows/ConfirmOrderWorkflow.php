<?php

declare(strict_types=1);

namespace Modules\Operations\Fulfillment\Application\Workflows;

use Modules\Commerce\Orders\Application\Actions\ReserveOrderInventoryAction;
use Modules\Commerce\Orders\Application\Actions\UpdateReservationStatusAction;
use Modules\Commerce\Orders\Application\Services\CreateOrderSnapshotService;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Enums\ReservationStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\OrderEvent;
use Modules\Commerce\Orders\Domain\Services\PaymentFulfillmentGate;
use Modules\Operations\Fulfillment\Application\DTOs\FulfillmentContext;
use Modules\Operations\Fulfillment\Application\DTOs\FulfillmentResult;
use Modules\Operations\Fulfillment\Domain\Contracts\FulfillmentWorkflowInterface;
use Modules\Operations\Fulfillment\Domain\Events\OrderConfirmedEvent;
use Modules\Operations\Fulfillment\Domain\Exceptions\WorkflowPreconditionException;

/**
 * Confirm — the explicit operator action that commits an order (ADR-042 §5.3).
 *
 *     in_progress → Confirm → confirmed
 *
 * ADR-042 restores `confirmed` as a canonical state, so this workflow writes
 * Confirmed rather than InProgress. Confirming applies the structural lock: from
 * `confirmed` onward product, price and shipping data are immutable
 * (OrderStatus::isLocked). Unlocking is the `return_to_in_progress` action.
 *
 * RESERVATION IS NOT MOVED HERE (ADR-042 §6). Reservation is triggered at creation
 * by ProcessOrderWorkflow ('initiate_order') and remains governed by ADR-027. The
 * `$alreadyReserved` short-circuit below is what keeps that true: for the normal
 * path — created `in_progress`, reserved at creation, then confirmed — Confirm
 * performs no reservation at all. It reserves only when an order arrives here
 * without one (e.g. created `awaiting_payment`, then paid), which is the same
 * idempotent behaviour this workflow already had.
 */
final class ConfirmOrderWorkflow implements FulfillmentWorkflowInterface
{
    public function __construct(
        private readonly ReserveOrderInventoryAction $reserveInventory,
        private readonly CreateOrderSnapshotService $snapshot,
        private readonly UpdateReservationStatusAction $updateReservationStatus,
        private readonly PaymentFulfillmentGate $paymentGate,
    ) {}

    public function guard(FulfillmentContext $ctx): void
    {
        $order = $ctx->order;

        // `in_progress` is the canonical source. The remaining states are recovery
        // sources that were already permitted before ADR-042 and are preserved so
        // re-activating a held, returned or cancelled order still reaches Confirmed.
        $allowed = [
            OrderStatus::InProgress,
            OrderStatus::AwaitingPayment,
            OrderStatus::AwaitingStock,
            OrderStatus::OnHold,
            OrderStatus::Returned,
            OrderStatus::Cancelled,
        ];

        if (! in_array($order->status, $allowed, true)) {
            throw new WorkflowPreconditionException(
                "Order [{$order->id}] cannot be confirmed from status [{$order->status->value}].",
            );
        }

        // ── Payment gate (ADR-042 §3.1 as amended; Owner decision D1-A) ───────
        // Confirming out of `awaiting_payment` must not bypass the very payment
        // condition that placed the order there. Creation and confirmation now
        // consult the SAME implementation — PaymentFulfillmentGate — so the two
        // can no longer drift; the previous claim that this "mirrors the
        // creation-time contract EXACTLY" had already stopped being true once
        // this gate moved to `payment_proofs` and creation stayed on the
        // superseded `payment_proof_path` string.
        //
        // Methods that need no proof pass unchanged (COD/cash → 'none'), and the
        // sanctioned clearance path is pay-in-full AND verify-proof. Note the
        // requirement itself is resolved from live policy, so the docblock must
        // not restate per-method values — an earlier comment claimed
        // `credit_card → 'optional'` while the live policy said `required`.
        if ($order->status === OrderStatus::AwaitingPayment && ! $this->paymentPermitsConfirmation($order)) {
            throw new WorkflowPreconditionException(
                "Order [{$order->id}] cannot be confirmed while payment is outstanding: its payment method "
                .'requires payment proof and none is attached, and the balance is unpaid. Attach and verify '
                .'payment proof, switch to a cash-on-delivery method, or record payment before confirming.',
            );
        }
    }

    /**
     * Whether an `awaiting_payment` order may be confirmed.
     *
     * The rule itself is UNCHANGED from the certified
     * TASK-ORDERS-PAYMENT-CONFIRMATION-FULFILLMENT-IMPLEMENTATION-001 contract (Decisions 1+3):
     *
     *   method requires no proof ('none' / 'optional')  -> does not block
     *   method REQUIRES proof                           -> paid in full AND a VERIFIED proof
     *
     * WHAT CHANGED IN IMPLEMENTATION-002, and why. The rule was implemented HERE and, in a
     * second, drifted copy, inline in `CreateManualOrderAction`. Two implementations of one
     * financial control is what let the creation path keep honouring the superseded
     * `orders.payment_proof_path` string after this gate stopped reading it. The logic now
     * lives in exactly one place — `PaymentFulfillmentGate` — which this workflow and every
     * creation path consult. No behaviour changes for a channel-bound order; the gate resolves
     * a NULL channel down the documented policy chain instead of hardcoding `'none'` (D2-B).
     *
     * WHAT DELIBERATELY DID NOT CHANGE. Methods whose requirement is not 'required' behave
     * exactly as before — COD and cash ('none') still confirm with no payment whatsoever,
     * which is the approved COD contract.
     */
    private function paymentPermitsConfirmation(Order $order): bool
    {
        // ADVANCE decision. This is consulted only when the order is `awaiting_payment`
        // (see the guard above), so confirming from here moves it INTO fulfilment
        // eligibility — which means a blank method must not satisfy it (BL-2-A).
        return $this->paymentGate->permitsAdvance($order);
    }

    public function execute(FulfillmentContext $ctx): FulfillmentResult
    {
        $order = $ctx->order;

        // Returned orders had inventory released — reset lifecycle fields before re-reserving.
        if ($order->status === OrderStatus::Returned) {
            $order->update([
                'inventory_reserved_at' => null,
                'inventory_released_at' => null,
                'inventory_shipped_at' => null,
                'reservation_status' => null,
                'reservation_failure_reason' => null,
            ]);
            $order->refresh();
        }

        // Clear on_hold / cancel metadata on re-activation
        if (in_array($order->status, [OrderStatus::OnHold, OrderStatus::Cancelled], true)) {
            $order->update([
                'rescheduled_at' => null,
                'next_delivery_date' => null,
                'resume_from_status' => null,
                'reschedule_reason' => null,
            ]);
            $order->refresh();
        }

        $activeStates = [ReservationStatus::Reserved, ReservationStatus::PartialReserved];
        $alreadyReserved = in_array($order->reservation_status, $activeStates, true);

        $snapshotCreated = false;
        $reservationPostponed = false;

        if (! $alreadyReserved) {
            // No warehouse assigned → reservation EXECUTION is postponed.
            // ADR-027 §2 (pending = decision made, execution pending) and §10
            // (no coverage is a Command Center signal, not a status change).
            //
            // ADR-042: this branch must NOT short-circuit the confirmation. A missing
            // warehouse is a reservation-execution concern; Confirm is a lifecycle
            // action. Returning early here (as this workflow did while it merely wrote
            // InProgress, where the write was a no-op) would silently swallow the
            // operator's confirmation and leave the order at in_progress. So the
            // postponement is recorded and execution FALLS THROUGH to the status write.
            if ($order->assigned_warehouse_id === null) {
                $this->updateReservationStatus->execute(
                    $order,
                    ReservationStatus::Pending,
                    'Warehouse Not Assigned',
                );

                OrderEvent::log(
                    orderId: $order->id,
                    type: 'reservation_execution_postponed',
                    description: "Reservation decision active for order #{$order->order_number}; execution postponed — no warehouse assigned.",
                    payload: [
                        'reason' => 'no_warehouse_assigned',
                        'reservation_status' => ReservationStatus::Pending->value,
                        'lifecycle_status' => $order->status->value,
                    ],
                    module: 'fulfillment',
                );

                $reservationPostponed = true;
            } else {
                $reservationStatus = $this->reserveInventory->execute($order);
                $order->refresh();

                // A finished-good shortage IS a lifecycle outcome (ADR-027 §3 Case 4),
                // unlike a missing warehouse. It legitimately overrides the confirmation.
                if ($reservationStatus === ReservationStatus::AwaitingStock) {
                    $order->update(['status' => OrderStatus::AwaitingStock]);
                    $order->refresh();

                    return FulfillmentResult::success(
                        $order,
                        "Order #{$order->order_number} awaiting stock — insufficient inventory.",
                        ['snapshot_created' => false, 'actor_id' => $ctx->actorId, 'reservation_failed' => true],
                    );
                }

                $snapshot = $this->snapshot->createIfAbsent($order);
                $snapshotCreated = $snapshot !== null;
            }
        }

        $order->update([
            'status' => OrderStatus::Confirmed,
            'confirmed_at' => $order->confirmed_at ?? now(),
        ]);
        $order->refresh();

        $message = match (true) {
            $reservationPostponed => "Order #{$order->order_number} confirmed. Reservation execution postponed — no warehouse assigned.",
            $alreadyReserved => "Order #{$order->order_number} confirmed.",
            default => "Order #{$order->order_number} confirmed. Inventory reserved.",
        };

        return FulfillmentResult::success(
            $order,
            $message,
            [
                'snapshot_created' => $snapshotCreated,
                'actor_id' => $ctx->actorId,
                'reservation_postponed' => $reservationPostponed,
            ],
        );
    }

    /** @return list<object> */
    public function events(FulfillmentResult $result): array
    {
        if ($result->meta['reservation_failed'] ?? false) {
            return [];
        }

        return [
            new OrderConfirmedEvent(
                orderId: $result->order->id,
                orderNumber: $result->order->order_number,
                companyId: $result->order->company_id ?? '',
                warehouseId: $result->order->assigned_warehouse_id ?? '',
                reservedAt: $result->order->inventory_reserved_at?->toIso8601String() ?? now()->toIso8601String(),
                snapshotCreated: (bool) ($result->meta['snapshot_created'] ?? false),
                actorId: $result->meta['actor_id'] ?? null,
            ),
        ];
    }

    public function name(): string
    {
        return 'confirm_order';
    }
}

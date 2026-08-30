<?php

declare(strict_types=1);

namespace Modules\Operations\Fulfillment\Application\Workflows;

use Modules\Commerce\Orders\Application\Actions\ReserveOrderInventoryAction;
use Modules\Commerce\Orders\Application\Actions\UpdateReservationStatusAction;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Enums\ReservationStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\OrderEvent;
use Modules\Commerce\Orders\Domain\Models\OrderReservationAudit;
use Modules\Commerce\Orders\Domain\Services\PaymentFulfillmentGate;
use Modules\Inventory\InventoryItems\Domain\Models\InventoryItem;
use Modules\Operations\Fulfillment\Application\DTOs\FulfillmentContext;
use Modules\Operations\Fulfillment\Application\DTOs\FulfillmentResult;
use Modules\Operations\Fulfillment\Domain\Contracts\FulfillmentWorkflowInterface;
use Modules\Operations\Fulfillment\Domain\Events\InterWarehouseTransferRequestedEvent;
use Modules\Operations\Fulfillment\Domain\Exceptions\WorkflowPreconditionException;

/**
 * Initiates an order: reserves inventory and moves to In Progress.
 *
 * V3 (TASK-ORDERS-LIFECYCLE-ARCH-002): Replaces both ProcessOrderWorkflow and
 * ConfirmOrderWorkflow. All new orders auto-trigger this workflow on creation.
 *
 * Inventory Reservation Contract:
 * - Reservation is ALWAYS attempted when entering In Progress.
 * - No warehouse → reservation EXECUTION is postponed (reservation_status = pending);
 *   the lifecycle status is not touched (ADR-027 §2/§10).
 * - Insufficient stock → reservation_status = awaiting_stock ALWAYS; the lifecycle
 *   status follows only for statuses that yield to a stock block
 *   (OrderStatus::yieldsToStockBlock()). Awaiting Payment, Scheduled and Confirmed
 *   keep their state — inventory reports the shortage, it does not govern the status.
 * - Successful reservation advances to In Progress only from statuses that admit it
 *   (OrderStatus::advancesToInProgressOnReservation()) — never from Awaiting Payment
 *   or Confirmed.
 * - Awaiting Payment is activated to In Progress BEFORE availability is consulted, and
 *   ONLY when PaymentFulfillmentGate permits (ADR-042 §7.1, owner decision M1). The enum
 *   helper above still excludes it, deliberately: that exclusion is the financial backstop
 *   for the generic status-patch route into this workflow, which evaluates no gate of its
 *   own. Both routes are therefore gated by the one check below.
 * - Idempotent: existing reservation is preserved and not duplicated.
 */
final class ProcessOrderWorkflow implements FulfillmentWorkflowInterface
{
    public function __construct(
        private readonly ReserveOrderInventoryAction $reserveInventory,
        private readonly UpdateReservationStatusAction $updateReservationStatus,
        // The EXISTING canonical financial gate (ADR-042 §3.1). Injected rather than
        // abstracted away: it is already the single implementation every other payment
        // decision consults, and an interface introduced only to avoid this dependency
        // would be a second seam with no second implementation behind it.
        private readonly PaymentFulfillmentGate $paymentGate,
    ) {}

    public function guard(FulfillmentContext $ctx): void
    {
        $order = $ctx->order;

        $allowed = [
            OrderStatus::AwaitingPayment,
            OrderStatus::AwaitingStock,
            OrderStatus::Scheduled,
            OrderStatus::OnHold,
            OrderStatus::Cancelled,
            // InProgress allowed for idempotent re-initiation. ADR-042 §6 makes this
            // the primary path: normal orders are CREATED at InProgress and this
            // workflow reserves for them, writing InProgress over InProgress (a no-op)
            // so PICK-AND-STAY holds.
            OrderStatus::InProgress,
            // Confirmed allowed so a late warehouse assignment can still execute the
            // reservation for an already-confirmed order (ADR-027 H3). The terminal
            // write below deliberately preserves Confirmed — reserving must not
            // silently un-confirm an order.
            OrderStatus::Confirmed,
        ];

        if (! in_array($order->status, $allowed, true)) {
            throw new WorkflowPreconditionException(
                "Order [{$order->id}] cannot be initiated from status [{$order->status->value}].",
            );
        }

        // Scheduled orders must not enter the operational queue before their activation
        // point. That point is D-1, not D: an order due tomorrow has to be picked,
        // prepared and staged today, so waiting until the delivery date itself leaves no
        // operational day to do it in.
        //
        // The window opens at `requested_delivery_date - 1 day`, which is what
        // ActivateScheduledOrdersCommand selects on. Both must agree — a command that
        // selects an order this guard then rejects would log a skip every night forever.
        if ($order->status === OrderStatus::Scheduled) {
            // `requested_delivery_date` is cast `date:Y-m-d`, so this attribute is a
            // Carbon instance and the cast format governs only serialisation. Casting it
            // to string yields "Y-m-d H:i:s", and comparing THAT to a "Y-m-d" string is
            // true for every date — "2026-08-15 00:00:00" > "2026-08-15" — so the guard
            // rejected even an order whose window had already opened, and scheduled
            // activation never once succeeded. Normalise to a day before comparing.
            $rawDeliveryDate = $order->requested_delivery_date;
            $deliveryDate = $rawDeliveryDate instanceof \DateTimeInterface
                ? $rawDeliveryDate->format('Y-m-d')
                : (string) ($rawDeliveryDate ?? '');
            $activationDate = now()->addDay()->toDateString();
            $forceActivate = (bool) ($ctx->get('force_activate') ?? false);

            if ($deliveryDate !== '' && $deliveryDate > $activationDate && ! $forceActivate) {
                throw new WorkflowPreconditionException(
                    "Order [{$order->id}] is Scheduled for [{$deliveryDate}] and cannot enter the operational queue before [{$activationDate}], one day prior. Pass force_activate=true to override.",
                );
            }
        }
    }

    public function execute(FulfillmentContext $ctx): FulfillmentResult
    {
        $order = $ctx->order;

        // Clear on_hold / cancel metadata on re-activation
        if (in_array($order->status, [OrderStatus::OnHold, OrderStatus::Cancelled], true)) {
            $order->update([
                'rescheduled_at'     => null,
                'next_delivery_date' => null,
                'resume_from_status' => null,
                'reschedule_reason'  => null,
            ]);
            $order->refresh();
        }

        // ADR-042 §7 — a Scheduled order's own trigger has fired by the time this workflow
        // runs (the delivery-date guard above is what proves the D-1 window is open), and
        // §7 states such orders "enter `in_progress` and become eligible by that route".
        // The activation must therefore happen BEFORE availability is consulted.
        //
        // Reserving first was the defect: on a shortage the workflow asked
        // yieldsToStockBlock(), which correctly excludes Scheduled, so the status was
        // preserved and the order stayed Scheduled — permanently, because its activation
        // trigger had already fired and would not fire again. Activating first lets
        // ADR-042 §6.1 apply normally ("Finished-good shortage → write awaiting_stock"),
        // which is the documented clause-F outcome.
        //
        // This is NOT the same as adding Scheduled to yieldsToStockBlock(): that would let
        // a shortage eject a FUTURE-dated order from Scheduled, which ADR-042 §5 rule 1
        // ("`scheduled` stays `scheduled` until its existing scheduling trigger fires")
        // forbids. The asymmetry between the two helpers is deliberate and is preserved.
        if ($order->status === OrderStatus::Scheduled) {
            $order->update(['status' => OrderStatus::InProgress]);
            $order->refresh();
        }

        // ── AwaitingPayment: activate ONLY when the financial gate permits ────────────
        //
        // ADR-042 §7.1 (amended 2026-08-23, owner decision Q2/M1): a payment-fact trigger
        // that satisfies `PaymentFulfillmentGate` advances `awaiting_payment -> in_progress`.
        // Never to `confirmed` — Confirm is an explicit operator action (§5 rule 3).
        //
        // WHY THE GATE IS EVALUATED HERE AND NOT IN THE ENUM.
        // `OrderStatus::advancesToInProgressOnReservation()` deliberately still EXCLUDES
        // AwaitingPayment, and that exclusion must stay: this workflow is reachable from
        // `PatchOrderAction::resolveWorkflow()` (`$to === InProgress => processWorkflow`)
        // with NO payment-gate evaluation anywhere on that path. Widening the helper would
        // therefore have made `PATCH /orders/{id} {status: in_progress}` a silent bypass of
        // a financial control for an unpaid order (blocker BL-1). The exclusion is a
        // financial backstop, not merely a stock rule.
        //
        // Gating the activation HERE closes that hole by construction: BOTH the
        // payment-triggered path and the generic status-patch path now pass through the same
        // check, so neither can advance an order whose payment is outstanding.
        //
        // ONE GATE, NOT A SECOND ONE. `permits()` is the same single implementation
        // `ConfirmOrderWorkflow::paymentPermitsConfirmation()` and
        // `ReevaluateOrderFulfillmentAction` consult. No predicate is restated here.
        //
        // ACTIVATE-FIRST, for the reason the Scheduled block above documents: if reservation
        // ran first, a shortage would ask `yieldsToStockBlock()` — which correctly excludes
        // AwaitingPayment — so the status would be preserved and a now-payable order would
        // stay parked on payment forever, its trigger already spent. Activating first lets
        // ADR-042 §6.1 apply normally, so a shortage produces `awaiting_stock` from
        // `in_progress` instead of stranding the order.
        //
        // This is NOT the same as adding AwaitingPayment to `yieldsToStockBlock()`: a stock
        // shortage must never eject an unpaid order from `awaiting_payment` and hide its
        // payment blocker behind a stock one. That asymmetry is deliberate and is preserved.
        // ┌─ NOT AT CREATION ────────────────────────────────────────────────────────┐
        // │ CreateManualOrderAction runs this workflow for every status in             │
        // │ `decidesAvailabilityAtCreation()`, which includes `awaiting_payment`, and   │
        // │ CLOSURE-001 PART 1/23-B states the invariant that call site relies on:      │
        // │ deciding availability at creation must NOT touch the payment block — the    │
        // │ order "stays Awaiting Payment and merely learns whether the goods exist".   │
        // │                                                                          │
        // │ Without this flag the advance fired inside the creation request itself, so  │
        // │ an operator who deliberately picked Awaiting Payment for a method that      │
        // │ needs no proof (cod) saw it saved as In Progress — `entryStatuses()` offers │
        // │ the state and the same request took it away. Whether that correction is     │
        // │ desirable is an OWNER decision, not a side effect of closing BL-1, so the   │
        // │ documented invariant is preserved and the question is reported.             │
        // │                                                                          │
        // │ SUPPRESSING THE ADVANCE IS THE SAFE DIRECTION, so BL-1 stays closed: this   │
        // │ flag can only ever leave an order parked on payment, never advance one the  │
        // │ gate refused. Every other call site — PatchOrderAction's in_progress route  │
        // │ included — keeps the gated advance exactly as M1 specified.                 │
        // └──────────────────────────────────────────────────────────────────────────┘
        $atCreation = (bool) ($ctx->get('creation_availability_decision') ?? false);

        if (! $atCreation
            && $order->status === OrderStatus::AwaitingPayment
            && $this->paymentGate->permitsAdvance($order)
        ) {
            $order->update(['status' => OrderStatus::InProgress]);
            $order->refresh();
        }

        // Idempotent: inventory already held — go straight to InProgress
        $activeStates    = [ReservationStatus::Reserved, ReservationStatus::PartialReserved];
        $alreadyReserved = in_array($order->reservation_status, $activeStates, true);

        if (! $alreadyReserved) {
            // No warehouse assigned → reservation EXECUTION is postponed. The lifecycle
            // status is deliberately NOT touched.
            //
            // ADR-027 §2: the reservation decision is immediate and is not contingent on
            // warehouse availability; when the warehouse is missing the decision stays
            // active and "reservation_status remains pending — meaning: decision made,
            // execution pending". §10 adds that no coverage is "a Command Center signal,
            // not an error" and specifies no status change at all.
            //
            // Writing AwaitingStock here was a contract breach: §3 Case 4 defines
            // awaiting_stock as the residual of the FINISHED-GOOD availability tree, in
            // which warehouse presence plays no part. Overloading it made a geography
            // failure indistinguishable from a stock shortage and — because every
            // recovery path keys on status — left such orders unrecoverable.
            //
            // The blocker is already fully described by the assignment fields that
            // BranchAssignmentEngine wrote moments earlier (warehouse_assignment_source,
            // warehouse_assignment_failure_reason); they are not touched here.
            // Recovery: WarehouseAssigned → ExecuteReservationOnWarehouseAssigned (H3).
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

                return FulfillmentResult::success(
                    $order,
                    "Order #{$order->order_number} is awaiting warehouse assignment — reservation execution postponed.",
                    [
                        'actor_id' => $ctx->actorId,
                        'reservation_postponed' => true,
                        'blocker' => 'no_warehouse_assigned',
                    ],
                );
            }

            // Attempt reservation — does NOT throw for insufficient stock
            $reservationStatus = $this->reserveInventory->execute($order);
            $order->refresh();

            if ($reservationStatus === ReservationStatus::AwaitingStock) {
                // Availability is NOT the order-status authority. `reservation_status`
                // already records the shortage — that is the inventory surface, and it
                // is written by ReserveOrderInventoryAction regardless of what happens
                // here. The lifecycle status is a separate question, and only statuses
                // that yield to a stock block may be rewritten.
                //
                // This write used to be unconditional, which let a shortage overwrite
                // Awaiting Payment, Scheduled and Confirmed alike. Every recovery path
                // keys on status, so an unpaid order silently became "awaiting stock"
                // and its payment blocker vanished from the only column showing it.
                $statusChanged = $order->status->yieldsToStockBlock();

                if ($statusChanged) {
                    $order->update(['status' => OrderStatus::AwaitingStock]);
                    $order->refresh();
                } else {
                    OrderEvent::log(
                        orderId: $order->id,
                        type: 'reservation_awaiting_stock_status_preserved',
                        description: "Order #{$order->order_number} has an inventory shortage; lifecycle status [{$order->status->value}] is preserved because availability does not govern it.",
                        payload: [
                            'lifecycle_status' => $order->status->value,
                            'reservation_status' => ReservationStatus::AwaitingStock->value,
                        ],
                        module: 'fulfillment',
                    );
                }

                $transferShortageLines = $this->resolveInterWarehouseShortage($order);

                return FulfillmentResult::success(
                    $order,
                    $statusChanged
                        ? "Order #{$order->order_number} awaiting stock — insufficient inventory."
                        : "Order #{$order->order_number} has an inventory shortage; status [{$order->status->value}] preserved.",
                    [
                        'actor_id'               => $ctx->actorId,
                        'reservation_failed'     => true,
                        'status_preserved'       => ! $statusChanged,
                        'transfer_requested'     => $transferShortageLines !== null,
                        'transfer_shortage_lines' => $transferShortageLines,
                        'transfer_requested_at'  => $transferShortageLines !== null ? now()->toIso8601String() : null,
                    ],
                );
            }
        }

        // Having stock is not a reason to advance an order whose lifecycle is blocked
        // on something else. ADR-042 §6 already kept Confirmed (a strictly later state)
        // from being walked back to InProgress; Awaiting Payment is excluded for the
        // symmetric reason — a successful reservation says nothing about whether the
        // customer has paid, and forcing In Progress here discarded the payment block
        // exactly as the shortage path above discarded it.
        $advanced = $order->status->advancesToInProgressOnReservation();

        if ($advanced) {
            $order->update(['status' => OrderStatus::InProgress]);
        }
        $order->refresh();

        return FulfillmentResult::success(
            $order,
            match (true) {
                ! $advanced => "Inventory reserved for order #{$order->order_number}; status [{$order->status->value}] preserved.",
                $alreadyReserved => "Order #{$order->order_number} moved to In Progress.",
                default => "Order #{$order->order_number} moved to In Progress. Inventory reserved.",
            },
            ['actor_id' => $ctx->actorId, 'status_preserved' => ! $advanced],
        );
    }

    /** @return list<object> */
    public function events(FulfillmentResult $result): array
    {
        if (! ($result->meta['transfer_requested'] ?? false)) {
            return [];
        }

        $order = $result->order;

        return [
            new InterWarehouseTransferRequestedEvent(
                orderId: $order->id,
                orderNumber: $order->order_number,
                companyId: $order->company_id ?? '',
                assignedWarehouseId: $order->assigned_warehouse_id ?? '',
                shortageLines: $result->meta['transfer_shortage_lines'] ?? [],
                requestedAt: $result->meta['transfer_requested_at'] ?? now()->toIso8601String(),
                actorId: $result->meta['actor_id'] ?? null,
            ),
        ];
    }

    public function name(): string
    {
        return 'initiate_order';
    }

    /**
     * P1-005 — Query other warehouses for shortage products.
     *
     * @return list<array{product_id: string, sku: string|null, required_qty: float, available_warehouses: list<array{warehouse_id: string, available_qty: float}>}>|null
     */
    private function resolveInterWarehouseShortage(Order $order): ?array
    {
        if ($order->assigned_warehouse_id === null) {
            return null;
        }

        $audit = OrderReservationAudit::where('order_id', $order->id)
            ->where('to_status', ReservationStatus::AwaitingStock->value)
            ->latest('created_at')
            ->first();

        if ($audit === null) {
            return null;
        }

        $auditLines = $audit->meta['lines'] ?? [];
        $shortageProductIds = collect($auditLines)
            ->filter(fn ($l) => in_array($l['outcome'], ['none', 'partial'], true))
            ->pluck('product_id')
            ->unique()
            ->values()
            ->all();

        if (empty($shortageProductIds)) {
            return null;
        }

        $alternativeItems = InventoryItem::whereIn('product_id', $shortageProductIds)
            ->where('warehouse_id', '!=', $order->assigned_warehouse_id)
            ->whereRaw('(on_hand_qty - reserved_qty) > 0')
            ->get(['product_id', 'warehouse_id', 'on_hand_qty', 'reserved_qty'])
            ->groupBy('product_id');

        if ($alternativeItems->isEmpty()) {
            return null;
        }

        $shortageLines = [];

        foreach ($auditLines as $line) {
            if (! in_array($line['outcome'], ['none', 'partial'], true)) {
                continue;
            }

            $productId   = $line['product_id'];
            $requiredQty = (float) $line['requested'] - (float) ($line['reserved'] ?? 0);
            $alternatives = $alternativeItems->get($productId, collect());

            if ($alternatives->isEmpty()) {
                continue;
            }

            $shortageLines[] = [
                'product_id'          => $productId,
                'sku'                 => null,
                'required_qty'        => $requiredQty,
                'available_warehouses' => $alternatives
                    ->map(fn ($item) => [
                        'warehouse_id'  => $item->warehouse_id,
                        'available_qty' => max(0.0, (float) $item->on_hand_qty - (float) $item->reserved_qty),
                    ])
                    ->values()
                    ->all(),
            ];
        }

        return empty($shortageLines) ? null : $shortageLines;
    }
}

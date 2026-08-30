<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Domain\Enums;

/**
 * Canonical Order Status lifecycle — ADR-042 (Order FSM V3 Canonical).
 *
 * Primary flow:  In Progress → Confirmed → Ready for Dispatch → Out for Delivery → Delivered
 * Entry states:  In Progress (normal) / Scheduled (future-dated) / Awaiting Payment
 * Exception:     Awaiting Payment / Awaiting Stock / Scheduled / On Hold
 * Terminal:      Delivered / Cancelled / Returned
 *
 * ADR-042 supersedes the vocabulary installed by TASK-ORDERS-LIFECYCLE-ARCH-002:
 *   - `new` is REMOVED. Normal orders are created directly at In Progress.
 *   - `confirmed` is RESTORED. Confirm is an explicit operator action, not a timestamp.
 *
 * Historical `new` rows are normalised to `in_progress` by
 * 2026_08_13_100000_supersede_order_lifecycle_v3_canonical, which MUST run in the
 * same deploy as this file — see ADR-042 §11.
 */
enum OrderStatus: string
{
    // ── Primary Flow ──────────────────────────────────────────────────────
    case InProgress = 'in_progress';
    case Confirmed = 'confirmed';
    case ReadyForDispatch = 'ready_for_dispatch';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';

    // ── Exception States ──────────────────────────────────────────────────
    case AwaitingPayment = 'awaiting_payment';
    case AwaitingStock = 'awaiting_stock';
    case Scheduled = 'scheduled';
    case OnHold = 'on_hold';

    // ── Terminal ──────────────────────────────────────────────────────────
    case Cancelled = 'cancelled';
    case Returned = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::InProgress => 'In Progress',
            self::Confirmed => 'Confirmed',
            self::ReadyForDispatch => 'Ready for Dispatch',
            self::OutForDelivery => 'Out for Delivery',
            self::Delivered => 'Delivered',
            self::AwaitingPayment => 'Awaiting Payment',
            self::AwaitingStock => 'Awaiting Stock',
            self::Scheduled => 'Scheduled',
            self::OnHold => 'On Hold',
            self::Cancelled => 'Cancelled',
            self::Returned => 'Returned',
        };
    }

    /**
     * Terminal: order has reached its final business state.
     * Delivered = fulfilled; Cancelled = explicitly ended; Returned = return processed.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Delivered, self::Cancelled, self::Returned], true);
    }

    /**
     * Structural lock: product/price/shipping data is immutable from Confirmed onward.
     * To unlock, return the order to In Progress or Awaiting Payment.
     *
     * ADR-042 §2.2: the entry role — and with it the unlocked semantics — transferred
     * from `new` to `in_progress` when `new` was removed. Without that transfer every
     * manually created order would be structurally uneditable from the instant of
     * creation, because creation now lands directly on In Progress.
     */
    public function isLocked(): bool
    {
        return ! in_array($this, [self::InProgress, self::Scheduled, self::AwaitingPayment], true);
    }

    /** Returns true if the order is pre-activation (future-dated). */
    public function isPreActivation(): bool
    {
        return $this === self::Scheduled;
    }

    /**
     * Has this order already moved PAST preparation?
     *
     * This is a lifecycle-POSITION fact, not a second eligibility predicate.
     * `fulfilmentEligible()` above remains the one and only admission rule; this answers a
     * different question — "is preparation still this order's concern at all?" — and the
     * two are asked together only by RC-3 wave retention (OrderPreparationObserver).
     *
     * WHY IT HAD TO EXIST. Wave membership is released by wave CLOSURE, never by order
     * completion: `preparation_wave_orders.released_at` is written only by closeWave() and
     * CancelWaveAction. A successfully prepared order therefore keeps an ACTIVE membership
     * while it walks ready_for_dispatch -> out_for_delivery -> delivered. None of those three
     * appear in `fulfilmentEligible()`, so a retention check written as the bare negation of
     * admission would read normal forward progress as "became ineligible" and evict the
     * order — decrementing `orders_count`, which CompleteWaveAction reports as the count of
     * what the wave actually prepared, and firing a spurious demand recompute on every
     * dispatch. Admission and retention are genuinely different questions; conflating them
     * silently corrupts the wave's own record of its work.
     *
     * NOT `isTerminal()`. That set is {delivered, cancelled, returned} — it mixes the order
     * that completed THROUGH preparation with the two that abandoned it. Abandonment must
     * still evict; completion must not.
     */
    public function hasLeftPreparation(): bool
    {
        return in_array($this, [
            self::ReadyForDispatch,
            self::OutForDelivery,
            self::Delivered,
        ], true);
    }

    /**
     * Entry statuses an order may be CREATED in (ADR-042 §3).
     *
     * `confirmed` is deliberately absent: it is reachable only through the Confirm
     * action, never by picking it at creation.
     *
     * @return list<self>
     */
    public static function entryStatuses(): array
    {
        return [
            self::InProgress,
            self::Scheduled,
            self::AwaitingPayment,
        ];
    }

    /**
     * Entry statuses whose availability decision is taken AT CREATION.
     *
     * TASK-ORDERS-LIFECYCLE-AVAILABILITY-RESERVATION-CLOSURE-001 PART 1: "DO NOT wait
     * for a scheduler to perform the first reservation attempt. The availability
     * decision must happen as part of the canonical order creation/availability
     * workflow." An order that takes no decision has no reservation outcome at all, and
     * a null outcome is what the UI was rendering as "Pending" — a state PART 5 rules
     * out of the business vocabulary entirely.
     *
     * `awaiting_payment` is here BECAUSE payment and availability are independent axes
     * (PART 11). Deciding availability tells the operator whether the goods exist; it
     * does not pay for them, and ProcessOrderWorkflow keeps the payment block on the
     * lifecycle column either way (advancesToInProgressOnReservation is false for it).
     * Leaving it out did not protect the payment state — it simply meant an unpaid order
     * never learned whether it could be fulfilled.
     *
     * `scheduled` is deliberately ABSENT, and this is the one deferral the contract
     * grants: PART 12 gives it its own activation rule (D-1), after which availability
     * and reservation run normally. A future-dated order holds no inventory today, so
     * deciding now would only reserve stock the order does not yet need — and
     * ActivateScheduledOrdersCommand is the trigger that decides it later.
     *
     * @return list<self>
     */
    public static function decidesAvailabilityAtCreation(): array
    {
        return [
            self::InProgress,
            self::AwaitingPayment,
        ];
    }

    /**
     * Statuses that Preparation, Distribution and the Wave Engine treat as
     * fulfilment-eligible (ADR-042 §7).
     *
     * A closed list: `scheduled` and `awaiting_payment` sit outside fulfilment
     * execution until their own business triggers move them to In Progress.
     *
     * @return list<self>
     */
    public static function fulfilmentEligible(): array
    {
        return [
            self::InProgress,
            self::Confirmed,
        ];
    }

    /**
     * May an inventory shortage move an order OUT of this status into Awaiting Stock?
     *
     * Availability answers exactly one question — does stock block fulfilment — and it
     * is NOT the order-status authority. Three states are therefore out of its reach,
     * each for its own reason:
     *
     *   Awaiting Payment — a payment block outranks an inventory one. An unpaid order
     *     whose stock is also short is still, first and foremost, unpaid; rewriting it
     *     to Awaiting Stock loses the blocker the operator must actually clear.
     *   Scheduled — scheduling and availability are independent axes. A future-dated
     *     order has not entered the operational queue yet, so today's stock position
     *     says nothing about it. It waits for its own activation trigger.
     *   Confirmed — strictly later than In Progress (ADR-042 §6). Reserving must never
     *     walk an order backwards, and neither may failing to reserve.
     *
     * In every one of those cases `reservation_status` still records the shortage
     * truthfully, so the Inventory Execution surface reports Awaiting Stock while the
     * Status column keeps the canonical lifecycle state. The two columns answer
     * different questions and are no longer collapsed into one.
     */
    public function yieldsToStockBlock(): bool
    {
        return in_array($this, [
            self::InProgress,
            self::AwaitingStock,
            // Reactivation paths — ProcessOrderWorkflow clears the hold/cancel metadata
            // and re-runs reservation, so a shortage found on the way back in is a
            // genuine Awaiting Stock.
            self::OnHold,
            self::Cancelled,
        ], true);
    }

    /**
     * May a SUCCESSFUL reservation move an order from this status to In Progress?
     *
     * The mirror of {@see yieldsToStockBlock()}, and deliberately not its complement:
     * Scheduled appears here but not there. That asymmetry is the whole point of
     * keeping scheduling and availability apart — a Scheduled order is never pushed
     * out of Scheduled by a stock shortage, but its own activation trigger (D-1) does
     * move it forward, and reservation is what runs at that moment.
     *
     * Awaiting Payment and Confirmed are excluded in BOTH directions: having stock is
     * no more a reason to declare an unpaid order In Progress than lacking it is a
     * reason to declare it Awaiting Stock.
     *
     * NOTE (2026-08-23): the exclusion of `AwaitingPayment` here is ALSO load-bearing as a
     * payment-gate backstop, not only as a stock rule. `ProcessOrderWorkflow` is reachable
     * from `PatchOrderAction::resolveWorkflow()` (`$to === InProgress => processWorkflow`)
     * WITHOUT any payment-gate evaluation, so this line is currently the only thing stopping
     * `PATCH /orders/{id} {status: in_progress}` from advancing an unpaid order. See
     * TASK-ORDER-PAYMENT-PREPARATION-IMPLEMENTATION — blocker BL-1.
     */
    public function advancesToInProgressOnReservation(): bool
    {
        return in_array($this, [
            self::InProgress,
            self::AwaitingStock,
            self::Scheduled,
            self::OnHold,
            self::Cancelled,
        ], true);
    }

    /**
     * Official display order across all dashboards, filters, and analytics.
     */
    public static function displayOrder(): array
    {
        return [
            self::AwaitingPayment,
            self::InProgress,
            self::Confirmed,
            // Schedule follows Confirm — same order the Orders workspace renders.
            self::Scheduled,
            self::AwaitingStock,
            self::ReadyForDispatch,
            self::OutForDelivery,
            self::Delivered,
            self::Returned,
            self::OnHold,
            self::Cancelled,
        ];
    }
}

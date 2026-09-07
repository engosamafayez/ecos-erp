<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Domain\Services;

use DateTimeInterface;
use Modules\Commerce\Orders\Domain\Models\Order;

/**
 * THE canonical authority for "is this Order still pre-activation because its
 * requested delivery date is in the future" — TASK-ECOS-COMMERCE-IAM-NOTIFICATIONS-
 * FINAL-USER-REVIEW-REMEDIATION-005 (C3).
 *
 * BEFORE THIS CLASS EXISTED, the only place that actually enforced "a future-dated
 * order must not enter the operational queue" was inlined inside
 * ProcessOrderWorkflow::guard(), and it only ran when the order's `status` column
 * was ALREADY exactly `scheduled`. That is not the same question as "is the
 * delivery date still future" — an order can reach `in_progress` (or any other
 * status) by a route that never passed through `scheduled` at all, e.g.:
 *
 *   - CreateManualOrderAction's PICK-AND-STAY rule stores an explicitly submitted
 *     `status: in_progress` verbatim even when requested_delivery_date is future —
 *     an intentional operator override, left untouched by this class.
 *   - UpdateOrderAction treats requested_delivery_date as a SOFT field, editable on
 *     an already-`in_progress` order with zero status re-evaluation.
 *
 * Once an order is sitting at `in_progress` with a future date, every OTHER
 * workflow's guard (ConfirmOrderWorkflow above all) had no date awareness
 * whatsoever and confirmed it without a second thought. This class is consulted
 * by both ProcessOrderWorkflow and ConfirmOrderWorkflow so the two can no longer
 * drift, and it reads `requested_delivery_date` directly — independent of
 * whatever `status` currently (possibly incorrectly) says.
 *
 * THE THRESHOLD IS D-1, NOT D, copied verbatim from the one place that already
 * had it right (ProcessOrderWorkflow's own Scheduled-source guard, and
 * ActivateScheduledOrdersCommand's query): an order due tomorrow has to be
 * picked, prepared and staged today, so the activation window opens one day
 * before the requested delivery date, not on the date itself. Both callers MUST
 * keep agreeing on this window — see activationHorizon().
 *
 * `force_activate` is the one sanctioned override (ActivateScheduledOrdersCommand
 * --force, and any workflow context that explicitly declares it), unchanged from
 * the existing contract.
 */
final class ScheduledFulfillmentGate
{
    /**
     * True when `$order`'s requested delivery date is still far enough in the
     * future that fulfillment must not advance past Scheduled for it — REGARDLESS
     * of what the order's `status` column currently holds.
     */
    public function isFutureDated(Order $order, bool $forceActivate = false): bool
    {
        if ($forceActivate) {
            return false;
        }

        // `requested_delivery_date` is cast `date:Y-m-d`, so this attribute is a
        // Carbon instance at runtime and the cast format governs only
        // serialisation. Casting it to string directly yields "Y-m-d H:i:s",
        // which compares greater than a bare "Y-m-d" activation date for every
        // date — "2026-08-15 00:00:00" > "2026-08-15" — so an order whose window
        // had already opened would still be rejected. Normalise to a day first.
        $rawDeliveryDate = $order->requested_delivery_date;
        $deliveryDate = $rawDeliveryDate instanceof DateTimeInterface
            ? $rawDeliveryDate->format('Y-m-d')
            : (string) ($rawDeliveryDate ?? '');

        if ($deliveryDate === '') {
            return false;
        }

        return $deliveryDate > $this->activationHorizon();
    }

    /**
     * The activation window opens at requested_delivery_date - 1 day: any date
     * strictly after this horizon is still "too early". Shared verbatim with
     * ActivateScheduledOrdersCommand's own query so the guard a nightly
     * activation run relies on and the command's own row selection can never
     * silently disagree.
     */
    public function activationHorizon(): string
    {
        return now()->addDay()->toDateString();
    }
}

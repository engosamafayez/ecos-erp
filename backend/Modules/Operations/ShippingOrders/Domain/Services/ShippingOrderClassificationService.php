<?php

declare(strict_types=1);

namespace Modules\Operations\ShippingOrders\Domain\Services;

use Modules\Logistics\Distribution\Domain\Enums\DeliveryStopStatus;
use Modules\Operations\ShippingOrders\Domain\Enums\ShippingOrderClassification;

/**
 * THE single classification authority for the Shipping Orders page —
 * TASK-ECOS-OPERATIONS-SHIPPING-ORDERS-IMPLEMENTATION-002 §13. Backend-authoritative:
 * the frontend never re-derives a classification from raw fields, it only renders
 * whichever one this service returned (§31 of the task).
 */
final class ShippingOrderClassificationService
{
    /**
     * Reason-level mapping — TASK-...-ARCHITECTURE-001-R1 §26, the approved, final
     * mapping. Every one of the 15 canonical FailureReason values is covered exactly
     * once, split across this list, NO_ANSWER_REASONS below, and "everything else"
     * (Postponed) — deliberately reason-level, not category-level: R1 proved the
     * `Customer` category alone splits three ways, so category-level filtering is
     * unsafe.
     *
     * @var list<string>
     */
    private const CANCELLED_REASONS = [
        'customer_refused',
        'product_damaged',
        'wrong_item',
        'item_missing',
    ];

    /** @var list<string> */
    private const NO_ANSWER_REASONS = [
        'customer_unavailable',
        'no_answer',
    ];

    /**
     * Classify one order's shipping-execution state.
     *
     * Precedence (Architecture-001 §12, 001-R1 §15), evaluated top to bottom:
     *   1. DeliveryStop Delivered/Partial                    -> Delivered
     *   2. Settled Failed/Returned/Skipped, reason -> Cancelled/No Answer/Postponed
     *      (a settled failure with NO reason recorded at all defaults to Cancelled —
     *      a safety-first fallback per 001-R1 §15 row 5, not an expected case: the
     *      driver app's own form always supplies a reason for these action types)
     *   3. Unsettled stop with a 'delay' DeliveryAction recorded -> Postponed
     *      (a delay action never changes DeliveryStop.status at all — confirmed
     *      against current source in 001-R1 §6/§9 — so this must be checked before
     *      the InProgress/Pending rows below, on the SAME unsettled status)
     *   4. InProgress                                          -> Out for Delivery
     *   5. Pending + custody evidence                          -> Assigned Driver
     *   -  Pending, no custody evidence                        -> not eligible (null)
     *
     * @param  DeliveryStopStatus  $stopStatus  The order's own DeliveryStop.status.
     * @param  string|null  $latestActionType  The latest DeliveryAction.action_type
     *                                         recorded for this stop, if any.
     * @param  string|null  $latestReason  The latest DeliveryAction.reason recorded
     *                                     for this stop, if any (a FailureReason value).
     * @param  bool  $custodyConfirmed  Whether every LoadingTask covering this order's
     *                                  own line products (within its Trip's vehicle
     *                                  assignment) has been driver-confirmed —
     *                                  Architecture-001 §4/§6's real handoff evidence.
     */
    public function classify(
        DeliveryStopStatus $stopStatus,
        ?string $latestActionType,
        ?string $latestReason,
        bool $custodyConfirmed,
    ): ?ShippingOrderClassification {
        if (in_array($stopStatus, [DeliveryStopStatus::Delivered, DeliveryStopStatus::Partial], true)) {
            return ShippingOrderClassification::Delivered;
        }

        if (in_array($stopStatus, [DeliveryStopStatus::Failed, DeliveryStopStatus::Returned, DeliveryStopStatus::Skipped], true)) {
            if ($latestReason === null) {
                return ShippingOrderClassification::Cancelled;
            }
            if (in_array($latestReason, self::CANCELLED_REASONS, true)) {
                return ShippingOrderClassification::Cancelled;
            }
            if (in_array($latestReason, self::NO_ANSWER_REASONS, true)) {
                return ShippingOrderClassification::NoAnswer;
            }

            // Everything else currently in the FailureReason catalogue — customer_rescheduled,
            // the 3 address reasons, cannot_pay/amount_disputed (CTO decision per this task's
            // own §10 "POSTPONED MAPPING": remain Postponed unless a later canonical terminal
            // outcome supersedes them; flagged as an open-but-defaulted question in R1 §16/§17),
            // and the 3 operational reasons.
            return ShippingOrderClassification::Postponed;
        }

        if ($latestActionType === 'delay') {
            return ShippingOrderClassification::Postponed;
        }

        if ($stopStatus === DeliveryStopStatus::InProgress) {
            return ShippingOrderClassification::OutForDelivery;
        }

        // Pending.
        return $custodyConfirmed ? ShippingOrderClassification::AssignedDriver : null;
    }
}

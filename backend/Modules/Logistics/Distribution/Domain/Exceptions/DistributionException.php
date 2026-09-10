<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Domain\Exceptions;

use Modules\Logistics\Distribution\Domain\Enums\SettlementStatus;
use Modules\Logistics\Distribution\Domain\Enums\TripStatus;
use RuntimeException;

/**
 * Raised when an operation would violate a Distribution business rule.
 * The presentation layer renders these as HTTP 422.
 */
class DistributionException extends RuntimeException
{
    public static function invalidTripTransition(TripStatus $from, TripStatus $to): self
    {
        $allowed = array_map(static fn (TripStatus $s) => $s->label(), $from->allowedTransitions());

        return new self(sprintf(
            'A trip cannot move from %s to %s. Allowed next states: %s.',
            $from->label(),
            $to->label(),
            $allowed === [] ? 'none — this state is terminal' : implode(', ', $allowed),
        ));
    }

    public static function tripNotEditable(TripStatus $status): self
    {
        return new self(sprintf(
            'Orders and custody can only be changed while a trip is in Planning or Loading. This trip is %s.',
            $status->label(),
        ));
    }

    public static function tripAtCapacity(int $capacity): self
    {
        return new self("This trip is already at its capacity of {$capacity} orders.");
    }

    public static function orderAlreadyOnAnotherTrip(string $tripNumber): self
    {
        return new self("That order is already assigned to trip {$tripNumber}. Remove it from that trip first.");
    }

    /** Raised by TripService::releaseOrder() when the order has no association with this trip at all. */
    public static function orderNotOnTrip(string $orderId, string $tripNumber): self
    {
        return new self("Order {$orderId} has no association with trip {$tripNumber}.");
    }

    public static function dispatchBlocked(array $reasons): self
    {
        return new self('This trip cannot be dispatched: '.implode(' ', $reasons));
    }

    public static function assignmentNotActive(): self
    {
        return new self('The selected driver/vehicle assignment is not active.');
    }

    /**
     * The single-active-custody invariant: a driver may hold at most one open operational custody
     * at a time. A second goods custody cannot begin until the driver's current one is closed.
     */
    public static function driverAlreadyHasOpenCustody(): self
    {
        return new self(
            'This driver already has an open operational custody. '
            .'Close the current trip/custody before handing over goods for another.',
        );
    }

    public static function deliveryNotOnTheRoad(TripStatus $status): self
    {
        return new self(sprintf(
            'Deliveries can only be recorded while the trip is on the road. This trip is %s.',
            $status->label(),
        ));
    }

    public static function stopAlreadySettled(): self
    {
        return new self('This stop has already reached an outcome and cannot be re-completed.');
    }

    public static function paymentNotAllowedForStop(): self
    {
        return new self('Payment can only be recorded against a delivered or partially delivered stop.');
    }

    public static function settlementRequiresCompletion(): self
    {
        return new self('A trip can only be settled once every stop has reached an outcome.');
    }

    public static function invalidSettlementTransition(SettlementStatus $from, SettlementStatus $to): self
    {
        $allowed = array_map(static fn (SettlementStatus $s) => $s->label(), $from->allowedTransitions());

        return new self(sprintf(
            'A settlement cannot move from %s to %s. Allowed next states: %s.',
            $from->label(),
            $to->label(),
            $allowed === [] ? 'none — this settlement is final' : implode(', ', $allowed),
        ));
    }

    public static function settlementFinal(): self
    {
        return new self('This settlement is finalized and can no longer be changed.');
    }

    // ── Cash Handover (TASK-ECOS-DRIVER-SETTLEMENT-TREASURY-FINAL-IMPLEMENTATION-002) ──

    public static function cashHandoverAmountInvalid(): self
    {
        return new self('The physically received cash amount must be zero or greater.');
    }

    public static function cashHandoverTripMissing(): self
    {
        return new self('This settlement has no trip to hand over cash against.');
    }

    /**
     * Covers every reason a caller-supplied cash account is unusable: it does not
     * exist, belongs to another company, or is inactive. Reported identically
     * (never distinguished) so a foreign account cannot be probed for existence —
     * the same fail-closed principle SettlementController::resolveTrip() documents.
     */
    public static function cashHandoverAccountInvalid(): self
    {
        return new self('The selected cash account does not exist, is inactive, or is not available to this company.');
    }

    /**
     * Raised when a second confirmation attempt for an already-confirmed settlement
     * supplies a DIFFERENT received amount than the one already on record. A
     * repeat with the SAME amount is not an error — see
     * CashHandoverService::assertSameOrRefuse() — this is only for a genuine conflict.
     */
    public static function cashHandoverAlreadyConfirmed(): self
    {
        return new self(
            'A cash handover has already been confirmed for this settlement with a different amount. '
            .'A confirmed handover cannot be corrected in place — raise a reversal through Finance '
            .'if the confirmed amount was wrong.',
        );
    }

    public static function cashHandoverRaceUnresolved(): self
    {
        return new self('A concurrent cash handover confirmation could not be reconciled. Retry the request.');
    }

    // ── Manual Group creation (TASK-OPERATIONS-DISTRIBUTION-LOADING-FINAL-022) ──

    /**
     * Raised when a CALLER-SUPPLIED `code` collides with a Group that already
     * exists anywhere in the same Window — another warehouse, another Wave, or
     * one that has since closed. `dist_slots_window_code_unique` is keyed on
     * (window, code) alone, so a caller who insists on a specific code that is
     * already taken is refused rather than silently handed a different one.
     */
    public static function groupCodeAlreadyInUse(string $code): self
    {
        return new self("Group code \"{$code}\" is already used in this window. Choose a different code.");
    }

    /**
     * Raised only if every regeneration attempt of a SERVER-GENERATED code still
     * lost the race against a concurrent create. Expected to be effectively
     * unreachable: the generator already reads the same (window, code) scope the
     * unique index guards, so a collision means as many concurrent creates as
     * there are attempts landed in the same window at once.
     */
    public static function groupCodeGenerationFailed(): self
    {
        return new self('Could not assign a Distribution Group code after several attempts. Please retry.');
    }
}

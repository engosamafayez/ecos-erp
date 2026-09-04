<?php

declare(strict_types=1);

namespace Modules\Logistics\Drivers\Domain\Exceptions;

use RuntimeException;

/**
 * A BUSINESS rejection of a fleet assignment — wrong tenant, missing entity, or
 * a group that does not fit the vehicle.
 *
 * WHY THIS TYPE EXISTS RATHER THAN A BARE RuntimeException
 * -------------------------------------------------------
 * `QueryException` extends `PDOException` extends `RuntimeException`. A
 * controller that catches `RuntimeException` to return 422 therefore reports
 * genuine database faults as business rejections — a NOT NULL violation comes
 * back to the operator as "the assignment was rejected", and the real fault is
 * silently swallowed. That is exactly what happened during this task's first
 * test run, and it cost a full debugging cycle to see.
 *
 * Catching this narrow type instead means an infrastructure fault stays a 500,
 * where it is visible.
 */
class FleetAssignmentException extends RuntimeException
{
    /**
     * Deliberately identical whether the entity is absent, archived or owned by
     * another company: distinguishing them would confirm the existence of
     * foreign rows and turn the endpoint into a probe (S-6).
     */
    public static function vehicleNotResolvable(): self
    {
        return new self('Vehicle not found in the active company.');
    }

    public static function driverNotResolvable(): self
    {
        return new self('Driver not found in the active company.');
    }

    public static function crossCompanyPairing(): self
    {
        return new self('A driver and a vehicle from different companies cannot be paired.');
    }

    public static function notInGroupCompany(string $what): self
    {
        return new self(sprintf('The selected %s does not belong to this group\'s company.', $what));
    }

    /** D4-C — capacity is an ORDER COUNT on both sides. */
    public static function groupExceedsVehicleCapacity(
        int $groupOrders,
        string $vehicle,
        int $capacity,
    ): self {
        return new self(sprintf(
            'Group has %d orders but vehicle %s carries %d. Reduce the group or choose a larger vehicle.',
            $groupOrders,
            $vehicle,
            $capacity,
        ));
    }

    /**
     * The chosen driver/vehicle pairing is already committed to a live (non-terminal)
     * trip on another Distribution Group, so it cannot run this one too.
     */
    public static function pairingEngagedElsewhere(string $vehicle): self
    {
        return new self(sprintf(
            'Vehicle %s and its driver are already assigned to another active group. '
            .'Finish or release that assignment first.',
            $vehicle,
        ));
    }

    /**
     * TASK-ECOS-DISTRIBUTION-GROUP-DETAILS-CANONICAL-RECONCILIATION-009 — the
     * server-side mirror of the fleet drawer's `canBeDispatched()` exclusion
     * (see `DistributionWindowController::groupFleetOptions()`). A stale drawer
     * — opened while the vehicle was still dispatchable, submitted after it went
     * to Maintenance/OutOfService/Archived, went InDelivery, or picked up a
     * blocking expired document — must fail here rather than assign a vehicle
     * the selector would no longer have offered.
     */
    public static function vehicleNotDispatchable(string $vehicle): self
    {
        return new self(sprintf(
            'Vehicle %s is not currently available for assignment (off the road, '
            .'archived, or blocked by an expired document). Refresh and choose another vehicle.',
            $vehicle,
        ));
    }

    /**
     * TASK-ECOS-DISTRIBUTION-GROUP-DETAILS-CANONICAL-RECONCILIATION-009-R1 —
     * the truthful rejection for a Vehicle committed to active Operations\
     * Loading work (see GroupVehicleAssignmentService::loadingBusyVehicleUuids()).
     * Deliberately distinct wording from `pairingEngagedElsewhere()` — this is
     * the Vehicle's own Loading commitment, not its pairing's Trip engagement.
     */
    public static function vehicleBusyInLoading(string $vehicle): self
    {
        return new self(sprintf(
            'Vehicle %s is currently assigned to active loading work. '
            .'Refresh and choose another vehicle.',
            $vehicle,
        ));
    }
}

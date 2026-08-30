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
}

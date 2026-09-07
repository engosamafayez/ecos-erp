<?php

declare(strict_types=1);

namespace Modules\Operations\Loading\Application\Listeners;

use Modules\Logistics\Drivers\Domain\Models\Driver;
use Modules\Logistics\Vehicles\Domain\Models\Vehicle;
use Modules\Operations\Loading\Application\Notifications\DriverAssignedNotification;
use Modules\Operations\Loading\Domain\Events\DriverAssigned;

/**
 * TASK-ECOS-NOTIFICATIONS-FINAL-USER-REVIEW-REMEDIATION-010 §7.
 *
 * Recipient resolution is deliberately direct — the specific driver named in the
 * event, resolved through the driver's own real auth identity (Driver::user(), a
 * genuine App\Models\User row per driver, confirmed by the remediation's own audit) —
 * never a broadcast, never an IAM-permission-filtered audience, matching the same
 * "notify the one specific actor" pattern already established by
 * ShortageDetectedNotification/WaveStartedNotification. This is exactly why an
 * unrelated driver, or a driver in a different company, can never receive it: nobody
 * but the named driver is ever a candidate recipient in the first place.
 */
final class NotifyDriverAssigned
{
    public function handle(DriverAssigned $event): void
    {
        $driver = Driver::find($event->driverId);
        $user = $driver?->user;

        if ($user === null) {
            return;
        }

        $vehicle = Vehicle::find($event->vehicleId);
        $vehicleLabel = $vehicle?->plate_number ?? $event->vehicleId;

        $user->notify(new DriverAssignedNotification(
            driverAssignmentId: $event->driverAssignmentId,
            vehicleId: $event->vehicleId,
            vehicleLabel: $vehicleLabel,
        ));
    }
}

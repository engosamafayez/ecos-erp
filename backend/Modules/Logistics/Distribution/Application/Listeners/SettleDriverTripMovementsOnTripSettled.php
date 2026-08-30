<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Application\Listeners;

use Modules\Logistics\Distribution\Domain\Enums\DriverTripMovementStatus;
use Modules\Logistics\Distribution\Domain\Events\TripSettled;
use Modules\Logistics\Distribution\Domain\Models\DriverTripMovement;

/**
 * Settle a trip's APPROVED driver movements at the canonical closing boundary
 * (TASK-OPERATIONS-DRIVER-TRIP-MOVEMENT-APPROVAL-001 §19).
 *
 * `Approved` ≠ automatically `Settled`. Settlement happens ONLY when the trip's cash settlement is
 * finalized — the same canonical event ({@see TripSettled}, dispatched by
 * SettlementService::finalize) that closes the trip. This listener marks every Approved movement on
 * that trip Settled; Pending and Rejected movements are deliberately left untouched. It is
 * idempotent (the WHERE targets Approved only) and never re-opens a settled/rejected record.
 */
final class SettleDriverTripMovementsOnTripSettled
{
    public function handle(TripSettled $event): void
    {
        DriverTripMovement::query()
            ->where('trip_id', $event->trip->id)
            ->where('status', DriverTripMovementStatus::Approved->value)
            ->update([
                'status' => DriverTripMovementStatus::Settled->value,
                'updated_at' => now(),
            ]);
    }
}

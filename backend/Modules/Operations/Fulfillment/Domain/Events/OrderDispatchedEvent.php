<?php

declare(strict_types=1);

namespace Modules\Operations\Fulfillment\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Emitted when an order leaves the warehouse.
 *
 * TWO producers, and they differ in what they know:
 *   - LoadVehicleWorkflow  — the Logistics path; supplies a real vehicle assignment.
 *   - DispatchOrderWorkflow — direct dispatch without vehicle loading; has none.
 *
 * D-10 (TASK-PHASE3-D10-RC10-FINAL-CERTIFY-001): the vehicle fields were declared
 * non-nullable while DispatchOrderWorkflow passes null unconditionally, so that
 * producer could NEVER construct this event — it threw a TypeError after the
 * dispatch transaction had already committed. `driverId` was already nullable;
 * the vehicle pair now matches it, which is the contract the code always implied.
 */
final class OrderDispatchedEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $orderId,
        public readonly string $orderNumber,
        public readonly string $companyId,
        /** Null when dispatched without vehicle loading. */
        public readonly ?string $vehicleAssignmentId,
        /** Null when dispatched without vehicle loading. */
        public readonly ?string $vehicleId,
        public readonly ?string $driverId,
        public readonly float $cogsAmount,
        public readonly string $dispatchedAt,
        public readonly ?string $actorId,
    ) {}
}

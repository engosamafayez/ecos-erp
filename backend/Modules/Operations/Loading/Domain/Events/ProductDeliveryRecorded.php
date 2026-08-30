<?php

declare(strict_types=1);

namespace Modules\Operations\Loading\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * The canonical delivered quantity for one allocation line was recorded
 * (RecordProductDeliveryAction → allocation_records.quantity_delivered, ADR-015
 * "the definitive record of what a driver delivers").
 *
 * The event carries only identifiers — never the quantity. The delivered figure
 * stays on the canonical source (allocation_records), so a listener re-derives it
 * from there rather than trusting a number on the wire. This keeps
 * allocation_records the single source of truth (TASK-DRIVER-04 decision A) and
 * makes the downstream projection idempotent by construction.
 */
final class ProductDeliveryRecorded
{
    use Dispatchable;

    public function __construct(
        public readonly string $orderId,
        public readonly string $orderLineId,
        public readonly string $companyId,
    ) {}
}

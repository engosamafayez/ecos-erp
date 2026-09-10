<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * TASK-ECOS-OPERATIONS-PREPARATION-DRIVER-EOD-FINAL-023 §C/§K — Treasury has
 * physically received and confirmed a driver's Trip cash handover
 * ({@see \Modules\Logistics\Distribution\Domain\Services\CashHandoverService::confirmReceipt()}).
 *
 * Dispatched exactly once per trip settlement (never on an idempotent repeat
 * confirmation), AFTER the confirming transaction has already committed — the
 * physical-cash fact is never at risk from anything a listener does with this
 * event. Distribution announces the fact; it does not know or care that
 * Commerce\Orders reacts to it by advancing delivered Orders to Final Cash —
 * same cross-module direction convention as `WaveClosed`/
 * `HandlePreparationWaveClosed`.
 *
 * Scalar payload, not model references: the confirming service has already
 * moved past the row lock/transaction by the time this fires, and every field
 * a listener needs is already a plain column on {@see \Modules\Logistics\Distribution\Domain\Models\TripCashHandover}.
 */
class TripCashHandoverConfirmed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $tripId,
        public readonly string $tripSettlementId,
        public readonly string $companyId,
        public readonly string $handoverId,
        public readonly float $receivedCash,
        public readonly float $expectedCash,
        public readonly int $confirmedBy,
        public readonly string $confirmedAt,
    ) {}
}

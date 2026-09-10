<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Application\Listeners;

use Modules\Logistics\Distribution\Domain\Services\DeliveryAttemptClosureService;
use Modules\Operations\Preparation\Domain\Events\WaveClosed;

/**
 * TASK-ECOS-OPERATIONS-PREPARATION-DRIVER-EOD-FINAL-023 §D/§E — the
 * operational-day-closure backstop for delivery-attempt outcomes. See
 * {@see DeliveryAttemptClosureService} for the full reasoning.
 *
 * A THIRD listener on `WaveClosed`, alongside `CloseWaveDistributionGroupsListener`
 * (Group closure) and `CloseWaveLoadingCustodyListener` (Task 022's Trip/Loading
 * custody sweep) — registered directly in
 * `LogisticsDistributionServiceProvider::boot()`, not the `LISTENERS` map, for
 * the same reason the second one was: the map holds exactly one value per event
 * key and `WaveClosed` already has one entry.
 */
final class CloseWaveDeliveryAttemptsListener
{
    public function __construct(
        private readonly DeliveryAttemptClosureService $closure,
    ) {}

    public function handle(WaveClosed $event): void
    {
        $this->closure->sweepWave($event->waveId);
    }
}

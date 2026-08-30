<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Application\Listeners;

use Modules\Logistics\Distribution\Domain\Services\DailyGroupLifecycleService;
use Modules\Operations\Preparation\Domain\Events\WaveClosed;

/**
 * TASK-DISTRIBUTION-WAVE-LIFECYCLE-TRIGGERS-003 — the canonical Wave-close trigger.
 *
 * The Wave lifecycle owns closure, so Distribution reacts to `WaveClosed` rather than
 * offering an operator a "close group" button. A manual-only closure would mean a Wave
 * could end while its Groups stayed operational, which is precisely the previous-Wave
 * contamination this whole lifecycle exists to prevent.
 *
 * WHAT THIS DOES NOT DO. It does not delete a Group, rewrite Window history, or touch a
 * Trip, Driver, Vehicle or Loading record. `closeWave()` stamps the closure and releases
 * the unfinished orders from their Group; everything else about them is left exactly as
 * the Wave left it.
 *
 * IDEMPOTENT. `closeWave()` skips Groups that already carry a `closed_at`, so a replayed
 * or duplicated event cannot restamp a closure time or re-release orders that have since
 * been picked up by the next Wave.
 */
final class CloseWaveDistributionGroupsListener
{
    public function __construct(
        private readonly DailyGroupLifecycleService $lifecycle,
    ) {}

    public function handle(WaveClosed $event): void
    {
        $this->lifecycle->closeWave($event->waveId);
    }
}

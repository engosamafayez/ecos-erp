<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Application\Listeners;

use Modules\Logistics\Distribution\Domain\Services\WaveClosureCustodyService;
use Modules\Operations\Preparation\Domain\Events\WaveClosed;

/**
 * TASK-ECOS-OPERATIONS-DISTRIBUTION-AND-LOADING-FINAL-022 §E/§F — the Wave-close
 * reaction `CloseWaveDistributionGroupsListener`'s own docblock explicitly says it
 * does NOT do: "it does not... touch a Trip, Driver, Vehicle or Loading record."
 * That gap is real — Wave/Group closure alone left every in-flight Trip and its
 * Loading execution running exactly as before, so a Draft/in-progress loading
 * execution could survive a closed Wave indefinitely (§E), and a Trip "waiting on
 * driver" had no terminal outcome when the day ended (§F).
 *
 * A SEPARATE listener, not folded into `CloseWaveDistributionGroupsListener`: that
 * class's docblock is a real, deliberate architectural boundary (Group-closure vs.
 * Trip/Loading custody are different concerns), so this stays its own reaction to
 * the same event rather than blurring that line.
 *
 * Registered directly in `LogisticsDistributionServiceProvider::boot()`, not via
 * the `LISTENERS` map — `WaveClosed` already has one entry there and the map holds
 * exactly one value per event key. See the provider for the full note.
 */
final class CloseWaveLoadingCustodyListener
{
    public function __construct(
        private readonly WaveClosureCustodyService $custody,
    ) {}

    public function handle(WaveClosed $event): void
    {
        $this->custody->sweepWave($event->waveId);
    }
}

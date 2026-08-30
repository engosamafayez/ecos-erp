<?php

declare(strict_types=1);

namespace Modules\Operations\Preparation\Infrastructure\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Modules\Operations\Preparation\Application\Services\WaveEngine\CompanyTimezoneResolver;
use Modules\Operations\Preparation\Application\Services\WaveEngine\WaveLifecycleService;
use Modules\Operations\Preparation\Application\Services\WaveEngine\WaveManager;
use Modules\Operations\Preparation\Application\Services\WaveEngine\WaveMembershipService;
use Modules\Operations\Preparation\Application\Services\WaveEngine\WavePreparationService;
use Modules\Operations\Preparation\Application\Services\WaveEngine\WaveScheduleResolver;
use Modules\Operations\Preparation\Domain\Enums\WaveStatus;
use Modules\Operations\Preparation\Domain\Models\PreparationWave;
use Modules\Operations\Preparation\Domain\Models\WaveEngineConfiguration;
use Throwable;

/**
 * Drives the Preparation Wave operational cycle — TASK-…-CROSS-DAY-TRANSITION-002.
 *
 * A wave is an OPERATIONAL CYCLE, not a calendar day. It may open on one date and end on
 * the next (18:00 → 08:00 → 15:00), and between the end of one cycle and the start of the
 * next there is a deliberate gap with no intake at all.
 *
 * Each tick, per warehouse:
 *
 *   RECONCILE  every open wave OF ANY DATE against its own stored boundaries
 *              ends_at reached          → Closed  (this is the stale-wave sweep, G-3)
 *              intake_closes_at reached → Preparing (intake cutoff; preparation goes on)
 *   OPEN       no wave open and a cycle is currently running → create exactly one
 *   COLLECT    the open wave, only while it is Collecting → attach eligible orders
 *
 * The ordering matters. Reconcile runs FIRST so that a cycle which ended overnight is
 * closed before the current one is opened — otherwise the stale wave would still count as
 * "a wave is open" and block creation for as long as it survived, which is precisely the
 * deadlock the previous implementation suffered.
 *
 * WHAT CHANGED, AND WHY: every step used to be scoped to `planning_date = today`, and the
 * command returned early when today had no wave. A wave left behind by a missed tick, a
 * restart, or a late manual start therefore became permanently unreachable — it could
 * never be advanced and never be closed. Reconciliation is now driven by the wave's own
 * `ends_at` / `intake_closes_at`, so no wave can fall out of scope by ageing.
 */
final class RunWaveSchedulerCommand extends Command
{
    protected $signature = 'wave:run-scheduler';

    protected $description = 'Drive preparation wave operational cycles: reconcile open waves (intake cutoff, end), open the current cycle, collect eligible orders.';

    public function __construct(
        private readonly WaveManager $waveManager,
        private readonly WaveLifecycleService $lifecycle,
        private readonly WaveMembershipService $membership,
        private readonly WavePreparationService $preparation,
        private readonly WaveScheduleResolver $schedule,
        private readonly CompanyTimezoneResolver $timezones,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $configs = WaveEngineConfiguration::where('is_active', true)->get();

        if ($configs->isEmpty()) {
            $this->line('No active wave engine configurations found.');

            return Command::SUCCESS;
        }

        $now = CarbonImmutable::now();

        foreach ($configs as $config) {
            try {
                $this->processWarehouse($config, $now);
            } catch (Throwable $e) {
                // Log and continue — one bad warehouse must not block others
                $this->error(
                    "Wave scheduler error for warehouse {$config->warehouse_id}: {$e->getMessage()}",
                );
                report($e);
            }
        }

        return Command::SUCCESS;
    }

    private function processWarehouse(WaveEngineConfiguration $config, CarbonImmutable $now): void
    {
        // G-2 — companies.timezone is the authority. Fail closed: a company with no
        // usable timezone is skipped loudly rather than silently scheduled against UTC,
        // which is how the operational day drifted two to three hours in the first place.
        $timezone = $this->timezones->resolve($config->company_id);

        if ($timezone === null) {
            $this->warn(
                "  [Wave Engine] Skipped company {$config->company_id}: no valid companies.timezone. ".
                'Set it before the wave engine can resolve an operational cycle.',
            );

            return;
        }

        $this->reconcileOpenWaves($config, $now);

        // Re-read AFTER reconciliation: a cycle that ended overnight has just been closed,
        // so it must no longer count as an open wave when deciding whether to open today's.
        $open = $this->waveManager->openWaves($config->company_id, $config->warehouse_id);

        $cycle = $this->schedule->resolveCycleAt($config, $timezone, $now);

        // ── Open the current cycle ───────────────────────────────────────────────
        //
        // $cycle === null means now sits in the gap between cycles: the previous one has
        // ended and the next has not started. No wave is created, so nothing is collected
        // and orders simply wait — the intended quiet window.
        if ($config->auto_create && $cycle !== null && $open->isEmpty()) {
            $wave = $this->lifecycle->createCollectingWave(
                $config->company_id,
                $config->warehouse_id,
                $cycle->planningDate,
                'system',
                $cycle,
            );

            $open = collect([$wave]);

            $this->line(
                "  [Wave Engine] Opened wave {$wave->wave_number} for warehouse {$config->warehouse_id} ".
                "({$cycle->startsAt->toIso8601String()} → intake closes {$cycle->intakeClosesAt->toIso8601String()} → ends {$cycle->endsAt->toIso8601String()})",
            );
        }

        // ── Collect ──────────────────────────────────────────────────────────────
        //
        // The newest open cycle is the current one; intake is open only while it is
        // Collecting.
        $openWave = $open->last();

        if ($openWave === null) {
            return;
        }

        if ($config->auto_assign_orders && $openWave->status === WaveStatus::Collecting) {
            $count = $this->membership->attachEligibleOrders($openWave, $config);

            if ($count > 0) {
                $this->line("  [Wave Engine] Attached {$count} order(s) to wave {$openWave->wave_number}");
            }
        }
    }

    /**
     * Advance or close every open wave against its OWN boundaries — regardless of date.
     *
     * End is evaluated before cutoff: a wave discovered long after both boundaries passed
     * (a stale wave, the case this sweep exists for) must close, not first transition to
     * Preparing and wait another tick to close.
     */
    private function reconcileOpenWaves(WaveEngineConfiguration $config, CarbonImmutable $now): void
    {
        foreach ($this->waveManager->openWaves($config->company_id, $config->warehouse_id) as $wave) {
            if ($wave->hasReachedEnd($now)) {
                $this->closeEndedWave($wave);

                continue;
            }

            if (
                $wave->status === WaveStatus::Collecting
                && $config->auto_move_to_preparing
                && $wave->hasReachedIntakeCutoff($now)
            ) {
                $this->preparation->startPreparation($wave);
                $this->line(
                    "  [Wave Engine] Intake closed for wave {$wave->wave_number} — preparation continues",
                );
            }
        }
    }

    /**
     * Close a wave whose cycle has ended.
     *
     * Deliberately closeWave() and NOT rotateWave(): opening the next cycle is the start
     * boundary's job, not closure's. Rotating here would re-open intake the instant the
     * previous cycle ended and erase the gap between cycles.
     *
     * Closure releases every membership and publishes WaveClosed, which is what triggers
     * the order-side carry-over decision (HandlePreparationWaveClosed).
     */
    private function closeEndedWave(PreparationWave $wave): void
    {
        $this->lifecycle->closeWave($wave, 'system', 'ends_at_reached');

        $this->line(
            "  [Wave Engine] Ended wave {$wave->wave_number} (ends_at {$wave->ends_at?->toIso8601String()})",
        );
    }
}

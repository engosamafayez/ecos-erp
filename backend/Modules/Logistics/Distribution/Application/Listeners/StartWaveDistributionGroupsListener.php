<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Application\Listeners;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\Logistics\Distribution\Domain\Services\DailyGroupLifecycleService;
use Modules\Logistics\Distribution\Domain\Services\DistributionWindowService;
use Modules\Operations\Preparation\Domain\Events\WavePreparationStarted;
use Modules\Operations\Preparation\Domain\Events\WaveStarted;

/**
 * TASK-DISTRIBUTION-WAVE-LIFECYCLE-TRIGGERS-003 — the canonical Wave-start trigger.
 *
 * ┌─ WHY AN EVENT AND NOT A SCHEDULER ───────────────────────────────────────┐
 * │ Preparation already owns the Wave lifecycle and already announces the      │
 * │ moment a Wave starts. Subscribing to that is the difference between        │
 * │ "Distribution reacts when the Wave starts" and "Distribution polls and     │
 * │ guesses which Wave is active" — and guessing the active Wave is one of     │
 * │ this task's STOP conditions.                                              │
 * │                                                                          │
 * │ It also means no second lifecycle owner exists: Preparation decides WHEN,  │
 * │ Distribution decides WHAT that means for its own rows.                    │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌─ WHY TWO EVENTS FUNNEL INTO ONE SWEEP ───────────────────────────────────┐
 * │ Preparation starts a Wave along two paths that both mean "this Wave is now │
 * │ operational":                                                             │
 * │                                                                          │
 * │   WaveStarted            — the MANUAL StartPreparationAction (Planning ->  │
 * │                            Preparing, with PickList creation).             │
 * │   WavePreparationStarted — the AUTOMATED Wave Engine transition            │
 * │                            (Collecting -> Preparing at intake cutoff).     │
 * │                                                                          │
 * │ Distribution's reaction is IDENTICAL for both — ensure the Wave's Groups   │
 * │ exist — so both handlers call ONE private sweep (TASK-FINAL-SYNC §GAP-1).  │
 * │ Before this the automated path had no subscriber, so an engine-started     │
 * │ Wave reached the workspace with no Groups until a manual Refresh ran.      │
 * │                                                                          │
 * │ Both events carry the same four fields the sweep needs (companyId, waveId, │
 * │ warehouseId, planningDate); nothing else about either event is read here.  │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * Registered in this module's own provider, following the direction the module already
 * uses for `OrderGeographyChanged`: the subscriber wires itself, and Preparation stays
 * unaware that Distribution exists.
 *
 * IDEMPOTENT BY CONSTRUCTION. The sweep finds each Template's existing Group and reuses
 * it, and the (wave, template) unique index is the backstop. Starting the same Wave twice
 * — or a manual start followed by the engine transition on the same Wave — creates
 * nothing twice.
 */
final class StartWaveDistributionGroupsListener
{
    public function __construct(
        private readonly DailyGroupLifecycleService $lifecycle,
        private readonly DistributionWindowService $windows,
    ) {}

    /** MANUAL start — Planning -> Preparing via StartPreparationAction. */
    public function handle(WaveStarted $event): void
    {
        $this->sweepForWave(
            $event->companyId,
            $event->waveId,
            $event->warehouseId,
            $event->planningDate,
        );
    }

    /** AUTOMATED start — Collecting -> Preparing via the Wave Engine. */
    public function handlePreparationStarted(WavePreparationStarted $event): void
    {
        $this->sweepForWave(
            $event->companyId,
            $event->waveId,
            $event->warehouseId,
            $event->planningDate,
        );
    }

    /**
     * The one reaction both wave-start paths share: ensure this Wave's Groups exist.
     *
     * @param  string  $planningDate  the Wave's OWN planning date (Y-m-d), never "today":
     *                                a Wave started late, replayed, or spanning midnight
     *                                must plan into the day it belongs to.
     */
    private function sweepForWave(
        string $companyId,
        string $waveId,
        string $warehouseId,
        string $planningDate,
    ): void {
        $window = $this->windows->resolveOrCreatePlanningWindow(
            $companyId,
            $waveId,
            $warehouseId,
            Carbon::parse($planningDate)->toImmutable(),
        );

        $result = $this->lifecycle->sweepWave(
            $window,
            $waveId,
            $companyId,
            $warehouseId,
        );

        /*
         * THE SWEEP MUST NOT BE SILENT — TASK-...-CLOSURE-FIX-001 §2.
         *
         * `sweepWave()` has always returned its own tally; this caller discarded it. A
         * sweep that creates nothing then looks identical to a sweep that never ran, and
         * telling those two apart is what turned the wave-009 question into a database
         * forensic. One structured line at INFO closes that gap.
         *
         * `uncovered_zones` is the field that matters: eligible work in a Zone NO active
         * Template covers is unreachable by construction, and it is the difference
         * between "nothing to do" and "misconfigured". Counts and ids only — no order,
         * customer, address or quantity data.
         */
        Log::info('distribution.wave_sweep', [
            'wave_id' => $waveId,
            'window_id' => (string) $window->id,
            'window_date' => $window->window_date instanceof DateTimeInterface
                ? $window->window_date->format('Y-m-d')
                : (string) $window->window_date,
            'created' => $result['created'],
            'reused' => $result['reused'],
            'skipped' => $result['skipped'],
            'skipped_templates' => $result['skipped_templates'] ?? [],
            'uncovered_zones' => $result['uncovered_zones'] ?? [],
            // Groups created for a scope another Group already owns: they exist but can
            // receive no Orders. Reported, not refused — see the service constant.
            'zone_conflicts' => $result['zone_conflicts'] ?? [],
        ]);
    }
}

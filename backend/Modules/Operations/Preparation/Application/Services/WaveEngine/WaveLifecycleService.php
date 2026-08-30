<?php

declare(strict_types=1);

namespace Modules\Operations\Preparation\Application\Services\WaveEngine;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Operations\Preparation\Application\DTOs\WaveCycle;
use Modules\Operations\Preparation\Domain\Enums\WaveStatus;
use Modules\Operations\Preparation\Domain\Events\WaveClosed;
use Modules\Operations\Preparation\Domain\Events\WaveCreated;
use Modules\Operations\Preparation\Domain\Events\WaveRotated;
use Modules\Operations\Preparation\Domain\Models\PreparationWave;
use Modules\Operations\Preparation\Domain\Models\PreparationWaveOrder;

final class WaveLifecycleService
{
    public function __construct(
        private readonly WaveManager $waveManager,
        private readonly DemandRefreshDispatcher $demandDispatcher,
    ) {}

    /**
     * Idempotent: returns existing Collecting/Preparing wave if one exists for this date+warehouse.
     *
     * $cycle carries the resolved operational boundaries (PART 2). When supplied — which
     * the scheduler always does — they are persisted on the wave, and from that moment
     * the wave's lifecycle is driven by those stored instants rather than by re-reading
     * configuration. Passing null keeps the pre-existing behaviour for manually created
     * waves: no boundaries, therefore never auto-advanced and never auto-closed.
     *
     * The `lockForUpdate` + existing-wave check is the concurrency guard (PART 6): two
     * scheduler ticks racing on the same start boundary serialise here, and the loser
     * returns the winner's wave instead of creating a second one.
     */
    public function createCollectingWave(
        string $companyId,
        string $warehouseId,
        string $planningDate,
        string $actorId = 'system',
        ?WaveCycle $cycle = null,
    ): PreparationWave {
        return DB::transaction(function () use ($companyId, $warehouseId, $planningDate, $actorId, $cycle): PreparationWave {
            $existing = PreparationWave::where('company_id', $companyId)
                ->where('warehouse_id', $warehouseId)
                ->where('planning_date', $planningDate)
                ->whereIn('status', [WaveStatus::Collecting->value, WaveStatus::Preparing->value])
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $waveNumber = $this->generateWaveNumber($companyId, $planningDate);

            $wave = PreparationWave::create([
                'company_id' => $companyId,
                'warehouse_id' => $warehouseId,
                'wave_number' => $waveNumber,
                'planning_date' => $planningDate,
                'starts_at' => $cycle?->startsAt,
                'intake_closes_at' => $cycle?->intakeClosesAt,
                'ends_at' => $cycle?->endsAt,
                'status' => WaveStatus::Collecting->value,
                'wave_type' => 'engine',
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);

            event(new WaveCreated(
                waveId: $wave->id,
                waveNumber: $waveNumber,
                companyId: $companyId,
                warehouseId: $warehouseId,
                planningDate: $planningDate,
                ordersCount: 0,
                orderIds: [],
                createdBy: $actorId,
                configVersionId: '',
            ));

            return $wave;
        });
    }

    /**
     * Transition wave to Closed status. Idempotent: returns as-is if already Closed.
     */
    public function closeWave(
        PreparationWave $wave,
        string $actorId = 'system',
        string $reason = 'scheduled',
    ): PreparationWave {
        return DB::transaction(function () use ($wave, $actorId, $reason): PreparationWave {
            $fresh = PreparationWave::where('id', $wave->id)->lockForUpdate()->first();

            if ($fresh === null) {
                return $wave;
            }

            if ($fresh->status === WaveStatus::Closed) {
                return $fresh;
            }

            if ($fresh->status->isTerminal()) {
                return $fresh;
            }

            $now = now();

            $fresh->update([
                'status' => WaveStatus::Closed->value,
                'completed_at' => $now,
                'completed_by' => $actorId,
                'updated_by' => $actorId,
            ]);

            // The cycle is over, so every membership it held is over — PART 17.
            //
            // The ROW is kept; only `released_at` is stamped (PART 14: history is never
            // deleted). Releasing here, in the same transaction as the status change, is
            // what frees each order to join a later wave without ever permitting two
            // simultaneous active memberships: the unique index over
            // (company_id, order_id, active_membership) can only admit the next one once
            // this one is released.
            //
            // Released uniformly, including postponed members: postponement withdrew an
            // order from THIS cycle's work, and this cycle has now ended for everyone.
            // Which orders are returned to In Progress is a separate decision, taken by
            // HandlePreparationWaveClosed against the G-1 completion fact — release is
            // about membership, not about order status.
            PreparationWaveOrder::where('preparation_wave_id', $fresh->id)
                ->whereNull('released_at')
                ->update(['released_at' => $now]);

            event(new WaveClosed(
                waveId: $fresh->id,
                waveNumber: $fresh->wave_number,
                companyId: $fresh->company_id,
                warehouseId: $fresh->warehouse_id,
                planningDate: $fresh->planning_date->toDateString(),
                closedBy: $actorId,
                closedAt: $now->toIso8601String(),
                reason: $reason,
            ));

            return $fresh->refresh();
        });
    }

    /**
     * Close the current wave and create the next Collecting wave for the following day.
     *
     * NO LONGER USED BY THE SCHEDULER (TASK-…-CROSS-DAY-TRANSITION-002). Closing and
     * opening are now independent: a wave closes when its own `ends_at` passes, and the
     * next wave opens when the next `collection_start_time` arrives. Coupling them made
     * the gap between cycles unexpressible — rotation created the successor immediately,
     * so intake reopened the instant the previous cycle ended, and the deliberate
     * 15:00 → 18:00 quiet window of PART 26 could not exist.
     *
     * Retained because it is a correct, tested operation in its own right and is still
     * the right primitive for a manual "close and roll forward" action.
     */
    public function rotateWave(
        PreparationWave $wave,
        string $actorId = 'system',
    ): PreparationWave {
        $this->closeWave($wave, $actorId, 'rotation');

        // Rotation targets the CURRENT operational cycle, never a historical one.
        //
        // planning_date + 1 is correct in the normal case (a wave closing at its end
        // time rolls into tomorrow), but a wave stranded in the past would otherwise
        // rotate one day at a time — 30 Jul → 31 Jul → 1 Aug … — manufacturing a
        // chain of dead waves and taking as many scheduler ticks as days elapsed to
        // reach the present. Clamping to today collapses that to a single rotation
        // and lands the engine on the operational date that actually matters.
        $nextDate = Carbon::parse($wave->planning_date)->addDay();
        $today = Carbon::now()->startOfDay();

        if ($nextDate->lessThan($today)) {
            $nextDate = $today;
        }

        $newWave = $this->createCollectingWave(
            $wave->company_id,
            $wave->warehouse_id,
            $nextDate->toDateString(),
            $actorId,
        );

        event(new WaveRotated(
            closedWaveId: $wave->id,
            newWaveId: $newWave->id,
            newWaveNumber: $newWave->wave_number,
            companyId: $wave->company_id,
            warehouseId: $wave->warehouse_id,
            rotatedBy: $actorId,
            rotatedAt: now()->toIso8601String(),
        ));

        return $newWave;
    }

    /**
     * Same sequential number scheme as CreateWaveAction (PREP-YYYYMM-000001).
     */
    private function generateWaveNumber(string $companyId, string $planningDate): string
    {
        $yearMonth = Carbon::parse($planningDate)->format('Ym');

        $last = PreparationWave::where('company_id', $companyId)
            ->where('wave_number', 'like', "PREP-{$yearMonth}-%")
            ->max('wave_number');

        $seq = $last === null ? 1 : ((int) Str::afterLast($last, '-')) + 1;

        return sprintf('PREP-%s-%06d', $yearMonth, $seq);
    }
}

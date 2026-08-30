<?php

declare(strict_types=1);

namespace Modules\Operations\Preparation\Application\Services\WaveEngine;

use Modules\Operations\Preparation\Domain\Enums\WaveStatus;
use Modules\Operations\Preparation\Domain\Models\PreparationWave;

final class WaveManager
{
    /** @var list<string> */
    private const ACTIVE_STATUSES = [
        WaveStatus::Collecting->value,
        WaveStatus::Preparing->value,
    ];

    /**
     * The active wave for an OPERATIONAL DATE.
     *
     * `$operationalDate` is what makes this the *current* wave rather than merely
     * *an* active one. Without it, a wave left Collecting on some past date is
     * indistinguishable from today's — and because the scheduler refuses to create
     * a wave while one is "active", a single stale row deadlocked the engine
     * permanently: no new wave could open, and orders that did get collected were
     * attached to a wave dated days earlier.
     *
     * Omitting the date keeps the legacy any-date behaviour for read-side callers,
     * but now with a deterministic order — newest planning_date first — instead of
     * whichever row the storage engine happened to return.
     */
    public function getActiveWave(
        string $companyId,
        string $warehouseId,
        ?string $operationalDate = null,
    ): ?PreparationWave {
        return PreparationWave::where('company_id', $companyId)
            ->where('warehouse_id', $warehouseId)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->when($operationalDate !== null, fn ($q) => $q->where('planning_date', $operationalDate))
            ->orderByDesc('planning_date')
            ->first();
    }

    public function getActiveWaveForDate(string $companyId, string $warehouseId, string $date): ?PreparationWave
    {
        return PreparationWave::where('company_id', $companyId)
            ->where('warehouse_id', $warehouseId)
            ->where('planning_date', $date)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->first();
    }

    /**
     * Does an active wave exist for this operational date?
     *
     * Pass the operational date on every scheduling path. A date-less check is what
     * let a stale Collecting wave stand in for today's and block wave creation.
     */
    public function hasActiveWave(
        string $companyId,
        string $warehouseId,
        ?string $operationalDate = null,
    ): bool {
        return PreparationWave::where('company_id', $companyId)
            ->where('warehouse_id', $warehouseId)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->when($operationalDate !== null, fn ($q) => $q->where('planning_date', $operationalDate))
            ->exists();
    }

    /**
     * Every ENGINE wave still open for this warehouse, OF ANY DATE — the scheduler's set.
     *
     * Not date-scoped, and that is the entire point (G-3). The scheduler used to reach
     * waves only through `getActiveWave(…, today)`, so a wave whose planning_date had
     * rolled past — a missed tick, a restart, a wave started late by hand — fell
     * permanently out of scope and could never be advanced or closed. Three of the five
     * live waves were stranded that way.
     *
     * Scoped to `wave_type = 'engine'` because the scheduler owns only the waves it
     * creates. A manually built wave follows the operator's own Draft → Planning →
     * Preparing → Completed path and has no resolved boundaries; without this scope a
     * manual wave left in Preparing would read as "a wave is already open" and block the
     * operational cycle from ever opening again — the same class of deadlock this method
     * exists to end, arriving by a different door.
     *
     * Oldest first: if more than one is somehow open, the eldest is reconciled first, so
     * the survivor is the most recent cycle rather than an arbitrary one.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, PreparationWave>
     */
    public function openWaves(string $companyId, string $warehouseId): \Illuminate\Database\Eloquent\Collection
    {
        return PreparationWave::where('company_id', $companyId)
            ->where('warehouse_id', $warehouseId)
            ->where('wave_type', 'engine')
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->orderBy('planning_date')
            ->get();
    }

    public function getCollectingWave(string $companyId, string $warehouseId): ?PreparationWave
    {
        return PreparationWave::where('company_id', $companyId)
            ->where('warehouse_id', $warehouseId)
            ->where('status', WaveStatus::Collecting->value)
            ->first();
    }

    public function getPreparingWave(string $companyId, string $warehouseId): ?PreparationWave
    {
        return PreparationWave::where('company_id', $companyId)
            ->where('warehouse_id', $warehouseId)
            ->where('status', WaveStatus::Preparing->value)
            ->first();
    }
}

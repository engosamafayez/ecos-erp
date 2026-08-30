<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Domain\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Logistics\Distribution\Domain\Enums\DistributionWindowStatus;
use Modules\Logistics\Distribution\Domain\Models\DistributionWindow;

/**
 * Resolves which Distribution Window an Order belongs to, and moves Windows
 * through their lifecycle.
 *
 * The single rule this class exists to enforce: BEFORE cutoff an eligible Order
 * joins today's Window; AFTER cutoff it joins the next one. Everything else here
 * is bookkeeping in service of making that question answerable at any instant.
 *
 * Window times come from configuration and are COPIED onto the row at creation.
 * Reading them from config at query time would mean a later configuration change
 * silently reinterpreted Windows that had already run.
 */
final class DistributionWindowService
{
    /**
     * The Window a newly eligible Order should join right now.
     *
     * Open Window → that Window. Past cutoff (or nothing open) → the next one.
     * This is the only method automatic collection needs, and it never returns a
     * Window that is closed to ingestion.
     */
    public function resolveIngestionWindow(string $companyId, CarbonImmutable $now): DistributionWindow
    {
        $today = $this->windowFor($companyId, $now->toDateString(), $now);

        if ($today->isAcceptingAutomaticIngestion($now)) {
            return $today;
        }

        // Past cutoff — the Order belongs to the next Window (§16). Not an error
        // and not a rejection: the Order is simply scheduled for tomorrow.
        return $this->nextWindowAfter($today, $now);
    }

    /**
     * Today's Window for this company, whatever state it is in.
     *
     * Returns null rather than creating one, for read paths that must not have
     * side effects.
     */
    public function currentWindow(string $companyId, CarbonImmutable $now): ?DistributionWindow
    {
        return DistributionWindow::query()
            ->where('company_id', $companyId)
            ->whereDate('window_date', $now->toDateString())
            ->first();
    }

    /**
     * The Window the workspace is currently PLANNING — D1-A.
     *
     * ┌─ WHY THIS IS NOT `windowFor(today)` ─────────────────────────────────────┐
     * │ A Distribution Window is an INGESTION-DAY container: `attach()` stamps an │
     * │ Order into whichever Window was open when it first became eligible, and   │
     * │ `dist_window_orders_order_unique` then pins it there permanently. Groups  │
     * │ (`distribution_virtual_slots`) and Zone attachments                       │
     * │ (`distribution_slot_zones`) are scoped to that same Window.               │
     * │                                                                          │
     * │ An operational cycle is NOT a calendar day. A Preparation Wave spans      │
     * │ midnight, so by the second day of a cycle `windowFor(today)` resolves a    │
     * │ Window that holds none of the cycle's Orders, none of its Zones and none  │
     * │ of its Groups — and the workspace reads 0 / 0 / 0 while the warehouse is  │
     * │ working. Re-running collection cannot repair it: every Order already      │
     * │ holds an assignment, so `attach()` returns null for all of them.          │
     * │                                                                          │
     * │ `current()` already refuses to date-filter the WAVE lookup for exactly    │
     * │ this reason. This applies the same rule to the WINDOW lookup beside it.   │
     * └──────────────────────────────────────────────────────────────────────────┘
     *
     * READ-SIDE ONLY (owner decision D1-A). Nothing here writes an assignment,
     * moves a row between Windows, clones a Group or touches Group identity. It
     * answers one question — "which Window is this cycle being planned in?" — by
     * OBSERVING where the governing Wave's own members already sit.
     *
     * THE WAVE IS THE AUTHORITY, NOT ITS `planning_date`. Anchoring on the date
     * would resolve a Window that is merely contemporaneous with the wave and
     * would still be empty; anchoring on the wave's MEMBERS resolves the Window
     * that actually holds the plan. Membership is `released_at IS NULL` — the same
     * ACTIVE predicate Preparation uses. Postponed members are deliberately
     * counted: a postponed Order still evidences which Window this cycle lives in,
     * and eligibility filtering stays where it already is, on the reads.
     *
     * DETERMINISTIC. Ordered by assignment count, then by the later
     * `window_date`, so a cycle whose Orders straddle two Windows resolves to one
     * answer rather than one answer per query.
     *
     * FAILS BACK, NEVER FAILS OPEN. No governing wave, or a wave whose members
     * carry no assignment yet, resolves the EXISTING window and never creates one —
     * the H1 Option B behaviour every
     * fresh tenant has today. Tenant scope is asserted on both the assignment and
     * the Window row, so a foreign Window can never be resolved.
     */
    public function resolvePlanningWindow(
        string $companyId,
        ?string $waveId,
        ?string $warehouseId,
        CarbonImmutable $now,
    ): ?DistributionWindow {
        // NO WAVE IS NOT AN ERROR — H1 = Option B.
        //
        // A Preparation Wave SELECTS which window is the current operational cycle. It is
        // not a prerequisite for reading Distribution, and the schema agrees: this table is
        // keyed (company_id, window_date) with no preparation_wave_id and no warehouse_id,
        // and ingestion (resolveIngestionWindow) consults no wave at all.
        //
        // So with no resolvable cycle we still resolve the EXISTING window through the
        // non-creating read. What was actually dangerous was never the fallback — it was
        // that the old fallback ran windowFor(), which CREATES. currentWindow() cannot.
        if ($waveId === null) {
            return $this->currentWindow($companyId, $now);
        }

        $anchor = DB::table('distribution_window_orders as dwo')
            ->join('preparation_wave_orders as pwo', function ($join) use ($waveId): void {
                $join->on('pwo.order_id', '=', 'dwo.order_id')
                    ->where('pwo.preparation_wave_id', '=', $waveId)
                    // ACTIVE membership only — a released row is history.
                    ->whereNull('pwo.released_at');
            })
            ->join('orders as o', 'o.id', '=', 'dwo.order_id')
            ->join('distribution_windows as w', 'w.id', '=', 'dwo.distribution_window_id')
            ->where('dwo.company_id', $companyId)
            // Asserted on the Window too: the assignment's tenant and the Window's
            // tenant must agree, so a mismatched row cannot leak a foreign Window.
            ->where('w.company_id', $companyId)
            // The Order's OWN warehouse, exactly as DistributionAggregationService
            // scopes it. Never inferred from the Zone.
            ->when(
                $warehouseId !== null,
                fn ($q) => $q->where('o.assigned_warehouse_id', $warehouseId),
            )
            ->groupBy('dwo.distribution_window_id')
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->orderByDesc(DB::raw('MAX(w.window_date)'))
            ->value('dwo.distribution_window_id');

        // A cycle whose orders carry no assignment yet has no window to ANCHOR on, but
        // an existing window may still hold real work. Resolve it without creating; the
        // collector is the only path allowed to create, via resolveOrCreatePlanningWindow().
        if ($anchor === null) {
            return $this->currentWindow($companyId, $now);
        }

        $window = DistributionWindow::query()
            ->where('id', (string) $anchor)
            ->where('company_id', $companyId)
            ->first();

        // The anchor named a window this tenant cannot see. Fall back to the tenant's own
        // existing window rather than inventing one.
        if ($window === null) {
            return $this->currentWindow($companyId, $now);
        }

        return $this->syncStatusToClock($window, $now);
    }

    /**
     * The planning Window for a cycle, CREATED if this cycle has not opened one yet.
     *
     * ┌─ WHY THIS IS A SEPARATE METHOD ──────────────────────────────────────────┐
     * │ resolvePlanningWindow() is a READ and NEVER creates: it resolves an        │
     * │ existing window or returns null, because a reader that invents one renders  │
     * │ an empty board that looks authoritative (TASK-1-A §1, H1 Option B).         │
     * │                                                                          │
     * │ Automatic collection has the opposite need. On the FIRST sweep of a new    │
     * │ cycle no order carries an assignment yet, so there is no anchor to find —  │
     * │ and collection must still have somewhere to write. That is a legitimate    │
     * │ create, so it is spelled out at the call site that means it instead of     │
     * │ being hidden inside a read.                                               │
     * │                                                                          │
     * │ Both paths resolve the SAME window for the same cycle. This method adds    │
     * │ creation, never a different resolution rule, so the reader and the writer  │
     * │ can never disagree about which window a cycle is planning.                 │
     * └──────────────────────────────────────────────────────────────────────────┘
     */
    public function resolveOrCreatePlanningWindow(
        string $companyId,
        ?string $waveId,
        ?string $warehouseId,
        CarbonImmutable $now,
    ): DistributionWindow {
        $resolved = $this->resolvePlanningWindow($companyId, $waveId, $warehouseId, $now);

        if ($resolved !== null) {
            return $resolved;
        }

        return $this->windowFor($companyId, $now->toDateString(), $now);
    }

    /**
     * Get or create the Window for a given date.
     *
     * Uses firstOrCreate under a transaction plus the (company, date) unique
     * index, so two concurrent collectors converge on one Window rather than
     * racing to create two.
     */
    public function windowFor(string $companyId, string $date, CarbonImmutable $now): DistributionWindow
    {
        $existing = DistributionWindow::query()
            ->where('company_id', $companyId)
            ->whereDate('window_date', $date)
            ->first();

        if ($existing !== null) {
            return $this->syncStatusToClock($existing, $now);
        }

        $window = DB::transaction(function () use ($companyId, $date, $now): DistributionWindow {
            $opens = $this->timeOnDate($date, (string) config('distribution.window.opens_at', '00:00'));
            $closes = $this->timeOnDate($date, (string) config('distribution.window.closes_at', '23:59'));

            // A closes_at at or before opens_at would make the Window
            // instantaneously shut; treat it as spanning to end of day instead of
            // silently ingesting nothing.
            if ($closes <= $opens) {
                $closes = $opens->endOfDay();
            }

            return DistributionWindow::query()->firstOrCreate(
                ['company_id' => $companyId, 'window_date' => $date],
                [
                    'opens_at' => $opens,
                    'closes_at' => $closes,
                    'status' => $this->statusForClock($opens, $closes, $now)->value,
                ],
            );
        });

        return $this->syncStatusToClock($window, $now);
    }

    /**
     * The Window that follows this one — created on demand when configured to.
     *
     * Late Orders need somewhere to land; if the successor did not exist they
     * would have no destination and would be dropped, which the contract forbids.
     */
    public function nextWindowAfter(DistributionWindow $window, CarbonImmutable $now): DistributionWindow
    {
        if ($window->next_window_id !== null) {
            $linked = DistributionWindow::query()->find($window->next_window_id);

            if ($linked !== null) {
                return $this->syncStatusToClock($linked, $now);
            }
        }

        $nextDate = CarbonImmutable::parse($window->window_date->toDateString())->addDay()->toDateString();
        $next = $this->windowFor($window->company_id, $nextDate, $now);

        if ($window->next_window_id !== $next->id) {
            $window->forceFill(['next_window_id' => $next->id])->save();
        }

        return $next;
    }

    /**
     * Move a Window to CutoffReached when the clock has passed closes_at.
     *
     * Deliberately does NOT close the Window: cutoff stops automatic ingestion
     * and nothing else. Manual assignment stays open (§15).
     */
    public function applyCutoffIfDue(DistributionWindow $window, CarbonImmutable $now): DistributionWindow
    {
        if ($window->status !== DistributionWindowStatus::Open) {
            return $window;
        }

        if (! $window->isPastCutoff($now)) {
            return $window;
        }

        $window->forceFill([
            'status' => DistributionWindowStatus::CutoffReached->value,
            'cutoff_reached_at' => $now,
        ])->save();

        return $window;
    }

    /**
     * Bring a Window's stored status in line with the clock.
     *
     * Scheduled→Open and Open→CutoffReached are time-driven, so a Window read
     * long after it was written would otherwise report a stale state. Closed is
     * never revisited: it is set deliberately downstream and outranks the clock.
     */
    private function syncStatusToClock(DistributionWindow $window, CarbonImmutable $now): DistributionWindow
    {
        if ($window->status === DistributionWindowStatus::Closed) {
            return $window;
        }

        $expected = $this->statusForClock(
            CarbonImmutable::parse($window->opens_at),
            CarbonImmutable::parse($window->closes_at),
            $now,
        );

        if ($expected === $window->status) {
            return $window;
        }

        $patch = ['status' => $expected->value];

        if ($expected === DistributionWindowStatus::CutoffReached && $window->cutoff_reached_at === null) {
            $patch['cutoff_reached_at'] = $now;
        }

        $window->forceFill($patch)->save();

        return $window;
    }

    private function statusForClock(
        CarbonImmutable $opens,
        CarbonImmutable $closes,
        CarbonImmutable $now,
    ): DistributionWindowStatus {
        if ($now < $opens) {
            return DistributionWindowStatus::Scheduled;
        }

        if ($now >= $closes) {
            return DistributionWindowStatus::CutoffReached;
        }

        return DistributionWindowStatus::Open;
    }

    /** Combine a Y-m-d date with an H:i wall-clock time in the app timezone. */
    private function timeOnDate(string $date, string $time): CarbonImmutable
    {
        [$hour, $minute] = array_pad(array_map('intval', explode(':', $time, 2)), 2, 0);

        return CarbonImmutable::parse($date)->setTime($hour, $minute);
    }
}

<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Domain\Services;

use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Modules\Logistics\Distribution\Domain\Exceptions\DistributionException;
use Modules\Logistics\Distribution\Domain\Models\DistributionGroupTemplate;
use Modules\Logistics\Distribution\Domain\Models\DistributionWindow;
use Modules\Logistics\Distribution\Domain\Models\VirtualCapacitySlot;

/**
 * TASK-DISTRIBUTION-DAILY-GROUP-WAVE-LIFECYCLE-002
 *
 * The Group lifecycle across Preparation Waves: find-or-create for the current Wave,
 * and closure when that Wave ends.
 *
 * ┌─ THIS IS NOT A SECOND CREATION ENGINE ───────────────────────────────────┐
 * │ Creation is still `GroupTemplateService::applyToNewGroup()` — the same     │
 * │ call the operator's Apply button makes. What this class adds is the        │
 * │ DECISION of whether a Group is needed at all, and the closure that ends    │
 * │ one. It owns no zone logic, no capacity logic and no assignment logic.    │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * THE INVARIANT IS THE DATABASE'S. `dist_slot_wave_template_unique` on
 * (preparation_wave_id, distribution_group_template_id) means two concurrent callers
 * cannot both create the Group for one Template in one Wave — the second insert fails
 * rather than racing past a read. MySQL treats NULLs as distinct there, so
 * operator-created Groups (no Template) stay unconstrained, which is what they need.
 */
final class DailyGroupLifecycleService
{
    /**
     * The one reason the existing sweep logic can decline a Template: no eligible work
     * in any Zone it covers. Named so the log and any future reader agree on it.
     */
    public const SKIP_NO_ELIGIBLE_WORK = 'no_eligible_work_in_template_zones';

    /**
     * REPORTED, NOT ENFORCED. Every Zone this Template covers is already held by another
     * Group in this window+warehouse, so creating a Group for it TAKES those Zones over:
     * `assignZoneToSlot()` re-points the existing (window, warehouse, zone) row, leaving
     * the previous holder with none.
     *
     * At a Wave rotation that is exactly right — the new Wave's Group should own the Zone.
     * Against an OPERATOR-created Group it silently empties an operator's work, and the
     * two are indistinguishable from Zones alone.
     *
     * Deliberately a DIAGNOSTIC and not a refusal: refusing breaks
     * `test_three_waves_on_one_day_each_get_their_own_group`, because "already owned" is
     * the normal state at every rotation. Telling them apart needs an ownership rule this
     * codebase does not have — see the report.
     */
    public const ZONES_ALREADY_OWNED = 'all_template_zones_owned_by_another_group';

    /** Why a Group stopped being operational. Stored on `closed_reason`. */
    public const CLOSED_WAVE_ENDED = 'wave_ended';

    public function __construct(
        private readonly GroupTemplateService $templates,
        private readonly DistributionCollectionService $collection,
        private readonly OrderZoneResolver $zones,
    ) {}

    /**
     * The Group for this Template in this Wave — found, or created if it should exist.
     *
     * NO EMPTY GROUPS. A Template with nothing eligible gets no Group and stays
     * perfectly usable; the moment work appears the Group is created lazily, from the
     * Template's configuration AS IT IS THEN. Creating empty shells at wave start
     * would fill the board with groups nobody asked for and make "has work" unreadable.
     *
     * IDEMPOTENT BY IDENTITY, not by name. The lookup is (wave, template), so a renamed
     * or re-coded Group is still recognised as this Template's Group for this Wave —
     * which is exactly what the previous string-convention approach could not do.
     *
     * @param  callable(): int  $eligibleCount  how much work this Template has right now
     * @return VirtualCapacitySlot|null null means "no Group is warranted yet"
     */
    public function ensureGroupForTemplate(
        DistributionWindow $window,
        DistributionGroupTemplate $template,
        string $warehouseId,
        string $waveId,
        callable $eligibleCount,
    ): ?VirtualCapacitySlot {
        $existing = $this->findGroup($waveId, $template->id);

        // Already exists — additional orders join THIS Group, never a second one.
        if ($existing !== null) {
            return $existing;
        }

        if ($eligibleCount() < 1) {
            return null;
        }

        try {
            return $this->templates->applyToNewGroup(
                window: $window,
                template: $template,
                warehouseId: $warehouseId,
                code: $this->codeFor($template, $window, $waveId),
                nameOverride: null,
                capacityOverride: null,
                capacityProvided: false,
                zoneIdsOverride: null,
                // PART 8 — the daily rule allows a Group to exceed its planning capacity.
                // This is the ONE caller that opts out; every manual path still enforces.
                enforceCapacity: false,
                // The Wave this Group is FOR — not whichever one a resolver would pick.
                waveId: $waveId,
            );
        } catch (\Illuminate\Database\QueryException $e) {
            // Lost the race against a concurrent caller. The unique index did its job;
            // the Group that won is the right one, so use it rather than failing.
            $winner = $this->findGroup($waveId, $template->id);

            if ($winner !== null) {
                return $winner;
            }

            throw $e;
        }
    }

    /**
     * WAVE START — create today's Groups for the Templates that actually have work.
     *
     * ┌─ WHAT DECIDES, AND WHAT DOES NOT ────────────────────────────────────────┐
     * │ Eligibility comes from `eligibleUnassignedOrders()` — the collector's own  │
     * │ definition, not a copy. Zones come from `OrderZoneResolver` — the same     │
     * │ resolver the collector uses. Creation goes through `applyToNewGroup()`.    │
     * │ This method only ANSWERS "does this Template have work?" and calls the     │
     * │ existing pieces in order.                                                 │
     * └──────────────────────────────────────────────────────────────────────────┘
     *
     * IT CREATES GROUPS, IT DOES NOT FILL THEM. Attaching the orders is the
     * collector's job and already works — the sweep exists so that there is somewhere
     * for the collector to put them. That split is why no assignment logic appears here.
     *
     * SCOPED TO THE WAVE'S WAREHOUSE. A Wave is per warehouse, so only orders whose
     * assigned warehouse matches it are counted; another warehouse's work belongs to its
     * own Wave and its own Groups.
     *
     * IDEMPOTENT. Re-running finds the existing Group for each Template and returns it
     * unchanged, and the (wave, template) unique index is the backstop if two sweeps
     * overlap. Running a Wave's start twice creates nothing twice.
     *
     * @return array{created: int, reused: int, skipped: int}
     */
    public function sweepWave(
        DistributionWindow $window,
        string $waveId,
        string $companyId,
        string $warehouseId,
    ): array {
        $eligibleByZone = $this->eligibleCountsByZone($companyId, $warehouseId, (string) $window->id);

        $created = 0;
        $reused = 0;
        $skipped = 0;

        /*
         * WHY THE SKIP IS NOW ITEMISED — TASK-...-CLOSURE-FIX-001 §2.
         *
         * A sweep that reports only `skipped: 5` is indistinguishable from a sweep that
         * never ran, which is exactly what made the wave-009 incident a database
         * forensic instead of a log read. The counts stay byte-identical; what is added
         * is WHICH template declined and WHAT it saw. No new business logic, no new
         * query, no order or customer data — a template name, its zones, and the count
         * the existing closure already computed.
         *
         * @var list<array{template: string, zones: list<int>, eligible: int, reason: string}>
         */
        $skippedTemplates = [];

        /*
         * Templates whose Zones are ALL already held by another Group. The Group is still
         * created — that is the certified multi-Wave behaviour — and it TAKES those Zones
         * from their current holder, so it is worth saying out loud rather than leaving an
         * emptied Group on the board unexplained.
         *
         * @var list<array{template: string, zones: list<int>, reason: string}>
         */
        $zoneConflicts = [];

        // The CURRENT active templates, freshly read — a new Wave uses the latest
        // configuration, never a previous Wave's copy of it.
        foreach ($this->templates->listForCompany($companyId) as $template) {
            $before = $this->findGroup($waveId, (string) $template->id);

            // Only asked on the create path — an existing Group already has its Zones.
            $fullyOwned = $before === null
                && $this->everyZoneAlreadyOwned($window, $warehouseId, $template);

            // Captured by reference so the count is recorded WITHOUT making the closure
            // eager: it still runs only when there is no existing Group, which is the
            // only path that can skip.
            $eligible = null;

            $group = $this->ensureGroupForTemplate(
                $window,
                $template,
                $warehouseId,
                $waveId,
                static function () use ($template, $eligibleByZone, &$eligible): int {
                    $total = 0;

                    foreach ($template->zoneIds() as $zoneId) {
                        $total += $eligibleByZone[$zoneId] ?? 0;
                    }

                    return $eligible = $total;
                },
            );

            if ($group === null) {
                $skipped++;
                $skippedTemplates[] = [
                    'template' => (string) ($template->name ?? $template->id),
                    'zones' => $template->zoneIds(),
                    'eligible' => (int) $eligible,
                    // The ONLY reason the existing logic declines. Named, not inferred.
                    'reason' => self::SKIP_NO_ELIGIBLE_WORK,
                ];
            } elseif ($before === null) {
                $created++;

                if ($fullyOwned) {
                    $zoneConflicts[] = [
                        'template' => (string) ($template->name ?? $template->id),
                        'zones' => $template->zoneIds(),
                        'reason' => self::ZONES_ALREADY_OWNED,
                    ];
                }
            } else {
                $reused++;
            }
        }

        return [
            'created' => $created,
            'reused' => $reused,
            'skipped' => $skipped,
            'skipped_templates' => $skippedTemplates,
            // The eligible-per-zone map the decision was made from. Small (zone id =>
            // count) and the single most useful fact when a sweep declines: it shows
            // work that no template can reach — see uncoveredZones().
            'eligible_by_zone' => $eligibleByZone,
            'uncovered_zones' => $this->uncoveredZones($companyId, $eligibleByZone),
            'zone_conflicts' => $zoneConflicts,
        ];
    }

    /**
     * How much eligible, unassigned work sits in each Zone for one warehouse.
     *
     * Built from the canonical eligibility list and the canonical city->zone resolver, so
     * the sweep's idea of "this Template has work" is the same as the collector's idea of
     * "this order belongs in that Group".
     *
     * @return array<int, int> zone id => order count
     */
    /**
     * TRUE when every Zone this Template covers already belongs to another Group in this
     * window+warehouse — so a Group created for it can be given none of them.
     *
     * DIAGNOSTIC ONLY; nothing branches on it. Reads the same table the uniqueness is
     * declared on, scoped exactly the way that index is keyed. A Template with no Zones is
     * NOT "fully owned" — that is a separate configuration question.
     */
    private function everyZoneAlreadyOwned(
        DistributionWindow $window,
        string $warehouseId,
        DistributionGroupTemplate $template,
    ): bool {
        $zoneIds = $template->zoneIds();

        if ($zoneIds === []) {
            return false;
        }

        $owned = DB::table('distribution_slot_zones')
            ->where('distribution_window_id', $window->id)
            ->where('warehouse_id', $warehouseId)
            ->whereIn('distribution_zone_id', $zoneIds)
            ->distinct()
            ->count('distribution_zone_id');

        return $owned >= count($zoneIds);
    }

    /**
     * Zones that hold eligible work which NO active Template can reach.
     *
     * ┌─ WHY THIS EXISTS ────────────────────────────────────────────────────────┐
     * │ The sweep is Template-driven, so a Zone attached to no Template is        │
     * │ unreachable: its Orders can never receive a Group, and the sweep declines │
     * │ in perfect silence. That is not "no work" — it is work the configuration  │
     * │ cannot express, and the two must never render the same way.              │
     * └──────────────────────────────────────────────────────────────────────────┘
     *
     * DERIVED, NOT RE-QUERIED for eligibility: it folds the map the caller already
     * built against the same active-Template list the sweep just iterated. It adds no
     * eligibility rule and makes no decision — it only names what was already true.
     *
     * @param  array<int, int>  $eligibleByZone  zone id => eligible order count
     * @return list<array{zone_id: int, eligible: int}>
     */
    private function uncoveredZones(string $companyId, array $eligibleByZone): array
    {
        $covered = [];

        foreach ($this->templates->listForCompany($companyId) as $template) {
            foreach ($template->zoneIds() as $zoneId) {
                $covered[$zoneId] = true;
            }
        }

        $uncovered = [];

        foreach ($eligibleByZone as $zoneId => $count) {
            if ($count > 0 && ! isset($covered[(int) $zoneId])) {
                $uncovered[] = ['zone_id' => (int) $zoneId, 'eligible' => (int) $count];
            }
        }

        return $uncovered;
    }

    private function eligibleCountsByZone(
        string $companyId,
        string $warehouseId,
        string $windowId,
    ): array {
        // WORK NEEDING A GROUP COMES FROM TWO PLACES, and counting only the first was a
        // real gap: `eligibleUnassignedOrders()` excludes anything already collected into
        // a window, and the collector runs continuously. So by the time a Wave starts,
        // the orders that most need a Group are usually ALREADY in the window with no
        // slot — invisible to that source. A sweep built on it alone would create nothing
        // and leave that work ungrouped indefinitely.
        //
        // Both halves use canonical rows: the collector's own eligibility list, and the
        // window's own assignment rows. Neither invents a definition.
        $counts = $this->collectedButUngroupedByZone($windowId, $warehouseId);

        $orders = $this->collection->eligibleUnassignedOrders($companyId);

        if ($orders === []) {
            return $counts;
        }

        $mine = array_values(array_filter(
            $orders,
            static fn (object $o): bool => $o->assigned_warehouse_id !== null
                && (string) $o->assigned_warehouse_id === $warehouseId,
        ));

        if ($mine === []) {
            return $counts;
        }

        $zoneByCity = $this->zones->resolveMany(array_values(array_filter(array_map(
            static fn (object $o): ?int => $o->logistics_city_id === null
                ? null
                : (int) $o->logistics_city_id,
            $mine,
        ))));

        foreach ($mine as $order) {
            if ($order->logistics_city_id === null) {
                continue;
            }

            $zoneId = $zoneByCity[(int) $order->logistics_city_id] ?? null;

            if ($zoneId === null) {
                continue;
            }

            $counts[$zoneId] = ($counts[$zoneId] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Orders already collected into this Window that still belong to no Group.
     *
     * These are the ones a Template's Group needs to exist for: the collector has placed
     * them, their Zone is known, and they are waiting for somewhere to go.
     *
     * @return array<int, int> zone id => order count
     */
    private function collectedButUngroupedByZone(string $windowId, string $warehouseId): array
    {
        $rows = DB::table('distribution_window_orders as dwo')
            ->join('orders as o', 'o.id', '=', 'dwo.order_id')
            ->where('dwo.distribution_window_id', $windowId)
            ->whereNull('dwo.virtual_slot_id')
            ->whereNotNull('dwo.distribution_zone_id')
            ->where('o.assigned_warehouse_id', $warehouseId)
            ->select('dwo.distribution_zone_id', DB::raw('COUNT(*) as total'))
            ->groupBy('dwo.distribution_zone_id')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row->distribution_zone_id] = (int) $row->total;
        }

        return $counts;
    }

    /** This Template's Group in this Wave, closed ones excluded — they are historical. */
    public function findGroup(string $waveId, string $templateId): ?VirtualCapacitySlot
    {
        return VirtualCapacitySlot::query()
            ->where('preparation_wave_id', $waveId)
            ->where('distribution_group_template_id', $templateId)
            ->whereNull('closed_at')
            ->first();
    }

    /**
     * End every Group belonging to a Wave, complete or not.
     *
     * ┌─ WHY INCOMPLETE GROUPS CLOSE TOO ────────────────────────────────────────┐
     * │ A Group is the operational instance of ONE Wave. When the Wave ends the   │
     * │ instance is over — whether or not its work finished. Carrying it forward   │
     * │ would make tomorrow's board show yesterday's plan, built from a Template   │
     * │ configuration that may since have changed.                                │
     * │                                                                          │
     * │ The unfinished ORDERS are a different question: they are released from    │
     * │ the Group so the existing collector can pick them up for the next Wave —  │
     * │ the canonical eligible pool, not a copied Group.                          │
     * └──────────────────────────────────────────────────────────────────────────┘
     *
     * NOTHING IS DELETED. The Group keeps its rows, its zones and its manifest; it is
     * stamped closed and drops out of the ACTIVE reads. History stays queryable.
     *
     * IDEMPOTENT. An already-closed Group is skipped, so re-running a wave close cannot
     * restamp a closure time or re-release orders that have moved on since.
     *
     * @return array{closed: int, orders_released: int}
     */
    public function closeWave(string $waveId, string $reason = self::CLOSED_WAVE_ENDED): array
    {
        return DB::transaction(function () use ($waveId, $reason): array {
            /** @var list<VirtualCapacitySlot> $groups */
            $groups = VirtualCapacitySlot::query()
                ->where('preparation_wave_id', $waveId)
                ->whereNull('closed_at')
                ->lockForUpdate()
                ->get()
                ->all();

            if ($groups === []) {
                return ['closed' => 0, 'orders_released' => 0];
            }

            $groupIds = array_map(
                static fn (VirtualCapacitySlot $g): string => (string) $g->id,
                $groups,
            );

            // Release the still-unfinished orders back to the pool by detaching them
            // from the closing Group. The assignment row survives — only its Group
            // membership is cleared — so the order's window history is not rewritten.
            $released = DB::table('distribution_window_orders')
                ->whereIn('virtual_slot_id', $groupIds)
                ->update(['virtual_slot_id' => null]);

            $now = now();

            VirtualCapacitySlot::query()
                ->whereIn('id', $groupIds)
                ->update(['closed_at' => $now, 'closed_reason' => $reason]);

            return ['closed' => count($groups), 'orders_released' => $released];
        });
    }

    /**
     * A code that reads as "this Template, this day".
     *
     * Human-facing only — identity lives in the two id columns, so a renamed Group is
     * still found. Collisions are prevented by the Group code's own unique key; the
     * date suffix keeps yesterday's code from colliding with today's.
     */
    private function codeFor(
        DistributionGroupTemplate $template,
        DistributionWindow $window,
        string $waveId,
    ): string {
        $date = $window->window_date instanceof DateTimeInterface
            ? $window->window_date->format('Ymd')
            : (string) $window->window_date;

        $stem = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '-', $template->name) ?? 'TPL');
        $stem = trim($stem, '-');

        if ($stem === '') {
            $stem = 'TPL';
        }

        // THE WAVE SUFFIX IS NOT DECORATION. Group codes are unique per
        // (window, code), and several Waves share ONE window on the same day — so
        // "MORNING-20260901" for two Waves collides on the second insert. Deriving the
        // suffix from the wave id keeps it deterministic, so re-running a Wave's sweep
        // produces the same code and stays idempotent.
        return substr($stem, 0, 22).'-'.$date.'-'.strtoupper(substr($waveId, 0, 4));
    }

    /**
     * Every Group that is still operational for a Wave.
     *
     * The ACTIVE read: scoped to the Wave and excluding closed Groups, so a previous
     * Wave's Groups can never appear on today's board.
     *
     * @return list<VirtualCapacitySlot>
     */
    public function activeGroupsForWave(string $waveId): array
    {
        return VirtualCapacitySlot::query()
            ->where('preparation_wave_id', $waveId)
            ->whereNull('closed_at')
            ->orderBy('code')
            ->get()
            ->all();
    }

    /** Guard for callers that must refuse to operate on a closed Group. */
    public function assertOperational(VirtualCapacitySlot $group): void
    {
        if ($group->closed_at !== null) {
            throw new DistributionException(
                "Group {$group->code} closed with its preparation wave and is historical.",
            );
        }
    }
}

<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Domain\Services;

use Illuminate\Support\Facades\DB;

/**
 * Detects capacity overflow and proposes where the excess could go.
 *
 * Nothing in this class writes. Suggestions are advisory and a manager approves
 * them explicitly — an over-capacity Slot must never silently shed Orders, and
 * an Order must never move because an algorithm decided it should.
 *
 * ON PROXIMITY. The brief ranks candidates by geographic proximity first.
 * `distribution_zones` carries no coordinates and no adjacency graph — only
 * code, names and an active flag — so true distance is not computable from the
 * existing architecture. Rather than invent a geography engine (which the brief
 * forbids) or fabricate a distance, proximity is approximated by the real
 * hierarchy that does exist: cities carry `governorate_id`, so two Zones that
 * serve cities in the same governorate are treated as near, and everything else
 * as far. This is a genuine approximation and is recorded as a gap, not
 * presented as distance.
 */
final class RedistributionSuggestionService
{
    public function __construct(
        private readonly DistributionAggregationService $aggregation,
    ) {}

    /**
     * Every over-capacity Slot in a Window, with candidate Orders and candidate
     * destination Slots.
     *
     * @return list<array<string, mixed>>
     */
    public function overflows(string $windowId, ?string $warehouseId = null): array
    {
        // Overflow is derived from the slot rollup, so scoping that rollup scopes
        // the suggestions with it — one warehouse's capacity problem is never
        // reported against another's.
        $slots = $this->aggregation->slotSummaries($windowId, $warehouseId);

        $overflowing = array_values(array_filter(
            $slots,
            static fn (array $s): bool => $s['is_over_capacity'] === true,
        ));

        if ($overflowing === []) {
            return [];
        }

        $headroom = $this->headroomBySlot($slots);
        $governorates = $this->governoratesByZone($windowId);
        $limit = (int) config('distribution.redistribution.max_suggestions_per_overflow', 25);

        $out = [];

        foreach ($overflowing as $slot) {
            $excess = (int) $slot['overflow_orders'];

            $candidateOrders = $this->candidateOrders($windowId, (string) $slot['slot_id'], $excess);

            $out[] = [
                'slot_id' => $slot['slot_id'],
                'code' => $slot['code'],
                'zone_ids' => $slot['zone_ids'],
                'capacity_orders' => $slot['capacity_orders'],
                'demand_orders' => $slot['demand_orders'],
                'excess_orders' => $excess,
                'candidate_orders' => $candidateOrders,
                'suggestions' => array_slice(
                    $this->rankSuggestions($candidateOrders, $slot, $headroom, $governorates),
                    0,
                    $limit,
                ),
            ];
        }

        return $out;
    }

    /**
     * The Orders proposed for relocation — the newest assignments first.
     *
     * Newest-first is deliberate: the Orders that pushed a Slot over its limit
     * are the least disruptive to move, and an Order that has been planned in
     * that Slot all morning is the one an operator least expects to jump.
     *
     * @return list<array<string, mixed>>
     */
    private function candidateOrders(string $windowId, string $slotId, int $excess): array
    {
        if ($excess <= 0) {
            return [];
        }

        return DB::table('distribution_window_orders as dwo')
            ->join('orders as o', 'o.id', '=', 'dwo.order_id')
            ->where('dwo.distribution_window_id', $windowId)
            ->where('dwo.virtual_slot_id', $slotId)
            ->orderByDesc('dwo.assigned_at')
            ->limit($excess)
            ->select([
                'dwo.id as assignment_id',
                'dwo.order_id',
                'dwo.distribution_zone_id',
                'o.order_number',
            ])
            ->get()
            ->map(static fn (object $r): array => [
                'assignment_id' => $r->assignment_id,
                'order_id' => $r->order_id,
                'order_number' => $r->order_number,
                'zone_id' => $r->distribution_zone_id === null ? null : (int) $r->distribution_zone_id,
            ])
            ->all();
    }

    /**
     * Pair each candidate Order with destination Slots that could take it.
     *
     * @param  list<array<string, mixed>>  $candidates
     * @param  array<string, mixed>  $fromSlot
     * @param  array<string, int|null>  $headroom
     * @param  array<int, list<int>>  $governorates
     * @return list<array<string, mixed>>
     */
    private function rankSuggestions(
        array $candidates,
        array $fromSlot,
        array $headroom,
        array $governorates,
    ): array {
        $out = [];

        foreach ($candidates as $order) {
            $zoneId = $order['zone_id'];
            $orderGovs = $zoneId === null ? [] : ($governorates[$zoneId] ?? []);

            $options = [];

            foreach ($headroom as $slotId => $free) {
                if ($slotId === $fromSlot['slot_id']) {
                    continue;
                }

                // Unconstrained Slots (null capacity) can always absorb work;
                // constrained Slots need genuine free space.
                if ($free !== null && $free <= 0) {
                    continue;
                }

                $near = $this->sharesGovernorate($orderGovs, $slotId, $governorates, $headroom);

                $options[] = [
                    'slot_id' => $slotId,
                    'available_capacity' => $free,
                    'proximity' => $near ? 'same_governorate' : 'other',
                    'rank_score' => ($near ? 1000 : 0) + ($free ?? 500),
                ];
            }

            usort($options, static fn (array $a, array $b): int => $b['rank_score'] <=> $a['rank_score']);

            if ($options === []) {
                continue;
            }

            $out[] = [
                'assignment_id' => $order['assignment_id'],
                'order_id' => $order['order_id'],
                'order_number' => $order['order_number'],
                'from_slot_id' => $fromSlot['slot_id'],
                'from_zone_id' => $zoneId,
                'candidate_slots' => $options,
            ];
        }

        return $out;
    }

    /**
     * @param  list<int>  $orderGovernorates
     * @param  array<int, list<int>>  $governorates
     * @param  array<string, int|null>  $headroom
     */
    private function sharesGovernorate(
        array $orderGovernorates,
        string $slotId,
        array $governorates,
        array $headroom,
    ): bool {
        if ($orderGovernorates === []) {
            return false;
        }

        foreach ($this->zonesForSlot($slotId, $headroom) as $zoneId) {
            if (array_intersect($orderGovernorates, $governorates[$zoneId] ?? []) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, int|null>  $headroom
     * @return list<int>
     */
    private function zonesForSlot(string $slotId, array $headroom): array
    {
        /** @var array<string, list<int>>|null $cache */
        static $cache = null;

        if ($cache === null) {
            $cache = [];

            DB::table('distribution_slot_zones')
                ->whereIn('virtual_slot_id', array_keys($headroom))
                ->select('virtual_slot_id', 'distribution_zone_id')
                ->get()
                ->each(function (object $r) use (&$cache): void {
                    $cache[(string) $r->virtual_slot_id][] = (int) $r->distribution_zone_id;
                });
        }

        return $cache[$slotId] ?? [];
    }

    /**
     * Free order-capacity per Slot. Null means unconstrained on that axis.
     *
     * @param  list<array<string, mixed>>  $slots
     * @return array<string, int|null>
     */
    private function headroomBySlot(array $slots): array
    {
        /** @var array<string, int|null> $out */
        $out = [];

        foreach ($slots as $s) {
            $capacity = $s['capacity_orders'];

            $out[(string) $s['slot_id']] = $capacity === null
                ? null
                : max(0, (int) $capacity - (int) $s['demand_orders']);
        }

        return $out;
    }

    /**
     * Governorates served by each Zone, via the existing city hierarchy.
     *
     * @return array<int, list<int>>
     */
    private function governoratesByZone(string $windowId): array
    {
        /** @var array<int, list<int>> $out */
        $out = [];

        DB::table('logistics_cities')
            ->whereNotNull('distribution_zone_id')
            ->whereNotNull('governorate_id')
            ->select('distribution_zone_id', 'governorate_id')
            ->distinct()
            ->get()
            ->each(function (object $r) use (&$out): void {
                $out[(int) $r->distribution_zone_id][] = (int) $r->governorate_id;
            });

        return $out;
    }
}

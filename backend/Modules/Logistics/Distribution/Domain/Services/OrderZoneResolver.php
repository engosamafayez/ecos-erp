<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Domain\Services;

use Illuminate\Support\Facades\DB;

/**
 * Resolves an Order to its Distribution Zone using the EXISTING geography chain.
 *
 *   orders.logistics_city_id → logistics_cities.distribution_zone_id → distribution_zones
 *
 * No second geographic engine is introduced here and no coordinates are
 * interpreted: this class is a lookup over configuration that Logistics/Geography
 * already owns. If a city carries no Zone, the answer is null — the Order is
 * still collected, and surfaces as unzoned work rather than disappearing.
 */
final class OrderZoneResolver
{
    /** @return int|null Distribution zone id, or null when the city has no Zone. */
    public function resolve(?int $logisticsCityId): ?int
    {
        if ($logisticsCityId === null) {
            return null;
        }

        $zoneId = DB::table('logistics_cities')
            ->where('id', $logisticsCityId)
            ->value('distribution_zone_id');

        return $zoneId === null ? null : (int) $zoneId;
    }

    /**
     * Resolve many cities at once.
     *
     * Collection runs over batches of Orders; resolving one city per Order would
     * make ingestion N+1 against a table that changes rarely.
     *
     * @param  list<int>  $logisticsCityIds
     * @return array<int, int> city id => zone id (cities without a Zone are absent)
     */
    public function resolveMany(array $logisticsCityIds): array
    {
        if ($logisticsCityIds === []) {
            return [];
        }

        /** @var array<int, int> $map */
        $map = [];

        DB::table('logistics_cities')
            ->whereIn('id', array_values(array_unique($logisticsCityIds)))
            ->whereNotNull('distribution_zone_id')
            ->select('id', 'distribution_zone_id')
            ->get()
            ->each(function (object $row) use (&$map): void {
                $map[(int) $row->id] = (int) $row->distribution_zone_id;
            });

        return $map;
    }
}

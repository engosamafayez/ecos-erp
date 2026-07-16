<?php

declare(strict_types=1);

namespace Modules\Operations\Distribution\Application\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Operations\Distribution\Domain\Models\DistributionTrip;
use Modules\Operations\Distribution\Domain\Models\DistributionTripOrder;

class DistributionBoardService
{
    /**
     * Fetch the active wave for a company.
     * Returns the most-recent wave that is not cancelled or closed.
     */
    public function getActiveWave(int $companyId): ?object
    {
        return DB::table('preparation_waves')
            ->where('company_id', $companyId)
            ->whereIn('status', ['draft', 'collecting', 'planning', 'shortage_blocked', 'preparing', 'completed'])
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * Return all distribution zones that appear in the wave's orders.
     * Each zone entry includes order counts (total / assigned / unassigned).
     */
    public function getWaveZones(string $waveId, int $companyId): Collection
    {
        $assignedByZone = DB::table('distribution_trip_orders as dto')
            ->join('distribution_trips as dt', 'dt.id', '=', 'dto.distribution_trip_id')
            ->join('orders as o', 'o.id', '=', 'dto.order_id')
            ->join('logistics_cities as lc', 'lc.id', '=', 'o.logistics_city_id')
            ->where('dt.preparation_wave_id', $waveId)
            ->whereNotNull('lc.distribution_zone_id')
            ->select('lc.distribution_zone_id', DB::raw('count(*) as assigned_count'))
            ->groupBy('lc.distribution_zone_id')
            ->pluck('assigned_count', 'distribution_zone_id');

        return DB::table('preparation_wave_orders as pwo')
            ->join('orders as o', 'o.id', '=', 'pwo.order_id')
            ->join('logistics_cities as lc', 'lc.id', '=', 'o.logistics_city_id')
            ->join('distribution_zones as dz', 'dz.id', '=', 'lc.distribution_zone_id')
            ->where('pwo.preparation_wave_id', $waveId)
            ->whereNotNull('lc.distribution_zone_id')
            ->where('dz.is_active', true)
            ->select(
                'dz.id as zone_id',
                'dz.name_en',
                'dz.name_ar',
                'dz.code',
                'dz.color',
                DB::raw('count(distinct o.id) as total_orders'),
                DB::raw('coalesce(sum(o.grand_total), 0) as total_value'),
            )
            ->groupBy('dz.id', 'dz.name_en', 'dz.name_ar', 'dz.code', 'dz.color')
            ->orderBy('dz.name_en')
            ->get()
            ->map(function ($zone) use ($assignedByZone) {
                $assigned = (int) ($assignedByZone[$zone->zone_id] ?? 0);
                return (object) [
                    'zone_id'         => $zone->zone_id,
                    'name_en'         => $zone->name_en,
                    'name_ar'         => $zone->name_ar,
                    'code'            => $zone->code,
                    'color'           => $zone->color,
                    'total_orders'    => (int) $zone->total_orders,
                    'assigned_orders' => $assigned,
                    'unassigned_orders' => (int) $zone->total_orders - $assigned,
                    'total_value'     => (float) $zone->total_value,
                ];
            });
    }

    /**
     * Return orders in a zone that are not yet assigned to any trip in this wave.
     */
    public function getUnassignedZoneOrders(string $waveId, int $zoneId): Collection
    {
        $assignedOrderIds = DB::table('distribution_trip_orders as dto')
            ->join('distribution_trips as dt', 'dt.id', '=', 'dto.distribution_trip_id')
            ->where('dt.preparation_wave_id', $waveId)
            ->pluck('dto.order_id');

        return DB::table('preparation_wave_orders as pwo')
            ->join('orders as o', 'o.id', '=', 'pwo.order_id')
            ->join('logistics_cities as lc', 'lc.id', '=', 'o.logistics_city_id')
            ->join('logistics_governorates as lg', 'lg.id', '=', 'lc.governorate_id')
            ->where('pwo.preparation_wave_id', $waveId)
            ->where('lc.distribution_zone_id', $zoneId)
            ->when($assignedOrderIds->isNotEmpty(), fn ($q) => $q->whereNotIn('o.id', $assignedOrderIds))
            ->select(
                'o.id as order_id',
                'o.order_number',
                'o.grand_total',
                'o.status',
                'lc.name_en as city_name',
                'lg.name_en as governorate_name',
                'pwo.delivery_zone_snapshot',
                'pwo.zone_code_snapshot',
                'o.customer_name',
                'o.customer_phone',
            )
            ->orderBy('pwo.created_at')
            ->get();
    }

    /**
     * Return trips for a wave, optionally filtered by zone.
     */
    public function getWaveTrips(string $waveId, ?int $zoneId = null): Collection
    {
        return DistributionTrip::with(['vehicle', 'driver', 'carrier', 'custodyItems'])
            ->where('preparation_wave_id', $waveId)
            ->where('status', '!=', 'cancelled')
            ->when($zoneId, fn ($q) => $q->where('distribution_zone_id', $zoneId))
            ->orderBy('trip_number')
            ->get();
    }

    /**
     * Return the assigned orders for a specific trip, with order detail.
     */
    public function getTripOrders(string $tripId): Collection
    {
        return DB::table('distribution_trip_orders as dto')
            ->join('orders as o', 'o.id', '=', 'dto.order_id')
            ->join('logistics_cities as lc', 'lc.id', '=', 'o.logistics_city_id')
            ->where('dto.distribution_trip_id', $tripId)
            ->select(
                'o.id as order_id',
                'o.order_number',
                'o.grand_total',
                'o.status',
                'o.customer_name',
                'o.customer_phone',
                'lc.name_en as city_name',
                'dto.assignment_type',
                'dto.assigned_at',
            )
            ->orderBy('dto.assigned_at')
            ->get();
    }

    /**
     * Compute wave-level KPI summary.
     */
    public function getWaveSummary(string $waveId): array
    {
        $totals = DB::table('preparation_wave_orders as pwo')
            ->join('orders as o', 'o.id', '=', 'pwo.order_id')
            ->where('pwo.preparation_wave_id', $waveId)
            ->selectRaw('count(distinct o.id) as total_orders, coalesce(sum(o.grand_total), 0) as total_value')
            ->first();

        $assigned = DB::table('distribution_trip_orders as dto')
            ->join('distribution_trips as dt', 'dt.id', '=', 'dto.distribution_trip_id')
            ->where('dt.preparation_wave_id', $waveId)
            ->count();

        $tripCount = DistributionTrip::where('preparation_wave_id', $waveId)
            ->where('status', '!=', 'cancelled')
            ->count();

        return [
            'total_orders'    => (int) $totals->total_orders,
            'assigned_orders' => $assigned,
            'unassigned_orders' => (int) $totals->total_orders - $assigned,
            'total_value'     => (float) $totals->total_value,
            'trip_count'      => $tripCount,
        ];
    }

    /**
     * Validate that the distribution plan is ready to finalize.
     * Returns a list of validation failures; empty = all clear.
     */
    public function validateForFinalization(string $waveId): array
    {
        $issues = [];

        $summary = $this->getWaveSummary($waveId);
        if ($summary['unassigned_orders'] > 0) {
            $issues[] = [
                'code'    => 'unassigned_orders',
                'message' => "{$summary['unassigned_orders']} orders are not assigned to any trip.",
                'severity' => 'error',
            ];
        }

        $trips = DistributionTrip::where('preparation_wave_id', $waveId)
            ->where('status', 'planning')
            ->get();

        foreach ($trips as $trip) {
            if ($trip->orders_count === 0) {
                $issues[] = [
                    'code'    => 'empty_trip',
                    'message' => "Trip {$trip->trip_number} ({$trip->name}) has no orders.",
                    'severity' => 'warning',
                ];
                continue;
            }

            if (!$trip->isReadyForLoading()) {
                $detail = match ($trip->type) {
                    'company_vehicle'  => 'missing driver or vehicle',
                    'personal_vehicle' => 'missing driver name',
                    'external_carrier' => 'missing carrier assignment',
                    default            => 'incomplete resource assignment',
                };
                $issues[] = [
                    'code'    => 'unassigned_resources',
                    'message' => "Trip {$trip->trip_number} ({$trip->name}): {$detail}.",
                    'severity' => 'error',
                ];
            }
        }

        return $issues;
    }

    /**
     * Finalize the distribution plan — transition all planning trips to loading.
     * This hands off to Loading OS.
     */
    public function finalizePlan(string $waveId, int $userId): void
    {
        DB::transaction(function () use ($waveId, $userId) {
            DistributionTrip::where('preparation_wave_id', $waveId)
                ->where('status', 'planning')
                ->where('orders_count', '>', 0)
                ->update([
                    'status'       => 'loading',
                    'finalized_at' => now(),
                    'finalized_by' => $userId,
                    'updated_at'   => now(),
                ]);
        });
    }

    /**
     * Generate the next sequential trip number for a wave.
     */
    public function nextTripNumber(string $waveId): string
    {
        $count = DistributionTrip::where('preparation_wave_id', $waveId)->count();
        return 'TRIP-' . str_pad((string) ($count + 1), 3, '0', STR_PAD_LEFT);
    }
}

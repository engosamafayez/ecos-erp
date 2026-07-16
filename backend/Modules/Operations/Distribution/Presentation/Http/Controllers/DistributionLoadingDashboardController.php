<?php

declare(strict_types=1);

namespace Modules\Operations\Distribution\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class DistributionLoadingDashboardController extends Controller
{
    /**
     * GET /distribution/loading-trips
     * All trips currently in loading or ready_for_dispatch status for the company.
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;

        $trips = DB::table('distribution_trips as dt')
            ->leftJoin('preparation_waves as pw', 'pw.id', '=', 'dt.preparation_wave_id')
            ->leftJoin('distribution_zones as dz', 'dz.id', '=', 'dt.distribution_zone_id')
            ->leftJoin('fleet_drivers as fd', 'fd.id', '=', 'dt.fleet_driver_id')
            ->leftJoin('fleet_vehicles as fv', 'fv.id', '=', 'dt.fleet_vehicle_id')
            ->leftJoin('external_carriers as ec', 'ec.id', '=', 'dt.external_carrier_id')
            ->leftJoin('distribution_loading_manifests as dlm', 'dlm.distribution_trip_id', '=', 'dt.id')
            ->where('dt.company_id', $companyId)
            ->whereIn('dt.status', ['loading', 'ready_for_dispatch'])
            ->select([
                'dt.id',
                'dt.trip_number',
                'dt.name',
                'dt.status',
                'dt.type',
                'dt.orders_count',
                'dt.collection_amount',
                'dt.finalized_at',
                'dt.dispatched_at',
                DB::raw("COALESCE(fd.name_en, dt.driver_name, 'No Driver') as driver_display"),
                DB::raw("COALESCE(fv.plate_number, '') as vehicle_plate"),
                DB::raw("COALESCE(ec.name, '') as carrier_name"),
                DB::raw("COALESCE(pw.wave_number, '') as wave_number"),
                DB::raw("COALESCE(dz.name_en, '') as zone_name"),
                DB::raw("COALESCE(dz.color, '#64748b') as zone_color"),
                'dlm.id as manifest_id',
                'dlm.status as manifest_status',
                DB::raw("COALESCE(dlm.total_products, 0) as total_products"),
                DB::raw("COALESCE(dlm.confirmed_products, 0) as confirmed_products"),
                DB::raw("COALESCE(dlm.shortage_products, 0) as shortage_products"),
            ])
            ->orderByRaw("CASE WHEN dt.status = 'ready_for_dispatch' THEN 0 ELSE 1 END")
            ->orderBy('dt.finalized_at', 'desc')
            ->get();

        // Batch-fetch driver confirmation stats for manifests
        $manifestIds = $trips->pluck('manifest_id')->filter()->values()->toArray();

        $driverStats = [];
        if (!empty($manifestIds)) {
            $driverStats = DB::table('distribution_loading_manifest_items')
                ->whereIn('loading_manifest_id', $manifestIds)
                ->select([
                    'loading_manifest_id',
                    DB::raw("SUM(CASE WHEN driver_status IN ('confirmed','accepted') THEN 1 ELSE 0 END) as driver_confirmed"),
                    DB::raw("SUM(CASE WHEN driver_status = 'discrepancy' THEN 1 ELSE 0 END) as driver_discrepancies"),
                    DB::raw("SUM(CASE WHEN driver_status = 'pending' THEN 1 ELSE 0 END) as driver_pending"),
                ])
                ->groupBy('loading_manifest_id')
                ->get()
                ->keyBy('loading_manifest_id')
                ->toArray();
        }

        // Batch-fetch custody confirmation stats
        $tripIds = $trips->pluck('id')->toArray();
        $custodyStats = [];
        if (!empty($tripIds)) {
            $custodyStats = DB::table('distribution_trip_custody')
                ->whereIn('distribution_trip_id', $tripIds)
                ->select([
                    'distribution_trip_id',
                    DB::raw('COUNT(*) as custody_total'),
                    DB::raw('SUM(CASE WHEN is_driver_confirmed THEN 1 ELSE 0 END) as custody_confirmed'),
                ])
                ->groupBy('distribution_trip_id')
                ->get()
                ->keyBy('distribution_trip_id')
                ->toArray();
        }

        $result = $trips->map(function ($trip) use ($driverStats, $custodyStats) {
            $manifestId = $trip->manifest_id;
            $dStat      = $manifestId ? ($driverStats[$manifestId] ?? null) : null;
            $cStat      = $custodyStats[$trip->id] ?? null;

            $loadingStatus = $this->deriveLoadingStatus($trip->status, $trip->manifest_status);

            return [
                'id'                  => $trip->id,
                'trip_number'         => $trip->trip_number,
                'name'                => $trip->name,
                'status'              => $trip->status,
                'loading_status'      => $loadingStatus,
                'type'                => $trip->type,
                'orders_count'        => $trip->orders_count,
                'collection_amount'   => (float) $trip->collection_amount,
                'driver_display'      => $trip->driver_display,
                'vehicle_plate'       => $trip->vehicle_plate,
                'carrier_name'        => $trip->carrier_name,
                'wave_number'         => $trip->wave_number,
                'zone_name'           => $trip->zone_name,
                'zone_color'          => $trip->zone_color,
                'manifest_id'         => $manifestId,
                'manifest_status'     => $trip->manifest_status,
                'total_products'      => (int) $trip->total_products,
                'confirmed_products'  => (int) $trip->confirmed_products,
                'shortage_products'   => (int) $trip->shortage_products,
                'driver_confirmed'    => $dStat ? (int) $dStat->driver_confirmed : 0,
                'driver_discrepancies' => $dStat ? (int) $dStat->driver_discrepancies : 0,
                'driver_pending'      => $dStat ? (int) $dStat->driver_pending : (int) $trip->total_products,
                'custody_total'       => $cStat ? (int) $cStat->custody_total : 0,
                'custody_confirmed'   => $cStat ? (int) $cStat->custody_confirmed : 0,
                'finalized_at'        => $trip->finalized_at,
                'dispatched_at'       => $trip->dispatched_at,
            ];
        });

        $stats = [
            'total'               => $result->count(),
            'waiting_for_loading' => $result->where('loading_status', 'waiting_for_loading')->count(),
            'loading'             => $result->where('loading_status', 'loading')->count(),
            'loaded'              => $result->where('loading_status', 'loaded')->count(),
            'ready_for_dispatch'  => $result->where('loading_status', 'ready_for_dispatch')->count(),
        ];

        return response()->json([
            'trips' => $result->values(),
            'stats' => $stats,
        ]);
    }

    private function deriveLoadingStatus(string $tripStatus, ?string $manifestStatus): string
    {
        if ($tripStatus === 'ready_for_dispatch') {
            return 'ready_for_dispatch';
        }

        return match ($manifestStatus) {
            'completed'   => 'loaded',
            'in_progress' => 'loading',
            default       => 'waiting_for_loading',
        };
    }
}

<?php

declare(strict_types=1);

namespace Modules\Operations\Distribution\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Modules\Operations\Distribution\Application\Services\DistributionBoardService;
use Modules\Operations\Distribution\Application\Services\TripManagementService;
use Modules\Operations\Distribution\Presentation\Http\Resources\DistributionBoardResource;
use Modules\Operations\Distribution\Presentation\Http\Resources\DistributionTripResource;

class DistributionBoardController extends Controller
{
    public function __construct(
        private readonly DistributionBoardService $boardService,
        private readonly TripManagementService $tripService,
    ) {}

    /**
     * GET /api/distribution/board
     * Returns active wave + zone breakdown + all trips.
     */
    public function show(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        $wave      = $this->boardService->getActiveWave($companyId);

        if (!$wave) {
            return response()->json(['wave' => null, 'message' => 'No active wave found.'], 200);
        }

        $zones   = $this->boardService->getWaveZones($wave->id, $companyId);
        $trips   = $this->boardService->getWaveTrips($wave->id);
        $summary = $this->boardService->getWaveSummary($wave->id);

        $tripResources = DistributionTripResource::collection($trips)->resolve();

        return response()->json([
            'wave'    => (new DistributionBoardResource($wave, $summary))->resolve(),
            'zones'   => $zones->values(),
            'trips'   => $tripResources,
        ]);
    }

    /**
     * GET /api/distribution/board/zones/{zoneId}/orders
     * Unassigned orders in the zone for the active wave.
     */
    public function zoneOrders(Request $request, int $zoneId): JsonResponse
    {
        $companyId = $request->user()->company_id;
        $wave      = $this->boardService->getActiveWave($companyId);

        if (!$wave) {
            return response()->json(['orders' => []]);
        }

        $orders = $this->boardService->getUnassignedZoneOrders($wave->id, $zoneId);

        return response()->json(['orders' => $orders->values()]);
    }

    /**
     * GET /api/distribution/board/trips/{tripId}/orders
     * Orders currently assigned to a specific trip.
     */
    public function tripOrders(string $tripId): JsonResponse
    {
        $orders = $this->boardService->getTripOrders($tripId);
        return response()->json(['orders' => $orders->values()]);
    }

    /**
     * POST /api/distribution/board/validate
     * Returns validation status without finalizing.
     */
    public function validate(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        $wave      = $this->boardService->getActiveWave($companyId);

        if (!$wave) {
            return response()->json(['issues' => []], 200);
        }

        $issues = $this->boardService->validateForFinalization($wave->id);
        $errors = array_filter($issues, fn ($i) => $i['severity'] === 'error');

        return response()->json([
            'ready'  => count($errors) === 0,
            'issues' => $issues,
        ]);
    }

    /**
     * POST /api/distribution/board/finalize
     * Finalize the distribution plan — hand off to Loading OS.
     */
    public function finalize(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        $userId    = $request->user()->id;
        $wave      = $this->boardService->getActiveWave($companyId);

        if (!$wave) {
            return response()->json(['message' => 'No active wave found.'], 422);
        }

        $issues = $this->boardService->validateForFinalization($wave->id);
        $errors = array_filter($issues, fn ($i) => $i['severity'] === 'error');

        if (!empty($errors)) {
            return response()->json([
                'message' => 'Distribution plan has validation errors.',
                'issues'  => array_values($errors),
            ], 422);
        }

        $this->boardService->finalizePlan($wave->id, $userId);

        return response()->json(['message' => 'Distribution plan finalized. Trips transferred to Loading OS.']);
    }

    /**
     * GET /api/distribution/board/exceptions
     * Returns open (unresolved) wave exceptions for the active wave.
     */
    public function waveExceptions(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        $wave      = $this->boardService->getActiveWave($companyId);

        if (!$wave) {
            return response()->json(['exceptions' => []]);
        }

        $exceptions = DB::table('distribution_wave_exceptions as dwe')
            ->join('orders as o', 'o.id', '=', 'dwe.order_id')
            ->join('logistics_cities as lc', 'lc.id', '=', 'o.logistics_city_id')
            ->join('logistics_governorates as lg', 'lg.id', '=', 'lc.governorate_id')
            ->leftJoin('distribution_trips as dt', 'dt.id', '=', 'dwe.distribution_trip_id')
            ->where('dwe.preparation_wave_id', $wave->id)
            ->whereNull('dwe.resolved_at')
            ->select([
                'dwe.id',
                'dwe.order_id',
                'o.order_number',
                'o.grand_total',
                'dwe.reason',
                'dwe.notes',
                'dwe.returned_at',
                'lc.name_en as city_name',
                'lg.name_en as governorate_name',
                'dt.trip_number as from_trip_number',
            ])
            ->orderByDesc('dwe.returned_at')
            ->get();

        return response()->json([
            'exceptions' => $exceptions->values(),
            'count'      => $exceptions->count(),
        ]);
    }
}

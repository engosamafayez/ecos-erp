<?php

declare(strict_types=1);

namespace Modules\Operations\Distribution\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Operations\Distribution\Application\Services\DriverMobileService;
use Modules\Operations\Distribution\Domain\Models\DistributionTrip;
use Modules\Operations\Distribution\Domain\Models\DriverDeliveryStop;
use Modules\Operations\Distribution\Domain\Models\DriverDeliveryException;
use Modules\Operations\Distribution\Domain\Models\DriverDeliveryReturn;
use RuntimeException;

class DriverMobileController extends Controller
{
    public function __construct(
        private readonly DriverMobileService $service,
    ) {}

    /**
     * GET /driver/trips
     * List active trips (out_for_delivery + in_progress).
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;
        return response()->json($this->service->getActiveTrips($companyId));
    }

    /**
     * GET /driver/trips/{id}
     * Full trip dashboard data.
     */
    public function dashboard(Request $request, string $id): JsonResponse
    {
        $trip = DistributionTrip::findOrFail($id);
        return response()->json($this->service->getTripDashboard($trip));
    }

    /**
     * POST /driver/trips/{id}/start
     */
    public function startTrip(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'lat'       => ['required', 'numeric'],
            'lng'       => ['required', 'numeric'],
            'odo_start' => ['nullable', 'integer', 'min:0'],
        ]);

        $trip = DistributionTrip::findOrFail($id);

        try {
            $trip = $this->service->startTrip(
                trip:     $trip,
                lat:      (float) $data['lat'],
                lng:      (float) $data['lng'],
                userId:   $request->user()->id,
                odoStart: isset($data['odo_start']) ? (int) $data['odo_start'] : null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Trip started.',
            'trip'    => $this->service->getTripDashboard($trip),
        ]);
    }

    /**
     * POST /driver/trips/{id}/finish
     */
    public function finishTrip(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'lat'     => ['required', 'numeric'],
            'lng'     => ['required', 'numeric'],
            'odo_end' => ['nullable', 'integer', 'min:0'],
        ]);

        $trip = DistributionTrip::findOrFail($id);

        try {
            $trip = $this->service->finishTrip(
                trip:   $trip,
                lat:    (float) $data['lat'],
                lng:    (float) $data['lng'],
                userId: $request->user()->id,
                odoEnd: isset($data['odo_end']) ? (int) $data['odo_end'] : null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Trip finished.',
            'trip'    => $this->service->getTripDashboard($trip),
        ]);
    }

    /**
     * GET /driver/trips/{id}/timeline
     */
    public function timeline(Request $request, string $id): JsonResponse
    {
        $trip = DistributionTrip::findOrFail($id);
        return response()->json($this->service->getTimeline($trip));
    }

    /**
     * POST /driver/trips/{id}/gps
     */
    public function recordGps(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'lat'      => ['required', 'numeric'],
            'lng'      => ['required', 'numeric'],
            'speed'    => ['nullable', 'numeric', 'min:0'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
        ]);

        $trip = DistributionTrip::findOrFail($id);

        $waypoint = $this->service->recordGps(
            trip:     $trip,
            lat:      (float) $data['lat'],
            lng:      (float) $data['lng'],
            speed:    isset($data['speed'])    ? (float) $data['speed']    : null,
            accuracy: isset($data['accuracy']) ? (float) $data['accuracy'] : null,
        );

        return response()->json(['recorded' => true, 'id' => $waypoint->id]);
    }

    /**
     * GET /driver/trips/{id}/stops
     */
    public function stops(Request $request, string $id): JsonResponse
    {
        $trip = DistributionTrip::findOrFail($id);
        return response()->json($this->service->getStopList($trip));
    }

    /**
     * GET /driver/trips/{id}/stops/{stopId}
     */
    public function stopDetail(Request $request, string $id, string $stopId): JsonResponse
    {
        $stop = DriverDeliveryStop::where('distribution_trip_id', $id)->findOrFail($stopId);
        return response()->json($this->service->getStopDetail($stop));
    }

    /**
     * GET /driver/trips/{id}/exceptions
     */
    public function exceptions(Request $request, string $id): JsonResponse
    {
        $exceptions = DriverDeliveryException::where('distribution_trip_id', $id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($exceptions);
    }

    /**
     * GET /driver/trips/{id}/returns
     */
    public function returns(Request $request, string $id): JsonResponse
    {
        $returns = DriverDeliveryReturn::where('distribution_trip_id', $id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($returns);
    }
}

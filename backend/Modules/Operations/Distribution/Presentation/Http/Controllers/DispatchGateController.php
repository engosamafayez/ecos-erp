<?php

declare(strict_types=1);

namespace Modules\Operations\Distribution\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Operations\Distribution\Application\Services\DispatchGateService;
use Modules\Operations\Distribution\Application\Services\TripAuditService;
use Modules\Operations\Distribution\Domain\Models\DistributionTrip;
use RuntimeException;

class DispatchGateController extends Controller
{
    public function __construct(
        private readonly DispatchGateService $gateService,
        private readonly TripAuditService    $audit,
    ) {}

    /**
     * GET /distribution/dispatch-gate
     * All trips in loading_completed / driver_accepted / dispatch_blocked.
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;

        return response()->json($this->gateService->getGateTrips($companyId));
    }

    /**
     * GET /distribution/dispatch-gate/{id}
     * Full trip review: manifest, custody, checklist, audit trail.
     */
    public function tripReview(Request $request, string $id): JsonResponse
    {
        try {
            $review = $this->gateService->getTripReview($id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->json($review);
    }

    /**
     * POST /distribution/trips/{id}/dispatch-vehicle
     * Dispatch the vehicle — records departure info and sets trip to 'out_for_delivery'.
     */
    public function dispatchVehicle(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'odometer_start' => ['nullable', 'integer', 'min:0'],
            'fuel_level'     => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes'          => ['nullable', 'string', 'max:500'],
        ]);

        $trip = DistributionTrip::findOrFail($id);

        try {
            $trip = $this->gateService->dispatchVehicle(
                $trip,
                isset($data['odometer_start']) ? (int) $data['odometer_start'] : null,
                isset($data['fuel_level']) ? (float) $data['fuel_level'] : null,
                $data['notes'] ?? null,
                $request->user()->id,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message'     => 'Vehicle dispatched. Trip is now out for delivery.',
            'trip'        => [
                'id'          => $trip->id,
                'status'      => $trip->status,
                'departure_at' => $trip->departure_at,
            ],
        ]);
    }

    /**
     * GET /distribution/trips/{id}/audit-trail
     */
    public function auditTrail(Request $request, string $id): JsonResponse
    {
        DistributionTrip::findOrFail($id);

        return response()->json([
            'audit_trail' => $this->audit->getTrail($id),
        ]);
    }
}

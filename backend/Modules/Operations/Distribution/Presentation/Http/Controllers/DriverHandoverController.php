<?php

declare(strict_types=1);

namespace Modules\Operations\Distribution\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Operations\Distribution\Application\Services\DriverHandoverService;
use Modules\Operations\Distribution\Domain\Models\DistributionLoadingManifest;
use Modules\Operations\Distribution\Domain\Models\DistributionLoadingManifestItem;
use Modules\Operations\Distribution\Domain\Models\DistributionTrip;
use Modules\Operations\Distribution\Domain\Models\DistributionTripCustody;
use RuntimeException;

class DriverHandoverController extends Controller
{
    public function __construct(
        private readonly DriverHandoverService $handoverService,
    ) {}

    /**
     * GET /distribution/trips/{id}/handover-status
     * Full driver handover status: manifest items + custody items + can_dispatch.
     */
    public function handoverStatus(string $id): JsonResponse
    {
        $trip = DistributionTrip::findOrFail($id);

        return response()->json($this->handoverService->handoverStatus($trip->id));
    }

    /**
     * POST /distribution/manifests/{id}/items/{itemId}/driver-confirm
     * Driver confirms the quantity received for one product.
     */
    public function confirmProductReceipt(Request $request, int $id, int $itemId): JsonResponse
    {
        $data = $request->validate([
            'received_qty' => ['required', 'numeric', 'min:0'],
        ]);

        $item = DistributionLoadingManifestItem::where('loading_manifest_id', $id)->findOrFail($itemId);

        try {
            $item = $this->handoverService->confirmProductReceipt(
                $item,
                (float) $data['received_qty'],
                $request->user()->id,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $manifest = DistributionLoadingManifest::findOrFail($id);
        $status   = $this->handoverService->handoverStatus($manifest->distribution_trip_id);

        return response()->json([
            'item'   => $item->toArray(),
            'status' => $status,
        ]);
    }

    /**
     * POST /distribution/manifests/{id}/items/{itemId}/accept-discrepancy
     * Supervisor accepts a quantity discrepancy so dispatch can proceed.
     */
    public function acceptDiscrepancy(Request $request, int $id, int $itemId): JsonResponse
    {
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $item = DistributionLoadingManifestItem::where('loading_manifest_id', $id)->findOrFail($itemId);

        try {
            $item = $this->handoverService->acceptDiscrepancy(
                $item,
                $request->user()->id,
                $data['notes'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $manifest = DistributionLoadingManifest::findOrFail($id);
        $status   = $this->handoverService->handoverStatus($manifest->distribution_trip_id);

        return response()->json([
            'item'   => $item->toArray(),
            'status' => $status,
        ]);
    }

    /**
     * POST /distribution/trips/{id}/custody/{custodyId}/driver-confirm
     * Driver confirms receipt of a custody item.
     */
    public function confirmCustody(Request $request, string $id, int $custodyId): JsonResponse
    {
        $data = $request->validate([
            'received_qty' => ['required', 'integer', 'min:0'],
        ]);

        $item = DistributionTripCustody::where('distribution_trip_id', $id)->findOrFail($custodyId);

        try {
            $item = $this->handoverService->confirmCustodyItem(
                $item,
                (int) $data['received_qty'],
                $request->user()->id,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $status = $this->handoverService->handoverStatus($id);

        return response()->json([
            'item'   => $item->toArray(),
            'status' => $status,
        ]);
    }

    /**
     * POST /distribution/trips/{id}/driver-accept
     * Formal driver acceptance — 3 mandatory confirmations (ADR-DIST-007).
     * Transitions trip to 'driver_accepted' or 'dispatch_blocked' (if discrepancy).
     */
    public function driverAccept(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'products_accepted'   => ['required', 'boolean'],
            'custody_accepted'    => ['required', 'boolean'],
            'equipment_accepted'  => ['required', 'boolean'],
            'has_discrepancy'     => ['required', 'boolean'],
            'discrepancy_notes'   => ['nullable', 'string', 'max:1000'],
        ]);

        if (!$data['products_accepted'] || !$data['custody_accepted'] || !$data['equipment_accepted']) {
            return response()->json([
                'message' => 'All three confirmations (products, custody, equipment) are required before acceptance.',
            ], 422);
        }

        $trip = DistributionTrip::findOrFail($id);

        try {
            $trip = $this->handoverService->driverAcceptTrip(
                $trip,
                (bool) $data['products_accepted'],
                (bool) $data['custody_accepted'],
                (bool) $data['equipment_accepted'],
                (bool) $data['has_discrepancy'],
                $data['discrepancy_notes'] ?? null,
                $request->user()->id,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $message = $data['has_discrepancy']
            ? 'Discrepancy reported. Trip has been blocked pending supervisor resolution.'
            : 'Driver acceptance confirmed. Trip is ready for dispatch authorization.';

        return response()->json([
            'message'             => $message,
            'trip'                => [
                'id'                  => $trip->id,
                'status'              => $trip->status,
                'driver_acceptance_at' => $trip->driver_acceptance_at,
                'has_discrepancy'     => $trip->has_discrepancy,
            ],
        ]);
    }

    /**
     * POST /distribution/trips/{id}/dispatch
     * Authorize dispatch — validates all conditions then advances trip to 'dispatched'.
     * Kept for backward compatibility with trips in legacy ready_for_dispatch status.
     */
    public function dispatch(Request $request, string $id): JsonResponse
    {
        $trip = DistributionTrip::findOrFail($id);

        try {
            $trip = $this->handoverService->authorizeDispatch($trip, $request->user()->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Trip dispatched successfully.',
            'trip'    => [
                'id'           => $trip->id,
                'status'       => $trip->status,
                'dispatched_at' => $trip->dispatched_at,
            ],
        ]);
    }
}

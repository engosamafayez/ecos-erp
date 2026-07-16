<?php

declare(strict_types=1);

namespace Modules\Operations\Distribution\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Operations\Distribution\Application\Services\ManifestRegenerationService;
use Modules\Operations\Distribution\Application\Services\OrderDistributionSyncService;
use Modules\Operations\Distribution\Domain\Models\DistributionTrip;

class OrderDistributionSyncController
{
    public function __construct(
        private readonly OrderDistributionSyncService $sync,
        private readonly ManifestRegenerationService  $regeneration,
    ) {}

    /**
     * GET /orders/{orderId}/distribution-stage
     *
     * Returns the operational Distribution stage for an order.
     * Returns null data if the order is not in any active trip.
     */
    public function getOrderStage(Request $request, string $orderId): JsonResponse
    {
        $stage = $this->sync->getOrderOperationalStage($orderId);

        return response()->json([
            'data'    => $stage,
            'message' => $stage ? 'Order is in an active distribution stage.' : 'Order is not in any active distribution stage.',
        ]);
    }

    /**
     * GET /orders/{orderId}/distribution-sync-history
     *
     * Returns the synchronization event history for an order.
     */
    public function getSyncHistory(Request $request, string $orderId): JsonResponse
    {
        $history = $this->sync->getSyncHistory($orderId);

        return response()->json([
            'data'    => $history,
            'message' => 'Distribution sync history retrieved.',
        ]);
    }

    /**
     * POST /distribution/trips/{tripId}/regenerate-manifest
     *
     * Supervisor action: regenerate the loading manifest after an order was modified.
     */
    public function regenerateManifest(Request $request, string $tripId): JsonResponse
    {
        $trip = DistributionTrip::where('id', $tripId)
            ->where('company_id', Auth::user()?->company_id)
            ->firstOrFail();

        $orderId  = (int) $request->input('order_id', 0);
        $userId   = (int) Auth::id();

        $manifest = $this->regeneration->regenerate($trip, $orderId, $userId);

        return response()->json([
            'data'    => [
                'manifest_id'     => $manifest->id,
                'total_products'  => $manifest->total_products,
                'status'          => $manifest->status,
                'items_count'     => $manifest->items->count(),
            ],
            'message' => 'Loading manifest regenerated successfully.',
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Modules\Operations\Distribution\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Operations\Distribution\Application\Services\LoadingWorkspaceService;
use Modules\Operations\Distribution\Domain\Models\DistributionLoadingManifest;
use Modules\Operations\Distribution\Domain\Models\DistributionLoadingManifestItem;
use RuntimeException;

class LoadingManifestController extends Controller
{
    public function __construct(
        private readonly LoadingWorkspaceService $workspaceService,
    ) {}

    /**
     * GET /distribution/manifests/{id}
     * Full manifest with all items + order breakdowns per product.
     */
    public function show(int $id): JsonResponse
    {
        $manifest = DistributionLoadingManifest::with(['items', 'trip'])->findOrFail($id);

        return response()->json([
            'manifest' => $this->formatManifest($manifest),
        ]);
    }

    /**
     * POST /distribution/manifests/{id}/start
     * Mark manifest as in_progress and record warehouse user.
     */
    public function start(Request $request, int $id): JsonResponse
    {
        $manifest = DistributionLoadingManifest::with('items')->findOrFail($id);

        $manifest = $this->workspaceService->startLoading($manifest, $request->user()->id);

        return response()->json(['manifest' => $this->formatManifest($manifest)]);
    }

    /**
     * POST /distribution/manifests/{id}/items/{itemId}/confirm
     * Confirm actual loaded quantity for one product.
     */
    public function confirmItem(Request $request, int $id, int $itemId): JsonResponse
    {
        $data = $request->validate([
            'loaded_qty' => ['required', 'numeric', 'min:0'],
        ]);

        $item = DistributionLoadingManifestItem::where('loading_manifest_id', $id)->findOrFail($itemId);

        try {
            $item = $this->workspaceService->confirmProduct($item, (float) $data['loaded_qty'], $request->user()->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $manifest = DistributionLoadingManifest::with('items')->findOrFail($id);

        return response()->json([
            'item'     => $item->toArray(),
            'manifest' => $this->formatManifest($manifest),
        ]);
    }

    /**
     * POST /distribution/manifests/{id}/items/{itemId}/resolve-shortage
     * Record the shortage resolution choice.
     */
    public function resolveShortage(Request $request, int $id, int $itemId): JsonResponse
    {
        $data = $request->validate([
            'resolution' => ['required', 'in:priority_allocation,manual_selection,return_preparation,send_manufacturing,delay_orders'],
            'notes'      => ['nullable', 'string', 'max:500'],
        ]);

        $item = DistributionLoadingManifestItem::where('loading_manifest_id', $id)->findOrFail($itemId);

        try {
            $item = $this->workspaceService->resolveShortage(
                $item,
                $data['resolution'],
                $data['notes'] ?? null,
                $request->user()->id,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['item' => $item->toArray()]);
    }

    /**
     * POST /distribution/manifests/{id}/complete
     * Complete loading — validates no pending items, advances trip to ready_for_dispatch.
     */
    public function complete(Request $request, int $id): JsonResponse
    {
        $manifest = DistributionLoadingManifest::with('items')->findOrFail($id);

        try {
            $manifest = $this->workspaceService->completeLoading($manifest, $request->user()->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message'  => 'Loading complete. Trip is ready for dispatch.',
            'manifest' => $this->formatManifest($manifest),
        ]);
    }

    /**
     * GET /distribution/manifests/{id}/product-breakdown/{itemId}
     * Which orders (and qty) contribute to a specific product in the manifest.
     */
    public function productBreakdown(int $id, int $itemId): JsonResponse
    {
        $item = DistributionLoadingManifestItem::where('loading_manifest_id', $id)->findOrFail($itemId);

        $manifest = DistributionLoadingManifest::findOrFail($id);

        $breakdown = \Illuminate\Support\Facades\DB::table('distribution_trip_orders as dto')
            ->join('order_lines as ol', function ($j) use ($item) {
                $j->on('ol.order_id', '=', 'dto.order_id')
                  ->where('ol.product_id', '=', $item->product_id);
            })
            ->join('orders as o', 'o.id', '=', 'dto.order_id')
            ->where('dto.distribution_trip_id', $manifest->distribution_trip_id)
            ->whereNull('ol.deleted_at')
            ->select([
                'o.id as order_id',
                'o.order_number',
                'o.grand_total',
                'ol.quantity',
            ])
            ->orderBy('o.order_number')
            ->get();

        return response()->json([
            'item'      => $item->toArray(),
            'breakdown' => $breakdown->values(),
        ]);
    }

    private function formatManifest(DistributionLoadingManifest $manifest): array
    {
        $pending         = $manifest->items->where('status', 'pending')->count();
        $unresolved      = $manifest->items->where('status', 'shortage')->whereNull('shortage_resolution')->count();

        return [
            'id'                  => $manifest->id,
            'distribution_trip_id' => $manifest->distribution_trip_id,
            'status'              => $manifest->status,
            'total_products'      => $manifest->total_products,
            'confirmed_products'  => $manifest->confirmed_products,
            'shortage_products'   => $manifest->shortage_products,
            'pending_products'    => $pending,
            'unresolved_shortages' => $unresolved,
            'can_complete'        => $pending === 0 && $unresolved === 0,
            'started_at'          => $manifest->started_at,
            'completed_at'        => $manifest->completed_at,
            'items'               => $manifest->items->map(fn ($i) => [
                'id'                  => $i->id,
                'product_id'          => $i->product_id,
                'product_name'        => $i->product_name,
                'product_sku'         => $i->product_sku,
                'required_qty'        => $i->required_qty,
                'loaded_qty'          => $i->loaded_qty,
                'shortage_qty'        => $i->shortage_qty,
                'unit'                => $i->unit,
                'status'              => $i->status,
                'shortage_resolution' => $i->shortage_resolution,
                'shortage_notes'      => $i->shortage_notes,
                'confirmed_at'        => $i->confirmed_at,
                'driver_received_qty' => $i->driver_received_qty,
                'driver_status'       => $i->driver_status,
                'driver_confirmed_at' => $i->driver_confirmed_at,
            ])->values(),
        ];
    }
}

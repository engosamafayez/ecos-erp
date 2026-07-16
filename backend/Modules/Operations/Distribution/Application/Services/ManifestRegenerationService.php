<?php

declare(strict_types=1);

namespace Modules\Operations\Distribution\Application\Services;

use Illuminate\Support\Facades\DB;
use Modules\Operations\Distribution\Domain\Models\DistributionLoadingManifest;
use Modules\Operations\Distribution\Domain\Models\DistributionLoadingManifestItem;
use Modules\Operations\Distribution\Domain\Models\DistributionTrip;
use RuntimeException;

class ManifestRegenerationService
{
    public function __construct(
        private readonly TripAuditService $audit,
        private readonly OrderDistributionSyncService $sync,
    ) {}

    /**
     * Regenerate the loading manifest for a trip by deleting existing manifest items
     * and re-aggregating from the current order lines.
     *
     * Only allowed when the trip is in 'approved' (awaiting loading) or 'loading' stage.
     */
    public function regenerate(DistributionTrip $trip, int $orderId, int $userId): DistributionLoadingManifest
    {
        $allowedStatuses = ['approved', 'loading', 'loading_completed'];
        if (!in_array($trip->status, $allowedStatuses, true)) {
            throw new RuntimeException(
                "Manifest regeneration is only allowed for trips in Approved or Loading stages. Current status: {$trip->status}"
            );
        }

        return DB::transaction(function () use ($trip, $orderId, $userId) {
            $manifest = DistributionLoadingManifest::where('distribution_trip_id', $trip->id)->first();

            if ($manifest === null) {
                throw new RuntimeException('No loading manifest found for this trip. Generate a manifest first.');
            }

            // Delete all existing manifest items (re-aggregation follows).
            DistributionLoadingManifestItem::where('loading_manifest_id', $manifest->id)->delete();

            // Re-aggregate from current order lines.
            $lines = DB::table('distribution_trip_orders as dto')
                ->join('order_lines as ol', 'ol.order_id', '=', 'dto.order_id')
                ->join('products as p', 'p.id', '=', 'ol.product_id')
                ->where('dto.distribution_trip_id', $trip->id)
                ->whereNull('ol.deleted_at')
                ->select([
                    'ol.product_id',
                    DB::raw('MAX(p.name) as product_name'),
                    DB::raw('MAX(p.sku) as product_sku'),
                    DB::raw('SUM(ol.quantity) as required_qty'),
                ])
                ->groupBy('ol.product_id')
                ->orderBy('product_name')
                ->get();

            if ($lines->isEmpty()) {
                throw new RuntimeException('No order lines found for this trip after regeneration.');
            }

            $items = $lines->map(fn ($row) => [
                'loading_manifest_id' => $manifest->id,
                'product_id'          => $row->product_id,
                'product_name'        => $row->product_name,
                'product_sku'         => $row->product_sku ?? null,
                'required_qty'        => (float) $row->required_qty,
                'unit'                => 'unit',
                'status'              => 'pending',
                'created_at'          => now(),
                'updated_at'          => now(),
            ])->toArray();

            DistributionLoadingManifestItem::insert($items);

            $manifest->update([
                'status'             => 'pending',
                'total_products'     => $lines->count(),
                'confirmed_products' => 0,
                'shortage_products'  => 0,
                'completed_at'       => null,
            ]);

            // Record audit entry on the trip.
            $this->audit->record(
                $trip->id,
                'manifest_regenerated',
                $trip->status,
                $trip->status,
                $userId,
                "Manifest regenerated after order #{$orderId} was modified.",
            );

            // Record sync event on the order.
            $stageInfo = $this->sync->getOrderOperationalStage($orderId);
            $this->sync->recordSyncEvent(
                $orderId,
                $trip->id,
                'manifest_regenerated',
                $stageInfo['stage'] ?? $trip->status,
                [],
                [],
                ['manifest_items' => $lines->count()],
                $userId,
                manifestRegenerated: true,
                notes: "Manifest regenerated for trip {$trip->trip_number}.",
            );

            return $manifest->fresh('items');
        });
    }
}

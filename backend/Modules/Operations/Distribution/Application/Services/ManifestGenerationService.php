<?php

declare(strict_types=1);

namespace Modules\Operations\Distribution\Application\Services;

use Illuminate\Support\Facades\DB;
use Modules\Operations\Distribution\Domain\Models\DistributionLoadingManifest;
use Modules\Operations\Distribution\Domain\Models\DistributionLoadingManifestItem;
use Modules\Operations\Distribution\Domain\Models\DistributionTrip;
use RuntimeException;

class ManifestGenerationService
{
    /**
     * Aggregate all order lines for a trip into a loading manifest.
     * Each unique product gets one manifest item with SUM(quantity).
     */
    public function generate(DistributionTrip $trip, int $approvedByUserId): DistributionLoadingManifest
    {
        if ($trip->orders_count === 0) {
            throw new RuntimeException('Trip has no orders — cannot generate manifest.');
        }

        if (DistributionLoadingManifest::where('distribution_trip_id', $trip->id)->exists()) {
            throw new RuntimeException('A loading manifest already exists for this trip.');
        }

        return DB::transaction(function () use ($trip, $approvedByUserId) {
            $lines = $this->aggregateProducts($trip->id);

            if ($lines->isEmpty()) {
                throw new RuntimeException('No order lines found for this trip — manifest cannot be generated.');
            }

            $manifest = DistributionLoadingManifest::create([
                'distribution_trip_id' => $trip->id,
                'preparation_wave_id'  => $trip->preparation_wave_id,
                'company_id'           => $trip->company_id,
                'status'               => 'pending',
                'total_products'       => $lines->count(),
                'approved_by'          => $approvedByUserId,
            ]);

            $items = $lines->map(fn ($row) => [
                'loading_manifest_id' => $manifest->id,
                'product_id'          => $row->product_id,
                'product_name'        => $row->product_name,
                'product_sku'         => $row->product_sku ?? null,
                'required_qty'        => (float) $row->required_qty,
                'unit'                => $row->unit ?? 'unit',
                'status'              => 'pending',
                'created_at'          => now(),
                'updated_at'          => now(),
            ])->toArray();

            DistributionLoadingManifestItem::insert($items);

            return $manifest->load('items');
        });
    }

    /**
     * Aggregate order line quantities by product across all orders in the trip.
     */
    private function aggregateProducts(string $tripId)
    {
        return DB::table('distribution_trip_orders as dto')
            ->join('order_lines as ol', 'ol.order_id', '=', 'dto.order_id')
            ->join('products as p', 'p.id', '=', 'ol.product_id')
            ->where('dto.distribution_trip_id', $tripId)
            ->whereNull('ol.deleted_at')
            ->select([
                'ol.product_id',
                DB::raw('MAX(p.name) as product_name'),
                DB::raw('MAX(p.sku) as product_sku'),
                DB::raw('SUM(ol.quantity) as required_qty'),
                DB::raw("'unit' as unit"),
            ])
            ->groupBy('ol.product_id')
            ->orderBy('product_name')
            ->get();
    }
}

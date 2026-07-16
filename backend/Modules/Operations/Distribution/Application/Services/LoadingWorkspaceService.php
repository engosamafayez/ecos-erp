<?php

declare(strict_types=1);

namespace Modules\Operations\Distribution\Application\Services;

use Illuminate\Support\Facades\DB;
use Modules\Operations\Distribution\Application\Services\TripAuditService;
use Modules\Operations\Distribution\Domain\Models\DistributionLoadingManifest;
use Modules\Operations\Distribution\Domain\Models\DistributionLoadingManifestItem;
use Modules\Operations\Distribution\Domain\Models\DistributionTrip;
use RuntimeException;

class LoadingWorkspaceService
{
    public function __construct(private readonly TripAuditService $audit) {}

    /**
     * Confirm a single product line: set actual loaded_qty, compute shortage.
     */
    public function confirmProduct(
        DistributionLoadingManifestItem $item,
        float $loadedQty,
        int $userId
    ): DistributionLoadingManifestItem {
        if ($item->status === 'confirmed') {
            throw new RuntimeException('Product is already confirmed.');
        }

        $shortage    = $item->required_qty - $loadedQty;
        $hasShortage = $shortage > 0.001;

        $item->update([
            'loaded_qty'   => $loadedQty,
            'status'       => $hasShortage ? 'shortage' : 'confirmed',
            'shortage_qty' => $hasShortage ? $shortage : null,
            'confirmed_by' => $userId,
            'confirmed_at' => now(),
        ]);

        $this->syncManifestCounters($item->loading_manifest_id);

        return $item->fresh();
    }

    /**
     * Resolve a shortage for a product line (choose strategy).
     * Allowed resolutions: priority_allocation / manual_selection / return_preparation / send_manufacturing / delay_orders
     */
    public function resolveShortage(
        DistributionLoadingManifestItem $item,
        string $resolution,
        ?string $notes,
        int $userId
    ): DistributionLoadingManifestItem {
        if (!$item->isShortage()) {
            throw new RuntimeException('Item does not have a shortage.');
        }

        $allowed = ['priority_allocation', 'manual_selection', 'return_preparation', 'send_manufacturing', 'delay_orders'];
        if (!in_array($resolution, $allowed, true)) {
            throw new RuntimeException("Invalid shortage resolution: {$resolution}");
        }

        $item->update([
            'shortage_resolution' => $resolution,
            'shortage_notes'      => $notes,
        ]);

        $this->syncManifestCounters($item->loading_manifest_id);

        return $item->fresh();
    }

    /**
     * Mark all products confirmed (no remaining pending/unresolved shortages)
     * and advance the trip to ready_for_dispatch.
     */
    public function completeLoading(DistributionLoadingManifest $manifest, int $userId): DistributionLoadingManifest
    {
        $pending  = $manifest->items()->where('status', 'pending')->count();
        $shortage = $manifest->items()->where('status', 'shortage')->whereNull('shortage_resolution')->count();

        if ($pending > 0) {
            throw new RuntimeException("{$pending} product(s) have not been confirmed yet.");
        }

        if ($shortage > 0) {
            throw new RuntimeException("{$shortage} shortage(s) require a resolution before completing.");
        }

        return DB::transaction(function () use ($manifest, $userId) {
            $manifest->update([
                'status'            => 'completed',
                'completed_at'      => now(),
                'warehouse_user_id' => $userId,
            ]);

            DistributionTrip::where('id', $manifest->distribution_trip_id)
                ->update(['status' => 'loading_completed']);

            $this->audit->record(
                $manifest->distribution_trip_id,
                'loading_completed',
                'loading',
                'loading_completed',
                $userId,
                'Warehouse loading completed — all products confirmed.',
            );

            return $manifest->fresh('items');
        });
    }

    /**
     * Start the loading session (set in_progress, record warehouse user).
     */
    public function startLoading(DistributionLoadingManifest $manifest, int $userId): DistributionLoadingManifest
    {
        if ($manifest->status !== 'pending') {
            return $manifest;
        }

        $manifest->update([
            'status'           => 'in_progress',
            'started_at'       => now(),
            'warehouse_user_id' => $userId,
        ]);

        return $manifest->fresh('items');
    }

    /**
     * Re-aggregate confirmed / shortage counters from items.
     */
    private function syncManifestCounters(int $manifestId): void
    {
        $stats = DB::table('distribution_loading_manifest_items')
            ->where('loading_manifest_id', $manifestId)
            ->selectRaw("
                SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed_count,
                SUM(CASE WHEN status = 'shortage' THEN 1 ELSE 0 END) as shortage_count
            ")
            ->first();

        DistributionLoadingManifest::where('id', $manifestId)->update([
            'confirmed_products' => (int) $stats->confirmed_count,
            'shortage_products'  => (int) $stats->shortage_count,
        ]);
    }
}

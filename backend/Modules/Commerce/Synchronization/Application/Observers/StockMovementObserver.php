<?php

declare(strict_types=1);

namespace Modules\Commerce\Synchronization\Application\Observers;

use Modules\Commerce\ProductMappings\Domain\Models\ProductMapping;
use Modules\Commerce\Synchronization\Application\Jobs\InventorySyncJob;
use Modules\Inventory\StockLedger\Domain\Models\StockMovement;

final class StockMovementObserver
{
    public function created(StockMovement $movement): void
    {
        $mappings = ProductMapping::query()
            ->with('channel.credential')
            ->where('product_id', $movement->product_id)
            ->whereNull('deleted_at')
            ->get();

        $stockQuantity = (float) $movement->balance_after;

        foreach ($mappings as $mapping) {
            /** @var ProductMapping $mapping */
            $channel = $mapping->channel;

            if ($channel === null || ! $channel->is_active || ! $channel->sync_stock) {
                continue;
            }

            $product = $movement->product;

            if ($product === null) {
                continue;
            }

            InventorySyncJob::dispatch($channel, $product, $stockQuantity);
        }
    }
}

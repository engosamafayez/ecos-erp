<?php

declare(strict_types=1);

namespace Modules\Commerce\Synchronization\Application\Observers;

use Modules\Commerce\ProductMappings\Domain\Models\ProductMapping;
use Modules\Commerce\Synchronization\Application\Jobs\PriceSyncJob;
use Modules\Commerce\Synchronization\Application\Jobs\ProductSyncJob;
use Modules\Inventory\Products\Domain\Models\Product;

final class ProductObserver
{
    public function updated(Product $product): void
    {
        $mappings = ProductMapping::query()
            ->with('channel.credential')
            ->where('product_id', $product->id)
            ->whereNull('deleted_at')
            ->get();

        foreach ($mappings as $mapping) {
            /** @var ProductMapping $mapping */
            $channel = $mapping->channel;

            if ($channel === null || ! $channel->is_active) {
                continue;
            }

            if ($channel->sync_products) {
                ProductSyncJob::dispatch($channel, $product);
            }

            if ($channel->sync_prices) {
                PriceSyncJob::dispatch($channel, $product);
            }
        }
    }
}

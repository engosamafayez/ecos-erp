<?php

declare(strict_types=1);

namespace Modules\Commerce\StockSync\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Illuminate\Support\Carbon;
use Modules\Commerce\Channels\Domain\Contracts\ChannelRepositoryInterface;
use Modules\Commerce\Channels\Domain\Exceptions\ChannelNotFoundException;
use Modules\Commerce\ProductMappings\Domain\Enums\SyncStatus;
use Modules\Commerce\ProductMappings\Domain\Models\ProductMapping;
use Modules\Commerce\StockSync\Application\Services\WooCommerceStockSyncer;
use Modules\Commerce\StockSync\Domain\Enums\StockSyncStatus;
use Modules\Commerce\StockSync\Domain\Models\StockSyncLog;
use Modules\Commerce\Synchronization\Application\Services\WooCommerceProductAvailabilityResolver;
use Modules\Inventory\Products\Domain\Models\Product;

/**
 * TASK-...-CONSOLIDATED-REMEDIATION-001-R1/R2 — manual/initial "resync now" trigger. Per §7,
 * an explicit resync always pushes the CURRENT canonical availability state (unlike the
 * domain-event pipeline, which only pushes on an actual change) — this is what lets a
 * newly-LIVE channel or an operator-triggered resync converge Woo even with no fresh
 * inventory movement. Reuses the same WooCommerceProductAvailabilityResolver — one formula,
 * never duplicated.
 */
final class SyncStockAction extends BaseAction
{
    public function __construct(
        private readonly ChannelRepositoryInterface $channels,
        private readonly WooCommerceStockSyncer $syncer,
        private readonly WooCommerceProductAvailabilityResolver $availability,
    ) {}

    /**
     * Arguments:
     *   [0] channel_id (string)
     *   [1] product_ids (array<string>|null) — null syncs all mapped products for the channel
     */
    public function execute(mixed ...$arguments): OperationResult
    {
        $channelId = (string) ($arguments[0] ?? '');
        /** @var array<string>|null $productIds */
        $productIds = $arguments[1] ?? null;

        $channel = $this->channels->findById($channelId);

        if ($channel === null) {
            throw new ChannelNotFoundException($channelId);
        }

        $credential = $channel->credential;

        if ($credential === null) {
            return OperationResult::failure('No credentials configured for this channel.');
        }

        $query = ProductMapping::query()
            ->whereNull('deleted_at')
            ->where('channel_id', $channelId);

        if ($productIds !== null) {
            $query->whereIn('product_id', $productIds);
        }

        $mappings = $query->get();

        $syncedCount = 0;
        $errorCount = 0;
        $now = Carbon::now();

        foreach ($mappings as $mapping) {
            /** @var ProductMapping $mapping */
            $product = Product::find($mapping->product_id);

            if ($product === null) {
                $mapping->update(['sync_status' => SyncStatus::Error->value]);
                $errorCount++;

                continue;
            }

            $status = $this->availability->resolve($product);

            // Keep ECOS's own record current even on a manual resync — the same field the
            // domain-event pipeline uses as its change-detector.
            if ($product->stock_status !== $status) {
                $product->update(['stock_status' => $status->value]);
            }

            $success = $this->syncer->updateAvailability(
                $channel->store_url,
                $credential->consumer_key,
                $credential->consumer_secret,
                $mapping->external_product_id,
                $status,
            );

            $syncStatus = $success ? StockSyncStatus::Success : StockSyncStatus::Error;
            $message = $success
                ? 'Availability updated successfully.'
                : 'Failed to update availability on WooCommerce.';

            StockSyncLog::create([
                'channel_id' => $channelId,
                'product_id' => $mapping->product_id,
                'product_mapping_id' => $mapping->id,
                'stock_status' => $status->value,
                'sync_status' => $syncStatus->value,
                'response_message' => $message,
                'synced_at' => $now,
            ]);

            if ($success) {
                $mapping->update([
                    'sync_status' => SyncStatus::Synced->value,
                    'last_sync_at' => $now,
                ]);
                $syncedCount++;
            } else {
                $mapping->update(['sync_status' => SyncStatus::Error->value]);
                $errorCount++;
            }
        }

        return OperationResult::success(
            [
                'synced' => $syncedCount,
                'errors' => $errorCount,
                'total' => $mappings->count(),
            ],
            "Sync complete. {$syncedCount} synced, {$errorCount} errors.",
        );
    }
}

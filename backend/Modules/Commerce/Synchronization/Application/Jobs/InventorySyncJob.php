<?php

declare(strict_types=1);

namespace Modules\Commerce\Synchronization\Application\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Commerce\ProductMappings\Domain\Models\ProductMapping;
use Modules\Commerce\StockSync\Application\Services\WooCommerceStockSyncer;
use Modules\Commerce\Synchronization\Application\Services\SyncLogService;
use Modules\Commerce\Synchronization\Domain\Enums\SyncDirection;
use Modules\Commerce\Synchronization\Domain\Enums\SyncEntityType;
use Modules\Commerce\Synchronization\Domain\Enums\SyncStatus;
use Modules\Inventory\Products\Domain\Models\Product;
use Throwable;

final class InventorySyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    // Ensure this job only runs after the DB transaction that recorded the stock movement
    // has committed. Without this, the queue worker could read stale or rolled-back data.
    public bool $afterCommit = true;

    public function __construct(
        private readonly Channel $channel,
        private readonly Product $product,
        private readonly float $stockQuantity,
    ) {}

    public function handle(SyncLogService $logService, WooCommerceStockSyncer $syncer): void
    {
        $log = $logService->createLog(
            $this->channel,
            SyncEntityType::Inventory,
            SyncDirection::Outbound,
            'inventory.sync',
            $this->product->id,
            SyncStatus::Processing,
            ['product_id' => $this->product->id, 'stock_quantity' => $this->stockQuantity],
        );

        $mapping = ProductMapping::query()
            ->where('product_id', $this->product->id)
            ->where('channel_id', $this->channel->id)
            ->first();

        if ($mapping === null) {
            $logService->markFailed($log, 'No product mapping found for this channel.');
            return;
        }

        $credential = $this->channel->credential;

        if ($credential === null) {
            $logService->markFailed($log, 'No credentials configured for this channel.');
            return;
        }

        try {
            $success = $syncer->updateStock(
                $this->channel->store_url,
                $credential->consumer_key,
                $credential->consumer_secret,
                $mapping->external_product_id,
                $this->stockQuantity,
            );

            if ($success) {
                $logService->markSuccess($log, ['stock_quantity' => $this->stockQuantity], $this->channel);
            } else {
                $logService->markFailed($log, 'WooCommerce stock update request failed.', null, $this->channel);
            }
        } catch (Throwable $e) {
            $logService->markFailed($log, $e->getMessage(), null, $this->channel);
            throw $e;
        }
    }
}

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
use Modules\Commerce\Synchronization\Application\Services\SyncLogService;
use Modules\Commerce\Synchronization\Application\Services\WooOutboundCommandDispatcher;
use Modules\Commerce\Synchronization\Domain\Enums\SyncDirection;
use Modules\Commerce\Synchronization\Domain\Enums\SyncEntityType;
use Modules\Commerce\Synchronization\Domain\Enums\SyncStatus;
use Modules\Inventory\Products\Domain\Models\Product;
use Throwable;

/**
 * TASK-...-CONSOLIDATED-REMEDIATION-001-R2-R1 — transport (direct Woo REST vs the paired
 * Connector plugin) is decided by WooOutboundCommandDispatcher, not here; this job only shapes
 * the ECOS-owned field payload, unchanged.
 */
final class ProductSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        private readonly Channel $channel,
        private readonly Product $product,
    ) {}

    public function handle(SyncLogService $logService, WooOutboundCommandDispatcher $dispatcher): void
    {
        $log = $logService->createLog(
            $this->channel,
            SyncEntityType::Product,
            SyncDirection::Outbound,
            'product.sync',
            $this->product->id,
            SyncStatus::Processing,
            ['product_id' => $this->product->id, 'sku' => $this->product->sku],
        );

        $mapping = ProductMapping::query()
            ->where('product_id', $this->product->id)
            ->where('channel_id', $this->channel->id)
            ->first();

        if ($mapping === null) {
            $logService->markFailed($log, 'No product mapping found for this channel.');

            return;
        }

        if ($this->channel->credential === null) {
            $logService->markFailed($log, 'No credentials configured for this channel.');

            return;
        }

        try {
            $result = $dispatcher->put($this->channel, 'products', $mapping->external_product_id, [
                'name' => $this->product->name,
                'sku' => $this->product->sku,
                'description' => $this->product->description ?? '',
                'short_description' => $this->product->short_description ?? '',
            ]);

            if ($result->ok) {
                $logService->markSuccess($log, ['status' => $result->status], $this->channel);
            } else {
                $logService->markFailed($log, (string) $result->error, null, $this->channel);
            }
        } catch (Throwable $e) {
            $logService->markFailed($log, $e->getMessage(), null, $this->channel);
            throw $e;
        }
    }
}

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
 * TASK-...-CONSOLIDATED-REMEDIATION-001-R2-R1 — transport decided by
 * WooOutboundCommandDispatcher, not here.
 */
final class PriceSyncJob implements ShouldQueue
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
            SyncEntityType::Price,
            SyncDirection::Outbound,
            'price.sync',
            $this->product->id,
            SyncStatus::Processing,
            ['product_id' => $this->product->id, 'regular_price' => $this->product->regular_price, 'sale_price' => $this->product->sale_price],
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

        $payload = [];

        if ($this->product->regular_price !== null) {
            $payload['regular_price'] = (string) $this->product->regular_price;
        }

        if ($this->product->sale_price !== null) {
            $payload['sale_price'] = (string) $this->product->sale_price;
        }

        if (empty($payload)) {
            $logService->markFailed($log, 'Product has no price to sync.');

            return;
        }

        try {
            $result = $dispatcher->put($this->channel, 'products', $mapping->external_product_id, $payload);

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

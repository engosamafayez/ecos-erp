<?php

declare(strict_types=1);

namespace Modules\Commerce\Synchronization\Application\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Commerce\ProductMappings\Domain\Models\ProductMapping;
use Modules\Commerce\StockSync\Application\Services\WooCommerceStockSyncer;
use Modules\Commerce\Synchronization\Application\Services\SyncLogService;
use Modules\Commerce\Synchronization\Domain\Enums\SyncDirection;
use Modules\Commerce\Synchronization\Domain\Enums\SyncEntityType;
use Modules\Commerce\Synchronization\Domain\Enums\SyncStatus;
use Modules\Inventory\Products\Domain\Enums\ProductStockStatus;
use Modules\Inventory\Products\Domain\Models\Product;
use Throwable;

/**
 * TASK-...-CONSOLIDATED-REMEDIATION-001-R1 — replaces InventorySyncJob (deleted). Pushes the
 * absolute ECOS-computed availability STATE (instock/outofstock) to WooCommerce — never a
 * finished-product quantity. See WooCommerceProductAvailabilityResolver for the one canonical
 * authority this status must already have been resolved from before dispatch.
 */
final class ProductAvailabilitySyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        private readonly Channel $channel,
        private readonly Product $product,
        private readonly ProductStockStatus $status,
        // ── correlation and event metadata, mirroring the domain-event pipeline's other jobs ──
        private readonly ?string $correlationId = null,
        private readonly ?string $eventName = null,
        private readonly ?int $eventVersion = null,
        private readonly ?string $warehouseId = null,
    ) {
        // Ensure this job only runs after the DB transaction that recorded the inventory
        // movement has committed. Without this, the queue worker could resolve availability
        // against stale (pre-commit) data.
        $this->afterCommit = true;
    }

    public function handle(SyncLogService $logService, WooCommerceStockSyncer $syncer): void
    {
        $startedAt = hrtime(true);

        $log = $logService->createLog(
            channel: $this->channel,
            entityType: SyncEntityType::Inventory,
            direction: SyncDirection::Outbound,
            action: 'availability.sync',
            entityId: $this->product->id,
            status: SyncStatus::Processing,
            requestPayload: [
                'product_id' => $this->product->id,
                'stock_status' => $this->status->value,
            ],
            correlationId: $this->correlationId,
            eventName: $this->eventName,
            eventVersion: $this->eventVersion,
            warehouseId: $this->warehouseId,
        );

        $mapping = ProductMapping::query()
            ->where('product_id', $this->product->id)
            ->where('channel_id', $this->channel->id)
            ->first();

        if ($mapping === null) {
            $logService->markFailed($log, 'No product mapping found for this channel.');
            $this->logStructured('failed', 'no_mapping', null, $startedAt);

            return;
        }

        $credential = $this->channel->credential;

        if ($credential === null) {
            $logService->markFailed($log, 'No credentials configured for this channel.');
            $this->logStructured('failed', 'no_credentials', null, $startedAt);

            return;
        }

        try {
            $success = $syncer->updateAvailability(
                $this->channel->store_url,
                $credential->consumer_key,
                $credential->consumer_secret,
                $mapping->external_product_id,
                $this->status,
            );

            $durationMs = $this->elapsedMs($startedAt);

            if ($success) {
                $logService->markSuccess($log, ['stock_status' => $this->status->value], $this->channel, $durationMs);
                $this->logStructured('success', null, $durationMs, $startedAt);
            } else {
                $logService->markFailed($log, 'WooCommerce availability update request failed.', null, $this->channel, $durationMs);
                $this->logStructured('failed', 'api_rejected', $durationMs, $startedAt);
            }
        } catch (Throwable $e) {
            $durationMs = $this->elapsedMs($startedAt);
            $logService->markFailed($log, $e->getMessage(), null, $this->channel, $durationMs);
            $this->logStructured('failed', $e->getMessage(), $durationMs, $startedAt);
            throw $e;
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function logStructured(string $result, ?string $error, ?int $durationMs, int $startedAt): void
    {
        Log::channel('daily')->info('[ProductAvailabilitySyncJob] Completed', [
            'correlation_id' => $this->correlationId,
            'event_name' => $this->eventName,
            'event_version' => $this->eventVersion,
            'channel' => $this->channel->name,
            'product' => $this->product->id,
            'warehouse' => $this->warehouseId,
            'direction' => SyncDirection::Outbound->value,
            'stock_status' => $this->status->value,
            'result' => $result,
            'error' => $error,
            'duration_ms' => $durationMs ?? $this->elapsedMs($startedAt),
        ]);
    }

    private function elapsedMs(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}

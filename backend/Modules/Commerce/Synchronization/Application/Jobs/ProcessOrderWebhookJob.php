<?php

declare(strict_types=1);

namespace Modules\Commerce\Synchronization\Application\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Commerce\OrderImport\Application\Services\WooCommerceOrderImporter;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Synchronization\Application\Services\SyncLogService;
use Modules\Commerce\Synchronization\Domain\Enums\SyncDirection;
use Modules\Commerce\Synchronization\Domain\Enums\SyncEntityType;
use Modules\Commerce\Synchronization\Domain\Enums\SyncStatus;
use Throwable;

final class ProcessOrderWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    /** @param array<string, mixed> $payload */
    public function __construct(
        private readonly Channel $channel,
        private readonly array $payload,
        private readonly string $webhookAction,
    ) {}

    public function handle(SyncLogService $logService, WooCommerceOrderImporter $importer): void
    {
        $externalId = (string) ($this->payload['id'] ?? '');

        $log = $logService->createLog(
            $this->channel,
            SyncEntityType::Order,
            SyncDirection::Inbound,
            $this->webhookAction,
            $externalId,
            SyncStatus::Processing,
            $this->payload,
        );

        try {
            $existingOrder = $externalId !== ''
                ? Order::query()
                    ->where('external_order_id', $externalId)
                    ->where('channel_id', $this->channel->id)
                    ->first()
                : null;

            if ($existingOrder !== null) {
                $wooStatus = (string) ($this->payload['status'] ?? '');
                $statusMap = [
                    'pending' => 'pending',
                    'processing' => 'processing',
                    'completed' => 'completed',
                    'cancelled' => 'cancelled',
                    'on-hold' => 'pending',
                    'refunded' => 'cancelled',
                    'failed' => 'cancelled',
                ];
                $newStatus = $statusMap[$wooStatus] ?? null;

                if ($newStatus !== null) {
                    $existingOrder->update(['status' => OrderStatus::from($newStatus)->value]);
                }

                $logService->markSuccess($log, ['message' => 'Order status updated.', 'order_id' => $existingOrder->id]);
            } else {
                $created = $importer->importSingle($this->channel, $this->payload);
                $logService->markSuccess($log, ['message' => $created ? 'Order created.' : 'Order skipped (no valid lines).']);
            }
        } catch (Throwable $e) {
            $logService->markFailed($log, $e->getMessage());
            throw $e;
        }
    }
}

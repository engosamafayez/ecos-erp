<?php

declare(strict_types=1);

namespace Modules\Commerce\Synchronization\Application\Jobs;

use BackedEnum;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Synchronization\Application\Services\EcosOrderStatusToWooTranslator;
use Modules\Commerce\Synchronization\Application\Services\SyncLogService;
use Modules\Commerce\Synchronization\Application\Services\WooOutboundCommandDispatcher;
use Modules\Commerce\Synchronization\Domain\Enums\SyncDirection;
use Modules\Commerce\Synchronization\Domain\Enums\SyncEntityType;
use Modules\Commerce\Synchronization\Domain\Enums\SyncStatus;
use Throwable;

/**
 * Pushes an ECOS order status change to WooCommerce.
 *
 * Dispatched by OrderObserver when an order with a known external_order_id
 * has its status changed. Maps ECOS OrderStatus values to WooCommerce status slugs.
 *
 * TASK-...-CONSOLIDATED-REMEDIATION-001-R2-R1 — transport decided by
 * WooOutboundCommandDispatcher, not here.
 */
final class OrderStatusSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        private readonly Channel $channel,
        private readonly Order $order,
    ) {}

    public function handle(SyncLogService $logService, EcosOrderStatusToWooTranslator $translator, WooOutboundCommandDispatcher $dispatcher): void
    {
        $log = $logService->createLog(
            $this->channel,
            SyncEntityType::Order,
            SyncDirection::Outbound,
            'order.status_sync',
            $this->order->id,
            SyncStatus::Processing,
            [
                'order_id' => $this->order->id,
                'external_order_id' => $this->order->external_order_id,
                'status' => $this->order->status instanceof BackedEnum
                    ? $this->order->status->value
                    : (string) $this->order->status,
            ],
        );

        if ($this->channel->credential === null) {
            $logService->markFailed($log, 'No credentials configured for this channel.', null, $this->channel);

            return;
        }

        $externalId = $this->order->external_order_id;

        if ($externalId === null || $externalId === '') {
            $logService->markFailed($log, 'Order has no external_order_id.', null, $this->channel);

            return;
        }

        $statusValue = $this->order->status instanceof BackedEnum
            ? $this->order->status->value
            : (string) $this->order->status;

        // TASK-...-024/-025 (W4/P0) — centralized outbound status mapping via the fixed
        // EcosOrderStatusToWooTranslator (the previous inline STATUS_MAP was keyed on
        // pre-ADR-042 status strings and silently failed for every status but Cancelled).
        // tryFrom() rather than a bare cast handles the defensive case of a queued payload whose
        // status isn't a valid OrderStatus backing value.
        $ecosStatus = OrderStatus::tryFrom($statusValue);
        $wooStatus = $ecosStatus !== null ? $translator->translate($ecosStatus) : null;

        if ($wooStatus === null) {
            $logService->markFailed(
                $log,
                "No WooCommerce mapping for ECOS status [{$statusValue}] — intentionally unmapped or invalid.",
                null,
                $this->channel,
            );

            return;
        }

        try {
            $result = $dispatcher->put($this->channel, 'orders', $externalId, ['status' => $wooStatus]);

            if ($result->ok) {
                $logService->markSuccess($log, ['woo_status' => $wooStatus, 'http_status' => $result->status], $this->channel);
            } else {
                $logService->markFailed($log, (string) $result->error, null, $this->channel);
            }
        } catch (Throwable $e) {
            $logService->markFailed($log, $e->getMessage(), null, $this->channel);
            throw $e;
        }
    }
}

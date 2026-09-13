<?php

declare(strict_types=1);

namespace Modules\Commerce\Synchronization\Application\Services;

use Illuminate\Support\Facades\Log;
use Modules\Commerce\ProductMappings\Domain\Models\ProductMapping;
use Modules\Commerce\Synchronization\Application\Jobs\ProductAvailabilitySyncJob;
use Modules\Inventory\DomainEvents\Contracts\DomainEvent;
use Modules\Inventory\Products\Domain\Enums\ProductStockStatus;
use Modules\Inventory\Products\Domain\Models\Product;

/**
 * Phase B — Channel Synchronization Service.
 *
 * Orchestrates the domain-event → WooCommerce availability-synchronization pipeline.
 *
 * TASK-...-CONSOLIDATED-REMEDIATION-001-R1 (CTO business-rule correction) — this used to
 * aggregate and push a finished-product QUANTITY (Σon_hand_qty). That contract is superseded:
 * WooCommerce must never receive or own a finished-product quantity. This service now:
 *   1. Determines whether the event carries a product_id (session-level events do not).
 *   2. Resolves the ONE canonical availability state for the product
 *      (WooCommerceProductAvailabilityResolver — itself backed by InventorySummaryService +
 *      ProductAvailability, never a Commerce-local formula).
 *   3. Skips entirely when that state has not actually changed since the last push
 *      (products.stock_status is both "what Woo was last told" and the change-detector — no
 *      new storage) — do not push on every raw-material movement if availability didn't move.
 *   4. Resolves which channels are LIVE, active, and have sync_stock enabled, with a mapping.
 *   5. Dispatches one ProductAvailabilitySyncJob per eligible channel — an absolute
 *      instock/outofstock state, never a quantity.
 *
 * This service MUST NOT contain inventory or recipe business logic — it consumes the
 * canonical availability answer, never recomputes one.
 * This service MUST NOT create StockMovement records or modify stock balances.
 * This service MUST NOT know the internals of any channel adapter.
 */
class ChannelSynchronizationService
{
    public function __construct(
        private readonly WooCommerceProductAvailabilityResolver $availability,
    ) {}

    /**
     * Entry point called by InventoryChannelSynchronizationListener for every
     * received domain event. Silently no-ops for events without a product_id
     * (e.g. InventoryCountApproved, which is session-scoped).
     */
    public function handleEvent(DomainEvent $event): void
    {
        $payload = $event->toArray();

        $productId = $payload['product_id'] ?? null;
        $warehouseId = $payload['warehouse_id'] ?? null;

        if (! is_string($productId) || $productId === '') {
            // Session-level events (InventoryCountApproved) have no product_id.
            // Per-product adjustments inside the session each emit InventoryStockAdjusted
            // which DOES carry a product_id. Nothing to dispatch here.
            Log::channel('daily')->info('[ChannelSync] Skipping session-level event — no product_id', [
                'correlation_id' => $event->correlationId(),
                'event_name' => $event->eventName(),
                'event_version' => $event->eventVersion(),
            ]);

            return;
        }

        $product = Product::find($productId);

        if ($product === null) {
            Log::channel('daily')->warning('[ChannelSync] Product not found — skipping sync', [
                'correlation_id' => $event->correlationId(),
                'event_name' => $event->eventName(),
                'product_id' => $productId,
            ]);

            return;
        }

        $newStatus = $this->availability->resolve($product);

        if ($product->stock_status === $newStatus) {
            Log::channel('daily')->info('[ChannelSync] Availability unchanged — nothing to push', [
                'correlation_id' => $event->correlationId(),
                'event_name' => $event->eventName(),
                'product_id' => $productId,
                'stock_status' => $newStatus->value,
            ]);

            return;
        }

        // products.stock_status is now ECOS-owned/outbound-only (see
        // WooCommerceProductImporter's corrected inbound handling) — updating it here is the
        // ECOS-side record of "what we last computed", which doubles as the change-detector
        // above. Never triggers ProductObserver's own sync (stock_status is not one of its
        // watched fields), so no duplicate dispatch loop.
        $product->update(['stock_status' => $newStatus->value]);

        $dispatchCount = $this->dispatchToChannels($event, $product, $newStatus, $warehouseId);

        Log::channel('daily')->info('[ChannelSync] Event processed', [
            'correlation_id' => $event->correlationId(),
            'event_name' => $event->eventName(),
            'event_version' => $event->eventVersion(),
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'stock_status' => $newStatus->value,
            'jobs_dispatched' => $dispatchCount,
        ]);
    }

    // ── Private orchestration ─────────────────────────────────────────────────

    /**
     * Find every LIVE, active channel with sync_stock enabled that has a mapping for this
     * product, then dispatch one ProductAvailabilitySyncJob per match.
     *
     * @return int number of jobs dispatched
     */
    private function dispatchToChannels(
        DomainEvent $event,
        Product $product,
        ProductStockStatus $status,
        ?string $warehouseId,
    ): int {
        $mappings = ProductMapping::query()
            ->with('channel.credential')
            ->where('product_id', $product->id)
            ->get();

        if ($mappings->isEmpty()) {
            return 0;
        }

        $dispatched = 0;

        foreach ($mappings as $mapping) {
            /** @var ProductMapping $mapping */
            $channel = $mapping->channel;

            if (! $this->shouldSync($channel)) {
                continue;
            }

            ProductAvailabilitySyncJob::dispatch(
                $channel,
                $product,
                $status,
                $event->correlationId(),
                $event->eventName(),
                $event->eventVersion(),
                $warehouseId,
            );

            $dispatched++;
        }

        return $dispatched;
    }

    /**
     * Verify that a channel is eligible for availability synchronization.
     *
     * Rules:
     *   - Channel must exist (not null / soft-deleted)
     *   - is_active must be true
     *   - sync_stock must be true
     *   - Channel::canSyncNow() — LIVE (TASK-...-WOO-04, 042A-R1 §5) AND, for a paired
     *     Channel, a genuinely connected Connector (TASK-...-CONSOLIDATED-REMEDIATION-001-
     *     R2-R1 §14: a Degraded/Disconnected paired Channel must not keep receiving pushes as
     *     though nothing changed).
     */
    private function shouldSync(mixed $channel): bool
    {
        if ($channel === null) {
            return false;
        }

        return $channel->is_active === true
            && $channel->sync_stock === true
            && $channel->canSyncNow();
    }
}

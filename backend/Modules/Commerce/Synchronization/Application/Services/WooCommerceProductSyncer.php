<?php

declare(strict_types=1);

namespace Modules\Commerce\Synchronization\Application\Services;

use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Commerce\ProductMappings\Domain\Enums\SyncStatus as MappingSyncStatus;
use Modules\Commerce\ProductMappings\Domain\Models\ProductMapping;
use Modules\Commerce\Synchronization\Application\Jobs\PriceSyncJob;
use Modules\Commerce\Synchronization\Application\Jobs\ProductSyncJob;
use Modules\Commerce\Synchronization\Domain\Enums\SyncDirection;
use Modules\Commerce\Synchronization\Domain\Enums\SyncEntityType;
use Modules\Commerce\Synchronization\Domain\Enums\SyncStatus;
use Modules\Inventory\Products\Domain\Models\Product;

/**
 * Handles inbound WooCommerce → ECOS product synchronization.
 *
 * TASK-ECOS-V1.1-WOO-03-PRODUCT-INBOUND-OWNERSHIP-PROTECTION — per ADR-003 §Decision-1 /
 * ADR-012 §8, restated and closed by architecture authority 042A-R1 §3: `name`, `description`,
 * `short_description`, `regular_price`, `sale_price` are ECOS-owned and are never written from
 * an inbound Woo event (this class used to write them directly — GAP-1 — it no longer does).
 * When Woo sends a DIFFERENT value for one of these fields on an already-mapped product, that
 * is drift: detected, logged via the existing SyncLogService as a distinct, queryable entry
 * (never silently applied, never silently discarded), and corrected by re-dispatching the
 * existing outbound ProductSyncJob/PriceSyncJob so the channel converges back to the ECOS
 * canonical value — the same "ERP always wins" rule ADR-003 already mandates outbound, applied
 * here as the corrective action rather than a passive log.
 *
 * Tenant safety (WOO-03 §7, same discipline as WOO-02): `products.sku` is globally unique
 * (confirmed in its migration), so a SKU can only ever belong to ONE company — but an inbound
 * event from a channel must never mutate (or deactivate) a product owned by a DIFFERENT
 * company than the one that channel resolves to. Every method below resolves the channel's
 * company first (fail-closed, via the same WooTenantCustomerResolver::resolveCompanyId() WOO-02
 * centralized — a channel→brand→company resolver, not customer-specific despite the class
 * name) and refuses to touch a SKU-matched product owned by any other company.
 *
 * Design constraints (unchanged from before this task):
 * - We cannot create products from WooCommerce because ECOS requires category_id and unit_id,
 *   which are ERP-specific and not present in WooCommerce payloads.
 * - product.created / product.updated: match by SKU; if no match, log as skipped (cannot
 *   auto-create); if matched, reconcile (see above) and maintain the ProductMapping.
 * - product.deleted: match by SKU (or the channel-scoped mapping); if match, deactivate.
 */
final class WooCommerceProductSyncer
{
    public function __construct(
        private readonly WooTenantCustomerResolver $tenantResolver,
        private readonly SyncLogService $logService,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{action: string, product_id: string|null}
     */
    public function syncCreated(Channel $channel, array $payload): array
    {
        return $this->resolveAndReconcile($channel, $payload, 'mapping_created');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{action: string, product_id: string|null}
     */
    public function syncUpdated(Channel $channel, array $payload): array
    {
        return $this->resolveAndReconcile($channel, $payload, 'updated');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{action: string, product_id: string|null}
     */
    public function syncDeleted(Channel $channel, array $payload): array
    {
        $sku = trim((string) ($payload['sku'] ?? ''));
        $externalId = (string) ($payload['id'] ?? '');

        $product = null;

        if ($sku !== '') {
            $product = Product::withoutEvents(function () use ($sku): ?Product {
                // Deliberately bypasses Product's own 'tenant' global scope: this lookup must
                // see a SKU regardless of the executing actor (an authenticated admin's own
                // company would otherwise hide a different company's product, and the create
                // fallback would then collide with sku's global unique constraint instead of
                // reporting a clear cross-company conflict). The explicit ownership check right
                // after this call is what actually enforces tenant safety, not this scope.
                return Product::withoutGlobalScope('tenant')->where('sku', $sku)->first();
            });
        }

        if ($product === null && $externalId !== '') {
            $mapping = ProductMapping::query()
                ->where('channel_id', $channel->id)
                ->where('external_product_id', $externalId)
                ->first();

            $product = $mapping?->product;
        }

        if ($product === null) {
            return ['action' => 'skipped_not_found', 'product_id' => null];
        }

        $companyId = $this->tenantResolver->resolveCompanyId($channel);

        if ($product->company_id !== null && (string) $product->company_id !== $companyId) {
            return ['action' => 'skipped_cross_company_conflict', 'product_id' => $product->id];
        }

        Product::withoutEvents(function () use ($product): void {
            $product->update(['is_active' => false]);
        });

        return ['action' => 'deactivated', 'product_id' => $product->id];
    }

    /**
     * Shared body of syncCreated/syncUpdated: resolve the SKU match, verify company ownership,
     * maintain the mapping (always allowed), then reconcile ECOS-owned fields — apply nothing,
     * detect drift, log it, and re-push the canonical value when found.
     *
     * @param  array<string, mixed>  $payload
     * @return array{action: string, product_id: string|null}
     */
    private function resolveAndReconcile(Channel $channel, array $payload, string $successAction): array
    {
        $sku = trim((string) ($payload['sku'] ?? ''));

        if ($sku === '') {
            return ['action' => 'skipped_no_sku', 'product_id' => null];
        }

        $product = Product::withoutEvents(function () use ($sku): ?Product {
            // See syncDeleted()'s identical lookup for why the tenant scope is bypassed here.
            return Product::withoutGlobalScope('tenant')->where('sku', $sku)->first();
        });

        if ($product === null) {
            return ['action' => 'skipped_no_sku_match', 'product_id' => null];
        }

        $companyId = $this->tenantResolver->resolveCompanyId($channel);

        if ($product->company_id !== null && (string) $product->company_id !== $companyId) {
            return ['action' => 'skipped_cross_company_conflict', 'product_id' => $product->id];
        }

        $externalId = (string) ($payload['id'] ?? '');
        $this->upsertMapping($channel, $product, $externalId);

        $drift = $this->detectDrift($product, $payload);

        if ($drift !== []) {
            $this->logDrift($channel, $product, $drift);
            ProductSyncJob::dispatch($channel, $product);
            PriceSyncJob::dispatch($channel, $product);

            return ['action' => 'drift_detected', 'product_id' => $product->id];
        }

        return ['action' => $successAction, 'product_id' => $product->id];
    }

    /**
     * Compare the inbound payload's ECOS-owned fields against the product's current values.
     * Pure detection — never applies anything to $product.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function detectDrift(Product $product, array $payload): array
    {
        $drift = [];

        // Every comparison below only fires when ECOS already holds a non-null value for that
        // field — i.e., an actual prior ECOS decision exists to protect. A currently-empty
        // field has no decision to protect yet, so an inbound value merely fills a gap; it is
        // not applied here either (still ECOS-owned going forward), just not flagged as drift.
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name !== '' && $name !== $product->name) {
            $drift['name'] = ['old' => $product->name, 'new' => $name];
        }

        $description = trim(strip_tags((string) ($payload['description'] ?? '')));
        if ($description !== '' && $product->description !== null && $description !== (string) $product->description) {
            $drift['description'] = ['old' => $product->description, 'new' => $description];
        }

        $shortDescription = trim(strip_tags((string) ($payload['short_description'] ?? '')));
        if ($shortDescription !== '' && $product->short_description !== null && $shortDescription !== (string) $product->short_description) {
            $drift['short_description'] = ['old' => $product->short_description, 'new' => $shortDescription];
        }

        $regularPriceRaw = $payload['regular_price'] ?? '';
        if ($regularPriceRaw !== '' && $regularPriceRaw !== null && $product->regular_price !== null
            && (float) $regularPriceRaw !== (float) $product->regular_price) {
            $drift['regular_price'] = ['old' => $product->regular_price, 'new' => (float) $regularPriceRaw];
        }

        $salePriceRaw = $payload['sale_price'] ?? '';
        if ($salePriceRaw !== '' && $salePriceRaw !== null && $product->sale_price !== null
            && (float) $salePriceRaw !== (float) $product->sale_price) {
            $drift['sale_price'] = ['old' => $product->sale_price, 'new' => (float) $salePriceRaw];
        }

        return $drift;
    }

    /**
     * @param  array<string, array{old: mixed, new: mixed}>  $drift
     */
    private function logDrift(Channel $channel, Product $product, array $drift): void
    {
        $log = $this->logService->createLog(
            $channel,
            SyncEntityType::Product,
            SyncDirection::Inbound,
            'product.drift_detected',
            $product->id,
            SyncStatus::Processing,
            ['product_id' => $product->id, 'sku' => $product->sku, 'drift' => $drift],
        );

        $this->logService->markSuccess($log, [
            'action' => 'drift_detected',
            'drift' => $drift,
            'corrective_action' => 'canonical_value_repushed',
        ], $channel);
    }

    private function upsertMapping(Channel $channel, Product $product, string $externalId): void
    {
        if ($externalId === '') {
            return;
        }

        ProductMapping::query()->updateOrCreate(
            ['channel_id' => $channel->id, 'product_id' => $product->id],
            ['external_product_id' => $externalId, 'sync_status' => MappingSyncStatus::Synced->value],
        );
    }
}

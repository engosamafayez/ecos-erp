<?php

declare(strict_types=1);

namespace Modules\Commerce\ProductImport\Application\Services;

use Illuminate\Support\Facades\Http;
use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Commerce\ProductImport\Application\DTO\ImportResultDTO;
use Modules\Commerce\ProductMappings\Domain\Enums\SyncStatus;
use Modules\Commerce\ProductMappings\Domain\Models\ProductMapping;
use Modules\Commerce\Synchronization\Application\Jobs\PriceSyncJob;
use Modules\Commerce\Synchronization\Application\Jobs\ProductAvailabilitySyncJob;
use Modules\Commerce\Synchronization\Application\Jobs\ProductSyncJob;
use Modules\Commerce\Synchronization\Application\Services\SyncLogService;
use Modules\Commerce\Synchronization\Application\Services\WooCommerceProductAvailabilityResolver;
use Modules\Commerce\Synchronization\Application\Services\WooTenantCustomerResolver;
use Modules\Commerce\Synchronization\Domain\Enums\SyncDirection;
use Modules\Commerce\Synchronization\Domain\Enums\SyncEntityType;
use Modules\Commerce\Synchronization\Domain\Enums\SyncStatus as SyncLogStatus;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\MasterData\Categories\Domain\Models\Category;
use Modules\MasterData\Units\Domain\Models\Unit;
use RuntimeException;
use Throwable;

final class WooCommerceProductImporter
{
    private const PER_PAGE = 100;

    private const TIMEOUT = 30;

    private const VALID_STOCK_STATUSES = ['instock', 'outofstock', 'onbackorder'];

    private const MAX_CATEGORY_DEPTH = 3;

    private int $categoriesCreated = 0;

    private int $categoriesUpdated = 0;

    public function __construct(
        // TASK-ECOS-V1.1-WOO-03-PRODUCT-INBOUND-OWNERSHIP-PROTECTION — the same shared
        // channel→brand→company authority WOO-02 centralized (WooTenantCustomerResolver;
        // not customer-specific despite the name — see its own docblock), reused here so
        // there is one company-resolution engine, not a second one for products.
        private readonly WooTenantCustomerResolver $tenantResolver,
        private readonly SyncLogService $logService,
        // TASK-...-CONSOLIDATED-REMEDIATION-001-R1/R2 (CTO business-rule correction) — the
        // ONE ECOS-owned availability authority; stock_status is no longer inbound-owned
        // (see resolveProduct()/reconcileExisting() below).
        private readonly WooCommerceProductAvailabilityResolver $availability,
    ) {}

    public function import(Channel $channel): ImportResultDTO
    {
        $this->categoriesCreated = 0;
        $this->categoriesUpdated = 0;

        $credential = $channel->credential;

        if ($credential === null) {
            return new ImportResultDTO(0, 0, 0, 0, 0, 0, ['No credentials configured for this channel.']);
        }

        // TASK-...-WOO-03 — resolved ONCE for the whole run (the channel is constant
        // throughout), fail-closed, before any product is matched/created — same pattern
        // WOO-02 established for WooCommerceOrderImporter::import().
        try {
            $companyId = $this->tenantResolver->resolveCompanyId($channel);
        } catch (Throwable $e) {
            return new ImportResultDTO(0, 0, 0, 0, 0, 0, [$e->getMessage()]);
        }

        $defaultCategory = Category::query()->first();
        $defaultUnit = Unit::query()->first();

        if ($defaultCategory === null || $defaultUnit === null) {
            return new ImportResultDTO(0, 0, 0, 0, 0, 0, [
                'No default category or unit found. Create at least one category and one unit before importing.',
            ]);
        }

        $baseUrl = rtrim($channel->store_url, '/').'/wp-json/wc/v3';

        $wooCategoryMap = $this->fetchAllWooCategories(
            $baseUrl,
            $credential->consumer_key,
            $credential->consumer_secret,
        );

        $imported = 0;
        $createdProducts = 0;
        $createdMappings = 0;
        $failed = 0;
        $errors = [];
        $page = 1;
        $productsUrl = $baseUrl.'/products';

        while (true) {
            try {
                $response = Http::withBasicAuth($credential->consumer_key, $credential->consumer_secret)
                    ->timeout(self::TIMEOUT)
                    ->get($productsUrl, ['per_page' => self::PER_PAGE, 'page' => $page, 'status' => 'any']);

                if (! $response->successful()) {
                    $errors[] = "Failed to fetch page {$page}: HTTP {$response->status()}.";
                    break;
                }

                /** @var list<array<string, mixed>> $wooProducts */
                $wooProducts = $response->json() ?? [];

                if (empty($wooProducts)) {
                    break;
                }

                foreach ($wooProducts as $wooProduct) {
                    $sku = trim((string) ($wooProduct['sku'] ?? ''));

                    if ($sku === '') {
                        $failed++;
                        $errors[] = sprintf('Product #%s skipped: no SKU.', $wooProduct['id'] ?? '?');

                        continue;
                    }

                    $imported++;

                    try {
                        [$product, $wasCreated] = $this->resolveProduct(
                            $sku,
                            $wooProduct,
                            $wooCategoryMap,
                            $defaultCategory->id,
                            $defaultUnit->id,
                            $channel,
                            $companyId,
                        );

                        if ($wasCreated) {
                            $createdProducts++;
                        }

                        if ($this->resolveMapping($product, $channel, $wooProduct)) {
                            $createdMappings++;
                        }
                    } catch (Throwable $e) {
                        $failed++;
                        $imported--;
                        $errors[] = "Failed to process SKU [{$sku}]: {$e->getMessage()}";
                    }
                }

                $totalPages = max(1, (int) ($response->header('X-WP-TotalPages') ?: 1));

                if ($page >= $totalPages || count($wooProducts) < self::PER_PAGE) {
                    break;
                }

                $page++;
            } catch (Throwable $e) {
                $errors[] = "Request error on page {$page}: {$e->getMessage()}";
                break;
            }
        }

        return new ImportResultDTO(
            $imported,
            $createdProducts,
            $createdMappings,
            $failed,
            $this->categoriesCreated,
            $this->categoriesUpdated,
            $errors,
        );
    }

    /**
     * Fetch all WooCommerce categories in one paginated pass.
     *
     * @return array<int, array<string, mixed>> keyed by WooCommerce category ID
     */
    private function fetchAllWooCategories(string $baseUrl, string $key, string $secret): array
    {
        $categories = [];
        $page = 1;
        $url = $baseUrl.'/products/categories';

        while (true) {
            try {
                $response = Http::withBasicAuth($key, $secret)
                    ->timeout(self::TIMEOUT)
                    ->get($url, ['per_page' => 100, 'page' => $page]);

                if (! $response->successful()) {
                    break;
                }

                /** @var list<array<string, mixed>> $batch */
                $batch = $response->json() ?? [];

                if (empty($batch)) {
                    break;
                }

                foreach ($batch as $cat) {
                    $id = (int) ($cat['id'] ?? 0);
                    if ($id > 0) {
                        $categories[$id] = $cat;
                    }
                }

                $totalPages = max(1, (int) ($response->header('X-WP-TotalPages') ?: 1));

                if ($page >= $totalPages || count($batch) < 100) {
                    break;
                }

                $page++;
            } catch (Throwable) {
                break;
            }
        }

        return $categories;
    }

    /**
     * Find an existing product by SKU, or create a new one.
     *
     * TASK-...-WOO-03: `name`, descriptions, prices, image_url and category_id are ECOS-owned
     * once a product exists — an existing match is NEVER updated from inbound Woo data for
     * these (previously it was, unconditionally — GAP-1). A different inbound value for one of
     * them is drift: detected, logged, and corrected by re-pushing the ECOS canonical value
     * back out, never applied. All of the above ARE still used to populate a brand-new
     * product, since ECOS has made no decision yet for a SKU it has never seen.
     *
     * `stock_status` is the one deliberate exception, NOT covered by the above: per
     * ProductStockStatusWritePathTest (TASK-PHASE3-GD2-STEP2-CLOSE-001), it is a WooCommerce
     * channel attribute owned by the inbound importer specifically — never human-editable
     * (excluded from every product write-path request's rules()) and never published outbound
     * — so it keeps being written on every match, existing or new, exactly as before this task.
     *
     * Tenant-safe: `products.sku` is globally unique, so a SKU match is never ambiguous — but a
     * match belonging to a company other than the one this $channel resolves to is refused
     * (thrown, caught by this method's only caller's existing per-SKU try/catch) rather than
     * silently updated or adopted.
     *
     * @param  array<string, mixed>  $wooProduct
     * @param  array<int, array<string, mixed>>  $wooCategoryMap
     * @return array{Product, bool}
     */
    private function resolveProduct(
        string $sku,
        array $wooProduct,
        array $wooCategoryMap,
        string $defaultCategoryId,
        string $defaultUnitId,
        Channel $channel,
        string $companyId,
    ): array {
        // Deliberately bypasses Product's own 'tenant' global scope: this lookup must see a
        // SKU regardless of the executing actor (this Action runs under an authenticated HTTP
        // request — see ImportProductsAction — so without this, an admin's own company would
        // silently hide a different company's product, and the create-fallback below would
        // then collide with sku's global unique constraint instead of reporting a clear
        // cross-company conflict). The explicit ownership check right after is what actually
        // enforces tenant safety, not this scope.
        $existing = Product::withoutGlobalScope('tenant')->where('sku', $sku)->first();

        if ($existing !== null) {
            if ($existing->company_id !== null && (string) $existing->company_id !== $companyId) {
                throw new RuntimeException(
                    "SKU [{$sku}] belongs to a different company than channel [{$channel->id}] resolves to. ".
                    'Refusing to update a product this channel does not own.',
                );
            }

            // TASK-...-CONSOLIDATED-REMEDIATION-001-R1/R2 (CTO business-rule correction) —
            // stock_status is now ECOS-owned/outbound-only, superseding the previous
            // inbound-owned rule. reconcileExisting() now detects stock_status drift (see
            // below) and, when found, corrects it by re-pushing ECOS's own computed value —
            // it is never applied from the inbound payload.
            $this->reconcileExisting($channel, $existing, $wooProduct);

            return [$existing, false];
        }

        $enrichment = $this->extractEnrichment($wooProduct);
        $categoryId = $this->resolveDeepestEcosCategory(
            $wooProduct['categories'] ?? [],
            $wooCategoryMap,
            $defaultCategoryId,
        );
        $isActive = (($wooProduct['status'] ?? '') === 'publish');

        // stock_status is computed by ECOS immediately after creation (below), never seeded
        // from the inbound payload — it is ECOS-owned/outbound-only.
        unset($enrichment['stock_status']);

        $product = Product::query()->create(array_merge([
            'sku' => $sku,
            'name' => (string) ($wooProduct['name'] ?? $sku),
            'description' => $enrichment['long_description'],
            'category_id' => $categoryId,
            'unit_id' => $defaultUnitId,
            'product_type' => Product::TYPE_FINISHED_GOOD,
            'is_active' => $isActive,
            // ADR-013 Principle 8 / TASK-PRODUCT-OWNERSHIP-002:
            // Brand is the direct owner; channel.brand_id is always set (non-nullable after TASK-ADMIN-005).
            'brand_id' => $channel->brand_id,
            // TASK-...-WOO-03 §7 — previously absent, so every Woo-imported product was
            // created with company_id = NULL. Resolved once per run (see import()).
            'company_id' => $companyId,
        ], $enrichment));

        // The initial ECOS-computed availability for a brand-new product — never taken from
        // Woo. A freshly-created product with no InventoryItem row yet resolves to OutOfStock
        // (ProductAvailability's null-available rule), which is the correct, honest starting
        // state until raw material stock actually exists.
        $product->update(['stock_status' => $this->availability->resolve($product)->value]);

        return [$product, true];
    }

    /**
     * Detect drift on an already-existing product match and correct it by re-pushing the
     * ECOS canonical value — never by applying the inbound one. See resolveProduct()'s
     * docblock and WooCommerceProductSyncer's class docblock for the full rationale; this is
     * the identical rule applied to the manual pull-import path.
     *
     * @param  array<string, mixed>  $wooProduct
     */
    private function reconcileExisting(Channel $channel, Product $existing, array $wooProduct): void
    {
        $enrichment = $this->extractEnrichment($wooProduct);
        $drift = [];

        // Every comparison below only fires when ECOS already holds a non-null value for that
        // field — i.e., an actual prior ECOS decision exists to protect. A currently-empty
        // field has no decision to protect yet, so an inbound value merely fills a gap; it is
        // not applied here either (still ECOS-owned going forward), just not flagged as drift.
        $name = trim((string) ($wooProduct['name'] ?? ''));
        if ($name !== '' && $name !== $existing->name) {
            $drift['name'] = ['old' => $existing->name, 'new' => $name];
        }

        // Enrichment key and Product column share the same name for both of these.
        foreach (['long_description', 'short_description'] as $field) {
            $value = $enrichment[$field];
            if ($value !== null && $existing->{$field} !== null && $value !== (string) $existing->{$field}) {
                $drift[$field] = ['old' => $existing->{$field}, 'new' => $value];
            }
        }

        foreach (['regular_price', 'sale_price'] as $field) {
            $value = $enrichment[$field];
            if ($value !== null && $existing->{$field} !== null && (float) $value !== (float) $existing->{$field}) {
                $drift[$field] = ['old' => $existing->{$field}, 'new' => $value];
            }
        }

        // TASK-...-CONSOLIDATED-REMEDIATION-001-R1/R2 (CTO business-rule correction) —
        // stock_status is now ECOS-owned/outbound-only, protected exactly like the fields
        // above: compared against ECOS's own canonical value (never Woo's inbound one, and
        // never $existing->stock_status, which may itself be stale — see below), detected as
        // drift, logged, and corrected by re-pushing the canonical value. Never applied here.
        $wooStockStatus = trim((string) ($wooProduct['stock_status'] ?? ''));
        $ecosStockStatus = $this->availability->resolve($existing);

        if ($wooStockStatus !== '' && $wooStockStatus !== $ecosStockStatus->value) {
            $drift['stock_status'] = ['old' => $ecosStockStatus->value, 'new' => $wooStockStatus];
        }

        if ($drift === []) {
            return;
        }

        $log = $this->logService->createLog(
            $channel,
            SyncEntityType::Product,
            SyncDirection::Inbound,
            'product.drift_detected',
            $existing->id,
            SyncLogStatus::Processing,
            ['product_id' => $existing->id, 'sku' => $existing->sku, 'drift' => $drift],
        );

        $this->logService->markSuccess($log, [
            'action' => 'drift_detected',
            'drift' => $drift,
            'corrective_action' => 'canonical_value_repushed',
        ], $channel);

        if (array_key_exists('stock_status', $drift)) {
            if ($existing->stock_status !== $ecosStockStatus) {
                $existing->update(['stock_status' => $ecosStockStatus->value]);
            }

            ProductAvailabilitySyncJob::dispatch($channel, $existing, $ecosStockStatus);
        }

        if (array_key_exists('name', $drift) || array_key_exists('long_description', $drift)
            || array_key_exists('short_description', $drift) || array_key_exists('regular_price', $drift)
            || array_key_exists('sale_price', $drift)) {
            ProductSyncJob::dispatch($channel, $existing);
            PriceSyncJob::dispatch($channel, $existing);
        }
    }

    /**
     * Create or restore a product-channel mapping.
     * Returns true if a mapping was created/restored, false if it already existed.
     *
     * @param  array<string, mixed>  $wooProduct
     */
    private function resolveMapping(Product $product, Channel $channel, array $wooProduct): bool
    {
        $existing = ProductMapping::withTrashed()
            ->where('product_id', $product->id)
            ->where('channel_id', $channel->id)
            ->first();

        $attributes = [
            'external_product_id' => (string) ($wooProduct['id'] ?? ''),
            'external_sku' => (string) ($wooProduct['sku'] ?? ''),
            'sync_status' => SyncStatus::Synced->value,
            'last_sync_at' => now(),
        ];

        if ($existing !== null) {
            if ($existing->trashed()) {
                $existing->restore();
                $existing->update($attributes);

                return true;
            }

            return false;
        }

        ProductMapping::query()->create(array_merge($attributes, [
            'product_id' => $product->id,
            'channel_id' => $channel->id,
        ]));

        return true;
    }

    /**
     * Resolve the ECOS category ID for the deepest WooCommerce category,
     * creating the full ancestor hierarchy as needed.
     *
     * @param  list<array<string, mixed>>  $wooProductCategories
     * @param  array<int, array<string, mixed>>  $wooCategoryMap
     */
    private function resolveDeepestEcosCategory(
        array $wooProductCategories,
        array $wooCategoryMap,
        string $defaultCategoryId,
    ): string {
        if (empty($wooProductCategories)) {
            return $defaultCategoryId;
        }

        $deepestId = $this->findDeepestCategoryId($wooProductCategories, $wooCategoryMap);

        if ($deepestId === null || ! isset($wooCategoryMap[$deepestId])) {
            return $defaultCategoryId;
        }

        $chain = $this->buildAncestryChain($deepestId, $wooCategoryMap);

        if (empty($chain)) {
            return $defaultCategoryId;
        }

        return $this->resolveEcosCategoryChain($chain, $wooCategoryMap, $defaultCategoryId);
    }

    /**
     * Find the deepest category among a product's WooCommerce categories.
     * A category is "deepest" if none of the other product categories is its child.
     *
     * @param  list<array<string, mixed>>  $wooProductCategories
     * @param  array<int, array<string, mixed>>  $wooCategoryMap
     */
    private function findDeepestCategoryId(array $wooProductCategories, array $wooCategoryMap): ?int
    {
        $productIds = array_map(
            fn (array $c): int => (int) ($c['id'] ?? 0),
            $wooProductCategories,
        );

        foreach ($wooProductCategories as $cat) {
            $catId = (int) ($cat['id'] ?? 0);
            $isParent = false;

            foreach ($productIds as $otherId) {
                if ($otherId === $catId) {
                    continue;
                }

                // Walk up the ancestry of $otherId; if we reach $catId it means $catId is an ancestor
                $current = $otherId;
                $visited = [];
                while ($current > 0 && isset($wooCategoryMap[$current]) && ! in_array($current, $visited, true)) {
                    $visited[] = $current;
                    $parentId = (int) ($wooCategoryMap[$current]['parent'] ?? 0);
                    if ($parentId === $catId) {
                        $isParent = true;
                        break 2;
                    }
                    $current = $parentId;
                }
            }

            if (! $isParent) {
                return $catId;
            }
        }

        // Fallback: pick the category with the greatest depth in the hierarchy
        $maxDepth = -1;
        $deepestId = (int) ($wooProductCategories[0]['id'] ?? 0);

        foreach ($wooProductCategories as $cat) {
            $catId = (int) ($cat['id'] ?? 0);
            $depth = $this->wooCategoryDepth($catId, $wooCategoryMap);

            if ($depth > $maxDepth) {
                $maxDepth = $depth;
                $deepestId = $catId;
            }
        }

        return $deepestId > 0 ? $deepestId : null;
    }

    /**
     * Calculate the depth of a WooCommerce category (root = 0).
     *
     * @param  array<int, array<string, mixed>>  $wooCategoryMap
     */
    private function wooCategoryDepth(int $wooCatId, array $wooCategoryMap): int
    {
        $depth = 0;
        $current = $wooCatId;
        $visited = [];

        while ($current > 0 && isset($wooCategoryMap[$current]) && ! in_array($current, $visited, true)) {
            $visited[] = $current;
            $parent = (int) ($wooCategoryMap[$current]['parent'] ?? 0);
            if ($parent === 0) {
                break;
            }
            $depth++;
            $current = $parent;
        }

        return $depth;
    }

    /**
     * Build ancestry chain from root to leaf, clamped to MAX_CATEGORY_DEPTH levels.
     *
     * @param  array<int, array<string, mixed>>  $wooCategoryMap
     * @return list<int> WooCommerce category IDs ordered root → leaf
     */
    private function buildAncestryChain(int $wooCatId, array $wooCategoryMap): array
    {
        $chain = [];
        $current = $wooCatId;
        $visited = [];

        while ($current > 0 && isset($wooCategoryMap[$current]) && ! in_array($current, $visited, true)) {
            array_unshift($chain, $current);
            $visited[] = $current;
            $current = (int) ($wooCategoryMap[$current]['parent'] ?? 0);
        }

        if (count($chain) > self::MAX_CATEGORY_DEPTH) {
            $chain = array_slice($chain, -self::MAX_CATEGORY_DEPTH);
        }

        return array_values($chain);
    }

    /**
     * Ensure the full ancestry chain exists in ECOS and return the deepest ECOS category ID.
     * Creates missing categories, updates names of existing ones if changed.
     *
     * @param  list<int>  $chain  WooCommerce IDs ordered root → leaf
     * @param  array<int, array<string, mixed>>  $wooCategoryMap
     */
    private function resolveEcosCategoryChain(
        array $chain,
        array $wooCategoryMap,
        string $defaultCategoryId,
    ): string {
        $parentEcosId = null;
        $lastId = $defaultCategoryId;
        $level = 1;

        foreach ($chain as $wooId) {
            $wooCat = $wooCategoryMap[$wooId] ?? null;
            if ($wooCat === null) {
                continue;
            }

            $slug = trim((string) ($wooCat['slug'] ?? ''));
            $name = trim((string) ($wooCat['name'] ?? ''));

            if ($slug === '' || $name === '') {
                continue;
            }

            // Match by slug under the same parent to preserve hierarchy
            $existing = Category::query()
                ->where('code', $slug)
                ->where('parent_id', $parentEcosId)
                ->first();

            if ($existing !== null) {
                if ($existing->name !== $name) {
                    $existing->update(['name' => $name]);
                    $this->categoriesUpdated++;
                }

                $parentEcosId = $existing->id;
                $lastId = $existing->id;
                $level++;

                continue;
            }

            // Deduplicate code if the same slug exists under a different parent
            $code = $slug;
            $suffix = 1;
            while (Category::query()->where('code', $code)->exists()) {
                $code = $slug.'-'.$suffix;
                $suffix++;
            }

            $category = Category::query()->create([
                'code' => $code,
                'name' => $name,
                'parent_id' => $parentEcosId,
                'level' => $level,
                'sort_order' => 0,
                'is_active' => true,
            ]);

            $this->categoriesCreated++;
            $parentEcosId = $category->id;
            $lastId = $category->id;
            $level++;
        }

        return $lastId;
    }

    /**
     * Extract enrichment fields from a WooCommerce product payload.
     *
     * @param  array<string, mixed>  $wooProduct
     * @return array<string, mixed>
     */
    private function extractEnrichment(array $wooProduct): array
    {
        $images = $wooProduct['images'] ?? [];
        $imageUrl = isset($images[0]['src']) ? (string) $images[0]['src'] : null;

        $regularPriceRaw = $wooProduct['regular_price'] ?? '';
        $regularPrice = is_numeric($regularPriceRaw) ? (float) $regularPriceRaw : null;

        $salePriceRaw = $wooProduct['sale_price'] ?? '';
        $salePrice = is_numeric($salePriceRaw) && $salePriceRaw !== '' ? (float) $salePriceRaw : null;

        $shortDescription = strip_tags((string) ($wooProduct['short_description'] ?? ''));
        $longDescription = strip_tags((string) ($wooProduct['description'] ?? ''));

        $wooStockStatus = (string) ($wooProduct['stock_status'] ?? 'instock');
        $stockStatus = in_array($wooStockStatus, self::VALID_STOCK_STATUSES, true)
            ? $wooStockStatus
            : 'instock';

        return [
            'image_url' => $imageUrl !== '' ? $imageUrl : null,
            'regular_price' => $regularPrice,
            'sale_price' => $salePrice,
            'short_description' => $shortDescription !== '' ? $shortDescription : null,
            'long_description' => $longDescription !== '' ? $longDescription : null,
            'stock_status' => $stockStatus,
        ];
    }
}

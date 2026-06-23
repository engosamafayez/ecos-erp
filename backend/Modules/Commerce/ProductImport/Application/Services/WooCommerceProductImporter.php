<?php

declare(strict_types=1);

namespace Modules\Commerce\ProductImport\Application\Services;

use Illuminate\Support\Facades\Http;
use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Commerce\ProductImport\Application\DTO\ImportResultDTO;
use Modules\Commerce\ProductMappings\Domain\Enums\SyncStatus;
use Modules\Commerce\ProductMappings\Domain\Models\ProductMapping;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\MasterData\Categories\Domain\Models\Category;
use Modules\MasterData\Units\Domain\Models\Unit;
use Throwable;

final class WooCommerceProductImporter
{
    private const PER_PAGE = 100;

    private const TIMEOUT = 30;

    public function import(Channel $channel): ImportResultDTO
    {
        $credential = $channel->credential;

        if ($credential === null) {
            return new ImportResultDTO(0, 0, 0, 0, ['No credentials configured for this channel.']);
        }

        $defaultCategory = Category::query()->first();
        $defaultUnit = Unit::query()->first();

        if ($defaultCategory === null || $defaultUnit === null) {
            return new ImportResultDTO(0, 0, 0, 0, [
                'No default category or unit found. Create at least one category and one unit before importing.',
            ]);
        }

        $imported = 0;
        $createdProducts = 0;
        $createdMappings = 0;
        $failed = 0;
        $errors = [];

        $page = 1;
        $baseUrl = rtrim($channel->store_url, '/') . '/wp-json/wc/v3/products';

        while (true) {
            try {
                $response = Http::withBasicAuth($credential->consumer_key, $credential->consumer_secret)
                    ->timeout(self::TIMEOUT)
                    ->get($baseUrl, ['per_page' => self::PER_PAGE, 'page' => $page, 'status' => 'any']);

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
                            $defaultCategory->id,
                            $defaultUnit->id,
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

        return new ImportResultDTO($imported, $createdProducts, $createdMappings, $failed, $errors);
    }

    /**
     * Find existing product by SKU or create a new one.
     *
     * @param  array<string, mixed>  $wooProduct
     * @return array{Product, bool}
     */
    private function resolveProduct(
        string $sku,
        array $wooProduct,
        string $categoryId,
        string $unitId,
    ): array {
        $existing = Product::query()->where('sku', $sku)->first();

        if ($existing !== null) {
            return [$existing, false];
        }

        $isActive = (($wooProduct['status'] ?? '') === 'publish');
        $description = strip_tags((string) ($wooProduct['description'] ?? ''));

        $product = Product::query()->create([
            'sku' => $sku,
            'name' => (string) ($wooProduct['name'] ?? $sku),
            'description' => $description !== '' ? $description : null,
            'category_id' => $categoryId,
            'unit_id' => $unitId,
            'product_type' => Product::TYPE_FINISHED_GOOD,
            'is_active' => $isActive,
        ]);

        return [$product, true];
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
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Commerce\Channels\Domain\Models\ChannelCredential;
use Modules\Commerce\ProductImport\Application\Services\WooCommerceProductImporter;
use Modules\Commerce\ProductMappings\Domain\Models\ProductMapping;
use Modules\Commerce\Synchronization\Application\Jobs\PriceSyncJob;
use Modules\Commerce\Synchronization\Application\Jobs\ProductSyncJob;
use Modules\Commerce\Synchronization\Application\Services\WooCommerceProductSyncer;
use Modules\Commerce\Synchronization\Domain\Models\SyncLog;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\MasterData\Categories\Domain\Models\Category;
use Modules\MasterData\Units\Domain\Models\Unit;
use Modules\Organization\Brands\Domain\Models\Brand;
use Modules\Organization\Companies\Domain\Models\Company;
use RuntimeException;
use Tests\TestCase;

/**
 * TASK-ECOS-V1.1-WOO-03-PRODUCT-INBOUND-OWNERSHIP-PROTECTION.
 *
 * Per the CTO's Track 2 execution-model change, these are written as source-level
 * regression coverage for the approved architecture (042A-R1 §3) but their EXECUTION is
 * deferred to the consolidated Track 2 test pass — no MySQL/PHPUnit cycle was launched
 * for this ticket. Not executed does not mean not real: every case below targets an
 * actual public entry point (WooCommerceProductSyncer / WooCommerceProductImporter),
 * not a placeholder.
 *
 * Woo variations are deliberately not covered — no variation-specific code path exists
 * anywhere in either sync class (confirmed by direct read), and the architecture defines
 * no variation semantics to test against; inventing one would violate §8's "do not invent
 * variation semantics not present in the approved architecture."
 */
final class WooCommerceProductInboundOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private function makeCompanyChannel(): array
    {
        $company = Company::factory()->create();
        $brand = Brand::factory()->create(['company_id' => $company->id]);
        $channel = Channel::factory()->create(['brand_id' => $brand->id]);

        return [$company, $brand, $channel];
    }

    private function makeProduct(string $companyId, string $brandId, array $attributes = []): Product
    {
        $category = Category::query()->first() ?? Category::factory()->create();
        $unit = Unit::query()->first() ?? Unit::factory()->create();

        return Product::withoutEvents(fn (): Product => Product::query()->create(array_merge([
            'sku' => 'SKU-'.uniqid(),
            'name' => 'Original Name',
            'description' => 'Original description',
            'short_description' => 'Original short',
            'regular_price' => 100.0,
            'sale_price' => 90.0,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'product_type' => Product::TYPE_FINISHED_GOOD,
            'is_active' => true,
            'brand_id' => $brandId,
            'company_id' => $companyId,
        ], $attributes)));
    }

    // ═══ TENANT-SCOPED PRODUCT RESOLUTION ════════════════════════════════════

    public function test_webhook_sync_refuses_a_product_owned_by_a_different_company(): void
    {
        [$companyA, $brandA] = $this->makeCompanyChannel();
        [, , $channelB] = $this->makeCompanyChannel();
        $product = $this->makeProduct($companyA->id, $brandA->id, ['sku' => 'SKU-CROSS-CO']);

        $result = app(WooCommerceProductSyncer::class)->syncUpdated($channelB, [
            'sku' => 'SKU-CROSS-CO', 'id' => 999, 'name' => 'Hijacked Name', 'regular_price' => '1.00',
        ]);

        $this->assertSame('skipped_cross_company_conflict', $result['action']);
        $this->assertSame('Original Name', $product->fresh()->name);
        $this->assertSame(100.0, $product->fresh()->regular_price);
        $this->assertDatabaseMissing('product_channel_mappings', ['channel_id' => $channelB->id, 'product_id' => $product->id]);
    }

    public function test_product_deleted_webhook_respects_tenant_ownership(): void
    {
        [$companyA, $brandA] = $this->makeCompanyChannel();
        [, , $channelB] = $this->makeCompanyChannel();
        $product = $this->makeProduct($companyA->id, $brandA->id, ['sku' => 'SKU-CROSS-DEL']);

        $result = app(WooCommerceProductSyncer::class)->syncDeleted($channelB, ['sku' => 'SKU-CROSS-DEL']);

        $this->assertSame('skipped_cross_company_conflict', $result['action']);
        $this->assertTrue($product->fresh()->is_active);
    }

    // ═══ ALLOWED INBOUND-OWNED FIELD UPDATE (MAPPING BOOKKEEPING) ════════════

    public function test_mapping_bookkeeping_is_updated_on_webhook_sync(): void
    {
        [$company, $brand, $channel] = $this->makeCompanyChannel();
        $product = $this->makeProduct($company->id, $brand->id, ['sku' => 'SKU-MAP-001']);

        $result = app(WooCommerceProductSyncer::class)->syncUpdated($channel, [
            'sku' => 'SKU-MAP-001', 'id' => 4242, 'name' => 'Original Name',
        ]);

        $this->assertContains($result['action'], ['updated', 'drift_detected']);
        $this->assertDatabaseHas('product_channel_mappings', [
            'channel_id' => $channel->id, 'product_id' => $product->id, 'external_product_id' => '4242',
        ]);
    }

    // ═══ ECOS-OWNED FIELD PRESERVATION + DRIFT LOGGING ═══════════════════════

    public function test_ecos_owned_fields_are_never_overwritten_by_inbound_webhook_and_drift_is_logged(): void
    {
        Bus::fake([ProductSyncJob::class, PriceSyncJob::class]);

        [$company, $brand, $channel] = $this->makeCompanyChannel();
        $product = $this->makeProduct($company->id, $brand->id, ['sku' => 'SKU-DRIFT-001']);

        $result = app(WooCommerceProductSyncer::class)->syncUpdated($channel, [
            'sku' => 'SKU-DRIFT-001',
            'id' => 5001,
            'name' => 'Store-Admin-Edited Name',
            'description' => 'Store-admin-edited description',
            'short_description' => 'Store-admin short',
            'regular_price' => '150.00',
            'sale_price' => '120.00',
        ]);

        $this->assertSame('drift_detected', $result['action']);

        $fresh = $product->fresh();
        $this->assertSame('Original Name', $fresh->name);
        $this->assertSame('Original description', $fresh->description);
        $this->assertSame('Original short', $fresh->short_description);
        $this->assertSame(100.0, $fresh->regular_price);
        $this->assertSame(90.0, $fresh->sale_price);

        $driftLog = SyncLog::query()->where('action', 'product.drift_detected')->where('entity_id', $product->id)->first();
        $this->assertNotNull($driftLog, 'A distinct, queryable drift SyncLog entry must exist.');
        $this->assertSame('success', $driftLog->status->value);

        Bus::assertDispatched(ProductSyncJob::class);
        Bus::assertDispatched(PriceSyncJob::class);
    }

    public function test_no_drift_when_inbound_values_match_current_ecos_values(): void
    {
        Bus::fake([ProductSyncJob::class, PriceSyncJob::class]);

        [$company, $brand, $channel] = $this->makeCompanyChannel();
        $this->makeProduct($company->id, $brand->id, ['sku' => 'SKU-NODRIFT-001']);

        $result = app(WooCommerceProductSyncer::class)->syncUpdated($channel, [
            'sku' => 'SKU-NODRIFT-001',
            'id' => 5002,
            'name' => 'Original Name',
            'description' => 'Original description',
            'short_description' => 'Original short',
            'regular_price' => '100.00',
            'sale_price' => '90.00',
        ]);

        $this->assertSame('updated', $result['action']);
        $this->assertSame(0, SyncLog::query()->where('action', 'product.drift_detected')->count());
        Bus::assertNotDispatched(ProductSyncJob::class);
        Bus::assertNotDispatched(PriceSyncJob::class);
    }

    // ═══ REPLAY / IDEMPOTENCY ═════════════════════════════════════════════════

    public function test_replaying_the_same_webhook_payload_is_idempotent(): void
    {
        [$company, $brand, $channel] = $this->makeCompanyChannel();
        $product = $this->makeProduct($company->id, $brand->id, ['sku' => 'SKU-REPLAY-001']);

        $payload = ['sku' => 'SKU-REPLAY-001', 'id' => 6001, 'name' => 'Original Name'];

        app(WooCommerceProductSyncer::class)->syncUpdated($channel, $payload);
        app(WooCommerceProductSyncer::class)->syncUpdated($channel, $payload);

        $this->assertSame(
            1,
            ProductMapping::query()->where('channel_id', $channel->id)->where('product_id', $product->id)->count(),
        );
        $this->assertSame('Original Name', $product->fresh()->name);
    }

    // ═══ COMPANY-RESOLUTION FAILURE (FAIL-CLOSED) ════════════════════════════

    public function test_webhook_sync_fails_closed_when_channel_has_no_resolvable_company(): void
    {
        $channel = Channel::factory()->create(['brand_id' => null]);
        Product::factory()->create(['sku' => 'SKU-NOCO-001']);

        $threw = false;

        try {
            app(WooCommerceProductSyncer::class)->syncUpdated($channel, ['sku' => 'SKU-NOCO-001', 'id' => 1, 'name' => 'X']);
        } catch (RuntimeException $e) {
            $threw = true;
            $this->assertStringContainsString('resolves to no owning company', $e->getMessage());
        }

        $this->assertTrue($threw);
    }

    public function test_importer_fails_closed_when_channel_has_no_resolvable_company(): void
    {
        $channel = Channel::factory()->create(['brand_id' => null]);
        ChannelCredential::query()->create([
            'channel_id' => $channel->id, 'consumer_key' => 'ck_test', 'consumer_secret' => 'cs_test',
        ]);
        Category::factory()->create();
        Unit::factory()->create();

        $result = app(WooCommerceProductImporter::class)->import($channel);

        $this->assertSame(0, $result->imported);
        $this->assertSame(0, $result->created_products);
        $this->assertNotEmpty($result->errors);
        $this->assertStringContainsString('resolves to no owning company', $result->errors[0]);
    }

    // ═══ NEW PRODUCT CREATION VIA IMPORT SETS company_id ════════════════════

    public function test_import_creates_a_new_product_scoped_to_the_resolved_company(): void
    {
        [$company, , $channel] = $this->makeCompanyChannel();
        ChannelCredential::query()->create([
            'channel_id' => $channel->id, 'consumer_key' => 'ck_test', 'consumer_secret' => 'cs_test',
        ]);
        Category::factory()->create();
        Unit::factory()->create();

        Http::fake([
            '*/products/categories*' => Http::response([], 200),
            '*/products?*' => Http::response([
                ['id' => 777, 'sku' => 'SKU-IMPORT-NEW', 'name' => 'Imported Product', 'status' => 'publish', 'regular_price' => '50.00'],
            ], 200, ['X-WP-TotalPages' => '1']),
        ]);

        $result = app(WooCommerceProductImporter::class)->import($channel);

        $this->assertSame(1, $result->created_products);
        $product = Product::query()->where('sku', 'SKU-IMPORT-NEW')->first();
        $this->assertNotNull($product);
        $this->assertSame($company->id, $product->company_id);
    }

    public function test_import_never_updates_an_existing_products_ecos_owned_fields(): void
    {
        [$company, $brand, $channel] = $this->makeCompanyChannel();
        ChannelCredential::query()->create([
            'channel_id' => $channel->id, 'consumer_key' => 'ck_test', 'consumer_secret' => 'cs_test',
        ]);
        Category::factory()->create();
        Unit::factory()->create();
        $product = $this->makeProduct($company->id, $brand->id, ['sku' => 'SKU-IMPORT-EXIST']);

        Http::fake([
            '*/products/categories*' => Http::response([], 200),
            '*/products?*' => Http::response([
                ['id' => 778, 'sku' => 'SKU-IMPORT-EXIST', 'name' => 'Renamed By Woo Admin', 'status' => 'publish', 'regular_price' => '999.00'],
            ], 200, ['X-WP-TotalPages' => '1']),
        ]);

        app(WooCommerceProductImporter::class)->import($channel);

        $fresh = $product->fresh();
        $this->assertSame('Original Name', $fresh->name);
        $this->assertSame(100.0, $fresh->regular_price);
        $this->assertSame(
            1,
            SyncLog::query()->where('action', 'product.drift_detected')->where('entity_id', $product->id)->count(),
        );
    }

    // ═══ stock_status: THE ONE DELIBERATE EXCEPTION ═════════════════════════

    public function test_stock_status_is_still_applied_inbound_by_the_importer_unlike_other_fields(): void
    {
        [$company, $brand, $channel] = $this->makeCompanyChannel();
        ChannelCredential::query()->create([
            'channel_id' => $channel->id, 'consumer_key' => 'ck_test', 'consumer_secret' => 'cs_test',
        ]);
        Category::factory()->create();
        Unit::factory()->create();
        $product = $this->makeProduct($company->id, $brand->id, ['sku' => 'SKU-STOCK-001']);

        Http::fake([
            '*/products/categories*' => Http::response([], 200),
            '*/products?*' => Http::response([
                ['id' => 779, 'sku' => 'SKU-STOCK-001', 'name' => 'Original Name', 'status' => 'publish', 'stock_status' => 'outofstock'],
            ], 200, ['X-WP-TotalPages' => '1']),
        ]);

        app(WooCommerceProductImporter::class)->import($channel);

        $this->assertSame('outofstock', $product->fresh()->stock_status?->value);
    }

    // ═══ INVENTORY / STOCK HARD BOUNDARY ═════════════════════════════════════

    public function test_product_sync_never_writes_inventory_or_stock_ledger_tables(): void
    {
        [$company, $brand, $channel] = $this->makeCompanyChannel();
        $product = $this->makeProduct($company->id, $brand->id, ['sku' => 'SKU-STOCKBOUND-001']);

        $inventoryItemsBefore = DB::table('inventory_items')->count();
        $stockMovementsBefore = DB::table('stock_movements')->count();

        app(WooCommerceProductSyncer::class)->syncUpdated($channel, [
            'sku' => 'SKU-STOCKBOUND-001', 'id' => 8001, 'name' => 'Different Name',
        ]);
        app(WooCommerceProductSyncer::class)->syncDeleted($channel, ['sku' => 'SKU-STOCKBOUND-001']);

        $this->assertSame($inventoryItemsBefore, DB::table('inventory_items')->count());
        $this->assertSame($stockMovementsBefore, DB::table('stock_movements')->count());
        unset($product);
    }
}

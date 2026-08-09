<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Products\Presentation\Http\Requests\PatchProductRequest;
use Modules\Inventory\Products\Presentation\Http\Requests\StoreProductRequest;
use Modules\Inventory\Products\Presentation\Http\Requests\UpdateProductRequest;
use Tests\TestCase;

/**
 * TASK-PHASE3-GD2-STEP2-CLOSE-001 — Step 8 write-path regression.
 *
 * Step 8 closed the human write path on `products.stock_status`: it is a
 * WooCommerce channel attribute owned by the inbound importer (E-3 proved it is
 * never published outbound), so a human edit would make the column neither an
 * ERP fact nor a faithful channel mirror.
 *
 * The previous task removed the validation rules but recorded that no executed
 * assertion proved it. These cases close that gap by asserting the rule sets
 * directly — the mechanism is Laravel's: a key absent from rules() never reaches
 * validated(), so it cannot be carried into any action, DTO or model write.
 */
final class ProductStockStatusWritePathTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function rulesOf(string $requestClass): array
    {
        return (new $requestClass)->rules();
    }

    // ── 1 + 2. Human write paths cannot submit stock_status ───────────────────

    public function test_store_product_request_does_not_accept_stock_status(): void
    {
        self::assertArrayNotHasKey('stock_status', $this->rulesOf(StoreProductRequest::class));
    }

    public function test_update_product_request_does_not_accept_stock_status(): void
    {
        self::assertArrayNotHasKey('stock_status', $this->rulesOf(UpdateProductRequest::class));
    }

    public function test_patch_product_request_does_not_accept_stock_status(): void
    {
        self::assertArrayNotHasKey('stock_status', $this->rulesOf(PatchProductRequest::class));
    }

    // ── 5. No unrelated validated product field was rejected ─────────────────

    public function test_store_request_still_accepts_its_other_fields(): void
    {
        $rules = $this->rulesOf(StoreProductRequest::class);

        foreach (['name', 'product_type', 'regular_price', 'sale_price', 'short_description', 'channel_ids'] as $field) {
            self::assertArrayHasKey($field, $rules, "Step 8 must not have removed [{$field}].");
        }
    }

    public function test_patch_request_still_accepts_its_other_fields(): void
    {
        $rules = $this->rulesOf(PatchProductRequest::class);

        foreach (['allow_negative_stock', 'is_active', 'manual_cost', 'regular_price', 'sale_price'] as $field) {
            self::assertArrayHasKey($field, $rules, "Step 8 must not have removed [{$field}].");
        }
    }

    // ── 3 + 4. Machine ingestion is deliberately unaffected ──────────────────

    /**
     * `ProductController::import()` is classified as machine ingestion — the same
     * class of writer as WooCommerceProductImporter. Step 8 scoped itself to the
     * human write path, so the importer must still carry the field.
     */
    public function test_import_path_still_accepts_stock_status(): void
    {
        $controller = file_get_contents(
            base_path('Modules/Inventory/Products/Presentation/Http/Controllers/ProductController.php'),
        );

        self::assertIsString($controller);
        self::assertStringContainsString(
            "'stock_status' => isset(\$data['stock_status'])",
            $controller,
            'Machine ingestion must remain able to write the channel attribute.',
        );
    }

    public function test_inbound_importer_still_maps_stock_status(): void
    {
        $importer = file_get_contents(
            base_path('Modules/Commerce/ProductImport/Application/Services/WooCommerceProductImporter.php'),
        );

        self::assertIsString($importer);
        self::assertStringContainsString("'stock_status' => \$stockStatus", $importer);
    }
}

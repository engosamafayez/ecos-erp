<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\MasterData\Units\Domain\Models\Unit;
use Modules\Organization\Brands\Domain\Models\Brand;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-CUSTOMER-PRODUCT-READMODEL-UI-CONTRACT-CLOSURE-001 · CONTINUATION-001 — D-5.
 *
 * Business contract: EVERY PRODUCT MUST HAVE A UNIT.
 *
 * These tests pin the two write-path rules the contract implies, through the REAL HTTP surface:
 *
 *   CREATE — a product may not be created without a unit.
 *   UPDATE — an existing product's unit may be CHANGED but never CLEARED.
 *
 * They also pin the two things the contract deliberately does NOT do, so a later session cannot
 * "helpfully" add them: no unit is ever guessed for a legacy row, and partial updates that never
 * mention `unit_id` keep working (which is what lets legacy rows stay editable while their
 * remediation is pending).
 *
 * Units are GLOBAL in this platform — `units` has no `company_id` — so there is no
 * cross-company unit rejection to assert. That is the existing architecture, preserved, not
 * invented.
 */
final class ProductUnitContractTest extends TestCase
{
    use DatabaseTransactions;

    private Company $company;

    private Brand $brand;

    private Unit $unit;

    private string $categoryId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->brand = Brand::factory()->create(['company_id' => $this->company->id]);
        $this->unit = Unit::factory()->create();
        $this->categoryId = (string) Product::factory()->create()->category_id;
    }

    private function user(): User
    {
        return User::factory()->create(['company_id' => $this->company->id]);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'sku' => 'PU-'.Str::random(8),
            'name' => 'Unit contract product',
            'brand_id' => $this->brand->id,
            'category_id' => $this->categoryId,
            'unit_id' => $this->unit->id,
            'product_type' => Product::TYPE_FINISHED_GOOD,
            'is_active' => true,
        ], $overrides);
    }

    /**
     * `PUT /api/products/{id}` is a FULL replace — UpdateProductRequest requires sku, name,
     * category and type — so a complete payload is built from the existing row and only the
     * field under test is varied.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function fullUpdatePayload(Product $product, array $overrides = []): array
    {
        return array_merge([
            'sku' => $product->sku,
            'name' => $product->name,
            'brand_id' => $product->brand_id,
            'category_id' => $product->category_id,
            'unit_id' => $product->unit_id,
            'product_type' => $product->product_type,
            'is_active' => (bool) $product->is_active,
        ], $overrides);
    }

    // ── CREATE ────────────────────────────────────────────────────────────────

    /**
     * A valid unit must CLEAR VALIDATION.
     *
     * This deliberately asserts the absence of a `unit_id` validation error rather than a 2xx:
     * `POST /api/products` cannot complete an insert in `ecos_dev_test` because that database
     * carries a `products.company_id` NOT NULL column which does not exist in `ecos_dev` or
     * `ecos_erp`, and the create path never populates it. That is a PRE-EXISTING schema drift
     * unrelated to the Unit contract (recorded in the report), so this test proves the rule it
     * owns — the unit is accepted — without being coupled to that defect.
     */
    public function test_create_with_a_valid_unit_clears_validation(): void
    {
        $response = $this->actingAs($this->user())
            ->postJson('/api/products', $this->payload());

        self::assertNotSame(422, $response->status(), 'a valid unit must not be a validation error');
        $response->assertJsonMissingValidationErrors('unit_id');
    }

    public function test_create_without_a_unit_is_rejected(): void
    {
        $payload = $this->payload();
        unset($payload['unit_id']);

        $this->actingAs($this->user())
            ->postJson('/api/products', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('unit_id');
    }

    public function test_create_with_an_explicitly_null_unit_is_rejected(): void
    {
        $this->actingAs($this->user())
            ->postJson('/api/products', $this->payload(['unit_id' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('unit_id');
    }

    public function test_create_with_an_unknown_unit_is_rejected(): void
    {
        $this->actingAs($this->user())
            ->postJson('/api/products', $this->payload(['unit_id' => (string) Str::uuid()]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('unit_id');
    }

    // ── UPDATE ────────────────────────────────────────────────────────────────

    public function test_update_may_change_the_unit(): void
    {
        $product = Product::factory()->create(['unit_id' => $this->unit->id]);
        $replacement = Unit::factory()->create();

        $response = $this->actingAs($this->user())
            ->putJson("/api/products/{$product->id}", $this->fullUpdatePayload($product, [
                'unit_id' => $replacement->id,
            ]));

        $response->assertJsonMissingValidationErrors('unit_id');
    }

    public function test_update_may_not_clear_the_unit(): void
    {
        $product = Product::factory()->create(['unit_id' => $this->unit->id]);

        $this->actingAs($this->user())
            ->putJson("/api/products/{$product->id}", $this->fullUpdatePayload($product, [
                'unit_id' => null,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('unit_id');

        self::assertSame(
            $this->unit->id,
            (string) $product->refresh()->unit_id,
            'a rejected update must leave the unit intact',
        );
    }

    /**
     * A payload that never mentions `unit_id` must still work. This is what keeps the legacy
     * NULL-unit products editable while their remediation is pending — the enforcement targets
     * NEW violations, it does not lock existing rows out of the application.
     */
    public function test_partial_update_that_omits_the_unit_is_accepted(): void
    {
        $product = Product::factory()->create(['unit_id' => $this->unit->id]);

        $payload = $this->fullUpdatePayload($product);
        unset($payload['unit_id']);

        $this->actingAs($this->user())
            ->putJson("/api/products/{$product->id}", $payload)
            ->assertJsonMissingValidationErrors('unit_id');

        self::assertSame($this->unit->id, (string) $product->refresh()->unit_id);
    }

    // ── REGRESSION — existing valid products are unaffected ──────────────────

    public function test_an_existing_valid_product_keeps_its_unit_through_an_unrelated_edit(): void
    {
        $product = Product::factory()->create(['unit_id' => $this->unit->id, 'is_active' => true]);

        $this->actingAs($this->user())
            ->putJson("/api/products/{$product->id}", $this->fullUpdatePayload($product, [
                'is_active' => false,
            ]))
            ->assertJsonMissingValidationErrors('unit_id');

        self::assertSame($this->unit->id, (string) $product->refresh()->unit_id);
    }

    // ── NO GUESSING — the prohibition is pinned, not just documented ─────────

    /**
     * Nothing in the write path may invent a unit. If a create is rejected for a missing unit,
     * no product row may be left behind carrying a "helpfully" chosen one.
     */
    public function test_a_rejected_create_persists_no_product_and_invents_no_unit(): void
    {
        $payload = $this->payload();
        unset($payload['unit_id']);

        $before = Product::query()->count();

        $this->actingAs($this->user())
            ->postJson('/api/products', $payload)
            ->assertStatus(422);

        self::assertSame($before, Product::query()->count(), 'no product may be created');
        self::assertNull(
            Product::query()->where('sku', $payload['sku'])->first(),
            'a rejected create must not persist a product with an invented unit',
        );
    }
}

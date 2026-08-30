<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Inventory\Products\Domain\Services\SkuGenerator;
use Modules\MasterData\Categories\Domain\Models\Category;
use Modules\MasterData\Units\Domain\Models\Unit;
use Modules\Organization\Brands\Domain\Models\Brand;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ORDERS-INVENTORY-MANUAL-REMEDIATION-001 — Decision 1 (company-scoped SKU).
 *
 *   {COMPANY_CODE}-{TYPE_PREFIX}-{NNNNNN}   e.g. AAA-FG-000001
 *
 * The sequence is company-scoped; the embedded company code keeps SKUs globally
 * unique (the existing DB unique index is preserved); generation is deterministic
 * and server-authoritative (never advisory, never a UUID, never the product name).
 */
class SkuGenerationTest extends TestCase
{
    use DatabaseTransactions;

    private function generator(): SkuGenerator
    {
        return app(SkuGenerator::class);
    }

    // ── Generator unit behaviour ────────────────────────────────────────────────

    public function test_first_sku_for_a_company_and_type_is_sequence_one(): void
    {
        $company = Company::factory()->create(['code' => 'AAA']);

        self::assertSame('AAA-FG-000001', $this->generator()->generate($company->id, Product::TYPE_FINISHED_GOOD));
    }

    public function test_sequence_increments_within_a_company(): void
    {
        $company = Company::factory()->create(['code' => 'AAA']);
        Product::factory()->create([
            'company_id' => $company->id,
            'sku' => 'AAA-FG-000001',
            'product_type' => Product::TYPE_FINISHED_GOOD,
        ]);

        self::assertSame('AAA-FG-000002', $this->generator()->generate($company->id, Product::TYPE_FINISHED_GOOD));
    }

    public function test_sequence_is_company_scoped_yet_globally_unique(): void
    {
        $a = Company::factory()->create(['code' => 'AAA']);
        $b = Company::factory()->create(['code' => 'BBB']);

        Product::factory()->create(['company_id' => $a->id, 'sku' => 'AAA-FG-000001', 'product_type' => Product::TYPE_FINISHED_GOOD]);

        // B's counter is independent (starts at 1), and the company code makes the
        // two SKUs globally distinct even though both are "sequence 1".
        self::assertSame('BBB-FG-000001', $this->generator()->generate($b->id, Product::TYPE_FINISHED_GOOD));
        self::assertSame('AAA-FG-000002', $this->generator()->generate($a->id, Product::TYPE_FINISHED_GOOD));
    }

    public function test_type_prefix_differs_by_product_type(): void
    {
        $company = Company::factory()->create(['code' => 'AAA']);

        self::assertStringStartsWith('AAA-FG-', $this->generator()->generate($company->id, Product::TYPE_FINISHED_GOOD));
        self::assertStringStartsWith('AAA-RM-', $this->generator()->generate($company->id, Product::TYPE_RAW_MATERIAL));
        self::assertStringStartsWith('AAA-PM-', $this->generator()->generate($company->id, Product::TYPE_PACKAGING_MATERIAL));
    }

    public function test_soft_deleted_sku_number_is_not_reissued(): void
    {
        $company = Company::factory()->create(['code' => 'AAA']);
        $p = Product::factory()->create([
            'company_id' => $company->id,
            'sku' => 'AAA-FG-000001',
            'product_type' => Product::TYPE_FINISHED_GOOD,
        ]);
        $p->delete();

        self::assertSame('AAA-FG-000002', $this->generator()->generate($company->id, Product::TYPE_FINISHED_GOOD));
    }

    // ── Create path — auto-generation through the real HTTP surface ─────────────

    /** @return array<string, mixed> */
    private function payload(Company $company, array $overrides = []): array
    {
        $brand = Brand::factory()->create(['company_id' => $company->id]);
        $unit = Unit::factory()->create();
        $category = Category::factory()->create(); // default scope 'product'

        return array_merge([
            'name' => 'Zephyr Gadget',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'product_type' => Product::TYPE_FINISHED_GOOD,
            'is_active' => true,
        ], $overrides);
    }

    public function test_create_without_sku_assigns_a_company_scoped_sku(): void
    {
        $company = Company::factory()->create(['code' => 'AAA']);
        $user = User::factory()->create(['company_id' => $company->id]);

        $response = $this->actingAs($user)->postJson('/api/products', $this->payload($company));

        self::assertContains($response->status(), [200, 201], $response->getContent());
        $id = $response->json('data.id') ?? $response->json('id');
        self::assertSame('AAA-FG-000001', (string) DB::table('products')->where('id', $id)->value('sku'));

        // A second create advances the company sequence.
        $second = $this->actingAs($user)->postJson('/api/products', $this->payload($company));
        $id2 = $second->json('data.id') ?? $second->json('id');
        self::assertSame('AAA-FG-000002', (string) DB::table('products')->where('id', $id2)->value('sku'));
    }

    public function test_generated_sku_never_derives_from_the_product_name(): void
    {
        $company = Company::factory()->create(['code' => 'AAA']);
        $user = User::factory()->create(['company_id' => $company->id]);

        $response = $this->actingAs($user)->postJson('/api/products', $this->payload($company, ['name' => 'Zephyr Gadget']));
        $id = $response->json('data.id') ?? $response->json('id');
        $sku = (string) DB::table('products')->where('id', $id)->value('sku');

        self::assertStringNotContainsStringIgnoringCase('zephyr', $sku);
        self::assertStringNotContainsStringIgnoringCase('gadget', $sku);
    }

    public function test_a_supplied_sku_is_preserved_and_remains_globally_unique(): void
    {
        $company = Company::factory()->create(['code' => 'AAA']);
        $user = User::factory()->create(['company_id' => $company->id]);

        $first = $this->actingAs($user)->postJson('/api/products', $this->payload($company, ['sku' => 'CUSTOM-XYZ-1']));
        self::assertContains($first->status(), [200, 201], $first->getContent());
        $id = $first->json('data.id') ?? $first->json('id');
        self::assertSame('CUSTOM-XYZ-1', (string) DB::table('products')->where('id', $id)->value('sku'));

        // The global unique constraint is preserved: a duplicate supplied SKU is rejected.
        $this->actingAs($user)->postJson('/api/products', $this->payload($company, ['sku' => 'CUSTOM-XYZ-1']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('sku');
    }
}

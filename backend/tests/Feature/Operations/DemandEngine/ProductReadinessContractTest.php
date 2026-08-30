<?php

declare(strict_types=1);

namespace Tests\Feature\Operations\DemandEngine;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Manufacturing\BillsOfMaterials\Domain\Services\ActiveRecipeResolver;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Operations\DemandAnalysis\Application\Services\DemandReadRepository;
use Modules\Operations\DemandAnalysis\Application\Services\MaterialDemandCalculator;
use Modules\Operations\DemandAnalysis\Application\Services\ProductReadinessCalculator;
use Modules\Operations\Preparation\Domain\Enums\WaveStatus;
use Modules\Operations\Preparation\Domain\Models\PreparationWave;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ORDERS-PREPARATION-PAYMENT-FINAL-FIX-001 — the owner's Preparation contract.
 *
 * MISSING MATERIAL and PREPARATION ELIGIBILITY are TWO DIFFERENT CONCEPTS:
 *
 *   missing_qty  — ALWAYS the real physical shortage. Procurement must see it.
 *   readiness    — may preparation proceed despite that shortage?
 *
 *   missing > 0 AND allow_negative = true   → READY
 *   missing > 0 AND allow_negative = false  → WAITING_MATERIAL
 *   missing = 0                             → READY
 *
 * Readiness is PER PRODUCT: the wave is never blocked, other products stay preparable,
 * and no order status or reservation is read or written.
 */
final class ProductReadinessContractTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Warehouse $warehouse;

    private MaterialDemandCalculator $materials;

    private ProductReadinessCalculator $readiness;

    private DemandReadRepository $repository;

    private string $categoryId;

    private string $unitId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
        $this->categoryId = (string) \Modules\MasterData\Categories\Domain\Models\Category::factory()->create()->id;
        $this->unitId = (string) \Modules\MasterData\Units\Domain\Models\Unit::factory()->create()->id;

        $this->materials = new MaterialDemandCalculator(new ActiveRecipeResolver);
        $this->readiness = new ProductReadinessCalculator($this->materials);
        $this->repository = app(DemandReadRepository::class);
    }

    // ── Fixture ──────────────────────────────────────────────────────────────

    private function makeWave(): PreparationWave
    {
        return PreparationWave::create([
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouse->id,
            'wave_number' => 'PREP-READY-'.random_int(1, 99999),
            'planning_date' => today()->toDateString(),
            'status' => WaveStatus::Collecting->value,
            'orders_count' => 0, 'products_count' => 0, 'lines_count' => 0,
            'total_units_required' => 0, 'total_units_prepared' => 0,
            'shortage_detected' => false,
            'wave_type' => 'engine',
            'created_by' => 'test', 'updated_by' => 'test',
        ]);
    }

    private function makeProduct(string $name, string $type, bool $allowNegative = false): string
    {
        $id = (string) Str::uuid();
        DB::table('products')->insert([
            'id' => $id,
            'company_id' => $this->company->id,
            'category_id' => $this->categoryId,
            'unit_id' => $this->unitId,
            'name' => $name,
            'sku' => 'SKU-'.random_int(100000, 999999),
            'product_type' => $type,
            'allow_negative_stock' => $allowNegative,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /** @param array<string, float> $ingredients */
    private function makeBom(string $productId, array $ingredients): void
    {
        $bomId = (string) Str::uuid();
        DB::table('bills_of_materials')->insert([
            'id' => $bomId,
            'product_id' => $productId,
            'bom_number' => 'BOM-'.random_int(100000, 999999),
            'version' => 1, 'bom_version_number' => 1,
            'is_active' => true, 'yield_quantity' => 1, 'recipe_cost' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ($ingredients as $materialId => $qty) {
            DB::table('bill_of_material_lines')->insert([
                'id' => (string) Str::uuid(),
                'bom_id' => $bomId,
                'raw_material_id' => $materialId,
                'quantity' => $qty,
                'waste_percentage' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function seedProductDemand(PreparationWave $wave, string $productId, float $requiredQty): void
    {
        DB::table('wave_product_demand')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $wave->company_id,
            'warehouse_id' => $wave->warehouse_id,
            'preparation_wave_id' => $wave->id,
            'product_id' => $productId,
            'product_name' => 'Product '.$productId,
            'required_qty' => $requiredQty,
            'prepared_qty' => 0,
            'remaining_qty' => $requiredQty,
            'orders_count' => 1,
            'completion_pct' => 0,
            'last_calculated_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedInventory(string $productId, float $onHand, float $reserved = 0.0): void
    {
        DB::table('inventory_items')->insert([
            'id' => (string) Str::uuid(),
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $productId,
            'company_id' => $this->company->id,
            'on_hand_qty' => $onHand,
            'reserved_qty' => $reserved,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Run the material layer then readiness, exactly as the builder does. */
    private function project(PreparationWave $wave): void
    {
        $this->repository->upsertMaterialDemand($this->materials->calculate($wave));
        $this->repository->upsertProductReadiness($wave->id, $this->readiness->calculate($wave));
    }

    private function materialRow(PreparationWave $wave, string $materialId): object
    {
        return DB::table('wave_material_demand')
            ->where('preparation_wave_id', $wave->id)
            ->where('material_id', $materialId)
            ->first();
    }

    private function productRow(PreparationWave $wave, string $productId): object
    {
        return DB::table('wave_product_demand')
            ->where('preparation_wave_id', $wave->id)
            ->where('product_id', $productId)
            ->first();
    }

    // ── The owner's two worked examples ──────────────────────────────────────

    /** Required 7 · Available 0 · allow_negative = TRUE → missing 7, status READY. */
    public function test_shortage_with_allow_negative_is_reported_and_still_ready(): void
    {
        $wave = $this->makeWave();
        $product = $this->makeProduct('FG', 'finished_good');
        $material = $this->makeProduct('RM', 'raw_material', allowNegative: true);

        $this->seedProductDemand($wave, $product, 7.0);
        $this->makeBom($product, [$material => 1.0]);
        $this->seedInventory($material, onHand: 0.0);

        $this->project($wave);

        self::assertEquals(7.0, (float) $this->materialRow($wave, $material)->missing_qty,
            'Procurement must see the real 7-unit shortage');
        self::assertSame(ProductReadinessCalculator::READY, $this->productRow($wave, $product)->material_status,
            'allow_negative permits preparation despite the shortage');
    }

    /** Required 7 · Available 0 · allow_negative = FALSE → missing 7, status WAITING_MATERIAL. */
    public function test_shortage_without_allow_negative_blocks_that_product(): void
    {
        $wave = $this->makeWave();
        $product = $this->makeProduct('FG', 'finished_good');
        $material = $this->makeProduct('RM', 'raw_material', allowNegative: false);

        $this->seedProductDemand($wave, $product, 7.0);
        $this->makeBom($product, [$material => 1.0]);
        $this->seedInventory($material, onHand: 0.0);

        $this->project($wave);

        self::assertEquals(7.0, (float) $this->materialRow($wave, $material)->missing_qty);
        self::assertSame(ProductReadinessCalculator::WAITING_MATERIAL, $this->productRow($wave, $product)->material_status);
        self::assertSame(1, (int) $this->productRow($wave, $product)->blocking_materials_count);
    }

    /** No shortage at all → READY. */
    public function test_no_shortage_is_ready(): void
    {
        $wave = $this->makeWave();
        $product = $this->makeProduct('FG', 'finished_good');
        $material = $this->makeProduct('RM', 'raw_material');

        $this->seedProductDemand($wave, $product, 5.0);
        $this->makeBom($product, [$material => 1.0]);
        $this->seedInventory($material, onHand: 50.0);

        $this->project($wave);

        self::assertEquals(0.0, (float) $this->materialRow($wave, $material)->missing_qty);
        self::assertSame(ProductReadinessCalculator::READY, $this->productRow($wave, $product)->material_status);
    }

    // ── Readiness is per product, never per wave ─────────────────────────────

    public function test_readiness_is_independent_per_product_and_never_blocks_the_wave(): void
    {
        $wave = $this->makeWave();

        $ok = $this->makeProduct('FG-OK', 'finished_good');
        $blocked = $this->makeProduct('FG-BLOCKED', 'finished_good');
        $goodMaterial = $this->makeProduct('RM-OK', 'raw_material');
        $shortMaterial = $this->makeProduct('RM-SHORT', 'raw_material', allowNegative: false);

        $this->seedProductDemand($wave, $ok, 2.0);
        $this->seedProductDemand($wave, $blocked, 1.0);
        $this->makeBom($ok, [$goodMaterial => 1.0]);
        $this->makeBom($blocked, [$shortMaterial => 1.0]);
        $this->seedInventory($goodMaterial, onHand: 100.0);
        $this->seedInventory($shortMaterial, onHand: 0.0);

        $this->project($wave);

        self::assertSame(ProductReadinessCalculator::READY, $this->productRow($wave, $ok)->material_status,
            'a sibling product with available materials stays preparable');
        self::assertSame(ProductReadinessCalculator::WAITING_MATERIAL, $this->productRow($wave, $blocked)->material_status);

        // The wave itself is untouched — no global block.
        $wave->refresh();
        self::assertSame(WaveStatus::Collecting, $wave->status, 'the wave is never blocked by a product shortage');
        self::assertFalse((bool) $wave->shortage_detected, 'shortage_detected is not wired by this contract');
    }

    // ── Recovery: material arrives → WAITING_MATERIAL becomes READY ──────────

    public function test_product_becomes_ready_once_the_missing_material_arrives(): void
    {
        $wave = $this->makeWave();
        $product = $this->makeProduct('FG', 'finished_good');
        $material = $this->makeProduct('RM', 'raw_material', allowNegative: false);

        $this->seedProductDemand($wave, $product, 4.0);
        $this->makeBom($product, [$material => 1.0]);
        $this->seedInventory($material, onHand: 0.0);

        $this->project($wave);
        self::assertSame(ProductReadinessCalculator::WAITING_MATERIAL, $this->productRow($wave, $product)->material_status);

        // The material physically arrives.
        DB::table('inventory_items')
            ->where('product_id', $material)
            ->where('warehouse_id', $this->warehouse->id)
            ->update(['on_hand_qty' => 10.0]);

        $this->project($wave);

        self::assertEquals(0.0, (float) $this->materialRow($wave, $material)->missing_qty);
        self::assertSame(ProductReadinessCalculator::READY, $this->productRow($wave, $product)->material_status,
            'the product recovers automatically once the shortage is resolved');
    }

    /** Re-projecting the same state converges — no flip-flop, no accumulation. */
    public function test_reprojection_is_idempotent(): void
    {
        $wave = $this->makeWave();
        $product = $this->makeProduct('FG', 'finished_good');
        $material = $this->makeProduct('RM', 'raw_material', allowNegative: false);

        $this->seedProductDemand($wave, $product, 3.0);
        $this->makeBom($product, [$material => 1.0]);
        $this->seedInventory($material, onHand: 0.0);

        $this->project($wave);
        $this->project($wave);
        $this->project($wave);

        self::assertSame(ProductReadinessCalculator::WAITING_MATERIAL, $this->productRow($wave, $product)->material_status);
        self::assertSame(1, (int) $this->productRow($wave, $product)->blocking_materials_count);
        self::assertSame(1, DB::table('wave_material_demand')->where('preparation_wave_id', $wave->id)->count());
    }

    /** A product blocked by one material is not rescued by a second, available one. */
    public function test_one_blocking_material_is_enough_to_hold_the_product(): void
    {
        $wave = $this->makeWave();
        $product = $this->makeProduct('FG', 'finished_good');
        $available = $this->makeProduct('RM-A', 'raw_material');
        $blocking = $this->makeProduct('RM-B', 'raw_material', allowNegative: false);
        $onCredit = $this->makeProduct('RM-C', 'raw_material', allowNegative: true);

        $this->seedProductDemand($wave, $product, 1.0);
        $this->makeBom($product, [$available => 1.0, $blocking => 1.0, $onCredit => 1.0]);
        $this->seedInventory($available, onHand: 10.0);
        $this->seedInventory($blocking, onHand: 0.0);
        $this->seedInventory($onCredit, onHand: 0.0);

        $this->project($wave);

        $row = $this->productRow($wave, $product);
        self::assertSame(ProductReadinessCalculator::WAITING_MATERIAL, $row->material_status);
        self::assertSame(1, (int) $row->blocking_materials_count,
            'only the non-credit shortage blocks; the allow_negative one does not');
        // …and the credit-covered material still reports its real shortage.
        self::assertEquals(1.0, (float) $this->materialRow($wave, $onCredit)->missing_qty);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Operations\DemandEngine;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Commerce\Orders\Application\Actions\ReconcileOrderMaterialReservationsAction;
use Modules\Inventory\InventoryItems\Domain\Enums\LedgerMovementType;
use Modules\Manufacturing\BillsOfMaterials\Domain\Services\ActiveRecipeResolver;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Operations\DemandAnalysis\Application\Services\MaterialDemandCalculator;
use Modules\Operations\Preparation\Domain\Enums\WaveStatus;
use Modules\Operations\Preparation\Domain\Models\PreparationWave;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-PREPARATION-MATERIAL-DEMAND-CALCULATION-REPAIR-002,
 * amended by TASK-PREPARATION-MANUAL-REMEDIATION-001 (ADR-027 §18).
 *
 * Locks the raw-material availability contract for the PREPARATION WAVE projection:
 *
 *     effective_reserved = reserved_qty - own_active_member - postponed_member
 *     available_qty      = max(on_hand_qty - effective_reserved, 0)   ← floored (§18.2)
 *     missing_qty        = max(required_qty - available_qty, 0)
 *
 * ADR-027 §17.3 made this figure signed; §18 re-floors it for the Preparation wave view
 * ONLY, so a competing over-commitment can no longer push operator-facing Missing beyond
 * Required. The inventory-wide (`InventoryItem::availableQty`) and Manufacturing figures
 * stay signed under §17.3 and are enforced by their own, separate tests — untouched here.
 *
 * Scope is deliberately narrow. This covers ONLY raw-material availability inside
 * MaterialDemandCalculator. It says nothing about finished-product availability,
 * order reservation, inventory-wide availability or the Preparation entry gate.
 *
 * Aggregation is asserted to be UNCHANGED by the amendment (Part 11): only the
 * availability arithmetic moved.
 */
final class MaterialAvailabilityContractTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Warehouse $warehouse;

    private MaterialDemandCalculator $calculator;

    private string $categoryId;

    private string $unitId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);

        $this->categoryId = (string) \Modules\MasterData\Categories\Domain\Models\Category::factory()->create()->id;
        $this->unitId = (string) \Modules\MasterData\Units\Domain\Models\Unit::factory()->create()->id;

        $this->calculator = new MaterialDemandCalculator(new ActiveRecipeResolver);
    }

    // ── Part 6 — the four standard cases ──────────────────────────────────────

    /**
     * @return array<string, array{float, float, float, float, float}>
     */
    public static function standardCases(): array
    {
        // label => [on_hand, reserved, required, expected_available, expected_missing]
        return [
            'CASE A nothing reserved' => [10.0, 0.0, 10.0, 10.0, 0.0],
            'CASE B partially reserved' => [10.0, 3.0, 10.0, 7.0, 3.0],
            // ADR-027 §18.2 — the Preparation wave figure is FLOORED at zero. An
            // over-commitment by competing demand caps availability at nothing, so Missing
            // is capped at Required and never runs past it in the operator view. The raw
            // signed deficit stays visible to Inventory/Manufacturing via §17.3.
            // available = max(10 − 15, 0) = 0 · missing = max(10 − 0, 0) = 10
            'CASE C over reserved (floored)' => [10.0, 15.0, 10.0, 0.0, 10.0],
            'CASE D short and reserved' => [5.0, 2.0, 10.0, 3.0, 7.0],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('standardCases')]
    public function test_raw_material_availability_contract(
        float $onHand,
        float $reserved,
        float $required,
        float $expectedAvailable,
        float $expectedMissing,
    ): void {
        $wave = $this->makeWave();
        $product = $this->makeProduct('FG', 'finished_good');
        $material = $this->makeProduct('RM', 'raw_material');

        $this->seedProductDemand($wave, $product, $required);
        $this->makeBom($product, [$material => 1.0]);
        $this->seedInventory($material, onHand: $onHand, reserved: $reserved);

        $row = collect($this->calculator->calculate($wave))->firstWhere('material_id', $material);
        self::assertNotNull($row);

        fwrite(STDERR, sprintf(
            PHP_EOL.'  on_hand=%.1f reserved=%.1f required=%.1f -> available=%.1f missing=%.1f'.PHP_EOL,
            $onHand, $reserved, $required, (float) $row['available_qty'], (float) $row['missing_qty'],
        ));

        self::assertEquals($expectedAvailable, (float) $row['available_qty'], 'available_qty');
        self::assertEquals($expectedMissing, (float) $row['missing_qty'], 'missing_qty');
    }

    // ── Part 5 — availability is floored for the Preparation wave view (§18.2) ─

    /**
     * ADR-027 §18.2 re-floors the Preparation wave figure at zero.
     *
     * §17.3 had made it signed so the raw over-commitment stayed visible everywhere. That
     * proved wrong for the operator-facing wave view: a signed-negative availability makes
     * `missing = required − available` exceed Required, which reads as a data error to the
     * planner. §18 floors availability (and therefore caps Missing at Required) for the
     * Preparation projection ONLY — the inventory-wide and Manufacturing figures stay
     * signed under §17.3, enforced by their own separate tests.
     */
    public function test_availability_is_floored_at_zero_for_the_preparation_view(): void
    {
        $wave = $this->makeWave();
        $product = $this->makeProduct('FG', 'finished_good');
        $material = $this->makeProduct('RM', 'raw_material');

        $this->seedProductDemand($wave, $product, 10.0);
        $this->makeBom($product, [$material => 1.0]);
        // Massively over-reserved.
        $this->seedInventory($material, onHand: 10.0, reserved: 999.0);

        $row = collect($this->calculator->calculate($wave))->firstWhere('material_id', $material);

        // max(10 − 999, 0) = 0: the floor holds; Preparation never shows negative Available.
        self::assertEquals(0.0, (float) $row['available_qty'], 'available is floored at zero (§18.2)');
        // missing = max(required − available, 0) = max(10 − 0, 0) = 10, capped at Required —
        // it can no longer absorb the negative balance and overshoot Required.
        self::assertEquals(10.0, (float) $row['missing_qty'], 'missing is capped at required');
        self::assertLessThanOrEqual(
            (float) $row['required_qty'],
            (float) $row['missing_qty'],
            'Missing must never exceed Required in the Preparation wave view.',
        );
        // coverage = min(100, (0 / 10) × 100) = 0. Floored availability means non-negative
        // coverage as well.
        self::assertEquals(0.0, (float) $row['coverage_pct'], 'coverage collapses to zero, not negative');
    }

    // ── Part 5b — a postponed member's reservation leaves the active view (§18.3)

    /**
     * ADR-027 §18.3 / owner Example 2. A wave member that has been POSTPONED (postponed_at
     * set, released_at still NULL) keeps its live `sales_order_material` reservation, but
     * that reservation must NOT reduce the availability the active orders see — its Required
     * has already left the projection, and postponement releases no inventory.
     *
     * on_hand 100, reserved_qty 12 (5 competing + 7 held by the postponed member). Without
     * §18.3 the postponed 7 would read as competing demand → available 88. With it, only the
     * genuinely competing 5 counts → available 95.
     */
    public function test_a_postponed_members_reservation_does_not_reduce_active_wave_availability(): void
    {
        $wave = $this->makeWave();
        $product = $this->makeProduct('FG', 'finished_good');
        $material = $this->makeProduct('RM', 'raw_material');

        $this->seedProductDemand($wave, $product, 10.0);
        $this->makeBom($product, [$material => 1.0]);
        // 12 reserved = 5 competing (foreign) + 7 still held by the postponed member.
        $this->seedInventory($material, onHand: 100.0, reserved: 12.0);
        $this->seedPostponedMemberReservation($wave, $material, qty: 7.0);

        $row = collect($this->calculator->calculate($wave))->firstWhere('material_id', $material);
        self::assertNotNull($row);

        // max(100 − (12 − 0 own − 7 postponed), 0) = 95, not 88.
        self::assertEquals(95.0, (float) $row['available_qty'],
            'a postponed member reservation must not reduce active-wave availability (§18.3)');
        self::assertEquals(0.0, (float) $row['missing_qty']);
    }

    /**
     * The §18.3 boundary: once the membership is RELEASED (released_at set — the wave ended
     * and the order carries over), the reservation is no longer parked here and reverts to
     * ordinary competing demand. available = max(100 − 12, 0) = 88.
     */
    public function test_a_released_members_reservation_is_competing_demand_again(): void
    {
        $wave = $this->makeWave();
        $product = $this->makeProduct('FG', 'finished_good');
        $material = $this->makeProduct('RM', 'raw_material');

        $this->seedProductDemand($wave, $product, 10.0);
        $this->makeBom($product, [$material => 1.0]);
        $this->seedInventory($material, onHand: 100.0, reserved: 12.0);
        // Postponed AND released — no longer a live member of this wave.
        $this->seedPostponedMemberReservation($wave, $material, qty: 7.0, released: true);

        $row = collect($this->calculator->calculate($wave))->firstWhere('material_id', $material);

        self::assertEquals(88.0, (float) $row['available_qty'],
            'a released member reservation is competing demand again (§18.3 boundary)');
    }

    // ── Part 5c — Allow Negative is a soft shortage (§18.4 / P-03) ─────────────

    /**
     * SUPERSEDED CONTRACT — owner decision 2026-08-20 (ADR-027 §18.4 v1.6).
     *
     * This case previously asserted `missing_qty = 0` and `coverage_pct = 100` for an
     * allow-negative material. That conflated two different questions and hid a genuine
     * shortage from Procurement.
     *
     * The approved rule separates them: `missing_qty` is ALWAYS the real physical
     * shortage, and `allow_negative_stock` decides only whether preparation may proceed
     * despite it (asserted per product in ProductReadinessContractTest). The real business
     * case is a material already purchased whose receipt has not been entered yet —
     * preparation continues, and the buyer still sees the outstanding quantity.
     *
     * The P-03 behaviour that must not regress ("allow_negative → preparation is allowed")
     * is preserved, and is now asserted where it actually lives: readiness, not Missing.
     */
    public function test_an_allow_negative_material_still_reports_its_real_physical_shortage(): void
    {
        $wave = $this->makeWave();
        $product = $this->makeProduct('FG', 'finished_good');
        $material = $this->makeProduct('RM', 'raw_material', allowNegative: true);

        $this->seedProductDemand($wave, $product, 10.0);
        $this->makeBom($product, [$material => 1.0]);
        // Only 2 physically present against a requirement of 10.
        $this->seedInventory($material, onHand: 2.0, reserved: 0.0);

        $row = collect($this->calculator->calculate($wave))->firstWhere('material_id', $material);
        self::assertNotNull($row);

        self::assertEquals(8.0, (float) $row['missing_qty'],
            'the real physical shortage is reported even when the material is drawable on credit');
        self::assertEquals(20.0, (float) $row['coverage_pct'],
            'coverage reports the true physical position (2 of 10), never a forced 100');
        self::assertEquals(2.0, (float) $row['available_qty'],
            'the real physical position is surfaced unchanged — on_hand is never invented');
        self::assertTrue((bool) $row['allow_negative'],
            'the flag is carried on the row so readiness can permit preparation despite the shortage');
    }

    /**
     * Control: the identical shortfall on an ordinary (non-allow-negative) material still
     * surfaces as Missing, so the flag — not a blanket change — is what suppresses it.
     */
    public function test_a_non_allow_negative_material_still_reports_its_shortfall(): void
    {
        $wave = $this->makeWave();
        $product = $this->makeProduct('FG', 'finished_good');
        $material = $this->makeProduct('RM', 'raw_material'); // allowNegative defaults false

        $this->seedProductDemand($wave, $product, 10.0);
        $this->makeBom($product, [$material => 1.0]);
        $this->seedInventory($material, onHand: 2.0, reserved: 0.0);

        $row = collect($this->calculator->calculate($wave))->firstWhere('material_id', $material);

        self::assertEquals(8.0, (float) $row['missing_qty'],
            'an ordinary material still reports its 8-unit shortfall');
    }

    // ── Part 10 — materials are independent ───────────────────────────────────

    public function test_each_material_is_evaluated_independently(): void
    {
        $wave = $this->makeWave();
        $product = $this->makeProduct('FG', 'finished_good');
        $materialA = $this->makeProduct('RM A', 'raw_material');
        $materialB = $this->makeProduct('RM B', 'raw_material');

        // 10 units of FG: A at 1/unit => 10 required, B at 0.5/unit => 5 required.
        $this->seedProductDemand($wave, $product, 10.0);
        $this->makeBom($product, [$materialA => 1.0, $materialB => 0.5]);

        $this->seedInventory($materialA, onHand: 10.0, reserved: 3.0);
        $this->seedInventory($materialB, onHand: 8.0, reserved: 0.0);

        $rows = collect($this->calculator->calculate($wave));
        $rowA = $rows->firstWhere('material_id', $materialA);
        $rowB = $rows->firstWhere('material_id', $materialB);

        fwrite(STDERR, PHP_EOL
            .'  RM A: required='.(float) $rowA['required_qty'].' available='.(float) $rowA['available_qty']
            .' missing='.(float) $rowA['missing_qty'].PHP_EOL
            .'  RM B: required='.(float) $rowB['required_qty'].' available='.(float) $rowB['available_qty']
            .' missing='.(float) $rowB['missing_qty'].PHP_EOL);

        self::assertEquals(10.0, (float) $rowA['required_qty']);
        self::assertEquals(7.0, (float) $rowA['available_qty'], 'A: 10 - 3');
        self::assertEquals(3.0, (float) $rowA['missing_qty']);

        self::assertEquals(5.0, (float) $rowB['required_qty']);
        self::assertEquals(8.0, (float) $rowB['available_qty'], 'B keeps its full stock — no inherited reservation');
        self::assertEquals(0.0, (float) $rowB['missing_qty']);
    }

    // ── Part 11 — aggregation must be untouched by this repair ────────────────

    public function test_demand_aggregation_is_unchanged(): void
    {
        $wave = $this->makeWave();
        $productA = $this->makeProduct('A', 'finished_good');
        $productB = $this->makeProduct('B', 'finished_good');
        $material = $this->makeProduct('RM', 'raw_material');

        // Two products, demand 3 and 2, both consuming 1 unit of the same material.
        $this->seedProductDemand($wave, $productA, 3.0);
        $this->seedProductDemand($wave, $productB, 2.0);
        $this->makeBom($productA, [$material => 1.0]);
        $this->makeBom($productB, [$material => 1.0]);
        $this->seedInventory($material, onHand: 10.0, reserved: 0.0);

        $rows = collect($this->calculator->calculate($wave))->where('material_id', $material);

        self::assertCount(1, $rows, 'still a single aggregated row per material');
        self::assertEquals(5.0, (float) $rows->first()['required_qty'], '3 + 2 = 5, aggregation unchanged');
    }

    // ── Part 18 — untouched projection fields ─────────────────────────────────

    public function test_expected_today_and_in_transit_are_unchanged(): void
    {
        $wave = $this->makeWave();
        $product = $this->makeProduct('FG', 'finished_good');
        $material = $this->makeProduct('RM', 'raw_material');

        $this->seedProductDemand($wave, $product, 10.0);
        $this->makeBom($product, [$material => 1.0]);
        $this->seedInventory($material, onHand: 15.0, reserved: 8.0);

        $row = collect($this->calculator->calculate($wave))->firstWhere('material_id', $material);

        self::assertEquals(0.0, (float) $row['expected_today'], 'out of scope, must stay 0.0');
        self::assertEquals(0.0, (float) $row['in_transit_qty'], 'out of scope, must stay 0.0');
        // reserved_qty was already reported before the repair; it must still be reported.
        self::assertEquals(8.0, (float) $row['reserved_qty'], 'reserved is still surfaced on the row');
    }

    // ── Part 16 — company isolation ───────────────────────────────────────────

    /**
     * Another company's stock must never satisfy this wave's material demand.
     *
     * The isolation mechanism is warehouse scoping: the calculator reads stock only
     * from the wave's own warehouse, and a warehouse belongs to exactly one company.
     * The repair must not widen that scope.
     */
    public function test_another_companys_stock_does_not_satisfy_this_waves_demand(): void
    {
        $otherCompany = Company::factory()->create();
        $otherWarehouse = Warehouse::factory()->create(['company_id' => $otherCompany->id]);

        $wave = $this->makeWave();
        $product = $this->makeProduct('FG', 'finished_good');
        $material = $this->makeProduct('RM', 'raw_material');

        $this->seedProductDemand($wave, $product, 10.0);
        $this->makeBom($product, [$material => 1.0]);

        // Plenty of stock — but it belongs to ANOTHER company's warehouse.
        DB::table('inventory_items')->insert([
            'id' => (string) Str::uuid(),
            'warehouse_id' => $otherWarehouse->id,
            'product_id' => $material,
            'company_id' => $otherCompany->id,
            'on_hand_qty' => 100.0,
            'reserved_qty' => 0.0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $row = collect($this->calculator->calculate($wave))->firstWhere('material_id', $material);
        self::assertNotNull($row);

        fwrite(STDERR, PHP_EOL.'  COMPANY ISOLATION: other-company stock=100 -> available='
            .(float) $row['available_qty'].' missing='.(float) $row['missing_qty'].PHP_EOL);

        self::assertEquals(0.0, (float) $row['available_qty'],
            "Another company's stock must be invisible to this wave.");
        self::assertEquals(10.0, (float) $row['missing_qty'],
            'The entire requirement is missing — foreign stock cannot cover it.');
        self::assertSame($this->company->id, $row['company_id'],
            'The row is stamped with the wave\'s own company.');
    }

    // ── Helpers (mirrors MaterialDemandCalculatorTest) ────────────────────────

    private function makeWave(): PreparationWave
    {
        return PreparationWave::create([
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouse->id,
            'wave_number' => 'PREP-CONTRACT-'.random_int(1, 99999),
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

    private function seedInventory(string $productId, float $onHand, float $reserved): void
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

    /**
     * A wave member holding a live sales_order_material reservation for $materialId.
     *
     * Mirrors what ReconcileOrderMaterialReservationsAction writes: one membership row and
     * one `reservation` ledger entry against the same order id. `order_id`/`reference_id`
     * are plain UUIDs (no FK), so no real Order row is needed. Postponed by default; pass
     * released:true to also close the membership (released_at) for the boundary case.
     */
    private function seedPostponedMemberReservation(
        PreparationWave $wave,
        string $materialId,
        float $qty,
        bool $released = false,
    ): void {
        $orderId = (string) Str::uuid();

        DB::table('preparation_wave_orders')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $wave->company_id,
            'preparation_wave_id' => $wave->id,
            'order_id' => $orderId,
            'order_number' => 'ORD-'.random_int(100000, 999999),
            'order_confirmed_at' => now(),
            'added_at' => now(),
            'added_by' => 'test',
            'postponed_at' => now(),
            'released_at' => $released ? now() : null,
        ]);

        $inventoryItemId = DB::table('inventory_items')
            ->where('warehouse_id', $wave->warehouse_id)
            ->where('product_id', $materialId)
            ->value('id');

        DB::table('stock_ledger_entries')->insert([
            'id' => (string) Str::uuid(),
            'inventory_item_id' => $inventoryItemId,
            'warehouse_id' => $wave->warehouse_id,
            'product_id' => $materialId,
            'company_id' => $wave->company_id,
            'movement_type' => LedgerMovementType::Reservation->value,
            'quantity' => $qty,
            'on_hand_before' => 0, 'on_hand_after' => 0,
            'reserved_before' => 0, 'reserved_after' => $qty,
            'reference_type' => ReconcileOrderMaterialReservationsAction::REFERENCE_TYPE,
            'reference_id' => $orderId,
            'created_at' => now(),
        ]);
    }
}

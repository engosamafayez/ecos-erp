<?php

declare(strict_types=1);

namespace Tests\Feature\Manufacturing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\InventoryItems\Domain\Models\InventoryItem;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Manufacturing\AvailabilityEngine\Domain\Enums\ManufacturingEligibility;
use Modules\Manufacturing\AvailabilityEngine\Domain\Services\InventoryAvailabilityEngine;
use Modules\Manufacturing\AvailabilityEngine\Domain\ValueObjects\AvailabilityResult;
use Modules\Manufacturing\BillsOfMaterials\Domain\Models\Recipe;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-MTO-PRODUCTION-QUANTITY-ACCURACY-FIX-001 — engine-level, exact-number proof.
 *
 * The defect: InventoryAvailabilityEngine derived the manufacturing shortage from
 * SIGNED availability (`on_hand − reserved`). In ECOS's made-to-order flow the finished
 * good is reserved BEFORE manufacturing runs, so availability is routinely negative
 * (on_hand 0, reserved ≥ required); `max(0, required − availability)` then collapses to
 * `required + reserved`, re-adding the entire warehouse reservation pool and
 * over-producing both the finished good and (via `recipe_qty × qty_to_manufacture`) the
 * raw materials.
 *
 * The fix: the shortage is measured against FREE PHYSICAL stock only —
 * `max(0, required − max(0, on_hand − reserved))`. A reservation is a commitment, never
 * additional demand. Clamping the free position at zero (rather than using bare on_hand)
 * also keeps stock reserved for OTHER orders committed to them, so the engine neither
 * over-produces nor under-produces on the shared-stock edge case.
 *
 * These tests seed InventoryItem rows directly and drive the real EloquentInventoryReader
 * through the real engine, so every asserted quantity is the true production quantity.
 */
class MtoProductionQuantityAccuracyTest extends TestCase
{
    use RefreshDatabase;

    private InventoryAvailabilityEngine $engine;

    private Company $company;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = app(InventoryAvailabilityEngine::class);
        $this->company = Company::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 1. Single MTO order: required 1, on_hand 0, reserved 1 → manufacture 1, NOT 2.
    // ═════════════════════════════════════════════════════════════════════════

    public function test_single_mto_order_manufactures_exactly_required_not_double(): void
    {
        $fg = $this->makeOutput();
        $component = $this->makeComponent();
        $recipe = $this->makeRecipe($fg);
        $this->addLine($recipe, $component, qty: 1.0);
        $this->seedInventory($component, onHand: 50.0);

        // The order's own reservation has already committed the finished good.
        $this->seedInventory($fg, onHand: 0.0, reserved: 1.0); // availableQty = -1

        $result = $this->analyse($fg, required: 1.0);

        self::assertSame(1.0, $result->qty_to_manufacture, 'produce exactly the ordered quantity — the reservation is not extra demand');
        self::assertSame(-1.0, $result->available_finished_goods, 'signed availability is reported as-is (fully reserved)');
        self::assertTrue($result->needs_manufacturing);
        self::assertSame(ManufacturingEligibility::CanManufacture, $result->eligibility);

        // Raw material scales with the CORRECTED production quantity: 1.0 × 1 = 1 (not 2).
        self::assertCount(1, $result->raw_materials);
        self::assertSame(1.0, $result->raw_materials[0]->required_qty);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 2. Multi-order reservation pool: a single line must NOT inflate by Σreserved.
    //    Historical failure pattern: required 1, aggregate reserved 15, on_hand 0
    //    produced 16. It must produce exactly 1.
    // ═════════════════════════════════════════════════════════════════════════

    public function test_aggregate_reservation_pool_does_not_inflate_a_single_line(): void
    {
        $fg = $this->makeOutput();
        $component = $this->makeComponent();
        $recipe = $this->makeRecipe($fg);
        $this->addLine($recipe, $component, qty: 1.0);
        $this->seedInventory($component, onHand: 500.0);

        // 15 units reserved across the whole warehouse pool; nothing physically on hand.
        $this->seedInventory($fg, onHand: 0.0, reserved: 15.0); // availableQty = -15

        $result = $this->analyse($fg, required: 1.0);

        self::assertSame(1.0, $result->qty_to_manufacture, 'the warehouse-aggregate reservation pool must not be re-added to the shortage');
        self::assertNotSame(16.0, $result->qty_to_manufacture, 'the historical over-production magnitude must never recur');
        self::assertSame(-15.0, $result->available_finished_goods);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 3. Physical on_hand already satisfies the requirement → manufacture 0.
    // ═════════════════════════════════════════════════════════════════════════

    public function test_free_physical_on_hand_satisfies_requirement_no_manufacturing(): void
    {
        $fg = $this->makeOutput();

        // 1 free unit physically present, nothing reserved.
        $this->seedInventory($fg, onHand: 1.0, reserved: 0.0);

        $result = $this->analyse($fg, required: 1.0);

        self::assertSame(0.0, $result->qty_to_manufacture, 'free physical stock covers the requirement');
        self::assertFalse($result->needs_manufacturing);
        self::assertSame(ManufacturingEligibility::Sufficient, $result->eligibility);
    }

    public function test_ample_free_physical_on_hand_manufactures_zero(): void
    {
        $fg = $this->makeOutput();
        $this->seedInventory($fg, onHand: 5.0, reserved: 0.0);

        $result = $this->analyse($fg, required: 1.0);

        self::assertSame(0.0, $result->qty_to_manufacture);
        self::assertSame(ManufacturingEligibility::Sufficient, $result->eligibility);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 4. SHARED-STOCK EDGE CASE — the reason we clamp (on_hand − reserved), not use
    //    bare on_hand. Physical stock exists but is reserved for OTHER orders; it must
    //    stay committed to them, so a new order still manufactures its full requirement.
    //    (Bare `required − on_hand` would wrongly produce 0 and under-serve someone.)
    // ═════════════════════════════════════════════════════════════════════════

    public function test_physical_stock_reserved_for_other_orders_is_not_treated_as_free(): void
    {
        $fg = $this->makeOutput();
        $component = $this->makeComponent();
        $recipe = $this->makeRecipe($fg);
        $this->addLine($recipe, $component, qty: 1.0);
        $this->seedInventory($component, onHand: 50.0);

        // 1 unit physically present but wholly reserved for other orders.
        $this->seedInventory($fg, onHand: 1.0, reserved: 1.0); // availableQty = 0

        $result = $this->analyse($fg, required: 1.0);

        self::assertSame(1.0, $result->qty_to_manufacture, 'stock reserved for others is not free — do not under-produce');
        self::assertSame(0.0, $result->available_finished_goods);
        self::assertTrue($result->needs_manufacturing);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 5. Partial free stock reduces the shortage but reservations do not deepen it.
    //    on_hand 3, reserved 1 → 2 free; required 5 → manufacture 3.
    // ═════════════════════════════════════════════════════════════════════════

    public function test_partial_free_stock_reduces_shortage_reservations_do_not_deepen_it(): void
    {
        $fg = $this->makeOutput();
        $component = $this->makeComponent();
        $recipe = $this->makeRecipe($fg);
        $this->addLine($recipe, $component, qty: 1.0);
        $this->seedInventory($component, onHand: 50.0);

        $this->seedInventory($fg, onHand: 3.0, reserved: 1.0); // availableQty = 2 (free)

        $result = $this->analyse($fg, required: 5.0);

        self::assertSame(3.0, $result->qty_to_manufacture, 'shortage = required 5 − free 2 = 3');
        self::assertSame(2.0, $result->available_finished_goods);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 6. Raw-material requirement scales with the CORRECTED production quantity.
    //    required 3, on_hand 0, reserved 3, recipe component qty 2 →
    //    produce 3, consume 2 × 3 = 6 (NOT 2 × 6 = 12).
    // ═════════════════════════════════════════════════════════════════════════

    public function test_raw_material_required_scales_with_actual_production_quantity(): void
    {
        $fg = $this->makeOutput();
        $component = $this->makeComponent();
        $recipe = $this->makeRecipe($fg);
        $this->addLine($recipe, $component, qty: 2.0);
        $this->seedInventory($component, onHand: 500.0);

        $this->seedInventory($fg, onHand: 0.0, reserved: 3.0); // availableQty = -3

        $result = $this->analyse($fg, required: 3.0);

        self::assertSame(3.0, $result->qty_to_manufacture, 'produce exactly the required quantity');
        self::assertCount(1, $result->raw_materials);
        self::assertSame(6.0, $result->raw_materials[0]->required_qty, 'raw material = recipe_qty 2 × production 3 = 6');
        self::assertNotSame(12.0, $result->raw_materials[0]->required_qty, 'the over-consumption magnitude (2 × 6) must never recur');
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 7. Regression guard: with non-negative availability the fix is a NO-OP — the
    //    engine behaves exactly as before. on_hand 20, reserved 2 → available 18,
    //    required 10 → sufficient, manufacture 0 (identical to pre-fix behaviour).
    // ═════════════════════════════════════════════════════════════════════════

    public function test_non_negative_availability_behaviour_is_unchanged(): void
    {
        $fg = $this->makeOutput();
        $this->seedInventory($fg, onHand: 20.0, reserved: 2.0); // available = 18

        $result = $this->analyse($fg, required: 10.0);

        self::assertSame(0.0, $result->qty_to_manufacture);
        self::assertSame(18.0, $result->available_finished_goods);
        self::assertSame(ManufacturingEligibility::Sufficient, $result->eligibility);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Read-only guarantee is unchanged: the fix touches only arithmetic, never writes.
    // ═════════════════════════════════════════════════════════════════════════

    public function test_engine_still_does_not_mutate_inventory(): void
    {
        $fg = $this->makeOutput();
        $component = $this->makeComponent();
        $recipe = $this->makeRecipe($fg);
        $this->addLine($recipe, $component, qty: 1.0);
        $componentItem = $this->seedInventory($component, onHand: 50.0);
        $fgItem = $this->seedInventory($fg, onHand: 0.0, reserved: 4.0);

        $this->analyse($fg, required: 4.0);

        self::assertSame(0.0, (float) $fgItem->fresh()->on_hand_qty);
        self::assertSame(4.0, (float) $fgItem->fresh()->reserved_qty);
        self::assertSame(50.0, (float) $componentItem->fresh()->on_hand_qty);
    }

    // ── Helpers (mirror InventoryAvailabilityEngineTest) ────────────────────────

    private function makeOutput(): Product
    {
        return Product::factory()->finishedGood()->manufacturable()->create();
    }

    private function makeComponent(bool $allowNegative = false): Product
    {
        return Product::factory()->rawMaterial()->create(['allow_negative_stock' => $allowNegative]);
    }

    private function makeRecipe(Product $output, int $version = 1): Recipe
    {
        return Recipe::create([
            'bom_number' => 'BOM-MTOQ-'.uniqid(),
            'product_id' => $output->id,
            'version' => "{$version}.0",
            'bom_version_number' => $version,
            'is_active' => true,
        ]);
    }

    private function addLine(Recipe $recipe, Product $component, float $qty): void
    {
        $recipe->components()->create([
            'raw_material_id' => $component->id,
            'quantity' => $qty,
        ]);
    }

    private function seedInventory(Product $product, float $onHand, float $reserved = 0.0): InventoryItem
    {
        return InventoryItem::query()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $product->id,
            'company_id' => $this->company->id,
            'on_hand_qty' => $onHand,
            'reserved_qty' => $reserved,
        ]);
    }

    private function analyse(Product $product, float $required): AvailabilityResult
    {
        return $this->engine->analyse(
            $product->id,
            $this->warehouse->id,
            $required,
            $this->company->id,
        );
    }
}

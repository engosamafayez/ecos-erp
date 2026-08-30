<?php

declare(strict_types=1);

namespace Tests\Feature\Manufacturing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\InventoryItems\Domain\Models\InventoryItem;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Inventory\ReceiptLayers\Domain\Models\InventoryReceiptLayer;
use Modules\Manufacturing\BillsOfMaterials\Application\Actions\CreateRecipeCostSnapshotAction;
use Modules\Manufacturing\BillsOfMaterials\Application\Actions\SetBomStatusAction;
use Modules\Manufacturing\BillsOfMaterials\Domain\Exceptions\RecipeCostSnapshotException;
use Modules\Manufacturing\BillsOfMaterials\Domain\Models\BillOfMaterial;
use Modules\Manufacturing\BillsOfMaterials\Domain\Models\Recipe;
use Modules\Manufacturing\BillsOfMaterials\Domain\Models\RecipeCostSnapshot;
use Modules\Manufacturing\BillsOfMaterials\Domain\Models\RecipeCostSnapshotLine;
use Modules\Manufacturing\BillsOfMaterials\Domain\Services\RecipeCostSnapshotResolver;
use Modules\Manufacturing\Disassembly\Domain\Enums\DisassemblyPolicyCode;
use Modules\Manufacturing\Disassembly\Domain\Services\DisassemblyPolicy;
use Modules\Manufacturing\Disassembly\Domain\ValueObjects\DisassemblyPolicyRequest;
use Modules\Manufacturing\ManufacturingService\Application\DTOs\Requests\DisassembleProductRequest;
use Modules\Manufacturing\ManufacturingService\Application\Services\ManufacturingApplicationService;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-DISASSEMBLY-RECIPE-COST-SNAPSHOT-AND-VALUATION-001.
 *
 * The approved recipe's component costs are the authoritative basis for valuing a
 * disassembly. The single most important assertion in this file is TEST 5, which
 * pins the mandated example: a finished product costing 1,000 with a FIFO layer at
 * 1,200, built from a recipe costing 800, must yield components worth 500 / 200 /
 * 100 — the recipe's own figures — and NOT 600 / 240 / 120, which is what
 * proportionally allocating the FG's acquisition cost would produce.
 *
 * That distinction is the whole point. Proportional allocation looks reasonable and
 * is silently wrong: it inflates component value by whatever margin, labour and
 * overhead the assembled product carried, none of which the components ever had.
 */
class RecipeCostSnapshotValuationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Warehouse $warehouse;

    private ManufacturingApplicationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
        $this->service = app(ManufacturingApplicationService::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeFinishedGood(): Product
    {
        return Product::factory()->finishedGood()->create([
            'can_disassemble' => true,
            'can_manufacture' => true,
        ]);
    }

    private function makeMaterial(float $materialCost): Product
    {
        return Product::factory()->rawMaterial()->create(['material_cost' => $materialCost]);
    }

    private function makeRecipe(Product $fg, float $yield = 1.0, int $version = 1): Recipe
    {
        return Recipe::create([
            'bom_number' => 'BOM-SNAP-'.uniqid(),
            'product_id' => $fg->id,
            'version' => "{$version}.0",
            'bom_version_number' => $version,
            'yield_quantity' => $yield,
            'is_active' => false,
        ]);
    }

    private function addLine(Recipe $recipe, Product $material, float $qty, float $waste = 0.0): void
    {
        $recipe->components()->create([
            'raw_material_id' => $material->id,
            'quantity' => $qty,
            'waste_percentage' => $waste,
        ]);
    }

    private function bom(Recipe $recipe): BillOfMaterial
    {
        return BillOfMaterial::query()->findOrFail($recipe->id);
    }

    /** Approve through the real lifecycle action, exactly as the API does. */
    private function approve(Recipe $recipe, ?string $by = null): BillOfMaterial
    {
        app(SetBomStatusAction::class)->execute($this->bom($recipe), true, $by);

        return $this->bom($recipe)->refresh();
    }

    private function snapshotOf(Recipe $recipe): ?RecipeCostSnapshot
    {
        return RecipeCostSnapshot::query()
            ->with('lines')
            ->where('bom_id', $recipe->id)
            ->first();
    }

    private function seedInventory(Product $product, float $onHand): InventoryItem
    {
        return InventoryItem::query()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $product->id,
            'company_id' => $this->company->id,
            'on_hand_qty' => $onHand,
            'reserved_qty' => 0.0,
        ]);
    }

    private function seedFgLayer(Product $fg, float $qty, float $unitCost): void
    {
        InventoryReceiptLayer::query()->create([
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $fg->id,
            'received_qty' => $qty,
            'remaining_qty' => $qty,
            'landed_unit_cost' => $unitCost,
            'receipt_date' => now(),
        ]);
    }

    private function disassemble(Product $fg, float $qty = 1.0): object
    {
        return $this->service->disassembleProduct(new DisassembleProductRequest(
            product_id: $fg->id,
            warehouse_id: $this->warehouse->id,
            company_id: $this->company->id,
            quantity: $qty,
            actor_id: 'test-actor',
        ));
    }

    /** @return array<string, float> materialId => remaining layer value */
    private function layerValueByMaterial(Product ...$materials): array
    {
        $out = [];

        foreach ($materials as $m) {
            $layers = InventoryReceiptLayer::query()
                ->where('product_id', $m->id)
                ->where('warehouse_id', $this->warehouse->id)
                ->get();

            $out[$m->id] = round(
                $layers->sum(fn (InventoryReceiptLayer $l): float => (float) $l->received_qty * (float) $l->landed_unit_cost),
                4,
            );
        }

        return $out;
    }

    // ── 1–4: The snapshot records what the recipe cost at approval ────────────

    public function test_1_approval_creates_a_snapshot_with_one_line_per_component(): void
    {
        $fg = $this->makeFinishedGood();
        $recipe = $this->makeRecipe($fg);
        $this->addLine($recipe, $this->makeMaterial(10.0), qty: 2.0);
        $this->addLine($recipe, $this->makeMaterial(5.0), qty: 3.0);

        $this->approve($recipe);

        $snapshot = $this->snapshotOf($recipe);
        $this->assertNotNull($snapshot, 'Approving a recipe must freeze its component costs.');
        $this->assertCount(2, $snapshot->lines);
    }

    public function test_2_snapshot_line_carries_the_material_cost_at_approval(): void
    {
        $fg = $this->makeFinishedGood();
        $material = $this->makeMaterial(37.5);
        $recipe = $this->makeRecipe($fg);
        $this->addLine($recipe, $material, qty: 4.0);

        $this->approve($recipe);

        $line = $this->snapshotOf($recipe)->lines->first();
        $this->assertSame(37.5, (float) $line->unit_cost);
        $this->assertSame(4.0, (float) $line->quantity);
        $this->assertSame((string) $material->id, (string) $line->raw_material_id);
    }

    public function test_3_extended_cost_applies_waste_to_the_quantity(): void
    {
        $fg = $this->makeFinishedGood();
        $recipe = $this->makeRecipe($fg);
        // 10 units @ 20.00 with 10% waste → 11 effective units → 220.00
        $this->addLine($recipe, $this->makeMaterial(20.0), qty: 10.0, waste: 10.0);

        $this->approve($recipe);

        $line = $this->snapshotOf($recipe)->lines->first();
        $this->assertSame(220.0, (float) $line->extended_cost);
        // The stated quantity stays stated — waste lives in its own column.
        $this->assertSame(10.0, (float) $line->quantity);
        $this->assertSame(10.0, (float) $line->waste_percentage);
    }

    public function test_4_total_cost_equals_the_sum_of_its_lines(): void
    {
        $fg = $this->makeFinishedGood();
        $recipe = $this->makeRecipe($fg);
        $this->addLine($recipe, $this->makeMaterial(100.0), qty: 5.0);
        $this->addLine($recipe, $this->makeMaterial(100.0), qty: 2.0);
        $this->addLine($recipe, $this->makeMaterial(100.0), qty: 1.0);

        $this->approve($recipe);

        $snapshot = $this->snapshotOf($recipe);
        $this->assertSame(800.0, (float) $snapshot->total_cost);
        $this->assertSame(
            800.0,
            round($snapshot->lines->sum(fn (RecipeCostSnapshotLine $l): float => (float) $l->extended_cost), 4),
        );
    }

    // ── 5: THE MANDATORY EXAMPLE ─────────────────────────────────────────────

    public function test_5_components_are_valued_at_recipe_cost_not_a_share_of_the_fg_cost(): void
    {
        // Finished product cost 1,000 · its FIFO layer 1,200 · recipe cost 800.
        $fg = $this->makeFinishedGood();
        $fg->update(['product_cost' => 1000.0]);

        $honey = $this->makeMaterial(100.0);     // 5 × 100 = 500
        $coffee = $this->makeMaterial(100.0);    // 2 × 100 = 200
        $hazelnut = $this->makeMaterial(100.0);  // 1 × 100 = 100

        $recipe = $this->makeRecipe($fg);
        $this->addLine($recipe, $honey, qty: 5.0);
        $this->addLine($recipe, $coffee, qty: 2.0);
        $this->addLine($recipe, $hazelnut, qty: 1.0);

        $this->approve($recipe);

        $this->assertSame(800.0, (float) $this->snapshotOf($recipe)->total_cost);

        $this->seedInventory($fg, onHand: 10.0);
        $this->seedFgLayer($fg, qty: 10.0, unitCost: 1200.0);

        $response = $this->disassemble($fg, qty: 1.0);
        $this->assertTrue($response->success, 'Disassembly must succeed once the recipe is approved.');

        $values = $this->layerValueByMaterial($honey, $coffee, $hazelnut);

        // The recipe's own figures.
        $this->assertSame(500.0, $values[$honey->id], 'Honey must be valued at its recipe cost of 500.');
        $this->assertSame(200.0, $values[$coffee->id], 'Coffee must be valued at its recipe cost of 200.');
        $this->assertSame(100.0, $values[$hazelnut->id], 'Hazelnut must be valued at its recipe cost of 100.');

        // And explicitly NOT the proportional allocation of the FG's 1,200 FIFO cost,
        // which is the plausible-looking wrong answer this whole mechanism prevents.
        $this->assertNotEquals(600.0, $values[$honey->id]);
        $this->assertNotEquals(240.0, $values[$coffee->id]);
        $this->assertNotEquals(120.0, $values[$hazelnut->id]);

        // Total recovered value is the recipe's 800, not the FG's 1,200.
        $this->assertSame(800.0, round(array_sum($values), 4));
    }

    // ── 6–7: Immutability ────────────────────────────────────────────────────

    public function test_6_changing_a_material_cost_never_alters_an_existing_snapshot(): void
    {
        $fg = $this->makeFinishedGood();
        $material = $this->makeMaterial(100.0);
        $recipe = $this->makeRecipe($fg);
        $this->addLine($recipe, $material, qty: 5.0);

        $this->approve($recipe);
        $before = $this->snapshotOf($recipe);
        $this->assertSame(500.0, (float) $before->total_cost);

        // The material trebles in price long after the goods were built.
        $material->update(['material_cost' => 300.0]);

        $after = $this->snapshotOf($recipe);
        $this->assertSame(100.0, (float) $after->lines->first()->unit_cost);
        $this->assertSame(500.0, (float) $after->total_cost);
    }

    public function test_7_re_approving_the_same_version_is_idempotent(): void
    {
        $fg = $this->makeFinishedGood();
        $material = $this->makeMaterial(100.0);
        $recipe = $this->makeRecipe($fg);
        $this->addLine($recipe, $material, qty: 5.0);

        $this->approve($recipe);
        $firstId = $this->snapshotOf($recipe)->id;

        // Deactivate, the price moves, reactivate — the frozen cost must not follow.
        app(SetBomStatusAction::class)->execute($this->bom($recipe), false, null);
        $material->update(['material_cost' => 999.0]);
        $this->approve($recipe);

        $this->assertSame(1, RecipeCostSnapshot::query()->where('bom_id', $recipe->id)->count());
        $this->assertSame($firstId, $this->snapshotOf($recipe)->id);
        $this->assertSame(500.0, (float) $this->snapshotOf($recipe)->total_cost);
    }

    // ── 8–9: A recipe that cannot be costed cannot be approved ───────────────

    public function test_8_a_recipe_with_an_unpriced_component_cannot_be_approved(): void
    {
        $fg = $this->makeFinishedGood();
        $unpriced = Product::factory()->rawMaterial()->create(['material_cost' => null]);
        $recipe = $this->makeRecipe($fg);
        $this->addLine($recipe, $unpriced, qty: 1.0);

        $this->expectException(RecipeCostSnapshotException::class);

        try {
            $this->approve($recipe);
        } finally {
            // The activation must roll back with the snapshot — no window in which
            // the recipe is live but unvalued.
            $this->assertFalse((bool) $this->bom($recipe)->refresh()->is_active);
            $this->assertNull($this->snapshotOf($recipe));
        }
    }

    public function test_9_a_recipe_with_no_components_cannot_be_approved(): void
    {
        $fg = $this->makeFinishedGood();
        $recipe = $this->makeRecipe($fg);

        $this->expectException(RecipeCostSnapshotException::class);
        $this->approve($recipe);
    }

    // ── 10–11: Disassembly refuses rather than guesses ───────────────────────

    public function test_10_disassembly_is_blocked_when_the_recipe_has_no_snapshot(): void
    {
        // Exactly the state of every recipe that predates this mechanism: active,
        // complete, priced — but never approved through the snapshotting path.
        $fg = $this->makeFinishedGood();
        $material = $this->makeMaterial(100.0);
        $recipe = $this->makeRecipe($fg);
        $this->addLine($recipe, $material, qty: 5.0);
        $recipe->update(['is_active' => true]);

        $this->seedInventory($fg, onHand: 10.0);

        $response = $this->disassemble($fg, qty: 1.0);

        $this->assertTrue($response->is_blocked);
        $this->assertSame('recipe_cost_snapshot_missing', $response->blocking_reason);
        $this->assertFalse($response->success);
    }

    public function test_11_blocked_disassembly_mutates_no_inventory(): void
    {
        $fg = $this->makeFinishedGood();
        $material = $this->makeMaterial(100.0);
        $recipe = $this->makeRecipe($fg);
        $this->addLine($recipe, $material, qty: 5.0);
        $recipe->update(['is_active' => true]);

        $this->seedInventory($fg, onHand: 10.0);
        $this->seedInventory($material, onHand: 0.0);

        $this->disassemble($fg, qty: 1.0);

        $fgItem = InventoryItem::query()->where('product_id', $fg->id)->first();
        $matItem = InventoryItem::query()->where('product_id', $material->id)->first();

        $this->assertSame(10.0, (float) $fgItem->on_hand_qty);
        $this->assertSame(0.0, (float) $matItem->on_hand_qty);
        $this->assertDatabaseCount('disassembly_transactions', 0);
        $this->assertSame(0, InventoryReceiptLayer::query()->where('product_id', $material->id)->count());
    }

    public function test_12_disassembly_is_blocked_when_a_component_was_added_after_approval(): void
    {
        $fg = $this->makeFinishedGood();
        $honey = $this->makeMaterial(100.0);
        $recipe = $this->makeRecipe($fg);
        $this->addLine($recipe, $honey, qty: 5.0);
        $this->approve($recipe);

        // The recipe gains a component without being re-approved. Valuing the new
        // one at zero would succeed and be wrong.
        $this->addLine($recipe, $this->makeMaterial(50.0), qty: 2.0);

        $this->seedInventory($fg, onHand: 10.0);

        $response = $this->disassemble($fg, qty: 1.0);

        $this->assertTrue($response->is_blocked);
        $this->assertSame('recipe_cost_snapshot_stale', $response->blocking_reason);
    }

    // ── 13–15: The FIFO layers disassembly now creates ───────────────────────

    public function test_13_produced_components_open_a_receipt_layer_at_the_snapshot_cost(): void
    {
        $fg = $this->makeFinishedGood();
        $material = $this->makeMaterial(40.0);
        $recipe = $this->makeRecipe($fg);
        $this->addLine($recipe, $material, qty: 3.0);
        $this->approve($recipe);

        $this->seedInventory($fg, onHand: 5.0);

        $this->disassemble($fg, qty: 2.0); // 3 × 2 = 6 units recovered

        $layer = InventoryReceiptLayer::query()->where('product_id', $material->id)->sole();
        $this->assertSame(6.0, (float) $layer->received_qty);
        $this->assertSame(6.0, (float) $layer->remaining_qty);
        $this->assertSame(40.0, (float) $layer->landed_unit_cost);
        $this->assertSame((string) $this->warehouse->id, (string) $layer->warehouse_id);
    }

    public function test_14_a_disassembly_layer_is_not_disguised_as_a_purchase(): void
    {
        $fg = $this->makeFinishedGood();
        $material = $this->makeMaterial(40.0);
        $recipe = $this->makeRecipe($fg);
        $this->addLine($recipe, $material, qty: 1.0);
        $this->approve($recipe);

        $this->seedInventory($fg, onHand: 5.0);
        $this->disassemble($fg, qty: 1.0);

        $layer = InventoryReceiptLayer::query()->where('product_id', $material->id)->sole();
        $this->assertNull($layer->supplier_id);
        $this->assertNull($layer->goods_receipt_id);
        $this->assertNull($layer->goods_receipt_line_id);
    }

    public function test_15_layer_cost_ignores_a_material_price_change_since_approval(): void
    {
        $fg = $this->makeFinishedGood();
        $material = $this->makeMaterial(40.0);
        $recipe = $this->makeRecipe($fg);
        $this->addLine($recipe, $material, qty: 2.0);
        $this->approve($recipe);

        // Price doubles between approval and disassembly.
        $material->update(['material_cost' => 80.0]);

        $this->seedInventory($fg, onHand: 5.0);
        $this->disassemble($fg, qty: 1.0);

        $layer = InventoryReceiptLayer::query()->where('product_id', $material->id)->sole();
        $this->assertSame(40.0, (float) $layer->landed_unit_cost, 'The frozen cost must win over today\'s price.');
    }

    // ── 16–17: Snapshot integrity ────────────────────────────────────────────

    public function test_16_snapshot_records_the_approval_anchor_and_yield(): void
    {
        $fg = $this->makeFinishedGood();
        $recipe = $this->makeRecipe($fg, yield: 4.0);
        $this->addLine($recipe, $this->makeMaterial(10.0), qty: 8.0);

        $bom = $this->approve($recipe);

        $this->assertNotNull($bom->approved_at, 'Activation must record when the recipe was approved.');

        $snapshot = $this->snapshotOf($recipe);
        $this->assertSame(4.0, (float) $snapshot->yield_quantity);
        $this->assertNotNull($snapshot->approved_at);
        // 8 units @ 10 = 80 per batch; the batch yields 4, so 20 per finished unit.
        $this->assertSame(20.0, $snapshot->perUnitCost($snapshot->lines->first()));
    }

    public function test_17_snapshot_survives_deletion_of_the_recipe_line_it_came_from(): void
    {
        $fg = $this->makeFinishedGood();
        $material = $this->makeMaterial(100.0);
        $recipe = $this->makeRecipe($fg);
        $this->addLine($recipe, $material, qty: 5.0);
        $this->approve($recipe);

        // Recipe lines are replaced wholesale on edit. History must not go with them.
        $recipe->components()->delete();

        $snapshot = $this->snapshotOf($recipe);
        $this->assertNotNull($snapshot);
        $this->assertCount(1, $snapshot->lines);
        $this->assertSame(500.0, (float) $snapshot->total_cost);
        // Denormalised identity keeps the row readable without the source line.
        $this->assertSame($material->sku, $snapshot->lines->first()->sku_snapshot);
    }

    public function test_18_snapshot_is_scoped_to_the_owning_company(): void
    {
        $fg = $this->makeFinishedGood();
        $recipe = $this->makeRecipe($fg);
        $this->addLine($recipe, $this->makeMaterial(10.0), qty: 1.0);

        $this->approve($recipe);

        $snapshot = $this->snapshotOf($recipe);
        $expected = $fg->company_id ?? $fg->brand?->company_id;

        // Resolved once at approval and stored — never re-derived through a join a
        // later re-brand could change.
        $this->assertSame(
            $expected !== null ? (string) $expected : null,
            $snapshot->company_id !== null ? (string) $snapshot->company_id : null,
        );
    }

    public function test_19_the_engine_aggregate_and_the_snapshot_cannot_disagree(): void
    {
        $fg = $this->makeFinishedGood();
        $recipe = $this->makeRecipe($fg);
        $this->addLine($recipe, $this->makeMaterial(12.5), qty: 3.0, waste: 5.0);
        $this->addLine($recipe, $this->makeMaterial(7.25), qty: 11.0, waste: 2.5);

        $this->approve($recipe);

        // Both read the same per-line breakdown, so recipe_cost (materials only)
        // and the snapshot total are the same number by construction.
        $this->assertSame(
            round((float) $this->bom($recipe)->refresh()->recipe_cost, 2),
            round((float) $this->snapshotOf($recipe)->total_cost, 2),
        );
    }

    public function test_20_deactivating_a_recipe_leaves_its_snapshot_intact(): void
    {
        $fg = $this->makeFinishedGood();
        $recipe = $this->makeRecipe($fg);
        $this->addLine($recipe, $this->makeMaterial(100.0), qty: 5.0);
        $this->approve($recipe);

        app(SetBomStatusAction::class)->execute($this->bom($recipe), false, null);

        // Turning a recipe off says nothing about goods already built under it.
        $snapshot = $this->snapshotOf($recipe);
        $this->assertNotNull($snapshot);
        $this->assertSame(500.0, (float) $snapshot->total_cost);
    }

    public function test_21_snapshot_action_is_the_same_whether_called_directly_or_via_approval(): void
    {
        $fg = $this->makeFinishedGood();
        $recipe = $this->makeRecipe($fg);
        $this->addLine($recipe, $this->makeMaterial(100.0), qty: 5.0);

        $direct = app(CreateRecipeCostSnapshotAction::class)->execute($this->bom($recipe));

        // Approving afterwards must reuse it rather than write a competing record.
        $this->approve($recipe);

        $this->assertSame(1, RecipeCostSnapshot::query()->where('bom_id', $recipe->id)->count());
        $this->assertSame($direct->id, $this->snapshotOf($recipe)->id);
    }

    // ── 22–23: Versioning — a new approval never rewrites the old one ────────

    public function test_22_a_new_version_creates_a_new_snapshot_and_leaves_the_old_untouched(): void
    {
        $fg = $this->makeFinishedGood();
        $material = $this->makeMaterial(100.0);
        $recipe = $this->makeRecipe($fg);
        $this->addLine($recipe, $material, qty: 5.0);
        $this->approve($recipe);

        $v1 = RecipeCostSnapshot::query()->where('bom_id', $recipe->id)->where('bom_version_number', 1)->first();
        $this->assertSame(500.0, (float) $v1->total_cost);

        // The material re-prices, the recipe is versioned up, and it is approved again.
        $material->update(['material_cost' => 300.0]);
        $recipe->update(['bom_version_number' => 2, 'version' => '2.0']);
        $this->approve($recipe);

        $snapshots = RecipeCostSnapshot::query()->where('bom_id', $recipe->id)->get();
        $this->assertCount(2, $snapshots, 'A new version must produce a new snapshot.');

        $v2 = $snapshots->firstWhere('bom_version_number', 2);
        $this->assertSame(1500.0, (float) $v2->total_cost, 'v2 freezes the new price.');

        // The whole point: goods built under v1 are still valued at v1 prices.
        $v1Reloaded = RecipeCostSnapshot::query()->with('lines')->find($v1->id);
        $this->assertSame(500.0, (float) $v1Reloaded->total_cost);
        $this->assertSame(100.0, (float) $v1Reloaded->lines->first()->unit_cost);
    }

    public function test_23_version_resolution_is_deterministic_not_latest_wins(): void
    {
        $fg = $this->makeFinishedGood();
        $material = $this->makeMaterial(100.0);
        $recipe = $this->makeRecipe($fg);
        $this->addLine($recipe, $material, qty: 5.0);
        $this->approve($recipe);

        $material->update(['material_cost' => 300.0]);
        $recipe->update(['bom_version_number' => 2, 'version' => '2.0']);
        $this->approve($recipe);

        $resolver = app(RecipeCostSnapshotResolver::class);

        // Asking for v1 must return v1 even though v2 is newer and active. If this
        // resolved to "latest", every historical disassembly would silently re-price
        // itself the moment a recipe changed.
        $this->assertSame(500.0, (float) $resolver->resolveOrFail((string) $recipe->id, 1)->total_cost);
        $this->assertSame(1500.0, (float) $resolver->resolveOrFail((string) $recipe->id, 2)->total_cost);
        $this->assertSame(100.0, $resolver->unitCosts($resolver->resolveOrFail((string) $recipe->id, 1))[$material->id]);

        // A version that was never approved resolves to nothing — never to a neighbour.
        $this->assertNull($resolver->resolve((string) $recipe->id, 3));
    }

    // ── 24: can_disassemble remains the runtime authority ───────────────────

    public function test_24_can_disassemble_false_still_blocks_even_with_a_valid_snapshot(): void
    {
        $fg = $this->makeFinishedGood();
        $fg->update(['can_disassemble' => false]);
        $recipe = $this->makeRecipe($fg);
        $this->addLine($recipe, $this->makeMaterial(100.0), qty: 5.0);
        $this->approve($recipe);

        // A perfectly valid snapshot exists. The product flag must still win — the
        // cost gate is an ADDITIONAL constraint, never a replacement for the
        // product's own permission to be taken apart.
        $decision = app(DisassemblyPolicy::class)->evaluate(new DisassemblyPolicyRequest(
            product_id: (string) $fg->id,
            quantity: 1.0,
            actor_id: 'test-actor',
            can_disassemble: false,
            has_active_recipe: true,
            is_inventory_managed: true,
            already_disassembled: false,
        ));

        $this->assertFalse($decision->eligible);
        $this->assertSame(DisassemblyPolicyCode::ProductCannotDisassemble, $decision->policy_code);
        $this->assertNotNull($this->snapshotOf($recipe), 'The snapshot exists; the flag is what blocked.');
    }

    // ── 25: Tenant isolation ────────────────────────────────────────────────

    public function test_25_each_company_disassembles_at_its_own_frozen_cost(): void
    {
        $companyB = Company::factory()->create();
        $warehouseB = Warehouse::factory()->create(['company_id' => $companyB->id]);

        // One shared material, approved into two recipes at two different prices.
        $shared = $this->makeMaterial(100.0);

        $fgA = $this->makeFinishedGood();
        $recipeA = $this->makeRecipe($fgA);
        $this->addLine($recipeA, $shared, qty: 2.0);
        $this->approve($recipeA);              // A freezes 100

        $shared->update(['material_cost' => 700.0]);

        $fgB = $this->makeFinishedGood();
        $recipeB = $this->makeRecipe($fgB);
        $this->addLine($recipeB, $shared, qty: 2.0);
        $this->approve($recipeB);              // B freezes 700

        $this->assertSame(200.0, (float) $this->snapshotOf($recipeA)->total_cost);
        $this->assertSame(1400.0, (float) $this->snapshotOf($recipeB)->total_cost);

        // Company A disassembles in its own warehouse.
        $this->seedInventory($fgA, onHand: 5.0);
        $responseA = $this->disassemble($fgA, qty: 1.0);
        $this->assertTrue($responseA->success);

        $layerA = InventoryReceiptLayer::query()
            ->where('product_id', $shared->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->sole();

        $this->assertSame(100.0, (float) $layerA->landed_unit_cost, "A must use A's frozen cost, not B's.");
        $this->assertSame((string) $this->company->id, (string) $layerA->company_id);

        // Company B's warehouse saw no movement at all.
        $this->assertSame(
            0,
            InventoryReceiptLayer::query()
                ->where('product_id', $shared->id)
                ->where('warehouse_id', $warehouseB->id)
                ->count(),
        );

        // And B's snapshot is entirely unaffected by A's disassembly.
        $this->assertSame(1400.0, (float) $this->snapshotOf($recipeB)->total_cost);
    }
}

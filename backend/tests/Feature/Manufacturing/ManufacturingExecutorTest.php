<?php

declare(strict_types=1);

namespace Tests\Feature\Manufacturing;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\InventoryItems\Domain\Contracts\InventoryItemRepositoryInterface;
use Modules\Inventory\InventoryItems\Domain\Models\InventoryItem;
use Modules\Inventory\InventoryItems\Domain\Models\StockLedgerEntry;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Manufacturing\AvailabilityEngine\Domain\Enums\ManufacturingEligibility;
use Modules\Manufacturing\BillsOfMaterials\Domain\Models\Recipe;
use Modules\Manufacturing\BillsOfMaterials\Domain\ValueObjects\RecipeComponent;
use Modules\Manufacturing\BillsOfMaterials\Domain\ValueObjects\RecipeSnapshot;
use Modules\Manufacturing\DecisionKernel\Domain\Enums\DecisionType;
use Modules\Manufacturing\DecisionKernel\Domain\ValueObjects\DecisionContext;
use Modules\Manufacturing\DecisionKernel\Domain\ValueObjects\DecisionEvaluation;
use Modules\Manufacturing\DecisionKernel\Domain\ValueObjects\DecisionReason;
use Modules\Manufacturing\DecisionKernel\Domain\ValueObjects\DecisionResult;
use Modules\Manufacturing\DecisionKernel\Domain\ValueObjects\DecisionTrigger;
use Modules\Manufacturing\ManufacturingExecution\Application\Services\ManufacturingExecutor;
use Modules\Manufacturing\ManufacturingExecution\Domain\Contracts\ManufacturingTransactionRepositoryInterface;
use Modules\Manufacturing\ManufacturingExecution\Domain\Enums\TransactionStatus;
use Modules\Manufacturing\ManufacturingExecution\Domain\Exceptions\ExecutionException;
use Modules\Manufacturing\ManufacturingExecution\Domain\Models\ManufacturingTransaction;
use Modules\Manufacturing\ManufacturingPlanner\Domain\ValueObjects\ComponentConsumptionPlan;
use Modules\Manufacturing\ManufacturingPlanner\Domain\ValueObjects\ManufacturingPlan;
use Modules\Manufacturing\ManufacturingPlanner\Domain\ValueObjects\NegativeStockDecision;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * PKG-05: ManufacturingExecutor — feature tests (real DB).
 *
 * Tests the actual execution: inventory mutations, ledger entries,
 * transaction records, idempotency, rollback, and integrity checks.
 */
class ManufacturingExecutorTest extends TestCase
{
    use RefreshDatabase;

    private ManufacturingExecutor $executor;
    private Company $company;
    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->executor  = app(ManufacturingExecutor::class);
        $this->company   = Company::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeOutput(): Product
    {
        return Product::factory()->finishedGood()->manufacturable()->create();
    }

    private function makeComponent(bool $allowNegative = false): Product
    {
        return Product::factory()->rawMaterial()->create([
            'allow_negative_stock' => $allowNegative,
        ]);
    }

    private function makeRecipeSnapshot(Product $output, array $componentProducts, array $quantities): RecipeSnapshot
    {
        $components = [];
        foreach ($componentProducts as $i => $product) {
            $components[] = new RecipeComponent(
                component_id:         $product->id,
                sku:                  $product->sku,
                name:                 $product->name,
                unit_id:              'unit-001',
                unit_name:            'Kilogram',
                unit_symbol:          'kg',
                quantity:             $quantities[$i] ?? 1.0,
                allow_negative_stock: (bool) $product->allow_negative_stock,
            );
        }

        return new RecipeSnapshot(
            recipe_id:          'recipe-' . uniqid(),
            bom_number:         'BOM-' . uniqid(),
            version:            '1.0',
            bom_version_number: 1,
            product_id:         $output->id,
            product_sku:        $output->sku,
            product_name:       $output->name,
            components:         $components,
            resolved_at:        (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        );
    }

    private function makeComponentPlan(
        Product $component,
        float $qtyToConsume,
        float $availableQty,
        bool $allowNegative = false,
    ): ComponentConsumptionPlan {
        $missing = max(0.0, $qtyToConsume - $availableQty);

        return new ComponentConsumptionPlan(
            component_id:         $component->id,
            sku:                  $component->sku,
            name:                 $component->name,
            unit_symbol:          'kg',
            qty_to_consume:       $qtyToConsume,
            available_qty:        $availableQty,
            missing_qty:          $missing,
            allow_negative_stock: $allowNegative,
            will_go_negative:     $missing > 0.0 && $allowNegative,
            is_blocked:           $missing > 0.0 && !$allowNegative,
        );
    }

    private function buildPlan(
        Product $output,
        RecipeSnapshot $snapshot,
        array $components,
        float $qtyToManufacture,
        bool $shouldManufacture = true,
        ?string $overrideHash = null,
    ): ManufacturingPlan {
        $hash = $overrideHash ?? hash('sha256', json_encode($snapshot->toArray(), JSON_THROW_ON_ERROR));

        return new ManufacturingPlan(
            plan_id:                   $this->generateUuid(),
            product_id:                $output->id,
            warehouse_id:              $this->warehouse->id,
            product_sku:               $output->sku,
            product_name:              $output->name,
            qty_to_manufacture:        $qtyToManufacture,
            finished_goods_to_produce: $qtyToManufacture,
            available_finished_goods:  0.0,
            recipe_id:                 $snapshot->recipe_id,
            bom_version_number:        $snapshot->bom_version_number,
            recipe_snapshot:           $snapshot,
            recipe_snapshot_hash:      $hash,
            components:                $components,
            negative_stock_decisions:  [],
            eligibility:               ManufacturingEligibility::CanManufacture,
            can_proceed:               $shouldManufacture,
            should_manufacture:        $shouldManufacture,
            decision_type:             DecisionType::Approve,
            decision_reason:           new DecisionReason(code: 'mfg_approved', message: 'Approved'),
            planned_at:                (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            metadata:                  [],
        );
    }

    private function seedInventory(Product $product, float $onHand, float $reserved = 0.0): InventoryItem
    {
        return InventoryItem::query()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_id'   => $product->id,
            'company_id'   => $this->company->id,
            'on_hand_qty'  => $onHand,
            'reserved_qty' => $reserved,
        ]);
    }

    private function generateUuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    // ── 1. Successful Execution ───────────────────────────────────────────────

    public function test_successful_execution_decrements_raw_material_stock(): void
    {
        $output    = $this->makeOutput();
        $component = $this->makeComponent();
        $snapshot  = $this->makeRecipeSnapshot($output, [$component], [2.0]);

        $this->seedInventory($component, onHand: 20.0);
        $componentPlan = $this->makeComponentPlan($component, qtyToConsume: 10.0, availableQty: 20.0);
        $plan = $this->buildPlan($output, $snapshot, [$componentPlan], qtyToManufacture: 5.0);

        $this->executor->execute($plan, $this->company->id);

        $item = InventoryItem::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $component->id)
            ->first();

        $this->assertSame(10.0, (float) $item->on_hand_qty); // 20 - 10
    }

    public function test_successful_execution_increments_finished_goods_stock(): void
    {
        $output    = $this->makeOutput();
        $component = $this->makeComponent();
        $snapshot  = $this->makeRecipeSnapshot($output, [$component], [1.0]);

        $this->seedInventory($component, onHand: 10.0);
        $this->seedInventory($output, onHand: 3.0); // existing FG
        $componentPlan = $this->makeComponentPlan($component, qtyToConsume: 5.0, availableQty: 10.0);
        $plan = $this->buildPlan($output, $snapshot, [$componentPlan], qtyToManufacture: 5.0);

        $this->executor->execute($plan, $this->company->id);

        $fgItem = InventoryItem::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $output->id)
            ->first();

        $this->assertSame(8.0, (float) $fgItem->on_hand_qty); // 3 + 5
    }

    public function test_successful_execution_creates_ledger_entries(): void
    {
        $output    = $this->makeOutput();
        $component = $this->makeComponent();
        $snapshot  = $this->makeRecipeSnapshot($output, [$component], [2.0]);

        $this->seedInventory($component, onHand: 20.0);
        $componentPlan = $this->makeComponentPlan($component, qtyToConsume: 10.0, availableQty: 20.0);
        $plan = $this->buildPlan($output, $snapshot, [$componentPlan], qtyToManufacture: 5.0);

        $result = $this->executor->execute($plan, $this->company->id);

        // 1 consumption entry + 1 production entry
        $this->assertCount(2, $result->ledger_entry_ids);

        $entries = StockLedgerEntry::query()
            ->where('reference_type', 'manufacturing_plan')
            ->where('reference_id', $plan->plan_id)
            ->get();

        $this->assertCount(2, $entries);
        $types = $entries->pluck('movement_type')->toArray();
        $this->assertContains('production_consumption', $types);
        $this->assertContains('production_output', $types);
    }

    public function test_successful_execution_creates_manufacturing_transaction(): void
    {
        $output    = $this->makeOutput();
        $component = $this->makeComponent();
        $snapshot  = $this->makeRecipeSnapshot($output, [$component], [1.0]);

        $this->seedInventory($component, onHand: 10.0);
        $componentPlan = $this->makeComponentPlan($component, qtyToConsume: 5.0, availableQty: 10.0);
        $plan = $this->buildPlan($output, $snapshot, [$componentPlan], qtyToManufacture: 5.0);

        $result = $this->executor->execute($plan, $this->company->id);

        $tx = ManufacturingTransaction::query()->where('plan_id', $plan->plan_id)->first();
        $this->assertNotNull($tx);
        $this->assertSame(TransactionStatus::Completed, $tx->status);
        $this->assertSame(5.0, (float) $tx->qty_produced);
        $this->assertSame($snapshot->recipe_id, $tx->bom_id);
        $this->assertSame($plan->recipe_snapshot_hash, $tx->recipe_snapshot_hash);
        $this->assertSame($result->transaction_id, $tx->id);
    }

    public function test_successful_execution_result_structure(): void
    {
        $output    = $this->makeOutput();
        $component = $this->makeComponent();
        $snapshot  = $this->makeRecipeSnapshot($output, [$component], [1.0]);

        $this->seedInventory($component, onHand: 10.0);
        $componentPlan = $this->makeComponentPlan($component, qtyToConsume: 5.0, availableQty: 10.0);
        $plan = $this->buildPlan($output, $snapshot, [$componentPlan], qtyToManufacture: 5.0);

        $result = $this->executor->execute($plan, $this->company->id);

        $this->assertTrue($result->success);
        $this->assertFalse($result->was_idempotent);
        $this->assertSame(5.0, $result->qty_produced);
        $this->assertCount(1, $result->consumed_components);
        $this->assertSame($component->id, $result->consumed_components[0]->component_id);
        $this->assertGreaterThanOrEqual(0, $result->duration_ms);
        $this->assertNotEmpty($result->execution_id);
        $this->assertNotEmpty($result->transaction_id);
    }

    // ── 2. Negative Stock Execution (RC-2) ────────────────────────────────────

    public function test_execution_with_negative_stock_component_goes_below_zero(): void
    {
        $output    = $this->makeOutput();
        $component = $this->makeComponent(allowNegative: true);
        $snapshot  = $this->makeRecipeSnapshot($output, [$component], [1.0]);

        $this->seedInventory($component, onHand: 3.0); // Only 3, will consume 10
        $componentPlan = $this->makeComponentPlan(
            $component, qtyToConsume: 10.0, availableQty: 3.0, allowNegative: true,
        );
        $plan = $this->buildPlan($output, $snapshot, [$componentPlan], qtyToManufacture: 10.0);

        $result = $this->executor->execute($plan, $this->company->id);

        $this->assertTrue($result->success);
        $this->assertTrue($result->consumed_components[0]->went_negative);
        $this->assertSame(3.0, $result->consumed_components[0]->on_hand_before);
        $this->assertSame(-7.0, $result->consumed_components[0]->on_hand_after);

        $item = InventoryItem::query()
            ->where('product_id', $component->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->first();
        $this->assertSame(-7.0, (float) $item->on_hand_qty);
    }

    // ── 3. Idempotency ────────────────────────────────────────────────────────

    public function test_duplicate_execution_returns_idempotent_result(): void
    {
        $output    = $this->makeOutput();
        $component = $this->makeComponent();
        $snapshot  = $this->makeRecipeSnapshot($output, [$component], [1.0]);

        $this->seedInventory($component, onHand: 20.0);
        $componentPlan = $this->makeComponentPlan($component, qtyToConsume: 5.0, availableQty: 20.0);
        $plan = $this->buildPlan($output, $snapshot, [$componentPlan], qtyToManufacture: 5.0);

        $result1 = $this->executor->execute($plan, $this->company->id);
        $result2 = $this->executor->execute($plan, $this->company->id); // same plan_id

        $this->assertTrue($result2->was_idempotent);
        $this->assertSame($result1->transaction_id, $result2->transaction_id);
    }

    public function test_idempotent_execution_does_not_double_consume_inventory(): void
    {
        $output    = $this->makeOutput();
        $component = $this->makeComponent();
        $snapshot  = $this->makeRecipeSnapshot($output, [$component], [1.0]);

        $this->seedInventory($component, onHand: 20.0);
        $componentPlan = $this->makeComponentPlan($component, qtyToConsume: 5.0, availableQty: 20.0);
        $plan = $this->buildPlan($output, $snapshot, [$componentPlan], qtyToManufacture: 5.0);

        $this->executor->execute($plan, $this->company->id);
        $this->executor->execute($plan, $this->company->id); // replay

        $item = InventoryItem::query()
            ->where('product_id', $component->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->first();

        // Consumed only once: 20 - 5 = 15 (not 20 - 5 - 5 = 10)
        $this->assertSame(15.0, (float) $item->on_hand_qty);
    }

    // ── 4. Rollback ───────────────────────────────────────────────────────────

    public function test_rollback_restores_inventory_on_transaction_creation_failure(): void
    {
        $output    = $this->makeOutput();
        $component = $this->makeComponent();
        $snapshot  = $this->makeRecipeSnapshot($output, [$component], [1.0]);

        $rawItem = $this->seedInventory($component, onHand: 20.0);
        $componentPlan = $this->makeComponentPlan($component, qtyToConsume: 5.0, availableQty: 20.0);
        $plan = $this->buildPlan($output, $snapshot, [$componentPlan], qtyToManufacture: 5.0);

        // Mock the repository to throw after inventory mutations start
        $mockRepo = $this->createMock(ManufacturingTransactionRepositoryInterface::class);
        $mockRepo->method('findByPlanId')->willReturn(null);
        $mockRepo->method('save')->willThrowException(new \RuntimeException('Simulated DB failure'));

        $executor = new ManufacturingExecutor(
            inventoryItems: app(InventoryItemRepositoryInterface::class),
            transactions:   $mockRepo,
        );

        try {
            $executor->execute($plan, $this->company->id);
            $this->fail('Expected exception was not thrown');
        } catch (\Throwable) {
            // Expected
        }

        // Inventory must be rolled back
        $rawItem->refresh();
        $this->assertSame(20.0, (float) $rawItem->on_hand_qty);

        // No ledger entries must exist
        $entries = StockLedgerEntry::query()
            ->where('reference_type', 'manufacturing_plan')
            ->where('reference_id', $plan->plan_id)
            ->get();
        $this->assertCount(0, $entries);

        // No transaction record must exist
        $tx = ManufacturingTransaction::query()->where('plan_id', $plan->plan_id)->first();
        $this->assertNull($tx);
    }

    // ── 5. Pre-execution Guards ───────────────────────────────────────────────

    public function test_plan_not_approved_throws_execution_exception(): void
    {
        $output   = $this->makeOutput();
        $snapshot = $this->makeRecipeSnapshot($output, [], []);

        $plan = $this->buildPlan($output, $snapshot, [], qtyToManufacture: 0.0, shouldManufacture: false);

        $this->expectException(ExecutionException::class);
        $this->expectExceptionMessage("should_manufacture is false");

        $this->executor->execute($plan, $this->company->id);
    }

    public function test_plan_not_approved_does_not_write_to_db(): void
    {
        $output   = $this->makeOutput();
        $snapshot = $this->makeRecipeSnapshot($output, [], []);
        $plan     = $this->buildPlan($output, $snapshot, [], qtyToManufacture: 0.0, shouldManufacture: false);

        try {
            $this->executor->execute($plan, $this->company->id);
        } catch (ExecutionException) {
        }

        $this->assertDatabaseCount('manufacturing_transactions', 0);
        $this->assertDatabaseCount('stock_ledger_entries', 0);
    }

    public function test_snapshot_mismatch_throws_execution_exception(): void
    {
        $output    = $this->makeOutput();
        $component = $this->makeComponent();
        $snapshot  = $this->makeRecipeSnapshot($output, [$component], [1.0]);

        $componentPlan = $this->makeComponentPlan($component, qtyToConsume: 5.0, availableQty: 10.0);
        $plan = $this->buildPlan(
            $output, $snapshot, [$componentPlan],
            qtyToManufacture: 5.0,
            overrideHash: 'deadbeef-this-is-not-the-correct-hash-aaaaaaaaaaaaaaaaaaaaaaaa',
        );

        $this->expectException(ExecutionException::class);

        $this->executor->execute($plan, $this->company->id);
    }

    public function test_snapshot_mismatch_exception_has_correct_reason(): void
    {
        $output    = $this->makeOutput();
        $component = $this->makeComponent();
        $snapshot  = $this->makeRecipeSnapshot($output, [$component], [1.0]);

        $componentPlan = $this->makeComponentPlan($component, qtyToConsume: 5.0, availableQty: 10.0);
        $plan = $this->buildPlan(
            $output, $snapshot, [$componentPlan],
            qtyToManufacture: 5.0,
            overrideHash: str_repeat('0', 64),
        );

        try {
            $this->executor->execute($plan, $this->company->id);
            $this->fail('Expected ExecutionException');
        } catch (ExecutionException $e) {
            $this->assertSame(ExecutionException::SNAPSHOT_MISMATCH, $e->reason());
        }
    }

    public function test_snapshot_missing_throws_when_hash_is_null(): void
    {
        $output    = $this->makeOutput();
        $component = $this->makeComponent();
        $snapshot  = $this->makeRecipeSnapshot($output, [$component], [1.0]);

        // Build plan manually with null hash but should_manufacture = true
        $plan = new ManufacturingPlan(
            plan_id:                   $this->generateUuid(),
            product_id:                $output->id,
            warehouse_id:              $this->warehouse->id,
            product_sku:               $output->sku,
            product_name:              $output->name,
            qty_to_manufacture:        5.0,
            finished_goods_to_produce: 5.0,
            available_finished_goods:  0.0,
            recipe_id:                 $snapshot->recipe_id,
            bom_version_number:        1,
            recipe_snapshot:           $snapshot,
            recipe_snapshot_hash:      null, // intentionally missing
            components:                [],
            negative_stock_decisions:  [],
            eligibility:               ManufacturingEligibility::CanManufacture,
            can_proceed:               true,
            should_manufacture:        true,
            decision_type:             DecisionType::Approve,
            decision_reason:           new DecisionReason(code: 'test', message: 'test'),
            planned_at:                (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            metadata:                  [],
        );

        $this->expectException(ExecutionException::class);
        $this->executor->execute($plan, $this->company->id);

        try {
            $this->executor->execute($plan, $this->company->id);
            $this->fail('Expected ExecutionException');
        } catch (ExecutionException $e) {
            $this->assertSame(ExecutionException::SNAPSHOT_MISSING, $e->reason());
        }
    }

    // ── 6. Transaction Integrity ──────────────────────────────────────────────

    public function test_transaction_has_correct_bom_version_for_rc10(): void
    {
        $output    = $this->makeOutput();
        $component = $this->makeComponent();
        $snapshot  = $this->makeRecipeSnapshot($output, [$component], [1.0]);

        $this->seedInventory($component, onHand: 20.0);
        $componentPlan = $this->makeComponentPlan($component, qtyToConsume: 5.0, availableQty: 20.0);
        $plan = $this->buildPlan($output, $snapshot, [$componentPlan], qtyToManufacture: 5.0);

        $this->executor->execute($plan, $this->company->id);

        $tx = ManufacturingTransaction::query()->where('plan_id', $plan->plan_id)->first();
        $this->assertSame(1, $tx->bom_version_number); // from RecipeSnapshot
        $this->assertSame($snapshot->recipe_id, $tx->bom_id);
    }

    public function test_multiple_components_all_consumed(): void
    {
        $output = $this->makeOutput();
        $mat1   = $this->makeComponent();
        $mat2   = $this->makeComponent();
        $snapshot = $this->makeRecipeSnapshot($output, [$mat1, $mat2], [2.0, 3.0]);

        $this->seedInventory($mat1, onHand: 20.0);
        $this->seedInventory($mat2, onHand: 30.0);

        $plan = $this->buildPlan($output, $snapshot, [
            $this->makeComponentPlan($mat1, qtyToConsume: 10.0, availableQty: 20.0),
            $this->makeComponentPlan($mat2, qtyToConsume: 15.0, availableQty: 30.0),
        ], qtyToManufacture: 5.0);

        $result = $this->executor->execute($plan, $this->company->id);

        $this->assertCount(2, $result->consumed_components);
        $this->assertCount(3, $result->ledger_entry_ids); // 2 consumption + 1 production

        $mat1Item = InventoryItem::query()->where('product_id', $mat1->id)->first();
        $mat2Item = InventoryItem::query()->where('product_id', $mat2->id)->first();
        $this->assertSame(10.0, (float) $mat1Item->on_hand_qty); // 20 - 10
        $this->assertSame(15.0, (float) $mat2Item->on_hand_qty); // 30 - 15
    }

    public function test_execution_creates_fg_inventory_item_when_none_exists(): void
    {
        $output    = $this->makeOutput();
        $component = $this->makeComponent();
        $snapshot  = $this->makeRecipeSnapshot($output, [$component], [1.0]);

        // No FG inventory seeded — executor should create it
        $this->seedInventory($component, onHand: 10.0);
        $componentPlan = $this->makeComponentPlan($component, qtyToConsume: 5.0, availableQty: 10.0);
        $plan = $this->buildPlan($output, $snapshot, [$componentPlan], qtyToManufacture: 5.0);

        $this->executor->execute($plan, $this->company->id);

        $fgItem = InventoryItem::query()
            ->where('product_id', $output->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->first();

        $this->assertNotNull($fgItem);
        $this->assertSame(5.0, (float) $fgItem->on_hand_qty);
    }
}

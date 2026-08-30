<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Commerce\Orders\Application\Actions\PrepareOrderAction;
use Modules\Commerce\Orders\Application\Actions\PrepareOrderManufacturingAction;
use Modules\Commerce\Orders\Domain\Enums\OrderLineManufacturingState;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Inventory\InventoryItems\Domain\Models\InventoryItem;
use Modules\Inventory\InventoryItems\Domain\Models\StockLedgerEntry;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Manufacturing\BillsOfMaterials\Domain\Models\Recipe;
use Modules\Manufacturing\DecisionKernel\Domain\Enums\DecisionType;
use Modules\Manufacturing\DecisionKernel\Domain\Services\InMemoryRuleProvider;
use Modules\Manufacturing\DecisionKernel\Domain\ValueObjects\DecisionReason;
use Modules\Manufacturing\DecisionKernel\Domain\ValueObjects\DecisionRule;
use Modules\Manufacturing\DecisionOrchestrator\Domain\Contracts\RuleProviderRegistryInterface;
use Modules\Manufacturing\DecisionOrchestrator\Domain\Services\DecisionOrchestrator;
use Modules\Manufacturing\ManufacturingService\Application\Services\ManufacturingApplicationService;
use Modules\Manufacturing\ManufacturingWorkflow\Domain\Services\ManufacturingWorkflow;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Operations\OrderLifecycle\Application\Services\OrderLifecycleCoordinator;
use Modules\Operations\Preparation\Domain\Events\WaveStarted;
use Modules\Organization\Brands\Domain\Models\Brand;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-MTO-PRODUCTION-QUANTITY-ACCURACY-FIX-001 — end-to-end, exact-number proof.
 *
 * Drives the REAL canonical manufacturing chain through BOTH triggers:
 *   - manual operator prepare  (PrepareOrderAction → MoveToPreparation → PrepareOrderManufacturingAction)
 *   - automated preparation wave (WaveStarted → HandlePreparationWaveStarted → same trigger)
 *
 * Both reserve the finished good BEFORE manufacturing, which is the precondition that made
 * the availability engine over-produce (reserved availability re-added to the shortage).
 * After the fix each path produces EXACTLY the ordered quantity, consumes raw material of
 * exactly `recipe_qty × produced`, writes a production_output ledger row of exactly the
 * produced quantity, and is idempotent. Nothing downstream (loading, custody, delivery,
 * finance) is touched — the correction lives entirely at the manufacturing quantity source.
 */
class MtoManufacturingQuantityIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Brand $brand;

    private Warehouse $warehouse;

    private Customer $customer;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetSingletons();
        $this->registerRule(DecisionType::Approve);

        $this->company = Company::factory()->create();
        $this->brand = Brand::factory()->create(['company_id' => $this->company->id]);
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
        $this->customer = Customer::factory()->create();
        $this->actor = User::factory()->create(['company_id' => $this->company->id]);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Wave path — produce EXACTLY the ordered quantity (not 2×), with an exact
    // production_output ledger row.
    // ═════════════════════════════════════════════════════════════════════════

    public function test_wave_path_manufactures_exactly_the_ordered_quantity(): void
    {
        $fg = $this->makeMtoProduct();
        $component = $this->makeComponent();
        $recipe = $this->makeRecipe($fg);
        $this->addLine($recipe, $component, 1.0);
        $this->seedInventory($component, 50.0);

        $order = $this->makeOrder([['product_id' => $fg->id, 'quantity' => 2.0]]);
        $line = $order->lines->first();

        self::assertSame(0.0, $this->onHand($fg), 'made to order — nothing on hand to start');

        $this->fireWaveStarted([$order->id]);

        $order->refresh();
        $line->refresh();
        self::assertSame(OrderStatus::ReadyForDispatch, $order->status);
        self::assertSame(OrderLineManufacturingState::Executed, $line->manufacturing_state);

        // Exactly one transaction, producing EXACTLY the ordered quantity.
        self::assertDatabaseCount('manufacturing_transactions', 1);
        $txn = DB::table('manufacturing_transactions')->first();
        self::assertSame(2.0, (float) $txn->qty_produced, 'produced exactly the ordered 2 (pre-fix produced 4)');

        // Warehouse on_hand rose by exactly the ordered quantity; reservation intact.
        $item = $this->item($fg);
        self::assertSame(2.0, (float) $item->on_hand_qty, 'on_hand equals the ordered quantity, not double');
        self::assertSame(2.0, (float) $item->reserved_qty, 'the order reservation is unchanged by manufacturing');

        // Production-output ledger row records exactly the produced quantity.
        $output = StockLedgerEntry::query()
            ->where('product_id', $fg->id)
            ->where('movement_type', 'production_output')
            ->first();
        self::assertNotNull($output);
        self::assertSame(0.0, (float) $output->on_hand_before);
        self::assertSame(2.0, (float) $output->quantity, 'ledger quantity == produced quantity');
        self::assertSame(2.0, (float) $output->on_hand_after);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Manual prepare path — same corrected quantity through the operator entry point.
    // ═════════════════════════════════════════════════════════════════════════

    public function test_manual_prepare_path_manufactures_exactly_the_ordered_quantity(): void
    {
        $fg = $this->makeMtoProduct();
        $component = $this->makeComponent();
        $recipe = $this->makeRecipe($fg);
        $this->addLine($recipe, $component, 1.0);
        $this->seedInventory($component, 50.0);

        $order = $this->makeOrder([['product_id' => $fg->id, 'quantity' => 2.0]]);

        app(PrepareOrderAction::class)->execute($order->id);

        $order->refresh();
        self::assertSame(OrderStatus::ReadyForDispatch, $order->status, 'manual prepare reserved and advanced the order');
        self::assertSame(OrderLineManufacturingState::Executed, $order->lines->first()->refresh()->manufacturing_state);

        self::assertDatabaseCount('manufacturing_transactions', 1);
        $txn = DB::table('manufacturing_transactions')->first();
        self::assertSame(2.0, (float) $txn->qty_produced, 'manual path also produces exactly the ordered quantity');
        self::assertSame(2.0, (float) $this->item($fg)->on_hand_qty);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Raw material consumed == recipe_qty × ACTUAL production (not the inflated qty).
    // recipe component qty 2, order 3 → produce 3, consume 6.
    // ═════════════════════════════════════════════════════════════════════════

    public function test_raw_material_consumption_equals_recipe_qty_times_production(): void
    {
        $fg = $this->makeMtoProduct();
        $component = $this->makeComponent();
        $recipe = $this->makeRecipe($fg);
        $this->addLine($recipe, $component, 2.0); // 2 units of RM per FG
        $this->seedInventory($component, 50.0);

        $order = $this->makeOrder([['product_id' => $fg->id, 'quantity' => 3.0]]);

        $this->fireWaveStarted([$order->id]);

        // FG: produced exactly 3.
        $txn = DB::table('manufacturing_transactions')->first();
        self::assertSame(3.0, (float) $txn->qty_produced);

        // RM: consumed exactly 2 × 3 = 6 (pre-fix would have consumed 2 × 6 = 12).
        self::assertSame(44.0, $this->onHand($component), 'raw material fell by exactly recipe_qty × production (50 − 6)');
        self::assertDatabaseHas('stock_ledger_entries', [
            'product_id' => $component->id,
            'movement_type' => 'production_consumption',
        ]);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Idempotency — repeated wave + direct re-fire produce no additional finished goods.
    // ═════════════════════════════════════════════════════════════════════════

    public function test_repeated_execution_produces_no_additional_finished_goods(): void
    {
        $fg = $this->makeMtoProduct();
        $component = $this->makeComponent();
        $recipe = $this->makeRecipe($fg);
        $this->addLine($recipe, $component, 1.0);
        $this->seedInventory($component, 50.0);

        $order = $this->makeOrder([['product_id' => $fg->id, 'quantity' => 2.0]]);

        $this->fireWaveStarted([$order->id]);
        self::assertDatabaseCount('manufacturing_transactions', 1);
        self::assertSame(2.0, $this->onHand($fg));

        // Repeat the wave, then re-invoke the canonical trigger directly.
        $this->fireWaveStarted([$order->id]);
        app(PrepareOrderManufacturingAction::class)->execute($order->fresh());

        self::assertDatabaseCount('manufacturing_transactions', 1);
        self::assertSame(2.0, $this->onHand($fg), 'on_hand did not change on re-fire — no double production');
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Non-MTO order is skipped — the quantity fix does not change eligibility rules.
    // ═════════════════════════════════════════════════════════════════════════

    public function test_non_mto_order_does_not_manufacture(): void
    {
        $purchased = $this->makePurchasedProduct();
        $this->seedInventory($purchased, 5.0);

        $order = $this->makeOrder([['product_id' => $purchased->id, 'quantity' => 2.0]]);

        $this->fireWaveStarted([$order->id]);

        $order->refresh();
        self::assertSame(OrderStatus::ReadyForDispatch, $order->status);
        self::assertSame(OrderLineManufacturingState::Skipped, $order->lines->first()->refresh()->manufacturing_state);
        self::assertDatabaseCount('manufacturing_transactions', 0);
        self::assertSame(5.0, $this->onHand($purchased), 'purchased FG stock untouched');
    }

    // ── Singleton / rule plumbing (mirrors WaveDrivenManufacturingTriggerTest) ──

    private function resetSingletons(): void
    {
        $this->app->forgetInstance(RuleProviderRegistryInterface::class);
        $this->app->forgetInstance(DecisionOrchestrator::class);
        $this->app->forgetInstance(ManufacturingWorkflow::class);
        $this->app->forgetInstance(ManufacturingApplicationService::class);
        $this->app->forgetInstance(OrderLifecycleCoordinator::class);
    }

    private function registerRule(DecisionType $type, string $id = 'mtoq-mfg-rule'): void
    {
        app(RuleProviderRegistryInterface::class)->register(
            'manufacturing',
            new InMemoryRuleProvider(
                new DecisionRule(
                    rule_id: $id,
                    name: "Test rule: {$type->label()}",
                    priority: 1,
                    decision_type: $type,
                    reason: new DecisionReason(code: "test_{$type->value}", message: $type->label()),
                    condition: fn ($ctx) => true,
                ),
            ),
        );
    }

    // ── Domain fixtures ─────────────────────────────────────────────────────────

    private function makeMtoProduct(): Product
    {
        $product = Product::factory()->finishedGood()->create(['brand_id' => $this->brand->id]);
        $product->update(['can_manufacture' => true]);

        return $product->refresh();
    }

    private function makePurchasedProduct(): Product
    {
        return Product::factory()->finishedGood()->create([
            'brand_id' => $this->brand->id,
            'can_manufacture' => false,
        ]);
    }

    private function makeComponent(): Product
    {
        return Product::factory()->rawMaterial()->create(['brand_id' => $this->brand->id]);
    }

    private function makeRecipe(Product $output): Recipe
    {
        return Recipe::create([
            'bom_number' => 'BOM-MTOQ-'.uniqid(),
            'product_id' => $output->id,
            'version' => '1.0',
            'bom_version_number' => 1,
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

    private function seedInventory(Product $product, float $onHand): void
    {
        InventoryItem::query()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $product->id,
            'company_id' => $this->company->id,
            'on_hand_qty' => $onHand,
            'reserved_qty' => 0.0,
        ]);
    }

    /** @param  list<array{product_id: string, quantity: float}>  $lineData */
    private function makeOrder(array $lineData): Order
    {
        $order = Order::create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'assigned_warehouse_id' => $this->warehouse->id,
            'order_number' => 'MTOQ-'.Str::random(6),
            'order_date' => now()->toDateString(),
            'status' => OrderStatus::InProgress->value,
            'subtotal' => 0,
            'total' => 0,
        ]);

        foreach ($lineData as $line) {
            $order->lines()->create([
                'product_id' => $line['product_id'],
                'quantity' => $line['quantity'],
                'unit_price' => 10.0,
                'line_total' => $line['quantity'] * 10.0,
            ]);
        }

        return $order->load('lines.product');
    }

    /** @param  list<string>  $orderIds */
    private function fireWaveStarted(array $orderIds): void
    {
        event(new WaveStarted(
            waveId: (string) Str::uuid(),
            waveNumber: 'W-'.Str::random(5),
            companyId: (string) $this->company->id,
            warehouseId: (string) $this->warehouse->id,
            planningDate: now()->toDateString(),
            orderIds: $orderIds,
            startedBy: (string) $this->actor->id,
            startedAt: now()->toIso8601String(),
        ));
    }

    // ── Readers ─────────────────────────────────────────────────────────────────

    private function item(Product $product): InventoryItem
    {
        return InventoryItem::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $product->id)
            ->firstOrFail();
    }

    private function onHand(Product $product): float
    {
        $item = InventoryItem::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $product->id)
            ->first();

        return $item ? (float) $item->on_hand_qty : 0.0;
    }
}

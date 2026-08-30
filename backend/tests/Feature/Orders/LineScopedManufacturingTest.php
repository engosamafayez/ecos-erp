<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
use Modules\Organization\Brands\Domain\Models\Brand;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-MTO-GATE1-PRECONDITION-CLOSURE-001 — Blocker B (line-scoped manufacturing) +
 * Blocker A (quantity accuracy) proven together through the REAL canonical pipeline.
 *
 * PrepareOrderManufacturingAction::executeForLines() must manufacture ONLY the authorized
 * line and never touch a sibling eligible line, and must produce EXACTLY the ordered
 * quantity even when the finished good is fully reserved (the negative-availability case
 * that previously over-produced). No second manufacturing engine is introduced — the same
 * OrderLifecycleCoordinator → ManufacturingExecutor path is used, just line-scoped.
 *
 * Uses isolated fixtures only. ORD-00014 is NOT used.
 */
class LineScopedManufacturingTest extends TestCase
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
    // CRITICAL SAFETY TEST — manufacture ONLY the authorized line; sibling untouched.
    // ═════════════════════════════════════════════════════════════════════════

    public function test_line_scoped_manufacture_produces_only_the_authorized_line(): void
    {
        // Line A — authorized. Fully reserved (pool 6) → negative availability.
        $fgA = $this->makeMtoProduct();
        $compA = $this->makeComponent();
        $this->addLine($this->makeRecipe($fgA), $compA, 1.0);
        $this->seedInventory($compA, onHand: 50.0);
        $this->seedInventory($fgA, onHand: 0.0, reserved: 6.0);

        // Line B — eligible, but NOT authorized. Must remain completely untouched.
        $fgB = $this->makeMtoProduct();
        $compB = $this->makeComponent();
        $this->addLine($this->makeRecipe($fgB), $compB, 1.0);
        $this->seedInventory($compB, onHand: 50.0);
        $this->seedInventory($fgB, onHand: 0.0, reserved: 6.0);

        $order = $this->makeOrder([
            ['product_id' => $fgA->id, 'quantity' => 1.0],
            ['product_id' => $fgB->id, 'quantity' => 1.0],
        ]);
        $lineA = $order->lines->firstWhere('product_id', $fgA->id);
        $lineB = $order->lines->firstWhere('product_id', $fgB->id);

        // Manufacture ONLY line A.
        app(PrepareOrderManufacturingAction::class)->executeForLines($order, [$lineA->id]);

        // ── Line A manufactured exactly once, exact quantity (not 1 + reserved 6 = 7) ──
        self::assertDatabaseCount('manufacturing_transactions', 1);
        $txn = DB::table('manufacturing_transactions')->first();
        self::assertSame($lineA->id, $txn->order_line_id, 'the only transaction is for line A');
        self::assertSame(1.0, (float) $txn->qty_produced, 'exactly the ordered 1 (fix prevents 7)');
        self::assertSame(OrderLineManufacturingState::Executed, $lineA->refresh()->manufacturing_state);
        self::assertSame(1.0, $this->onHand($fgA));
        self::assertSame(49.0, $this->onHand($compA), 'component A consumed 1 (recipe 1 × produced 1)');

        // ── Line B completely untouched ──────────────────────────────────────────
        self::assertNull($lineB->refresh()->manufacturing_state, 'line B was never processed');
        self::assertNull($lineB->manufacturing_started_at);
        self::assertSame(0.0, $this->onHand($fgB), 'no FG produced for B');
        self::assertSame(50.0, $this->onHand($compB), 'no RM consumed for B');
        self::assertSame(
            0,
            StockLedgerEntry::where('product_id', $fgB->id)->where('movement_type', 'production_output')->count(),
            'no production ledger for B',
        );
        self::assertSame(
            0,
            DB::table('manufacturing_transactions')->where('order_line_id', $lineB->id)->count(),
            'no manufacturing transaction for B',
        );
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Quantity safety through the line-scoped path — the ORD-00014 Honey pattern
    // (reserved 6) and the historical worst case (reserved 15).
    // ═════════════════════════════════════════════════════════════════════════

    public function test_reserved_pool_does_not_over_produce_through_line_scope(): void
    {
        foreach ([6.0 => 1.0, 15.0 => 1.0] as $reserved => $expected) {
            $fg = $this->makeMtoProduct();
            $comp = $this->makeComponent();
            $this->addLine($this->makeRecipe($fg), $comp, 1.0);
            $this->seedInventory($comp, onHand: 500.0);
            $this->seedInventory($fg, onHand: 0.0, reserved: $reserved);

            $order = $this->makeOrder([['product_id' => $fg->id, 'quantity' => 1.0]]);
            $line = $order->lines->first();

            app(PrepareOrderManufacturingAction::class)->executeForLines($order, [$line->id]);

            self::assertSame($expected, $this->onHand($fg), "reserved {$reserved} → produce exactly {$expected}, never 1+reserved");
        }
    }

    public function test_empty_line_scope_is_a_no_op_never_all_lines(): void
    {
        $fg = $this->makeMtoProduct();
        $comp = $this->makeComponent();
        $this->addLine($this->makeRecipe($fg), $comp, 1.0);
        $this->seedInventory($comp, onHand: 50.0);
        $this->seedInventory($fg, onHand: 0.0, reserved: 1.0);
        $order = $this->makeOrder([['product_id' => $fg->id, 'quantity' => 1.0]]);

        app(PrepareOrderManufacturingAction::class)->executeForLines($order, []);

        self::assertDatabaseCount('manufacturing_transactions', 0);
        self::assertSame(0.0, $this->onHand($fg), 'empty scope manufactures nothing');
    }

    // ── plumbing (mirrors MtoManufacturingQuantityIntegrationTest) ──

    private function resetSingletons(): void
    {
        $this->app->forgetInstance(RuleProviderRegistryInterface::class);
        $this->app->forgetInstance(DecisionOrchestrator::class);
        $this->app->forgetInstance(ManufacturingWorkflow::class);
        $this->app->forgetInstance(ManufacturingApplicationService::class);
        $this->app->forgetInstance(OrderLifecycleCoordinator::class);
    }

    private function registerRule(DecisionType $type): void
    {
        app(RuleProviderRegistryInterface::class)->register(
            'manufacturing',
            new InMemoryRuleProvider(new DecisionRule(
                rule_id: 'linescope-rule',
                name: "Test rule: {$type->label()}",
                priority: 1,
                decision_type: $type,
                reason: new DecisionReason(code: "test_{$type->value}", message: $type->label()),
                condition: fn ($ctx) => true,
            )),
        );
    }

    private function makeMtoProduct(): Product
    {
        $p = Product::factory()->finishedGood()->create(['brand_id' => $this->brand->id]);
        $p->update(['can_manufacture' => true]);

        return $p->refresh();
    }

    private function makeComponent(): Product
    {
        return Product::factory()->rawMaterial()->create(['brand_id' => $this->brand->id]);
    }

    private function makeRecipe(Product $output): Recipe
    {
        return Recipe::create([
            'bom_number' => 'BOM-LS-'.uniqid(),
            'product_id' => $output->id,
            'version' => '1.0',
            'bom_version_number' => 1,
            'is_active' => true,
        ]);
    }

    private function addLine(Recipe $recipe, Product $component, float $qty): void
    {
        $recipe->components()->create(['raw_material_id' => $component->id, 'quantity' => $qty]);
    }

    private function seedInventory(Product $product, float $onHand, float $reserved = 0.0): void
    {
        InventoryItem::query()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $product->id,
            'company_id' => $this->company->id,
            'on_hand_qty' => $onHand,
            'reserved_qty' => $reserved,
        ]);
    }

    /** @param list<array{product_id:string, quantity:float}> $lineData */
    private function makeOrder(array $lineData): Order
    {
        $order = Order::create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'assigned_warehouse_id' => $this->warehouse->id,
            'order_number' => 'LS-'.Str::random(6),
            'order_date' => now()->toDateString(),
            'status' => OrderStatus::ReadyForDispatch->value,
            'subtotal' => 0,
            'total' => 0,
        ]);

        foreach ($lineData as $l) {
            $order->lines()->create([
                'product_id' => $l['product_id'],
                'quantity' => $l['quantity'],
                'unit_price' => 10.0,
                'line_total' => $l['quantity'] * 10.0,
            ]);
        }

        return $order->load('lines.product');
    }

    private function onHand(Product $product): float
    {
        $item = InventoryItem::where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $product->id)->first();

        return $item ? (float) $item->on_hand_qty : 0.0;
    }
}

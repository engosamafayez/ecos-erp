<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Models\User;
use DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Commerce\Orders\Application\Actions\PrepareOrderManufacturingAction;
use Modules\Commerce\Orders\Application\Listeners\HandlePreparationWavePreparationStarted;
use Modules\Commerce\Orders\Domain\Enums\OrderLineManufacturingState;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\OrderLine;
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
use Modules\Operations\Loading\Application\Actions\TransferLoadedStockToVehicleAction;
use Modules\Operations\Loading\Domain\Models\LoadingSession;
use Modules\Operations\Loading\Domain\Models\LoadingTask;
use Modules\Operations\Loading\Domain\Models\VehicleAssignment;
use Modules\Operations\Loading\Domain\Models\VehicleInventoryItem;
use Modules\Operations\Loading\Domain\Services\VehicleInventoryService;
use Modules\Operations\OrderLifecycle\Application\Services\OrderLifecycleCoordinator;
use Modules\Operations\Preparation\Domain\Events\WavePreparationStarted;
use Modules\Operations\Preparation\Domain\Events\WaveStarted;
use Modules\Organization\Brands\Domain\Models\Brand;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-MTO-MANUFACTURING-TRIGGER-GAP — systemic-fix verification.
 *
 * ┌─ THE GAP THIS SUITE PINS CLOSED ─────────────────────────────────────────┐
 * │ The automated preparation WAVE is the real production path, yet it only    │
 * │ reserved stock and NEVER manufactured. Two independent breaks caused it:   │
 * │   BREAK A — ManufacturingLifecycleHandler::supports() gated on stale pre-  │
 * │             ADR-042 statuses (pending/processing/preparing), so the handler │
 * │             ignored every real order → StatusIgnored → Skipped.            │
 * │   BREAK B — HandlePreparationWaveStarted /                                  │
 * │             HandlePreparationWavePreparationStarted ran MoveToPreparation-  │
 * │             Workflow only; the canonical PrepareOrderManufacturingAction    │
 * │             had a single caller, the MANUAL PrepareOrderAction.            │
 * │ Net: zero made-to-order finished goods were ever produced into warehouse   │
 * │ stock, which is why the downstream Warehouse→Vehicle custody transfer      │
 * │ correctly refused (on_hand 0, allow_negative_stock false).                 │
 * └────────────────────────────────────────────────────────────────────────────┘
 *
 * Everything here is driven through the REAL wired events (WaveStarted /
 * WavePreparationStarted) and the REAL canonical actions — no manufacturing
 * logic is duplicated and no second engine is introduced. The chain proven is:
 *
 *   Wave → Preparation (reserve) → MTO Manufacturing → Production Output
 *        → Warehouse Stock → TransferLoadedStockToVehicleAction → Vehicle Custody
 *
 * ┌─ PRODUCED-QUANTITY NOTE (pre-existing defect, out of scope for the trigger fix) ─┐
 * │ This suite asserts the INVARIANTS the trigger fix guarantees — manufacturing     │
 * │ fires exactly once, FG on_hand rises from zero and equals the transaction's      │
 * │ recorded qty, raw material is consumed, and the Warehouse→Vehicle transfer moves  │
 * │ EXACTLY the loaded quantity — rather than the absolute produced magnitude.        │
 * │                                                                                  │
 * │ Reason: `InventoryAvailabilityEngine::analyse()` computes                         │
 * │ `qty_to_manufacture = required − availableQty`, and `availableQty = on_hand −     │
 * │ reserved`. In ECOS's order-driven / made-to-order flow the order's OWN            │
 * │ reservation has already committed `required` on the FG (on_hand 0, reserved       │
 * │ `required`), so `availableQty = −required` and the engine over-produces           │
 * │ `2 × required`. This predates and is independent of the trigger-gap fix, hits the │
 * │ manual PrepareOrderAction path identically (whose tests never asserted on_hand),  │
 * │ and lives inside the manufacturing engine this task must not modify. It is        │
 * │ reported as a separate finding for an owner decision. The DELTA facts this suite  │
 * │ pins are exact and hold both before and after any future quantity fix.            │
 * └──────────────────────────────────────────────────────────────────────────────────┘
 */
class WaveDrivenManufacturingTriggerTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    /**
     * ADR-027 §16.4 scopes recipe-component availability to the company that owns the
     * finished good (Product → Brand → Company). Every product in this suite hangs off
     * THIS brand so the finished good, its components and the warehouse share one tenant.
     */
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
    // 1. The core: a wave-driven MTO order manufactures exactly once and posts
    //    production output into warehouse stock.
    // ═════════════════════════════════════════════════════════════════════════

    public function test_wave_driven_mto_order_manufactures_once_and_posts_production_output(): void
    {
        $fg = $this->makeMtoProduct();
        $component = $this->makeComponent();
        $recipe = $this->makeRecipe($fg);
        $this->addLine($recipe, $component, 1.0);
        // Seeded generously: see PRODUCED-QUANTITY NOTE on the class. The engine
        // over-computes qty_to_manufacture, so the recipe must stay executable.
        $this->seedInventory($component, 50.0);

        $order = $this->makeWaveOrder([['product_id' => $fg->id, 'quantity' => 2.0]]);
        $line = $order->lines->first();

        // BEFORE: nothing produced, nothing manufactured.
        self::assertSame(0.0, $this->onHand($fg), 'FG starts at zero on_hand — it is made to order');
        self::assertDatabaseCount('manufacturing_transactions', 0);

        $this->fireWaveStarted([$order->id]);

        // ── Order reached Ready for Dispatch via the wave path ──────────────────
        $order->refresh();
        self::assertSame(OrderStatus::ReadyForDispatch, $order->status);

        // ── The line manufactured exactly once ─────────────────────────────────
        $line->refresh();
        self::assertSame(OrderLineManufacturingState::Executed, $line->manufacturing_state);
        self::assertNotNull($line->manufacturing_started_at);
        self::assertNotNull($line->manufacturing_completed_at);

        // ── The canonical manufacturing transaction exists ─────────────────────
        self::assertDatabaseCount('manufacturing_transactions', 1);
        $txn = DB::table('manufacturing_transactions')->first();
        self::assertSame($fg->id, $txn->product_id);
        self::assertSame($this->warehouse->id, $txn->warehouse_id);
        self::assertSame($line->id, $txn->order_line_id, 'RC-10: the transaction traces to the order line');
        $produced = (float) $txn->qty_produced;
        self::assertGreaterThan(0.0, $produced, 'the transaction records a positive produced quantity');

        // ── Production output posted into warehouse FG stock ────────────────────
        // Invariant asserted, not the absolute magnitude (see the class PRODUCED-QUANTITY
        // NOTE): on_hand rose from zero, matches exactly what the transaction recorded, and
        // is at least enough to cover the ordered quantity.
        $fgItem = $this->item($fg);
        self::assertGreaterThan(0.0, (float) $fgItem->on_hand_qty, 'production output incremented on_hand');
        self::assertSame($produced, (float) $fgItem->on_hand_qty, 'warehouse on_hand equals the produced quantity');
        self::assertGreaterThanOrEqual(2.0, (float) $fgItem->on_hand_qty, 'at least the ordered quantity was produced');

        $output = StockLedgerEntry::query()
            ->where('product_id', $fg->id)
            ->where('movement_type', 'production_output')
            ->first();
        self::assertNotNull($output, 'a production_output ledger entry was written');
        self::assertSame(0.0, (float) $output->on_hand_before, 'production started from zero on_hand');
        self::assertSame($produced, (float) $output->quantity, 'the ledger records the produced quantity');
        self::assertSame($produced, (float) $output->on_hand_after);

        // ── Raw material consumed by the recipe ────────────────────────────────
        self::assertLessThan(50.0, $this->onHand($component), 'raw material was consumed from stock');
        self::assertDatabaseHas('stock_ledger_entries', [
            'product_id' => $component->id,
            'movement_type' => 'production_consumption',
        ]);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 2. Idempotency: repeated scheduler/wave execution must not manufacture twice.
    // ═════════════════════════════════════════════════════════════════════════

    public function test_repeated_wave_execution_does_not_manufacture_twice(): void
    {
        $fg = $this->makeMtoProduct();
        $component = $this->makeComponent();
        $recipe = $this->makeRecipe($fg);
        $this->addLine($recipe, $component, 1.0);
        $this->seedInventory($component, 50.0);

        $order = $this->makeWaveOrder([['product_id' => $fg->id, 'quantity' => 2.0]]);

        // First wave run — produces.
        $this->fireWaveStarted([$order->id]);
        self::assertDatabaseCount('manufacturing_transactions', 1);
        $onHandAfterFirst = $this->onHand($fg);
        self::assertGreaterThan(0.0, $onHandAfterFirst, 'the first wave produced stock');

        // Second wave run for the SAME order. The order is now Ready for Dispatch, so
        // the listener's terminal-status guard skips it — no re-manufacture.
        $this->fireWaveStarted([$order->id]);
        self::assertDatabaseCount('manufacturing_transactions', 1); // a repeated wave produced no second transaction
        self::assertSame($onHandAfterFirst, $this->onHand($fg), 'on_hand was not incremented by the repeated wave');

        // And the action-level idempotency guard itself: re-invoking the canonical
        // trigger directly (Executed lines are skipped) also produces nothing new.
        app(PrepareOrderManufacturingAction::class)->execute($order->fresh());
        self::assertDatabaseCount('manufacturing_transactions', 1); // the Executed-line guard blocks a direct re-run
        self::assertSame($onHandAfterFirst, $this->onHand($fg), 'a direct re-run produced nothing further');
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 3. Negative: a non-MTO / non-qualifying order does NOT enter manufacturing,
    //    even though the wave path now reaches the trigger.
    // ═════════════════════════════════════════════════════════════════════════

    public function test_non_mto_order_does_not_enter_manufacturing(): void
    {
        // A purchased finished good — cannot manufacture, no recipe — with real FG
        // stock, so reservation succeeds physically (Case 1) and it reaches dispatch.
        $purchased = $this->makePurchasedProduct();
        $this->seedInventory($purchased, 5.0);

        $order = $this->makeWaveOrder([['product_id' => $purchased->id, 'quantity' => 2.0]]);
        $line = $order->lines->first();

        $this->fireWaveStarted([$order->id]);

        $order->refresh();
        self::assertSame(OrderStatus::ReadyForDispatch, $order->status, 'it reserves from physical stock and reaches dispatch');

        // The wave path DID reach manufacturing evaluation (BREAK B fixed) but the
        // product is ineligible, so the policy rejects it: Skipped, never Executed.
        $line->refresh();
        self::assertSame(OrderLineManufacturingState::Skipped, $line->manufacturing_state);

        self::assertDatabaseCount('manufacturing_transactions', 0); // nothing was manufactured
        self::assertSame(5.0, $this->onHand($purchased), 'FG on_hand is untouched — no production output');
        self::assertSame(
            0,
            StockLedgerEntry::query()
                ->where('product_id', $purchased->id)
                ->where('movement_type', 'production_output')
                ->count(),
            'no production_output ledger entry for a non-manufactured product',
        );
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 4. The engine-path listener (WavePreparationStarted) also triggers manufacturing.
    // ═════════════════════════════════════════════════════════════════════════

    public function test_engine_path_wave_preparation_started_also_manufactures(): void
    {
        $fg = $this->makeMtoProduct();
        $component = $this->makeComponent();
        $recipe = $this->makeRecipe($fg);
        $this->addLine($recipe, $component, 1.0);
        $this->seedInventory($component, 50.0);

        $order = $this->makeWaveOrder([['product_id' => $fg->id, 'quantity' => 3.0]]);
        $line = $order->lines->first();

        $this->fireWavePreparationStarted([$order->id]);

        $order->refresh();
        $line->refresh();
        self::assertSame(OrderStatus::ReadyForDispatch, $order->status);
        self::assertSame(OrderLineManufacturingState::Executed, $line->manufacturing_state);
        self::assertDatabaseCount('manufacturing_transactions', 1);
        self::assertGreaterThan(0.0, $this->onHand($fg), 'the automated WaveEngine path produced FG into warehouse stock');
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 5. Full E2E downstream: manufactured warehouse stock transfers to vehicle
    //    custody; warehouse decreases and custody increases by the exact loaded qty.
    // ═════════════════════════════════════════════════════════════════════════

    public function test_manufactured_stock_transfers_to_vehicle_custody_and_reconciles(): void
    {
        $fg = $this->makeMtoProduct();
        $component = $this->makeComponent();
        $recipe = $this->makeRecipe($fg);
        $this->addLine($recipe, $component, 1.0);
        $this->seedInventory($component, 50.0);

        // Manufacture FG into the warehouse via the wave.
        $order = $this->makeWaveOrder([['product_id' => $fg->id, 'quantity' => 4.0]]);
        $this->fireWaveStarted([$order->id]);

        // Snapshot the produced warehouse position (absolute magnitude is subject to the
        // PRODUCED-QUANTITY NOTE; this E2E asserts the transfer DELTA, which is exact).
        $whOnHandBefore = (float) $this->item($fg)->on_hand_qty;
        $whReservedBefore = (float) $this->item($fg)->reserved_qty;
        $loaded = 3.0;
        self::assertGreaterThanOrEqual($loaded, $whOnHandBefore, 'manufacturing produced enough FG to ship the loaded qty');

        // Load onto a vehicle (custody credit through the canonical service).
        [$assignment, $task] = $this->makeLoadingFixtures($fg, quantityLoaded: $loaded);
        app(VehicleInventoryService::class)->recordLoad($assignment, $task, $loaded, (string) $this->actor->id);

        $custody = $this->custody($assignment->id, $fg->id);
        self::assertSame($loaded, (float) $custody->quantity_loaded, 'vehicle custody credited with the loaded qty');
        self::assertSame($loaded, (float) $custody->quantity_on_hand);

        // The warehouse-side half of the transfer: issue the loaded qty out of stock.
        app(TransferLoadedStockToVehicleAction::class)->execute($task->fresh());

        // ── Warehouse decreased by EXACTLY the loaded quantity ──────────────────
        $whOnHandAfter = (float) $this->item($fg)->on_hand_qty;
        $whReservedAfter = (float) $this->item($fg)->reserved_qty;
        self::assertSame($loaded, $whOnHandBefore - $whOnHandAfter, 'warehouse on_hand fell by exactly the loaded qty');
        self::assertSame($loaded, $whReservedBefore - $whReservedAfter, 'the shipment consumed exactly the loaded qty of reservation');

        // ── The canonical custody-transfer ledger row was written ───────────────
        $transferEntry = StockLedgerEntry::query()
            ->where('product_id', $fg->id)
            ->where('reference_type', TransferLoadedStockToVehicleAction::REFERENCE_TYPE)
            ->where('reference_id', $task->id)
            ->first();
        self::assertNotNull($transferEntry, 'a vehicle_custody_transfer stock-ledger row exists');
        self::assertSame('sales_issue', $transferEntry->movement_type->value);
        self::assertSame($loaded, (float) $transferEntry->quantity);

        // ── Reconciliation: warehouse quantity out == vehicle custody quantity in ─
        self::assertSame(
            (float) $custody->quantity_on_hand,
            $whOnHandBefore - $whOnHandAfter,
            'warehouse decrement == vehicle custody credit == loaded qty',
        );

        // Custody itself is untouched by the warehouse-side transfer (already credited).
        self::assertSame($loaded, (float) $this->custody($assignment->id, $fg->id)->quantity_on_hand);
    }

    public function test_custody_transfer_is_idempotent_on_the_manufactured_position(): void
    {
        $fg = $this->makeMtoProduct();
        $component = $this->makeComponent();
        $recipe = $this->makeRecipe($fg);
        $this->addLine($recipe, $component, 1.0);
        $this->seedInventory($component, 50.0);

        $order = $this->makeWaveOrder([['product_id' => $fg->id, 'quantity' => 4.0]]);
        $this->fireWaveStarted([$order->id]);

        $whOnHandBefore = (float) $this->item($fg)->on_hand_qty;
        $loaded = 3.0;

        [$assignment, $task] = $this->makeLoadingFixtures($fg, quantityLoaded: $loaded);
        app(VehicleInventoryService::class)->recordLoad($assignment, $task, $loaded, (string) $this->actor->id);

        $transfer = app(TransferLoadedStockToVehicleAction::class);
        $transfer->execute($task->fresh());
        $transfer->execute($task->fresh()); // repeat — must move nothing more

        self::assertSame(
            $loaded,
            $whOnHandBefore - (float) $this->item($fg)->on_hand_qty,
            'the warehouse fell by exactly one loaded qty — a repeated transfer did not deduct twice',
        );
        self::assertSame(
            1,
            StockLedgerEntry::query()
                ->where('reference_type', TransferLoadedStockToVehicleAction::REFERENCE_TYPE)
                ->where('reference_id', $task->id)
                ->count(),
            'exactly one custody-transfer ledger row for the task',
        );
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Singleton / rule plumbing — mirrors OrderManufacturingIntegrationTest
    // ═════════════════════════════════════════════════════════════════════════

    private function resetSingletons(): void
    {
        $this->app->forgetInstance(RuleProviderRegistryInterface::class);
        $this->app->forgetInstance(DecisionOrchestrator::class);
        $this->app->forgetInstance(ManufacturingWorkflow::class);
        $this->app->forgetInstance(ManufacturingApplicationService::class);
        $this->app->forgetInstance(OrderLifecycleCoordinator::class);
    }

    private function registerRule(DecisionType $type, string $id = 'wave-mfg-rule'): void
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

    // ═════════════════════════════════════════════════════════════════════════
    // Domain fixtures
    // ═════════════════════════════════════════════════════════════════════════

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
            'bom_number' => 'BOM-WAVE-'.uniqid(),
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
    private function makeWaveOrder(array $lineData): Order
    {
        $order = Order::create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'assigned_warehouse_id' => $this->warehouse->id,
            'order_number' => 'WAVE-'.Str::random(6),
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

    /**
     * @param  list<string>  $orderIds
     *
     * Invoked directly rather than via event() so this asserts the Orders engine-path
     * listener's own behaviour in isolation. Firing the real event would ALSO wake the
     * Distribution group-sweep listener (also bound to WavePreparationStarted), which
     * needs geography this focused suite does not seed. The event→listener registration
     * itself is identical in shape to WaveStarted, which the other tests exercise live.
     */
    private function fireWavePreparationStarted(array $orderIds): void
    {
        app(HandlePreparationWavePreparationStarted::class)->handle(new WavePreparationStarted(
            waveId: (string) Str::uuid(),
            waveNumber: 'W-'.Str::random(5),
            companyId: (string) $this->company->id,
            warehouseId: (string) $this->warehouse->id,
            planningDate: now()->toDateString(),
            ordersCount: count($orderIds),
            orderIds: $orderIds,
            startedBy: (string) $this->actor->id,
            startedAt: now()->toIso8601String(),
        ));
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Loading fixtures — a minimal but real session/assignment/task, built
    // directly (the full HTTP distribution path is a different suite's concern).
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * @return array{0: VehicleAssignment, 1: LoadingTask}
     */
    private function makeLoadingFixtures(Product $product, float $quantityLoaded): array
    {
        $actorId = (string) $this->actor->id;

        $session = LoadingSession::create([
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouse->id,
            'session_number' => 'LS-'.strtoupper(Str::random(6)),
            'operational_date' => now()->toDateString(),
            'status' => 'loading',
            'session_type' => 'standard',
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]);

        $assignment = VehicleAssignment::create([
            'company_id' => $this->company->id,
            'loading_session_id' => $session->id,
            'vehicle_id' => (string) Str::uuid(),
            'vehicle_registration_snapshot' => 'PL-'.strtoupper(Str::random(6)),
            'vehicle_type_snapshot' => 'van',
            'capacity_weight_kg_snapshot' => 1000,
            'capacity_volume_m3_snapshot' => 10,
            'assignment_number' => 'VA-'.strtoupper(Str::random(6)),
            'status' => 'loading',
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]);

        $task = LoadingTask::create([
            'company_id' => $this->company->id,
            'loading_session_id' => $session->id,
            'vehicle_assignment_id' => $assignment->id,
            'pool_entry_id' => (string) Str::uuid(),
            'product_id' => $product->id,
            'sku_snapshot' => (string) $product->sku,
            'name_snapshot' => (string) $product->name,
            'preparation_wave_id' => (string) Str::uuid(),
            'quantity_planned' => $quantityLoaded,
            'quantity_loaded' => $quantityLoaded,
            'quantity_short' => 0,
            'status' => 'loaded',
            'requires_refrigeration' => false,
            'loaded_at' => now(),
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]);

        return [$assignment, $task];
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Readers
    // ═════════════════════════════════════════════════════════════════════════

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

    private function custody(string $assignmentId, string $productId): VehicleInventoryItem
    {
        return VehicleInventoryItem::query()
            ->where('vehicle_assignment_id', $assignmentId)
            ->where('product_id', $productId)
            ->firstOrFail();
    }
}

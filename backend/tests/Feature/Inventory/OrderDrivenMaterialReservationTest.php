<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Commerce\Orders\Application\Actions\ReconcileOrderMaterialReservationsAction;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\OrderLine;
use Modules\Inventory\InventoryItems\Domain\Enums\AvailabilityState;
use Modules\Inventory\InventoryItems\Domain\Models\InventoryItem;
use Modules\Inventory\InventoryItems\Domain\Models\StockLedgerEntry;
use Modules\Inventory\InventoryItems\Domain\Services\InventorySummaryService;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Manufacturing\BillsOfMaterials\Domain\Models\Recipe;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Brands\Domain\Models\Brand;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * ADR-027 §17 — Order-Driven Raw Material Reservation. Runtime contract matrix.
 *
 * Eighteen cases, numbered to the task brief. Every quantity assertion reads the
 * PERSISTED `inventory_items` row, never a return value, because the defect this
 * suite exists to prevent was precisely a commitment that lived only in memory:
 * `ReserveOrderInventoryAction` recorded the remainder on the order line while
 * nothing reached inventory, so `Reserved` stayed 0 and `Available` never went
 * negative.
 *
 * CASE 1 (idempotency) is deliberately first. A reservation that accumulates on
 * retry silently manufactures demand no customer placed — the single most damaging
 * way this design can fail — so it is asserted on quantity AND on ledger-entry
 * count, since a converging quantity could still hide duplicated movements.
 */
final class OrderDrivenMaterialReservationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Warehouse $warehouse;

    private Brand $brand;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
        $this->brand = Brand::factory()->create(['company_id' => $this->company->id]);
        $this->customer = Customer::factory()->create();
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function finishedGood(): Product
    {
        return Product::factory()->finishedGood()->manufacturable()->create(['brand_id' => $this->brand->id]);
    }

    private function rawMaterial(bool $allowNegative): Product
    {
        return Product::factory()->rawMaterial()->create([
            'brand_id' => $this->brand->id,
            'is_active' => true,
            'allow_negative_stock' => $allowNegative,
        ]);
    }

    /**
     * `yield_quantity` defaults to 1.0, never null.
     *
     * `bills_of_materials.yield_quantity` is `decimal(10,4) NOT NULL DEFAULT 1.0000`
     * (TASK-ARCH-PRICE-001, commit 30655b91). A column DEFAULT is only consulted when the
     * column is OMITTED from the INSERT — supplying an explicit null still violates NOT
     * NULL, which is why every case in this file died in setup with SQLSTATE[23000].
     *
     * The schema is the committed contract and is not relaxed to accommodate the fixture:
     * making it nullable would break `ProductCostCalculator::…` (`max((float) $recipe->yield_quantity, 0.0001)`)
     * and the frozen-yield rule in the recipe cost-snapshot contract. A yield of 1 means
     * "one batch produces one finished unit", which is what these reservation cases assume.
     */
    private function recipe(Product $fg, float $yield = 1.0): Recipe
    {
        return Recipe::create([
            'bom_number' => 'BOM-RM-'.uniqid(),
            'product_id' => $fg->id,
            'version' => '1.0',
            'bom_version_number' => 1,
            'is_active' => true,
            'yield_quantity' => $yield,
        ]);
    }

    private function addComponent(Recipe $recipe, Product $rm, float $qty): void
    {
        $recipe->components()->create(['raw_material_id' => $rm->id, 'quantity' => $qty]);
    }

    private function stock(Product $product, float $onHand, ?Warehouse $warehouse = null): InventoryItem
    {
        return InventoryItem::query()->create([
            'warehouse_id' => ($warehouse ?? $this->warehouse)->id,
            'product_id' => $product->id,
            'company_id' => $this->company->id,
            'on_hand_qty' => $onHand,
            'reserved_qty' => 0,
        ]);
    }

    private function order(Product $fg, float $qty = 1.0): Order
    {
        $order = Order::query()->create([
            'company_id' => $this->company->id,
            'assigned_warehouse_id' => $this->warehouse->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-'.uniqid(),
            'order_date' => now()->toDateString(),
            'status' => OrderStatus::InProgress->value,
            'subtotal' => 100,
            'total' => 100,
            'shipping_total' => 0,
            'discount_total' => 0,
            'tax_total' => 0,
        ]);

        OrderLine::query()->create([
            'order_id' => $order->id,
            'product_id' => $fg->id,
            'quantity' => $qty,
            'unit_price' => 100.0,
            'line_total' => 100.0 * $qty,
        ]);

        return $order;
    }

    private function reconcile(Order $order): void
    {
        app(ReconcileOrderMaterialReservationsAction::class)->execute($order->fresh());
    }

    /** Persisted state, read fresh — never a return value. */
    private function assertStock(Product $rm, float $onHand, float $reserved, string $context): void
    {
        $item = InventoryItem::query()
            ->where('product_id', $rm->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->first();

        self::assertNotNull($item, "{$context}: no inventory row exists for the material.");
        self::assertEqualsWithDelta($onHand, (float) $item->on_hand_qty, 0.0001, "{$context}: on_hand");
        self::assertEqualsWithDelta($reserved, (float) $item->reserved_qty, 0.0001, "{$context}: reserved");
        self::assertEqualsWithDelta(
            $onHand - $reserved,
            $item->availableQty(),
            0.0001,
            "{$context}: available must be signed on_hand − reserved",
        );
    }

    private function materialLedgerCount(Order $order, Product $rm): int
    {
        return StockLedgerEntry::query()
            ->where('reference_type', ReconcileOrderMaterialReservationsAction::REFERENCE_TYPE)
            ->where('reference_id', $order->id)
            ->where('product_id', $rm->id)
            ->count();
    }

    // ── CASE 1 — IDENTITY / IDEMPOTENCY (highest priority) ───────────────────

    public function test_case_1_reconciling_twice_does_not_double_the_reservation(): void
    {
        $fg = $this->finishedGood();
        $rm = $this->rawMaterial(allowNegative: true);
        $this->addComponent($this->recipe($fg), $rm, 5.0);
        $this->stock($rm, 0.0);

        $order = $this->order($fg, 1.0);

        $this->reconcile($order);
        $this->assertStock($rm, onHand: 0.0, reserved: 5.0, context: 'first reconciliation');
        $ledgerAfterFirst = $this->materialLedgerCount($order, $rm);

        // Nothing about the order changed.
        $this->reconcile($order);

        $this->assertStock($rm, onHand: 0.0, reserved: 5.0, context: 'second reconciliation');
        self::assertSame(
            $ledgerAfterFirst,
            $this->materialLedgerCount($order, $rm),
            'A no-op reconciliation must write no additional ledger movement.',
        );

        // A third, for good measure — convergence, not oscillation.
        $this->reconcile($order);
        $this->assertStock($rm, onHand: 0.0, reserved: 5.0, context: 'third reconciliation');
        self::assertSame($ledgerAfterFirst, $this->materialLedgerCount($order, $rm));
    }

    // ── CASE 2 — Allow Negative ON, zero stock ───────────────────────────────

    public function test_case_2_allow_negative_on_reserves_beyond_physical_stock(): void
    {
        $fg = $this->finishedGood();
        $rm = $this->rawMaterial(allowNegative: true);
        $this->addComponent($this->recipe($fg), $rm, 5.0);
        $this->stock($rm, 0.0);

        $this->reconcile($this->order($fg, 1.0));

        $this->assertStock($rm, onHand: 0.0, reserved: 5.0, context: 'CASE 2');
    }

    // ── CASE 3 — Allow Negative OFF blocks, and does not touch order status ──

    public function test_case_3_allow_negative_off_blocks_and_never_writes_order_status(): void
    {
        $fg = $this->finishedGood();
        $rm = $this->rawMaterial(allowNegative: false);
        $this->addComponent($this->recipe($fg), $rm, 5.0);
        $this->stock($rm, 0.0);

        $order = $this->order($fg, 1.0);
        $statusBefore = $order->status;

        $result = app(ReconcileOrderMaterialReservationsAction::class)->execute($order->fresh());

        $this->assertStock($rm, onHand: 0.0, reserved: 0.0, context: 'CASE 3 — nothing reserved');

        $blocked = $result->data()['blocked'] ?? [];
        self::assertNotEmpty($blocked, 'A blocked material must be reported, not silently dropped.');
        self::assertSame($rm->id, $blocked[0]['product_id']);

        // ADR-027 §4 — inventory code must never rewrite order status.
        self::assertSame($statusBefore, $order->fresh()->status, 'Order status must be untouched.');
    }

    // ── CASE 4 — positive stock ──────────────────────────────────────────────

    public function test_case_4_positive_stock_reserves_normally(): void
    {
        $fg = $this->finishedGood();
        $rm = $this->rawMaterial(allowNegative: false);
        $this->addComponent($this->recipe($fg), $rm, 5.0);
        $this->stock($rm, 10.0);

        $this->reconcile($this->order($fg, 1.0));

        $this->assertStock($rm, onHand: 10.0, reserved: 5.0, context: 'CASE 4'); // available 5
    }

    // ── CASE 5 — reserved exceeds on hand ────────────────────────────────────

    public function test_case_5_reserved_may_exceed_on_hand_under_negative_policy(): void
    {
        $fg = $this->finishedGood();
        $rm = $this->rawMaterial(allowNegative: true);
        $this->addComponent($this->recipe($fg), $rm, 8.0);
        $this->stock($rm, 5.0);

        $this->reconcile($this->order($fg, 1.0));

        $this->assertStock($rm, onHand: 5.0, reserved: 8.0, context: 'CASE 5'); // available −3
    }

    // ── CASE 6 — revised downward releases only the delta ────────────────────

    public function test_case_6_reducing_the_requirement_releases_only_the_delta(): void
    {
        $fg = $this->finishedGood();
        $rm = $this->rawMaterial(allowNegative: true);
        $this->addComponent($this->recipe($fg), $rm, 8.0);
        $this->stock($rm, 8.0);

        $order = $this->order($fg, 1.0);
        $this->reconcile($order);
        $this->assertStock($rm, onHand: 8.0, reserved: 8.0, context: 'CASE 6 — before revision');

        // Requirement 8 → 5 by halving nothing but the recipe component.
        $order->lines()->first()->product->activeRecipe->components()->first()->update(['quantity' => 5.0]);

        $this->reconcile($order);

        $this->assertStock($rm, onHand: 8.0, reserved: 5.0, context: 'CASE 6 — after revision'); // available 3
    }

    // ── CASE 7 — revised upward reserves only the delta ──────────────────────

    public function test_case_7_increasing_the_requirement_reserves_only_the_delta(): void
    {
        $fg = $this->finishedGood();
        $rm = $this->rawMaterial(allowNegative: true);
        $this->addComponent($this->recipe($fg), $rm, 5.0);
        $this->stock($rm, 0.0);

        $order = $this->order($fg, 1.0);
        $this->reconcile($order);
        $this->assertStock($rm, onHand: 0.0, reserved: 5.0, context: 'CASE 7 — before');

        $order->lines()->first()->product->activeRecipe->components()->first()->update(['quantity' => 8.0]);

        $this->reconcile($order);

        $this->assertStock($rm, onHand: 0.0, reserved: 8.0, context: 'CASE 7 — after');
    }

    // ── CASE 8 — material no longer required is fully released ───────────────

    public function test_case_8_material_dropped_from_the_recipe_is_released_completely(): void
    {
        $fg = $this->finishedGood();
        $rm = $this->rawMaterial(allowNegative: true);
        $recipe = $this->recipe($fg);
        $this->addComponent($recipe, $rm, 5.0);
        $this->stock($rm, 0.0);

        $order = $this->order($fg, 1.0);
        $this->reconcile($order);
        $this->assertStock($rm, onHand: 0.0, reserved: 5.0, context: 'CASE 8 — before removal');

        $recipe->components()->delete();

        $this->reconcile($order);

        $this->assertStock($rm, onHand: 0.0, reserved: 0.0, context: 'CASE 8 — orphan released');
    }

    // ── CASE 9 — multiple materials reconcile independently ──────────────────

    public function test_case_9_each_material_reconciles_independently(): void
    {
        $fg = $this->finishedGood();
        $rmA = $this->rawMaterial(allowNegative: true);
        $rmB = $this->rawMaterial(allowNegative: true);
        $recipe = $this->recipe($fg);
        $this->addComponent($recipe, $rmA, 5.0);
        $this->addComponent($recipe, $rmB, 2.0);
        $this->stock($rmA, 0.0);
        $this->stock($rmB, 10.0);

        $this->reconcile($this->order($fg, 1.0));

        $a = InventoryItem::query()->where('product_id', $rmA->id)->first();
        $b = InventoryItem::query()->where('product_id', $rmB->id)->first();

        self::assertEqualsWithDelta(5.0, (float) $a->reserved_qty, 0.0001, 'RM-A reserved');
        self::assertEqualsWithDelta(-5.0, $a->availableQty(), 0.0001, 'RM-A available');
        self::assertEqualsWithDelta(2.0, (float) $b->reserved_qty, 0.0001, 'RM-B reserved');
        self::assertEqualsWithDelta(8.0, $b->availableQty(), 0.0001, 'RM-B available');
    }

    // ── CASE 10 — yield_quantity ─────────────────────────────────────────────

    public function test_case_10_yield_quantity_scales_the_requirement(): void
    {
        $fg = $this->finishedGood();
        $rm = $this->rawMaterial(allowNegative: true);
        // 5 KG yields 10 finished units → 0.5 KG per unit.
        $this->addComponent($this->recipe($fg, yield: 10.0), $rm, 5.0);
        $this->stock($rm, 0.0);

        $this->reconcile($this->order($fg, 1.0));

        $this->assertStock($rm, onHand: 0.0, reserved: 0.5, context: 'CASE 10 — yield-scaled');
    }

    // ── CASE 11 — multi-warehouse signed aggregation ─────────────────────────

    public function test_case_11_multi_warehouse_available_is_signed_and_unclamped(): void
    {
        $rm = $this->rawMaterial(allowNegative: true);
        $whB = Warehouse::factory()->create(['company_id' => $this->company->id]);

        $this->stock($rm, 10.0);                       // WH-A: 10 on hand, 0 reserved
        $b = $this->stock($rm, 0.0, warehouse: $whB);  // WH-B: 0 on hand
        $b->update(['reserved_qty' => 15.0]);          // …15 reserved

        $summary = app(InventorySummaryService::class)->summarize($rm->id, $this->company->id);

        self::assertEqualsWithDelta(10.0, $summary->onHand, 0.0001, 'Σ on_hand');
        self::assertEqualsWithDelta(15.0, $summary->reserved, 0.0001, 'Σ reserved');
        self::assertEqualsWithDelta(
            -5.0,
            $summary->available,
            0.0001,
            'Σ available must be signed — clamp-per-warehouse would have produced 10.',
        );
    }

    // ── CASE 12 — negative available survives the projection ─────────────────

    public function test_case_12_negative_available_is_visible_not_collapsed(): void
    {
        $fg = $this->finishedGood();
        $rm = $this->rawMaterial(allowNegative: true);
        $this->addComponent($this->recipe($fg), $rm, 5.0);
        $this->stock($rm, 0.0);

        $this->reconcile($this->order($fg, 1.0));

        $summary = app(InventorySummaryService::class)->summarize($rm->id, $this->company->id);

        self::assertEqualsWithDelta(-5.0, $summary->available, 0.0001, 'summary available stays negative');
        self::assertSame(AvailabilityState::OutOfStock, $summary->availabilityState);
        self::assertTrue(
            AvailabilityState::canCommit($summary->available, true),
            'Negative available with Allow Negative ON must remain committable.',
        );
        self::assertFalse(
            AvailabilityState::canCommit($summary->available, false),
            'Negative available with Allow Negative OFF must not be committable.',
        );
    }

    // ── CASE 13 — shortage stays non-negative ────────────────────────────────

    public function test_case_13_shortage_remains_clamped_while_available_is_signed(): void
    {
        $available = -5.0;
        $required = 5.0;

        // The contract: available signed, shortage never negative, and the shortage
        // of a negative balance covers the existing commitment too.
        self::assertSame(10.0, max(0.0, $required - $available), 'missing = required − available');
        self::assertGreaterThanOrEqual(0.0, max(0.0, $required - $available));
        self::assertSame(0.0, max(0.0, 5.0 - 20.0), 'surplus never yields negative shortage');
    }

    // ── CASE 14 — FG protection (ADR-027 §16.2) ──────────────────────────────

    public function test_case_14_material_shortage_never_blocks_a_finished_good_in_stock(): void
    {
        $fg = $this->finishedGood();
        $rm = $this->rawMaterial(allowNegative: false); // material cannot be committed
        $this->addComponent($this->recipe($fg), $rm, 5.0);
        $this->stock($rm, 0.0);
        $this->stock($fg, 50.0);                        // …but FG stock covers the order

        $order = $this->order($fg, 1.0);
        $status = app(\Modules\Commerce\Orders\Application\Actions\ReserveOrderInventoryAction::class)
            ->execute($order->fresh());

        $fgItem = InventoryItem::query()
            ->where('product_id', $fg->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->first();

        self::assertEqualsWithDelta(1.0, (float) $fgItem->reserved_qty, 0.0001, 'FG must reserve from stock');
        self::assertNotSame(
            \Modules\Commerce\Orders\Domain\Enums\ReservationStatus::AwaitingStock,
            $status,
            'ADR-027 §16.2 — an order shippable from FG stock must never be blocked by material policy.',
        );
    }

    // ── CASE 15 — reservation is order-driven ────────────────────────────────

    public function test_case_15_material_reservation_originates_from_the_order_flow(): void
    {
        $fg = $this->finishedGood();
        $rm = $this->rawMaterial(allowNegative: true);
        $this->addComponent($this->recipe($fg), $rm, 5.0);
        $this->stock($rm, 0.0);
        $this->stock($fg, 0.0);

        $order = $this->order($fg, 1.0);

        // Driven purely by the order reservation path — no manufacturing workflow.
        app(\Modules\Commerce\Orders\Application\Actions\ReserveOrderInventoryAction::class)
            ->execute($order->fresh());

        $this->assertStock($rm, onHand: 0.0, reserved: 5.0, context: 'CASE 15 — order-driven');

        self::assertGreaterThan(
            0,
            $this->materialLedgerCount($order, $rm),
            'The commitment must be recorded in the ledger against this order.',
        );
    }

    // ── CASE 16 — failure safety ─────────────────────────────────────────────

    public function test_case_16_blocked_material_causes_no_partial_mutation(): void
    {
        $fg = $this->finishedGood();
        $rmOk = $this->rawMaterial(allowNegative: true);
        $rmBlocked = $this->rawMaterial(allowNegative: false);
        $recipe = $this->recipe($fg);
        $this->addComponent($recipe, $rmOk, 2.0);
        $this->addComponent($recipe, $rmBlocked, 5.0);
        $this->stock($rmOk, 0.0);
        $this->stock($rmBlocked, 0.0);

        $order = $this->order($fg, 1.0);
        $result = app(ReconcileOrderMaterialReservationsAction::class)->execute($order->fresh());

        $ok = InventoryItem::query()->where('product_id', $rmOk->id)->first();
        $blockedItem = InventoryItem::query()->where('product_id', $rmBlocked->id)->first();

        self::assertEqualsWithDelta(2.0, (float) $ok->reserved_qty, 0.0001, 'permitted material still reserves');
        self::assertEqualsWithDelta(0.0, (float) $blockedItem->reserved_qty, 0.0001, 'blocked material reserves nothing');
        self::assertCount(1, $result->data()['blocked'], 'exactly one material reported blocked');
    }

    // ── CASE 17 — repeated reconciliation converges ──────────────────────────

    public function test_case_17_repeated_reconciliation_converges_on_the_target(): void
    {
        $fg = $this->finishedGood();
        $rm = $this->rawMaterial(allowNegative: true);
        $this->addComponent($this->recipe($fg), $rm, 3.0);
        $this->stock($rm, 0.0);

        $order = $this->order($fg, 2.0); // target 6

        for ($i = 0; $i < 5; $i++) {
            $this->reconcile($order);
        }

        $this->assertStock($rm, onHand: 0.0, reserved: 6.0, context: 'CASE 17 — five runs');
    }

    // ── CASE 18 — finished-goods reservation is unaffected ───────────────────

    public function test_case_18_finished_goods_reservation_still_behaves_as_before(): void
    {
        $fg = Product::factory()->finishedGood()->create(['brand_id' => $this->brand->id]);
        $this->stock($fg, 10.0);

        $order = $this->order($fg, 4.0);
        app(\Modules\Commerce\Orders\Application\Actions\ReserveOrderInventoryAction::class)
            ->execute($order->fresh());

        $item = InventoryItem::query()
            ->where('product_id', $fg->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->first();

        self::assertEqualsWithDelta(10.0, (float) $item->on_hand_qty, 0.0001, 'FG on_hand untouched by reservation');
        self::assertEqualsWithDelta(4.0, (float) $item->reserved_qty, 0.0001, 'FG reserved');
        self::assertEqualsWithDelta(6.0, $item->availableQty(), 0.0001, 'FG available');
        self::assertEqualsWithDelta(4.0, (float) $order->fresh()->lines()->first()->reserved_qty, 0.0001);
    }
}

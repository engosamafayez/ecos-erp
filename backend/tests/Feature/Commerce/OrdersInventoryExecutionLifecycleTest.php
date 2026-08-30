<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Enums\ReservationStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\OrderLine;
use Modules\Inventory\InventoryItems\Application\Actions\ReceiveStockAction;
use Modules\Inventory\InventoryItems\Application\Actions\ReleaseStockAction;
use Modules\Inventory\InventoryItems\Application\DTO\StockOperationDTO;
use Modules\Inventory\InventoryItems\Domain\Models\InventoryItem;
use Modules\Inventory\InventoryItems\Domain\Models\StockLedgerEntry;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Manufacturing\BillsOfMaterials\Domain\Models\Recipe;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Operations\Fulfillment\Application\FulfillmentEngine;
use Modules\Operations\Fulfillment\Application\Workflows\ProcessOrderWorkflow;
use Modules\Organization\Brands\Domain\Models\Brand;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-ORDERS-INVENTORY-EXECUTION-LIFECYCLE-REPAIR-001 — runtime contract matrix.
 *
 * The authoritative rule under test: A NEW ORDER DOES NOT MEAN AWAITING STOCK.
 * Availability answers one question — does stock block fulfilment — and it is not
 * the order-status authority.
 *
 * Every assertion reads PERSISTED state (`orders`, `inventory_items`,
 * `stock_ledger_entries`), never a workflow return value, because the defects this
 * suite exists to prevent were all cases of two surfaces disagreeing: an order that
 * claimed Reserved while a line held nothing, and a shortage that overwrote a
 * lifecycle status it had no authority over.
 *
 * Stock movements go through the real ReceiveStockAction / ReleaseStockAction so the
 * real domain events fire through the real bus. Nothing here dispatches an event by
 * hand — that would prove the listener works while leaving the wiring untested, which
 * is exactly how the release path stayed dead.
 */
final class OrdersInventoryExecutionLifecycleTest extends TestCase
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

    private function product(bool $manufacturable = false): Product
    {
        $factory = Product::factory()->finishedGood();

        if ($manufacturable) {
            $factory = $factory->manufacturable();
        }

        return $factory->create([
            'brand_id' => $this->brand->id,
            'allow_negative_stock' => false,
        ]);
    }

    private function rawMaterial(): Product
    {
        return Product::factory()->rawMaterial()->create([
            'brand_id' => $this->brand->id,
            'is_active' => true,
            'allow_negative_stock' => false,
        ]);
    }

    private function stock(Product $product, float $onHand, ?Warehouse $warehouse = null, ?Company $company = null): InventoryItem
    {
        return InventoryItem::query()->create([
            'warehouse_id' => ($warehouse ?? $this->warehouse)->id,
            'product_id' => $product->id,
            'company_id' => ($company ?? $this->company)->id,
            'on_hand_qty' => $onHand,
            'reserved_qty' => 0,
        ]);
    }

    /**
     * @param  list<array{product: Product, qty: float}>  $lines
     */
    private function order(
        array $lines,
        OrderStatus $status = OrderStatus::InProgress,
        ?Warehouse $warehouse = null,
        ?Company $company = null,
        ?string $deliveryDate = null,
    ): Order {
        $order = Order::query()->create([
            'company_id' => ($company ?? $this->company)->id,
            'assigned_warehouse_id' => ($warehouse ?? $this->warehouse)->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-'.uniqid(),
            'order_date' => now()->toDateString(),
            'requested_delivery_date' => $deliveryDate,
            'status' => $status->value,
            'subtotal' => 100,
            'total' => 100,
            'shipping_total' => 0,
            'discount_total' => 0,
            'tax_total' => 0,
        ]);

        foreach ($lines as $line) {
            OrderLine::query()->create([
                'order_id' => $order->id,
                'product_id' => $line['product']->id,
                'quantity' => $line['qty'],
                'unit_price' => 100.0,
                'line_total' => 100.0 * $line['qty'],
            ]);
        }

        return $order->fresh();
    }

    private function recipe(Product $fg, Product $rm, float $qtyPerUnit, bool $active = true, int $version = 1): Recipe
    {
        $recipe = Recipe::create([
            'bom_number' => 'BOM-'.uniqid(),
            'product_id' => $fg->id,
            'version' => (string) $version.'.0',
            'bom_version_number' => $version,
            'is_active' => $active,
        ]);

        $recipe->components()->create(['raw_material_id' => $rm->id, 'quantity' => $qtyPerUnit]);

        return $recipe;
    }

    private function process(Order $order, array $ctx = []): Order
    {
        app(FulfillmentEngine::class)->run(
            app(ProcessOrderWorkflow::class),
            $order->fresh(),
            $ctx,
            null,
        );

        return $order->fresh();
    }

    private function receive(Product $product, float $qty, ?Warehouse $warehouse = null, ?Company $company = null): void
    {
        app(ReceiveStockAction::class)->execute(new StockOperationDTO(
            warehouse_id: ($warehouse ?? $this->warehouse)->id,
            product_id: $product->id,
            company_id: ($company ?? $this->company)->id,
            quantity: $qty,
            reference_type: 'test_receipt',
            unit_cost: 10.0,
        ));
    }

    private function inventory(Product $product, ?Warehouse $warehouse = null): ?InventoryItem
    {
        return InventoryItem::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', ($warehouse ?? $this->warehouse)->id)
            ->first();
    }

    // ── A — New order, product available → NOT Awaiting Stock ────────────────

    public function test_a_new_order_with_available_product_does_not_become_awaiting_stock(): void
    {
        $product = $this->product();
        $this->stock($product, 10);

        $order = $this->process($this->order([['product' => $product, 'qty' => 2]]));

        self::assertNotSame(OrderStatus::AwaitingStock, $order->status, 'Available stock must never yield Awaiting Stock.');
        self::assertSame(OrderStatus::InProgress, $order->status);
        self::assertSame(ReservationStatus::Reserved, $order->reservation_status);
    }

    // ── B — New order, product unavailable → Awaiting Stock ──────────────────

    public function test_b_new_order_with_unavailable_product_becomes_awaiting_stock(): void
    {
        $product = $this->product();
        $this->stock($product, 0);

        $order = $this->process($this->order([['product' => $product, 'qty' => 2]]));

        self::assertSame(OrderStatus::AwaitingStock, $order->status);
        self::assertSame(ReservationStatus::AwaitingStock, $order->reservation_status);
        self::assertSame('Insufficient Inventory', $order->reservation_failure_reason);
    }

    // ── C — Available product + payment pending → stays Awaiting Payment ─────

    public function test_c_available_stock_does_not_advance_an_awaiting_payment_order(): void
    {
        $product = $this->product();
        $this->stock($product, 10);

        $order = $this->process($this->order([['product' => $product, 'qty' => 2]], OrderStatus::AwaitingPayment));

        self::assertSame(OrderStatus::AwaitingPayment, $order->status, 'Having stock is not a reason to declare an unpaid order In Progress.');
        self::assertSame(ReservationStatus::Reserved, $order->reservation_status);
    }

    public function test_c2_shortage_does_not_overwrite_an_awaiting_payment_order(): void
    {
        $product = $this->product();
        $this->stock($product, 0);

        $order = $this->process($this->order([['product' => $product, 'qty' => 2]], OrderStatus::AwaitingPayment));

        self::assertSame(OrderStatus::AwaitingPayment, $order->status, 'A payment block outranks an inventory one.');
        self::assertSame(ReservationStatus::AwaitingStock, $order->reservation_status, 'The shortage must still be recorded on the inventory surface.');
    }

    public function test_c3_shortage_does_not_unconfirm_a_confirmed_order(): void
    {
        $product = $this->product();
        $this->stock($product, 0);

        $order = $this->process($this->order([['product' => $product, 'qty' => 2]], OrderStatus::Confirmed));

        self::assertSame(OrderStatus::Confirmed, $order->status, 'Failing to reserve must never walk a Confirmed order backwards.');
        self::assertSame(ReservationStatus::AwaitingStock, $order->reservation_status);
    }

    // ── D/E/F — Scheduled ────────────────────────────────────────────────────

    public function test_d_scheduled_order_stays_scheduled_before_activation_point(): void
    {
        $product = $this->product();
        $this->stock($product, 10);

        $order = $this->order(
            [['product' => $product, 'qty' => 1]],
            OrderStatus::Scheduled,
            deliveryDate: now()->addDays(5)->toDateString(),
        );

        $this->artisan('orders:activate-scheduled')->assertSuccessful();

        self::assertSame(OrderStatus::Scheduled, $order->fresh()->status, 'A Scheduled order must not activate five days early.');
    }

    public function test_e_scheduled_order_activates_automatically_one_day_before(): void
    {
        $product = $this->product();
        $this->stock($product, 10);

        $order = $this->order(
            [['product' => $product, 'qty' => 1]],
            OrderStatus::Scheduled,
            deliveryDate: now()->addDay()->toDateString(),
        );

        $this->artisan('orders:activate-scheduled')->assertSuccessful();

        $order = $order->fresh();
        self::assertSame(OrderStatus::InProgress, $order->status, 'D-1 must activate the order with no manual action.');
        self::assertSame(ReservationStatus::Reserved, $order->reservation_status);
    }

    public function test_f_scheduled_order_activating_without_stock_becomes_awaiting_stock(): void
    {
        $product = $this->product();
        $this->stock($product, 0);

        $order = $this->order(
            [['product' => $product, 'qty' => 1]],
            OrderStatus::Scheduled,
            deliveryDate: now()->addDay()->toDateString(),
        );

        $this->artisan('orders:activate-scheduled')->assertSuccessful();

        self::assertSame(OrderStatus::AwaitingStock, $order->fresh()->status);
    }

    public function test_f2_unavailability_never_moves_a_scheduled_order_early(): void
    {
        $product = $this->product();
        $this->stock($product, 0);

        $order = $this->order(
            [['product' => $product, 'qty' => 1]],
            OrderStatus::Scheduled,
            deliveryDate: now()->addDays(5)->toDateString(),
        );

        $this->artisan('orders:activate-scheduled')->assertSuccessful();

        self::assertSame(OrderStatus::Scheduled, $order->fresh()->status, 'Scheduling and availability are independent axes.');
    }

    // ── G/H — Automatic re-evaluation on stock arrival ───────────────────────

    public function test_g_awaiting_stock_order_is_re_evaluated_automatically_when_stock_arrives(): void
    {
        $product = $this->product();
        $this->stock($product, 0);

        $order = $this->process($this->order([['product' => $product, 'qty' => 2]]));
        self::assertSame(OrderStatus::AwaitingStock, $order->status);

        // No manual status change, no re-open, no button — just stock arriving.
        $this->receive($product, 5);

        $order = $order->fresh();
        self::assertSame(OrderStatus::InProgress, $order->status);
        self::assertSame(ReservationStatus::Reserved, $order->reservation_status);
        self::assertEqualsWithDelta(2.0, (float) $this->inventory($product)->reserved_qty, 0.0001);
    }

    public function test_h_awaiting_stock_order_remains_awaiting_when_arrival_is_insufficient(): void
    {
        $product = $this->product();
        $this->stock($product, 0);

        $order = $this->process($this->order([['product' => $product, 'qty' => 10]]));
        self::assertSame(OrderStatus::AwaitingStock, $order->status);

        $this->receive($product, 3);

        $order = $order->fresh();
        self::assertSame(ReservationStatus::PartialReserved, $order->reservation_status, 'Three of ten is a partial, not a completion.');
        self::assertEqualsWithDelta(3.0, (float) $this->inventory($product)->reserved_qty, 0.0001);
    }

    /**
     * The case that mattered most in production: every physical unit already
     * reserved, so `available` is zero and only a RELEASE can ever free the order.
     * Nothing listened for that event, and those orders waited forever.
     */
    public function test_g2_release_by_another_order_re_evaluates_a_blocked_order(): void
    {
        $product = $this->product();
        $this->stock($product, 5);

        $first = $this->process($this->order([['product' => $product, 'qty' => 5]]));
        self::assertSame(ReservationStatus::Reserved, $first->reservation_status);

        $blocked = $this->process($this->order([['product' => $product, 'qty' => 2]]));
        self::assertSame(OrderStatus::AwaitingStock, $blocked->status, 'available = on_hand - reserved = 0');

        // The first order releases its hold — on_hand does not change, availability does.
        app(ReleaseStockAction::class)->execute(new StockOperationDTO(
            warehouse_id: $this->warehouse->id,
            product_id: $product->id,
            company_id: $this->company->id,
            quantity: 5.0,
            reference_type: 'sales_order',
            reference_id: $first->id,
        ));

        $blocked = $blocked->fresh();
        self::assertSame(OrderStatus::InProgress, $blocked->status, 'A freed reservation must unblock the waiting order automatically.');
        self::assertSame(ReservationStatus::Reserved, $blocked->reservation_status);
    }

    // ── I/J/K/Q — Recipe resolution and raw-material reservation ─────────────

    public function test_ijk_stock_arrival_resolves_active_recipe_and_reserves_raw_materials(): void
    {
        $fg = $this->product(manufacturable: true);
        $rm = $this->rawMaterial();

        $this->recipe($fg, $rm, qtyPerUnit: 3.0);
        $this->stock($fg, 0);
        $this->stock($rm, 100);

        $order = $this->process($this->order([['product' => $fg, 'qty' => 4]]));

        // FG stock arrives; everything downstream must follow with no manual step.
        $this->receive($fg, 4);

        $order = $order->fresh();
        self::assertSame(OrderStatus::InProgress, $order->status);
        self::assertSame(ReservationStatus::Reserved, $order->reservation_status);

        // J — required raw material = 4 units x 3 per unit.
        // K/L — the SAME reservation is visible on the warehouse inventory row.
        self::assertEqualsWithDelta(12.0, (float) $this->inventory($rm)->reserved_qty, 0.0001, 'Raw material must be reserved against the real inventory row.');
    }

    public function test_q_only_the_active_recipe_drives_the_material_requirement(): void
    {
        $fg = $this->product(manufacturable: true);
        $rm = $this->rawMaterial();

        // An older, inactive version demanding a wildly different quantity. If recipe
        // resolution ever degrades to "first BOM" or "any active row", this figure leaks.
        $this->recipe($fg, $rm, qtyPerUnit: 50.0, active: false, version: 1);
        $this->recipe($fg, $rm, qtyPerUnit: 2.0, active: true, version: 2);

        $this->stock($fg, 10);
        $this->stock($rm, 500);

        $this->process($this->order([['product' => $fg, 'qty' => 3]]));

        self::assertEqualsWithDelta(6.0, (float) $this->inventory($rm)->reserved_qty, 0.0001, 'Only the ACTIVE recipe may contribute.');
    }

    // ── L/O — Warehouse scope ────────────────────────────────────────────────

    public function test_lo_reservation_lands_in_the_orders_assigned_warehouse_only(): void
    {
        $product = $this->product();
        $other = Warehouse::factory()->create(['company_id' => $this->company->id]);

        $this->stock($product, 10);
        $this->stock($product, 10, warehouse: $other);

        $order = $this->process($this->order([['product' => $product, 'qty' => 4]]));

        self::assertSame($this->warehouse->id, $order->assigned_warehouse_id);
        self::assertEqualsWithDelta(4.0, (float) $this->inventory($product)->reserved_qty, 0.0001);
        self::assertEqualsWithDelta(0.0, (float) $this->inventory($product, $other)->reserved_qty, 0.0001, 'No other warehouse may be consumed.');
    }

    // ── M/N — Idempotency ────────────────────────────────────────────────────

    public function test_m_duplicate_stock_event_creates_no_duplicate_reservation(): void
    {
        $product = $this->product();
        $this->stock($product, 0);

        $order = $this->process($this->order([['product' => $product, 'qty' => 2]]));

        $this->receive($product, 5);
        $reservedAfterFirst = (float) $this->inventory($product)->reserved_qty;

        // A second, identical arrival must not reserve the same demand twice.
        $this->receive($product, 5);

        self::assertEqualsWithDelta($reservedAfterFirst, (float) $this->inventory($product)->reserved_qty, 0.0001);
        self::assertSame(ReservationStatus::Reserved, $order->fresh()->reservation_status);
    }

    public function test_n_repeated_re_evaluation_is_idempotent_on_quantity_and_ledger(): void
    {
        $fg = $this->product(manufacturable: true);
        $rm = $this->rawMaterial();
        $this->recipe($fg, $rm, qtyPerUnit: 2.0);
        $this->stock($fg, 10);
        $this->stock($rm, 100);

        $order = $this->order([['product' => $fg, 'qty' => 3]]);

        $this->process($order);
        $reserved = (float) $this->inventory($rm)->reserved_qty;
        $ledgerCount = StockLedgerEntry::query()->where('reference_id', $order->id)->count();

        $this->process($order);
        $this->process($order);

        self::assertEqualsWithDelta($reserved, (float) $this->inventory($rm)->reserved_qty, 0.0001, 'Reservation quantity must remain unchanged.');
        self::assertSame($ledgerCount, StockLedgerEntry::query()->where('reference_id', $order->id)->count(), 'A converging quantity can still hide duplicated ledger movements.');
    }

    // ── P — Tenant isolation ─────────────────────────────────────────────────

    public function test_p_company_a_order_is_never_satisfied_by_company_b_inventory(): void
    {
        $otherCompany = Company::factory()->create();
        $otherWarehouse = Warehouse::factory()->create(['company_id' => $otherCompany->id]);

        $product = $this->product();
        $this->stock($product, 0);
        // Plenty of stock — but it belongs to another company, in another warehouse.
        $this->stock($product, 100, warehouse: $otherWarehouse, company: $otherCompany);

        $order = $this->process($this->order([['product' => $product, 'qty' => 5]]));

        self::assertSame(OrderStatus::AwaitingStock, $order->status, 'Cross-tenant inventory must never satisfy an order.');
        self::assertEqualsWithDelta(0.0, (float) $this->inventory($product, $otherWarehouse)->reserved_qty, 0.0001);
    }

    public function test_p2_stock_arriving_for_another_company_does_not_re_evaluate_this_order(): void
    {
        $otherCompany = Company::factory()->create();
        $otherWarehouse = Warehouse::factory()->create(['company_id' => $otherCompany->id]);

        $product = $this->product();
        $this->stock($product, 0);

        $order = $this->process($this->order([['product' => $product, 'qty' => 5]]));
        self::assertSame(OrderStatus::AwaitingStock, $order->status);

        $this->receive($product, 100, warehouse: $otherWarehouse, company: $otherCompany);

        self::assertSame(OrderStatus::AwaitingStock, $order->fresh()->status, 'Company B stock must not unblock a Company A order.');
    }

    // ── Multi-line contract (ADR-027 §8) ─────────────────────────────────────

    public function test_multi_line_with_one_unavailable_line_is_partial_not_reserved(): void
    {
        $available = $this->product();
        $unavailable = $this->product();

        $this->stock($available, 10);
        $this->stock($unavailable, 0);

        $order = $this->process($this->order([
            ['product' => $available, 'qty' => 2],
            ['product' => $unavailable, 'qty' => 2],
        ]));

        self::assertSame(
            ReservationStatus::PartialReserved,
            $order->reservation_status,
            'Reserved requires EVERY line satisfied; one wholly blocked line is a partial.',
        );
        self::assertNotSame(OrderStatus::AwaitingStock, $order->status, 'Reservation is non-blocking — a partial still progresses.');
    }

    public function test_multi_line_with_every_line_unavailable_is_awaiting_stock(): void
    {
        $a = $this->product();
        $b = $this->product();
        $this->stock($a, 0);
        $this->stock($b, 0);

        $order = $this->process($this->order([
            ['product' => $a, 'qty' => 1],
            ['product' => $b, 'qty' => 1],
        ]));

        self::assertSame(ReservationStatus::AwaitingStock, $order->reservation_status);
        self::assertSame(OrderStatus::AwaitingStock, $order->status);
    }

    // ── Awaiting Stock is not a catch-all (§2) ───────────────────────────────

    public function test_order_with_no_reservable_line_is_not_awaiting_stock(): void
    {
        $order = $this->process($this->order([]));

        self::assertNotSame(
            ReservationStatus::AwaitingStock,
            $order->reservation_status,
            'awaiting_stock asserts an inventory block; nothing is blocked when nothing was requested.',
        );
        self::assertNull($order->reservation_failure_reason);
        self::assertSame(OrderStatus::InProgress, $order->status);
    }
}

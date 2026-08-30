<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Modules\Commerce\Orders\Application\Actions\ReconcileOrderMaterialReservationsAction;
use Modules\Commerce\Orders\Application\Listeners\RetryReservationOnStockAvailableListener;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Enums\ReservationStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Inventory\DomainEvents\Events\InventoryStockReceived;
use Modules\Inventory\InventoryItems\Domain\Models\InventoryItem;
use Modules\Inventory\Products\Domain\Enums\InventoryClass;
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
 * TASK-ORDERS-AVAILABILITY-LIFECYCLE-CLOSURE-001 — the A–Q contract matrix.
 *
 * Certifies the EXISTING repair (the uncommitted Orders/Fulfillment changeset).
 * No production behaviour is altered by this file; it only asserts it.
 *
 * The contract under test:
 *
 *   available            → canonical lifecycle state, NOT Awaiting Stock
 *   unavailable          → Awaiting Stock
 *   available + unpaid   → Awaiting Payment (payment outranks availability)
 *   scheduled            → holds until D-1, then In Progress
 *   stock arrives        → automatic re-evaluation → recipe → RM reservation
 *   warehouse missing    → reservation execution postponed, lifecycle UNTOUCHED
 *
 * `DatabaseTransactions`, not `RefreshDatabase`: the shared `ecos_dev_test` schema is
 * contended and `migrate:fresh` is what makes concurrent agents destroy each other's
 * runs. This suite needs a migrated schema, not a rebuilt one — matching the existing
 * OrderManufacturingIntegrationTest.
 */
final class OrderAvailabilityLifecycleContractTest extends TestCase
{
    use DatabaseTransactions;

    private Company $company;

    private Warehouse $warehouse;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
        $this->customer = Customer::factory()->create();
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function finishedGood(bool $canManufacture = false): Product
    {
        return Product::factory()->finishedGood()->create(['can_manufacture' => $canManufacture]);
    }

    private function rawMaterial(): Product
    {
        return Product::factory()->rawMaterial()->create();
    }

    /**
     * A finished good whose OWNING COMPANY is this test's company.
     *
     * `finishedGood()` alone builds its own Brand, therefore its own Company, and
     * ManufacturingAvailabilityService §16.4 resolves component availability through
     * `Product -> Brand -> Company` and FAILS CLOSED on a mismatch. A recipe gate
     * exercised on a foreign-company product would report `outofstock` no matter how
     * much material exists, so any test that depends on the gate's ANSWER (rather
     * than merely on it being closed) must own the product properly.
     */
    private function ownCompanyFinishedGood(bool $canManufacture = false): Product
    {
        $brand = Brand::factory()->create(['company_id' => $this->company->id]);

        return Product::factory()->finishedGood()->create([
            'can_manufacture' => $canManufacture,
            'brand_id' => $brand->id,
            'company_id' => $this->company->id,
        ]);
    }

    private function stock(Product $product, float $onHand, ?Warehouse $warehouse = null): InventoryItem
    {
        return InventoryItem::query()->create([
            'warehouse_id' => ($warehouse ?? $this->warehouse)->id,
            'product_id' => $product->id,
            'company_id' => $this->company->id,
            'on_hand_qty' => $onHand,
            'reserved_qty' => 0.0,
        ]);
    }

    /** @param list<array{product: Product, quantity: float}> $lines */
    private function order(
        array $lines,
        OrderStatus $status = OrderStatus::InProgress,
        ?Warehouse $warehouse = null,
        bool $assignWarehouse = true,
        ?string $deliveryDate = null,
    ): Order {
        $order = Order::create([
            'customer_id' => $this->customer->id,
            'company_id' => $this->company->id,
            'assigned_warehouse_id' => $assignWarehouse ? ($warehouse ?? $this->warehouse)->id : null,
            'order_number' => 'OAL-'.Str::random(8),
            'order_date' => now()->toDateString(),
            'requested_delivery_date' => $deliveryDate,
            'status' => $status->value,
            'subtotal' => 0,
            'total' => 0,
        ]);

        foreach ($lines as $line) {
            $order->lines()->create([
                'product_id' => $line['product']->id,
                'quantity' => $line['quantity'],
                'unit_price' => 10.0,
                'line_total' => 10.0 * $line['quantity'],
            ]);
        }

        return $order->refresh();
    }

    /**
     * Drive the order through the CANONICAL entry point.
     *
     * Not `ProcessOrderWorkflow::execute()` directly: `Order::booted()` enforces P9 —
     * any `status` write outside `FulfillmentEngine::run()` throws
     * UnauthorizedOrderStatusWriteException. Calling the workflow directly bypasses
     * the engine and trips that guard, so the engine is also what production uses.
     */
    private function process(Order $order): Order
    {
        app(FulfillmentEngine::class)->run(app(ProcessOrderWorkflow::class), $order);

        return $order->refresh();
    }

    private function recipeFor(Product $output, Product $material, float $qtyPerUnit): Recipe
    {
        $recipe = Recipe::create([
            'bom_number' => 'BOM-'.Str::random(6),
            'product_id' => $output->id,
            'version' => '1.0',
            'bom_version_number' => 1,
            'is_active' => true,
        ]);

        $recipe->components()->create([
            'raw_material_id' => $material->id,
            'quantity' => $qtyPerUnit,
        ]);

        return $recipe;
    }

    private function reservedQty(Product $product, ?Warehouse $warehouse = null): float
    {
        return (float) InventoryItem::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', ($warehouse ?? $this->warehouse)->id)
            ->value('reserved_qty');
    }

    private function fireStockReceived(
        Product $product,
        InventoryItem $item,
        float $qty,
        InventoryClass $class = InventoryClass::FinishedGood,
    ): void {
        app(RetryReservationOnStockAvailableListener::class)->handleStockReceived(
            new InventoryStockReceived(
                inventoryItemId: $item->id,
                warehouseId: $this->warehouse->id,
                productId: $product->id,
                companyId: $this->company->id,
                quantityReceived: $qty,
                onHandBefore: 0.0,
                onHandAfter: $qty,
                inventoryClass: $class,
                unitCost: 10.0,
            ),
        );
    }

    // ── A. Available product → canonical normal state ─────────────────────────

    public function test_a_available_product_reaches_the_canonical_state_not_awaiting_stock(): void
    {
        $product = $this->finishedGood();
        $this->stock($product, 50.0);

        $order = $this->process($this->order([['product' => $product, 'quantity' => 10.0]]));

        self::assertSame(OrderStatus::InProgress, $order->status);
        self::assertNotSame(OrderStatus::AwaitingStock, $order->status);
        self::assertSame(ReservationStatus::Reserved, $order->reservation_status);
        self::assertSame(10.0, $this->reservedQty($product));
    }

    // ── B. Unavailable product → Awaiting Stock ───────────────────────────────

    public function test_b_unavailable_product_goes_to_awaiting_stock(): void
    {
        $product = $this->finishedGood();
        $this->stock($product, 0.0);

        $order = $this->process($this->order([['product' => $product, 'quantity' => 10.0]]));

        self::assertSame(OrderStatus::AwaitingStock, $order->status);
        self::assertSame(ReservationStatus::AwaitingStock, $order->reservation_status);
    }

    // ── C. Available + unpaid → Awaiting Payment ──────────────────────────────

    public function test_c_available_but_unpaid_stays_awaiting_payment(): void
    {
        $product = $this->finishedGood();
        $this->stock($product, 50.0);

        $order = $this->process($this->order(
            [['product' => $product, 'quantity' => 10.0]],
            status: OrderStatus::AwaitingPayment,
        ));

        // Stock is plentiful, but a payment block outranks an availability answer.
        self::assertSame(OrderStatus::AwaitingPayment, $order->status);
        self::assertNotSame(OrderStatus::InProgress, $order->status);
    }

    public function test_c2_unpaid_and_unavailable_still_reports_payment_not_stock(): void
    {
        $product = $this->finishedGood();
        $this->stock($product, 0.0);

        $order = $this->process($this->order(
            [['product' => $product, 'quantity' => 10.0]],
            status: OrderStatus::AwaitingPayment,
        ));

        // The shortage is recorded on the inventory surface; the lifecycle keeps the
        // blocker the operator must actually clear.
        self::assertSame(OrderStatus::AwaitingPayment, $order->status);
        self::assertSame(ReservationStatus::AwaitingStock, $order->reservation_status);
    }

    // ── D / E. Scheduled holds until D-1, then activates ──────────────────────

    public function test_d_scheduled_order_before_d1_remains_scheduled(): void
    {
        $product = $this->finishedGood();
        $this->stock($product, 50.0);

        $order = $this->order(
            [['product' => $product, 'quantity' => 5.0]],
            status: OrderStatus::Scheduled,
            deliveryDate: now()->addDays(5)->toDateString(),
        );

        $this->artisan('orders:activate-scheduled')->assertSuccessful();

        self::assertSame(OrderStatus::Scheduled, $order->refresh()->status);
    }

    public function test_e_scheduled_order_at_d1_moves_to_in_progress(): void
    {
        $product = $this->finishedGood();
        $this->stock($product, 50.0);

        $order = $this->order(
            [['product' => $product, 'quantity' => 5.0]],
            status: OrderStatus::Scheduled,
            deliveryDate: now()->addDay()->toDateString(),
        );

        $this->artisan('orders:activate-scheduled')->assertSuccessful();

        $order->refresh();
        self::assertSame(OrderStatus::InProgress, $order->status);
        self::assertSame(ReservationStatus::Reserved, $order->reservation_status);
    }

    // ── F. Scheduled + unavailable, after activation → Awaiting Stock ─────────

    public function test_f_scheduled_and_unavailable_becomes_awaiting_stock_only_after_activation(): void
    {
        $product = $this->finishedGood();
        $this->stock($product, 0.0);

        $order = $this->order(
            [['product' => $product, 'quantity' => 5.0]],
            status: OrderStatus::Scheduled,
            deliveryDate: now()->addDay()->toDateString(),
        );

        // ADR-042 §7 — the D-1 trigger moves the order into `in_progress`; §6.1 then
        // applies normally, so a finished-good shortage writes `awaiting_stock`. The
        // order must NOT remain Scheduled: its activation trigger has fired and will
        // not fire again, so staying Scheduled would strand it permanently.
        $this->artisan('orders:activate-scheduled')->assertSuccessful();

        $order->refresh();
        self::assertSame(OrderStatus::AwaitingStock, $order->status);
        self::assertSame(ReservationStatus::AwaitingStock, $order->reservation_status);
    }

    /**
     * The other half of clause F, and the reason activation is ordered the way it is:
     * a shortage must never eject a FUTURE-dated order from Scheduled.
     *
     * ADR-042 §5 rule 1 — "`scheduled` stays `scheduled` until its existing scheduling
     * trigger fires." This is why the repair activates before reserving rather than
     * adding Scheduled to yieldsToStockBlock(), which would break exactly this case.
     */
    public function test_f2_a_future_dated_scheduled_order_is_not_ejected_by_a_shortage(): void
    {
        $product = $this->finishedGood();
        $this->stock($product, 0.0);

        $order = $this->order(
            [['product' => $product, 'quantity' => 5.0]],
            status: OrderStatus::Scheduled,
            deliveryDate: now()->addDays(5)->toDateString(),
        );

        $this->artisan('orders:activate-scheduled')->assertSuccessful();

        self::assertSame(OrderStatus::Scheduled, $order->refresh()->status);
    }

    // ── G / H. Automatic stock recovery ───────────────────────────────────────

    public function test_g_stock_arrival_recovers_an_awaiting_stock_order_automatically(): void
    {
        $product = $this->finishedGood();
        $item = $this->stock($product, 0.0);

        $order = $this->process($this->order([['product' => $product, 'quantity' => 10.0]]));
        self::assertSame(OrderStatus::AwaitingStock, $order->status);

        // Stock arrives. No refresh, no button, no polling.
        $item->update(['on_hand_qty' => 40.0]);
        $this->fireStockReceived($product, $item, 40.0);

        $order->refresh();
        self::assertSame(OrderStatus::InProgress, $order->status);
        self::assertSame(ReservationStatus::Reserved, $order->reservation_status);
        self::assertSame(10.0, $this->reservedQty($product));
    }

    public function test_h_insufficient_arrival_leaves_the_order_awaiting_stock(): void
    {
        $product = $this->finishedGood();
        $item = $this->stock($product, 0.0);

        $order = $this->process($this->order([['product' => $product, 'quantity' => 10.0]]));
        self::assertSame(OrderStatus::AwaitingStock, $order->status);

        // Re-evaluation fires but availability is still zero — nothing usable arrived
        // (a receipt into another warehouse, or one immediately consumed elsewhere).
        $this->fireStockReceived($product, $item, 0.0);

        $order->refresh();
        self::assertSame(OrderStatus::AwaitingStock, $order->status);
        self::assertSame(ReservationStatus::AwaitingStock, $order->reservation_status);
        self::assertSame(0.0, $this->reservedQty($product));
    }

    /**
     * H, second half — a PARTIAL arrival is NOT "still awaiting stock".
     *
     * Clause H reads "still insufficient → remains Awaiting Stock", but 4 arriving
     * against a demand of 10 is not the same as nothing arriving. ADR-027 §8 defines
     * `awaiting_stock` as "no reservable line could be satisfied at all"; a line
     * satisfied in part is `partial_reserved`, which is an ACTIVE reservation and
     * therefore advances the lifecycle.
     *
     * Recorded explicitly because the intuitive reading of H would make this a bug,
     * and it is not one — it is the documented partial-reservation contract.
     */
    public function test_h2_partial_arrival_is_partial_reserved_not_awaiting_stock(): void
    {
        $product = $this->finishedGood();
        $item = $this->stock($product, 0.0);

        $order = $this->process($this->order([['product' => $product, 'quantity' => 10.0]]));
        self::assertSame(OrderStatus::AwaitingStock, $order->status);

        $item->update(['on_hand_qty' => 4.0]);
        $this->fireStockReceived($product, $item, 4.0);

        $order->refresh();
        self::assertSame(ReservationStatus::PartialReserved, $order->reservation_status);
        self::assertSame(4.0, $this->reservedQty($product), 'what was available is held, not discarded');
        self::assertSame(OrderStatus::InProgress, $order->status);
    }

    public function test_g2_the_recovery_listener_is_actually_subscribed(): void
    {
        // The logic above is proven by direct invocation; this proves the wiring, so a
        // dropped subscription cannot pass the suite while breaking recovery in production.
        $listeners = app('events')->getListeners(InventoryStockReceived::class);

        self::assertNotEmpty($listeners, 'InventoryStockReceived has no subscriber.');
    }

    // ── I / J / K / L. Recipe resolution → RM requirement → real reservation ──

    public function test_i_j_k_l_recipe_resolves_and_raw_materials_are_really_reserved(): void
    {
        $finishedGood = $this->finishedGood(canManufacture: true);
        $material = $this->rawMaterial();

        // I — an ACTIVE recipe: 3 units of material per 1 unit of finished good.
        $this->recipeFor($finishedGood, $material, 3.0);

        $this->stock($finishedGood, 100.0);
        $this->stock($material, 100.0);

        $order = $this->process($this->order([['product' => $finishedGood, 'quantity' => 7.0]]));

        // L — the order reports Reserved.
        self::assertSame(ReservationStatus::Reserved, $order->reservation_status);

        // J + K — requirement is 7 x 3 = 21, and it is a REAL reservation on the
        // warehouse row, not a UI-only figure. Same row the warehouse screen reads.
        self::assertSame(21.0, $this->reservedQty($material), 'J/K: 7 x 3 raw material reserved');
        self::assertSame(7.0, $this->reservedQty($finishedGood), 'FG reservation is unaffected');
    }

    // ── RM-1..RM-4. Recovery when the BLOCKING stock is a RAW MATERIAL ────────
    //
    // TASK-ORDERS-AVAILABILITY-LIFECYCLE-COMPLETION-001, clauses C + D + M.
    //
    // For a recipe-backed finished good the ADR-027 §16 gate is what withholds the
    // commitment, and it closes on RAW MATERIAL availability — not on the finished
    // good's own stock. The order that results is Awaiting Stock exactly as clause B
    // requires, but the stock that unblocks it never appears on the order's own lines.

    /**
     * The order goes to Awaiting Stock because its RECIPE cannot be executed. This is
     * the precondition for RM-2 and is asserted separately so a failure there cannot be
     * mistaken for a broken fixture.
     */
    public function test_rm1_an_unexecutable_recipe_produces_awaiting_stock(): void
    {
        $finishedGood = $this->ownCompanyFinishedGood(canManufacture: true);
        $material = $this->rawMaterial();
        $this->recipeFor($finishedGood, $material, 2.0);

        $this->stock($finishedGood, 0.0);   // nothing to ship from stock
        $this->stock($material, 0.0);       // and nothing to manufacture from

        $order = $this->process($this->order([['product' => $finishedGood, 'quantity' => 5.0]]));

        self::assertSame(OrderStatus::AwaitingStock, $order->status);
        self::assertSame(ReservationStatus::AwaitingStock, $order->reservation_status);
    }

    /**
     * Clause C — the order must recover AUTOMATICALLY when the raw material arrives.
     *
     * The candidate query matched on the order's own line products only, so a raw
     * material receipt selected nothing and the order waited for ever on an event that,
     * for it, never came. No operator action must be required (clause C), and no
     * polling from the frontend (clause M).
     */
    public function test_rm2_raw_material_arrival_recovers_the_order_automatically(): void
    {
        $finishedGood = $this->ownCompanyFinishedGood(canManufacture: true);
        $material = $this->rawMaterial();
        $this->recipeFor($finishedGood, $material, 2.0);

        $this->stock($finishedGood, 0.0);
        $materialItem = $this->stock($material, 0.0);

        $order = $this->process($this->order([['product' => $finishedGood, 'quantity' => 5.0]]));
        self::assertSame(OrderStatus::AwaitingStock, $order->status);

        // The RAW MATERIAL arrives. The order's own line product is untouched.
        $materialItem->update(['on_hand_qty' => 100.0]);
        $this->fireStockReceived($material, $materialItem, 100.0, InventoryClass::RawMaterial);

        $order->refresh();
        self::assertSame(OrderStatus::InProgress, $order->status, 'clause C: automatic recovery');
        self::assertSame(ReservationStatus::Reserved, $order->reservation_status);
        // Clause D — and the material requirement is really reserved: 5 x 2.
        self::assertSame(10.0, $this->reservedQty($material));
    }

    /**
     * Clause N — the same raw-material event replayed must converge, not accumulate.
     */
    public function test_rm3_replayed_raw_material_event_does_not_duplicate_the_reservation(): void
    {
        $finishedGood = $this->ownCompanyFinishedGood(canManufacture: true);
        $material = $this->rawMaterial();
        $this->recipeFor($finishedGood, $material, 3.0);

        $this->stock($finishedGood, 0.0);
        $materialItem = $this->stock($material, 0.0);

        $this->process($this->order([['product' => $finishedGood, 'quantity' => 4.0]]));

        $materialItem->update(['on_hand_qty' => 100.0]);
        $this->fireStockReceived($material, $materialItem, 100.0, InventoryClass::RawMaterial);
        $this->fireStockReceived($material, $materialItem, 100.0, InventoryClass::RawMaterial);
        $this->fireStockReceived($material, $materialItem, 100.0, InventoryClass::RawMaterial);

        self::assertSame(12.0, $this->reservedQty($material), '4 x 3 — target, not a running total');
    }

    /**
     * Clause J — the reverse recipe lookup must not cross a company boundary.
     *
     * The order predicate is already company-scoped, so this asserts the whole chain:
     * a raw-material event carrying ANOTHER company's id must not recover our order
     * even though the material and the recipe are the ones our order depends on.
     */
    public function test_rm4_a_foreign_company_raw_material_event_does_not_recover_our_order(): void
    {
        $finishedGood = $this->ownCompanyFinishedGood(canManufacture: true);
        $material = $this->rawMaterial();
        $this->recipeFor($finishedGood, $material, 2.0);

        $this->stock($finishedGood, 0.0);
        $materialItem = $this->stock($material, 0.0);

        $order = $this->process($this->order([['product' => $finishedGood, 'quantity' => 5.0]]));
        self::assertSame(OrderStatus::AwaitingStock, $order->status);

        $foreign = Company::factory()->create();
        $materialItem->update(['on_hand_qty' => 100.0]);

        app(RetryReservationOnStockAvailableListener::class)->handleStockReceived(
            new InventoryStockReceived(
                inventoryItemId: $materialItem->id,
                warehouseId: $this->warehouse->id,
                productId: $material->id,
                companyId: $foreign->id,
                quantityReceived: 100.0,
                onHandBefore: 0.0,
                onHandAfter: 100.0,
                inventoryClass: InventoryClass::RawMaterial,
                unitCost: 10.0,
            ),
        );

        self::assertSame(OrderStatus::AwaitingStock, $order->refresh()->status);
        self::assertSame(0.0, $this->reservedQty($material));
    }

    // ── M / N. Idempotency ────────────────────────────────────────────────────

    public function test_m_repeated_stock_events_do_not_duplicate_the_reservation(): void
    {
        $product = $this->finishedGood();
        $item = $this->stock($product, 0.0);

        $order = $this->process($this->order([['product' => $product, 'quantity' => 10.0]]));

        $item->update(['on_hand_qty' => 40.0]);
        $this->fireStockReceived($product, $item, 40.0);
        $afterFirst = $this->reservedQty($product);

        // Same event again — a replay, a retry, a duplicated queue message.
        $this->fireStockReceived($product, $item, 40.0);
        $this->fireStockReceived($product, $item, 40.0);

        self::assertSame(10.0, $afterFirst);
        self::assertSame(10.0, $this->reservedQty($product), 'reservation must converge, not accumulate');
        self::assertSame(ReservationStatus::Reserved, $order->refresh()->reservation_status);
    }

    public function test_n_repeated_re_evaluation_does_not_duplicate_the_reservation(): void
    {
        $finishedGood = $this->finishedGood(canManufacture: true);
        $material = $this->rawMaterial();
        $this->recipeFor($finishedGood, $material, 2.0);
        $this->stock($finishedGood, 100.0);
        $this->stock($material, 100.0);

        $order = $this->order([['product' => $finishedGood, 'quantity' => 5.0]]);

        $this->process($order);
        $this->process($order);
        $this->process($order);

        self::assertSame(5.0, $this->reservedQty($finishedGood));
        self::assertSame(10.0, $this->reservedQty($material), '5 x 2, reconciled to target on every pass');
    }

    public function test_n2_material_reconciliation_converges_when_invoked_directly(): void
    {
        $finishedGood = $this->finishedGood(canManufacture: true);
        $material = $this->rawMaterial();
        $this->recipeFor($finishedGood, $material, 4.0);
        $this->stock($finishedGood, 100.0);
        $this->stock($material, 100.0);

        $order = $this->process($this->order([['product' => $finishedGood, 'quantity' => 3.0]]));

        $reconcile = app(ReconcileOrderMaterialReservationsAction::class);
        $reconcile->execute($order);
        $reconcile->execute($order);

        self::assertSame(12.0, $this->reservedQty($material), '3 x 4 — target, not a running total');
    }

    // ── O. Multi-line orders ──────────────────────────────────────────────────

    public function test_o_multi_line_order_reserves_every_satisfiable_line(): void
    {
        $a = $this->finishedGood();
        $b = $this->finishedGood();
        $this->stock($a, 50.0);
        $this->stock($b, 50.0);

        $order = $this->process($this->order([
            ['product' => $a, 'quantity' => 4.0],
            ['product' => $b, 'quantity' => 6.0],
        ]));

        self::assertSame(ReservationStatus::Reserved, $order->reservation_status);
        self::assertSame(4.0, $this->reservedQty($a));
        self::assertSame(6.0, $this->reservedQty($b));
    }

    public function test_o2_multi_line_with_one_short_line_is_partial_not_awaiting_stock(): void
    {
        $plentiful = $this->finishedGood();
        $short = $this->finishedGood();
        $this->stock($plentiful, 50.0);
        $this->stock($short, 0.0);

        $order = $this->process($this->order([
            ['product' => $plentiful, 'quantity' => 4.0],
            ['product' => $short, 'quantity' => 6.0],
        ]));

        // One line satisfied, one short → partial. awaiting_stock is reserved for
        // "no reservable line could be satisfied at all".
        self::assertSame(ReservationStatus::PartialReserved, $order->reservation_status);
        self::assertSame(4.0, $this->reservedQty($plentiful));
    }

    // ── P. Tenant isolation ───────────────────────────────────────────────────

    public function test_p_stock_arriving_for_another_company_does_not_recover_our_order(): void
    {
        $product = $this->finishedGood();
        $item = $this->stock($product, 0.0);

        $order = $this->process($this->order([['product' => $product, 'quantity' => 10.0]]));
        self::assertSame(OrderStatus::AwaitingStock, $order->status);

        // Real stock arrives, but it belongs to a different company.
        $foreign = Company::factory()->create();
        $item->update(['on_hand_qty' => 40.0]);

        app(RetryReservationOnStockAvailableListener::class)->handleStockReceived(
            new InventoryStockReceived(
                inventoryItemId: $item->id,
                warehouseId: $this->warehouse->id,
                productId: $product->id,
                companyId: $foreign->id,
                quantityReceived: 40.0,
                onHandBefore: 0.0,
                onHandAfter: 40.0,
                inventoryClass: InventoryClass::FinishedGood,
                unitCost: 10.0,
            ),
        );

        self::assertSame(
            OrderStatus::AwaitingStock,
            $order->refresh()->status,
            "Another company's stock event must never move our order.",
        );
    }

    // ── Q. Warehouse correctness ──────────────────────────────────────────────

    public function test_q_reservation_lands_on_the_assigned_warehouse_only(): void
    {
        $product = $this->finishedGood();
        $other = Warehouse::factory()->create(['company_id' => $this->company->id]);

        $this->stock($product, 50.0);            // assigned warehouse
        $this->stock($product, 50.0, $other);    // a different warehouse, same product

        $order = $this->process($this->order([['product' => $product, 'quantity' => 10.0]]));

        self::assertSame(ReservationStatus::Reserved, $order->reservation_status);
        self::assertSame(10.0, $this->reservedQty($product), 'assigned warehouse holds the reservation');
        self::assertSame(0.0, $this->reservedQty($product, $other), 'the other warehouse is untouched');
    }

    // ── The headline regression: warehouse missing ≠ stock shortage ───────────

    public function test_missing_warehouse_does_not_become_a_fake_stock_shortage(): void
    {
        $product = $this->finishedGood();
        $this->stock($product, 50.0);

        $order = $this->process($this->order(
            [['product' => $product, 'quantity' => 10.0]],
            assignWarehouse: false,
        ));

        // The defect this whole task exists for: an unresolved warehouse (a geography
        // failure) written as awaiting_stock (a finished-goods shortage), which made the
        // order unrecoverable because every recovery path keys on status.
        self::assertNotSame(OrderStatus::AwaitingStock, $order->status);
        self::assertSame(OrderStatus::InProgress, $order->status);
        self::assertSame(ReservationStatus::Pending, $order->reservation_status);
    }

    public function test_missing_warehouse_blocker_is_recorded_as_an_order_event(): void
    {
        $product = $this->finishedGood();
        $this->stock($product, 50.0);

        $order = $this->process($this->order(
            [['product' => $product, 'quantity' => 10.0]],
            assignWarehouse: false,
        ));

        self::assertDatabaseHas('order_events', [
            'order_id' => $order->id,
            'event_type' => 'reservation_execution_postponed',
        ]);
    }

    // ── HTTP surface ──────────────────────────────────────────────────────────

    public function test_http_order_read_exposes_the_lifecycle_and_reservation_separately(): void
    {
        $product = $this->finishedGood();
        $this->stock($product, 0.0);

        $order = $this->process($this->order(
            [['product' => $product, 'quantity' => 10.0]],
            status: OrderStatus::AwaitingPayment,
        ));

        $this->actingAs(\App\Models\User::factory()->create(['company_id' => $this->company->id]));

        $data = $this->getJson("/api/orders/{$order->id}")->assertOk()->json('data');

        // Two columns answering two different questions — the collapse of which was
        // the original defect.
        self::assertSame(OrderStatus::AwaitingPayment->value, $data['status']);
        self::assertSame(ReservationStatus::AwaitingStock->value, $data['reservation_status']);
    }

    /**
     * `inventory_reserved_at` IS NOT A RESERVATION OUTCOME — it is a "last evaluated at"
     * timestamp, and it is stamped on EVERY outcome including a total shortage
     * (ReserveOrderInventoryAction writes it unconditionally alongside the status).
     *
     * Recorded as a test because two Order UI surfaces derived their "Reserved" state
     * from exactly this field, and therefore reported a green "Reserved" for an order
     * holding no inventory at all. `reservation_status` is the only reservation
     * authority; this asserts the trap rather than leaving it for the next reader to
     * rediscover.
     */
    public function test_reserved_at_is_stamped_even_when_nothing_was_reserved(): void
    {
        $product = $this->finishedGood();
        $this->stock($product, 0.0);

        $order = $this->process($this->order([['product' => $product, 'quantity' => 10.0]]));

        self::assertSame(ReservationStatus::AwaitingStock, $order->reservation_status);
        self::assertNotNull(
            $order->inventory_reserved_at,
            'the timestamp is present despite zero reservation — so it cannot mean "reserved"',
        );
        self::assertSame(0.0, $this->reservedQty($product));
    }
}

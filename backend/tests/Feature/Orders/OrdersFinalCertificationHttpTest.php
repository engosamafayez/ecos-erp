<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Configuration\Domain\Models\MasterGovernorate;
use Modules\Admin\Configuration\Domain\Models\MasterZone;
use Modules\Commerce\Orders\Application\Listeners\RetryReservationOnStockAvailableListener;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Enums\ReservationStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Modules\Inventory\DomainEvents\Events\InventoryStockReceived;
use Modules\Inventory\InventoryItems\Domain\Models\InventoryItem;
use Modules\Inventory\Products\Domain\Enums\InventoryClass;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Manufacturing\BillsOfMaterials\Domain\Models\Recipe;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\MasterData\Warehouses\Domain\Models\WarehouseBrandCoverage;
use Modules\Operations\Preparation\Application\Services\WarehouseAssignmentEngine;
use Modules\Organization\Branches\Domain\Models\Branch;
use Modules\Organization\Branches\Domain\Models\BranchCoverageArea;
use Modules\Organization\Brands\Domain\Models\Brand;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-ORDERS-FINAL-INTEGRATION-AND-CERTIFICATION-CLOSURE-001 — HTTP certification.
 *
 * The point of this file is the SURFACE, not the rules. Every behaviour asserted here is
 * already covered at the domain layer by OrderAvailabilityLifecycleContractTest and
 * OrderLifecycleAvailabilityReservationClosureTest; what those cannot prove is that the
 * real route → FormRequest → controller → workflow → response chain produces the same
 * answer. PART 23: "Static tests are NOT sufficient."
 *
 * Two things are therefore deliberate:
 *   - orders are created through POST /api/orders/manual, never by Order::create();
 *   - every lifecycle transition goes through its real endpoint, and the assertion is on
 *     the RESPONSE plus the persisted row — so a 200 that did not transition fails here.
 *
 * `DatabaseTransactions`, not `RefreshDatabase`: `ecos_dev_test` is shared and contended;
 * `migrate:fresh` is what makes concurrent agents destroy each other's runs (PART 21).
 */
final class OrdersFinalCertificationHttpTest extends TestCase
{
    use DatabaseTransactions;

    private Company $company;

    private Warehouse $warehouse;

    private Customer $customer;

    private Brand $brand;

    private MasterGovernorate $governorate;

    private MasterZone $zone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->customer = Customer::factory()->create();

        $this->governorate = MasterGovernorate::create([
            'name' => 'Cairo',
            'name_ar' => 'القاهرة',
            'code' => 'C'.substr(uniqid(), -7),
            'is_active' => true,
        ]);

        $this->zone = MasterZone::create([
            'master_governorate_id' => $this->governorate->id,
            'name' => 'Nasr City',
            'code' => 'NC'.substr(uniqid(), -8),
            'is_active' => true,
        ]);

        [$this->warehouse, $this->brand] = $this->coveredWarehouseAndBrand($this->company);
    }

    // ── Fixture ───────────────────────────────────────────────────────────────

    /**
     * A warehouse reachable from this test's geography, plus a brand it is configured to
     * serve. Both halves are required: geography alone yields a candidate branch, and
     * WarehouseBrandCoverage is what makes the warehouse eligible for the order's brand
     * (NO ROWS = SERVES NO BRANDS).
     *
     * @return array{0: Warehouse, 1: Brand}
     */
    private function coveredWarehouseAndBrand(Company $company): array
    {
        $warehouse = Warehouse::factory()->create([
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $brand = Brand::create([
            'company_id' => $company->id,
            'code' => 'BR'.substr(uniqid(), -8),
            'name' => 'Brand '.uniqid(),
            'slug' => 'brand-'.uniqid(),
            'is_active' => true,
        ]);

        $branch = Branch::create([
            'company_id' => $company->id,
            'code' => 'BR-'.uniqid(),
            'name' => 'Branch '.uniqid(),
            'default_warehouse_id' => $warehouse->id,
            'is_active' => true,
        ]);

        BranchCoverageArea::create([
            'branch_id' => $branch->id,
            'master_governorate_id' => $this->governorate->id,
            'master_zone_id' => $this->zone->id,
            'priority' => 100,
            'is_active' => true,
        ]);

        WarehouseBrandCoverage::create([
            'company_id' => $company->id,
            'warehouse_id' => $warehouse->id,
            'brand_id' => $brand->id,
            'is_active' => true,
        ]);

        return [$warehouse, $brand];
    }

    private function product(?Brand $brand = null, bool $canManufacture = false): Product
    {
        $brand ??= $this->brand;

        return Product::factory()->finishedGood()->create([
            'brand_id' => $brand->id,
            'company_id' => $brand->company_id,
            'can_manufacture' => $canManufacture,
        ]);
    }

    private function rawMaterial(): Product
    {
        return Product::factory()->rawMaterial()->create();
    }

    private function stock(Product $product, float $onHand, ?Warehouse $warehouse = null, ?Company $company = null): InventoryItem
    {
        return InventoryItem::query()->create([
            'warehouse_id' => ($warehouse ?? $this->warehouse)->id,
            'product_id' => $product->id,
            'company_id' => ($company ?? $this->company)->id,
            'on_hand_qty' => $onHand,
            'reserved_qty' => 0.0,
        ]);
    }

    private function user(?Company $company = null): User
    {
        return User::factory()->create(['company_id' => ($company ?? $this->company)->id]);
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

    /**
     * POST /api/orders/manual — the real creation surface.
     *
     * `$resolveWarehouse = false` omits the geography so BranchAssignmentEngine cannot
     * resolve a warehouse; that is how CASE 6 (RC-10) is exercised over HTTP rather than
     * by hand-nulling a column.
     */
    private function createOrder(
        Product $product,
        ?string $status = null,
        float $qty = 5.0,
        ?Company $company = null,
        bool $resolveWarehouse = true,
        ?string $deliveryDate = null,
    ): Order {
        $company ??= $this->company;

        $payload = [
            'customer_id' => $this->customer->id,
            'company_id' => $company->id,
            'order_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $product->id,
                'quantity' => $qty,
                'unit_price' => 100,
            ]],
        ];

        if ($resolveWarehouse) {
            $payload['governorate'] = 'Cairo';
            $payload['area'] = 'Nasr City';
        }

        if ($status !== null) {
            $payload['status'] = $status;
        }

        if ($deliveryDate !== null) {
            $payload['requested_delivery_date'] = $deliveryDate;
        }

        $response = $this->actingAs($this->user($company))
            ->postJson('/api/orders/manual', $payload);

        $response->assertSuccessful();

        $order = Order::whereKey($response->json('data.id'))->first();
        self::assertNotNull($order, 'order was not persisted by the HTTP surface');

        return $order;
    }

    // ══ PART 7 — ORDER CREATION MATRIX, over HTTP ═════════════════════════════

    /** CASE 1 — product available → canonical lifecycle, no needless Awaiting Stock. */
    public function test_case1_available_product_stays_in_the_canonical_lifecycle(): void
    {
        $product = $this->product();
        $this->stock($product, 100.0);

        $order = $this->createOrder($product, OrderStatus::InProgress->value);

        self::assertNotNull($order->assigned_warehouse_id, 'HTTP surface resolved a warehouse');
        self::assertSame(OrderStatus::InProgress, $order->status);
        self::assertNotSame(OrderStatus::AwaitingStock, $order->status);
        self::assertSame(ReservationStatus::Reserved, $order->reservation_status);
        self::assertSame(5.0, $this->reservedQty($product));
    }

    /** CASE 2 — product unavailable → Awaiting Stock. */
    public function test_case2_unavailable_product_becomes_awaiting_stock(): void
    {
        $product = $this->product();
        $this->stock($product, 0.0);

        $order = $this->createOrder($product, OrderStatus::InProgress->value);

        self::assertSame(OrderStatus::AwaitingStock, $order->status);
        self::assertSame(ReservationStatus::AwaitingStock, $order->reservation_status);
        self::assertSame(0.0, $this->reservedQty($product));
    }

    /** CASE 3 — available + Awaiting Payment: availability decided, payment block intact. */
    public function test_case3_available_awaiting_payment_decides_availability_and_keeps_the_block(): void
    {
        $product = $this->product();
        $this->stock($product, 100.0);

        $order = $this->createOrder($product, OrderStatus::AwaitingPayment->value);

        self::assertSame(OrderStatus::AwaitingPayment, $order->status, 'payment block intact');
        self::assertSame(ReservationStatus::Reserved, $order->reservation_status, 'availability decided');
        self::assertNotSame(ReservationStatus::Pending, $order->reservation_status);
    }

    /** CASE 4 — unavailable + Awaiting Payment: shortage recorded, payment block intact. */
    public function test_case4_unavailable_awaiting_payment_records_shortage_and_keeps_the_block(): void
    {
        $product = $this->product();
        $this->stock($product, 0.0);

        $order = $this->createOrder($product, OrderStatus::AwaitingPayment->value);

        self::assertSame(OrderStatus::AwaitingPayment, $order->status);
        self::assertSame(ReservationStatus::AwaitingStock, $order->reservation_status);
    }

    /** CASE 5 — Scheduled: no immediate availability decision. */
    public function test_case5_scheduled_order_takes_no_immediate_availability_decision(): void
    {
        $product = $this->product();
        $this->stock($product, 100.0);

        $order = $this->createOrder(
            $product,
            OrderStatus::Scheduled->value,
            deliveryDate: now()->addDays(5)->toDateString(),
        );

        self::assertSame(OrderStatus::Scheduled, $order->status);
        self::assertNull($order->reservation_status, 'no decision until D-1');
        self::assertSame(0.0, $this->reservedQty($product), 'no stock held by a future-dated order');
    }

    /**
     * CASE 6 — warehouse unresolved → RC-10.
     *
     * The lifecycle status must be untouched and the reservation postponed. This is the
     * headline regression of the whole Orders line of work: a geography failure written
     * as `awaiting_stock` (a finished-goods shortage) made such orders unrecoverable,
     * because every recovery path keys on state.
     */
    public function test_case6_unresolved_warehouse_postpones_reservation_and_never_fakes_a_shortage(): void
    {
        $product = $this->product();
        $this->stock($product, 100.0);

        $order = $this->createOrder($product, OrderStatus::InProgress->value, resolveWarehouse: false);

        self::assertNull($order->assigned_warehouse_id, 'precondition: no warehouse resolved');
        self::assertNotSame(OrderStatus::AwaitingStock, $order->status);
        self::assertSame(OrderStatus::InProgress, $order->status);
        self::assertSame(ReservationStatus::Pending, $order->reservation_status);
        self::assertSame(0.0, $this->reservedQty($product));
    }

    // ══ PART 6 — TENANT ISOLATION, proven at the reservation layer ════════════

    /**
     * Company A's order must reserve only Company A's inventory.
     *
     * Company B holds ample stock of the SAME product id in ITS OWN warehouse; A holds
     * none. A's order must go short rather than reach across the boundary.
     */
    public function test_tenant_company_a_cannot_reserve_company_b_inventory(): void
    {
        $foreign = Company::factory()->create();
        [$foreignWarehouse] = $this->coveredWarehouseAndBrand($foreign);

        $product = $this->product();
        $this->stock($product, 0.0);                                   // A: nothing
        $this->stock($product, 500.0, $foreignWarehouse, $foreign);    // B: plenty

        $order = $this->createOrder($product, OrderStatus::InProgress->value);

        self::assertSame($this->warehouse->id, $order->assigned_warehouse_id);
        self::assertSame(OrderStatus::AwaitingStock, $order->status);
        self::assertSame(0.0, $this->reservedQty($product, $foreignWarehouse), "B's stock untouched");
    }

    /** A foreign-company stock event must not recover our order (recovery-path isolation). */
    public function test_tenant_foreign_stock_event_does_not_recover_our_order(): void
    {
        $foreign = Company::factory()->create();

        $product = $this->product();
        $item = $this->stock($product, 0.0);

        $order = $this->createOrder($product, OrderStatus::InProgress->value);
        self::assertSame(OrderStatus::AwaitingStock, $order->status);

        $item->update(['on_hand_qty' => 100.0]);

        app(RetryReservationOnStockAvailableListener::class)->handleStockReceived(
            new InventoryStockReceived(
                inventoryItemId: $item->id,
                warehouseId: $this->warehouse->id,
                productId: $product->id,
                companyId: $foreign->id,          // the foreign tenant
                quantityReceived: 100.0,
                onHandBefore: 0.0,
                onHandAfter: 100.0,
                inventoryClass: InventoryClass::FinishedGood,
                unitCost: 10.0,
            ),
        );

        self::assertSame(OrderStatus::AwaitingStock, $order->refresh()->status);
        self::assertSame(0.0, $this->reservedQty($product));
    }

    /** The raw-material recovery edge must be tenant-scoped too (ADR-027 §16.4 path). */
    public function test_tenant_foreign_raw_material_event_does_not_recover_our_recipe_order(): void
    {
        $foreign = Company::factory()->create();

        $finishedGood = $this->product(canManufacture: true);
        $material = $this->rawMaterial();
        $this->recipeFor($finishedGood, $material, 2.0);

        $this->stock($finishedGood, 0.0);
        $materialItem = $this->stock($material, 0.0);

        $order = $this->createOrder($finishedGood, OrderStatus::InProgress->value);
        self::assertSame(OrderStatus::AwaitingStock, $order->status);

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

    /**
     * PART 6 — the same isolation under a RESTRICTED actor, not the baseline system role.
     *
     * `actingAs()` in this suite grants the `is_system` role, whose Gate::before bypass
     * passes every permission check. That is fine for proving lifecycle behaviour, but it
     * would hide an authorization defect, so this case authenticates an operator holding
     * ONLY `sales.orders.create` and scoped to Company A. Isolation must hold on the data
     * path regardless of who is asking.
     */
    public function test_tenant_isolation_holds_for_a_restricted_company_a_operator(): void
    {
        $foreign = Company::factory()->create();
        [$foreignWarehouse] = $this->coveredWarehouseAndBrand($foreign);

        $product = $this->product();
        $this->stock($product, 0.0);
        $this->stock($product, 500.0, $foreignWarehouse, $foreign);

        $operator = $this->restrictedOperator($this->company, ['sales.orders.create'], 'cert-a-creator');

        $response = $this->actingAsUnprivileged($operator)->postJson('/api/orders/manual', [
            'customer_id' => $this->customer->id,
            'company_id' => $this->company->id,
            'order_date' => now()->toDateString(),
            'governorate' => 'Cairo',
            'area' => 'Nasr City',
            'status' => OrderStatus::InProgress->value,
            'lines' => [[
                'product_id' => $product->id,
                'quantity' => 5.0,
                'unit_price' => 100,
            ]],
        ]);

        $response->assertSuccessful();

        $order = Order::whereKey($response->json('data.id'))->first();

        self::assertSame(OrderStatus::AwaitingStock, $order->status);
        self::assertSame(0.0, $this->reservedQty($product, $foreignWarehouse), "B's stock untouched");
    }

    /** @param list<string> $permissions */
    private function restrictedOperator(Company $company, array $permissions, string $slug): User
    {
        $user = User::factory()->create(['company_id' => $company->id]);

        $role = Role::firstOrCreate(['slug' => $slug], ['name' => 'Cert '.$slug, 'is_system' => false]);

        foreach ($permissions as $name) {
            // `permissions` is the source of truth and its rows are normally seeded, but
            // this shared test schema is not seeded, so the row is created here with the
            // columns the table actually requires (module/resource/action are NOT NULL and
            // are derived from the canonical dotted name).
            [$module, $resource, $action] = array_pad(explode('.', $name), 3, '');

            $permission = Permission::firstOrCreate(
                ['name' => $name],
                ['module' => $module, 'resource' => $resource, 'action' => $action],
            );

            if (! $role->permissions()->where('permissions.id', $permission->id)->exists()) {
                $role->permissions()->attach($permission->id);
            }
        }

        $user->roles()->attach($role->id);
        $user->unsetRelation('roles');

        return $user;
    }

    // ══ PART 8 — AUTOMATIC STOCK RECOVERY ════════════════════════════════════

    public function test_stock_recovery_returns_an_awaiting_stock_order_to_in_progress(): void
    {
        $product = $this->product();
        $item = $this->stock($product, 0.0);

        $order = $this->createOrder($product, OrderStatus::InProgress->value);
        self::assertSame(OrderStatus::AwaitingStock, $order->status);

        $item->update(['on_hand_qty' => 50.0]);
        app(RetryReservationOnStockAvailableListener::class)->handleStockReceived(
            new InventoryStockReceived(
                inventoryItemId: $item->id,
                warehouseId: $this->warehouse->id,
                productId: $product->id,
                companyId: $this->company->id,
                quantityReceived: 50.0,
                onHandBefore: 0.0,
                onHandAfter: 50.0,
                inventoryClass: InventoryClass::FinishedGood,
                unitCost: 10.0,
            ),
        );

        $order->refresh();
        self::assertSame(OrderStatus::InProgress, $order->status);
        self::assertSame(ReservationStatus::Reserved, $order->reservation_status);
        self::assertSame(5.0, $this->reservedQty($product));
    }

    // ══ PART 9 — WAREHOUSE RECOVERY (RC-10 → H3) ═════════════════════════════

    /**
     * The other half of CASE 6: a postponed reservation must not be permanently stuck.
     * Assigning a warehouse through the canonical WarehouseAssignmentEngine fires
     * WarehouseAssigned, and ExecuteReservationOnWarehouseAssigned executes the
     * postponed reservation.
     */
    public function test_warehouse_recovery_executes_a_postponed_reservation(): void
    {
        $product = $this->product();
        $this->stock($product, 100.0);

        $order = $this->createOrder($product, OrderStatus::InProgress->value, resolveWarehouse: false);
        self::assertSame(ReservationStatus::Pending, $order->reservation_status);

        // Canonical assignment path — the supervisor override, which is what dispatches
        // WarehouseAssigned. Not a hand-written column update.
        app(WarehouseAssignmentEngine::class)->override(
            $order,
            $this->warehouse->id,
            'certification: warehouse resolved after creation',
            (string) $this->user()->id,
        );

        $order->refresh();
        self::assertSame($this->warehouse->id, $order->assigned_warehouse_id);
        self::assertSame(ReservationStatus::Reserved, $order->reservation_status, 'H3 recovery executed');
        self::assertSame(OrderStatus::InProgress, $order->status);
        self::assertSame(5.0, $this->reservedQty($product));
    }

    // ══ PART 10 — RAW MATERIAL RESERVATION ═══════════════════════════════════

    public function test_raw_material_reservation_uses_the_active_recipe_and_correct_quantity(): void
    {
        $finishedGood = $this->product(canManufacture: true);
        $material = $this->rawMaterial();
        $this->recipeFor($finishedGood, $material, 3.0);

        $this->stock($finishedGood, 100.0);
        $this->stock($material, 100.0);

        $order = $this->createOrder($finishedGood, OrderStatus::InProgress->value, qty: 7.0);

        self::assertSame(ReservationStatus::Reserved, $order->reservation_status);
        self::assertSame(7.0, $this->reservedQty($finishedGood), 'FG reserved from stock');
        self::assertSame(21.0, $this->reservedQty($material), '7 x 3 raw material, correct warehouse');
    }

    /** RM shortage blocks via the §16 recipe gate, then recovers when the RM arrives. */
    public function test_raw_material_shortage_blocks_then_recovers(): void
    {
        $finishedGood = $this->product(canManufacture: true);
        $material = $this->rawMaterial();
        $this->recipeFor($finishedGood, $material, 2.0);

        $this->stock($finishedGood, 0.0);
        $materialItem = $this->stock($material, 0.0);

        $order = $this->createOrder($finishedGood, OrderStatus::InProgress->value);
        self::assertSame(OrderStatus::AwaitingStock, $order->status);

        $materialItem->update(['on_hand_qty' => 100.0]);
        app(RetryReservationOnStockAvailableListener::class)->handleStockReceived(
            new InventoryStockReceived(
                inventoryItemId: $materialItem->id,
                warehouseId: $this->warehouse->id,
                productId: $material->id,
                companyId: $this->company->id,
                quantityReceived: 100.0,
                onHandBefore: 0.0,
                onHandAfter: 100.0,
                inventoryClass: InventoryClass::RawMaterial,
                unitCost: 10.0,
            ),
        );

        $order->refresh();
        self::assertSame(OrderStatus::InProgress, $order->status);
        self::assertSame(10.0, $this->reservedQty($material), '5 x 2');
    }

    // ══ PART 11 — IDEMPOTENCY, including over HTTP ═══════════════════════════

    /** Repeated confirm requests must not accumulate reservation. */
    public function test_idempotency_repeated_confirm_requests_do_not_duplicate_reservation(): void
    {
        $product = $this->product();
        $this->stock($product, 100.0);

        $order = $this->createOrder($product, OrderStatus::InProgress->value);
        self::assertSame(5.0, $this->reservedQty($product));

        $user = $this->user();
        $first = $this->actingAs($user)->postJson("/api/fulfillment/orders/{$order->id}/confirm");
        $first->assertOk();

        // A second confirm on an already-confirmed order is not a valid transition.
        $second = $this->actingAs($user)->postJson("/api/fulfillment/orders/{$order->id}/confirm");
        $second->assertStatus(422);

        self::assertSame(5.0, $this->reservedQty($product), 'reservation converged, never accumulated');
    }

    /** Repeated raw-material events must converge to the target, not accumulate. */
    public function test_idempotency_repeated_raw_material_events_converge(): void
    {
        $finishedGood = $this->product(canManufacture: true);
        $material = $this->rawMaterial();
        $this->recipeFor($finishedGood, $material, 3.0);

        $this->stock($finishedGood, 0.0);
        $materialItem = $this->stock($material, 0.0);

        $this->createOrder($finishedGood, OrderStatus::InProgress->value, qty: 4.0);

        $materialItem->update(['on_hand_qty' => 100.0]);

        for ($i = 0; $i < 3; $i++) {
            app(RetryReservationOnStockAvailableListener::class)->handleStockReceived(
                new InventoryStockReceived(
                    inventoryItemId: $materialItem->id,
                    warehouseId: $this->warehouse->id,
                    productId: $material->id,
                    companyId: $this->company->id,
                    quantityReceived: 100.0,
                    onHandBefore: 0.0,
                    onHandAfter: 100.0,
                    inventoryClass: InventoryClass::RawMaterial,
                    unitCost: 10.0,
                ),
            );
        }

        self::assertSame(12.0, $this->reservedQty($material), '4 x 3 — target, not a running total');
    }

    // ══ PART 13 / 14 — CONFIRM CONTRACT + EVENT INTEGRITY ════════════════════

    /**
     * PART 13. The reported bug was HTTP 200 while the order stayed `in_progress`.
     * The response body and the persisted row must agree, and both must show the
     * canonical post-confirm state.
     */
    public function test_confirm_transitions_and_the_response_matches_the_persisted_row(): void
    {
        $product = $this->product();
        $this->stock($product, 100.0);

        $order = $this->createOrder($product, OrderStatus::InProgress->value);

        $response = $this->actingAs($this->user())
            ->postJson("/api/fulfillment/orders/{$order->id}/confirm")
            ->assertOk();

        $reported = $response->json('status');
        $persisted = $order->refresh()->status->value;

        self::assertSame($persisted, $reported, 'the response must not claim a state the row does not hold');
        self::assertNotSame(
            OrderStatus::InProgress->value,
            $persisted,
            'confirm returned 200 but the order never left in_progress',
        );
        self::assertSame(OrderStatus::Confirmed->value, $persisted);
    }

    /**
     * PART 14. A failed transition must produce NO success: not a 2xx, and not a
     * success OrderEvent. `delivered` is terminal, so confirm from it is impossible.
     */
    public function test_failed_confirm_emits_no_success_event_and_no_2xx(): void
    {
        $product = $this->product();
        $this->stock($product, 100.0);

        $order = $this->createOrder($product, OrderStatus::InProgress->value);

        // Reach a state Confirm's guard rejects, without going through Confirm.
        $order->forceFill(['status' => OrderStatus::Delivered->value])->saveQuietly();

        $eventsBefore = DB::table('order_events')
            ->where('order_id', $order->id)
            ->where('event_type', 'confirm_order')
            ->count();

        $this->actingAs($this->user())
            ->postJson("/api/fulfillment/orders/{$order->id}/confirm")
            ->assertStatus(422);

        $eventsAfter = DB::table('order_events')
            ->where('order_id', $order->id)
            ->where('event_type', 'confirm_order')
            ->count();

        self::assertSame($eventsBefore, $eventsAfter, 'a rejected transition logged a success event');
        self::assertSame(OrderStatus::Delivered, $order->refresh()->status, 'status untouched by the failure');
    }

    // ══ PART 12 — STATUS INTEGRITY ═══════════════════════════════════════════

    /**
     * Every status write must go through the canonical workflow. `Order::booted()`
     * enforces this (P9) by throwing on any status write outside FulfillmentEngine::run,
     * so this asserts the guard is actually armed rather than trusting that it is.
     */
    public function test_status_integrity_a_direct_status_write_is_rejected(): void
    {
        $product = $this->product();
        $this->stock($product, 100.0);

        $order = $this->createOrder($product, OrderStatus::InProgress->value);

        $this->expectException(\Modules\Commerce\Orders\Domain\Exceptions\UnauthorizedOrderStatusWriteException::class);

        $order->update(['status' => OrderStatus::Confirmed->value]);
    }

    // ══ PART 16 — WAVE / PREPARATION ELIGIBILITY EXPOSURE ════════════════════

    /**
     * Orders must expose the correct lifecycle state to Wave eligibility. This asserts
     * the EXPOSURE only — no Wave behaviour is exercised or modified (PART 16).
     */
    public function test_wave_eligibility_exposure_matches_the_canonical_list(): void
    {
        $eligible = OrderStatus::fulfilmentEligible();

        $available = $this->product();
        $this->stock($available, 100.0);
        $inProgress = $this->createOrder($available, OrderStatus::InProgress->value);

        $short = $this->product();
        $this->stock($short, 0.0);
        $awaitingStock = $this->createOrder($short, OrderStatus::InProgress->value);

        $unpaidProduct = $this->product();
        $this->stock($unpaidProduct, 100.0);
        $unpaid = $this->createOrder($unpaidProduct, OrderStatus::AwaitingPayment->value);

        self::assertContains($inProgress->status, $eligible, 'a reserved In Progress order is eligible');
        self::assertNotContains($awaitingStock->status, $eligible, 'Awaiting Stock is not eligible');
        self::assertNotContains(
            $unpaid->status,
            $eligible,
            'an unpaid order is not eligible even though it now holds a reservation',
        );
    }

    // ══ PART 18 — UI / BACKEND PARITY over the read surface ══════════════════

    /**
     * The two columns the Orders UI renders must arrive from the API as two independent
     * fields. Their collapse into one was the original defect.
     */
    public function test_read_surface_exposes_lifecycle_and_reservation_independently(): void
    {
        $product = $this->product();
        $this->stock($product, 0.0);

        $order = $this->createOrder($product, OrderStatus::AwaitingPayment->value);

        $data = $this->actingAs($this->user())
            ->getJson("/api/orders/{$order->id}")
            ->assertOk()
            ->json('data');

        self::assertSame(OrderStatus::AwaitingPayment->value, $data['status']);
        self::assertSame(ReservationStatus::AwaitingStock->value, $data['reservation_status']);
        self::assertArrayHasKey('reservation_failure_reason', $data);
    }

    /**
     * A Scheduled order carries NO reservation decision, and the API must say so with
     * null rather than a fabricated state — this is the backend half of the UI's "—".
     */
    public function test_read_surface_reports_null_rather_than_a_fabricated_state(): void
    {
        $product = $this->product();
        $this->stock($product, 100.0);

        $order = $this->createOrder(
            $product,
            OrderStatus::Scheduled->value,
            deliveryDate: now()->addDays(5)->toDateString(),
        );

        $data = $this->actingAs($this->user())
            ->getJson("/api/orders/{$order->id}")
            ->assertOk()
            ->json('data');

        self::assertSame(OrderStatus::Scheduled->value, $data['status']);
        self::assertNull($data['reservation_status']);
    }
}

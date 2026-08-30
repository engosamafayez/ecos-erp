<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Commerce\Orders\Application\Listeners\RetryReservationOnStockAvailableListener;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Enums\ReservationStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\OrderLine;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Modules\Inventory\DomainEvents\Events\InventoryStockReceived;
use Modules\Inventory\DomainEvents\Events\ProductNegativeStockEnabled;
use Modules\Inventory\InventoryItems\Domain\Models\InventoryItem;
use Modules\Inventory\Products\Domain\Enums\InventoryClass;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Manufacturing\BillsOfMaterials\Domain\Models\Recipe;
use Modules\Manufacturing\BillsOfMaterials\Domain\Services\ManufacturingAvailabilityService;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Brands\Domain\Models\Brand;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-ORDER-PREPARATION-FULFILLABILITY-CONTRACT-001 — the made-to-order contract.
 *
 * ECOS is order-driven preparation. A finished product with ZERO finished-good stock
 * is fulfillable when its PREPARATION RECIPE is executable (every required material
 * available OR allow_negative). The `can_manufacture` capability flag is NO LONGER an
 * order-fulfillability gate (ADR-027 §16 v1.5). Every finished good below is created
 * WITHOUT `manufacturable()`, i.e. `can_manufacture = false`, precisely to prove that
 * the flag is not consulted — recipe executability alone governs.
 *
 * Sibling suites still prove the complementary facts (unchanged by this task):
 *   - RecipeToOrderAvailabilityE2ETest — recipe executability governs when the flag is true
 *   - OrderAvailabilityLifecycleContractTest — RM-arrival recovery, replay/tenant isolation
 */
final class OrderPreparationFulfillabilityContractTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Warehouse $warehouse;

    private Customer $customer;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
        $this->customer = Customer::factory()->create();
        $this->brand = Brand::factory()->create(['company_id' => $this->company->id]);
    }

    // ── Fixture (finished good is deliberately NOT manufacturable) ───────────────

    /** can_manufacture = false (base default) — the whole point of this suite. */
    private function finishedGood(): Product
    {
        $fg = Product::factory()->finishedGood()->create(['brand_id' => $this->brand->id]);
        self::assertFalse((bool) $fg->can_manufacture, 'fixture guard: FG must be can_manufacture=false');

        return $fg;
    }

    private function rawMaterial(bool $allowNegative): Product
    {
        return Product::factory()->rawMaterial()->create([
            'brand_id' => $this->brand->id,
            'is_active' => true,
            'allow_negative_stock' => $allowNegative,
        ]);
    }

    private function recipeFor(Product $fg): Recipe
    {
        return Recipe::create([
            'bom_number' => 'BOM-PREP-'.uniqid(),
            'product_id' => $fg->id,
            'version' => '1.0',
            'bom_version_number' => 1,
            'is_active' => true,
        ]);
    }

    private function addComponent(Recipe $recipe, Product $rm, float $qty): void
    {
        $recipe->components()->create(['raw_material_id' => $rm->id, 'quantity' => $qty]);
    }

    private function stock(Product $product, float $onHand): InventoryItem
    {
        return InventoryItem::query()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $product->id,
            'company_id' => $this->company->id,
            'on_hand_qty' => $onHand,
            'reserved_qty' => 0,
        ]);
    }

    private function orderFor(Product $fg, float $qty = 1.0): Order
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

    private function operator(): User
    {
        $user = User::factory()->create(['company_id' => $this->company->id]);

        $role = Role::firstOrCreate(
            ['slug' => 'test-prep-fulfill-operator'],
            ['name' => 'Test Prep Fulfillability Operator', 'is_system' => false],
        );

        $permission = Permission::firstOrCreate(
            ['name' => 'operations.fulfillment.manage'],
            ['module' => 'operations', 'resource' => 'fulfillment', 'action' => 'manage'],
        );

        if (! $role->permissions()->where('permissions.id', $permission->id)->exists()) {
            $role->permissions()->attach($permission->id);
        }

        $user->roles()->attach($role->id);
        $user->unsetRelation('roles');

        return $user;
    }

    private function recipeStatus(Product $fg): string
    {
        return (string) app(ManufacturingAvailabilityService::class)->evaluate($fg->fresh())['status'];
    }

    /**
     * Drive the REAL V3 path: HTTP → FulfillmentEngine → MoveToPreparationWorkflow →
     * ReserveOrderInventoryAction → persistence.
     *
     * @return array{http:int, status:string, reservation:?string, reserved:float}
     */
    private function runOrderPath(Order $order): array
    {
        $response = $this->postJson(
            "/api/fulfillment/orders/{$order->id}/transition",
            ['target_status' => OrderStatus::ReadyForDispatch->value],
        );

        $order->refresh();
        $line = OrderLine::query()->where('order_id', $order->id)->first();

        return [
            'http' => $response->getStatusCode(),
            'status' => $order->status->value,
            'reservation' => $order->reservation_status?->value,
            'reserved' => (float) ($line?->reserved_qty ?? 0),
        ];
    }

    private function listener(): RetryReservationOnStockAvailableListener
    {
        return app(RetryReservationOnStockAvailableListener::class);
    }

    // ── §8 — Honey Jar: FG=0, can_manufacture=false, recipe executable → RESERVES ──

    public function test_zero_fg_stock_with_executable_recipe_is_fulfillable_without_can_manufacture(): void
    {
        // Honey Jar 250g: Honey 250g + Jar 1, both available. FG stock 0.
        $fg = $this->finishedGood();
        $honey = $this->rawMaterial(allowNegative: false);
        $jar = $this->rawMaterial(allowNegative: false);

        $recipe = $this->recipeFor($fg);
        $this->addComponent($recipe, $honey, 0.25);
        $this->addComponent($recipe, $jar, 1.0);

        $this->stock($honey, 100.0);
        $this->stock($jar, 100.0);
        $this->stock($fg, 0.0);

        self::assertSame('instock', $this->recipeStatus($fg), 'recipe executable from raw materials');

        $this->actingAs($this->operator());
        $r = $this->runOrderPath($this->orderFor($fg));

        self::assertSame(200, $r['http']);
        self::assertNotSame(OrderStatus::AwaitingStock->value, $r['status'], 'FG=0 alone must NOT cause Awaiting Stock');
        self::assertSame(OrderStatus::ReadyForDispatch->value, $r['status']);
        self::assertSame(1.0, $r['reserved'], 'the finished product is fulfillable and reserved');
    }

    // ── §9 — allow_negative material keeps the recipe executable ────────────────

    public function test_zero_fg_stock_with_allow_negative_material_is_fulfillable(): void
    {
        $fg = $this->finishedGood();
        $honey = $this->rawMaterial(allowNegative: true);   // on hand 0, but drawable on credit
        $jar = $this->rawMaterial(allowNegative: false);

        $recipe = $this->recipeFor($fg);
        $this->addComponent($recipe, $honey, 0.25);
        $this->addComponent($recipe, $jar, 1.0);

        $this->stock($honey, 0.0);
        $this->stock($jar, 100.0);
        $this->stock($fg, 0.0);

        self::assertSame('instock', $this->recipeStatus($fg), 'allow_negative keeps the recipe executable');

        $this->actingAs($this->operator());
        $r = $this->runOrderPath($this->orderFor($fg));

        self::assertSame(OrderStatus::ReadyForDispatch->value, $r['status']);
        self::assertSame(1.0, $r['reserved']);
    }

    // ── §10 — blocked material → NOT fulfillable → Awaiting Stock ───────────────

    public function test_blocked_material_makes_the_order_awaiting_stock(): void
    {
        $fg = $this->finishedGood();
        $honey = $this->rawMaterial(allowNegative: false);  // on hand 0, no negative
        $jar = $this->rawMaterial(allowNegative: false);

        $recipe = $this->recipeFor($fg);
        $this->addComponent($recipe, $honey, 0.25);
        $this->addComponent($recipe, $jar, 1.0);

        $this->stock($honey, 0.0);
        $this->stock($jar, 100.0);
        $this->stock($fg, 0.0);

        self::assertSame('outofstock', $this->recipeStatus($fg), 'a blocked material makes the recipe unexecutable');

        $this->actingAs($this->operator());
        $r = $this->runOrderPath($this->orderFor($fg));

        self::assertSame(OrderStatus::AwaitingStock->value, $r['status']);
        self::assertSame(0.0, $r['reserved']);
    }

    // ── §11 — automatic recovery when the raw material becomes available ────────

    public function test_raw_material_arrival_recovers_the_awaiting_stock_order(): void
    {
        $fg = $this->finishedGood();
        $honey = $this->rawMaterial(allowNegative: false);
        $jar = $this->rawMaterial(allowNegative: false);

        $recipe = $this->recipeFor($fg);
        $this->addComponent($recipe, $honey, 0.25);
        $this->addComponent($recipe, $jar, 1.0);

        $honeyItem = $this->stock($honey, 0.0);
        $this->stock($jar, 100.0);
        $this->stock($fg, 0.0);

        $this->actingAs($this->operator());
        $order = $this->orderFor($fg);
        $r = $this->runOrderPath($order);
        self::assertSame(OrderStatus::AwaitingStock->value, $r['status'], 'precondition: blocked → Awaiting Stock');

        // Honey arrives. No operator action.
        $honeyItem->update(['on_hand_qty' => 100.0]);
        $this->listener()->handleStockReceived(new InventoryStockReceived(
            inventoryItemId: $honeyItem->id,
            warehouseId: $this->warehouse->id,
            productId: $honey->id,
            companyId: $this->company->id,
            quantityReceived: 100.0,
            onHandBefore: 0.0,
            onHandAfter: 100.0,
            inventoryClass: InventoryClass::RawMaterial,
            unitCost: 0.0,
        ));

        $order->refresh();
        self::assertNotSame(OrderStatus::AwaitingStock, $order->status, 'automatic recovery, no manual status change');
        self::assertSame(ReservationStatus::Reserved, $order->reservation_status);
    }

    // ── §12 — automatic recovery when allow_negative_stock flips false → true ───

    public function test_allow_negative_policy_flip_recovers_the_awaiting_stock_order(): void
    {
        $fg = $this->finishedGood();
        $honey = $this->rawMaterial(allowNegative: false);
        $jar = $this->rawMaterial(allowNegative: false);

        $recipe = $this->recipeFor($fg);
        $this->addComponent($recipe, $honey, 0.25);
        $this->addComponent($recipe, $jar, 1.0);

        $this->stock($honey, 0.0);
        $this->stock($jar, 100.0);
        $this->stock($fg, 0.0);

        $this->actingAs($this->operator());
        $order = $this->orderFor($fg);
        self::assertSame(OrderStatus::AwaitingStock->value, $this->runOrderPath($order)['status']);

        // Policy change: Honey may now go negative → the recipe becomes executable.
        $honey->update(['allow_negative_stock' => true]);
        $this->listener()->handleNegativeStockEnabled(
            new ProductNegativeStockEnabled($honey->id, $this->company->id),
        );

        $order->refresh();
        self::assertNotSame(OrderStatus::AwaitingStock, $order->status, 'policy-flip recovery, no manual status change');
        self::assertSame(ReservationStatus::Reserved, $order->reservation_status);
    }

    // ── Wiring: the observer publishes the policy event only on false → true ─────

    public function test_turning_allow_negative_on_publishes_the_recovery_event(): void
    {
        $rm = $this->rawMaterial(allowNegative: false);

        Event::fake([ProductNegativeStockEnabled::class]);
        $rm->update(['allow_negative_stock' => true]);

        Event::assertDispatched(
            ProductNegativeStockEnabled::class,
            fn (ProductNegativeStockEnabled $e): bool => $e->productId === $rm->id && $e->companyId === $this->company->id,
        );
    }

    public function test_turning_allow_negative_off_publishes_nothing(): void
    {
        $rm = $this->rawMaterial(allowNegative: true);

        Event::fake([ProductNegativeStockEnabled::class]);
        $rm->update(['allow_negative_stock' => false]);

        Event::assertNotDispatched(ProductNegativeStockEnabled::class);
    }

    public function test_the_policy_recovery_listener_is_subscribed(): void
    {
        self::assertNotEmpty(
            app('events')->getListeners(ProductNegativeStockEnabled::class),
            'ProductNegativeStockEnabled has no recovery subscriber.',
        );
    }

    // ── §6C — can_manufacture is NOT a recovery trigger ─────────────────────────

    public function test_changing_can_manufacture_publishes_no_recovery_event(): void
    {
        $fg = $this->finishedGood();

        Event::fake([ProductNegativeStockEnabled::class]);
        $fg->update(['can_manufacture' => true]);

        Event::assertNotDispatched(
            ProductNegativeStockEnabled::class,
            'can_manufacture is no longer a fulfillability gate and must drive no recovery event.',
        );
    }
}

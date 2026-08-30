<?php

declare(strict_types=1);

namespace Tests\Feature\Manufacturing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\OrderLine;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Modules\Inventory\InventoryItems\Domain\Models\InventoryItem;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Manufacturing\BillsOfMaterials\Domain\Models\Recipe;
use Modules\Manufacturing\BillsOfMaterials\Domain\Services\ManufacturingAvailabilityService;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Brands\Domain\Models\Brand;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-GOLIVE-RECIPE-TO-ORDER-AVAILABILITY-E2E-001
 *
 * Proves - or disproves - the chain:
 *   Recipe availability -> Finished Product availability -> Order reservation
 *   -> Order status
 *
 * Each scenario asserts BOTH sides in the same test:
 *   1. what ManufacturingAvailabilityService says about the recipe, and
 *   2. what the real V3 order path actually does.
 *
 * That pairing is the whole point: it is the only way to show whether recipe
 * availability actually participates in the reservation decision.
 *
 * Originally evidence-gathering. Since TASK-GOLIVE-RECIPE-GATE-TENANT-REPAIR-001
 * (F4 + Option B) the previously observation-only scenarios - B, E and TENANT -
 * assert the corrected behaviour, so this suite is now a regression guard: it
 * fails if the recipe gate or the company boundary is ever removed.
 */
final class RecipeToOrderAvailabilityE2ETest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Warehouse $warehouse;

    private Customer $customer;

    /** Brand A - the ownership link Product -> Brand -> Company (ADR-013). */
    private Brand $brand;

    /** @var array<string, string> */
    public static array $evidence = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
        $this->customer = Customer::factory()->create();
        $this->brand = Brand::factory()->create(['company_id' => $this->company->id]);
    }

    // -- Fixture -------------------------------------------------------------

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

    private function recipeFor(Product $fg): Recipe
    {
        return Recipe::create([
            'bom_number' => 'BOM-E2E-'.uniqid(),
            'product_id' => $fg->id,
            'version' => '1.0',
            'bom_version_number' => 1,
            'is_active' => true,
        ]);
    }

    private function addComponent(Recipe $recipe, Product $rm, float $qty): void
    {
        $recipe->components()->create([
            'raw_material_id' => $rm->id,
            'quantity' => $qty,
        ]);
    }

    private function stock(Product $product, float $onHand, ?Company $company = null, ?Warehouse $warehouse = null): void
    {
        InventoryItem::query()->create([
            'warehouse_id' => ($warehouse ?? $this->warehouse)->id,
            'product_id' => $product->id,
            'company_id' => ($company ?? $this->company)->id,
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
            ['slug' => 'test-recipe-e2e-operator'],
            ['name' => 'Test Recipe E2E Operator', 'is_system' => false],
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

    /** Recipe availability as the platform computes it. */
    private function recipeStatus(Product $fg): string
    {
        return (string) app(ManufacturingAvailabilityService::class)->evaluate($fg->fresh())['status'];
    }

    /**
     * Drive the REAL V3 path: HTTP -> controller -> FulfillmentEngine ->
     * MoveToPreparationWorkflow -> ReserveOrderInventoryAction -> persistence.
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

    private function record(string $scenario, string $recipe, array $r): void
    {
        self::$evidence[$scenario] = sprintf(
            'recipe=%s | http=%d | order=%s | reservation=%s | reserved_qty=%.2f',
            $recipe, $r['http'], $r['status'], $r['reservation'] ?? 'null', $r['reserved'],
        );
    }

    // == TEST A - recipe fully suppliable =====================================

    public function test_a_recipe_available_order_proceeds(): void
    {
        $fg = $this->finishedGood();
        $rmA = $this->rawMaterial(allowNegative: false);
        $rmB = $this->rawMaterial(allowNegative: false);

        $recipe = $this->recipeFor($fg);
        $this->addComponent($recipe, $rmA, 2.0);
        $this->addComponent($recipe, $rmB, 3.0);

        $this->stock($rmA, 10.0);
        $this->stock($rmB, 10.0);
        $this->stock($fg, 0.0);

        $recipeStatus = $this->recipeStatus($fg);
        self::assertSame('instock', $recipeStatus, 'All components suppliable => recipe instock.');

        $this->actingAs($this->operator());
        $r = $this->runOrderPath($this->orderFor($fg));
        $this->record('A', $recipeStatus, $r);

        self::assertSame(200, $r['http'], 'Available recipe must let the order proceed.');
        self::assertSame(OrderStatus::ReadyForDispatch->value, $r['status']);
    }

    // == TEST B - CRITICAL: one component short, allow_negative OFF ===========

    public function test_b_recipe_outofstock_offnegative_component(): void
    {
        $fg = $this->finishedGood();
        $rmA = $this->rawMaterial(allowNegative: false);
        $rmB = $this->rawMaterial(allowNegative: false);

        $recipe = $this->recipeFor($fg);
        $this->addComponent($recipe, $rmA, 2.0);
        $this->addComponent($recipe, $rmB, 3.0);

        $this->stock($rmA, 10.0);
        $this->stock($rmB, 0.0);   // short, and cannot go negative
        $this->stock($fg, 0.0);

        $recipeStatus = $this->recipeStatus($fg);
        self::assertSame('outofstock', $recipeStatus, 'One non-negative shortage must block the recipe.');

        $this->actingAs($this->operator());
        $r = $this->runOrderPath($this->orderFor($fg));
        $this->record('B', $recipeStatus, $r);

        // Option B (TASK-GOLIVE-RECIPE-GATE-TENANT-REPAIR-001): the pre-repair baseline
        // here was `ready_for_dispatch` with reserved_qty=1.00. It must now invert.
        self::assertSame(
            OrderStatus::AwaitingStock->value,
            $r['status'],
            'An unexecutable recipe must divert the order to Awaiting Stock.',
        );
        self::assertSame(0.0, $r['reserved'], 'No quantity may be reserved against an unexecutable recipe.');
    }

    // == TEST C - shortage permitted by allow_negative_stock ==================

    public function test_c_shortage_permitted_by_allow_negative(): void
    {
        $fg = $this->finishedGood();
        $rmA = $this->rawMaterial(allowNegative: false);
        $rmB = $this->rawMaterial(allowNegative: true);

        $recipe = $this->recipeFor($fg);
        $this->addComponent($recipe, $rmA, 2.0);
        $this->addComponent($recipe, $rmB, 3.0);

        $this->stock($rmA, 10.0);
        $this->stock($rmB, 0.0);
        $this->stock($fg, 0.0);

        $recipeStatus = $this->recipeStatus($fg);
        self::assertSame('instock', $recipeStatus, 'allow_negative_stock keeps the recipe executable.');

        $this->actingAs($this->operator());
        $r = $this->runOrderPath($this->orderFor($fg));
        $this->record('C', $recipeStatus, $r);

        self::assertSame(200, $r['http']);
    }

    // == TEST D - mixed policies, evaluated per component =====================

    public function test_d1_mixed_shortage_allowed_component(): void
    {
        $fg = $this->finishedGood();
        $rmA = $this->rawMaterial(allowNegative: false);
        $rmB = $this->rawMaterial(allowNegative: true);

        $recipe = $this->recipeFor($fg);
        $this->addComponent($recipe, $rmA, 2.0);
        $this->addComponent($recipe, $rmB, 3.0);

        $this->stock($rmA, 10.0);
        $this->stock($rmB, 0.0);

        self::assertSame('instock', $this->recipeStatus($fg));
    }

    public function test_d2_mixed_shortage_blocked_component(): void
    {
        $fg = $this->finishedGood();
        $rmA = $this->rawMaterial(allowNegative: true);
        $rmB = $this->rawMaterial(allowNegative: false);

        $recipe = $this->recipeFor($fg);
        $this->addComponent($recipe, $rmA, 2.0);
        $this->addComponent($recipe, $rmB, 3.0);

        $this->stock($rmA, 10.0);
        $this->stock($rmB, 0.0);   // the single blocking component

        self::assertSame(
            'outofstock',
            $this->recipeStatus($fg),
            'A single non-negative shortage must block the whole recipe.',
        );
    }

    // == TEST E - DECISIVE: does can_manufacture bypass the recipe? ===========

    public function test_e_can_manufacture_precedence_over_unavailable_recipe(): void
    {
        $fg = $this->finishedGood();          // can_manufacture = true
        $rm = $this->rawMaterial(allowNegative: false);

        $recipe = $this->recipeFor($fg);
        $this->addComponent($recipe, $rm, 5.0);

        $this->stock($rm, 0.0);               // recipe cannot be supplied
        $this->stock($fg, 0.0);               // no finished-good stock either

        $recipeStatus = $this->recipeStatus($fg);
        self::assertSame('outofstock', $recipeStatus);

        $this->actingAs($this->operator());
        $r = $this->runOrderPath($this->orderFor($fg));
        $this->record('E', $recipeStatus, $r);

        // Option B: can_manufacture no longer takes precedence over an unexecutable recipe.
        // Pre-repair baseline was `ready_for_dispatch` with reserved_qty=1.00.
        self::assertSame(
            OrderStatus::AwaitingStock->value,
            $r['status'],
            'can_manufacture must not bypass an unexecutable recipe.',
        );
        self::assertSame(0.0, $r['reserved'], 'The manufacturing commitment must be withheld.');
    }

    // == TEST F - NEW CONTRACT: can_manufacture is no longer a fulfillability gate ==
    // TASK-ORDER-PREPARATION-FULFILLABILITY-CONTRACT-001 / ADR-027 §16 v1.5.
    // The suite's other scenarios build the FG with ->manufacturable() (can_manufacture
    // = true). This one inverts the premise: a NON-manufacturable finished good with an
    // executable recipe must now reserve, because ECOS is order-driven preparation and
    // the capability flag no longer gates order fulfilment.

    public function test_f_can_manufacture_false_with_executable_recipe_reserves(): void
    {
        $fg = Product::factory()->finishedGood()->create(['brand_id' => $this->brand->id]);
        self::assertFalse((bool) $fg->can_manufacture, 'fixture guard: FG is not manufacturable');

        $rm = $this->rawMaterial(allowNegative: false);
        $recipe = $this->recipeFor($fg);
        $this->addComponent($recipe, $rm, 2.0);

        $this->stock($rm, 10.0);   // recipe fully suppliable
        $this->stock($fg, 0.0);    // no finished-good stock

        $recipeStatus = $this->recipeStatus($fg);
        self::assertSame('instock', $recipeStatus);

        $this->actingAs($this->operator());
        $r = $this->runOrderPath($this->orderFor($fg));
        $this->record('F', $recipeStatus, $r);

        self::assertSame(
            OrderStatus::ReadyForDispatch->value,
            $r['status'],
            'An executable recipe makes the order fulfillable even when can_manufacture=false.',
        );
        self::assertSame(1.0, $r['reserved'], 'The finished product is fulfillable via its preparation recipe.');
    }

    // == Tenant isolation =====================================================

    public function test_tenant_isolation_other_company_stock_does_not_satisfy_recipe(): void
    {
        $fg = $this->finishedGood();
        $rm = $this->rawMaterial(allowNegative: false);

        $recipe = $this->recipeFor($fg);
        $this->addComponent($recipe, $rm, 5.0);

        // Company B holds plenty; Company A holds none.
        $companyB = Company::factory()->create();
        $warehouseB = Warehouse::factory()->create(['company_id' => $companyB->id]);
        $this->stock($rm, 100.0, company: $companyB, warehouse: $warehouseB);
        $this->stock($rm, 0.0);

        $status = $this->recipeStatus($fg);
        self::$evidence['TENANT'] = 'recipe='.$status.' (companyA=0, companyB=100)';

        // F4: the pre-repair baseline here was `instock` — the tenant leak.
        self::assertSame(
            'outofstock',
            $status,
            'F4: another company\'s inventory must not satisfy this company\'s recipe.',
        );
    }

    // == FIXTURE OWNERSHIP PROOF (Part 4) =====================================

    public function test_fixture_ownership_chain_is_single_tenant(): void
    {
        $fg = $this->finishedGood();
        $rmA = $this->rawMaterial(allowNegative: false);
        $rmB = $this->rawMaterial(allowNegative: true);

        $recipe = $this->recipeFor($fg);
        $this->addComponent($recipe, $rmA, 2.0);
        $this->addComponent($recipe, $rmB, 3.0);
        $this->stock($rmA, 10.0);
        $this->stock($rmB, 10.0);

        $expected = $this->company->id;

        // Finished product ownership: Product -> Brand -> Company (ADR-013).
        self::assertSame($expected, $fg->brand?->company_id, 'FG company');
        self::assertSame($expected, $this->warehouse->company_id, 'Warehouse company');

        // Every recipe component belongs to the same company as the finished good.
        foreach ($recipe->components as $line) {
            self::assertSame(
                $fg->brand?->company_id,
                $line->component?->brand?->company_id,
                'BOM component company must equal finished-good company',
            );
        }

        // Every inventory row for those components belongs to the same company.
        foreach ([$rmA, $rmB] as $rm) {
            foreach (InventoryItem::query()->where('product_id', $rm->id)->get() as $item) {
                self::assertSame($expected, $item->company_id, 'InventoryItem company');
            }
        }

        self::$evidence['OWNERSHIP'] = sprintf(
            'fg=%s | rmA=%s | rmB=%s | warehouse=%s | expected=%s',
            $fg->brand?->company_id, $rmA->brand?->company_id, $rmB->brand?->company_id,
            $this->warehouse->company_id, $expected,
        );
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$evidence !== []) {
            fwrite(STDERR, "\n===== RECIPE->ORDER E2E EVIDENCE =====\n");
            foreach (self::$evidence as $k => $v) {
                fwrite(STDERR, "  {$k}: {$v}\n");
            }
            fwrite(STDERR, "======================================\n");
        }
        parent::tearDownAfterClass();
    }
}

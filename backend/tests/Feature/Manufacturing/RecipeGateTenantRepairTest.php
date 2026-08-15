<?php

declare(strict_types=1);

namespace Tests\Feature\Manufacturing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
 * TASK-GOLIVE-RECIPE-GATE-TENANT-REPAIR-001
 *
 * Certifies the two approved corrections:
 *
 *   F4       - Recipe availability is COMPANY-scoped (never Brand-scoped).
 *   OPTION B - can_manufacture may no longer bypass an unexecutable Recipe.
 *
 * Ownership source is exactly ADR-013: Product -> Brand -> Company.
 * Every business assertion here is database-backed; nothing is inferred.
 */
final class RecipeGateTenantRepairTest extends TestCase
{
    use RefreshDatabase;

    private Company $companyA;

    private Warehouse $warehouseA;

    private Brand $brandA;

    private Customer $customer;

    /** @var array<string, string> */
    public static array $evidence = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->companyA = Company::factory()->create();
        $this->warehouseA = Warehouse::factory()->create(['company_id' => $this->companyA->id]);
        $this->brandA = Brand::factory()->create(['company_id' => $this->companyA->id]);
        $this->customer = Customer::factory()->create();
    }

    // == Fixture =============================================================

    private function finishedGood(?Brand $brand = null): Product
    {
        return Product::factory()->finishedGood()->manufacturable()
            ->create(['brand_id' => ($brand ?? $this->brandA)->id]);
    }

    private function rawMaterial(bool $allowNegative = false, ?Brand $brand = null): Product
    {
        return Product::factory()->rawMaterial()->create([
            'brand_id' => ($brand ?? $this->brandA)->id,
            'is_active' => true,
            'allow_negative_stock' => $allowNegative,
        ]);
    }

    /** @param  array<int, Product>  $components */
    private function recipeFor(Product $fg, array $components, float $qty = 2.0): Recipe
    {
        $recipe = Recipe::create([
            'bom_number' => 'BOM-GATE-'.uniqid(),
            'product_id' => $fg->id,
            'version' => '1.0',
            'bom_version_number' => 1,
            'is_active' => true,
        ]);

        foreach ($components as $component) {
            $recipe->components()->create([
                'raw_material_id' => $component->id,
                'quantity' => $qty,
            ]);
        }

        return $recipe;
    }

    private function stock(Product $product, float $onHand, ?Company $company = null, ?Warehouse $warehouse = null): InventoryItem
    {
        return InventoryItem::query()->create([
            'warehouse_id' => ($warehouse ?? $this->warehouseA)->id,
            'product_id' => $product->id,
            'company_id' => ($company ?? $this->companyA)->id,
            'on_hand_qty' => $onHand,
            'reserved_qty' => 0,
        ]);
    }

    private function recipeStatus(Product $fg): string
    {
        return (string) app(ManufacturingAvailabilityService::class)->evaluate($fg->fresh())['status'];
    }

    private function orderFor(Product $fg, float $qty = 1.0): Order
    {
        $order = Order::query()->create([
            'company_id' => $this->companyA->id,
            'assigned_warehouse_id' => $this->warehouseA->id,
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
        $user = User::factory()->create(['company_id' => $this->companyA->id]);

        $role = Role::firstOrCreate(
            ['slug' => 'test-recipe-gate-operator'],
            ['name' => 'Test Recipe Gate Operator', 'is_system' => false],
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

    /**
     * Drives the REAL V3 path:
     * HTTP -> FulfillmentController -> FulfillmentEngine -> workflow
     *      -> ReserveOrderInventoryAction -> persistence.
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

    // == PART 6 - F4 company isolation =======================================

    public function test_part6_other_company_stock_cannot_satisfy_this_company_recipe(): void
    {
        $fg = $this->finishedGood();
        $rm = $this->rawMaterial(allowNegative: false);
        $this->recipeFor($fg, [$rm]);

        // Company A holds none of RM.
        $this->stock($rm, 0.0);

        // Company B holds plenty of the SAME product.
        $companyB = Company::factory()->create();
        $warehouseB = Warehouse::factory()->create(['company_id' => $companyB->id]);
        $this->stock($rm, 100.0, company: $companyB, warehouse: $warehouseB);

        $status = $this->recipeStatus($fg);
        self::$evidence['F4_FORWARD'] = sprintf(
            'companyA=0 companyB=100 -> recipe=%s (fg_company=%s)',
            $status, $fg->brand?->company_id,
        );

        self::assertSame(
            'outofstock',
            $status,
            'F4: Company B inventory must NOT make Company A recipe executable.',
        );
    }

    // == PART 7 - reverse direction ==========================================

    public function test_part7_reverse_company_a_stock_cannot_satisfy_company_b_recipe(): void
    {
        $companyB = Company::factory()->create();
        $warehouseB = Warehouse::factory()->create(['company_id' => $companyB->id]);
        $brandB = Brand::factory()->create(['company_id' => $companyB->id]);

        $fgB = $this->finishedGood($brandB);
        $rmB = $this->rawMaterial(allowNegative: false, brand: $brandB);
        $this->recipeFor($fgB, [$rmB]);

        // Company B holds none; Company A holds plenty of the same product.
        $this->stock($rmB, 0.0, company: $companyB, warehouse: $warehouseB);
        $this->stock($rmB, 100.0);

        $status = $this->recipeStatus($fgB);
        self::$evidence['F4_REVERSE'] = sprintf(
            'companyB=0 companyA=100 -> recipe=%s (fg_company=%s)',
            $status, $fgB->brand?->company_id,
        );

        self::assertSame(
            'outofstock',
            $status,
            'F4 reverse: Company A inventory must NOT make Company B recipe executable.',
        );
    }

    // == PART 8 - own-company availability is NOT over-restricted ============

    public function test_part8_own_company_stock_makes_recipe_executable(): void
    {
        $fg = $this->finishedGood();
        $rm = $this->rawMaterial(allowNegative: false);
        $this->recipeFor($fg, [$rm]);

        $this->stock($rm, 50.0);                       // Company A - sufficient

        $companyB = Company::factory()->create();
        $warehouseB = Warehouse::factory()->create(['company_id' => $companyB->id]);
        $otherRm = $this->rawMaterial(allowNegative: false);
        $this->stock($otherRm, 999.0, company: $companyB, warehouse: $warehouseB);

        self::assertSame(
            'instock',
            $this->recipeStatus($fg),
            'F4 must not over-restrict: own-company stock must still satisfy the recipe.',
        );
    }

    public function test_part8_fail_closed_when_finished_good_has_no_company(): void
    {
        // ADR-013 makes Product -> Brand -> Company the authoritative ownership source,
        // and products.brand_id is nullable, so "no derivable company" is a reachable
        // state. The brand is detached AFTER creation because ProductFactory derives the
        // legacy products.company_id column from the brand and that column is NOT NULL.
        $fg = $this->finishedGood();
        $fg->update(['brand_id' => null]);
        $fg = $fg->fresh();

        $rm = $this->rawMaterial(allowNegative: false);
        $this->recipeFor($fg, [$rm]);

        // Stock exists and is plentiful - but it belongs to a company this product
        // can no longer be shown to belong to.
        $this->stock($rm, 100.0);

        self::assertNull($fg->brand?->company_id, 'Fixture must have no derivable company.');
        self::assertSame(
            'outofstock',
            $this->recipeStatus($fg),
            'A null company must FAIL CLOSED, never fall back to the global inventory pool.',
        );
    }

    // == PART 10 / 19 - multi-material negative-stock policy ==================

    /**
     * Three required materials, six policy combinations.
     * Rule under certification: a material passes when
     *   available > 0 OR allow_negative_stock = true.
     * A recipe is executable only if EVERY required material passes.
     */
    public function test_part19_multi_material_policy_matrix(): void
    {
        $cases = [
            // [ [onHand, allowNegative] x3, expected ]
            'all_available' => [[[10, false], [10, false], [10, false]], 'instock'],
            'one_short_off' => [[[10, false], [0, false], [10, false]], 'outofstock'],
            'one_short_on' => [[[10, false], [0, true], [10, false]], 'instock'],
            'two_short_on' => [[[10, false], [0, true], [0, true]], 'instock'],
            'one_on_one_off' => [[[10, false], [0, true], [0, false]], 'outofstock'],
            'all_short_off' => [[[0, false], [0, false], [0, false]], 'outofstock'],
        ];

        foreach ($cases as $label => [$spec, $expected]) {
            $fg = $this->finishedGood();
            $materials = [];

            foreach ($spec as [$onHand, $allowNegative]) {
                $rm = $this->rawMaterial(allowNegative: (bool) $allowNegative);
                $this->stock($rm, (float) $onHand);
                $materials[] = $rm;
            }

            $this->recipeFor($fg, $materials);

            self::assertSame(
                $expected,
                $this->recipeStatus($fg),
                "Multi-material case '{$label}' must evaluate {$expected}.",
            );
        }

        self::$evidence['MATRIX'] = '6/6 multi-material policy combinations behaved as specified';
    }

    // == PART 14 - direct finished-good stock must NOT be gated ==============

    public function test_part14_direct_finished_good_stock_bypasses_the_recipe_gate(): void
    {
        $fg = $this->finishedGood();                   // can_manufacture = true
        $rm = $this->rawMaterial(allowNegative: false);
        $this->recipeFor($fg, [$rm]);

        $this->stock($rm, 0.0);                        // recipe is NOT executable
        $this->stock($fg, 10.0);                       // but FG is physically in stock

        self::assertSame('outofstock', $this->recipeStatus($fg));

        $this->actingAs($this->operator());
        $r = $this->runOrderPath($this->orderFor($fg, qty: 1.0));
        self::$evidence['DIRECT_FG'] = sprintf(
            'recipe=outofstock fg_stock=10 -> order=%s reserved=%.2f',
            $r['status'], $r['reserved'],
        );

        self::assertSame(200, $r['http']);
        self::assertSame(
            OrderStatus::ReadyForDispatch->value,
            $r['status'],
            'Direct FG stock must fulfil the order; the recipe gate must not block it.',
        );
        self::assertSame(1.0, $r['reserved'], 'The line must be fully reserved from physical FG stock.');
    }

    // == PART 15 / 17 - CORE OPTION B ========================================

    public function test_part15_unexecutable_recipe_blocks_manufacturing_reservation(): void
    {
        $fg = $this->finishedGood();                   // can_manufacture = true
        $rm = $this->rawMaterial(allowNegative: false);
        $this->recipeFor($fg, [$rm]);

        $this->stock($rm, 0.0);                        // recipe unexecutable
        $fgItem = $this->stock($fg, 0.0);              // no finished-good stock

        self::assertSame('outofstock', $this->recipeStatus($fg));

        $this->actingAs($this->operator());
        $order = $this->orderFor($fg, qty: 1.0);
        $r = $this->runOrderPath($order);

        self::$evidence['OPTION_B'] = sprintf(
            'recipe=outofstock fg_stock=0 -> order=%s reservation=%s reserved=%.2f',
            $r['status'], $r['reservation'] ?? 'null', $r['reserved'],
        );

        // -- Order state (Part 18) -------------------------------------------
        self::assertSame(
            OrderStatus::AwaitingStock->value,
            $r['status'],
            'can_manufacture must no longer bypass an unexecutable recipe.',
        );
        self::assertSame('awaiting_stock', $r['reservation'], 'Reservation status must be awaiting_stock.');

        // -- No phantom reservation (Part 17) --------------------------------
        self::assertSame(0.0, $r['reserved'], 'Order line reserved_qty must be 0.');
        self::assertSame(0.0, (float) $fgItem->fresh()->reserved_qty, 'FG inventory must hold no reservation.');
        self::assertSame(0.0, (float) $fgItem->fresh()->on_hand_qty, 'FG on-hand must be untouched.');

        $rmItem = InventoryItem::query()->where('product_id', $rm->id)->firstOrFail();
        self::assertSame(0.0, (float) $rmItem->reserved_qty, 'Raw material must not be reserved by an order.');
        self::assertSame(0.0, (float) $rmItem->on_hand_qty, 'Raw material on-hand must be untouched.');

        // No ledger mutation, no FIFO consumption, no partial transaction.
        self::assertSame(
            0,
            DB::table('stock_ledger_entries')->whereIn('product_id', [$fg->id, $rm->id])->count(),
            'A refused reservation must write no stock ledger entry.',
        );
        self::assertSame(
            0,
            DB::table('inventory_layer_consumptions')->count(),
            'A refused reservation must consume no FIFO layer.',
        );
    }

    // == PART 16 - negative stock keeps the recipe executable ================

    public function test_part16_allow_negative_material_keeps_reservation_alive(): void
    {
        $fg = $this->finishedGood();
        $rm = $this->rawMaterial(allowNegative: true);
        $this->recipeFor($fg, [$rm]);

        $this->stock($rm, 0.0);                        // zero, but negative stock is permitted
        $this->stock($fg, 0.0);

        self::assertSame('instock', $this->recipeStatus($fg));

        $this->actingAs($this->operator());
        $r = $this->runOrderPath($this->orderFor($fg, qty: 1.0));

        self::$evidence['NEG_STOCK'] = sprintf(
            'recipe=instock(allow_negative) -> order=%s reserved=%.2f',
            $r['status'], $r['reserved'],
        );

        self::assertSame(200, $r['http']);
        self::assertSame(
            OrderStatus::ReadyForDispatch->value,
            $r['status'],
            'allow_negative_stock must keep the existing manufacturing commitment path alive.',
        );
        self::assertSame(1.0, $r['reserved'], 'Manufacturing commitment must reserve the full quantity.');
    }

    // == PART 13 - recipe_missing must NOT block =============================

    public function test_part13_recipe_missing_does_not_block_reservation(): void
    {
        $fg = $this->finishedGood();                   // can_manufacture = true, NO recipe
        $this->stock($fg, 0.0);

        self::assertSame(
            'recipe_missing',
            $this->recipeStatus($fg),
            'A finished good with no active recipe evaluates recipe_missing.',
        );

        $this->actingAs($this->operator());
        $r = $this->runOrderPath($this->orderFor($fg, qty: 1.0));

        self::$evidence['RECIPE_MISSING'] = sprintf(
            'recipe=recipe_missing fg_stock=0 -> order=%s reserved=%.2f',
            $r['status'], $r['reserved'],
        );

        self::assertSame(
            OrderStatus::ReadyForDispatch->value,
            $r['status'],
            'recipe_missing must NOT be treated as outofstock by the new gate.',
        );
    }

    // == PART 20 / 21 - cross-brand reuse survives F4 ========================

    public function test_part20_cross_brand_reuse_survives_company_scoping(): void
    {
        $brandB = Brand::factory()->create(['company_id' => $this->companyA->id]);
        $brandC = Brand::factory()->create(['company_id' => $this->companyA->id]);

        // ONE raw material, owned by Brand A.
        $rawX = $this->rawMaterial(allowNegative: false);
        $this->stock($rawX, 100.0);

        $fgA = $this->finishedGood($this->brandA);
        $fgB = $this->finishedGood($brandB);
        $fgC = $this->finishedGood($brandC);

        $this->recipeFor($fgA, [$rawX]);
        $this->recipeFor($fgB, [$rawX]);
        $this->recipeFor($fgC, [$rawX]);

        $a = $this->recipeStatus($fgA);
        $b = $this->recipeStatus($fgB);
        $c = $this->recipeStatus($fgC);

        self::$evidence['CROSS_BRAND'] = sprintf(
            'one raw material (brand=%s) -> A=%s B=%s C=%s',
            $rawX->brand_id, $a, $b, $c,
        );

        self::assertNotSame($fgB->brand_id, $rawX->brand_id, 'Cross-brand condition must be genuine.');
        self::assertSame('instock', $a, 'Brand A recipe must remain executable.');
        self::assertSame('instock', $b, 'Brand B recipe must remain executable - F4 is COMPANY-level.');
        self::assertSame('instock', $c, 'Brand C recipe must remain executable - F4 is COMPANY-level.');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$evidence !== []) {
            fwrite(STDERR, "\n===== RECIPE GATE + TENANT REPAIR EVIDENCE =====\n");
            foreach (self::$evidence as $k => $v) {
                fwrite(STDERR, "  {$k}: {$v}\n");
            }
            fwrite(STDERR, "===============================================\n");
        }
        parent::tearDownAfterClass();
    }
}

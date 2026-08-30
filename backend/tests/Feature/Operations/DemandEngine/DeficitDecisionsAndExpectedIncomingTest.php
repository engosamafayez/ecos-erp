<?php

declare(strict_types=1);

namespace Tests\Feature\Operations\DemandEngine;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Operations\DemandAnalysis\Application\Services\DemandReadRepository;
use Modules\Operations\DemandAnalysis\Application\Services\MaterialDemandCalculator;
use Modules\Operations\DemandAnalysis\Application\Services\ProductReadinessCalculator;
use Modules\Operations\Preparation\Domain\Enums\WaveStatus;
use Modules\Operations\Preparation\Domain\Models\PreparationWave;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-PREPARATION-OPERATIONS-UX-002 — Expected Incoming, Deficit Decisions, and
 * "Continue Process Despite Shortage".
 *
 * Proves the owner contract: Missing Material and Preparation Eligibility are separate;
 * Expected Incoming is PLANNING data that never touches inventory or the ledger and never
 * reduces the real missing_qty; a fully-covered shortage is not a decision candidate; the
 * "continue" decision records the product as NOT FULLY FULFILLED (prepared_qty < required)
 * without deleting the line, inventing a status, or mutating the order total.
 */
final class DeficitDecisionsAndExpectedIncomingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Warehouse $warehouse;

    private Customer $customer;

    private string $categoryId;

    private string $unitId;

    private DemandReadRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
        $this->customer = Customer::factory()->create();
        $this->categoryId = (string) \Modules\MasterData\Categories\Domain\Models\Category::factory()->create()->id;
        $this->unitId = (string) \Modules\MasterData\Units\Domain\Models\Unit::factory()->create()->id;
        $this->repo = app(DemandReadRepository::class);
    }

    // ── Fixture ──────────────────────────────────────────────────────────────

    private function wave(): PreparationWave
    {
        return PreparationWave::create([
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouse->id,
            'wave_number' => 'PREP-UX-'.random_int(1, 99999),
            'planning_date' => today()->toDateString(),
            'status' => WaveStatus::Collecting->value,
            'orders_count' => 0, 'products_count' => 0, 'lines_count' => 0,
            'total_units_required' => 0, 'total_units_prepared' => 0,
            'shortage_detected' => false, 'wave_type' => 'engine',
            'created_by' => 'test', 'updated_by' => 'test',
        ]);
    }

    private function product(string $name, string $type, bool $allowNegative = false): string
    {
        $id = (string) Str::uuid();
        DB::table('products')->insert([
            'id' => $id, 'company_id' => $this->company->id,
            'category_id' => $this->categoryId, 'unit_id' => $this->unitId,
            'name' => $name, 'sku' => 'SKU-'.random_int(100000, 999999),
            'product_type' => $type, 'allow_negative_stock' => $allowNegative, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /** @param array<string, float> $ingredients */
    private function bom(string $productId, array $ingredients): void
    {
        $bomId = (string) Str::uuid();
        DB::table('bills_of_materials')->insert([
            'id' => $bomId, 'product_id' => $productId, 'bom_number' => 'BOM-'.random_int(100000, 999999),
            'version' => 1, 'bom_version_number' => 1, 'is_active' => true, 'yield_quantity' => 1, 'recipe_cost' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($ingredients as $materialId => $qty) {
            DB::table('bill_of_material_lines')->insert([
                'id' => (string) Str::uuid(), 'bom_id' => $bomId, 'raw_material_id' => $materialId,
                'quantity' => $qty, 'waste_percentage' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function stock(string $productId, float $onHand): void
    {
        DB::table('inventory_items')->insert([
            'id' => (string) Str::uuid(), 'warehouse_id' => $this->warehouse->id,
            'product_id' => $productId, 'company_id' => $this->company->id,
            'on_hand_qty' => $onHand, 'reserved_qty' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function productDemand(PreparationWave $wave, string $productId, float $required): void
    {
        DB::table('wave_product_demand')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $wave->company_id, 'warehouse_id' => $wave->warehouse_id,
            'preparation_wave_id' => $wave->id, 'product_id' => $productId, 'product_name' => 'P '.$productId,
            'required_qty' => $required, 'prepared_qty' => 0, 'remaining_qty' => $required,
            'orders_count' => 1, 'completion_pct' => 0, 'last_calculated_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** An order in the wave demanding $qty of $productId, at $unitPrice. */
    private function orderInWave(PreparationWave $wave, string $productId, float $qty, float $unitPrice = 100.0): string
    {
        $orderId = (string) Str::uuid();
        DB::table('orders')->insert([
            'id' => $orderId, 'company_id' => $this->company->id, 'assigned_warehouse_id' => $this->warehouse->id,
            'customer_id' => $this->customer->id, 'customer_name' => 'Cust', 'order_number' => 'ORD-'.random_int(100000, 999999),
            'order_date' => today()->toDateString(), 'status' => 'in_progress', 'payment_status' => null,
            'subtotal' => $qty * $unitPrice, 'total' => $qty * $unitPrice,
            'shipping_total' => 0, 'discount_total' => 0, 'tax_total' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_lines')->insert([
            'id' => (string) Str::uuid(), 'order_id' => $orderId, 'product_id' => $productId,
            'quantity' => $qty, 'unit_price' => $unitPrice, 'line_total' => $qty * $unitPrice,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('preparation_wave_orders')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $this->company->id,
            'preparation_wave_id' => $wave->id, 'order_id' => $orderId,
            'order_number' => 'ORD-'.random_int(100000, 999999),
            'order_confirmed_at' => now(), 'added_by' => (string) Str::uuid(),
            'added_at' => now(),
            'postponed_at' => null, 'released_at' => null,
        ]);

        return $orderId;
    }

    /** An open, receivable purchase order line for $materialId (Expected Incoming source). */
    private function openPurchaseOrder(string $materialId, float $ordered, float $received = 0.0, string $status = 'approved', ?string $warehouseId = null): void
    {
        $supplierId = (string) Str::uuid();
        DB::table('suppliers')->insert([
            'id' => $supplierId, 'company_id' => $this->company->id, 'code' => 'SUP-'.random_int(1000, 9999),
            'name' => 'Supplier', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $poId = (string) Str::uuid();
        DB::table('purchase_orders')->insert([
            'id' => $poId, 'company_id' => $this->company->id, 'warehouse_id' => $warehouseId ?? $this->warehouse->id,
            'po_number' => 'PO-'.random_int(100000, 999999), 'supplier_id' => $supplierId,
            'order_date' => today()->toDateString(), 'status' => $status,
            'subtotal' => 0, 'total' => 0, 'grand_total' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('purchase_order_lines')->insert([
            'id' => (string) Str::uuid(), 'purchase_order_id' => $poId, 'product_id' => $materialId,
            'quantity' => $ordered, 'received_qty' => $received, 'unit_price' => 1, 'line_total' => $ordered,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function operator(): User
    {
        $user = User::factory()->create(['company_id' => $this->company->id]);
        $role = Role::firstOrCreate(['slug' => 'test-ux-operator'], ['name' => 'UX Op', 'is_system' => false]);
        $perm = Permission::firstOrCreate(['name' => 'operations.preparation.update'],
            ['module' => 'operations', 'resource' => 'preparation', 'action' => 'update']);
        if (! $role->permissions()->where('permissions.id', $perm->id)->exists()) {
            $role->permissions()->attach($perm->id);
        }
        $user->roles()->attach($role->id);
        $user->unsetRelation('roles');

        return $user;
    }

    /** Run the material + readiness projection exactly as the builder does. */
    private function project(PreparationWave $wave): void
    {
        $materials = app(MaterialDemandCalculator::class);
        $this->repo->upsertMaterialDemand($materials->calculate($wave));
        $this->repo->upsertProductReadiness($wave->id, app(ProductReadinessCalculator::class)->calculate($wave));
    }

    // ── C — Expected Incoming: missing 15, expected 10 → uncovered 5 ─────────

    public function test_c_expected_incoming_reduces_uncovered_but_not_missing(): void
    {
        $wave = $this->wave();
        $fg = $this->product('FG', 'finished_good');
        $rm = $this->product('RM', 'raw_material', allowNegative: false);
        $this->productDemand($wave, $fg, 15.0);
        $this->bom($fg, [$rm => 1.0]);
        $this->stock($rm, 0.0);
        $this->openPurchaseOrder($rm, ordered: 10.0); // Expected Incoming = 10

        $this->project($wave);
        // Missing materials are populated from missing_qty>0.
        DB::table('wave_missing_materials')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $wave->company_id, 'warehouse_id' => $wave->warehouse_id,
            'preparation_wave_id' => $wave->id, 'material_id' => $rm, 'material_name' => 'RM',
            'missing_qty' => 15.0, 'affected_orders_count' => 1, 'priority' => 'high',
            'last_calculated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->operator());
        $res = $this->getJson("/api/preparation/waves/{$wave->id}/missing-materials");
        $res->assertOk();
        $row = collect($res->json('data'))->firstWhere('material_id', $rm);

        self::assertNotNull($row);
        self::assertEqualsWithDelta(15.0, (float) $row['missing_qty'], 0.001, 'real physical shortage stays 15');
        self::assertEqualsWithDelta(10.0, (float) $row['expected_incoming_qty'], 0.001);
        self::assertEqualsWithDelta(5.0, (float) $row['uncovered_shortage_qty'], 0.001, '15 - 10 = 5 uncovered');
    }

    // ── D + E — Expected Incoming never touches inventory or the ledger ──────

    public function test_d_e_expected_incoming_never_mutates_inventory_or_ledger(): void
    {
        $wave = $this->wave();
        $fg = $this->product('FG', 'finished_good');
        $rm = $this->product('RM', 'raw_material');
        $this->productDemand($wave, $fg, 20.0);
        $this->bom($fg, [$rm => 1.0]);
        $this->stock($rm, 5.0);
        $this->openPurchaseOrder($rm, ordered: 30.0);
        $this->project($wave);

        $invBefore = DB::table('inventory_items')->where('product_id', $rm)->value('on_hand_qty');
        $ledgerBefore = DB::table('stock_ledger_entries')->count();

        $expected = app(\Modules\Purchasing\PurchaseOrders\Application\Queries\ExpectedIncomingQuery::class)
            ->forWarehouse((string) $this->company->id, (string) $this->warehouse->id, [$rm]);

        self::assertEqualsWithDelta(30.0, (float) ($expected[$rm] ?? 0), 0.001, 'expected incoming derived from open PO');
        self::assertEquals($invBefore, DB::table('inventory_items')->where('product_id', $rm)->value('on_hand_qty'),
            'querying expected incoming must not change on_hand');
        self::assertEquals($ledgerBefore, DB::table('stock_ledger_entries')->count(),
            'querying expected incoming must not create ledger entries');
    }

    // ── F — a fully expected-covered shortage is NOT a deficit candidate ─────

    public function test_f_fully_covered_shortage_is_not_a_deficit_candidate(): void
    {
        $wave = $this->wave();
        $fg = $this->product('FG', 'finished_good');
        $rm = $this->product('RM', 'raw_material', allowNegative: false);
        $this->productDemand($wave, $fg, 7.0);
        $this->bom($fg, [$rm => 1.0]);
        $this->stock($rm, 0.0);
        $this->openPurchaseOrder($rm, ordered: 7.0); // fully covers the 7 missing
        $this->orderInWave($wave, $fg, 7.0);
        $this->project($wave);

        $this->actingAs($this->operator());
        $res = $this->getJson("/api/preparation/waves/{$wave->id}/deficit-decisions");
        $res->assertOk();
        // TASK-PREPARATION-DEFICIT-DECISIONS-IMPACT-001 changed the GRAIN of this endpoint to
        // {materials, totals, orders}, one row per ORDER. The contract under test is unchanged:
        // a shortage fully covered by expected incoming is still not a decision candidate.
        self::assertSame([], $res->json('data.materials'), 'covered by an in-flight PO — not a candidate');
        self::assertSame([], $res->json('data.orders'));
    }

    // ── G — an uncovered shortage IS a deficit candidate ─────────────────────

    public function test_g_uncovered_shortage_appears_in_deficit_decisions(): void
    {
        $wave = $this->wave();
        $fg = $this->product('FG', 'finished_good');
        $rm = $this->product('RM', 'raw_material', allowNegative: false);
        $this->productDemand($wave, $fg, 7.0);
        $this->bom($fg, [$rm => 1.0]);
        $this->stock($rm, 0.0);
        // no PO → uncovered = 7
        $order = $this->orderInWave($wave, $fg, 7.0);
        $this->project($wave);

        $this->actingAs($this->operator());
        $res = $this->getJson("/api/preparation/waves/{$wave->id}/deficit-decisions");
        $res->assertOk();
        // Grain is now one row per ORDER (IMPACT-001): the product it was keyed on moves into
        // affected_products, and uncovered is reported on the MATERIAL rather than the row.
        $rows = $res->json('data.orders');
        self::assertCount(1, $rows);
        self::assertSame($order, $rows[0]['order_id']);
        self::assertSame($fg, $rows[0]['affected_products'][0]['product_id']);
        self::assertEqualsWithDelta(7.0, (float) $res->json('data.materials.0.uncovered_qty'), 0.001);
        self::assertEqualsWithDelta(7.0, (float) $rows[0]['shortage_impact_qty'], 0.001);
    }

    // ── H + I + J + O — Continue Despite Shortage ────────────────────────────

    public function test_h_i_j_o_continue_despite_shortage_preserves_everything(): void
    {
        $wave = $this->wave();
        $fg = $this->product('FG', 'finished_good');
        $rm = $this->product('RM', 'raw_material', allowNegative: false);
        $this->productDemand($wave, $fg, 7.0);
        $this->bom($fg, [$rm => 1.0]);
        $this->stock($rm, 0.0);
        $order = $this->orderInWave($wave, $fg, 7.0, unitPrice: 50.0);
        $this->project($wave);

        $lineBefore = DB::table('order_lines')->where('order_id', $order)->count();
        $totalBefore = DB::table('orders')->where('id', $order)->value('total');
        $statusBefore = DB::table('orders')->where('id', $order)->value('status');

        $this->actingAs($this->operator());
        $res = $this->postJson("/api/preparation/waves/{$wave->id}/product-demand/{$fg}/continue-despite-shortage");
        $res->assertOk();
        $res->assertJsonPath('data.shortage_decision', 'continue');

        // H — the order line is NOT deleted.
        self::assertSame($lineBefore, DB::table('order_lines')->where('order_id', $order)->count());
        self::assertSame(1, DB::table('order_lines')->where('order_id', $order)->count());
        // I — the original order is intact (status + total untouched).
        self::assertEquals($statusBefore, DB::table('orders')->where('id', $order)->value('status'));
        // O — Preparation never mutates the invoice total.
        self::assertEquals($totalBefore, DB::table('orders')->where('id', $order)->value('total'));
        // J — "not fully fulfilled" is the canonical prepared_qty < required_qty.
        $row = DB::table('wave_product_demand')->where('preparation_wave_id', $wave->id)->where('product_id', $fg)->first();
        self::assertLessThan((float) $row->required_qty, (float) $row->prepared_qty, 'recorded as not fully fulfilled');

        // The decision authorizes completion despite the shortfall (relaxed P-04).
        $complete = $this->postJson("/api/preparation/waves/{$wave->id}/product-demand/{$fg}/complete");
        $complete->assertOk();
        self::assertNotNull($complete->json('data.preparation_completed_at'));
    }

    // ── Q — tenant isolation on Expected Incoming ────────────────────────────

    public function test_q_expected_incoming_is_tenant_and_warehouse_scoped(): void
    {
        $rm = $this->product('RM', 'raw_material');
        // Another company's PO for the same product must not leak in.
        $otherCompany = Company::factory()->create();
        $otherWarehouse = Warehouse::factory()->create(['company_id' => $otherCompany->id]);
        $supplierId = (string) Str::uuid();
        DB::table('suppliers')->insert(['id' => $supplierId, 'company_id' => $otherCompany->id, 'code' => 'X', 'name' => 'X', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $poId = (string) Str::uuid();
        DB::table('purchase_orders')->insert(['id' => $poId, 'company_id' => $otherCompany->id, 'warehouse_id' => $otherWarehouse->id, 'po_number' => 'PO-X', 'supplier_id' => $supplierId, 'order_date' => today()->toDateString(), 'status' => 'approved', 'subtotal' => 0, 'total' => 0, 'grand_total' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('purchase_order_lines')->insert(['id' => (string) Str::uuid(), 'purchase_order_id' => $poId, 'product_id' => $rm, 'quantity' => 99, 'received_qty' => 0, 'unit_price' => 1, 'line_total' => 99, 'created_at' => now(), 'updated_at' => now()]);

        $expected = app(\Modules\Purchasing\PurchaseOrders\Application\Queries\ExpectedIncomingQuery::class)
            ->forWarehouse((string) $this->company->id, (string) $this->warehouse->id, [$rm]);

        self::assertEqualsWithDelta(0.0, (float) ($expected[$rm] ?? 0.0), 0.001, "another company's PO must not leak in");
    }

    // ── draft/submitted POs are not receivable → contribute 0 ────────────────

    public function test_draft_purchase_order_does_not_count_as_expected_incoming(): void
    {
        $rm = $this->product('RM', 'raw_material');
        $this->openPurchaseOrder($rm, ordered: 50.0, status: 'draft');

        $expected = app(\Modules\Purchasing\PurchaseOrders\Application\Queries\ExpectedIncomingQuery::class)
            ->forWarehouse((string) $this->company->id, (string) $this->warehouse->id, [$rm]);

        self::assertEqualsWithDelta(0.0, (float) ($expected[$rm] ?? 0.0), 0.001, 'a draft PO is not a firm commitment');
    }
}

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
use Modules\Operations\DemandAnalysis\Application\Services\MissingMaterialCalculator;
use Modules\Operations\DemandAnalysis\Application\Services\ProductReadinessCalculator;
use Modules\Operations\Preparation\Domain\Enums\WaveStatus;
use Modules\Operations\Preparation\Domain\Models\PreparationWave;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-PREPARATION-WORKSPACE-FIX-003 §2 — operator-owned Expected Incoming.
 *
 * Expected Incoming becomes an INDEPENDENT planning input that Procurement edits from
 * Missing Materials. These tests hold the owner contract:
 *   - it persists and survives a re-read (and a demand rebuild);
 *   - it overrides the derived purchase-order balance once set, and only then;
 *   - it NEVER changes missing_qty, on-hand, available, reserved, the stock ledger,
 *     goods receipts, reservations or product readiness;
 *   - Uncovered = max(0, missing_qty - expected_incoming) follows it;
 *   - it is permission-gated and tenant-isolated.
 */
final class ExpectedIncomingPlanningInputTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Warehouse $warehouse;

    private string $categoryId;

    private string $unitId;

    private DemandReadRepository $repo;

    private Customer $customer;

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

    private function wave(?Company $company = null, ?Warehouse $warehouse = null): PreparationWave
    {
        $company ??= $this->company;
        $warehouse ??= $this->warehouse;

        return PreparationWave::create([
            'company_id' => $company->id,
            'warehouse_id' => $warehouse->id,
            'wave_number' => 'PREP-EI-'.random_int(1, 99999),
            'planning_date' => today()->toDateString(),
            'status' => WaveStatus::Collecting->value,
            'orders_count' => 0, 'products_count' => 0, 'lines_count' => 0,
            'total_units_required' => 0, 'total_units_prepared' => 0,
            'shortage_detected' => false, 'wave_type' => 'engine',
            'created_by' => 'test', 'updated_by' => 'test',
        ]);
    }

    private function product(string $name, string $type, ?Company $company = null): string
    {
        $id = (string) Str::uuid();
        DB::table('products')->insert([
            'id' => $id, 'company_id' => ($company ?? $this->company)->id,
            'category_id' => $this->categoryId, 'unit_id' => $this->unitId,
            'name' => $name, 'sku' => 'SKU-'.random_int(100000, 999999),
            'product_type' => $type, 'allow_negative_stock' => false, 'is_active' => true,
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

    private function stock(string $productId, float $onHand, ?Warehouse $warehouse = null, ?Company $company = null): void
    {
        DB::table('inventory_items')->insert([
            'id' => (string) Str::uuid(), 'warehouse_id' => ($warehouse ?? $this->warehouse)->id,
            'product_id' => $productId, 'company_id' => ($company ?? $this->company)->id,
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

    private function openPurchaseOrder(string $materialId, float $ordered, float $received = 0.0): void
    {
        $supplierId = (string) Str::uuid();
        DB::table('suppliers')->insert([
            'id' => $supplierId, 'company_id' => $this->company->id, 'code' => 'SUP-'.random_int(1000, 9999),
            'name' => 'Supplier', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $poId = (string) Str::uuid();
        DB::table('purchase_orders')->insert([
            'id' => $poId, 'company_id' => $this->company->id, 'warehouse_id' => $this->warehouse->id,
            'po_number' => 'PO-'.random_int(100000, 999999), 'supplier_id' => $supplierId,
            'order_date' => today()->toDateString(), 'status' => 'approved',
            'subtotal' => 0, 'total' => 0, 'grand_total' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('purchase_order_lines')->insert([
            'id' => (string) Str::uuid(), 'purchase_order_id' => $poId, 'product_id' => $materialId,
            'quantity' => $ordered, 'received_qty' => $received, 'unit_price' => 1, 'line_total' => $ordered,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A user holding purchasing.expected_incoming.update. */
    private function procurementUser(?Company $company = null): User
    {
        $user = User::factory()->create(['company_id' => ($company ?? $this->company)->id]);
        $role = Role::firstOrCreate(['slug' => 'test-procurement'], ['name' => 'Procurement', 'is_system' => false]);
        $perm = Permission::firstOrCreate(['name' => 'purchasing.expected_incoming.update'],
            ['module' => 'purchasing', 'resource' => 'expected_incoming', 'action' => 'update']);
        if (! $role->permissions()->where('permissions.id', $perm->id)->exists()) {
            $role->permissions()->attach($perm->id);
        }
        $user->roles()->attach($role->id);
        $user->unsetRelation('roles');

        return $user;
    }

    /** A user with a role but WITHOUT the expected-incoming permission. */
    private function unprivilegedUser(): User
    {
        $user = User::factory()->create(['company_id' => $this->company->id]);
        $role = Role::firstOrCreate(['slug' => 'test-no-ei'], ['name' => 'No EI', 'is_system' => false]);
        $user->roles()->attach($role->id);
        $user->unsetRelation('roles');

        return $user;
    }

    /** Run the projection exactly as the demand builder does, including the shortage rows. */
    private function project(PreparationWave $wave): void
    {
        $materials = app(MaterialDemandCalculator::class);
        $this->repo->upsertMaterialDemand($materials->calculate($wave));
        $this->repo->upsertProductReadiness($wave->id, app(ProductReadinessCalculator::class)->calculate($wave));
        // Missing Materials is its own projection, built from missing_qty > 0. Using the
        // real calculator here keeps the fixture honest about the shape the API reads.
        $this->repo->upsertMissingMaterials(app(MissingMaterialCalculator::class)->calculate($wave));
    }

    /**
     * Wave with one finished good short of one raw material.
     *
     * @return array{0: PreparationWave, 1: string}
     */
    private function shortWave(float $required = 15.0, float $onHand = 0.0): array
    {
        $wave = $this->wave();
        $fg = $this->product('FG', 'finished_good');
        $rm = $this->product('RM', 'raw_material');
        $this->productDemand($wave, $fg, $required);
        $this->bom($fg, [$rm => 1.0]);
        $this->stock($rm, $onHand);
        $this->project($wave);

        return [$wave, $rm];
    }

    private function url(PreparationWave $wave, string $materialId): string
    {
        return "/api/preparation/waves/{$wave->id}/missing-materials/{$materialId}/expected-incoming";
    }

    /** An order inside the wave demanding $qty of $productId. */
    private function orderInWave(PreparationWave $wave, string $productId, float $qty): string
    {
        $orderId = (string) Str::uuid();
        DB::table('orders')->insert([
            'id' => $orderId, 'company_id' => $this->company->id, 'assigned_warehouse_id' => $this->warehouse->id,
            'customer_id' => $this->customer->id, 'customer_name' => 'Cust',
            'order_number' => 'ORD-'.random_int(100000, 999999),
            'order_date' => today()->toDateString(), 'status' => 'in_progress', 'payment_status' => null,
            'subtotal' => $qty * 100, 'total' => $qty * 100,
            'shipping_total' => 0, 'discount_total' => 0, 'tax_total' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_lines')->insert([
            'id' => (string) Str::uuid(), 'order_id' => $orderId, 'product_id' => $productId,
            'quantity' => $qty, 'unit_price' => 100, 'line_total' => $qty * 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('preparation_wave_orders')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $this->company->id,
            'preparation_wave_id' => $wave->id, 'order_id' => $orderId,
            'order_number' => 'ORD-'.random_int(100000, 999999),
            'order_confirmed_at' => now(), 'added_by' => (string) Str::uuid(), 'added_at' => now(),
            'postponed_at' => null, 'released_at' => null,
        ]);

        return $orderId;
    }

    /**
     * A wave with one order for a finished good blocked by a HARD-short raw material
     * (`allow_negative_stock = false`), which is the only shape that can legitimately
     * reach Deficit Decisions. A soft (allow-negative) shortage is READY by ADR-027 and
     * is deliberately NOT forced into the queue.
     *
     * @return array{0: PreparationWave, 1: string, 2: string, 3: string} wave, material, product, order
     */
    private function deficitWave(float $required = 15.0): array
    {
        $wave = $this->wave();
        $fg = $this->product('FG', 'finished_good');
        $rm = $this->product('RM', 'raw_material'); // allow_negative_stock = false
        $this->productDemand($wave, $fg, $required);
        $this->bom($fg, [$rm => 1.0]);
        $this->stock($rm, 0.0);
        $order = $this->orderInWave($wave, $fg, $required);
        $this->project($wave);

        return [$wave, $rm, $fg, $order];
    }

    /**
     * The deficit endpoint returns {materials, totals, orders} at ORDER grain
     * (TASK-PREPARATION-DEFICIT-DECISIONS-IMPACT-001).
     *
     * @return array{materials: array<int, mixed>, totals: array<string, int>, orders: array<int, mixed>}
     */
    private function deficit(PreparationWave $wave, User $user): array
    {
        $res = $this->actingAs($user)->getJson("/api/preparation/waves/{$wave->id}/deficit-decisions");
        $res->assertOk();

        return $res->json('data');
    }

    // ── Persistence ──────────────────────────────────────────────────────────

    public function test_it_persists_expected_incoming_and_returns_the_three_distinct_figures(): void
    {
        [$wave, $rm] = $this->shortWave(required: 15.0);

        $res = $this->actingAs($this->procurementUser())
            ->putJson($this->url($wave, $rm), ['expected_qty' => 10]);

        $res->assertOk()
            ->assertJsonPath('data.missing_qty', 15)
            ->assertJsonPath('data.expected_incoming_qty', 10)
            ->assertJsonPath('data.uncovered_shortage_qty', 5);

        $this->assertDatabaseHas('wave_expected_incoming', [
            'preparation_wave_id' => $wave->id,
            'material_id' => $rm,
            'expected_qty' => 10.0,
            'company_id' => $this->company->id,
        ]);
    }

    public function test_saved_value_survives_a_reload_of_missing_materials(): void
    {
        [$wave, $rm] = $this->shortWave(required: 15.0);
        $user = $this->procurementUser();

        $this->actingAs($user)->putJson($this->url($wave, $rm), ['expected_qty' => 12])->assertOk();

        $this->actingAs($user)
            ->getJson("/api/preparation/waves/{$wave->id}/missing-materials")
            ->assertOk()
            ->assertJsonPath('data.0.missing_qty', 15)
            ->assertJsonPath('data.0.expected_incoming_qty', 12)
            ->assertJsonPath('data.0.uncovered_shortage_qty', 3);
    }

    public function test_repeated_writes_update_in_place_rather_than_accumulating_rows(): void
    {
        [$wave, $rm] = $this->shortWave();
        $user = $this->procurementUser();

        $this->actingAs($user)->putJson($this->url($wave, $rm), ['expected_qty' => 4])->assertOk();
        $this->actingAs($user)->putJson($this->url($wave, $rm), ['expected_qty' => 9])->assertOk();

        $this->assertSame(1, DB::table('wave_expected_incoming')
            ->where('preparation_wave_id', $wave->id)->where('material_id', $rm)->count());
        $this->assertEqualsWithDelta(9.0, (float) DB::table('wave_expected_incoming')
            ->where('preparation_wave_id', $wave->id)->where('material_id', $rm)->value('expected_qty'), 0.0001);
    }

    public function test_operator_value_survives_a_demand_rebuild(): void
    {
        [$wave, $rm] = $this->shortWave(required: 15.0);
        $user = $this->procurementUser();
        $this->actingAs($user)->putJson($this->url($wave, $rm), ['expected_qty' => 6])->assertOk();

        // The projections are rebuilt wholesale; the planning value lives outside them.
        $this->project($wave);

        $this->actingAs($user)
            ->getJson("/api/preparation/waves/{$wave->id}/missing-materials")
            ->assertOk()
            ->assertJsonPath('data.0.expected_incoming_qty', 6);
    }

    // ── Precedence over the derived purchase-order balance ───────────────────

    public function test_derived_purchase_order_balance_is_used_until_an_operator_value_exists(): void
    {
        [$wave, $rm] = $this->shortWave(required: 15.0);
        $this->openPurchaseOrder($rm, ordered: 10.0); // derived Expected Incoming = 10

        $user = $this->procurementUser();

        $this->actingAs($user)
            ->getJson("/api/preparation/waves/{$wave->id}/missing-materials")
            ->assertOk()
            ->assertJsonPath('data.0.expected_incoming_qty', 10);

        // Once Procurement sets its own figure it is authoritative and no longer tracks the PO.
        $this->actingAs($user)->putJson($this->url($wave, $rm), ['expected_qty' => 3])->assertOk();

        $this->actingAs($user)
            ->getJson("/api/preparation/waves/{$wave->id}/missing-materials")
            ->assertOk()
            ->assertJsonPath('data.0.expected_incoming_qty', 3)
            ->assertJsonPath('data.0.uncovered_shortage_qty', 12);
    }

    // ── It is planning data: no inventory effect whatsoever ──────────────────

    public function test_it_never_changes_inventory_readiness_or_the_real_shortage(): void
    {
        [$wave, $rm] = $this->shortWave(required: 15.0, onHand: 0.0);
        $user = $this->procurementUser();

        $inventoryBefore = DB::table('inventory_items')->orderBy('id')->get()->toJson();
        $materialBefore = DB::table('wave_material_demand')
            ->where('preparation_wave_id', $wave->id)->where('material_id', $rm)
            ->first(['required_qty', 'available_qty', 'reserved_qty', 'missing_qty', 'coverage_pct']);
        $readinessBefore = DB::table('wave_product_demand')
            ->where('preparation_wave_id', $wave->id)->orderBy('id')
            ->get(['material_status', 'blocking_materials_count', 'prepared_qty'])->toJson();
        $ledgerBefore = DB::table('stock_ledger_entries')->count();
        $receiptsBefore = DB::table('goods_receipts')->count();

        // Cover the whole shortage on paper.
        $this->actingAs($user)->putJson($this->url($wave, $rm), ['expected_qty' => 999])->assertOk();

        $materialAfter = DB::table('wave_material_demand')
            ->where('preparation_wave_id', $wave->id)->where('material_id', $rm)
            ->first(['required_qty', 'available_qty', 'reserved_qty', 'missing_qty', 'coverage_pct']);

        // The REAL shortage and every inventory figure are untouched.
        $this->assertEquals($materialBefore, $materialAfter);
        $this->assertSame($inventoryBefore, DB::table('inventory_items')->orderBy('id')->get()->toJson());
        $this->assertSame($readinessBefore, DB::table('wave_product_demand')
            ->where('preparation_wave_id', $wave->id)->orderBy('id')
            ->get(['material_status', 'blocking_materials_count', 'prepared_qty'])->toJson());

        // No ledger entry, no goods receipt, no reservation.
        $this->assertSame($ledgerBefore, DB::table('stock_ledger_entries')->count());
        $this->assertSame($receiptsBefore, DB::table('goods_receipts')->count());
        $this->assertSame(0, DB::table('preparation_inventory_reservations')->count());
    }

    public function test_it_does_not_change_the_wave_or_any_order_status(): void
    {
        [$wave, $rm] = $this->shortWave();
        $statusBefore = $wave->status;

        $this->actingAs($this->procurementUser())
            ->putJson($this->url($wave, $rm), ['expected_qty' => 50])->assertOk();

        $this->assertSame($statusBefore->value, $wave->fresh()->status->value);
    }

    // ── Authorization ────────────────────────────────────────────────────────

    public function test_it_requires_the_expected_incoming_permission(): void
    {
        [$wave, $rm] = $this->shortWave();

        $this->actingAsUnprivileged($this->unprivilegedUser())
            ->putJson($this->url($wave, $rm), ['expected_qty' => 5])
            ->assertForbidden();

        $this->assertDatabaseMissing('wave_expected_incoming', [
            'preparation_wave_id' => $wave->id, 'material_id' => $rm,
        ]);
    }

    public function test_it_rejects_an_unauthenticated_request(): void
    {
        [$wave, $rm] = $this->shortWave();

        $this->putJson($this->url($wave, $rm), ['expected_qty' => 5])->assertUnauthorized();
    }

    // ── Tenant isolation ─────────────────────────────────────────────────────

    public function test_another_company_cannot_write_expected_incoming_and_gets_404(): void
    {
        [$wave, $rm] = $this->shortWave();

        $otherCompany = Company::factory()->create();
        $intruder = $this->procurementUser($otherCompany);

        $this->actingAs($intruder)
            ->putJson($this->url($wave, $rm), ['expected_qty' => 77])
            ->assertNotFound();

        // Nothing leaked and nothing was written.
        $this->assertDatabaseMissing('wave_expected_incoming', [
            'preparation_wave_id' => $wave->id, 'material_id' => $rm,
        ]);
    }

    // ── Input contract ───────────────────────────────────────────────────────

    public function test_it_rejects_a_negative_quantity(): void
    {
        [$wave, $rm] = $this->shortWave();

        $this->actingAs($this->procurementUser())
            ->putJson($this->url($wave, $rm), ['expected_qty' => -1])
            ->assertStatus(422);
    }

    public function test_it_404s_for_a_material_that_is_not_part_of_this_wave(): void
    {
        [$wave] = $this->shortWave();
        $stranger = $this->product('Other RM', 'raw_material');

        $this->actingAs($this->procurementUser())
            ->putJson($this->url($wave, $stranger), ['expected_qty' => 5])
            ->assertNotFound();
    }

    // ── Deficit Decisions consequence (hard shortage only) ───────────────────

    /**
     * A — an EXPLICITLY SAVED zero must not hide an unresolved shortage.
     *
     * Deliberately not an implicit zero: the row really exists in wave_expected_incoming
     * with expected_qty = 0, which is the case the earlier suite never covered.
     */
    public function test_explicitly_saved_zero_still_leaves_the_order_in_deficit_decisions(): void
    {
        [$wave, $rm, $fg, $order] = $this->deficitWave(required: 15.0);
        $user = $this->procurementUser();

        $this->actingAs($user)->putJson($this->url($wave, $rm), ['expected_qty' => 0])->assertOk();

        // The zero is genuinely persisted, not merely absent.
        $this->assertDatabaseHas('wave_expected_incoming', [
            'preparation_wave_id' => $wave->id, 'material_id' => $rm, 'expected_qty' => 0.0,
        ]);

        $data = $this->deficit($wave, $user);

        self::assertCount(1, $data['orders']);
        self::assertSame($order, $data['orders'][0]['order_id']);
        self::assertSame($fg, $data['orders'][0]['affected_products'][0]['product_id']);
        self::assertEqualsWithDelta(15.0, (float) $data['materials'][0]['missing_qty'], 0.001);
        self::assertEqualsWithDelta(0.0, (float) $data['materials'][0]['expected_incoming_qty'], 0.001);
        self::assertEqualsWithDelta(15.0, (float) $data['materials'][0]['uncovered_qty'], 0.001);
    }

    /** B1 — partial cover: missing 15, expected 10 → uncovered 5, order still listed. */
    public function test_partially_covered_shortage_still_appears_with_the_remainder(): void
    {
        [$wave, $rm, , $order] = $this->deficitWave(required: 15.0);
        $user = $this->procurementUser();

        $this->actingAs($user)->putJson($this->url($wave, $rm), ['expected_qty' => 10])->assertOk();

        $data = $this->deficit($wave, $user);

        self::assertCount(1, $data['orders']);
        self::assertSame($order, $data['orders'][0]['order_id']);
        self::assertEqualsWithDelta(15.0, (float) $data['materials'][0]['missing_qty'], 0.001, 'real shortage is untouched');
        self::assertEqualsWithDelta(10.0, (float) $data['materials'][0]['expected_incoming_qty'], 0.001);
        self::assertEqualsWithDelta(5.0, (float) $data['materials'][0]['uncovered_qty'], 0.001, '15 - 10 = 5');
    }

    /** B2 — full cover: missing 15, expected 15 → uncovered 0 and no unresolved deficit. */
    public function test_fully_covered_shortage_is_no_longer_an_unresolved_deficit(): void
    {
        [$wave, $rm] = $this->deficitWave(required: 15.0);
        $user = $this->procurementUser();

        $this->actingAs($user)->putJson($this->url($wave, $rm), ['expected_qty' => 15])
            ->assertOk()
            ->assertJsonPath('data.uncovered_shortage_qty', 0);

        $data = $this->deficit($wave, $user);
        self::assertSame([], $data['orders']);
        self::assertSame([], $data['materials']);

        // Covering it on paper must NOT have altered the real shortage.
        $this->assertEqualsWithDelta(15.0, (float) DB::table('wave_material_demand')
            ->where('preparation_wave_id', $wave->id)->where('material_id', $rm)->value('missing_qty'), 0.001);
    }

    public function test_zero_clears_the_expectation_and_restores_the_full_uncovered_shortage(): void
    {
        [$wave, $rm] = $this->shortWave(required: 15.0);
        $user = $this->procurementUser();

        $this->actingAs($user)->putJson($this->url($wave, $rm), ['expected_qty' => 15])->assertOk();
        $this->actingAs($user)->putJson($this->url($wave, $rm), ['expected_qty' => 0])
            ->assertOk()
            ->assertJsonPath('data.expected_incoming_qty', 0)
            ->assertJsonPath('data.uncovered_shortage_qty', 15);
    }
}

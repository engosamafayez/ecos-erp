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
 * TASK-PREPARATION-DEFICIT-DECISIONS-IMPACT-001 — Deficit Decisions as an operator decision
 * workspace driven by REAL uncovered material shortage impact.
 *
 * The load-bearing distinction proved here: READINESS and SHORTAGE DECISION are independent.
 * `allow_negative_stock = true` still yields Product Readiness = READY (that contract is
 * untouched), yet the order still reaches the decision queue when its uncovered shortage is
 * real. The queue is keyed on `uncovered = max(0, missing - expected) > 0`, nothing else.
 */
final class DeficitDecisionsImpactTest extends TestCase
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
            'wave_number' => 'PREP-DD-'.random_int(1, 99999),
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

    /** @param array<string, float> $ingredients materialId => qty per unit */
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

    /**
     * @param  array<int, array{0:string,1:float}>  $lines  [productId, qty]
     * @param  array<string, string|null>  $payment  any of payment_method_manual / _title / payment_method
     */
    private function orderInWave(PreparationWave $wave, array $lines, float $total = 100.0, array $payment = []): string
    {
        $orderId = (string) Str::uuid();
        DB::table('orders')->insert([
            'id' => $orderId, 'company_id' => $this->company->id, 'assigned_warehouse_id' => $this->warehouse->id,
            'customer_id' => $this->customer->id, 'customer_name' => 'Cust',
            'order_number' => 'ORD-'.random_int(100000, 999999),
            'order_date' => today()->toDateString(), 'status' => 'in_progress', 'payment_status' => null,
            'payment_method_manual' => $payment['payment_method_manual'] ?? null,
            'payment_method_title' => $payment['payment_method_title'] ?? null,
            'payment_method' => $payment['payment_method'] ?? null,
            'subtotal' => $total, 'total' => $total,
            'shipping_total' => 0, 'discount_total' => 0, 'tax_total' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($lines as [$productId, $qty]) {
            DB::table('order_lines')->insert([
                'id' => (string) Str::uuid(), 'order_id' => $orderId, 'product_id' => $productId,
                'quantity' => $qty, 'unit_price' => 10, 'line_total' => $qty * 10,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('preparation_wave_orders')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $this->company->id,
            'preparation_wave_id' => $wave->id, 'order_id' => $orderId,
            'order_number' => 'ORD-'.random_int(100000, 999999),
            'order_confirmed_at' => now(), 'added_by' => (string) Str::uuid(), 'added_at' => now(),
            'postponed_at' => null, 'released_at' => null,
        ]);

        // The real attach path maintains this counter, and postponeOrder decrements it under
        // a CHECK constraint that forbids going negative. A fixture that inserts membership
        // rows without it produces a wave that cannot legally be postponed from.
        DB::table('preparation_waves')->where('id', $wave->id)->increment('orders_count');

        return $orderId;
    }

    private function operator(): User
    {
        $user = User::factory()->create(['company_id' => $this->company->id]);
        $role = Role::firstOrCreate(['slug' => 'test-dd-operator'], ['name' => 'DD Op', 'is_system' => false]);

        // Two EXISTING permissions, because the postpone surface is gated twice under two
        // different names: the route middleware checks `operations.preparation.update` while
        // PreparationWavePolicy::postponeOrder checks `preparation.wave.update`. Neither is
        // new — the fixture simply has to satisfy both to exercise the real workflow.
        foreach ([
            ['operations.preparation.update', 'operations', 'preparation', 'update'],
            ['preparation.wave.update', 'preparation', 'wave', 'update'],
            // Reading the wave header is gated by PreparationWavePolicy::view.
            ['preparation.wave.view', 'preparation', 'wave', 'view'],
        ] as [$name, $module, $resource, $action]) {
            $perm = Permission::firstOrCreate(['name' => $name],
                ['module' => $module, 'resource' => $resource, 'action' => $action]);
            if (! $role->permissions()->where('permissions.id', $perm->id)->exists()) {
                $role->permissions()->attach($perm->id);
            }
        }

        $user->roles()->attach($role->id);
        $user->unsetRelation('roles');

        return $user;
    }

    private function project(PreparationWave $wave): void
    {
        $this->repo->upsertMaterialDemand(app(MaterialDemandCalculator::class)->calculate($wave));
        $this->repo->upsertProductReadiness($wave->id, app(ProductReadinessCalculator::class)->calculate($wave));
        $this->repo->upsertMissingMaterials(app(MissingMaterialCalculator::class)->calculate($wave));
    }

    private function setExpected(PreparationWave $wave, string $materialId, float $qty, User $user): void
    {
        DB::table('wave_expected_incoming')->insert([
            'id' => (string) Str::uuid(), 'company_id' => (string) $wave->company_id,
            'preparation_wave_id' => (string) $wave->id, 'material_id' => $materialId,
            'expected_qty' => $qty, 'updated_by' => $user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array{materials: array<int, mixed>, totals: array<string, int>, orders: array<int, mixed>} */
    private function deficit(PreparationWave $wave, User $user): array
    {
        $res = $this->actingAs($user)->getJson("/api/preparation/waves/{$wave->id}/deficit-decisions");
        $res->assertOk();

        return $res->json('data');
    }

    // ── CASE 1 — hard shortage, no expected incoming ─────────────────────────

    public function test_case1_hard_shortage_lists_the_affected_orders(): void
    {
        $wave = $this->wave();
        $fg = $this->product('FG', 'finished_good');
        $rm = $this->product('RM', 'raw_material', allowNegative: false);
        $this->productDemand($wave, $fg, 6.0);
        $this->bom($fg, [$rm => 1.0]);
        $this->stock($rm, 0.0);
        $order = $this->orderInWave($wave, [[$fg, 6.0]]);
        $this->project($wave);

        $data = $this->deficit($wave, $this->operator());

        self::assertCount(1, $data['materials']);
        self::assertEqualsWithDelta(6.0, (float) $data['materials'][0]['missing_qty'], 0.001);
        self::assertEqualsWithDelta(0.0, (float) $data['materials'][0]['expected_incoming_qty'], 0.001);
        self::assertEqualsWithDelta(6.0, (float) $data['materials'][0]['uncovered_qty'], 0.001);
        self::assertSame(1, $data['materials'][0]['affected_orders_count']);

        self::assertCount(1, $data['orders']);
        self::assertSame($order, $data['orders'][0]['order_id']);
        self::assertEqualsWithDelta(6.0, (float) $data['orders'][0]['shortage_impact_qty'], 0.001);
    }

    // ── CASE 2 — partial expected incoming ───────────────────────────────────

    public function test_case2_partial_expected_incoming_still_lists_the_orders(): void
    {
        $wave = $this->wave();
        $fg = $this->product('FG', 'finished_good');
        $rm = $this->product('RM', 'raw_material', allowNegative: false);
        $this->productDemand($wave, $fg, 6.0);
        $this->bom($fg, [$rm => 1.0]);
        $this->stock($rm, 0.0);
        $this->orderInWave($wave, [[$fg, 6.0]]);
        $this->project($wave);

        $user = $this->operator();
        $this->setExpected($wave, $rm, 4.0, $user);

        $data = $this->deficit($wave, $user);

        self::assertEqualsWithDelta(6.0, (float) $data['materials'][0]['missing_qty'], 0.001, 'real shortage untouched');
        self::assertEqualsWithDelta(4.0, (float) $data['materials'][0]['expected_incoming_qty'], 0.001);
        self::assertEqualsWithDelta(2.0, (float) $data['materials'][0]['uncovered_qty'], 0.001, '6 - 4 = 2');
        self::assertCount(1, $data['orders']);
    }

    // ── CASE 3 — THE distinction: allow-negative stays READY but still decides ──

    public function test_case3_allow_negative_stays_ready_yet_reaches_the_decision_queue(): void
    {
        $wave = $this->wave();
        $fg = $this->product('FG', 'finished_good');
        $rm = $this->product('RM', 'raw_material', allowNegative: true);
        $this->productDemand($wave, $fg, 6.0);
        $this->bom($fg, [$rm => 1.0]);
        $this->stock($rm, 0.0);
        $order = $this->orderInWave($wave, [[$fg, 6.0]]);
        $this->project($wave);

        $user = $this->operator();
        $this->setExpected($wave, $rm, 4.0, $user);

        // Readiness contract is UNCHANGED: an allow-negative shortage is still READY.
        $readiness = DB::table('wave_product_demand')
            ->where('preparation_wave_id', $wave->id)->where('product_id', $fg)
            ->first(['material_status', 'blocking_materials_count']);
        self::assertSame(ProductReadinessCalculator::READY, $readiness->material_status);
        self::assertSame(0, (int) $readiness->blocking_materials_count);

        // ...and yet the uncovered shortage still requires an operator decision.
        $data = $this->deficit($wave, $user);

        self::assertCount(1, $data['materials']);
        self::assertTrue($data['materials'][0]['allow_negative']);
        self::assertEqualsWithDelta(2.0, (float) $data['materials'][0]['uncovered_qty'], 0.001);
        self::assertCount(1, $data['orders']);
        self::assertSame($order, $data['orders'][0]['order_id']);
    }

    // ── CASE 4 — fully covered ───────────────────────────────────────────────

    public function test_case4_full_expected_incoming_empties_the_queue(): void
    {
        $wave = $this->wave();
        $fg = $this->product('FG', 'finished_good');
        $rm = $this->product('RM', 'raw_material', allowNegative: false);
        $this->productDemand($wave, $fg, 6.0);
        $this->bom($fg, [$rm => 1.0]);
        $this->stock($rm, 0.0);
        $this->orderInWave($wave, [[$fg, 6.0]]);
        $this->project($wave);

        $user = $this->operator();
        $this->setExpected($wave, $rm, 6.0, $user);

        $data = $this->deficit($wave, $user);

        self::assertSame([], $data['materials']);
        self::assertSame([], $data['orders']);
        self::assertSame(0, $data['totals']['affected_orders']);

        // The REAL shortage is untouched by the planning value.
        self::assertEqualsWithDelta(6.0, (float) DB::table('wave_material_demand')
            ->where('preparation_wave_id', $wave->id)->where('material_id', $rm)->value('missing_qty'), 0.001);
    }

    // ── CASE 5 — only genuinely impacted orders ──────────────────────────────

    public function test_case5_only_orders_whose_lines_use_the_material_appear(): void
    {
        $wave = $this->wave();
        $affected = $this->product('Affected FG', 'finished_good');
        $unrelated = $this->product('Unrelated FG', 'finished_good');
        $rm = $this->product('RM', 'raw_material', allowNegative: false);
        $otherRm = $this->product('Other RM', 'raw_material', allowNegative: false);

        $this->productDemand($wave, $affected, 6.0);
        $this->productDemand($wave, $unrelated, 3.0);
        $this->bom($affected, [$rm => 1.0]);
        $this->bom($unrelated, [$otherRm => 1.0]);
        $this->stock($rm, 0.0);
        $this->stock($otherRm, 500.0); // plentiful — never short

        $affectedOrder = $this->orderInWave($wave, [[$affected, 6.0]]);
        $this->orderInWave($wave, [[$unrelated, 3.0]]);
        $this->project($wave);

        $data = $this->deficit($wave, $this->operator());

        self::assertCount(1, $data['orders'], 'the unrelated order must not be listed');
        self::assertSame($affectedOrder, $data['orders'][0]['order_id']);
    }

    // ── CASE 6 — one order, two short materials, listed once ─────────────────

    public function test_case6_order_affected_by_two_materials_appears_once_with_both(): void
    {
        $wave = $this->wave();
        $fg = $this->product('FG', 'finished_good');
        $rmA = $this->product('RM A', 'raw_material', allowNegative: false);
        $rmB = $this->product('RM B', 'raw_material', allowNegative: false);

        $this->productDemand($wave, $fg, 6.0);
        $this->bom($fg, [$rmA => 1.0, $rmB => 2.0]);
        $this->stock($rmA, 0.0);
        $this->stock($rmB, 0.0);
        $order = $this->orderInWave($wave, [[$fg, 6.0]]);
        $this->project($wave);

        $data = $this->deficit($wave, $this->operator());

        self::assertCount(2, $data['materials']);
        self::assertCount(1, $data['orders'], 'one row per order, never one per material');
        self::assertSame($order, $data['orders'][0]['order_id']);
        self::assertCount(2, $data['orders'][0]['affected_materials']);

        // 6 x 1 for A, 6 x 2 for B.
        $impacts = collect($data['orders'][0]['affected_materials'])->pluck('impact_qty', 'material_id');
        self::assertEqualsWithDelta(6.0, (float) $impacts[$rmA], 0.001);
        self::assertEqualsWithDelta(12.0, (float) $impacts[$rmB], 0.001);
    }

    // ── CASE 7 — postpone recalculates the queue ─────────────────────────────

    public function test_case7_postponing_one_order_leaves_the_other_in_the_queue(): void
    {
        $wave = $this->wave();
        $fg = $this->product('FG', 'finished_good');
        $rm = $this->product('RM', 'raw_material', allowNegative: false);
        $this->productDemand($wave, $fg, 2.0);
        $this->bom($fg, [$rm => 1.0]);
        $this->stock($rm, 0.0);
        $orderA = $this->orderInWave($wave, [[$fg, 1.0]]);
        $orderB = $this->orderInWave($wave, [[$fg, 1.0]]);
        $this->project($wave);

        $user = $this->operator();
        self::assertCount(2, $this->deficit($wave, $user)['orders']);

        // The EXISTING postpone workflow — nothing new is invented.
        $this->actingAs($user)
            ->postJson("/api/preparation/waves/{$wave->id}/orders/{$orderA}/postpone")
            ->assertSuccessful();

        $after = $this->deficit($wave, $user)['orders'];

        self::assertCount(1, $after, 'the queue recalculates; no stale row remains');
        self::assertSame($orderB, $after[0]['order_id']);

        // Postpone must not delete the order or its lines.
        self::assertDatabaseHas('orders', ['id' => $orderA]);
        self::assertSame(1, DB::table('order_lines')->where('order_id', $orderA)->count());
    }

    // ── CASE 8 — continue despite shortage ───────────────────────────────────

    public function test_case8_continue_despite_shortage_records_the_decision_without_deleting(): void
    {
        $wave = $this->wave();
        $fg = $this->product('FG', 'finished_good');
        $rm = $this->product('RM', 'raw_material', allowNegative: false);
        $this->productDemand($wave, $fg, 6.0);
        $this->bom($fg, [$rm => 1.0]);
        $this->stock($rm, 0.0);
        $order = $this->orderInWave($wave, [[$fg, 6.0]]);
        $this->project($wave);

        $user = $this->operator();

        $this->actingAs($user)
            ->postJson("/api/preparation/waves/{$wave->id}/product-demand/{$fg}/continue-despite-shortage")
            ->assertSuccessful();

        $rows = $this->deficit($wave, $user)['orders'];

        self::assertCount(1, $rows, 'a decided order stays visible so it can be revisited');
        self::assertSame('continue', $rows[0]['shortage_decision']);

        // Nothing deleted, no new order status.
        self::assertDatabaseHas('orders', ['id' => $order, 'status' => 'in_progress']);
        self::assertSame(1, DB::table('order_lines')->where('order_id', $order)->count());
    }

    // ── CASE 9 — no double counting ──────────────────────────────────────────

    public function test_case9_order_with_many_products_and_lines_appears_exactly_once(): void
    {
        $wave = $this->wave();
        $fgA = $this->product('FG A', 'finished_good');
        $fgB = $this->product('FG B', 'finished_good');
        $rm = $this->product('RM', 'raw_material', allowNegative: false);

        $this->productDemand($wave, $fgA, 4.0);
        $this->productDemand($wave, $fgB, 2.0);
        $this->bom($fgA, [$rm => 1.0]);
        $this->bom($fgB, [$rm => 1.0]);
        $this->stock($rm, 0.0);

        // One order, two products, and a repeated line for the same product.
        $order = $this->orderInWave($wave, [[$fgA, 3.0], [$fgA, 1.0], [$fgB, 2.0]]);
        $this->project($wave);

        $data = $this->deficit($wave, $this->operator());

        self::assertCount(1, $data['orders']);
        self::assertSame($order, $data['orders'][0]['order_id']);
        self::assertSame(1, $data['totals']['affected_orders']);
        self::assertSame(1, $data['materials'][0]['affected_orders_count'], 'counted once, not per line');

        // 3 lines on the order, all three impacted; impact 3+1+2 = 6.
        self::assertSame(3, $data['orders'][0]['affected_lines_count']);
        self::assertSame(3, $data['orders'][0]['products_count']);
        self::assertEqualsWithDelta(6.0, (float) $data['orders'][0]['shortage_impact_qty'], 0.001);
    }

    // ── Consistency with Missing Materials (single source of truth) ──────────

    public function test_missing_materials_and_deficit_decisions_agree_on_uncovered(): void
    {
        $wave = $this->wave();
        $fg = $this->product('FG', 'finished_good');
        $rm = $this->product('RM', 'raw_material', allowNegative: false);
        $this->productDemand($wave, $fg, 6.0);
        $this->bom($fg, [$rm => 1.0]);
        $this->stock($rm, 0.0);
        $this->orderInWave($wave, [[$fg, 6.0]]);
        $this->project($wave);

        $user = $this->operator();
        $this->setExpected($wave, $rm, 4.0, $user);

        $missing = $this->actingAs($user)
            ->getJson("/api/preparation/waves/{$wave->id}/missing-materials")->json('data');
        $deficit = $this->deficit($wave, $user)['materials'];

        self::assertEqualsWithDelta((float) $missing[0]['missing_qty'], (float) $deficit[0]['missing_qty'], 0.0001);
        self::assertEqualsWithDelta((float) $missing[0]['expected_incoming_qty'], (float) $deficit[0]['expected_incoming_qty'], 0.0001);
        self::assertEqualsWithDelta((float) $missing[0]['uncovered_shortage_qty'], (float) $deficit[0]['uncovered_qty'], 0.0001);
    }

    // ── Side effects ─────────────────────────────────────────────────────────

    public function test_reading_the_queue_changes_no_inventory_ledger_or_purchase_order(): void
    {
        $wave = $this->wave();
        $fg = $this->product('FG', 'finished_good');
        $rm = $this->product('RM', 'raw_material', allowNegative: false);
        $this->productDemand($wave, $fg, 6.0);
        $this->bom($fg, [$rm => 1.0]);
        $this->stock($rm, 0.0);
        $this->orderInWave($wave, [[$fg, 6.0]]);
        $this->project($wave);

        $before = [
            'inv' => DB::table('inventory_items')->orderBy('id')->get()->toJson(),
            'ledger' => DB::table('stock_ledger_entries')->count(),
            'grs' => DB::table('goods_receipts')->count(),
            'po' => DB::table('purchase_order_lines')->count(),
            'reservations' => DB::table('preparation_inventory_reservations')->count(),
        ];

        $this->deficit($wave, $this->operator());

        self::assertSame($before['inv'], DB::table('inventory_items')->orderBy('id')->get()->toJson());
        self::assertSame($before['ledger'], DB::table('stock_ledger_entries')->count());
        self::assertSame($before['grs'], DB::table('goods_receipts')->count());
        self::assertSame($before['po'], DB::table('purchase_order_lines')->count());
        self::assertSame($before['reservations'], DB::table('preparation_inventory_reservations')->count());
    }

    // ── Payment METHOD (TASK-PREPARATION-DEFICIT-PAYMENT-RETURN-001 Part 1) ──

    /**
     * The method is emitted, not the payment state, and it is resolved with the Orders
     * precedence: manual -> gateway title -> gateway slug.
     *
     * @return string|null
     */
    private function methodFor(array $payment)
    {
        $wave = $this->wave();
        $fg = $this->product('FG', 'finished_good');
        $rm = $this->product('RM', 'raw_material', allowNegative: false);
        $this->productDemand($wave, $fg, 2.0);
        $this->bom($fg, [$rm => 1.0]);
        $this->stock($rm, 0.0);
        $this->orderInWave($wave, [[$fg, 2.0]], 100.0, $payment);
        $this->project($wave);

        $data = $this->deficit($wave, $this->operator());

        return $data['orders'][0]['payment_method'];
    }

    public function test_payment_method_cod_is_reported(): void
    {
        self::assertSame('cod', $this->methodFor(['payment_method_manual' => 'cod']));
    }

    public function test_payment_method_instapay_is_reported(): void
    {
        self::assertSame('instapay', $this->methodFor(['payment_method_manual' => 'instapay']));
    }

    public function test_payment_method_credit_card_is_reported(): void
    {
        self::assertSame('credit_card', $this->methodFor(['payment_method_manual' => 'credit_card']));
    }

    public function test_gateway_method_is_used_when_no_manual_method_exists(): void
    {
        // A WooCommerce-imported order carries the gateway pair and no manual value; the
        // human-readable title wins over the raw slug, exactly as the Orders UI resolves it.
        self::assertSame('Visa', $this->methodFor([
            'payment_method' => 'visa_gateway',
            'payment_method_title' => 'Visa',
        ]));
    }

    public function test_manual_method_takes_precedence_over_the_gateway(): void
    {
        self::assertSame('bank_transfer', $this->methodFor([
            'payment_method_manual' => 'bank_transfer',
            'payment_method_title' => 'Visa',
            'payment_method' => 'visa_gateway',
        ]));
    }

    public function test_missing_payment_method_is_null_and_never_fabricated(): void
    {
        self::assertNull($this->methodFor([]));
    }

    // -- Return to Preparation (PART 2) ---------------------------------------

    private function postpone(PreparationWave $wave, string $orderId, User $user): void
    {
        $this->actingAs($user)
            ->postJson("/api/preparation/waves/{$wave->id}/orders/{$orderId}/postpone")
            ->assertSuccessful();
    }

    private function returnToPreparation(PreparationWave $wave, string $orderId, User $user)
    {
        return $this->actingAs($user)
            ->postJson("/api/preparation/waves/{$wave->id}/orders/{$orderId}/return-to-preparation");
    }

    /**
     * A wave with one order; $onHand decides whether the material is available again.
     *
     * @return array{0: PreparationWave, 1: string}
     */
    private function returnableWave(float $onHand): array
    {
        $wave = $this->wave();
        $fg = $this->product('FG', 'finished_good');
        $rm = $this->product('RM', 'raw_material', allowNegative: false);
        $this->productDemand($wave, $fg, 2.0);
        $this->bom($fg, [$rm => 1.0]);
        $this->stock($rm, $onHand);
        $order = $this->orderInWave($wave, [[$fg, 2.0]]);
        $this->project($wave);

        return [$wave, $order];
    }

    private function postponedAt(PreparationWave $wave, string $orderId)
    {
        return DB::table('preparation_wave_orders')
            ->where('preparation_wave_id', $wave->id)
            ->where('order_id', $orderId)
            ->value('postponed_at');
    }

    public function test_return_succeeds_when_material_is_available_and_wave_is_collecting(): void
    {
        [$wave, $order] = $this->returnableWave(onHand: 50.0);
        $user = $this->operator();
        $this->postpone($wave, $order, $user);
        self::assertNotNull($this->postponedAt($wave, $order));

        $this->returnToPreparation($wave, $order, $user)->assertSuccessful();

        // postponed_at cleared, on the SAME row (an UPDATE, never an insert).
        self::assertNull($this->postponedAt($wave, $order));

        // exactly one membership row: no duplicate.
        self::assertSame(1, DB::table('preparation_wave_orders')
            ->where('preparation_wave_id', $wave->id)->where('order_id', $order)->count());

        // order line and order status untouched.
        self::assertSame(1, DB::table('order_lines')->where('order_id', $order)->count());
        self::assertSame('in_progress', DB::table('orders')->where('id', $order)->value('status'));
    }

    public function test_returned_order_is_eligible_for_preparation_again(): void
    {
        [$wave, $order] = $this->returnableWave(onHand: 50.0);
        $user = $this->operator();
        $this->postpone($wave, $order, $user);
        $this->returnToPreparation($wave, $order, $user)->assertSuccessful();

        // The collector's active-membership predicate is satisfied once more.
        self::assertSame(1, DB::table('preparation_wave_orders')
            ->where('preparation_wave_id', $wave->id)
            ->where('order_id', $order)
            ->whereNull('postponed_at')
            ->whereNull('released_at')
            ->count());
    }

    public function test_return_is_refused_while_the_material_is_still_unavailable(): void
    {
        [$wave, $order] = $this->returnableWave(onHand: 0.0);
        $user = $this->operator();
        $this->postpone($wave, $order, $user);

        $this->returnToPreparation($wave, $order, $user)->assertStatus(422);

        self::assertNotNull($this->postponedAt($wave, $order));
    }

    /**
     * REWRITTEN by TASK-OPERATIONS-PREPARATION-DEFERRED-ORDER-CUTOFF-RETURN-001.
     *
     * This test used to assert that leaving `Collecting` refused the return, and it passed —
     * but it was pinning the defect. `Collecting → Preparing` is the INTAKE CUTOFF, which stops
     * new orders from JOINING the wave. It never ended the membership of an order that had
     * already joined, so refusing that order's return stranded work the operator had
     * deliberately parked until stock arrived.
     *
     * The refusal boundary the assertion was really reaching for is WAVE CLOSE — a wave that
     * has genuinely ended, where the existing carry-over owns the order. That is what it now
     * asserts. Neither the 422 nor the "nothing mutated" guarantee is weakened; only the
     * boundary moved to the event that actually ends membership. The repaired direction is
     * pinned by the companion test below and, in full, by WaveDeferredOrderCutoffReturnTest.
     */
    public function test_return_is_refused_when_the_wave_has_closed(): void
    {
        [$wave, $order] = $this->returnableWave(onHand: 50.0);
        $user = $this->operator();
        $this->postpone($wave, $order, $user);

        // A wave that has ENDED is never forced open; the existing carry-over owns this case.
        $wave->update(['status' => WaveStatus::Closed->value]);

        $this->returnToPreparation($wave->fresh(), $order, $user)->assertStatus(422);

        self::assertNotNull($this->postponedAt($wave, $order));
        self::assertSame(WaveStatus::Closed->value, $wave->fresh()->status->value);
    }

    /** The repaired direction: past cutoff, wave still open, existing member returns. */
    public function test_return_is_allowed_after_intake_cutoff_while_the_wave_is_open(): void
    {
        [$wave, $order] = $this->returnableWave(onHand: 50.0);
        $user = $this->operator();
        $this->postpone($wave, $order, $user);

        // The intake cutoff, exactly as the scheduler applies it.
        $wave->update(['status' => WaveStatus::Preparing->value]);

        $this->returnToPreparation($wave->fresh(), $order, $user)->assertOk();

        self::assertNull(
            $this->postponedAt($wave, $order),
            'Cutoff stops new admissions; it must not lock a member that joined before it.',
        );
        self::assertSame(WaveStatus::Preparing->value, $wave->fresh()->status->value, 'The wave is not reopened.');
    }

    public function test_return_is_refused_for_an_order_that_is_not_postponed(): void
    {
        [$wave, $order] = $this->returnableWave(onHand: 50.0);

        $this->returnToPreparation($wave, $order, $this->operator())->assertStatus(422);
    }

    public function test_return_requires_the_preparation_update_permission(): void
    {
        [$wave, $order] = $this->returnableWave(onHand: 50.0);
        $user = $this->operator();
        $this->postpone($wave, $order, $user);

        // A role-bearing user WITHOUT operations.preparation.update is refused by the existing
        // route guard. No new permission was introduced for this action.
        $stranger = User::factory()->create(['company_id' => $this->company->id]);
        $role = Role::firstOrCreate(['slug' => 'test-no-prep'], ['name' => 'No Prep', 'is_system' => false]);
        $stranger->roles()->attach($role->id);
        $stranger->unsetRelation('roles');

        $this->actingAsUnprivileged($stranger)
            ->postJson("/api/preparation/waves/{$wave->id}/orders/{$order}/return-to-preparation")
            ->assertForbidden();

        self::assertNotNull($this->postponedAt($wave, $order));
    }

    public function test_return_causes_no_inventory_ledger_receipt_or_purchase_order_side_effects(): void
    {
        [$wave, $order] = $this->returnableWave(onHand: 50.0);
        $user = $this->operator();
        $this->postpone($wave, $order, $user);

        $before = [
            'inv' => DB::table('inventory_items')->orderBy('id')->get()->toJson(),
            'ledger' => DB::table('stock_ledger_entries')->count(),
            'grs' => DB::table('goods_receipts')->count(),
            'po' => DB::table('purchase_order_lines')->count(),
            'ei' => DB::table('wave_expected_incoming')->count(),
        ];

        $this->returnToPreparation($wave, $order, $user)->assertSuccessful();

        self::assertSame($before['inv'], DB::table('inventory_items')->orderBy('id')->get()->toJson());
        self::assertSame($before['ledger'], DB::table('stock_ledger_entries')->count());
        self::assertSame($before['grs'], DB::table('goods_receipts')->count());
        self::assertSame($before['po'], DB::table('purchase_order_lines')->count());
        self::assertSame($before['ei'], DB::table('wave_expected_incoming')->count());
    }

    public function test_postponed_orders_are_listed_with_their_return_eligibility(): void
    {
        [$wave, $order] = $this->returnableWave(onHand: 0.0);
        $user = $this->operator();
        $this->postpone($wave, $order, $user);

        $data = $this->deficit($wave, $user);

        // The postponed order is NOT in the decision queue -- it is a separate list, so the
        // uncovered calculation is untouched -- and it is not offered an action that would fail.
        self::assertCount(1, $data['postponed_orders']);
        self::assertSame($order, $data['postponed_orders'][0]['order_id']);
        self::assertFalse($data['postponed_orders'][0]['can_return']);
        self::assertNotNull($data['postponed_orders'][0]['return_blocked_reason']);
    }

    public function test_a_returnable_order_is_advertised_as_returnable(): void
    {
        [$wave, $order] = $this->returnableWave(onHand: 50.0);
        $user = $this->operator();
        $this->postpone($wave, $order, $user);

        $data = $this->deficit($wave, $user);

        self::assertCount(1, $data['postponed_orders']);
        self::assertTrue($data['postponed_orders'][0]['can_return']);
        self::assertNull($data['postponed_orders'][0]['return_blocked_reason']);
    }

    // -- Wave Completion consistency (PART 3 section 7) ------------------------

    private function waveCompletionPct(PreparationWave $wave, User $user): float
    {
        $res = $this->actingAs($user)->getJson("/api/preparation/waves/{$wave->id}");
        $res->assertOk();

        return (float) $res->json('data.completion_pct');
    }

    private function recordPrepared(PreparationWave $wave, string $productId, float $qty, User $user): void
    {
        $this->actingAs($user)
            ->patchJson("/api/preparation/waves/{$wave->id}/product-demand/{$productId}/prepared",
                ['prepared_qty' => $qty])
            ->assertSuccessful();
    }

    /** One product, fully prepared: the wave must read 100%, not the pre-write snapshot. */
    public function test_wave_completion_matches_product_preparation(): void
    {
        $wave = $this->wave();
        $fg = $this->product('FG', 'finished_good');
        $rm = $this->product('RM', 'raw_material', allowNegative: false);
        $this->productDemand($wave, $fg, 2.0);
        $this->bom($fg, [$rm => 1.0]);
        $this->stock($rm, 500.0);
        $this->orderInWave($wave, [[$fg, 2.0]]);
        $this->project($wave);

        $user = $this->operator();
        self::assertEqualsWithDelta(0.0, $this->waveCompletionPct($wave, $user), 0.01);

        $this->recordPrepared($wave, $fg, 2.0, $user);

        // Product-level truth.
        $row = DB::table('wave_product_demand')
            ->where('preparation_wave_id', $wave->id)->where('product_id', $fg)
            ->first(['required_qty', 'prepared_qty', 'remaining_qty', 'completion_pct']);
        self::assertEqualsWithDelta(2.0, (float) $row->required_qty, 0.001);
        self::assertEqualsWithDelta(2.0, (float) $row->prepared_qty, 0.001);
        self::assertEqualsWithDelta(0.0, (float) $row->remaining_qty, 0.001);
        self::assertEqualsWithDelta(100.0, (float) $row->completion_pct, 0.01);

        // The wave header must agree, derived from the same quantities.
        self::assertEqualsWithDelta(100.0, $this->waveCompletionPct($wave, $user), 0.01,
            'wave completion must derive from wave_product_demand, not a stale snapshot');

        self::assertEqualsWithDelta(2.0, (float) DB::table('preparation_waves')
            ->where('id', $wave->id)->value('total_units_prepared'), 0.001);
    }

    /** Aggregate is quantity-weighted, never an average of percentages. */
    public function test_wave_completion_aggregates_multiple_products_by_quantity(): void
    {
        $wave = $this->wave();
        $big = $this->product('Big', 'finished_good');
        $small = $this->product('Small', 'finished_good');
        $rm = $this->product('RM', 'raw_material', allowNegative: false);
        $this->productDemand($wave, $big, 9.0);
        $this->productDemand($wave, $small, 1.0);
        $this->bom($big, [$rm => 1.0]);
        $this->bom($small, [$rm => 1.0]);
        $this->stock($rm, 500.0);
        $this->orderInWave($wave, [[$big, 9.0], [$small, 1.0]]);
        $this->project($wave);

        $user = $this->operator();

        // Fully prepare ONLY the small product: 1 of 10 units => 10%.
        // An average of percentages would give (0% + 100%) / 2 = 50%.
        $this->recordPrepared($wave, $small, 1.0, $user);

        self::assertEqualsWithDelta(10.0, $this->waveCompletionPct($wave, $user), 0.01,
            'quantity-weighted, not an average of per-product percentages');

        $this->recordPrepared($wave, $big, 9.0, $user);
        self::assertEqualsWithDelta(100.0, $this->waveCompletionPct($wave, $user), 0.01);
    }

    /** The recomputation must not invent stock movement of any kind. */
    public function test_recording_preparation_has_no_inventory_or_ledger_side_effects(): void
    {
        $wave = $this->wave();
        $fg = $this->product('FG', 'finished_good');
        $rm = $this->product('RM', 'raw_material', allowNegative: false);
        $this->productDemand($wave, $fg, 2.0);
        $this->bom($fg, [$rm => 1.0]);
        $this->stock($rm, 500.0);
        $this->orderInWave($wave, [[$fg, 2.0]]);
        $this->project($wave);

        $user = $this->operator();
        $before = [
            'inv' => DB::table('inventory_items')->orderBy('id')->get()->toJson(),
            'ledger' => DB::table('stock_ledger_entries')->count(),
            'grs' => DB::table('goods_receipts')->count(),
            'po' => DB::table('purchase_order_lines')->count(),
            'orderStatus' => DB::table('orders')->orderBy('id')->pluck('status')->toJson(),
        ];

        $this->recordPrepared($wave, $fg, 2.0, $user);

        self::assertSame($before['inv'], DB::table('inventory_items')->orderBy('id')->get()->toJson());
        self::assertSame($before['ledger'], DB::table('stock_ledger_entries')->count());
        self::assertSame($before['grs'], DB::table('goods_receipts')->count());
        self::assertSame($before['po'], DB::table('purchase_order_lines')->count());
        self::assertSame($before['orderStatus'], DB::table('orders')->orderBy('id')->pluck('status')->toJson());
    }

    /** Re-reading the wave returns the same completion: it is persisted, not per-request. */
    public function test_wave_completion_persists_across_reads(): void
    {
        $wave = $this->wave();
        $fg = $this->product('FG', 'finished_good');
        $rm = $this->product('RM', 'raw_material', allowNegative: false);
        $this->productDemand($wave, $fg, 4.0);
        $this->bom($fg, [$rm => 1.0]);
        $this->stock($rm, 500.0);
        $this->orderInWave($wave, [[$fg, 4.0]]);
        $this->project($wave);

        $user = $this->operator();
        $this->recordPrepared($wave, $fg, 3.0, $user);

        self::assertEqualsWithDelta(75.0, $this->waveCompletionPct($wave, $user), 0.01);
        self::assertEqualsWithDelta(75.0, $this->waveCompletionPct($wave, $user), 0.01);
    }
}

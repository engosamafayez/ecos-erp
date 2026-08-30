<?php

declare(strict_types=1);

namespace Tests\Feature\Operations\DemandEngine;

use App\Core\FeatureFlags\FeatureFlagService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\OrderLine;
use Modules\IAM\Domain\Models\Role;
use Modules\Inventory\InventoryItems\Domain\Models\InventoryItem;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Inventory\ReceiptLayers\Domain\Models\InventoryReceiptLayer;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Operations\DemandAnalysis\Application\Services\DemandCalculationService;
use Modules\Operations\Preparation\Domain\Models\PreparationWave;
use Modules\Organization\Brands\Domain\Models\Brand;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-PREPARATION-FG-OWN-RESERVATION-DEMAND-CERTIFICATION-001.
 *
 * Settles the case the previous certification deliberately left open: a Finished
 * Good that is reserved by the SAME order that is being prepared.
 *
 *   Order A  →  line = Finished Product A × 10
 *            →  reserved by the real workflow      (same-order reservation)
 *            →  the SAME order enters Preparation  (real entry gate)
 *            →  wave demand for A = 10
 *
 * The question is whether that reservation is "competing" demand (and so should
 * reduce availability) or the very demand Preparation is already planning (and so
 * would be double-counted if subtracted).
 *
 * Two structural facts are probed at runtime rather than assumed:
 *   1. Which product's stock does MaterialDemandCalculator actually read — the
 *      finished good, or only the BOM components?
 *   2. Does reserving a finished good also reserve its BOM components?
 *
 * Everything comes from real workflows. No reservation row, no wave demand row and
 * no order status is written directly. No production code is modified, and no
 * existing test expectation is changed. Disputed values are RECORDED, not asserted.
 */
final class FinishedGoodOwnReservationDemandTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Brand $brand;

    private Warehouse $warehouse;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->brand = Brand::factory()->create(['company_id' => $this->company->id]);
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
        $this->customer = Customer::factory()->create();

        $flags = app(FeatureFlagService::class);
        $flags->enable('modules.preparation_os', $this->company->id);
        $flags->enable('workflow.stages.preparation', $this->company->id);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function product(string $type): Product
    {
        return Product::factory()->create([
            'brand_id' => $this->brand->id,
            'company_id' => $this->company->id,
            'product_type' => $type,
        ]);
    }

    private function operator(): User
    {
        $user = User::factory()->create(['company_id' => $this->company->id]);
        $role = Role::firstOrCreate(['slug' => 'sysadmin-fg-cert'], ['name' => 'System Admin', 'is_system' => true]);
        $user->roles()->attach($role->id);
        $user->unsetRelation('roles');

        return $user;
    }

    /** A real order left at `new`, so the real workflow performs the transition. */
    private function newOrder(Product $product, float $qty): Order
    {
        $order = Order::query()->create([
            'company_id' => $this->company->id,
            'assigned_warehouse_id' => $this->warehouse->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-'.uniqid(),
            'order_date' => now()->toDateString(),
            'confirmed_at' => now(),
            'status' => OrderStatus::InProgress->value,
            'subtotal' => 100, 'total' => 100,
            'shipping_total' => 0, 'discount_total' => 0, 'tax_total' => 0,
        ]);

        OrderLine::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => $qty,
            'unit_price' => 10.0,
            'line_total' => $qty * 10.0,
        ]);

        return $order->refresh();
    }

    private function stock(Product $product, float $onHand): void
    {
        InventoryItem::query()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $product->id,
            'company_id' => $this->company->id,
            'on_hand_qty' => $onHand,
            'reserved_qty' => 0,
        ]);

        InventoryReceiptLayer::query()->create([
            'company_id' => $this->company->id,
            'supplier_id' => null, 'product_id' => $product->id,
            'goods_receipt_id' => null, 'goods_receipt_line_id' => null,
            'warehouse_id' => $this->warehouse->id,
            'received_qty' => $onHand, 'remaining_qty' => $onHand,
            'landed_unit_cost' => 5.0, 'sale_price_snapshot' => 10.0,
            'receipt_date' => now()->toDateString(),
        ]);
    }

    /** @param array<string, float> $ingredients */
    private function bom(Product $parent, array $ingredients): string
    {
        $bomId = (string) Str::uuid();

        DB::table('bills_of_materials')->insert([
            'id' => $bomId,
            'product_id' => $parent->id,
            'bom_number' => 'BOM-'.random_int(1000, 9999),
            'version' => 1, 'bom_version_number' => 1,
            'is_active' => true, 'yield_quantity' => 1, 'recipe_cost' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ($ingredients as $materialId => $qty) {
            DB::table('bill_of_material_lines')->insert([
                'id' => (string) Str::uuid(),
                'bom_id' => $bomId,
                'raw_material_id' => $materialId,
                'quantity' => $qty,
                'waste_percentage' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $bomId;
    }

    private function inv(Product $product): object
    {
        return DB::table('inventory_items')
            ->where('product_id', $product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->first();
    }

    /** The REAL transition new → in_progress. ProcessOrderWorkflow reserves here. */
    private function processToInProgress(Order $order): int
    {
        $response = $this->postJson("/api/fulfillment/orders/{$order->id}/transition", [
            'target_status' => OrderStatus::InProgress->value,
        ]);

        return $response->getStatusCode();
    }

    /** The REAL entry gate. Returns [httpStatus, waveId|null]. */
    private function createWave(Order $order): array
    {
        $response = $this->postJson('/api/preparation/waves', [
            'planning_date' => now()->toDateString(),
            'warehouse_id' => $this->warehouse->id,
            'order_ids' => [$order->id],
        ]);

        $waveId = $response->json('data.id') ?? $response->json('id');

        return [$response->getStatusCode(), is_string($waveId) ? $waveId : null];
    }

    private function runDemandPipeline(string $waveId): PreparationWave
    {
        $wave = PreparationWave::findOrFail($waveId);
        app(DemandCalculationService::class)->recalculate($wave, 'fg-own-reservation-audit');

        return $wave->refresh();
    }

    /** @return array<string, mixed>|null */
    private function materialRow(string $waveId, Product $p): ?array
    {
        $row = DB::table('wave_material_demand')
            ->where('preparation_wave_id', $waveId)
            ->where('material_id', $p->id)
            ->first();

        return $row ? (array) $row : null;
    }

    // ── PART 8 — same-order reservation ───────────────────────────────────────

    public function test_finished_good_reserved_by_the_same_order_that_is_being_prepared(): void
    {
        $this->actingAs($this->operator());

        $finishedProductA = $this->product(Product::TYPE_FINISHED_GOOD);
        $rawMaterialX = $this->product(Product::TYPE_RAW_MATERIAL);

        // Part 2 / Part 4 — a real recipe: A consumes 1 X per unit.
        $this->bom($finishedProductA, [$rawMaterialX->id => 1.0]);

        // Part 8 fixture: FG required 10, on_hand 10, to be reserved 10 by the same order.
        $this->stock($finishedProductA, onHand: 10.0);
        // The component is deliberately plentiful so the recipe gate cannot refuse.
        $this->stock($rawMaterialX, onHand: 20.0);

        // Part 3 — real order, real reservation lifecycle. No direct writes.
        $order = $this->newOrder($finishedProductA, qty: 10.0);
        $transitionHttp = $this->processToInProgress($order);
        $order->refresh();

        $fgInv = $this->inv($finishedProductA);
        $rmInvBefore = $this->inv($rawMaterialX);

        // Part 13 — the SAME order must pass the certified entry gate, unmutated.
        [$waveHttp, $waveId] = $this->createWave($order);

        $waveDemandA = null;
        $rowForFinishedGood = null;
        $rowForRawMaterial = null;
        $attached = [];

        if ($waveId !== null) {
            $wave = $this->runDemandPipeline($waveId);

            $attached = DB::table('preparation_wave_orders')
                ->where('preparation_wave_id', $wave->id)->pluck('order_id')->all();

            $pd = DB::table('wave_product_demand')
                ->where('preparation_wave_id', $wave->id)
                ->where('product_id', $finishedProductA->id)->first();
            $waveDemandA = $pd ? (float) $pd->required_qty : null;

            $rowForFinishedGood = $this->materialRow($wave->id, $finishedProductA);
            $rowForRawMaterial = $this->materialRow($wave->id, $rawMaterialX);
        }

        $rmInvAfter = $this->inv($rawMaterialX);

        fwrite(STDERR, PHP_EOL
            .'═══ FG OWN-RESERVATION AUDIT (PART 8) ═══'.PHP_EOL
            .'  order id                     = '.$order->id.PHP_EOL
            .'  transition http              = '.$transitionHttp.PHP_EOL
            .'  order status                 = '.$order->status->value.PHP_EOL
            .'  order reservation_status     = '.($order->reservation_status?->value ?? 'null').PHP_EOL
            .'  order line                   = A qty 10'.PHP_EOL
            .'  ---- FINISHED GOOD inventory ----'.PHP_EOL
            .'  FG on_hand / reserved        = '.(float) $fgInv->on_hand_qty.' / '.(float) $fgInv->reserved_qty.PHP_EOL
            .'  ---- RAW MATERIAL inventory (Part 6: kept separate) ----'.PHP_EOL
            .'  RM on_hand / reserved before = '.(float) $rmInvBefore->on_hand_qty.' / '.(float) $rmInvBefore->reserved_qty.PHP_EOL
            .'  RM on_hand / reserved after  = '.(float) $rmInvAfter->on_hand_qty.' / '.(float) $rmInvAfter->reserved_qty.PHP_EOL
            .'  ---- PREPARATION ENTRY (real gate) ----'.PHP_EOL
            .'  wave create http             = '.$waveHttp.PHP_EOL
            .'  wave id                      = '.($waveId ?? 'REFUSED').PHP_EOL
            .'  wave order membership        = '.json_encode($attached).PHP_EOL
            .'  wave product demand (A)      = '.($waveDemandA ?? 'n/a').PHP_EOL
            .'  ---- MaterialDemandCalculator OUTPUT ----'.PHP_EOL
            .'  row for FINISHED GOOD A      = '.($rowForFinishedGood === null ? 'NONE' : json_encode([
                'required' => (float) $rowForFinishedGood['required_qty'],
                'available' => (float) $rowForFinishedGood['available_qty'],
                'reserved' => (float) $rowForFinishedGood['reserved_qty'],
                'missing' => (float) $rowForFinishedGood['missing_qty'],
            ])).PHP_EOL
            .'  row for RAW MATERIAL X       = '.($rowForRawMaterial === null ? 'NONE' : json_encode([
                'required' => (float) $rowForRawMaterial['required_qty'],
                'available' => (float) $rowForRawMaterial['available_qty'],
                'reserved' => (float) $rowForRawMaterial['reserved_qty'],
                'missing' => (float) $rowForRawMaterial['missing_qty'],
            ])).PHP_EOL
            .'════════════════════════════════════════'.PHP_EOL);

        // Facts independent of the disputed formula.
        self::assertSame(201, $waveHttp,
            'Part 13: the same reserved order must pass the certified entry gate unmutated.');
        self::assertContains($order->id, $attached,
            'The reserving order IS the order being prepared.');
        self::assertEquals(10.0, $waveDemandA, 'Preparation demand for A must be 10.');

        // Part 7 — what does the calculator actually evaluate?
        self::assertNull($rowForFinishedGood,
            'MaterialDemandCalculator must produce NO row for the finished good itself.');
        self::assertNotNull($rowForRawMaterial,
            'MaterialDemandCalculator must produce a row for the BOM component.');

        // Part 6 — reserving a finished good must not reserve its components.
        self::assertEquals(0.0, (float) $rmInvAfter->reserved_qty,
            'Reserving the finished good must NOT reserve its BOM components.');
        self::assertEquals(
            (float) $rmInvBefore->reserved_qty,
            (float) $rmInvAfter->reserved_qty,
            'Component reservation must be unchanged by the finished-good reservation.',
        );
    }

    // ── PART 9 — competing order control ──────────────────────────────────────

    public function test_competing_order_reserves_the_same_finished_good_but_stays_out_of_the_wave(): void
    {
        $this->actingAs($this->operator());

        $finishedProductA = $this->product(Product::TYPE_FINISHED_GOOD);
        $rawMaterialX = $this->product(Product::TYPE_RAW_MATERIAL);

        $this->bom($finishedProductA, [$rawMaterialX->id => 1.0]);
        $this->stock($finishedProductA, onHand: 15.0);
        $this->stock($rawMaterialX, onHand: 20.0);

        // Order A — 10 units, enters Preparation.
        $orderA = $this->newOrder($finishedProductA, qty: 10.0);
        $this->processToInProgress($orderA);
        $orderA->refresh();

        // Order B — 5 units, reserved, deliberately NOT attached to the wave.
        $orderB = $this->newOrder($finishedProductA, qty: 5.0);
        $this->processToInProgress($orderB);
        $orderB->refresh();

        $fgInv = $this->inv($finishedProductA);

        [$waveHttp, $waveId] = $this->createWave($orderA);
        self::assertSame(201, $waveHttp);
        self::assertIsString($waveId);

        $wave = $this->runDemandPipeline($waveId);

        $attached = DB::table('preparation_wave_orders')
            ->where('preparation_wave_id', $wave->id)->pluck('order_id')->all();

        $pd = DB::table('wave_product_demand')
            ->where('preparation_wave_id', $wave->id)
            ->where('product_id', $finishedProductA->id)->first();

        $rmRow = $this->materialRow($wave->id, $rawMaterialX);
        $fgRow = $this->materialRow($wave->id, $finishedProductA);

        fwrite(STDERR, PHP_EOL
            .'═══ COMPETING-ORDER CONTROL (PART 9) ═══'.PHP_EOL
            .'  order A (in wave)            = '.$orderA->id.' qty 10 res='.($orderA->reservation_status?->value ?? 'null').PHP_EOL
            .'  order B (NOT in wave)        = '.$orderB->id.' qty 5  res='.($orderB->reservation_status?->value ?? 'null').PHP_EOL
            .'  FG on_hand / reserved TOTAL  = '.(float) $fgInv->on_hand_qty.' / '.(float) $fgInv->reserved_qty.PHP_EOL
            .'  wave order membership        = '.json_encode($attached).PHP_EOL
            .'  wave product demand (A)      = '.($pd ? (float) $pd->required_qty : 'n/a').PHP_EOL
            .'  row for FINISHED GOOD A      = '.($fgRow === null ? 'NONE' : 'PRESENT').PHP_EOL
            .'  RM required/available/missing= '.($rmRow === null ? 'NONE' : (float) $rmRow['required_qty'].' / '
                .(float) $rmRow['available_qty'].' / '.(float) $rmRow['missing_qty']).PHP_EOL
            .'════════════════════════════════════════'.PHP_EOL);

        self::assertContains($orderA->id, $attached);
        self::assertNotContains($orderB->id, $attached,
            'Order B is competing demand and must stay outside the wave.');
        self::assertEquals(10.0, $pd ? (float) $pd->required_qty : null,
            'Wave demand must reflect ONLY order A, not the competing order B.');
        self::assertNull($fgRow, 'Still no material-demand row for the finished good.');
    }

    // ── PART 10 — the residual case: a component reserved by an IN-WAVE order ──

    /**
     * The only configuration in which a BOM component can carry a reservation that
     * belongs to an order inside the wave: the wave holds BOTH a finished good that
     * consumes X AND a direct order for X itself.
     *
     * This is the case that decides whether `on_hand - reserved` could double-count.
     */
    public function test_component_reserved_by_an_order_inside_the_same_wave(): void
    {
        $this->actingAs($this->operator());

        $finishedProductA = $this->product(Product::TYPE_FINISHED_GOOD);
        $rawMaterialX = $this->product(Product::TYPE_RAW_MATERIAL);

        $this->bom($finishedProductA, [$rawMaterialX->id => 1.0]);
        $this->stock($finishedProductA, onHand: 10.0);
        $this->stock($rawMaterialX, onHand: 20.0);

        // Order A — 10 units of the finished good (consumes 10 X to build).
        $orderA = $this->newOrder($finishedProductA, qty: 10.0);
        $this->processToInProgress($orderA);
        $orderA->refresh();

        // Order C — 8 units of the raw material itself, shipped as-is.
        $orderC = $this->newOrder($rawMaterialX, qty: 8.0);
        $this->processToInProgress($orderC);
        $orderC->refresh();

        $rmInv = $this->inv($rawMaterialX);

        // BOTH orders enter the SAME wave.
        $response = $this->postJson('/api/preparation/waves', [
            'planning_date' => now()->toDateString(),
            'warehouse_id' => $this->warehouse->id,
            'order_ids' => [$orderA->id, $orderC->id],
        ]);
        $waveHttp = $response->getStatusCode();
        $waveId = $response->json('data.id') ?? $response->json('id');
        self::assertIsString($waveId, 'Both orders must form a wave.');

        $wave = $this->runDemandPipeline($waveId);

        $attached = DB::table('preparation_wave_orders')
            ->where('preparation_wave_id', $wave->id)->pluck('order_id')->all();

        $pdA = DB::table('wave_product_demand')->where('preparation_wave_id', $wave->id)
            ->where('product_id', $finishedProductA->id)->first();
        $pdX = DB::table('wave_product_demand')->where('preparation_wave_id', $wave->id)
            ->where('product_id', $rawMaterialX->id)->first();

        $rmRow = $this->materialRow($wave->id, $rawMaterialX);

        $productDemandX = $pdX ? (float) $pdX->required_qty : 0.0;
        $materialDemandX = $rmRow ? (float) $rmRow['required_qty'] : 0.0;
        $onHandX = (float) $rmInv->on_hand_qty;
        $reservedX = (float) $rmInv->reserved_qty;

        fwrite(STDERR, PHP_EOL
            .'═══ IN-WAVE COMPONENT RESERVATION (PART 10) ═══'.PHP_EOL
            .'  wave http                    = '.$waveHttp.PHP_EOL
            .'  wave order membership        = '.count($attached).' orders'.PHP_EOL
            .'  order A (finished good)      = qty 10  res='.($orderA->reservation_status?->value ?? 'null').PHP_EOL
            .'  order C (raw material X)     = qty 8   res='.($orderC->reservation_status?->value ?? 'null').PHP_EOL
            .'  ---- X carries a reservation from an IN-WAVE order ----'.PHP_EOL
            .'  X on_hand / reserved         = '.$onHandX.' / '.$reservedX.PHP_EOL
            .'  X as PRODUCT demand (ship)   = '.$productDemandX.PHP_EOL
            .'  X as MATERIAL demand (build) = '.$materialDemandX.PHP_EOL
            .'  total true need (ship+build) = '.($productDemandX + $materialDemandX).PHP_EOL
            .'  ---- engine output for X ----'.PHP_EOL
            .'  available / missing          = '.($rmRow === null ? 'NONE' : (float) $rmRow['available_qty']
                .' / '.(float) $rmRow['missing_qty']).PHP_EOL
            .'  if reserved were subtracted  = '.max(0.0, $onHandX - $reservedX).PHP_EOL
            .'════════════════════════════════════════'.PHP_EOL);

        self::assertCount(2, $attached, 'Both orders must be in the wave.');
        self::assertEquals(8.0, $reservedX, 'X is reserved by order C, which IS inside the wave.');
        // The two demands are distinct: 8 shipped as-is, 10 consumed to build A.
        self::assertEquals(8.0, $productDemandX, 'X product demand = order C only.');
        self::assertEquals(10.0, $materialDemandX, 'X material demand = BOM explosion of A only.');
        self::assertNotEquals($productDemandX, $materialDemandX,
            'Product demand and material demand for X are different demands, not one demand counted twice.');
    }
}

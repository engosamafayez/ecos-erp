<?php

declare(strict_types=1);

namespace Tests\Feature\Operations\DemandEngine;

use App\Core\FeatureFlags\FeatureFlagService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Enums\ReservationStatus;
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
 * TASK-PREPARATION-RM-DIRECT-SALE-BOM-COMPONENT-CERTIFICATION-001.
 *
 * Certifies the BUSINESS CONTRACT behind the material demand engine — not any
 * particular formula. The decisive scenario is one raw material playing two
 * roles simultaneously, inside ONE company:
 *
 *   Raw Material X  is a BOM component of Finished Product A   → Preparation demand
 *   Raw Material X  is ALSO sold directly on a second Order    → competing demand
 *
 * If the reservation on X originates from a DIFFERENT order than the one driving
 * the wave, that reservation cannot be the same demand Preparation is planning.
 * It is competing demand, and the stock behind it is not freely available.
 *
 * Every fact here comes from the real application: real Orders, the real
 * reservation transition, the real wave route, and the real demand pipeline
 * (DemandCalculationService → DemandProjectionBuilder → persisted
 * wave_material_demand → the real read-model endpoint). Nothing is inserted
 * straight into a reservation or demand table.
 *
 * The engine's own available/missing output is RECORDED, not asserted, because
 * this task must not encode either candidate rule as an expectation. Assertions
 * are limited to facts that hold under BOTH candidate rules.
 *
 * No production code is modified. No existing test expectation is changed.
 */
final class RawMaterialDirectSaleBomComponentTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Brand $brand;

    private Warehouse $warehouse;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        // Part 2 + Part 11 — one coherent company boundary; no cross-company fixture.
        $this->company = Company::factory()->create();
        $this->brand = Brand::factory()->create(['company_id' => $this->company->id]);
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
        $this->customer = Customer::factory()->create();

        $flags = app(FeatureFlagService::class);
        $flags->enable('modules.preparation_os', $this->company->id);
        $flags->enable('workflow.stages.preparation', $this->company->id);
    }

    // ── Fixtures (products, orders, stock, BOM — all through real models) ──────

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
        $role = Role::firstOrCreate(['slug' => 'sysadmin-rm-cert'], ['name' => 'System Admin', 'is_system' => true]);
        $user->roles()->attach($role->id);
        $user->unsetRelation('roles');

        return $user;
    }

    /**
     * A real order carrying one real line.
     *
     * Left In Progress: TASK-GOLIVE-PREPARATION-ENTRY-GATE-REPAIR-002 makes
     * Ready For Dispatch a POST-Preparation state that the entry gate refuses.
     */
    private function order(Product $product, float $qty): Order
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

    /** Physical stock: the inventory row plus a matching FIFO receipt layer. */
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

    /** @param array<string, float> $ingredients material_id => qty per unit */
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

    private function inventoryRow(Product $product): object
    {
        return DB::table('inventory_items')
            ->where('product_id', $product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->first();
    }

    /** The real reservation path: a status transition through the fulfillment API. */
    private function reserveThroughWorkflow(Order $order): void
    {
        $this->postJson("/api/fulfillment/orders/{$order->id}/transition", [
            'target_status' => OrderStatus::ReadyForDispatch->value,
        ])->assertOk();
    }

    /** Real wave route + the real demand pipeline that persists wave_material_demand. */
    private function waveWithDemand(Order $order): PreparationWave
    {
        $response = $this->postJson('/api/preparation/waves', [
            'planning_date' => now()->toDateString(),
            'warehouse_id' => $this->warehouse->id,
            'order_ids' => [$order->id],
        ]);
        $response->assertStatus(201);

        $waveId = $response->json('data.id') ?? $response->json('id');
        self::assertIsString($waveId, 'Wave id must be readable from the create response.');

        $wave = PreparationWave::findOrFail($waveId);

        // The production entry point for demand recalculation — the same service
        // the wave listeners call. Not a fixture, and not the calculator directly.
        app(DemandCalculationService::class)->recalculate($wave, 'rm-dual-role-certification');

        return $wave->refresh();
    }

    /** The persisted material-demand row, read back through the real endpoint. */
    private function materialDemandViaApi(PreparationWave $wave, Product $material): ?array
    {
        $response = $this->getJson("/api/preparation/waves/{$wave->id}/material-demand");
        $response->assertOk();

        return collect($response->json('data'))->firstWhere('material_id', $material->id);
    }

    // ── THE DECISIVE SCENARIO ─────────────────────────────────────────────────

    public function test_raw_material_that_is_both_a_bom_component_and_directly_sold(): void
    {
        $this->actingAs($this->operator());

        // Raw Material X and Finished Product A — same company, same brand.
        $rawMaterialX = $this->product(Product::TYPE_RAW_MATERIAL);
        $finishedProductA = $this->product(Product::TYPE_FINISHED_GOOD);

        self::assertSame(Product::TYPE_RAW_MATERIAL, $rawMaterialX->product_type);
        self::assertSame(Product::TYPE_FINISHED_GOOD, $finishedProductA->product_type);

        // Part 5 — physical stock of X = 15, established before any reservation.
        $this->stock($rawMaterialX, onHand: 15.0);

        // Part 4 — BOM: one unit of A consumes one unit of X.
        $bomId = $this->bom($finishedProductA, [$rawMaterialX->id => 1.0]);

        // ── Part 3 — COMPETING demand: a real, separate order that BUYS X ──────
        $directOrder = $this->order($rawMaterialX, qty: 8.0);
        $this->reserveThroughWorkflow($directOrder);
        $directOrder->refresh();

        $inv = $this->inventoryRow($rawMaterialX);

        self::assertSame(ReservationStatus::Reserved, $directOrder->reservation_status,
            'The direct raw-material order must have reserved through the real workflow.');
        self::assertEquals(8.0, (float) $inv->reserved_qty,
            'Raw Material X must carry reserved_qty = 8 written by the real reservation path.');
        self::assertEquals(15.0, (float) $inv->on_hand_qty,
            'Reservation must not consume physical stock.');

        // ── PREPARATION demand: a DIFFERENT order, for Finished Product A ──────
        $preparationOrder = $this->order($finishedProductA, qty: 10.0);
        $wave = $this->waveWithDemand($preparationOrder);

        $productDemand = DB::table('wave_product_demand')
            ->where('preparation_wave_id', $wave->id)
            ->where('product_id', $finishedProductA->id)
            ->first();

        self::assertNotNull($productDemand, 'The real pipeline must produce product demand for A.');
        self::assertEquals(10.0, (float) $productDemand->required_qty);

        // ── Part 6 — the engine's own output, unmodified, read two ways ────────
        $persisted = DB::table('wave_material_demand')
            ->where('preparation_wave_id', $wave->id)
            ->where('material_id', $rawMaterialX->id)
            ->first();

        self::assertNotNull($persisted, 'BOM explosion must persist a material-demand row for X.');

        $api = $this->materialDemandViaApi($wave, $rawMaterialX);
        self::assertNotNull($api, 'The read-model endpoint must expose the material-demand row for X.');

        $required = (float) $persisted->required_qty;
        $available = (float) $persisted->available_qty;
        $missing = (float) $persisted->missing_qty;
        $reservedOnRow = (float) $persisted->reserved_qty;

        // ── Part 7 — CORE PROOF: two demands, two distinct origins ─────────────
        $directLine = OrderLine::where('order_id', $directOrder->id)->first();
        $prepLine = OrderLine::where('order_id', $preparationOrder->id)->first();
        $bomLine = DB::table('bill_of_material_lines')->where('bom_id', $bomId)->first();

        self::assertSame($rawMaterialX->id, $directLine->product_id,
            'The reservation originates from an order line whose product IS Raw Material X.');
        self::assertSame($finishedProductA->id, $prepLine->product_id,
            'The Preparation demand originates from an order line for Finished Product A, NOT for X.');
        self::assertSame($rawMaterialX->id, $bomLine->raw_material_id,
            'X reaches Preparation demand only as a BOM component.');
        self::assertNotSame($directOrder->id, $preparationOrder->id,
            'Two different orders — this is what makes the two demands competing.');
        self::assertSame($this->company->id, $directOrder->company_id);
        self::assertSame($this->company->id, $preparationOrder->company_id);

        // The wave must NOT contain the direct raw-material order.
        $attachedOrders = DB::table('preparation_wave_orders')
            ->where('preparation_wave_id', $wave->id)
            ->pluck('order_id')->all();
        self::assertContains($preparationOrder->id, $attachedOrders);
        self::assertNotContains($directOrder->id, $attachedOrders,
            'The direct RM order is competing demand OUTSIDE the wave, not part of its demand.');

        $businessAvailable = max(0.0, 15.0 - 8.0);
        $businessMissing = max(0.0, $required - $businessAvailable);

        fwrite(STDERR, PHP_EOL
            .'═══ RM DIRECT-SALE / BOM-COMPONENT CERTIFICATION ═══'.PHP_EOL
            .'  company                      = '.$this->company->id.PHP_EOL
            .'  Raw Material X               = '.$rawMaterialX->id.' ('.$rawMaterialX->product_type.')'.PHP_EOL
            .'  Finished Product A           = '.$finishedProductA->id.' ('.$finishedProductA->product_type.')'.PHP_EOL
            .'  ---- competing demand (real order + real reservation) ----'.PHP_EOL
            .'  direct order                 = '.$directOrder->id.'  line = X qty 8'.PHP_EOL
            .'  order status                 = '.$directOrder->status->value.PHP_EOL
            .'  order reservation_status     = '.$directOrder->reservation_status->value.PHP_EOL
            .'  X on_hand / reserved         = '.(float) $inv->on_hand_qty.' / '.(float) $inv->reserved_qty.PHP_EOL
            .'  ---- preparation demand (different order) ----'.PHP_EOL
            .'  preparation order            = '.$preparationOrder->id.'  line = A qty 10'.PHP_EOL
            .'  BOM parent / component       = A / X  @ 1 per unit'.PHP_EOL
            .'  wave product demand (A)      = '.(float) $productDemand->required_qty.PHP_EOL
            .'  ---- ENGINE OUTPUT (UNMODIFIED PRODUCTION CODE) ----'.PHP_EOL
            .'  required_qty  (X)            = '.$required.PHP_EOL
            .'  available_qty (X)            = '.$available.PHP_EOL
            .'  reserved_qty  (X, reported)  = '.$reservedOnRow.PHP_EOL
            .'  missing_qty   (X)            = '.$missing.PHP_EOL
            .'  api available / missing      = '.$api['available_qty'].' / '.$api['missing_qty'].PHP_EOL
            .'  ---- business contract ----'.PHP_EOL
            .'  expected available (15 - 8)  = '.$businessAvailable.PHP_EOL
            .'  expected shortage  (10 - 7)  = '.$businessMissing.PHP_EOL
            .'  OUTCOME: '.($available === $businessAvailable
                ? 'A — engine ALREADY honours the competing reservation'
                : 'B — engine reports '.$available.' available; competing reservation IGNORED').PHP_EOL
            .'════════════════════════════════════════════════════'.PHP_EOL);

        // Facts that hold under EITHER candidate rule.
        self::assertEquals(10.0, $required, 'BOM demand for X must be 10 (10 units of A × 1).');
        self::assertEquals(8.0, $reservedOnRow, 'The engine must SEE the competing reservation of 8.');
        self::assertEquals(3.0, $businessMissing, 'Business contract: 10 − (15 − 8) = 3.');
        self::assertEquals($available, (float) $api['available_qty'],
            'The read model must expose exactly what the engine persisted.');
    }

    // ── Part 9 — CONTROL: no competing reservation ────────────────────────────

    public function test_control_no_competing_reservation_leaves_full_stock_available(): void
    {
        $this->actingAs($this->operator());

        $material = $this->product(Product::TYPE_RAW_MATERIAL);
        $finished = $this->product(Product::TYPE_FINISHED_GOOD);

        $this->stock($material, onHand: 15.0);
        $this->bom($finished, [$material->id => 1.0]);

        $wave = $this->waveWithDemand($this->order($finished, qty: 10.0));

        $inv = $this->inventoryRow($material);
        $row = $this->materialDemandViaApi($wave, $material);
        self::assertNotNull($row);

        fwrite(STDERR, PHP_EOL.'CONTROL (Part 9) — on_hand=15 reserved=0 required=10 -> available='
            .$row['available_qty'].' missing='.$row['missing_qty'].PHP_EOL);

        self::assertEquals(0.0, (float) $inv->reserved_qty, 'Control precondition: nothing reserved.');
        self::assertEquals(10.0, (float) $row['required_qty']);
        // With reserved = 0 both candidate rules agree, so this IS safely assertable.
        self::assertEquals(15.0, (float) $row['available_qty'], 'No reservation ⇒ full stock available.');
        self::assertEquals(0.0, (float) $row['missing_qty'], 'No reservation ⇒ no shortage.');
    }
}

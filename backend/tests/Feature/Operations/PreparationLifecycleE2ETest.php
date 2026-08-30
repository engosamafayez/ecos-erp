<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Core\FeatureFlags\FeatureFlagService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Enums\ReservationStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\OrderLine;
use Modules\IAM\Domain\Models\Role;
use Modules\Inventory\InventoryItems\Domain\Models\InventoryItem;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Inventory\ReceiptLayers\Domain\Models\InventoryReceiptLayer;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-GOLIVE-PREPARATION-BATCH-B-E2E-CERTIFICATION-001 — Parts 3, 4, 9, 10, 11,
 * 12, 16, 17, 20.
 *
 * Real orders, real HTTP, real engines, real database. No synthetic order ids:
 * every wave in this file references persisted Order rows, which is precisely
 * the gap Batch A recorded (the existing wave suites attach Str::uuid() strings).
 */
final class PreparationLifecycleE2ETest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Warehouse $warehouse;

    private Customer $customer;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
        $this->customer = Customer::factory()->create();

        $flags = app(FeatureFlagService::class);
        $flags->enable('modules.preparation_os', $this->company->id);
        $flags->enable('workflow.stages.preparation', $this->company->id);

        $this->actor = User::factory()->create(['company_id' => $this->company->id]);
        $role = Role::firstOrCreate(
            ['slug' => 'sysadmin-prep-e2e'],
            ['name' => 'System Admin', 'is_system' => true],
        );
        $this->actor->roles()->attach($role->id);
        $this->actor->unsetRelation('roles');

        $this->actingAs($this->actor);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function order(): Order
    {
        return Order::query()->create([
            'company_id' => $this->company->id,
            'assigned_warehouse_id' => $this->warehouse->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-'.uniqid(),
            'order_date' => now()->toDateString(),
            'confirmed_at' => now(),
            'status' => OrderStatus::InProgress->value,
            'subtotal' => 100,
            'total' => 100,
            'shipping_total' => 0,
            'discount_total' => 0,
            'tax_total' => 0,
        ]);
    }

    private function stockProduct(Product $product, float $onHand): void
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
            'supplier_id' => null,
            'product_id' => $product->id,
            'goods_receipt_id' => null,
            'goods_receipt_line_id' => null,
            'warehouse_id' => $this->warehouse->id,
            'received_qty' => $onHand,
            'remaining_qty' => $onHand,
            'landed_unit_cost' => 20.0,
            'sale_price_snapshot' => 50.0,
            'receipt_date' => now()->toDateString(),
        ]);
    }

    private function line(Order $order, Product $product, float $qty): void
    {
        OrderLine::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => $qty,
            'unit_price' => 50.0,
            'line_total' => $qty * 50.0,
        ]);
    }

    private function reserve(Order $order): void
    {
        $this->postJson("/api/fulfillment/orders/{$order->id}/transition", [
            'target_status' => OrderStatus::ReadyForDispatch->value,
        ])->assertOk();
    }

    /** @param list<string> $orderIds */
    private function createWave(array $orderIds): string
    {
        $response = $this->postJson('/api/preparation/waves', [
            'planning_date' => now()->toDateString(),
            'warehouse_id' => $this->warehouse->id,
            'order_ids' => $orderIds,
        ]);

        $response->assertStatus(201);

        $waveId = $response->json('data.id') ?? $response->json('id');
        self::assertIsString($waveId, 'Wave id must be readable from the create response.');

        return $waveId;
    }

    private function inventory(Product $product): InventoryItem
    {
        return InventoryItem::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->firstOrFail();
    }

    // ── Parts 3, 4, 11, 12: real order → reservation → wave → demand ──────────

    public function test_real_orders_form_a_wave_whose_demand_aggregates_correctly(): void
    {
        $product = Product::factory()->create();
        $this->stockProduct($product, onHand: 50);

        // Order A: 3 units. Order B: 2 units. Demand for the product must be 5.
        //
        // TASK-GOLIVE-PREPARATION-ENTRY-GATE-REPAIR-002: the orders are left In
        // Progress and are deliberately NOT reserved first. Reserving would move
        // them to Ready for Dispatch, which is a POST-Preparation state and is
        // refused by the entry gate. Reservation is also not a Preparation
        // prerequisite — see test_an_eligible_order_is_accepted_even_when_not_reserved
        // in PreparationEntryGateTest.
        $orderA = $this->order();
        $this->line($orderA, $product, 3.0);
        $orderB = $this->order();
        $this->line($orderB, $product, 2.0);

        self::assertSame(OrderStatus::InProgress, $orderA->refresh()->status);
        self::assertSame(OrderStatus::InProgress, $orderB->refresh()->status);

        // Part 11 — a wave over REAL persisted orders.
        $waveId = $this->createWave([$orderA->id, $orderB->id]);

        $attached = DB::table('preparation_wave_orders')
            ->where('preparation_wave_id', $waveId)
            ->pluck('order_id')
            ->all();

        self::assertContains($orderA->id, $attached);
        self::assertContains($orderB->id, $attached);
        self::assertCount(2, $attached, 'Exactly the two real orders must be attached.');

        $waveRow = DB::table('preparation_waves')->where('id', $waveId)->first();
        self::assertSame($this->company->id, $waveRow->company_id);
        self::assertSame($this->warehouse->id, $waveRow->warehouse_id);

        // Part 12 — demand generation through the real action.
        $this->postJson("/api/preparation/waves/{$waveId}/generate-demand")->assertOk();

        $item = DB::table('preparation_wave_items')
            ->where('preparation_wave_id', $waveId)
            ->where('product_id', $product->id)
            ->first();

        self::assertNotNull($item, 'Demand generation must create a wave item for the ordered product.');

        fwrite(STDERR, PHP_EOL.'DEMAND: product='.$product->id.
            ' required='.$item->quantity_required.
            ' (orderA=3 + orderB=2 expected 5)'.PHP_EOL);

        self::assertSame(5.0, (float) $item->quantity_required,
            'Demand must aggregate 3 + 2 = 5 without duplication.');
    }

    // ── Parts 9, 10: inventory semantics during preparation ──────────────────

    public function test_preparation_does_not_consume_inventory_and_available_is_on_hand_minus_reserved(): void
    {
        $product = Product::factory()->create();
        $this->stockProduct($product, onHand: 10);

        // The availability arithmetic needs a real reservation, but reserving moves
        // an order to Ready for Dispatch, which the entry gate refuses. So the
        // reservation is proven on a DEDICATED order that never joins the wave, and
        // a separate In Progress order carries the Preparation half of the test.
        $reservedOrder = $this->order();
        $this->line($reservedOrder, $product, 6.0);
        $this->reserve($reservedOrder);
        self::assertSame(ReservationStatus::Reserved, $reservedOrder->refresh()->reservation_status);

        $order = $this->order();
        $this->line($order, $product, 2.0);

        // Part 10 — on_hand 10, reserved 6 => available 4.
        $item = $this->inventory($product);
        $onHandBefore = (float) $item->on_hand_qty;
        $reservedBefore = (float) $item->reserved_qty;

        fwrite(STDERR, PHP_EOL."AVAILABILITY: on_hand={$onHandBefore} reserved={$reservedBefore} ".
            'available='.($onHandBefore - $reservedBefore).PHP_EOL);

        self::assertSame(10.0, $onHandBefore);
        self::assertSame(6.0, $reservedBefore, 'Reservation must be 6, not 0 and not 10.');
        self::assertSame(4.0, $onHandBefore - $reservedBefore, 'available = on_hand - reserved.');

        $waveId = $this->createWave([$order->id]);
        $this->postJson("/api/preparation/waves/{$waveId}/generate-demand")->assertOk();

        $layerBefore = (float) InventoryReceiptLayer::query()
            ->where('product_id', $product->id)->firstOrFail()->remaining_qty;

        // Part 8/9 — start preparation through the authoritative path.
        $start = $this->postJson("/api/preparation/waves/{$waveId}/start", []);

        fwrite(STDERR, 'START PREPARATION http='.$start->getStatusCode().PHP_EOL);
        $start->assertOk();

        $after = $this->inventory($product)->refresh();
        $layerAfter = (float) InventoryReceiptLayer::query()
            ->where('product_id', $product->id)->firstOrFail()->remaining_qty;

        // Part 9 — preparation must not consume physical inventory.
        self::assertSame($onHandBefore, (float) $after->on_hand_qty,
            'Preparation must NOT consume physical on-hand inventory.');

        // StartPreparationAction:161 soft-reserves the wave's demand via
        // SoftReservationService ("Soft-reserve inventory for this wave now that
        // preparation has started"). So reserved RISES by the wave order's 2 units,
        // from the 6 held by the separately-reserved order to 8. That is a
        // reservation, not a consumption — on_hand and the FIFO layers below are
        // the invariants that prove nothing physical moved.
        self::assertSame($reservedBefore + 2.0, (float) $after->reserved_qty,
            'Preparation soft-reserves its demand; it must not release or consume it.');
        self::assertSame($layerBefore, $layerAfter,
            'Preparation must NOT consume FIFO receipt layers.');
        self::assertSame(0, DB::table('inventory_layer_consumptions')->count(),
            'No FIFO consumption may exist before shipment.');
    }

    // ── Part 16: partial preparation ─────────────────────────────────────────

    public function test_partial_preparation_tracks_remaining_and_then_completes(): void
    {
        $product = Product::factory()->create();
        $this->stockProduct($product, onHand: 50);

        // Left In Progress: Ready for Dispatch is a post-Preparation state and is
        // refused by the entry gate (ENTRY-GATE-REPAIR-002).
        $order = $this->order();
        $this->line($order, $product, 10.0);

        $waveId = $this->createWave([$order->id]);
        $this->postJson("/api/preparation/waves/{$waveId}/generate-demand")->assertOk();
        $this->postJson("/api/preparation/waves/{$waveId}/start", [])->assertOk();

        $itemId = DB::table('preparation_wave_items')
            ->where('preparation_wave_id', $waveId)
            ->where('product_id', $product->id)
            ->value('id');
        self::assertNotNull($itemId, 'A wave item is required to prepare against.');

        // Prepare 6 of 10.
        $partial = $this->patchJson(
            "/api/preparation/waves/{$waveId}/items/{$itemId}/complete",
            ['quantity_prepared' => 6],
        );
        $partial->assertOk();

        $row = DB::table('preparation_wave_items')->where('id', $itemId)->first();

        fwrite(STDERR, PHP_EOL.'PARTIAL: required='.$row->quantity_required.
            ' prepared='.$row->quantity_prepared.
            ' short='.$row->quantity_short.
            ' status='.$row->status.PHP_EOL);

        self::assertSame(10.0, (float) $row->quantity_required);
        self::assertSame(6.0, (float) $row->quantity_prepared,
            'Prepared must record 6, not silently 10.');
        self::assertSame(4.0, (float) $row->quantity_required - (float) $row->quantity_prepared,
            'Remaining must be 4 and must stay actionable.');

        // Prepare the remaining 4 — cumulative total must reach 10, not 6+4=…14.
        $final = $this->patchJson(
            "/api/preparation/waves/{$waveId}/items/{$itemId}/complete",
            ['quantity_prepared' => 10],
        );
        $final->assertOk();

        $row = DB::table('preparation_wave_items')->where('id', $itemId)->first();

        fwrite(STDERR, 'COMPLETED: required='.$row->quantity_required.
            ' prepared='.$row->quantity_prepared.
            ' short='.$row->quantity_short.
            ' status='.$row->status.PHP_EOL);

        self::assertSame(10.0, (float) $row->quantity_prepared,
            'Prepared must reach exactly 10 — no quantity lost, none invented.');

        // Part 17 — wave completion through the real path.
        $complete = $this->postJson("/api/preparation/waves/{$waveId}/complete", []);
        fwrite(STDERR, 'WAVE COMPLETE http='.$complete->getStatusCode().PHP_EOL);
        $complete->assertOk();

        $wave = DB::table('preparation_waves')->where('id', $waveId)->first();
        fwrite(STDERR, 'WAVE STATUS='.$wave->status.PHP_EOL);

        // Physical inventory still untouched: consumption belongs to shipment.
        self::assertSame(50.0, (float) $this->inventory($product)->on_hand_qty,
            'Wave completion must not consume physical inventory.');

        // ── The Preparation → Shipping/Logistics handoff boundary ────────────
        // CompleteWaveAction writes the prepared output into PreparedProductsPool.
        // This is where Preparation's ownership ENDS: the pool is the artifact a
        // downstream Shipping/Logistics module later consumes. Proving the pool is
        // populated is what makes "Preparation is complete" a real, observable
        // state rather than just a status string — and it is why Picking is not a
        // Preparation dependency (CompleteWaveAction references no pick list at all).
        $poolRows = DB::table('prepared_products_pool')
            ->where('preparation_wave_id', $waveId)
            ->get();

        fwrite(STDERR, 'PREPARED POOL HANDOFF: rows='.$poolRows->count().
            ' qty='.$poolRows->sum(fn ($r) => (float) $r->quantity_available).PHP_EOL);

        self::assertGreaterThan(0, $poolRows->count(),
            'Wave completion must publish prepared output to the pool — the handoff to downstream Shipping.');
    }
}

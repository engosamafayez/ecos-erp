<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Enums\ReservationStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\OrderEvent;
use Modules\Commerce\Orders\Domain\Models\OrderLine;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Modules\Inventory\InventoryItems\Domain\Models\InventoryItem;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Inventory\ReceiptLayers\Domain\Models\InventoryReceiptLayer;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-PHASE3-RC10-FINAL-CLOSE-001 - runtime lifecycle certification.
 *
 * Database-backed. Nothing is mocked: real HTTP requests hit the real
 * controller, FulfillmentEngine runs the real workflow, the real guard executes
 * outside the transaction, and every assertion reads persisted state.
 */
final class Rc10LifecycleCertificationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Warehouse $warehouse;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
        $this->customer = Customer::factory()->create();
    }

    // Fixtures ---------------------------------------------------------------

    private function order(
        string $status = 'in_progress',
        ?string $warehouseId = null,
        ?Company $company = null,
    ): Order {
        return Order::query()->create([
            'company_id' => ($company ?? $this->company)->id,
            'assigned_warehouse_id' => $warehouseId,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-'.uniqid(),
            'order_date' => now()->toDateString(),
            'status' => OrderStatus::from($status)->value,
            'subtotal' => 100,
            'total' => 100,
            'shipping_total' => 0,
            'discount_total' => 0,
            'tax_total' => 0,
        ]);
    }

    /**
     * Seeds an order line plus REAL inventory: an InventoryItem and a matching
     * FIFO receipt layer. Dispatch consumes layers, not the item row, so a
     * fixture without layers is unrealistic. The field set matches the
     * production writers (AddManualStockAction, TransferStockAction), so FIFO is
     * exercised rather than bypassed.
     */
    private function stockedLine(Order $order, float $onHand, float $qty = 2.0): Product
    {
        $product = Product::factory()->create();

        OrderLine::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => $qty,
            'unit_price' => 50.0,
            'line_total' => $qty * 50.0,
        ]);

        InventoryItem::query()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $product->id,
            'company_id' => $this->company->id,
            'on_hand_qty' => $onHand,
            'reserved_qty' => 0,
        ]);

        if ($onHand > 0.0) {
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

        return $product;
    }

    /** A real operator: holds the production permission, no is_system bypass. */
    private function operator(?Company $company = null): User
    {
        $user = User::factory()->create(['company_id' => ($company ?? $this->company)->id]);

        $role = Role::firstOrCreate(
            ['slug' => 'test-fulfillment-operator'],
            ['name' => 'Test Fulfillment Operator', 'is_system' => false],
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

    /** @param array<string, mixed> $extra */
    private function transition(Order $order, string $target, array $extra = []): TestResponse
    {
        return $this->postJson(
            "/api/fulfillment/orders/{$order->id}/transition",
            array_merge(['target_status' => $target], $extra),
        );
    }

    // Happy path -------------------------------------------------------------

    public function test_full_lifecycle_reaches_delivered_and_consumes_fifo(): void
    {
        $order = $this->order(warehouseId: $this->warehouse->id);
        $product = $this->stockedLine($order, onHand: 10);

        $this->actingAs($this->operator());

        // 1. in_progress -> ready_for_dispatch (auto-reserves)
        $this->transition($order, OrderStatus::ReadyForDispatch->value)->assertOk();

        $order->refresh();
        self::assertSame(OrderStatus::ReadyForDispatch, $order->status);
        self::assertSame(
            ReservationStatus::Reserved,
            $order->reservation_status,
            'MoveToPreparationWorkflow must auto-reserve.',
        );
        self::assertNotNull($order->inventory_reserved_at);

        // 2. ready_for_dispatch -> out_for_delivery (consumes FIFO layers)
        $this->transition($order, OrderStatus::OutForDelivery->value)->assertOk();

        $order->refresh();
        self::assertSame(OrderStatus::OutForDelivery, $order->status);
        self::assertNotNull($order->inventory_shipped_at, 'Dispatch must ship inventory.');

        // Inventory consumption: FIFO layer and on-hand both drawn down by 2.
        $layer = InventoryReceiptLayer::query()->where('product_id', $product->id)->firstOrFail();
        self::assertSame(8.0, (float) $layer->remaining_qty, 'FIFO layer must go 10 to 8.');

        $item = InventoryItem::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->firstOrFail();
        self::assertSame(8.0, (float) $item->on_hand_qty, 'On hand must go 10 to 8.');

        // 3. out_for_delivery -> delivered (terminal in V3)
        $this->transition($order, OrderStatus::Delivered->value)->assertOk();

        $order->refresh();
        self::assertSame(OrderStatus::Delivered, $order->status);
        self::assertSame('delivered', $order->status->value, 'PD-2: no Completed state.');

        // Audit trail exists for each transition.
        self::assertGreaterThanOrEqual(
            3,
            OrderEvent::query()->where('order_id', $order->id)->count(),
            'Each transition must be audited.',
        );
    }

    // Warehouse: two gates ---------------------------------------------------

    public function test_reservation_is_the_first_warehouse_gate(): void
    {
        $order = $this->order(warehouseId: null);
        $this->stockedLine($order, onHand: 10);

        $this->actingAs($this->operator());

        // Cannot even reach Ready for Dispatch: MoveToPreparationWorkflow
        // auto-reserves, and reservation needs a warehouse to reserve against.
        $this->transition($order, OrderStatus::ReadyForDispatch->value)->assertStatus(422);

        $order->refresh();
        self::assertSame(OrderStatus::InProgress, $order->status);
        self::assertNull($order->inventory_reserved_at);
    }

    public function test_dispatch_is_the_final_defensive_warehouse_gate(): void
    {
        // Deliberate fixture: a state the normal path cannot produce, precisely
        // because reservation is the first gate. This isolates the dispatch-time
        // refusal that PD-1 Option B documents.
        $order = $this->order(status: 'ready_for_dispatch', warehouseId: null);
        $this->stockedLine($order, onHand: 10);

        $this->actingAs($this->operator());

        $response = $this->transition($order, OrderStatus::OutForDelivery->value);
        self::assertGreaterThanOrEqual(400, $response->getStatusCode());

        $order->refresh();
        self::assertSame(
            OrderStatus::ReadyForDispatch,
            $order->status,
            'Status must roll back; the order must not be dispatched.',
        );
        self::assertNull($order->inventory_shipped_at, 'No partial shipment may remain.');

        $layer = InventoryReceiptLayer::query()->where('warehouse_id', $this->warehouse->id)->firstOrFail();
        self::assertSame(10.0, (float) $layer->remaining_qty, 'FIFO must be untouched.');
    }

    // Stock shortage ---------------------------------------------------------

    public function test_insufficient_stock_diverts_to_awaiting_stock(): void
    {
        $order = $this->order(warehouseId: $this->warehouse->id);
        $this->stockedLine($order, onHand: 0, qty: 5);

        $this->actingAs($this->operator());

        $this->transition($order, OrderStatus::ReadyForDispatch->value)->assertOk();

        self::assertSame(
            OrderStatus::AwaitingStock,
            $order->refresh()->status,
            'Shortage diverts to Awaiting Stock rather than dispatching unreserved stock.',
        );
    }

    // Invalid transition -----------------------------------------------------

    public function test_invalid_transition_is_refused_with_structured_reason(): void
    {
        $order = $this->order();
        $this->stockedLine($order, onHand: 10);

        $this->actingAs($this->operator());

        $this->transition($order, OrderStatus::Delivered->value)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Transition from [in_progress] to [delivered] is not allowed.');

        self::assertSame(OrderStatus::InProgress, $order->refresh()->status);
    }

    public function test_generic_endpoint_no_longer_refuses_valid_v3_transitions(): void
    {
        // The regression this release exists to fix: before Steps 4-7 every V3
        // order was refused because the routing table spoke V2 vocabulary.
        $order = $this->order();
        $this->stockedLine($order, onHand: 10);

        $this->actingAs($this->operator());

        $this->transition($order, OrderStatus::OnHold->value)->assertOk();

        self::assertSame(OrderStatus::OnHold, $order->refresh()->status);
    }

    // Unauthorized -----------------------------------------------------------

    public function test_transition_without_permission_is_refused_and_mutates_nothing(): void
    {
        $order = $this->order();
        $this->stockedLine($order, onHand: 10);

        $stranger = User::factory()->create(['company_id' => $this->company->id]);
        $role = Role::firstOrCreate(
            ['slug' => 'test-no-fulfillment'],
            ['name' => 'Test No Fulfillment', 'is_system' => false],
        );
        $stranger->roles()->attach($role->id);

        $this->actingAsUnprivileged($stranger);

        $this->transition($order, OrderStatus::ReadyForDispatch->value)->assertForbidden();

        $order->refresh();
        self::assertSame(OrderStatus::InProgress, $order->status);
        self::assertNull($order->inventory_reserved_at);
    }

    // Cross-company ----------------------------------------------------------

    public function test_cannot_transition_an_order_belonging_to_another_company(): void
    {
        $foreign = Company::factory()->create();
        $order = $this->order(company: $foreign);
        $this->stockedLine($order, onHand: 10);

        $this->actingAsUnprivileged($this->operator());

        $this->transition($order, OrderStatus::ReadyForDispatch->value)->assertNotFound();

        $order->refresh();
        self::assertSame(OrderStatus::InProgress, $order->status);
        self::assertNull($order->inventory_reserved_at);
    }

    // Bulk -------------------------------------------------------------------

    public function test_bulk_transition_runs_the_same_guard_and_reports_per_order(): void
    {
        $ok = $this->order(warehouseId: $this->warehouse->id);
        $this->stockedLine($ok, onHand: 10);

        // Delivered is locked; MoveToPreparationWorkflow's guard must refuse it.
        $blocked = $this->order(status: 'delivered', warehouseId: $this->warehouse->id);

        $this->actingAs($this->operator());

        $this->postJson('/api/fulfillment/bulk/move-to-preparation', [
            'order_ids' => [$ok->id, $blocked->id],
        ])->assertOk();

        self::assertSame(OrderStatus::ReadyForDispatch, $ok->refresh()->status);
        self::assertSame(
            OrderStatus::Delivered,
            $blocked->refresh()->status,
            'The guard must refuse the locked order without affecting the valid one.',
        );
    }

    // Dedicated routes -------------------------------------------------------

    public function test_dedicated_move_to_preparation_runs_through_the_engine(): void
    {
        $order = $this->order(warehouseId: $this->warehouse->id);
        $this->stockedLine($order, onHand: 10);

        $this->actingAs($this->operator());

        $this->postJson("/api/fulfillment/orders/{$order->id}/move-to-preparation")->assertOk();

        $order->refresh();
        self::assertSame(OrderStatus::ReadyForDispatch, $order->status);
        self::assertSame(ReservationStatus::Reserved, $order->reservation_status);
    }

    public function test_dedicated_route_guard_refuses_an_invalid_source_state(): void
    {
        $order = $this->order(status: 'delivered', warehouseId: $this->warehouse->id);

        $this->actingAs($this->operator());

        $this->postJson("/api/fulfillment/orders/{$order->id}/move-to-preparation")->assertStatus(422);

        self::assertSame(OrderStatus::Delivered, $order->refresh()->status);
    }

    public function test_dedicated_cancel_route_persists(): void
    {
        $order = $this->order(warehouseId: $this->warehouse->id);
        $this->stockedLine($order, onHand: 10);

        $this->actingAs($this->operator());

        $this->postJson("/api/fulfillment/orders/{$order->id}/cancel", ['reason' => 'customer request'])
            ->assertOk();

        self::assertSame(OrderStatus::Cancelled, $order->refresh()->status);
    }

    public function test_dedicated_awaiting_stock_route_persists(): void
    {
        $order = $this->order(warehouseId: $this->warehouse->id);
        $this->stockedLine($order, onHand: 10);

        $this->actingAs($this->operator());

        $this->postJson("/api/fulfillment/orders/{$order->id}/awaiting-stock", ['reason' => 'supplier delay'])
            ->assertOk();

        self::assertSame(OrderStatus::AwaitingStock, $order->refresh()->status);
    }

    public function test_dedicated_review_route_results_in_on_hold(): void
    {
        // PD-2: /review carries stale naming but is functionally OnHold.
        $order = $this->order(warehouseId: $this->warehouse->id);
        $this->stockedLine($order, onHand: 10);

        $this->actingAs($this->operator());

        $this->postJson("/api/fulfillment/orders/{$order->id}/review", ['reason' => 'fraud check'])
            ->assertOk();

        self::assertSame(OrderStatus::OnHold, $order->refresh()->status);
    }

    public function test_dedicated_resume_route_returns_to_in_progress(): void
    {
        $order = $this->order(status: 'on_hold', warehouseId: $this->warehouse->id);
        $this->stockedLine($order, onHand: 10);

        $this->actingAs($this->operator());

        $this->postJson("/api/fulfillment/orders/{$order->id}/resume")->assertOk();

        self::assertSame(OrderStatus::InProgress, $order->refresh()->status);
    }

    public function test_dedicated_return_to_pending_route_returns_to_new(): void
    {
        $order = $this->order(warehouseId: $this->warehouse->id);
        $this->stockedLine($order, onHand: 10);

        $this->actingAs($this->operator());

        $this->postJson("/api/fulfillment/orders/{$order->id}/return-to-pending")->assertOk();

        self::assertSame(OrderStatus::NewOrder, $order->refresh()->status);
    }

    // Audit ------------------------------------------------------------------

    public function test_a_refused_transition_writes_no_audit_event(): void
    {
        $order = $this->order();
        $this->stockedLine($order, onHand: 10);

        $this->actingAs($this->operator());

        $before = OrderEvent::query()->where('order_id', $order->id)->count();

        $this->transition($order, OrderStatus::Delivered->value)->assertStatus(422);

        self::assertSame(
            $before,
            OrderEvent::query()->where('order_id', $order->id)->count(),
            'A refused transition must not create a success audit record.',
        );
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Core\FeatureFlags\FeatureFlagService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
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
 * TASK-GOLIVE-PREPARATION-BATCH-B-E2E-CERTIFICATION-001 — Parts 6, 21, 24.
 *
 * Runtime certification of the Preparation entry guard. Nothing is mocked:
 * real HTTP hits the real controllers, the real FulfillmentEngine drives the
 * order into Awaiting Stock, and every assertion reads persisted rows.
 *
 * The question under test: can an order that the Reservation gate refused
 * still be pulled into a Preparation Wave through the wave entry point?
 */
final class PreparationBypassGuardTest extends TestCase
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

        $flags = app(FeatureFlagService::class);
        $flags->enable('modules.preparation_os', $this->company->id);
        $flags->enable('workflow.stages.preparation', $this->company->id);
    }

    // ── Fixtures (same shape as the certified Rc10LifecycleCertificationTest) ──

    private function order(string $status = 'in_progress', ?Company $company = null): Order
    {
        return Order::query()->create([
            'company_id' => ($company ?? $this->company)->id,
            'assigned_warehouse_id' => $this->warehouse->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-'.uniqid(),
            'order_date' => now()->toDateString(),
            'confirmed_at' => now(),
            'status' => OrderStatus::from($status)->value,
            'subtotal' => 100,
            'total' => 100,
            'shipping_total' => 0,
            'discount_total' => 0,
            'tax_total' => 0,
        ]);
    }

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
            'company_id' => $order->company_id,
            'on_hand_qty' => $onHand,
            'reserved_qty' => 0,
        ]);

        if ($onHand > 0.0) {
            InventoryReceiptLayer::query()->create([
                'company_id' => $order->company_id,
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

    /**
     * A privileged operator. The system role is deliberate: it removes
     * authorization from the equation so that whatever happens next is the
     * BUSINESS guard's doing, not a permission accident.
     */
    private function operator(?Company $company = null): User
    {
        $user = User::factory()->create(['company_id' => ($company ?? $this->company)->id]);

        $role = Role::firstOrCreate(
            ['slug' => 'sysadmin-prep-bypass'],
            ['name' => 'System Admin', 'is_system' => true],
        );

        $user->roles()->attach($role->id);
        $user->unsetRelation('roles');

        return $user;
    }

    /** @param list<string> $orderIds */
    private function createWave(array $orderIds): TestResponse
    {
        return $this->postJson('/api/preparation/waves', [
            'planning_date' => now()->toDateString(),
            'warehouse_id' => $this->warehouse->id,
            'order_ids' => $orderIds,
        ]);
    }

    private function attachedOrderCount(string $orderId): int
    {
        return DB::table('preparation_wave_orders')->where('order_id', $orderId)->count();
    }

    // ── Positive control ──────────────────────────────────────────────────────

    /**
     * Proves the endpoint works and the negative cases below are meaningful.
     * A properly reserved order MUST be accepted into a wave.
     */
    /**
     * ENTRY-GATE-REPAIR-002 amended this control. It previously reserved the order
     * first, which moves it to Ready for Dispatch — a POST-Preparation state that
     * the entry gate now correctly refuses. The control therefore uses an eligible
     * In Progress order: its purpose is only to prove the endpoint accepts a valid
     * order, so that the refusals below cannot be dismissed as a broken fixture.
     */
    public function test_control_an_eligible_order_is_accepted_into_a_wave(): void
    {
        $order = $this->order();
        $this->stockedLine($order, onHand: 10);

        $this->actingAs($this->operator());

        self::assertSame(OrderStatus::InProgress, $order->refresh()->status);

        $response = $this->createWave([$order->id]);

        fwrite(STDERR, PHP_EOL."CONTROL: reserved order -> wave create http={$response->getStatusCode()} ".
            'attached='.$this->attachedOrderCount($order->id).PHP_EOL);

        $response->assertStatus(201);
        self::assertSame(1, $this->attachedOrderCount($order->id),
            'A reserved order must be attached to the wave.');
    }

    // ── Part 6: Awaiting Stock must not enter Preparation ─────────────────────

    /**
     * The order is driven into Awaiting Stock by the REAL engine (shortage
     * diversion, exactly as Rc10LifecycleCertificationTest certifies), then the
     * wave entry point is attacked with it.
     *
     * Expected: refused. An order whose inventory the Reservation gate declined
     * to commit has nothing to prepare.
     */
    public function test_an_awaiting_stock_order_must_not_be_attached_to_a_preparation_wave(): void
    {
        $order = $this->order();
        $this->stockedLine($order, onHand: 0, qty: 5);

        $this->actingAs($this->operator());

        // Real engine drives the diversion — not a hand-written status.
        $this->postJson("/api/fulfillment/orders/{$order->id}/transition", [
            'target_status' => OrderStatus::ReadyForDispatch->value,
        ])->assertOk();

        $order->refresh();
        self::assertSame(OrderStatus::AwaitingStock, $order->status,
            'Precondition: the engine must have diverted this order to Awaiting Stock.');
        self::assertNotSame(ReservationStatus::Reserved, $order->reservation_status,
            'Precondition: no inventory may be reserved.');

        $response = $this->createWave([$order->id]);
        $attached = $this->attachedOrderCount($order->id);

        fwrite(STDERR, PHP_EOL.'BYPASS PROBE (awaiting_stock -> wave): '.
            "order_status={$order->status->value} ".
            'reservation='.($order->reservation_status?->value ?? 'null').' '.
            "wave_create_http={$response->getStatusCode()} ".
            "attached_rows={$attached}".PHP_EOL);

        self::assertSame(0, $attached,
            'BYPASS DEFECT: an Awaiting Stock order was attached to a Preparation Wave. '.
            'The wave entry point does not enforce the Reservation gate.');
    }

    /**
     * The same attack via the wave-recalculate entry point, which is a second
     * route that mutates wave membership.
     */
    public function test_an_awaiting_stock_order_must_not_be_added_by_wave_recalculate(): void
    {
        $good = $this->order();
        $this->stockedLine($good, onHand: 10);

        $blocked = $this->order();
        $this->stockedLine($blocked, onHand: 0, qty: 5);

        $this->actingAs($this->operator());

        // $good stays In Progress (eligible). Only $blocked is driven through the
        // real engine, whose shortage path diverts it to Awaiting Stock.
        $this->postJson("/api/fulfillment/orders/{$blocked->id}/transition", [
            'target_status' => OrderStatus::ReadyForDispatch->value,
        ])->assertOk();

        self::assertSame(OrderStatus::AwaitingStock, $blocked->refresh()->status);
        self::assertSame(OrderStatus::InProgress, $good->refresh()->status);

        $create = $this->createWave([$good->id]);
        $create->assertStatus(201);
        $waveId = $create->json('data.id') ?? $create->json('id');
        self::assertNotNull($waveId, 'Wave id must be readable from the create response.');

        // RecalculateWaveRequest validates `add_order_ids`, NOT `order_ids`. An earlier
        // revision of this test sent `order_ids`, so the payload was silently dropped by
        // validation and the endpoint was never actually exercised — it reported a PASS
        // without adding anything. The field name below is the real contract.
        $response = $this->postJson("/api/preparation/waves/{$waveId}/recalculate", [
            'add_order_ids' => [$blocked->id],
        ]);
        $attached = $this->attachedOrderCount($blocked->id);

        fwrite(STDERR, PHP_EOL.'BYPASS PROBE (recalculate): '.
            "http={$response->getStatusCode()} attached_blocked_rows={$attached}".PHP_EOL);

        self::assertSame(0, $attached,
            'BYPASS DEFECT: recalculate attached an Awaiting Stock order to a wave.');
    }

    // ── Part 24: company isolation at the Preparation entry point ─────────────

    /**
     * An operator of Company A submits an order id belonging to Company B.
     *
     * PreparationWaveController::store() loads the order rows with
     * `DB::table('orders')->whereIn('id', $orderIds)` and no company predicate,
     * then writes the wave rows stamped with the ACTOR's company. This asserts
     * the tenant boundary holds at runtime.
     */
    public function test_an_order_from_another_company_must_not_be_attached_to_a_wave(): void
    {
        $otherCompany = Company::factory()->create();
        $foreignOrder = $this->order(company: $otherCompany);

        $this->actingAs($this->operator());

        $response = $this->createWave([$foreignOrder->id]);
        $attached = $this->attachedOrderCount($foreignOrder->id);

        $stampedCompany = DB::table('preparation_wave_orders')
            ->where('order_id', $foreignOrder->id)
            ->value('company_id');

        fwrite(STDERR, PHP_EOL.'TENANT PROBE (foreign order -> wave): '.
            "http={$response->getStatusCode()} attached_rows={$attached} ".
            'stamped_company='.($stampedCompany ?? 'none').' '.
            "actor_company={$this->company->id} owner_company={$otherCompany->id}".PHP_EOL);

        self::assertSame(0, $attached,
            'TENANT DEFECT: an order owned by another company was attached to this '.
            "company's Preparation Wave.");
    }
}

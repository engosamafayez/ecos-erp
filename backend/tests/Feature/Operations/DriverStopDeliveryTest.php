<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\OrderLine;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Operations\Loading\Domain\Models\VehicleInventoryItem;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-DRIVER-DELIVERY-ALLOCATION-BRIDGE-001 — the driver records full/partial delivery
 * through the CANONICAL delivery authority.
 *
 * POST /api/driver/stops/{stopId}/deliver bridges the driver stop to allocation_records via
 * EnsureStopDeliveryAllocationsAction, then records the delivered quantity through the SOLE
 * canonical writer (RecordProductDeliveryAction) — which projects order_lines.delivered_qty
 * and lowers vehicle custody. Warehouse stock is NOT touched during customer delivery (it was
 * already deducted at the Warehouse→Vehicle confirm-received transfer).
 *
 * Everything runs over the real driver HTTP stack; nothing writes delivered_qty by hand.
 */
final class DriverStopDeliveryTest extends TestCase
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

    private function url(string $stopUuid): string
    {
        return '/api/driver/stops/'.$stopUuid.'/deliver';
    }

    // ── A / K / L / M / N — full delivery ─────────────────────────────────────

    public function test_full_delivery_writes_canonical_delivered_and_projects_and_closes(): void
    {
        $s = $this->scenario([['ordered' => 10, 'onhand' => 10]]);
        $line = $s['lines'][0];

        $this->actingAs($s['user'])->postJson($this->url($s['stop_uuid']), [
            'lines' => [['order_line_id' => $line['order_line_id'], 'delivered_qty' => 10]],
        ])->assertOk();

        // K — allocation_records is the canonical source.
        self::assertSame(10.0, $this->allocationDelivered($line['order_line_id']));
        // L — the projection updated the order line.
        self::assertSame(10.0, $this->lineDeliveredQty($line['order_line_id']));
        // M / N — remaining closed to 0 and the stop is Delivered.
        self::assertSame(0.0, $this->lineRemaining($line['order_line_id']));
        self::assertSame('delivered', $this->stopStatus($s['stop_id']));
    }

    // ── B / M / O — single partial delivery ──────────────────────────────────

    public function test_partial_delivery_records_the_partial_and_does_not_complete_the_stop(): void
    {
        $s = $this->scenario([['ordered' => 10, 'onhand' => 10]]);
        $line = $s['lines'][0];

        $this->actingAs($s['user'])->postJson($this->url($s['stop_uuid']), [
            'lines' => [['order_line_id' => $line['order_line_id'], 'delivered_qty' => 4]],
        ])->assertOk();

        self::assertSame(4.0, $this->allocationDelivered($line['order_line_id']));
        self::assertSame(4.0, $this->lineDeliveredQty($line['order_line_id']));
        self::assertSame(6.0, $this->lineRemaining($line['order_line_id']));
        // O — a partial delivery must NOT auto-complete the stop.
        self::assertNotSame('delivered', $this->stopStatus($s['stop_id']));
    }

    // ── C / M — multiple cumulative partials converge on full ─────────────────

    public function test_multiple_cumulative_partials_converge_and_finally_close(): void
    {
        $s = $this->scenario([['ordered' => 10, 'onhand' => 10]]);
        $line = $s['lines'][0];
        $item = $s['lines'][0]['custody_item_id'];

        $this->deliver($s, $line['order_line_id'], 4)->assertOk();
        self::assertSame(4.0, $this->lineDeliveredQty($line['order_line_id']));
        self::assertSame(6.0, $this->onHand($item));

        $this->deliver($s, $line['order_line_id'], 7)->assertOk(); // cumulative 7
        self::assertSame(7.0, $this->lineDeliveredQty($line['order_line_id']));
        self::assertSame(3.0, $this->onHand($item));

        $this->deliver($s, $line['order_line_id'], 10)->assertOk(); // cumulative 10 → full
        self::assertSame(10.0, $this->lineDeliveredQty($line['order_line_id']));
        self::assertSame(0.0, $this->onHand($item));
        self::assertSame('delivered', $this->stopStatus($s['stop_id']));
    }

    // ── D — over-delivery rejected, no mutation ───────────────────────────────

    public function test_over_delivery_is_rejected_and_mutates_nothing(): void
    {
        $s = $this->scenario([['ordered' => 10, 'onhand' => 10]]);
        $line = $s['lines'][0];

        $this->deliver($s, $line['order_line_id'], 11)->assertStatus(422);

        self::assertSame(0.0, $this->allocationDelivered($line['order_line_id']));
        self::assertSame(0.0, $this->lineDeliveredQty($line['order_line_id']));
        self::assertSame(10.0, $this->onHand($line['custody_item_id']), 'custody untouched by a refused over-delivery');
        self::assertNotSame('delivered', $this->stopStatus($s['stop_id']));
    }

    // ── I / J — custody decreases; warehouse is NOT deducted at delivery ──────

    public function test_delivery_lowers_vehicle_custody_but_never_touches_warehouse_stock(): void
    {
        $s = $this->scenario([['ordered' => 10, 'onhand' => 10]]);
        $line = $s['lines'][0];

        $ledgerBefore = (int) DB::table('stock_ledger_entries')->where('product_id', $line['product_id'])->count();

        $this->deliver($s, $line['order_line_id'], 4)->assertOk();

        // I — vehicle custody fell by exactly the delivered quantity.
        self::assertSame(6.0, $this->onHand($line['custody_item_id']));
        self::assertSame(4.0, (float) DB::table('vehicle_inventory_items')
            ->where('id', $line['custody_item_id'])->value('quantity_delivered'));
        // a canonical vehicle-custody `delivered` movement was appended.
        self::assertSame(1, (int) DB::table('vehicle_inventory_movements')
            ->where('vehicle_inventory_item_id', $line['custody_item_id'])
            ->where('movement_type', 'delivered')->count());
        // J — customer delivery creates NO warehouse stock ledger movement.
        self::assertSame(
            $ledgerBefore,
            (int) DB::table('stock_ledger_entries')->where('product_id', $line['product_id'])->count(),
            'customer delivery must not deduct warehouse stock again',
        );
    }

    // ── H / P / Q — idempotent replay ─────────────────────────────────────────

    public function test_replaying_the_same_delivery_does_not_double_anything(): void
    {
        $s = $this->scenario([['ordered' => 10, 'onhand' => 10]]);
        $line = $s['lines'][0];

        $this->deliver($s, $line['order_line_id'], 4)->assertOk();
        $this->deliver($s, $line['order_line_id'], 4)->assertOk(); // exact replay

        // P — custody deducted once, not twice.
        self::assertSame(6.0, $this->onHand($line['custody_item_id']));
        // Q — one allocation, delivered recorded once; one custody movement.
        self::assertSame(4.0, $this->allocationDelivered($line['order_line_id']));
        self::assertSame(4.0, $this->lineDeliveredQty($line['order_line_id']));
        self::assertSame(1, (int) DB::table('allocation_records')
            ->where('order_line_id', $line['order_line_id'])->count());
        self::assertSame(1, (int) DB::table('vehicle_inventory_movements')
            ->where('vehicle_inventory_item_id', $line['custody_item_id'])
            ->where('movement_type', 'delivered')->count());
    }

    public function test_a_cumulative_total_below_the_recorded_delivered_is_refused(): void
    {
        $s = $this->scenario([['ordered' => 10, 'onhand' => 10]]);
        $line = $s['lines'][0];

        $this->deliver($s, $line['order_line_id'], 7)->assertOk();
        // Sending a LOWER cumulative is the footgun the endpoint refuses (not a silent reduce).
        $this->deliver($s, $line['order_line_id'], 3)->assertStatus(422);

        self::assertSame(7.0, $this->allocationDelivered($line['order_line_id']));
        self::assertSame(3.0, $this->onHand($line['custody_item_id']));
    }

    // ── on-the-road guard ─────────────────────────────────────────────────────

    public function test_delivery_is_refused_when_the_trip_is_not_on_the_road(): void
    {
        $s = $this->scenario([['ordered' => 10, 'onhand' => 10]], tripStatus: 'loading');
        $line = $s['lines'][0];

        $this->deliver($s, $line['order_line_id'], 4)->assertStatus(422);
        self::assertSame(0.0, $this->lineDeliveredQty($line['order_line_id']));
    }

    // ── E / F / G — authorization ─────────────────────────────────────────────

    public function test_a_driver_cannot_deliver_another_drivers_stop(): void
    {
        $a = $this->scenario([['ordered' => 10, 'onhand' => 10]]);
        $b = $this->scenario([['ordered' => 5, 'onhand' => 5]]);

        $this->actingAs($b['user'])->postJson($this->url($a['stop_uuid']), [
            'lines' => [['order_line_id' => $a['lines'][0]['order_line_id'], 'delivered_qty' => 4]],
        ])->assertStatus(404);

        self::assertSame(0.0, $this->lineDeliveredQty($a['lines'][0]['order_line_id']));
    }

    public function test_a_non_driver_user_is_refused(): void
    {
        $s = $this->scenario([['ordered' => 10, 'onhand' => 10]]);
        $notADriver = User::factory()->create(['company_id' => $this->company->id]);

        $this->actingAs($notADriver)->postJson($this->url($s['stop_uuid']), [
            'lines' => [['order_line_id' => $s['lines'][0]['order_line_id'], 'delivered_qty' => 4]],
        ])->assertStatus(403);
    }

    public function test_unauthenticated_delivery_is_denied(): void
    {
        $s = $this->scenario([['ordered' => 10, 'onhand' => 10]]);

        $this->postJson($this->url($s['stop_uuid']), [
            'lines' => [['order_line_id' => $s['lines'][0]['order_line_id'], 'delivered_qty' => 4]],
        ])->assertStatus(401);
    }

    // ── insufficient custody ──────────────────────────────────────────────────

    public function test_delivery_exceeding_on_hand_custody_is_refused(): void
    {
        // Ordered 10 but only 6 physically on the vehicle: the driver cannot deliver 8.
        $s = $this->scenario([['ordered' => 10, 'onhand' => 6]]);
        $line = $s['lines'][0];

        $this->deliver($s, $line['order_line_id'], 8)->assertStatus(422);
        self::assertSame(0.0, $this->lineDeliveredQty($line['order_line_id']));
        self::assertSame(6.0, $this->onHand($line['custody_item_id']), 'custody never goes negative');

        // …but delivering what IS on the vehicle (6) succeeds, leaving remaining 4.
        $this->deliver($s, $line['order_line_id'], 6)->assertOk();
        self::assertSame(6.0, $this->lineDeliveredQty($line['order_line_id']));
        self::assertSame(4.0, $this->lineRemaining($line['order_line_id']));
        self::assertSame(0.0, $this->onHand($line['custody_item_id']));
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    private function deliver(array $s, string $orderLineId, float $qty): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($s['user'])->postJson($this->url($s['stop_uuid']), [
            'lines' => [['order_line_id' => $orderLineId, 'delivered_qty' => $qty]],
        ]);
    }

    private function allocationDelivered(string $orderLineId): float
    {
        return (float) DB::table('allocation_records')->where('order_line_id', $orderLineId)->value('quantity_delivered');
    }

    private function lineDeliveredQty(string $orderLineId): float
    {
        return (float) DB::table('order_lines')->where('id', $orderLineId)->value('delivered_qty');
    }

    private function lineRemaining(string $orderLineId): float
    {
        $l = DB::table('order_lines')->where('id', $orderLineId)->first();

        return (float) $l->quantity - (float) $l->delivered_qty - (float) $l->returned_qty - (float) $l->cancelled_qty;
    }

    private function onHand(string $custodyItemId): float
    {
        return (float) DB::table('vehicle_inventory_items')->where('id', $custodyItemId)->value('quantity_on_hand');
    }

    private function stopStatus(int $stopId): string
    {
        return (string) DB::table('distribution_delivery_stops')->where('id', $stopId)->value('status');
    }

    /**
     * Build a driver on an on-the-road trip with ONE stop/order, N lines, each backed by real
     * vehicle custody. Everything canonical; nothing mocked.
     *
     * @param  list<array{ordered: float|int, onhand: float|int}>  $specs
     * @return array{user: User, stop_uuid: string, stop_id: int, order_id: string, assignment_id: string, lines: list<array{order_line_id: string, product_id: string, custody_item_id: string}>}
     */
    private function scenario(array $specs, string $tripStatus = 'out_for_delivery'): array
    {
        $user = User::factory()->create(['company_id' => $this->company->id]);

        $driverId = (int) DB::table('logistics_drivers')->insertGetId([
            'company_id' => $this->company->id,
            'user_id' => $user->id,
            'driver_code' => 'DRV-'.substr(uniqid(), -6),
            'full_name' => 'Driver '.substr(uniqid(), -4),
            'mobile' => '0100'.random_int(1000000, 9999999),
            'national_id' => (string) random_int(10000000000000, 99999999999999),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $vehicleId = (int) DB::table('logistics_vehicles')->insertGetId([
            'company_id' => $this->company->id,
            'plate_number' => 'PL-'.strtoupper(substr(uniqid(), -6)),
            'name' => 'V-'.substr(uniqid(), -4),
            'capacity_orders' => 25,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $pairingId = (int) DB::table('logistics_driver_vehicle_assignments')->insertGetId([
            'driver_id' => $driverId,
            'vehicle_id' => $vehicleId,
            'assigned_at' => now(),
            'active_flag' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $tripId = (int) DB::table('distribution_trips')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => $this->company->id,
            'trip_number' => 'TRP-'.substr(uniqid(), -6),
            'name' => 'delivery trip',
            'status' => $tripStatus,
            'driver_vehicle_assignment_id' => $pairingId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $sessionId = (string) Str::uuid();
        DB::table('loading_sessions')->insert([
            'id' => $sessionId,
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouse->id,
            'session_number' => 'LS-'.substr(uniqid(), -6),
            'operational_date' => now()->toDateString(),
            'status' => 'loading',
            'created_by' => (string) Str::uuid(), 'updated_by' => (string) Str::uuid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $assignmentId = (string) Str::uuid();
        DB::table('vehicle_assignments')->insert([
            'id' => $assignmentId,
            'company_id' => $this->company->id,
            'loading_session_id' => $sessionId,
            'trip_id' => $tripId,
            'vehicle_id' => (string) Str::uuid(),
            'vehicle_registration_snapshot' => 'REG-'.substr(uniqid(), -6),
            'vehicle_type_snapshot' => 'van',
            'assignment_number' => 'VA-'.substr(uniqid(), -6),
            'status' => 'loading',
            'created_by' => (string) Str::uuid(), 'updated_by' => (string) Str::uuid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $order = Order::query()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-'.strtoupper(substr(uniqid(), -8)),
            'order_date' => now()->toDateString(),
            'assigned_warehouse_id' => $this->warehouse->id,
            'city' => 'Cairo', 'governorate' => 'Cairo', 'status' => 'in_progress',
            'subtotal' => 100, 'total' => 100, 'deposit_amount' => 0,
            'shipping_total' => 0, 'discount_total' => 0, 'tax_total' => 0,
        ]);

        $lines = [];
        foreach ($specs as $spec) {
            $product = Product::factory()->create();
            $sku = 'SKU-'.substr(uniqid(), -6);

            $line = OrderLine::query()->create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'quantity' => (float) $spec['ordered'],
                'unit_price' => 10,
                'line_total' => 10 * (float) $spec['ordered'],
            ]);

            $loadingTaskId = (string) Str::uuid();
            DB::table('loading_tasks')->insert([
                'id' => $loadingTaskId,
                'company_id' => $this->company->id,
                'loading_session_id' => $sessionId,
                'vehicle_assignment_id' => $assignmentId,
                'pool_entry_id' => null,
                'preparation_wave_id' => null,
                'product_id' => $product->id,
                'sku_snapshot' => $sku,
                'name_snapshot' => 'Test Product',
                'quantity_planned' => max((float) $spec['onhand'], 1),
                'quantity_loaded' => (float) $spec['onhand'],
                'quantity_short' => 0,
                'status' => 'loaded',
                'requires_refrigeration' => false,
                'created_by' => (string) Str::uuid(), 'updated_by' => (string) Str::uuid(),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $item = VehicleInventoryItem::query()->create([
                'company_id' => $this->company->id,
                'vehicle_assignment_id' => $assignmentId,
                'vehicle_id' => (string) $vehicleId,
                'product_id' => $product->id,
                'sku_snapshot' => $sku,
                'name_snapshot' => 'Test Product',
                'operational_date' => now()->toDateString(),
                'pool_entry_id' => (string) Str::uuid(),
                'loading_task_id' => $loadingTaskId,
                'quantity_loaded' => (float) $spec['onhand'],
                'quantity_allocated' => 0,
                'quantity_delivered' => 0,
                'quantity_returned' => 0,
                'quantity_on_hand' => (float) $spec['onhand'],
                'quantity_unallocated' => (float) $spec['onhand'],
                'requires_refrigeration' => false,
                'status' => 'active',
                'created_by' => (string) Str::uuid(), 'updated_by' => (string) Str::uuid(),
            ]);

            $lines[] = [
                'order_line_id' => (string) $line->id,
                'product_id' => (string) $product->id,
                'custody_item_id' => (string) $item->id,
            ];
        }

        $stopUuid = (string) Str::uuid();
        $stopId = (int) DB::table('distribution_delivery_stops')->insertGetId([
            'uuid' => $stopUuid,
            'trip_id' => $tripId,
            'order_id' => $order->id,
            'sequence' => 1,
            'status' => 'in_progress',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [
            'user' => $user,
            'stop_uuid' => $stopUuid,
            'stop_id' => $stopId,
            'order_id' => (string) $order->id,
            'assignment_id' => $assignmentId,
            'lines' => $lines,
        ];
    }
}

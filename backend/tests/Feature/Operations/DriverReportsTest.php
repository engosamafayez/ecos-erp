<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-DRIVER-APP-PHASE-6-WALLET-REPORTS-CLOSURE-001 — the driver-scoped Wallet + Reports reads.
 *
 * Pins the driver concerns: self-scope (own trips only), server-side date-range windowing,
 * canonical money aggregation (never fabricated), the orders histogram, goods movement from
 * custody, the honestly-unavailable advances section (§5), auth, and that the frozen settlement
 * routes stay 403.
 */
final class DriverReportsTest extends TestCase
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

    // ── Wallet ────────────────────────────────────────────────────────────────

    public function test_wallet_aggregates_the_drivers_own_collections(): void
    {
        $s = $this->scenario();
        $this->addCashCollection($s['trip_id'], 100.0);

        $res = $this->actingAs($s['user'])->getJson('/api/driver/wallet?period=this_month')->assertOk();

        $res->assertJsonPath('data.trips', 1);
        $res->assertJsonPath('data.collections.cash', 100);
        // §5/§8 — no fabricated authorities.
        $res->assertJsonPath('data.advances.available', false);
        $res->assertJsonPath('data.expenses.available', false);
    }

    public function test_wallet_excludes_another_drivers_trips(): void
    {
        $a = $this->scenario();
        $this->addCashCollection($a['trip_id'], 250.0);
        $b = $this->scenario();

        // Driver B sees none of A's money.
        $this->actingAs($b['user'])->getJson('/api/driver/wallet?period=this_month')
            ->assertOk()
            ->assertJsonPath('data.trips', 1)
            ->assertJsonPath('data.collections.cash', 0);
    }

    public function test_wallet_date_window_excludes_out_of_range_trips(): void
    {
        // A trip anchored two months ago is outside the this_month window.
        $s = $this->scenario(createdAt: now()->subMonthsNoOverflow(2));

        $this->actingAs($s['user'])->getJson('/api/driver/wallet?period=this_month')
            ->assertOk()
            ->assertJsonPath('data.trips', 0);

        // …but a custom range covering it includes it.
        $from = now()->subMonthsNoOverflow(3)->toDateString();
        $to = now()->toDateString();
        $this->actingAs($s['user'])->getJson("/api/driver/wallet?period=custom&from={$from}&to={$to}")
            ->assertOk()
            ->assertJsonPath('data.trips', 1);
    }

    // ── Orders performance ──────────────────────────────────────────────────────

    public function test_orders_report_buckets_every_stop_status(): void
    {
        $s = $this->scenario();
        $this->addStop($s['trip_id'], 'delivered');
        $this->addStop($s['trip_id'], 'failed');
        $this->addStop($s['trip_id'], 'pending');

        $res = $this->actingAs($s['user'])->getJson('/api/driver/reports/orders?period=this_month')->assertOk();

        $res->assertJsonPath('summary.received', 3);
        $res->assertJsonPath('summary.delivered', 1);
        $res->assertJsonPath('summary.failed', 1);
        $res->assertJsonPath('summary.pending', 1);
        $res->assertJsonPath('summary.delivery_rate', 33);
        self::assertIsArray($res->json('items'));
        self::assertSame(3, $res->json('meta.total'));
    }

    // ── Goods movement ──────────────────────────────────────────────────────────

    public function test_goods_movement_reports_custody_per_product(): void
    {
        $s = $this->scenario();
        $this->addCustodyItem($s['assignment_id'], loaded: 10, delivered: 4, onHand: 6);

        $res = $this->actingAs($s['user'])->getJson('/api/driver/reports/goods-movement?period=this_month')->assertOk();

        $products = $res->json('data.products');
        self::assertCount(1, $products);
        self::assertSame(10.0, (float) $products[0]['received']);
        self::assertSame(4.0, (float) $products[0]['delivered']);
        self::assertSame(6.0, (float) $products[0]['remaining_custody']);
        // No reconciliation lines → returned/damaged/shortage are zero, never fabricated.
        self::assertSame(0.0, (float) $products[0]['returned']);
        self::assertSame(0.0, (float) $products[0]['shortage']);
    }

    // ── Advances (no canonical authority) ───────────────────────────────────────

    public function test_advances_report_is_explicitly_unavailable(): void
    {
        $s = $this->scenario();

        $this->actingAs($s['user'])->getJson('/api/driver/reports/advances')
            ->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.reason', 'no_canonical_authority');
    }

    // ── Security / auth ─────────────────────────────────────────────────────────

    public function test_a_non_driver_user_is_refused(): void
    {
        $notADriver = User::factory()->create(['company_id' => $this->company->id]);

        $this->actingAs($notADriver)->getJson('/api/driver/wallet')->assertStatus(403);
    }

    public function test_unauthenticated_is_denied(): void
    {
        $this->getJson('/api/driver/wallet')->assertStatus(401);
    }

    public function test_frozen_settlement_read_stays_forbidden(): void
    {
        $s = $this->scenario();

        // Regression: the Phase-6 reports must NOT unfreeze the settlement money endpoints.
        $this->actingAs($s['user'])
            ->getJson('/api/driver/trips/'.$s['trip_uuid'].'/settlement')
            ->assertStatus(403);
    }

    // ── Fixture ──────────────────────────────────────────────────────────────────

    /**
     * A driver with one trip in the current month (by default), plus a matching loading vehicle
     * assignment. Returns ids for attaching stops/collections/custody.
     *
     * @return array{user: User, trip_id: int, trip_uuid: string, assignment_id: string}
     */
    private function scenario(?\Carbon\CarbonInterface $createdAt = null): array
    {
        $createdAt ??= now();
        $user = User::factory()->create(['company_id' => $this->company->id]);

        $driverId = (int) DB::table('logistics_drivers')->insertGetId([
            'company_id' => $this->company->id, 'user_id' => $user->id,
            'driver_code' => 'DRV-'.substr(uniqid(), -6), 'full_name' => 'Driver '.substr(uniqid(), -4),
            'mobile' => '0100'.random_int(1000000, 9999999), 'national_id' => (string) random_int(10000000000000, 99999999999999),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $vehicleId = (int) DB::table('logistics_vehicles')->insertGetId([
            'company_id' => $this->company->id, 'plate_number' => 'PL-'.strtoupper(substr(uniqid(), -6)),
            'name' => 'V-'.substr(uniqid(), -4), 'capacity_orders' => 25, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $pairingId = (int) DB::table('logistics_driver_vehicle_assignments')->insertGetId([
            'driver_id' => $driverId, 'vehicle_id' => $vehicleId, 'assigned_at' => now(),
            'active_flag' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $tripUuid = (string) Str::uuid();
        $tripId = (int) DB::table('distribution_trips')->insertGetId([
            'uuid' => $tripUuid, 'company_id' => $this->company->id, 'trip_number' => 'TRP-'.substr(uniqid(), -6),
            'name' => 'trip', 'status' => 'out_for_delivery', 'driver_vehicle_assignment_id' => $pairingId,
            'trip_started_at' => $createdAt, 'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);

        $sessionId = (string) Str::uuid();
        DB::table('loading_sessions')->insert([
            'id' => $sessionId, 'company_id' => $this->company->id, 'warehouse_id' => $this->warehouse->id,
            'session_number' => 'LS-'.substr(uniqid(), -6), 'operational_date' => now()->toDateString(), 'status' => 'loading',
            'created_by' => (string) Str::uuid(), 'updated_by' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $assignmentId = (string) Str::uuid();
        DB::table('vehicle_assignments')->insert([
            'id' => $assignmentId, 'company_id' => $this->company->id, 'loading_session_id' => $sessionId,
            'trip_id' => $tripId, 'vehicle_id' => (string) Str::uuid(), 'vehicle_registration_snapshot' => 'REG-'.substr(uniqid(), -6),
            'vehicle_type_snapshot' => 'van', 'assignment_number' => 'VA-'.substr(uniqid(), -6), 'status' => 'loading',
            'created_by' => (string) Str::uuid(), 'updated_by' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['user' => $user, 'trip_id' => $tripId, 'trip_uuid' => $tripUuid, 'assignment_id' => $assignmentId];
    }

    private function addStop(int $tripId, string $status): int
    {
        $order = Order::query()->create([
            'company_id' => $this->company->id, 'customer_id' => $this->customer->id,
            'order_number' => 'ORD-'.strtoupper(substr(uniqid(), -8)), 'order_date' => now()->toDateString(),
            'city' => 'Cairo', 'governorate' => 'Cairo', 'status' => 'out_for_delivery',
            'subtotal' => 100, 'total' => 100, 'deposit_amount' => 0, 'shipping_total' => 0, 'discount_total' => 0, 'tax_total' => 0,
        ]);

        return (int) DB::table('distribution_delivery_stops')->insertGetId([
            'uuid' => (string) Str::uuid(), 'trip_id' => $tripId, 'order_id' => $order->id,
            'sequence' => random_int(1, 999), 'status' => $status, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function addCashCollection(int $tripId, float $amount): void
    {
        DB::table('distribution_payment_collections')->insert([
            'trip_id' => $tripId, 'stop_id' => null, 'payment_type' => 'cash', 'amount' => $amount,
            'status' => 'verified', 'collected_by' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function addCustodyItem(string $assignmentId, float $loaded, float $delivered, float $onHand): void
    {
        $product = Product::factory()->create();
        $sessionId = (string) DB::table('vehicle_assignments')->where('id', $assignmentId)->value('loading_session_id');
        $sku = 'SKU-'.substr(uniqid(), -6);

        // vehicle_inventory_items.loading_task_id is a NOT-NULL FK → a loading_task must exist.
        $loadingTaskId = (string) Str::uuid();
        DB::table('loading_tasks')->insert([
            'id' => $loadingTaskId, 'company_id' => $this->company->id, 'loading_session_id' => $sessionId,
            'vehicle_assignment_id' => $assignmentId, 'pool_entry_id' => null, 'preparation_wave_id' => null,
            'product_id' => $product->id, 'sku_snapshot' => $sku, 'name_snapshot' => 'Test Product',
            'quantity_planned' => $loaded, 'quantity_loaded' => $loaded, 'quantity_short' => 0, 'status' => 'loaded',
            'requires_refrigeration' => false, 'created_by' => (string) Str::uuid(), 'updated_by' => (string) Str::uuid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('vehicle_inventory_items')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $this->company->id, 'vehicle_assignment_id' => $assignmentId,
            'vehicle_id' => (string) Str::uuid(), 'product_id' => $product->id, 'sku_snapshot' => $sku,
            'name_snapshot' => 'Test Product', 'operational_date' => now()->toDateString(), 'pool_entry_id' => (string) Str::uuid(),
            'loading_task_id' => $loadingTaskId, 'quantity_loaded' => $loaded, 'quantity_allocated' => 0,
            'quantity_delivered' => $delivered, 'quantity_returned' => 0, 'quantity_on_hand' => $onHand, 'quantity_unallocated' => 0,
            'requires_refrigeration' => false, 'status' => 'active',
            'created_by' => (string) Str::uuid(), 'updated_by' => (string) Str::uuid(),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Operations\Loading\Domain\Models\VehicleInventoryItem;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-DRIVER-EXPERIENCE-UX-AND-ORDERS-FLOW-REWORK-001 — the two approved, read-only
 * driver read-model additions:
 *
 *   (B1) GET /api/driver/trips exposes the assigned vehicle's PLATE alongside vehicle_id,
 *        sourced from the canonical Vehicle through the driver↔vehicle pairing.
 *   (B2) GET /api/driver/vehicle-inventory exposes the driver's OWN vehicle stock
 *        (loaded/delivered/returned/on-hand), reusing the existing VehicleInventoryItem
 *        read model + VehicleInventoryItemResource, scoped fail-closed to the caller's own
 *        current vehicle assignment and gated by the existing loading.driver.operate.
 *
 * Both are READ-ONLY: no schema, no new permission, no write path, no custody change.
 */
final class DriverVehicleInventoryAndIdentityTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
    }

    // ── B2: Vehicle inventory ────────────────────────────────────────────────

    public function test_the_assigned_driver_sees_their_own_vehicle_inventory(): void
    {
        $a = $this->driverShipment('AAA-1336', loaded: 10, delivered: 3, returned: 1, onHand: 6);

        $data = $this->actingAs($a['user'])
            ->getJson('/api/driver/vehicle-inventory')
            ->assertOk()
            ->json('data');

        self::assertSame(10.0, (float) $data['summary']['total_quantity_loaded']);
        self::assertSame(3.0, (float) $data['summary']['total_quantity_delivered']);
        self::assertSame(1.0, (float) $data['summary']['total_quantity_returned']);
        self::assertSame(6.0, (float) $data['summary']['total_quantity_on_hand']);
        self::assertSame(1, (int) $data['summary']['products_count']);
        self::assertCount(1, $data['items']);
        self::assertSame($a['sku'], $data['items'][0]['sku_snapshot']);
    }

    public function test_a_driver_never_sees_another_drivers_vehicle_inventory(): void
    {
        $a = $this->driverShipment('AAA-1336', loaded: 10, delivered: 3, returned: 1, onHand: 6);
        $b = $this->driverShipment('BBB-9999', loaded: 5, delivered: 0, returned: 0, onHand: 5);

        // B calls the SAME self-scoped endpoint and receives B's inventory — never A's.
        $data = $this->actingAs($b['user'])
            ->getJson('/api/driver/vehicle-inventory')
            ->assertOk()
            ->json('data');

        self::assertSame(5.0, (float) $data['summary']['total_quantity_loaded']);
        self::assertCount(1, $data['items']);
        self::assertSame($b['sku'], $data['items'][0]['sku_snapshot']);
        self::assertNotSame($a['sku'], $data['items'][0]['sku_snapshot'], 'B must never receive A\'s inventory row');
    }

    public function test_a_non_driver_user_is_refused(): void
    {
        // A real user of the company who is NOT a logistics_driver. The driver() guard
        // refuses regardless of permissions.
        $notADriver = User::factory()->create(['company_id' => $this->company->id]);

        $this->actingAs($notADriver)
            ->getJson('/api/driver/vehicle-inventory')
            ->assertStatus(403);
    }

    public function test_unauthenticated_access_is_denied(): void
    {
        $this->getJson('/api/driver/vehicle-inventory')->assertStatus(401);
    }

    public function test_a_driver_with_no_assignment_gets_an_empty_inventory(): void
    {
        // A driver identity with no trip/assignment: a clean empty inventory, not a 404.
        $user = User::factory()->create(['company_id' => $this->company->id]);
        DB::table('logistics_drivers')->insert([
            'company_id' => $this->company->id,
            'user_id' => $user->id,
            'driver_code' => 'DRV-'.substr(uniqid(), -6),
            'full_name' => 'Idle Driver',
            'mobile' => '0100'.random_int(1000000, 9999999),
            'national_id' => (string) random_int(10000000000000, 99999999999999),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $data = $this->actingAs($user)
            ->getJson('/api/driver/vehicle-inventory')
            ->assertOk()
            ->json('data');

        self::assertSame(0.0, (float) $data['summary']['total_quantity_loaded']);
        self::assertSame([], $data['items']);
    }

    // ── B1: Vehicle plate on the trips read model ────────────────────────────

    public function test_the_trips_read_model_exposes_the_assigned_vehicle_plate(): void
    {
        $a = $this->driverShipment('AAA-1336', loaded: 10, delivered: 3, returned: 1, onHand: 6);

        $trips = $this->actingAs($a['user'])->getJson('/api/driver/trips')->assertOk()->json();

        self::assertCount(1, $trips);
        self::assertSame($a['vehicle_id'], (int) $trips[0]['vehicle_id']);
        self::assertSame($a['plate'], $trips[0]['vehicle_plate']);
    }

    public function test_a_driver_never_sees_another_drivers_vehicle_identity(): void
    {
        $a = $this->driverShipment('AAA-1336', loaded: 10, delivered: 3, returned: 1, onHand: 6);
        $b = $this->driverShipment('BBB-9999', loaded: 5, delivered: 0, returned: 0, onHand: 5);

        $trips = $this->actingAs($b['user'])->getJson('/api/driver/trips')->assertOk()->json();

        self::assertCount(1, $trips);
        self::assertSame($b['plate'], $trips[0]['vehicle_plate']);
        self::assertNotSame($a['plate'], $trips[0]['vehicle_plate']);
    }

    // ── Fixture ──────────────────────────────────────────────────────────────

    /**
     * Build one driver's full chain: user + driver + vehicle + pairing + trip + loading
     * vehicle-assignment + one vehicle-inventory row. Everything canonical; nothing mocked.
     *
     * @return array{user: User, plate: string, vehicle_id: int, sku: string}
     */
    private function driverShipment(string $plate, float $loaded, float $delivered, float $returned, float $onHand): array
    {
        $user = User::factory()->create(['company_id' => $this->company->id]);

        $driverId = (int) DB::table('logistics_drivers')->insertGetId([
            'company_id' => $this->company->id,
            'user_id' => $user->id,
            'driver_code' => 'DRV-'.substr(uniqid(), -6),
            'full_name' => 'Driver '.$plate,
            'mobile' => '0100'.random_int(1000000, 9999999),
            'national_id' => (string) random_int(10000000000000, 99999999999999),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $vehicleId = (int) DB::table('logistics_vehicles')->insertGetId([
            'company_id' => $this->company->id,
            'plate_number' => $plate,
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
            'name' => 'inventory trip',
            'status' => 'loading',
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
            'vehicle_registration_snapshot' => $plate,
            'vehicle_type_snapshot' => 'van',
            'assignment_number' => 'VA-'.substr(uniqid(), -6),
            'status' => 'loading',
            'created_by' => (string) Str::uuid(), 'updated_by' => (string) Str::uuid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $product = Product::factory()->create();
        $sku = 'SKU-'.substr(uniqid(), -6);

        // vehicle_inventory_items.loading_task_id is a NOT NULL FK to loading_tasks, so a
        // real task must back the inventory row (the canonical shape).
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
            'name_snapshot' => 'Honey',
            'quantity_planned' => max($loaded, 1),
            'quantity_loaded' => $loaded,
            'quantity_short' => 0,
            'status' => 'loaded',
            'requires_refrigeration' => false,
            'created_by' => (string) Str::uuid(), 'updated_by' => (string) Str::uuid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        VehicleInventoryItem::query()->create([
            'company_id' => $this->company->id,
            'vehicle_assignment_id' => $assignmentId,
            'vehicle_id' => (string) $vehicleId,
            'product_id' => $product->id,
            'sku_snapshot' => $sku,
            'name_snapshot' => 'Honey',
            'operational_date' => now()->toDateString(),
            'pool_entry_id' => (string) Str::uuid(),
            'loading_task_id' => $loadingTaskId,
            'quantity_loaded' => $loaded,
            'quantity_allocated' => 0,
            'quantity_delivered' => $delivered,
            'quantity_returned' => $returned,
            'quantity_on_hand' => $onHand,
            'quantity_unallocated' => $onHand,
            'requires_refrigeration' => false,
            'status' => 'active',
            'created_by' => (string) Str::uuid(), 'updated_by' => (string) Str::uuid(),
        ]);

        return ['user' => $user, 'plate' => $plate, 'vehicle_id' => $vehicleId, 'sku' => $sku];
    }
}

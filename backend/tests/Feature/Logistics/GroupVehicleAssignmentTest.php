<?php

declare(strict_types=1);

namespace Tests\Feature\Logistics;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\OrderLine;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Logistics\Drivers\Domain\Models\Driver;
use Modules\Logistics\Vehicles\Domain\Models\Vehicle;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-OPERATIONS-DISTRIBUTION-GROUPS-AND-VEHICLE-PLANNING-IMPLEMENTATION-001
 * — the approved VP-1 decisions, exercised over HTTP.
 *
 *   D1-C  logistics_vehicles is canonical; Operations resolves via its uuid
 *   D2-A  a Driver belongs to exactly one company and never crosses companies
 *   D3-D  logistics_driver_vehicle_assignments is the ONE pairing ledger
 *   D4-C  Group order count <= Vehicle.capacity_orders, enforced server-side
 *
 * The cross-tenant cases are the point of this file. They are written with TWO
 * companies because a single-company fixture cannot fail them — a scoping defect
 * is invisible until something foreign exists to leak.
 */
class GroupVehicleAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/logistics/distribution';

    private Company $companyA;

    private Company $companyB;

    private Customer $customer;

    private Warehouse $warehouseA;

    private int $zoneMaadi;

    private Product $honey;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('distribution.window.opens_at', '00:00');
        config()->set('distribution.window.closes_at', '23:59');

        $this->companyA = Company::factory()->create();
        $this->companyB = Company::factory()->create();
        $this->customer = Customer::factory()->create();
        $this->warehouseA = Warehouse::factory()->create(['company_id' => $this->companyA->id]);

        $governorate = (int) DB::table('logistics_governorates')->insertGetId([
            'country_id' => 1,
            'name_ar' => 'القاهرة', 'name_en' => 'Cairo',
            'default_shipping_price' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->zoneMaadi = $this->zone('Maadi');
        $this->city($governorate, 'Maadi', 'المعادي', $this->zoneMaadi);
        $this->honey = Product::factory()->create();
    }

    // ── D2 — driver identity and tenancy ──────────────────────────────────────

    public function test_a_driver_is_born_with_a_uuid_and_its_creators_company(): void
    {
        $driver = $this->driverFor($this->companyA);

        $this->assertNotNull($driver->uuid, 'D2: every driver must carry a cross-module uuid from birth.');
        $this->assertSame($this->companyA->id, $driver->company_id);
    }

    public function test_the_driver_tenant_scope_hides_another_companys_drivers(): void
    {
        $mine = $this->driverFor($this->companyA);
        $theirs = $this->driverFor($this->companyB);

        $this->actingAs($this->userFor($this->companyA));

        $visible = Driver::query()->pluck('id')->all();

        $this->assertContains($mine->id, $visible);
        $this->assertNotContains(
            $theirs->id,
            $visible,
            'D2/S-2: a driver owned by another company must not be readable.',
        );
    }

    // ── S-2 — the live cross-tenant write the audit found ─────────────────────

    public function test_assigning_a_vehicle_to_a_foreign_companys_driver_is_rejected(): void
    {
        $foreignDriver = $this->driverFor($this->companyB);
        $myVehicle = $this->vehicleFor($this->companyA, capacity: 10);

        // Before D2 this returned 201: the route's permission middleware is a
        // capability check, and Driver::findOrFail was unscoped.
        $this->actingAs($this->userFor($this->companyA))
            ->postJson("/api/logistics/drivers/{$foreignDriver->id}/vehicle", [
                'vehicle_id' => $myVehicle->id,
            ])
            ->assertNotFound();

        $this->assertSame(
            0,
            (int) DB::table('logistics_driver_vehicle_assignments')->count(),
            'No pairing may exist after a rejected cross-tenant assignment.',
        );
    }

    // ── S-1 — cross-tenant vehicle rejection ──────────────────────────────────

    public function test_a_group_cannot_be_assigned_a_foreign_companys_vehicle(): void
    {
        $group = $this->groupWithOrders('DG-V1', 2);
        $foreignVehicle = $this->vehicleFor($this->companyB, capacity: 10);
        $myDriver = $this->driverFor($this->companyA);

        $this->assign($group, $foreignVehicle->uuid, $myDriver->uuid)
            ->assertStatus(422);

        $this->assertSame(0, (int) DB::table('logistics_driver_vehicle_assignments')->count());
    }

    public function test_a_group_cannot_be_assigned_a_foreign_companys_driver(): void
    {
        $group = $this->groupWithOrders('DG-V2', 2);
        $myVehicle = $this->vehicleFor($this->companyA, capacity: 10);
        $foreignDriver = $this->driverFor($this->companyB);

        $this->assign($group, $myVehicle->uuid, $foreignDriver->uuid)
            ->assertStatus(422);

        $this->assertSame(0, (int) DB::table('logistics_driver_vehicle_assignments')->count());
    }

    // ── D4 — capacity is an ORDER COUNT, enforced server-side ─────────────────

    public function test_a_group_larger_than_the_vehicle_capacity_is_rejected(): void
    {
        $group = $this->groupWithOrders('DG-C1', 3);
        $small = $this->vehicleFor($this->companyA, capacity: 2);
        $driver = $this->driverFor($this->companyA);

        $this->assign($group, $small->uuid, $driver->uuid)
            ->assertStatus(422);

        $this->assertSame(
            0,
            (int) DB::table('logistics_driver_vehicle_assignments')->count(),
            'D4: an over-capacity assignment must not create a pairing.',
        );
    }

    public function test_a_group_that_fits_is_assigned_and_reports_remaining_capacity(): void
    {
        $group = $this->groupWithOrders('DG-C2', 3);
        $vehicle = $this->vehicleFor($this->companyA, capacity: 10);
        $driver = $this->driverFor($this->companyA);

        $body = $this->assign($group, $vehicle->uuid, $driver->uuid)
            ->assertOk()
            ->json('data');

        $this->assertSame(3, $body['group_orders']);
        $this->assertSame(10, $body['vehicle_capacity']);
        $this->assertSame(7, $body['remaining_capacity']);
    }

    // ── D3 — the ONE pairing ledger ───────────────────────────────────────────

    public function test_assignment_writes_the_canonical_ledger_and_never_a_second_pairing(): void
    {
        $group = $this->groupWithOrders('DG-A1', 2);
        $vehicle = $this->vehicleFor($this->companyA, capacity: 10);
        $driver = $this->driverFor($this->companyA);

        $this->assign($group, $vehicle->uuid, $driver->uuid)->assertOk();

        // The pairing lives in the ledger, keyed by the canonical BIGINTs.
        $this->assertDatabaseHas('logistics_driver_vehicle_assignments', [
            'driver_id' => $driver->id,
            'vehicle_id' => $vehicle->id,
        ]);

        // …and Loading's parallel tables stay untouched. D3-D means Loading
        // CONSUMES the ledger; assigning a vehicle to a Group must never mint a
        // second pairing there.
        $this->assertSame(0, (int) DB::table('vehicle_assignments')->count());
        $this->assertSame(0, (int) DB::table('driver_assignments')->count());
    }

    public function test_the_group_reaches_the_ledger_through_its_trip_not_directly(): void
    {
        $group = $this->groupWithOrders('DG-A2', 2);
        $vehicle = $this->vehicleFor($this->companyA, capacity: 10);
        $driver = $this->driverFor($this->companyA);

        $this->assign($group, $vehicle->uuid, $driver->uuid)->assertOk();

        $trip = DB::table('distribution_trips')->where('virtual_slot_id', $group['id'])->first();

        $this->assertNotNull($trip, 'The Group must reach transport through a Trip.');
        $this->assertNotNull(
            $trip->driver_vehicle_assignment_id,
            'D3: the Trip carries a ledger REFERENCE, not its own driver/vehicle.',
        );

        // The certified contract: the trip owns no pairing columns of its own.
        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasColumn('distribution_trips', 'vehicle_id'),
            'distribution_trips must not gain a vehicle_id — the ledger owns the pairing.',
        );
    }

    // ── D1 — the resolver accepts the uuid contract ───────────────────────────

    public function test_the_fleet_options_endpoint_publishes_uuids_and_never_bigint_ids(): void
    {
        $group = $this->groupWithOrders('DG-R1', 2);
        $vehicle = $this->vehicleFor($this->companyA, capacity: 10);
        $driver = $this->driverFor($this->companyA);

        $data = $this->actingAs($this->userFor($this->companyA))
            ->getJson(self::BASE."/windows/{$this->windowId()}/slots/{$group['id']}/fleet-options")
            ->assertOk()
            ->json('data');

        $this->assertSame($vehicle->uuid, $data['vehicles'][0]['id']);
        $this->assertSame($driver->uuid, $data['drivers'][0]['id']);
        $this->assertSame(2, $data['group_orders']);
        $this->assertTrue($data['vehicles'][0]['fits_group']);
    }

    public function test_fleet_options_never_lists_another_companys_fleet(): void
    {
        $group = $this->groupWithOrders('DG-R2', 1);
        $this->vehicleFor($this->companyB, capacity: 10);
        $this->driverFor($this->companyB);

        $data = $this->actingAs($this->userFor($this->companyA))
            ->getJson(self::BASE."/windows/{$this->windowId()}/slots/{$group['id']}/fleet-options")
            ->assertOk()
            ->json('data');

        $this->assertSame([], $data['vehicles'], 'S-6: a foreign fleet must not be enumerable.');
        $this->assertSame([], $data['drivers']);
    }

    // ── TASK-DISTRIBUTION-VEHICLE-DRIVER-PAIRING-FILTER-FIX-001 ───────────────
    //
    // The Driver selector depends on the Vehicle: only drivers ACTIVELY paired to
    // the chosen vehicle may be offered, and choosing one of them must REUSE the
    // live pairing rather than attempt a duplicate. The create path (no pairing
    // yet) is unchanged and stays covered by the D3 test above.

    public function test_fleet_options_lists_only_the_drivers_actively_paired_to_each_vehicle(): void
    {
        $group = $this->groupWithOrders('DG-P1', 1);
        $vehicle = $this->vehicleFor($this->companyA, capacity: 10);
        $paired = $this->driverFor($this->companyA);
        $unpaired = $this->driverFor($this->companyA);

        $this->pair($paired, $vehicle);

        $data = $this->actingAs($this->userFor($this->companyA))
            ->getJson(self::BASE."/windows/{$this->windowId()}/slots/{$group['id']}/fleet-options")
            ->assertOk()
            ->json('data');

        $row = collect($data['vehicles'])->firstWhere('id', $vehicle->uuid);

        self::assertNotNull($row);
        self::assertSame(
            [$paired->uuid],
            $row['driver_ids'],
            'Only the driver actively paired to this vehicle may be offered.',
        );

        // The flat list is a CERTIFIED contract and stays complete — the selector
        // narrows; the payload does not stop publishing the tenant's drivers.
        $publishedDrivers = collect($data['drivers'])->pluck('id')->all();
        self::assertContains($paired->uuid, $publishedDrivers);
        self::assertContains($unpaired->uuid, $publishedDrivers);
    }

    public function test_fleet_options_reports_no_eligible_drivers_for_an_unpaired_vehicle(): void
    {
        $group = $this->groupWithOrders('DG-P2', 1);
        $vehicle = $this->vehicleFor($this->companyA, capacity: 10);
        $this->driverFor($this->companyA); // exists, but paired with nothing

        $data = $this->actingAs($this->userFor($this->companyA))
            ->getJson(self::BASE."/windows/{$this->windowId()}/slots/{$group['id']}/fleet-options")
            ->assertOk()
            ->json('data');

        $row = collect($data['vehicles'])->firstWhere('id', $vehicle->uuid);

        self::assertNotNull($row);
        self::assertSame([], $row['driver_ids'], 'A vehicle nobody is paired with offers no driver.');
    }

    public function test_assigning_a_driver_already_paired_to_the_vehicle_reuses_the_pairing(): void
    {
        $group = $this->groupWithOrders('DG-P3', 2);
        $vehicle = $this->vehicleFor($this->companyA, capacity: 10);
        $driver = $this->driverFor($this->companyA);

        $existing = $this->pair($driver, $vehicle);

        // Before the fix this returned 422 (alreadyAssignedToSameVehicle), which
        // made the ONLY valid selection unassignable.
        $this->assign($group, $vehicle->uuid, $driver->uuid)->assertOk();

        self::assertSame(
            1,
            (int) DB::table('logistics_driver_vehicle_assignments')
                ->where('driver_id', $driver->id)
                ->where('vehicle_id', $vehicle->id)
                ->whereNotNull('active_flag')
                ->count(),
            'Reuse must not mint a second active pairing.',
        );

        $trip = DB::table('distribution_trips')->where('virtual_slot_id', $group['id'])->first();
        self::assertNotNull($trip);
        self::assertSame(
            $existing->id,
            (int) $trip->driver_vehicle_assignment_id,
            'The Trip must reference the pairing that already existed.',
        );
    }

    public function test_assigning_the_same_valid_pair_twice_is_idempotent(): void
    {
        $group = $this->groupWithOrders('DG-P4', 1);
        $vehicle = $this->vehicleFor($this->companyA, capacity: 10);
        $driver = $this->driverFor($this->companyA);

        $this->pair($driver, $vehicle);

        $this->assign($group, $vehicle->uuid, $driver->uuid)->assertOk();
        $this->assign($group, $vehicle->uuid, $driver->uuid)->assertOk();

        self::assertSame(
            1,
            (int) DB::table('logistics_driver_vehicle_assignments')
                ->where('driver_id', $driver->id)
                ->where('vehicle_id', $vehicle->id)
                ->whereNotNull('active_flag')
                ->count(),
        );
    }

    // ── TASK-DISTRIBUTION-DRIVER-AVAILABILITY-FIX-001 ─────────────────────────
    //
    // A driver/vehicle pairing is ENGAGED when its ledger row is attached to a
    // non-terminal Distribution trip. A pairing engaged by ANOTHER Group must be
    // neither offered by fleet-options nor accepted by assign; a pairing engaged
    // by the CURRENT Group stays available (idempotent re-entry); terminal trips
    // do not engage.

    /** CASE 1 — an idle, validly paired driver/vehicle IS offered. */
    public function test_case1_an_idle_pairing_is_offered(): void
    {
        $group = $this->groupWithOrders('DG-AV1', 2);
        $vehicle = $this->vehicleFor($this->companyA, capacity: 10);
        $driver = $this->driverFor($this->companyA);
        $this->pair($driver, $vehicle);

        $row = collect($this->fleetOptions($group)['vehicles'])->firstWhere('id', $vehicle->uuid);

        self::assertNotNull($row);
        self::assertSame(
            [$driver->uuid],
            $row['driver_ids'],
            'An idle pairing consumes nothing and must be offered.',
        );
    }

    /**
     * CASE 2 + CASE 3 — a pairing engaged on ANOTHER Group is hidden by the
     * selector AND rejected by the write path (fail-closed even if bypassed).
     */
    public function test_case2_and_3_a_pairing_engaged_elsewhere_is_hidden_and_rejected(): void
    {
        $groupA = $this->groupWithOrders('DG-AV2A', 2);
        $groupB = $this->groupWithOrders('DG-AV2B', 2);
        $vehicle = $this->vehicleFor($this->companyA, capacity: 10);
        $driver = $this->driverFor($this->companyA);

        // Engage the pairing on group A — this creates a non-terminal trip on A.
        $this->assign($groupA, $vehicle->uuid, $driver->uuid)->assertOk();

        // CASE 2 (READ): group B's drawer must not offer this driver on this vehicle.
        $rowB = collect($this->fleetOptions($groupB)['vehicles'])->firstWhere('id', $vehicle->uuid);
        self::assertNotNull($rowB, 'The vehicle still lists; only the busy pairing is withheld.');
        self::assertSame(
            [],
            $rowB['driver_ids'],
            'A pairing engaged on another group must not be offered here.',
        );

        // CASE 3 (WRITE): the backend refuses it directly, without the selector.
        $this->assign($groupB, $vehicle->uuid, $driver->uuid)->assertStatus(422);

        // …and the rejected attempt created no trip for group B.
        self::assertNull(
            DB::table('distribution_trips')->where('virtual_slot_id', $groupB['id'])->first(),
            'A rejected assignment must not leave a Trip behind.',
        );
    }

    /** CASE 4 — once every trip on the pairing is terminal, it is available again. */
    public function test_case4_a_pairing_with_only_terminal_trips_is_available_again(): void
    {
        $groupA = $this->groupWithOrders('DG-AV4A', 2);
        $groupB = $this->groupWithOrders('DG-AV4B', 2);
        $vehicle = $this->vehicleFor($this->companyA, capacity: 10);
        $driver = $this->driverFor($this->companyA);

        $this->assign($groupA, $vehicle->uuid, $driver->uuid)->assertOk();

        // Group A's trip reaches a terminal state and stops consuming the pairing.
        DB::table('distribution_trips')
            ->where('virtual_slot_id', $groupA['id'])
            ->update(['status' => 'cancelled']);

        // READ: offered for group B again.
        $rowB = collect($this->fleetOptions($groupB)['vehicles'])->firstWhere('id', $vehicle->uuid);
        self::assertSame([$driver->uuid], $rowB['driver_ids']);

        // WRITE: the assignment to group B now succeeds.
        $this->assign($groupB, $vehicle->uuid, $driver->uuid)->assertOk();
    }

    /** CASE 5 — the pairing stays available for its OWN group (idempotent re-entry). */
    public function test_case5_a_pairing_engaged_on_the_current_group_stays_available_for_it(): void
    {
        $group = $this->groupWithOrders('DG-AV5', 2);
        $vehicle = $this->vehicleFor($this->companyA, capacity: 10);
        $driver = $this->driverFor($this->companyA);

        $this->assign($group, $vehicle->uuid, $driver->uuid)->assertOk();

        // READ: re-opening the SAME group's drawer still shows its own selection.
        $row = collect($this->fleetOptions($group)['vehicles'])->firstWhere('id', $vehicle->uuid);
        self::assertSame(
            [$driver->uuid],
            $row['driver_ids'],
            'The current group must still see the pairing it already holds.',
        );

        // WRITE: re-assigning the same pair to the same group is idempotent, not a conflict.
        $this->assign($group, $vehicle->uuid, $driver->uuid)->assertOk();

        self::assertSame(
            1,
            (int) DB::table('distribution_trips')->where('virtual_slot_id', $group['id'])->count(),
            'Re-assignment to the same group must not create a second trip.',
        );
    }

    /** CASE 6 — an inactive driver is not offered (existing employment rule, preserved). */
    public function test_case6_an_inactive_driver_is_not_offered(): void
    {
        $group = $this->groupWithOrders('DG-AV6', 1);
        $vehicle = $this->vehicleFor($this->companyA, capacity: 10);
        $driver = $this->driverFor($this->companyA);
        $this->pair($driver, $vehicle);

        DB::table('logistics_drivers')->where('id', $driver->id)
            ->update(['status' => Driver::STATUS_INACTIVE]);

        $data = $this->fleetOptions($group);
        $row = collect($data['vehicles'])->firstWhere('id', $vehicle->uuid);

        self::assertSame([], $row['driver_ids'], 'An inactive driver must not be offered on any vehicle.');
        self::assertNotContains(
            $driver->uuid,
            collect($data['drivers'])->pluck('id')->all(),
            'An inactive driver must not appear in the roster either.',
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** GET the fleet-options payload (`data`) for a group. */
    private function fleetOptions(array $group): array
    {
        return $this->actingAs($this->userFor($this->companyA))
            ->getJson(self::BASE."/windows/{$this->windowId()}/slots/{$group['id']}/fleet-options")
            ->assertOk()
            ->json('data');
    }

    /** Create a live pairing through the canonical ledger service. */
    private function pair(Driver $driver, Vehicle $vehicle): \Modules\Logistics\Drivers\Domain\Models\DriverVehicleAssignment
    {
        return app(\Modules\Logistics\Drivers\Domain\Services\DriverVehicleAssignmentService::class)
            ->assign($driver, $vehicle);
    }

    private function assign(array $group, string $vehicleRef, string $driverRef): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->userFor($this->companyA))
            ->postJson(
                self::BASE."/windows/{$this->windowId()}/slots/{$group['id']}/assign-vehicle",
                ['vehicle_id' => $vehicleRef, 'driver_id' => $driverRef],
            );
    }

    private function vehicleFor(Company $company, int $capacity): Vehicle
    {
        // Created unauthenticated so the model's creating() hook does not stamp
        // the acting user's company over the one under test.
        // company_id is not fillable on either fleet model (ownership is stamped,
        // never client-supplied), so it is set explicitly after create().
        $vehicle = Vehicle::withoutEvents(fn () => Vehicle::create([
            'plate_number' => 'V-'.mt_rand(1000, 9999),
            'capacity_orders' => $capacity,
            'status' => 'available',
        ]));

        $vehicle->forceFill([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'company_id' => $company->id,
        ])->saveQuietly();

        return $vehicle->refresh();
    }

    private function driverFor(Company $company): Driver
    {
        $driver = Driver::withoutEvents(fn () => Driver::create([
            'driver_code' => 'DRV-'.mt_rand(10000, 99999),
            'full_name' => 'Driver '.substr($company->id, 0, 4),
            'mobile' => '01'.mt_rand(100000000, 999999999),
            // national_id is NOT NULL with no default, and it is globally unique
            // (BR-3) — deliberately NOT tenant-scoped, so two companies' drivers
            // still cannot share one.
            'national_id' => (string) mt_rand(10000000000000, 99999999999999),
            'status' => Driver::STATUS_ACTIVE,
        ]));

        $driver->forceFill([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'company_id' => $company->id,
        ])->saveQuietly();

        return $driver->refresh();
    }

    private function userFor(Company $company): User
    {
        return User::factory()->create(['company_id' => $company->id]);
    }

    private function groupWithOrders(string $code, int $count): array
    {
        for ($i = 0; $i < $count; $i++) {
            $o = $this->order($this->warehouseA, 'Maadi');
            $this->line($o, $this->honey->id, 1);
        }

        $this->collect();
        $group = $this->group($this->warehouseA, $code);

        if ($count > 0) {
            $this->addZone($group['id'], $this->zoneMaadi);
        }

        return $group;
    }

    private function collect(): void
    {
        $this->actingAs($this->userFor($this->companyA))
            ->postJson(self::BASE.'/windows/collect')->assertOk();
    }

    private function windowId(): string
    {
        return (string) $this->actingAs($this->userFor($this->companyA))
            ->getJson(self::BASE.'/windows/current')->assertOk()->json('data.window.id');
    }

    private function group(Warehouse $warehouse, string $code): array
    {
        return $this->actingAs($this->userFor($this->companyA))
            ->postJson(self::BASE."/windows/{$this->windowId()}/slots", [
                'warehouse_id' => $warehouse->id,
                'code' => $code,
            ])->assertStatus(201)->json('data');
    }

    private function addZone(string $groupId, int $zoneId): void
    {
        $this->actingAs($this->userFor($this->companyA))
            ->postJson(self::BASE."/windows/{$this->windowId()}/slots/{$groupId}/zones", ['zone_id' => $zoneId])
            ->assertOk();
    }

    private function zone(string $name): int
    {
        return (int) DB::table('distribution_zones')->insertGetId([
            'code' => strtoupper(substr($name, 0, 6)).mt_rand(10, 99),
            'name_en' => $name, 'name_ar' => $name,
            'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function city(int $governorate, string $en, string $ar, int $zoneId): void
    {
        DB::table('logistics_cities')->insert([
            'governorate_id' => $governorate,
            'name_en' => $en, 'name_ar' => $ar,
            'distribution_zone_id' => $zoneId,
            'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function order(Warehouse $warehouse, string $city): Order
    {
        return Order::query()->create([
            'company_id' => $this->companyA->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-VA-'.uniqid(),
            'order_date' => now()->toDateString(),
            'assigned_warehouse_id' => $warehouse->id,
            'city' => $city,
            'governorate' => 'Cairo',
            'status' => 'in_progress',
            'subtotal' => 100, 'total' => 100,
            'deposit_amount' => 0,
            'shipping_total' => 0, 'discount_total' => 0, 'tax_total' => 0,
        ]);
    }

    private function line(Order $order, string $productId, float $qty): void
    {
        OrderLine::query()->create([
            'order_id' => $order->id,
            'product_id' => $productId,
            'quantity' => $qty,
            'unit_price' => 10,
            'line_total' => $qty * 10,
        ]);
    }
}

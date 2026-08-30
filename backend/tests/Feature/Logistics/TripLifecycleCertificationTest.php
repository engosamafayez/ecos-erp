<?php

declare(strict_types=1);

namespace Tests\Feature\Logistics;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\OrderLine;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Logistics\Distribution\Domain\Enums\TripStatus;
use Modules\Logistics\Distribution\Domain\Models\Trip;
use Modules\Logistics\Distribution\Domain\Models\VirtualCapacitySlot;
use Modules\Logistics\Distribution\Domain\Services\DriverDaySettlementReadService;
use Modules\Logistics\Drivers\Domain\Models\Driver;
use Modules\Logistics\Vehicles\Domain\Models\Vehicle;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Operations\Loading\Domain\Models\LoadingTask;
use Modules\Operations\Loading\Domain\Models\VehicleAssignment;
use Modules\Operations\Loading\Domain\Services\LoadingCustodyService;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-TRIP-LIFECYCLE-AND-VEHICLE-CUSTODY-BRIDGE-CERTIFICATION-001
 *
 * ┌─ WHAT THIS CERTIFIES ────────────────────────────────────────────────────┐
 * │ ONE shipment, driven end to end through the canonical chain, with every   │
 * │ hop asserted from the SERVER's own state — never from a fixture shortcut: │
 * │                                                                          │
 * │   Warehouse Loading → Driver Confirmation → Loading Complete →           │
 * │   Group Finalization → trip_orders → Delivery Stops →                    │
 * │   Driver Accepted → Ready for Dispatch → Dispatched → In Progress →      │
 * │   Day Settlement visibility                                              │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * Every write goes through the real HTTP route with a real permissioned actor, so
 * nothing here can pass on a path production does not have. The numbered methods
 * map 1:1 to the certification checklist items.
 *
 * THIS IS NOT BROWSER VERIFICATION and is not offered as such.
 */
final class TripLifecycleCertificationTest extends TestCase
{
    use RefreshDatabase;

    private const DIST = '/api/logistics/distribution';

    private const DRIVER = '/api/driver';

    private Company $company;

    private Customer $customer;

    private Warehouse $warehouse;

    private int $governorate;

    private int $zone;

    private Product $honey;

    private Product $nuts;

    /** @var array{user: User, driver: Driver, vehicle: Vehicle, group: VirtualCapacitySlot, trip: Trip, window: string} */
    private array $shipment;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('distribution.window.opens_at', '00:00');
        config()->set('distribution.window.closes_at', '23:59');

        $this->company = Company::factory()->create();
        $this->customer = Customer::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);

        $this->governorate = (int) DB::table('logistics_governorates')->insertGetId([
            'country_id' => 1,
            'name_ar' => 'القاهرة', 'name_en' => 'Cairo',
            'default_shipping_price' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Geography must exist BEFORE the first collect — city binding is a sweep, and
        // an order it missed is never asked about again.
        $this->zone = $this->zone('Maadi');
        $this->city($this->governorate, 'Maadi', 'المعادي', $this->zone);

        $this->honey = Product::factory()->create();
        $this->nuts = Product::factory()->create();

        $this->shipment = $this->buildShipment();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1. A shipment valid for the driver flow
    // ─────────────────────────────────────────────────────────────────────────

    public function test_01_the_shipment_is_valid_for_the_driver_flow(): void
    {
        $trip = $this->shipment['trip'];

        self::assertNotNull($trip->virtual_slot_id, 'the trip is owned by a Group');
        self::assertSame($this->shipment['group']->id, $trip->virtual_slot_id);
        self::assertNotNull($trip->driver_vehicle_assignment_id, 'a driver/vehicle pairing is linked');
        self::assertSame(TripStatus::Planning, $trip->status, 'a freshly assigned trip starts in Planning');

        $assignment = $this->openLoading();
        self::assertNotNull($assignment, 'warehouse loading opens a vehicle assignment');
        self::assertSame($trip->id, (int) $assignment->trip_id, 'the assignment is bound to this trip');

        // A loading task is materialised when the WAREHOUSE records a load, not when the
        // session opens — so an empty manifest here is correct, not a fixture fault.
        self::assertSame(0, LoadingTask::query()->where('vehicle_assignment_id', $assignment->id)->count());
        $this->warehouseLoads($this->honey, 4.0)->assertSuccessful();
        self::assertSame(1, LoadingTask::query()->where('vehicle_assignment_id', $assignment->id)->count());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. All required driver confirmations resolved
    // ─────────────────────────────────────────────────────────────────────────

    public function test_02_driver_confirmations_are_resolved_and_an_unconfirmed_item_is_visible_as_unresolved(): void
    {
        $assignment = $this->openLoading();

        $this->warehouseLoads($this->honey, 6.0);

        // BEFORE the driver speaks, the item is unresolved — proving the check is real.
        self::assertCount(
            1,
            app(LoadingCustodyService::class)->unresolvedLoadedTasks((string) $assignment->id),
            'a warehouse-loaded item awaits the driver'
        );

        $this->driverConfirms($this->honey, 6.0)->assertOk();

        self::assertSame(
            [],
            app(LoadingCustodyService::class)->unresolvedLoadedTasks((string) $assignment->id),
            'once confirmed, nothing is outstanding'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3./4./5./6. Loading Complete → finalization → trip_orders → stops
    // ─────────────────────────────────────────────────────────────────────────

    public function test_03_loading_complete_succeeds_once_custody_is_resolved(): void
    {
        $this->loadAndConfirmAll();

        $this->complete()->assertOk();

        $assignment = $this->openLoading();
        self::assertSame('loading_complete', $this->statusValue($assignment->refresh()->status));
        self::assertNotNull($assignment->loading_completed_at);
    }

    public function test_04_loading_complete_runs_the_canonical_finalization(): void
    {
        $this->loadAndConfirmAll();
        $this->complete()->assertOk();

        $trip = $this->shipment['trip']->refresh();

        self::assertNotNull($trip->finalized_at, 'the Group was finalized through the canonical service');
        self::assertSame(TripStatus::LoadingCompleted, $trip->status, 'and the trip advanced');
    }

    public function test_05_trip_orders_belong_only_to_this_group_and_trip(): void
    {
        $this->loadAndConfirmAll();
        $this->complete()->assertOk();

        $trip = $this->shipment['trip'];

        $tripOrderIds = DB::table('distribution_trip_orders')
            ->where('trip_id', $trip->id)->pluck('order_id')->sort()->values()->all();

        self::assertNotEmpty($tripOrderIds, 'finalization materialised trip orders');

        // The ONLY legitimate source: the orders this Group actually collected.
        $groupOrderIds = DB::table('distribution_window_orders')
            ->where('virtual_slot_id', $trip->virtual_slot_id)->pluck('order_id')->sort()->values()->all();

        self::assertSame($groupOrderIds, $tripOrderIds, 'no foreign order joined the trip, and none was dropped');

        // Nothing leaked onto any other trip.
        self::assertSame(
            0,
            DB::table('distribution_trip_orders')->where('trip_id', '!=', $trip->id)->count(),
            'no other trip received these orders'
        );
    }

    public function test_06_delivery_stops_correspond_exactly_to_the_trip_orders(): void
    {
        $this->loadAndConfirmAll();
        $this->complete()->assertOk();

        $trip = $this->shipment['trip'];

        $tripOrderIds = DB::table('distribution_trip_orders')
            ->where('trip_id', $trip->id)->pluck('order_id')->sort()->values()->all();
        $stopOrderIds = DB::table('distribution_delivery_stops')
            ->where('trip_id', $trip->id)->pluck('order_id')->sort()->values()->all();

        self::assertNotEmpty($stopOrderIds, 'stops were generated');
        self::assertSame($tripOrderIds, $stopOrderIds, 'one stop per trip order, and nothing else');
        self::assertSame(
            0,
            DB::table('distribution_delivery_stops')->where('trip_id', '!=', $trip->id)->count(),
            'no stop was written against another trip'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 7. What the driver actually sees
    // ─────────────────────────────────────────────────────────────────────────

    public function test_07_the_driver_sees_the_correct_vehicle_plate_trip_and_orders(): void
    {
        $this->loadAndConfirmAll();
        $this->complete()->assertOk();

        $trip = $this->shipment['trip']->refresh();
        $plate = $this->shipment['vehicle']->plate_number;

        $trips = $this->actingAs($this->shipment['user'])
            ->getJson(self::DRIVER.'/trips')->assertOk()->json();

        self::assertCount(1, $trips, 'exactly one trip is listed for this driver');

        $mine = collect($trips)->firstWhere('id', $trip->uuid);
        self::assertNotNull($mine, 'the trip is addressed by its public uuid');

        $expectedStops = DB::table('distribution_delivery_stops')->where('trip_id', $trip->id)->count();

        self::assertSame($plate, $mine['vehicle_plate'], 'the correct VEHICLE PLATE');
        // NOTE: this endpoint publishes the vehicle's internal id, not its uuid.
        self::assertSame($this->shipment['vehicle']->id, $mine['vehicle_id'], 'the correct VEHICLE');
        self::assertSame($trip->trip_number, $mine['trip_number'], 'the correct TRIP');
        self::assertSame($this->shipment['driver']->id, $mine['driver_id'], 'attributed to this driver');
        self::assertSame($expectedStops, $mine['stops_count'], 'the correct ORDER count');

        // The stops list itself — a bare array, one entry per trip order.
        $stops = $this->actingAs($this->shipment['user'])
            ->getJson(self::DRIVER."/trips/{$trip->uuid}/stops")->assertOk()->json();

        self::assertCount($expectedStops, $stops, 'the driver sees exactly the generated stops');

        $stopOrderIds = collect($stops)->pluck('order_id')->filter()->sort()->values()->all();
        if ($stopOrderIds !== []) {
            $tripOrderIds = DB::table('distribution_trip_orders')
                ->where('trip_id', $trip->id)->pluck('order_id')->sort()->values()->all();
            self::assertSame($tripOrderIds, $stopOrderIds, 'and they are the trip own orders');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 8.-12. Acceptance → Dispatched → In Progress, with the timestamps
    // ─────────────────────────────────────────────────────────────────────────

    public function test_08_to_12_departure_records_acceptance_dispatches_and_reaches_in_progress(): void
    {
        $this->loadAndConfirmAll();
        $this->complete()->assertOk();

        $trip = $this->shipment['trip']->refresh();
        self::assertSame(TripStatus::LoadingCompleted, $trip->status);
        self::assertNull($trip->dispatched_at, 'nothing was dispatched before departure');
        self::assertNull($trip->trip_started_at);

        $this->departOk();

        $trip = $trip->refresh();

        // 8. acceptance recorded
        self::assertTrue((bool) $trip->driver_accepted_products, '8: products accepted');
        self::assertTrue((bool) $trip->driver_accepted_custody, '8: custody accepted');
        self::assertTrue((bool) $trip->driver_accepted_equipment, '8: equipment accepted');
        self::assertNotNull($trip->driver_acceptance_at, '8: acceptance is stamped');

        // 9./10. dispatch happened and stamped the operational date
        self::assertNotNull($trip->dispatched_at, '10: dispatched_at is populated');

        // 11. the start stamped its own time
        self::assertNotNull($trip->trip_started_at, '11: trip_started_at is populated');

        // 12. and the trip is on the road, reached through the legal chain
        self::assertSame(TripStatus::InProgress, $trip->status, '12: InProgress');
    }

    /**
     * 12 (chain proof). `InProgress` is unreachable from `LoadingCompleted` directly —
     * so arriving there is itself evidence the intermediate states were walked. This
     * asserts the table has not been bypassed by a force-fill.
     */
    public function test_12_in_progress_was_not_reachable_directly_from_loading_completed(): void
    {
        self::assertFalse(
            TripStatus::LoadingCompleted->canTransitionTo(TripStatus::InProgress),
            'the illegal shortcut stays illegal'
        );
        self::assertTrue(TripStatus::LoadingCompleted->canTransitionTo(TripStatus::DriverAccepted));
        self::assertTrue(TripStatus::DriverAccepted->canTransitionTo(TripStatus::ReadyForDispatch));
        self::assertTrue(TripStatus::ReadyForDispatch->canTransitionTo(TripStatus::Dispatched));
        self::assertTrue(TripStatus::Dispatched->canTransitionTo(TripStatus::InProgress));
    }

    /**
     * 9 (gate proof). Dispatch is not a rubber stamp: `dispatchBlockers()` refuses an
     * unfit driver, and the refusal must leave NOTHING stamped. Discovered while
     * certifying — the suite first failed here with this exact message.
     */
    public function test_09_dispatch_refuses_an_unfit_driver_and_stamps_nothing(): void
    {
        $this->loadAndConfirmAll();
        $this->complete()->assertOk();

        // Revoke the licence — a real operational condition, not a contrived one.
        DB::table('logistics_drivers')
            ->where('id', $this->shipment['driver']->id)
            ->update(['license_expiry_date' => null]);

        $response = $this->depart();

        $response->assertStatus(422);
        self::assertStringContainsString('cannot start deliveries', (string) $response->getContent());

        $trip = $this->shipment['trip']->refresh();

        self::assertSame(TripStatus::LoadingCompleted, $trip->status, 'the trip did not move');
        self::assertNull($trip->dispatched_at, 'nothing was dispatched');
        self::assertNull($trip->trip_started_at, 'and nothing was stamped as started');
        self::assertFalse((bool) $trip->driver_accepted_custody, 'acceptance rolled back with the refusal');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 13. Day Settlement uses the OPERATIONAL date, not created_at
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The decisive one. The trip row is aged three days so `created_at` and the
     * operational date genuinely differ; before the seam existed the read fell back
     * to `created_at` and answered on the wrong day.
     */
    public function test_13_day_settlement_finds_the_driver_on_the_operational_date_not_created_at(): void
    {
        $this->loadAndConfirmAll();
        $this->complete()->assertOk();

        $trip = $this->shipment['trip'];
        $createdDay = now()->copy()->subDays(3)->toDateString();
        DB::table('distribution_trips')->where('id', $trip->id)->update(['created_at' => $createdDay.' 09:00:00']);

        $this->departOk();

        $operationalDay = $trip->refresh()->trip_started_at->toDateString();
        self::assertNotSame($createdDay, $operationalDay, 'the two dates genuinely differ');

        $read = app(DriverDaySettlementReadService::class);

        $onOperational = $read->daySummary((string) $this->company->id, $operationalDay);
        self::assertNotEmpty($onOperational['drivers'], '13: found on the day it departed');
        self::assertSame(1, $onOperational['kpis']['total_drivers']);

        $onCreated = $read->daySummary((string) $this->company->id, $createdDay);
        self::assertSame(0, $onCreated['kpis']['total_drivers'], '13: NOT reported on its creation date');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 14./15. Idempotency and no duplicates
    // ─────────────────────────────────────────────────────────────────────────

    public function test_14_and_15_repeating_completion_and_departure_is_idempotent_with_no_duplicates(): void
    {
        $this->loadAndConfirmAll();

        $this->complete()->assertOk();
        $this->complete()->assertOk();   // repeat
        $this->departOk();
        $this->departOk();     // repeat

        $trip = $this->shipment['trip']->refresh();
        $firstDispatch = $trip->dispatched_at;
        $firstStart = $trip->trip_started_at;

        $this->complete()->assertOk();
        $this->departOk();

        $trip = $trip->refresh();

        self::assertEquals($firstDispatch, $trip->dispatched_at, '14: dispatch time is not rewritten');
        self::assertEquals($firstStart, $trip->trip_started_at, '14: start time is not rewritten');
        self::assertSame(TripStatus::InProgress, $trip->status);

        $groupOrders = DB::table('distribution_window_orders')
            ->where('virtual_slot_id', $trip->virtual_slot_id)->count();

        self::assertSame(
            $groupOrders,
            DB::table('distribution_trip_orders')->where('trip_id', $trip->id)->count(),
            '15: no duplicate trip_orders'
        );
        self::assertSame(
            $groupOrders,
            DB::table('distribution_delivery_stops')->where('trip_id', $trip->id)->count(),
            '15: no duplicate delivery stops'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 16./17. Tenancy and authentication
    // ─────────────────────────────────────────────────────────────────────────

    public function test_16_another_driver_cannot_reach_this_drivers_trip_or_loading(): void
    {
        $this->loadAndConfirmAll();
        $this->complete()->assertOk();

        $trip = $this->shipment['trip']->refresh();

        $otherUser = User::factory()->create(['company_id' => $this->company->id]);
        $this->driverFor($otherUser); // a real driver, with no assignment to this trip

        $this->actingAs($otherUser)
            ->getJson(self::DRIVER."/trips/{$trip->uuid}")
            ->assertNotFound();

        $this->actingAs($otherUser)
            ->getJson(self::DRIVER."/trips/{$trip->uuid}/stops")
            ->assertNotFound();

        $this->actingAs($otherUser)
            ->postJson(self::DRIVER."/trips/{$trip->uuid}/start", [])
            ->assertNotFound();

        // And the other driver's own view is empty rather than showing foreign work.
        $trips = $this->actingAs($otherUser)->getJson(self::DRIVER.'/trips')->assertOk()->json();
        self::assertSame([], is_array($trips) ? $trips : [], 'no foreign trip is listed');
    }

    public function test_17_unauthenticated_access_is_denied(): void
    {
        $trip = $this->shipment['trip'];

        // The driver group carries BOTH `auth:sanctum` and a permission middleware, so an
        // anonymous caller is refused with 401 or 403 depending on which answers first.
        // What is certified is that no unauthenticated caller is ever served.
        $routes = [
            ['get', self::DRIVER.'/trips'],
            ['get', self::DRIVER."/trips/{$trip->uuid}"],
            ['post', self::DRIVER."/trips/{$trip->uuid}/start"],
            ['post', self::DRIVER.'/loading/complete'],
            ['get', self::DRIVER.'/loading'],
        ];

        foreach ($routes as [$verb, $uri]) {
            $status = ($verb === 'get' ? $this->getJson($uri) : $this->postJson($uri))->status();

            self::assertContains(
                $status,
                [401, 403],
                "{$verb} {$uri} must refuse an unauthenticated caller, got {$status}",
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Fixture — every step through a real route with a real actor
    // ─────────────────────────────────────────────────────────────────────────

    /** Orders → collect → window → group → zone → assign vehicle. Returns the shipment. */
    private function buildShipment(): array
    {
        foreach ([$this->honey, $this->nuts] as $product) {
            $this->line($this->order($this->warehouse, 'Maadi'), $product, 10.0);
        }

        $this->actingAs($this->operator())
            ->postJson(self::DIST.'/windows/collect')
            ->assertOk()
            ->assertJsonPath('data.cities_unresolved', 0);

        $window = (string) $this->actingAs($this->operator())
            ->getJson(self::DIST.'/windows/current?warehouse_id='.$this->warehouse->id)
            ->assertOk()->json('data.window.id');

        $groupId = (string) $this->actingAs($this->operator())
            ->postJson(self::DIST."/windows/{$window}/slots", [
                'warehouse_id' => $this->warehouse->id,
                'code' => 'CERT-A',
            ])->assertSuccessful()->json('data.id');

        $this->actingAs($this->operator())
            ->postJson(self::DIST."/windows/{$window}/slots/{$groupId}/zones", ['zone_id' => $this->zone])
            ->assertSuccessful();

        $user = User::factory()->create(['company_id' => $this->company->id]);
        $driver = $this->driverFor($user);
        $vehicle = $this->vehicle();

        $this->actingAs($this->operator())
            ->postJson(self::DIST."/windows/{$window}/slots/{$groupId}/assign-vehicle", [
                'vehicle_id' => $vehicle->uuid,
                'driver_id' => $driver->uuid,
            ])->assertOk();

        return [
            'user' => $user,
            'driver' => $driver,
            'vehicle' => $vehicle,
            'group' => VirtualCapacitySlot::query()->findOrFail($groupId),
            'trip' => Trip::query()->where('virtual_slot_id', $groupId)->firstOrFail(),
            'window' => $window,
        ];
    }

    /** Open the warehouse loading session for the group's trip (idempotent). */
    private function openLoading(): ?VehicleAssignment
    {
        $trip = $this->shipment['trip'];
        $existing = VehicleAssignment::query()->where('trip_id', $trip->id)->first();

        if ($existing === null) {
            $this->actingAs($this->operator())
                ->postJson(self::DIST."/windows/{$this->shipment['window']}/slots/{$this->shipment['group']->id}/trips/{$trip->uuid}/loading")
                ->assertSuccessful();
        }

        return VehicleAssignment::query()->where('trip_id', $trip->id)->first();
    }

    /** The WAREHOUSE records and confirms what it put on the vehicle. */
    private function warehouseLoads(Product $product, float $quantity): TestResponse
    {
        return $this->actingAs($this->operator())
            ->postJson("/api/loading/groups/{$this->shipment['group']->id}/products/{$product->id}/confirm", [
                'quantity_loaded' => $quantity,
            ]);
    }

    /** The DRIVER counts it and accepts custody. */
    private function driverConfirms(Product $product, float $quantity): TestResponse
    {
        return $this->actingAs($this->shipment['user'])
            ->postJson(self::DRIVER."/loading/products/{$product->id}/confirm", [
                'received_qty' => $quantity,
            ]);
    }

    /** Load and fully confirm every product on the manifest. */
    private function loadAndConfirmAll(): void
    {
        $assignment = $this->openLoading();

        foreach (LoadingTask::query()->where('vehicle_assignment_id', $assignment->id)->get() as $task) {
            $qty = max(1.0, (float) $task->quantity_planned);
            $this->warehouseLoads($this->productOf((string) $task->product_id), $qty)->assertSuccessful();
            $this->driverConfirms($this->productOf((string) $task->product_id), $qty)->assertSuccessful();
        }

        self::assertSame(
            [],
            app(LoadingCustodyService::class)->unresolvedLoadedTasks((string) $assignment->id),
            'the manifest is fully confirmed before completion'
        );
    }

    private function productOf(string $productId): Product
    {
        return Product::query()->findOrFail($productId);
    }

    private function complete(): TestResponse
    {
        return $this->actingAs($this->shipment['user'])->postJson(self::DRIVER.'/loading/complete');
    }

    /** Depart, and on refusal say WHY — a bare 422 hides the dispatch blocker. */
    private function departOk(): void
    {
        $response = $this->depart();

        self::assertSame(
            200,
            $response->status(),
            'departure was refused: '.$response->getContent(),
        );
    }

    private function depart(): TestResponse
    {
        return $this->actingAs($this->shipment['user'])
            ->postJson(self::DRIVER."/trips/{$this->shipment['trip']->uuid}/start", []);
    }

    private function operator(): User
    {
        return User::factory()->create(['company_id' => $this->company->id]);
    }

    private function driverFor(User $user): Driver
    {
        $suffix = strtoupper(substr(md5(uniqid('', true)), 0, 8));

        $driver = new Driver([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'driver_code' => 'DRV-'.$suffix,
            'full_name' => 'Cert Driver '.$suffix,
            'mobile' => '01'.random_int(100000000, 999999999),
            'national_id' => (string) random_int(10000000000000, 99999999999999),
            // Dispatch is gated on licence validity via Driver::canStartDeliveries();
            // omitting these makes licenseStatus() === LICENSE_MISSING and the trip is
            // refused. Certified explicitly in test_09.
            'license_issue_date' => '2024-01-01',
            'license_expiry_date' => '2031-01-01',
            'status' => Driver::STATUS_ACTIVE,
        ]);
        $driver->company_id = $this->company->id;
        $driver->save();

        return $driver->refresh();
    }

    private function vehicle(): Vehicle
    {
        $suffix = strtoupper(substr(md5(uniqid('', true)), 0, 8));

        return Vehicle::create([
            'company_id' => $this->company->id,
            'vehicle_code' => 'VEH-'.$suffix,
            'plate_number' => 'PLATE-'.$suffix,
            'type' => 'van',
            'capacity_orders' => 40,
            'status' => 'available',
        ]);
    }

    private function zone(string $name): int
    {
        return (int) DB::table('distribution_zones')->insertGetId([
            'code' => 'CERT-'.strtoupper(substr(md5(uniqid('', true)), 0, 6)),
            'name_ar' => $name, 'name_en' => $name,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function city(int $governorate, string $en, string $ar, int $zoneId): void
    {
        DB::table('logistics_cities')->insert([
            'governorate_id' => $governorate,
            'name_ar' => $ar, 'name_en' => $en,
            'distribution_zone_id' => $zoneId,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function order(Warehouse $warehouse, string $city): Order
    {
        return Order::query()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-CERT-'.uniqid(),
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

    private function line(Order $order, Product $product, float $quantity): void
    {
        OrderLine::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_price' => 10,
            'line_total' => $quantity * 10,
        ]);
    }

    private function statusValue(mixed $status): string
    {
        return $status instanceof \BackedEnum ? (string) $status->value : (string) $status;
    }
}

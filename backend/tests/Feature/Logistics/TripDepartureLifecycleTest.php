<?php

declare(strict_types=1);

namespace Tests\Feature\Logistics;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Logistics\Distribution\Domain\Enums\TripStatus;
use Modules\Logistics\Distribution\Domain\Enums\TripType;
use Modules\Logistics\Distribution\Domain\Models\Trip;
use Modules\Logistics\Distribution\Domain\Services\DriverDaySettlementReadService;
use Modules\Logistics\Distribution\Domain\Services\TripService;
use Modules\Logistics\Drivers\Domain\Models\Driver;
use Modules\Logistics\Drivers\Domain\Services\DriverVehicleAssignmentService;
use Modules\Logistics\Vehicles\Domain\Models\Vehicle;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Operations\Loading\Domain\Enums\VehicleAssignmentStatus;
use Modules\Operations\Loading\Domain\Models\LoadingTask;
use Modules\Operations\Loading\Domain\Models\VehicleAssignment;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-TRIP-LIFECYCLE-AND-VEHICLE-CUSTODY-BRIDGE-001 — the departure seam.
 *
 * ┌─ WHAT THIS PINS ─────────────────────────────────────────────────────────┐
 * │ A loaded trip sits at LoadingCompleted. `InProgress` is NOT reachable      │
 * │ from there — the table routes through DriverAccepted → ReadyForDispatch    │
 * │ → Dispatched — and until this task NOTHING in the operational flow walked  │
 * │ those three. `dispatched_at` and `trip_started_at` therefore stayed NULL   │
 * │ forever, and the Day Settlement date fell back to `created_at`.            │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * The last test is the one that matters to the business: it asks the REAL
 * settlement read service, not a hand-rolled query, whether the trip now appears
 * on the day it actually departed.
 */
final class TripDepartureLifecycleTest extends TestCase
{
    use DatabaseTransactions;

    private Company $company;

    private User $driverUser;

    private Driver $driver;

    private Vehicle $vehicle;

    private Trip $trip;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->driverUser = User::factory()->create(['company_id' => $this->company->id]);
        $this->driver = $this->makeDriver($this->driverUser);

        $assignment = app(DriverVehicleAssignmentService::class)
            ->assign($this->driver, $this->vehicle = $this->makeVehicle());

        $this->trip = Trip::create([
            'company_id' => $this->company->id,
            'trip_number' => 'TRP-'.substr(md5(uniqid('', true)), 0, 6),
            'name' => 'Departure Run',
            'type' => TripType::CompanyVehicle->value,
            'capacity' => 3,
            'created_by' => $this->driverUser->id,
            'driver_vehicle_assignment_id' => $assignment->id,
        ]);
    }

    // ── A. The seam works ─────────────────────────────────────────────────────

    public function test_departure_walks_a_loaded_trip_to_dispatched_and_stamps_the_operational_date(): void
    {
        $this->withOrder()->atLoadingCompleted();

        $this->depart()->assertOk();

        $trip = $this->trip->refresh();

        self::assertSame(TripStatus::InProgress, $trip->status, 'the driver is on the road');
        self::assertNotNull($trip->dispatched_at, 'dispatch stamped the operational date');
        self::assertNotNull($trip->trip_started_at, 'the start stamped its own time');
        self::assertTrue($trip->driver_accepted_products);
        self::assertTrue($trip->driver_accepted_custody);
        self::assertTrue($trip->driver_accepted_equipment);
    }

    // ── B. Custody still governs ──────────────────────────────────────────────

    /**
     * A product the warehouse loaded that this driver never confirmed must stop the
     * departure — and must leave NOTHING behind. A trip that is stamped as started
     * but never dispatched is a state no later step can reconcile.
     */
    public function test_an_unconfirmed_loaded_product_blocks_departure_and_leaves_no_partial_stamp(): void
    {
        $this->withOrder()->atLoadingCompleted();
        $this->unconfirmedLoadedTask();

        $this->depart()->assertStatus(422);

        $trip = $this->trip->refresh();

        self::assertSame(TripStatus::LoadingCompleted, $trip->status, 'the trip did not move');
        self::assertNull($trip->dispatched_at, 'nothing was dispatched');
        self::assertNull($trip->trip_started_at, 'and nothing was stamped as started');
        self::assertFalse((bool) $trip->driver_accepted_custody, 'custody was not claimed on the driver\'s behalf');
    }

    // ── C. The pre-existing dispatch gate is unchanged ────────────────────────

    public function test_a_trip_with_no_orders_is_refused_with_the_blocker_reason(): void
    {
        $this->atLoadingCompleted(); // no order assigned

        $this->depart()
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'no orders'));

        self::assertNull($this->trip->refresh()->dispatched_at);
    }

    // ── D. Idempotency ────────────────────────────────────────────────────────

    public function test_a_second_departure_post_does_not_re_stamp_or_error(): void
    {
        $this->withOrder()->atLoadingCompleted();

        $this->depart()->assertOk();
        $first = $this->trip->refresh()->dispatched_at;

        $this->depart()->assertOk();

        self::assertEquals($first, $this->trip->refresh()->dispatched_at, 'the dispatch time is not rewritten');
        self::assertSame(TripStatus::InProgress, $this->trip->refresh()->status);
    }

    // ── E. The business outcome ───────────────────────────────────────────────

    /**
     * The symptom that started this: Day Settlement showed nothing. It anchors on
     * `DATE(COALESCE(trip_started_at, dispatched_at, created_at))`, so before the
     * seam existed every trip answered with its creation date. This asks the real
     * service — no hand-rolled SQL — whether the trip is now found on its own day.
     */
    public function test_the_settlement_day_read_finds_the_trip_on_the_day_it_departed(): void
    {
        $this->withOrder()->atLoadingCompleted();
        $this->depart()->assertOk();

        $day = $this->trip->refresh()->trip_started_at->toDateString();

        $summary = app(DriverDaySettlementReadService::class)
            ->daySummary((string) $this->company->id, $day);

        self::assertNotEmpty($summary['drivers'], 'the departed trip appears on its operational day');
        self::assertSame(1, $summary['kpis']['total_drivers']);
    }

    // ── Fixture ───────────────────────────────────────────────────────────────

    private function depart(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->driverUser)
            ->postJson("/api/driver/trips/{$this->trip->uuid}/start", []);
    }

    private function withOrder(): self
    {
        app(TripService::class)->assignOrder($this->trip->refresh(), $this->makeOrder(), [], $this->driverUser->id);

        return $this;
    }

    /**
     * A real order row — `distribution_trip_orders.order_id` carries a foreign key,
     * so a bare UUID is rejected. Same shape the sibling Distribution suite uses.
     */
    private function makeOrder(): string
    {
        $customerId = (string) Str::uuid();
        DB::table('customers')->insert([
            'id' => $customerId,
            'code' => 'CUS-'.substr(md5($customerId), 0, 8),
            'name' => 'Departure Test Customer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orderId = (string) Str::uuid();
        DB::table('orders')->insert([
            'id' => $orderId,
            'customer_id' => $customerId,
            'order_number' => 'ORD-'.substr(md5($orderId), 0, 8),
            'order_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $orderId;
    }

    /** Walk the trip to the state loading completion leaves it in. */
    private function atLoadingCompleted(): self
    {
        $trips = app(TripService::class);

        foreach ([TripStatus::Loading, TripStatus::LoadingCompleted] as $state) {
            $trips->changeStatus($this->trip->refresh(), $state);
        }

        $this->trip->refresh();

        return $this;
    }

    /** A warehouse-loaded product this driver has never confirmed receiving. */
    private function unconfirmedLoadedTask(): void
    {
        $warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);

        $sessionId = (string) Str::uuid();
        DB::table('loading_sessions')->insert([
            'id' => $sessionId,
            'company_id' => $this->company->id,
            'warehouse_id' => $warehouse->id,
            'session_number' => 'LS-'.substr(md5($sessionId), 0, 8),
            'operational_date' => now()->toDateString(),
            'status' => 'loading',
            'created_by' => (string) $this->driverUser->id,
            'updated_by' => (string) $this->driverUser->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $assignment = VehicleAssignment::create([
            'company_id' => $this->company->id,
            'loading_session_id' => $sessionId,
            'trip_id' => $this->trip->id,
            'vehicle_id' => $this->vehicle->id,
            'vehicle_registration_snapshot' => $this->vehicle->plate_number,
            'vehicle_type_snapshot' => 'van',
            'assignment_number' => 'VA-'.substr(md5($sessionId), 0, 8),
            'status' => VehicleAssignmentStatus::LoadingComplete->value,
            'created_by' => (string) $this->driverUser->id,
            'updated_by' => (string) $this->driverUser->id,
        ]);

        LoadingTask::create([
            'company_id' => $this->company->id,
            'loading_session_id' => $sessionId,
            'vehicle_assignment_id' => $assignment->id,
            'product_id' => (string) Str::uuid(),
            'sku_snapshot' => 'SKU-DEPART',
            'name_snapshot' => 'Departure Product',
            'quantity_planned' => 5,
            'created_by' => (string) $this->driverUser->id,
            'updated_by' => (string) $this->driverUser->id,
            'quantity_loaded' => 5,
            'confirmed_at' => now(),   // the warehouse loaded and confirmed it
            'driver_confirmed_at' => null, // the driver never did
        ]);
    }

    private function makeDriver(User $user): Driver
    {
        $suffix = strtoupper(substr(md5(uniqid('', true)), 0, 8));

        $driver = new Driver([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'driver_code' => 'DRV-'.$suffix,
            'full_name' => 'Departure Driver',
            'mobile' => '01'.random_int(100000000, 999999999),
            'national_id' => (string) random_int(10000000000000, 99999999999999),
            'license_issue_date' => '2024-01-01',
            'license_expiry_date' => '2031-01-01',
            'status' => Driver::STATUS_ACTIVE,
        ]);
        $driver->company_id = $user->company_id;
        $driver->save();

        return $driver->refresh();
    }

    private function makeVehicle(): Vehicle
    {
        $suffix = substr(md5(uniqid('', true)), 0, 8);

        $vehicle = new Vehicle([
            'vehicle_code' => 'VEH-'.$suffix,
            'plate_number' => 'PL-'.$suffix,
            'type' => 'van',
            'capacity_orders' => 60,
        ]);
        $vehicle->company_id = $this->company->id;
        $vehicle->save();

        return $vehicle->refresh();
    }
}

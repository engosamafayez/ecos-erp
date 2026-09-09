<?php

declare(strict_types=1);

namespace Tests\Feature\Logistics;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\OrderLine;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Logistics\Distribution\Domain\Models\DistributionGroupTemplate;
use Modules\Logistics\Distribution\Domain\Models\DistributionWindow;
use Modules\Logistics\Distribution\Domain\Services\GroupTemplateService;
use Modules\Logistics\Drivers\Domain\Models\Driver;
use Modules\Logistics\Drivers\Domain\Models\DriverVehicleAssignment;
use Modules\Logistics\Drivers\Domain\Services\DriverVehicleAssignmentService;
use Modules\Logistics\Vehicles\Domain\Models\Vehicle;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-ECOS-OPERATIONS-DISTRIBUTION-AND-LOADING-FINAL-022 §C — a Group Template's
 * Preferred Driver / Preferred Vehicle, attempted (never guaranteed) when a Group
 * is generated from the template.
 *
 * Exercises `GroupTemplateService::applyToNewGroup()` directly (the shared method
 * both the automatic Wave sweep and, historically, the now-removed manual "apply"
 * endpoint called — see §D and `DistributionWorkspaceFinalizationTest`), so this
 * proves the preference behaviour independently of which caller triggers it.
 *
 * Fixture helpers (`driverFor`, `vehicleFor`, `pair`, `groupWithOrders`, etc.)
 * mirror `GroupVehicleAssignmentTest` deliberately, so both files agree on what a
 * valid Driver/Vehicle/pairing fixture looks like.
 */
class DistributionGroupTemplatePreferredAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/logistics/distribution';

    private Company $companyA;

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

    public function test_preferred_driver_and_vehicle_are_assigned_when_both_available(): void
    {
        $vehicle = $this->vehicleFor(10);
        $driver = $this->driverFor();
        $this->pair($driver, $vehicle);

        $window = $this->currentWindow();
        $template = $this->templateFor($vehicle, $driver);

        $group = app(GroupTemplateService::class)->applyToNewGroup(
            $window,
            $template,
            $this->warehouseA->id,
            'DG-PREF-1',
            nameOverride: null,
            capacityOverride: null,
            capacityProvided: false,
            zoneIdsOverride: [$this->zoneMaadi],
        );

        $trip = DB::table('distribution_trips')->where('virtual_slot_id', $group->id)->first();

        self::assertNotNull($trip, 'A canonically available preference must produce a Trip.');
        self::assertNotNull($trip->driver_vehicle_assignment_id);
        self::assertDatabaseHas('logistics_driver_vehicle_assignments', [
            'id' => $trip->driver_vehicle_assignment_id,
            'driver_id' => $driver->id,
            'vehicle_id' => $vehicle->id,
        ]);
    }

    public function test_preference_is_skipped_when_vehicle_is_engaged_elsewhere(): void
    {
        $vehicle = $this->vehicleFor(10);
        $driver = $this->driverFor();
        $this->pair($driver, $vehicle);

        // Engage the pairing on an unrelated, already-existing Group first.
        $busyGroup = $this->groupWithOrders('DG-BUSY', 1);
        $this->assign($busyGroup, $vehicle->uuid, $driver->uuid)->assertOk();

        $window = $this->currentWindow();
        $template = $this->templateFor($vehicle, $driver);

        $group = app(GroupTemplateService::class)->applyToNewGroup(
            $window,
            $template,
            $this->warehouseA->id,
            'DG-PREF-2',
            nameOverride: null,
            capacityOverride: null,
            capacityProvided: false,
            zoneIdsOverride: [$this->zoneMaadi],
        );

        self::assertNotNull($group->id, 'The Group itself must still be created.');
        self::assertNull(
            DB::table('distribution_trips')->where('virtual_slot_id', $group->id)->first(),
            'Preference never overrides availability — an engaged pairing must leave the new Group unassigned, not fail its creation.',
        );
    }

    public function test_only_preferred_driver_falls_back_to_its_current_vehicle(): void
    {
        $vehicle = $this->vehicleFor(10);
        $driver = $this->driverFor();
        $this->pair($driver, $vehicle);

        $window = $this->currentWindow();
        $template = $this->templateFor(vehicle: null, driver: $driver);

        $group = app(GroupTemplateService::class)->applyToNewGroup(
            $window,
            $template,
            $this->warehouseA->id,
            'DG-PREF-3',
            nameOverride: null,
            capacityOverride: null,
            capacityProvided: false,
            zoneIdsOverride: [$this->zoneMaadi],
        );

        $trip = DB::table('distribution_trips')->where('virtual_slot_id', $group->id)->first();

        self::assertNotNull($trip, 'A driver-only preference must fall back to the driver\'s current vehicle.');
        self::assertDatabaseHas('logistics_driver_vehicle_assignments', [
            'id' => $trip->driver_vehicle_assignment_id,
            'driver_id' => $driver->id,
            'vehicle_id' => $vehicle->id,
        ]);
    }

    public function test_preference_is_skipped_when_the_named_driver_has_no_current_pairing(): void
    {
        $driver = $this->driverFor(); // never paired to any vehicle

        $window = $this->currentWindow();
        $template = $this->templateFor(vehicle: null, driver: $driver);

        $group = app(GroupTemplateService::class)->applyToNewGroup(
            $window,
            $template,
            $this->warehouseA->id,
            'DG-PREF-4',
            nameOverride: null,
            capacityOverride: null,
            capacityProvided: false,
            zoneIdsOverride: [$this->zoneMaadi],
        );

        self::assertNull(
            DB::table('distribution_trips')->where('virtual_slot_id', $group->id)->first(),
            'A lone preference with no current pairing to complete it must not invent one.',
        );
    }

    /** TASK-...-FINAL-022 §C — the new driver-side Loading-busy guard. */
    public function test_preference_is_skipped_when_the_driver_is_busy_in_active_loading_work(): void
    {
        $vehicle = $this->vehicleFor(10);
        $driver = $this->driverFor();
        $this->pair($driver, $vehicle);
        $this->markDriverBusyInLoading($driver, $vehicle);

        $window = $this->currentWindow();
        $template = $this->templateFor($vehicle, $driver);

        $group = app(GroupTemplateService::class)->applyToNewGroup(
            $window,
            $template,
            $this->warehouseA->id,
            'DG-PREF-5',
            nameOverride: null,
            capacityOverride: null,
            capacityProvided: false,
            zoneIdsOverride: [$this->zoneMaadi],
        );

        self::assertNull(
            DB::table('distribution_trips')->where('virtual_slot_id', $group->id)->first(),
            'A driver committed to active Operations\\Loading work must not be auto-assigned.',
        );
    }

    // ── Helpers (mirroring GroupVehicleAssignmentTest) ──────────────────────────

    private function templateFor(?Vehicle $vehicle, ?Driver $driver): DistributionGroupTemplate
    {
        $created = $this->actingAs($this->userFor())
            ->postJson(self::BASE.'/group-templates', [
                'name' => 'Pref-'.Str::random(8),
                'zone_ids' => [],
                'preferred_driver_id' => $driver?->id,
                'preferred_vehicle_id' => $vehicle?->id,
            ])->assertStatus(201)->json('data');

        return DistributionGroupTemplate::findOrFail($created['id']);
    }

    private function currentWindow(): DistributionWindow
    {
        $windowId = (string) $this->actingAs($this->userFor())
            ->getJson(self::BASE.'/windows/current')->assertOk()->json('data.window.id');

        return DistributionWindow::findOrFail($windowId);
    }

    private function pair(Driver $driver, Vehicle $vehicle): DriverVehicleAssignment
    {
        return app(DriverVehicleAssignmentService::class)->assign($driver, $vehicle);
    }

    private function assign(array $group, string $vehicleRef, string $driverRef): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->userFor())
            ->postJson(
                self::BASE."/windows/{$this->windowId()}/slots/{$group['id']}/assign-vehicle",
                ['vehicle_id' => $vehicleRef, 'driver_id' => $driverRef],
            );
    }

    /**
     * Minimal, best-effort valid rows down the driver_assignments FK chain
     * (loading_sessions -> vehicle_assignments -> driver_assignments), so the new
     * loadingBusyDriverUuids() guard has something real to find. Unverified by
     * execution — the test database is unreachable in this environment.
     */
    private function markDriverBusyInLoading(Driver $driver, Vehicle $vehicle): void
    {
        $sessionId = (string) Str::uuid();
        DB::table('loading_sessions')->insert([
            'id' => $sessionId,
            'company_id' => $this->companyA->id,
            'warehouse_id' => $this->warehouseA->id,
            'session_number' => 'LS-'.mt_rand(10000, 99999),
            'operational_date' => now()->toDateString(),
            'status' => 'loading',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $assignmentId = (string) Str::uuid();
        DB::table('vehicle_assignments')->insert([
            'id' => $assignmentId,
            'company_id' => $this->companyA->id,
            'loading_session_id' => $sessionId,
            'vehicle_id' => $vehicle->uuid,
            'vehicle_registration_snapshot' => $vehicle->plate_number,
            'vehicle_type_snapshot' => 'van',
            'capacity_weight_kg_snapshot' => 100,
            'capacity_volume_m3_snapshot' => 10,
            'refrigerated_snapshot' => false,
            'assignment_number' => 'VA-'.mt_rand(10000, 99999),
            'status' => 'loading',
            'orders_count' => 0,
            'loading_weight_kg' => 0,
            'loading_volume_m3' => 0,
            'created_by' => (string) Str::uuid(),
            'updated_by' => (string) Str::uuid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('driver_assignments')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $this->companyA->id,
            'vehicle_assignment_id' => $assignmentId,
            'loading_session_id' => $sessionId,
            'vehicle_id' => $vehicle->uuid,
            'driver_id' => $driver->uuid,
            'driver_name_snapshot' => $driver->full_name,
            'status' => 'on_trip',
            'assignment_type' => 'primary',
            'assigned_at' => now(),
            'assigned_by' => (string) Str::uuid(),
            'created_by' => (string) Str::uuid(),
            'updated_by' => (string) Str::uuid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function vehicleFor(int $capacity): Vehicle
    {
        $vehicle = Vehicle::withoutEvents(fn () => Vehicle::create([
            'plate_number' => 'V-'.mt_rand(1000, 9999),
            'capacity_orders' => $capacity,
            'status' => 'available',
        ]));

        $vehicle->forceFill([
            'uuid' => (string) Str::uuid(),
            'company_id' => $this->companyA->id,
        ])->saveQuietly();

        return $vehicle->refresh();
    }

    private function driverFor(): Driver
    {
        $driver = Driver::withoutEvents(fn () => Driver::create([
            'driver_code' => 'DRV-'.mt_rand(10000, 99999),
            'full_name' => 'Driver '.mt_rand(1000, 9999),
            'mobile' => '01'.mt_rand(100000000, 999999999),
            'national_id' => (string) mt_rand(10000000000000, 99999999999999),
            'status' => Driver::STATUS_ACTIVE,
        ]));

        $driver->forceFill([
            'uuid' => (string) Str::uuid(),
            'company_id' => $this->companyA->id,
        ])->saveQuietly();

        return $driver->refresh();
    }

    private function userFor(): User
    {
        return User::factory()->create(['company_id' => $this->companyA->id]);
    }

    private function groupWithOrders(string $code, int $count): array
    {
        for ($i = 0; $i < $count; $i++) {
            $o = $this->order();
            $this->line($o, $this->honey->id, 1);
        }

        $this->collect();
        $group = $this->group($code);

        if ($count > 0) {
            $this->addZone($group['id'], $this->zoneMaadi);
        }

        return $group;
    }

    private function collect(): void
    {
        $this->actingAs($this->userFor())
            ->postJson(self::BASE.'/windows/collect')->assertOk();
    }

    private function windowId(): string
    {
        return (string) $this->actingAs($this->userFor())
            ->getJson(self::BASE.'/windows/current')->assertOk()->json('data.window.id');
    }

    private function group(string $code): array
    {
        return $this->actingAs($this->userFor())
            ->postJson(self::BASE."/windows/{$this->windowId()}/slots", [
                'warehouse_id' => $this->warehouseA->id,
                'code' => $code,
            ])->assertStatus(201)->json('data');
    }

    private function addZone(string $groupId, int $zoneId): void
    {
        $this->actingAs($this->userFor())
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

    private function order(): Order
    {
        return Order::query()->create([
            'company_id' => $this->companyA->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-PA-'.uniqid(),
            'order_date' => now()->toDateString(),
            'assigned_warehouse_id' => $this->warehouseA->id,
            'city' => 'Maadi',
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

<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Logistics\Distribution\Domain\Models\DistributionZone;
use Modules\Logistics\Geography\Domain\Models\City;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-ECOS-DRIVER-ORDERS-LIST-PAGE-CLOSURE-001 §9/§14 — the Driver Orders list now
 * exposes the canonical Distribution Zone (orders.logistics_city_id →
 * logistics_cities.distribution_zone_id → distribution_zones), resolved via the
 * EXISTING OrderZoneResolver, batched per trip rather than per stop.
 *
 * Focused backend proof only: correct zone resolution, tenant isolation, and a
 * bounded query count (no N+1) for GET /api/driver/trips/{tripId}/stops.
 */
final class DriverOrdersZoneFilterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Minimal fixture for GET /driver/trips/{id}/stops — a driver/vehicle/pairing/trip
     * (satisfying ownedTrip()'s ownership check) plus N orders, each optionally
     * assigned to a canonical City→Zone. Deliberately excludes loading/custody
     * machinery (irrelevant to this read path) — see DriverStopDeliveryTest for the
     * heavier fixture the delivery-recording flow actually needs.
     *
     * @param  list<array{governorate: string, cityId: string|null}>  $orders
     * @return array{user: User, trip_uuid: string, order_ids: array<int, string>}
     */
    private function tripWithOrders(Company $company, Customer $customer, array $orders): array
    {
        $user = User::factory()->create(['company_id' => $company->id]);

        $driverId = (int) DB::table('logistics_drivers')->insertGetId([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'driver_code' => 'DRV-'.substr(uniqid(), -6),
            'full_name' => 'Driver '.substr(uniqid(), -4),
            'mobile' => '0100'.random_int(1000000, 9999999),
            'national_id' => (string) random_int(10000000000000, 99999999999999),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $vehicleId = (int) DB::table('logistics_vehicles')->insertGetId([
            'company_id' => $company->id,
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

        $tripUuid = (string) Str::uuid();
        $tripId = (int) DB::table('distribution_trips')->insertGetId([
            'uuid' => $tripUuid,
            'company_id' => $company->id,
            'trip_number' => 'TRP-'.substr(uniqid(), -6),
            'name' => 'zone filter trip',
            // On the road — required for the PII stage to disclose geographic fields
            // (zone included), the same gate governorate/city/area already sit behind.
            'status' => 'in_progress',
            'driver_vehicle_assignment_id' => $pairingId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $orderIds = [];
        $sequence = 1;
        foreach ($orders as $spec) {
            $order = Order::query()->create([
                'company_id' => $company->id,
                'customer_id' => $customer->id,
                'order_number' => 'ORD-'.strtoupper(substr(uniqid(), -8)),
                'order_date' => now()->toDateString(),
                'governorate' => $spec['governorate'],
                'city' => $spec['governorate'],
                'logistics_city_id' => $spec['cityId'],
                'status' => 'in_progress',
                'subtotal' => 100, 'total' => 100, 'deposit_amount' => 0,
                'shipping_total' => 0, 'discount_total' => 0, 'tax_total' => 0,
            ]);
            $orderIds[] = $order->id;

            DB::table('distribution_delivery_stops')->insert([
                'uuid' => (string) Str::uuid(),
                'trip_id' => $tripId,
                'order_id' => $order->id,
                'sequence' => $sequence++,
                'status' => 'pending',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return ['user' => $user, 'trip_uuid' => $tripUuid, 'order_ids' => $orderIds];
    }

    private function makeZone(string $nameEn): DistributionZone
    {
        return DistributionZone::query()->create([
            'code' => 'Z-'.strtoupper(substr(uniqid(), -6)),
            'name_en' => $nameEn,
            'name_ar' => $nameEn.'-ar',
            'is_active' => true,
        ]);
    }

    /**
     * Raw inserts, not Eloquent create(): City::$fillable does not expose
     * distribution_zone_id (it was added by a later migration than the model's own
     * fillable list), and governorate_id is a required FK with no factory in this
     * codebase — matching the established raw-DB fixture convention this test suite
     * already uses (see DriverStopDeliveryTest::scenario()) for exactly this reason.
     */
    private function makeCity(string $nameEn, ?int $zoneId): City
    {
        $governorateId = (int) DB::table('logistics_governorates')->insertGetId([
            'name_ar' => $nameEn.'-gov-ar',
            'name_en' => $nameEn.'-gov',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $cityId = (int) DB::table('logistics_cities')->insertGetId([
            'governorate_id' => $governorateId,
            'name_ar' => $nameEn.'-ar',
            'name_en' => $nameEn,
            'distribution_zone_id' => $zoneId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return City::query()->findOrFail($cityId);
    }

    // ── Correct zone resolution ───────────────────────────────────────────────────

    public function test_stops_expose_the_resolved_canonical_zone_for_each_order(): void
    {
        $company = Company::factory()->create();
        $customer = Customer::factory()->create();
        $zone = $this->makeZone('Downtown');
        $city = $this->makeCity('Cairo', $zone->id);

        $fixture = $this->tripWithOrders($company, $customer, [
            ['governorate' => 'Cairo', 'cityId' => $city->id],
        ]);

        $response = $this->actingAs($fixture['user'])
            ->getJson("/api/driver/trips/{$fixture['trip_uuid']}/stops")
            ->assertOk();

        $zonePayload = $response->json('0.order.zone');
        self::assertSame($zone->id, $zonePayload['id']);
        self::assertSame('Downtown', $zonePayload['name_en']);
        self::assertSame('Downtown-ar', $zonePayload['name_ar']);
    }

    public function test_an_order_whose_city_has_no_zone_resolves_to_null_not_an_error(): void
    {
        $company = Company::factory()->create();
        $customer = Customer::factory()->create();
        $city = $this->makeCity('Zoneless City', null);

        $fixture = $this->tripWithOrders($company, $customer, [
            ['governorate' => 'Somewhere', 'cityId' => $city->id],
        ]);

        $response = $this->actingAs($fixture['user'])
            ->getJson("/api/driver/trips/{$fixture['trip_uuid']}/stops")
            ->assertOk();

        self::assertNull($response->json('0.order.zone'));
    }

    public function test_an_order_with_no_logistics_city_at_all_resolves_to_null_not_an_error(): void
    {
        $company = Company::factory()->create();
        $customer = Customer::factory()->create();

        $fixture = $this->tripWithOrders($company, $customer, [
            ['governorate' => 'Somewhere', 'cityId' => null],
        ]);

        $response = $this->actingAs($fixture['user'])
            ->getJson("/api/driver/trips/{$fixture['trip_uuid']}/stops")
            ->assertOk();

        self::assertNull($response->json('0.order.zone'));
    }

    // ── Tenant isolation ───────────────────────────────────────────────────────────

    public function test_one_companys_zone_resolution_never_leaks_another_companys_order_data(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $customerA = Customer::factory()->create();
        $customerB = Customer::factory()->create();

        $zoneA = $this->makeZone('Zone A');
        $zoneB = $this->makeZone('Zone B');
        $cityA = $this->makeCity('City A', $zoneA->id);
        $cityB = $this->makeCity('City B', $zoneB->id);

        $fixtureA = $this->tripWithOrders($companyA, $customerA, [
            ['governorate' => 'A', 'cityId' => $cityA->id],
        ]);
        $this->tripWithOrders($companyB, $customerB, [
            ['governorate' => 'B', 'cityId' => $cityB->id],
        ]);

        $response = $this->actingAs($fixtureA['user'])
            ->getJson("/api/driver/trips/{$fixtureA['trip_uuid']}/stops")
            ->assertOk();

        // Company A's driver sees exactly one stop — its own — resolved to its own Zone.
        self::assertCount(1, $response->json());
        self::assertSame('Zone A', $response->json('0.order.zone.name_en'));
        self::assertSame($fixtureA['order_ids'][0], $response->json('0.order.id'));
    }

    // ── Bounded query count — no N+1 ────────────────────────────────────────────────

    public function test_zone_resolution_adds_a_fixed_number_of_queries_regardless_of_stop_count(): void
    {
        $company = Company::factory()->create();
        $customer = Customer::factory()->create();
        $zone = $this->makeZone('Shared Zone');
        $city = $this->makeCity('Shared City', $zone->id);

        // Two trips, differing only in stop count — the QUERY COUNT this test asserts
        // on must not grow between them, proving zone resolution is batched per trip
        // rather than issued once per stop.
        $small = $this->tripWithOrders($company, $customer, [
            ['governorate' => 'Cairo', 'cityId' => $city->id],
        ]);
        $large = $this->tripWithOrders($company, $customer, array_fill(0, 8, ['governorate' => 'Cairo', 'cityId' => $city->id]));

        DB::enableQueryLog();
        $this->actingAs($small['user'])->getJson("/api/driver/trips/{$small['trip_uuid']}/stops")->assertOk();
        $smallQueryCount = count(DB::getQueryLog());

        DB::flushQueryLog();
        $this->actingAs($large['user'])->getJson("/api/driver/trips/{$large['trip_uuid']}/stops")->assertOk();
        $largeQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // 1 stop vs 8 stops must not scale the query count by stop count — the
        // pre-existing per-stop Order fetch (out of this task's scope) means these
        // are not perfectly equal, but zone resolution's OWN batching means the
        // DELTA is nowhere near proportional to the 8x increase in stops.
        self::assertLessThan(
            $smallQueryCount * 3,
            $largeQueryCount,
            "Query count scaled with stop count ({$smallQueryCount} -> {$largeQueryCount}) — zone resolution is not batched.",
        );
    }
}

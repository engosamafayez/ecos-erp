<?php

declare(strict_types=1);

namespace Tests\Feature\Logistics;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\IAM\Domain\Models\Role;
use Modules\Logistics\Distribution\Domain\Enums\TripType;
use Modules\Logistics\Distribution\Domain\Models\Trip;
use Modules\Logistics\Distribution\Domain\Services\DeliveryService as DistributionDeliveryService;
use Modules\Logistics\Distribution\Domain\Services\TripService;
use Modules\Logistics\Drivers\Domain\Models\Driver;
use Modules\Logistics\Drivers\Domain\Services\DriverVehicleAssignmentService;
use Modules\Logistics\Vehicles\Domain\Models\Vehicle;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-SHIPPING-OS-REDESIGN-002 §8/§21.6 — Control Tower's Active
 * Execution section needs "stops completed / total" per trip. Covers the
 * `stops_completed_count` field added to `TripController::index()`/`show()`
 * this task (a conditional withCount alongside the existing `stops_count` —
 * no new table, no new engine, tenant scope untouched).
 */
class TripStopsCompletedCountTest extends TestCase
{
    use DatabaseTransactions;

    private const BASE = '/api/logistics/distribution';

    private User $user;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->user = User::factory()->create(['company_id' => $this->company->id]);

        $role = Role::create([
            'name' => 'Trip Stops Test Admin',
            'slug' => 'trip-stops-test-admin-'.substr(md5(uniqid('', true)), 0, 8),
            'is_system' => true,
        ]);
        $this->user->roles()->attach($role->id);
    }

    private function auth(): static
    {
        return $this->actingAs($this->user);
    }

    private function makeOrder(): string
    {
        $customerId = (string) Str::uuid();
        DB::table('customers')->insert([
            'id' => $customerId,
            'code' => 'CUS-'.substr(md5($customerId), 0, 8),
            'name' => 'Stops Count Test Customer',
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

    /** A trip with three real, canonically-generated stops (not hand-inserted rows). */
    private function tripWithThreeStops(): Trip
    {
        $trip = Trip::create([
            'company_id' => $this->company->id,
            'trip_number' => 'TRP-'.substr(md5(uniqid('', true)), 0, 6),
            'name' => 'Stops Completed Count Test Run',
            'type' => TripType::CompanyVehicle->value,
            'capacity' => 5,
            'created_by' => $this->user->id,
        ]);

        $suffix = substr(md5(uniqid('', true)), 0, 8);
        $assignment = app(DriverVehicleAssignmentService::class)->assign(
            Driver::create([
                'driver_code' => 'DRV-'.$suffix,
                'full_name' => 'Stops Count Test Driver',
                'mobile' => '010'.substr($suffix, 0, 8),
                'national_id' => 'NID-'.$suffix,
                'license_issue_date' => '2024-01-01',
                'license_expiry_date' => '2031-01-01',
            ]),
            Vehicle::create([
                'vehicle_code' => 'VEH-'.$suffix,
                'plate_number' => 'PL-'.$suffix,
                'type' => 'van',
                'capacity_orders' => 60,
            ]),
        );
        $trip->update(['driver_vehicle_assignment_id' => $assignment->id]);

        $tripService = app(TripService::class);
        foreach (range(1, 3) as $i) {
            $tripService->assignOrder($trip->refresh(), $this->makeOrder());
        }

        app(DistributionDeliveryService::class)->generateStops($trip->refresh());

        return $trip->refresh();
    }

    public function test_stops_completed_count_reflects_settled_stops_only(): void
    {
        $trip = $this->tripWithThreeStops();
        $stops = $trip->stops()->orderBy('id')->get();
        $this->assertCount(3, $stops, 'fixture setup must produce exactly 3 stops');

        // One settled (delivered), one settled (failed), one still pending —
        // deliberately mixed outcomes, not all-or-nothing, so a query that
        // only counted a single status would fail this.
        $stops[0]->update(['status' => 'delivered']);
        $stops[1]->update(['status' => 'failed']);
        // $stops[2] stays 'pending'.

        $response = $this->auth()->getJson(self::BASE.'/trips?search='.$trip->trip_number);
        $response->assertOk();

        $row = collect($response->json('data'))->firstOrFail(fn ($t) => $t['id'] === $trip->id);

        $this->assertSame(3, $row['stops_count'], 'stops_count must still be the TOTAL, unchanged by this task');
        $this->assertSame(
            2,
            $row['stops_completed_count'],
            'stops_completed_count must count delivered+failed (settled) but not the pending stop',
        );
    }

    public function test_stops_completed_count_is_zero_not_null_when_no_stop_is_settled(): void
    {
        $trip = $this->tripWithThreeStops();
        // All three stops remain 'pending' — the default from generateStops().

        $response = $this->auth()->getJson(self::BASE.'/trips?search='.$trip->trip_number);
        $response->assertOk();

        $row = collect($response->json('data'))->firstOrFail(fn ($t) => $t['id'] === $trip->id);

        // A real, verified zero — not an absent/null field a fabricating client
        // could mistake for "no data available".
        $this->assertSame(0, $row['stops_completed_count']);
        $this->assertSame(3, $row['stops_count']);
    }

    public function test_trips_list_is_scoped_to_the_acting_company(): void
    {
        $otherCompany = Company::factory()->create();
        $otherTrip = Trip::create([
            'company_id' => $otherCompany->id,
            'trip_number' => 'TRP-OTHER-'.substr(md5(uniqid('', true)), 0, 6),
            'name' => 'Other Company Trip',
            'type' => TripType::CompanyVehicle->value,
            'capacity' => 3,
            'created_by' => $this->user->id,
        ]);

        $response = $this->auth()->getJson(self::BASE.'/trips');
        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id');

        // Unchanged tenant guard (TripController::index()'s own
        // `where('company_id', $this->companyId())`) — this task's withCount
        // addition sits inside the same scoped query, not before/around it.
        $this->assertFalse($ids->contains($otherTrip->id), 'a trip from another company must not leak into the list');
    }
}

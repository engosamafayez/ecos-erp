<?php

declare(strict_types=1);

namespace Tests\Feature\Logistics;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-SHIPPING-OS-REDESIGN-004 §28 — LiveMapController's two read
 * models: GET /logistics/distribution/live-map and
 * GET /logistics/distribution/trips/{id}/location-history.
 *
 * ┌─ ENVIRONMENT ────────────────────────────────────────────────────────────┐
 * │ The Shipping test DB has been unreachable across Tasks 001-004 (127.0.0.1 │
 * │ :3306 connection refused). These tests are written and PHPStan-validated  │
 * │ but, per this task's own §28 instruction, were NOT executed — do not      │
 * │ treat a lack of a green run as a claim they pass.                         │
 * └────────────────────────────────────────────────────────────────────────────┘
 */
final class LiveDriverMapTest extends TestCase
{
    use RefreshDatabase;

    private const LIVE_MAP = '/api/logistics/distribution/live-map';

    /**
     * A Driver + Vehicle + active pairing + Trip, with the custody/status
     * combination the caller asks for. Mirrors the raw-DB fixture convention
     * DriverOrdersZoneFilterTest::tripWithOrders() already established for
     * this exact table set.
     *
     * @return array{trip_id: int, trip_uuid: string, driver_id: int}
     */
    private function tripFixture(
        Company $company,
        string $status,
        bool $fullCustody,
        ?string $tripNumber = null,
    ): array {
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
            'trip_number' => $tripNumber ?? 'TRP-'.substr(uniqid(), -6),
            'name' => 'live map fixture',
            'status' => $status,
            'driver_vehicle_assignment_id' => $pairingId,
            'driver_accepted_products' => $fullCustody,
            'driver_accepted_custody' => $fullCustody,
            'driver_accepted_equipment' => $fullCustody,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['trip_id' => $tripId, 'trip_uuid' => $tripUuid, 'driver_id' => $driverId, 'user' => $user];
    }

    private function ping(int $tripId, int $driverId, string $companyId, float $lat, float $lng, \DateTimeInterface $recordedAt): void
    {
        DB::table('distribution_driver_location_pings')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $companyId,
            'driver_id' => $driverId,
            'trip_id' => $tripId,
            'latitude' => $lat,
            'longitude' => $lng,
            'recorded_at' => $recordedAt,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── §4/§8 — the trackable set itself ────────────────────────────────────────

    public function test_a_fully_trackable_trip_appears_on_the_live_map(): void
    {
        $company = Company::factory()->create();
        $fixture = $this->tripFixture($company, 'out_for_delivery', fullCustody: true, tripNumber: 'TRP-VISIBLE');
        $this->ping($fixture['trip_id'], $fixture['driver_id'], $company->id, 30.05, 31.23, now());

        $response = $this->actingAs($fixture['user'])->getJson(self::LIVE_MAP)->assertOk();

        $numbers = array_column($response->json('data'), 'trip_number');
        self::assertContains('TRP-VISIBLE', $numbers);
    }

    public function test_a_trip_with_partial_custody_acceptance_never_shows_as_live(): void
    {
        $company = Company::factory()->create();
        // On the road by status, but the driver has not confirmed all three custody
        // items — driver assignment alone must not be enough (§3).
        $user = User::factory()->create(['company_id' => $company->id]);
        $fixture = $this->tripFixture($company, 'out_for_delivery', fullCustody: false, tripNumber: 'TRP-PARTIAL');

        $response = $this->actingAs($user)->getJson(self::LIVE_MAP)->assertOk();

        self::assertNotContains('TRP-PARTIAL', array_column($response->json('data'), 'trip_number'));
    }

    public function test_a_fully_accepted_trip_still_in_planning_never_shows_as_live(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        // Full custody but not yet on the road — the OTHER half of the boundary (§3).
        $fixture = $this->tripFixture($company, 'planning', fullCustody: true, tripNumber: 'TRP-PLANNING');

        $response = $this->actingAs($user)->getJson(self::LIVE_MAP)->assertOk();

        self::assertNotContains('TRP-PLANNING', array_column($response->json('data'), 'trip_number'));
    }

    public function test_a_closed_trip_never_shows_as_live_even_with_full_custody_history(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $fixture = $this->tripFixture($company, 'closed', fullCustody: true, tripNumber: 'TRP-CLOSED');

        $response = $this->actingAs($user)->getJson(self::LIVE_MAP)->assertOk();

        self::assertNotContains('TRP-CLOSED', array_column($response->json('data'), 'trip_number'));
    }

    // ── §22 — tenant isolation ───────────────────────────────────────────────────

    public function test_one_companys_live_map_never_exposes_another_companys_trips(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $this->tripFixture($companyA, 'in_progress', fullCustody: true, tripNumber: 'TRP-A');
        $userB = User::factory()->create(['company_id' => $companyB->id]);
        $this->tripFixture($companyB, 'in_progress', fullCustody: true, tripNumber: 'TRP-B');

        $response = $this->actingAs($userB)->getJson(self::LIVE_MAP)->assertOk();

        $numbers = array_column($response->json('data'), 'trip_number');
        self::assertContains('TRP-B', $numbers);
        self::assertNotContains('TRP-A', $numbers);
    }

    // ── §6 — freshness, latest-location selection ───────────────────────────────

    public function test_only_the_most_recent_location_ping_is_returned(): void
    {
        $company = Company::factory()->create();
        $fixture = $this->tripFixture($company, 'in_progress', fullCustody: true);

        $this->ping($fixture['trip_id'], $fixture['driver_id'], $company->id, 30.0, 31.0, now()->subMinutes(10));
        $this->ping($fixture['trip_id'], $fixture['driver_id'], $company->id, 30.5, 31.5, now()->subMinute());

        $response = $this->actingAs($fixture['user'])->getJson(self::LIVE_MAP)->assertOk();

        $row = collect($response->json('data'))->firstWhere('trip_id', $fixture['trip_uuid']);
        self::assertEqualsWithDelta(30.5, $row['location']['lat'], 0.0001);
        self::assertEqualsWithDelta(31.5, $row['location']['lng'], 0.0001);
    }

    public function test_a_stale_location_is_labelled_stale_never_silently_shown_as_live(): void
    {
        $company = Company::factory()->create();
        config()->set('distribution.tracking.stale_after_seconds', 180);
        $fixture = $this->tripFixture($company, 'in_progress', fullCustody: true);

        $this->ping($fixture['trip_id'], $fixture['driver_id'], $company->id, 30.0, 31.0, now()->subMinutes(30));

        $response = $this->actingAs($fixture['user'])->getJson(self::LIVE_MAP)->assertOk();

        $row = collect($response->json('data'))->firstWhere('trip_id', $fixture['trip_uuid']);
        self::assertSame('stale', $row['location']['freshness']);
    }

    public function test_a_trackable_trip_with_no_recorded_sample_yet_reports_null_location_not_a_guess(): void
    {
        $company = Company::factory()->create();
        $fixture = $this->tripFixture($company, 'dispatched', fullCustody: true);

        $response = $this->actingAs($fixture['user'])->getJson(self::LIVE_MAP)->assertOk();

        $row = collect($response->json('data'))->firstWhere('trip_id', $fixture['trip_uuid']);
        self::assertNull($row['location']);
    }

    // ── §8 — stop progress ───────────────────────────────────────────────────────

    public function test_stop_progress_counts_completed_as_left_the_two_unsettled_statuses(): void
    {
        $company = Company::factory()->create();
        $fixture = $this->tripFixture($company, 'in_progress', fullCustody: true);

        foreach (['delivered', 'failed', 'pending', 'in_progress'] as $i => $status) {
            DB::table('distribution_delivery_stops')->insert([
                'uuid' => (string) Str::uuid(),
                'trip_id' => $fixture['trip_id'],
                'sequence' => $i + 1,
                'status' => $status,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $response = $this->actingAs($fixture['user'])->getJson(self::LIVE_MAP)->assertOk();

        $row = collect($response->json('data'))->firstWhere('trip_id', $fixture['trip_uuid']);
        self::assertSame(4, $row['stops_total']);
        self::assertSame(2, $row['stops_completed']); // delivered + failed
    }

    // ── location-history: ordering, tenant scope, bounded read ─────────────────

    public function test_route_history_samples_are_returned_in_chronological_order(): void
    {
        $company = Company::factory()->create();
        $fixture = $this->tripFixture($company, 'completed', fullCustody: true);

        $this->ping($fixture['trip_id'], $fixture['driver_id'], $company->id, 30.3, 31.3, now()->subMinutes(5));
        $this->ping($fixture['trip_id'], $fixture['driver_id'], $company->id, 30.1, 31.1, now()->subMinutes(15));
        $this->ping($fixture['trip_id'], $fixture['driver_id'], $company->id, 30.2, 31.2, now()->subMinutes(10));

        $response = $this->actingAs($fixture['user'])
            ->getJson("/api/logistics/distribution/trips/{$fixture['trip_uuid']}/location-history")
            ->assertOk();

        $lats = array_column($response->json('samples'), 'lat');
        self::assertSame([30.1, 30.2, 30.3], array_map(fn ($v) => round((float) $v, 1), $lats));
    }

    public function test_route_history_remains_readable_after_the_trip_is_closed(): void
    {
        $company = Company::factory()->create();
        $fixture = $this->tripFixture($company, 'closed', fullCustody: true);
        $this->ping($fixture['trip_id'], $fixture['driver_id'], $company->id, 30.0, 31.0, now()->subHour());

        $this->actingAs($fixture['user'])
            ->getJson("/api/logistics/distribution/trips/{$fixture['trip_uuid']}/location-history")
            ->assertOk()
            ->assertJsonCount(1, 'samples');
    }

    public function test_route_history_for_another_companys_trip_is_denied_not_found(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $fixtureA = $this->tripFixture($companyA, 'completed', fullCustody: true);
        $userB = User::factory()->create(['company_id' => $companyB->id]);

        $this->actingAs($userB)
            ->getJson("/api/logistics/distribution/trips/{$fixtureA['trip_uuid']}/location-history")
            ->assertNotFound();
    }

    public function test_route_history_sample_limit_is_bounded_and_reports_truncation(): void
    {
        $company = Company::factory()->create();
        $fixture = $this->tripFixture($company, 'completed', fullCustody: true);

        for ($i = 0; $i < 5; $i++) {
            $this->ping($fixture['trip_id'], $fixture['driver_id'], $company->id, 30.0 + $i * 0.01, 31.0, now()->subMinutes(5 - $i));
        }

        $response = $this->actingAs($fixture['user'])
            ->getJson("/api/logistics/distribution/trips/{$fixture['trip_uuid']}/location-history?limit=2")
            ->assertOk();

        self::assertCount(2, $response->json('samples'));
        self::assertTrue($response->json('samples_truncated'));
    }
}

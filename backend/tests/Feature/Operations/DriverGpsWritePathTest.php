<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-SHIPPING-OS-REDESIGN-004 §3/§14/§15/§16 —
 * DriverRuntimeController::gps(), the one write path for
 * distribution_driver_location_pings.
 *
 * Written and PHPStan-validated but not executed (test DB unreachable across
 * Tasks 001-004) — see LiveDriverMapTest's own header for the same note.
 */
final class DriverGpsWritePathTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{trip_id: int, trip_uuid: string, driver_id: int, user: User} */
    private function tripFixture(Company $company, string $status, bool $fullCustody): array
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
            'name' => 'gps write-path fixture',
            'status' => $status,
            'driver_vehicle_assignment_id' => $pairingId,
            'driver_accepted_products' => $fullCustody,
            'driver_accepted_custody' => $fullCustody,
            'driver_accepted_equipment' => $fullCustody,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['trip_id' => $tripId, 'trip_uuid' => $tripUuid, 'driver_id' => $driverId, 'user' => $user];
    }

    private function gps(User $user, string $tripUuid, array $payload)
    {
        return $this->actingAs($user)->postJson("/api/driver/trips/{$tripUuid}/gps", $payload);
    }

    // ── §3 — the trackability boundary itself ───────────────────────────────────

    public function test_a_sample_is_persisted_while_the_trip_is_trackable(): void
    {
        $company = Company::factory()->create();
        $fixture = $this->tripFixture($company, 'out_for_delivery', fullCustody: true);

        $this->gps($fixture['user'], $fixture['trip_uuid'], ['lat' => 30.05, 'lng' => 31.23])
            ->assertNoContent();

        self::assertDatabaseHas('distribution_driver_location_pings', [
            'trip_id' => $fixture['trip_id'],
            'driver_id' => $fixture['driver_id'],
        ]);
    }

    public function test_a_sample_is_validated_but_discarded_when_custody_is_only_partially_accepted(): void
    {
        $company = Company::factory()->create();
        $fixture = $this->tripFixture($company, 'out_for_delivery', fullCustody: false);

        $this->gps($fixture['user'], $fixture['trip_uuid'], ['lat' => 30.05, 'lng' => 31.23])
            ->assertNoContent(); // same 204 contract as before this task — no behaviour change visible to the client

        self::assertDatabaseCount('distribution_driver_location_pings', 0);
    }

    public function test_a_sample_is_discarded_once_the_trip_is_closed_even_with_full_custody_history(): void
    {
        $company = Company::factory()->create();
        $fixture = $this->tripFixture($company, 'closed', fullCustody: true);

        $this->gps($fixture['user'], $fixture['trip_uuid'], ['lat' => 30.05, 'lng' => 31.23])
            ->assertNoContent();

        self::assertDatabaseCount('distribution_driver_location_pings', 0);
    }

    // ── §15 — validation, identity binding ──────────────────────────────────────

    public function test_an_out_of_range_latitude_is_rejected(): void
    {
        $company = Company::factory()->create();
        $fixture = $this->tripFixture($company, 'out_for_delivery', fullCustody: true);

        $this->gps($fixture['user'], $fixture['trip_uuid'], ['lat' => 95.0, 'lng' => 31.23])
            ->assertStatus(422);

        self::assertDatabaseCount('distribution_driver_location_pings', 0);
    }

    public function test_an_out_of_range_longitude_is_rejected(): void
    {
        $company = Company::factory()->create();
        $fixture = $this->tripFixture($company, 'out_for_delivery', fullCustody: true);

        $this->gps($fixture['user'], $fixture['trip_uuid'], ['lat' => 30.0, 'lng' => 185.0])
            ->assertStatus(422);

        self::assertDatabaseCount('distribution_driver_location_pings', 0);
    }

    public function test_a_driver_cannot_report_gps_for_a_trip_they_do_not_own(): void
    {
        $company = Company::factory()->create();
        $owned = $this->tripFixture($company, 'out_for_delivery', fullCustody: true);
        $other = $this->tripFixture($company, 'out_for_delivery', fullCustody: true);

        // $other['user'] is a DIFFERENT driver's identity; posting against $owned's trip
        // (belonging to a different driver) must fail ownership resolution.
        $this->gps($other['user'], $owned['trip_uuid'], ['lat' => 30.0, 'lng' => 31.0])
            ->assertNotFound();

        self::assertDatabaseCount('distribution_driver_location_pings', 0);
    }

    public function test_a_persisted_sample_is_tied_to_the_authenticated_drivers_own_identity(): void
    {
        $company = Company::factory()->create();
        $fixture = $this->tripFixture($company, 'out_for_delivery', fullCustody: true);

        // The endpoint accepts no client-supplied driver id at all — this proves the
        // STORED row is the authenticated driver's, not merely that the request lacks
        // a spoofable field.
        $this->gps($fixture['user'], $fixture['trip_uuid'], ['lat' => 30.0, 'lng' => 31.0])
            ->assertNoContent();

        self::assertDatabaseHas('distribution_driver_location_pings', [
            'trip_id' => $fixture['trip_id'],
            'driver_id' => $fixture['driver_id'],
        ]);
    }

    // ── §16 — sampling / dedup ───────────────────────────────────────────────────

    public function test_two_reports_inside_the_minimum_interval_produce_only_one_stored_sample(): void
    {
        config()->set('distribution.tracking.min_sample_interval_seconds', 20);
        $company = Company::factory()->create();
        $fixture = $this->tripFixture($company, 'out_for_delivery', fullCustody: true);

        $this->gps($fixture['user'], $fixture['trip_uuid'], ['lat' => 30.0, 'lng' => 31.0])->assertNoContent();
        $this->gps($fixture['user'], $fixture['trip_uuid'], ['lat' => 30.001, 'lng' => 31.001])->assertNoContent();

        self::assertDatabaseCount('distribution_driver_location_pings', 1);
    }

    public function test_a_report_after_the_minimum_interval_has_elapsed_is_stored_as_a_new_sample(): void
    {
        config()->set('distribution.tracking.min_sample_interval_seconds', 20);
        $company = Company::factory()->create();
        $fixture = $this->tripFixture($company, 'out_for_delivery', fullCustody: true);

        DB::table('distribution_driver_location_pings')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $company->id,
            'driver_id' => $fixture['driver_id'],
            'trip_id' => $fixture['trip_id'],
            'latitude' => 30.0,
            'longitude' => 31.0,
            'recorded_at' => now()->subSeconds(30),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->gps($fixture['user'], $fixture['trip_uuid'], ['lat' => 30.01, 'lng' => 31.01])
            ->assertNoContent();

        self::assertDatabaseCount('distribution_driver_location_pings', 2);
    }
}

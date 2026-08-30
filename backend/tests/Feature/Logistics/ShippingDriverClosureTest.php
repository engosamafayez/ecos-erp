<?php

declare(strict_types=1);

namespace Tests\Feature\Logistics;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Modules\Logistics\Drivers\Domain\Models\Driver;
use Modules\Logistics\ShippingCompanies\Domain\Models\ShippingCompany;
use Modules\Logistics\ShippingCompanies\Domain\Models\ShippingCompanyMapping;
use Modules\Logistics\Vehicles\Domain\Models\Vehicle;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-SHIPPING-DRIVER-CLOSURE-001 — Part 18 focused tests.
 *
 * Covers the security- and contract-critical gaps closed this pass:
 *   G2  vehicle tenant isolation (by-id IDOR) + company_id stamping + carrier mapping
 *   G7  company timezone must be a real IANA identifier
 *   G8  the Loading OS permission rows now exist (were defined nowhere)
 *   G10 driver runtime identity bridge + fail-closed guard + frozen financial endpoints
 *
 * DatabaseTransactions (not RefreshDatabase) so the seeded roles/permissions are present,
 * matching the sibling VehicleModuleTest / DriverModuleTest suites.
 */
final class ShippingDriverClosureTest extends TestCase
{
    use DatabaseTransactions;

    private const VEHICLES = '/api/logistics/vehicles';

    /** @param array<string,mixed> $o */
    private function vehiclePayload(array $o = []): array
    {
        return array_merge([
            'vehicle_code' => 'VEH-'.uniqid(),
            'plate_number' => 'PL-'.uniqid(),
            'type' => 'van',
            'capacity_orders' => 40,
        ], $o);
    }

    private function makeShippingCompany(): ShippingCompany
    {
        return ShippingCompany::create([
            'name' => 'Carrier '.uniqid(),
            'code' => 'SHC-'.strtoupper(substr(uniqid(), -6)),
            'type' => ShippingCompany::TYPE_EXTERNAL,
            'status' => ShippingCompany::STATUS_ACTIVE,
        ]);
    }

    // ── G2 — vehicle tenant isolation ────────────────────────────────────────

    public function test_vehicle_by_id_is_not_readable_across_companies(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $userA = User::factory()->create(['company_id' => $companyA->id]);

        // A vehicle OWNED by company B (explicit company_id is preserved by the model hook).
        $vehicleB = Vehicle::create($this->vehiclePayload(['company_id' => $companyB->id]));

        // The company-A operator must not reach it — fail closed (404, not the row).
        $this->actingAs($userA)->getJson(self::VEHICLES.'/'.$vehicleB->id)->assertNotFound();
    }

    public function test_vehicle_create_stamps_the_actor_company(): void
    {
        $companyA = Company::factory()->create();
        $userA = User::factory()->create(['company_id' => $companyA->id]);

        $id = $this->actingAs($userA)->postJson(self::VEHICLES, $this->vehiclePayload())
            ->assertCreated()
            ->json('data.id');

        // company_id is never client-settable; it is stamped from the creating operator.
        $this->assertDatabaseHas('logistics_vehicles', ['id' => $id, 'company_id' => $companyA->id]);
    }

    public function test_carrier_assignment_is_tenant_scoped_fail_closed(): void
    {
        $companyA = Company::factory()->create();
        $userA = User::factory()->create(['company_id' => $companyA->id]);
        $carrier = $this->makeShippingCompany();

        // No mapping between the carrier and company A yet → assignment is refused.
        $this->actingAs($userA)
            ->postJson(self::VEHICLES, $this->vehiclePayload(['shipping_company_id' => $carrier->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('shipping_company_id');

        // Once the carrier is mapped to the tenant, the same assignment is accepted.
        ShippingCompanyMapping::create([
            'shipping_company_id' => $carrier->id,
            'company_id' => $companyA->id,
        ]);

        $this->actingAs($userA)
            ->postJson(self::VEHICLES, $this->vehiclePayload(['shipping_company_id' => $carrier->id]))
            ->assertCreated()
            ->assertJsonPath('data.shipping_company_id', $carrier->id);
    }

    // ── G1 — loading over-quantity guard ────────────────────────────────────

    public function test_loading_refuses_over_load_at_validation(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        // quantity_loaded > quantity_planned is refused before the action even runs
        // (the request-level lte guard; the action itself is the authoritative twin).
        $this->actingAs($user)->postJson(
            '/api/loading/sessions/'.Str::uuid().'/assignments/'.Str::uuid().'/load-product',
            [
                'pool_entry_id' => (string) Str::uuid(),
                'product_id' => (string) Str::uuid(),
                'sku_snapshot' => 'SKU-1',
                'name_snapshot' => 'Widget',
                'preparation_wave_id' => (string) Str::uuid(),
                'quantity_planned' => 5,
                'quantity_loaded' => 10,
            ],
        )->assertStatus(422)->assertJsonValidationErrors('quantity_loaded');
    }

    // ── G7 — timezone must be a real IANA identifier ─────────────────────────

    public function test_company_rejects_a_non_iana_timezone(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        $this->actingAs($user)->putJson('/api/companies/'.$company->id, [
            'name' => $company->name,
            'timezone' => 'Mars/Phobos',
        ])->assertStatus(422)->assertJsonValidationErrors('timezone');
    }

    public function test_company_accepts_a_valid_iana_timezone(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        $this->actingAs($user)->putJson('/api/companies/'.$company->id, [
            'name' => $company->name,
            'timezone' => 'Africa/Cairo',
        ])->assertOk();

        $this->assertDatabaseHas('companies', ['id' => $company->id, 'timezone' => 'Africa/Cairo']);
    }

    // ── G8 — Loading OS permission rows exist ────────────────────────────────

    public function test_loading_os_permissions_are_registered(): void
    {
        foreach ([
            'loading.session.view', 'loading.session.create', 'loading.session.operate',
            'loading.session.cancel', 'loading.session.dispatch',
            'loading.vehicle.assign',
            'loading.allocation.view', 'loading.allocation.manage', 'loading.allocation.override',
            'loading.driver.operate',
        ] as $name) {
            $this->assertDatabaseHas('permissions', ['name' => $name]);
        }
    }

    // ── G10 — driver runtime identity + fail-closed guard ────────────────────

    public function test_driver_runtime_refuses_a_non_driver_user(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        // Authenticated, but not linked to any driver row → 403, fail closed.
        $this->actingAs($user)->getJson('/api/driver/trips')->assertForbidden();
    }

    public function test_driver_runtime_resolves_the_logged_in_driver(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        Driver::create([
            'driver_code' => 'DRV-'.strtoupper(substr(uniqid(), -6)),
            'user_id' => $user->id,
            'full_name' => 'Runtime Driver',
            'mobile' => '0100'.random_int(1000000, 9999999),
            'national_id' => 'NID-'.strtoupper(substr(uniqid(), -8)),
        ]);

        // Identity bridge resolves; the driver has no trips yet → empty list, not an error.
        $this->actingAs($user)->getJson('/api/driver/trips')
            ->assertOk()
            ->assertExactJson([]);
    }

    public function test_driver_runtime_freezes_financial_settlement_endpoints(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        Driver::create([
            'driver_code' => 'DRV-'.strtoupper(substr(uniqid(), -6)),
            'user_id' => $user->id,
            'full_name' => 'Frozen Driver',
            'mobile' => '0100'.random_int(1000000, 9999999),
            'national_id' => 'NID-'.strtoupper(substr(uniqid(), -8)),
        ]);

        // Financial Settlement is frozen (Section 17): the endpoint is reachable but refused.
        $this->actingAs($user)
            ->getJson('/api/driver/trips/'.Str::uuid().'/settlement')
            ->assertForbidden()
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'frozen'));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Logistics;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-LOGISTICS-TEMPLATE-DRIVER-RECOMMENDATIONS-AND-VEHICLE-CREATION-FIX-001 — Issue B.
 *
 * A Vehicle may only be assigned a Shipping Company MAPPED to the operator's company
 * (`logistics_shipping_company_mappings`). The bug was not in that fail-closed rule —
 * it was that the carrier dropdown offered UNMAPPED carriers, which then failed
 * validation with "The selected shipping company id is invalid." These tests pin both
 * halves: the validation still rejects an unmapped carrier, and the `assignable_only`
 * list surface now returns exactly the carriers that will pass.
 */
final class VehicleShippingCompanyTenancyTest extends TestCase
{
    use RefreshDatabase;

    private const VEHICLES = '/api/logistics/vehicles';

    private const CARRIERS = '/api/logistics/shipping-companies';

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->user = User::factory()->create(['company_id' => $this->company->id]);
    }

    public function test_a_vehicle_is_created_with_a_mapped_shipping_company(): void
    {
        $carrier = $this->carrier('SHC-MAP', 'Mapped Carrier');
        $this->map($carrier, $this->company->id);

        $id = $this->actingAs($this->user)
            ->postJson(self::VEHICLES, $this->payload(['shipping_company_id' => $carrier]))
            ->assertStatus(201)
            ->json('data.id');

        // The relation persisted and reloads correctly.
        $reloaded = $this->actingAs($this->user)
            ->getJson(self::VEHICLES.'/'.$id)
            ->assertOk()
            ->json('data');

        self::assertSame($carrier, $reloaded['shipping_company_id']);
        self::assertSame('Mapped Carrier', $reloaded['shipping_company_name']);
    }

    /** The exact reported bug: an active-but-unmapped carrier is refused, not bound. */
    public function test_an_unmapped_shipping_company_is_rejected(): void
    {
        $carrier = $this->carrier('SHC-UNMAPPED', 'osamafayez');
        // No mapping row for $this->company.

        $this->actingAs($this->user)
            ->postJson(self::VEHICLES, $this->payload(['shipping_company_id' => $carrier]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('shipping_company_id');

        self::assertSame(0, DB::table('logistics_vehicles')->count());
    }

    public function test_a_foreign_companys_mapping_does_not_make_a_carrier_valid(): void
    {
        $other = Company::factory()->create();
        $carrier = $this->carrier('SHC-FOREIGN', 'Foreign');
        $this->map($carrier, $other->id); // mapped, but to another tenant

        $this->actingAs($this->user)
            ->postJson(self::VEHICLES, $this->payload(['shipping_company_id' => $carrier]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('shipping_company_id');
    }

    public function test_assignable_only_returns_only_mapped_carriers(): void
    {
        $mapped = $this->carrier('SHC-A', 'Mapped');
        $this->map($mapped, $this->company->id);
        $this->carrier('SHC-B', 'Unmapped');

        $codes = collect(
            $this->actingAs($this->user)
                ->getJson(self::CARRIERS.'?assignable_only=1&status=active&per_page=100')
                ->assertOk()
                ->json('data'),
        )->pluck('code')->all();

        // The unmapped carrier — exactly the one whose selection would 422 — is hidden,
        // so the dropdown and the validation now agree.
        self::assertContains('SHC-A', $codes);
        self::assertNotContains('SHC-B', $codes);
    }

    public function test_the_default_list_is_unaffected_and_still_shows_all_active(): void
    {
        $mapped = $this->carrier('SHC-A', 'Mapped');
        $this->map($mapped, $this->company->id);
        $this->carrier('SHC-B', 'Unmapped');

        // The management screen omits the flag and must still see every active carrier.
        $codes = collect(
            $this->actingAs($this->user)
                ->getJson(self::CARRIERS.'?status=active&per_page=100')
                ->assertOk()
                ->json('data'),
        )->pluck('code')->all();

        self::assertContains('SHC-A', $codes);
        self::assertContains('SHC-B', $codes);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'vehicle_code' => 'VEH-001',
            'plate_number' => 'ABC-1234',
            'type' => 'van',
            'capacity_orders' => 60,
        ], $overrides);
    }

    private function carrier(string $code, string $name): int
    {
        return (int) DB::table('logistics_shipping_companies')->insertGetId([
            'name' => $name,
            'code' => $code,
            'type' => 'internal',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function map(int $carrierId, string $companyId): void
    {
        DB::table('logistics_shipping_company_mappings')->insert([
            'shipping_company_id' => $carrierId,
            'company_id' => $companyId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

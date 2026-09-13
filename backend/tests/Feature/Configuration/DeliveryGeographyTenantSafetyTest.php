<?php

declare(strict_types=1);

namespace Tests\Feature\Configuration;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Modules\Admin\Configuration\Domain\Models\DeliveryGeography;
use Modules\Admin\Configuration\Domain\Models\DeliveryZone;
use Modules\Admin\Configuration\Domain\Models\MasterGovernorate;
use Modules\Admin\Configuration\Domain\Models\MasterZone;
use Modules\Organization\Brands\Domain\Models\Brand;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-V1.1-OPS-02-CLOSURE — Delivery Geography/Zone routes were newly
 * registered (the controllers already existed with zero routes anywhere; the
 * frontend already called them and 404'd every time). This suite proves the
 * routes work AND that hardening them did not just trust brand_id/geo_id: a
 * UUID from another company is refused, never leaked or silently adopted.
 */
final class DeliveryGeographyTenantSafetyTest extends TestCase
{
    use DatabaseTransactions;

    private Company $company;

    private Brand $brand;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->brand = Brand::factory()->create(['company_id' => $this->company->id]);
        $this->user = User::factory()->create(['company_id' => $this->company->id]);
    }

    private function auth(): static
    {
        return $this->actingAs($this->user);
    }

    // ── Happy path ────────────────────────────────────────────────────────────

    public function test_create_list_update_and_delete_a_geography_for_own_brand(): void
    {
        $create = $this->auth()->postJson("/api/configuration/brands/{$this->brand->id}/geographies", [
            'name' => 'Cairo',
        ])->assertStatus(201);

        $geoId = $create->json('data.id');
        self::assertNotNull($geoId);

        $this->auth()->getJson("/api/configuration/brands/{$this->brand->id}/geographies")
            ->assertOk()
            ->assertJsonPath('data.0.id', $geoId);

        $this->auth()->putJson("/api/configuration/brands/{$this->brand->id}/geographies/{$geoId}", [
            'name' => 'Cairo Governorate',
        ])->assertOk()->assertJsonPath('data.name', 'Cairo Governorate');

        $this->auth()->deleteJson("/api/configuration/brands/{$this->brand->id}/geographies/{$geoId}")
            ->assertOk();

        self::assertNull(DeliveryGeography::find($geoId));
    }

    public function test_create_list_update_and_delete_a_zone_for_own_brand_and_geography(): void
    {
        $geography = DeliveryGeography::create([
            'brand_id' => $this->brand->id,
            'company_id' => $this->company->id,
            'name' => 'Giza',
        ]);

        $create = $this->auth()->postJson(
            "/api/configuration/brands/{$this->brand->id}/geographies/{$geography->id}/zones",
            ['name' => 'Dokki'],
        )->assertStatus(201);

        $zoneId = $create->json('data.id');
        self::assertNotNull($zoneId);

        $this->auth()->getJson("/api/configuration/brands/{$this->brand->id}/geographies/{$geography->id}/zones")
            ->assertOk()
            ->assertJsonPath('data.0.id', $zoneId);

        $this->auth()->putJson(
            "/api/configuration/brands/{$this->brand->id}/geographies/{$geography->id}/zones/{$zoneId}",
            ['name' => 'Dokki Updated'],
        )->assertOk()->assertJsonPath('data.name', 'Dokki Updated');

        $this->auth()->deleteJson(
            "/api/configuration/brands/{$this->brand->id}/geographies/{$geography->id}/zones/{$zoneId}",
        )->assertOk();

        self::assertNull(DeliveryZone::find($zoneId));
    }

    public function test_valid_master_governorate_reference_auto_creates_its_master_zones(): void
    {
        $governorate = MasterGovernorate::create(['name' => 'Alexandria', 'code' => 'ALX', 'sort_order' => 1]);
        MasterZone::create(['master_governorate_id' => $governorate->id, 'name' => 'Smouha', 'code' => 'SMH', 'sort_order' => 1]);
        MasterZone::create(['master_governorate_id' => $governorate->id, 'name' => 'Miami', 'code' => 'MIA', 'sort_order' => 2]);

        $create = $this->auth()->postJson("/api/configuration/brands/{$this->brand->id}/geographies", [
            'name' => 'Alexandria',
            'master_governorate_id' => $governorate->id,
        ])->assertStatus(201);

        self::assertCount(2, $create->json('data.zones'), 'both master zones must be auto-created — global reference data, no company boundary to enforce.');
    }

    // ── Foreign-company denial ───────────────────────────────────────────────

    public function test_a_foreign_companys_brand_id_is_refused_on_every_verb(): void
    {
        $otherBrand = Brand::factory()->create(['company_id' => Company::factory()->create()->id]);

        $this->auth()->getJson("/api/configuration/brands/{$otherBrand->id}/geographies")
            ->assertNotFound();

        $this->auth()->postJson("/api/configuration/brands/{$otherBrand->id}/geographies", ['name' => 'Hack'])
            ->assertNotFound();

        $foreignGeography = DeliveryGeography::create([
            'brand_id' => $otherBrand->id,
            'company_id' => $otherBrand->company_id,
            'name' => 'Foreign Geo',
        ]);

        $this->auth()->putJson(
            "/api/configuration/brands/{$otherBrand->id}/geographies/{$foreignGeography->id}",
            ['name' => 'Renamed'],
        )->assertNotFound();

        $this->auth()->deleteJson("/api/configuration/brands/{$otherBrand->id}/geographies/{$foreignGeography->id}")
            ->assertNotFound();

        self::assertNotNull(DeliveryGeography::find($foreignGeography->id), 'the foreign row must be untouched.');
    }

    public function test_a_foreign_companys_geography_id_is_refused_even_under_ones_own_brand(): void
    {
        // Attacker's OWN brand is legitimate, but they supply another company's
        // geography id, hoping brand_id alone gates the lookup.
        $otherCompany = Company::factory()->create();
        $otherBrand = Brand::factory()->create(['company_id' => $otherCompany->id]);
        $foreignGeography = DeliveryGeography::create([
            'brand_id' => $otherBrand->id,
            'company_id' => $otherCompany->id,
            'name' => 'Foreign Geo Under Own Brand',
        ]);

        $this->auth()->getJson("/api/configuration/brands/{$this->brand->id}/geographies/{$foreignGeography->id}/zones")
            ->assertNotFound();

        $this->auth()->postJson(
            "/api/configuration/brands/{$this->brand->id}/geographies/{$foreignGeography->id}/zones",
            ['name' => 'Hack Zone'],
        )->assertNotFound();

        self::assertSame(0, DeliveryZone::where('delivery_geography_id', $foreignGeography->id)->count());
    }

    public function test_a_foreign_companys_zone_id_is_refused_even_under_ones_own_brand_and_geography(): void
    {
        $ownGeography = DeliveryGeography::create([
            'brand_id' => $this->brand->id,
            'company_id' => $this->company->id,
            'name' => 'Own Geo',
        ]);

        $otherCompany = Company::factory()->create();
        $otherBrand = Brand::factory()->create(['company_id' => $otherCompany->id]);
        $otherGeography = DeliveryGeography::create([
            'brand_id' => $otherBrand->id,
            'company_id' => $otherCompany->id,
            'name' => 'Other Geo',
        ]);
        $foreignZone = DeliveryZone::create([
            'brand_id' => $otherBrand->id,
            'delivery_geography_id' => $otherGeography->id,
            'name' => 'Foreign Zone',
        ]);

        // Attacker supplies their OWN brand + OWN geography in the URL, but the
        // foreign zone id — must still be refused, not matched by id alone.
        $this->auth()->putJson(
            "/api/configuration/brands/{$this->brand->id}/geographies/{$ownGeography->id}/zones/{$foreignZone->id}",
            ['name' => 'Renamed'],
        )->assertNotFound();

        $this->auth()->deleteJson(
            "/api/configuration/brands/{$this->brand->id}/geographies/{$ownGeography->id}/zones/{$foreignZone->id}",
        )->assertNotFound();

        self::assertNotNull(DeliveryZone::find($foreignZone->id), 'the foreign zone must be untouched.');
    }

    public function test_a_nonexistent_master_governorate_reference_is_rejected(): void
    {
        $this->auth()->postJson("/api/configuration/brands/{$this->brand->id}/geographies", [
            'name' => 'Bad Ref',
            'master_governorate_id' => (string) Str::uuid(),
        ])->assertStatus(422);
    }
}

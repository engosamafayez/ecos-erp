<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Configuration\Domain\Models\DeliveryGeography;
use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Logistics\Geography\Domain\Models\Governorate;
use Modules\Organization\Brands\Domain\Models\Brand;
use Modules\Organization\Brands\Domain\Models\BrandDeliveryTimeSlot;
use Modules\Organization\Brands\Domain\Models\BrandGovernorateSettings;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * CD-01 (TASK-ECOS-COMMERCE-PRE-USER-REVIEW-REMEDIATION-002 §2) — New Order readiness.
 *
 * The readiness gate asserted three tables the New Order form does not read
 * (`config_delivery_geographies`, `config_delivery_zones`, `config_brand_shipping_rules`),
 * so a brand configured on the live path — the brand shipping engine's
 * `brand_governorate_settings`, which is what the form's governorate combo is built from —
 * was still reported not-ready.
 *
 * These cases pin the reconciled contract in both directions: readiness is satisfied by the
 * canonical authority with ZERO legacy rows present, and each genuinely-required condition
 * still blocks on its own.
 */
final class BrandConfigurationReadinessTest extends TestCase
{
    use RefreshDatabase;

    private function brandWith(
        bool $activeChannel,
        bool $enabledGovernorate,
        bool $activeWindow,
        bool $legacyGeography = false,
    ): Brand {
        $company = Company::factory()->create();
        $brand = Brand::factory()->create(['company_id' => $company->id]);

        if ($activeChannel) {
            Channel::factory()->create(['brand_id' => $brand->id, 'is_active' => true]);
        }

        if ($enabledGovernorate) {
            BrandGovernorateSettings::create([
                'brand_id' => $brand->id,
                'governorate_id' => $this->governorateId(),
                'is_enabled' => true,
            ]);
        }

        if ($activeWindow) {
            BrandDeliveryTimeSlot::create([
                'brand_id' => $brand->id,
                'name' => 'Morning',
                'start_time' => '09:00',
                'end_time' => '12:00',
                'is_active' => true,
            ]);
        }

        if ($legacyGeography) {
            DeliveryGeography::create([
                'brand_id' => $brand->id,
                'company_id' => $company->id,
                'name' => 'Legacy Governorate',
                'is_active' => true,
            ]);
        }

        return $brand;
    }

    /** A real reference governorate — the settings table has an FK onto logistics_governorates. */
    private function governorateId(): int
    {
        $existing = Governorate::query()->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        return (int) Governorate::query()->insertGetId([
            'name_en' => 'Test Governorate',
            'name_ar' => 'محافظة اختبار',
            'is_active' => true,
        ]);
    }

    private function health(Brand $brand): array
    {
        $this->actingAs(User::factory()->create(['company_id' => $brand->company_id]));

        $response = $this->getJson("/api/brands/{$brand->id}/configuration-health");
        $response->assertOk();

        return $response->json('data');
    }

    /**
     * The CD-01 regression. Canonical configuration present, and NOT ONE row in any of the
     * three legacy tables the old gate asserted — readiness must still be satisfied.
     */
    public function test_readiness_passes_on_canonical_brand_governorate_settings_with_no_legacy_rows(): void
    {
        $brand = $this->brandWith(activeChannel: true, enabledGovernorate: true, activeWindow: true);

        self::assertSame(0, DeliveryGeography::query()->count());
        self::assertSame(0, \Modules\Admin\Configuration\Domain\Models\DeliveryZone::query()->count());
        self::assertSame(0, \Modules\Admin\Configuration\Domain\Models\BrandShippingRule::query()->count());

        $data = $this->health($brand);

        self::assertTrue($data['is_ready'], 'A brand configured on the live path must be order-ready.');
        self::assertTrue($data['checks']['channels']);
        self::assertTrue($data['checks']['delivery_geography']);
        self::assertTrue($data['checks']['delivery_windows']);
    }

    /** The retired legacy checks must not reappear as permanently-failing report keys. */
    public function test_retired_legacy_checks_are_absent_from_the_report(): void
    {
        $brand = $this->brandWith(activeChannel: true, enabledGovernorate: true, activeWindow: true);

        $checks = $this->health($brand)['checks'];

        self::assertArrayNotHasKey('delivery_zones', $checks);
        self::assertArrayNotHasKey('shipping_rules', $checks);
        self::assertSame(['channels', 'delivery_geography', 'delivery_windows'], array_keys($checks));
    }

    /** The legacy geography table remains an accepted FALLBACK, mirroring the form's own. */
    public function test_legacy_delivery_geography_still_satisfies_the_geography_check(): void
    {
        $brand = $this->brandWith(
            activeChannel: true,
            enabledGovernorate: false,
            activeWindow: true,
            legacyGeography: true,
        );

        $data = $this->health($brand);

        self::assertTrue($data['checks']['delivery_geography']);
        self::assertTrue($data['is_ready']);
    }

    public function test_no_selectable_governorate_on_either_authority_still_blocks(): void
    {
        $brand = $this->brandWith(activeChannel: true, enabledGovernorate: false, activeWindow: true);

        $data = $this->health($brand);

        self::assertFalse($data['checks']['delivery_geography']);
        self::assertFalse($data['is_ready'], 'Readiness must not be weakened: with no governorate the operator cannot enter a destination.');
    }

    public function test_a_disabled_governorate_does_not_satisfy_the_geography_check(): void
    {
        $company = Company::factory()->create();
        $brand = Brand::factory()->create(['company_id' => $company->id]);
        Channel::factory()->create(['brand_id' => $brand->id, 'is_active' => true]);
        BrandDeliveryTimeSlot::create([
            'brand_id' => $brand->id, 'name' => 'Morning',
            'start_time' => '09:00', 'end_time' => '12:00', 'is_active' => true,
        ]);
        BrandGovernorateSettings::create([
            'brand_id' => $brand->id,
            'governorate_id' => $this->governorateId(),
            'is_enabled' => false,
        ]);

        $data = $this->health($brand);

        self::assertFalse($data['checks']['delivery_geography']);
        self::assertFalse($data['is_ready']);
    }

    public function test_no_active_channel_still_blocks(): void
    {
        $brand = $this->brandWith(activeChannel: false, enabledGovernorate: true, activeWindow: true);

        $data = $this->health($brand);

        self::assertFalse($data['checks']['channels']);
        self::assertFalse($data['is_ready'], 'The brand is resolved FROM the channel; without one there is no order.');
    }

    public function test_no_active_delivery_window_still_blocks(): void
    {
        $brand = $this->brandWith(activeChannel: true, enabledGovernorate: true, activeWindow: false);

        $data = $this->health($brand);

        self::assertFalse($data['checks']['delivery_windows']);
        self::assertFalse($data['is_ready']);
    }
}

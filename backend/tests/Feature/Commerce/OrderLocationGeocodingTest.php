<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * DISTRIBUTION MAP / ORDER LOCATION correction — server-side geocoding of the
 * COMPLETE delivery address via `POST /orders/{order}/resolve-location`.
 *
 * Every Google call is faked; no real network request is made. These prove the
 * five honest states, that a success persists into the existing location columns
 * with `location_source='geocoded'`, and that a locality-only address is NEVER
 * geocoded to a centroid (rule §5).
 */
final class OrderLocationGeocodingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $customer;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->customer = Customer::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
    }

    public function test_captured_coordinates_are_returned_without_calling_google(): void
    {
        Http::fake();
        $order = $this->order(['google_maps_lat' => 30.05, 'google_maps_lng' => 31.23, 'location_source' => 'google_maps']);

        $this->actingAs($this->userFor())
            ->postJson("/api/orders/{$order->id}/resolve-location")
            ->assertOk()
            ->assertJsonPath('data.status', 'available')
            ->assertJsonPath('data.source', 'google_maps');

        Http::assertNothingSent();
    }

    public function test_missing_key_reports_not_configured_and_does_not_persist(): void
    {
        config()->set('services.google_maps.key', '');
        Http::fake();
        $order = $this->order($this->completeAddress());

        $this->actingAs($this->userFor())
            ->postJson("/api/orders/{$order->id}/resolve-location")
            ->assertOk()
            ->assertJsonPath('data.status', 'not_configured');

        Http::assertNothingSent();
        self::assertNull($order->refresh()->google_maps_lat);
    }

    public function test_complete_address_geocodes_and_persists_as_geocoded(): void
    {
        config()->set('services.google_maps.key', 'test-key');
        Http::fake([
            'maps.googleapis.com/*' => Http::response([
                'status' => 'OK',
                'results' => [['geometry' => ['location' => ['lat' => 29.9921582, 'lng' => 31.5089043]]]],
            ]),
        ]);

        $order = $this->order($this->completeAddress());

        $this->actingAs($this->userFor())
            ->postJson("/api/orders/{$order->id}/resolve-location")
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved_from_address')
            ->assertJsonPath('data.source', 'geocoded');

        $order->refresh();
        self::assertEqualsWithDelta(29.9921582, (float) $order->google_maps_lat, 0.0001);
        self::assertEqualsWithDelta(31.5089043, (float) $order->google_maps_lng, 0.0001);
        self::assertSame('geocoded', $order->location_source);
    }

    public function test_zero_results_reports_geocoding_failed_and_does_not_persist(): void
    {
        config()->set('services.google_maps.key', 'test-key');
        Http::fake([
            'maps.googleapis.com/*' => Http::response(['status' => 'ZERO_RESULTS', 'results' => []]),
        ]);

        $order = $this->order($this->completeAddress());

        $this->actingAs($this->userFor())
            ->postJson("/api/orders/{$order->id}/resolve-location")
            ->assertOk()
            ->assertJsonPath('data.status', 'geocoding_failed');

        self::assertNull($order->refresh()->google_maps_lat);
    }

    public function test_locality_only_address_is_never_geocoded_to_a_centroid(): void
    {
        config()->set('services.google_maps.key', 'test-key');
        Http::fake();
        // City + governorate but NO street/building line — a centroid, not a delivery point.
        $order = $this->order(['city' => 'Maadi', 'governorate' => 'Cairo']);

        $this->actingAs($this->userFor())
            ->postJson("/api/orders/{$order->id}/resolve-location")
            ->assertOk()
            ->assertJsonPath('data.status', 'address_unavailable');

        Http::assertNothingSent();
        self::assertNull($order->refresh()->google_maps_lat);
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function completeAddress(): array
    {
        return [
            'shipping_address' => '2 Shalaby Street',
            'building' => '22',
            'apartment' => '2',
            'city' => 'Maadi',
            'governorate' => 'Cairo',
        ];
    }

    /** @param array<string, mixed> $attrs */
    private function order(array $attrs = []): Order
    {
        return Order::query()->create(array_merge([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-GEO-'.uniqid(),
            'order_date' => now()->toDateString(),
            'assigned_warehouse_id' => $this->warehouse->id,
            'status' => 'in_progress',
            'subtotal' => 100, 'total' => 100, 'deposit_amount' => 0,
            'shipping_total' => 0, 'discount_total' => 0, 'tax_total' => 0,
        ], $attrs));
    }

    private function userFor(): User
    {
        return User::factory()->create(['company_id' => $this->company->id]);
    }
}

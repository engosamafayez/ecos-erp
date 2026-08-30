<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Commerce\Orders\Application\Services\GoogleMapsUrlResolver;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-ORDERS-INVENTORY-MANUAL-REMEDIATION-001 — Finding 05 (GPS "No GPS").
 *
 * Root cause: CreateManualOrderAction/UpdateOrderAction persisted a Google Maps
 * URL verbatim but never resolved it to coordinates (only PatchOrderAction did),
 * so an order created from a pasted short link stored NULL lat/lng and the grid
 * showed "No GPS". The fix routes create/update through GoogleMapsUrlResolver.
 */
class OrderGpsPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private function resolver(): GoogleMapsUrlResolver
    {
        return app(GoogleMapsUrlResolver::class);
    }

    // ── Coordinate extraction (parsing) ─────────────────────────────────────────

    public function test_resolve_extracts_at_style_coordinates(): void
    {
        // HEAD is faked; with no redirect the effective URL is the input itself,
        // so this exercises the extraction over a canonical long Maps URL.
        Http::fake(['*' => Http::response('', 200)]);

        [$url, $lat, $lng] = $this->resolver()->resolve('https://www.google.com/maps/@30.0444,31.2357,15z');

        self::assertSame(30.0444, $lat);
        self::assertSame(31.2357, $lng);
    }

    public function test_resolve_prefers_place_pin_over_viewport_centre(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        // @-part is the viewport centre; !3d!4d is the actual place pin and wins.
        [, $lat, $lng] = $this->resolver()->resolve(
            'https://www.google.com/maps/place/X/@30.1,31.1,15z/data=!3d30.0444!4d31.2357',
        );

        self::assertSame(30.0444, $lat);
        self::assertSame(31.2357, $lng);
    }

    public function test_resolve_returns_nulls_when_no_coordinates_present(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        [, $lat, $lng] = $this->resolver()->resolve('https://www.google.com/maps/place/SomePlace');

        self::assertNull($lat);
        self::assertNull($lng);
    }

    // ── backfillCoordinates — the guard branches (no network) ───────────────────

    public function test_backfill_is_a_noop_when_coordinates_are_already_present(): void
    {
        Http::fake();

        $data = $this->resolver()->backfillCoordinates([
            'google_maps_url' => 'https://maps.app.goo.gl/abc',
            'google_maps_lat' => 30.0,
            'google_maps_lng' => 31.0,
        ]);

        self::assertSame(30.0, $data['google_maps_lat']);
        Http::assertNothingSent();
    }

    public function test_backfill_recovers_coordinates_from_a_full_place_url_offline(): void
    {
        // REMEDIATION-002 (ORD-00002): a full google.com/maps place URL pasted without
        // client-side import must be resolved SERVER-SIDE. Coordinates are embedded in
        // the URL, so no network is needed — parsed offline via the shared parser.
        Http::fake();

        $data = $this->resolver()->backfillCoordinates([
            'google_maps_url' => 'https://www.google.com/maps/place/Air+Force+Specialized+Hospital/@30.0118436,31.2616504,12z/data=!4m9!3d30.0176104!4d31.4345694!16s%2Fg%2F11b5wl4hdk',
        ]);

        // !3d!4d place-pin wins over the @-viewport centre.
        self::assertSame(30.0176104, $data['google_maps_lat']);
        self::assertSame(31.4345694, $data['google_maps_lng']);
        self::assertSame('google_maps', $data['location_source']);
        Http::assertNothingSent(); // full URL parsed offline — no redirect follow
    }

    public function test_backfill_leaves_a_coordinateless_full_url_untouched(): void
    {
        Http::fake();

        $data = $this->resolver()->backfillCoordinates([
            'google_maps_url' => 'https://www.google.com/maps/place/SomePlace',
        ]);

        self::assertArrayNotHasKey('google_maps_lat', $data);
        Http::assertNothingSent();
    }

    // ── backfillCoordinates — short link resolved end-to-end ────────────────────

    public function test_backfill_resolves_a_short_link_to_coordinates(): void
    {
        // The short link 302-redirects to a canonical Maps URL carrying the pin.
        Http::fake([
            'maps.app.goo.gl/*' => Http::response('', 302, [
                'Location' => 'https://www.google.com/maps/place/X/@30.0444,31.2357,15z/data=!3d30.0444!4d31.2357',
            ]),
            'www.google.com/*' => Http::response('', 200),
        ]);

        $data = $this->resolver()->backfillCoordinates([
            'google_maps_url' => 'https://maps.app.goo.gl/abc123',
        ]);

        self::assertSame(30.0444, $data['google_maps_lat']);
        self::assertSame(31.2357, $data['google_maps_lng']);
    }

    // ── Integration: create persists GPS and the read model returns it ──────────

    public function test_manual_order_persists_supplied_coordinates_and_resource_returns_them(): void
    {
        $company = Company::factory()->create();
        $customer = Customer::factory()->create();
        $product = Product::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        $response = $this->actingAs($user)->postJson('/api/orders/manual', [
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'order_date' => now()->toDateString(),
            'google_maps_lat' => 30.0444,
            'google_maps_lng' => 31.2357,
            'location_source' => 'manual',
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100]],
        ]);

        self::assertContains($response->status(), [200, 201], $response->getContent());
        $id = $response->json('data.id') ?? $response->json('id');

        // Persisted on the row …
        $row = DB::table('orders')->where('id', $id)->first();
        self::assertEqualsWithDelta(30.0444, (float) $row->google_maps_lat, 0.0001);
        self::assertEqualsWithDelta(31.2357, (float) $row->google_maps_lng, 0.0001);

        // … and surfaced by the read model (OrderResource emits `location` only
        // when coordinates exist — the exact field the grid reads for GPS).
        self::assertEqualsWithDelta(30.0444, (float) $response->json('data.location.lat'), 0.0001);
        self::assertEqualsWithDelta(31.2357, (float) $response->json('data.location.lng'), 0.0001);
    }

    public function test_manual_order_without_gps_reports_no_location(): void
    {
        $company = Company::factory()->create();
        $customer = Customer::factory()->create();
        $product = Product::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        $response = $this->actingAs($user)->postJson('/api/orders/manual', [
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'order_date' => now()->toDateString(),
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100]],
        ]);

        self::assertContains($response->status(), [200, 201], $response->getContent());
        $id = $response->json('data.id') ?? $response->json('id');

        $row = DB::table('orders')->where('id', $id)->first();
        self::assertNull($row->google_maps_lat);
        self::assertNull($response->json('data.location'));
    }

    /**
     * REMEDIATION-002 — the live ORD-00002 case. An operator pastes a full Google
     * Maps place URL but never clicks "Import Location", so the request carries the
     * URL with NO coordinates. The server must recover them and the read model must
     * expose `location` — exactly the field the Orders grid reads for GPS.
     */
    public function test_manual_order_backfills_coordinates_from_url_only_full_place_link(): void
    {
        Http::fake(); // full URL is parsed offline; assert nothing is sent below

        $company = Company::factory()->create();
        $customer = Customer::factory()->create();
        $product = Product::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        // The exact URL shape found on ORD-00002: /maps/place/…/@lat,lng,zoom/data=…!3d!4d
        $url = 'https://www.google.com/maps/place/Air+Force+Specialized+Hospital/@30.0118436,31.2616504,12z/data=!4m9!1m2!2m1!1sMaadi,+Cairo!3d30.0176104!4d31.4345694!16s%2Fg%2F11b5wl4hdk?entry=ttu';

        $response = $this->actingAs($user)->postJson('/api/orders/manual', [
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'order_date' => now()->toDateString(),
            'google_maps_url' => $url,
            // NO google_maps_lat / google_maps_lng — the whole point of the case.
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100]],
        ]);

        self::assertContains($response->status(), [200, 201], $response->getContent());
        $id = $response->json('data.id') ?? $response->json('id');

        // Persisted on the row — the !3d!4d place-pin, not the @-viewport centre.
        $row = DB::table('orders')->where('id', $id)->first();
        self::assertEqualsWithDelta(30.0176104, (float) $row->google_maps_lat, 0.0001);
        self::assertEqualsWithDelta(31.4345694, (float) $row->google_maps_lng, 0.0001);
        self::assertSame('google_maps', $row->location_source);

        // Surfaced by the read model — the exact contract the grid renders for GPS.
        self::assertEqualsWithDelta(30.0176104, (float) $response->json('data.location.lat'), 0.0001);
        self::assertEqualsWithDelta(31.4345694, (float) $response->json('data.location.lng'), 0.0001);

        Http::assertNothingSent(); // recovered offline — no outbound redirect follow
    }
}

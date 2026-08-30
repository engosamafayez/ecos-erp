<?php

declare(strict_types=1);

namespace Tests\Feature\Logistics;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Logistics\Geography\Domain\Services\OrderCityBinder;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-DISTRIBUTION-ORDER-GEOGRAPHY-SYNCHRONIZATION-003.
 *
 * The chain, end to end:
 *
 *   Order address → city → logistics_city_id → OrderZoneResolver → Distribution zone
 *
 * The defect this closes: `logistics_city_id` is the ONLY input to the zone
 * resolver, and before this task nothing wrote it on an edit path — only
 * OrderCityBinder's NULL-only sweep and a one-time backfill. `PatchOrderRequest`
 * did not even accept `city`, so an operator editing an Order's location from the
 * grid could only change free-text labels (`area`, `delivery_zone`) while the one
 * field that decides the zone kept its original value.
 *
 * What must NOT change, and is asserted here:
 *   • `bindForCompany()` stays NULL-only — "a later geography edit cannot silently
 *     move an Order that operators have already planned around";
 *   • `reconcileUnzoned()` stays NULL-zone-only;
 *   • no city or zone is ever guessed.
 */
class DistributionOrderGeographySyncTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/logistics/distribution';

    private Company $company;

    private Customer $customer;

    private Warehouse $warehouse;

    private int $zoneMaadi;

    private int $zoneObour;

    private int $cityMaadi;

    private int $cityObour;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('distribution.window.opens_at', '00:00');
        config()->set('distribution.window.closes_at', '23:59');

        $this->company = Company::factory()->create();
        $this->customer = Customer::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);

        $governorate = (int) DB::table('logistics_governorates')->insertGetId([
            'country_id' => 1,
            'name_ar' => 'القاهرة', 'name_en' => 'Cairo',
            'default_shipping_price' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->zoneMaadi = $this->zone('Maadi');
        $this->zoneObour = $this->zone('Obour');

        // The two canonical cities. Exactly the shape the live data has: a city
        // row whose name is what an operator types, carrying its zone.
        $this->cityMaadi = $this->city($governorate, 'Maadi', 'المعادي', $this->zoneMaadi);
        $this->cityObour = $this->city($governorate, 'Obour City', 'مدينة العبور', $this->zoneObour);
    }

    // ── §7 forward ───────────────────────────────────────────────────────────

    /**
     * §7 — the reported defect, reproduced and fixed.
     *
     * Maadi → Obour City must move `city`, `logistics_city_id` AND the Distribution
     * zone. No stale Maadi value may survive anywhere.
     */
    public function test_changing_city_moves_the_logistics_city_and_the_distribution_zone(): void
    {
        [$order, $assignment] = $this->collectedOrderIn('Maadi');

        // Baseline: the Order is in Maadi, bound to Maadi, zoned Maadi.
        self::assertSame($this->cityMaadi, (int) $order->refresh()->logistics_city_id);
        self::assertSame($this->zoneMaadi, (int) DB::table('distribution_window_orders')
            ->where('id', $assignment)->value('distribution_zone_id'));

        // The operator edits City + Governorate from the Orders grid.
        $this->patchGeography($order, ['city' => 'Obour City', 'governorate' => 'Cairo']);

        $order->refresh();

        self::assertSame('Obour City', $order->city, 'the free-text city must be stored');
        self::assertSame(
            $this->cityObour,
            (int) $order->logistics_city_id,
            'logistics_city_id must be re-resolved to the new city',
        );
        self::assertSame(
            $this->zoneObour,
            (int) DB::table('distribution_window_orders')->where('id', $assignment)
                ->value('distribution_zone_id'),
            'the Distribution zone must follow the new city',
        );

        // §12 — the third representation: what Distribution actually SHOWS.
        // `city_name` is the canonical join (logistics_cities.name_en) and
        // `shipping_address.city` is what the operator reads on the row; both must
        // have moved, or the screen would still say Maadi.
        $row = $this->distributionRowFor($order);
        self::assertSame($this->cityObour, $row['city_id']);
        self::assertSame('Obour City', $row['city_name']);
        self::assertSame('Obour City', $row['shipping_address']['city'] ?? null);
        self::assertSame($this->zoneObour, $row['zone_id']);
        self::assertNotSame(
            'Maadi',
            $row['shipping_address']['city'] ?? null,
            'no stale Maadi may remain anywhere in the read model',
        );
    }

    // ── TASK-DISTRIBUTION-ZONES-TABLE-UX-001 — coordinate payload ──────────────

    /**
     * The Zones table links to the delivery pin, so the orders read model must
     * carry the captured coordinates. Asserted end to end through the real
     * quick-update and orders endpoints: absent coordinates stay null (never a
     * fabricated 0), and a captured pin surfaces on the row the table renders.
     */
    public function test_orders_read_model_exposes_captured_coordinates(): void
    {
        [$order] = $this->collectedOrderIn('Maadi');

        $before = $this->distributionRowFor($order);
        self::assertNull($before['latitude'], 'no coordinate captured yet — must be null, not 0');
        self::assertNull($before['longitude']);
        self::assertNull($before['google_maps_url']);

        // The operator pins the location from the grid — the existing quick-update
        // contract, unchanged.
        $this->patchGeography($order, [
            'google_maps_url' => 'https://www.google.com/maps?q=30.0444,31.2357',
            'google_maps_lat' => 30.0444,
            'google_maps_lng' => 31.2357,
        ]);

        $after = $this->distributionRowFor($order);
        self::assertEqualsWithDelta(30.0444, $after['latitude'], 0.0000001);
        self::assertEqualsWithDelta(31.2357, $after['longitude'], 0.0000001);
        self::assertSame('https://www.google.com/maps?q=30.0444,31.2357', $after['google_maps_url']);
    }

    // ── §8 reverse ───────────────────────────────────────────────────────────

    /** §8 — and back again. The sync is not one-directional. */
    public function test_changing_the_city_back_returns_the_logistics_city_and_zone(): void
    {
        [$order, $assignment] = $this->collectedOrderIn('Obour City');

        self::assertSame($this->zoneObour, (int) DB::table('distribution_window_orders')
            ->where('id', $assignment)->value('distribution_zone_id'));

        $this->patchGeography($order, ['city' => 'Maadi', 'governorate' => 'Cairo']);

        $order->refresh();

        self::assertSame($this->cityMaadi, (int) $order->logistics_city_id);
        self::assertSame(
            $this->zoneMaadi,
            (int) DB::table('distribution_window_orders')->where('id', $assignment)
                ->value('distribution_zone_id'),
        );
    }

    // ── §9 unmatched ─────────────────────────────────────────────────────────

    /**
     * §9 — an unmatched city is never guessed at.
     *
     * The resolver matches EXACTLY; a near-miss is unresolved. The previously
     * stored id was justified by the OLD text, so once that text is gone the id is
     * an assertion nothing supports — it is cleared, and the Order becomes
     * unzoned rather than staying in a zone its address no longer implies.
     */
    public function test_an_unmatched_city_clears_the_binding_and_guesses_nothing(): void
    {
        [$order, $assignment] = $this->collectedOrderIn('Maadi');

        $this->patchGeography($order, ['city' => 'Nowhere-Ville', 'governorate' => 'Cairo']);

        $order->refresh();

        self::assertSame('Nowhere-Ville', $order->city);
        self::assertNull($order->logistics_city_id, 'no city may be guessed');
        self::assertNull(
            DB::table('distribution_window_orders')->where('id', $assignment)
                ->value('distribution_zone_id'),
            'no zone may be guessed, and the stale one may not survive',
        );
        self::assertNull(
            DB::table('distribution_window_orders')->where('id', $assignment)
                ->value('virtual_slot_id'),
            'an unzoned Order cannot remain in a Group',
        );
    }

    /** An Order that genuinely has no city stays unassigned — the ORD-00001 shape. */
    public function test_an_order_with_no_city_stays_unassigned(): void
    {
        $order = $this->order(city: null);
        $this->line($order);

        $this->collect();

        $order->refresh();

        self::assertNull($order->logistics_city_id);
        self::assertNull(
            DB::table('distribution_window_orders')->where('order_id', $order->id)
                ->value('distribution_zone_id'),
        );
    }

    /** A city that exists but carries no zone resolves the city and no zone. */
    public function test_a_city_with_no_zone_resolves_the_city_but_not_a_zone(): void
    {
        $governorate = (int) DB::table('logistics_governorates')->where('name_en', 'Cairo')->value('id');
        $zonelessCity = $this->city($governorate, 'Zoneless Town', 'بلا منطقة', null);

        [$order, $assignment] = $this->collectedOrderIn('Maadi');

        $this->patchGeography($order, ['city' => 'Zoneless Town']);

        $order->refresh();

        self::assertSame($zonelessCity, (int) $order->logistics_city_id, 'the city IS known');
        self::assertNull(
            DB::table('distribution_window_orders')->where('id', $assignment)
                ->value('distribution_zone_id'),
            'but it maps to no zone, and none may be invented',
        );
    }

    // ── §10 regression: the untouched contracts ──────────────────────────────

    /**
     * §10 — `bindForCompany()` is still NULL-only.
     *
     * This is the contract the whole design routes around: a background sweep must
     * never move an Order an operator has planned around. Here the stored id is
     * deliberately WRONG relative to the text, and the sweep must still refuse.
     */
    public function test_the_background_binder_still_refuses_to_rebind_a_bound_order(): void
    {
        [$order] = $this->collectedOrderIn('Maadi');

        // Force a disagreement the sweep could "fix" if it were allowed to.
        DB::table('orders')->where('id', $order->id)->update(['city' => 'Obour City']);

        $result = app(OrderCityBinder::class)->bindForCompany($this->company->id);

        self::assertSame(0, $result['bound'], 'a bound Order must not be re-bound by the sweep');
        self::assertSame(
            $this->cityMaadi,
            (int) $order->refresh()->logistics_city_id,
            'the sweep must leave an already-bound Order exactly as it found it',
        );
    }

    /** §10 — `reconcileUnzoned()` is still NULL-zone-only. */
    public function test_reconcile_unzoned_still_refuses_to_move_a_zoned_order(): void
    {
        [$order, $assignment] = $this->collectedOrderIn('Maadi');

        // The Order's city now says Obour, but its assignment is zoned Maadi.
        DB::table('orders')->where('id', $order->id)
            ->update(['city' => 'Obour City', 'logistics_city_id' => $this->cityObour]);

        $windowId = DB::table('distribution_window_orders')->where('id', $assignment)
            ->value('distribution_window_id');

        $repaired = app(\Modules\Logistics\Distribution\Domain\Services\DistributionCollectionService::class)
            ->reconcileUnzoned($this->company->id, (string) $windowId);

        self::assertSame(0, $repaired, 'reconcileUnzoned must not touch an already-zoned row');
        self::assertSame(
            $this->zoneMaadi,
            (int) DB::table('distribution_window_orders')->where('id', $assignment)
                ->value('distribution_zone_id'),
        );
    }

    /** §10 — editing one Order's geography leaves every other Order alone. */
    public function test_no_unrelated_order_changes(): void
    {
        [$target] = $this->collectedOrderIn('Maadi');
        [$bystander, $bystanderAssignment] = $this->collectedOrderIn('Maadi');

        $before = DB::table('distribution_window_orders')->where('id', $bystanderAssignment)
            ->get()->toJson();
        $beforeOrder = DB::table('orders')->where('id', $bystander->id)
            ->get(['city', 'logistics_city_id', 'governorate'])->toJson();

        $this->patchGeography($target, ['city' => 'Obour City']);

        self::assertSame(
            $before,
            DB::table('distribution_window_orders')->where('id', $bystanderAssignment)->get()->toJson(),
            'a bystander assignment must be byte-identical',
        );
        self::assertSame(
            $beforeOrder,
            DB::table('orders')->where('id', $bystander->id)
                ->get(['city', 'logistics_city_id', 'governorate'])->toJson(),
        );
    }

    /** An edit that does not touch city or governorate raises no re-zone at all. */
    public function test_editing_an_unrelated_field_does_not_rezone(): void
    {
        [$order, $assignment] = $this->collectedOrderIn('Maadi');

        $before = DB::table('distribution_window_orders')->where('id', $assignment)->get()->toJson();

        // `area` is a free-text label. It resolves to nothing and must move nothing.
        $this->patchGeography($order, ['area' => 'Some Neighbourhood']);

        self::assertSame(
            $before,
            DB::table('distribution_window_orders')->where('id', $assignment)->get()->toJson(),
            'an area-only edit must not re-zone',
        );
    }

    /** Re-sending the SAME city is not a change and must not re-stamp the audit. */
    public function test_resending_the_same_city_is_a_no_op(): void
    {
        [$order, $assignment] = $this->collectedOrderIn('Maadi');

        $before = DB::table('distribution_window_orders')->where('id', $assignment)->get()->toJson();

        $this->patchGeography($order, ['city' => 'Maadi']);

        self::assertSame($before, DB::table('distribution_window_orders')
            ->where('id', $assignment)->get()->toJson());
    }

    /** An Order never collected into Distribution has nothing to re-zone. */
    public function test_an_uncollected_order_only_updates_its_city_binding(): void
    {
        $order = $this->order(city: 'Maadi');
        $this->line($order);

        // No collect() — so no assignment exists.
        $this->patchGeography($order, ['city' => 'Obour City']);

        self::assertSame($this->cityObour, (int) $order->refresh()->logistics_city_id);
        self::assertSame(
            0,
            DB::table('distribution_window_orders')->where('order_id', $order->id)->count(),
            'no assignment may be invented for an uncollected Order',
        );
    }

    /** Tenant boundary: the re-bind refuses an Order outside the acting company. */
    public function test_rebind_refuses_an_order_outside_the_company(): void
    {
        $order = $this->order(city: 'Maadi');
        $other = Company::factory()->create();

        $result = app(OrderCityBinder::class)->rebindOrder((string) $order->id, $other->id);

        self::assertFalse($result['changed']);
        self::assertNull($result['city_id']);
        self::assertNull($order->refresh()->logistics_city_id, 'no cross-tenant write');
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function zone(string $name): int
    {
        return (int) DB::table('distribution_zones')->insertGetId([
            'code' => 'GS-'.substr(uniqid(), -6),
            'name_ar' => $name.'-'.uniqid(), 'name_en' => $name,
            'color' => '#3b82f6',
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function city(int $governorate, string $en, string $ar, ?int $zoneId): int
    {
        $id = (int) DB::table('logistics_cities')->insertGetId([
            'governorate_id' => $governorate,
            'name_ar' => $ar, 'name_en' => $en,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('logistics_cities')->where('id', $id)
            ->update(['distribution_zone_id' => $zoneId]);

        return $id;
    }

    private function order(?string $city): Order
    {
        return Order::query()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-GS-'.uniqid(),
            'order_date' => now()->toDateString(),
            'assigned_warehouse_id' => $this->warehouse->id,
            'city' => $city,
            'governorate' => 'Cairo',
            'status' => 'in_progress',
            'subtotal' => 100, 'total' => 100,
            'deposit_amount' => 0,
            'shipping_total' => 0, 'discount_total' => 0, 'tax_total' => 0,
        ]);
    }

    private function line(Order $order): void
    {
        DB::table('order_lines')->insert([
            'id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'product_id' => Product::factory()->create()->id,
            'quantity' => 1,
            'unit_price' => 10,
            'line_total' => 10,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * An Order that has been through the real collection path, so its city binding
     * and its Distribution zone were both produced by production code.
     *
     * @return array{0: Order, 1: string} [order, assignmentId]
     */
    private function collectedOrderIn(string $city): array
    {
        $order = $this->order($city);
        $this->line($order);

        $this->collect();

        $assignment = DB::table('distribution_window_orders')
            ->where('order_id', $order->id)->value('id');

        self::assertNotNull($assignment, 'the Order must have been collected');

        return [$order, (string) $assignment];
    }

    private function collect(): void
    {
        $this->actingAs($this->user())
            ->postJson(self::BASE.'/windows/collect?warehouse_id='.$this->warehouse->id)
            ->assertOk();
    }

    /**
     * The Orders-grid inline edit — the approved operator workflow.
     *
     * `PATCH /orders/{order}/quick-update` is the route that carries
     * PatchOrderRequest. Plain `PATCH /orders/{order}` is the FULL update and
     * demands customer_id, order_date, status and lines, so it is not the grid's
     * edit path and must not be used to exercise it.
     */
    private function patchGeography(Order $order, array $payload): void
    {
        $this->actingAs($this->user())
            ->patchJson('/api/orders/'.$order->id.'/quick-update', $payload)
            ->assertSuccessful();
    }

    /** One row of Distribution's own read model — the third representation (§12). */
    private function distributionRowFor(Order $order): array
    {
        $windowId = $this->actingAs($this->user())
            ->getJson(self::BASE.'/windows/current?warehouse_id='.$this->warehouse->id)
            ->assertOk()->json('data.window.id');

        $rows = $this->actingAs($this->user())
            ->getJson(self::BASE."/windows/{$windowId}/orders?warehouse_id=".$this->warehouse->id)
            ->assertOk()->json('data');

        foreach ($rows as $row) {
            if (($row['order_number'] ?? null) === $order->order_number) {
                return $row;
            }
        }

        self::fail('the Order is missing from the Distribution read model');
    }

    private function user(): User
    {
        return User::factory()->create(['company_id' => $this->company->id]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Logistics;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-DISTRIBUTION-UNASSIGNED-ZONES-VISIBILITY-001.
 *
 * ┌─ THE DEFECT ─────────────────────────────────────────────────────────────┐
 * │ An eligible Order can sit in a Window, carry a Zone, and belong to NO     │
 * │ Group — because a Group holds only the Zones an operator attached to it.  │
 * │ And because every Group-side read is warehouse-scoped, an Order with      │
 * │ `assigned_warehouse_id = NULL` matched no warehouse and disappeared from  │
 * │ the board entirely.                                                      │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * The read classifies the ROOT blocker: each Order appears in exactly one bucket, the
 * most actionable one. An Order that is both warehouse-less AND in an uncovered Zone is
 * WAREHOUSE_UNASSIGNED, because that is what must be cleared first.
 *
 * READ ONLY. These rows assert that the endpoint classifies and mutates nothing — no
 * warehouse, zone, group, trip or window is created or changed by reading it.
 */
class OrdersAwaitingGroupVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/logistics/distribution';

    private Company $company;

    private Customer $customer;

    private Warehouse $warehouse;

    /** Attached to a Group in every scenario. */
    private int $zoneCovered;

    /** Deliberately attached to no Group. */
    private int $zoneUncovered;

    /** A second uncovered Zone, so ordering can be asserted deterministically. */
    private int $zoneUncoveredSecond;

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

        $this->zoneCovered = $this->zone('Maadi');
        $this->zoneUncovered = $this->zone('Helwan');
        $this->zoneUncoveredSecond = $this->zone('Obour');
        $this->city($governorate, 'Maadi', 'المعادي', $this->zoneCovered);
        $this->city($governorate, 'Helwan', 'حلوان', $this->zoneUncovered);
        $this->city($governorate, 'Obour', 'العبور', $this->zoneUncoveredSecond);
    }

    // ── 1-3. The three blockers ──────────────────────────────────────────────

    /** An Order whose Zone belongs to no Group is visible, with that reason. */
    public function test_an_order_in_an_uncovered_zone_is_visible_as_zone_not_in_group(): void
    {
        [$windowId] = $this->windowWithGroup();
        $stranded = $this->order('Helwan');           // covered zone is Maadi
        $this->collect();

        $data = $this->awaitingGroup($windowId);

        self::assertSame(1, $data['summary']['total']);
        self::assertSame(1, $data['summary']['zone_not_in_group']);
        self::assertSame(0, $data['summary']['warehouse_unassigned']);
        self::assertSame($stranded->order_number, $data['orders'][0]['order_number']);
        self::assertSame('zone_not_in_group', $data['orders'][0]['blocker']);
    }

    /**
     * §4 — the root blocker wins. An Order that is BOTH warehouse-less and in an
     * uncovered Zone must read as WAREHOUSE_UNASSIGNED, never as zone_not_in_group.
     * This is the live ORD-00013 / ORD-00014 shape.
     */
    public function test_a_warehouse_less_order_in_an_uncovered_zone_reads_as_warehouse_unassigned(): void
    {
        [$windowId] = $this->windowWithGroup();
        $noWarehouse = $this->order('Helwan', warehouse: null);
        $this->collect();

        $data = $this->awaitingGroup($windowId);

        self::assertSame(1, $data['summary']['total']);
        self::assertSame(1, $data['summary']['warehouse_unassigned'], 'the root blocker');
        self::assertSame(0, $data['summary']['zone_not_in_group'], 'not double-counted');

        $row = $data['orders'][0];
        self::assertSame($noWarehouse->order_number, $row['order_number']);
        self::assertSame('warehouse_unassigned', $row['blocker']);
        self::assertNull($row['warehouse_id']);
        self::assertNotNull($row['zone_id'], 'it does have a zone — the warehouse is the blocker');
    }

    /** An Order with no Zone at all is visible, carrying the EXISTING zone-level reason. */
    public function test_an_unzoneable_order_is_visible_with_the_existing_secondary_reason(): void
    {
        [$windowId] = $this->windowWithGroup();
        // No city text at all -> the existing classifier says `address_incomplete`.
        $this->order(null);
        $this->collect();

        $data = $this->awaitingGroup($windowId);

        $row = collect($data['orders'])->firstWhere('zone_id', null);
        self::assertNotNull($row, 'an unzoneable order must not vanish');
        self::assertSame('zone_not_in_group', $row['blocker']);
        self::assertSame(
            'address_incomplete',
            $row['secondary_reason'],
            'the existing zone-level classifier is carried through, not recomputed',
        );
    }

    // ── 4, 7. What must NOT appear ───────────────────────────────────────────

    /** An Order already covered by a Group is not an exception. */
    public function test_an_order_already_in_a_group_does_not_appear(): void
    {
        [$windowId] = $this->windowWithGroup(['Maadi', 'Maadi']);

        $data = $this->awaitingGroup($windowId);

        self::assertSame(0, $data['summary']['total']);
        self::assertSame([], $data['orders']);
    }

    /**
     * An ineligible Order is not surfaced as awaiting a Group.
     *
     * Eligibility is not redefined here: the Order set comes from the same
     * `DistributionAggregationService::orders()` call the Groups board uses, so an Order
     * outside that predicate simply never reaches the classifier.
     */
    public function test_an_ineligible_order_is_not_classified_as_awaiting_a_group(): void
    {
        [$windowId] = $this->windowWithGroup();

        $cancelled = $this->order('Helwan');
        $this->collect();
        // Status changed directly, and only to build an ineligible fixture — the
        // classifier must simply not see it.
        DB::table('orders')->where('id', $cancelled->id)->update(['status' => 'cancelled']);

        $data = $this->awaitingGroup($windowId);

        self::assertSame(0, $data['summary']['total']);
    }

    // ── 8. Tenancy ───────────────────────────────────────────────────────────

    /** One company can never see another company's Orders. */
    public function test_another_companys_orders_never_appear(): void
    {
        [$windowId] = $this->windowWithGroup();
        $this->order('Helwan');
        $this->collect();

        self::assertSame(1, $this->awaitingGroup($windowId)['summary']['total']);

        // A second company reading the SAME window id must not resolve it at all.
        $other = Company::factory()->create();
        $this->actingAs(User::factory()->create(['company_id' => $other->id]))
            ->getJson(self::BASE."/windows/{$windowId}/awaiting-group")
            ->assertStatus(404);
    }

    /** The read carries the same view permission as its sibling Distribution reads. */
    public function test_the_read_requires_the_view_permission(): void
    {
        [$windowId] = $this->windowWithGroup();

        $this->actingAsUnprivileged($this->user())
            ->getJson(self::BASE."/windows/{$windowId}/awaiting-group")
            ->assertStatus(403);
    }

    // ── 9-13. The read mutates nothing ───────────────────────────────────────

    /**
     * No Group, Trip, Window, membership or Order is touched by reading the exception
     * list — asserted across every table the surface reads from, twice.
     */
    public function test_the_read_mutates_nothing(): void
    {
        [$windowId] = $this->windowWithGroup(['Maadi']);
        $this->order('Helwan');
        $this->order('Helwan', warehouse: null);
        $this->collect();

        $before = [
            'orders' => DB::table('orders')->count(),
            'order_state' => DB::table('orders')->orderBy('id')->pluck('status', 'id')->toArray(),
            'warehouses' => DB::table('orders')->orderBy('id')->pluck('assigned_warehouse_id', 'id')->toArray(),
            'groups' => DB::table('distribution_virtual_slots')->count(),
            'slot_zones' => DB::table('distribution_slot_zones')->count(),
            'windows' => DB::table('distribution_windows')->count(),
            'trips' => DB::table('distribution_trips')->count(),
            'manifest' => DB::table('distribution_trip_orders')->count(),
            'membership' => DB::table('distribution_window_orders')->orderBy('order_id')
                ->pluck('virtual_slot_id', 'order_id')->toArray(),
            'zones' => DB::table('distribution_window_orders')->orderBy('order_id')
                ->pluck('distribution_zone_id', 'order_id')->toArray(),
        ];

        $this->awaitingGroup($windowId);
        $this->awaitingGroup($windowId);

        self::assertSame($before['orders'], DB::table('orders')->count());
        self::assertSame($before['order_state'], DB::table('orders')->orderBy('id')->pluck('status', 'id')->toArray());
        self::assertSame(
            $before['warehouses'],
            DB::table('orders')->orderBy('id')->pluck('assigned_warehouse_id', 'id')->toArray(),
            'no warehouse was assigned',
        );
        self::assertSame($before['groups'], DB::table('distribution_virtual_slots')->count(), 'no Group created');
        self::assertSame($before['slot_zones'], DB::table('distribution_slot_zones')->count(), 'no zone attached');
        self::assertSame($before['windows'], DB::table('distribution_windows')->count(), 'no Window created');
        self::assertSame($before['trips'], DB::table('distribution_trips')->count(), 'no Trip touched');
        self::assertSame($before['manifest'], DB::table('distribution_trip_orders')->count());
        self::assertSame(
            $before['membership'],
            DB::table('distribution_window_orders')->orderBy('order_id')->pluck('virtual_slot_id', 'order_id')->toArray(),
            'no group membership changed',
        );
        self::assertSame(
            $before['zones'],
            DB::table('distribution_window_orders')->orderBy('order_id')->pluck('distribution_zone_id', 'order_id')->toArray(),
            'no zone reassigned',
        );
    }

    /** Both blockers coexist in one response, each counted once. */
    public function test_the_summary_counts_each_order_in_exactly_one_bucket(): void
    {
        [$windowId] = $this->windowWithGroup(['Maadi']);
        $this->order('Helwan');                      // zone not in group
        $this->order('Helwan', warehouse: null);     // warehouse unassigned
        $this->order('Helwan', warehouse: null);     // warehouse unassigned
        $this->collect();

        $summary = $this->awaitingGroup($windowId)['summary'];

        self::assertSame(3, $summary['total']);
        self::assertSame(2, $summary['warehouse_unassigned']);
        self::assertSame(1, $summary['zone_not_in_group']);
        self::assertSame(0, $summary['awaiting_group_assignment']);
        self::assertSame(
            $summary['total'],
            $summary['warehouse_unassigned'] + $summary['zone_not_in_group'] + $summary['awaiting_group_assignment'],
            'the buckets partition the set — no order counted twice or dropped',
        );
    }

    /**
     * A warehouse-scoped read still returns warehouse-NULL Orders.
     *
     * Deliberate: they belong to no warehouse, so a warehouse filter would drop exactly
     * the rows that need attention — which is the defect this surface exists to fix.
     */
    public function test_a_warehouse_scoped_read_still_includes_warehouse_null_orders(): void
    {
        [$windowId] = $this->windowWithGroup(['Maadi']);
        $this->order('Helwan', warehouse: null);
        $this->collect();

        $scoped = $this->awaitingGroup($windowId, $this->warehouse->id);

        self::assertSame(1, $scoped['summary']['total']);
        self::assertSame(1, $scoped['summary']['warehouse_unassigned']);
    }

    /** An Order of ANOTHER warehouse is not this operator's exception. */
    public function test_a_warehouse_scoped_read_excludes_another_warehouses_order(): void
    {
        [$windowId] = $this->windowWithGroup(['Maadi']);

        $other = Warehouse::factory()->create(['company_id' => $this->company->id]);
        $this->order('Helwan', warehouse: $other);
        $this->collect();

        self::assertSame(1, $this->awaitingGroup($windowId)['summary']['total'], 'company-wide sees it');
        self::assertSame(
            0,
            $this->awaitingGroup($windowId, $this->warehouse->id)['summary']['total'],
            'the other warehouse does not',
        );
    }

    // ── ZONE-LEVEL COVERAGE (TASK-DISTRIBUTION-ZONE-GROUP-CONFIGURATION-VISIBILITY-001) ──

    /** A Zone holding uncovered work appears, with the count of Orders behind it. */
    public function test_a_zone_with_uncovered_orders_appears_with_its_order_count(): void
    {
        [$windowId] = $this->windowWithGroup();
        $this->order('Helwan');
        $this->order('Helwan');
        $this->collect();

        $zones = $this->awaitingGroup($windowId)['zones'];

        self::assertCount(1, $zones);
        self::assertSame($this->zoneUncovered, $zones[0]['zone_id']);
        self::assertSame(2, $zones[0]['orders_waiting']);
        self::assertSame(0, $zones[0]['orders_needing_warehouse']);
    }

    /** A Zone whose work is already covered by a Group is not listed. */
    public function test_a_covered_zone_does_not_appear(): void
    {
        [$windowId] = $this->windowWithGroup(['Maadi', 'Maadi']);

        $data = $this->awaitingGroup($windowId);

        self::assertSame([], $data['zones'], 'the covered zone is not a gap');
        self::assertSame(0, $data['summary']['total']);
    }

    /** A Zone with no relevant Orders at all is not listed — only real demand shows. */
    public function test_a_zone_with_no_relevant_orders_does_not_appear(): void
    {
        [$windowId] = $this->windowWithGroup();

        // The uncovered zone exists and is attached to no Group, but holds no orders.
        self::assertSame([], $this->awaitingGroup($windowId)['zones']);
    }

    /**
     * §5 — a warehouse-null Order must NOT hide its Zone, and the two blockers stay
     * separate. This is the live DZ-0003 shape: both its Orders lack a warehouse.
     */
    public function test_a_zone_whose_orders_all_lack_a_warehouse_still_appears(): void
    {
        [$windowId] = $this->windowWithGroup();
        $this->order('Helwan', warehouse: null);
        $this->order('Helwan', warehouse: null);
        $this->collect();

        $zones = $this->awaitingGroup($windowId)['zones'];

        self::assertCount(1, $zones, 'the zone must not disappear');
        self::assertSame(2, $zones[0]['orders_waiting']);
        self::assertSame(
            2,
            $zones[0]['orders_needing_warehouse'],
            'the warehouse blocker is reported separately, not merged into "no group"',
        );
    }

    /** A Zone can carry both kinds at once, each counted correctly. */
    public function test_a_zone_reports_partial_warehouse_blockers(): void
    {
        [$windowId] = $this->windowWithGroup();
        $this->order('Helwan');
        $this->order('Helwan', warehouse: null);
        $this->collect();

        $zone = $this->awaitingGroup($windowId)['zones'][0];

        self::assertSame(2, $zone['orders_waiting']);
        self::assertSame(1, $zone['orders_needing_warehouse']);
    }

    /** §7 — an Order with no Zone must not invent a Zone row. */
    public function test_a_zoneless_order_creates_no_zone_row(): void
    {
        [$windowId] = $this->windowWithGroup();
        $this->order(null);   // no city -> no zone
        $this->collect();

        $data = $this->awaitingGroup($windowId);

        self::assertSame([], $data['zones'], 'no synthetic zone');
        self::assertSame(1, $data['summary']['total'], 'but the order is still visible');
    }

    /**
     * The two grains must reconcile: every waiting Order is either counted under a Zone
     * or is one of the zone-less Orders. Nothing is double-counted or dropped.
     */
    public function test_the_zone_rollup_reconciles_with_the_order_list(): void
    {
        [$windowId] = $this->windowWithGroup(['Maadi']);
        $this->order('Helwan');
        $this->order('Helwan', warehouse: null);
        $this->order(null);   // zone-less
        $this->collect();

        $data = $this->awaitingGroup($windowId);

        $zoneTotal = array_sum(array_column($data['zones'], 'orders_waiting'));
        $zoneless = count(array_filter(
            $data['orders'],
            static fn (array $o): bool => $o['zone_id'] === null,
        ));

        self::assertSame(3, $data['summary']['total']);
        self::assertSame(1, $zoneless);
        self::assertSame(
            $data['summary']['total'],
            $zoneTotal + $zoneless,
            'zone rollup + zone-less orders == the order list, exactly',
        );
    }

    /**
     * Busiest Zone first — the one blocking the most work is the one to configure next.
     *
     * Both uncovered Zones come from setUp, so the fixture is deterministic: a Zone and
     * its City created mid-test would have to be bound by the collector before it could
     * appear, which is a timing dependency this assertion should not carry.
     */
    public function test_zones_are_ordered_by_how_much_work_they_block(): void
    {
        [$windowId] = $this->windowWithGroup();

        $this->order('Helwan');
        $this->order('Helwan');
        $this->order('Obour');
        $this->collect();

        $zones = $this->awaitingGroup($windowId)['zones'];

        self::assertCount(2, $zones, 'both uncovered zones appear');
        self::assertSame(2, $zones[0]['orders_waiting'], 'busiest first');
        self::assertSame($this->zoneUncovered, $zones[0]['zone_id']);
        self::assertSame(1, $zones[1]['orders_waiting']);
        self::assertSame($this->zoneUncoveredSecond, $zones[1]['zone_id']);
    }

    /** Tenancy holds at the zone grain too. */
    public function test_the_zone_rollup_is_company_scoped(): void
    {
        [$windowId] = $this->windowWithGroup();
        $this->order('Helwan');
        $this->collect();

        self::assertCount(1, $this->awaitingGroup($windowId)['zones']);

        $other = Company::factory()->create();
        $this->actingAs(User::factory()->create(['company_id' => $other->id]))
            ->getJson(self::BASE."/windows/{$windowId}/awaiting-group")
            ->assertStatus(404);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function zone(string $name): int
    {
        return (int) DB::table('distribution_zones')->insertGetId([
            'code' => 'UZ-'.substr(uniqid(), -6),
            'name_ar' => $name, 'name_en' => $name,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function city(int $governorate, string $en, string $ar, int $zoneId): void
    {
        $id = (int) DB::table('logistics_cities')->insertGetId([
            'governorate_id' => $governorate,
            'name_ar' => $ar, 'name_en' => $en,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('logistics_cities')->where('id', $id)->update(['distribution_zone_id' => $zoneId]);
    }

    /** @param  Warehouse|null  $warehouse  explicit null = no warehouse assigned */
    private function order(?string $city, mixed $warehouse = false): Order
    {
        return Order::query()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-UZ-'.uniqid(),
            'order_date' => now()->toDateString(),
            'assigned_warehouse_id' => $warehouse === false
                ? $this->warehouse->id
                : ($warehouse instanceof Warehouse ? $warehouse->id : null),
            'city' => $city,
            'governorate' => 'Cairo',
            'status' => 'in_progress',
            'subtotal' => 100, 'total' => 100,
            'deposit_amount' => 0,
            'shipping_total' => 0, 'discount_total' => 0, 'tax_total' => 0,
        ]);
    }

    private function user(): User
    {
        return User::factory()->create(['company_id' => $this->company->id]);
    }

    private function collect(): void
    {
        $this->actingAs($this->user())->postJson(self::BASE.'/windows/collect')->assertOk();
    }

    /**
     * A Window holding a Group that covers `zoneCovered` only.
     *
     * @param  list<string>  $coveredOrders  one order per entry, all in the covered zone
     * @return array{0: string, 1: string} [windowId, slotId]
     */
    private function windowWithGroup(array $coveredOrders = ['Maadi']): array
    {
        foreach ($coveredOrders as $city) {
            $this->order($city);
        }

        $this->collect();

        $user = $this->user();
        $windowId = (string) $this->actingAs($user)
            ->getJson(self::BASE.'/windows/current?warehouse_id='.$this->warehouse->id)
            ->assertOk()->json('data.window.id');

        $slotId = (string) $this->actingAs($user)
            ->postJson(self::BASE."/windows/{$windowId}/slots", [
                'warehouse_id' => $this->warehouse->id,
                'code' => 'DG-UZ-'.substr(uniqid(), -5),
                'capacity_orders' => 50,
            ])->assertSuccessful()->json('data.id');

        $this->actingAs($user)
            ->postJson(self::BASE."/windows/{$windowId}/slots/{$slotId}/zones", [
                'zone_id' => $this->zoneCovered,
                'warehouse_id' => $this->warehouse->id,
            ])->assertSuccessful();

        return [$windowId, $slotId];
    }

    /** @return array<string, mixed> */
    private function awaitingGroup(string $windowId, ?string $warehouseId = null): array
    {
        $query = $warehouseId === null ? '' : '?warehouse_id='.$warehouseId;

        return $this->actingAs($this->user())
            ->getJson(self::BASE."/windows/{$windowId}/awaiting-group".$query)
            ->assertOk()
            ->json('data');
    }
}

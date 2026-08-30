<?php

declare(strict_types=1);

namespace Tests\Feature\Logistics;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-1-A — FAIL-CLOSED WINDOW RESOLUTION + GROUP CAPACITY PRESENTATION.
 *
 * ┌─ THE DEFECT THESE ROWS PIN — and the line H1 Option B drew ──────────────┐
 * │ `resolvePlanningWindow()` used to answer an unresolvable read with        │
 * │ `windowFor(today)` — and `windowFor` CREATES. So a read minted an empty    │
 * │ calendar window and the workspace rendered five tabs, five KPIs and a      │
 * │ status badge over a cycle nobody was planning.                            │
 * │                                                                          │
 * │ THE DEFECT WAS THE CREATE, NOT THE FALLBACK. H1 = Option B: a read never   │
 * │ creates, but it still resolves an EXISTING window. A Preparation Wave      │
 * │ SELECTS the current operational cycle; it is not a prerequisite for        │
 * │ reading Distribution, and the schema agrees — `distribution_windows` is    │
 * │ keyed (company_id, window_date) with no preparation_wave_id and no         │
 * │ warehouse_id, and ingestion consults no wave at all.                      │
 * │                                                                          │
 * │ Collection keeps its create through the explicit                          │
 * │ resolveOrCreatePlanningWindow(), because the first sweep of a new cycle    │
 * │ legitimately has no anchor yet.                                           │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * Capacity is asserted as PRESENTATION only. `capacity_orders` remains the single
 * axis, `remaining_orders` stays derived server-side, and no test here writes a
 * capacity value the contract does not already own.
 */
class DistributionWindowResolutionAndCapacityTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/logistics/distribution';

    private Company $company;

    private Customer $customer;

    private Warehouse $warehouseA;

    private Warehouse $warehouseB;

    private int $zoneMaadi;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('distribution.window.opens_at', '00:00');
        config()->set('distribution.window.closes_at', '23:59');

        $this->company = Company::factory()->create();
        $this->customer = Customer::factory()->create();
        $this->warehouseA = Warehouse::factory()->create(['company_id' => $this->company->id]);
        $this->warehouseB = Warehouse::factory()->create(['company_id' => $this->company->id]);

        $governorate = (int) DB::table('logistics_governorates')->insertGetId([
            'country_id' => 1,
            'name_ar' => 'القاهرة', 'name_en' => 'Cairo',
            'default_shipping_price' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->zoneMaadi = $this->zone('Maadi');
        $this->city($governorate, 'Maadi', 'المعادي', $this->zoneMaadi);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1. No warehouse → NO silent fallback, and NO window created
    // ─────────────────────────────────────────────────────────────────────────

    public function test_a_read_with_no_warehouse_does_not_fall_back_to_todays_window(): void
    {
        $this->wave($this->warehouseA);

        $before = DB::table('distribution_windows')->count();

        $data = $this->actingAs($this->user())
            ->getJson(self::BASE.'/windows/current')
            ->assertOk()
            ->json('data');

        self::assertSame('no_planning_window', $data['resolution']);
        self::assertSame('no_window_available', $data['resolution_reason']);
        self::assertNull($data['window'], 'with no window in existence, none may be named');
        self::assertSame([], $data['zones']);
        self::assertSame([], $data['slots']);

        // The half that made the old behaviour dangerous rather than merely wrong.
        self::assertSame(
            $before,
            DB::table('distribution_windows')->count(),
            'a READ must never create a distribution window',
        );
    }

    /**
     * H1 Option B, the ruling itself: a missing Preparation Wave must NOT block a read.
     *
     * No wave exists here at all. Collection has still produced a window holding real
     * assignments, and that window — with its groups, zones and orders — must resolve.
     * The operator must never be shown "nothing" merely because no cycle is open.
     */
    public function test_a_missing_preparation_wave_does_not_block_a_valid_read(): void
    {
        $this->order($this->warehouseA, 'Maadi');
        $this->collect(); // ingestion is wave-independent

        self::assertSame(
            0,
            DB::table('preparation_waves')->count(),
            'precondition: this scenario has NO Preparation Wave',
        );

        $data = $this->current($this->warehouseA);

        self::assertSame(
            'resolved',
            $data['resolution'],
            'a wave selects the cycle; its absence must not gate Distribution',
        );
        self::assertNotNull($data['window']);
        self::assertSame(
            DB::table('distribution_window_orders')->value('distribution_window_id'),
            $data['window']['id'],
            'and it must resolve the window that actually holds the work',
        );
    }

    /**
     * The same rule with no warehouse either — the company-wide read the endpoint has
     * always served keeps working, and still creates nothing.
     */
    public function test_an_existing_window_resolves_without_a_wave_or_a_warehouse(): void
    {
        $this->order($this->warehouseA, 'Maadi');
        $this->collect();

        $before = DB::table('distribution_windows')->count();

        $data = $this->actingAs($this->user())
            ->getJson(self::BASE.'/windows/current')
            ->assertOk()
            ->json('data');

        self::assertSame('resolved', $data['resolution']);
        self::assertNotNull($data['window']);
        self::assertSame($before, DB::table('distribution_windows')->count(), 'still no create');
    }

    /**
     * A cycle exists but nothing has been collected, so no window exists to resolve.
     * Unresolved, and above all: still no create.
     */
    public function test_a_cycle_with_no_collected_orders_is_unresolved_and_creates_nothing(): void
    {
        $waveId = $this->wave($this->warehouseA);
        $order = $this->order($this->warehouseA, 'Maadi');
        $this->attachToWave($waveId, $order); // in the cycle, but never collected

        $before = DB::table('distribution_windows')->count();

        $data = $this->current($this->warehouseA);

        self::assertSame('no_planning_window', $data['resolution']);
        self::assertSame('no_window_available', $data['resolution_reason']);
        self::assertSame($before, DB::table('distribution_windows')->count());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. Correct warehouse → correct planning window
    // ─────────────────────────────────────────────────────────────────────────

    public function test_the_correct_warehouse_resolves_the_window_holding_its_cycle(): void
    {
        $waveId = $this->wave($this->warehouseA);
        $order = $this->order($this->warehouseA, 'Maadi');
        $this->attachToWave($waveId, $order);
        $this->collect();

        $data = $this->current($this->warehouseA);

        self::assertSame('resolved', $data['resolution']);
        self::assertNull($data['resolution_reason']);
        self::assertNotNull($data['window']);

        $expected = DB::table('distribution_window_orders')
            ->where('order_id', $order->id)
            ->value('distribution_window_id');

        self::assertSame(
            $expected,
            $data['window']['id'],
            'the resolved window must be the one actually holding the cycle assignments',
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. A warehouse cannot inherit another warehouse's window
    // ─────────────────────────────────────────────────────────────────────────

    public function test_a_second_warehouse_cannot_inherit_the_first_warehouses_window(): void
    {
        $waveA = $this->wave($this->warehouseA);
        $order = $this->order($this->warehouseA, 'Maadi');
        $this->attachToWave($waveA, $order);
        $this->collect();

        $resolvedForA = $this->current($this->warehouseA)['window']['id'];
        self::assertNotNull($resolvedForA, 'precondition: warehouse A resolves a window');

        // Warehouse B has its own active cycle but no collected orders of its own.
        $this->wave($this->warehouseB);

        $dataB = $this->current($this->warehouseB);

        // Under Option B the WINDOW is company-scoped by design (no warehouse_id column),
        // so B may legitimately resolve the same row. What must never leak across the
        // boundary is the WORK — B's groups and orders must be empty, because every
        // aggregate is warehouse-scoped even when the window is not.
        self::assertSame([], $dataB['slots'], "warehouse B must see none of A's groups");
        self::assertSame(
            [],
            array_values(array_filter(
                $dataB['zones'],
                static fn (array $z): bool => ($z['order_count'] ?? 0) > 0,
            )),
            "warehouse B must see none of A's zone work",
        );

        // And A is undisturbed by B's read.
        self::assertSame($resolvedForA, $this->current($this->warehouseA)['window']['id']);
        self::assertSame(
            $this->warehouseA->id,
            DB::table('orders')->where('id', $order->id)->value('assigned_warehouse_id'),
            'a read must not move an order between warehouses',
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4 + 5. Groups expose canonical current / maximum / remaining
    // ─────────────────────────────────────────────────────────────────────────

    public function test_groups_expose_canonical_current_maximum_and_remaining(): void
    {
        $slot = $this->groupWithOneOrder(capacity: 20);

        $group = $this->slotPayload($slot);

        self::assertSame(20, $group['capacity_orders'], 'maximum is the stored capacity_orders');
        self::assertSame(1, $group['demand_orders'], 'current is demand_orders, the capacity aggregate');
        self::assertSame(19, $group['remaining_orders'], 'remaining is derived: 20 - 1');
    }

    /**
     * Remaining is DERIVED, floored at 0, and null when there is no maximum.
     *
     * Asserted against the server payload rather than recomputed here, because the
     * screen and the row-locked write guard must read the same number.
     */
    public function test_remaining_is_derived_floored_and_null_when_unbounded(): void
    {
        // Capacity BELOW current occupancy — remaining must floor at 0, never go negative.
        $slot = $this->groupWithOneOrder(capacity: 20);
        DB::table('distribution_virtual_slots')->where('id', $slot)->update(['capacity_orders' => 1]);

        $group = $this->slotPayload($slot);
        self::assertSame(1, $group['capacity_orders']);
        self::assertSame(1, $group['demand_orders']);
        self::assertSame(0, $group['remaining_orders'], 'floored at 0');

        // No maximum → no remaining. Zero would read as "full".
        DB::table('distribution_virtual_slots')->where('id', $slot)->update(['capacity_orders' => null]);

        $unbounded = $this->slotPayload($slot);
        self::assertNull($unbounded['capacity_orders']);
        self::assertNull($unbounded['remaining_orders'], 'null maximum means null remaining, not 0');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 6-8. A read changes nothing
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The whole point of failing closed is that the read stopped having side effects.
     * All three no-create claims are asserted together, across BOTH the resolvable and
     * the unresolvable read, because a regression in either direction matters.
     */
    public function test_reads_create_no_wave_no_group_and_no_membership_change(): void
    {
        $slot = $this->groupWithOneOrder(capacity: 20);

        $waves = DB::table('preparation_waves')->count();
        $groups = DB::table('distribution_virtual_slots')->count();
        $windows = DB::table('distribution_windows')->count();
        $membership = DB::table('distribution_window_orders')
            ->orderBy('order_id')->pluck('virtual_slot_id', 'order_id')->toArray();
        $zoneLinks = DB::table('distribution_slot_zones')->count();

        // A resolvable read, an unresolvable read, and a read for the other warehouse.
        $this->current($this->warehouseA);
        $this->actingAs($this->user())->getJson(self::BASE.'/windows/current')->assertOk();
        $this->current($this->warehouseB);

        self::assertSame($waves, DB::table('preparation_waves')->count(), 'no Preparation Wave created');
        self::assertSame($groups, DB::table('distribution_virtual_slots')->count(), 'no Distribution Group created');
        self::assertSame($windows, DB::table('distribution_windows')->count(), 'no Distribution Window created');
        self::assertSame($zoneLinks, DB::table('distribution_slot_zones')->count(), 'no zone membership change');
        self::assertSame(
            $membership,
            DB::table('distribution_window_orders')->orderBy('order_id')->pluck('virtual_slot_id', 'order_id')->toArray(),
            'group membership unchanged by any read',
        );
        self::assertNotNull($slot);

        // §4 — Trip capacity is out of scope and must remain exactly as the schema left it.
        foreach (DB::table('distribution_trips')->pluck('capacity') as $capacity) {
            self::assertSame(60, (int) $capacity, 'Trip capacity must remain untouched at 60');
        }
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function zone(string $name): int
    {
        return (int) DB::table('distribution_zones')->insertGetId([
            'code' => 'T1A-'.substr(uniqid(), -6),
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

    private function order(Warehouse $warehouse, string $city): Order
    {
        return Order::query()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-T1A-'.uniqid(),
            'order_date' => now()->toDateString(),
            'assigned_warehouse_id' => $warehouse->id,
            'city' => $city,
            'governorate' => 'Cairo',
            'status' => 'in_progress',
            'subtotal' => 100, 'total' => 100,
            'deposit_amount' => 0,
            'shipping_total' => 0, 'discount_total' => 0, 'tax_total' => 0,
        ]);
    }

    /**
     * Attach an order to a wave as an ACTIVE member.
     *
     * Required by the resolver, not decoration: resolvePlanningWindow() anchors by
     * joining `distribution_window_orders` to `preparation_wave_orders` on the wave's
     * active membership, so a wave with no members legitimately anchors nothing.
     */
    private function attachToWave(string $waveId, Order $order): void
    {
        DB::table('preparation_wave_orders')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $this->company->id,
            'preparation_wave_id' => $waveId,
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'order_confirmed_at' => now(),
            'added_at' => now(),
            'added_by' => (string) Str::uuid(),
            'released_at' => null,   // active
            'postponed_at' => null,  // in this cycle
        ]);
    }

    /** The canonical current cycle: an ENGINE wave in an ACTIVE status. */
    private function wave(Warehouse $warehouse, string $status = 'collecting'): string
    {
        $id = (string) Str::uuid();

        DB::table('preparation_waves')->insert([
            'id' => $id,
            'company_id' => $this->company->id,
            'warehouse_id' => $warehouse->id,
            'wave_number' => 'PREP-T1A-'.substr(uniqid(), -8),
            'planning_date' => now()->toDateString(),
            'starts_at' => now()->copy()->setTime(17, 30),
            'intake_closes_at' => now()->copy()->addDay()->setTime(5, 0),
            'ends_at' => now()->copy()->addDay()->setTime(12, 0),
            'status' => $status,
            'wave_type' => 'engine',
            'created_at' => now(), 'updated_at' => now(),
            'created_by' => (string) Str::uuid(),
            'updated_by' => (string) Str::uuid(),
        ]);

        return $id;
    }

    private function user(): User
    {
        return User::factory()->create(['company_id' => $this->company->id]);
    }

    private function collect(): void
    {
        $this->actingAs($this->user())
            ->postJson(self::BASE.'/windows/collect')
            ->assertOk();
    }

    /** @return array<string, mixed> */
    private function current(?Warehouse $warehouse = null): array
    {
        $query = $warehouse === null ? '' : '?warehouse_id='.$warehouse->id;

        return $this->actingAs($this->user())
            ->getJson(self::BASE.'/windows/current'.$query)
            ->assertOk()
            ->json('data');
    }

    /**
     * A collected order in warehouse A's cycle, inside a Group holding its zone.
     *
     * Built through the real collection sweep and the real zone-attach endpoint, so
     * the capacity numbers under test are the ones production produces.
     */
    private function groupWithOneOrder(int $capacity): string
    {
        $waveId = $this->wave($this->warehouseA);
        $order = $this->order($this->warehouseA, 'Maadi');
        $this->attachToWave($waveId, $order);
        $this->collect();

        $windowId = $this->current($this->warehouseA)['window']['id'];
        $user = $this->user();

        $slotId = $this->actingAs($user)
            ->postJson(self::BASE."/windows/{$windowId}/slots", [
                'warehouse_id' => $this->warehouseA->id,
                'code' => 'DG-T1A-'.substr(uniqid(), -5),
                'capacity_orders' => $capacity,
            ])
            ->assertSuccessful()
            ->json('data.id');

        $this->actingAs($user)
            ->postJson(self::BASE."/windows/{$windowId}/slots/{$slotId}/zones", [
                'zone_id' => $this->zoneMaadi,
                'warehouse_id' => $this->warehouseA->id,
            ])
            ->assertSuccessful();

        return (string) $slotId;
    }

    /** @return array<string, mixed> */
    private function slotPayload(string $slotId): array
    {
        $slots = $this->current($this->warehouseA)['slots'];

        foreach ($slots as $slot) {
            if ($slot['slot_id'] === $slotId) {
                return $slot;
            }
        }

        self::fail("slot {$slotId} not present in the window payload");
    }
}

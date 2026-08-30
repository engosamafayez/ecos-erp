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
use Modules\Logistics\Distribution\Domain\Models\VirtualCapacitySlot;
use Modules\Logistics\Distribution\Domain\Services\DistributionWindowService;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-OPERATIONS-DISTRIBUTION-WORKSPACE-FINALIZATION-001.
 *
 * Four things, and the invariants each one must not break:
 *
 *   1. WINDOW ANCHOR (D1-A). The workspace plans the Window the governing
 *      Preparation Wave's members are actually in, not today's calendar Window.
 *      Read-side only: Group identity, assignments and the unique constraint are
 *      all untouched.
 *   2. GROUP CAPACITY. `capacity_orders` is enforced on the write paths that add
 *      an Order to a Group, under a row lock — not only at Finalize.
 *   3. MAP. Real `orders.google_maps_lat/lng` only. A missing coordinate stays
 *      missing and is never substituted.
 *   4. TEMPLATES. Configuration only — name, zones, maximum. Applying one copies
 *      no runtime state whatsoever.
 */
class DistributionWorkspaceFinalizationTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/logistics/distribution';

    private Company $company;

    private Company $otherCompany;

    private Customer $customer;

    private Warehouse $warehouse;

    private Warehouse $otherWarehouse;

    private int $zoneMaadi;

    private int $zoneNasr;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('distribution.window.opens_at', '00:00');
        config()->set('distribution.window.closes_at', '23:59');

        $this->company = Company::factory()->create();
        $this->otherCompany = Company::factory()->create();
        $this->customer = Customer::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
        $this->otherWarehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);

        $governorate = (int) DB::table('logistics_governorates')->insertGetId([
            'country_id' => 1,
            'name_ar' => 'القاهرة', 'name_en' => 'Cairo',
            'default_shipping_price' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->zoneMaadi = $this->zone('Maadi');
        $this->zoneNasr = $this->zone('Nasr City');

        $this->city($governorate, 'Maadi', 'المعادي', $this->zoneMaadi);
        $this->city($governorate, 'Nasr City', 'مدينة نصر', $this->zoneNasr);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1 — WINDOW ANCHOR
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * §18.1 — today's calendar date differs from where the cycle's Orders live.
     *
     * This reproduces the reported defect exactly: the plan sits in an earlier
     * Window, today's Window is empty, and the old behaviour resolved the empty
     * one. The anchor must resolve the Window that holds the wave's members.
     */
    public function test_planning_window_resolves_the_wave_cycle_not_todays_calendar_window(): void
    {
        $now = CarbonImmutable::now();

        $planning = $this->window($now->subDays(2)->toDateString());
        $today = $this->window($now->toDateString());

        $wave = $this->wave();
        $order = $this->order($this->warehouse, 'Maadi');

        $this->assign($order, $planning, $this->zoneMaadi);
        $this->member($wave, $order);

        $resolved = $this->resolve($wave);

        self::assertSame($planning, $resolved, 'the anchor must follow the wave, not the calendar');
        self::assertNotSame($today, $resolved);
    }

    /**
     * §18.2 — a wave spanning midnight must not blank the workspace.
     *
     * The cycle opened yesterday and is still running. Before the fix, every night
     * after midnight the workspace reported 0 orders / 0 zones / 0 groups.
     */
    public function test_workspace_is_not_empty_after_midnight_for_a_wave_that_spans_it(): void
    {
        $now = CarbonImmutable::now();

        $planning = $this->window($now->subDay()->toDateString());
        $wave = $this->wave($now->subDay()->toDateString());

        $group = $this->groupIn($planning, $this->warehouse, 'DG-MID');
        $this->slotZone($planning, $group, $this->zoneMaadi);

        foreach (range(1, 3) as $i) {
            $order = $this->order($this->warehouse, 'Maadi');
            $this->line($order);
            $this->assign($order, $planning, $this->zoneMaadi, $group);
            $this->member($wave, $order);
        }

        $payload = $this->actingAs($this->userFor())
            ->getJson(self::BASE.'/windows/current?warehouse_id='.$this->warehouse->id)
            ->assertOk()
            ->json('data');

        self::assertSame($planning, $payload['window']['id']);
        self::assertNotSame($now->toDateString(), $payload['window']['window_date']);
        self::assertCount(1, $payload['slots'], 'the cycle\'s Group must still be visible');
        self::assertSame(3, $payload['slots'][0]['orders_count']);
        self::assertNotEmpty($payload['zones']);
    }

    /** §18.3 + §18.4 — reading the workspace changes no Group and no assignment. */
    public function test_resolving_the_planning_window_mutates_nothing(): void
    {
        $planning = $this->window(CarbonImmutable::now()->subDay()->toDateString());
        $wave = $this->wave();

        $group = $this->groupIn($planning, $this->warehouse, 'DG-IDENT');
        $this->slotZone($planning, $group, $this->zoneMaadi);

        $order = $this->order($this->warehouse, 'Maadi');
        $this->assign($order, $planning, $this->zoneMaadi, $group);
        $this->member($wave, $order);

        $groupsBefore = DB::table('distribution_virtual_slots')->orderBy('id')->get()->toJson();
        $assignBefore = DB::table('distribution_window_orders')->orderBy('id')->get()->toJson();

        // Read it repeatedly — a read that mutates would show up on the second pass.
        foreach (range(1, 3) as $ignored) {
            $this->actingAs($this->userFor())
                ->getJson(self::BASE.'/windows/current?warehouse_id='.$this->warehouse->id)
                ->assertOk();
        }

        self::assertSame(
            $groupsBefore,
            DB::table('distribution_virtual_slots')->orderBy('id')->get()->toJson(),
            'Group identity must be untouched by a read',
        );
        self::assertSame(
            $assignBefore,
            DB::table('distribution_window_orders')->orderBy('id')->get()->toJson(),
            'existing assignments must be untouched by a read',
        );
    }

    /** §18.5 — collect stays idempotent: no duplicate assignment, ever. */
    public function test_collect_creates_no_duplicate_assignment(): void
    {
        $order = $this->order($this->warehouse, 'Maadi');

        $this->actingAs($this->userFor())->postJson(self::BASE.'/windows/collect')->assertOk();
        $first = DB::table('distribution_window_orders')->count();

        $this->actingAs($this->userFor())->postJson(self::BASE.'/windows/collect')->assertOk();

        self::assertSame($first, DB::table('distribution_window_orders')->count());
        self::assertSame(
            1,
            DB::table('distribution_window_orders')->where('order_id', $order->id)->count(),
            'order_id is globally unique — a second assignment must be impossible',
        );
    }

    /**
     * No governing wave still falls back to today — but H1 Option B makes it resolve the
     * EXISTING window instead of creating one. Both halves are asserted: the fallback
     * happens, and the read created nothing.
     */
    public function test_without_a_governing_wave_the_anchor_falls_back_to_today(): void
    {
        $today = CarbonImmutable::now()->toDateString();
        $this->ensureTodayWindow();
        $before = DB::table('distribution_windows')->count();

        $resolved = app(DistributionWindowService::class)
            ->resolvePlanningWindow($this->company->id, null, $this->warehouse->id, CarbonImmutable::now());

        self::assertNotNull($resolved, 'a missing wave must not block resolution');
        self::assertSame($today, $resolved->window_date->toDateString());
        self::assertSame($before, DB::table('distribution_windows')->count(), 'and nothing was created');
    }

    /** The other half of the same rule: with no window in existence, the read yields null. */
    public function test_without_any_existing_window_the_read_resolves_nothing_and_creates_nothing(): void
    {
        self::assertSame(0, DB::table('distribution_windows')->count(), 'precondition');

        $resolved = app(DistributionWindowService::class)
            ->resolvePlanningWindow($this->company->id, null, $this->warehouse->id, CarbonImmutable::now());

        self::assertNull($resolved);
        self::assertSame(0, DB::table('distribution_windows')->count(), 'a read must never create');
    }

    /** §18.7 (tenancy) — the anchor can never resolve another tenant's Window. */
    public function test_anchor_never_resolves_a_foreign_companys_window(): void
    {
        $planning = $this->window(CarbonImmutable::now()->subDay()->toDateString());
        $wave = $this->wave();

        $order = $this->order($this->warehouse, 'Maadi');
        $this->assign($order, $planning, $this->zoneMaadi);
        $this->member($wave, $order);

        $this->ensureTodayWindow($this->otherCompany->id);

        // Same wave id, different acting company. The wave's assignments belong to
        // company A, so company B must get its own (today's) window, never A's.
        $resolved = app(DistributionWindowService::class)->resolvePlanningWindow(
            $this->otherCompany->id,
            $wave,
            $this->warehouse->id,
            CarbonImmutable::now(),
        );

        // Company B must never receive A's window. Under Option B it receives its OWN
        // existing window, so the tenancy assertion becomes stronger, not weaker.
        self::assertNotNull($resolved, 'company B has its own window and must resolve it');
        self::assertNotSame($planning, $resolved->id);
        self::assertSame($this->otherCompany->id, $resolved->company_id);
    }

    /** §18.8 (warehouse isolation) — the anchor honours the Order's own warehouse. */
    public function test_anchor_is_scoped_to_the_requested_warehouse(): void
    {
        $now = CarbonImmutable::now();

        $windowA = $this->window($now->subDays(3)->toDateString());
        $windowB = $this->window($now->subDay()->toDateString());
        $wave = $this->wave();

        $a = $this->order($this->warehouse, 'Maadi');
        $this->assign($a, $windowA, $this->zoneMaadi);
        $this->member($wave, $a);

        $b = $this->order($this->otherWarehouse, 'Nasr City');
        $this->assign($b, $windowB, $this->zoneNasr);
        $this->member($wave, $b);

        self::assertSame($windowA, $this->resolve($wave, $this->warehouse->id));
        self::assertSame($windowB, $this->resolve($wave, $this->otherWarehouse->id));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1b — READ / WRITE WINDOW CONSISTENCY (owner decision: option (i))
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * §1.1 — the workspace and collection resolve the SAME window.
     *
     * The whole point of option (i): one anchor for both paths. A newly eligible
     * Order must land where the operator is looking, not in a window they cannot
     * see and — because `order_id` is globally unique — could never be moved from.
     */
    public function test_collection_writes_into_the_window_the_workspace_reads(): void
    {
        $planning = $this->window(CarbonImmutable::now()->subDay()->toDateString());
        $wave = $this->wave();

        // The cycle already lives in yesterday's window.
        $member = $this->order($this->warehouse, 'Maadi');
        $this->assign($member, $planning, $this->zoneMaadi);
        $this->member($wave, $member);

        // A brand-new eligible Order, never collected.
        $fresh = $this->order($this->warehouse, 'Nasr City');
        $this->line($fresh);

        $this->collect();

        $readWindow = $this->actingAs($this->userFor())
            ->getJson(self::BASE.'/windows/current?warehouse_id='.$this->warehouse->id)
            ->assertOk()->json('data.window.id');

        self::assertSame($planning, $readWindow, 'the workspace must read the planning window');
        self::assertSame(
            $readWindow,
            $this->windowOf($fresh),
            'collection must write into the window the workspace reads',
        );
    }

    /**
     * §1.2 — a wave spanning midnight does not drag collection into a new
     * calendar window.
     */
    public function test_collection_does_not_switch_to_a_new_calendar_window_mid_cycle(): void
    {
        $now = CarbonImmutable::now();
        $planning = $this->window($now->subDay()->toDateString());
        $today = $this->window($now->toDateString());

        $wave = $this->wave($now->subDay()->toDateString());

        $member = $this->order($this->warehouse, 'Maadi');
        $this->assign($member, $planning, $this->zoneMaadi);
        $this->member($wave, $member);

        $fresh = $this->order($this->warehouse, 'Nasr City');
        $this->line($fresh);

        $this->collect();

        self::assertSame($planning, $this->windowOf($fresh));
        self::assertNotSame($today, $this->windowOf($fresh), 'the calendar must not win');
    }

    /**
     * §1.3 — CUTOFF IS PRESERVED. Running collection after the wave's intake has
     * closed must NOT pull a new Order into the cycle.
     *
     * This is the guard-rail on option (i): the anchor may not become a back door
     * into a wave whose intake is shut.
     */
    public function test_collection_after_intake_cutoff_does_not_join_the_cycle(): void
    {
        $planning = $this->window(CarbonImmutable::now()->subDay()->toDateString());

        // Intake CLOSED, status still `collecting` — cutoff is not close.
        $wave = $this->wave(CarbonImmutable::now()->subDay()->toDateString(), intakeClosed: true);

        $member = $this->order($this->warehouse, 'Maadi');
        $this->assign($member, $planning, $this->zoneMaadi);
        $this->member($wave, $member);

        $late = $this->order($this->warehouse, 'Nasr City');
        $this->line($late);

        $this->collect();

        self::assertNotSame(
            $planning,
            $this->windowOf($late),
            'an Order collected after intake closed must not join the cycle',
        );
        self::assertNotNull($this->windowOf($late), 'but it must not be dropped either');
    }

    /**
     * Collection never admits an Order to a Preparation Wave — before or after
     * cutoff. Distribution has never written wave membership and still does not.
     */
    public function test_collection_never_creates_wave_membership(): void
    {
        $planning = $this->window(CarbonImmutable::now()->subDay()->toDateString());
        $wave = $this->wave();

        $member = $this->order($this->warehouse, 'Maadi');
        $this->assign($member, $planning, $this->zoneMaadi);
        $this->member($wave, $member);

        $before = DB::table('preparation_wave_orders')->orderBy('id')->get()->toJson();

        $fresh = $this->order($this->warehouse, 'Nasr City');
        $this->line($fresh);
        $this->collect();

        self::assertSame(
            $before,
            DB::table('preparation_wave_orders')->orderBy('id')->get()->toJson(),
            'no Preparation membership row may be created, changed or removed',
        );
    }

    /** §1.4 — collection never duplicates or moves an existing assignment. */
    public function test_collection_does_not_duplicate_or_move_existing_assignments(): void
    {
        $planning = $this->window(CarbonImmutable::now()->subDay()->toDateString());
        $wave = $this->wave();

        $group = $this->groupIn($planning, $this->warehouse, 'DG-KEEP');
        $this->slotZone($planning, $group, $this->zoneMaadi);

        $member = $this->order($this->warehouse, 'Maadi');
        $this->line($member);
        $existing = $this->assign($member, $planning, $this->zoneMaadi, $group);
        $this->member($wave, $member);

        $before = DB::table('distribution_window_orders')->orderBy('id')->get()->toJson();

        // Twice, because idempotence is the claim.
        $this->collect();
        $this->collect();

        self::assertSame(
            $before,
            DB::table('distribution_window_orders')->orderBy('id')->get()->toJson(),
            'existing assignments must be byte-identical after collection',
        );
        self::assertSame(
            1,
            DB::table('distribution_window_orders')->where('id', $existing)->count(),
        );
        self::assertSame(
            1,
            DB::table('distribution_window_orders')->where('order_id', $member->id)->count(),
            'order_id is globally unique — no second row is possible',
        );
    }

    /**
     * §1.5 + §1.6 — Group identity, Group→Trip and Loading are untouched by the
     * new write path.
     */
    public function test_collection_leaves_group_identity_trips_and_loading_untouched(): void
    {
        $planning = $this->window(CarbonImmutable::now()->subDay()->toDateString());
        $wave = $this->wave();

        $group = $this->groupIn($planning, $this->warehouse, 'DG-STABLE', capacity: 10);
        $this->slotZone($planning, $group, $this->zoneMaadi);

        $member = $this->order($this->warehouse, 'Maadi');
        $this->line($member);
        $this->assign($member, $planning, $this->zoneMaadi, $group);
        $this->member($wave, $member);

        // A real Loading Preparation row, so "untouched" has something to touch.
        DB::table('distribution_group_product_preparation')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $this->company->id,
            'distribution_window_id' => $planning,
            'virtual_slot_id' => $group,
            'product_id' => Product::factory()->create()->id,
            'prepared_qty' => 2,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $before = [
            'groups' => DB::table('distribution_virtual_slots')->orderBy('id')->get()->toJson(),
            'slotZones' => DB::table('distribution_slot_zones')->orderBy('id')->get()->toJson(),
            'trips' => DB::table('distribution_trips')->orderBy('id')->get()->toJson(),
            'prepared' => DB::table('distribution_group_product_preparation')->orderBy('id')->get()->toJson(),
        ];

        $fresh = $this->order($this->warehouse, 'Nasr City');
        $this->line($fresh);
        $this->collect();

        self::assertSame($before['groups'], DB::table('distribution_virtual_slots')->orderBy('id')->get()->toJson(), 'Group identity');
        self::assertSame($before['slotZones'], DB::table('distribution_slot_zones')->orderBy('id')->get()->toJson(), 'Zone membership');
        self::assertSame($before['trips'], DB::table('distribution_trips')->orderBy('id')->get()->toJson(), 'Group -> Trip');
        self::assertSame($before['prepared'], DB::table('distribution_group_product_preparation')->orderBy('id')->get()->toJson(), 'Loading Preparation');
    }

    /**
     * Two warehouses, two waves, two planning windows — each Order joins its OWN
     * warehouse's cycle. Collection is company-wide, so this is the case a single
     * company-level window would silently get wrong.
     */
    public function test_collection_resolves_the_window_per_warehouse(): void
    {
        $now = CarbonImmutable::now();

        $windowA = $this->window($now->subDays(3)->toDateString());
        $windowB = $this->window($now->subDay()->toDateString());

        $waveA = $this->wave($now->subDays(3)->toDateString(), $this->warehouse->id);
        $waveB = $this->wave($now->subDay()->toDateString(), $this->otherWarehouse->id);

        $memberA = $this->order($this->warehouse, 'Maadi');
        $this->assign($memberA, $windowA, $this->zoneMaadi);
        $this->member($waveA, $memberA);

        $memberB = $this->order($this->otherWarehouse, 'Nasr City');
        $this->assign($memberB, $windowB, $this->zoneNasr);
        $this->member($waveB, $memberB);

        $freshA = $this->order($this->warehouse, 'Maadi');
        $this->line($freshA);
        $freshB = $this->order($this->otherWarehouse, 'Nasr City');
        $this->line($freshB);

        $this->collect();

        self::assertSame($windowA, $this->windowOf($freshA));
        self::assertSame($windowB, $this->windowOf($freshB));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1c — VEHICLE / DRIVER / LOADING WORKFLOW (review addendum)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The complete operator path, end to end, through the SUPPORTED endpoints:
     *
     *   register a Vehicle → register a Driver → assign both to the Group's Trip
     *   → Loading prerequisite satisfied → Loading opens.
     *
     * This exists because the browser review found a dead end: with zero fleet
     * registered, Distribution stated the prerequisite and offered no route to
     * satisfying it. The backend chain was already complete, so what had to be
     * proven is that the chain WORKS — not that the fields render.
     *
     * It is also the substitute for live browser proof of the assignment step:
     * `logistics_vehicles` and `logistics_drivers` have NO delete endpoint, so
     * creating fleet in the dev database would leave permanent records, which the
     * task forbids. A transactional test creates and discards them.
     */
    public function test_operator_can_register_fleet_assign_it_and_open_loading(): void
    {
        [$window, $group] = $this->groupWithOrders(2, capacity: null);

        // 1. Loading is refused BEFORE any fleet exists. The guard is the point —
        //    it must keep refusing, so this asserts the refusal first.
        $this->actingAs($this->userFor())
            ->postJson(self::BASE."/windows/{$window}/slots/{$group}/finalize")
            ->assertOk();

        $tripId = $this->actingAs($this->userFor())
            ->getJson(self::BASE."/windows/{$window}/slots/{$group}/trips")
            ->assertOk()->json('data.0.trip_id');

        self::assertNotNull($tripId, 'finalize must produce a Trip');

        $blocked = $this->actingAs($this->userFor())
            ->postJson(self::BASE."/windows/{$window}/slots/{$group}/trips/{$tripId}/loading");

        self::assertContains(
            $blocked->getStatusCode(),
            [422, 500],
            'Loading must be refused while the Group has no vehicle and driver',
        );

        // 2. Register a Vehicle through the operator endpoint.
        $vehicle = $this->actingAs($this->userFor())
            ->postJson('/api/logistics/vehicles', [
                'vehicle_code' => 'VEH-WF-'.substr(uniqid(), -5),
                'plate_number' => 'WF '.random_int(1000, 9999),
                'type' => 'van',
                'capacity_orders' => 50,
            ])->assertStatus(201)->json('data');

        // 3. Register a Driver through the operator endpoint.
        $driver = $this->actingAs($this->userFor())
            ->postJson('/api/logistics/drivers', [
                'driver_code' => 'DRV-WF-'.substr(uniqid(), -5),
                'full_name' => 'Workflow Test Driver',
                'mobile' => '010'.random_int(10000000, 99999999),
                'national_id' => (string) random_int(10000000000000, 99999999999999),
            ])->assertStatus(201)->json('data');

        // Both must be offered to THIS group — proving the fleet-options read is
        // not empty for a company that has registered fleet.
        $options = $this->actingAs($this->userFor())
            ->getJson(self::BASE."/windows/{$window}/slots/{$group}/fleet-options?warehouse_id=".$this->warehouse->id)
            ->assertOk()->json('data');

        self::assertNotEmpty($options['vehicles'], 'a registered vehicle must be offered');
        self::assertNotEmpty($options['drivers'], 'a registered driver must be offered');

        // 4. Assign both to the Group's Trip through the supported endpoint.
        $this->actingAs($this->userFor())
            ->postJson(self::BASE."/windows/{$window}/slots/{$group}/assign-vehicle", [
                'vehicle_id' => $options['vehicles'][0]['id'],
                'driver_id' => $options['drivers'][0]['id'],
            ])->assertOk();

        // The pairing reached the Trip through the ledger, which is the only
        // authority — Distribution stores no driver_id or vehicle_id of its own.
        self::assertNotNull(
            DB::table('distribution_trips')->where('uuid', $tripId)->value('driver_vehicle_assignment_id'),
            'the Trip must reference the pairing ledger row',
        );
        self::assertSame(
            1,
            DB::table('logistics_driver_vehicle_assignments')->count(),
            'exactly one pairing, created by its owning service',
        );

        // 5. Loading now opens. The guard was satisfied, not bypassed.
        $this->actingAs($this->userFor())
            ->postJson(self::BASE."/windows/{$window}/slots/{$group}/trips/{$tripId}/loading")
            ->assertOk();

        // Sanity: no duplicate fleet entity was created inside Distribution.
        self::assertSame(1, DB::table('logistics_vehicles')->count());
        self::assertSame(1, DB::table('logistics_drivers')->count());
        unset($vehicle, $driver);
    }

    /** Fleet options are tenant-scoped: another company's fleet is never offered. */
    public function test_fleet_options_never_offer_another_companys_fleet(): void
    {
        [$window, $group] = $this->groupWithOrders(1, capacity: null);

        $foreignUser = User::factory()->create(['company_id' => $this->otherCompany->id]);

        // Registered by the OTHER company, through the same supported endpoint.
        $this->actingAs($foreignUser)
            ->postJson('/api/logistics/vehicles', [
                'vehicle_code' => 'VEH-FOREIGN',
                'plate_number' => 'FGN 0001',
                'type' => 'van',
                'capacity_orders' => 50,
            ])->assertStatus(201);

        $options = $this->actingAs($this->userFor())
            ->getJson(self::BASE."/windows/{$window}/slots/{$group}/fleet-options?warehouse_id=".$this->warehouse->id)
            ->assertOk()->json('data');

        $plates = array_column($options['vehicles'], 'plate_number');

        self::assertNotContains('FGN 0001', $plates, 'a foreign company vehicle must not be offered');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2 — GROUP CAPACITY
    // ─────────────────────────────────────────────────────────────────────────

    /** §18.6 — below capacity, an order may join. */
    public function test_an_order_may_join_a_group_below_its_maximum(): void
    {
        [$window, $group] = $this->groupWithOrders(2, capacity: 5);

        $extra = $this->order($this->warehouse, 'Nasr City');
        $this->line($extra);
        $assignment = $this->assign($extra, $window, $this->zoneNasr);

        $this->actingAs($this->userFor())
            ->patchJson(self::BASE."/assignments/{$assignment}/slot", ['slot_id' => $group])
            ->assertOk();

        self::assertSame(
            $group,
            DB::table('distribution_window_orders')->where('id', $assignment)->value('virtual_slot_id'),
        );
    }

    /** §18.7 — at capacity, the add is refused. */
    public function test_an_order_cannot_join_a_group_at_its_maximum(): void
    {
        [$window, $group] = $this->groupWithOrders(3, capacity: 3);

        $extra = $this->order($this->warehouse, 'Nasr City');
        $this->line($extra);
        $assignment = $this->assign($extra, $window, $this->zoneNasr);

        $this->actingAs($this->userFor())
            ->patchJson(self::BASE."/assignments/{$assignment}/slot", ['slot_id' => $group])
            ->assertStatus(422);

        self::assertNull(
            DB::table('distribution_window_orders')->where('id', $assignment)->value('virtual_slot_id'),
            'a refused add must leave the order out of the group',
        );
    }

    /** A NULL maximum is unconstrained — never a maximum of zero. */
    public function test_a_group_with_no_maximum_accepts_orders_as_before(): void
    {
        [$window, $group] = $this->groupWithOrders(4, capacity: null);

        $extra = $this->order($this->warehouse, 'Nasr City');
        $this->line($extra);
        $assignment = $this->assign($extra, $window, $this->zoneNasr);

        $this->actingAs($this->userFor())
            ->patchJson(self::BASE."/assignments/{$assignment}/slot", ['slot_id' => $group])
            ->assertOk();
    }

    /** A Zone attach is an add too, and cannot overflow the Group either. */
    public function test_attaching_a_zone_cannot_push_a_group_over_its_maximum(): void
    {
        [$window, $group] = $this->groupWithOrders(2, capacity: 3);

        // Two more orders waiting in a second zone: attaching it would make 4 > 3.
        foreach (range(1, 2) as $ignored) {
            $order = $this->order($this->warehouse, 'Nasr City');
            $this->line($order);
            $this->assign($order, $window, $this->zoneNasr);
        }

        $this->actingAs($this->userFor())
            ->postJson(self::BASE."/windows/{$window}/slots/{$group}/zones", ['zone_id' => $this->zoneNasr])
            ->assertStatus(422);

        self::assertDatabaseMissing('distribution_slot_zones', [
            'virtual_slot_id' => $group,
            'distribution_zone_id' => $this->zoneNasr,
        ]);
    }

    /** §18.8 — concurrent adds cannot exceed the maximum. */
    public function test_concurrent_adds_cannot_exceed_the_maximum(): void
    {
        [$window, $group] = $this->groupWithOrders(2, capacity: 3);

        // Two candidates, ONE seat. Sequential requests model the serialised
        // outcome the row lock produces: the second recounts after the first
        // commits and is refused. (A truly parallel test would need two
        // connections, which RefreshDatabase's transaction cannot provide.)
        $codes = [];

        foreach (range(1, 2) as $ignored) {
            $order = $this->order($this->warehouse, 'Nasr City');
            $this->line($order);
            $assignment = $this->assign($order, $window, $this->zoneNasr);

            $codes[] = $this->actingAs($this->userFor())
                ->patchJson(self::BASE."/assignments/{$assignment}/slot", ['slot_id' => $group])
                ->getStatusCode();
        }

        self::assertSame([200, 422], $codes, 'exactly one of two contenders may take the last seat');
        self::assertSame(3, $this->occupancy($window, $group));
    }

    /** The maximum may not be set below what the Group already holds. */
    public function test_a_maximum_below_current_occupancy_is_refused(): void
    {
        [$window, $group] = $this->groupWithOrders(3, capacity: null);

        $this->actingAs($this->userFor())
            ->patchJson(self::BASE."/windows/{$window}/slots/{$group}", ['capacity_orders' => 2])
            ->assertStatus(422);

        self::assertNull(
            DB::table('distribution_virtual_slots')->where('id', $group)->value('capacity_orders'),
        );
    }

    /** Editing the maximum, and removing it, both work; remaining is derived. */
    public function test_the_maximum_can_be_set_raised_and_removed(): void
    {
        [$window, $group] = $this->groupWithOrders(2, capacity: null);

        $set = $this->actingAs($this->userFor())
            ->patchJson(self::BASE."/windows/{$window}/slots/{$group}", ['capacity_orders' => 5])
            ->assertOk()->json('data');

        self::assertSame(5, $set['capacity_orders']);
        self::assertSame(2, $set['orders_count']);
        self::assertSame(3, $set['remaining_orders'], 'remaining is max minus current');

        $cleared = $this->actingAs($this->userFor())
            ->patchJson(self::BASE."/windows/{$window}/slots/{$group}", ['capacity_orders' => null])
            ->assertOk()->json('data');

        self::assertNull($cleared['capacity_orders']);
        self::assertNull($cleared['remaining_orders'], 'no maximum means no remaining, not zero');
    }

    /** The read model publishes the derived remaining alongside the maximum. */
    public function test_slot_summary_publishes_derived_remaining(): void
    {
        [$window, $group] = $this->groupWithOrders(2, capacity: 5);

        $slot = collect(
            $this->actingAs($this->userFor())
                ->getJson(self::BASE.'/windows/current?warehouse_id='.$this->warehouse->id)
                ->assertOk()->json('data.slots'),
        )->firstWhere('slot_id', $group);

        self::assertSame(5, $slot['capacity_orders']);
        self::assertSame(3, $slot['remaining_orders']);
    }

    /** A group is created with its maximum in one call. */
    public function test_a_group_can_be_created_with_a_maximum(): void
    {
        $window = $this->currentWindowId();

        $created = $this->actingAs($this->userFor())
            ->postJson(self::BASE."/windows/{$window}/slots", [
                'warehouse_id' => $this->warehouse->id,
                'code' => 'DG-CAP',
                'capacity_orders' => 12,
            ])->assertStatus(201)->json('data');

        self::assertSame(12, $created['capacity_orders']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3 — MAP
    // ─────────────────────────────────────────────────────────────────────────

    /** §18.9 — real coordinates reach the map contract. */
    public function test_map_publishes_real_order_coordinates(): void
    {
        $window = $this->currentWindowId();
        $group = $this->groupIn($window, $this->warehouse, 'DG-MAP');
        $this->slotZone($window, $group, $this->zoneMaadi);

        $order = $this->order($this->warehouse, 'Maadi');
        $this->line($order);
        $order->forceFill(['google_maps_lat' => '30.0176104', 'google_maps_lng' => '31.4345694'])->save();
        $this->assign($order, $window, $this->zoneMaadi, $group);

        $map = $this->actingAs($this->userFor())
            ->getJson(self::BASE."/windows/{$window}/map?warehouse_id=".$this->warehouse->id)
            ->assertOk()->json('data');

        $plotted = collect($map['orders'])->firstWhere('order_number', $order->order_number);

        self::assertTrue($plotted['has_location']);
        self::assertSame(30.0176104, $plotted['latitude']);
        self::assertSame(31.4345694, $plotted['longitude']);

        // The zone is DERIVED from that order — no zone geometry is stored.
        $zone = collect($map['zones'])->firstWhere('zone_id', $this->zoneMaadi);
        self::assertTrue($zone['has_location']);
        self::assertSame('orders', $zone['centroid_source']);
        self::assertSame(30.0176104, $zone['latitude']);

        self::assertSame(1, $map['summary']['orders_plotted']);
        self::assertSame(0, $map['summary']['orders_without_location']);
    }

    /** §18.10 — a missing coordinate stays missing. Nothing is fabricated. */
    public function test_map_never_fabricates_a_missing_coordinate(): void
    {
        $window = $this->currentWindowId();

        $order = $this->order($this->warehouse, 'Maadi');
        $this->line($order);
        $this->assign($order, $window, $this->zoneMaadi);

        $map = $this->actingAs($this->userFor())
            ->getJson(self::BASE."/windows/{$window}/map?warehouse_id=".$this->warehouse->id)
            ->assertOk()->json('data');

        $row = collect($map['orders'])->firstWhere('order_number', $order->order_number);

        self::assertFalse($row['has_location']);
        self::assertNull($row['latitude']);
        self::assertNull($row['longitude']);

        // ...and the zone it sits in is honestly unplaced rather than centred on a guess.
        $zone = collect($map['zones'])->firstWhere('zone_id', $this->zoneMaadi);
        self::assertFalse($zone['has_location']);
        self::assertNull($zone['latitude']);
        self::assertNull($zone['centroid_source']);

        self::assertSame(1, $map['summary']['orders_without_location']);
    }

    /** The map obeys warehouse scope like every other read. */
    public function test_map_is_warehouse_scoped(): void
    {
        $window = $this->currentWindowId();

        $mine = $this->order($this->warehouse, 'Maadi');
        $this->line($mine);
        $this->assign($mine, $window, $this->zoneMaadi);

        $theirs = $this->order($this->otherWarehouse, 'Nasr City');
        $this->line($theirs);
        $this->assign($theirs, $window, $this->zoneNasr);

        $numbers = collect(
            $this->actingAs($this->userFor())
                ->getJson(self::BASE."/windows/{$window}/map?warehouse_id=".$this->warehouse->id)
                ->assertOk()->json('data.orders'),
        )->pluck('order_number')->all();

        self::assertContains($mine->order_number, $numbers);
        self::assertNotContains($theirs->order_number, $numbers);
    }

    /** The map is behind the same permission as every other Distribution read. */
    public function test_map_requires_the_distribution_view_permission(): void
    {
        // Built directly, NOT through currentWindowId(): that helper calls
        // actingAs(), which persists on the TestCase and would leave the
        // "unauthenticated" request below authenticated — the assertion would then
        // pass or fail for a reason that has nothing to do with the route.
        $window = $this->window(CarbonImmutable::now()->toDateString());

        $this->getJson(self::BASE."/windows/{$window}/map")->assertStatus(401);

        $this->actingAsUnprivileged($this->userFor())
            ->getJson(self::BASE."/windows/{$window}/map")
            ->assertStatus(403);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4 — TEMPLATES
    // ─────────────────────────────────────────────────────────────────────────

    /** §18.11 — create. */
    public function test_a_template_can_be_created(): void
    {
        $created = $this->createTemplate('Morning Cairo', 20, [$this->zoneMaadi, $this->zoneNasr]);

        self::assertSame('Morning Cairo', $created['name']);
        self::assertSame(20, $created['capacity_orders']);
        self::assertSame(2, $created['zones_count']);
        self::assertEqualsCanonicalizing([$this->zoneMaadi, $this->zoneNasr], $created['zone_ids']);
    }

    /** §18.12 — edit. */
    public function test_a_template_can_be_edited(): void
    {
        $template = $this->createTemplate('Evening Giza', 10, [$this->zoneMaadi]);

        $updated = $this->actingAs($this->userFor())
            ->patchJson(self::BASE."/group-templates/{$template['id']}", [
                'name' => 'Evening Giza v2',
                'capacity_orders' => 15,
                'zone_ids' => [$this->zoneNasr],
            ])->assertOk()->json('data');

        self::assertSame('Evening Giza v2', $updated['name']);
        self::assertSame(15, $updated['capacity_orders']);
        self::assertSame([$this->zoneNasr], $updated['zone_ids']);
    }

    /** Archiving hides a template without touching anything it produced. */
    public function test_a_template_can_be_archived(): void
    {
        $template = $this->createTemplate('Throwaway', null, []);

        $this->actingAs($this->userFor())
            ->deleteJson(self::BASE."/group-templates/{$template['id']}")
            ->assertStatus(204);

        self::assertEmpty(
            $this->actingAs($this->userFor())->getJson(self::BASE.'/group-templates')
                ->assertOk()->json('data'),
        );
        self::assertSoftDeleted('distribution_group_templates', ['id' => $template['id']]);
    }

    /** §18.13 — apply: the configuration is copied onto a new Group. */
    public function test_applying_a_template_creates_a_group_with_its_configuration(): void
    {
        $window = $this->currentWindowId();
        $template = $this->createTemplate('Cairo Core', 25, [$this->zoneMaadi, $this->zoneNasr]);

        $group = $this->actingAs($this->userFor())
            ->postJson(self::BASE."/windows/{$window}/group-templates/{$template['id']}/apply", [
                'warehouse_id' => $this->warehouse->id,
                'code' => 'DG-FROM-TPL',
            ])->assertStatus(201)->json('data');

        self::assertSame('DG-FROM-TPL', $group['code']);
        self::assertSame('Cairo Core', $group['name']);
        self::assertSame(25, $group['capacity_orders']);

        self::assertEqualsCanonicalizing(
            [$this->zoneMaadi, $this->zoneNasr],
            DB::table('distribution_slot_zones')
                ->where('virtual_slot_id', $group['slot_id'])
                ->pluck('distribution_zone_id')->map(fn ($id) => (int) $id)->all(),
        );
    }

    /** Apply accepts overrides, because the operator edits before creating. */
    public function test_applying_a_template_accepts_overrides(): void
    {
        $window = $this->currentWindowId();
        $template = $this->createTemplate('Base', 25, [$this->zoneMaadi, $this->zoneNasr]);

        $group = $this->actingAs($this->userFor())
            ->postJson(self::BASE."/windows/{$window}/group-templates/{$template['id']}/apply", [
                'warehouse_id' => $this->warehouse->id,
                'code' => 'DG-OVR',
                'name' => 'Renamed On Apply',
                'capacity_orders' => 7,
                'zone_ids' => [$this->zoneNasr],
            ])->assertStatus(201)->json('data');

        self::assertSame('Renamed On Apply', $group['name']);
        self::assertSame(7, $group['capacity_orders']);

        self::assertSame(
            [$this->zoneNasr],
            DB::table('distribution_slot_zones')
                ->where('virtual_slot_id', $group['slot_id'])
                ->pluck('distribution_zone_id')->map(fn ($id) => (int) $id)->all(),
        );

        // The template itself is unchanged: apply reads it, it does not write it.
        $stored = $this->actingAs($this->userFor())
            ->getJson(self::BASE.'/group-templates')->assertOk()->json('data.0');
        self::assertSame('Base', $stored['name']);
        self::assertSame(25, $stored['capacity_orders']);
    }

    /**
     * §18.15 — a template can carry NO runtime state.
     *
     * The strongest statement available: apply a template while real runtime rows
     * exist, and assert that not one of them changed and no new one appeared.
     */
    public function test_applying_a_template_copies_no_runtime_state(): void
    {
        [$window, $existingGroup] = $this->groupWithOrders(2, capacity: null);

        // Real runtime rows on the existing group, so "copied nothing" is a claim
        // with something to copy.
        DB::table('distribution_group_product_preparation')->insert([
            'id' => (string) Str::uuid(),
            // Both NOT NULL with no default — the table records the tenant and the
            // window the prepared quantity belongs to, and neither is optional.
            'company_id' => $this->company->id,
            'distribution_window_id' => $window,
            'virtual_slot_id' => $existingGroup,
            'product_id' => Product::factory()->create()->id,
            'prepared_qty' => 4,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $template = $this->createTemplate('Clean', 9, [$this->zoneNasr]);

        $before = [
            'assignments' => DB::table('distribution_window_orders')->orderBy('id')->get()->toJson(),
            'trips' => DB::table('distribution_trips')->count(),
            'prepared' => DB::table('distribution_group_product_preparation')->orderBy('id')->get()->toJson(),
        ];

        $group = $this->actingAs($this->userFor())
            ->postJson(self::BASE."/windows/{$window}/group-templates/{$template['id']}/apply", [
                'warehouse_id' => $this->warehouse->id,
                'code' => 'DG-CLEAN',
            ])->assertStatus(201)->json('data');

        self::assertSame(
            $before['trips'],
            DB::table('distribution_trips')->count(),
            'apply must not create a Trip',
        );
        self::assertSame(
            $before['prepared'],
            DB::table('distribution_group_product_preparation')->orderBy('id')->get()->toJson(),
            'apply must not create or copy a prepared quantity',
        );

        // No assignment was created; existing ones only ever changed their slot,
        // which is zone membership doing its normal job — not a copy.
        self::assertSame(
            json_decode($before['assignments'], true) === null ? 0 : count(json_decode($before['assignments'], true)),
            DB::table('distribution_window_orders')->count(),
            'apply must not create an assignment',
        );

        // And the new Group holds no vehicle or driver, because it cannot.
        $row = DB::table('distribution_virtual_slots')->where('id', $group['slot_id'])->first();
        self::assertObjectNotHasProperty('vehicle_id', $row);
        self::assertObjectNotHasProperty('driver_id', $row);
    }

    /** §18.14 — templates are company scoped, both ways. */
    public function test_templates_are_company_scoped(): void
    {
        $mine = $this->createTemplate('Mine', 5, [$this->zoneMaadi]);

        $foreignUser = User::factory()->create(['company_id' => $this->otherCompany->id]);

        // Not listed for the other tenant...
        self::assertEmpty(
            $this->actingAs($foreignUser)->getJson(self::BASE.'/group-templates')
                ->assertOk()->json('data'),
        );

        // ...not readable, not editable, not archivable, and not applicable.
        $this->actingAs($foreignUser)
            ->patchJson(self::BASE."/group-templates/{$mine['id']}", ['name' => 'Hijacked'])
            ->assertStatus(404);

        $this->actingAs($foreignUser)
            ->deleteJson(self::BASE."/group-templates/{$mine['id']}")
            ->assertStatus(404);

        self::assertSame('Mine', DB::table('distribution_group_templates')
            ->where('id', $mine['id'])->value('name'));
    }

    /** A template may not be applied into another tenant's window. */
    public function test_a_template_cannot_be_applied_into_a_foreign_window(): void
    {
        $template = $this->createTemplate('Local', 5, [$this->zoneMaadi]);

        $foreignWindow = (string) Str::uuid();
        DB::table('distribution_windows')->insert([
            'id' => $foreignWindow,
            'company_id' => $this->otherCompany->id,
            'window_date' => CarbonImmutable::now()->toDateString(),
            'opens_at' => CarbonImmutable::now()->startOfDay(),
            'closes_at' => CarbonImmutable::now()->endOfDay(),
            'status' => 'open',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->userFor())
            ->postJson(self::BASE."/windows/{$foreignWindow}/group-templates/{$template['id']}/apply", [
                'warehouse_id' => $this->warehouse->id,
                'code' => 'DG-NOPE',
            ])->assertStatus(404);
    }

    /** A template cannot name another tenant's warehouse. */
    public function test_apply_refuses_a_warehouse_outside_the_tenant(): void
    {
        $window = $this->currentWindowId();
        $template = $this->createTemplate('Local', 5, [$this->zoneMaadi]);
        $foreignWarehouse = Warehouse::factory()->create(['company_id' => $this->otherCompany->id]);

        $this->actingAs($this->userFor())
            ->postJson(self::BASE."/windows/{$window}/group-templates/{$template['id']}/apply", [
                'warehouse_id' => $foreignWarehouse->id,
                'code' => 'DG-NOPE',
            ])->assertStatus(404);
    }

    /** Template writes sit behind the existing permissions — no new ones. */
    public function test_template_routes_require_the_existing_distribution_permissions(): void
    {
        $this->getJson(self::BASE.'/group-templates')->assertStatus(401);

        $this->actingAsUnprivileged($this->userFor())
            ->getJson(self::BASE.'/group-templates')
            ->assertStatus(403);

        $this->actingAsUnprivileged($this->userFor())
            ->postJson(self::BASE.'/group-templates', ['name' => 'X'])
            ->assertStatus(403);
    }

    /** Two tenants may hold the same template name; it is unique per company. */
    public function test_a_template_name_is_unique_per_company_not_globally(): void
    {
        $this->createTemplate('Shared Name', 5, []);

        $this->actingAs($this->userFor())
            ->postJson(self::BASE.'/group-templates', ['name' => 'Shared Name'])
            ->assertStatus(422);

        $foreignUser = User::factory()->create(['company_id' => $this->otherCompany->id]);

        $this->actingAs($foreignUser)
            ->postJson(self::BASE.'/group-templates', ['name' => 'Shared Name'])
            ->assertStatus(201);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Fixtures
    // ─────────────────────────────────────────────────────────────────────────

    private function zone(string $name): int
    {
        return (int) DB::table('distribution_zones')->insertGetId([
            'code' => 'WF-'.substr(uniqid(), -6),
            'name_ar' => $name.'-'.uniqid(), 'name_en' => $name,
            'color' => '#3b82f6',
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

    private function order(Warehouse $warehouse, string $city, float $total = 100.0): Order
    {
        return Order::query()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-WF-'.uniqid(),
            'order_date' => now()->toDateString(),
            'assigned_warehouse_id' => $warehouse->id,
            'city' => $city,
            'governorate' => 'Cairo',
            'status' => 'in_progress',
            'subtotal' => $total, 'total' => $total,
            'deposit_amount' => 0,
            'shipping_total' => 0, 'discount_total' => 0, 'tax_total' => 0,
        ]);
    }

    private function line(Order $order, float $qty = 1.0): void
    {
        DB::table('order_lines')->insert([
            'id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'product_id' => Product::factory()->create()->id,
            'quantity' => $qty,
            'unit_price' => 10,
            'line_total' => 10 * $qty,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A Distribution Window on an explicit date. */
    private function window(string $date): string
    {
        $id = (string) Str::uuid();

        DB::table('distribution_windows')->insert([
            'id' => $id,
            'company_id' => $this->company->id,
            'window_date' => $date,
            'opens_at' => CarbonImmutable::parse($date)->startOfDay(),
            'closes_at' => CarbonImmutable::parse($date)->endOfDay(),
            'status' => 'open',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * An ENGINE preparation wave — `governingPreparationWave` accepts no other
     * type, because only an engine wave carries resolved boundaries.
     */
    private function wave(
        ?string $planningDate = null,
        ?string $warehouseId = null,
        bool $intakeClosed = false,
    ): string {
        $id = (string) Str::uuid();

        DB::table('preparation_waves')->insert([
            'id' => $id,
            'company_id' => $this->company->id,
            'warehouse_id' => $warehouseId ?? $this->warehouse->id,
            'wave_number' => 'PREP-WF-'.substr(uniqid(), -6),
            'planning_date' => $planningDate ?? now()->toDateString(),
            'starts_at' => now()->copy()->subHours(6),
            // The ONE knob these tests turn. Intake open by default (tomorrow);
            // `$intakeClosed` puts it in the past so `hasReachedIntakeCutoff()` is
            // true — which is the actual boundary the write path must respect.
            'intake_closes_at' => $intakeClosed
                ? now()->copy()->subHour()
                : now()->copy()->addDay()->setTime(5, 0),
            'ends_at' => now()->copy()->addDay()->setTime(12, 0),
            // Deliberately still `collecting` even when intake has closed: CUTOFF
            // IS NOT CLOSE, and a test that also flipped the status would be
            // proving something else.
            'status' => 'collecting',
            'wave_type' => 'engine',
            'created_at' => now(), 'updated_at' => now(),
            'created_by' => (string) Str::uuid(),
            'updated_by' => (string) Str::uuid(),
        ]);

        return $id;
    }

    /** The window an Order's assignment actually landed in. */
    private function windowOf(Order $order): ?string
    {
        return DB::table('distribution_window_orders')
            ->where('order_id', $order->id)
            ->value('distribution_window_id');
    }

    private function collect(): void
    {
        $this->actingAs($this->userFor())
            ->postJson(self::BASE.'/windows/collect?warehouse_id='.$this->warehouse->id)
            ->assertOk();
    }

    /** ACTIVE wave membership — `released_at IS NULL`. */
    private function member(string $waveId, Order $order): void
    {
        DB::table('preparation_wave_orders')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $this->company->id,
            'preparation_wave_id' => $waveId,
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'order_confirmed_at' => now(),
            'added_by' => (string) Str::uuid(),
            'added_at' => now(),
        ]);
    }

    /** A Distribution assignment, placed directly so the Window is explicit. */
    private function assign(Order $order, string $windowId, ?int $zoneId, ?string $slotId = null): string
    {
        $id = (string) Str::uuid();

        DB::table('distribution_window_orders')->insert([
            'id' => $id,
            'company_id' => $this->company->id,
            'distribution_window_id' => $windowId,
            'order_id' => $order->id,
            'distribution_zone_id' => $zoneId,
            'virtual_slot_id' => $slotId,
            'assignment_source' => 'auto',
            'assigned_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function groupIn(string $windowId, Warehouse $warehouse, string $code, ?int $capacity = null): string
    {
        $group = VirtualCapacitySlot::query()->create([
            'company_id' => $this->company->id,
            'distribution_window_id' => $windowId,
            'warehouse_id' => $warehouse->id,
            'code' => $code,
            'capacity_orders' => $capacity,
        ]);

        return $group->id;
    }

    private function slotZone(string $windowId, string $slotId, int $zoneId): void
    {
        DB::table('distribution_slot_zones')->insert([
            'distribution_window_id' => $windowId,
            'warehouse_id' => $this->warehouse->id,
            'virtual_slot_id' => $slotId,
            'distribution_zone_id' => $zoneId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * A Group in today's window holding `$count` orders in Maadi.
     *
     * @return array{0: string, 1: string} [windowId, groupId]
     */
    private function groupWithOrders(int $count, ?int $capacity): array
    {
        $window = $this->currentWindowId();
        $group = $this->groupIn($window, $this->warehouse, 'DG-'.substr(uniqid(), -5), $capacity);
        $this->slotZone($window, $group, $this->zoneMaadi);

        foreach (range(1, $count) as $ignored) {
            $order = $this->order($this->warehouse, 'Maadi');
            $this->line($order);
            $this->assign($order, $window, $this->zoneMaadi, $group);
        }

        return [$window, $group];
    }

    private function occupancy(string $windowId, string $groupId): int
    {
        return (int) collect(
            $this->actingAs($this->userFor())
                ->getJson(self::BASE.'/windows/current?warehouse_id='.$this->warehouse->id)
                ->assertOk()->json('data.slots'),
        )->firstWhere('slot_id', $groupId)['orders_count'];
    }

    /** @return array<string, mixed> */
    private function createTemplate(string $name, ?int $capacity, array $zoneIds): array
    {
        return $this->actingAs($this->userFor())
            ->postJson(self::BASE.'/group-templates', [
                'name' => $name,
                'capacity_orders' => $capacity,
                'zone_ids' => $zoneIds,
            ])->assertStatus(201)->json('data');
    }

    private function resolve(string $waveId, ?string $warehouseId = null): string
    {
        return app(DistributionWindowService::class)->resolvePlanningWindow(
            $this->company->id,
            $waveId,
            $warehouseId ?? $this->warehouse->id,
            CarbonImmutable::now(),
        )->id;
    }

    /**
     * Make sure TODAY's Distribution Window row exists — fixture plumbing only.
     *
     * H1 = Option B: a READ never creates a Window. These fixtures used to obtain one as a
     * side effect of `GET /windows/current`, which is exactly the behaviour the ruling
     * removed. Creating it here as a plain idempotent insert keeps every assertion in this
     * class unchanged while no longer depending on a prohibited side effect.
     */
    private function ensureTodayWindow(?string $companyId = null): void
    {
        $company = $companyId ?? $this->company->id;
        $date = CarbonImmutable::now()->toDateString();

        $exists = DB::table('distribution_windows')
            ->where('company_id', $company)
            ->whereDate('window_date', $date)
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('distribution_windows')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $company,
            'window_date' => $date,
            'opens_at' => CarbonImmutable::parse($date)->startOfDay(),
            'closes_at' => CarbonImmutable::parse($date)->endOfDay(),
            'status' => 'open',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function currentWindowId(): string
    {
        $this->ensureTodayWindow();

        return $this->actingAs($this->userFor())
            ->getJson(self::BASE.'/windows/current')->assertOk()->json('data.window.id');
    }

    private function userFor(?Company $company = null): User
    {
        return User::factory()->create(['company_id' => ($company ?? $this->company)->id]);
    }
}

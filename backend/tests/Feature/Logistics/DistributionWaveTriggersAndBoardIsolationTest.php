<?php

declare(strict_types=1);

namespace Tests\Feature\Logistics;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Logistics\Distribution\Domain\Models\DistributionGroupTemplate;
use Modules\Logistics\Distribution\Domain\Models\DistributionWindow;
use Modules\Logistics\Distribution\Domain\Models\VirtualCapacitySlot;
use Modules\Logistics\Distribution\Domain\Services\DailyGroupLifecycleService;
use Modules\Logistics\Distribution\Domain\Services\DistributionAggregationService;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Operations\Preparation\Domain\Events\WaveClosed;
use Modules\Operations\Preparation\Domain\Events\WavePreparationStarted;
use Modules\Operations\Preparation\Domain\Events\WaveStarted;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-DISTRIBUTION-WAVE-LIFECYCLE-TRIGGERS-AND-BOARD-ISOLATION-003
 *
 * The wiring: Preparation announces, Distribution reacts, and the board shows one Wave.
 *
 * ┌─ WHAT IS ACTUALLY BEING TESTED HERE ─────────────────────────────────────┐
 * │ The lifecycle CORE is already covered by                                  │
 * │ DistributionDailyGroupWaveLifecycleTest (24 green). This suite exercises   │
 * │ the three things TASK-003 added:                                          │
 * │   TRIGGERS   the real `WaveStarted` / `WaveClosed` events are dispatched,  │
 * │              not the services called directly — so the wiring itself is    │
 * │              under test, which is the whole point of the task.            │
 * │   ISOLATION  several Waves on ONE day, each with its own Group, and the    │
 * │              board showing exactly one of them.                           │
 * │   IDEMPOTENCE replaying either event changes nothing.                     │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class DistributionWaveTriggersAndBoardIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/logistics/distribution';

    /**
     * The operational day for every fixture here.
     *
     * TODAY, deliberately. `collect()` writes into the window for the CURRENT date, so a
     * fixed future date would put the orders in one window and the Wave, sweep and board
     * in another — and every assertion about groups filling would then be measuring two
     * unrelated windows.
     */
    private static string $day;

    private Company $company;

    private Customer $customer;

    private Warehouse $warehouse;

    private int $maadi;

    private int $helwan;

    private DailyGroupLifecycleService $lifecycle;

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

        $this->maadi = $this->zone('Maadi');
        $this->helwan = $this->zone('Helwan');
        $this->city($governorate, 'Maadi', 'المعادي', $this->maadi);
        $this->city($governorate, 'Helwan', 'حلوان', $this->helwan);

        $this->lifecycle = app(DailyGroupLifecycleService::class);
        self::$day = now()->toDateString();
    }

    // ── 1-5. The Wave-start trigger ──────────────────────────────────────────

    /** TEST 1/3 — dispatching the real event creates the Group for a Template with work. */
    public function test_wave_start_triggers_the_template_sweep(): void
    {
        $template = $this->template('Morning', [$this->maadi]);
        $this->order('Maadi');
        $this->collect();

        $waveId = $this->wave($this->day());

        self::assertSame(0, VirtualCapacitySlot::query()->count(), 'nothing before the wave starts');

        $this->startWave($waveId, $this->day());

        $group = $this->lifecycle->findGroup($waveId, (string) $template->id);

        self::assertNotNull($group, 'the sweep ran off the event alone');
        self::assertSame($template->id, $group->distribution_group_template_id);
        self::assertSame($waveId, $group->preparation_wave_id);
    }

    /** TEST 2 — a Template with no eligible work creates nothing at wave start. */
    public function test_wave_start_creates_nothing_for_an_empty_template(): void
    {
        $this->template('Morning', [$this->maadi]);   // no orders at all

        $waveId = $this->wave($this->day());
        $this->startWave($waveId, $this->day());

        self::assertSame(0, VirtualCapacitySlot::query()->count(), 'no empty shell');
    }

    /** Only the Templates WITH work get Groups — the others stay untouched and usable. */
    public function test_wave_start_creates_groups_only_where_work_exists(): void
    {
        $withWork = $this->template('Morning', [$this->maadi]);
        $withoutWork = $this->template('Evening', [$this->helwan]);

        $this->order('Maadi');
        $this->collect();

        $waveId = $this->wave($this->day());
        $this->startWave($waveId, $this->day());

        self::assertNotNull($this->lifecycle->findGroup($waveId, (string) $withWork->id));
        self::assertNull($this->lifecycle->findGroup($waveId, (string) $withoutWork->id));
        self::assertSame(1, VirtualCapacitySlot::query()->count());
    }

    /** TEST 4/5 — work arriving after the sweep creates the Group lazily, then reuses it. */
    public function test_late_work_creates_lazily_then_reuses(): void
    {
        $template = $this->template('Morning', [$this->maadi]);
        $waveId = $this->wave($this->day());

        $this->startWave($waveId, $this->day());
        self::assertNull($this->lifecycle->findGroup($waveId, (string) $template->id));

        // Work turns up mid-wave.
        $this->order('Maadi');
        $this->collect();

        $window = $this->window($this->day());

        $first = $this->lifecycle->ensureGroupForTemplate(
            $window, $template, $this->warehouse->id, $waveId, static fn (): int => 1,
        );
        $second = $this->lifecycle->ensureGroupForTemplate(
            $window, $template, $this->warehouse->id, $waveId, static fn (): int => 4,
        );

        self::assertNotNull($first);
        self::assertSame($first->id, $second?->id, 'the same Group, not a second one');
        self::assertSame(1, VirtualCapacitySlot::query()->count());
    }

    // ── 6-8, 18. Uniqueness, isolation, idempotency ──────────────────────────

    /** TEST 18 — replaying wave start creates nothing twice and changes nothing. */
    public function test_wave_start_is_idempotent(): void
    {
        $template = $this->template('Morning', [$this->maadi]);
        $this->order('Maadi');
        $this->collect();

        $waveId = $this->wave($this->day());

        $this->startWave($waveId, $this->day());
        $group = $this->lifecycle->findGroup($waveId, (string) $template->id);
        self::assertNotNull($group);
        $stamp = $group->updated_at;

        $this->startWave($waveId, $this->day());
        $this->startWave($waveId, $this->day());

        self::assertSame(1, VirtualCapacitySlot::query()->count(), 'still exactly one Group');
        self::assertEquals($stamp, $group->refresh()->updated_at, 'and it was not rewritten');
    }

    /**
     * TEST 7/8 — THE ISOLATION CASE. Three Waves, one day, one company, one warehouse.
     * Each gets its own Group for the same Template, and none is shared.
     */
    public function test_three_waves_on_one_day_each_get_their_own_group(): void
    {
        $template = $this->template('Morning', [$this->maadi]);
        $this->order('Maadi');
        $this->collect();

        $waveIds = [];

        foreach ([1, 2, 3] as $n) {
            $waveId = $this->wave($this->day());
            $waveIds[] = $waveId;

            $this->lifecycle->ensureGroupForTemplate(
                $this->window($this->day()),
                $template,
                $this->warehouse->id,
                $waveId,
                static fn (): int => 1,
            );
        }

        $groupIds = [];

        foreach ($waveIds as $waveId) {
            $group = $this->lifecycle->findGroup($waveId, (string) $template->id);
            self::assertNotNull($group, 'each wave has its own group');
            $groupIds[] = $group->id;
        }

        self::assertCount(3, array_unique($groupIds), 'three distinct Groups, no sharing');
        self::assertSame(3, VirtualCapacitySlot::query()->count());
    }

    /** TEST 6 — and the database still refuses a duplicate for one Template + Wave. */
    public function test_duplicate_template_and_wave_is_impossible(): void
    {
        $template = $this->template('Morning', [$this->maadi]);
        $waveId = $this->wave($this->day());
        $windowId = $this->window($this->day())->id;

        VirtualCapacitySlot::query()->create([
            'company_id' => $this->company->id,
            'distribution_window_id' => $windowId,
            'preparation_wave_id' => $waveId,
            'distribution_group_template_id' => $template->id,
            'warehouse_id' => $this->warehouse->id,
            'code' => 'A-'.substr(uniqid(), -5),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        VirtualCapacitySlot::query()->create([
            'company_id' => $this->company->id,
            'distribution_window_id' => $windowId,
            'preparation_wave_id' => $waveId,
            'distribution_group_template_id' => $template->id,
            'warehouse_id' => $this->warehouse->id,
            'code' => 'B-'.substr(uniqid(), -5),
        ]);
    }

    // ── 9-10. Board isolation ────────────────────────────────────────────────

    /**
     * TEST 9/10 — the active board shows the ACTIVE Wave's Group and not the other
     * Wave's, even though both live on the same day and in the same window.
     */
    public function test_the_active_board_shows_only_the_active_waves_group(): void
    {
        $template = $this->template('Morning', [$this->maadi]);
        $this->order('Maadi');
        $this->collect();

        $window = $this->window($this->day());

        // An earlier wave, now closed, with its own Group.
        $oldWave = $this->wave($this->day(), status: 'closed');
        $old = $this->lifecycle->ensureGroupForTemplate(
            $window, $template, $this->warehouse->id, $oldWave, static fn (): int => 1,
        );
        self::assertNotNull($old);

        // The live wave, with its own.
        $liveWave = $this->wave($this->day());
        $live = $this->lifecycle->ensureGroupForTemplate(
            $window, $template, $this->warehouse->id, $liveWave, static fn (): int => 1,
        );
        self::assertNotNull($live);

        $codes = array_column($this->board($window->id), 'code');

        self::assertContains($live->code, $codes, 'the live wave is on the board');
        self::assertNotContains($old->code, $codes, 'the other wave is not');
    }

    /** TEST 10 — a closed Wave's Group never reappears as active. */
    public function test_a_closed_waves_group_is_absent_from_the_board(): void
    {
        $template = $this->template('Morning', [$this->maadi]);
        $this->order('Maadi');
        $this->collect();

        $window = $this->window($this->day());
        $waveId = $this->wave($this->day());

        $group = $this->lifecycle->ensureGroupForTemplate(
            $window, $template, $this->warehouse->id, $waveId, static fn (): int => 1,
        );
        self::assertNotNull($group);
        self::assertContains($group->code, array_column($this->board($window->id), 'code'));

        $this->closeWaveEvent($waveId, $this->day());

        self::assertNotContains(
            $group->code,
            array_column($this->board($window->id), 'code'),
            'closed with its wave, gone from the active board',
        );
    }

    // ── 11-14, 17. The Wave-close trigger ────────────────────────────────────

    /** TEST 11/13 — the real event closes the Wave's Groups. */
    public function test_the_wave_closed_event_closes_its_groups(): void
    {
        $template = $this->template('Morning', [$this->maadi]);
        $this->order('Maadi');
        $this->collect();

        $waveId = $this->wave($this->day());
        $group = $this->lifecycle->ensureGroupForTemplate(
            $this->window($this->day()), $template, $this->warehouse->id, $waveId,
            static fn (): int => 1,
        );
        self::assertNotNull($group);

        $this->closeWaveEvent($waveId, $this->day());

        $group->refresh();
        self::assertNotNull($group->closed_at);
        self::assertSame(DailyGroupLifecycleService::CLOSED_WAVE_ENDED, $group->closed_reason);
    }

    /** TEST 12 — closing does not delete: history survives. */
    public function test_closing_retains_the_group_as_history(): void
    {
        $template = $this->template('Morning', [$this->maadi]);
        $this->order('Maadi');
        $this->collect();

        $waveId = $this->wave($this->day());
        $group = $this->lifecycle->ensureGroupForTemplate(
            $this->window($this->day()), $template, $this->warehouse->id, $waveId,
            static fn (): int => 1,
        );
        self::assertNotNull($group);
        $zonesBefore = DB::table('distribution_slot_zones')->where('virtual_slot_id', $group->id)->count();

        $this->closeWaveEvent($waveId, $this->day());

        self::assertNotNull(VirtualCapacitySlot::query()->find($group->id), 'row survives');
        self::assertSame(
            $zonesBefore,
            DB::table('distribution_slot_zones')->where('virtual_slot_id', $group->id)->count(),
            'its zones survive too',
        );
    }

    /** TEST 14 — unfinished orders are released, assignment history intact. */
    public function test_unfinished_orders_are_released_on_close(): void
    {
        $template = $this->template('Morning', [$this->maadi]);
        $this->order('Maadi');
        $this->collect();

        $waveId = $this->wave($this->day());
        $group = $this->lifecycle->ensureGroupForTemplate(
            $this->window($this->day()), $template, $this->warehouse->id, $waveId,
            static fn (): int => 1,
        );
        self::assertNotNull($group);

        $attached = DB::table('distribution_window_orders')
            ->where('virtual_slot_id', $group->id)->count();
        self::assertGreaterThan(0, $attached, 'fixture: the collector filled the group');

        $rowsBefore = DB::table('distribution_window_orders')->count();

        $this->closeWaveEvent($waveId, $this->day());

        self::assertSame(
            0,
            DB::table('distribution_window_orders')->where('virtual_slot_id', $group->id)->count(),
            'released from the group',
        );
        self::assertSame(
            $rowsBefore,
            DB::table('distribution_window_orders')->count(),
            'no assignment row was deleted — window history is intact',
        );
    }

    /** TEST 17 — replaying the close event changes nothing the second time. */
    public function test_the_wave_closed_event_is_idempotent(): void
    {
        $template = $this->template('Morning', [$this->maadi]);
        $this->order('Maadi');
        $this->collect();

        $waveId = $this->wave($this->day());
        $group = $this->lifecycle->ensureGroupForTemplate(
            $this->window($this->day()), $template, $this->warehouse->id, $waveId,
            static fn (): int => 1,
        );
        self::assertNotNull($group);

        $this->closeWaveEvent($waveId, $this->day());
        $stamp = $group->refresh()->closed_at;

        $this->closeWaveEvent($waveId, $this->day());

        self::assertEquals($stamp, $group->refresh()->closed_at, 'closure time not rewritten');
    }

    // ── 15-16, 19. The rules that must NOT have moved ────────────────────────

    /** TEST 16 — automatic creation may still exceed capacity, in ONE Group. */
    public function test_the_sweep_still_produces_one_oversized_group(): void
    {
        $template = $this->template('Morning', [$this->maadi], capacity: 3);

        for ($i = 0; $i < 7; $i++) {
            $this->order('Maadi');
        }
        $this->collect();

        $waveId = $this->wave($this->day());
        $this->startWave($waveId, $this->day());

        $group = $this->lifecycle->findGroup($waveId, (string) $template->id);

        self::assertNotNull($group);
        self::assertSame(3, $group->capacity_orders, 'planning capacity untouched');
        self::assertSame(1, VirtualCapacitySlot::query()->count(), 'no split, no hidden group');
        self::assertSame(
            7,
            DB::table('distribution_window_orders')->where('virtual_slot_id', $group->id)->count(),
        );
    }

    /** TEST 15 — manual capacity enforcement is unchanged. */
    public function test_manual_zone_attach_still_refuses_to_exceed_capacity(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->order('Maadi');
        }
        $this->collect();

        $windowId = $this->window($this->day())->id;

        $slotId = (string) $this->actingAs($this->user())
            ->postJson(self::BASE."/windows/{$windowId}/slots", [
                'warehouse_id' => $this->warehouse->id,
                'code' => 'MAN-'.substr(uniqid(), -5),
                'capacity_orders' => 2,
            ])->assertSuccessful()->json('data.id');

        $this->actingAs($this->user())
            ->postJson(self::BASE."/windows/{$windowId}/slots/{$slotId}/zones", [
                'zone_id' => $this->maadi,
                'warehouse_id' => $this->warehouse->id,
            ])->assertStatus(422);
    }

    /** TEST 19 — operator Groups with no Template stay valid and stay on the board. */
    public function test_operator_created_groups_remain_valid_and_visible(): void
    {
        $window = $this->window($this->day());
        $waveId = $this->wave($this->day());

        foreach (['OPS-A', 'OPS-B'] as $code) {
            VirtualCapacitySlot::query()->create([
                'company_id' => $this->company->id,
                'distribution_window_id' => $window->id,
                'preparation_wave_id' => $waveId,
                'warehouse_id' => $this->warehouse->id,
                'code' => $code,
            ]);
        }

        $codes = array_column($this->board($window->id), 'code');

        self::assertContains('OPS-A', $codes);
        self::assertContains('OPS-B', $codes, 'two template-less Groups coexist in one Wave');
    }

    /** A Group with no Wave at all is still shown — it cannot belong to another Wave. */
    public function test_a_group_with_no_wave_is_still_shown_on_the_board(): void
    {
        $window = $this->window($this->day());
        $this->wave($this->day());

        VirtualCapacitySlot::query()->create([
            'company_id' => $this->company->id,
            'distribution_window_id' => $window->id,
            'warehouse_id' => $this->warehouse->id,
            'code' => 'NO-WAVE',
        ]);

        self::assertContains('NO-WAVE', array_column($this->board($window->id), 'code'));
    }

    /** Tenancy still holds on the board read. */
    public function test_the_board_is_company_scoped(): void
    {
        $window = $this->window($this->day());
        $other = Company::factory()->create();

        $this->actingAs(User::factory()->create(['company_id' => $other->id]))
            ->getJson(self::BASE."/windows/{$window->id}/slots?warehouse_id={$this->warehouse->id}")
            ->assertStatus(404);
    }

    // ── TASK-FINAL-SYNC §GAP-1 — the AUTOMATED wave-start path ───────────────

    /**
     * The automated Wave Engine transition (Collecting -> Preparing) must create the
     * Group exactly like the manual start does. Before this fix WavePreparationStarted
     * had no Distribution subscriber, so an engine-started Wave reached the workspace
     * with no Groups until a manual Refresh ran.
     */
    public function test_automated_wave_preparation_started_triggers_the_template_sweep(): void
    {
        $template = $this->template('Morning', [$this->maadi]);
        $this->order('Maadi');
        $this->collect();

        $waveId = $this->wave($this->day());

        self::assertSame(0, VirtualCapacitySlot::query()->count(), 'nothing before the engine transition');

        $this->startWaveViaEngine($waveId, $this->day());

        $group = $this->lifecycle->findGroup($waveId, (string) $template->id);

        self::assertNotNull($group, 'the sweep ran off the AUTOMATED event alone');
        self::assertSame($template->id, $group->distribution_group_template_id);
        self::assertSame($waveId, $group->preparation_wave_id);
    }

    /**
     * Both wave-start paths funnel into ONE idempotent sweep: a manual start followed
     * by the engine transition on the SAME Wave (or the reverse) creates exactly one
     * Group, never a duplicate. The (wave, template) unique index is the backstop.
     */
    public function test_manual_then_automated_start_on_one_wave_creates_one_group(): void
    {
        $template = $this->template('Morning', [$this->maadi]);
        $this->order('Maadi');
        $this->collect();

        $waveId = $this->wave($this->day());

        $this->startWave($waveId, $this->day());          // manual WaveStarted
        $this->startWaveViaEngine($waveId, $this->day());  // automated WavePreparationStarted
        $this->startWaveViaEngine($waveId, $this->day());  // replay — still nothing new

        self::assertSame(
            1,
            VirtualCapacitySlot::query()->where('preparation_wave_id', $waveId)->count(),
            'both events, and their replays, funnel into one idempotent sweep',
        );
        self::assertNotNull($this->lifecycle->findGroup($waveId, (string) $template->id));
    }

    /** An automated start warrants no Group for a Template with no eligible work. */
    public function test_automated_start_creates_nothing_for_an_empty_template(): void
    {
        $this->template('Morning', [$this->maadi]);   // no orders

        $waveId = $this->wave($this->day());
        $this->startWaveViaEngine($waveId, $this->day());

        self::assertSame(0, VirtualCapacitySlot::query()->count(), 'no empty shell on the automated path either');
    }

    // ── TASK-FINAL-SYNC §GAP-4 — Map & Fleet Wave isolation ──────────────────

    /**
     * mapData() must scope its Group overlay to the governing Wave exactly as the
     * Groups tab does: this Wave's Groups, plus Groups belonging to no Wave — never
     * another active Wave's. Proven at the service level with an explicit waveId so it
     * does not depend on which Wave the active-wave resolver happens to pick.
     */
    public function test_map_data_isolates_its_group_overlay_to_the_given_wave(): void
    {
        $window = $this->window($this->day());
        $waveA = $this->wave($this->day());
        $waveB = $this->wave($this->day());

        $this->slotFor($window->id, $waveA, 'MAP-A');
        $this->slotFor($window->id, $waveB, 'MAP-B');
        $this->slotFor($window->id, null, 'MAP-NONE');

        $agg = app(DistributionAggregationService::class);

        $scoped = array_column($agg->mapData($window->id, $this->warehouse->id, $waveA)['groups'], 'code');
        self::assertContains('MAP-A', $scoped, "the given Wave's own Group is on its map");
        self::assertContains('MAP-NONE', $scoped, 'a Group with no Wave is never another Wave\'s');
        self::assertNotContains('MAP-B', $scoped, "another active Wave's Group must not appear on the map");

        // A null waveId is the pre-fix behaviour, preserved: every active Group shows.
        $unscoped = array_column($agg->mapData($window->id, $this->warehouse->id, null)['groups'], 'code');
        self::assertContains('MAP-A', $unscoped);
        self::assertContains('MAP-B', $unscoped);
        self::assertContains('MAP-NONE', $unscoped);
    }

    /**
     * The map ENDPOINT threads the governing Wave through, so its Group overlay matches
     * the Groups tab (board) for the same window — another Wave's Group is absent from
     * both, and a Group with no Wave is present in both.
     *
     * The non-governing Wave is created `closed` (as the board-isolation test does) only
     * so the active-wave resolver deterministically picks the live one; its Group itself
     * is left OPEN, so its absence proves WAVE isolation, not the closed-Group filter.
     */
    public function test_the_map_endpoint_group_overlay_matches_the_board(): void
    {
        $template = $this->template('Morning', [$this->maadi]);
        $this->order('Maadi');
        $this->collect();

        $window = $this->window($this->day());

        // Another (non-governing) Wave with its own still-open Group.
        $otherWave = $this->wave($this->day(), status: 'closed');
        $other = $this->lifecycle->ensureGroupForTemplate(
            $window, $template, $this->warehouse->id, $otherWave, static fn (): int => 1,
        );
        self::assertNotNull($other);

        // The live (governing) Wave's Group.
        $liveWave = $this->wave($this->day());
        $live = $this->lifecycle->ensureGroupForTemplate(
            $window, $template, $this->warehouse->id, $liveWave, static fn (): int => 1,
        );
        self::assertNotNull($live);

        // A Group with no Wave — must show on both surfaces.
        $this->slotFor($window->id, null, 'MAP-NONE');

        $boardCodes = array_column($this->board($window->id), 'code');
        $mapCodes = array_column($this->mapGroups($window->id), 'code');

        sort($boardCodes);
        sort($mapCodes);

        self::assertSame($boardCodes, $mapCodes, 'the map overlay and the board agree on which Groups are live');
        self::assertContains($live->code, $mapCodes, 'the live Wave is on the map');
        self::assertContains('MAP-NONE', $mapCodes, 'the un-waved Group is on the map');
        self::assertNotContains($other->code, $mapCodes, "another Wave's Group is not on the map");
    }

    /**
     * The fleet-options endpoint reports the target Group's own order count, read from
     * the Wave-isolated summary. A regression guard that threading the Wave through left
     * the happy path intact.
     */
    public function test_group_fleet_options_reports_the_groups_order_count(): void
    {
        $template = $this->template('Morning', [$this->maadi]);
        $this->order('Maadi');
        $this->order('Maadi');
        $this->collect();

        $window = $this->window($this->day());
        $waveId = $this->wave($this->day());
        $group = $this->lifecycle->ensureGroupForTemplate(
            $window, $template, $this->warehouse->id, $waveId, static fn (): int => 1,
        );
        self::assertNotNull($group);

        $attached = (int) DB::table('distribution_window_orders')
            ->where('virtual_slot_id', $group->id)->count();
        self::assertGreaterThan(0, $attached, 'fixture: the group holds its zone\'s orders');

        $data = $this->actingAs($this->user())
            ->getJson(self::BASE."/windows/{$window->id}/slots/{$group->id}/fleet-options?warehouse_id={$this->warehouse->id}")
            ->assertOk()
            ->json('data');

        self::assertSame($attached, (int) $data['group_orders'], 'fleet options report the Wave-scoped count');
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    /** The operational day these fixtures run on — see self::$day. */
    private function day(): string
    {
        return self::$day;
    }

    /** Dispatch the REAL wave-start event, so the listener wiring is what runs. */
    private function startWave(string $waveId, string $date): void
    {
        event(new WaveStarted(
            waveId: $waveId,
            waveNumber: 'W-'.substr($waveId, 0, 6),
            companyId: (string) $this->company->id,
            warehouseId: (string) $this->warehouse->id,
            planningDate: $date,
            orderIds: [],
            startedBy: (string) Str::uuid(),
            startedAt: now()->toIso8601String(),
        ));
    }

    /**
     * Dispatch the REAL AUTOMATED wave-start event — the Wave Engine's
     * Collecting -> Preparing transition. TASK-FINAL-SYNC §GAP-1 wired this to the
     * SAME sweep as the manual WaveStarted, so both paths react identically.
     */
    private function startWaveViaEngine(string $waveId, string $date): void
    {
        event(new WavePreparationStarted(
            waveId: $waveId,
            waveNumber: 'W-'.substr($waveId, 0, 6),
            companyId: (string) $this->company->id,
            warehouseId: (string) $this->warehouse->id,
            planningDate: $date,
            ordersCount: 0,
            orderIds: [],
            startedBy: 'system',
            startedAt: now()->toIso8601String(),
        ));
    }

    /** Dispatch the REAL wave-close event. */
    private function closeWaveEvent(string $waveId, string $date): void
    {
        event(new WaveClosed(
            waveId: $waveId,
            waveNumber: 'W-'.substr($waveId, 0, 6),
            companyId: (string) $this->company->id,
            warehouseId: (string) $this->warehouse->id,
            planningDate: $date,
            closedBy: (string) Str::uuid(),
            closedAt: now()->toIso8601String(),
        ));
    }

    /** @return list<array<string, mixed>> */
    private function board(string $windowId): array
    {
        return $this->actingAs($this->user())
            ->getJson(self::BASE."/windows/{$windowId}/slots?warehouse_id={$this->warehouse->id}")
            ->assertOk()
            ->json('data') ?? [];
    }

    /** The map endpoint's Group overlay for a window. @return list<array<string, mixed>> */
    private function mapGroups(string $windowId): array
    {
        return $this->actingAs($this->user())
            ->getJson(self::BASE."/windows/{$windowId}/map?warehouse_id={$this->warehouse->id}")
            ->assertOk()
            ->json('data.groups') ?? [];
    }

    /** A bare Group in a window, optionally bound to a Wave (null = an un-waved Group). */
    private function slotFor(string $windowId, ?string $waveId, string $code): VirtualCapacitySlot
    {
        return VirtualCapacitySlot::query()->create([
            'company_id' => $this->company->id,
            'distribution_window_id' => $windowId,
            'preparation_wave_id' => $waveId,
            'warehouse_id' => $this->warehouse->id,
            'code' => $code,
        ]);
    }

    private function wave(string $date, string $status = 'collecting'): string
    {
        $id = (string) Str::uuid();

        DB::table('preparation_waves')->insert([
            'id' => $id,
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouse->id,
            'wave_number' => 'W-'.substr(uniqid(), -8),
            'planning_date' => $date,
            'status' => $status,
            'wave_type' => 'engine',
            'starts_at' => $date.' 00:00:00',
            'ends_at' => $date.' 23:59:59',
            'created_by' => (string) Str::uuid(),
            'updated_by' => (string) Str::uuid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function window(string $date): DistributionWindow
    {
        $existing = DistributionWindow::query()
            ->where('company_id', $this->company->id)
            ->whereDate('window_date', $date)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $id = (string) Str::uuid();

        DB::table('distribution_windows')->insert([
            'id' => $id,
            'company_id' => $this->company->id,
            'window_date' => $date,
            'status' => 'open',
            'opens_at' => $date.' 00:00:00',
            'closes_at' => $date.' 23:59:59',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DistributionWindow::query()->findOrFail($id);
    }

    /** @param  list<int>  $zoneIds */
    private function template(string $name, array $zoneIds, ?int $capacity = null): DistributionGroupTemplate
    {
        $payload = ['name' => $name, 'zone_ids' => $zoneIds];

        if ($capacity !== null) {
            $payload['capacity_orders'] = $capacity;
        }

        $id = (string) $this->actingAs($this->user())
            ->postJson(self::BASE.'/group-templates', $payload)
            ->assertStatus(201)->json('data.id');

        return DistributionGroupTemplate::query()->findOrFail($id);
    }

    private function collect(): void
    {
        $this->actingAs($this->user())->postJson(self::BASE.'/windows/collect')->assertOk();
    }

    private function zone(string $name): int
    {
        return (int) DB::table('distribution_zones')->insertGetId([
            'code' => 'WT-'.substr(uniqid(), -6),
            'name_ar' => $name.'-'.uniqid(), 'name_en' => $name,
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

    private function order(string $city): Order
    {
        return Order::query()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-WT-'.uniqid(),
            'order_date' => now()->toDateString(),
            'assigned_warehouse_id' => $this->warehouse->id,
            'city' => $city,
            'governorate' => 'Cairo',
            'status' => 'in_progress',
            'subtotal' => 100, 'total' => 100, 'deposit_amount' => 0,
            'shipping_total' => 0, 'discount_total' => 0, 'tax_total' => 0,
        ]);
    }

    private function user(): User
    {
        return User::factory()->create(['company_id' => $this->company->id]);
    }
}

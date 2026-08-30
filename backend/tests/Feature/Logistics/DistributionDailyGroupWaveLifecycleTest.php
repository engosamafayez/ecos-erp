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
use Modules\Logistics\Distribution\Domain\Services\GroupTemplateService;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-DISTRIBUTION-DAILY-GROUP-WAVE-LIFECYCLE-002
 *
 * A Group is the operational instance of ONE Preparation Wave.
 *
 * ┌─ THE TWO PROPERTIES THIS SUITE PROTECTS ─────────────────────────────────┐
 * │ IDENTITY  a Group knows its Wave and the Template that stamped it, so     │
 * │           "does Template A already have a Group in Wave Y?" is answerable │
 * │           structurally — never from a code, a name or a date.            │
 * │ ISOLATION yesterday's Group can never become today's. It closes with its  │
 * │           Wave, stays historical, and its unfinished orders return to the │
 * │           canonical pool rather than being carried across in a Group.     │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * Capacity is asserted as a PLANNING threshold, not a gate: 27 eligible orders under a
 * capacity of 20 must produce ONE Group of 27, and manual paths must still refuse — the
 * opt-out is scoped to daily creation alone.
 */
final class DistributionDailyGroupWaveLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/logistics/distribution';

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
    }

    // ── 1-2. Identity is persisted structurally ──────────────────────────────

    /** TEST 1 — the Group knows its Wave. */
    public function test_a_group_stores_its_preparation_wave_identity(): void
    {
        $wave = $this->wave('2026-09-01');
        $group = $this->createFromTemplate($this->template('Morning', [$this->maadi]), '2026-09-01');

        self::assertSame($wave, $group->preparation_wave_id);
    }

    /** TEST 2 — and the Template that stamped it. */
    public function test_a_group_stores_the_template_it_was_created_from(): void
    {
        $this->wave('2026-09-01');
        $template = $this->template('Morning', [$this->maadi]);

        $group = $this->createFromTemplate($template, '2026-09-01');

        self::assertSame($template->id, $group->distribution_group_template_id);
    }

    /** Identity survives a rename — which a code/name convention could never do. */
    public function test_identity_is_not_derived_from_the_code_or_name(): void
    {
        $waveId = $this->wave('2026-09-01');
        $template = $this->template('Morning', [$this->maadi]);
        $group = $this->createFromTemplate($template, '2026-09-01');

        DB::table('distribution_virtual_slots')->where('id', $group->id)
            ->update(['code' => 'RENAMED-XYZ', 'name' => 'Something Else']);

        self::assertSame(
            $group->id,
            $this->lifecycle->findGroup($waveId, $template->id)?->id,
            'still found by identity after a rename',
        );
    }

    // ── 3-5. One per Template per Wave; never the previous Wave's ────────────

    /** TEST 3 — the database refuses a second auto-created Group for one Template+Wave. */
    public function test_the_same_template_cannot_have_two_groups_in_one_wave(): void
    {
        $waveId = $this->wave('2026-09-01');
        $template = $this->template('Morning', [$this->maadi]);
        $this->createFromTemplate($template, '2026-09-01');

        $this->expectException(\Illuminate\Database\QueryException::class);

        VirtualCapacitySlot::query()->create([
            'company_id' => $this->company->id,
            'distribution_window_id' => $this->window('2026-09-01')->id,
            'preparation_wave_id' => $waveId,
            'distribution_group_template_id' => $template->id,
            'warehouse_id' => $this->warehouse->id,
            'code' => 'DUPE-'.substr(uniqid(), -5),
        ]);
    }

    /** Operator-created Groups carry no Template, so many may exist in one Wave. */
    public function test_operator_created_groups_are_not_constrained_by_the_invariant(): void
    {
        $waveId = $this->wave('2026-09-01');
        $windowId = $this->window('2026-09-01')->id;

        foreach (['OPS-A', 'OPS-B'] as $code) {
            VirtualCapacitySlot::query()->create([
                'company_id' => $this->company->id,
                'distribution_window_id' => $windowId,
                'preparation_wave_id' => $waveId,
                'warehouse_id' => $this->warehouse->id,
                'code' => $code,
            ]);
        }

        self::assertSame(2, VirtualCapacitySlot::query()->where('preparation_wave_id', $waveId)->count());
    }

    /** TEST 4 — the same Template in a different Wave gets its own Group. */
    public function test_the_same_template_gets_a_new_group_in_a_new_wave(): void
    {
        $template = $this->template('Morning', [$this->maadi]);

        $waveOne = $this->wave('2026-09-01');
        $first = $this->createFromTemplate($template, '2026-09-01');

        $waveTwo = $this->wave('2026-09-02');
        $second = $this->createFromTemplate($template, '2026-09-02');

        self::assertNotSame($first->id, $second->id);
        self::assertSame($waveOne, $first->preparation_wave_id);
        self::assertSame($waveTwo, $second->preparation_wave_id);
    }

    /** TEST 5 — the previous Wave's Group is never returned for the new Wave. */
    public function test_a_previous_waves_group_is_never_reused(): void
    {
        $template = $this->template('Morning', [$this->maadi]);

        $waveOne = $this->wave('2026-09-01');
        $yesterday = $this->createFromTemplate($template, '2026-09-01');

        $waveTwo = $this->wave('2026-09-02');

        self::assertNull(
            $this->lifecycle->findGroup($waveTwo, $template->id),
            'the new wave starts with no group for this template',
        );
        self::assertNotNull($this->lifecycle->findGroup($waveOne, $template->id));
        self::assertNull($yesterday->refresh()->closed_at, 'and yesterday was not mutated');
    }

    // ── 6-9. Wave close ──────────────────────────────────────────────────────

    /** TEST 7 — an incomplete Group closes with its Wave and becomes historical. */
    public function test_an_incomplete_group_closes_with_its_wave(): void
    {
        $waveId = $this->wave('2026-09-01');
        $template = $this->template('Morning', [$this->maadi]);
        $group = $this->createFromTemplate($template, '2026-09-01');

        $result = $this->lifecycle->closeWave($waveId);

        self::assertSame(1, $result['closed']);
        self::assertNotNull($group->refresh()->closed_at);
        self::assertSame(DailyGroupLifecycleService::CLOSED_WAVE_ENDED, $group->closed_reason);
    }

    /** TEST 6 — and it is gone from the active board, without being deleted. */
    public function test_a_closed_group_leaves_the_active_board_but_survives(): void
    {
        $waveId = $this->wave('2026-09-01');
        $group = $this->createFromTemplate($this->template('Morning', [$this->maadi]), '2026-09-01');
        $windowId = $this->window('2026-09-01')->id;

        self::assertCount(1, $this->boardGroups($windowId));

        $this->lifecycle->closeWave($waveId);

        self::assertCount(0, $this->boardGroups($windowId), 'not on the active board');
        self::assertNotNull(
            VirtualCapacitySlot::query()->find($group->id),
            'but the row still exists — history is not deleted',
        );
    }

    /** TEST 8 — unfinished orders are released back to the canonical pool. */
    public function test_incomplete_orders_return_to_the_pool_at_wave_close(): void
    {
        $waveId = $this->wave('2026-09-01');
        $group = $this->createFromTemplate($this->template('Morning', [$this->maadi]), '2026-09-01');

        $assignment = $this->assign($this->order('Maadi'), $this->window('2026-09-01')->id, $group->id);

        $result = $this->lifecycle->closeWave($waveId);

        self::assertSame(1, $result['orders_released']);
        self::assertNull(
            DB::table('distribution_window_orders')->where('id', $assignment)->value('virtual_slot_id'),
            'released from the group',
        );
        self::assertNotNull(
            DB::table('distribution_window_orders')->find($assignment),
            'the assignment row itself survives — window history is not rewritten',
        );
    }

    /** TEST 9 — the next Wave creates a NEW Group, not a copy of the closed one. */
    public function test_the_next_wave_creates_a_new_group_for_the_same_template(): void
    {
        $template = $this->template('Morning', [$this->maadi]);

        $waveOne = $this->wave('2026-09-01');
        $closed = $this->createFromTemplate($template, '2026-09-01');
        $this->lifecycle->closeWave($waveOne);

        $waveTwo = $this->wave('2026-09-02');
        $fresh = $this->createFromTemplate($template, '2026-09-02');

        self::assertNotSame($closed->id, $fresh->id);
        self::assertSame($waveTwo, $fresh->preparation_wave_id);
        self::assertNull($fresh->closed_at);
    }

    /** Closing twice is harmless — the second run neither restamps nor re-releases. */
    public function test_closing_a_wave_twice_is_idempotent(): void
    {
        $waveId = $this->wave('2026-09-01');
        $group = $this->createFromTemplate($this->template('Morning', [$this->maadi]), '2026-09-01');

        $first = $this->lifecycle->closeWave($waveId);
        $stamp = $group->refresh()->closed_at;

        $second = $this->lifecycle->closeWave($waveId);

        self::assertSame(1, $first['closed']);
        self::assertSame(0, $second['closed']);
        self::assertEquals($stamp, $group->refresh()->closed_at, 'closure time was not rewritten');
    }

    // ── 10-11. Snapshot semantics ────────────────────────────────────────────

    /** TEST 10 — editing the Template does not reach a Group that already exists. */
    public function test_a_template_edit_does_not_mutate_an_existing_group(): void
    {
        $this->wave('2026-09-01');
        $template = $this->template('Morning', [$this->maadi]);
        $group = $this->createFromTemplate($template, '2026-09-01');

        $before = $this->groupZones($group->id);
        self::assertSame([$this->maadi], $before);

        $this->actingAs($this->user())
            ->patchJson(self::BASE."/group-templates/{$template->id}", [
                'zone_ids' => [$this->maadi, $this->helwan],
            ])->assertOk();

        self::assertSame($before, $this->groupZones($group->id), 'the group is a snapshot');
    }

    /** TEST 11 — a Group created afterwards uses the latest Template configuration. */
    public function test_a_new_wave_group_uses_the_latest_template_configuration(): void
    {
        $this->wave('2026-09-01');
        $template = $this->template('Morning', [$this->maadi]);
        $old = $this->createFromTemplate($template, '2026-09-01');

        $this->actingAs($this->user())
            ->patchJson(self::BASE."/group-templates/{$template->id}", [
                'zone_ids' => [$this->maadi, $this->helwan],
            ])->assertOk();

        $this->wave('2026-09-02');
        $fresh = $this->createFromTemplate($template, '2026-09-02');

        self::assertSame([$this->maadi], $this->groupZones($old->id), 'old unchanged');
        self::assertSame(
            [$this->maadi, $this->helwan],
            $this->groupZones($fresh->id),
            'new one has the added zone',
        );
    }

    // ── 12-14. Empty templates, lazy creation, reuse ─────────────────────────

    /** TEST 12 — a Template with no eligible work creates no Group. */
    public function test_a_template_with_no_eligible_orders_creates_no_group(): void
    {
        $waveId = $this->wave('2026-09-01');
        $template = $this->template('Morning', [$this->maadi]);

        $group = $this->lifecycle->ensureGroupForTemplate(
            $this->window('2026-09-01'),
            $template,
            $this->warehouse->id,
            $waveId,
            static fn (): int => 0,
        );

        self::assertNull($group);
        self::assertSame(0, VirtualCapacitySlot::query()->count(), 'no empty shell was created');
        self::assertNotNull($template->fresh(), 'and the template remains usable');
    }

    /** TEST 13 — work arriving later creates the Group lazily. */
    public function test_a_late_eligible_order_creates_the_group_lazily(): void
    {
        $waveId = $this->wave('2026-09-01');
        $template = $this->template('Morning', [$this->maadi]);
        $window = $this->window('2026-09-01');

        self::assertNull($this->lifecycle->ensureGroupForTemplate(
            $window, $template, $this->warehouse->id, $waveId, static fn (): int => 0,
        ));

        $group = $this->lifecycle->ensureGroupForTemplate(
            $window, $template, $this->warehouse->id, $waveId, static fn (): int => 3,
        );

        self::assertNotNull($group);
        self::assertSame($waveId, $group->preparation_wave_id);
        self::assertSame($template->id, $group->distribution_group_template_id);
    }

    /** TEST 14 — more work reuses the SAME Group, never a second one. */
    public function test_additional_eligible_orders_reuse_the_same_group(): void
    {
        $waveId = $this->wave('2026-09-01');
        $template = $this->template('Morning', [$this->maadi]);
        $window = $this->window('2026-09-01');

        $first = $this->lifecycle->ensureGroupForTemplate(
            $window, $template, $this->warehouse->id, $waveId, static fn (): int => 1,
        );
        $second = $this->lifecycle->ensureGroupForTemplate(
            $window, $template, $this->warehouse->id, $waveId, static fn (): int => 9,
        );

        self::assertNotNull($first);
        self::assertSame($first->id, $second?->id);
        self::assertSame(1, VirtualCapacitySlot::query()->count());
    }

    // ── 15-16. Capacity is a threshold, not a gate ───────────────────────────

    /** TEST 15/16 — 27 orders under a capacity of 20 make ONE Group of 27. */
    public function test_daily_creation_may_exceed_capacity_without_splitting(): void
    {
        $waveId = $this->wave('2026-09-01');

        for ($i = 0; $i < 27; $i++) {
            $this->order('Maadi');
        }
        $this->collect();

        $template = $this->template('Morning', [$this->maadi], capacity: 20);
        $window = $this->currentWindow();

        $group = $this->lifecycle->ensureGroupForTemplate(
            $window, $template, $this->warehouse->id, $waveId, static fn (): int => 27,
        );

        self::assertNotNull($group);
        self::assertSame(20, $group->capacity_orders, 'the planning threshold is preserved');
        self::assertSame(1, VirtualCapacitySlot::query()->count(), 'ONE group, no split');
        self::assertSame(
            27,
            DB::table('distribution_window_orders')->where('virtual_slot_id', $group->id)->count(),
            'all 27 orders are in it',
        );
    }

    /** The opt-out is SCOPED: manual attach still refuses to exceed capacity. */
    public function test_manual_zone_attach_still_enforces_capacity(): void
    {
        $this->wave('2026-09-01');

        for ($i = 0; $i < 5; $i++) {
            $this->order('Maadi');
        }
        $this->collect();

        $windowId = $this->currentWindow()->id;

        $slotId = (string) $this->actingAs($this->user())
            ->postJson(self::BASE."/windows/{$windowId}/slots", [
                'warehouse_id' => $this->warehouse->id,
                'code' => 'MANUAL-'.substr(uniqid(), -5),
                'capacity_orders' => 2,
            ])->assertSuccessful()->json('data.id');

        $this->actingAs($this->user())
            ->postJson(self::BASE."/windows/{$windowId}/slots/{$slotId}/zones", [
                'zone_id' => $this->maadi,
                'warehouse_id' => $this->warehouse->id,
            ])->assertStatus(422);
    }

    // ── 17-21. Everything else stays as it was ───────────────────────────────

    /** TEST 17 — Template Zone exclusivity is untouched. */
    public function test_template_zone_exclusivity_remains_enforced(): void
    {
        $this->template('Morning', [$this->maadi]);

        $this->actingAs($this->user())
            ->postJson(self::BASE.'/group-templates', [
                'name' => 'Evening',
                'zone_ids' => [$this->maadi],
            ])->assertStatus(422);
    }

    /** TEST 18 — tenancy holds: another company cannot see or close these Groups. */
    public function test_company_isolation_is_enforced(): void
    {
        $waveId = $this->wave('2026-09-01');
        $this->createFromTemplate($this->template('Morning', [$this->maadi]), '2026-09-01');

        $other = Company::factory()->create();
        $windowId = $this->window('2026-09-01')->id;

        $this->actingAs(User::factory()->create(['company_id' => $other->id]))
            ->getJson(self::BASE."/windows/{$windowId}/slots")
            ->assertStatus(404);

        self::assertCount(1, $this->lifecycle->activeGroupsForWave($waveId));
    }

    /** TEST 19/20 — no Driver and no Vehicle is assigned by any of this. */
    public function test_no_driver_or_vehicle_is_assigned_by_group_creation(): void
    {
        $waveId = $this->wave('2026-09-01');
        $this->createFromTemplate($this->template('Morning', [$this->maadi]), '2026-09-01');
        $this->lifecycle->closeWave($waveId);

        self::assertSame(0, DB::table('logistics_driver_vehicle_assignments')->count());
        self::assertSame(0, DB::table('vehicle_assignments')->count());
        self::assertSame(0, DB::table('driver_assignments')->count());
        self::assertSame(0, DB::table('loading_sessions')->count(), 'and no loading was started');
    }

    /** TEST 21 — Loading can still find the Group by its canonical identity. */
    public function test_the_group_remains_discoverable_by_its_canonical_identity(): void
    {
        $this->wave('2026-09-01');
        $group = $this->createFromTemplate($this->template('Morning', [$this->maadi]), '2026-09-01');

        // The identity Loading uses is the Group's own id — unchanged by this task.
        $found = VirtualCapacitySlot::query()->find($group->id);

        self::assertNotNull($found);
        self::assertSame($group->code, $found->code);
        self::assertSame($this->warehouse->id, $found->warehouse_id);
    }

    /** No Trip is created or split by any of this. */
    public function test_no_trip_is_created_or_split(): void
    {
        $waveId = $this->wave('2026-09-01');
        $this->createFromTemplate($this->template('Morning', [$this->maadi]), '2026-09-01');
        $this->lifecycle->closeWave($waveId);

        self::assertSame(0, DB::table('distribution_trips')->count());
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    /** An active engine wave for a planning date. Returns its id. */
    private function wave(string $date): string
    {
        $id = (string) Str::uuid();

        // Every column the table declares NOT NULL without a default. `wave_number` and
        // `created_by` are easy to miss: a regex-filtered SHOW COLUMNS hides them.
        DB::table('preparation_waves')->insert([
            'id' => $id,
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouse->id,
            'wave_number' => 'W-'.substr(uniqid(), -8),
            'planning_date' => $date,
            'status' => 'collecting',
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

    private function currentWindow(): DistributionWindow
    {
        return DistributionWindow::query()
            ->where('company_id', $this->company->id)
            ->orderByDesc('window_date')
            ->firstOrFail();
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

    /** Creation through the canonical service, for a given planning day. */
    private function createFromTemplate(
        DistributionGroupTemplate $template,
        string $date,
    ): VirtualCapacitySlot {
        return app(GroupTemplateService::class)->applyToNewGroup(
            window: $this->window($date),
            template: $template,
            warehouseId: $this->warehouse->id,
            code: 'DG-'.substr(uniqid(), -8),
            nameOverride: null,
            capacityOverride: null,
            capacityProvided: false,
            zoneIdsOverride: null,
        );
    }

    /** @return list<array<string, mixed>> */
    private function boardGroups(string $windowId): array
    {
        // The canonical board read. There is no `GET /windows/{window}`; the group list
        // is its own endpoint, backed by slotSummaries().
        return $this->actingAs($this->user())
            ->getJson(self::BASE."/windows/{$windowId}/slots")
            ->assertOk()
            ->json('data') ?? [];
    }

    /** @return list<int> */
    private function groupZones(string $groupId): array
    {
        return DB::table('distribution_slot_zones')
            ->where('virtual_slot_id', $groupId)
            ->orderBy('distribution_zone_id')
            ->pluck('distribution_zone_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    private function assign(Order $order, string $windowId, string $slotId): string
    {
        $id = (string) Str::uuid();

        DB::table('distribution_window_orders')->insert([
            'id' => $id,
            'distribution_window_id' => $windowId,
            'company_id' => $this->company->id,
            'order_id' => $order->id,
            'virtual_slot_id' => $slotId,
            'assignment_source' => 'auto',
            'assigned_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function collect(): void
    {
        $this->actingAs($this->user())->postJson(self::BASE.'/windows/collect')->assertOk();
    }

    private function zone(string $name): int
    {
        return (int) DB::table('distribution_zones')->insertGetId([
            'code' => 'LC-'.substr(uniqid(), -6),
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
            'order_number' => 'ORD-LC-'.uniqid(),
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

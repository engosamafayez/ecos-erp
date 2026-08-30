<?php

declare(strict_types=1);

namespace Tests\Feature\Logistics;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-DISTRIBUTION-TEMPLATES-ZONE-EXCLUSIVITY-AND-DRIVER-RECOMMENDATIONS-001
 *
 * ONE ZONE -> ONE TEMPLATE, per company — and a Move that is a move, not a copy.
 *
 * ┌─ WHY THE DATABASE CANNOT DO THIS ────────────────────────────────────────┐
 * │ `dist_group_tpl_zone_unique` is on (template_id, zone_id): it stops the   │
 * │ same Zone appearing TWICE IN ONE template and happily permits it in two.  │
 * │ A DB-level key would have to be scoped per company, and the pivot carries │
 * │ no company_id — that is a migration, so the invariant is enforced in the  │
 * │ service under a Zone row lock and the migration is left to the owner.     │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ARCHIVED TEMPLATES DO NOT OWN ZONES. `archive()` soft-deletes and deliberately keeps
 * its Zone rows so a restore is intact, so the pivot legitimately holds rows for
 * templates nobody can open. The live database already has this shape. Counting them
 * would strand a Zone forever, which `test_an_archived_template_releases_its_zones`
 * pins.
 *
 * CONFIGURATION ONLY. The last tests assert a Move touches no Order, Group, Trip,
 * Driver, Vehicle or Loading row, and that a Group created earlier keeps its own
 * snapshot when the template it came from is edited afterwards.
 */
final class DistributionTemplateZoneExclusivityTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/logistics/distribution';

    private Company $company;

    private Warehouse $warehouse;

    private int $maadi;

    private int $helwan;

    private int $giza;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('distribution.window.opens_at', '00:00');
        config()->set('distribution.window.closes_at', '23:59');

        $this->company = Company::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);

        $this->maadi = $this->zone('Maadi');
        $this->helwan = $this->zone('Helwan');
        $this->giza = $this->zone('Giza');
    }

    // ── Exclusivity ──────────────────────────────────────────────────────────

    /** The base case: a free Zone goes in without ceremony. */
    public function test_a_free_zone_can_be_added_to_a_template(): void
    {
        $template = $this->createTemplate('Morning Cairo', [$this->maadi, $this->helwan]);

        self::assertSame([$this->maadi, $this->helwan], $this->zonesOf($template['id']));
    }

    /** The invariant: the same Zone cannot sit in two templates of one company. */
    public function test_a_zone_cannot_belong_to_two_templates(): void
    {
        $morning = $this->createTemplate('Morning Cairo', [$this->maadi]);

        $this->actingAs($this->user())
            ->postJson(self::BASE.'/group-templates', [
                'name' => 'Evening Cairo',
                'zone_ids' => [$this->maadi],
            ])->assertStatus(422);

        // And the first template still holds it.
        self::assertSame([$this->maadi], $this->zonesOf($morning['id']));
        self::assertSame(1, DB::table('distribution_group_templates')->whereNull('deleted_at')->count());
    }

    /** The refusal names the owner, so the operator can act on it. */
    public function test_the_refusal_names_the_template_that_owns_the_zone(): void
    {
        $this->createTemplate('Morning Cairo', [$this->maadi]);

        $response = $this->actingAs($this->user())
            ->postJson(self::BASE.'/group-templates', [
                'name' => 'Evening Cairo',
                'zone_ids' => [$this->maadi],
            ])->assertStatus(422);

        self::assertStringContainsString('Morning Cairo', (string) $response->json('message'));
    }

    /** Editing a template to grab another's Zone is the same refusal. */
    public function test_an_edit_cannot_steal_a_zone_from_another_template(): void
    {
        $this->createTemplate('Morning Cairo', [$this->maadi]);
        $evening = $this->createTemplate('Evening Cairo', [$this->giza]);

        $this->actingAs($this->user())
            ->patchJson(self::BASE."/group-templates/{$evening['id']}", [
                'zone_ids' => [$this->giza, $this->maadi],
            ])->assertStatus(422);

        self::assertSame([$this->giza], $this->zonesOf($evening['id']));
    }

    /** A template keeping its OWN zones on edit is not a conflict with itself. */
    public function test_a_template_can_keep_its_own_zones_on_edit(): void
    {
        $template = $this->createTemplate('Morning Cairo', [$this->maadi, $this->helwan]);

        $this->actingAs($this->user())
            ->patchJson(self::BASE."/group-templates/{$template['id']}", [
                'name' => 'Morning Cairo v2',
                'zone_ids' => [$this->maadi, $this->helwan],
            ])->assertOk();

        self::assertSame([$this->maadi, $this->helwan], $this->zonesOf($template['id']));
    }

    // ── Visibility ───────────────────────────────────────────────────────────

    /** PART 6 — the list reports who owns each Zone, from the server. */
    public function test_the_template_list_reports_zone_ownership(): void
    {
        $morning = $this->createTemplate('Morning Cairo', [$this->maadi, $this->helwan]);

        $ownership = $this->actingAs($this->user())
            ->getJson(self::BASE.'/group-templates')->assertOk()->json('zone_ownership');

        $byZone = [];

        foreach ($ownership as $row) {
            $byZone[(int) $row['zone_id']] = $row;
        }

        self::assertArrayHasKey($this->maadi, $byZone);
        self::assertSame($morning['id'], $byZone[$this->maadi]['template_id']);
        self::assertSame('Morning Cairo', $byZone[$this->maadi]['template_name']);
        self::assertArrayNotHasKey($this->giza, $byZone, 'a free zone has no owner');
    }

    /** An archived template must not keep a Zone hostage. */
    public function test_an_archived_template_releases_its_zones(): void
    {
        $morning = $this->createTemplate('Morning Cairo', [$this->maadi]);

        $this->actingAs($this->user())
            ->deleteJson(self::BASE."/group-templates/{$morning['id']}")
            ->assertSuccessful();

        // Its pivot rows are deliberately kept for a restore...
        self::assertSame([$this->maadi], $this->zonesOf($morning['id']));

        // ...but the zone reads as free, and can be taken by a new template.
        $ownership = $this->actingAs($this->user())
            ->getJson(self::BASE.'/group-templates')->assertOk()->json('zone_ownership');
        self::assertSame([], $ownership);

        $this->createTemplate('Evening Cairo', [$this->maadi]);
    }

    // ── Move ─────────────────────────────────────────────────────────────────

    /** PART 7/8 — with confirmation the Zone MOVES: added here, removed there. */
    public function test_a_confirmed_move_transfers_the_zone(): void
    {
        $morning = $this->createTemplate('Morning Cairo', [$this->maadi, $this->helwan]);
        $evening = $this->createTemplate('Evening Cairo', [$this->giza]);

        $this->actingAs($this->user())
            ->patchJson(self::BASE."/group-templates/{$evening['id']}", [
                'zone_ids' => [$this->giza, $this->maadi],
                'move_zones' => true,
            ])->assertOk();

        self::assertSame([$this->giza, $this->maadi], $this->zonesOf($evening['id']), 'arrived');
        self::assertSame([$this->helwan], $this->zonesOf($morning['id']), 'and left the old one');
    }

    /** It is a MOVE, not a copy — the zone exists in exactly one template afterwards. */
    public function test_a_move_never_duplicates_the_zone(): void
    {
        $this->createTemplate('Morning Cairo', [$this->maadi]);
        $evening = $this->createTemplate('Evening Cairo', []);

        $this->actingAs($this->user())
            ->patchJson(self::BASE."/group-templates/{$evening['id']}", [
                'zone_ids' => [$this->maadi],
                'move_zones' => true,
            ])->assertOk();

        $holders = DB::table('distribution_group_template_zones as z')
            ->join('distribution_group_templates as t', 't.id', '=', 'z.distribution_group_template_id')
            ->whereNull('t.deleted_at')
            ->where('z.distribution_zone_id', $this->maadi)
            ->count();

        self::assertSame(1, $holders, 'exactly one live template holds it');
    }

    /** A Move can be performed while CREATING a template too. */
    public function test_a_confirmed_move_works_on_create(): void
    {
        $morning = $this->createTemplate('Morning Cairo', [$this->maadi, $this->helwan]);

        $created = $this->actingAs($this->user())
            ->postJson(self::BASE.'/group-templates', [
                'name' => 'Evening Cairo',
                'zone_ids' => [$this->maadi],
                'move_zones' => true,
            ])->assertStatus(201)->json('data');

        self::assertSame([$this->maadi], $this->zonesOf($created['id']));
        self::assertSame([$this->helwan], $this->zonesOf($morning['id']));
    }

    /** Without the flag nothing moves — the confirmation is what authorises it. */
    public function test_an_unconfirmed_move_changes_nothing(): void
    {
        $morning = $this->createTemplate('Morning Cairo', [$this->maadi]);
        $evening = $this->createTemplate('Evening Cairo', [$this->giza]);

        $this->actingAs($this->user())
            ->patchJson(self::BASE."/group-templates/{$evening['id']}", [
                'zone_ids' => [$this->giza, $this->maadi],
            ])->assertStatus(422);

        self::assertSame([$this->maadi], $this->zonesOf($morning['id']), 'source untouched');
        self::assertSame([$this->giza], $this->zonesOf($evening['id']), 'target untouched');
    }

    /** A failed save leaves the whole configuration as it was — including the name. */
    public function test_a_failed_move_rolls_back_the_whole_edit(): void
    {
        $this->createTemplate('Morning Cairo', [$this->maadi]);
        $evening = $this->createTemplate('Evening Cairo', [$this->giza]);

        $this->actingAs($this->user())
            ->patchJson(self::BASE."/group-templates/{$evening['id']}", [
                'name' => 'Renamed',
                'zone_ids' => [$this->maadi],
            ])->assertStatus(422);

        $row = DB::table('distribution_group_templates')->where('id', $evening['id'])->first();
        self::assertSame('Evening Cairo', $row->name, 'the name did not change either');
        self::assertSame([$this->giza], $this->zonesOf($evening['id']));
    }

    // ── Tenancy ──────────────────────────────────────────────────────────────

    /** PART 17 — exclusivity is per company; another tenant may use the same Zone. */
    public function test_another_company_may_use_the_same_zone(): void
    {
        $this->createTemplate('Morning Cairo', [$this->maadi]);

        $other = Company::factory()->create();
        $otherUser = User::factory()->create(['company_id' => $other->id]);

        $created = $this->actingAs($otherUser)
            ->postJson(self::BASE.'/group-templates', [
                'name' => 'Morning Cairo',
                'zone_ids' => [$this->maadi],
            ])->assertStatus(201)->json('data');

        self::assertSame([$this->maadi], $this->zonesOf($created['id']));
    }

    /** And one company's ownership map never mentions another's templates. */
    public function test_the_ownership_map_is_company_scoped(): void
    {
        $other = Company::factory()->create();
        $otherUser = User::factory()->create(['company_id' => $other->id]);

        $this->actingAs($otherUser)
            ->postJson(self::BASE.'/group-templates', [
                'name' => 'Theirs',
                'zone_ids' => [$this->maadi],
            ])->assertStatus(201);

        $ownership = $this->actingAs($this->user())
            ->getJson(self::BASE.'/group-templates')->assertOk()->json('zone_ownership');

        self::assertSame([], $ownership, 'their zone is not my conflict');
    }

    // ── Snapshot + isolation ─────────────────────────────────────────────────

    /**
     * PART 9 — the critical one. A Group created from a template keeps its own Zones
     * when the template is edited afterwards. Nothing is retroactive.
     */
    public function test_a_group_keeps_its_zones_when_the_template_changes_later(): void
    {
        $template = $this->createTemplate('Morning Cairo', [$this->maadi, $this->helwan]);
        $windowId = $this->window();

        $group = $this->actingAs($this->user())
            ->postJson(self::BASE."/windows/{$windowId}/group-templates/{$template['id']}/apply", [
                'warehouse_id' => $this->warehouse->id,
                'code' => 'DG-SNAP',
            ])->assertSuccessful()->json('data');

        // The apply endpoint publishes the Group as `slot_id`, not `id`.
        $before = $this->groupZones($group['slot_id']);
        self::assertSame([$this->maadi, $this->helwan], $before);

        // Now shrink the template.
        $this->actingAs($this->user())
            ->patchJson(self::BASE."/group-templates/{$template['id']}", [
                'zone_ids' => [$this->helwan],
            ])->assertOk();

        self::assertSame([$this->helwan], $this->zonesOf($template['id']), 'the template changed');
        self::assertSame($before, $this->groupZones($group['slot_id']), 'the group did NOT');
    }

    /** A Move is template configuration and nothing else. */
    public function test_a_move_mutates_no_runtime_data(): void
    {
        $morning = $this->createTemplate('Morning Cairo', [$this->maadi]);
        $evening = $this->createTemplate('Evening Cairo', []);
        $windowId = $this->window();

        $this->actingAs($this->user())
            ->postJson(self::BASE."/windows/{$windowId}/group-templates/{$morning['id']}/apply", [
                'warehouse_id' => $this->warehouse->id,
                'code' => 'DG-KEEP',
            ])->assertSuccessful();

        $before = [
            'groups' => DB::table('distribution_virtual_slots')->count(),
            'group_zones' => DB::table('distribution_slot_zones')->count(),
            'trips' => DB::table('distribution_trips')->count(),
            'trip_orders' => DB::table('distribution_trip_orders')->count(),
            'window_orders' => DB::table('distribution_window_orders')->count(),
            'orders' => DB::table('orders')->count(),
            'drivers' => DB::table('logistics_drivers')->count(),
            'pairings' => DB::table('logistics_driver_vehicle_assignments')->count(),
            'loading' => DB::table('loading_sessions')->count(),
        ];

        $this->actingAs($this->user())
            ->patchJson(self::BASE."/group-templates/{$evening['id']}", [
                'zone_ids' => [$this->maadi],
                'move_zones' => true,
            ])->assertOk();

        foreach ($before as $label => $count) {
            self::assertSame($count, match ($label) {
                'groups' => DB::table('distribution_virtual_slots')->count(),
                'group_zones' => DB::table('distribution_slot_zones')->count(),
                'trips' => DB::table('distribution_trips')->count(),
                'trip_orders' => DB::table('distribution_trip_orders')->count(),
                'window_orders' => DB::table('distribution_window_orders')->count(),
                'orders' => DB::table('orders')->count(),
                'drivers' => DB::table('logistics_drivers')->count(),
                'pairings' => DB::table('logistics_driver_vehicle_assignments')->count(),
                'loading' => DB::table('loading_sessions')->count(),
            }, $label.' must be untouched by a template move');
        }
    }

    /**
     * PART 2/20 — no Driver or Vehicle is stored on a Template. Asserted against the
     * schema itself, so adding such a column later trips this test deliberately.
     */
    public function test_a_template_stores_no_driver_or_vehicle(): void
    {
        $columns = array_map(
            static fn ($c): string => $c->Field,
            DB::select('SHOW COLUMNS FROM distribution_group_templates'),
        );

        foreach ($columns as $column) {
            self::assertStringNotContainsString('driver', strtolower($column));
            self::assertStringNotContainsString('vehicle', strtolower($column));
        }
    }

    /** A template with no Zones stays valid — the existing contract allows it. */
    public function test_a_template_with_no_zones_is_still_valid(): void
    {
        $template = $this->createTemplate('Empty', []);

        self::assertSame([], $this->zonesOf($template['id']));
        self::assertSame(0, (int) $template['zones_count']);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    /**
     * @param  list<int>  $zoneIds
     * @return array<string, mixed>
     */
    private function createTemplate(string $name, array $zoneIds): array
    {
        return $this->actingAs($this->user())
            ->postJson(self::BASE.'/group-templates', [
                'name' => $name,
                'zone_ids' => $zoneIds,
            ])->assertStatus(201)->json('data');
    }

    /** @return list<int> */
    private function zonesOf(string $templateId): array
    {
        return DB::table('distribution_group_template_zones')
            ->where('distribution_group_template_id', $templateId)
            ->orderBy('id')
            ->pluck('distribution_zone_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /** @return list<int> */
    private function groupZones(string $groupId): array
    {
        return DB::table('distribution_slot_zones')
            ->where('virtual_slot_id', $groupId)
            ->orderBy('id')
            ->pluck('distribution_zone_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * A Window, inserted directly.
     *
     * NOT via the collector: it opens a Window inside its candidate loop, so a sweep with
     * no Orders creates nothing — and these tests deliberately have no Orders. The Window
     * is scaffolding here, not the thing under test.
     */
    private function window(): string
    {
        $id = (string) \Illuminate\Support\Str::uuid();

        DB::table('distribution_windows')->insert([
            'id' => $id,
            'company_id' => $this->company->id,
            'window_date' => now()->toDateString(),
            'status' => 'open',
            'opens_at' => now()->startOfDay(),
            'closes_at' => now()->endOfDay(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function zone(string $name): int
    {
        return (int) DB::table('distribution_zones')->insertGetId([
            'code' => 'TZ-'.substr(uniqid(), -6),
            'name_ar' => $name.'-'.uniqid(),
            'name_en' => $name,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function user(): User
    {
        return User::factory()->create(['company_id' => $this->company->id]);
    }
}

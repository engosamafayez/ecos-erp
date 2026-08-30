<?php

declare(strict_types=1);

namespace Tests\Feature\Logistics;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-LOGISTICS-TEMPLATE-DRIVER-RECOMMENDATIONS-AND-VEHICLE-CREATION-FIX-001
 *
 * Recommended Drivers on a Distribution Group Template — SUGGESTIONS ONLY.
 *
 * The frozen contract these tests pin:
 *   • a template may recommend MANY drivers;
 *   • the SAME driver may be recommended by MANY templates (no unique on driver id);
 *   • recommendations survive save / reload / edit;
 *   • they are tenant-scoped (a foreign company's driver is refused);
 *   • empty is valid;
 *   • applying a template NEVER turns a recommendation into a Driver assignment.
 */
final class DistributionTemplateDriverRecommendationsTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/logistics/distribution';

    private Company $company;

    private Warehouse $warehouse;

    private int $maadi;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('distribution.window.opens_at', '00:00');
        config()->set('distribution.window.closes_at', '23:59');

        $this->company = Company::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
        $this->maadi = $this->zone('Maadi');
    }

    // ── Migration ──────────────────────────────────────────────────────────────

    /** The pivot exists, is unique PER TEMPLATE, and is NOT unique on driver alone. */
    public function test_the_recommended_drivers_pivot_is_unique_per_template_not_per_driver(): void
    {
        self::assertTrue(
            \Illuminate\Support\Facades\Schema::hasTable('distribution_group_template_drivers'),
        );

        $indexes = DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'distribution_group_template_drivers')
            ->where('non_unique', 0)
            ->get()
            ->groupBy('INDEX_NAME');

        // A unique across (template, driver) must exist.
        $composite = $indexes->first(
            fn ($rows) => $rows->pluck('COLUMN_NAME')->sort()->values()->all()
                === collect(['distribution_group_template_id', 'logistics_driver_id'])->sort()->values()->all(),
        );
        self::assertNotNull($composite, 'Expected a unique index on (template_id, driver_id).');

        // A unique on driver id ALONE must NOT exist — one driver, many templates.
        $driverOnly = $indexes->first(
            fn ($rows) => $rows->pluck('COLUMN_NAME')->all() === ['logistics_driver_id'],
        );
        self::assertNull($driverOnly, 'A driver must be recommendable by many templates.');
    }

    // ── Create / reload ──────────────────────────────────────────────────────────

    public function test_a_template_is_created_with_multiple_recommended_drivers(): void
    {
        $a = $this->driver($this->company->id, 'Ahmed');
        $b = $this->driver($this->company->id, 'Mohamed');

        $data = $this->actingAs($this->user())
            ->postJson(self::BASE.'/group-templates', [
                'name' => 'Cairo AM',
                'zone_ids' => [$this->maadi],
                'driver_ids' => [$a, $b],
            ])->assertStatus(201)->json('data');

        self::assertEqualsCanonicalizing([$a, $b], $data['driver_ids']);
        self::assertSame(2, $data['drivers_count']);
        self::assertEqualsCanonicalizing([$a, $b], $this->recommendedOf($data['id']));
    }

    public function test_recommended_drivers_persist_on_reload(): void
    {
        $a = $this->driver($this->company->id, 'Ahmed');
        $created = $this->createTemplate('Persist', [], [$a]);

        $reloaded = $this->actingAs($this->user())
            ->getJson(self::BASE.'/group-templates')
            ->assertOk()
            ->json('data');

        $row = collect($reloaded)->firstWhere('id', $created['id']);
        self::assertNotNull($row);
        self::assertSame([$a], $row['driver_ids']);
        self::assertSame(1, $row['drivers_count']);
    }

    public function test_empty_recommendations_are_valid(): void
    {
        $data = $this->createTemplate('No drivers', []);

        self::assertSame([], $data['driver_ids']);
        self::assertSame(0, $data['drivers_count']);
    }

    // ── Edit ─────────────────────────────────────────────────────────────────────

    public function test_editing_replaces_the_recommended_driver_set(): void
    {
        $a = $this->driver($this->company->id, 'Ahmed');
        $b = $this->driver($this->company->id, 'Mohamed');
        $c = $this->driver($this->company->id, 'Mahmoud');

        $tpl = $this->createTemplate('Editable', [], [$a, $b]);

        // Remove one, add another.
        $updated = $this->actingAs($this->user())
            ->patchJson(self::BASE.'/group-templates/'.$tpl['id'], [
                'driver_ids' => [$b, $c],
            ])->assertOk()->json('data');

        self::assertEqualsCanonicalizing([$b, $c], $updated['driver_ids']);
        self::assertEqualsCanonicalizing([$b, $c], $this->recommendedOf($tpl['id']));
    }

    public function test_omitting_driver_ids_on_edit_leaves_recommendations_untouched(): void
    {
        $a = $this->driver($this->company->id, 'Ahmed');
        $tpl = $this->createTemplate('Keep', [], [$a]);

        // A name-only edit must not clear the recommendations.
        $this->actingAs($this->user())
            ->patchJson(self::BASE.'/group-templates/'.$tpl['id'], ['name' => 'Keep renamed'])
            ->assertOk();

        self::assertSame([$a], $this->recommendedOf($tpl['id']));
    }

    public function test_an_empty_driver_ids_array_on_edit_clears_recommendations(): void
    {
        $a = $this->driver($this->company->id, 'Ahmed');
        $tpl = $this->createTemplate('Clearable', [], [$a]);

        $this->actingAs($this->user())
            ->patchJson(self::BASE.'/group-templates/'.$tpl['id'], ['driver_ids' => []])
            ->assertOk();

        self::assertSame([], $this->recommendedOf($tpl['id']));
    }

    // ── Tenancy ──────────────────────────────────────────────────────────────────

    public function test_a_foreign_companys_driver_cannot_be_recommended(): void
    {
        $other = Company::factory()->create();
        $foreign = $this->driver($other->id, 'Outsider');

        $this->actingAs($this->user())
            ->postJson(self::BASE.'/group-templates', [
                'name' => 'Cross tenant',
                'driver_ids' => [$foreign],
            ])->assertStatus(422);

        // Nothing was persisted.
        self::assertSame(0, DB::table('distribution_group_template_drivers')->count());
    }

    public function test_an_archived_driver_cannot_be_recommended(): void
    {
        $archived = $this->driver($this->company->id, 'Retired', 'archived');

        $this->actingAs($this->user())
            ->postJson(self::BASE.'/group-templates', [
                'name' => 'Archived driver',
                'driver_ids' => [$archived],
            ])->assertStatus(422);
    }

    public function test_the_same_driver_may_be_recommended_by_many_templates(): void
    {
        $shared = $this->driver($this->company->id, 'Popular');

        $one = $this->createTemplate('Template One', [], [$shared]);
        $two = $this->createTemplate('Template Two', [], [$shared]);

        self::assertSame([$shared], $this->recommendedOf($one['id']));
        self::assertSame([$shared], $this->recommendedOf($two['id']));
    }

    // ── Apply safety ─────────────────────────────────────────────────────────────

    public function test_applying_a_template_assigns_no_driver(): void
    {
        $recommended = $this->driver($this->company->id, 'Ahmed');
        $tpl = $this->createTemplate('Applied', [$this->maadi], [$recommended]);

        $window = $this->window();

        $pairingsBefore = DB::table('logistics_driver_vehicle_assignments')->count();

        $this->actingAs($this->user())
            ->postJson(self::BASE."/windows/{$window}/group-templates/{$tpl['id']}/apply", [
                'warehouse_id' => $this->warehouse->id,
                'code' => 'DG-APPLIED',
            ])->assertStatus(201);

        // Applying created NO driver/vehicle pairing.
        self::assertSame(
            $pairingsBefore,
            DB::table('logistics_driver_vehicle_assignments')->count(),
        );

        // The created Group carries no driver/vehicle column value.
        $group = DB::table('distribution_virtual_slots')
            ->where('code', 'DG-APPLIED')
            ->first();
        self::assertNotNull($group);

        // The template's recommendation is untouched — it stayed template metadata.
        self::assertSame([$recommended], $this->recommendedOf($tpl['id']));
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────────

    /**
     * @param  list<int>  $zoneIds
     * @param  list<int>  $driverIds
     * @return array<string, mixed>
     */
    private function createTemplate(string $name, array $zoneIds, array $driverIds = []): array
    {
        return $this->actingAs($this->user())
            ->postJson(self::BASE.'/group-templates', [
                'name' => $name,
                'zone_ids' => $zoneIds,
                'driver_ids' => $driverIds,
            ])->assertStatus(201)->json('data');
    }

    /** @return list<int> */
    private function recommendedOf(string $templateId): array
    {
        return DB::table('distribution_group_template_drivers')
            ->where('distribution_group_template_id', $templateId)
            ->orderBy('logistics_driver_id')
            ->pluck('logistics_driver_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    private function driver(string $companyId, string $name, string $status = 'active'): int
    {
        $suffix = substr(uniqid(), -6);

        return (int) DB::table('logistics_drivers')->insertGetId([
            'company_id' => $companyId,
            'uuid' => (string) Str::uuid(),
            'driver_code' => 'DRV-'.$suffix,
            'full_name' => $name,
            'mobile' => '0100'.$suffix,
            'national_id' => '2'.str_pad($suffix, 13, '0'),
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function zone(string $name): int
    {
        return (int) DB::table('distribution_zones')->insertGetId([
            'code' => 'TZ-'.substr(uniqid(), -6),
            'name_ar' => $name.'-'.uniqid(),
            'name_en' => $name,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function window(): string
    {
        $id = (string) Str::uuid();

        DB::table('distribution_windows')->insert([
            'id' => $id,
            'company_id' => $this->company->id,
            'window_date' => now()->toDateString(),
            'status' => 'open',
            'opens_at' => now()->startOfDay(),
            'closes_at' => now()->endOfDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function user(): User
    {
        return User::factory()->create(['company_id' => $this->company->id]);
    }
}

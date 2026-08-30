<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Core\FeatureFlags\FeatureFlagService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\IAM\Domain\Models\Role;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Operations\Preparation\Domain\Enums\WaveStatus;
use Modules\Operations\Preparation\Domain\Models\PreparationWave;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-PREPARATION-WORKSPACE-MOBILE-UX-ACTIVE-WAVE-001 — HTTP contract for the canonical
 * current-active-wave resolution (§3-§6) and the single-source, live, quantity-weighted
 * wave completion (§19-§21).
 */
class WaveCurrentAndKpisHttpTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
        $this->user = User::factory()->create(['company_id' => $this->company->id]);

        $role = Role::create(['name' => 'System Admin', 'slug' => 'sysadmin', 'is_system' => true]);
        $this->user->roles()->attach($role->id);

        $flags = app(FeatureFlagService::class);
        $flags->enable('modules.preparation_os', $this->company->id);
        $flags->enable('workflow.stages.preparation', $this->company->id);
    }

    // ── Current active wave (§3-§6) ─────────────────────────────────────────────

    public function test_current_returns_the_single_active_wave(): void
    {
        $wave = $this->makeWave(WaveStatus::Collecting);
        // A terminal wave must not count as active.
        $this->makeWave(WaveStatus::Completed);

        $this->actingAs($this->user)
            ->getJson('/api/preparation/waves/current')
            ->assertOk()
            ->assertJsonPath('data.active_count', 1)
            ->assertJsonPath('data.wave.id', $wave->id)
            ->assertJsonCount(1, 'data.waves');
    }

    public function test_current_reports_no_active_wave(): void
    {
        // Only a closed wave exists → nothing is currently active.
        $this->makeWave(WaveStatus::Completed);

        $this->actingAs($this->user)
            ->getJson('/api/preparation/waves/current')
            ->assertOk()
            ->assertJsonPath('data.active_count', 0)
            ->assertJsonPath('data.wave', null)
            ->assertJsonCount(0, 'data.waves');
    }

    public function test_current_flags_multiple_active_waves_without_picking_one(): void
    {
        $a = $this->makeWave(WaveStatus::Collecting, '2026-08-27');
        $b = $this->makeWave(WaveStatus::Preparing, '2026-08-28');

        $response = $this->actingAs($this->user)
            ->getJson('/api/preparation/waves/current')
            ->assertOk()
            ->assertJsonPath('data.active_count', 2)
            // Never silently picks one when several are active (§6).
            ->assertJsonPath('data.wave', null)
            ->assertJsonCount(2, 'data.waves');

        $ids = array_column($response->json('data.waves'), 'id');
        $this->assertContains($a->id, $ids);
        $this->assertContains($b->id, $ids);
    }

    public function test_current_isolates_other_companies(): void
    {
        $mine = $this->makeWave(WaveStatus::Collecting);

        // Another company's active wave must never surface here.
        $other = Company::factory()->create();
        $otherWarehouse = Warehouse::factory()->create(['company_id' => $other->id]);
        PreparationWave::create($this->waveAttributes(WaveStatus::Collecting) + [
            'company_id' => $other->id,
            'warehouse_id' => $otherWarehouse->id,
            'wave_number' => 'PREP-OTHER-'.random_int(1, 99999),
        ]);

        $this->actingAs($this->user)
            ->getJson('/api/preparation/waves/current')
            ->assertOk()
            ->assertJsonPath('data.active_count', 1)
            ->assertJsonPath('data.wave.id', $mine->id);
    }

    // ── Live, quantity-weighted completion (§19-§21) ────────────────────────────

    public function test_kpis_completion_is_quantity_weighted_and_live(): void
    {
        $wave = $this->makeWave(WaveStatus::Preparing);
        // Product A fully prepared (10/10), Product B untouched (0/5).
        $this->seedProductDemand($wave, 'prod-a', required: 10.0, prepared: 10.0);
        $this->seedProductDemand($wave, 'prod-b', required: 5.0, prepared: 0.0);

        $this->actingAs($this->user)
            ->getJson("/api/preparation/waves/{$wave->id}/kpis")
            ->assertOk()
            // 10 prepared / 15 required = 66.67% — quantity-weighted, NOT the 50% a naive
            // average of the two product percentages (100% and 0%) would give.
            ->assertJsonPath('data.completion_pct', fn ($v): bool => abs((float) $v - 66.67) < 0.01)
            ->assertJsonPath('data.total_units_required', fn ($v): bool => (float) $v === 15.0)
            ->assertJsonPath('data.total_units_prepared', fn ($v): bool => (float) $v === 10.0)
            ->assertJsonPath('data.products_count', 2)
            ->assertJsonPath('data.prepared_count', 1)
            ->assertJsonPath('data.remaining_count', 1);
    }

    public function test_kpis_completion_is_zero_when_nothing_prepared(): void
    {
        $wave = $this->makeWave(WaveStatus::Preparing);
        $this->seedProductDemand($wave, 'prod-a', required: 8.0, prepared: 0.0);

        $this->actingAs($this->user)
            ->getJson("/api/preparation/waves/{$wave->id}/kpis")
            ->assertOk()
            ->assertJsonPath('data.completion_pct', fn ($v): bool => (float) $v === 0.0);
    }

    public function test_kpis_completion_is_hundred_when_all_prepared(): void
    {
        $wave = $this->makeWave(WaveStatus::Preparing);
        $this->seedProductDemand($wave, 'prod-a', required: 4.0, prepared: 4.0);
        $this->seedProductDemand($wave, 'prod-b', required: 6.0, prepared: 6.0);

        $this->actingAs($this->user)
            ->getJson("/api/preparation/waves/{$wave->id}/kpis")
            ->assertOk()
            ->assertJsonPath('data.completion_pct', fn ($v): bool => (float) $v === 100.0);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function waveAttributes(WaveStatus $status, ?string $planningDate = null): array
    {
        return [
            'planning_date' => $planningDate ?? today()->toDateString(),
            'status' => $status->value,
            'orders_count' => 0,
            'products_count' => 0,
            'lines_count' => 0,
            'total_units_required' => 0,
            'total_units_prepared' => 0,
            'shortage_detected' => false,
            'wave_type' => 'engine',
            'created_by' => (string) $this->user->id,
            'updated_by' => (string) $this->user->id,
        ];
    }

    private function makeWave(WaveStatus $status, ?string $planningDate = null): PreparationWave
    {
        return PreparationWave::create($this->waveAttributes($status, $planningDate) + [
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouse->id,
            'wave_number' => 'PREP-'.random_int(1, 999999),
        ]);
    }

    private function seedProductDemand(PreparationWave $wave, string $productId, float $required, float $prepared): void
    {
        DB::table('wave_product_demand')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $wave->company_id,
            'warehouse_id' => $wave->warehouse_id,
            'preparation_wave_id' => $wave->id,
            'product_id' => $productId,
            'product_name' => 'Product '.$productId,
            'required_qty' => $required,
            'prepared_qty' => $prepared,
            'remaining_qty' => max(0.0, $required - $prepared),
            'orders_count' => 1,
            'completion_pct' => $required > 0 ? ($prepared / $required) * 100 : 0,
            'last_calculated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

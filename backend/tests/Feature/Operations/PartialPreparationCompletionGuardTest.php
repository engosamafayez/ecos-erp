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
 * TASK-PREPARATION-MANUAL-REMEDIATION-001 — P-04.
 *
 * A product must not be declared "preparation finished" while its prepared quantity is
 * below Required. Completion is the existing `wave_product_demand.preparation_completed_at`
 * timestamp — no new status is introduced; the guard only refuses to stamp it early.
 */
final class PartialPreparationCompletionGuardTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);

        $flags = app(FeatureFlagService::class);
        $flags->enable('modules.preparation_os', $this->company->id);
        $flags->enable('workflow.stages.preparation', $this->company->id);

        $this->actingAs($this->operator());
    }

    public function test_a_partially_prepared_product_cannot_be_marked_complete(): void
    {
        [$waveId, $productId] = $this->seedProductDemand(required: 5.0, prepared: 4.0);

        $response = $this->postJson(
            "/api/preparation/waves/{$waveId}/product-demand/{$productId}/complete"
        );

        $response->assertStatus(422);
        self::assertNull(
            DB::table('wave_product_demand')
                ->where('preparation_wave_id', $waveId)
                ->where('product_id', $productId)
                ->value('preparation_completed_at'),
            'A shortfall must leave the product open (preparation_completed_at NULL).',
        );
    }

    public function test_a_fully_prepared_product_can_be_marked_complete(): void
    {
        [$waveId, $productId] = $this->seedProductDemand(required: 5.0, prepared: 5.0);

        $response = $this->postJson(
            "/api/preparation/waves/{$waveId}/product-demand/{$productId}/complete"
        );

        $response->assertOk();
        self::assertNotNull(
            DB::table('wave_product_demand')
                ->where('preparation_wave_id', $waveId)
                ->where('product_id', $productId)
                ->value('preparation_completed_at'),
            'Reaching Required must allow completion.',
        );
    }

    /**
     * Float dust must not block a genuinely finished product: the controller rounds to 4dp,
     * so 4.9999999 against a Required of 5 still completes.
     */
    public function test_float_dust_below_required_still_completes(): void
    {
        [$waveId, $productId] = $this->seedProductDemand(required: 5.0, prepared: 4.99999999);

        $this->postJson("/api/preparation/waves/{$waveId}/product-demand/{$productId}/complete")
            ->assertOk();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function operator(): User
    {
        $user = User::factory()->create(['company_id' => $this->company->id]);
        $role = Role::firstOrCreate(
            ['slug' => 'sysadmin-prep-guard'],
            ['name' => 'System Admin', 'is_system' => true],
        );
        $user->roles()->attach($role->id);
        $user->unsetRelation('roles');

        return $user;
    }

    /**
     * @return array{0: string, 1: string} [waveId, productId]
     */
    private function seedProductDemand(float $required, float $prepared): array
    {
        $wave = PreparationWave::create([
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouse->id,
            'wave_number' => 'PREP-P04-'.random_int(1, 99999),
            'planning_date' => today()->toDateString(),
            'status' => WaveStatus::Preparing->value,
            'orders_count' => 1, 'products_count' => 1, 'lines_count' => 1,
            'total_units_required' => $required, 'total_units_prepared' => $prepared,
            'shortage_detected' => false,
            'wave_type' => 'engine',
            'created_by' => 'test', 'updated_by' => 'test',
        ]);

        $productId = (string) Str::uuid();

        DB::table('wave_product_demand')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $wave->company_id,
            'warehouse_id' => $wave->warehouse_id,
            'preparation_wave_id' => $wave->id,
            'product_id' => $productId,
            'product_name' => 'Widget',
            'required_qty' => $required,
            'prepared_qty' => $prepared,
            'remaining_qty' => max(0.0, round($required - $prepared, 4)),
            'orders_count' => 1,
            'completion_pct' => $required > 0.0 ? round(($prepared / $required) * 100.0, 2) : 0.0,
            'last_calculated_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$wave->id, $productId];
    }
}

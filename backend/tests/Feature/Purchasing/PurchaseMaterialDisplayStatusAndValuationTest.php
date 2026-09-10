<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Purchasing\PurchaseMaterials\Application\Actions\GetPurchaseMaterialStatsAction;
use Modules\Purchasing\PurchaseMaterials\Domain\Enums\PurchaseMaterialStatus;
use Modules\Purchasing\PurchaseMaterials\Domain\Models\PurchaseMaterial;
use Modules\Purchasing\PurchaseMaterials\Domain\Models\PurchaseMaterialLine;
use Tests\TestCase;

/**
 * TASK-ECOS-PROCUREMENT-PURCHASE-REQUESTS-FINAL-019 §3/§5/§17/§19.
 *
 * Two independent fixes, one test file: the 6-word display-status projection (§5) and the
 * Est. Value formula switch from `average_cost` (a weighted average, not "latest") to
 * `last_purchase_cost` (the real latest-purchase-price authority, §3). Same RefreshDatabase
 * convention as every other test in this module (PurchaseMaterialFulfillmentWorkflowTest,
 * PurchaseMaterialOwnershipTest, etc.) — a hand-rolled isolated schema would be a larger,
 * riskier departure from house style for no benefit, since live execution is blocked by the
 * same environment issue either way (see the task report).
 */
final class PurchaseMaterialDisplayStatusAndValuationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $grantsBaselineAuthorization = false;

    private Company $company;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
    }

    private function actor(): User
    {
        $user = User::factory()->create(['company_id' => $this->company->id]);
        $role = Role::create(['slug' => 'test-pm-display-'.uniqid(), 'name' => 'test-pm-display', 'is_system' => false]);

        $permission = Permission::firstOrCreate(
            ['name' => 'purchasing.materials.view'],
            ['module' => 'purchasing', 'resource' => 'materials', 'action' => 'view'],
        );
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $user->roles()->attach($role->id);
        $user->unsetRelation('roles');

        return $user;
    }

    private function material(string $status, ?string $heldFromStatus = null): PurchaseMaterial
    {
        return PurchaseMaterial::query()->create([
            'request_number' => 'PM-'.substr(md5(uniqid('', true)), 0, 8),
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouse->id,
            'record_type' => 'purchase',
            'status' => $status,
            'held_from_status' => $heldFromStatus,
            'priority' => 'normal',
        ]);
    }

    // ── §5 — display status bucketing ──────────────────────────────────────

    /** @return list<array{0: string, 1: string}> */
    public static function statusBucketProvider(): array
    {
        return [
            ['draft', 'draft'],
            ['under_review', 'awaiting_supplier'],
            ['waiting_supplier_selection', 'awaiting_supplier'],
            ['approved', 'awaiting_supplier'],
            ['purchasing', 'purchasing'],
            ['receiving', 'receiving'],
            ['completed', 'completed'],
            ['rejected', 'rejected'],
            ['cancelled', 'rejected'],
        ];
    }

    /** @dataProvider statusBucketProvider */
    public function test_display_status_buckets_the_internal_statuses_correctly(string $status, string $expectedBucket): void
    {
        $material = $this->material($status);

        $this->assertSame($expectedBucket, $material->displayStatus());
        $this->assertSame($expectedBucket, PurchaseMaterialStatus::from($status)->displayBucket());
    }

    public function test_on_hold_resolves_through_held_from_status(): void
    {
        $material = $this->material('on_hold', 'purchasing');

        $this->assertSame('purchasing', $material->displayStatus());
    }

    public function test_on_hold_with_no_recorded_held_from_status_falls_back_to_awaiting_supplier(): void
    {
        $material = $this->material('on_hold', null);

        $this->assertSame('awaiting_supplier', $material->displayStatus());
    }

    public function test_the_real_status_and_available_actions_are_never_touched_by_the_display_projection(): void
    {
        $material = $this->material('on_hold', 'receiving');

        // The internal engine stays exactly what it was — display_status is presentation-only.
        $this->assertSame(PurchaseMaterialStatus::OnHold, $material->status);
        $this->assertContains('resume', $material->status->availableActions());
    }

    // ── §3 — Est. Value uses the latest purchase price, not the weighted average ────

    public function test_estimated_value_uses_last_purchase_cost_not_average_cost(): void
    {
        $product = Product::factory()->create([
            'company_id' => $this->company->id,
            // Deliberately different so a regression back to average_cost is unmistakable.
            'average_cost' => 999.00,
            'last_purchase_cost' => 12.50,
        ]);
        $material = $this->material('draft');
        PurchaseMaterialLine::query()->create([
            'purchase_material_id' => $material->id,
            'product_id' => $product->id,
            'requested_qty' => 10,
        ]);

        $response = $this->actingAs($this->actor())
            ->getJson("/api/purchase-materials/{$material->id}")
            ->assertOk();

        $response->assertJsonPath('data.estimated_value', 125.0); // 10 * 12.50, never 10 * 999
        $response->assertJsonPath('data.estimated_value_has_gaps', false);
        $response->assertJsonPath('data.lines.0.estimated_unit_price', 12.5);
        $response->assertJsonPath('data.lines.0.estimated_line_value', 125.0);
    }

    public function test_a_line_with_no_purchase_history_reports_unavailable_not_a_fake_zero(): void
    {
        $product = Product::factory()->create([
            'company_id' => $this->company->id,
            'last_purchase_cost' => null,
        ]);
        $material = $this->material('draft');
        PurchaseMaterialLine::query()->create([
            'purchase_material_id' => $material->id,
            'product_id' => $product->id,
            'requested_qty' => 10,
        ]);

        $response = $this->actingAs($this->actor())
            ->getJson("/api/purchase-materials/{$material->id}")
            ->assertOk();

        $response->assertJsonPath('data.lines.0.estimated_unit_price', null);
        $response->assertJsonPath('data.lines.0.estimated_line_value', null);
        // The total is an honest sum of what IS known (nothing, here) — never a fabricated
        // price standing in for the missing one — and the gaps flag says so explicitly.
        $response->assertJsonPath('data.estimated_value', 0.0);
        $response->assertJsonPath('data.estimated_value_has_gaps', true);
    }

    public function test_a_partially_priced_request_sums_only_the_known_lines_and_flags_the_gap(): void
    {
        $priced = Product::factory()->create(['company_id' => $this->company->id, 'last_purchase_cost' => 5.0]);
        $unpriced = Product::factory()->create(['company_id' => $this->company->id, 'last_purchase_cost' => null]);
        $material = $this->material('draft');
        PurchaseMaterialLine::query()->create(['purchase_material_id' => $material->id, 'product_id' => $priced->id, 'requested_qty' => 10]);
        PurchaseMaterialLine::query()->create(['purchase_material_id' => $material->id, 'product_id' => $unpriced->id, 'requested_qty' => 4]);

        $response = $this->actingAs($this->actor())
            ->getJson("/api/purchase-materials/{$material->id}")
            ->assertOk();

        $response->assertJsonPath('data.estimated_value', 50.0); // only the priced line's 10*5
        $response->assertJsonPath('data.estimated_value_has_gaps', true);
    }

    // ── §19 — the Hub's by_display_status bucket, and the comma-separated whereIn filter ────

    public function test_stats_by_display_status_matches_the_bucket_mapping(): void
    {
        $this->material('draft');
        $this->material('under_review');
        $this->material('approved');
        $this->material('purchasing');

        $stats = app(GetPurchaseMaterialStatsAction::class)->execute($this->company->id);

        $this->assertSame(1, $stats['by_display_status']['draft']);
        // under_review + approved both fold into awaiting_supplier.
        $this->assertSame(2, $stats['by_display_status']['awaiting_supplier']);
        $this->assertSame(1, $stats['by_display_status']['purchasing']);
    }

    public function test_status_filter_accepts_a_comma_separated_bucket_combination(): void
    {
        $inBucket1 = $this->material('under_review');
        $inBucket2 = $this->material('approved');
        $outsideBucket = $this->material('purchasing');

        $response = $this->actingAs($this->actor())
            ->getJson('/api/purchase-materials?status=under_review,approved')
            ->assertOk();

        $ids = collect($response->json('data.items'))->pluck('id')->all();
        $this->assertContains($inBucket1->id, $ids);
        $this->assertContains($inBucket2->id, $ids);
        $this->assertNotContains($outsideBucket->id, $ids);
    }

    public function test_status_filter_with_a_single_value_still_works_unchanged(): void
    {
        $match = $this->material('purchasing');
        $other = $this->material('receiving');

        $response = $this->actingAs($this->actor())
            ->getJson('/api/purchase-materials?status=purchasing')
            ->assertOk();

        $ids = collect($response->json('data.items'))->pluck('id')->all();
        $this->assertContains($match->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }
}

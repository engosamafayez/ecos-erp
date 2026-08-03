<?php

declare(strict_types=1);

namespace Tests\Feature\Branches;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Admin\Configuration\Domain\Models\MasterGovernorate;
use Modules\Admin\Configuration\Domain\Models\MasterZone;
use Modules\Organization\Branches\Domain\Models\Branch;
use Modules\Organization\Branches\Domain\Models\BranchCoverageArea;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * Tests for BranchCoverageController — branches/{branch}/coverage CRUD.
 *
 * Auth middleware is bypassed via withoutMiddleware(); these tests verify
 * routing, controller logic, and database effects — not the auth layer.
 *
 * Covers:
 *   - Listing coverage areas for a branch
 *   - Adding a coverage area (governorate-wide and zone-specific)
 *   - Updating a coverage area
 *   - Deleting a coverage area
 *   - Validation: unknown governorate → 422
 *   - Scope enforcement: area belonging to different branch → 404
 */
final class BranchCoverageControllerTest extends TestCase
{
    use DatabaseTransactions;

    private Company $company;
    private Branch $branch;
    private MasterGovernorate $governorate;
    private MasterZone $zone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();

        $this->company = Company::factory()->create();

        $this->branch = Branch::factory()->create([
            'company_id'     => $this->company->id,
            'is_head_office' => true,
            'is_active'      => true,
        ]);

        $this->governorate = MasterGovernorate::create([
            'name'      => 'Cairo',
            'name_ar'   => 'القاهرة',
            'code'      => 'C' . substr(uniqid(), -7),
            'is_active' => true,
        ]);

        $this->zone = MasterZone::create([
            'master_governorate_id' => $this->governorate->id,
            'name'                  => 'Nasr City',
            'code'                  => 'NC' . substr(uniqid(), -8),
            'is_active'             => true,
        ]);
    }

    public function test_index_returns_coverage_areas_for_branch(): void
    {
        BranchCoverageArea::create([
            'branch_id'             => $this->branch->id,
            'master_governorate_id' => $this->governorate->id,
            'master_zone_id'        => null,
            'priority'              => 10,
            'is_active'             => true,
        ]);

        $this->getJson("/api/branches/{$this->branch->id}/coverage")
            ->assertOk()
            ->assertJsonPath('data.0.master_governorate_id', $this->governorate->id)
            ->assertJsonPath('data.0.master_zone_id', null)
            ->assertJsonPath('data.0.priority', 10)
            ->assertJsonPath('data.0.governorate.name', 'Cairo');
    }

    public function test_store_creates_governorate_wide_coverage(): void
    {
        $this->postJson("/api/branches/{$this->branch->id}/coverage", [
            'master_governorate_id' => $this->governorate->id,
            'master_zone_id'        => null,
            'priority'              => 20,
            'is_active'             => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.master_governorate_id', $this->governorate->id)
            ->assertJsonPath('data.master_zone_id', null)
            ->assertJsonPath('data.priority', 20);

        $this->assertDatabaseHas('branch_coverage_areas', [
            'branch_id'             => $this->branch->id,
            'master_governorate_id' => $this->governorate->id,
            'master_zone_id'        => null,
        ]);
    }

    public function test_store_creates_zone_specific_coverage(): void
    {
        $this->postJson("/api/branches/{$this->branch->id}/coverage", [
            'master_governorate_id' => $this->governorate->id,
            'master_zone_id'        => $this->zone->id,
            'priority'              => 5,
            'is_active'             => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.master_zone_id', $this->zone->id)
            ->assertJsonPath('data.zone.name', 'Nasr City');
    }

    public function test_store_rejects_unknown_governorate(): void
    {
        $this->postJson("/api/branches/{$this->branch->id}/coverage", [
            'master_governorate_id' => '00000000-0000-0000-0000-000000000000',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['master_governorate_id']);
    }

    public function test_update_changes_priority_and_status(): void
    {
        $area = BranchCoverageArea::create([
            'branch_id'             => $this->branch->id,
            'master_governorate_id' => $this->governorate->id,
            'master_zone_id'        => null,
            'priority'              => 50,
            'is_active'             => true,
        ]);

        $this->putJson("/api/branches/{$this->branch->id}/coverage/{$area->id}", [
            'master_governorate_id' => $this->governorate->id,
            'master_zone_id'        => null,
            'priority'              => 15,
            'is_active'             => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.priority', 15)
            ->assertJsonPath('data.is_active', false);
    }

    public function test_update_returns_404_for_area_belonging_to_different_branch(): void
    {
        $otherBranch = Branch::factory()->create(['company_id' => $this->company->id]);

        $area = BranchCoverageArea::create([
            'branch_id'             => $otherBranch->id,
            'master_governorate_id' => $this->governorate->id,
            'master_zone_id'        => null,
            'priority'              => 10,
            'is_active'             => true,
        ]);

        $this->putJson("/api/branches/{$this->branch->id}/coverage/{$area->id}", [
            'master_governorate_id' => $this->governorate->id,
            'is_active'             => true,
        ])
            ->assertNotFound();
    }

    public function test_destroy_removes_coverage_area(): void
    {
        $area = BranchCoverageArea::create([
            'branch_id'             => $this->branch->id,
            'master_governorate_id' => $this->governorate->id,
            'master_zone_id'        => null,
            'priority'              => 10,
            'is_active'             => true,
        ]);

        $this->deleteJson("/api/branches/{$this->branch->id}/coverage/{$area->id}")
            ->assertOk();

        $this->assertDatabaseMissing('branch_coverage_areas', ['id' => $area->id]);
    }

    public function test_destroy_returns_404_for_area_belonging_to_different_branch(): void
    {
        $otherBranch = Branch::factory()->create(['company_id' => $this->company->id]);

        $area = BranchCoverageArea::create([
            'branch_id'             => $otherBranch->id,
            'master_governorate_id' => $this->governorate->id,
            'master_zone_id'        => null,
            'priority'              => 10,
            'is_active'             => true,
        ]);

        $this->deleteJson("/api/branches/{$this->branch->id}/coverage/{$area->id}")
            ->assertNotFound();
    }
}

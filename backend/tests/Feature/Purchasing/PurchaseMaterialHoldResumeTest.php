<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Purchasing\PurchaseMaterials\Domain\Models\PurchaseMaterial;
use Tests\TestCase;

/**
 * TASK-ECOS-PROCUREMENT-PURCHASE-REQUESTS-AND-HUB-FINAL-REMEDIATION-011 §4.
 *
 * on_hold was previously a dead end: HoldPurchaseMaterialAction never recorded which status a
 * request was held from, so nothing could move it back out — Resume did not exist. Hold now
 * stamps `held_from_status`; Resume restores it and clears the marker.
 */
final class PurchaseMaterialHoldResumeTest extends TestCase
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

    private function reviewer(): User
    {
        $user = User::factory()->create(['company_id' => $this->company->id]);
        $role = Role::create(['slug' => 'test-pm-hold-'.uniqid(), 'name' => 'test-pm-hold', 'is_system' => false]);

        foreach (['purchasing.materials.view', 'purchasing.materials.review'] as $name) {
            [$module, $resource, $action] = explode('.', $name);
            $permission = Permission::firstOrCreate(
                ['name' => $name],
                ['module' => $module, 'resource' => $resource, 'action' => $action],
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user->roles()->attach($role->id);
        $user->unsetRelation('roles');

        return $user;
    }

    private function material(string $status): PurchaseMaterial
    {
        return PurchaseMaterial::query()->create([
            'request_number' => 'PM-'.substr(md5(uniqid('', true)), 0, 8),
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouse->id,
            'record_type' => 'purchase',
            'status' => $status,
            'priority' => 'normal',
        ]);
    }

    public function test_hold_records_which_status_it_was_held_from(): void
    {
        $material = $this->material('waiting_supplier_selection');
        $this->actingAsUnprivileged($this->reviewer());

        $this->postJson("/api/purchase-materials/{$material->id}/hold")
            ->assertOk()
            ->assertJsonPath('data.status', 'on_hold')
            ->assertJsonPath('data.held_from_status', 'waiting_supplier_selection');

        self::assertSame('waiting_supplier_selection', $material->refresh()->held_from_status);
    }

    public function test_resume_restores_the_held_from_status_and_clears_the_marker(): void
    {
        $material = $this->material('waiting_supplier_selection');
        $this->actingAsUnprivileged($this->reviewer());

        $this->postJson("/api/purchase-materials/{$material->id}/hold")->assertOk();

        $this->postJson("/api/purchase-materials/{$material->id}/resume")
            ->assertOk()
            ->assertJsonPath('data.status', 'waiting_supplier_selection')
            ->assertJsonPath('data.held_from_status', null);

        self::assertNull($material->refresh()->held_from_status);
    }

    public function test_resume_is_unavailable_outside_on_hold(): void
    {
        $material = $this->material('approved');
        $this->actingAsUnprivileged($this->reviewer());

        $this->postJson("/api/purchase-materials/{$material->id}/resume")->assertStatus(422);
    }

    public function test_resume_falls_back_to_under_review_when_no_held_from_status_was_recorded(): void
    {
        // A request held before this field existed — held_from_status is null.
        $material = $this->material('on_hold');
        $this->actingAsUnprivileged($this->reviewer());

        $this->postJson("/api/purchase-materials/{$material->id}/resume")
            ->assertOk()
            ->assertJsonPath('data.status', 'under_review');
    }

    public function test_available_actions_include_resume_only_when_on_hold(): void
    {
        $onHold = $this->material('on_hold');
        $approved = $this->material('approved');
        $this->actingAsUnprivileged($this->reviewer());

        $onHoldActions = $this->getJson("/api/purchase-materials/{$onHold->id}")->assertOk()->json('data.available_actions');
        $approvedActions = $this->getJson("/api/purchase-materials/{$approved->id}")->json('data.available_actions');

        self::assertContains('resume', $onHoldActions);
        self::assertNotContains('resume', $approvedActions);
    }
}

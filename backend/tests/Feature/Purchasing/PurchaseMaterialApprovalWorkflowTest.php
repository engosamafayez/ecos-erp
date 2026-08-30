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
use Modules\Purchasing\PurchaseMaterials\Domain\Models\PurchaseMaterial;
use Tests\TestCase;

/**
 * TASK-PROCUREMENT-MANUAL-REMEDIATION-001 — the approval workflow reaches
 * waiting_supplier_selection.
 *
 * The under_review → waiting_supplier_selection hop was never wired (no code
 * path wrote the state), so supplier selection was unreachable and Approve
 * failed on an under_review request even though the drawer offers the button.
 * Approve now advances one workflow step at a time via nextWorkflowState().
 */
final class PurchaseMaterialApprovalWorkflowTest extends TestCase
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

    private function buyer(): User
    {
        $user = User::factory()->create(['company_id' => $this->company->id]);
        $role = Role::firstOrCreate(['slug' => 'test-pm-flow'], ['name' => 'test-pm-flow', 'is_system' => false]);

        foreach ([
            'purchasing.materials.view',
            'purchasing.materials.create',
            'purchasing.materials.submit',
            'purchasing.materials.approve',
        ] as $name) {
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

    public function test_approve_advances_under_review_to_waiting_supplier_selection_then_approved(): void
    {
        $product = Product::factory()->create();
        $this->actingAsUnprivileged($this->buyer());

        $id = $this->postJson('/api/purchase-materials', [
            'warehouse_id' => $this->warehouse->id,
            'record_type' => 'purchase',
            'lines' => [['product_id' => $product->id, 'requested_qty' => 4]],
        ])->assertCreated()->json('data.id');

        // draft → under_review
        $this->postJson("/api/purchase-materials/{$id}/submit")
            ->assertOk()->assertJsonPath('data.status', 'under_review');

        // under_review → waiting_supplier_selection (the previously-missing hop)
        $this->postJson("/api/purchase-materials/{$id}/approve")
            ->assertOk()->assertJsonPath('data.status', 'waiting_supplier_selection');

        self::assertNull(
            PurchaseMaterial::query()->withoutGlobalScope('tenant')->find($id)->approved_at,
            'The intermediate hop must not stamp approval.',
        );

        // waiting_supplier_selection → approved
        $this->postJson("/api/purchase-materials/{$id}/approve")
            ->assertOk()->assertJsonPath('data.status', 'approved');

        self::assertNotNull(
            PurchaseMaterial::query()->withoutGlobalScope('tenant')->find($id)->approved_at,
            'The terminal approval hop must stamp approved_at.',
        );
    }
}

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
 * TASK-ECOS-PROCUREMENT-PURCHASE-REQUESTS-AND-HUB-FINAL-REMEDIATION-011 §5.
 *
 * Ownership no longer needs a manual "assign a buyer" step: the first authorized purchasing
 * user to take a purchasing action on an unowned request becomes its buyer automatically
 * (PurchaseMaterialOwnershipService::claimIfUnowned, called from Approve/Reject/Hold/Cancel/
 * SelectLineSupplier). A second user acting on an already-owned request never displaces the
 * first. Explicit reassignment (assign-buyer) is a separate, always-allowed path gated by the
 * stronger `purchasing.materials.review` permission.
 */
final class PurchaseMaterialOwnershipTest extends TestCase
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

    private function purchasingUser(array $permissions, ?Company $company = null): User
    {
        $company ??= $this->company;
        $user = User::factory()->create(['company_id' => $company->id]);
        $role = Role::create(['slug' => 'test-pm-owner-'.uniqid(), 'name' => 'test-pm-owner', 'is_system' => false]);

        foreach ($permissions as $name) {
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

    private function underReviewMaterial(): PurchaseMaterial
    {
        return PurchaseMaterial::query()->create([
            'request_number' => 'PM-'.substr(md5(uniqid('', true)), 0, 8),
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouse->id,
            'record_type' => 'purchase',
            'status' => 'under_review',
            'priority' => 'normal',
        ]);
    }

    public function test_approving_an_unowned_request_claims_it_for_the_actor(): void
    {
        $material = $this->underReviewMaterial();
        $buyer = $this->purchasingUser(['purchasing.materials.view', 'purchasing.materials.approve']);

        self::assertNull($material->assigned_buyer_id);

        $this->actingAsUnprivileged($buyer);
        $this->postJson("/api/purchase-materials/{$material->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.assigned_buyer_id', $buyer->id)
            ->assertJsonPath('data.is_unowned', false);

        self::assertSame($buyer->id, $material->refresh()->assigned_buyer_id);
        self::assertSame($buyer->name, $material->assigned_buyer);
    }

    public function test_a_second_user_acting_on_an_already_owned_request_does_not_displace_the_first_buyer(): void
    {
        $material = $this->underReviewMaterial();
        $firstBuyer = $this->purchasingUser(['purchasing.materials.view', 'purchasing.materials.approve']);
        $secondBuyer = $this->purchasingUser(['purchasing.materials.view', 'purchasing.materials.review']);

        $this->actingAsUnprivileged($firstBuyer);
        // under_review -> waiting_supplier_selection: claims for firstBuyer.
        $this->postJson("/api/purchase-materials/{$material->id}/approve")->assertOk();
        self::assertSame($firstBuyer->id, $material->refresh()->assigned_buyer_id);

        // A second, differently-authorized user hold()s the same request.
        $this->actingAsUnprivileged($secondBuyer);
        $this->postJson("/api/purchase-materials/{$material->id}/hold")->assertOk();

        self::assertSame(
            $firstBuyer->id,
            $material->refresh()->assigned_buyer_id,
            'claimIfUnowned must be a no-op once a buyer is already assigned.',
        );
    }

    public function test_explicit_reassignment_moves_ownership_regardless_of_current_owner(): void
    {
        $material = $this->underReviewMaterial();
        $firstBuyer = $this->purchasingUser(['purchasing.materials.view', 'purchasing.materials.approve']);
        $manager = $this->purchasingUser(['purchasing.materials.view', 'purchasing.materials.review']);
        $newBuyer = User::factory()->create(['company_id' => $this->company->id]);

        $this->actingAsUnprivileged($firstBuyer);
        $this->postJson("/api/purchase-materials/{$material->id}/approve")->assertOk();
        self::assertSame($firstBuyer->id, $material->refresh()->assigned_buyer_id);

        $this->actingAsUnprivileged($manager);
        $this->postJson("/api/purchase-materials/{$material->id}/assign-buyer", ['buyer_id' => $newBuyer->id])
            ->assertOk()
            ->assertJsonPath('data.assigned_buyer_id', $newBuyer->id);

        self::assertSame($newBuyer->id, $material->refresh()->assigned_buyer_id);
    }

    public function test_reassignment_requires_the_review_permission(): void
    {
        $material = $this->underReviewMaterial();
        // Holds select_supplier but NOT review — mirrors the purchasing-officer role's actual gap.
        $officer = $this->purchasingUser(['purchasing.materials.view', 'purchasing.materials.select_supplier']);
        $newBuyer = User::factory()->create(['company_id' => $this->company->id]);

        $this->actingAsUnprivileged($officer);
        $this->postJson("/api/purchase-materials/{$material->id}/assign-buyer", ['buyer_id' => $newBuyer->id])
            ->assertForbidden();
    }

    public function test_reassignment_to_a_user_of_another_company_is_rejected(): void
    {
        $material = $this->underReviewMaterial();
        $manager = $this->purchasingUser(['purchasing.materials.view', 'purchasing.materials.review']);
        $foreignUser = User::factory()->create(['company_id' => Company::factory()->create()->id]);

        $this->actingAsUnprivileged($manager);
        $this->postJson("/api/purchase-materials/{$material->id}/assign-buyer", ['buyer_id' => $foreignUser->id])
            ->assertStatus(422);

        self::assertNull($material->refresh()->assigned_buyer_id);
    }

    public function test_submit_does_not_claim_ownership(): void
    {
        // Submit is the REQUESTER's own action, not purchasing's — it must never claim.
        $material = PurchaseMaterial::query()->create([
            'request_number' => 'PM-'.substr(md5(uniqid('', true)), 0, 8),
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouse->id,
            'record_type' => 'purchase',
            'status' => 'draft',
            'priority' => 'normal',
        ]);
        $requester = $this->purchasingUser(['purchasing.materials.view', 'purchasing.materials.submit']);

        $product = Product::factory()->create();
        $material->lines()->create(['product_id' => $product->id, 'requested_qty' => 5]);

        $this->actingAsUnprivileged($requester);
        $this->postJson("/api/purchase-materials/{$material->id}/submit")->assertOk();

        self::assertNull($material->refresh()->assigned_buyer_id);
    }
}

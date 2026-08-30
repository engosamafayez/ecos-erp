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
use Modules\Purchasing\PurchaseMaterials\Domain\Enums\PurchaseMaterialStatus;
use Modules\Purchasing\PurchaseMaterials\Domain\Models\PurchaseMaterial;
use Tests\TestCase;

/**
 * TASK-PROCUREMENT-MANUAL-REMEDIATION-001 — PurchaseMaterial tenant isolation.
 *
 * PurchaseMaterial was the only inbound aggregate without a tenant global scope.
 * Its list path (EloquentPurchaseMaterialRepository::paginate) filtered by company
 * only when the client passed `?company_id=`, so any `purchasing.materials.view`
 * holder could read every company's requests. Ownership on the write path was
 * likewise taken from the (nullable) client payload rather than resolved
 * server-side. This pins the repair: a fail-closed read scope matching Supplier /
 * GoodsReceipt / SupplierInvoice, and warehouse-derived ownership on create.
 *
 * `$grantsBaselineAuthorization = false`: TestCase::actingAs() grants an is_system
 * role to a role-less user, and is_system is exactly the flag that authorizes
 * cross-company access.
 */
final class PurchaseMaterialTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $grantsBaselineAuthorization = false;

    /** A company-scoped actor holding exactly the named permissions. */
    private function actor(Company $company, array $permissions = ['purchasing.materials.view']): User
    {
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->grantRole($user, 'test-pm-'.substr(md5(implode(',', $permissions)), 0, 10), $permissions);

        return $user;
    }

    /** No company, no privileged role — the fail-open vector. */
    private function companylessActor(array $permissions = ['purchasing.materials.view']): User
    {
        $user = User::factory()->create(['company_id' => null]);
        $this->grantRole($user, 'test-pm-companyless', $permissions);

        return $user;
    }

    private function grantRole(User $user, string $slug, array $permissions): void
    {
        $role = Role::firstOrCreate(['slug' => $slug], ['name' => $slug, 'is_system' => false]);

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
    }

    private function makeMaterial(Company $company, string $number): PurchaseMaterial
    {
        $warehouse = Warehouse::factory()->create(['company_id' => $company->id]);

        return PurchaseMaterial::query()->withoutGlobalScope('tenant')->create([
            'request_number' => $number,
            'record_type' => 'purchase',
            'warehouse_id' => $warehouse->id,
            'company_id' => $company->id,
            'status' => PurchaseMaterialStatus::Draft->value,
            'priority' => 'normal',
        ]);
    }

    // ── Read isolation ────────────────────────────────────────────────────────

    public function test_own_company_material_is_listed_and_foreign_is_not(): void
    {
        $own = Company::factory()->create();
        $foreign = Company::factory()->create();
        $mine = $this->makeMaterial($own, 'PM-00001');
        $this->makeMaterial($foreign, 'PM-00002');

        $this->actingAsUnprivileged($this->actor($own));

        $items = $this->getJson('/api/purchase-materials')->assertOk()->json('data.items');
        self::assertSame([$mine->id], array_column($items, 'id'));
    }

    public function test_cannot_read_a_material_belonging_to_another_company(): void
    {
        $own = Company::factory()->create();
        $foreign = Company::factory()->create();
        $foreignMaterial = $this->makeMaterial($foreign, 'PM-00001');

        $this->actingAsUnprivileged($this->actor($own));

        $this->getJson("/api/purchase-materials/{$foreignMaterial->id}")->assertNotFound();
    }

    public function test_companyless_non_privileged_user_sees_no_materials(): void
    {
        $a = Company::factory()->create();
        $this->makeMaterial($a, 'PM-00001');

        $this->actingAsUnprivileged($this->companylessActor());

        self::assertSame(
            [],
            $this->getJson('/api/purchase-materials')->assertOk()->json('data.items'),
            'A NULL company must not mean "return everything".',
        );
    }

    public function test_unrestricted_user_retains_cross_company_visibility(): void
    {
        $a = Company::factory()->create();
        $b = Company::factory()->create();
        $this->makeMaterial($a, 'PM-00001');
        $this->makeMaterial($b, 'PM-00002');

        $this->actingAsUnprivileged($this->grantSystemRole(User::factory()->create(['company_id' => null])));

        self::assertCount(
            2,
            $this->getJson('/api/purchase-materials')->assertOk()->json('data.items'),
        );
    }

    // ── Write ownership ─────────────────────────────────────────────────────────

    public function test_create_stamps_company_from_the_warehouse_ignoring_a_spoofed_payload(): void
    {
        $own = Company::factory()->create();
        $foreign = Company::factory()->create();
        $warehouse = Warehouse::factory()->create(['company_id' => $own->id]);
        $product = Product::factory()->create();

        $this->actingAsUnprivileged($this->actor($own, ['purchasing.materials.view', 'purchasing.materials.create']));

        $response = $this->postJson('/api/purchase-materials', [
            'warehouse_id' => $warehouse->id,
            'company_id' => $foreign->id, // spoof attempt — must be ignored
            'lines' => [['product_id' => $product->id, 'requested_qty' => 5]],
        ])->assertCreated();

        $id = $response->json('data.id');
        $stored = PurchaseMaterial::query()->withoutGlobalScope('tenant')->findOrFail($id);

        self::assertSame($own->id, $stored->company_id, 'company_id must derive from the warehouse, not the payload.');
    }

    public function test_restricted_actor_cannot_raise_a_request_for_a_foreign_warehouse(): void
    {
        $own = Company::factory()->create();
        $foreign = Company::factory()->create();
        $foreignWarehouse = Warehouse::factory()->create(['company_id' => $foreign->id]);
        $product = Product::factory()->create();

        $this->actingAsUnprivileged($this->actor($own, ['purchasing.materials.view', 'purchasing.materials.create']));

        $this->postJson('/api/purchase-materials', [
            'warehouse_id' => $foreignWarehouse->id,
            'lines' => [['product_id' => $product->id, 'requested_qty' => 5]],
        ])->assertStatus(422)->assertJsonValidationErrors(['warehouse_id']);
    }

    // ── Console/queue execution stays unscoped ──────────────────────────────────

    public function test_unauthenticated_execution_is_not_scoped(): void
    {
        $a = Company::factory()->create();
        $this->makeMaterial($a, 'PM-00001');

        self::assertSame(1, PurchaseMaterial::query()->count());
    }
}

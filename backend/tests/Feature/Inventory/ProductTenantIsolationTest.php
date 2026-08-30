<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\MasterData\Categories\Domain\Models\Category;
use Modules\MasterData\Units\Domain\Models\Unit;
use Modules\Organization\Brands\Domain\Models\Brand;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Purchasing\Suppliers\Domain\Models\Supplier;
use Tests\TestCase;

/**
 * TASK-ORDERS-INVENTORY-MANUAL-REMEDIATION-001 — Decision 2 (fail-closed isolation).
 *
 * Products (and their raw-material subtype) and Suppliers are company-scoped by
 * the SAME certified TenantOwnershipResolver global scope. Cross-company access
 * fails closed; ownership is derived from the authenticated actor, never the
 * client. Isolation is exercised with NON-system users — a system role bypasses
 * the scope by design, so these tests must not use the default actingAs() grant.
 */
class ProductTenantIsolationTest extends TestCase
{
    use DatabaseTransactions;

    private Company $companyA;

    private Company $companyB;

    private User $userA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = Company::factory()->create(['code' => 'AAA']);
        $this->companyB = Company::factory()->create(['code' => 'BBB']);
        $this->userA = User::factory()->create(['company_id' => $this->companyA->id]);
    }

    private function productIn(Company $company, string $type = Product::TYPE_FINISHED_GOOD): Product
    {
        return Product::factory()->create([
            'company_id' => $company->id,
            'product_type' => $type,
            'brand_id' => $type === Product::TYPE_FINISHED_GOOD ? Brand::factory()->create(['company_id' => $company->id])->id : null,
        ]);
    }

    /** Grant real product permissions through a NON-system role, so middleware passes but the tenant scope still applies. */
    private function grantProductPermissions(User $user): void
    {
        $role = Role::create(['name' => 'Inv Ops', 'slug' => 'inv-ops-'.substr(md5(uniqid('', true)), 0, 8), 'is_system' => false]);

        foreach (['inventory.products.view', 'inventory.products.update', 'inventory.products.delete', 'inventory.products.create'] as $name) {
            [$module, $resource, $action] = explode('.', $name);
            $perm = Permission::firstOrCreate(['name' => $name], ['module' => $module, 'resource' => $resource, 'action' => $action]);
            $role->permissions()->attach($perm->id);
        }

        $user->roles()->attach($role->id);
        $user->unsetRelation('roles');
    }

    // ── Model-level scope — the mechanism that fails every by-id path closed ─────

    public function test_product_query_is_scoped_to_the_actors_company(): void
    {
        $mine = $this->productIn($this->companyA);
        $theirs = $this->productIn($this->companyB);

        $this->actingAsUnprivileged($this->userA);

        self::assertNotNull(Product::find($mine->id), 'own product must be visible');
        self::assertNull(Product::find($theirs->id), 'another company\'s product must be invisible');
        self::assertFalse(Product::query()->whereKey($theirs->id)->exists());
    }

    public function test_raw_material_is_scoped_too(): void
    {
        $theirMaterial = $this->productIn($this->companyB, Product::TYPE_RAW_MATERIAL);

        $this->actingAsUnprivileged($this->userA);

        self::assertNull(Product::find($theirMaterial->id));
    }

    public function test_a_company_only_lists_its_own_products(): void
    {
        $this->productIn($this->companyA);
        $this->productIn($this->companyA);
        $this->productIn($this->companyB);

        $this->actingAsUnprivileged($this->userA);

        $companyIds = Product::query()->get()->pluck('company_id')->unique()->values()->all();
        self::assertSame([$this->companyA->id], $companyIds, 'the list must contain only the actor company');
    }

    public function test_a_null_company_actor_sees_no_products(): void
    {
        $orphan = User::factory()->create(['company_id' => null]);
        $this->productIn($this->companyA);

        $this->actingAsUnprivileged($orphan);

        // Fail-closed: a null company closes the query rather than removing the filter.
        self::assertSame(0, Product::query()->count());
    }

    // ── HTTP — direct API access cannot bypass isolation ────────────────────────

    public function test_cross_company_product_show_returns_404(): void
    {
        $theirs = $this->productIn($this->companyB);
        $mine = $this->productIn($this->companyA);
        $this->grantProductPermissions($this->userA);

        $this->actingAsUnprivileged($this->userA)->getJson("/api/products/{$theirs->id}")->assertStatus(404);
        $this->actingAsUnprivileged($this->userA)->getJson("/api/products/{$mine->id}")->assertStatus(200);
    }

    public function test_cross_company_product_delete_returns_404(): void
    {
        $theirs = $this->productIn($this->companyB);
        $this->grantProductPermissions($this->userA);

        $this->actingAsUnprivileged($this->userA)->deleteJson("/api/products/{$theirs->id}")->assertStatus(404);

        // The other company's product is untouched (not soft-deleted).
        self::assertNull(DB::table('products')->where('id', $theirs->id)->value('deleted_at'));
    }

    // ── Ownership is server-derived, never taken from the client ────────────────

    public function test_created_product_is_owned_by_the_actor_company_not_the_request_body(): void
    {
        $user = User::factory()->create(['company_id' => $this->companyA->id]);
        $brand = Brand::factory()->create(['company_id' => $this->companyA->id]);

        $response = $this->actingAs($user)->postJson('/api/products', [
            'name' => 'Owned Product',
            'company_id' => $this->companyB->id, // hostile client input — must be ignored
            'brand_id' => $brand->id,
            'category_id' => Category::factory()->create()->id,
            'unit_id' => Unit::factory()->create()->id,
            'product_type' => Product::TYPE_FINISHED_GOOD,
        ]);

        self::assertContains($response->status(), [200, 201], $response->getContent());
        $id = $response->json('data.id') ?? $response->json('id');

        self::assertSame(
            $this->companyA->id,
            (string) DB::table('products')->where('id', $id)->value('company_id'),
            'ownership must come from the authenticated actor, not the request body',
        );
    }

    // ── Suppliers — the reference pattern, verified still fail-closed ────────────

    public function test_supplier_cross_company_access_fails_closed(): void
    {
        $theirSupplier = Supplier::factory()->create(['company_id' => $this->companyB->id]);
        $mySupplier = Supplier::factory()->create(['company_id' => $this->companyA->id]);

        $this->actingAsUnprivileged($this->userA);

        self::assertNull(Supplier::find($theirSupplier->id));
        self::assertNotNull(Supplier::find($mySupplier->id));
    }
}

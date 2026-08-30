<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route as RouteFacade;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Organization\Brands\Domain\Models\Brand;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-INV-RAW-MATERIAL-POLICY-TOGGLE-REPAIR-001 — the inventory-policy toggle path.
 *
 * `Route::apiResource('products', …)` registered `update` as PUT|PATCH. Laravel returns
 * the FIRST matching route, so that resource shadowed the dedicated PATCH route declared
 * immediately after it: every partial payload was dispatched to `update()` and validated
 * by `UpdateProductRequest`, whose `sku`, `name`, `category_id` and `product_type` are all
 * `required`. The Raw Materials Allow-Negative toggle sends only `allow_negative_stock`,
 * so it could only ever return 422, and `ProductController::patch()` was unreachable from
 * the day it shipped (TASK-INV-RAW-MATERIALS-REGRESSION-DIAGNOSTIC-001).
 *
 * The verbs are now declared explicitly. These cases pin the resolution itself — a
 * first-match assertion, not a behavioural proxy — so the shadow cannot silently return.
 *
 * `$grantsBaselineAuthorization = false` is mandatory: `actingAs()` would otherwise grant
 * the is_system role, whose Gate::before bypass makes every denial assertion pass
 * vacuously.
 */
final class ProductPolicyTogglePatchRouteTest extends TestCase
{
    use RefreshDatabase;

    protected bool $grantsBaselineAuthorization = false;

    /** @param list<string> $permissions */
    private function operatorFor(?Company $company, array $permissions, string $slug): User
    {
        $user = User::factory()->create(['company_id' => $company?->id]);

        $role = Role::firstOrCreate(['slug' => $slug], ['name' => 'PT '.$slug, 'is_system' => false]);

        foreach ($permissions as $name) {
            [$module, $resource, $action] = explode('.', $name);
            $permission = Permission::firstOrCreate(
                ['name' => $name],
                ['module' => $module, 'resource' => $resource, 'action' => $action],
            );
            if (! $role->permissions()->where('permissions.id', $permission->id)->exists()) {
                $role->permissions()->attach($permission->id);
            }
        }

        $user->roles()->attach($role->id);
        $user->unsetRelation('roles');

        return $user->refresh();
    }

    private function productFor(Company $company, bool $allowNegative = false): Product
    {
        $brand = Brand::factory()->create(['company_id' => $company->id]);

        return Product::factory()->create([
            'brand_id' => $brand->id,
            'product_type' => 'raw_material',
            'allow_negative_stock' => $allowNegative,
        ]);
    }

    // ── 1. Route resolution — the defect this task exists to close ─────────────

    public function test_patch_route_resolves_to_the_dedicated_patch_action(): void
    {
        $route = RouteFacade::getRoutes()->match(
            Request::create('/api/products/'.fake()->uuid(), 'PATCH'),
        );

        self::assertSame('patch', $route->getActionMethod(), 'PATCH must reach ProductController::patch().');
        self::assertSame('products.patch', $route->getName());
    }

    public function test_put_route_still_resolves_to_the_full_update_action(): void
    {
        $route = RouteFacade::getRoutes()->match(
            Request::create('/api/products/'.fake()->uuid(), 'PUT'),
        );

        self::assertSame('update', $route->getActionMethod(), 'PUT must still reach ProductController::update().');
        self::assertSame('products.update', $route->getName(), 'The products.update route name must be preserved.');
    }

    public function test_patch_and_put_carry_the_same_update_permission(): void
    {
        foreach (['PATCH', 'PUT'] as $verb) {
            $route = RouteFacade::getRoutes()->match(
                Request::create('/api/products/'.fake()->uuid(), $verb),
            );

            self::assertContains(
                'permission:inventory.products.update',
                $route->middleware(),
                "[{$verb}] must keep the inventory.products.update permission.",
            );
        }
    }

    // ── 2. The toggle works, and needs no unrelated product fields ────────────

    public function test_authorized_user_can_toggle_the_inventory_policy(): void
    {
        $company = Company::factory()->create();
        $product = $this->productFor($company, allowNegative: false);

        $this->actingAsUnprivileged($this->operatorFor($company, ['inventory.products.update'], 'pt-updater'));

        $this->patchJson("/api/products/{$product->id}", ['allow_negative_stock' => true])
            ->assertOk()
            ->assertJsonPath('data.allow_negative_stock', true);

        self::assertTrue((bool) $product->refresh()->allow_negative_stock);
    }

    public function test_toggle_does_not_require_unrelated_full_product_fields(): void
    {
        $company = Company::factory()->create();
        $product = $this->productFor($company);

        $this->actingAsUnprivileged($this->operatorFor($company, ['inventory.products.update'], 'pt-updater'));

        // sku / name / category_id / product_type are deliberately absent. Under the
        // shadowed route this returned 422 on all four.
        $this->patchJson("/api/products/{$product->id}", ['allow_negative_stock' => true])
            ->assertOk();
    }

    public function test_invalid_policy_value_is_rejected(): void
    {
        $company = Company::factory()->create();
        $product = $this->productFor($company);

        $this->actingAsUnprivileged($this->operatorFor($company, ['inventory.products.update'], 'pt-updater'));

        $this->patchJson("/api/products/{$product->id}", ['allow_negative_stock' => 'not-a-boolean'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('allow_negative_stock');
    }

    // ── 3. Authorization ──────────────────────────────────────────────────────

    public function test_user_without_the_update_permission_is_denied(): void
    {
        $company = Company::factory()->create();
        $product = $this->productFor($company, allowNegative: false);

        // Holds a DIFFERENT inventory.products permission, in the SAME company.
        $this->actingAsUnprivileged($this->operatorFor($company, ['inventory.products.view'], 'pt-viewer'));

        $this->patchJson("/api/products/{$product->id}", ['allow_negative_stock' => true])
            ->assertForbidden();

        self::assertFalse((bool) $product->refresh()->allow_negative_stock, 'A denied toggle must not mutate.');
    }

    public function test_unauthenticated_request_is_denied(): void
    {
        $product = $this->productFor(Company::factory()->create(), allowNegative: false);

        $this->patchJson("/api/products/{$product->id}", ['allow_negative_stock' => true])
            ->assertUnauthorized();

        self::assertFalse((bool) $product->refresh()->allow_negative_stock);
    }

    // ── 4. Tenant isolation (RC-6 fail-closed) ────────────────────────────────

    public function test_cross_company_product_cannot_be_patched(): void
    {
        $own = Company::factory()->create();
        $foreign = Company::factory()->create();
        $foreignProduct = $this->productFor($foreign, allowNegative: false);

        $this->actingAsUnprivileged($this->operatorFor($own, ['inventory.products.update'], 'pt-updater'));

        $this->patchJson("/api/products/{$foreignProduct->id}", ['allow_negative_stock' => true])
            ->assertNotFound();

        self::assertFalse(
            (bool) $foreignProduct->refresh()->allow_negative_stock,
            'The certified tenant boundary must leave a foreign product byte-identical.',
        );
    }

    public function test_company_scoped_actor_with_no_company_fails_closed(): void
    {
        $product = $this->productFor(Company::factory()->create(), allowNegative: false);

        $this->actingAsUnprivileged($this->operatorFor(null, ['inventory.products.update'], 'pt-updater'));

        $this->patchJson("/api/products/{$product->id}", ['allow_negative_stock' => true])
            ->assertNotFound();

        self::assertFalse((bool) $product->refresh()->allow_negative_stock);
    }

    public function test_system_actor_retains_existing_cross_company_semantics(): void
    {
        $product = $this->productFor(Company::factory()->create(), allowNegative: false);

        $this->actingAsUnprivileged($this->grantSystemRole(User::factory()->create(['company_id' => null])));

        $this->patchJson("/api/products/{$product->id}", ['allow_negative_stock' => true])
            ->assertOk();

        self::assertTrue((bool) $product->refresh()->allow_negative_stock);
    }

    // ── 5. The toggle is a POLICY change only (GD-2 must not shift) ────────────

    public function test_toggle_changes_only_the_policy_column(): void
    {
        $company = Company::factory()->create();
        $product = $this->productFor($company, allowNegative: false);

        $before = $product->refresh()->getAttributes();

        $this->actingAsUnprivileged($this->operatorFor($company, ['inventory.products.update'], 'pt-updater'));

        $this->patchJson("/api/products/{$product->id}", ['allow_negative_stock' => true])->assertOk();

        $after = $product->refresh()->getAttributes();

        $changed = [];
        foreach ($after as $key => $value) {
            if (($before[$key] ?? null) !== $value) {
                $changed[] = $key;
            }
        }

        // updated_at is expected to move; nothing else may.
        self::assertSame(
            ['allow_negative_stock'],
            array_values(array_diff($changed, ['updated_at'])),
            'A policy toggle must not write stock, cost, status or any other column.',
        );
    }

    /**
     * Part 7 — MaterialCostService isolation. `patch()` guards the cost branch with
     * `isset($validated['manual_cost'])`, which is false both when the key is absent
     * and when it is explicitly null, so a policy toggle can never invoke the service.
     */
    public function test_policy_toggle_never_touches_product_cost(): void
    {
        $company = Company::factory()->create();
        $product = $this->productFor($company, allowNegative: false);

        $costBefore = $product->refresh()->only(['manual_cost', 'cost_price', 'average_cost', 'last_purchase_cost']);

        $this->actingAsUnprivileged($this->operatorFor($company, ['inventory.products.update'], 'pt-updater'));

        $this->patchJson("/api/products/{$product->id}", ['allow_negative_stock' => true])->assertOk();

        self::assertSame(
            $costBefore,
            $product->refresh()->only(['manual_cost', 'cost_price', 'average_cost', 'last_purchase_cost']),
            'Cost must be untouched when manual_cost is not part of the payload.',
        );
    }

    // ── 6. The full-update contract is not weakened ───────────────────────────

    public function test_put_full_update_still_requires_its_own_fields(): void
    {
        $company = Company::factory()->create();
        $product = $this->productFor($company);

        $this->actingAsUnprivileged($this->operatorFor($company, ['inventory.products.update'], 'pt-updater'));

        // The same partial payload against PUT must still be refused — splitting the
        // verbs must not have relaxed UpdateProductRequest.
        $this->putJson("/api/products/{$product->id}", ['allow_negative_stock' => true])
            ->assertUnprocessable();
    }
}

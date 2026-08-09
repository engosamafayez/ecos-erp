<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\IAM\Domain\Models\Role;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Purchasing\Suppliers\Domain\Models\Supplier;
use Tests\TestCase;

/**
 * TASK-GOLIVE-PILOT-PHASE3-PREP-001 — D-8 Supplier tenant isolation.
 *
 * `Supplier` carried the same fail-open `tenant` global scope that RC-6 proved
 * against `Warehouse`: a null `company_id` returned early, so an actor with no
 * company affiliation was served every company's suppliers.
 *
 * It is reachable with no permission at all — `GET /api/suppliers` and
 * `GET /api/suppliers/{id}` carry only `auth:sanctum` (routes/api.php:558-563
 * gates store/update/destroy only).
 *
 * `$grantsBaselineAuthorization = false`: TestCase::actingAs() grants an
 * is_system role to a role-less user, and is_system is exactly the flag that
 * authorizes cross-company access.
 */
final class SupplierTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $grantsBaselineAuthorization = false;

    /** A company-scoped actor holding no privileged role. */
    private function operatorFor(Company $company): User
    {
        $user = User::factory()->create(['company_id' => $company->id]);

        $role = Role::firstOrCreate(
            ['slug' => 'test-supplier-operator'],
            ['name' => 'Test Supplier Operator', 'is_system' => false],
        );
        $user->roles()->attach($role->id);
        $user->unsetRelation('roles');

        return $user;
    }

    /** Privileged actor: is_system role, no company — the documented capability. */
    private function unrestrictedUser(): User
    {
        return $this->grantSystemRole(User::factory()->create(['company_id' => null]));
    }

    /** The fail-open vector: no company, no privileged role. */
    private function companylessNonPrivilegedUser(): User
    {
        $user = User::factory()->create(['company_id' => null]);

        $role = Role::firstOrCreate(
            ['slug' => 'test-supplier-companyless'],
            ['name' => 'Test Supplier Companyless', 'is_system' => false],
        );
        $user->roles()->attach($role->id);
        $user->unsetRelation('roles');

        return $user;
    }

    // ── Own-company access still works ────────────────────────────────────────

    public function test_own_company_supplier_is_listed_and_readable(): void
    {
        $company = Company::factory()->create();
        $supplier = Supplier::factory()->create(['company_id' => $company->id]);

        $this->actingAsUnprivileged($this->operatorFor($company));

        $items = $this->getJson('/api/suppliers')->assertOk()->json('data.items');
        self::assertSame([$supplier->id], array_column($items, 'id'));

        $this->getJson("/api/suppliers/{$supplier->id}")->assertOk();
    }

    // ── Cross-company access is blocked ───────────────────────────────────────

    public function test_cannot_read_a_supplier_belonging_to_another_company(): void
    {
        $own = Company::factory()->create();
        $foreign = Company::factory()->create();
        $foreignSupplier = Supplier::factory()->create(['company_id' => $foreign->id]);

        $this->actingAsUnprivileged($this->operatorFor($own));

        self::assertSame([], $this->getJson('/api/suppliers')->assertOk()->json('data.items'));
        $this->getJson("/api/suppliers/{$foreignSupplier->id}")->assertNotFound();
    }

    // ── NULL company must fail closed ─────────────────────────────────────────

    public function test_companyless_non_privileged_user_sees_no_suppliers(): void
    {
        $a = Company::factory()->create();
        $b = Company::factory()->create();
        Supplier::factory()->create(['company_id' => $a->id]);
        $bSupplier = Supplier::factory()->create(['company_id' => $b->id]);

        $this->actingAsUnprivileged($this->companylessNonPrivilegedUser());

        self::assertSame(
            [],
            $this->getJson('/api/suppliers')->assertOk()->json('data.items'),
            'A NULL company must not mean "return everything".',
        );

        $this->getJson("/api/suppliers/{$bSupplier->id}")->assertNotFound();
    }

    // ── The documented is_system capability survives ──────────────────────────

    public function test_unrestricted_user_retains_cross_company_visibility(): void
    {
        $a = Company::factory()->create();
        $b = Company::factory()->create();
        Supplier::factory()->create(['company_id' => $a->id]);
        Supplier::factory()->create(['company_id' => $b->id]);

        $this->actingAsUnprivileged($this->unrestrictedUser());

        self::assertCount(
            2,
            $this->getJson('/api/suppliers')->assertOk()->json('data.items'),
            'The documented is_system capability must be preserved.',
        );
    }

    // ── Console/queue execution stays unscoped ────────────────────────────────

    public function test_unauthenticated_execution_is_not_scoped(): void
    {
        $a = Company::factory()->create();
        Supplier::factory()->create(['company_id' => $a->id]);

        // No actor — console commands, queue workers, seeders and migrations.
        self::assertSame(1, Supplier::query()->count());
    }
}

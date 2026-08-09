<?php

declare(strict_types=1);

namespace Tests\Feature\MasterData;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-GOLIVE-RC6-REPAIR-001 — Warehouse tenant isolation.
 *
 * Characterization + regression tests for RC-6: a warehouse created with a
 * client-supplied `company_id` was written under that company, while every read
 * path filtered by `Auth::user()->company_id`. The record was created (201) and
 * then denied to exist.
 *
 * These cases deliberately run with `$grantsBaselineAuthorization = false`.
 * TestCase::actingAs() grants an is_system role to a role-less user, and
 * is_system is precisely the flag that authorizes cross-company access — a
 * subject built that way would be handed the access these cases assert is
 * refused.
 */
final class WarehouseTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $grantsBaselineAuthorization = false;

    /** Permissions the warehouse write routes require. */
    private const WAREHOUSE_PERMISSIONS = [
        'inventory.warehouses.create',
        'inventory.warehouses.update',
        'inventory.warehouses.delete',
    ];

    // ── Fixtures ──────────────────────────────────────────────────────────────

    /**
     * A company-scoped operator: real permissions, no is_system role.
     * This is the ordinary tenant user the isolation contract protects against.
     */
    private function operatorFor(Company $company): User
    {
        $user = User::factory()->create(['company_id' => $company->id]);

        $role = Role::firstOrCreate(
            ['slug' => 'test-company-operator'],
            ['name' => 'Test Company Operator', 'is_system' => false],
        );

        foreach (self::WAREHOUSE_PERMISSIONS as $name) {
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

        return $user;
    }

    /**
     * A privileged actor: holds an is_system role and has no company affiliation.
     * config/permissions.php documents this as the cross-company capability
     * ("is_system = true → role bypasses all permission checks via Gate::before()").
     */
    private function unrestrictedUser(): User
    {
        $user = User::factory()->create(['company_id' => null]);

        return $this->grantSystemRole($user);
    }

    /**
     * A company-less actor with NO privileged role — the fail-open vector.
     * Before the fix, a NULL company_id alone skipped every company filter.
     */
    private function companylessNonPrivilegedUser(): User
    {
        $user = User::factory()->create(['company_id' => null]);

        $role = Role::firstOrCreate(
            ['slug' => 'test-companyless-operator'],
            ['name' => 'Test Companyless Operator', 'is_system' => false],
        );

        $user->roles()->attach($role->id);
        $user->unsetRelation('roles');

        return $user;
    }

    /** @return array<string, mixed> */
    private function payload(Company $company, string $name = 'Main Warehouse'): array
    {
        return [
            'company_id' => $company->id,
            'name' => $name,
            'city' => 'Cairo',
            'country' => 'Egypt',
            'is_active' => true,
        ];
    }

    // ── 1. Own-company create ─────────────────────────────────────────────────

    public function test_create_warehouse_for_own_company_succeeds_and_is_readable(): void
    {
        $company = Company::factory()->create();
        $this->actingAsUnprivileged($this->operatorFor($company));

        $response = $this->postJson('/api/warehouses', $this->payload($company));

        $response->assertCreated();

        $id = $response->json('data.id');
        self::assertIsString($id);

        // The record belongs to the authenticated company.
        $stored = Warehouse::withoutGlobalScopes()->find($id);
        self::assertNotNull($stored);
        self::assertSame($company->id, $stored->company_id);

        // And the subsequent read succeeds — the RC-6 symptom is absent.
        $this->getJson("/api/warehouses/{$id}")->assertOk();

        $listed = $this->getJson('/api/warehouses')->assertOk()->json('data.items');
        self::assertSame([$id], array_column($listed, 'id'));
    }

    // ── 2. Other-company create — the RC-6 write vector ───────────────────────

    public function test_cannot_create_warehouse_under_another_company(): void
    {
        $own = Company::factory()->create();
        $foreign = Company::factory()->create();

        $this->actingAsUnprivileged($this->operatorFor($own));

        $this->postJson('/api/warehouses', $this->payload($foreign))
            ->assertStatus(422)
            ->assertJsonValidationErrors('company_id');

        // Nothing was written under either company.
        self::assertSame(0, Warehouse::withoutGlobalScopes()->count());
    }

    public function test_unrestricted_user_may_still_create_for_any_company(): void
    {
        $company = Company::factory()->create();

        $this->actingAsUnprivileged($this->unrestrictedUser());

        $response = $this->postJson('/api/warehouses', $this->payload($company));

        $response->assertCreated();

        $stored = Warehouse::withoutGlobalScopes()->find($response->json('data.id'));
        self::assertNotNull($stored);
        self::assertSame($company->id, $stored->company_id);
    }

    // ── 3. Other-company read ─────────────────────────────────────────────────

    public function test_cannot_read_a_warehouse_belonging_to_another_company(): void
    {
        $own = Company::factory()->create();
        $foreign = Company::factory()->create();
        $foreignWarehouse = Warehouse::factory()->create(['company_id' => $foreign->id]);

        $this->actingAsUnprivileged($this->operatorFor($own));

        $this->getJson("/api/warehouses/{$foreignWarehouse->id}")->assertNotFound();

        $items = $this->getJson('/api/warehouses')->assertOk()->json('data.items');
        self::assertSame([], $items);
    }

    // ── 4. NULL company scope must fail closed ────────────────────────────────

    public function test_companyless_non_privileged_user_sees_no_warehouses(): void
    {
        $a = Company::factory()->create();
        $b = Company::factory()->create();
        Warehouse::factory()->create(['company_id' => $a->id]);
        $bWarehouse = Warehouse::factory()->create(['company_id' => $b->id]);

        $this->actingAsUnprivileged($this->companylessNonPrivilegedUser());

        $items = $this->getJson('/api/warehouses')->assertOk()->json('data.items');
        self::assertSame([], $items, 'A NULL company must not mean "return everything".');

        $this->getJson("/api/warehouses/{$bWarehouse->id}")->assertNotFound();
    }

    public function test_unrestricted_user_retains_cross_company_visibility(): void
    {
        $a = Company::factory()->create();
        $b = Company::factory()->create();
        Warehouse::factory()->create(['company_id' => $a->id]);
        Warehouse::factory()->create(['company_id' => $b->id]);

        $this->actingAsUnprivileged($this->unrestrictedUser());

        $items = $this->getJson('/api/warehouses')->assertOk()->json('data.items');
        self::assertCount(2, $items, 'The documented is_system capability must be preserved.');
    }

    // ── 5. Grid company filter ────────────────────────────────────────────────

    public function test_company_filter_narrows_for_an_unrestricted_user(): void
    {
        $a = Company::factory()->create();
        $b = Company::factory()->create();
        $aWarehouse = Warehouse::factory()->create(['company_id' => $a->id]);
        Warehouse::factory()->create(['company_id' => $b->id]);

        $this->actingAsUnprivileged($this->unrestrictedUser());

        $items = $this->getJson('/api/warehouses?company_id='.$a->id)->assertOk()->json('data.items');

        self::assertSame(
            [$aWarehouse->id],
            array_column($items, 'id'),
            'The requested company filter must be applied, not silently discarded.',
        );
    }

    public function test_company_filter_cannot_widen_beyond_the_authoritative_scope(): void
    {
        $own = Company::factory()->create();
        $foreign = Company::factory()->create();
        Warehouse::factory()->create(['company_id' => $own->id]);
        Warehouse::factory()->create(['company_id' => $foreign->id]);

        $this->actingAsUnprivileged($this->operatorFor($own));

        // Asking for another company's data must yield nothing — it must not
        // silently fall back to the caller's own rows either.
        $items = $this->getJson('/api/warehouses?company_id='.$foreign->id)->assertOk()->json('data.items');
        self::assertSame([], $items);
    }

    // ── 6. The original RC-6 sequence ─────────────────────────────────────────

    public function test_rc6_sequence_created_record_never_becomes_invisible(): void
    {
        $own = Company::factory()->create();
        $this->actingAsUnprivileged($this->operatorFor($own));

        // 1–3. Submit and receive success.
        $created = $this->postJson('/api/warehouses', $this->payload($own, 'RC-6 Warehouse'))
            ->assertCreated();

        $id = $created->json('data.id');

        // 4–5. Retrieve and list — visibility matches authoritative ownership.
        $this->getJson("/api/warehouses/{$id}")->assertOk()->assertJsonPath('data.id', $id);

        $items = $this->getJson('/api/warehouses')->assertOk()->json('data.items');
        self::assertContains($id, array_column($items, 'id'), 'RC-6: created then denied to exist.');

        // 6–7. Another company cannot reach it.
        $otherCompany = Company::factory()->create();
        $this->actingAsUnprivileged($this->operatorFor($otherCompany));

        $this->getJson("/api/warehouses/{$id}")->assertNotFound();
        self::assertSame([], $this->getJson('/api/warehouses')->assertOk()->json('data.items'));
    }

    // ── Ownership mutation ────────────────────────────────────────────────────

    /**
     * Ownership is already immutable on update: UpdateWarehouseRequest does not
     * accept `company_id`, and UpdateWarehouseAction strips it explicitly
     * ("code and company_id cannot change after creation"). This pins that
     * behaviour so the RC-6 repair cannot regress it.
     */
    public function test_update_cannot_move_a_warehouse_into_another_company(): void
    {
        $own = Company::factory()->create();
        $foreign = Company::factory()->create();
        $warehouse = Warehouse::factory()->create(['company_id' => $own->id]);

        $this->actingAsUnprivileged($this->operatorFor($own));

        $this->putJson("/api/warehouses/{$warehouse->id}", [
            'company_id' => $foreign->id,
            'name' => 'Renamed',
        ])->assertOk();

        $stored = Warehouse::withoutGlobalScopes()->find($warehouse->id);
        self::assertNotNull($stored);
        self::assertSame($own->id, $stored->company_id, 'company_id must remain immutable.');
        self::assertSame('Renamed', $stored->name);
    }

    public function test_cannot_update_a_warehouse_belonging_to_another_company(): void
    {
        $own = Company::factory()->create();
        $foreign = Company::factory()->create();
        $foreignWarehouse = Warehouse::factory()->create(['company_id' => $foreign->id]);

        $this->actingAsUnprivileged($this->operatorFor($own));

        $this->putJson("/api/warehouses/{$foreignWarehouse->id}", [
            'company_id' => $foreign->id,
            'name' => 'Hijacked',
        ])->assertNotFound();
    }

    public function test_cannot_delete_a_warehouse_belonging_to_another_company(): void
    {
        $own = Company::factory()->create();
        $foreign = Company::factory()->create();
        $foreignWarehouse = Warehouse::factory()->create(['company_id' => $foreign->id]);

        $this->actingAsUnprivileged($this->operatorFor($own));

        $this->deleteJson("/api/warehouses/{$foreignWarehouse->id}")->assertNotFound();

        self::assertNotNull(Warehouse::withoutGlobalScopes()->find($foreignWarehouse->id));
    }

    // ── Authorization is unchanged ────────────────────────────────────────────

    public function test_user_without_the_create_permission_is_refused(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        $role = Role::firstOrCreate(
            ['slug' => 'test-no-warehouse-permissions'],
            ['name' => 'Test No Warehouse Permissions', 'is_system' => false],
        );
        $user->roles()->attach($role->id);

        $this->actingAsUnprivileged($user);

        $this->postJson('/api/warehouses', $this->payload($company))->assertForbidden();
    }
}

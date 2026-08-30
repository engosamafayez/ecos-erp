<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * TASK-LOADING-WAREHOUSE-DRIVER-CUSTODY-IMPLEMENTATION-001 (owner-approved #2).
 *
 * ┌─ NO NEW PERMISSION. GRANTS ONLY. ────────────────────────────────────────┐
 * │ `loading.session.operate` and `loading.driver.operate` already exist and   │
 * │ already split along the boundary this workflow needs. What was missing is  │
 * │ that neither had ever been granted to the roles that do the work: the live │
 * │ matrix held both at company-admin only, so the warehouse could not record  │
 * │ a loaded quantity and NO DRIVER COULD REACH THE DRIVER RUNTIME AT ALL.     │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * THIS SUPERSEDES ONE EARLIER DECISION, DELIBERATELY.
 * `2026_08_20_100000_seed_loading_os_permissions` states: "`loading.driver.operate`
 * is granted to company-admin as an operational permission, NOT to any driver role —
 * the driver identity is resolved per-request in the driver runtime, not via a role."
 * Per-request identity resolution is still how ownership is enforced (a driver reaches
 * only their own trip), and nothing about that changes here. But identity is not
 * capability: with no role grant, the policy layer refuses every driver before
 * ownership is ever consulted. Owner decision #2 authorises the grant.
 *
 * SEPARATION IS PRESERVED BY WHAT IS *NOT* GRANTED.
 * Driver roles receive `loading.driver.operate` ONLY. They are never granted
 * `loading.session.operate`, which is the capability that can move
 * `quantity_loaded` — so a driver still cannot modify the warehouse's number, by
 * construction rather than by convention.
 *
 * Mirrors `2026_08_20_100000_seed_loading_os_permissions` in shape: idempotent on
 * both halves, and a no-op for any role slug absent from this environment.
 */
return new class extends Migration
{
    /**
     * The people who physically load, plus their role-template equivalents — the same
     * set that already holds `operations.preparation.update`, narrowed to warehouse
     * actors. Executive/finance roles are deliberately not widened here.
     *
     * @var list<string>
     */
    private const WAREHOUSE_ROLES = [
        'warehouse-operator',
        'warehouse-manager',
        'preparation-supervisor',
        'tpl-warehouse-manager',
        'tpl-warehouse-director',
    ];

    /** @var list<string> */
    private const DRIVER_ROLES = [
        'driver',
        'tpl-driver',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles') || ! Schema::hasTable('role_permissions')) {
            return;
        }

        $this->applyToRoles(self::WAREHOUSE_ROLES, 'loading.session.operate');
        $this->applyToRoles(self::DRIVER_ROLES, 'loading.driver.operate');
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('role_permissions')) {
            return;
        }

        $this->revokeFromRoles(self::WAREHOUSE_ROLES, 'loading.session.operate');
        $this->revokeFromRoles(self::DRIVER_ROLES, 'loading.driver.operate');
    }

    /** @param list<string> $slugs */
    private function applyToRoles(array $slugs, string $permissionName): void
    {
        $permissionId = DB::table('permissions')->where('name', $permissionName)->value('id');

        // The permission itself is created by the 2026_08_20 seed migration. If it is
        // absent this environment has not run that yet, and granting a name that does
        // not exist would be meaningless — so this is a no-op rather than an invention.
        if ($permissionId === null) {
            return;
        }

        $now = now();

        foreach ($slugs as $slug) {
            $roleId = DB::table('roles')->where('slug', $slug)->value('id');

            if ($roleId === null) {
                continue;
            }

            $exists = DB::table('role_permissions')
                ->where('role_id', $roleId)
                ->where('permission_id', $permissionId)
                ->exists();

            if ($exists) {
                continue;
            }

            // `role_permissions` carries `created_at` but NO `updated_at` — a grant is
            // a fact that is made once and revoked by deletion, never edited in place.
            DB::table('role_permissions')->insert([
                'id' => (string) Str::uuid(),
                'role_id' => $roleId,
                'permission_id' => $permissionId,
                'effect' => 'allow',
                'conditions' => null,
                'created_at' => $now,
            ]);
        }
    }

    /** @param list<string> $slugs */
    private function revokeFromRoles(array $slugs, string $permissionName): void
    {
        $permissionId = DB::table('permissions')->where('name', $permissionName)->value('id');

        if ($permissionId === null) {
            return;
        }

        $roleIds = DB::table('roles')->whereIn('slug', $slugs)->pluck('id');

        DB::table('role_permissions')
            ->whereIn('role_id', $roleIds)
            ->where('permission_id', $permissionId)
            ->delete();
    }
};

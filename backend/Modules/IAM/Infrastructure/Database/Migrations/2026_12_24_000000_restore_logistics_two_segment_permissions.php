<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * TASK-LOGISTICS-PERMISSIONS-ENVIRONMENT-PARITY-REPAIR-001
 *
 * Restores the 17 two-segment Logistics permissions and grants them under the
 * authorised RBAC decision (company-admin: all 17; viewer: the `.view` subset).
 *
 * ┌─ WHY A MIGRATION EXISTS FOR PERMISSIONS THAT ARE ALREADY DEFINED ────────┐
 * │ These 17 permissions are DEFINED by five Logistics module migrations that │
 * │ are already recorded as run. They were then deleted from an environment   │
 * │ by RbacSeeder's former "delete every permission with exactly one dot"     │
 * │ cleanup — see the docblock at RbacSeeder:22-51, which removed that rule   │
 * │ and records that it "deleted 19 live ones on every run".                  │
 * │                                                                           │
 * │ Because the defining migrations are marked run, `migrate` will never      │
 * │ re-insert them, and RbacSeeder cannot: its catalogue loop builds only     │
 * │ three-segment `domain.resource.action` names from config, and its adopt   │
 * │ step only picks up rows that already exist. So a re-registration step is  │
 * │ required, and RbacSeeder:48-50 prescribes the form: "a migration that     │
 * │ names them explicitly, where the list is reviewable."                     │
 * └───────────────────────────────────────────────────────────────────────────┘
 *
 * The name/module/resource/action/description of every row below is copied
 * verbatim from its defining migration. Nothing is renamed, and no three-part
 * alias is introduced — the two-segment names are the repair target.
 *
 * Scope discipline:
 *   • Only these 17 names are touched. No other permission is read or written.
 *   • `finance.admin` was lost to the same cleanup but belongs to Finance; it is
 *     deliberately NOT restored here.
 *   • `routing.manage` was lost too and is Logistics, but it falls outside the
 *     authorised 17 and is gated by no route, so it stays out of scope.
 *
 * Idempotent on both halves, and the database agrees: `permissions.name` is
 * unique, and `role_permissions` is unique on (role_id, permission_id).
 */
return new class extends Migration
{
    /**
     * The authorised 17, verbatim from their defining migrations.
     *
     * [name, resource, action, description, sourceMigration]
     *
     * @var list<array{0:string,1:string,2:string,3:string,4:string}>
     */
    private const PERMISSIONS = [
        // Operations — 2026_08_05_100003_seed_phase4_permissions.php
        ['operations.view', 'operations', 'view', 'View operations dashboards, pools and the exception registry', 'phase4'],

        // Fleet — 2026_07_30_100013_seed_fleet_permissions.php
        ['fleet.view', 'fleet', 'view', 'View fleet units, health and maintenance', 'fleet'],
        ['fleet.manage', 'fleet', 'manage', 'Create and manage fleets, groups and units', 'fleet'],

        // Delivery — 2026_07_29_100009_seed_delivery_permissions.php
        ['delivery.view', 'delivery', 'view', 'View deliveries and their timeline', 'delivery'],
        ['delivery.execute', 'delivery', 'execute', 'Open and close delivery attempts', 'delivery'],
        ['delivery.retry', 'delivery', 'retry', 'Schedule or cancel a delivery retry', 'delivery'],
        ['delivery.cancel', 'delivery', 'cancel', 'Cancel a delivery', 'delivery'],

        // Network / Dispatch / Routing / Carrier — 2026_07_31_100004_seed_phase2_permissions.php
        ['network.view', 'network', 'view', 'View service areas, coverage and capacity', 'phase2'],
        ['network.manage', 'network', 'manage', 'Create and manage service areas, regions and levels', 'phase2'],
        ['dispatch.view', 'dispatch', 'view', 'View dispatch boards and proposals', 'phase2'],
        ['dispatch.propose', 'dispatch', 'propose', 'Generate and adjust dispatch proposals', 'phase2'],
        ['dispatch.release', 'dispatch', 'release', 'Release a proposal, committing resources in V1', 'phase2'],
        ['dispatch.manage', 'dispatch', 'manage', 'Open, close and configure dispatch boards', 'phase2'],
        ['routing.view', 'routing', 'view', 'View route plans and ETAs', 'phase2'],
        ['routing.optimize', 'routing', 'optimize', 'Plan and re-plan routes', 'phase2'],
        ['carrier.view', 'carrier', 'view', 'View carrier accounts and capabilities', 'phase2'],
        ['carrier.manage', 'carrier', 'manage', 'Configure carrier accounts and status mappings', 'phase2'],
    ];

    /** Roles receiving the full set, per the authorised RBAC decision. */
    private const FULL_SET_ROLES = ['company-admin'];

    /** Roles receiving only names ending in `.view`, per the authorised RBAC decision. */
    private const VIEW_ONLY_ROLES = ['viewer'];

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $now = now();

        // ── 1. Re-register any missing definition ─────────────────────────────
        // Guarded on the unique name, so a correct existing row is left exactly
        // as it is — this never overwrites a live permission.
        foreach (self::PERMISSIONS as [$name, $resource, $action, $description]) {
            if (DB::table('permissions')->where('name', $name)->exists()) {
                continue;
            }

            DB::table('permissions')->insert([
                'id' => (string) Str::uuid(),
                'name' => $name,
                'module' => 'logistics',
                'resource' => $resource,
                'action' => $action,
                'description' => $description,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // ── 2. Apply the authorised grants ────────────────────────────────────
        if (! Schema::hasTable('roles') || ! Schema::hasTable('role_permissions')) {
            return;
        }

        // Resolve ids for the 17 by name — never by a wildcard or name pattern,
        // so no permission outside this list can be caught up in the grant.
        $permissionIds = DB::table('permissions')
            ->whereIn('name', array_column(self::PERMISSIONS, 0))
            ->pluck('id', 'name');

        foreach (self::FULL_SET_ROLES as $slug) {
            $this->grant($slug, $permissionIds->all(), $now);
        }

        foreach (self::VIEW_ONLY_ROLES as $slug) {
            $viewOnly = $permissionIds
                ->filter(static fn ($id, string $name): bool => str_ends_with($name, '.view'))
                ->all();

            $this->grant($slug, $viewOnly, $now);
        }
    }

    /**
     * Grant a set of permissions to a role, skipping any grant that already exists.
     *
     * @param  array<string, string>  $permissionIds  name => id
     */
    private function grant(string $slug, array $permissionIds, mixed $now): void
    {
        $roleId = DB::table('roles')->where('slug', $slug)->value('id');

        if ($roleId === null) {
            return;
        }

        foreach ($permissionIds as $permissionId) {
            $exists = DB::table('role_permissions')
                ->where('role_id', $roleId)
                ->where('permission_id', $permissionId)
                ->exists();

            if ($exists) {
                continue;
            }

            // effect and data_scope are left at their column defaults ('allow',
            // 'all'), matching every one of the 396 company-admin grants the
            // enterprise permission matrix already created. This migration adds
            // no scope semantics of its own: tenant isolation is enforced in the
            // controllers and is the subject of T-01/T-02, not of this repair.
            DB::table('role_permissions')->insert([
                'id' => (string) Str::uuid(),
                'role_id' => $roleId,
                'permission_id' => $permissionId,
                'effect' => 'allow',
                'conditions' => null,
                'expires_at' => null,
                'created_at' => $now,
            ]);
        }
    }

    /**
     * Reverses the GRANTS only — the permission definitions are deliberately left.
     *
     * Deleting the 17 rows here would reproduce exactly the defect this migration
     * repairs: the definitions are owned by the five Logistics module migrations,
     * and a second migration that deletes them on rollback would strip live
     * permissions that other roles or environments legitimately hold. An extra
     * permission row is inert; a missing one silently 403s a whole module.
     */
    public function down(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('role_permissions')) {
            return;
        }

        $ids = DB::table('permissions')
            ->whereIn('name', array_column(self::PERMISSIONS, 0))
            ->pluck('id');

        $roleIds = DB::table('roles')
            ->whereIn('slug', array_merge(self::FULL_SET_ROLES, self::VIEW_ONLY_ROLES))
            ->pluck('id');

        DB::table('role_permissions')
            ->whereIn('role_id', $roleIds)
            ->whereIn('permission_id', $ids)
            ->delete();
    }
};

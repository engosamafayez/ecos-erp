<?php

declare(strict_types=1);

namespace Modules\IAM\Infrastructure\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;

/**
 * Seeds roles and permissions from config/permissions.php.
 *
 * Safe to re-run: uses firstOrCreate for every record.
 * After seeding it invalidates the RBAC permission cache.
 */
final class RbacSeeder extends Seeder
{
    public function run(): void
    {
        // ── 1. Create every permission ─────────────────────────────────────────
        $allPermissions = [];

        foreach (config('permissions.modules', []) as $module => $actions) {
            foreach ($actions as $action) {
                $name = "{$module}.{$action}";

                /** @var Permission $permission */
                $permission = Permission::firstOrCreate(
                    ['name' => $name],
                    [
                        'module'      => $module,
                        'action'      => $action,
                        'description' => ucfirst($action).' '.str_replace('_', ' ', $module),
                    ],
                );

                $allPermissions[$name] = $permission;
            }
        }

        $this->command->info(sprintf(
            '  RBAC: %d permissions seeded.',
            count($allPermissions),
        ));

        // ── 2. Create every role ───────────────────────────────────────────────
        $roles = [];

        foreach (config('permissions.roles', []) as $slug => $name) {
            $roles[$slug] = Role::firstOrCreate(
                ['slug' => $slug],
                [
                    'name'      => $name,
                    'is_system' => true,
                ],
            );
        }

        $this->command->info(sprintf(
            '  RBAC: %d roles seeded.',
            count($roles),
        ));

        // ── 3. Assign permissions to roles ─────────────────────────────────────
        //
        // Super Admin is intentionally excluded from this mapping.
        // It gains access through Gate::before() instead of per-permission rows,
        // so new permissions automatically apply without re-seeding.
        //
        $rolePermissionMap = config('permissions.role_permissions', []);

        foreach ($rolePermissionMap as $slug => $moduleGrants) {
            if (! isset($roles[$slug])) {
                continue;
            }

            $role = $roles[$slug];
            $permissionIds = [];

            foreach ($moduleGrants as $module => $actions) {
                foreach ($actions as $action) {
                    $name = "{$module}.{$action}";
                    if (isset($allPermissions[$name])) {
                        $permissionIds[] = $allPermissions[$name]->id;
                    }
                }
            }

            // syncWithoutDetaching: idempotent; re-running never removes
            // permissions that were manually granted outside the seeder.
            $role->permissions()->syncWithoutDetaching($permissionIds);

            $this->command->line(sprintf(
                '    → %s (%d permissions)',
                $role->name,
                count($permissionIds),
            ));
        }

        // ── 4. Flush RBAC cache after seeding ─────────────────────────────────
        // This ensures no stale cached permission lists survive the seed run.
        try {
            cache()->flush();
        } catch (\Throwable) {
            // Non-fatal: cache may not be configured during CI.
        }
    }
}

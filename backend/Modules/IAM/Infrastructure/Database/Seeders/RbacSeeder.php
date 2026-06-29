<?php

declare(strict_types=1);

namespace Modules\IAM\Infrastructure\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;

/**
 * Seeds roles and permissions from config/permissions.php.
 *
 * Safe to re-run: cleans up old two-segment permission names then uses
 * firstOrCreate for every new record.
 *
 * Permission format: domain.resource.action  (e.g. inventory.products.view)
 * Role is_system:    driven by config — never hardcoded in application logic.
 */
final class RbacSeeder extends Seeder
{
    public function run(): void
    {
        // ── 0. Remove stale two-segment permissions (old module.action format) ──
        //
        // Old format has exactly one dot; new format has exactly two.
        // This keeps re-seeding idempotent when migrating from 001 → 001A.
        //
        $removed = Permission::all()
            ->filter(fn (Permission $p) => substr_count($p->name, '.') === 1)
            ->each->delete()
            ->count();

        if ($removed > 0) {
            $this->command->warn(sprintf(
                '  RBAC: removed %d old-format permissions (module.action → domain.resource.action).',
                $removed,
            ));
        }

        // ── 1. Create every permission (domain.resource.action) ───────────────
        $allPermissions = [];

        foreach (config('permissions.modules', []) as $domain => $resources) {
            foreach ($resources as $resource => $actions) {
                foreach ($actions as $action) {
                    $name = "{$domain}.{$resource}.{$action}";

                    /** @var Permission $permission */
                    $permission = Permission::firstOrCreate(
                        ['name' => $name],
                        [
                            'module'      => $domain,
                            'resource'    => $resource,
                            'action'      => $action,
                            'description' => ucfirst($action).' '.str_replace('_', ' ', $resource),
                        ],
                    );

                    $allPermissions[$name] = $permission;
                }
            }
        }

        $this->command->info(sprintf(
            '  RBAC: %d permissions seeded.',
            count($allPermissions),
        ));

        // ── 2. Create every role ───────────────────────────────────────────────
        $roles = [];

        foreach (config('permissions.roles', []) as $slug => $def) {
            $roles[$slug] = Role::firstOrCreate(
                ['slug' => $slug],
                [
                    'name'      => $def['name'],
                    'is_system' => $def['is_system'],
                ],
            );
        }

        $this->command->info(sprintf(
            '  RBAC: %d roles seeded.',
            count($roles),
        ));

        // ── 3. Assign permissions to roles ─────────────────────────────────────
        //
        // Super Admin is intentionally excluded from role_permissions.
        // System roles gain access through Gate::before() — no per-permission
        // rows required. New permissions automatically apply without re-seeding.
        //
        $rolePermissionMap = config('permissions.role_permissions', []);

        foreach ($rolePermissionMap as $slug => $domainResourceGrants) {
            if (! isset($roles[$slug])) {
                continue;
            }

            $role          = $roles[$slug];
            $permissionIds = [];

            foreach ($domainResourceGrants as $domainResource => $actions) {
                foreach ($actions as $action) {
                    $name = "{$domainResource}.{$action}"; // e.g. inventory.products.view
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
        try {
            cache()->flush();
        } catch (\Throwable) {
            // Non-fatal: cache may not be configured during CI.
        }
    }
}

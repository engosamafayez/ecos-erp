<?php

declare(strict_types=1);

namespace Modules\IAM\Application\Services;

use Illuminate\Support\Facades\DB;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Modules\IAM\Domain\Models\RoleTemplate;

/**
 * Compiles a Role Template (ADR-039) into a runtime Role + role_permissions (ADR-040,
 * Decision 2). This closes the authoring→runtime loop: it is the ONLY place that sets
 * role_templates.role_id, so the Authorization Platform keeps reading roles unchanged.
 *
 * Idempotent: re-compiling re-syncs the linked role's grants from the current definition.
 */
class RoleTemplateCompiler
{
    public function __construct(private readonly RoleCompositionService $composition) {}

    /** Ensure the template has a linked runtime role whose grants match its profile. */
    public function compile(RoleTemplate $template): Role
    {
        $role = $this->resolveRole($template);
        $profile = $this->composition->composeProfiles([$template->profile()]);

        $pivot = [];
        foreach ($profile->permissions as $name) {
            $permission = $this->ensurePermission($name);
            if ($permission === null) {
                continue;
            }
            $resource = $this->resourceOf($name);
            $pivot[$permission->id] = [
                'effect' => 'allow',
                'data_scope' => $profile->scopeFor($resource),
            ];
        }

        // The compiled role is fully owned by the template — sync (replace) its grants.
        $role->permissions()->sync($pivot);

        return $role;
    }

    private function resolveRole(RoleTemplate $template): Role
    {
        if ($template->role_id !== null) {
            $existing = Role::find($template->role_id);
            if ($existing !== null) {
                return $existing;
            }
        }

        $role = Role::firstOrCreate(
            ['slug' => 'tpl-'.$template->key],
            ['name' => $template->name, 'description' => 'Compiled from role template: '.$template->key, 'is_system' => false],
        );

        // Link without mutating a system template through the guarded repository.
        DB::table('role_templates')->where('id', $template->id)->update(['role_id' => $role->id]);
        $template->setAttribute('role_id', $role->id);

        return $role;
    }

    /** firstOrCreate a permission row for a concrete name (module.resource.action or module.action). */
    private function ensurePermission(string $name): ?Permission
    {
        $parts = explode('.', $name);
        if (count($parts) < 2) {
            return null; // not a valid permission token
        }

        if (count($parts) === 2) {
            [$module, $action] = $parts;
            $resource = null;
        } else {
            $module = $parts[0];
            $action = $parts[count($parts) - 1];
            $resource = implode('.', array_slice($parts, 1, -1));
        }

        return Permission::firstOrCreate(
            ['name' => $name],
            ['module' => $module, 'resource' => $resource, 'action' => $action],
        );
    }

    /** "sales.orders.view" → "sales.orders" (the scope key). */
    private function resourceOf(string $name): string
    {
        $parts = explode('.', $name);
        if (count($parts) <= 2) {
            return $parts[0];
        }

        return implode('.', array_slice($parts, 0, -1));
    }
}

<?php

declare(strict_types=1);

namespace Modules\IAM\Application\Services;

use Illuminate\Support\Facades\DB;
use Modules\IAM\Domain\Exceptions\UnknownTemplatePermissionException;
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

        // Resolve every referenced permission against the catalog FIRST, and collect
        // all failures before throwing. Reporting one unknown token per run would
        // make correcting a template an exercise in repeated trial (BUG-GL-011).
        $names = array_values(array_filter(
            $profile->permissions,
            static fn (string $name): bool => substr_count($name, '.') >= 1,
        ));

        $resolved = Permission::query()->whereIn('name', $names)->get()->keyBy('name');
        $unknown = array_values(array_diff($names, $resolved->keys()->all()));

        if ($unknown !== []) {
            throw UnknownTemplatePermissionException::forTemplate($template->key, $unknown);
        }

        $pivot = [];
        foreach ($names as $name) {
            $resource = $this->resourceOf($name);
            $pivot[$resolved[$name]->id] = [
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

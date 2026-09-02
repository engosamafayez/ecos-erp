<?php

declare(strict_types=1);

namespace Modules\IAM\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\IAM\Domain\Models\Role;

/**
 * IAM / Admin / Roles (TASK-ECOS-IAM-SECURE-ADMIN-API-002, §7/§11/§18) — READ-ONLY by
 * architecture decision, not an oversight.
 *
 * Task 1's ratified architecture (ADR-039/040 Decision 2: roles are assigned ONLY through Role
 * Templates) is binding here: "create custom role" / "edit role metadata" / "assign or revoke
 * permissions directly" from the batch's own §11 wishlist would each mean writing role_permissions
 * outside RoleTemplateCompiler's fail-closed validation — a second, parallel role-mutation path,
 * which §0/§11 of this same task explicitly forbids ("DO NOT create a second role engine",
 * "DO NOT implement raw table CRUD"). Every one of those capabilities is instead satisfied by the
 * Role TEMPLATE API (RoleTemplateController) — creating/editing a template and applying it IS how
 * a role's permission set is authored and changed. "Assign role to user" / "revoke role from user"
 * are UserController::assignTemplate()/revokeTemplate() for the same reason. This controller only
 * exposes what a role actually is once compiled: a read model over the template that produced it.
 *
 * Authorization: a Role carries no company_id and no per-instance tenant logic of its own (that
 * boundary lives on the template that compiled it, enforced by RoleTemplatePolicy on that
 * resource) — so, matching this codebase's own convention for plain permission-gated reads
 * (e.g. MasterGeographyController), both actions are gated by route-level `permission:iam.roles.view`
 * middleware rather than a bespoke Policy with nothing tenant-specific to check.
 */
final class RoleController extends Controller
{
    use HasApiResponse;

    public function index(): JsonResponse
    {
        $roles = Role::query()
            ->withCount('users')
            ->with('roleTemplate:id,key,name,is_system')
            ->orderBy('name')
            ->get();

        return $this->success(array_map($this->serialize(...), $roles->all()));
    }

    public function show(Role $role): JsonResponse
    {
        $role->loadCount('users');
        $role->load(['permissions:id,name', 'roleTemplate:id,key,name,is_system']);

        return $this->success($this->serialize($role) + [
            'permissions' => $role->permissions->pluck('name')->values()->all(),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function serialize(Role $role): array
    {
        return [
            'id' => $role->getKey(),
            'slug' => $role->slug,
            'name' => $role->name,
            'is_system' => (bool) $role->is_system,
            'user_count' => $role->users_count ?? null,
            'template' => $role->relationLoaded('roleTemplate') && $role->roleTemplate !== null
                ? ['key' => $role->roleTemplate->key, 'name' => $role->roleTemplate->name, 'is_system' => (bool) $role->roleTemplate->is_system]
                : null,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Modules\IAM\Presentation\Http\Controllers;

use App\Core\Company\TenantOwnershipResolver;
use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\IAM\Application\Services\RoleAuthoringService;
use Modules\IAM\Domain\Catalog\BusinessRoleCatalog;
use Modules\IAM\Domain\Catalog\PermissionBusinessCatalog;
use Modules\IAM\Domain\Enums\RoleCategory;
use Modules\IAM\Domain\Exceptions\RoleLifecycleException;
use Modules\IAM\Domain\Models\Role;
use Modules\IAM\Presentation\Http\Requests\CloneRoleRequest;
use Modules\IAM\Presentation\Http\Requests\CreateRoleRequest;
use Modules\IAM\Presentation\Http\Requests\UpdateRolePermissionsRequest;
use Modules\IAM\Presentation\Http\Requests\UpdateRoleRequest;

/**
 * IAM / Admin / Roles.
 *
 * ORIGINALLY READ-ONLY, NOW WRITABLE — and the original reasoning is preserved intact.
 *
 * The predecessor of this controller was read-only by explicit architecture decision
 * (ADR-039/040 Decision 2: a role's permission set is authored through Role Templates), and
 * the reason it gave was precise: exposing "create role" / "assign or revoke permissions
 * directly" here would mean writing `role_permissions` outside
 * `RoleTemplateCompiler`'s fail-closed validation — a second, parallel role-mutation path.
 *
 * TASK-ECOS-IAM-FINAL-REMEDIATION-DIRECT-DEV-001 §11/§14 requires Create / Edit / Clone /
 * Archive / Delete Role and an EDITABLE permission matrix. Those requirements are met
 * without breaking that decision, because none of the write actions below touch
 * `role_permissions`. Every one delegates to `RoleAuthoringService`, which authors the
 * backing Role Template and then calls the ONE canonical compiler. §15 is likewise
 * unbroken: templates remain presets, and runtime authorization is still
 * User → Role → canonical permissions.
 *
 * Presentation: the index/show payloads now lead with Arabic business language (§5/§13) —
 * role name/description in Arabic where the role is part of the approved business
 * catalogue, and each granted permission carried with its Arabic business label,
 * description, module group and sensitivity band from `PermissionBusinessCatalog`. The raw
 * canonical key is always present as `name`, never replaced.
 */
final class RoleController extends Controller
{
    use HasApiResponse;

    public function __construct(
        private readonly RoleAuthoringService $authoring,
        private readonly TenantOwnershipResolver $tenant,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Role::class);

        $roles = Role::query()
            ->withCount('users')
            ->with('roleTemplate:id,key,name,is_system,company_id,status')
            ->when(! $request->boolean('include_archived'), fn ($q) => $q->whereNull('archived_at'))
            ->orderBy('archived_at')
            ->orderBy('name')
            ->get();

        return $this->success([
            'data' => array_map($this->serialize(...), $roles->all()),
            'meta' => [
                'total' => $roles->count(),
                'business_catalog' => BusinessRoleCatalog::keys(),
                'categories' => RoleCategory::options(),
            ],
        ]);
    }

    public function show(Role $role): JsonResponse
    {
        Gate::authorize('view', $role);

        $role->loadCount('users');
        $role->load(['permissions:id,name,description', 'roleTemplate:id,key,name,is_system,company_id,status']);

        return $this->success($this->serialize($role) + [
            // Raw canonical keys, unchanged — every existing consumer keeps working.
            'permissions' => $role->permissions->pluck('name')->values()->all(),
            // The same set, described in business language for the permission matrix (§14).
            'permission_details' => $role->permissions
                ->map(fn ($p) => PermissionBusinessCatalog::describe((string) $p->name, $p->description))
                ->values()->all(),
            'definition' => $role->roleTemplate?->definition,
            'editable' => $this->isEditable($role),
            'assigned_users' => $role->users()
                ->select('users.id', 'users.name', 'users.email', 'users.status')
                ->limit(200)->get()
                ->map(fn ($u) => [
                    'id' => $u->getKey(),
                    'name' => $u->name,
                    'email' => $u->email,
                    'status' => $u->status,
                ])->values()->all(),
        ]);
    }

    public function store(CreateRoleRequest $request): JsonResponse
    {
        Gate::authorize('create', Role::class);

        $companyId = $this->resolveCompanyId($request);
        $role = $this->authoring->create($request->validated(), $companyId, $request->user()?->getKey());

        return $this->created($this->serialize($role->loadCount('users')));
    }

    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        Gate::authorize('update', $role);

        $role = $this->authoring->updateMetadata($role, $request->validated(), $request->user()?->getKey());

        return $this->updated($this->serialize($role->loadCount('users')));
    }

    /** §14 — the editable permission matrix's save path. */
    public function updatePermissions(UpdateRolePermissionsRequest $request, Role $role): JsonResponse
    {
        Gate::authorize('update', $role);

        $role = $this->authoring->updatePermissions(
            $role,
            $request->validated('permissions'),
            $request->user()?->getKey(),
        );

        return $this->updated($this->serialize($role->loadCount('users')) + [
            'permissions' => $role->permissions()->pluck('name')->values()->all(),
        ]);
    }

    public function cloneRole(CloneRoleRequest $request, Role $role): JsonResponse
    {
        Gate::authorize('cloneFrom', $role);

        $companyId = $this->resolveCompanyId($request);
        $clone = $this->authoring->cloneRole(
            $role,
            (string) $request->validated('name'),
            $companyId,
            $request->user()?->getKey(),
        );

        return $this->created($this->serialize($clone->loadCount('users')));
    }

    public function archive(Request $request, Role $role): JsonResponse
    {
        Gate::authorize('archive', $role);

        $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:512']]);

        $role = $this->authoring->archive($role, $request->input('reason'), $request->user()?->getKey());

        return $this->updated($this->serialize($role->loadCount('users')));
    }

    public function restore(Request $request, Role $role): JsonResponse
    {
        Gate::authorize('restore', $role);

        $role = $this->authoring->restore($role, $request->user()?->getKey());

        return $this->updated($this->serialize($role->loadCount('users')));
    }

    public function destroy(Request $request, Role $role): JsonResponse
    {
        Gate::authorize('delete', $role);

        $this->authoring->delete($role, $request->user()?->getKey());

        return $this->success(null, 'Role deleted.');
    }

    /**
     * D2 pattern, identical to UserController::store(): tenant ownership is server-derived.
     * An unrestricted actor may target an explicit company because there is no acting
     * company for a cross-company actor; everyone else is bound to their own.
     */
    private function resolveCompanyId(Request $request): string
    {
        $companyId = $this->tenant->isUnrestricted() && $request->filled('company_id')
            ? $request->string('company_id')->toString()
            : $this->tenant->companyId();

        if ($companyId === null) {
            throw RoleLifecycleException::noResolvableCompany();
        }

        return $companyId;
    }

    /**
     * Whether the permission matrix may be saved for this role. A system role and a role
     * compiled from an immutable ECOS system template are both View + Clone only (§11/§15).
     */
    private function isEditable(Role $role): bool
    {
        if ($role->is_system || in_array((string) $role->slug, BusinessRoleCatalog::SYSTEM_PROTECTED_ROLES, true)) {
            return false;
        }

        return ! ($role->roleTemplate?->is_system ?? false);
    }

    /**
     * @return array<string,mixed>
     */
    private function serialize(Role $role): array
    {
        $template = $role->roleTemplate;
        $businessKey = $template?->key;
        $display = $businessKey !== null ? BusinessRoleCatalog::displayFor($businessKey) : null;

        return [
            'id' => $role->getKey(),
            'slug' => $role->slug,
            'name' => $role->name,
            // Arabic business name/description for the approved catalogue (§5/§12). Falls back
            // to the stored name so a custom role an administrator authored still reads well.
            'name_ar' => $display['name_ar'] ?? $role->name,
            'description' => $role->description,
            'description_ar' => $display['description_ar'] ?? $role->description,
            'is_system' => (bool) $role->is_system,
            'is_business_catalog' => $businessKey !== null && $display !== null,
            'archived' => $role->isArchived(),
            'archived_at' => $role->archived_at?->toIso8601String(),
            'archived_reason' => $role->archived_reason,
            'user_count' => $role->users_count ?? null,
            'editable' => $this->isEditable($role),
            'scope_expectation' => $businessKey !== null
                ? BusinessRoleCatalog::scopeExpectationFor($businessKey)
                : [],
            'template' => $template !== null
                ? [
                    'key' => $template->key,
                    'name' => $template->name,
                    'is_system' => (bool) $template->is_system,
                    'status' => $template->status,
                ]
                : null,
        ];
    }
}

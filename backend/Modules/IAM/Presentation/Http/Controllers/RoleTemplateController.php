<?php

declare(strict_types=1);

namespace Modules\IAM\Presentation\Http\Controllers;

use App\Core\Company\TenantOwnershipResolver;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\IAM\Application\Services\RoleComparisonService;
use Modules\IAM\Application\Services\RoleCompositionService;
use Modules\IAM\Application\Services\RoleTemplateCompiler;
use Modules\IAM\Application\Services\RoleTemplateExportService;
use Modules\IAM\Application\Services\RoleTemplateVersionService;
use Modules\IAM\Domain\Contracts\RoleTemplateRepositoryInterface;
use Modules\IAM\Domain\Models\Role;
use Modules\IAM\Domain\Models\RoleTemplate;
use Modules\IAM\Domain\Models\UserTemplateAssignment;
use Modules\IAM\Presentation\Http\Requests\CloneRoleTemplateRequest;
use Modules\IAM\Presentation\Http\Requests\CreateRoleTemplateRequest;
use Modules\IAM\Presentation\Http\Requests\UpdateRoleTemplateRequest;

/**
 * IAM / Admin / Role Templates (TASK-ECOS-IAM-SECURE-ADMIN-API-002, §9/§14/§16/§18). Exposes the
 * EXISTING Role Template engine (RoleTemplateRepository, RoleTemplateCompiler,
 * RoleCompositionService, RoleComparisonService, RoleTemplateVersionService,
 * RoleTemplateExportService) — no new engine, no second compiler.
 *
 * D11: system templates are global; custom templates are company-scoped — RoleTemplatePolicy
 * enforces both, self-consistently with UserPolicy's pattern.
 * D13: destroy() refuses a used template (RoleTemplateInUseException, mapped to 409); archive()
 * is the safe lifecycle path for a used template.
 * D12: impactPreview()/apply() are the backend foundation for "Preview Impact → Apply Version" —
 * see the docblock on apply() for why this is a template-wide operation, not a per-user loop.
 */
final class RoleTemplateController extends Controller
{
    use HasApiResponse;

    public function __construct(
        private readonly RoleTemplateRepositoryInterface $repository,
        private readonly RoleTemplateVersionService $versions,
        private readonly RoleComparisonService $comparison,
        private readonly RoleTemplateExportService $export,
        private readonly RoleTemplateCompiler $compiler,
        private readonly RoleCompositionService $composition,
        private readonly TenantOwnershipResolver $tenant,
    ) {}

    /** System templates (global) + this actor's own company's custom templates (D11). */
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', RoleTemplate::class);

        $system = $this->repository->systemTemplates();
        $custom = $this->tenant->isUnrestricted()
            ? $this->repository->customTemplates()
            : ($this->tenant->companyId() !== null ? $this->repository->customTemplatesForCompany($this->tenant->companyId()) : collect());

        $templates = $system->concat($custom);

        return $this->success($templates->map($this->serialize(...))->values()->all());
    }

    public function show(RoleTemplate $roleTemplate): JsonResponse
    {
        Gate::authorize('view', $roleTemplate);

        return $this->success($this->serialize($roleTemplate, detailed: true));
    }

    public function versions(RoleTemplate $roleTemplate): JsonResponse
    {
        Gate::authorize('view', $roleTemplate);

        $history = $this->versions->history($roleTemplate)->map(fn ($v) => [
            'version' => $v->version,
            'status' => $v->status,
            'change_note' => $v->change_note,
            'created_by' => $v->created_by,
            'created_at' => $v->created_at?->toIso8601String(),
        ]);

        return $this->success($history->values()->all());
    }

    public function compare(RoleTemplate $roleTemplate, RoleTemplate $other): JsonResponse
    {
        Gate::authorize('view', $roleTemplate);
        Gate::authorize('view', $other);

        return $this->success($this->comparison->compare($roleTemplate, $other)->toArray());
    }

    public function export(RoleTemplate $roleTemplate): JsonResponse
    {
        Gate::authorize('view', $roleTemplate);

        return $this->success($this->export->toArray($roleTemplate));
    }

    public function store(CreateRoleTemplateRequest $request): JsonResponse
    {
        Gate::authorize('create', RoleTemplate::class);

        $companyId = $this->requireCompanyId($request->user());
        if ($companyId === null) {
            return $this->error('Cannot create a role template without a resolvable company.', 422);
        }

        $template = $this->repository->createCustom($request->validated() + [
            'created_by' => $request->user()?->getKey(),
            'company_id' => $companyId,
        ]);

        return $this->created($this->serialize($template, detailed: true));
    }

    public function cloneTemplate(CloneRoleTemplateRequest $request, RoleTemplate $roleTemplate): JsonResponse
    {
        Gate::authorize('cloneFrom', $roleTemplate);

        $companyId = $this->requireCompanyId($request->user());
        if ($companyId === null) {
            return $this->error('Cannot clone a role template without a resolvable company.', 422);
        }

        $clone = $this->repository->clone($roleTemplate, (string) $request->validated('new_key'), $companyId, $request->validated('new_name'));

        return $this->created($this->serialize($clone, detailed: true));
    }

    public function update(UpdateRoleTemplateRequest $request, RoleTemplate $roleTemplate): JsonResponse
    {
        Gate::authorize('update', $roleTemplate);

        $template = $this->repository->update(
            $roleTemplate,
            $request->validated() + ['updated_by' => $request->user()?->getKey()],
            $request->validated('change_note'),
        );

        return $this->updated($this->serialize($template, detailed: true));
    }

    /** D13 preferred lifecycle path — safe for a used template, unlike destroy(). */
    public function archive(RoleTemplate $roleTemplate): JsonResponse
    {
        Gate::authorize('archive', $roleTemplate);

        $template = $this->repository->archive($roleTemplate);

        return $this->updated($this->serialize($template, detailed: true));
    }

    /** D13 hard security contract — the repository itself refuses a used template (409 via the exception handler). */
    public function destroy(RoleTemplate $roleTemplate): JsonResponse
    {
        Gate::authorize('delete', $roleTemplate);

        $this->repository->delete($roleTemplate);

        return $this->deleted();
    }

    /**
     * D12 backend foundation: what would change if apply() ran right now. Diffs the template's
     * CURRENT definition against its LINKED role's actually-compiled permissions (which is
     * exactly what happens when update() runs without a following compile — the two drift), plus
     * how many assignments would be affected. Read-only; nothing is mutated.
     */
    public function impactPreview(RoleTemplate $roleTemplate): JsonResponse
    {
        Gate::authorize('view', $roleTemplate);

        $intended = $this->composition->composeProfiles([$roleTemplate->profile()])->permissions;
        $compiled = $roleTemplate->role_id !== null
            ? (Role::find($roleTemplate->role_id)?->permissions->pluck('name')->all() ?? [])
            : [];

        return $this->success([
            'template_version' => $roleTemplate->version,
            'compiled' => $roleTemplate->role_id !== null,
            'affected_holders' => UserTemplateAssignment::where('role_template_id', $roleTemplate->id)->count(),
            'permission_additions' => array_values(array_diff($intended, $compiled)),
            'permission_removals' => array_values(array_diff($compiled, $intended)),
        ]);
    }

    /**
     * D12 backend foundation: applies the template's CURRENT definition to its compiled role.
     *
     * This is deliberately a TEMPLATE-WIDE operation, not a per-user loop: every holder of a
     * given template shares exactly one compiled `tpl-{key}` Role (ADR-039/040 — one role per
     * template), so recompiling it updates every current holder's effective grants together, in
     * one atomic sync() + cache-invalidation pass (Security Gate B) — there is no architectural
     * way to "apply to User A but not User B" while they hold the same role. "Apply to selected /
     * all holders" (§16, Task 3's UX) is satisfied by this single call plus the affected_holders
     * count impactPreview() already returns for the confirmation screen; assigning the template
     * to a user who does NOT yet hold it is the separate, genuinely per-user
     * UserRoleAssignmentService::assignTemplate() action (which itself calls this same
     * compiler internally). No second compiler; RoleTemplateCompiler is reused unchanged.
     */
    public function apply(RoleTemplate $roleTemplate): JsonResponse
    {
        Gate::authorize('update', $roleTemplate);

        $affected = UserTemplateAssignment::where('role_template_id', $roleTemplate->id)->count();
        $role = $this->compiler->compile($roleTemplate);

        return $this->success([
            'role_id' => $role->getKey(),
            'template_version' => $roleTemplate->version,
            'affected_holders' => $affected,
            'permission_count' => $role->permissions()->count(),
        ], 'Template applied to its compiled role.');
    }

    private function requireCompanyId(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        return $this->tenant->companyId();
    }

    /**
     * @return array<string,mixed>
     */
    private function serialize(RoleTemplate $template, bool $detailed = false): array
    {
        // §5/§12: Arabic business name/description for a template that is part of the
        // approved fourteen-role catalogue (keyed `business-*`), so the role-assignment
        // picker and the templates list can lead with Arabic without a second lookup. A
        // custom or non-catalogue template falls back to its own stored name.
        $display = \Modules\IAM\Domain\Catalog\BusinessRoleCatalog::displayFor($template->key);

        $base = [
            'key' => $template->key,
            'name' => $template->name,
            'name_ar' => $display['name_ar'] ?? $template->name,
            'description' => $template->description,
            'description_ar' => $display['description_ar'] ?? $template->description,
            'category' => $template->category,
            'status' => $template->status,
            'version' => $template->version,
            'is_system' => (bool) $template->is_system,
            'is_composable' => (bool) $template->is_composable,
            'company_id' => $template->company_id,
            'compiled' => $template->role_id !== null,
        ];

        if (! $detailed) {
            return $base;
        }

        return $base + [
            'definition' => $template->definition,
            'assignment_count' => UserTemplateAssignment::where('role_template_id', $template->id)->count(),
            'published_at' => $template->published_at?->toIso8601String(),
            'created_at' => $template->created_at?->toIso8601String(),
            'updated_at' => $template->updated_at?->toIso8601String(),
        ];
    }
}

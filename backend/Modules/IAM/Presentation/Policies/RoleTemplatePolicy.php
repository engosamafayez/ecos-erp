<?php

declare(strict_types=1);

namespace Modules\IAM\Presentation\Policies;

use App\Core\Company\TenantOwnershipResolver;
use App\Models\User;
use Modules\IAM\Domain\Contracts\PermissionServiceInterface;
use Modules\IAM\Domain\Models\RoleTemplate;

/**
 * Authorization for the Role Template Admin API (TASK-ECOS-IAM-SECURE-ADMIN-API-002, §14).
 *
 * D11 (CTO-ratified): system templates are global — no tenant boundary applies to them, matching
 * ADR-039's "portable official ECOS job profiles" intent. Custom templates are company-scoped;
 * every ability that names a target template requires BOTH permission and (for a custom
 * template) tenant ownership, via the same TenantOwnershipResolver UserPolicy already uses —
 * not reimplemented here. No possession of a template id grants access on its own.
 */
class RoleTemplatePolicy extends BasePolicy
{
    public function __construct(
        PermissionServiceInterface $permissions,
        private readonly TenantOwnershipResolver $tenant,
    ) {
        parent::__construct($permissions);
    }

    /** System templates are global; custom templates require tenant ownership. */
    private function ownsTemplate(RoleTemplate $template): bool
    {
        if ($template->is_system) {
            return true;
        }

        $companyId = is_string($template->company_id) ? $template->company_id : null;

        return $this->tenant->owns($companyId);
    }

    public function viewAny(User $user): bool
    {
        return $this->can($user, 'iam.role-templates.view');
    }

    public function view(User $user, RoleTemplate $template): bool
    {
        return $this->can($user, 'iam.role-templates.view') && $this->ownsTemplate($template);
    }

    /** Create a new custom template, or clone any template into one. */
    public function create(User $user): bool
    {
        return $this->can($user, 'iam.role-templates.create');
    }

    /** Cloning FROM $template: system templates are readable by anyone with view; a custom source must be owned. */
    public function cloneFrom(User $user, RoleTemplate $template): bool
    {
        return $this->can($user, 'iam.role-templates.create') && $this->ownsTemplate($template);
    }

    public function update(User $user, RoleTemplate $template): bool
    {
        return $this->can($user, 'iam.role-templates.update') && $this->ownsTemplate($template);
    }

    /** Same permission as update() — archiving is the D13 lifecycle path, not a distinct ability. */
    public function archive(User $user, RoleTemplate $template): bool
    {
        return $this->can($user, 'iam.role-templates.update') && $this->ownsTemplate($template);
    }

    public function delete(User $user, RoleTemplate $template): bool
    {
        return $this->can($user, 'iam.role-templates.delete') && $this->ownsTemplate($template);
    }
}

<?php

declare(strict_types=1);

namespace Modules\IAM\Application\Services;

use App\Core\Audit\AuditService;
use Illuminate\Support\Facades\Auth;
use Modules\IAM\Domain\Models\Role;
use Modules\IAM\Domain\Models\RoleTemplate;

/**
 * Thin, role/role-template-specific facade over the generic App\Core\Audit\AuditService —
 * the same pattern UserAuditService already establishes for entity_type 'user' (ADR-040,
 * Decision 4). Added by TASK-ECOS-IAM-SECURE-ADMIN-API-002 §18/§30.4: security-sensitive
 * template and role mutations must leave an auditable trail, and the platform already has
 * exactly one canonical mechanism for that — this reuses it rather than inventing a second.
 */
class RoleTemplateAuditService
{
    public const TEMPLATE_ENTITY = 'role_template';

    public const ROLE_ENTITY = 'role';

    public function __construct(private readonly AuditService $audit) {}

    /**
     * @param  array<string,mixed>  $old
     * @param  array<string,mixed>  $new
     * @param  array<string,mixed>  $metadata
     */
    public function logTemplate(string $action, RoleTemplate $template, array $old = [], array $new = [], array $metadata = []): void
    {
        $this->audit->record(
            action: 'role_template.'.$action,
            entityType: self::TEMPLATE_ENTITY,
            entityId: (string) $template->getKey(),
            companyId: is_string($template->company_id ?? null) ? $template->company_id : null,
            userId: Auth::id(),
            oldValues: $old,
            newValues: $new,
            metadata: $metadata,
        );
    }

    /**
     * $old/$new are appended AFTER $metadata deliberately: every pre-existing positional
     * call site passes at most three arguments, so adding them here cannot change the
     * meaning of any existing call. They are needed by RoleAuthoringService — §11 requires
     * role lifecycle changes to be audited, and "audited" for an edit means recording what
     * changed, not only that something did.
     *
     * @param  array<string,mixed>  $metadata
     * @param  array<string,mixed>  $old
     * @param  array<string,mixed>  $new
     */
    public function logRole(string $action, Role $role, array $metadata = [], array $old = [], array $new = []): void
    {
        $this->audit->record(
            action: 'role.'.$action,
            entityType: self::ROLE_ENTITY,
            entityId: (string) $role->getKey(),
            companyId: null,
            userId: Auth::id(),
            oldValues: $old,
            newValues: $new,
            metadata: $metadata,
        );
    }
}

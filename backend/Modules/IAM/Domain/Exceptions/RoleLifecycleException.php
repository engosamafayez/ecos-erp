<?php

declare(strict_types=1);

namespace Modules\IAM\Domain\Exceptions;

use RuntimeException;

/**
 * Role lifecycle guard failures (TASK-ECOS-IAM-FINAL-REMEDIATION-DIRECT-DEV-001, §11).
 *
 * §11 is explicit about what must NEVER happen: "Do NOT delete a role that is
 * system-required, still has assigned users, or is referenced by required configuration.
 * Require reassignment or safe archival first." Every one of those is a distinct named
 * constructor here, so the reason reaches the administrator as a sentence they can act on
 * rather than a generic 409.
 *
 * Mapped to HTTP 409 in bootstrap/app.php, matching the IAM error contract already
 * established for InvalidUserTransitionException / UserSecurityRuleException /
 * RoleTemplateInUseException: the request is well-formed and the role real, but the
 * current domain state does not permit the operation.
 */
final class RoleLifecycleException extends RuntimeException
{
    public static function systemRoleImmutable(string $slug): self
    {
        return new self(
            "Role '{$slug}' is a system role and cannot be edited, archived or deleted. ".
            'System roles carry platform invariants — clone it to a custom role instead.'
        );
    }

    public static function systemTemplateBacked(string $slug, string $templateKey): self
    {
        return new self(
            "Role '{$slug}' is compiled from the official ECOS system template '{$templateKey}', ".
            'which is immutable. Clone the role to get an editable copy.'
        );
    }

    public static function stillAssigned(string $slug, int $userCount): self
    {
        return new self(
            "Role '{$slug}' cannot be deleted — it is still assigned to {$userCount} user(s). ".
            'Reassign those users to another role first, or archive this role instead.'
        );
    }

    public static function archivedRoleNotAssignable(string $slug): self
    {
        return new self(
            "Role '{$slug}' is archived and can no longer be assigned. Restore it first, ".
            'or assign an active role.'
        );
    }

    public static function alreadyArchived(string $slug): self
    {
        return new self("Role '{$slug}' is already archived.");
    }

    public static function notArchived(string $slug): self
    {
        return new self("Role '{$slug}' is not archived, so it cannot be restored.");
    }

    public static function slugTaken(string $slug): self
    {
        return new self("A role with the identifier '{$slug}' already exists. Choose a different name.");
    }

    public static function noResolvableCompany(): self
    {
        return new self(
            'Cannot author a role without a resolvable company. A custom role is company-scoped '.
            'by construction (D11) and its owning company is derived server-side.'
        );
    }
}

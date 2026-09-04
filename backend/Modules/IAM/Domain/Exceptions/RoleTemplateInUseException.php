<?php

declare(strict_types=1);

namespace Modules\IAM\Domain\Exceptions;

use RuntimeException;

/**
 * Raised when a hard-delete is attempted against a custom Role Template that is currently
 * assigned to at least one user (TASK-ECOS-IAM-SECURE-ADMIN-API-002, D13 — CTO-ratified hard
 * security contract).
 *
 * Source proved that deleting a used template cascades away the UserTemplateAssignment row
 * while leaving the compiled Role and its user_roles grant untouched — silently orphaning live,
 * ungoverned access. Hard-delete must never be exposed for a template in this state; archive it
 * instead (RoleTemplateRepository::archive(), which sets status to RoleTemplateStatus::ARCHIVED
 * without touching any assignment or grant).
 */
final class RoleTemplateInUseException extends RuntimeException
{
    public static function forKey(string $key, int $assignmentCount): self
    {
        return new self(
            "Role template '{$key}' cannot be deleted — it is currently assigned to {$assignmentCount} user(s). ".
            'Archive it instead; hard-delete is only available for a template with zero assignments.',
        );
    }
}

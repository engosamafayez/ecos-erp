<?php

declare(strict_types=1);

namespace Modules\IAM\Domain\Exceptions;

use Modules\IAM\Domain\Enums\UserStatus;
use RuntimeException;

/**
 * Raised when an operation would violate an enterprise user-security rule (ADR-040, Decision 5):
 * self-deactivation, removing one's own super-admin role, removing the last super-admin, or
 * (TASK-ECOS-IAM-SECURE-ADMIN-API-002, D1) resetting a password against a lifecycle state that
 * does not permit it.
 */
final class UserSecurityRuleException extends RuntimeException
{
    public static function cannotActOnSelf(string $action): self
    {
        return new self("You cannot {$action} your own account.");
    }

    public static function cannotRemoveOwnSuperAdmin(): self
    {
        return new self('You cannot remove your own Super Administrator role.');
    }

    public static function cannotRemoveLastSuperAdmin(): self
    {
        return new self('Cannot remove or deactivate the last active Super Administrator.');
    }

    public static function cannotAssignSystemRoleWithoutSystemAuthority(): self
    {
        return new self('Assigning a system-level role template requires system-level authority.');
    }

    /** D1: ARCHIVED requires restore first; DELETED has no reset path. */
    public static function cannotResetPasswordInStatus(UserStatus $status): self
    {
        $message = match ($status) {
            UserStatus::ARCHIVED => 'Cannot reset password for an archived user — restore the account first.',
            UserStatus::DELETED => 'Cannot reset password for a deleted user.',
            default => "Cannot reset password while the account status is '{$status->value}'.",
        };

        return new self($message);
    }
}

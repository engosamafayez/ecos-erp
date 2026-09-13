<?php

declare(strict_types=1);

namespace Modules\IAM\Domain\Exceptions;

use RuntimeException;

/**
 * Raised when authoring a Role/Role Template would grant a permission the acting user does
 * not themselves hold (CORE-02 Task 1: Permission Grant Ceiling).
 *
 * Only NEW permissions — not already present in the template/role's current definition — are
 * checked, so re-saving an unchanged definition (including RoleAuthoringService::adoptIntoTemplate()'s
 * grant-preserving mirror of a legacy role's existing grants) is never blocked by this rule.
 * An actor holding system authority (is_system) is exempt — see PermissionGrantCeiling.
 */
final class PermissionGrantCeilingExceededException extends RuntimeException
{
    /**
     * @param  list<string>  $unauthorized  every requested permission the actor does not hold, not just the first
     */
    private function __construct(
        public readonly array $unauthorized,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * @param  list<string>  $unauthorized
     */
    public static function forPermissions(array $unauthorized): self
    {
        sort($unauthorized);

        $message = sprintf(
            "Cannot grant %d permission(s) you do not hold yourself:\n%s\n".
            'An actor may only grant permissions that are already part of their own effective access.',
            count($unauthorized),
            implode("\n", array_map(static fn (string $p): string => '  - '.$p, $unauthorized)),
        );

        return new self($unauthorized, $message);
    }
}

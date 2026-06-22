<?php

declare(strict_types=1);

namespace Modules\IAM\Application\DTO;

use App\Core\DTO\BaseDTO;
use App\Models\User;

/**
 * Immutable, safe representation of the authenticated user returned to clients.
 * Excludes sensitive attributes (password, tokens, …).
 */
final class AuthenticatedUserDTO extends BaseDTO
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $email,
    ) {}

    public static function fromModel(User $user): self
    {
        return new self(
            id: (int) $user->id,
            name: (string) $user->name,
            email: (string) $user->email,
        );
    }
}

<?php

declare(strict_types=1);

namespace Modules\IAM\Application\DTO;

use App\Core\DTO\BaseDTO;

/**
 * Immutable input for the login use case.
 *
 * TASK-ECOS-IAM-FINAL-REMEDIATION-DIRECT-DEV-001, §7: the credential is a LOGIN
 * IDENTIFIER — an email address OR a username — so the property is named for what it
 * actually holds. `fromArray()` accepts `identifier`, `email` or `username` as the input
 * key, preferring the explicit `identifier`, which keeps the existing client contract
 * (`{ email, password }`) working untouched while allowing a clearer one.
 *
 * The two consumers in the codebase (AuthController, LoginAction) are updated with it;
 * there is no third reader of `$dto->email`.
 */
final class LoginDTO extends BaseDTO
{
    public function __construct(
        public readonly string $identifier,
        public readonly string $password,
        public readonly bool $remember = false,
    ) {}

    /**
     * @param  array{identifier?: string, email?: string, username?: string, password: string, remember?: bool}  $data
     */
    public static function fromArray(array $data): self
    {
        $identifier = $data['identifier'] ?? $data['email'] ?? $data['username'] ?? '';

        return new self(
            identifier: (string) $identifier,
            password: $data['password'],
            remember: (bool) ($data['remember'] ?? false),
        );
    }
}

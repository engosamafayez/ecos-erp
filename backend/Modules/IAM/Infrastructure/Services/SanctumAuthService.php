<?php

declare(strict_types=1);

namespace Modules\IAM\Infrastructure\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\IAM\Domain\Contracts\AuthServiceInterface;

/**
 * Laravel Sanctum implementation of {@see AuthServiceInterface}.
 *
 * Uses stateless personal access tokens (Bearer) — no session/CSRF required.
 */
final class SanctumAuthService implements AuthServiceInterface
{
    private ?int $lastTokenId = null;

    public function attemptCredentials(string $email, string $password): ?User
    {
        /** @var User|null $user */
        $user = User::query()->where('email', $email)->first();

        if ($user === null || ! Hash::check($password, (string) $user->password)) {
            return null;
        }

        // D7 (TASK-ECOS-IAM-SECURE-ADMIN-API-002, CTO-ratified): valid credentials alone are
        // insufficient — the canonical lifecycle status gates authentication itself, using the
        // existing UserStatus::canAuthenticate() authority (previously defined, never called).
        // Returns null uniformly with the wrong-password case rather than a distinct exception,
        // deliberately: the login endpoint must not tell an unauthenticated caller whether an
        // account exists, or what state it's in.
        if (! $user->statusEnum()->canAuthenticate()) {
            return null;
        }

        return $user;
    }

    public function issueToken(User $user, bool $remember = false): string
    {
        // "Remember me" tokens are long-lived; otherwise expire after a day.
        $expiresAt = $remember ? null : now()->addDay();

        $newToken = $user->createToken('auth', ['*'], $expiresAt);
        $this->lastTokenId = (int) $newToken->accessToken->getKey();

        return $newToken->plainTextToken;
    }

    public function revokeCurrentToken(User $user): void
    {
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }
    }

    public function lastIssuedTokenId(): ?int
    {
        return $this->lastTokenId;
    }
}

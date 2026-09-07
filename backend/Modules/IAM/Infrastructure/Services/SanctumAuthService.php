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

    /**
     * TASK-ECOS-IAM-FINAL-REMEDIATION-DIRECT-DEV-001, §7: username is now a valid login
     * identifier alongside email.
     *
     * This is an EXTENSION of the existing lookup, not a second authentication flow. There
     * is still exactly one credential path: resolve one account from one identifier, then
     * Hash::check, then the lifecycle gate. Only the resolution step widened, from
     * `where('email', …)` to "email OR username".
     *
     * Three details that keep it safe:
     *
     *  • `whereNotNull('username')` — `users.username` is nullable, and most rows have it
     *    null. Without this guard an empty-string identifier could match on
     *    `username = ''`; with it, a null username can never be an identifier.
     *
     *  • Uniqueness is enforced at the source, not here. `users.username` and
     *    `users.email` each carry a UNIQUE index, and
     *    UserIdentityService::assertUniqueIdentity() additionally rejects a username that
     *    collides with any other user's email (and vice versa) — so this OR can resolve at
     *    most one account. `first()` is not papering over an ambiguity; the ambiguity is
     *    prevented on write.
     *
     *  • Ordering is deterministic and email-first, so even in the presence of legacy data
     *    that predates the cross-field check, an exact email match always wins over a
     *    username match rather than the result depending on table order.
     */
    public function attemptCredentials(string $identifier, string $password): ?User
    {
        /** @var User|null $user */
        $user = User::query()
            ->where(function ($query) use ($identifier): void {
                $query->where('email', $identifier)
                    ->orWhere(function ($q) use ($identifier): void {
                        $q->whereNotNull('username')->where('username', $identifier);
                    });
            })
            ->orderByRaw('CASE WHEN email = ? THEN 0 ELSE 1 END', [$identifier])
            ->first();

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

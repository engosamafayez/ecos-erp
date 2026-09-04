<?php

declare(strict_types=1);

namespace Modules\IAM\Domain\Contracts;

use App\Models\User;

/**
 * Port for authentication operations. Implemented by the Infrastructure layer
 * (e.g. Laravel Sanctum) so the Application layer stays framework-agnostic.
 */
interface AuthServiceInterface
{
    /**
     * Verify credentials and return the matching user, or null when invalid.
     */
    public function attemptCredentials(string $email, string $password): ?User;

    /**
     * Issue an API access token for the given user.
     *
     * @param  bool  $remember  When true, the token is long-lived.
     * @return string The plain-text token to return to the client.
     */
    public function issueToken(User $user, bool $remember = false): string;

    /**
     * Revoke the access token used for the current request.
     */
    public function revokeCurrentToken(User $user): void;

    /**
     * The database id of the token most recently issued by issueToken() on this instance, or
     * null if none has been issued yet. Additive (TASK-ECOS-IAM-SECURE-ADMIN-API-002, D10) — lets
     * a caller record session metadata against the issued token without changing issueToken()'s
     * existing return type.
     */
    public function lastIssuedTokenId(): ?int;
}

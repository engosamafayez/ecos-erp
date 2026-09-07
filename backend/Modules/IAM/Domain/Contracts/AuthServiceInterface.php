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
     *
     * $identifier is the LOGIN IDENTIFIER, not necessarily an email address
     * (TASK-ECOS-IAM-FINAL-REMEDIATION-DIRECT-DEV-001, §7). The parameter was renamed from
     * $email; the signature — one string, one string, nullable User — is unchanged, so
     * every existing positional call site and every implementation keeps working. Which
     * identifier columns are accepted is the implementation's decision, so this port stays
     * framework- AND policy-agnostic.
     */
    public function attemptCredentials(string $identifier, string $password): ?User;

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

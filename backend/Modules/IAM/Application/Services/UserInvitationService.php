<?php

declare(strict_types=1);

namespace Modules\IAM\Application\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\IAM\Domain\Enums\UserStatus;
use Modules\IAM\Domain\Models\UserInvitation;

/**
 * Invitation / activation flow (ADR-040): create → invite → activation token → password
 * setup → first login. Only the token HASH is persisted; the raw token is returned once
 * (for the activation link / email) and never stored or logged.
 */
class UserInvitationService
{
    public function __construct(
        private readonly UserLifecycleService $lifecycle,
        private readonly UserAuditService $audit,
    ) {}

    /**
     * Issue an invitation. Returns the RAW token (caller sends it; it is never persisted).
     */
    public function invite(User $user, ?int $actorId = null, int $ttlHours = 72): string
    {
        $raw = $this->issueToken($user, $actorId, $ttlHours);

        if ($user->statusEnum() === UserStatus::DRAFT) {
            $this->lifecycle->transition($user, UserStatus::INVITED);
        }

        $this->audit->log('invited', $user, [], ['email' => $user->email]);

        return $raw;
    }

    /**
     * Re-issue an invitation for a user who has not yet accepted one (CORE-02 Task 1).
     * Same mechanics as invite() — any outstanding pending invitation is revoked and a fresh
     * token issued — but audited as a distinct action so a resend is never indistinguishable
     * from the original invite in the trail. Refuses a user who already has an active account
     * (nothing pending left to resend), matching the state invite() itself expects.
     */
    public function resend(User $user, ?int $actorId = null, int $ttlHours = 72): string
    {
        if (! in_array($user->statusEnum(), [UserStatus::DRAFT, UserStatus::INVITED], true)) {
            throw new InvalidArgumentException('This user has already activated their account; there is no pending invitation to resend.');
        }

        $raw = $this->issueToken($user, $actorId, $ttlHours);

        $this->audit->log('invitation_resent', $user, [], ['email' => $user->email]);

        return $raw;
    }

    /**
     * Revoke a specific pending invitation (CORE-02 Task 1). Independently callable/auditable —
     * previously revocation only ever happened as an unaudited side-effect buried inside invite().
     */
    public function revoke(UserInvitation $invitation, ?int $actorId = null): void
    {
        if (! $invitation->isPending()) {
            throw new InvalidArgumentException('Only a pending invitation can be revoked.');
        }

        $invitation->status = UserInvitation::STATUS_REVOKED;
        $invitation->save();

        /** @var User $user */
        $user = $invitation->user;
        $this->audit->log('invitation_revoked', $user, [], ['email' => $invitation->email, 'invitation_id' => $invitation->getKey(), 'actor_id' => $actorId]);
    }

    /** This user's invitation history, most recent first — for the admin list/inspect view. */
    public function historyFor(User $user): \Illuminate\Support\Collection
    {
        return UserInvitation::where('user_id', $user->getKey())
            ->orderByDesc('created_at')
            ->get();
    }

    /** Core token issuance shared by invite()/resend() — never audits on its own; callers do. */
    private function issueToken(User $user, ?int $actorId, int $ttlHours): string
    {
        $raw = Str::random(64);

        // Revoke any outstanding invitations first.
        UserInvitation::where('user_id', $user->getKey())
            ->where('status', UserInvitation::STATUS_PENDING)
            ->update(['status' => UserInvitation::STATUS_REVOKED]);

        UserInvitation::create([
            'user_id' => $user->getKey(),
            'email' => $user->email,
            'token_hash' => hash('sha256', $raw),
            'status' => UserInvitation::STATUS_PENDING,
            'expires_at' => now()->addHours($ttlHours),
            'invited_by' => $actorId,
        ]);

        $user->invited_at = now();
        $user->invited_by = $actorId;
        $user->save();

        return $raw;
    }

    /** Look up a pending, unexpired invitation by its raw token. */
    public function findValidInvitation(string $rawToken): ?UserInvitation
    {
        $invitation = UserInvitation::where('token_hash', hash('sha256', $rawToken))
            ->where('status', UserInvitation::STATUS_PENDING)
            ->first();

        if ($invitation === null || $invitation->isExpired()) {
            return null;
        }

        return $invitation;
    }

    /**
     * Complete activation: set the password, mark the invitation accepted, activate the user.
     */
    public function activate(string $rawToken, string $newPassword, bool $requireChangeOnFirstLogin = false): User
    {
        $invitation = $this->findValidInvitation($rawToken);
        if ($invitation === null) {
            throw new InvalidArgumentException('Invitation is invalid or has expired.');
        }

        /** @var User $user */
        $user = $invitation->user;
        $user->password = Hash::make($newPassword);
        $user->password_changed_at = now();
        $user->require_password_change = $requireChangeOnFirstLogin;
        $user->email_verified_at = $user->email_verified_at ?? now();
        $user->save();

        $invitation->status = UserInvitation::STATUS_ACCEPTED;
        $invitation->accepted_at = now();
        $invitation->save();

        $this->lifecycle->transition($user, UserStatus::ACTIVE);
        $this->audit->log('activated', $user, [], []);

        return $user->refresh();
    }
}

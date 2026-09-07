<?php

declare(strict_types=1);

namespace Modules\IAM\Application\Services;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Modules\IAM\Domain\Exceptions\UserSecurityRuleException;

/**
 * Administrator password reset (TASK-IAM-PASSWORD-RESET-DOMAIN-OPERATION-001).
 *
 * The dedicated operation behind the `iam.users.reset-password` permission. It exists because
 * TASK-IAM-PASSWORD-RESET-CONTRACT-001 proved the invitation flow cannot serve this purpose:
 * `UserInvitationService::activate()` requires an invitation token, consumes the invitation,
 * stamps `email_verified_at` and force-transitions the user to ACTIVE — so an administrator
 * resetting a SUSPENDED user's password would silently restore that account's ability to
 * authenticate. None of those side effects happen here.
 *
 * What this operation does, and nothing more:
 *   • authorizes through the certified path — permission AND tenant ownership
 *   • hashes and persists the new password
 *   • stamps `password_changed_at`
 *   • sets `require_password_change`
 *   • records an audit entry
 *
 * Deliberately absent:
 *   • lifecycle change — `status`, `activated_at`, `suspended_at`, `locked_at` are never written
 *   • `email_verified_at`
 *   • invitation creation, consumption or revocation
 *   • role, permission, organization or company assignment
 *   • session/token revocation — `UserSessionService::forceLogout()` sits behind the separate
 *     `iam.users.manage-sessions` permission and the architecture does not define whether a reset
 *     implies it. Recorded as NOT DEFINED / OUT OF SCOPE rather than decided here.
 *
 * Authorization is performed inside the operation rather than left to the caller. That differs
 * from `UserLifecycleService` and `UserInvitationService`, which trust their callers, and is
 * deliberate: there is no IAM HTTP surface yet, and a password reset that could be invoked
 * unauthorized would be the most dangerous instance of the boundary defect certified closed by
 * TASK-IAM-TENANT-AUTHORIZATION-BOUNDARY-IMPLEMENTATION-001. It delegates to `UserPolicy` and so
 * duplicates neither the permission check nor the tenant check.
 */
class UserPasswordService
{
    public function __construct(private readonly UserAuditService $audit) {}

    /**
     * Reset another user's password as an administrator.
     *
     * @param  User  $target  the account whose password is being reset
     * @param  string  $newPassword  raw password; never logged, audited or returned
     * @param  int|null  $actorId  acting administrator, recorded in audit metadata only
     * @param  bool  $requirePasswordChange  force a change at next login
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException when the actor lacks
     *                                                        `iam.users.reset-password` or does not
     *                                                        own the target's company
     */
    public function adminReset(
        User $target,
        string $newPassword,
        ?int $actorId = null,
        bool $requirePasswordChange = false,
    ): User {
        // Permission AND tenant ownership, through the single certified authorization path:
        // UserPolicy::resetPassword() → BasePolicy::can() → PermissionService, plus
        // TenantOwnershipResolver. Throws before any mutation, so a denied reset leaves the
        // target completely untouched.
        Gate::authorize('resetPassword', $target);

        // D1 (TASK-ECOS-IAM-SECURE-ADMIN-API-002, CTO-ratified): a lifecycle-state gate, evaluated
        // AFTER authorization (matching the existing order-matters-only-for-clarity convention
        // above) and BEFORE any mutation. Archived/Deleted are rejected outright — restore is a
        // separate, explicit, audited operation; this method must never perform it implicitly.
        //
        // TASK-ECOS-IAM-FINAL-REMEDIATION-DIRECT-DEV-001 §10: the gate is now
        // allowsAdminPasswordWrite(), the union of "may reset an existing credential" and
        // "may set a first credential". DRAFT / INVITED / PENDING_ACTIVATION pass through the
        // second branch — that is the fix for the dead-end account, and it is a lifecycle
        // distinction, not a relaxation: ARCHIVED and DELETED are still refused, and the
        // password strength rules applied by the caller (AdminResetPasswordRequest) are
        // unchanged for every state.
        if (! $target->statusEnum()->allowsAdminPasswordWrite()) {
            throw UserSecurityRuleException::cannotResetPasswordInStatus($target->statusEnum());
        }

        $isInitialCredential = $target->statusEnum()->allowsAdminPasswordSet();

        // The `password` cast is `hashed`, which skips values that are already hashed, so this
        // is the same single-hash path `UserInvitationService` and `UserIdentityService` use.
        $target->password = Hash::make($newPassword);
        $target->password_changed_at = now();
        $target->require_password_change = $requirePasswordChange;

        // Only these three columns are written. `status` is not among them, by design: the
        // account's lifecycle state survives the reset unchanged.
        $target->save();

        // The raw password and its hash are both excluded from the audit payload. Only the
        // timestamp is recorded, matching how every other IAM user operation audits.
        // The action name distinguishes a first credential from a replacement so the audit
        // trail answers "was this account provisioned, or was someone's password changed?".
        $this->audit->log(
            $isInitialCredential ? 'password_initialised' : 'password_reset',
            $target,
            [],
            ['password_changed_at' => $target->password_changed_at?->toIso8601String()],
            ['actor_id' => $actorId, 'status' => $target->statusEnum()->value],
        );

        return $target->refresh();
    }
}

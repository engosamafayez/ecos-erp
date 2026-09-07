<?php

declare(strict_types=1);

namespace Modules\IAM\Domain\Enums;

/**
 * User lifecycle status (TASK-IAM-004 / ADR-040). Every transition is validated against
 * the allowed-transitions map and audited by UserLifecycleService.
 */
enum UserStatus: string
{
    case DRAFT = 'draft';
    case INVITED = 'invited';
    case PENDING_ACTIVATION = 'pending_activation';
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
    case SUSPENDED = 'suspended';
    case LOCKED = 'locked';
    case ARCHIVED = 'archived';
    case DELETED = 'deleted';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::INVITED => 'Invited',
            self::PENDING_ACTIVATION => 'Pending Activation',
            self::ACTIVE => 'Active',
            self::INACTIVE => 'Inactive',
            self::SUSPENDED => 'Suspended',
            self::LOCKED => 'Locked',
            self::ARCHIVED => 'Archived',
            self::DELETED => 'Deleted',
        };
    }

    /** Can this user authenticate right now? */
    public function canAuthenticate(): bool
    {
        return $this === self::ACTIVE;
    }

    /**
     * May an administrator RESET this account's password? (D1,
     * TASK-ECOS-IAM-SECURE-ADMIN-API-002 — CTO-ratified for ACTIVE/SUSPENDED/LOCKED/
     * ARCHIVED/DELETED explicitly.) A reset replaces the credential of a real, provisioned
     * account; INACTIVE is treated like SUSPENDED/LOCKED, a temporary administrative hold.
     *
     * Pre-activation states are deliberately NOT reset states — see
     * allowsAdminPasswordSet(), which is the operation they actually need.
     */
    public function allowsAdminPasswordReset(): bool
    {
        return in_array($this, [self::ACTIVE, self::INACTIVE, self::SUSPENDED, self::LOCKED], true);
    }

    /**
     * May an administrator SET this account's initial credential?
     * (TASK-ECOS-IAM-FINAL-REMEDIATION-DIRECT-DEV-001, §10.)
     *
     * §10 records the defect precisely: a DRAFT user was "effectively stuck with View-only
     * UX and password reset can produce: Cannot reset password while the account status is
     * 'draft'." The original rule was not wrong about WHY — a pre-activation account has no
     * real credential yet, so replacing one is meaningless — it was wrong about the
     * conclusion, because it left the only path to a first credential behind the invitation
     * flow, and an administrator provisioning an account directly never enters that flow.
     * The result was an account that could be created and could never be used: the dead end.
     *
     * So the distinction is now explicit. SETTING a first credential is permitted for
     * DRAFT / INVITED / PENDING_ACTIVATION; RESETTING an existing one is permitted for the
     * provisioned states above. Both go through UserPasswordService, both are audited, and
     * both apply the same password-strength rules — §10's "Do not weaken password security"
     * is untouched: nothing here lowers a requirement, it only distinguishes the FIRST
     * credential from a REPLACEMENT credential.
     *
     * ARCHIVED and DELETED remain excluded from both: restore is a separate, explicit,
     * audited operation and must never happen implicitly as a side effect of a password
     * change.
     */
    public function allowsAdminPasswordSet(): bool
    {
        return in_array($this, [self::DRAFT, self::INVITED, self::PENDING_ACTIVATION], true);
    }

    /**
     * Either operation is available — the single question the HTTP layer needs to answer
     * before it decides whether a password write is possible at all.
     */
    public function allowsAdminPasswordWrite(): bool
    {
        return $this->allowsAdminPasswordReset() || $this->allowsAdminPasswordSet();
    }

    /**
     * Is this account awaiting activation? Drives the §10 "explicit, discoverable Activate
     * action" in the UI, from the same authority the transition map uses.
     */
    public function isPreActivation(): bool
    {
        return in_array($this, [self::DRAFT, self::INVITED, self::PENDING_ACTIVATION], true);
    }

    /** Statuses this status may transition to. */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::DRAFT => [self::INVITED, self::ACTIVE, self::ARCHIVED, self::DELETED],
            self::INVITED => [self::PENDING_ACTIVATION, self::ACTIVE, self::SUSPENDED, self::ARCHIVED, self::DELETED],
            self::PENDING_ACTIVATION => [self::ACTIVE, self::SUSPENDED, self::ARCHIVED, self::DELETED],
            self::ACTIVE => [self::INACTIVE, self::SUSPENDED, self::LOCKED, self::ARCHIVED, self::DELETED],
            self::INACTIVE => [self::ACTIVE, self::SUSPENDED, self::ARCHIVED, self::DELETED],
            self::SUSPENDED => [self::ACTIVE, self::INACTIVE, self::ARCHIVED, self::DELETED],
            self::LOCKED => [self::ACTIVE, self::SUSPENDED, self::ARCHIVED, self::DELETED],
            self::ARCHIVED => [self::ACTIVE, self::DELETED],
            self::DELETED => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /** Deactivating actions a user must never perform on their own account. */
    public function isDeactivating(): bool
    {
        return in_array($this, [self::INACTIVE, self::SUSPENDED, self::LOCKED, self::ARCHIVED, self::DELETED], true);
    }
}

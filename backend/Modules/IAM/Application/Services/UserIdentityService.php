<?php

declare(strict_types=1);

namespace Modules\IAM\Application\Services;

use App\Core\Exceptions\ValidationException;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\IAM\Domain\Enums\UserStatus;

/**
 * Manages the enterprise identity — creation and identity-field edits with
 * de-duplication (ADR-040).
 *
 * TASK-ECOS-IAM-FINAL-REMEDIATION-DIRECT-DEV-001 changes three things here:
 *
 *  §6/§11 INITIAL PASSWORD. `createDraft()` used to hash `Str::random(40)` unconditionally
 *  — an unusable credential — leaving the invitation flow as the only way a new user could
 *  ever get one. That is the root of the §10 dead-end: an administrator who created a user
 *  and then tried to set a password got "Cannot reset password while the account status is
 *  'draft'". An initial password may now be supplied at creation, hashed through the same
 *  single-hash path (`password` is a `hashed` cast, which skips already-hashed values). It
 *  stays OPTIONAL: omit it and the previous unusable-random behaviour is unchanged, so the
 *  invitation flow is untouched.
 *
 *  §7 LOGIN IDENTIFIER UNIQUENESS. `username` is now a login identifier alongside `email`
 *  (SanctumAuthService::attemptCredentials()). That makes CROSS-FIELD collision a real
 *  security question the per-field check could not see: if user A's username equals user
 *  B's email, one submitted identifier matches two accounts. `assertUniqueIdentity()` now
 *  rejects a username that collides with ANY existing email and an email that collides
 *  with ANY existing username, in addition to the per-field uniqueness it already
 *  enforced. Enforced server-side, on create AND update, exactly as §7 requires.
 *
 *  §6 EMPLOYEE LINK. `employee_number` was free text. It is now verified against the
 *  canonical `hr_employees` directory when a value is supplied AND that table holds
 *  employees — so an administrator picks a real employee rather than typing a number that
 *  matches nobody. When the directory is empty (its state on DEV today) the check is
 *  skipped rather than making user creation impossible; the UI still offers only a lookup,
 *  never free text.
 *
 * `company_id` remains deliberately excluded from IDENTITY_FIELDS (D2/D3, CTO-ratified):
 * tenant ownership is server-derived on create and non-writable on update, and a
 * client-supplied value in $data is never read by fill(), by construction.
 */
class UserIdentityService
{
    private const IDENTITY_FIELDS = [
        'name', 'display_name', 'email', 'username', 'employee_number', 'phone', 'avatar_path',
    ];

    public function __construct(
        private readonly UserAuditService $audit,
        private readonly EmployeeDirectory $employees,
    ) {}

    /**
     * @param  array<string,mixed>  $data  `auto_generate_password: true` (User-review
     *                                     remediation, Batch 02, item B) asks this method to
     *                                     mint a secure initial credential itself when no
     *                                     explicit `password` was supplied — the normal
     *                                     Create User path always sets it
     *                                     (UserController::store()). Omitted entirely, this
     *                                     method's default is UNCHANGED from before: the
     *                                     historical unusable `Str::random(40)` placeholder,
     *                                     which every existing direct caller of this method
     *                                     (this class's docblock, and ~20 test fixtures) relies
     *                                     on not suddenly becoming a real, usable credential.
     * @param  string  $companyId  server-derived tenant ownership (D2) — never taken from $data
     * @param  string|null  $generatedPassword  out parameter: the plaintext this call minted,
     *                                          when it did. Never logged, audited, or stored
     *                                          anywhere but the single hashed `password`
     *                                          column — the caller must show it to the actor
     *                                          exactly once and then discard it.
     */
    public function createDraft(
        array $data,
        string $companyId,
        ?int $actorId = null,
        ?string &$generatedPassword = null,
    ): User {
        $this->assertUniqueIdentity($data, null);
        $this->assertEmployeeLink($data);

        $generatedPassword = null;
        $initialPassword = isset($data['password']) && is_string($data['password']) && $data['password'] !== ''
            ? $data['password']
            : null;

        if ($initialPassword === null && ($data['auto_generate_password'] ?? false) === true) {
            $initialPassword = UserPasswordService::generateInitial();
            $generatedPassword = $initialPassword;
        }

        $user = new User();
        $user->fill(array_intersect_key($data, array_flip(self::IDENTITY_FIELDS)));
        $user->company_id = $companyId;

        // An administrator-set or auto-generated initial password, or the historical unusable
        // random one when neither was requested.
        $user->password = Hash::make($initialPassword ?? Str::random(40));
        $user->status = UserStatus::DRAFT->value;
        $user->created_by = $actorId;

        if ($initialPassword !== null) {
            $user->password_changed_at = now();
            // Force a change at first login by default — an administrator (or the generator
            // acting on their behalf) knows this password, so the user must replace it.
            // Overridable, because a service or shared operational account legitimately
            // should not be prompted.
            $user->require_password_change = (bool) ($data['require_password_change'] ?? true);
        }

        $user->save();

        $this->audit->log('created', $user, [], [
            'email' => $user->email,
            'username' => $user->username,
            'status' => $user->status,
            'company_id' => $companyId,
            'employee_number' => $user->employee_number,
            // Never the password or its hash — only whether one was set.
            'initial_password_set' => $initialPassword !== null,
        ]);

        return $user;
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public function updateIdentity(User $user, array $data, ?int $actorId = null): User
    {
        $this->assertUniqueIdentity($data, $user);
        $this->assertEmployeeLink($data);

        $old = $user->only(self::IDENTITY_FIELDS);
        $user->fill(array_intersect_key($data, array_flip(self::IDENTITY_FIELDS)));
        $user->save();

        $this->audit->log('identity_updated', $user, $old, $user->only(self::IDENTITY_FIELDS), ['actor_id' => $actorId]);

        return $user;
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function assertUniqueIdentity(array $data, ?User $ignore): void
    {
        // Dev defect fix (remediation-005): these must throw ValidationException (422,
        // field-keyed), not a bare \InvalidArgumentException. The latter has no render()
        // mapping in bootstrap/app.php, so it fell through to Laravel's default handler and
        // reached the admin as an unhelpful "Server Error" 500 instead of "email already
        // taken" / "employee already linked to another user".
        $messages = [
            'email' => 'A user with this email already exists.',
            'username' => 'A user with this username already exists.',
            'employee_number' => 'This employee is already linked to another user account.',
        ];

        foreach (['email', 'username', 'employee_number'] as $field) {
            if (empty($data[$field])) {
                continue;
            }
            $exists = User::withTrashed()
                ->where($field, $data[$field])
                ->when($ignore !== null, fn ($q) => $q->where('id', '!=', $ignore->getKey()))
                ->exists();
            if ($exists) {
                throw new ValidationException([$field => [$messages[$field]]], $messages[$field]);
            }
        }

        // §7 cross-field collision. Both `email` and `username` resolve an account at
        // login, so a value that is one user's username and another user's email would
        // make the identifier ambiguous. Rejected on both sides.
        foreach ([['username', 'email'], ['email', 'username']] as [$submitted, $conflicting]) {
            if (empty($data[$submitted])) {
                continue;
            }

            $collides = User::withTrashed()
                ->where($conflicting, $data[$submitted])
                ->when($ignore !== null, fn ($q) => $q->where('id', '!=', $ignore->getKey()))
                ->exists();

            if ($collides) {
                $message = "This {$submitted} is already in use as another user's {$conflicting}. ".
                    'Email and username are both login identifiers, so they must not collide.';

                throw new ValidationException([$submitted => [$message]], $message);
            }
        }
    }

    /**
     * §6 — a supplied employee number must name a real employee.
     *
     * Skipped entirely when the canonical directory holds no employees: enforcing it
     * against an empty table would make it impossible to create any user with an employee
     * number, which is a worse outcome than the free-text state this replaces. The UI
     * offers a lookup and no free-text field regardless, so the only way to reach this
     * check with an unmatched value is a hand-crafted request.
     *
     * @param  array<string,mixed>  $data
     */
    private function assertEmployeeLink(array $data): void
    {
        if (empty($data['employee_number']) || ! is_string($data['employee_number'])) {
            return;
        }

        if (! $this->employees->available()) {
            return;
        }

        // Nothing to validate against yet — see the docblock.
        if ($this->employees->search(null, null, false, 1) === []) {
            return;
        }

        if (! $this->employees->numberExists($data['employee_number'])) {
            $message = "No employee exists with the number '{$data['employee_number']}'. ".
                'Select an existing employee record instead of entering a number manually.';

            throw new ValidationException(['employee_number' => [$message]], $message);
        }
    }
}

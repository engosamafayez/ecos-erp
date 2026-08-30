# TASK-IAM-PASSWORD-RESET-CONTRACT-001 — Engineering Report

**Date:** 2026-08-11
**Branch:** `develop` · **HEAD:** `6149875bd8a01820116b5deacbbfb8ef0e51cc05`
**Type:** Domain contract audit — **no code written**
**Decision:** **C — A NEW DOMAIN OPERATION IS REQUIRED** (conditional: two security parameters are UNSPECIFIED and need a business decision)

---

## 1 — Executive Summary

`iam.users.reset-password` exists in the permission catalog and `UserPolicy::resetPassword()` maps to
it correctly, but **no domain capability performs an administrative password reset**.

The only code path in the entire IAM domain that sets a usable password is
`UserInvitationService::activate()`. It cannot be reused: it requires a valid invitation token,
consumes that invitation, **force-transitions the user to ACTIVE**, stamps `email_verified_at`, and
audits the event as `activated`. Reusing it would silently reactivate a suspended or inactive user as
a side effect of an administrator resetting their password.

The only other password write is `ResetDevAdminCommand` — a production-refused console utility that
writes the column raw with no audit and no `password_changed_at`.

So the answer to the question that blocked `TASK-IAM-HTTP-SURFACE-001` is settled: **the invitation
flow must not be reused, and a new domain operation is required.** Its supporting parts already exist
and should be composed into it (`UserAuditService`, `UserSessionService`, `UserPolicy`).

Two parameters of that operation are **UNSPECIFIED** by the existing architecture and are recorded as
such rather than decided silently — see §7 and §9. One of them has a concrete security consequence
that should be read before implementation is authorised.

---

## 2 — Starting Commit

| Item | Value |
|---|---|
| HEAD | `6149875bd8a01820116b5deacbbfb8ef0e51cc05` |
| Branch | `develop` |
| Tracked diff | `9 files changed, 326 insertions(+), 31 deletions(-)` — unchanged by this task |
| IAM source files modified | **none**, by me or any other agent |
| Untracked IAM items | prior IAM *report documents* only (`TASK-IAM-HTTP-SURFACE-001`, `TASK-IAM-PRECONDITION-*`, `TASK-GOLIVE-IAM-ADMIN-*`) |
| Active agents owning IAM files | none |

No STOP condition triggered on ownership. Nothing was reverted or reset.

---

## 3 — IAM Domain Inventory

`Modules/IAM` application services (28):

| Area | Services |
|---|---|
| Authorization | `PermissionService`, `PermissionRegistry`, `PermissionExpander`, `AuthorizationGateway`, `AuthorizationContextBuilder`, `PolicyResolver`, `ScopeResolver`, `VisibilityResolver` |
| Roles / templates | `RoleCompositionService`, `RoleComparisonService`, `RoleConflictResolver`, `RolePreviewService`, `RoleTemplateCompiler`, `RoleTemplate{Export,Import,Repository,Version}Service`, `UserRoleAssignmentService` |
| User platform | `UserIdentityService`, `UserLifecycleService`, `UserInvitationService`, `UserProfileService`, `UserOrganizationAssignmentService`, `UserSessionService`, `UserSecurityRules`, `UserAuditService`, `UserRepository` |
| Other | `SensitiveFieldRegistry` |

Actions: `LoginAction`, `LogoutAction`. Commands: `ResetDevAdminCommand`.
Infrastructure: `SanctumAuthService`. Presentation: `AuthController`, `LoginRequest`, `UserPolicy`,
`BasePolicy`.

Behavioural search (not filename-based) across the whole tree for `password`, `setPassword`,
`changePassword`, `updatePassword`, `Hash`, `bcrypt`, `password_hash`, `credentials`, `activate`,
`invitation` returned 19 files. After eliminating migrations, DTOs, exceptions, login/auth paths and
the invitation model, **exactly three** touch password state:

1. `UserInvitationService::activate()`
2. `UserIdentityService::createDraft()`
3. `ResetDevAdminCommand::handle()`

---

## 4 — Existing Password Capabilities

| Capability | Sets password? | Usable for admin reset? | Why |
|---|---|---|---|
| `UserInvitationService::activate()` | **Yes** | **No** | Invitation-token gated; consumes invitation; force-activates; see §5 |
| `UserIdentityService::createDraft()` | Yes — `Hash::make(Str::random(40))` | **No** | Deliberately *unusable* placeholder at creation. Comment: *"unusable until set via invitation"* |
| `UserIdentityService::updateIdentity()` | **No** | **No** | `IDENTITY_FIELDS` excludes `password`; `array_intersect_key` strips it |
| `ResetDevAdminCommand` | Yes | **No** | Console-only, hardcoded email + password, `app()->isProduction()` refusal, **no audit**, **no `password_changed_at`** |
| `UserLifecycleService` | No | No | Pure state machine |
| `UserProfileService` | No | No | No password handling at all |
| `SanctumAuthService` / `LoginAction` | No | No | Verification only, not mutation |

**There is no self-service "change my password" capability either.** The domain has exactly one
password-setting path, and it is the invitation flow.

---

## 5 — UserInvitationService Analysis (Part 4)

**Verdict: A — it is specifically an invitation activation flow.** Not a generic credential setter.

`activate(string $rawToken, string $newPassword, bool $requireChangeOnFirstLogin = false)`:

| Line | Behaviour | Invitation-specific? |
|---|---|---|
| 78–81 | `findValidInvitation($rawToken)`; throws `InvalidArgumentException` if absent/expired | **Yes — hard token requirement** |
| 85 | `$user->password = Hash::make($newPassword)` | No — the only reusable line |
| 86 | `password_changed_at = now()` | No |
| 87 | `require_password_change = $requireChangeOnFirstLogin` | No |
| 88 | `email_verified_at = $user->email_verified_at ?? now()` | **Yes — onboarding side effect** |
| 91–93 | invitation → `STATUS_ACCEPTED`, `accepted_at = now()` | **Yes — consumes the invitation** |
| 95 | `lifecycle->transition($user, UserStatus::ACTIVE)` | **Yes — activates the account** |
| 96 | `audit->log('activated', ...)` | **Yes — wrong event semantics for a reset** |

Answers to the required checks:

- requires invitation token — **YES**
- changes account state — **YES** (→ `ACTIVE`)
- marks invitation consumed — **YES**
- sets `activated_at` — **YES**, indirectly via `UserLifecycleService::stampTimestamps()`
- assigns roles — no
- sends notifications — no (the caller sends the raw token)
- emits events — audit `user.activated`; no domain event
- other onboarding operations — **YES** (`email_verified_at`)

Per the task's own rule — *"If it performs invitation-specific side effects: it MUST NOT be reused as
Admin Password Reset"* — **reuse is excluded.**

**Concrete harm if it were reused:** an administrator resetting the password of a `SUSPENDED`,
`INACTIVE` or `LOCKED` user would silently transition that account to `ACTIVE`, restoring
authentication to a deliberately disabled account. `UserStatus::canAuthenticate()` returns true only
for `ACTIVE`, so this is a real privilege restoration, not a cosmetic status change.

---

## 6 — Admin Password Reset Semantics (Part 5)

Required semantics vs what the domain supports today:

| Required | Supported today? |
|---|---|
| Set a new password for an existing user | Only inside the invitation flow |
| Persist hashed password | Yes — `Hash::make()` is the established convention |
| Do **not** activate/deactivate | **Violated** by `activate()` (line 95) |
| Do **not** assign roles | Satisfied — no password path touches roles |
| Do **not** modify company ownership | Satisfied |
| Do **not** modify permissions | Satisfied |
| Do **not** require invitation semantics | **Violated** by `activate()` (lines 78–81, 91–93) |

**Conclusion: the existing domain does not support these semantics.** Two of the seven are actively
violated by the only available capability.

---

## 7 — Security Semantics (Part 6)

Determined strictly from existing architecture. Nothing invented.

| Question | Answer | Source |
|---|---|---|
| Require current password? | **NO** — architecturally inapplicable | This is an administrative operation on *another* user; no existing admin operation requires the target's current credential |
| Require invitation token? | **NO** | Invitation tokens belong to `UserInvitation`, gated on `STATUS_PENDING`; an established user has no pending invitation |
| Require reset token? | **NO** | No reset-token concept exists anywhere in the domain — no model, table, or service |
| Require authenticated admin authorization? | **YES** | `iam.users.reset-password` exists in `config/permissions.php` and `UserPolicy::resetPassword()` |
| Invalidate sessions/tokens? | **UNSPECIFIED** | See below |
| Emit audit event? | **YES** | `UserAuditService` is used by every comparable user operation (`created`, `invited`, `activated`, `status_changed`, `identity_updated`, `force_logout`, `logout_others`). A reset must audit for consistency. The action name is not yet defined |
| Emit password-changed event? | **UNSPECIFIED** | No domain event class exists for any user operation; the domain audits rather than emits. No `PasswordChanged` event exists |

### UNSPECIFIED — session/token invalidation

The architecture provides `UserSessionService::forceLogout()` (revokes all `UserSession` rows and all
Sanctum tokens, audits `force_logout`), **but gates it behind a separate permission**,
`iam.users.manage-sessions`, distinct from `iam.users.reset-password`.

That separation is an architectural signal that session revocation is its own operation — but the
architecture **nowhere states** whether an administrative password reset should imply it. Both
readings are defensible:

- *Do not revoke* — respects the permission separation; a holder of `reset-password` alone has not
  been granted session management.
- *Do revoke* — a password reset whose old sessions survive does not actually lock an attacker out,
  which is the usual reason to reset a password.

**Recorded as UNSPECIFIED. Not decided here.** This maps to STOP condition 3 and must be settled
before implementation.

---

## 8 — User Lifecycle Semantics (Part 7)

`UserStatus` has nine states: `DRAFT`, `INVITED`, `PENDING_ACTIVATION`, `ACTIVE`, `INACTIVE`,
`SUSPENDED`, `LOCKED`, `ARCHIVED`, `DELETED`. `canAuthenticate()` is true **only** for `ACTIVE`.

| State | Reset permitted? | Existing domain rule |
|---|---|---|
| Active | — | **none** |
| Inactive | — | **none** |
| Suspended | — | **none** |
| Invited, not activated | — | **none** (invitation flow covers credential setup, but says nothing about admin reset) |
| Locked | — | **none** (`unlock()` clears `failed_login_count`; unrelated to password) |
| Archived | — | **none** |
| Soft-deleted (`DELETED`) | — | **none**; soft delete is supported (`$user->delete()`, `withTrashed()` used in `UserRepository` and `assertUniqueIdentity`) |

**No domain rule anywhere ties password mutation to lifecycle state.** `activate()` sidesteps the
question by forcing `ACTIVE` regardless of prior state.

**This is ambiguous by the task's definition and is reported rather than resolved.** The specific
open question: should resetting the password of an `ARCHIVED` or soft-deleted user be refused? The
domain currently expresses no opinion, and inventing one would be a new lifecycle rule — explicitly
out of scope.

---

## 9 — Tenant / Company Isolation (Part 8)

**Finding: there is currently no enforced company boundary on IAM user management.** This is proven,
not merely unproven — three independent layers were checked and none enforces one:

| Layer | Evidence | Enforces tenant boundary? |
|---|---|---|
| `UserPolicy::resetPassword(User $user, User $target)` | Body is `return $this->can($user, 'iam.users.reset-password');` — **`$target` is accepted and never read** | **No** |
| `PermissionService::userHasPermission()` | `in_array($permission, $this->getUserPermissions($user), true)` — flat, company-agnostic | **No** |
| `PermissionService::userHasPermissionInScope()` | Lines 49–51: *"Scope resolution not yet implemented. Delegates to flat permission check until scoped authorization lands."* — accepts `$companyId` and ignores it | **No — explicitly unimplemented** |
| `App\Models\User` | `company_id` appears **only** in `$fillable`; no `addGlobalScope`, no company trait | **No** |
| `UserRepository::query()` | `if (! empty($filters['company_id']))` — an **optional filter**, not a scope | **No** |

`ScopeResolver` and `VisibilityResolver` exist as services but are not wired into this path.

**Security consequence, stated plainly.** Combined with §10, any user holding
`iam.users.reset-password` — regardless of company — could reset the password of **any** user in the
system, including a Super Administrator, and then authenticate as them. `UserSecurityRules` protects
against self-deactivation, self-removal of super-admin, and removing the *last* super-admin, but has
**no** invariant guarding password mutation.

This is a **pre-existing platform-wide condition**, not something introduced by the proposed
operation, and it affects every future `iam.users.*` endpoint equally — not just reset-password.

**The boundary that *should* apply is UNSPECIFIED.** Deciding it is a security decision, not an
engineering inference, so it is recorded rather than invented. This maps to STOP condition 4.

---

## 10 — Permission Contract

`iam.users.reset-password` is registered in **both** catalog locations in `config/permissions.php`:

```
line  24: 'users'      => [..., 'invite', 'reset-password', 'manage-sessions']
line 176: 'iam.users'  => [..., 'invite', 'reset-password', 'manage-sessions']
```

`reset-password` and `manage-sessions` are **separate, independently grantable** permissions — the
basis for the UNSPECIFIED finding in §7.

---

## 11 — UserPolicy Contract (Part 9)

Verified correct and non-duplicating:

```
UserPolicy::resetPassword($user, $target)
  └─ BasePolicy::can($user, 'iam.users.reset-password')
       └─ PermissionServiceInterface::userHasPermission($user, $permission)
            └─ PermissionService::userHasPermission()   [in_array over cached permission set]
```

- The ability maps to exactly the catalog name `iam.users.reset-password`. ✔
- `BasePolicy::can()` delegates to the same `PermissionService` path used by every other policy. ✔
- No authorization logic is duplicated. ✔
- Super-admin bypass is global via `Gate::before()` and is deliberately **not** re-checked per policy. ✔
- **No contradiction** between the policy and `PermissionService` — STOP condition 5 not triggered.

The single gap is that `$target` is unused (§9) — a tenant-scope gap, not a policy/permission
contradiction.

---

## 12 — Candidate Reuse Analysis

| Candidate | Verdict | Reason |
|---|---|---|
| `UserInvitationService::activate()` | **Rejected** | Invitation token required; consumes invitation; force-activates; sets `email_verified_at`; audits as `activated` |
| `UserInvitationService::invite()` + `activate()` | **Rejected** | Worse — issues a *new* invitation, revokes outstanding ones, sets `invited_at`/`invited_by`, may transition `DRAFT → INVITED`, and requires delivering a token out-of-band. Turns a reset into re-onboarding |
| `UserIdentityService::updateIdentity()` | **Rejected** | Structurally cannot set a password — `IDENTITY_FIELDS` excludes it |
| `ResetDevAdminCommand` | **Rejected** | Console-only; production-refused; hardcoded credentials; no audit; no `password_changed_at`; not a domain service |
| `UserLifecycleService` | **N/A** | No password behaviour |

**Why B (safe composition) is also rejected:** composition presupposes an existing standalone
credential-mutation capability to compose *with*. There is none — the mutation exists only as three
lines embedded inside `activate()` and as a raw column write in a dev console command. The mutation
itself must be authored, which makes this C, not B.

The *supporting* capabilities are genuinely reusable and should be composed **into** the new
operation: `UserAuditService` (audit), `UserSessionService::forceLogout()` (if §7 is decided that
way), `UserPolicy`/`PermissionService` (authorization), `Hash::make()` (the established hashing
convention).

---

## 13 — Decision

> ### C — A NEW DOMAIN OPERATION IS REQUIRED
>
> Proven from the implementation, not chosen for convenience:
>
> - **A is excluded** — the only password-setting path, `UserInvitationService::activate()`, carries
>   four invitation-specific side effects, two of which directly violate the required semantics
>   (it activates the user; it demands an invitation token).
> - **B is excluded** — there is no standalone credential-mutation capability in the domain to
>   compose with. The mutation must be written.
> - **C holds.**
>
> **C is conditional.** The operation's *existence* is settled; two of its *parameters* are
> UNSPECIFIED and require a business/security decision before implementation:
>
> 1. **Session/token invalidation** (§7) — should a reset revoke existing sessions and Sanctum
>    tokens, given `manage-sessions` is a separate permission?
> 2. **Tenant boundary** (§9) — the platform currently enforces none for user management. What scope
>    should constrain an administrator, and should a non-super-admin be able to reset a
>    Super Administrator's password?

---

## 14 — Domain Contract (Part 13)

Specification only. **Not implemented.**

**Operation:** `AdminPasswordResetService::reset()` — proposed placement
`Modules/IAM/Application/Services/`, consistent with the existing user-platform service layout.

| Element | Contract |
|---|---|
| **Input** | `User $target`, `string $newPassword`, `?User $actor`, `bool $requirePasswordChange = true` |
| **Output** | `User` (refreshed) |
| **Authorization boundary** | Caller must satisfy `UserPolicy::resetPassword($actor, $target)` → `iam.users.reset-password`. Enforced at the HTTP boundary, not inside the service — matching how `UserLifecycleService` and `UserInvitationService` behave today |
| **Transaction boundary** | Single `DB::transaction` covering the password write and (if adopted) session revocation, so a reset cannot half-apply. Note: existing IAM services do **not** wrap in transactions — this would be a deliberate, documented strengthening, and should be confirmed rather than assumed |
| **Persistence** | `password = Hash::make($newPassword)`; `password_changed_at = now()`; `require_password_change = $requirePasswordChange`. Columns already exist and are already used by `activate()` |
| **Audit** | `UserAuditService::log('password_reset', $target, [], [], ['actor_id' => ...])` — new action name `user.password_reset`. **The password value must never be logged**, matching the existing convention that only the token hash is persisted in the invitation flow |
| **Events** | None. The domain audits rather than emitting domain events for user operations; introducing one would break that convention |

**Invariants (must hold):**

1. Lifecycle status is **never** modified — no call into `UserLifecycleService`.
2. `email_verified_at` is never modified.
3. No `UserInvitation` row is created, consumed, or revoked.
4. Role, permission, organization and company assignments are untouched.
5. The raw password never reaches audit, logs, or the response.

**Lifecycle restrictions:** UNSPECIFIED (§8) — must be decided before implementation.
**Session invalidation:** UNSPECIFIED (§7) — must be decided before implementation.
**Tenant boundary:** UNSPECIFIED (§9) — must be decided before implementation.

---

## 15 — HTTP Surface Dependency (Part 14)

**The IAM user-management HTTP surface does not exist yet.** `Modules/IAM/Presentation/Http/` contains
only `AuthController` and `LoginRequest`, and `routes/api.php` registers **zero** `iam/users` routes.
`TASK-IAM-HTTP-SURFACE-001` is therefore fully greenfield, not an extension.

Conceptual path the future endpoint would depend on (**none of this created here**):

```
POST /api/iam/users/{user}/reset-password
  → FormRequest              (password validation rules — none exist yet for this)
  → UserPolicy::resetPassword / PermissionService     [EXISTS ✔]
  → AdminPasswordResetService::reset()                [DOES NOT EXIST — §14]
  → User persistence + UserAuditService               [EXISTS ✔]
  → UserSessionService::forceLogout()                 [EXISTS ✔ — inclusion UNSPECIFIED, §7]
  → response / resource                               [DOES NOT EXIST]
```

Blocking dependency: the domain operation in §14. The HTTP task cannot proceed past the policy layer
without it.

---

## 16 — Runtime Evidence

**None required, and none performed.** Every question was resolved from the implementation by direct
source inspection. No database was accessed, no test was executed, and no `RefreshDatabase` ran — so
Part 15's `SELECT DATABASE()` precondition was never reached and no destructive operation occurred.

---

## 17 — Files Changed

**Production code changed: none.** No controller, route, request, service, policy, permission,
migration, model or lifecycle change.

**Added:** this report only.

Tracked diff is unchanged by this task: `9 files changed, 326 insertions(+), 31 deletions(-)` —
identical before and after, and entirely attributable to the previously authorised
`REPAIR-002` and F4 / Option B / entry-gate work.

---

## 18 — Final Recommendation

The ambiguity that blocked `TASK-IAM-HTTP-SURFACE-001` is removed: **do not reuse the invitation
flow; author a new, narrowly-scoped domain operation** per §14.

Before implementation is authorised, three decisions are needed. These are business/security calls,
deliberately not made here:

1. **Session invalidation** — should an admin reset revoke the target's sessions and Sanctum tokens?
   *Engineering note, not a decision: `UserSessionService::forceLogout()` already does exactly this,
   so adopting it costs nothing structurally; the objection is that it is gated behind a separate
   permission.*
2. **Tenant boundary** — user management currently has **no** company scoping at all (§9). This is
   platform-wide and affects every future `iam.users.*` endpoint, so it is arguably its own task
   rather than a rider on this one.
3. **Lifecycle restrictions** — may an archived or soft-deleted user's password be reset?

Recommended sequencing: settle (1) and (3) as parameters of this operation; treat (2) as a separate
authorized task, since retrofitting tenant scoping touches `PermissionService`, `UserPolicy` and
`UserRepository` — none of which this task may modify.

**Per the final rule, work stops here.** The password-reset operation was not implemented, no HTTP
endpoint was built, and Shipping, Logistics, Preparation, Reservation, F4 and Option B were not
touched.

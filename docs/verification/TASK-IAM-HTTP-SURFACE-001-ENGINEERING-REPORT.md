# TASK-IAM-HTTP-SURFACE-001 — Engineering Report

**Date:** 2026-08-10
**Status:** **STOPPED before implementation.** Part 1 survey completed; no code was written.
**Verdict:** see Section 31.

---

## 1 — Executive Summary

The pre-implementation survey (Part 1) was completed in full. The IAM domain is present, coherent, and
sufficient to back **ten of the eleven** required HTTP operations. No new RBAC engine, model or service would
be required for those ten.

Implementation was **not started**, because two of the task's own declared STOP conditions are active:

| # | STOP condition (Part 34) | Status |
| --- | --- | --- |
| **12** | *Runtime security tests cannot execute* | **ACTIVE** — the test harness currently targets a database that does not exist on the server it points at (§7.2). A host-run `phpunit` cannot connect at all. |
| **13** | *The test environment is not isolated* | **ACTIVE** — a second agent is concurrently editing `backend/phpunit.xml` and `backend/tests/TestCase.php` in this worktree, mid-migration between two Docker stacks (§7.1). |
| **1** | *Existing domain service cannot safely support an operation* | **ACTIVE (scoped to Part 10)** — `iam.users.reset-password` and `UserPolicy::resetPassword()` exist, but **no IAM domain service implements password reset** (§18). |

Per Part 34 — *"Do not workaround a STOP condition. Report it."* — no code was written and no workaround was
attempted. Parts 5–21 remain unimplemented; Parts 22–28 were not run.

This task is **security-sensitive** and Part 32 states plainly that static routing is not certification.
Building the surface now would produce controllers that could not be security-certified, which is precisely
the outcome the task sequence is designed to prevent.

---

## 2 — Starting Commit

```
HEAD    6149875bd8a01820116b5deacbbfb8ef0e51cc05
branch  develop
repo    C:\ecos-develop
```

Worktree was already dirty on arrival and **grew during this task without my involvement** (§7.1).
Nothing in this task modified, committed, reset, checked out or stashed anything.

---

## 3 — Pre-Implementation Survey (Part 1) — COMPLETE

All fifteen survey points were inspected. Findings in §4–§8.

---

## 4 — Existing IAM Services

`backend/Modules/IAM/Application/Services/` — 28 services. Every service named in the brief exists.
Actual signatures, read from source (Part 1 requires these before implementation):

**`UserIdentityService`**
```php
createDraft(array $data, ?int $actorId = null): User
updateIdentity(User $user, array $data, ?int $actorId = null): User
```

**`UserLifecycleService`**
```php
transition(User $user, UserStatus $to, ?User $actor = null, ?string $reason = null): User
activate(User $user, ?User $actor = null): User
deactivate(User $user, ?User $actor = null, ?string $reason = null): User
suspend(User $user, ?User $actor = null, ?string $reason = null): User
lock(User $user, ?User $actor = null, ?string $reason = null): User
unlock(User $user, ?User $actor = null): User
archive(User $user, ?User $actor = null, ?string $reason = null): User
restore(User $user, ?User $actor = null): User
softDelete(User $user, ?User $actor = null, ?string $reason = null): User
```

**`UserRoleAssignmentService`**
```php
assignTemplate(User $user, RoleTemplate|string $template, bool $primary = false, ?int $actorId = null): UserTemplateAssignment
removeTemplate(User $user, RoleTemplate|string $template): void
setPrimary(User $user, RoleTemplate|string $template): void
effectiveProfile(User $user): EffectiveRoleProfile
```
> Note: roles are assigned **via RoleTemplate**, consistent with the established rule that templates author
> and roles execute. A `/users/{user}/assign-role` endpoint must therefore accept a **template** identifier,
> not a raw role id. This is a real contract detail that would have been easy to get wrong.

**`UserOrganizationAssignmentService`**
```php
assign(User $user, string $orgType, ?string $orgId, ?string $label = null, bool $primary = false, ?int $actorId = null): UserOrganizationAssignment
unassign(User $user, string $orgType, ?string $orgId): void
```

**`UserInvitationService`**
```php
invite(User $user, ?int $actorId = null, int $ttlHours = 72): string   // returns raw token
findValidInvitation(string $rawToken): ?UserInvitation
activate(string $rawToken, string $newPassword, bool $requireChangeOnFirstLogin = false): User
```

**`UserSessionService`**
```php
record(User $user, ?int $tokenId, ?string $ip, ?string $userAgent): UserSession
activeSessions(User $user): Collection
revoke(UserSession $session): void
forceLogout(User $user): int
logoutOthers(User $user, ?int $keepTokenId): int
```

**Supporting:** `UserSecurityRules` (`assertNotSelfDeactivation`, `assertNotRemovingOwnSuperAdmin`,
`assertNotLastSuperAdmin`, `isSuperAdmin`), `UserAuditService`, `UserRepository`, `UserProfileService`,
`RoleTemplateCompiler`, `AuthorizationGateway`, `PermissionService`, `ScopeResolver`, `VisibilityResolver`.

**Existing HTTP layer:** only `Presentation/Http/Controllers/AuthController.php` and
`Presentation/Http/Requests/LoginRequest.php`. **The administration surface genuinely does not exist** — this
task is not duplicating existing work.

---

## 5 — Existing Permission Catalog

`backend/config/permissions.php:176` — verbatim, all eleven present and to be reused unchanged:

```php
'iam.users' => ['view', 'create', 'update', 'delete', 'activate', 'suspend',
                'assign-role', 'assign-org', 'invite', 'reset-password', 'manage-sessions'],
```

Two further registrations grant `iam.users => ['view']` only (lines 272, 472). **No new permission is
required for the ten implementable operations.** No permission was added, renamed or duplicated.

---

## 6 — Authorization Architecture

`Modules/IAM/Presentation/Policies/UserPolicy.php` maps **1:1** onto the catalog — the cleanest possible
foundation for this surface:

| Policy method | Permission |
| --- | --- |
| `viewAny(User)` / `view(User, User $target)` | `iam.users.view` |
| `create(User)` | `iam.users.create` |
| `update(User, $target)` | `iam.users.update` |
| `delete(User, $target)` | `iam.users.delete` |
| `activate(User, $target)` | `iam.users.activate` |
| `suspend(User, $target)` | `iam.users.suspend` |
| `assignRole(User, $target)` | `iam.users.assign-role` |
| `assignOrganization(User, $target)` | `iam.users.assign-org` |
| `invite(User, $target)` | `iam.users.invite` |
| `resetPassword(User, $target)` | `iam.users.reset-password` |
| `manageSessions(User, $target)` | `iam.users.manage-sessions` |

`BasePolicy::can(User $user, string $permission)` delegates to `PermissionService`, so **Policy and
PermissionService cannot disagree by construction** — they are one path, not two. Part 19's contradiction
condition is therefore **not triggered**.

System privilege uses the RC-6 path `PermissionService::userHasSystemRole(User): bool`. `ScopeResolver`
exposes `resolve(User $user, string $resource, ?string $ownerColumn = null): ScopeConstraint`.

---

## 7 — Tenant Scope Contract — BLOCKED BY ENVIRONMENT, NOT BY DESIGN

The mechanism exists (`ScopeResolver` + `VisibilityResolver` + `userHasSystemRole`) and appears sufficient to
satisfy Parts 4, 15 and 16 without inventing any rule. **The blocker is not the design — it is that the
resulting rules could not be proven at runtime**, and Part 4 forbids implementing an assumed security rule
while Part 32 forbids certifying one statically.

### 7.1 — STOP #13: the test environment is not isolated

A second agent is actively working in this same worktree. Observed during this task:

* `backend/phpunit.xml` — **modified** (was clean at 01:57).
* `backend/tests/TestCase.php` — **modified** (was clean at 01:57). This file owns
  `grantsBaselineAuthorization` and `grantSystemRole()`, which are **directly load-bearing for every IAM
  authorization test** this task must write.
* New untracked files appearing continuously: `DevTestEnvironmentRefreshTest.php`,
  `DevTestEnvironmentSmokeTest.php`, `PreparationBypassGuardTest.php`, `PreparationLifecycleE2ETest.php`,
  and four new verification reports including `TASK-ENV-DUAL-STACK-DEV-ISOLATION-001` and
  `TASK-TEST-ENV-DEV-PHPUNIT-ENABLE-001`.
* Container `ecos-dev-testrunner` running (~1 h).

Writing security tests whose authorization baseline is being rewritten underneath them cannot produce
trustworthy certification.

### 7.2 — STOP #12: runtime tests cannot execute at all right now

The harness is mid-migration between two Docker stacks and is currently **internally inconsistent**:

| Setting | Value | Source |
| --- | --- | --- |
| `DB_DATABASE` | `ecos_dev_test` (forced) | `phpunit.xml` (changed this session) |
| `DB_HOST` / `DB_PORT` | `127.0.0.1` / `3306` | `phpunit.xml` defaults + `.env` |
| `127.0.0.1:3306` resolves to | `ecos-mysql` | `docker ps` |
| Databases on `ecos-mysql` | `ecos_erp`, `ecos_erp_test` — **no `ecos_dev_test`** | verified |
| `ecos_dev_test` actually lives on | `ecos-dev-mysql`, published on **127.0.0.1:3316** | verified |
| `.env` `DB_DATABASE` | still `ecos_erp_test` | contradicts `phpunit.xml` |

**A host-run `phpunit` therefore asks `ecos-mysql` for a database that only exists on `ecos-dev-mysql`.**
Verified directly:

```
$ docker exec ecos-mysql mysql -N -B -e "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA
                                         WHERE SCHEMA_NAME IN ('ecos_dev_test','ecos_erp_test');"
ecos_erp_test
```

The intended path is presumably the `ecos-dev-testrunner` container (where `DB_HOST=mysql` resolves inside
the dev network), but that is the other agent's in-flight design and is not yet reflected in `.env`.

Parts 22–25 require **twenty** authorization tests, five tenant-isolation cases, five privilege-escalation
cases and the D-9 null-scope test. **None of them can run until §7.2 is resolved.**

---

## 8 — Planned HTTP Surface (designed, NOT implemented)

Recorded so implementation can begin immediately once the environment is fixed. Prefix follows the existing
convention in `routes/api.php` (`Route::middleware(['auth:sanctum', 'permission:<perm>'])`).

| Method | Route | Domain service call | Permission | Policy |
| --- | --- | --- | --- | --- |
| GET | `/users` | `UserRepository` + `ScopeResolver::resolve()` | `iam.users.view` | `viewAny` |
| POST | `/users` | `UserIdentityService::createDraft()` | `iam.users.create` | `create` |
| GET | `/users/{user}` | scoped lookup | `iam.users.view` | `view` |
| PUT | `/users/{user}` | `UserIdentityService::updateIdentity()` | `iam.users.update` | `update` |
| DELETE | `/users/{user}` | `UserLifecycleService::softDelete()` | `iam.users.delete` | `delete` |
| POST | `/users/{user}/activate` | `UserLifecycleService::activate()` | `iam.users.activate` | `activate` |
| POST | `/users/{user}/suspend` | `UserLifecycleService::suspend()` | `iam.users.suspend` | `suspend` |
| POST | `/users/{user}/assign-role` | `UserRoleAssignmentService::assignTemplate()` | `iam.users.assign-role` | `assignRole` |
| POST | `/users/{user}/assign-org` | `UserOrganizationAssignmentService::assign()` | `iam.users.assign-org` | `assignOrganization` |
| POST | `/users/{user}/invite` | `UserInvitationService::invite()` | `iam.users.invite` | `invite` |
| POST | `/users/{user}/reset-password` | **NO SERVICE EXISTS** — see §18 | `iam.users.reset-password` | `resetPassword` |
| GET | `/users/{user}/sessions` | `UserSessionService::activeSessions()` | `iam.users.manage-sessions` | `manageSessions` |
| GET | `/roles`, `/roles/{role}` | read-only via existing role models | `iam.users.view` (confirm) | — |

---

## 9–17 — Controllers, FormRequests, Resources, Routes, Middleware, Lifecycle, Role Assignment, Organization Assignment, Invitations

**NOT IMPLEMENTED.** No file was created or modified. See §1.

---

## 18 — Password Reset — **GAP (Part 10 STOP)**

Part 10: *"Do not invent a password-reset implementation. If the existing service does not provide this
operation: STOP and report the exact gap."*

**The exact gap:**

* `iam.users.reset-password` **exists** in the catalog (`config/permissions.php:176`).
* `UserPolicy::resetPassword(User $user, User $target): bool` **exists**
  (`Modules/IAM/Presentation/Policies/UserPolicy.php:65`).
* **No IAM domain service implements password reset.** An exhaustive case-insensitive search of
  `Modules/IAM/` for `resetPassword|reset_password|passwordReset` returns **exactly one hit — the policy
  method above**. There is no service-layer counterpart.

The nearest existing capability is credential establishment through invitation:
`UserInvitationService::invite()` issues a token and `::activate($rawToken, $newPassword)` sets the password.
Whether an administrative reset should reuse that flow (re-invite), or requires a new domain operation, is a
**business decision not encoded in the domain** — so Parts 10 and 13 both require a STOP rather than a guess.

The other ten operations are unaffected by this gap.

---

## 19 — Sessions

`UserSessionService` supports `activeSessions()` (read), plus `revoke()`, `forceLogout()`,
`logoutOthers()`. Part 11 permits exposing only what the domain already supports; `GET /users/{user}/sessions`
maps cleanly to `activeSessions()`. Revocation endpoints are available if wanted but were not in the minimum
surface and were not designed here.

---

## 20–26 — Security Tests, Tenant Isolation, Privilege Escalation, Null Scope, Route Verification, Domain Service Verification, IAM Regression

**NOT EXECUTED.** Blocked by §7.2 — the suite cannot connect to a database. Nothing is claimed.

Per Part 32, no partial credit is taken: an untested authorization surface is not certifiable.

---

## 27 — PHPStan

**NOT RUN for this task.** (For reference, a run earlier in this session against the same HEAD returned
`[OK] No errors` for `phpstan.neon.dist`; that predates the harness changes in §7.1 and is not claimed as
evidence for this task.)

---

## 28 — Guardian

**NOT RUN for this task.** No code was produced to gate.

---

## 29 — Failure Classification

No test failures to classify — no tests were executed. The three active conditions classify as:

| Item | Classification |
| --- | --- |
| Harness targets a non-existent database (§7.2) | **ENVIRONMENT** (introduced this session by the concurrent dual-stack migration) |
| Concurrent mutation of `phpunit.xml` / `TestCase.php` (§7.1) | **ENVIRONMENT** |
| Password-reset service gap (§18) | **PRE-EXISTING** (domain gap at HEAD `6149875b`) |

---

## 30 — Remaining Gaps

1. **Environment (blocking).** Resolve the dual-stack migration: make `.env`, `phpunit.xml` and the chosen
   runner agree on one server and one database. Either point the host at `127.0.0.1:3316` or run through
   `ecos-dev-testrunner`. Owner: `TASK-ENV-DUAL-STACK-DEV-ISOLATION-001`.
2. **Concurrency (blocking).** One agent at a time in this worktree. Two sessions editing the same harness and
   sharing one test database already produced hours of false signal in the preceding Preparation task.
3. **Password reset (scoped).** Decide whether administrative reset reuses the invitation flow or becomes a
   new domain operation. Requires an owner decision; not an engineering guess.
4. **`/roles` read API.** Confirm which permission gates role reads — `iam.users.view` is the natural
   candidate but was not confirmed against the role catalog.

---

## 31 — Certification Verdict

# IAM HTTP SURFACE = NOT CERTIFIED

Not a failure of the surface — **the surface was not built.** Implementation was stopped before the first
line of code, under Part 34 STOP conditions **12**, **13** and (for Part 10 only) **1**.

The domain is ready. Ten of eleven operations map cleanly onto existing services, permissions and policy
methods with no redesign. Once §7.1 and §7.2 are resolved and the §18 decision is made, this task can proceed
directly from the mapping in §8.

### Attestations

* No production code was created or modified.
* No frontend work was started (Parts 30, 33 respected).
* No new permission, role, tenant or RBAC engine was created.
* Nothing was committed; no `--no-verify`; no history rewrite.
* No STOP condition was worked around.

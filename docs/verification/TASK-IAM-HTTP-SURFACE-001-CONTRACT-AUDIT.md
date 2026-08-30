# TASK-IAM-HTTP-SURFACE-001 — Contract & Architecture Audit

**Date:** 2026-08-12
**Branch:** `develop` · **HEAD:** `6149875bd8a01820116b5deacbbfb8ef0e51cc05`
**Status:** **AUDIT COMPLETE — IMPLEMENTATION BLOCKED.** 9 STOP conditions confirmed.
**Scope:** read-only. **Zero files created, edited or deleted.** No tests, no migrations, no DB writes.

---

## 1 — Executive Summary

The IAM domain layer is **rich and largely complete**: 30 Application services, a 9-state lifecycle
machine, a certified tenant boundary, and a role-template engine. The IAM **HTTP** layer is almost
entirely absent: `AuthController` (3 actions) and `LoginRequest` are the whole surface. User
Management HTTP is confirmed **greenfield** — zero `users` routes, no `UserController` anywhere in the
repo, no `iam` route prefix, no `Modules/IAM/.../Resources/` directory.

So the task premise is correct. **But it cannot proceed as scoped.** The audit found that exposing the
existing domain operations over HTTP is *not* a pure transport exercise — the domain was written on
the assumption that its callers are trusted, and several contracts that HTTP requires simply do not
exist yet.

**Nine STOP conditions, five of them security-critical:**

| # | STOP | Severity |
|---|---|---|
| 1 | `company_id` is client-suppliable on **create** — cross-tenant user creation | **CRITICAL** |
| 2 | `company_id` is client-suppliable on **update** — cross-tenant re-homing | **CRITICAL** |
| 3 | No tenant scope on the **list** path — full cross-company user directory | **CRITICAL** |
| 4 | 6 required permissions do not exist (`archive`, `restore`, `deactivate`, `lock`, `unlock`, `revoke-role`) | **BLOCKING** |
| 5 | `UserLifecycleService::restore()` is **broken** — always throws for a trashed user, leaving unrecoverable state | **BLOCKING** |
| 6 | No HTTP renderer for any IAM exception — every domain rejection returns **500** | **BLOCKING** |
| 7 | Error envelope undefined — **four** incompatible shapes ship today | **BLOCKING** |
| 8 | Password-strength policy has no precedent anywhere in the platform | **DECISION** |
| 9 | Roles-only-via-templates is **documentation, not enforcement**; `is_system` role assignment is unguarded | **CRITICAL** |

**A false premise in the brief must be corrected before anything is built.** The brief lists as
*already certified*:

> `Archived → DENY`, `Soft Deleted → DENY`

**This is not implemented at any layer.** Adversarial verification (re-derived independently from
source) returned **REFUTED**. `UserPasswordService::adminReset()` does not import `UserStatus`, reads
no `status`, and performs no `trashed()` check; `UserPolicy` contains no occurrence of
`status|archiv|trashed|deleted`. What TASK-IAM-PASSWORD-RESET-DOMAIN-OPERATION-001 certified was
**preservation** (a reset does not *change* lifecycle state) — the brief has inverted that into
**denial** (a reset is *refused*). Those are opposite guarantees. My own certification report
explicitly carved CASE 8 out as *undefined*.

Consequence: the DENY rule is a **new business decision**, and it belongs in the **domain layer**, not
in a controller. Implementing it in the HTTP surface would create precisely the second authorization
engine Part 5 forbids. See §22, Decision 1.

---

## 2 — Existing IAM HTTP Surface (Part 1)

### 2.1 What exists

| Artifact | Path | Content |
|---|---|---|
| Controller | `Modules/IAM/Presentation/Http/Controllers/AuthController.php` | 53 lines; `login`, `logout`, `me` |
| FormRequest | `Modules/IAM/Presentation/Http/Requests/LoginRequest.php` | the **only** request object in IAM |
| Policies | `Presentation/Policies/{BasePolicy,UserPolicy}.php` | 12 abilities (§6) |
| Actions | `Application/Actions/{Login,Logout}Action.php` | |
| DTOs | `Application/DTO/{Login,AuthenticatedUser}DTO.php` | |
| Concern | `Presentation/Concerns/HidesSensitiveFields.php` | **zero consumers platform-wide** |

Routes (`routes/api.php:283-291`):

```php
Route::prefix('auth')->group(function (): void {
    Route::post('/login',  [AuthController::class, 'login']);              // throttled
    Route::middleware(['auth:sanctum', 'throttle:120,1'])->group(function (): void {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me',      [AuthController::class, 'me']);
    });
});
```

### 2.2 What does NOT exist — verified negative

| Claim | Evidence |
|---|---|
| Any `users` route | 0 hits in `routes/api.php` |
| `UserController.php` anywhere | `find` returns nothing |
| An `iam` route prefix | 0 hits |
| API versioning (`/v1`) | 0 hits — **no module is versioned** |
| Named routes (`->name()`) | **0** occurrences in all 3,897 lines of `routes/api.php` |
| `app/Exceptions/Handler.php` | does not exist — config lives in `bootstrap/app.php` |
| Named rate limiter (`RateLimiter::for`) | 0 hits |
| `Illuminate\Validation\Rules\Password` | **0 hits in the entire backend** |
| IAM `Resources/` directory | does not exist — IAM returns raw DTO arrays |

### 2.3 Conventions in force

- **Envelope (success):** `app/Core/Responses/ApiResponse.php` via the `HasApiResponse` trait →
  `{success, message, data, errors}`. `errors` serializes as `[]` (array, not object).
- **Permission middleware:** `permission:{name}` exists and is used extensively by other modules
  (e.g. `routes/api.php:318-320`). It checks the **permission only — never tenancy**.
- **Route model binding:** the platform mixes implicit binding and explicit lookups. `users.id` is a
  **bigint auto-increment**, unlike every other tenant entity, which uses UUIDs.

---

## 3 — User Management Capability Matrix (Part 2)

Classification: **A** domain+HTTP · **B** domain, no HTTP · **C** neither · **D** undefined contract ·
**E** needs repair.

| Operation | Domain implementation | Class |
|---|---|---|
| list users | `UserRepository::search()/query()` — **no tenant scope** | **B + E** |
| view user | *no single-user fetch method exists* | **C** |
| create user | `UserIdentityService::createDraft()` | **B + E** (STOP 1) |
| update user | `UserIdentityService::updateIdentity()`, `UserProfileService` | **B + E** (STOP 2) |
| delete (soft) | `UserLifecycleService::softDelete()` :94 | **B** |
| archive | `UserLifecycleService::archive()` :80 | **B** — no permission, no ability |
| activate | `UserLifecycleService::activate()` :53 | **B** |
| deactivate | `UserLifecycleService::deactivate()` :58 | **B** — no permission, no ability |
| suspend | `UserLifecycleService::suspend()` :63 | **B** |
| lock | `UserLifecycleService::lock()` :68 | **B** — no permission, no ability |
| unlock | `UserLifecycleService::unlock()` :73 | **B + E** — counter reset lost on no-op |
| restore | `UserLifecycleService::restore()` :85 | **E** — **broken** (STOP 5) |
| assign role | `UserRoleAssignmentService::assignTemplate()` | **B** |
| revoke role | `UserRoleAssignmentService::removeTemplate()` :56 | **B** — no permission, no ability |
| reset password | `UserPasswordService::adminReset()` | **B** — **certified** |
| resend invitation | `invite()` acts as an undocumented resend | **D** |
| invitation activation | `UserInvitationService::activate()` | **B** — must be unauthenticated |
| permanent delete | **DOES NOT EXIST** — no `forceDelete` in the codebase | **C** |

### 3.1 The authorization asymmetry that shapes the whole task

**`UserPasswordService::adminReset()` is the ONLY IAM service that authorizes itself.** Verified: the
sole `Gate::` call in all of `Modules/IAM/Application/Services/` is `UserPasswordService.php:70`.

Every other service — lifecycle, identity, roles, invitations, sessions, organization — **trusts its
caller**. The service's own docblock says so (`UserPasswordService.php:37-42`).

**Therefore the HTTP layer is solely responsible for authorizing 7 services**, and the certified
"authorization lives inside the operation" pattern applies to exactly one method. This is the single
most important architectural fact for the implementation task.

---

## 4 — Scope Definition (Part 3)

**In scope:** HTTP exposure of already-defined IAM domain operations for User Management.

**Explicitly out of scope** (and none was designed here): IAM Workspace UI, any frontend, RBAC
redesign, session-management redesign, `ScopeResolver` redesign, authentication redesign, invitation
redesign, password-policy design.

**Forced out of scope by the audit** — these cannot ship in an HTTP-only task because they require
domain or catalog changes:

1. `archive`, `restore`, `deactivate`, `lock`, `unlock`, `revoke-role` endpoints — no permission, no
   policy ability (STOP 4).
2. Any `restore` endpoint — the domain method is broken (STOP 5).
3. Session endpoints — `user_sessions` is **never written in production**;
   `UserSessionService::record()` has no caller, so `forceLogout()` always operates on an empty set.

---

## 5 — Proposed Route Matrix (Part 4)

Conventions adopted: no versioning, no named routes, `{success,message,data,errors}` envelope,
`auth:sanctum` + `throttle:120,1` + `permission:` middleware — all matching what ships today.

**Tier 1 — implementable once STOPs 1–3, 6, 7 are resolved:**

| Method | URI | Action | Permission | Tenant | Success | Errors |
|---|---|---|---|---|---|---|
| GET | `api/users` | `index` | `iam.users.view` | **query-scoped (must be built)** | 200 paginated | 401/403 |
| GET | `api/users/{user}` | `show` | `iam.users.view` | `UserPolicy::view` | 200 | 401/403/404 |
| POST | `api/users` | `store` | `iam.users.create` | **server-derived company_id** | 201 | 401/403/422 |
| PATCH | `api/users/{user}` | `update` | `iam.users.update` | `UserPolicy::update` | 200 | 401/403/404/422 |
| DELETE | `api/users/{user}` | `destroy` | `iam.users.delete` | `UserPolicy::delete` | 200 | 401/403/404/422 |
| POST | `api/users/{user}/activate` | `activate` | `iam.users.activate` | `UserPolicy::activate` | 200 | 401/403/404/422 |
| POST | `api/users/{user}/suspend` | `suspend` | `iam.users.suspend` | `UserPolicy::suspend` | 200 | 401/403/404/422 |
| POST | `api/users/{user}/reset-password` | `resetPassword` | `iam.users.reset-password` | **certified**, inside the service | 200 | 401/403/404/422 |
| POST | `api/users/{user}/invitations` | `invite` | `iam.users.invite` | `UserPolicy::invite` | 201 | 401/403/404/422 |
| POST | `api/users/{user}/roles` | `assignRole` | `iam.users.assign-role` | `UserPolicy::assignRole` | 200 | 401/403/404/422 |
| POST | `api/users/{user}/organizations` | `assignOrg` | `iam.users.assign-org` | `UserPolicy::assignOrganization` | 200 | 401/403/404/422 |

**Tier 2 — BLOCKED, do not implement:** `archive`, `restore`, `deactivate`, `lock`, `unlock`,
`DELETE .../roles/{template}` (revoke), and all session endpoints.

`reset-password` is the **only** route where the controller must NOT call `authorize()` — the service
does it internally. Calling it in both places would duplicate the check the certification pinned.

---

## 6 — Permission Matrix (Part 6)

**Catalog** — `config/permissions.php:23-26`, the complete `iam` namespace:

```php
'iam' => [
    'users' => ['view', 'create', 'update', 'delete', 'activate', 'suspend',
                'assign-role', 'assign-org', 'invite', 'reset-password', 'manage-sessions'],
    'roles' => ['view', 'create', 'update', 'delete', 'assign'],
],
```

Names compose as `{domain}.{resource}.{action}` (`RbacSeeder.php:60`) → **16 `iam.*` permissions**.

| Endpoint | Ability | Permission | Exists? |
|---|---|---|---|
| index | `viewAny` | `iam.users.view` | ✅ |
| show | `view` | `iam.users.view` | ✅ |
| store | `create` | `iam.users.create` | ✅ |
| update | `update` | `iam.users.update` | ✅ |
| destroy | `delete` | `iam.users.delete` | ✅ |
| activate | `activate` | `iam.users.activate` | ✅ |
| suspend | `suspend` | `iam.users.suspend` | ✅ |
| assign role | `assignRole` | `iam.users.assign-role` | ✅ |
| assign org | `assignOrganization` | `iam.users.assign-org` | ✅ |
| invite | `invite` | `iam.users.invite` | ✅ |
| reset password | `resetPassword` | `iam.users.reset-password` | ✅ |
| **archive** | — | `iam.users.archive` | ❌ |
| **restore** | — | `iam.users.restore` | ❌ |
| **deactivate** | — | `iam.users.deactivate` | ❌ |
| **lock** | — | `iam.users.lock` | ❌ |
| **unlock** | — | `iam.users.unlock` | ❌ |
| **revoke role** | — | `iam.users.revoke-role` | ❌ |

**STOP 4.** Six shipped domain operations have no permission *and* no policy ability. `iam.users.delete`
cannot honestly gate `archive` — they are distinct transitions (`DELETED` vs `ARCHIVED`). Nor does
`suspend` cover `deactivate` (`SUSPENDED` vs `INACTIVE`). Adding them is a `config/permissions.php`
change plus a re-seed — outside "HTTP exposure of existing operations".

> **Secondary hazard.** `PermissionName.php:66` enforces `/^[a-z][a-z0-9_]*$/` per segment. **Hyphens
> are outside that grammar**, so `assign-role`, `assign-org`, `reset-password` and `manage-sessions`
> are silently dropped by any code path routing through `PermissionName`. Enforcement is unaffected
> today (raw string comparison), but new HTTP code must not use `PermissionName` for these four.

Holders today: `company-admin` (all 11, `:176`), `viewer` (`view`, `:272`), `system-auditor`
(`view`, `:472`).

---

## 7 — Tenant Boundary (Part 7)

### 7.1 The certified path — unchanged, unmodified

```
UserPolicy::resetPassword() → allow() → can() → PermissionService     [permission]
                                     → ownsTarget() → TenantOwnershipResolver::owns()  [tenant]
```

`TenantOwnershipResolver::owns()` compares company UUIDs and fails closed on a null own-company.
`Gate::before` (`IamServiceProvider.php:104-113`) only ever **widens** (`is_system` → allow).

Required behaviour — already proven at runtime by the password-reset certification:

| Scenario | Expected | Status |
|---|---|---|
| Company A admin → Company A user | ALLOW | ✅ certified |
| Company A admin → Company B user | DENY | ✅ certified |
| Company A admin → Company B Super Admin | DENY | ✅ certified |
| System Admin → cross-company | ALLOW (existing semantics) | ✅ certified |

### 7.2 Where the boundary does NOT reach — the three holes

`UserPolicy.php:29-31` states plainly that `viewAny` and `create` take **no target**, so the tenant
check cannot apply, and defers scoping to "the query/creation path".

**That query/creation path does not exist.**

| Hole | Evidence |
|---|---|
| No tenant global scope on `User` | only `SoftDeletingScope`; unlike `Order`, `Warehouse`, `Supplier` which all have one |
| `UserRepository` does not scope | `UserRepository.php:48-50` — `company_id` is an **optional caller-supplied filter** |
| `createDraft` does not scope | `company_id` mass-assigned from `$data` |

The brief's item 8 ("User global scope NOT IMPLEMENTED; policy-level tenant enforcement is the
certified boundary") is accurate — but policy-level enforcement is **per-target**, and `index` and
`store` have no target. Those two endpoints are outside the certified boundary's reach by
construction.

---

## 8 — Create User Contract (Part 9) — **STOP 1**

`UserIdentityService.php:19-42`, verbatim:

```php
private const IDENTITY_FIELDS = [
    'name', 'display_name', 'email', 'username', 'employee_number', 'phone', 'avatar_path', 'company_id',
];                                                                                          // ← :20

public function createDraft(array $data, ?int $actorId = null): User
{
    $this->assertUniqueIdentity($data, null);
    $user = new User();
    $user->fill(array_intersect_key($data, array_flip(self::IDENTITY_FIELDS)));             // ← :33
    ...
}
```

And `app/Models/User.php:40` — `'company_id'` **is in `$fillable`**.

**The escape chain is complete and I verified every link myself:**

1. `company_id` is in `IDENTITY_FIELDS` (`:20`)
2. `$data` is client input, mass-assigned via `fill()` (`:33`)
3. `company_id` is fillable (`User.php:40`)
4. `createDraft()` **never** reads `Auth::user()`, `CurrentCompanyService` or `TenantOwnershipResolver`
5. The only gate is `UserPolicy::create()` (`:78-81`) — a **bare permission check with no tenant
   boundary**, necessarily, because there is no target yet

**Answer to Part 7's question: YES — exposing create over HTTP with a pass-through FormRequest lets a
Company A `company-admin` create a user owned by Company B.** This is a real tenant escape, not a
theoretical one.

**Second-order effect:** if the request *omits* `company_id`, the column takes its NULL default. The
creating admin can then never view, update, invite or reset that user, because
`TenantOwnershipResolver::owns(null)` returns false. A null company must be rejected with 422, not
silently persisted.

**Required repair (must land BEFORE any create endpoint):** `company_id` must be **server-derived**
from the authenticated tenant context and never accepted from the client — with a documented
exception for `is_system` actors, who legitimately provision across companies. The established
platform pattern to copy is `CustomerController.php:66-70`.

---

## 9 — Update User Contract (Part 10) — **STOP 2**

`updateIdentity()` (`:47-58`) fills **the same `IDENTITY_FIELDS`, including `company_id`** (`:52`).

`UserPolicy::update()` checks ownership against the target's **current** company. So a Company A admin
passes the check on their own user, then moves that user into Company B **in the same request**.
Nothing re-validates after the fill.

This is exactly what Part 10 prohibits: *"Do NOT allow `company_id` to become an arbitrary
client-controlled tenant switch."* It is switchable today.

**Field classification for the update endpoint:**

| Class | Fields | HTTP-updatable? |
|---|---|---|
| Identity | `name`, `display_name`, `email`, `username`, `employee_number`, `phone`, `avatar_path` | ✅ yes |
| Profile / preferences | `locale`, `timezone`, `date_format`, `number_format`, `currency_preference`, `signature`, `notes` | ✅ yes |
| Employment | `job_title`, `employment_type`, `manager_id`, `hire_date`, `termination_date` | ⚠️ yes, but `manager_id` has no FK and no tenant validation |
| **Tenant ownership** | `company_id` | ❌ **must be removed from the update path** |
| Security | `password`, `password_changed_at`, `require_password_change`, `failed_login_count` | ❌ dedicated operations only |
| Lifecycle | `status`, `activated_at`, `locked_at`, `suspended_at`, `archived_at`, `deleted_at` | ❌ **not fillable** — correctly protected |

**Good news:** `status` is deliberately **not** in `$fillable` (`User.php:30-32` documents this as
ADR-040 Decision 5), so mass assignment cannot bypass the state machine. That protection holds.
`password` **is** fillable, so the update FormRequest must explicitly exclude it.

---

## 10 — Role Management Contract (Part 11) — **STOP 9**

Assignment: `UserRoleAssignmentService::assignTemplate()`. Revocation: `removeTemplate()` (`:56`) —
**no permission, no ability** (STOP 4).

**The "roles ONLY via templates" rule is documentation, not enforcement.** `App\Models\User` uses the
public `HasRoles` trait (`User.php:25`), so `assignRole()`, `revokeRole()` and `roles()->attach()`
bypass the template path entirely. There is **no guard on `is_system` role assignment anywhere** —
and attaching the `super-admin` role grants an unconditional `Gate::before` bypass of the **entire
certified tenant boundary**.

**`UserSecurityRules::assertNotRemovingOwnSuperAdmin()` is dead code** — zero callers backend-wide,
and `UserRoleAssignmentService` does not even inject `UserSecurityRules`. Exposing role revocation
over HTTP without resolving this permits a self-inflicted total lockout.

---

## 11 — Lifecycle Contract (Part 12)

`UserStatus` — 9 cases; the **actual** matrix from `allowedTransitions()` (`:47-57`):

| FROM | ALLOWED TO |
|---|---|
| `DRAFT` | INVITED, ACTIVE, ARCHIVED, DELETED |
| `INVITED` | PENDING_ACTIVATION, ACTIVE, SUSPENDED, ARCHIVED, DELETED |
| `PENDING_ACTIVATION` | ACTIVE, SUSPENDED, ARCHIVED, DELETED |
| `ACTIVE` | INACTIVE, SUSPENDED, LOCKED, ARCHIVED, DELETED |
| `INACTIVE` | ACTIVE, SUSPENDED, ARCHIVED, DELETED |
| `SUSPENDED` | ACTIVE, INACTIVE, ARCHIVED, DELETED |
| `LOCKED` | ACTIVE, SUSPENDED, ARCHIVED, DELETED |
| `ARCHIVED` | ACTIVE, DELETED |
| `DELETED` | **∅ — terminal** |

Structural facts that constrain the API:

- **`DELETED` is absolutely terminal.** Nothing can leave it. This is what breaks `restore()` (§12).
- **`LOCKED` is reachable only from `ACTIVE`**, and `failed_login_count` is **never incremented
  anywhere** — there is no auto-lock mechanism.
- **`PENDING_ACTIVATION` is orphaned** — defined, reachable only from `INVITED`, and never entered by
  any production path.
- `transition()` is **idempotent**: `$from === $to` returns early **without saving or auditing**.
- `transition()` **does not authorize** — no `Gate::` in the file.
- **No transactions exist anywhere in `Modules/IAM`.**

---

## 12 — Delete / Archive Contract (Part 13) — **STOP 5**

**Archive and soft delete are entirely separate mechanisms.**

| | Mechanism | Sets | Hidden from queries? |
|---|---|---|---|
| **Archive** | `status = 'archived'` + `archived_at` | status only | **NO** — `deleted_at` stays NULL |
| **Soft delete** | `status = 'deleted'` + `$user->delete()` | both | YES — `SoftDeletingScope` |
| **Permanent delete** | **DOES NOT EXIST** — no `forceDelete` in the codebase | — | — |

The only coupling point in the entire codebase is `UserLifecycleService.php:44-46`
(`if ($to === UserStatus::DELETED) { $user->delete(); }`) — the sole `delete()` call on a User.

**An archived user appears in every default query.** Archiving is a status, not a hiding mechanism.

### `restore()` is broken — and fails destructively

```php
public function restore(User $user, ?User $actor = null): User
{
    if ($user->trashed()) { $user->restore(); }              // :88 — commits deleted_at = NULL
    return $this->transition($user, UserStatus::ACTIVE, $actor);  // :91 — THROWS
}
```

For a soft-deleted user — the only case where `trashed()` is true — `:88` nulls `deleted_at` **and
persists it**, then `:91` throws `InvalidUserTransitionException` because `DELETED → ACTIVE` is not in
the matrix. **There is no transaction**, so the un-delete is already committed.

The account is left in a state the state machine cannot describe or escape: `deleted_at` NULL (so it
is back in every listing) with `status='deleted'` (so no domain operation can ever move it again).
Combined with §17, **it can log in again**.

`restore()` only works for a **non-trashed** user, i.e. as archive-undo. Its name promises soft-delete
recovery; the code cannot deliver it. **There is no test for `restore()` or `archive()`.**

---

## 13 — Password Reset HTTP Contract

The **only** endpoint whose domain operation is fully certified and self-authorizing.

```
POST api/users/{user}/reset-password
middleware: auth:sanctum, throttle
permission: iam.users.reset-password  (enforced INSIDE UserPasswordService::adminReset)
controller: MUST NOT call authorize() — the service does it
body:     { password: <string>, require_password_change?: <bool> }
success:  200 {success,message,data:null,errors:[]}
errors:   401 | 403 (cross-tenant or missing permission) | 404 | 422
```

Guaranteed by the certification: writes exactly `password`, `password_changed_at`,
`require_password_change`; never changes lifecycle, never verifies email, never touches invitations,
never calls `forceLogout()`; audits `user.password_reset` with no credential material.

**`require_password_change` DEFAULT = `false`** — preserved.

⚠️ **Archived / soft-deleted DENY is NOT implemented** (see §1 and §22 Decision 1). If that rule is
real it must be added to the **domain**, not this endpoint.

---

## 14 — Validation Contract (Part 8) — **STOP 8**

| Field | Rule | Notes |
|---|---|---|
| `name` | required, string | |
| `email` | required, email, **globally unique** | see below |
| `username`, `employee_number` | nullable, unique | checked `withTrashed()` |
| `company_id` | **must NOT be accepted from the client** | STOP 1 / STOP 2 |
| `status` | **must NOT be accepted** | lifecycle endpoints only |
| `password` | **UNDEFINED — no precedent** | STOP 8 |

**Password policy has no precedent anywhere in the platform.** `Illuminate\Validation\Rules\Password`
is imported **nowhere**; `LoginRequest` uses `['required','string']`; `adminReset()` applies no
strength check; `UserInvitationService::activate()` validates nothing. **An empty string is currently
accepted.** This must be a stated business/security decision — the domain deliberately did not invent
one, and the HTTP task must not either.

**Uniqueness is global, not per-tenant.** `users.email` is globally unique with no
`(email, company_id)` composite. `assertUniqueIdentity()` queries `User::withTrashed()` with **no
company scope** and throws `"A user with this {field} already exists."` — over HTTP that is an
**existence oracle** for any email in any company, and it lets Company A squat identifiers Company B
needs. A soft-deleted user **permanently burns** its identifiers: there is no `forceDelete`, and
`restore()` is broken.

---

## 15 — Response Contract (Part 14)

**Three incompatible pagination shapes ship today:**

| Shape | Used by |
|---|---|
| **A** — `$this->success(['items'=>…, 'meta'=>[...]])` inside `{success,message,data,errors}` | Inventory/Products, Organization/Companies, HR/Workforce |
| **B** — bare `response()->json(['data'=>…, 'meta'=>[...]])` | CRM/Customers |
| **C** — native `Resource::collection($page)->response()` → `{data,links,meta}` | Logistics/Fleet |

**Recommendation: Convention A** — it matches `AuthController` and the platform's own `ApiResponse`
helper. No new envelope should be created.

**A `UserResource` must whitelist explicitly.** `$hidden` contains only `password` and
`remember_token`, so `status`, `deleted_at`, `failed_login_count`, `notes`, `signature`, `company_id`,
`created_by` and `invited_by` would all serialize by default.

Two further facts for the resource design:

- **`User` has no `company()` relation** — `company_id` is fillable but no Eloquent relation exposes
  it. A company name requires a manual join.
- **`locked_at` / `suspended_at` / `archived_at` are never cleared.** An ACTIVE user carries stale
  timestamps forever. **Any resource deriving display state from these columns instead of `status`
  will be wrong.**

Passwords and hashes must never appear — satisfied by `$hidden` plus explicit whitelisting.

---

## 16 — Error Contract (Part 15) — **STOP 6 + STOP 7**

**Four incompatible error shapes ship today:**

| Case | Shape |
|---|---|
| 200 success | `{success, message, data, errors}` |
| 401 from `auth:sanctum` | bare `{message}` |
| 403 from `Gate::authorize` | bare `{message}` (+ trace when `APP_DEBUG=true`) |
| 403 from `permission:` middleware | bare `{message: "Permission denied: X"}` |
| 422 from FormRequest | `{message, errors{}}` |
| 401 from `InvalidCredentialsException` | **the full envelope** |

**STOP 6 — every IAM domain exception renders as HTTP 500.** Only `InvalidCredentialsException`
extends `BusinessException`. These all extend plain `RuntimeException` with **no renderer** in
`bootstrap/app.php:51-128`:

- `InvalidUserTransitionException`
- `UserSecurityRuleException`
- `UnknownTemplatePermissionException`
- `SystemTemplateImmutableException`
- `RoleTemplateImportException`
- plus 4 raw `\InvalidArgumentException` throw sites (including duplicate-identity,
  `UserIdentityService.php:74`)

So today a rejected transition returns **500 `{"message":"Server Error"}`**, losing messages such as
*"Cannot remove or deactivate the last active Super Administrator."* Every lifecycle and role endpoint
would be affected.

**Required mapping (must be decided before controllers exist):**

| Condition | Proposed | Currently |
|---|---|---|
| Unauthenticated | 401 | ✅ works |
| Missing permission / cross-tenant | 403 | ✅ works |
| Missing target | 404 | ⚠️ leaks model FQCN + probed id |
| Validation | 422 | ✅ works |
| Invalid lifecycle transition | **422 or 409 — UNDEFINED** | ❌ 500 |
| Security-rule violation | **422 or 403 — UNDEFINED** | ❌ 500 |
| Duplicate identity | **409 or 422 — UNDEFINED** | ❌ 500 |

---

## 17 — Authentication Boundary (Part 17)

`POST api/auth/login`, `POST api/auth/logout`, `GET api/auth/me` are **outside User Management** and
must not be modified.

Sanctum: `createToken('auth', ['*'], $expiresAt)`; `$expiresAt = remember ? null : now()->addDay()`.
`revokeCurrentToken()` deletes only the current token — **there is no revoke-all path** in the auth
service.

> **Security finding (not a task deliverable, but it changes what the HTTP surface means).**
> `SanctumAuthService::attemptCredentials()` checks **only email + password hash. It never reads
> `status`.** `UserStatus::canAuthenticate()` has **zero callers** — it is dead code.
>
> Consequently **SUSPENDED, LOCKED, INACTIVE, ARCHIVED, DRAFT, INVITED and PENDING_ACTIVATION users
> can all obtain a working Bearer token today.** Shipping `suspend` and `lock` endpoints without
> resolving this delivers controls that visibly "work" while not actually restricting access.
> (Soft-deleted users are blocked, but incidentally — by `SoftDeletingScope`, not by the state
> machine.)

---

## 18 — Audit / Event Mapping (Part 18)

**IAM dispatches no domain events at all.** There is no `Domain/Events` directory for user operations —
IAM **audits** rather than emitting events. Inventing an event architecture is out of scope.

`UserAuditService::log()` prefixes `user.` and writes `entity_type='user'`.

| HTTP action | Domain operation | Audit action |
|---|---|---|
| store | `createDraft()` | `user.created` |
| update | `updateIdentity()` | `user.identity_updated` |
| activate/suspend/… | `transition()` | `user.status_changed` |
| assign role | `assignTemplate()` | `user.template_assigned` |
| invite | `invite()` | `user.invited` |
| activation | `activate()` | `user.activated` |
| **reset password** | `adminReset()` | **`user.password_reset`** ✅ certified credential-free |
| force logout | `forceLogout()` | `user.force_logout` |

**Three audit gaps the HTTP layer must account for:**

1. **Actor comes from `Auth::id()`, not the `$actorId` parameter** threaded through nine service
   methods. Behind `auth:sanctum` this is correct — but the parameters are then redundant.
2. **No audit on a no-op transition** — `$from === $to` returns before the audit call.
3. **Audit is fire-and-forget** — it never throws, so a lost audit row does not fail the operation.
4. `audit_logs.company_id` is the **target's** company, not the actor's — relevant for cross-tenant
   system-admin actions.

---

## 19 — Route Security (Part 20)

- **Middleware order:** `auth:sanctum` → `throttle:120,1` → `permission:{name}` → controller → policy.
- **`permission:` middleware checks the permission only — never tenancy.** It is not a substitute for
  the policy; both are required.
- **Route model binding:** implicit binding on `{user}` **will** load a cross-company user — the model
  has no tenant global scope. The policy is therefore the *only* thing standing between binding and a
  cross-tenant mutation. `UserPolicy` handles this correctly for every target-bearing ability, and the
  password-reset certification proved it at runtime (a directly-loaded foreign user is refused).
- **Implicit binding 404s soft-deleted users** (`SoftDeletingScope`), and there is **no `withTrashed()`
  usage anywhere in the codebase** — so a restore endpoint would need a different binding mechanism.
- **`users.id` is a bigint auto-increment**, unlike every other tenant entity (UUID). Sequential
  integer IDs in URLs are enumerable.
- **404 bodies leak the model FQCN and probed id** — `"No query results for model [App\Models\User] 42"`.

---

## 20 — Runtime Test Matrix (Part 21)

Per endpoint, minimum:

| # | Case | Expected |
|---|---|---|
| 1 | unauthenticated | 401 |
| 2 | authenticated, no permission | 403 |
| 3 | same-company authorized | 2xx |
| 4 | cross-company target | 403 |
| 5 | cross-company Super Admin target | 403 |
| 6 | system admin cross-company | existing semantics (allow) |
| 7 | missing target | 404 |
| 8 | invalid payload | 422 |
| 9 | duplicate identity | per §16 decision |
| 10 | reset password → `adminReset()` invoked | 200 + password changed |
| 11 | archived target reset | **per §22 Decision 1** |
| 12 | soft-deleted target reset | **per §22 Decision 1** |
| 13 | no password/hash in response | assert on body |
| 14 | no password/hash in audit | assert on `audit_logs` |
| 15 | tenant isolation | cross-company invisible |

**Endpoint-specific additions — these are the ones that would catch the STOPs:**

| Endpoint | Extra case |
|---|---|
| `POST users` | **`company_id` in the payload must NOT set the owner** (STOP 1 regression test) |
| `POST users` | omitted `company_id` → 422, never a NULL-company orphan |
| `PATCH users/{user}` | **`company_id` in the payload must NOT re-home the user** (STOP 2) |
| `GET users` | **Company A admin sees zero Company B users** (STOP 3) |
| `GET users` | archived users included/excluded per decision |
| lifecycle | invalid transition → mapped status, **not 500** (STOP 6) |
| lifecycle | self-deactivation → refused |
| lifecycle | last-super-admin → refused |

Mandatory: `$grantsBaselineAuthorization = false` on every test class, or `actingAs()` grants the
`is_system` bypass and **every denial assertion passes vacuously**.

---

## 21 — Stop Conditions (Part 24)

| # | Part-24 condition | Triggered | Detail |
|---|---|---|---|
| 1 | Create exposes arbitrary `company_id` | **YES** | §8 — verified escape chain |
| 2 | Required permission missing | **YES** | §6 — six missing |
| 3 | Lifecycle transition undefined | **YES** | §12 — `DELETED → *` breaks `restore()` |
| 4 | Password policy needs a decision | **YES** | §14 — no precedent |
| 5 | Role assignment ambiguous | **YES** | §10 — templates unenforced, `is_system` unguarded |
| 6 | Delete/archive ambiguous | **YES** | §12 — three distinct meanings, one broken |
| 7 | Response conventions unclear | **YES** | §15/§16 — 3 pagination + 4 error shapes |
| 8 | Authentication must be redesigned | **PARTIAL** | §17 — login ignores `status`; not required for HTTP, but suspend/lock are cosmetic without it |
| 9 | New schema required | **NO** | none needed for Tier 1 |
| 10 | Tenant boundary needs certified-code changes | **NO** | `UserPolicy`/`TenantOwnershipResolver` untouched; gaps are in *uncovered* paths |
| 11 | Another agent owns the same files | **NO** | audit touched nothing |
| 12 | Docker/test env unavailable | **NO** | healthy, DB = `ecos_dev_test` |
| 13 | HTTP needs non-existent domain logic | **YES** | tenant-scoped listing + single-user fetch do not exist |

---

## 22 — Open Business Decisions

**These require your approval before implementation.**

**Decision 1 — Archived / soft-deleted password reset (CORRECTS THE BRIEF).**
The brief states `Archived → DENY` / `Soft Deleted → DENY` as already certified. **It is not
implemented at any layer** — verified REFUTED. Please confirm: is DENY the intended rule? If yes it is
a **domain change to `UserPasswordService`/`UserPolicy`** in its own task (the FINAL STOP forbids
touching that service here), **not** a controller check. Note that today an archived user's password
can be reset *and* they can then log in, because login ignores `status`.

**Decision 2 — Who owns a created user?** Server-derived `company_id` from the authenticated tenant
(recommended), with `is_system` actors permitted to specify another company? ADR-040 never addressed
tenancy — this needs an ADR amendment, not a task-level invention.

**Decision 3 — May `company_id` ever be updated?** Recommendation: **no** — remove it from
`IDENTITY_FIELDS`. Tenant transfer should be a separate, explicitly-permissioned operation if needed
at all.

**Decision 4 — Add the six missing permissions?** `archive`, `restore`, `deactivate`, `lock`,
`unlock`, `revoke-role` — or drop those endpoints from scope?

**Decision 5 — Password strength policy.** No precedent exists. What is the rule for admin reset and
invitation activation?

**Decision 6 — Error contract.** Which shape, and what status codes for invalid transition (422/409),
security-rule violation (422/403) and duplicate identity (409/422)?

**Decision 7 — Does login gate on `status`?** Without it, `suspend`/`lock` endpoints ship controls
that do not restrict access.

**Decision 8 — Are archived users in the default listing?** They are today (`deleted_at` is NULL).

**Decision 9 — Global email uniqueness.** Accept as the tenancy model, or change the constraint
(schema change)? Today a soft-deleted user permanently burns its email.

**Decision 10 — Session endpoints.** `user_sessions` is never written; `record()` has no caller. Drop
from scope, or wire login to record sessions (touches the certified auth flow)?

---

## 23 — Implementation Plan

**Phase 0 — Decisions (blocking).** Resolve §22. No code until Decisions 1–6 are answered.

**Phase 1 — Domain repairs (separate task, NOT HTTP).**
1. Remove `company_id` from `UserIdentityService::IDENTITY_FIELDS`; derive it server-side on create.
2. Add tenant scoping to the user-listing path.
3. Fix `UserLifecycleService::restore()` + wrap in a transaction.
4. Add the six permissions + policy abilities (if Decision 4 = yes).
5. Register exception renderers, or rebase IAM exceptions on `BusinessException`.
6. Apply Decision 1 to `UserPasswordService`/`UserPolicy` if DENY is confirmed.

**Phase 2 — HTTP surface (the actual TASK-IAM-HTTP-SURFACE-001).**
Tier-1 routes only (§5): `index`, `show`, `store`, `update`, `destroy`, `activate`, `suspend`,
`reset-password`, `invite`, `assignRole`, `assignOrganization`.
- One `UserController`, one FormRequest per mutating action, one `UserResource`.
- **Controllers authorize via `$this->authorize()` for all 7 non-self-authorizing services**, and
  **must not** authorize for `reset-password`.
- Convention A envelope. No versioning. No named routes.

**Phase 3 — Runtime certification.** §20 matrix, `$grantsBaselineAuthorization = false`, in
`ecos-dev-testrunner` against `ecos_dev_test`, plus PHPStan L0 / core L6 / scoped Pint / Guardian.

---

## 24 — Static Quality (Part 23)

This audit **modified no files**, so scoped Pint has no target. PHPStan re-run to confirm the tree is
unchanged from the certified state:

```
phpstan.neon.dist       (L0)         [OK] No errors
phpstan-core.neon.dist  (core L6)    [OK] No errors
```

Pre-existing Guardian Pint violations (`ProductPopulationScopeTest`, `V3TransitionResolutionTest`)
remain untouched.

---

## 25 — Compliance With the Final Stop

No controller, route, FormRequest, resource or migration was created. `UserPolicy`,
`TenantOwnershipResolver` and `UserPasswordService` were **not modified**. `git status` for
`Modules/IAM/` is unchanged from the start of this task apart from this document.

> # TASK-IAM-HTTP-SURFACE-001 = AUDIT COMPLETE — IMPLEMENTATION BLOCKED
>
> 9 STOP conditions, 10 business decisions. The task premise (greenfield User Management HTTP) is
> confirmed correct, but it is **not** a pure transport exercise: three tenant-escape paths, six
> missing permissions, one broken domain operation and an undefined error contract must be resolved
> in the **domain and catalog layers** first.

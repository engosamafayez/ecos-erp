# TASK-IAM-TENANT-AUTHORIZATION-BOUNDARY-001 — Engineering Report

**Date:** 2026-08-11
**Branch:** `develop` · **HEAD:** `6149875bd8a01820116b5deacbbfb8ef0e51cc05`
**Type:** Security / domain contract audit — **no production code changed**
**Decision:** **B — the existing architecture can enforce the boundary with a small authorization change**

---

## 1 — Executive Summary

**The security question is answered, with runtime evidence: YES — a Company A administrator holding an
`iam.users.*` permission can currently operate on a Company B user. All ten user-management abilities
cross the company boundary, including reset-password against a Super Administrator in another
company.**

```
B  company-A admin -> company-B user                     -> ALLOWED   (must be DENIED)
C  company-B admin -> company-A user                     -> ALLOWED   (must be DENIED)
E  company-A admin -> SUPER ADMIN in company B           -> ALLOWED   (privilege escalation)
   cross-company ALLOWED: 10 of 10 abilities
```

The cause is narrow and precise: **IAM user management was never wired into the platform's existing
tenant architecture.** `App\Core\Company\TenantOwnershipResolver` already exists, is documented as
*"the single server-side authority for tenant (company) ownership"*, already implements exactly the
semantics this boundary needs, and is already used by `Order`, `Warehouse`, `Supplier` and
`Product`. **`App\Models\User` is the omission.**

No new tenant component is required, no schema change is required, and **no new global privilege has
to be invented** — the architecture already defines its global actor as any role with
`is_system = true` (via `Gate::before()`), and `TenantOwnershipResolver` already encodes that rule.

Hence decision **B**, not C.

---

## 2 — Starting Commit

| Item | Value |
|---|---|
| HEAD | `6149875bd8a01820116b5deacbbfb8ef0e51cc05` |
| Branch | `develop` |
| Tracked diff | `9 files changed, 326 insertions(+), 31 deletions(-)` — unchanged by this task |
| IAM authorization files modified by any agent | **none** |
| Added by this task | 1 test-only probe + this report |

No STOP condition on ownership (Part 21.6). Nothing was reverted, reset or cleaned.

---

## 3 — Current IAM Authorization Architecture

The authorization path is uniform for every `iam.users.*` ability:

```
(future HTTP entry point — does not exist yet)
  → Gate::forUser($actor)->allows($ability, $target)
      → Gate::before()                    [IamServiceProvider:104]
            └─ userHasSystemRole($actor) → TRUE ⇒ ALLOW everything, policy never runs
      → UserPolicy::{ability}($actor, $target)     [Gate::policy(User::class, …) at :89]
            └─ BasePolicy::can($actor, 'iam.users.…')
                  └─ PermissionService::userHasPermission($actor, $permission)
                        └─ in_array($permission, $this->getUserPermissions($actor), true)
  → decision
```

**There is no target-resolution step and no company-resolution step anywhere in this path.** The
decision is a pure function of the *actor's* permission set. `$target` is passed into every policy
method and never read.

`config/permissions.php` registers all ten abilities in both catalog locations (lines 24 and 176):
`view, create, update, delete, activate, suspend, assign-role, assign-org, invite, reset-password,
manage-sessions`. Note the catalog has no `revoke-role`; role removal is `removeTemplate()` on the
service, with no corresponding policy ability.

---

## 4 — User Company Ownership (Part 3)

| Question | Answer | Evidence |
|---|---|---|
| 1. Is `company_id` mandatory? | **No** | `$table->uuid('company_id')->nullable()` |
| 2. Nullable? | **Yes** | same |
| 3. Enforced by DB? | Referentially only | FK → `companies`, `nullOnDelete()`, indexed. **Not** `NOT NULL` |
| 4. Enforced by model? | **No** | `company_id` appears in `App\Models\User` **only** in `$fillable`; no `booted()`, no global scope, no tenant trait |
| 5. Enforced by repository? | **No** | `UserRepository::query()` — `if (! empty($filters['company_id']))`, an optional filter |
| 6. Enforced by policy? | **No** | `UserPolicy` ignores `$target` entirely |
| 7. Global User tenant scope? | **No** | — |
| 8. IAM-specific tenant resolver? | **No** | `TenantOwnershipResolver` exists platform-wide but `User` does not use it |

Migration `2026_07_07_000002_add_company_id_to_users_table.php` states the intent —
*"When company_id is set, repositories scope queries to that company"* — but that scoping was never
implemented for users.

**Company ownership of a User is representable and provable (Part 21.1 satisfied): the column exists,
is indexed, and is FK-constrained.** It is simply not enforced.

---

## 5 — PermissionService Scope Analysis (Part 4)

Verified against current source — the previous audit's finding still holds verbatim:

```php
public function userHasPermission(User $user, string $permission): bool
{
    return in_array($permission, $this->getUserPermissions($user), true);   // flat, company-agnostic
}

public function userHasPermissionInScope(
    User $user, string $permission,
    ?string $companyId = null, ?string $branchId = null, ?string $warehouseId = null,
): bool {
    // Scope resolution not yet implemented.
    // Delegates to flat permission check until scoped authorization lands.
    return $this->userHasPermission($user, $permission);
}
```

| Question | Answer |
|---|---|
| What does `userHasPermission()` check? | Membership of the permission name in the actor's cached flat permission set |
| What does `userHasPermissionInScope()` check? | **Identical** — the three scope arguments are accepted and discarded |
| Are scope parameters meaningful today? | **No** |
| Is Company part of the permission model? | **Partially** — see below |
| Role scope? | Roles are global: `roles` has `id, name, slug, description, is_system, timestamps` — **no `company_id`** |
| User scope? | `users.company_id` exists, unenforced |
| Company scope? | **The `user_roles` pivot already carries `company_id`, `branch_id`, `warehouse_id`** (nullable, indexed), commented *"Scope columns for future scoped RBAC"* |
| Stored, derived, or absent? | **Stored but unread** for authorization |

**This is the single most important structural finding.** The scope columns on `user_roles` match the
`userHasPermissionInScope()` signature exactly (company / branch / warehouse). The intended design is
already expressed in both the schema and the interface — only the wiring is missing.

**Separately**, `ScopeResolver` (ADR-038 Data Scope Engine) *is* fully implemented, but it governs
**data query filtering** (`Model::query()->scopedTo($user, 'resource')`), not authorization decisions
about a specific target object. It cannot protect `UserPolicy::resetPassword($actor, $target)`.

> **Inconsistency worth recording.** `ScopeResolver::constraintFor()` treats a null `company_id` as
> *unrestricted* for `DataScope::COMPANY` (line 109–111, commented "super-admin-style"), whereas
> `TenantOwnershipResolver` explicitly refuses that inference: *"Cross-company access is NOT inferred
> from a null company_id… A user who simply has no company affiliation and no privileged role is
> unprivileged, not unrestricted."* The two components disagree about a null-company, non-system user.
> They govern different concerns, so this is not a contradiction *within* the authorization path
> (Part 21.4 not triggered), but the IAM boundary must follow `TenantOwnershipResolver` — the declared
> single authority — and must not replicate `ScopeResolver`'s null-handling.

---

## 6 — UserPolicy Analysis (Part 5)

Every user-targeting ability has the identical body shape:

```php
public function resetPassword(User $user, User $target): bool
{
    return $this->can($user, 'iam.users.reset-password');    // $target unused
}
```

| Ability | Checks actor permission | Checks target company | Checks actor company | Both | Role exception | Nothing beyond permission |
|---|---|---|---|---|---|---|
| `view` | ✔ | ✘ | ✘ | ✘ | via `Gate::before` only | **F** |
| `update` | ✔ | ✘ | ✘ | ✘ | ″ | **F** |
| `delete` | ✔ | ✘ | ✘ | ✘ | ″ | **F** |
| `activate` | ✔ | ✘ | ✘ | ✘ | ″ | **F** |
| `suspend` | ✔ | ✘ | ✘ | ✘ | ″ | **F** |
| `assignRole` | ✔ | ✘ | ✘ | ✘ | ″ | **F** |
| `assignOrganization` | ✔ | ✘ | ✘ | ✘ | ″ | **F** |
| `invite` | ✔ | ✘ | ✘ | ✘ | ″ | **F** |
| `resetPassword` | ✔ | ✘ | ✘ | ✘ | ″ | **F** |
| `manageSessions` | ✔ | ✘ | ✘ | ✘ | ″ | **F** |

**Classification F — nothing beyond the actor's permission — for all ten.** `viewAny` and `create`
take no target, so the boundary question does not arise for them in the policy (it arises for
`create` in the *service*; see §12).

There is no `revokeRole` ability; template removal has no policy.

---

## 7 — UserRepository Analysis (Part 6)

```php
public function query(array $filters = []): Builder
{
    $query = User::query()->with([...]);
    ...
    if (! empty($filters['company_id'])) { $query->where('company_id', $filters['company_id']); }
    if (array_key_exists('with_trashed', $filters) && $filters['with_trashed']) { $query->withTrashed(); }
    return $query;
}
```

| Question | Answer |
|---|---|
| How are users fetched? | `User::query()` with caller-supplied filters |
| Is `company_id` optional? | **Yes** — a caller-supplied filter, defaulting to absent |
| Implicit tenant filter? | **None** |
| Can a lookup return another company's user? | **Yes** — and there is no `find()` at all; callers use `User::find()` / route-model binding directly |
| Are list/search methods company-scoped? | **No** |
| Can role/permission queries cross companies? | **Yes** — `roles` has no company column, and `user_roles` scope columns are unread |

**`find(id)` vs `find(id, company_id)`:** only the former concept exists. `UserRepository` exposes no
by-id method, so a future controller would use route-model binding or `User::findOrFail()`, neither of
which is tenant-aware. This is precisely the bypass Part 16 requires the design to survive.

---

## 8 — Global / System Administrator Analysis (Part 7)

**A legitimate global actor already exists and is explicitly contracted — nothing needs inventing.**

`IamServiceProvider:104`:

```php
Gate::before(function (User $user, string $ability): ?bool {
    if ($permissions->userHasSystemRole($user)) { return true; }   // bypasses every ability
```

Documented at `:43-44` — *"Wire Gate::before() so system roles bypass all ability checks. The bypass
is keyed on is_system = true — never on a hardcoded slug"* — and at `:101-103` — *"any role with
is_system = true skips all subsequent policy / ability checks. This covers Super Admin today and any
future system roles (Owner, Support, etc.) without code changes."*

`config/permissions.php` documents the same flag. `TenantOwnershipResolver::isUnrestricted()`
independently implements it as the sole grant of cross-company access.

| Actor class | Definition | Cross-company authority |
|---|---|---|
| **A — Company Administrator** | Non-system role carrying `iam.users.*`; `company_id` set | **Must be none** (currently unlimited — §9) |
| **B — System / Global Administrator** | Any role with `is_system = true` | **Yes — explicitly contracted** |

**A null `company_id` is NOT a global-actor signal.** `TenantOwnershipResolver` rejects that inference
by name. (`ScopeResolver` disagrees for data scope — §5.) Part 21.2 and 21.9 are therefore **not**
triggered: global semantics are defined, and no new role is needed.

---

## 9 — Security Matrix (Part 8)

Actual runtime results through the real Gate → UserPolicy → PermissionService path.

| # | Actor | Target | Permission | **Current** | **Expected** | Evidence |
|---|---|---|---|---|---|---|
| 1 | Company A admin | Company A user | `reset-password` | **ALLOWED** | ALLOWED | Case A |
| 2 | Company A admin | Company B user | `reset-password` | **ALLOWED** | **DENIED** | Case B |
| 3 | Company B admin | Company A user | `reset-password` | **ALLOWED** | **DENIED** | Case C |
| 4 | System actor (`is_system`, no company) | Company A user | `reset-password` | **ALLOWED** | ALLOWED — explicitly permitted by `Gate::before` | Case D |
| 5 | Company A admin | null / unresolvable target | any | **n/a** | DENIED | See note |
| 6 | Company A admin | soft-deleted Company B user | `reset-password` | **ALLOWED** | **DENIED** | Case F |
| 7 | Company A admin | **Super Administrator in Company B** | `reset-password` | **ALLOWED** | **DENIED** | Case E |

**Row 4 is the only ALLOWED that the existing architecture explicitly sanctions** — `Gate::before` is
a documented contract, not an inference, so it is not marked UNSPECIFIED.

**Row 5:** the policy is never reached with a null target — Laravel's Gate requires an instance for a
model-targeted ability. Target resolution happens before authorization, so "invalid target" is a
404/`ModelNotFoundException` concern of the (non-existent) HTTP layer, not a policy outcome. Recorded
as **not-applicable at the policy layer**, and as a requirement on the future controller.

**Rows 2, 3, 6, 7 are security defects**: the Expected column follows from the task's own success
condition ("target state must be NO unless the actor is an already-defined legitimate global
administrator"), not from an invented policy.

---

## 10 — Runtime Evidence (Parts 9, 10)

**Environment (Part 9 precondition satisfied before any DB access):**

```
app.env           = testing
config db         = ecos_dev_test
live connection   = ecos_dev_test
SELECT DATABASE() = ecos_dev_test
config cached     = no
reachable databases: ecos_dev, ecos_dev_test, information_schema, performance_schema
```

`ecos_erp` and `ecos_erp_test` are **not reachable** from this connection. MAIN was not touched.

**Probe integrity.** `$grantsBaselineAuthorization = false` was set. This is essential and easy to get
wrong: `TestCase::actingAs()` grants an `is_system` role to any role-less user, and `is_system` is
precisely the flag that bypasses every check — leaving it enabled would have handed each probe subject
the global authority being measured and produced false ALLOWED results throughout.

**Internal control proving the probe is real:** an actor granted *only* `iam.users.assign-role` was
**DENIED** `resetPassword` on the same cross-company target. Permission granularity still functions;
the ALLOWED results are genuine boundary failures, not a blanket true.

```
═══ IAM TENANT BOUNDARY — reset-password ═══
  A  company-A admin -> company-A user               -> ALLOWED
  B  company-A admin -> company-B user               -> ALLOWED
  C  company-B admin -> company-A user               -> ALLOWED
  D  system actor (no company) -> company-A user     -> ALLOWED
  E  company-A admin -> SUPER ADMIN in company B     -> ALLOWED
  F  company-A admin -> soft-deleted company-B user  -> ALLOWED
  G  company-A admin -> self                         -> ALLOWED

═══ ALL iam.users.* ABILITIES: company-A admin -> company-B user ═══
  view, update, delete, activate, suspend,
  assignRole, assignOrganization, invite,
  resetPassword, manageSessions                      -> ALLOWED (10 of 10)

═══ ROLE ASSIGNMENT BOUNDARY ═══
  assign-role -> company-B user                      -> ALLOWED
  assign-role -> company-B SUPER ADMIN               -> ALLOWED
  reset-password (NOT granted) -> company-B user     -> DENIED   ← control
```

`OK (6 tests, 28 assertions)`. The probe asserts only fixture preconditions and the one
architecturally-guaranteed fact (system-role bypass); it deliberately does **not** assert the insecure
current results, which would cement them.

---

## 11 — Privilege Escalation Evidence (Part 11)

**Case E: a Company A administrator is authorized to reset the password of a Super Administrator in
Company B.** Confirmed at runtime. No password was changed — only the authorization decision was
probed, using disposable fixtures.

Chained with `assign-role → Company B super admin → ALLOWED`, the practical consequence is full
platform takeover from any tenant administrator account:

```
Company A admin (non-system role, iam.users.* granted)
  → authorized against a Company B Super Administrator
  → reset that account's password  → authenticate as a system-role holder
  → Gate::before() then bypasses every ability check platform-wide
```

**Mitigating factor, stated for accuracy:** `RoleTemplateCompiler:94` creates compiled roles with
`'is_system' => false` **explicitly**, so assigning a role template cannot itself confer the
`Gate::before` bypass. The escalation route is therefore *targeting an existing system-role holder*,
not *minting* one. That protection is real and should be preserved.

---

## 12 — User Management Cross-Company Analysis (Part 12)

The gap is uniform — **10 of 10 abilities cross the boundary**, so the fix must not be designed for
reset-password alone.

**CREATE is a distinct case and a distinct defect.** `create` takes no target, so the policy cannot be
the control point. `UserIdentityService::createDraft()` fills from:

```php
private const IDENTITY_FIELDS = [
    'name','display_name','email','username','employee_number','phone','avatar_path','company_id',
];
```

**`company_id` is client-suppliable and unvalidated against the actor.** A Company A administrator can
create a user directly into Company B.

This is structurally identical to **RC-6**, already fixed elsewhere in this platform: *"the create path
took `company_id` from the client payload, while every read path took it from the authenticated user."*
The required rule follows from that precedent rather than from invention: **a new user must belong to
the actor's company, and only an `is_system` actor may specify a different one.**

---

## 13 — Role Assignment Analysis (Part 13)

`UserRoleAssignmentService::assignTemplate(User $user, RoleTemplate|string $template, bool $primary, ?int $actorId)`:

| Check | Present? |
|---|---|
| Target user's company | **No** |
| Template's company | **No** — templates have no company |
| Actor's authority to assign this template | **No** — `$actorId` is used only to stamp `assigned_by` for audit |
| `is_system` template protection | **No explicit guard**, but see mitigation below |

`removeTemplate()` has no authorization either, and no `revoke-role` permission exists in the catalog.

**Mitigation (important, and it holds):** `RoleTemplateCompiler` forces `'is_system' => false` on every
compiled role. Template assignment therefore grants *permissions*, never the `Gate::before` bypass.

**Residual risk:** a template whose `definition` contains `iam.users.*` permissions can still be
assigned to a user in another company, propagating the boundary defect. Tenant isolation of user
management would be incomplete without covering role assignment — exactly as Part 13 anticipates.

---

## 14 — RoleTemplate Analysis (Part 14)

| Entity | Company ownership | Evidence |
|---|---|---|
| `Permission` | Global | catalog in `config/permissions.php` |
| `Role` | **Global** | `roles`: `id, name, slug, description, is_system` — no `company_id` |
| `RoleTemplate` | **Global** | `role_templates`: `key, name, category, status, version, is_system, is_composable, definition, role_id` — no `company_id` |
| `user_roles` (assignment) | **Company-capable** | `company_id`, `branch_id`, `warehouse_id` — nullable, indexed, currently unread |
| `User` | Company-capable | `users.company_id` — nullable, FK, indexed, unenforced |

**Ownership belongs on the *assignment* and on the *User*, not on Role or RoleTemplate.** That is
already the schema's design: roles and templates are global definitions; scope attaches when a role is
granted to a user. This is a coherent model and needs no redesign.

**Smallest consistent enforcement layer:** the **actor↔target company relationship**, evaluated where
a target is known — i.e. the policy — backed by making cross-company targets hard to obtain at the
model layer. Both are already available through `TenantOwnershipResolver`.

---

## 15 — Architectural Options (Part 15)

`App\Core\Company\TenantOwnershipResolver` is the pivot for every option. It already provides:

```php
public function owns(?string $companyId): bool      // isUnrestricted() || companyId === own
public function isUnrestricted(): bool              // userHasSystemRole() — and ONLY that
public function appliesTo(): bool                   // Auth::check() — console/queue/migrations exempt
```

Already consumed by `Order`, `Warehouse`, `Supplier`, `EloquentProductRepository`, `ProductController`,
`StoreWarehouseRequest`. **`User` is the only tenant-owned entity not wired in.**

| | **A — Policy target check** | **B — Scope-aware PermissionService** | **C — Repository/model scoping** | **D — Combined (A + C)** |
|---|---|---|---|---|
| Security coverage | All 10 target-taking abilities | All permission checks | All reads incl. route-model binding | Both layers |
| Operations covered | Not `create` (no target) | Depends on caller passing scope | Not `create` payload | + `create` via request rule |
| Bypass risk | Controller could skip `authorize()` | **High** — every caller must remember to pass `companyId`; the 3 args are optional and currently ignored | Explicit `withoutGlobalScope()` | Low — two independent layers |
| Compatible with `BasePolicy`? | Yes — add a protected helper | Would change `can()` signature or add a variant | Untouched | Yes |
| Compatible with `PermissionService`? | Untouched | Requires implementing `userHasPermissionInScope()` + reading `user_roles.company_id` | Untouched | Untouched |
| Compatible with `UserRepository`? | Untouched | Untouched | Global scope makes it tenant-safe automatically | Yes |
| Effect on system/global admins | `isUnrestricted()` short-circuits | Must re-implement bypass | `isUnrestricted()` short-circuits | Correct in both layers |
| Implementation surface | `UserPolicy` (+ tiny `BasePolicy` helper) | `PermissionService`, every call site, scope plumbing | `User::booted()` | `UserPolicy` + `User::booted()` (+ a create-time rule) |
| Duplicates authorization logic? | No — delegates to the single authority | Risk of a second scope engine beside `ScopeResolver` | No | No |

**Option B (scope-aware PermissionService) is not recommended** despite matching the dormant signature:
it makes correctness depend on every future caller remembering to pass `$companyId` into an argument
that is optional and currently ignored — a fail-open design — and risks a second scope engine
competing with `ScopeResolver`.

---

## 16 — Recommended Enforcement Layer

**Option D — policy check plus model-layer scoping, both delegating to `TenantOwnershipResolver`.**

This satisfies Part 16's requirement that the boundary survive a badly-written controller:

- **Model layer** — a `tenant` global scope on `User`, mirroring `Warehouse::booted()` verbatim
  (`appliesTo()` → `isUnrestricted()` → `where('company_id', …)`). A Company B user becomes
  *unresolvable* from a Company A request, so route-model binding and `User::findOrFail()` yield 404
  before authorization is even reached.
- **Policy layer** — each target-taking ability additionally asserts
  `TenantOwnershipResolver::owns($target->company_id)`. Even if a controller hands the policy a
  Company B `User` object obtained some other way, the ability is denied.

Neither layer re-implements authorization: both delegate to the single declared authority, and the
`is_system` global-actor contract is honoured automatically in both.

> **Implementation risk that must be assessed by the implementing task, not assumed away.** `User` is
> not an ordinary tenant entity — it backs authentication, Sanctum token resolution, queue workers and
> `Auth::user()` itself. `appliesTo()` returning false when unauthenticated protects console,
> seeders and migrations, and login runs unauthenticated so it is unaffected. But behaviours such as an
> actor loading their own record, system-role holders listing users across companies, and any
> `whereHas('users')` relation traversal need explicit verification. If the global scope proves unsafe
> on `User`, **Option A alone still satisfies Part 16's stated requirement** and is the fallback.

---

## 17 — Password Reset Dependency (Part 18)

`TASK-IAM-PASSWORD-RESET-CONTRACT-001` concluded a **new domain operation** is required. This audit
supplies the missing half of its authorization contract:

- The future `AdminPasswordResetService` must be reachable **only** behind
  `UserPolicy::resetPassword($actor, $target)` **with** the target-company check of §16.
- Runtime evidence here proves that without it, the operation would be exploitable on day one — Case E
  shows the policy currently authorizes a Company A admin against a Company B Super Administrator.
- The tenant boundary should therefore land **before or together with** password reset. Shipping reset
  first would create the most dangerous instance of this defect.
- One of the three items that audit recorded as UNSPECIFIED — the tenant boundary — is now **resolved**
  in mechanism (`TenantOwnershipResolver`), though the decision to adopt it remains the user's.

---

## 18 — HTTP Surface Impact (Part 17)

For `TASK-IAM-HTTP-SURFACE-001` (fully greenfield — only `AuthController`/`LoginRequest` exist, zero
`iam/users` routes registered):

| Endpoint | Actor company | Target company | Global exception | Repository scope | Role/template scope |
|---|---|---|---|---|---|
| `GET /iam/users` | ✔ | n/a | `is_system` | **required** | — |
| `POST /iam/users` | ✔ | **payload `company_id` must be constrained** (§12) | `is_system` | — | — |
| `GET /iam/users/{id}` | ✔ | ✔ | `is_system` | **required** | — |
| `PATCH /iam/users/{id}` | ✔ | ✔ | `is_system` | **required** | — |
| `DELETE /iam/users/{id}` | ✔ | ✔ | `is_system` | **required** | — |
| `POST /iam/users/{id}/activate` · `/suspend` | ✔ | ✔ | `is_system` | **required** | — |
| `POST /iam/users/{id}/roles` | ✔ | ✔ | `is_system` | **required** | **✔ — §13** |
| `POST /iam/users/{id}/invite` | ✔ | ✔ | `is_system` | **required** | — |
| `POST /iam/users/{id}/reset-password` | ✔ | ✔ | `is_system` | **required** | — |
| `POST /iam/users/{id}/sessions/revoke` | ✔ | ✔ | `is_system` | **required** | — |

Every target-taking endpoint requires the same boundary — which is the argument for enforcing it once,
structurally, rather than per-controller.

---

## 19 — Schema Impact (Part 19)

**No schema change is required. Part 21.7 is not triggered.**

The current schema can already represent the contract in full:

- `users.company_id` — uuid, nullable, FK → `companies`, indexed
- `user_roles.company_id` / `branch_id` / `warehouse_id` — nullable, indexed, already provisioned
  *"for future scoped RBAC"*
- `roles.is_system`, `role_templates.is_system` — the global-actor flag, indexed

No `tenant_id`, pivot, index, scope table or migration is needed.

---

## 20 — Production Changes

**None.** No policy, PermissionService, repository, model, migration, controller, route, request,
service or permission-catalog change. Tracked diff unchanged at
`9 files changed, 326 insertions(+), 31 deletions(-)`.

---

## 21 — Test Changes

**Added (test-only, permitted by Part 20):**
`backend/tests/Feature/IAM/UserManagementTenantBoundaryProbeTest.php` — 3 tests, 28 assertions,
Pint clean. It characterises current behaviour and asserts only fixture preconditions plus the
documented system-role bypass; it does **not** assert the insecure outcomes.

No existing test was modified.

---

## 22 — Final Decision

> ### B — THE EXISTING ARCHITECTURE CAN ENFORCE THE COMPANY BOUNDARY WITH A SMALL AUTHORIZATION CHANGE
>
> **Enforcement point:** `App\Core\Company\TenantOwnershipResolver` — the platform's declared single
> authority for tenant ownership — invoked from two places:
>
> 1. **`UserPolicy`** — every target-taking ability additionally requires
>    `owns($target->company_id)`.
> 2. **`App\Models\User::booted()`** — a `tenant` global scope mirroring `Warehouse`, so a foreign
>    user is unresolvable rather than merely unauthorized.
>
> Plus, for `create`, a request-level constraint on the client-supplied `company_id`, following the
> RC-6 `StoreWarehouseRequest` precedent.
>
> **Why B and not C:** the tenant component, the global-actor contract, the schema columns and the
> enforcement precedent all already exist and are in production use on four other entities. Nothing
> new is required — `User` simply needs to be wired in.

**No new global privilege was invented.** The `is_system` bypass is pre-existing and documented in
three independent places.

---

## 23 — Implementation Task Recommendation

Recommended scope for the next (separately authorised) task — **not started here**:

1. Wire `User` into `TenantOwnershipResolver` at both layers (§16), including the risk assessment
   flagged there before the global scope is adopted.
2. Constrain client-supplied `company_id` on user creation (§12).
3. Extend the boundary to role assignment (§13), and decide whether `removeTemplate()` needs a
   `revoke-role` ability — the catalog currently has none.
4. Ship this **before or with** the password-reset domain operation (§17).

**Security tests the implementation task must include** (this probe is the ready-made harness — flip
it from recording to asserting):

- Company A admin → Company B user **DENIED** for all 10 abilities
- Company B admin → Company A user **DENIED**
- Company A admin → Super Administrator in Company B **DENIED**
- `is_system` actor → any company **ALLOWED** (contract preserved)
- Company A admin → soft-deleted Company B user **DENIED**
- Company A admin creating a user with `company_id = B` **REJECTED**
- Company A admin → own-company user **ALLOWED** (no over-blocking)
- Cross-company target unresolvable → 404, not 403 (proves model-layer scoping)
- Unauthenticated console/seeder execution unaffected (`appliesTo()` false)
- An actor lacking the permission is still denied within their own company (granularity intact)

**Two open decisions for the user, not made here:** whether to adopt the `User` global scope given the
authentication-path risk (§16), and whether the `ScopeResolver` null-company inconsistency (§5) should
be reconciled as part of this work or tracked separately.

**Per the final rule, work stops here.** The tenant boundary was not implemented, password reset was
not implemented, no IAM HTTP surface was built, and Shipping, Preparation and Reservation were not
touched.

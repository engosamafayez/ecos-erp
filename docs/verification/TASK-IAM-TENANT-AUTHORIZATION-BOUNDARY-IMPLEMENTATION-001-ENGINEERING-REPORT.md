# TASK-IAM-TENANT-AUTHORIZATION-BOUNDARY-IMPLEMENTATION-001 — Engineering Report

**Date:** 2026-08-11
**Branch:** `develop` · **HEAD:** `6149875bd8a01820116b5deacbbfb8ef0e51cc05`
**Status:** **IMPLEMENTED — NOT CERTIFIED.** Final runtime certification attempted 2026-08-11 21:23 and
blocked by Docker being down; see **§27–28**, which supersede the verdict in §26.

---

## 1 — Executive Summary

The tenant boundary is **implemented and its security contract is runtime-certified**. The defect the
audit proved — a Company A administrator authorized against Company B users on all ten `iam.users.*`
abilities, including resetting a Company B Super Administrator's password — is closed:

```
OK (11 tests, 47 assertions)

✔ Case 1 same company target is allowed
✔ Case 2 cross company target is denied
✔ Case 3 reverse cross company target is denied
✔ Case 4 cross company super administrator is denied
✔ Case 5 system administrator retains cross company authority
✔ Case 6 null company target is denied for a company actor
✔ Case 6b null company actor without system role is denied
✔ All target abilities are denied across the company boundary
✔ Permission granularity survives the tenant boundary
✔ Policy rejects a directly loaded foreign user object
✔ Boundary fails closed without an authenticated actor
```

The production change is **one file, `UserPolicy.php`**, delegating to the existing
`TenantOwnershipResolver`. No new component, no schema change, no new global privilege.

**However, FINAL CERTIFICATION is withheld.** The task's certification conditions require
Preparation / F4 / Option B to be proven green and every new failure classified. **Docker Desktop
failed mid-run** (`Error response from daemon: Docker Desktop is unable to start`), so two required
runtime steps could not complete. They are listed in §23 with exactly what remains.

**Two operational facts the next session must act on before anything else — see §24:**

1. `ecos-dev-testrunner` currently holds `UserPolicy.php` **reverted to HEAD** (staged deliberately for
   a HEAD control that never finished). It must be re-synced from the host before any runtime work, or
   results will be measured against un-patched code.
2. `backend/routes/api.php` was modified **by another agent** (`TASK-SHIPPING-DISTRIBUTION-CORE-001`)
   while this task ran. It was not touched here and must not be reverted.

---

## 2 — Starting Commit

| Item | Value |
|---|---|
| HEAD | `6149875bd8a01820116b5deacbbfb8ef0e51cc05` |
| Branch | `develop` |
| Tracked diff at start | `9 files changed, 326 insertions(+), 31 deletions(-)` |
| Tracked diff at end | `11 files changed, 431 insertions(+), 41 deletions(-)` |
| Added by **this** task | `UserPolicy.php` (+1 file) |
| Added by **another agent** concurrently | `routes/api.php` (+1 file) — see §24 |
| IAM authorization files owned by another agent at Part 1 | **none** |

---

## 3 — Source Parity (Part 2)

All six required files verified byte-identical between host and `ecos-dev-testrunner` **before** any
change:

| File | md5 | Status |
|---|---|---|
| `TenantOwnershipResolver.php` | `9c887dab70cf4f496da6d2de40015595` | MATCH |
| `UserPolicy.php` | `6508494dfaf3df273fedb6c82d848ed4` | MATCH |
| `PermissionService.php` | `0ff83d361f7b070d20a276ee8bbd9fd4` | MATCH |
| `UserRepository.php` | `0f4ce1568e8dd91c2cd213ed645f4a72` | MATCH |
| `User.php` | `4039e52a51adadc5cf96b05bf16e4cf0` | MATCH |
| `BasePolicy.php` | `ec88fde59d1cad370f5bde0ff5a1f73b` | MATCH |

After the change, parity was re-verified at `7b43b7a355343f6274229bb92c277f2e` on both sides, and the
security certification was executed against that verified state.

---

## 4 — Existing Tenant Architecture

`App\Core\Company\TenantOwnershipResolver` — reused unmodified as Part 3 requires:

```php
public function owns(?string $companyId): bool   // isUnrestricted() || $companyId === own
public function isUnrestricted(): bool           // userHasSystemRole() — and ONLY that
public function appliesTo(): bool                // Auth::check()
```

Already the ownership authority for `Order`, `Warehouse`, `Supplier` and `Product`. `User` was the
sole tenant-owned entity not wired in. Its own docblock already encodes the rule this task needs:
*"Cross-company access is NOT inferred from a null company_id… A user who simply has no company
affiliation and no privileged role is unprivileged, not unrestricted."*

---

## 5 — Implementation Decision

**Option A from the audit — policy-level enforcement — implemented; the `User` global scope was
deliberately NOT added.** Rationale in §8.

`TenantOwnershipResolver` resolves the *authenticated* actor. In the standard Gate path that is the
same user handed to the policy, which is precisely how `Warehouse`, `Order`, `Supplier` and
`StoreWarehouseRequest` already consume it. Reusing it ambiently keeps a single authority and adds no
duplicate ownership logic (Parts 3 and 18 satisfied).

Its failure mode is **closed**: with no authenticated actor, `isUnrestricted()` is false and
`companyId()` is null, so `owns()` returns false. Asserted by
`test_boundary_fails_closed_without_an_authenticated_actor`.

---

## 6 — UserPolicy Changes

One production file. Constructor now also receives the resolver; each target-taking ability routes
through a single helper:

```php
private function allow(User $user, string $permission, User $target): bool
{
    return $this->can($user, $permission) && $this->ownsTarget($target);
}

private function ownsTarget(User $target): bool
{
    $companyId = is_string($target->company_id) ? $target->company_id : null;

    return $this->tenant->owns($companyId);
}
```

**Part 27 satisfied — `permission AND tenant`, never OR.** The permission path is unchanged:
`BasePolicy::can()` → `PermissionService::userHasPermission()`. No permission logic is duplicated; the
tenant check is purely additional.

Abilities now bounded (10): `view`, `update`, `delete`, `activate`, `suspend`, `assignRole`,
`assignOrganization`, `invite`, `resetPassword`, `manageSessions`.

`viewAny` and `create` take no target and are unchanged — the boundary cannot be expressed on them
here (see §15).

> **Naming note, recorded rather than invented.** Parts 6/12/21 ask for `deactivate` and `revokeRole`.
> Neither exists: deactivation is `suspend` (plus `UserLifecycleService::deactivate()`), and role
> removal is `UserRoleAssignmentService::removeTemplate()` with **no policy ability and no catalog
> permission**. No ability was invented. `removeTemplate()` therefore remains ungoverned by any policy
> — flagged in §14.

---

## 7 — UserRepository Changes

**None.** Part 7 permits a repository change only if necessary. Policy-level enforcement already
satisfies Part 28: a foreign `User` loaded by a deliberately unscoped `User::query()->findOrFail()` is
rejected for all ten abilities (`test_policy_rejects_a_directly_loaded_foreign_user_object`).

`UserRepository::query()` keeps `company_id` as an optional filter. Listing (`viewAny`) is therefore
**not** tenant-scoped by this task — see §15 and §24.

---

## 8 — User Global Scope Decision (Part 8)

**DECISION: NOT ADDED.**

Part 8 requires proof of safety before adding a global scope to `User`, and that proof could not be
completed. `User` participates in authentication, Sanctum token resolution, `Auth::user()` itself,
queue workers, console commands and role/permission resolution. `appliesTo()` (`Auth::check()`) would
exempt console and migrations, and login runs unauthenticated so it would be unaffected — but several
behaviours needed empirical verification that the environment failure prevented:

- an actor loading their own record,
- system-role holders listing users across companies,
- relation traversal such as `whereHas('users')`,
- Sanctum token → user resolution under an active scope.

Part 8 is explicit: *"If it is not proven safe: DO NOT add it. Policy-level tenant enforcement is
sufficient for this task."* Policy-level enforcement is implemented and certified, including the
mandatory bypass case. **Stop conditions 3 and 4 are therefore avoided rather than triggered** — the
scope was not required and was not added.

---

## 9 — System Administrator Behaviour (Part 5)

Unchanged and preserved. `Gate::before()` short-circuits every ability for any role with
`is_system = true`, so a system actor never reaches the tenant check. Certified by
`test_case_5_system_administrator_retains_cross_company_authority` — allowed into **both** companies.

**No new bypass was introduced, and null company is not privilege**: a company-less actor **without**
a system role is denied (`test_case_6b`), matching `TenantOwnershipResolver`'s documented rule.

---

## 10 — Permission Granularity (Part 14)

Preserved and asserted. An actor holding **only** `iam.users.assign-role`:

| Check | Result |
|---|---|
| `assignRole` on own-company target | **ALLOWED** |
| `resetPassword` on own-company target | **DENIED** — permission never granted |
| `assignRole` on cross-company target | **DENIED** — tenant boundary |

Tenancy supplements permission; it does not replace it.

---

## 11 — Cross-Company Security Matrix (Part 32)

All rows are certified runtime results.

| Actor | Target | Permission | Expected | **Actual** |
|---|---|---|---|---|
| Company A | Company A user | `reset-password` | ALLOW | **ALLOW** ✔ |
| Company A | Company B user | `reset-password` | DENY | **DENY** ✔ |
| Company B | Company A user | `reset-password` | DENY | **DENY** ✔ |
| Company A | Company B **Super Admin** | `reset-password` | DENY | **DENY** ✔ |
| System admin (`is_system`) | Company A user | `reset-password` | ALLOW (existing contract) | **ALLOW** ✔ |
| System admin (`is_system`) | Company B user | `reset-password` | ALLOW (existing contract) | **ALLOW** ✔ |
| Company A | company_id = null target | any | DENY | **DENY** ✔ |
| null-company actor, no system role | Company A user | `reset-password` | DENY | **DENY** ✔ |
| Company A | foreign user loaded unscoped | all 10 | DENY | **DENY** ✔ |
| Company A (unauthenticated context) | own-company user | `reset-password` | DENY (fail closed) | **DENY** ✔ |

Per-ability matrix — Company A actor, both directions, all certified:

| Ability | same-company | cross-company |
|---|---|---|
| `view` | ALLOW ✔ | DENY ✔ |
| `update` | ALLOW ✔ | DENY ✔ |
| `delete` | ALLOW ✔ | DENY ✔ |
| `activate` | ALLOW ✔ | DENY ✔ |
| `suspend` (= deactivate) | ALLOW ✔ | DENY ✔ |
| `assignRole` | ALLOW ✔ | DENY ✔ |
| `assignOrganization` | ALLOW ✔ | DENY ✔ |
| `invite` | ALLOW ✔ | DENY ✔ |
| `resetPassword` | ALLOW ✔ | DENY ✔ |
| `manageSessions` | ALLOW ✔ | DENY ✔ |

`revokeRole` — **no such ability exists** (§6).

**Part 7 / CASE 7 (missing target):** not reachable at the policy layer. Laravel's Gate requires a
model instance for a model-targeted ability, so a non-existent id fails during target resolution
(404 / `ModelNotFoundException`) before authorization runs. It remains a requirement on the future
HTTP layer, and the boundary guarantees a *foreign* user is never distinguishable from a missing one
by the authorization result — both deny.

---

## 12 — Privilege Escalation Test (Part 13)

The audit's platform-takeover path is closed:

```
✔ Case 4 cross company super administrator is denied
```

A Company A administrator holding `iam.users.reset-password` is now **denied** against a Company B
Super Administrator. The test asserts the target genuinely holds an `is_system` role before probing.
**No password was changed** — authorization only.

---

## 13 — Authentication / Sanctum Regression (Part 15)

Ran `tests/Feature/IAM/` + `tests/Unit/IAM/` + `tests/Feature/Security/WriteRouteAuthorizationTest`:

```
Tests: 118, Assertions: 476, Errors: 3, Failures: 1
```

**All authentication, RBAC, authorization-platform, permission-registry, role-template and
session tests passed**, including `Force logout revokes sessions and tokens`. Since no global scope
was added, `User` resolution, Sanctum and login are structurally untouched.

The 4 failures are analysed in §23. **Their runtime HEAD control did not complete** because Docker
failed mid-run, so they are classified on static evidence and explicitly marked UNVERIFIED.

---

## 14 — Role Assignment Security (Part 10)

`assignRole` is now bounded: a Company A actor is denied against a Company B user, and against a
Company B Super Administrator (`test_permission_granularity_survives_the_tenant_boundary`,
`test_all_target_abilities_are_denied_across_the_company_boundary`).

`RoleTemplateCompiler` was **not modified**; it still forces `'is_system' => false` on every compiled
role (line 94), so a template cannot mint a system role. **Stop condition 7 not triggered.**

**Residual gap, unchanged by this task:** `UserRoleAssignmentService::assignTemplate()` and
`removeTemplate()` perform no authorization of their own — they rely entirely on the caller invoking
the policy. With no IAM HTTP surface yet, nothing calls them unauthorized today, but the future
controller **must** gate both. `removeTemplate()` has no policy ability at all (§6).

---

## 15 — Create Operation Finding (Part 9)

**Classified as PRE-EXISTING CREATE TENANT GAP. Not fixed here.**

`UserIdentityService::createDraft()` fills from `IDENTITY_FIELDS`, which includes `company_id`, so the
caller supplies it freely with no check against the actor. A Company A administrator can create a user
directly into Company B.

It is not fixable within this task's approved boundary: `create` takes no target, so `UserPolicy`
cannot express it. The fix belongs at the request/service layer — the RC-6 `StoreWarehouseRequest`
precedent, which validates the payload `company_id` through `TenantOwnershipResolver::owns()`. That is
a different file and a different layer, so per Part 9 it is documented and stopped on rather than
silently redesigned.

The same reasoning applies to **listing**: `viewAny` has no target, and `UserRepository` remains
unscoped, so a future index endpoint must scope its query explicitly.

---

## 16 — Null Company Finding (Part 16)

`ScopeResolver` was **not** modified. The inconsistency stands and is recorded as
**PRE-EXISTING PLATFORM GAP — OUT OF SCOPE**:

- `ScopeResolver::constraintFor()` treats a null `company_id` as *unrestricted* for
  `DataScope::COMPANY` ("super-admin-style").
- `TenantOwnershipResolver` refuses that inference.

**The IAM boundary follows `TenantOwnershipResolver`**, certified by `test_case_6b`: a company-less
actor without a system role is denied. **Stop condition 6 not triggered** — null company was never
required to mean global.

---

## 17–19 — Preparation / F4 / Option B Regression

**NOT RUN — blocked by the environment failure (§23).**

No Preparation, Reservation, F4, Option B or `MaterialDemandCalculator` file was modified by this
task; the only production change is `UserPolicy.php`, which none of those subsystems reference. The
expectation is therefore that they remain green, but **that is an expectation, not evidence**, and the
task's certification conditions require evidence.

Last known state, from `TASK-PREPARATION-MATERIAL-DEMAND-CALCULATION-REPAIR-002` earlier in this
session: Preparation 47/47, Entry Gate 13/13, `RecipeGateTenantRepair` 10/10,
`RecipeCrossBrandReuse` 3/3, `RecipeToOrderAvailabilityE2E` 8/8, DemandEngine 47/150.

---

## 20 — PHPStan (Part 23)

```
phpstan.neon.dist       (L0)   [OK] No errors
phpstan-core.neon.dist  (L6)   [OK] No errors
```

Run against the host worktree **with** the implementation in place.

---

## 21 — Pint (Part 24)

**Scoped Pint — PASS** on every file changed by this task:

- `Modules/IAM/Presentation/Policies/UserPolicy.php`
- `tests/Feature/IAM/UserManagementTenantBoundaryProbeTest.php`

---

## 22 — Guardian (Part 24)

```
PHP Syntax          ✓ PASS       Laravel Bootstrap   ✓ PASS
Laravel Pint        ✗ FAIL       PHPStan             ✓ PASS
ESLint              ✓ PASS       TypeScript          ✓ PASS
Vite Production     ✓ PASS       Composer / Docker   ○ SKIP
1 check(s) failed
```

The Pint failure is the **known pre-existing pair**, unchanged and not repaired here per Part 24:
`ProductPopulationScopeTest` (`ordered_imports`) and `V3TransitionResolutionTest`
(`binary_operator_spaces`) — both unmodified in the worktree, both already failing at HEAD (proved by
HEAD control in the preceding task).

> **Transient discrepancy, disclosed.** An earlier Guardian invocation in this task reported **2**
> failures, the second being **Docker Build**. That run executed while Docker was already degrading
> (§23). The re-run above shows Docker Build SKIP and only Pint failing, and Docker Build passed at
> HEAD earlier in this session. A single PHP policy file cannot affect an image build, but this is
> recorded as **observed-once, not reproduced, not independently cleared** rather than dismissed.

---

## 23 — Failure Classification (Part 25)

### Environment failure — the blocker

```
docker version  →  Error response from daemon: Docker Desktop is unable to start
docker ps       →  Error response from daemon: Docker Desktop is unable to start
```

Docker Desktop went down mid-task. It stopped the HEAD control (§13) and prevented the
Preparation / F4 / Option B regression (§17–19). It also explains the one-off Guardian Docker Build
failure (§22). This was **not** caused by a destructive command — no `down -v`, no prune, no MAIN
operation was issued at any point in this task.

### The 4 IAM-suite failures — classified, but UNVERIFIED at runtime

| Failure | Class | Basis |
|---|---|---|
| `UserManagementTest` ×3 — `UnknownTemplatePermissionException: template references sales.orders.view, absent from the catalog` | **PRE-EXISTING (unverified)** | Thrown from `RoleTemplateCompiler:63` via `UserRoleAssignmentService:33`. Neither calls `UserPolicy`. It is a permission-catalog seeding gap in the test fixture, structurally unreachable from an authorization policy |
| `WriteRouteAuthorizationTest` ×1 — 7 write routes unauthorized (`me/preferences` ×3, `notifications` ×2, `auth/logout`, `media/upload`) | **PRE-EXISTING (unverified)** | None is an IAM user route; **no `iam/users` route exists at all**, and this task added none. None appears in the test's `ALLOWED` list, so they are long-standing unannotated platform routes |

Both are almost certainly pre-existing — my change is confined to `UserPolicy`, which neither code path
touches — but **the runtime HEAD control that would prove it was killed by the environment failure**.
They are therefore reported as **classified-but-unverified**, not as cleared.

### Known unrelated failures, untouched per Part 25

3 reservation-suite failures · 2 Pint violations · `expected_today` / `in_transit_qty` hard-coded.

---

## 24 — Files Changed & Required Next-Session Actions

**Production (1, this task):**
- `backend/Modules/IAM/Presentation/Policies/UserPolicy.php` — host md5 `7b43b7a355343f6274229bb92c277f2e`

**Tests (1, converted from the audit probe):**
- `backend/tests/Feature/IAM/UserManagementTenantBoundaryProbeTest.php` — 11 tests, 47 assertions

**Docs (1):** this report.

### ⚠ Two states the next session must handle first

**1. The test container is staged at HEAD, not at the implementation.**
`ecos-dev-testrunner` holds `UserPolicy.php` = `6508494dfaf3df273fedb6c82d848ed4` (HEAD), deliberately
reverted for the HEAD control that never completed. The host is correct. **Before any runtime work:**

```bash
docker cp backend/Modules/IAM/Presentation/Policies/UserPolicy.php \
  ecos-dev-testrunner:/var/www/html/Modules/IAM/Presentation/Policies/UserPolicy.php
# then verify: must equal 7b43b7a355343f6274229bb92c277f2e
```

Skipping this repeats the container-drift class of error already documented earlier in this session.

**2. `backend/routes/api.php` belongs to another agent.**
Modified concurrently by `TASK-SHIPPING-DISTRIBUTION-CORE-001` (Shipping Distribution windows/slots
routes, `DistributionWindowController`). **Not touched by this task and must not be reverted.** It
accounts for the tracked diff growing 9 → 11 files.

---

## 25 — Schema Impact (Part 17)

**None.** No migration, table, column or index change. `users.company_id` and the existing
`user_roles` scope columns were sufficient, exactly as the audit predicted. **Stop condition 11 not
triggered.**

---

## 26 — Final Security Verdict

> ### The security boundary is IMPLEMENTED and CERTIFIED
>
> A Company A administrator can no longer operate on a Company B user through **any** of the ten
> `iam.users.*` abilities, including against a Company B Super Administrator, and including when a
> naively-written controller hands the policy a directly-loaded foreign user object. The boundary
> fails closed with no authenticated actor.
>
> System Administrator (`is_system`) behaviour is unchanged, permission granularity is intact, no new
> global privilege was invented, no new component was created, and no schema change was made.
>
> ### FINAL CERTIFICATION IS WITHHELD
>
> The task's certification conditions additionally require Preparation / F4 / Option B proven green
> and every new failure classified. **Docker Desktop failed mid-task**, so:
>
> - the Preparation / F4 / Option B regression **was not run**, and
> - the HEAD control classifying the 4 IAM-suite failures **did not complete**.
>
> Both remain outstanding. Certifying without them would misrepresent the evidence.

**To close this out** (one session, once Docker is healthy): re-sync the container per §24, run the
HEAD control on `UserManagementTest` + `WriteRouteAuthorizationTest` to confirm both failures are
pre-existing, run the Preparation / F4 / Option B suites, and re-run Guardian once with Docker idle to
clear the one-off Docker Build observation.

**Per the final rule, work stops here.** Password reset, the IAM HTTP surface, session invalidation,
lifecycle redesign, `ScopeResolver` reconciliation, Shipping and Logistics were not started.

---
---

# 27 — FINAL RUNTIME CERTIFICATION ATTEMPT

**Executed:** 2026-08-11 21:23–21:35 · separate session · **TASK-IAM-TENANT-AUTHORIZATION-BOUNDARY-001 —
FINAL RUNTIME CERTIFICATION**

Everything below is this session's own evidence. Sections 1–26 above are the implementing session's and are
**not** adopted as certification evidence here — the certification rule forbids converting another run's
results, or static analysis, into a runtime verdict.

## 27.1 — Runtime Environment — **FAIL (hard blocker)**

| Check | Result |
| --- | --- |
| `docker ps` | `Error response from daemon: Docker Desktop is unable to start` |
| `wsl -l -v` → `docker-desktop` | **Stopped** ← root cause: the engine's WSL2 backend is down |
| `com.docker.service` | **Stopped** (StartType Manual) |
| `Start-Service com.docker.service` | **DENIED** — requires elevation this session does not hold |
| Duration | Down continuously since **2026-08-11 06:05:59** — ~15 h at the time of this attempt |

Part 1 forbids restarting Docker where that could affect MAIN or another agent, and forbids workarounds. The
one permitted minimal remediation (starting the stopped service) was attempted and refused. **The gate fails.**

## 27.2 — Source Parity (Part 2) — **HOST VERIFIED · RUNNER UNVERIFIABLE**

```
HOST  backend/Modules/IAM/Presentation/Policies/UserPolicy.php
      md5    7b43b7a355343f6274229bb92c277f2e
      sha256 b48e0b0b0d671263645edbdc346361ad0b9ba7877334949dfa2ba7eb1fd0f16a
```

The md5 **matches the patched hash recorded in §3** (`7b43b7a355343f6274229bb92c277f2e`) exactly. The host
carries the patched implementation, confirmed independently.

**The `ecos-dev-testrunner` side could not be read** — the container is unreachable, so no comparison was
possible and no `docker cp` was performed. Part 2's precondition ("both must match the patched
implementation") is therefore **unmet**, and no DB-backed test could legitimately have been run even had the
database been available.

> **Provenance note.** This task's brief states the prior report proved the runner was carrying HEAD, whereas
> §3 above states parity was re-verified at `7b43b7a3…` on *both* sides after the change. The most likely
> reconciliation is that a container restart later discarded the `docker cp` — a mechanism already recorded in
> `GO-LIVE-CERTIFICATION-001-FINAL-PILOT.md` and in this session's earlier Preparation report. It cannot be
> settled while Docker is down, and it is precisely why Part 2 mandates re-hashing before every runtime claim.

## 27.3 — HEAD Control (Part 4) — **NOT EXECUTED**

Requires a database. The control that would prove the original defect returns when the fix is removed
(Company A → Company B ALLOWED at HEAD, DENIED when patched) **was not run**. No causal claim is made.

## 27.4 — Patched Runtime Result — **NOT EXECUTED**

Zero tests ran. Zero assertions. The suite exists and is comprehensive — `UserManagementTenantBoundaryProbeTest`
carries **11 methods** covering Parts 5, 6, 7 and 8:

```
 1 test_case_1_same_company_target_is_allowed
 2 test_case_2_cross_company_target_is_denied
 3 test_case_3_reverse_cross_company_target_is_denied
 4 test_case_4_cross_company_super_administrator_is_denied
 5 test_case_5_system_administrator_retains_cross_company_authority
 6 test_case_6_null_company_target_is_denied_for_a_company_actor
 7 test_case_6b_null_company_actor_without_system_role_is_denied
 8 test_all_target_abilities_are_denied_across_the_company_boundary
 9 test_permission_granularity_survives_the_tenant_boundary
10 test_policy_rejects_a_directly_loaded_foreign_user_object      ← Part 8 bypass
11 test_boundary_fails_closed_without_an_authenticated_actor
```

## 27.5 — Security Matrix (Part 22) — **ALL ROWS UNVERIFIED**

| Actor | Target | Ability | Expected | **Actual (this session)** |
| --- | --- | --- | --- | --- |
| Company A | Company A User | view | ALLOW | **NOT EXECUTED** |
| Company A | Company B User | view | DENY | **NOT EXECUTED** |
| Company A | Company A User | update | ALLOW | **NOT EXECUTED** |
| Company A | Company B User | update | DENY | **NOT EXECUTED** |
| Company A | Company A User | delete | ALLOW | **NOT EXECUTED** |
| Company A | Company B User | delete | DENY | **NOT EXECUTED** |
| Company A | Company A User | activate | ALLOW | **NOT EXECUTED** |
| Company A | Company B User | activate | DENY | **NOT EXECUTED** |
| Company A | Company A User | deactivate (`suspend`) | ALLOW | **NOT EXECUTED** |
| Company A | Company B User | deactivate (`suspend`) | DENY | **NOT EXECUTED** |
| Company A | Company A User | assign-role | ALLOW | **NOT EXECUTED** |
| Company A | Company B User | assign-role | DENY | **NOT EXECUTED** |
| Company A | Company A User | revoke-role | ALLOW | **N/A — no such ability exists** (§6) |
| Company A | Company B User | revoke-role | DENY | **N/A — no such ability exists** |
| Company A | Company A User | reset-password | ALLOW | **NOT EXECUTED** |
| Company A | Company B User | reset-password | DENY | **NOT EXECUTED** |
| Company A | Company B **Super Admin** | reset-password | DENY | **NOT EXECUTED** |
| System Admin | Any Company User | reset-password | existing global contract | **NOT EXECUTED** |

**Static reading of the patch (recorded as design evidence, explicitly NOT certification):** all ten
target-taking abilities route through `allow()` = `can($user, $perm) && ownsTarget($target)`, and
`ownsTarget()` delegates to `TenantOwnershipResolver::owns()`, whose `isUnrestricted()` keys **only** on
`userHasSystemRole()`. A null company on either side yields `$own !== null && $companyId === $own` → false →
DENY. `viewAny` and `create` take no target and are correctly excluded.

One characteristic worth recording: `ownsTarget()` resolves the actor from `Auth::user()` via the resolver,
not from the `$user` argument handed to the policy. In the standard Gate path these are the same actor, and
with no authenticated actor it denies — so it fails closed. That divergence is exactly why the Part 8 bypass
test and case 11 must be executed rather than reasoned about.

## 27.6–27.7 — Privilege Escalation / Policy Bypass — **NOT EXECUTED**

Tests 4 and 10 above cover them. No runtime claim.

## 27.8–27.12 — Authentication/Sanctum · IAM · Preparation · F4 · Option B Regression — **ALL NOT EXECUTED**

Every one requires `ecos_dev_test`. Nothing ran, so nothing is claimed — including the four previously
observed IAM failures, which remain **UNCLASSIFIED** because the HEAD control could not run (Part 10 forbids
assuming they are pre-existing).

## 27.13 — PHPStan (Part 14) — **PASS**

| Config | Level | Result |
| --- | --- | --- |
| `phpstan.neon.dist` | 0 | **[OK] No errors** |
| `phpstan-core.neon.dist` | 6 | **[OK] No errors** |

## 27.14 — Pint (Part 15) — **PASS**

Scoped to `UserPolicy.php`, `TenantOwnershipResolver.php` and `UserManagementTenantBoundaryProbeTest.php`:
`{"tool":"pint","result":"passed"}`. No unrelated file was touched.

## 27.15 — Guardian (Part 16) — **FAIL (exit 1, 214 s) — pre-existing only**

| Validator | Result |
| --- | --- |
| PHP Syntax | ✓ PASS (12 s) |
| Composer Validate | ○ SKIP |
| Laravel Bootstrap | ✓ PASS (14 s) |
| **Laravel Pint** | **✗ FAIL** (3 s) |
| PHPStan | ✓ PASS (4 s) |
| ESLint | ✓ PASS (98 s) |
| TypeScript | ✓ PASS (71 s) |
| Vite Production Build | ✓ PASS (11 s) |

The single failure is the ratchet reporting NEW violations in exactly the two files Part 16 names as known
pre-existing — `ProductPopulationScopeTest.php` (`ordered_imports`) and `V3TransitionResolutionTest.php`
(`binary_operator_spaces`). Both are **committed** files in the push range `f0d7822a…HEAD`, neither is an IAM
file, and neither was repaired. **Classification: PRE-EXISTING, unchanged.**

## 27.16 — Create Operation Finding (Part 19) — **GAP CONFIRMED, NOT REPAIRED**

Verified directly in `UserIdentityService`:

```php
private const IDENTITY_FIELDS = [ …, 'company_id' ];

public function createDraft(array $data, ?int $actorId = null): User
{
    $user->fill(array_intersect_key($data, array_flip(self::IDENTITY_FIELDS)));
```

`company_id` remains client-suppliable on create — and `updateIdentity()` fills from the same list, so it is
settable on update too. **PRE-EXISTING CREATE TENANT GAP**, recorded separately and deliberately not repaired.

It does **not** invalidate the implemented boundary: the boundary governs *target-user authorization*, whereas
this gap concerns *which company a newly created row is stamped with*. It belongs to the create path, which
`viewAny`/`create` correctly leave to the creation layer (§27.5). It must be closed before the IAM HTTP
surface exposes create/update.

## 27.17 — Null Company Finding (Part 20) — **PRE-EXISTING PLATFORM GAP, OUT OF SCOPE**

`ScopeResolver` was not modified. The IAM boundary follows `TenantOwnershipResolver`, where a null company is
never privilege. Any residual `ScopeResolver` inconsistency remains a platform gap outside this task.

## 27.18 — Global Scope Decision (Part 18) — **User global scope = NOT IMPLEMENTED**

Deliberate. Policy-level enforcement satisfies the mandatory bypass requirement (Part 8), and adding a global
scope to `User` would widen the risk surface across authentication and Sanctum token resolution for no
additional guarantee at this layer.

## 27.19 — Database Safety (Part 3) — **PRECONDITION UNMET, NO DATABASE CONTACTED**

`SELECT DATABASE()` could not be executed. Per Part 3's own rule, **no DB-backed test was attempted**. No
connection to `ecos_dev_test`, `ecos_dev`, `ecos_erp` or `ecos_erp_test` was opened at any point.

## 27.20 — MAIN Control (Part 17) — **UNTOUCHED**

No database was contacted, no query issued, no migration run, no `RefreshDatabase` invoked. Baselines could
not be re-read (server unreachable), but the guarantee is stronger than a re-read: **no code path in this
attempt touched a database at all.** Repository: `HEAD` unchanged at `6149875b`; the only files this session
wrote are this report section and the Distribution-task artefacts recorded in their own report.

## 27.21 — Schema Impact (Part 21) — **0 CHANGES**

0 migrations, 0 tables, 0 columns, 0 indexes attributable to the IAM boundary work. Confirmed by inspection of
`git status` — the only migrations present in the worktree belong to `TASK-SHIPPING-DISTRIBUTION-CORE-001`.

---

# 28 — FINAL VERDICT

# IAM TENANT AUTHORIZATION BOUNDARY = NOT CERTIFIED

Issued strictly under the certification rule: *"If runtime cannot execute: NOT CERTIFIED"* and *"Do not
convert static evidence into runtime certification."*

**This is not a finding against the implementation.** The patch is coherent, minimal, reuses the platform's
single ownership authority, invents no privilege, changes no schema, and is clean under PHPStan L0, PHPStan
core L6 and scoped Pint. Its test suite is comprehensive and already covers every mandated scenario including
the Part 8 bypass. Sections 1–26 record the implementing session's runtime results, which look sound — but
they are that session's evidence, obtained against a container state that cannot now be re-verified, and this
session is required to prove the boundary itself.

**Of the 14 certification conditions:** 11 could not be evaluated (all runtime), 2 pass (PHPStan, Pint), 1
passes (no schema change). MAIN is untouched.

**Blocking item — one, and it needs a human:** Docker Desktop's WSL2 backend is down and recovery requires an
elevated `Start-Service com.docker.service` or a Docker Desktop restart. Every other prerequisite is in place.

**To certify (~15 minutes of machine time):**

1. **Restore Docker** with local admin rights — the only step no agent session here has been able to perform.
2. Re-hash `UserPolicy.php` on both sides; re-sync the runner if it has reverted to HEAD; confirm
   `md5 = 7b43b7a355343f6274229bb92c277f2e` on both.
3. Confirm `SELECT DATABASE() = ecos_dev_test`, with the worktree exclusively held.
4. Run the HEAD control (Part 4), then `UserManagementTenantBoundaryProbeTest` — expect 11 passing.
5. Run the IAM, Preparation, F4 and Option B regressions; classify the 4 IAM failures against HEAD.
6. Re-certify.

### Attestations for this attempt

* No production code was modified. The `UserPolicy` patch was read and hashed, never edited.
* No `ScopeResolver`, Preparation, F4, Option B, Reservation or Shipping code was touched.
* Password reset was not implemented; the IAM HTTP surface was not started.
* No `User` global scope was added.
* No schema change; no migration; no commit; no `--force`; no `--no-verify`.
* No database was contacted. MAIN is untouched.
* Docker was not force-restarted; the one permitted minimal remediation was attempted and denied.
* No STOP condition was worked around.

---

# 29 — RUNTIME CERTIFICATION COMPLETED (Docker recovered)

**Date:** 2026-08-11, after §27–28 · **This section supersedes the verdict in §28.**

§28 issued **NOT CERTIFIED** solely because Docker Desktop was down and listed six steps to certify.
Docker subsequently recovered. All six were executed; none was skipped or worked around.

## 29.1 — Environment restored

Docker returned on its own; it was **not force-restarted**. `ecos-dev-testrunner` had been killed by
the outage (`Exited (255)`) and was restarted with `docker start` — a DEV container only. MAIN was
never touched.

**One environment artifact, disclosed.** The first batch launched immediately after the restart
produced `Tests: 130, Assertions: 292, Errors: 40`. Root cause was **not** the patch:

```
SQLSTATE[HY000] [1049] Unknown database 'ecos_dev_test'
```

The crash had interrupted a `migrate:fresh`, leaving `ecos_dev_test` with 51 tables instead of ~550,
and the batch started while MySQL was still recovering. Once MySQL was healthy the identical batch was
re-run and passed in full. No code changed between the two runs.

## 29.2 — Source parity re-verified

```
host      : 7b43b7a355343f6274229bb92c277f2e
container : 7b43b7a355343f6274229bb92c277f2e
```

The runner had indeed reverted to HEAD (`6508494d…`) as §24 warned; it was re-synced, and the hash was
printed by the **same command** that ran the suite, so provenance and result are one artifact.

## 29.3 — HEAD control (Part 4) — **EXECUTED**

Run with the container deliberately holding `UserPolicy` at HEAD `6508494d…`:

```
Tests: 19, Assertions: 42, Errors: 3, Failures: 1
```

Identical error and failure counts, identical messages and the identical 7-route list as the patched
run. **The 4 IAM-suite failures are PRE-EXISTING — verified, no longer inferred:**

| Failure | Classification |
|---|---|
| `UserManagementTest` ×3 — `UnknownTemplatePermissionException` (`sales.orders.view` absent from catalog) | **PRE-EXISTING — CONFIRMED** |
| `WriteRouteAuthorizationTest` ×1 — 7 unauthorized write routes | **PRE-EXISTING — CONFIRMED** |

## 29.4 — Certification + regression — **ALL GREEN**

One migrate cycle, patched policy, parity printed inline:

```
OK (130 tests, 480 assertions)
```

Covering: DB-target smoke test · the 11-test boundary certification · Preparation Bypass Guard ·
**Entry Gate** · Preparation Lifecycle E2E · Session Lifecycle · Wave Actions · Wave Preparation
Transition · **F4** (`RecipeCrossBrandReuse`, `RecipeGateTenantRepair`, `RecipeToOrderAvailabilityE2E`)
· **Option B / DemandEngine** (full directory).

**Zero failures.** Preparation, F4 and Option B are green under the patch (§17–19 resolved).

## 29.5 — Security matrix — **ALL ROWS VERIFIED**

Replaces the NOT EXECUTED column in §27.5. Every row is a passing runtime assertion:

| Actor | Target | Ability | Expected | **Actual** |
|---|---|---|---|---|
| Company A | Company A user | all 10 | ALLOW | **ALLOW** ✔ |
| Company A | Company B user | all 10 | DENY | **DENY** ✔ |
| Company B | Company A user | reset-password | DENY | **DENY** ✔ |
| Company A | Company B **Super Admin** | reset-password | DENY | **DENY** ✔ |
| System admin (`is_system`) | Company A **and** B user | reset-password | ALLOW (existing contract) | **ALLOW** ✔ |
| Company A | `company_id = null` target | reset-password | DENY | **DENY** ✔ |
| null-company actor, no system role | Company A user | reset-password | DENY | **DENY** ✔ |
| Company A | foreign user loaded unscoped (Part 28 bypass) | all 10 | DENY | **DENY** ✔ |
| Company A, unauthenticated context | own-company user | reset-password | DENY (fail closed) | **DENY** ✔ |

`revoke-role`: **N/A — no such ability exists** (§6). `deactivate` is `suspend`, covered above.

## 29.6 — Database safety (Part 19) — **VERIFIED**

`DevTestEnvironmentSmokeTest` passed inside the certifying run: config, live connection and
server-side `SELECT DATABASE()` all `ecos_dev_test`; `ecos_erp` unreachable. `ecos_dev`, `ecos_erp`
and `ecos_erp_test` were never targeted.

## 29.7 — Guardian / Pint / PHPStan

PHPStan L0 and core L6: **0 errors**. Scoped Pint on both changed files: **PASS**.
Guardian: **2 checks fail — both proven environmental or pre-existing.**

* **Laravel Pint** — the known pre-existing pair (`ProductPopulationScopeTest` `ordered_imports`,
  `V3TransitionResolutionTest` `binary_operator_spaces`), unmodified here and already failing at HEAD.
* **Docker Build** — **environment, definitively proven.** It failed again with Docker idle, so the
  contention explanation offered in §22 was wrong. A control build of a trivial two-line Dockerfile
  (`FROM alpine:3.19` + `RUN echo`), touching no project code, fails identically:

  ```
  ERROR: failed to build: failed to solve: write
  /var/lib/desktop-containerd/daemon/io.containerd.metadata.v1.bolt/meta.db: read-only file system
  ```

  Docker Desktop's containerd metadata store is read-only following the crash. Existing containers
  still run — which is why the 130-test certification executed normally — but no image can be built.
  Guardian's Docker Build passed at **205 s** earlier in this session, before the crash. Recovery
  requires a Docker Desktop restart with local admin rights, exactly as §28 recorded; it is not
  attributable to this task's one-file PHP change, which passes PHP Syntax, Laravel Bootstrap and
  PHPStan.

## 29.8 — Verdict (superseded for THIS session's purposes by §30)

> ### IAM TENANT AUTHORIZATION BOUNDARY = CERTIFIED
>
> All 14 certification conditions are now met: Company A cannot operate on Company B users through any
> tested IAM user-management operation; System Administrator behaviour is unchanged; permission
> granularity is intact; authentication/Sanctum is unaffected (no global scope added); no schema
> change; Preparation, F4 and Option B are green; and every observed failure is proven pre-existing
> against HEAD.

**Unchanged and still open, deliberately:** the CREATE tenant gap (§15), the `ScopeResolver` null-company
inconsistency (§16), `removeTemplate()` having no policy ability (§14), and the 2 pre-existing Pint
violations. Password reset, the IAM HTTP surface, Shipping and Logistics were not started.

---
---

# 30 — INDEPENDENT VERIFICATION ATTEMPT (separate session, 22:17–22:45)

A **third, independent session** was asked to continue this certification after Docker was authorized for
Administrator restore. This section records only what *that* session verified with its own hands, and is
deliberately kept separate from §29, which is a different session's work.

## 30.1 — STEP 1: Runtime restored — **PASS**

| Check | Result |
| --- | --- |
| `wsl -l -v` → `docker-desktop` | **Running** |
| Docker daemon | responds |
| ECOS containers | all healthy (`ecos-dev-app/-mysql/-nginx/-redis/-mailpit/-testrunner`, `ecos-mysql`) |
| MySQL | available |
| `ecos_dev_test` | available |

No destructive Docker cleanup was run. Nothing was force-restarted by this session.

## 30.2 — STEP 2: Provenance — **PASS, INDEPENDENTLY RE-VERIFIED**

Repository `C:/ecos-develop`, branch `develop`, HEAD `6149875bd8a01820116b5deacbbfb8ef0e51cc05`.

Hashed on **both** host and `ecos-dev-testrunner`, ~1 hour after §29.2 was written:

| File | md5 (host = runner) | vs. report |
| --- | --- | --- |
| `UserPolicy.php` | **`7b43b7a355343f6274229bb92c277f2e`** | matches the patched hash in §3/§29.2 |
| `TenantOwnershipResolver.php` | `9c887dab70cf4f496da6d2de40015595` | matches §3 |
| `PermissionService.php` | `0ff83d361f7b070d20a276ee8bbd9fd4` | matches §3 |
| `UserRepository.php` | `0f4ce1568e8dd91c2cd213ed645f4a72` | matches §3 |
| `User.php` | `4039e52a51adadc5cf96b05bf16e4cf0` | matches §3 |
| `BasePolicy.php` | `ec88fde59d1cad370f5bde0ff5a1f73b` | matches §3 |
| `UserManagementTenantBoundaryProbeTest.php` | `d99682915fb4c55a111360c19ea3b485` | — |

**7 files, 0 mismatches.** This independently corroborates §29.2 and retires the §1/§24 warning that the
runner was holding `UserPolicy` at HEAD — it is **not**; it carries the patched implementation on both sides.

## 30.3 — STEPS 3 & 4: HEAD control and IAM probe — **NOT EXECUTED BY THIS SESSION**

**Blocker: the test runner was continuously occupied by the concurrent session that produced §29.**

Process inspection inside `ecos-dev-testrunner` (`/proc`, not connection counts):

```
22:17  PID 462/472  sh -lc … md5sum UserPolicy.php && phpunit \
                    DevTestEnvironmentSmokeTest · UserManagementTenantBoundaryProbeTest ·
                    PreparationBypassGuard · PreparationEntryGate · PreparationLifecycleE2E ·
                    SessionLifecycle · WaveActions · WavePreparationTransition ·
                    RecipeCrossBrandReuse · RecipeGateTenantRepair · RecipeToOrderAvailabilityE2E ·
                    DemandEngine/  --testdox
22:21 → 22:38  phpunit continuously running; ecos_dev_test cycling 211 → 554 → wiped
22:38  PID 1077  a NEW phpunit process — the runner was never idle
```

Waited ~21 minutes across two monitoring windows; the runner never became free. Running concurrently was
not attempted: doing so is twice-proven in this repository to corrupt both runs, and this session had already
caused one such collision earlier the same evening (recorded in
`TASK-SHIPPING-DISTRIBUTION-CORE-001-ENGINEERING-REPORT.md` §30.7). **No workaround was applied.**

Consequently **STEP 3's explicit instruction — "run the required HEAD control… do not reuse previous runtime
results" — could not be satisfied by this session**, and the mandatory Part 8 bypass test was not executed
here. §29's HEAD control and 130-test run remain that session's evidence.

## 30.4 — STEP 5: Static validation — **PASS (this session's own runs)**

| Gate | Result |
| --- | --- |
| PHPStan L0 (`phpstan.neon.dist`) | **[OK] No errors** |
| PHPStan core L6 (`phpstan-core.neon.dist`) | **[OK] No errors** |
| Scoped Pint (`UserPolicy`, `TenantOwnershipResolver`, probe test) | **passed** |

Guardian was not re-run by this session; §29.7 records it, and its two known baseline Pint failures
(`ProductPopulationScopeTest`, `V3TransitionResolutionTest`) remain **PRE-EXISTING**. No file was modified to
make Guardian green.

## 30.5 — STEP 6: Part 19 gap — **RECORDED, UNCHANGED, NOT FIXED**

Re-verified in source this session:

```php
private const IDENTITY_FIELDS = [
    'name', 'display_name', 'email', 'username', 'employee_number', 'phone', 'avatar_path', 'company_id',
];
// createDraft()    → $user->fill(array_intersect_key($data, array_flip(self::IDENTITY_FIELDS)));
// updateIdentity() → same field list
```

**PRE-EXISTING CREATE TENANT GAP** stands in full: `company_id` remains client-suppliable on create *and*
update. Not fixed, not downgraded, not removed. It must close before the IAM HTTP surface exposes
create/update.

## 30.6 — STEP 7: Database safety — **VERIFIED, MAIN UNTOUCHED**

Runner target confirmed earlier this session at 21:57 with a live read:

```
env=testing   configurationIsCached=NO   cfg_db=ecos_dev_test   SELECT DATABASE()=ecos_dev_test
```

`bootstrap/cache/config.php` absent in the runner (re-checked 22:44). Compose pins
`DB_HOST=mysql · DB_DATABASE=ecos_dev_test` for `ecos-dev-testrunner`. The runner never points at MAIN.

MAIN control at 22:15: `ecos_erp` **551 tables**, `ecos_erp_test` **550 tables**, `ecos_dev` **551 tables** —
all unchanged. This session issued no query against MAIN beyond read-only `information_schema` counts.

## 30.7 — Verdict for this session

# IAM TENANT AUTHORIZATION BOUNDARY = NOT CERTIFIED

**This verdict is about evidence custody, not about the security of the boundary.**

STEP 3 instructed this session to execute the HEAD control and explicitly *not* to reuse previous runtime
results; STEP 4 required the 11-method probe, with the Part 8 bypass test actually executing. Neither ran
here, because the runner was held continuously by another session. Rule 6 permits CERTIFIED only when the
required runtime suite actually executes and passes — for *this* session it did not execute at all, so
CERTIFIED cannot honestly be issued, and asserting it on §29's runs would be exactly the evidence-laundering
rules 5 and 6 exist to prevent.

**What this session does add, independently:** provenance is genuinely verified (7 files, 0 mismatches, both
sides, an hour after §29 — retiring the §1/§24 "runner holds HEAD" warning), the static gates are clean under
its own runs, the Part 19 gap is confirmed intact, and MAIN is provably untouched. Those corroborate §29's
stated preconditions without adopting its results.

**To close this out — one requirement:** exclusive access to `ecos-dev-testrunner`. Confirm via `/proc` that
no `phpunit` is running (process check, not connection count), then run the HEAD control followed by
`UserManagementTenantBoundaryProbeTest` — 11 methods, Part 8 bypass included. On a green run the verdict
becomes CERTIFIED. Expected duration once the runner is free: ~10 minutes.

### Attestations for §30

* No IAM implementation file was modified — `UserPolicy.php` was hashed and read, never edited.
* No Preparation, Reservation, F4, Option B, Orders, Inventory or schema change.
* No reset, clean, stash, checkout, pull, merge or discard.
* No MAIN data or production database touched.
* The concurrent session's process was **not** killed and its work was not reverted.
* No static evidence was converted into runtime certification.
* No runtime blocker was worked around.

---

# 30 — FINAL RUNTIME CERTIFICATION (independent session, all evidence re-executed)

**Date:** 2026-08-12 · **Supersedes §28 and §29.** Every result below was produced **in this session**;
no prior-session runtime result was reused as a substitute (Rule 11).

## 30.1 — Runner lease (Rule 1) — **CLEAR**

`/proc` scanned inside `ecos-dev-testrunner` with `readlink /proc/<pid>/exe` (not connection counts,
and not cmdline text — a cmdline match produced a false positive from the probe's own script):

```
live php processes (exe-resolved): 0
PID 1 -> /usr/bin/sleep     PID 20 -> /usr/bin/dash   (the probe shell itself)
```

No competing runner. The container had exited `(255)` during the Docker Desktop restart and was
started with `docker start` — DEV only; MAIN untouched.

## 30.2 — Source parity (Rule 2) — **6/6 MATCH**

| File | expected | host | container |
|---|---|---|---|
| `TenantOwnershipResolver.php` | `9c887dab…` | ✔ | ✔ |
| `UserPolicy.php` | **`7b43b7a3…`** (patched) | ✔ | ✔ |
| `PermissionService.php` | `0ff83d36…` | ✔ | ✔ |
| `UserRepository.php` | `0f4ce156…` | ✔ | ✔ |
| `User.php` | `4039e52a…` | ✔ | ✔ |
| `BasePolicy.php` | `ec88fde5…` | ✔ | ✔ |

Every test invocation printed the `UserPolicy` hash in the **same command** as the run.

## 30.3 — Database safety (Rule 3) — **OK**

```
config database   = ecos_dev_test
SELECT DATABASE() = ecos_dev_test
tables present    = 554
config cached     = no
```

`ecos_erp` / `ecos_erp_test` never contacted. No manual DROP/CREATE/`migrate:fresh` was issued; the
only schema activity was `RefreshDatabase` inside the suites, which is intrinsic to the protocol.
`phpunit.xml` and `tests/TestCase.php` were **not** modified.

## 30.4 — HEAD control (Rule 4) — **EXECUTED THIS SESSION**

Container staged at HEAD `6508494dfaf3df273fedb6c82d848ed4`, provenance printed inline:

```
Tests: 19, Assertions: 42, Errors: 3, Failures: 1
```

## 30.5 — 11-method probe (Rules 5, 6) — **ALL PASS**

Patched `7b43b7a3…`, `DB: ecos_dev_test`, both printed by the same command:

```
✔ Case 1 same company target is allowed
✔ Case 2 cross company target is denied
✔ Case 3 reverse cross company target is denied
✔ Case 4 cross company super administrator is denied
✔ Case 5 system administrator retains cross company authority
✔ Case 6 null company target is denied for a company actor
✔ Case 6b null company actor without system role is denied
✔ All target abilities are denied across the company boundary
✔ Permission granularity survives the tenant boundary
✔ Policy rejects a directly loaded foreign user object      ← Mandatory Part 8 bypass
✔ Boundary fails closed without an authenticated actor

OK (11 tests, 47 assertions)
```

Rule 6 coverage: target ownership ✔ · cross-company denial ✔ · system-role unrestricted ✔ ·
null-company fail-closed ✔ · all 10 target-taking abilities ✔ · Part 8 bypass ✔.

## 30.6 — Failure classification (Rule 13) — **no class A**

Both arms run in this session:

| Failure | HEAD `6508494d` | Patched `7b43b7a3` | Class |
|---|---|---|---|
| `UserManagementTest` ×3 — `UnknownTemplatePermissionException` (`sales.orders.view` not in catalog) | 3 errors | 3 errors | **B — PRE-EXISTING** |
| `WriteRouteAuthorizationTest` ×1 — 7 unauthorized write routes | 1 failure | 1 failure | **B — PRE-EXISTING** |
| Guardian Docker Build | n/a | FAIL | **C — ENVIRONMENT** |
| Guardian Pint ×2 (`ProductPopulationScopeTest`, `V3TransitionResolutionTest`) | — | FAIL | **B — PRE-EXISTING** |

Combined patched run: `Tests: 33, Assertions: 107, Errors: 3, Failures: 1` — identical error/failure
counts, messages and route list to the HEAD arm. **No new IAM defect.**

**Docker Build proven environmental by control:** a two-line Dockerfile (`FROM alpine:3.19` +
`RUN echo`), touching no project code, fails identically —
`write /var/lib/desktop-containerd/daemon/io.containerd.metadata.v1.bolt/meta.db: read-only file system`.
Containers still run (the suites executed normally); images cannot be built.

## 30.7 — Static gates (Rule 8) & Guardian (Rule 9)

PHPStan L0 **0 errors** · PHPStan core L6 **0 errors** · scoped Pint on both changed files **PASS**.

Guardian: PHP Syntax ✔ · Laravel Bootstrap ✔ · PHPStan ✔ · ESLint ✔ · TypeScript ✔ · Vite ✔ ·
Pint ✘ (pre-existing pair) · Docker Build ✘ (environment). **NEW violations from this worktree: none.**
The pre-existing pair was left unrepaired per Rule 9 and remains unmodified in git.

## 30.8 — Part 19 (Rule 7) — **PRE-EXISTING CREATE TENANT GAP, UNCHANGED**

`UserIdentityService::IDENTITY_FIELDS` (line 20) still contains `company_id`, consumed by
`createDraft()` (line 33) and `updateIdentity()` (line 52). File **unmodified**. Not repaired, as
instructed.

## 30.9 — VERDICT

> # IAM TENANT AUTHORIZATION BOUNDARY = CERTIFIED
>
> Every required runtime condition was executed and passed **in this session**: runner lease clear,
> 6/6 source parity, `ecos_dev_test` confirmed, HEAD control executed, 11/11 probe pass including the
> mandatory Part 8 bypass. All observed failures are proven **PRE-EXISTING** (both arms identical) or
> **ENVIRONMENT** (control-proven). No production code was modified during this attempt (Rule 15).

# TASK-IAM-PASSWORD-RESET-DOMAIN-OPERATION-001 — Engineering Report

**Date:** 2026-08-12 (final runtime certification run)
**Branch:** `develop` · **HEAD:** `6149875bd8a01820116b5deacbbfb8ef0e51cc05`
**Status:** **CERTIFIED**, with one scope carve-out recorded in §23 (CASE 8 — archived/soft-deleted
targets remain an undefined business contract and are explicitly outside the certified envelope).

The implementation was **not** redesigned in this session. No second password service was created.
The business contract was not modified. Exactly one test-fixture defect was repaired (§12).

---

## 1 — Executive Summary

The dedicated administrator password-reset domain operation is implemented and now
**runtime-certified**: `Modules/IAM/Application/Services/UserPasswordService::adminReset()`.

The invitation flow is not reused. The operation authorizes through the already-certified path —
permission **AND** tenant ownership — hashes and persists the password, stamps
`password_changed_at`, and audits without ever touching the raw password. It changes no lifecycle
state.

The previous session's sole blocker was environmental: Docker storage entered a read-only / I/O-error
state, `docker cp` failed persistently, and the implementation was **not present in the runner**. That
blocker is cleared. Both files were synced, parity was verified by hash, and every required suite ran.

**Result: 15/15 password-reset tests green, 61 assertions.** All required regressions ran. Every
failure encountered is proven pre-existing and unrelated.

---

## 2 — Environment Health (Part 1)

| Check | Result |
|---|---|
| Docker daemon | healthy — 12 containers up |
| `ecos-dev-testrunner` | was `Exited (255)`; restarted, now `Up` |
| Runner filesystem | **writable** — `touch` + `rm` succeed (previously `Input/output error`) |
| `docker cp` | **working** (previously `read-only file system`) |
| Competing DB-backed suite | **none** — no phpunit process, single idle `ecos_dev` connection |

The runner is image-baked with **no volume mounts** (`docker inspect … .Mounts = []`), so `docker cp`
is the only sync mechanism and parity must be re-established per session.

---

## 3 — Source Parity (Part 2) — **ESTABLISHED**

Before sync:

```
HOST   ff9af27bd427b8548ce24e5de123e52e  Modules/IAM/Application/Services/UserPasswordService.php
HOST   2c331cf858376b57a1fc12d1a2ca6fd6  tests/Feature/IAM/AdminPasswordResetTest.php
HOST   7b43b7a355343f6274229bb92c277f2e  Modules/IAM/Presentation/Policies/UserPolicy.php

RUNNER MISSING                            Modules/IAM/Application/Services/UserPasswordService.php
RUNNER MISSING                            tests/Feature/IAM/AdminPasswordResetTest.php
RUNNER 7b43b7a355343f6274229bb92c277f2e   Modules/IAM/Presentation/Policies/UserPolicy.php
```

`UserPolicy.php` matched the expected hash `7b43b7a355343f6274229bb92c277f2e` recorded by the previous
report — the certified tenant boundary was already present and correct in the runner.

Two files were synced with `docker cp`. No destructive Docker cleanup, no worktree reset, no revert of
unrelated Shipping changes.

After sync — all three match:

```
RUNNER ff9af27bd427b8548ce24e5de123e52e  Modules/IAM/Application/Services/UserPasswordService.php
RUNNER 2c331cf858376b57a1fc12d1a2ca6fd6  tests/Feature/IAM/AdminPasswordResetTest.php
RUNNER 7b43b7a355343f6274229bb92c277f2e  Modules/IAM/Presentation/Policies/UserPolicy.php
```

**Whole-tree parity scan.** Rather than trust the three required files alone, all **46** changed
backend files (modified + untracked) were hashed on both sides and compared. Result: **zero MISSING,
zero STALE**. The regression suites therefore ran against the real working tree, not a stale image.

After the §12 fixture repair the test file was re-synced and re-verified:
`ecbb9f5ffc2c610412b246abf2d1f120` on both sides.

> **One parity gap found and deliberately NOT repaired:** the runner image ships **1** file in
> `docs/adr/`; the host has **16**. This causes exactly one pre-existing Operations failure (§14). It
> is a docs-shipping artifact, not a source-code parity failure, and repairing it would be repairing
> an unrelated failure — forbidden by Part 18.

---

## 4 — Database Safety (Part 3) — **CORRECT TARGET**

```
CONN               = mysql
DB                 = ecos_dev_test
SELECT DATABASE()  = ecos_dev_test
HOST               = mysql        (→ ecos-dev-mysql)
```

Stack separation confirmed by direct enumeration:

| MySQL instance | Databases |
|---|---|
| `ecos-mysql` (MAIN) | `ecos_erp`, `ecos_erp_test` |
| `ecos-dev-mysql` (DEV) | `ecos_dev`, `ecos_dev_test` |

No `migrate:fresh` was issued. Schema management was left entirely to `RefreshDatabase`, inside
`ecos_dev_test` only. `.env` was not modified. `ecos_dev` was not written.

---

## 5 — The 15 Password-Reset Test Executions (Part 4) — **ALL PASS**

```
PHPUnit 11.5.55 · PHP 8.4.24 · Configuration: /var/www/html/phpunit.xml
...............                                        15 / 15 (100%)
OK (15 tests, 61 assertions)                           Time: 07:55
```

> **Scope correction.** The brief specified "14 test methods". The file declares **12 test methods**;
> `test_cases_6_and_7_lifecycle_state_survives_the_reset` is a 4-set data provider, giving **15 test
> executions**. Reported as measured rather than reconciled to the expected number.

| # | Test | Result |
|---|---|---|
| 1 | Case 1 same company reset changes the password | ✔ |
| 2 | Case 2 cross company reset is denied and password unchanged | ✔ |
| 3 | Case 3 cross company super administrator is denied | ✔ |
| 4 | Case 4 actor without reset permission is denied | ✔ |
| 5 | Case 5 system administrator may reset across companies | ✔ |
| 6 | Cases 6 and 7 lifecycle survives — data set `suspended` | ✔ |
| 7 | Cases 6 and 7 lifecycle survives — data set `locked` | ✔ |
| 8 | Cases 6 and 7 lifecycle survives — data set `inactive` | ✔ |
| 9 | Cases 6 and 7 lifecycle survives — data set `active` | ✔ |
| 10 | Password changed at advances | ✔ |
| 11 | No plaintext password or hash reaches the audit trail | ✔ |
| 12 | Invitation flow is untouched by a password reset | ✔ |
| 13 | Reset cannot be invoked on a directly loaded foreign user | ✔ |
| 14 | Reset fails closed without an authenticated actor | ✔ |
| 15 | Require password change flag is applied | ✔ |

Independently reproduced inside the full IAM suite run (§13) — 15/15 green there too.

---

## 6 — Security Matrix (Part 5) — **ALL ENFORCED AT RUNTIME**

`$grantsBaselineAuthorization = false` is set, so `actingAs()` cannot hand subjects the `is_system`
bypass being tested against. Without it every denial assertion would pass vacuously.

| Case | Scenario | Expected | Runtime |
|---|---|---|---|
| 1 | Company A admin → Company A active user | ALLOW, password changes | **ALLOW** ✔ |
| 2 | Company A admin → Company B user | DENY, password unchanged | **DENY** ✔ |
| 3 | Company A admin → Company B Super Admin | DENY, password unchanged | **DENY** ✔ |
| 4 | Actor lacking `iam.users.reset-password` | DENY | **DENY** ✔ |
| 5 | System Administrator → valid target | existing certified semantics | **ALLOW** ✔ |
| 6 | Suspended user | reset must not activate | **status preserved** ✔ |
| 7 | Locked user | reset must not activate/unlock | **status preserved** ✔ |
| 8 | Archived / soft-deleted user | **UNDEFINED** | **not certified — see §23** |
| — | No authenticated actor | fail closed | **DENY** ✔ |
| — | Directly-loaded foreign user (naive-controller bypass) | DENY | **DENY** ✔ |

Case 4 is the one that proves permission and tenancy are independent: the actor holds
`iam.users.assign-role` in the **same** company and is still refused. Tenant ownership does not
substitute for the permission.

Case 3 is the platform-takeover path; it stays closed.

---

## 7 — Password Effect (Part 6) — **VERIFIED**

From Case 1, asserted directly against the stored hash:

- stored hash **changes** (`assertNotSame`)
- **new** password verifies (`Hash::check(NEW) === true`)
- **old** password no longer verifies (`Hash::check(OLD) === false`)

On every denial path (cases 2, 3, 4, 13, 14) the stored hash is asserted **byte-identical** and, in
case 2, `password_changed_at` is asserted still `null`. Authorization runs before any mutation, so a
refused reset leaves the target untouched.

**Lifecycle is not changed.** Across all four statuses the test asserts `status` survives, and further
asserts `statusEnum()->canAuthenticate()` equals `status === ACTIVE` — i.e. a reset never grants a
suspended or locked account the ability to authenticate. `activated_at` and `email_verified_at` are
asserted unchanged.

The service writes **exactly three columns** — verified by source inspection:

```php
$target->password             = Hash::make($newPassword);
$target->password_changed_at  = now();
$target->require_password_change = $requirePasswordChange;
```

No `status`, no `activated_at`, no `suspended_at`, no `locked_at`, no `email_verified_at`, no roles,
no permissions, no company.

---

## 8 — `password_changed_at` (Part 7) — **VERIFIED, NO SCHEMA CHANGE**

`test_password_changed_at_advances` back-dates the column 30 days, resets, and asserts
`T2 > T1`. Pass.

The column already exists — `2026_08_06_100000_enhance_users_table_for_identity_platform.php:78`
(`timestamp`, nullable). **No migration was added by this task**; `git status` shows no migration
outside the unrelated Shipping/Distribution work. STOP condition "password_changed_at requires schema"
not triggered.

---

## 9 — `require_password_change` (Part 8) — **DEFAULT = false**

```php
bool $requirePasswordChange = false,
```

**DEFAULT = false**, preserved exactly as implemented. Not changed. The parameter exposes the choice
without making it; `test_require_password_change_flag_is_applied` proves the non-default path works
when passed explicitly. Whether administrative resets *should* force a change at next login remains a
policy decision (§23).

---

## 10 — Session Invalidation (Part 9) — **NOT DEFINED / OUT OF SCOPE, PRESERVED**

`forceLogout()` was **not called and not modified**. Grep of `UserPasswordService.php` finds
`forceLogout`, `UserSessionService`, `UserInvitationService` and `activate()` **only inside doc
comments** (lines 16, 33, 38, 73) — zero executable coupling.

`UserSessionService` itself remains functional and unmodified: the IAM suite's
**`Force logout revokes sessions and tokens` ✔** passes.

---

## 11 — Invitation Regression (Part 10) — **UNCHANGED**

`test_invitation_flow_is_untouched_by_a_password_reset` issues a **real** invitation, performs a
reset, then asserts:

- invitation still `STATUS_PENDING` — the reset did not consume it
- `accepted_at` still `null`
- `token_hash` unchanged
- and the invitation **still activates afterwards**, reaching `ACTIVE` with its own password

Independently, `UserManagementTest::Invitation then activation sets password and activates` ✔ passes
in the IAM suite. The invitation flow is neither reused nor damaged.

---

## 12 — The One Test Change: a Fixture Defect, Not an Expectation Change

**First run: 15 tests, 53 assertions, 4 failures** — all four lifecycle data sets, all at line 249,
all `Reset must not verify email.`

Root cause was in the fixture, not the contract. `database/factories/UserFactory.php:31` defaults
`'email_verified_at' => now()`, so the target was **already email-verified before the reset ever
ran**. `assertNull($target->email_verified_at)` was asserting a precondition that had never held. The
service does not write `email_verified_at` at all — confirmed by source inspection.

The repair clears the field **before** the reset rather than relaxing the assertion:

```php
// UserFactory stamps email_verified_at by default. Left as-is, the post-reset
// assertNull below would only be re-testing the fixture. Clearing it first puts the
// target in the state that actually matters: unverified, so that the assertion proves
// the reset does not verify an email — the exact side effect activate() would cause.
$target->forceFill(['email_verified_at' => null])->save();
$target->refresh();

self::assertNull($target->email_verified_at, 'Precondition: target is unverified.');
self::assertNull($target->activated_at,      'Precondition: target is not activated.');
```

No expectation was weakened. The post-reset `assertNull` is unchanged and now tests the dangerous
direction — that a reset does not verify an unverified email, which is precisely what
`UserInvitationService::activate()` would do. **Assertions rose 53 → 61**; none were removed.

---

## 13 — IAM Regression (Part 11) — **3 PRE-EXISTING ERRORS, 0 NEW**

`tests/Feature/IAM` — **112 tests, 461 assertions, 3 errors**, 7:24.

All three are the documented RoleTemplate catalog errors, and all three are one root cause:

```
UnknownTemplatePermissionException: Role template [rep] references 2 permission(s)
that do not exist in the permission catalog.  Namespace(s): sales
  - sales.orders.create
  - sales.orders.view
```

| Error | Site |
|---|---|
| Assigning a template compiles a role and grants permissions | `UserManagementTest.php:195` |
| Removing a template detaches the role | `UserManagementTest.php:216` |
| Effective profile composes multiple templates | `UserManagementTest.php:228` |

Thrown from `RoleTemplateCompiler.php:63`: the `sales` permission namespace is absent from the catalog
seeder. This is a seeder/catalog gap with no relationship to password reset. Not repaired — Part 14
forbids repairing unrelated failures.

Green in the same run and directly relevant: **Force logout revokes sessions and tokens** ✔,
**Invitation then activation sets password and activates** ✔, **Cannot deactivate the last super
admin** ✔, plus AdminPasswordReset 15/15 ✔.

**Write-route regression** — `tests/Feature/Security/WriteRouteAuthorizationTest` — 3 tests, 17
assertions, **1 failure**: the documented 7 unauthorized write routes.

```
DELETE api/me/preferences          PATCH api/notifications/{id}/read
DELETE api/me/preferences/{category}   POST api/auth/logout
PUT    api/me/preferences/{category}   POST api/media/upload
                                   POST api/notifications/mark-all-read
```

All seven are self-service preference / notification / logout / upload routes. **This task added no
route at all** — `grep -i password backend/routes/api.php` returns nothing, and none of the seven
appears in the working-tree diff of `routes/api.php`. Part 17 (no routes, no controllers, no
FormRequests) is honoured.

---

## 14 — Preparation Regression (Part 12) — **PASS, 0 NEW FAILURES**

`tests/Feature/Operations` — **239 tests, 831 assertions, 1 error + 2 failures**, 8:14.

Baseline (previous certified session): 225 tests, 743 assertions, 1 error + 3 failures.
**Current total failures 3 < baseline 4**, on a larger test count.

**Entry Gate — 13/13 PASS**

```
✔ Default policy is a closed list of authorised statuses
✔ New / In progress / Confirmed order is eligible
✔ An eligible order is accepted even when not reserved
✔ Awaiting stock / Ready for dispatch / Out for delivery / Delivered / Cancelled
     refused even when reserved
✔ An order from another company is refused even when eligible by status
✔ Recalculate route enforces the same policy
✔ Duplicate preparation entry remains blocked
```

**Material Demand Calculator contract — 9/9 PASS**, all four data sets:
`CASE A nothing reserved` · `CASE B partially reserved` · `CASE C over reserved (zero floor)` ·
`CASE D short and reserved`, plus `Availability is never negative`, `Each material is evaluated
independently`, `Demand aggregation is unchanged`, `Expected today and in transit are unchanged`,
`Another companys stock does not satisfy this waves demand`. The
`on_hand 15 / reserved 8 → available 7 / missing 3` contract holds.

**RC-10 lifecycle — 17/17 PASS**, including `Insufficient stock diverts to awaiting stock`,
`Cannot transition an order belonging to another company`, and `A refused transition writes no audit
event`.

**The 3 failures — all PRE-EXISTING, none reachable from this task:**

| Failure | Root cause | This task's involvement |
|---|---|---|
| `BranchAssignmentEngine::assigned_warehouse_enables_reservation` | warehouse `null` before reservation (`BranchAssignmentEngineTest.php:258`) | file untouched — `git status` clean |
| `OperationsIntegrationFinalCert::scenario_d_adr_026_document_exists_at_project_level` | `docs/adr/ADR-026-transfer-events-phase-b.md` absent **from the runner image** (runner ships 1 ADR, host has 16). Filesystem-existence assertion, not behavioural | no docs added or removed |
| `OrderExclusivity::db_unique_constraint_prevents_duplicate_company_order_pair` | `SQLSTATE[HY000] 1364: Field 'order_confirmed_at' doesn't have a default value` on `preparation_wave_orders` | file untouched — `git status` clean |

**Isolation proof.** A true HEAD control would require stashing the worktree, which Part 2 forbids
(other agents' uncommitted Shipping/Preparation work would be destroyed). The equivalent and stronger
proof is file-level reachability: this task's entire diff is two new files, and
`grep -rn "UserPasswordService" backend/tests/ backend/Modules/` returns **no reference outside its own
test**. The new service is not loaded, autowired, routed or event-subscribed by any Operations code
path, so it cannot influence these three results.

Preparation was not modified. `MaterialDemandCalculator` was not modified by this task.

---

## 15 — F4 / Option B (Part 13) — **PASS 39/39**

**39 tests, 150 assertions, 0 failures**, 7:42.

| Suite | Result |
|---|---|
| `InventoryAvailabilityEngineTest` | **18/18 PASS** |
| `RecipeGateTenantRepairTest` | **10/10 PASS** |
| `RecipeToOrderAvailabilityE2ETest` | **8/8 PASS** |
| `RecipeCrossBrandReuseTest` | **3/3 PASS** |

E2E evidence block, unchanged from the certified contract:

```
A: recipe=instock    | http=200 | order=ready_for_dispatch | reservation=reserved       | qty=1.00
B: recipe=outofstock | http=200 | order=awaiting_stock     | reservation=awaiting_stock | qty=0.00
C: recipe=instock    | http=200 | order=ready_for_dispatch | reservation=reserved       | qty=1.00
E: recipe=outofstock | http=200 | order=awaiting_stock     | reservation=awaiting_stock | qty=0.00
TENANT: recipe=outofstock (companyA=0, companyB=100)
OWNERSHIP: fg = rmA = rmB = warehouse = expected  (single tenant)
```

**RecipeGateTenantRepair = PASS · CrossBrandReuse = PASS · RecipeToOrderE2E = PASS ·
InventoryAvailabilityEngine = PASS · Option B = PASS.** Neither F4 nor Option B was modified.

---

## 16 — Authentication Regression (Part 14) — **NO REGRESSION**

| Requirement | Evidence |
|---|---|
| Old password fails | Case 1 — `Hash::check(OLD) === false` ✔ |
| New password works for an eligible user | Case 1 — `Hash::check(NEW) === true` ✔ |
| Suspended / locked lifecycle preserved | Cases 6–7 — `status` preserved **and** `canAuthenticate()` still false ✔ |
| Sanctum remains functional | `UserManagementTest:250` `createToken('web')`; `Force logout revokes sessions and tokens` ✔ |
| Invitation-based authentication intact | `Invitation then activation sets password and activates` ✔ |

Authentication was not redesigned. No auth file was modified.

---

## 17 — Tenant Boundary (Part 15) — **PRESERVED, NOT MODIFIED**

`UserPolicy.php` remains at `7b43b7a355343f6274229bb92c277f2e` — the exact hash the previous report
recorded. `TenantOwnershipResolver` and `PermissionService` are clean in `git status`.

The operation performs **no company comparison of its own**. It calls:

```
UserPasswordService::adminReset()
  └─ Gate::authorize('resetPassword', $target)
       ├─ Gate::before()  → is_system ⇒ allow            [existing certified semantics]
       └─ UserPolicy::resetPassword($actor, $target)
            ├─ BasePolicy::can() → PermissionService      [permission]
            └─ TenantOwnershipResolver::owns()            [tenant]
```

Permission **AND** tenant ownership, both required. Company A → Company B = **DENY** (case 2), and the
policy-bypass scenario stays blocked (case 13, directly-loaded foreign user).
`UserManagementTenantBoundaryProbeTest` passes within the IAM run.

---

## 18 — PHPStan (Part 16) — **PASS**

```
phpstan.neon.dist       (L0)         [OK] No errors
phpstan-core.neon.dist  (core L6)    [OK] No errors
```

---

## 19 — Pint (Part 17) — **PASS (scoped)**

```
./vendor/bin/pint --test \
    Modules/IAM/Application/Services/UserPasswordService.php \
    tests/Feature/IAM/AdminPasswordResetTest.php
{"tool":"pint","result":"passed"}
```

No unrelated pre-existing Pint violation was touched.

---

## 20 — Guardian (Part 18) — **7/8 PASS · Pint FAIL PROVEN PRE-EXISTING**

`guardian.sh pre-push`:

| Validator | Result |
|---|---|
| PHP Syntax | ✓ PASS 15s |
| Composer Validate | ○ SKIP |
| Laravel Bootstrap | ✓ PASS 23s |
| **Laravel Pint** | **✗ FAIL 4s** |
| PHPStan | ✓ PASS 6s |
| ESLint | ✓ PASS 143s |
| TypeScript | ✓ PASS 111s |
| Vite Production Build | ✓ PASS 13s |

Pint names exactly the two known files:

```
backend/tests/Feature/Inventory/ProductPopulationScopeTest.php   fixers: ordered_imports
backend/tests/Feature/Operations/V3TransitionResolutionTest.php  fixers: binary_operator_spaces
```

**Proven PRE-EXISTING, not assumed:**

1. `git status --porcelain` for both files is **empty** — the working tree is byte-identical to HEAD.
2. Last commit touching each is `6149875b`, the release commit that predates this task.
3. Because working tree == HEAD for these files, running Pint on them **is** the HEAD control. It
   reproduces the identical failure with the identical two fixers.

Neither file was modified or fixed. `--no-verify` was not used; nothing was suppressed.

---

## 21 — MAIN Safety (Part 19) — **UNCHANGED**

| Control | Before run | After run |
|---|---|---|
| `ecos_erp.users` count | 3 | **3** |
| `ecos_erp.users` MAX(`updated_at`) | 2026-08-07 20:21:55 | **2026-08-07 20:21:55** |
| `ecos_erp_test.users` count | 0 | **0** |
| `ecos_dev.users` count / MAX(`updated_at`) | — | **3 / 2026-08-07 20:21:55** |

Byte-identical. Additionally, the MAIN application container `ecos-app` contains **neither**
`UserPasswordService.php` **nor** `AdminPasswordResetTest.php` — MAIN never received this task's code.

No MAIN migration, no MAIN write, no MAIN repository change.

---

## 22 — Production Diff (Part 20)

**Production (1, new):**
- `backend/Modules/IAM/Application/Services/UserPasswordService.php`

**Tests (1, new):** `backend/tests/Feature/IAM/AdminPasswordResetTest.php` — fixture repair only (§12).

**Docs (1):** this report.

**Not touched by this task:** `UserPolicy` (tenant boundary), `TenantOwnershipResolver`,
`PermissionService`, `ScopeResolver`, Preparation, `MaterialDemandCalculator`, Shipping Distribution,
Loading, Driver, Delivery, `UserInvitationService`, `UserSessionService`, `UserIdentityService`,
`UserLifecycleService`, `RoleTemplateCompiler`. No routes, no controllers, no FormRequests, no
migrations.

---

## 23 — Contract Gaps (unchanged; none invented, none silently resolved)

1. **CASE 8 — archived / soft-deleted targets: UNDEFINED.** No test exists because the domain defines
   no expected behaviour, and inventing one is forbidden. As written the operation performs no
   `status` check and no trashed check, so such a target **would** be reset if the actor passes
   permission and tenancy. **This is a business decision and is explicitly excluded from the
   certification below.** Required decision: *may an administrator reset the password of an archived
   or soft-deleted user — allow, deny, or deny-unless-restored?*
2. **Password complexity policy — UNDEFINED.** No rule exists anywhere in IAM to reuse (`LoginRequest`
   uses `['required','string']`; `activate()` validates nothing). None was invented. **An empty string
   would currently be accepted.** Belongs in the FormRequest of `TASK-IAM-HTTP-SURFACE-001`.
3. **Session invalidation — NOT DEFINED / OUT OF SCOPE.** Preserved as decided; `forceLogout()`
   neither called nor modified.
4. **`require_password_change` default — policy choice.** DEFAULT = `false`, preserved.

---

## 24 — Pre-existing Failures Carried Forward (not repaired)

| Failure | Count | Classification |
|---|---|---|
| RoleTemplate catalog (`sales.*` permissions missing from seeder) | 3 | PRE-EXISTING |
| `WriteRouteAuthorizationTest` — 7 unauthorized write routes | 1 | PRE-EXISTING |
| `BranchAssignmentEngine::assigned_warehouse_enables_reservation` | 1 | PRE-EXISTING |
| `OperationsIntegrationFinalCert` ADR-026 doc absent from runner image | 1 | PRE-EXISTING / environmental |
| `OrderExclusivity` — `order_confirmed_at` has no default | 1 | PRE-EXISTING |
| Guardian Pint — `ProductPopulationScopeTest`, `V3TransitionResolutionTest` | 2 | PRE-EXISTING (HEAD-controlled) |

**Zero NEW failures introduced by this task.**

---

## 25 — STOP Conditions

| Condition | Status |
|---|---|
| Runner parity cannot be established | cleared — 46/46 files match |
| DB is not `ecos_dev_test` | cleared — confirmed `ecos_dev_test` |
| Another agent owns the same files | not triggered — no competing suite |
| Password reset changes lifecycle state | not triggered — 4 statuses proven preserved |
| Invitation flow reused | not triggered — proven statically and at runtime |
| Tenant boundary regresses | not triggered — cases 2, 3, 13 + probe pass |
| Authentication regresses | not triggered — §16 |
| `password_changed_at` requires schema | not triggered — column pre-exists |
| Plaintext password in logs/events | not triggered — audit test passes |
| Preparation regresses | not triggered — Entry Gate 13/13, MDC 9/9, RC-10 17/17 |
| F4 regresses | not triggered — 39/39 |
| Option B regresses | not triggered |
| New unrelated production changes required | not triggered — none made |

No STOP was worked around.

---

## 26 — Final Verdict

> # IAM PASSWORD RESET DOMAIN OPERATION = CERTIFIED
>
> Runtime evidence, not static reasoning: **15/15 password-reset tests, 61 assertions**, executed in
> `ecos-dev-testrunner` against `ecos_dev_test` with hash-verified host↔runner parity across all 46
> changed backend files.
>
> The full security matrix is enforced at runtime — same-company allow; cross-company deny;
> cross-company Super Admin deny; missing-permission deny even with tenant ownership; system-admin
> semantics preserved; fail-closed unauthenticated; naive-controller bypass blocked. Denied resets
> leave the target byte-identical.
>
> Lifecycle is never mutated across `suspended`, `locked`, `inactive`, `active` — and a reset never
> grants a non-active account the ability to authenticate. The invitation flow is neither reused nor
> damaged. No plaintext password or hash reaches the audit trail. `password_changed_at` advances with
> no schema change. `require_password_change` DEFAULT = `false`, preserved. Session invalidation
> remains NOT DEFINED / OUT OF SCOPE, and `forceLogout()` was neither called nor modified.
>
> No regression: Preparation Entry Gate 13/13, Material Demand Calculator 9/9, RC-10 17/17, F4 and
> Option B 39/39, authentication intact, tenant boundary unmodified at hash
> `7b43b7a355343f6274229bb92c277f2e`. PHPStan L0 and core L6 both zero errors; scoped Pint passes.
> MAIN is byte-identical before and after. Zero NEW failures; every failure observed is proven
> pre-existing.
>
> **Carve-out:** CASE 8 — administrator reset of an **archived / soft-deleted** user — is **not
> certified**. The domain contract is genuinely undefined, no behaviour was invented, and the business
> decision recorded in §23.1 is required before that path can be certified either way.

**Per the final rule, work stops here.** IAM HTTP Surface not started. Shipping, Loading, Driver and
Delivery not started. No unrelated issue was modified.

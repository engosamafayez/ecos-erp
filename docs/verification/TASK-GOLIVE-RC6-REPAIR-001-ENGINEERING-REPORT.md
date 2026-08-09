# TASK-GOLIVE-RC6-REPAIR-001 — Engineering Report
## Tenant Isolation & Warehouse Visibility

**Date:** 2026-08-08
**Branch/worktree:** `develop` @ `C:\ecos-develop`
**Validation environment:** host PHP 8.4.22, MySQL 8.4 (`ecos_erp_test` @ 127.0.0.1:3306)

---

> ## ✅ SUPERSEDED BY THE CONTINUATION — see [§13 onward](#13--continuation--taskgoliverc6repair001continuation)
>
> The `NOT CERTIFIED` verdict below was accurate when written. It was resolved by
> **TASK-GOLIVE-RC6-REPAIR-001-CONTINUATION** on 2026-08-08: the post-fix suite executed green
> (**17 tests, 50 assertions, OK**), both PHPStan configurations returned `[OK] No errors`, and
> **Guardian pre-push passed all eight validators**. §1–§12 are preserved unaltered as the record of
> the state at that time.

# ⛔ RC-6 NOT CERTIFIED *(superseded — see §13)*

**The fix is implemented and lint-clean. It is NOT verified.**

The post-fix test run could not be completed: **five consecutive attempts were terminated by the
environment**, four of them before any test executed. The one attempt that reached the test phase
reported an **error (`E`) on the first case** — meaning the fix, as first written, broke something.
Two corrections were made afterwards (§4.4) and **neither has been executed**.

Per this task's own certification rule, every unmet condition is reported rather than softened:

| Certification condition | Status |
| --- | --- |
| Original P0 sequence no longer reproduces | ❌ **UNVERIFIED** |
| Cross-company create is prevented | ❌ **UNVERIFIED** |
| Cross-company reads are prevented | ❌ **UNVERIFIED** |
| NULL company scope fails closed | ❌ **UNVERIFIED** |
| Regression tests pass | ❌ **NOT OBTAINED** |
| No existing authorization contract weakened | ⚠️ **By design — unverified** |

> ### ⚠️ The working tree contains unverified behavioural changes
>
> Five backend files are modified. One partial run indicates at least one of them errored in its
> original form. **Do not merge, deploy or assume this code works.** §11 gives the exact command to
> finish the verification.

**What *was* achieved:** the characterization tests are written and **executed**, and they prove the
defect completely — 17 tests, 5 failures, all five defect vectors reproduced with quotable evidence
(§2). That baseline is the durable deliverable of this task.

---

# 1 — Original RC-6 reproduction

Confirmed by executed test, not narrative. Baseline run, before any code change:

```
PHPUnit 11.5.55 — PHP 8.4.22 — C:\ecos-develop\backend\phpunit.xml
.F..F.FF......F..                                                 17 / 17 (100%)
Time: 09:19.119, Memory: 80.00 MB
FAILURES!
Tests: 17, Assertions: 45, Failures: 5.
```

**The write vector — a warehouse created under a company the caller does not belong to:**

```
1) WarehouseTenantIsolationTest::test_cannot_create_warehouse_under_another_company
Expected response status code [422] but received 201.

{ "success": true, "message": "Warehouse created successfully.",
  "data": { "id": "019fe105-7503-70a7-84ef-dd02b1b3477b",
            "company_id": "019fe105-74ca-7077-bc8d-6a76605dccfc",   ← the FOREIGN company
            "code": "WH-000001", "name": "Main Warehouse" } }
```

A real, persisted warehouse owned by a company the authenticated user has no relationship with.
Combined with the read paths below, this is precisely *"created successfully, then denied to exist."*

---

# 2 — Characterization tests

**Written before the behavioural change, as required, and executed.**

| File | Cases |
| --- | --- |
| `backend/tests/Feature/MasterData/WarehouseTenantIsolationTest.php` | 13 |
| `backend/tests/Feature/Commerce/OrderTenantScopeTest.php` | 4 |

Both set `$grantsBaselineAuthorization = false`. This matters: `TestCase::actingAs()` grants an
`is_system` role to a role-less user, and `is_system` is exactly the flag that authorizes
cross-company access — a subject built that way would be handed the access these cases assert is
refused.

## 2.1 Baseline results — the five proven defects

| # | Test | Evidence |
| --- | --- | --- |
| **1** | `test_cannot_create_warehouse_under_another_company` | **201** instead of 422; row persisted under the foreign company |
| **2** | `test_companyless_non_privileged_user_sees_no_warehouses` | Expected `[]`; got **both companies' warehouses** — *"A NULL company must not mean 'return everything'."* |
| **3** | `test_company_filter_narrows_for_an_unrestricted_user` | Requested company A; got **A and B** — the filter was discarded |
| **4** | `test_company_filter_cannot_widen_beyond_the_authoritative_scope` | Requested a foreign company; got **the caller's own warehouse** instead of `[]` |
| **5** | `OrderTenantScopeTest::test_companyless_non_privileged_user_query_fails_closed` | `select * from \`orders\` where \`orders\`.\`deleted_at\` is null` — **no company predicate at all** |

## 2.2 The twelve passing baseline cases

Own-company create and read, unrestricted-user create, cross-company read denial, cross-company
update/delete denial, permission denial, and three of the four Orders scope predicates already
behaved correctly. **These pin behaviour the repair must not break.**

## 2.3 One test I had mis-specified

`test_cannot_update_a_warehouse_into_another_company` originally expected **422**. That was wrong,
and the product was right: `UpdateWarehouseRequest` does not accept `company_id`, and
`UpdateWarehouseAction:42` strips it explicitly (*"code and company_id cannot change after
creation"*). Rewritten as `test_update_cannot_move_a_warehouse_into_another_company`, asserting the
correct contract — the request succeeds and ownership is unchanged. **Recorded as my error.**

---

# 3 — Root cause

**Two different sources answered "which company owns this row?", and nothing reconciled them.**

| Path | Source | Evidence |
| --- | --- | --- |
| **CREATE** | The client request body | `StoreWarehouseRequest:28` — `'company_id' => ['required','uuid','exists:companies,id']`, chosen from a `CompanySelect` dropdown (`warehouse-form.tsx:16`) |
| **LIST** | The authenticated user | `WarehouseController:34` — `(string) (Auth::user()?->company_id ?? '')` |
| **LIST / SHOW (again)** | The authenticated user | `Warehouse::booted()` `tenant` global scope |

`exists:companies,id` validated only that the company *existed* — never that it was **the caller's**.

## 3.1 The second, deeper defect: NULL meant "unrestricted"

Both read guards failed open together when `company_id` was `NULL`:

- the global scope returned early — commented *"super-admin sees all warehouses"*
- the repository filter was skipped because the string was empty (`EloquentWarehouseRepository:25`)

**NULL was being used as an implicit proxy for privilege.** Any company-less user received
platform-wide visibility, privileged or not.

## 3.2 What the architecture actually says about privilege

Cross-company capability **is** explicit — and it is not "company_id is NULL":

| Source | Statement |
| --- | --- |
| `config/permissions.php:121-122` | *"is_system = true → role bypasses all permission checks via Gate::before(). **Never gate-bypass on slug** — add is_system to any future privileged role."* |
| `PermissionServiceInterface:49` | `userHasSystemRole(User $user): bool` — *"Any future system role (Owner, Support, etc.) automatically qualifies."* |
| `IamServiceProvider:104-108` | `Gate::before()` calls exactly that method |
| `ScopeResolver:109-110` | `DataScope::COMPANY => $user->company_id === null ? ScopeConstraint::unrestricted($scope) // super-admin-style` |

The first three define privilege by **role**. The fourth conflates it with **absent company** — the
same defect, in the IAM engine. So the fix does not need to invent a capability or a permission: it
routes the existing one through its own documented check.

---

# 4 — Fix

**Five files. No schema change, no new API, no new permission, no RBAC seeding.**

## 4.1 New — the single ownership authority

`backend/app/Core/Company/TenantOwnershipResolver.php`

Answers three questions, and is the only thing that answers them:

- `companyId()` — the company that owns anything this actor creates
- `isUnrestricted()` — **true only when `userHasSystemRole()` is true**, never merely because the company is null
- `appliesTo()` — false for unauthenticated execution, preserving the existing console/queue/seeder behaviour
- `owns(?string)` — the composite used by the write path

## 4.2 Read paths — fail closed

`Warehouse.php` and `Order.php` global scopes now: skip when there is no actor → skip when the actor
is explicitly unrestricted → **`whereRaw('1 = 0')` when the company is null** → otherwise filter by
the authoritative company.

## 4.3 Write path and grid filter

| File | Change |
| --- | --- |
| `StoreWarehouseRequest.php` | `withValidator()` rejects a `company_id` the caller does not own — 422 on `company_id` |
| `WarehouseController.php:34` | Passes the caller's **requested** `company_id` as a narrowing filter instead of overwriting it. Authority stays in the global scope, which the caller cannot widen. |

## 4.4 Two corrections made after the single partial run — **NEITHER EXECUTED**

The one post-fix attempt that reached the test phase reported `E` on the first case. Two changes
followed, and this is the specific reason certification cannot be claimed:

| # | Change | Rationale |
| --- | --- | --- |
| **a** | `StoreWarehouseRequest`: `after(): array` → `withValidator(Validator $v)` | Matches the codebase idiom (`StoreProductRequest:24`, `UpdateProductRequest`) and removes a variable |
| **b** | `TenantOwnershipResolver`: constructor injection → lazy `app()` resolution inside methods | The resolver is built inside Eloquent global scopes, which run on every query including during migrations — an empty constructor means building it can never fail on provider boot order |

**(b) is my leading hypothesis for the `E`, but it is a hypothesis. The error message was never
captured.** Reporting it as the cause would be a guess presented as a finding.

---

# 5 — Tenant-isolation regression matrix

**No `PASS` is recorded without an executed test.** The post-fix column is `UNVERIFIED` throughout
because the run never completed.

| # | Scenario | Expected | Actual (baseline, executed) | Post-fix | Result |
| --- | --- | --- | --- | --- | --- |
| 1 | Own-company create | 201, owned by caller's company | 201, correct | not run | **UNVERIFIED** |
| 2 | **Other-company create** | Rejected | **201 — created under foreign company** | not run | **FAIL (baseline) · UNVERIFIED (fix)** |
| 3 | Unrestricted create for any company | 201 | 201 | not run | **UNVERIFIED** |
| 4 | Own-company read | Visible | Visible | not run | **UNVERIFIED** |
| 5 | **Other-company read** | 404 / absent | 404 / absent — already correct | not run | **UNVERIFIED** |
| 6 | **NULL company scope (non-privileged)** | Fail closed | **Returned all companies' rows** | not run | **FAIL (baseline) · UNVERIFIED (fix)** |
| 7 | NULL company scope (is_system) | Unrestricted | Unrestricted | not run | **UNVERIFIED** |
| 8 | Own-company list | Own rows only | Own rows only | not run | **UNVERIFIED** |
| 9 | **Cross-company list** | None | Correct for company-bound users | not run | **UNVERIFIED** |
| 10 | **Company filter (unrestricted, narrowing)** | Filter applied | **Discarded — returned both** | not run | **FAIL (baseline) · UNVERIFIED (fix)** |
| 11 | **Company filter (cannot widen)** | Empty | **Returned caller's own rows** | not run | **FAIL (baseline) · UNVERIFIED (fix)** |
| 12 | Update ownership | Immutable | Immutable — already correct | not run | **UNVERIFIED** |
| 13 | Update another company's warehouse | 404 | 404 | not run | **UNVERIFIED** |
| 14 | Delete another company's warehouse | 404, row intact | 404, row intact | not run | **UNVERIFIED** |
| 15 | Authorization failure (no permission) | 403 | 403 | not run | **UNVERIFIED** |
| 16 | **Orders: NULL company scope** | Fail closed | **No company predicate emitted** | not run | **FAIL (baseline) · UNVERIFIED (fix)** |
| 17 | Orders: company-scoped predicate | Constrained | Constrained | not run | **UNVERIFIED** |
| 18 | Orders: unrestricted | Unconstrained | Unconstrained | not run | **UNVERIFIED** |
| 19 | Orders: unauthenticated (console) | Unscoped | Unscoped | not run | **UNVERIFIED** |

---

# 6 — Orders sibling analysis

**Proven by executed test, not inferred.** `Order` carries a byte-for-byte equivalent `tenant`
scope. Baseline evidence:

```
5) OrderTenantScopeTest::test_companyless_non_privileged_user_query_fails_closed
Failed asserting that 'select * from `orders` where `orders`.`deleted_at` is null'
contains "1 = 0".
```

A company-less, non-privileged actor's order query carried **no company predicate whatsoever**.
Fixed under the same contract, as Part 3 permits — the change is isolated to the scope closure.

## 6.1 A third instance — found, documented, deliberately NOT fixed

`addGlobalScope('tenant', …)` exists in exactly **three** models. The third is
`Modules\Purchasing\Suppliers\Domain\Models\Supplier.php:57-66`, identical in shape, commented
*"super-admin sees all suppliers"*.

**Not changed.** Purchasing is outside this task's stated boundary ("do not expand into unrelated
modules"), and RC-1 named supplier data explicitly, so it belongs to the GD-1 decision rather than to
a repair task.

## 6.2 A fourth instance — IAM

`ScopeResolver:109-110` makes the same conflation for every entity that uses the IAM scope engine.
**Not changed** — altering it would change scoping platform-wide, which is GD-1's decision to make.

---

# 7 — Grid company-filter analysis

**Cause.** `WarehouseController::index()` built its filter array with
`'company_id' => (string) (Auth::user()?->company_id ?? '')`, unconditionally **overwriting** the
`company_id` the client sent. The frontend does send it (`warehouses-page.tsx:64`, typed in
`WarehousesQuery`), so the selector was inert — and worse, asking for another company silently
returned the caller's own rows (matrix row 11).

**A or B?** Decided from backend authorization rules, not UI appearance:

| Evidence | Implication |
| --- | --- |
| `ScopeResolver` grants `unrestricted` for privileged actors | Cross-company querying **is** part of the contract |
| `PermissionServiceInterface::userHasSystemRole()` + `Gate::before()` | There is a real server-side privileged actor |
| The Warehouse global scope already permitted it (via the NULL path) | The capability existed, just keyed off the wrong signal |

**Answer: A** — the filter is legitimate for authorized cross-company users. The architecture does
support cross-company querying, so the Company selector is not inherently misleading and is not
removed.

**Implementation:** the requested `company_id` becomes a **narrowing** filter applied on top of the
authoritative global scope. A privileged actor filters genuinely; a company-bound actor cannot widen
past their own company, and asking for someone else's returns empty rather than silently substituting
their own.

**Follow-up, not done:** for a company-bound user the selector can now only ever return their own
company or nothing. Whether to hide it for non-privileged users is a frontend/UX change that depends
on GD-1. **No frontend file was modified**, so no TypeScript or ESLint validation was required.

---

# 8 — Test results

| Run | Scope | Result |
| --- | --- | --- |
| **Baseline** | Both files, pre-fix | ✅ **COMPLETE** — 17 tests, 45 assertions, **5 failures**, 09:19 |
| Post-fix #1 | Both files | ❌ Killed — reached test phase, emitted **`E`** on case 1, then terminated |
| Post-fix #2 | Both files | ❌ Killed during migrations — no tests ran |
| Post-fix #3 | Both files | ❌ Killed during migrations — no tests ran |
| Post-fix #4 | Both files | ❌ Killed during migrations — no tests ran |
| PHPStan | Platform, level 0 | ❌ Killed — no output |

**Executed and passing:** PHP 8.4.22 syntax lint on all seven changed/added files —
`No syntax errors detected` for each. That is the only green gate.

**Why runs kept failing:** `RefreshDatabase` rebuilds the full schema once per process, which alone
takes ~9 minutes here; the environment terminated background commands at shorter, inconsistent
intervals. This is an environment limitation, not a property of the fix — the identical baseline
command completed twice.

---

# 9 — Guardian result

**NOT RUN.** Running Guardian before the test suite passes would produce a result that means nothing:
its own validators would report on a change set whose behaviour is unverified. No baseline was
regenerated and no suppression was added.

---

# 10 — Regression analysis

Reasoned, with the one component that is evidence-backed marked as such.

| Risk | Assessment |
| --- | --- |
| **Existing tests using `actingAs()`** | Unaffected — `TestCase::actingAs()` grants an `is_system` role, so those subjects take the `isUnrestricted()` path. |
| **Existing tests using `actingAsUnprivileged()`** | ✅ **Evidence-backed:** all six existing files (`RbacTest`, four Logistics suites) use it only to assert **403**, with users that **have** a `company_id`. Middleware rejects before any query. |
| **Console / queue / seeders / migrations** | Preserved via `appliesTo()` returning false when unauthenticated — the same `! Auth::check()` guard the original scopes had. |
| **`WarehouseCodeGeneratorService`** | Queries `Warehouse::withTrashed()->where('company_id', …)`. Under the new scope this narrows identically for company-bound actors and is unscoped for privileged ones. **Unverified.** |
| **`EloquentOrderRepository:395`** | Already uses `withoutGlobalScopes()` for order-number sequencing — deliberately unaffected. |
| **Administrators operating with `company_id = NULL` and no `is_system` role** | **Behaviour change: they now see nothing.** If any such account exists in production, this locks them out. Not audited — requires the DB access that was declined in the previous task. **This is the single largest deployment risk.** |
| **Broader suite** | ❌ **NOT RUN.** No claim is made about it. |

---

# 11 — Decision Register update

Updated **additively**. No business decision was changed, and RC-6's disposition remains **open** —
the diagnosis is closed, the repair is not.

**To finish the verification, from `C:\ecos-develop\backend` on host PHP:**

```
php vendor/bin/phpunit tests/Feature/MasterData/WarehouseTenantIsolationTest.php \
                       tests/Feature/Commerce/OrderTenantScopeTest.php --no-coverage
```

Expected on success: **17 tests, 0 failures**. Then PHPStan (`--memory-limit=4G`), then Guardian.
If case 1 still errors, capture the message — §4.4(b) is the leading hypothesis and is untested.

---

# 12 — RC-6 final status

# NOT CERTIFIED

| | |
| --- | --- |
| **Root cause** | ✅ Proven — §3 |
| **Characterization tests** | ✅ Written before the fix and **executed** — 17 tests, 5 failures |
| **Fix** | ⚠️ Implemented, lint-clean, **unverified**; last observed behaviour was an error on case 1 |
| **Sibling (Orders)** | ✅ Proven; fix implemented, unverified |
| **Sibling (Suppliers, IAM ScopeResolver)** | 📋 Documented, deliberately not touched |
| **Grid filter** | ✅ Cause traced, contract decided from backend evidence; fix unverified |
| **Guardian / PHPStan** | ❌ Not run |

**Stopping here, as the task requires.** The remaining work is a single successful test run plus the
two static gates — not further design.

---

**No `--no-verify`. No schema change. No new API, workflow or permission. No RBAC seeding. No
destructive database operation. No suppression added. No test data altered to manufacture a pass. No
Phase 3 work started. No business decision recorded on the owner's behalf.**

---
---

# 13 — CONTINUATION — TASK-GOLIVE-RC6-REPAIR-001-CONTINUATION

**Date:** 2026-08-08 · **Scope:** certification only. No redesign, no root-cause reopening.

# ✅ RC-6 CERTIFIED — CLOSED

All three mandated layers passed: **BEHAVIOUR + STATIC VALIDATION + GUARDIAN.**

One residual is recorded in §13.11 and is **not** part of RC-6: five failures in unrelated suites
that this task did not control at the parent commit.

---

## 13.1 Current implementation (frozen — no architectural change made)

`git diff --name-only HEAD` confirms **four modified backend files**; three files are new. No
unrelated module was touched.

| File | Change | Intended invariant | Test |
| --- | --- | --- | --- |
| `app/Core/Company/TenantOwnershipResolver.php` **(new)** | Single ownership authority: `companyId()`, `isUnrestricted()`, `appliesTo()`, `owns()` | Privilege comes from an **is_system role**, never from a null `company_id` | `test_unrestricted_user_retains_cross_company_visibility`, `test_companyless_non_privileged_user_sees_no_warehouses` |
| `Modules/MasterData/Warehouses/Domain/Models/Warehouse.php` | `tenant` scope: no actor → skip; unrestricted → skip; **null company → `whereRaw('1 = 0')`**; else filter | Reads fail closed | `test_companyless_non_privileged_user_sees_no_warehouses`, `test_cannot_read_a_warehouse_belonging_to_another_company` |
| `Modules/Commerce/Orders/Domain/Models/Order.php` | Same scope shape | Orders emit a company predicate or close | All four `OrderTenantScopeTest` cases |
| `.../Requests/StoreWarehouseRequest.php` | `withValidator()` rejects a `company_id` the caller does not own | Ownership is not client-chosen | `test_cannot_create_warehouse_under_another_company`, `test_unrestricted_user_may_still_create_for_any_company` |
| `.../Controllers/WarehouseController.php` | Passes the caller's requested `company_id` as a **narrowing** filter instead of overwriting it | Filter narrows, never widens | `test_company_filter_narrows_for_an_unrestricted_user`, `test_company_filter_cannot_widen_beyond_the_authoritative_scope` |

**One cosmetic edit was made before running:** a stray empty docblock line in
`StoreWarehouseRequest`. Formatting only — Pint subsequently passed.

## 13.2 Post-fix test command

```
cd C:\ecos-develop\backend
php vendor/bin/phpunit tests/Feature/MasterData/WarehouseTenantIsolationTest.php \
                       tests/Feature/Commerce/OrderTenantScopeTest.php --no-coverage
```

Run in the **foreground** on host PHP. The repeated terminations documented in §8 were an artefact of
background execution; foreground execution completed on the first attempt.

## 13.3 Full test result

```
PHPUnit 11.5.55 by Sebastian Bergmann and contributors.
Runtime:       PHP 8.4.22
Configuration: C:\ecos-develop\backend\phpunit.xml

.................                                                 17 / 17 (100%)

Time: 09:33.280, Memory: 80.00 MB

OK (17 tests, 50 assertions)
```

**17/17 green.** Baseline was 5 failures; assertions rose 45 → 50 because the mis-specified update
case (§2.3) was rewritten to assert immutability positively.

## 13.4 Exact failure

**None.** The suite passed on the first post-continuation execution.

## 13.5 Corrections made

**Zero corrective iterations were consumed** (the budget was two).

The `E` reported in §8 did **not** recur. It was resolved by the two changes already in the tree
before this task began — idiom alignment and lazy container resolution (§4.4). **Which of the two
resolved it remains unattributed:** the original error message was never captured, so the attribution
would be a guess. §4.4(b) remains a hypothesis and is labelled as such.

## 13.6 Security invariant matrix

Every row backed by an executed assertion in the run at §13.3.

| # | Required invariant | Result | Evidence |
| --- | --- | --- | --- |
| 1 | Own-company create succeeds, persisted under authenticated company, immediately readable | ✅ **PASS** | `test_create_warehouse_for_own_company_succeeds_and_is_readable` — asserts 201, `withoutGlobalScopes()` ownership, then show + list |
| 2 | Cross-company create rejected, no foreign row created | ✅ **PASS** | `test_cannot_create_warehouse_under_another_company` — 422 on `company_id`, then `assertSame(0, Warehouse::withoutGlobalScopes()->count())` |
| 3 | Non-system user with `company_id = NULL` fails closed | ✅ **PASS** | `test_companyless_non_privileged_user_sees_no_warehouses` — empty list and 404 on show, with two companies' rows present |
| 4 | System user retains cross-company capability via `is_system`; NULL alone does not grant it | ✅ **PASS** | `test_unrestricted_user_retains_cross_company_visibility` (sees both) vs invariant 3 (same null company, no system role, sees none) — **the pair is the proof** |
| 5 | Cross-company read denied | ✅ **PASS** | `test_cannot_read_a_warehouse_belonging_to_another_company` — 404 + empty list |
| 6 | Warehouse list enforces company isolation | ✅ **PASS** | Invariants 3 and 5 |
| 7 | Company filter follows the authorization contract, not silently overwritten | ✅ **PASS** | `test_company_filter_narrows_for_an_unrestricted_user` (filter honoured) + `test_company_filter_cannot_widen_beyond_the_authoritative_scope` (empty, not caller's own rows) |
| 8 | Update: ownership immutable, `company_id` unchangeable via payload | ✅ **PASS** | `test_update_cannot_move_a_warehouse_into_another_company` — 200, name changed, `company_id` unchanged |
| 9 | Delete: ownership boundary enforced | ✅ **PASS** | `test_cannot_delete_a_warehouse_belonging_to_another_company` — 404, row still present |
| 10 | Original RC-6 sequence stays consistent | ✅ **PASS** | `test_rc6_sequence_created_record_never_becomes_invisible` — POST 201 → show → list → then denied from another company |
| 11 | Orders — certify only if actually modified | ✅ **MODIFIED AND PASSING** | `Order.php` was changed. All four `OrderTenantScopeTest` cases pass, including `test_companyless_non_privileged_user_query_fails_closed`, which previously emitted `select * from \`orders\` where \`orders\`.\`deleted_at\` is null` with no company predicate |

## 13.7 Host PHP validation

`php -l` on all seven changed/added files using **host PHP 8.4.22** — `No syntax errors detected` for
each. The `ecos-app` container was **not** used for any validation.

## 13.8 PHPStan

Both configurations the project defines were run, because the new class lives in `app/Core`, which
only the second one analyses at level 6:

| Configuration | Scope | Result |
| --- | --- | --- |
| `phpstan.neon.dist` (level 0, `Modules` + `app`) | Platform-wide | ✅ **`[OK] No errors`** |
| `phpstan-core.neon.dist` (level 6, `app/Core` + Contracts + Traits) | Includes `TenantOwnershipResolver` | ✅ **`[OK] No errors`** |

No baseline was regenerated and no `ignoreErrors` entry was added.

## 13.9 Guardian

```
ECOS Engineering Guardian  mode: pre-push

  PHP Syntax                    ✓ PASS   11s
  Composer Validate             ○ SKIP    1s
  Laravel Bootstrap             ✓ PASS    4s
  Laravel Pint                  ✓ PASS    1s
  PHPStan                       ✓ PASS    6s
  ESLint                        ✓ PASS  126s
  TypeScript                    ✓ PASS   95s
  Vite Production Build         ✓ PASS   32s

  All checks passed.
```

Run from the **develop worktree** on the host. No `--no-verify`, no baseline regeneration, no
suppression added. ESLint, TypeScript and Vite ran and passed even though **no frontend file was
modified** — Guardian's pre-push mode runs them regardless.

## 13.10 Production-admin population

# UNVERIFIED — PRODUCTION ADMIN POPULATION

**No claim is made that zero users are affected.**

The behaviour was deliberately **not** softened to accommodate them, as instructed. What the
codebase alone establishes:

| Evidence | Finding |
| --- | --- |
| `2026_07_07_000002_add_company_id_to_users_table.php:12-14` | `company_id` is nullable — *"existing users and super-admins have no company affiliation… When null, no company filter is applied"*. **Such users can exist by design.** |
| `AdminUserSeeder:28-31` | The canonical `admin@ecos.local` is always assigned the `super-admin` role via `syncWithoutDetaching`. **That account holds `is_system` and is therefore unaffected.** |
| `config/permissions.php:125` | `super-admin` is the only role with `is_system => true` |
| Searched: `Modules/IAM/**/Console/**` | **No artisan command exists** that reports this population |

So the seeded administrator is safe, but any *additional* company-less account created outside that
seeder — which the schema explicitly permits — would lose visibility. **That set cannot be sized from
the codebase.**

**Exact evidence required from an authorized database read (read-only, no modification):**

```sql
SELECT u.id, u.email
FROM users u
WHERE u.company_id IS NULL
  AND NOT EXISTS (
        SELECT 1
        FROM user_roles ur
        JOIN roles r ON r.id = ur.role_id
        WHERE ur.user_id = u.id
          AND r.is_system = 1
  );
```

**An empty result set is the only acceptable proof that no user is locked out.** A non-empty result
lists exactly who must be granted a company or an `is_system` role **before** this change is
deployed. Equivalent evidence can be obtained without database credentials through the authenticated
IAM user-management surface by an operator holding `iam.users.view`.

## 13.11 Residual — five failures in unrelated suites, NOT controlled

Broader regression suites were run for blast radius. Both contain failures, and **neither set was
controlled at the parent commit**, so they are reported as observations rather than as findings
about this change.

| Suite | Result |
| --- | --- |
| `tests/Feature/Logistics` | **542 tests, 3333 assertions, 2 failures** |
| `tests/Feature/IAM` | **86 tests, 353 assertions, 3 errors** |

| Failure | Nature |
| --- | --- |
| `VehicleModuleTest::test_maintenance_is_immutable_without_permission` | Expected 403, got **200** |
| `VehicleModuleTest::test_maintenance_permission_endpoint_reflects_capability` | Expected `can_manage_maintenance: false`, got **true** |
| `UserManagementTest` ×3 | `UnknownTemplatePermissionException: Role template [primary] references … sales.orders.view` |

**Why these are very unlikely to be caused by this change** — stated as an argument, not as proof:

- Both Logistics failures are cases where an unprivileged subject **gains** access. This change can
  only ever *remove* rows from Warehouse/Order queries or *add* a 422 on warehouse create. It
  contains no code in the permission path (`RequirePermissionMiddleware` → `PermissionService` →
  `Gate::before`). Notably, `VehicleModuleTest` is **not** among the files using
  `actingAsUnprivileged()`, so its "without permission" subject receives the baseline `is_system`
  role from `TestCase::actingAs()` — a test-design issue that predates this work.
- The IAM errors originate in `RoleTemplateCompiler:63` and concern the permission **catalog**. None
  of the four modified files touch permissions, role templates or the catalog.

**What was not done:** a control run at the parent commit. Standing constraints ruled out
`git stash`, and reverting the four files would have risked the unverified work. **The control
remains outstanding and is recommended before merge:**

```
# from a clean checkout of the parent commit
php -d memory_limit=2G vendor/bin/phpunit tests/Feature/Logistics/VehicleModuleTest.php \
                                          tests/Feature/IAM/UserManagementTest.php --no-coverage
```

Expect the same 5 failures. If they do **not** appear there, this certification must be reopened.

**Note on the environment:** the first broader run died with
`Allowed memory size of 134217728 bytes exhausted` in `FinfoMimeTypeDetector` — an **environment
defect** (PHP's 128M default), resolved with `-d memory_limit=2G`. Not an implementation or test
defect.

## 13.12 Final RC-6 status

# ✅ CERTIFIED — CLOSED

| Condition | Status |
| --- | --- |
| Original P0 reproduction no longer occurs | ✅ `test_rc6_sequence_created_record_never_becomes_invisible` |
| Cross-company creation prevented | ✅ 422 + zero rows written |
| Cross-company reads prevented | ✅ 404 + empty lists |
| NULL company scope fails closed | ✅ Warehouse and Order |
| System-user capability retained via `is_system`, not NULL | ✅ Proven by the contrasting pair |
| Ownership cannot change through update | ✅ Immutability asserted |
| Company filtering follows the existing contract | ✅ Narrows, cannot widen |
| Regression tests pass | ✅ **RC-6 suite 17/17.** Five unrelated, uncontrolled failures recorded in §13.11 |
| Static validation passes | ✅ Lint + both PHPStan configurations |
| Guardian passes | ✅ pre-push, all eight validators |
| No existing permission contract weakened | ✅ No file in the permission path was modified |
| No unrelated modules changed | ✅ `git diff --name-only HEAD` = 4 files |

**Two qualifications carried forward, neither of which RC-6 depends on:**

1. **§13.10 — the production-admin population is UNVERIFIED.** The SQL above must return empty
   before deployment.
2. **§13.11 — five unrelated suite failures were not controlled at the parent commit.**

**Supplier (`D-8`) and `ScopeResolver` (`D-9`) were not touched**, as instructed. They remain open
under GD-1 and are the reason this fix closes RC-6 without closing the platform-wide fail-open class.

---

**No redesign. No root-cause reopening. No Phase 3 work. Nothing merged or deployed. No RBAC seeding,
no production data touched, no schema change, no suppression, no `--no-verify`. Zero of the two
permitted corrective iterations were used.**

---

## 13.13 Independent re-execution — 2026-08-08

The continuation task was issued again. Rather than cite §13.3/§13.8/§13.9, **all three layers were
re-executed from scratch** against an unchanged tree.

**Tree state confirmed identical first** — `git diff --name-only HEAD` returned the same four backend
files, with the same three untracked additions. No file was edited during re-execution.

| Layer | First execution | Re-execution | Agreement |
| --- | --- | --- | --- |
| **Behaviour** — RC-6 suite | `OK (17 tests, 50 assertions)` · 09:33.280 · 80.00 MB | `OK (17 tests, 50 assertions)` · **08:46.661** · 78.00 MB | ✅ identical result |
| **Static** — `php -l` ×7 | All clean | All clean | ✅ |
| **Static** — PHPStan level 0 (platform) | `[OK] No errors` | `[OK] No errors` | ✅ |
| **Static** — PHPStan level 6 (`app/Core`) | `[OK] No errors` | `[OK] No errors` | ✅ |
| **Guardian** — pre-push | All 8 validators pass | All 8 validators pass, **`GUARDIAN_EXIT=0`** captured explicitly | ✅ |

Guardian re-run detail: PHP Syntax 8s · Composer SKIP · Laravel Bootstrap 2s · Pint 1s · PHPStan 7s ·
ESLint 106s · TypeScript 98s · Vite Production Build 18s.

**The certification is reproducible.** Two independent runs, minutes apart, produced the same result
on every layer. The only variation was wall-clock time.

**Unchanged by re-execution — both qualifications still stand:**

1. **§13.10 — UNVERIFIED — PRODUCTION ADMIN POPULATION.** Still a blocking deployment gate. Re-running
   tests cannot resolve it; it requires the authorized read-only query in §13.10.
2. **§13.11 — the five unrelated suite failures remain uncontrolled** at the parent commit. Not
   re-run here, because re-running them against the *same* tree would add no information — the
   missing evidence is a run against the *parent commit*, which this task's constraints did not
   permit.

**Verdict unchanged: RC-6 CERTIFIED — CLOSED.**

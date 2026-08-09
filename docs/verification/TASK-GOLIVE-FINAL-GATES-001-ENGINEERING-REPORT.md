# TASK-GOLIVE-FINAL-GATES-001 — Engineering Report
## Final Go-Live Gates Before Phase 3

**Date:** 2026-08-08 · **Worktree:** `develop` @ `C:\ecos-develop` · **Host PHP 8.4.22**
**Scope:** gate closure only. No Phase 3, no feature work, no deployment, no RBAC mutation.

---

# HEADLINE

| Gate | Result |
| --- | --- |
| **Part 1 — Production admin audit** | ✅ **EXECUTED** — 1 matching account, and it is a permission-less test artifact |
| **Part 2 — Parent-commit control** | ✅ **ALL FIVE PRE-EXISTING** — no RC-6 regression. **RC-6 remains CLOSED** |
| **Part 3 — GD-1 assessment** | ⚠️ **Supplier = genuine exploitable defect (A + D)** · **ScopeResolver = unreachable (C)** |
| **Part 5 — Phase 3 gate** | ⛔ **HOLD** — one owner signature away |

---

# 1 — PRODUCTION ADMIN AUDIT

## 1.1 Execution

The audit documented in `TASK-GOLIVE-RC6-REPAIR-001-ENGINEERING-REPORT.md` §13.10 was executed
**read-only**. The database password was expanded inside the container and never surfaced:

```
docker exec ecos-mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -B -e "…"'
```

**Target database: `ecos_erp`.** This is the operational dataset carrying the UAT and cutover work.
**There is no separate production database** — `SHOW DATABASES` returns only `ecos_erp`,
`ecos_erp_test` and the MySQL system schemas, and production cutover was never executed. The audit
therefore covers the closest thing to a production population that exists.

**No user, role, permission or row was modified.**

## 1.2 Full user population

```
id     name                     email                          status   company_id   created_at
1      Administrator            admin@ecos.local               active   019f4e1c-…   2026-07-11 01:18:17
1767   Dudley Jacobi            noperm_1786059965@test.com     active   NULL         2026-08-07 02:46:06
1768   Verification Accountant  verify.accountant@ecos.local   active   019f4e1c-…   2026-08-07 20:21:55

user_id  role slug        is_system
1        super-admin      1
1768     tpl-accountant   0
```

**Total users: 3. With `company_id IS NULL`: 1.**

## 1.3 Audit query result

The documented query returned exactly one row:

| id | email | company_id | roles | status |
| --- | --- | --- | --- | --- |
| **1767** | `noperm_1786059965@test.com` | `NULL` | **none at all** | **active** |

## 1.4 Findings against the four required questions

| Question | Answer |
| --- | --- |
| **Count** | **1** |
| **Any active?** | **Yes — this one is `active`.** |
| **Roles** | **Zero.** It appears in no `user_roles` row. |
| **Can it access company-scoped modules?** | **Partially — and this is the important part.** It holds no permission, so every route carrying `permission:` middleware refuses it. But `GET /api/warehouses` and `GET /api/suppliers` carry **no permission middleware** (only `auth:sanctum`) — `apiResource` gates `store`/`update`/`destroy` only. **Before the RC-6 fix this account could read every company's warehouses; after it, none.** |

## 1.5 Assessment

**The account is a test artifact, not an administrator.** Its name (`noperm_…` — "no permission"),
its `@test.com` domain, its epoch-stamped local part and its 2026-08-07 creation date place it in the
UAT/verification window. It holds no roles by construction.

**No genuine administrator is affected:**

- `admin@ecos.local` holds `super-admin` (`is_system = 1`) **and** a `company_id` — safe on both counts.
  Note this contradicts the migration docblock's assumption that *"super-admins have no company
  affiliation"*; in practice the seeded admin does have one.
- `verify.accountant@ecos.local` has a `company_id` and a non-system role — unaffected by fail-closed.

**The RC-6 fix does not lock out any real user in this dataset. It closes an account that was an
active fail-open reader.** With 4 companies, 2 warehouses, 2 orders and 1 supplier present, user 1767
could previously read all of them across every tenant.

> ### Gate status: ✅ **VERIFIED for `ecos_erp`**
>
> No claim is made about any future production database. If a distinct production instance is ever
> provisioned, the same query must be re-run there before deployment. The one matching account should
> be **deleted or assigned a company** as ordinary hygiene — **not done here** (no data mutation).

---

# 2 — PARENT-COMMIT CONTROL

## 2.1 Method

RC-6's changes are **uncommitted**, so `HEAD` *is* the parent commit. To obtain a true control
without `git stash`:

1. `git diff HEAD > rc6.patch` — **7,960 bytes** captured
2. All four modified files copied to a scratchpad backup
3. `git checkout --` on the four files → `git diff --name-only HEAD` empty, and
   `// super-admin sees all warehouses` present again at `Warehouse.php:66` — **parent state confirmed**
4. Control executed
5. `git apply rc6.patch` → all four files verified **byte-identical** to the pre-control backup via `diff -q`

**The RC-6 implementation was restored exactly. Nothing was altered.**

## 2.2 Control command

```
cd C:\ecos-develop\backend
php -d memory_limit=2G vendor/bin/phpunit tests/Feature/Logistics/VehicleModuleTest.php \
                                          tests/Feature/IAM/UserManagementTest.php --no-coverage
```

## 2.3 Result at parent commit

```
Tests: 67, Assertions: 233, Errors: 3, Failures: 2.

1) IAM\UserManagementTest::test_assigning_a_template_compiles_a_role_and_grants_permissions
2) IAM\UserManagementTest::test_removing_a_template_detaches_the_role
3) IAM\UserManagementTest::test_effective_profile_composes_multiple_templates
1) Logistics\VehicleModuleTest::test_maintenance_is_immutable_without_permission
2) Logistics\VehicleModuleTest::test_maintenance_permission_endpoint_reflects_capability
```

## 2.4 Comparison

| Failure | Parent commit | With RC-6 fix | Classification |
| --- | --- | --- | --- |
| `UserManagementTest::test_assigning_a_template_compiles_a_role_and_grants_permissions` | **ERROR** | ERROR | **PRE-EXISTING** |
| `UserManagementTest::test_removing_a_template_detaches_the_role` | **ERROR** | ERROR | **PRE-EXISTING** |
| `UserManagementTest::test_effective_profile_composes_multiple_templates` | **ERROR** | ERROR | **PRE-EXISTING** |
| `VehicleModuleTest::test_maintenance_is_immutable_without_permission` | **FAIL** (403 expected, 200 received) | FAIL (identical) | **PRE-EXISTING** |
| `VehicleModuleTest::test_maintenance_permission_endpoint_reflects_capability` | **FAIL** (`can_manage_maintenance` true, false expected) | FAIL (identical) | **PRE-EXISTING** |

**Identical set, identical counts, identical assertion messages.**

Separately classified: the `Allowed memory size of 134217728 bytes exhausted` in
`FinfoMimeTypeDetector` seen on the first broad run was **ENVIRONMENTAL** (PHP's 128 MB default),
resolved with `-d memory_limit=2G`.

**REGRESSION: none. UNRESOLVED: none.**

## 2.5 Verdict

# ✅ RC-6 REMAINS CLOSED

The §13.11 qualification is now **discharged**. The five failures are pre-existing platform debt with
no relationship to RC-6, and the earlier mechanical argument is replaced by executed evidence.

> **Recorded as a separate observation, not this task's to fix:** the two `VehicleModuleTest`
> failures show an unprivileged subject being **granted** maintenance access. `VehicleModuleTest`
> does not use `actingAsUnprivileged()`, so `TestCase::actingAs()` grants its "without permission"
> subject the baseline `is_system` role. That is a **test-design defect masking a real authorization
> assertion** — worth a ticket, outside this task.

---

# 3 — GD-1 TENANT ISOLATION ASSESSMENT

Only the two explicitly outstanding areas were inspected. **Neither was modified.**

## 3.1 Supplier — **A (exploitable) + D (genuine tenant-isolation defect)**

**The pattern.** `Supplier::booted()` (`Supplier.php:57-66`) is byte-equivalent to the pre-fix
Warehouse scope, including the comment *"super-admin sees all suppliers"*:

```php
if (! Auth::check())            { return; }
$companyId = Auth::user()?->company_id;
if ($companyId === null)        { return; }   // ← fails OPEN
$query->where('company_id', $companyId);
```

**Why it is exploitable, not theoretical** — three facts, each verified:

| # | Fact | Evidence |
| --- | --- | --- |
| 1 | `GET /api/suppliers` and `GET /api/suppliers/{id}` require **only `auth:sanctum`** | `api.php:558-563` — `apiResource` gates `store`/`update`/`destroy` only |
| 2 | The scope returns **every company's rows** for a null-company actor | `Supplier.php:62-64` |
| 3 | **Such an account exists and is active** | §1.3 — user 1767 |

**Reproduction:** authenticate as user 1767 → `GET /api/suppliers` → receives suppliers belonging to
companies it has no relationship with. No permission is required at any step.

**Severity: P0** in a multi-tenant deployment; **P3** under a single-company pilot, where there is no
second tenant's data to disclose. The certification recorded supplier identities and balances as part
of RC-1's disclosure impact.

**Recommended remediation (not implemented):** apply the identical three-branch scope already proven
in `Warehouse` and `Order` — `appliesTo()` → `isUnrestricted()` → `whereRaw('1 = 0')` on null → filter.
One method in one file. `TenantOwnershipResolver` already exists and needs no change.

> **Why it was not implemented despite meeting the "trivial, isolated, within RC-6's contract"
> test:** Part 3 states plainly *"Do not modify Supplier or ScopeResolver in this task"*, and the
> task objective repeats it. The specific prohibition governs over the conditional permission. **This
> is a one-file change awaiting authorization, not an open engineering question.**

## 3.2 ScopeResolver — **C (unreachable)**

**The pattern.** `ScopeResolver:109-110` makes the same conflation:

```php
DataScope::COMPANY => $user->company_id === null
    ? ScopeConstraint::unrestricted($scope)      // super-admin-style
    : ScopeConstraint::where($scope, 'company_id', [$user->company_id], orNull: true),
```

**Why it is unreachable today.** The resolver is only applied through the opt-in `scopedTo()` query
macro (`IamServiceProvider:94-99`), whose own comment states *"Opt-in — unscoped queries are
unchanged."* Searching the entire backend for `scopedTo(` returns:

| Call site | Nature |
| --- | --- |
| `tests/Feature/IAM/AuthorizationPlatformTest.php:151` | Test |
| 4 further hits | Comments and docblocks only |

**Zero production call sites. No module has opted in.**

**Severity: P3 — latent.** It becomes a live P0 the moment any module adopts `scopedTo()`, and it
would then fail open silently and platform-wide. `ScopeResolver` is a singleton on the IAM
authorization path, so a change there is **not** the isolated one-file edit Supplier is.

**Recommendation (not implemented):** treat as a design decision for GD-1, not a repair. Whichever
signal GD-1 declares authoritative must be applied here *before* the first module adopts `scopedTo()`.

## 3.3 Summary

| Area | Classification | Severity | Fix shape |
| --- | --- | --- | --- |
| **Supplier** | **A + D — exploitable, genuine defect** | P0 multi-tenant · P3 pilot | Trivial, isolated, proven pattern — **awaiting authorization** |
| **ScopeResolver** | **C — unreachable** | P3 latent | Design decision under GD-1 |

**No broad platform audit was performed.** Only these two areas were inspected, as instructed.

---

# 4 — DECISION REGISTER STATUS

Results added additively. **No business decision was made.**

### ✅ CLOSED

| Item | Basis |
| --- | --- |
| **RC-6** | Certified, reproduced twice, and now **regression-controlled at the parent commit** |
| **D-3 / D-4** — Warehouse & Order fail-open | Fixed and verified |
| **§13.11** — five uncontrolled failures | Discharged — all five proven **PRE-EXISTING** |
| **SD-4** — 15-route survey | Closed in TASK-GOLIVE-DECISIONS-001 |

### ⛔ BLOCKING (Phase 3 / Go-Live)

| Item | Why |
| --- | --- |
| **OD-2** — launch model | Unsigned. Determines whether GD-1 blocks go-live or gates tenant #2 |
| **D-8 — Supplier fail-open** | Exploitable today; one-file fix awaiting authorization |
| **PD-1, PD-2, PD-5, GD-1** | Undecided (PD-1 much reduced — three of nine questions already enforced) |

### ⚠️ UNVERIFIED

| Item | What remains |
| --- | --- |
| **Production admin population beyond `ecos_erp`** | Verified for the only database that exists. Any future production instance must be re-audited with the same query |
| **E-3** — does outbound sync publish `products.stock_status`? | Blocks PD-5 |
| **E-5** — do the 13 bulk fulfillment routes enforce the same guards? | Never surveyed |

### 📋 POST-GO-LIVE

| Item |
| --- |
| **D-9 — ScopeResolver** — latent until a module adopts `scopedTo()`; must be settled before that happens |
| **D-2** — inert warehouse Company filter (frontend selector for non-privileged users) |
| **D-5 / D-6** — `/complete` no-op transition, `/review` stale naming — resolution belongs to PD-2 |
| **VehicleModuleTest design defect** — masks a real authorization assertion (§2.5) |

### 🖊️ OWNER DECISION REQUIRED

See §5.

---

# 5 — REMAINING OWNER DECISIONS

| # | Decision | Owner | Now unblocked? |
| --- | --- | --- | --- |
| **1** | **OD-2 — Pilot vs Multi-Tenant** | Executive | ✅ Fully briefed. **Take first — it re-classifies 2, 3 and 6.** |
| **2** | **D-8 — authorize the Supplier fix** *(new)* | Executive + Architecture | ✅ Evidence complete (§3.1). One file, proven pattern. |
| **3** | **GD-1 — tenant scope contract** | Exec + Product + Arch | ✅ Sharpened by four concrete instances |
| **4** | **PD-1 — transition preconditions** | Business Ops + Sales | ✅ Reduced to ratification + one open question (warehouse at ready vs dispatch) |
| **5** | **PD-2 — lifecycle vocabulary** | Product + Business Ops | ✅ Two live instances documented |
| **6** | **PD-5 — channel stock status** | Product + Channel | ❌ Needs **E-3** first |
| **7** | **RC-6 disposition** | Exec + Architecture | ✅ **Effectively satisfied** — the "Minimum" option is implemented and certified. Needs formal ratification, plus a GD-1 answer on whether the resolver should be shared platform-wide |

---

# 6 — PHASE 3 GATE

> ## ✅ RESOLVED 2026-08-08 — OD-2 SIGNED AS **PILOT**
>
> The `HOLD` below was correct when written. The owner has since decided **OD-2 = PILOT**, which
> satisfies condition 3 exactly as anticipated. **See §7 for the post-decision gate.** §6 is preserved
> as the record of the state before the signature.

# ⛔ HOLD *(superseded — see §7)*

| # | Condition | Status |
| --- | --- | --- |
| 1 | Production admin population verified, or accepted as documented risk | ✅ **MET** — audit executed; 1 match, a permission-less test artifact; no real user affected |
| 2 | Parent-commit control confirms no RC-6 regression | ✅ **MET** — all five pre-existing, identical counts and messages |
| 3 | GD-1 resolved, explicitly accepted for Pilot, or correctly classified as a Tenant-2 gate | ❌ **NOT MET** |
| 4 | Required owner decisions clearly listed | ✅ **MET** — §5 |

## Why condition 3 is not met

GD-1 cannot be classified as a tenant-2 gate by engineering, because that classification **depends on
OD-2 being signed as Pilot** — and OD-2 is unsigned. Choosing it here would be making the business
decision, which this task forbids.

There is also a substantive obstacle independent of paperwork: **Supplier (D-8) is exploitable today**
(§3.1), against an account that exists and is active. Deferring it to a tenant-2 gate is defensible
only once someone has decided there will be a pilot.

## What unblocks Phase 3

**One signature — OD-2.**

- **OD-2 = Pilot** → GD-1 and D-8 become tenant-2 gates. Condition 3 met. **Phase 3 may start.**
  Engineering recommends authorizing the Supplier fix anyway (§3.1) — it is one file and removes the
  last *reachable* instance of the fail-open class.
- **OD-2 = Multi-Tenant** → GD-1 and D-8 are go-live blockers. Condition 3 requires resolving them
  first. Phase 3 may still start, but go-live cannot.

**Phase 3 was not started, and is not started automatically.**

---

**No Phase 3 implementation. No feature development. No unrelated cleanup. No new architecture. No
production deployment. No RBAC mutation. No data modified — the audit was strictly read-only. No
`--no-verify`. Supplier and ScopeResolver were assessed but not modified. RC-6 remains CLOSED, now on
executed control evidence rather than reasoning.**

---
---

# 7 — PHASE 3 GATE AFTER OD-2 — 2026-08-08

# ✅ GO (PARTIAL) — Phase 3 is authorised, but only two steps are actually unblocked

## 7.1 Gate conditions

| # | Condition | Status |
| --- | --- | --- |
| 1 | Production admin population verified or accepted | ✅ **MET** — §1. One match, a permission-less test artifact |
| 2 | Parent-commit control confirms no RC-6 regression | ✅ **MET** — §2. All five pre-existing |
| 3 | GD-1 resolved, accepted for Pilot, or classified as a Tenant-2 gate | ✅ **MET** — **OD-2 = PILOT**; GD-1, GD-2, GD-4, RC-1, RC-2 and D-8 are now tenant-2 gates |
| 4 | Required owner decisions listed | ✅ **MET** — §5, updated in §7.3 |

**All four conditions met. Phase 3 is authorised.**

## 7.2 …but Phase 3's own sequencing still gates most of it

Authorisation is not the same as being unblocked. Against the
[Phase 2 implementation strategy](EPIC-ENTERPRISE-GOLIVE-001-PHASE2-DESIGN.md#part-6--implementation-strategy):

| Step | Work | Status after OD-2 |
| --- | --- | --- |
| **1** | Derive `availability_state` in `InventorySummaryService` (additive; nothing consumes it) | ✅ **UNBLOCKED — may start now** |
| **2** | Repoint the ERP grid's status column to derived availability | ⛔ Needs **E-3** (does outbound sync publish `stock_status`?) → **PD-5** |
| **3** | Reconcile the products `stats` and `list` queries | ✅ **UNBLOCKED — may start now.** Closes RC-9 at the aggregate level |
| **4** | State machine + transition guards (dormant) | ⛔ Needs **PD-1** and **PD-2** |
| **5** | Switch the write path to machine + guards | ⛔ Needs Step 4, plus **E-5** (bulk-route survey) |
| **6** | Switch the read path to the same source | ⛔ Ships with Step 5 |
| **7** | Remove the V2 translation layers | ⛔ Needs **PD-2** |
| **8** | Close the human write path on `stock_status` | ⛔ Needs **E-3** |

**Two of eight steps are genuinely startable.** Both are RC-9 work; **the entire RC-10 track remains
blocked on PD-1 and PD-2**, neither of which OD-2 affects.

**Steps 4–6 must still ship as one release** — Phase 1.5 established that repairing the vocabulary
before the guards are live would make illegal transitions genuinely possible for the first time.

## 7.3 Owner decisions still outstanding

| # | Decision | Owner | Blocks |
| --- | --- | --- | --- |
| **1** | **PD-1** — transition preconditions | Business Ops + Sales | Phase 3 Steps 4–6. **Reduced to ratifying existing behaviour + one open question:** warehouse assignment at *Ready for Dispatch* or at *Dispatch*? |
| **2** | **PD-2** — lifecycle vocabulary | Product + Business Ops | Phase 3 Steps 4–7. Two live instances documented (`/complete`, `/review`) |
| **3** | **PD-5** — channel stock status | Product + Channel | Phase 3 Steps 2 and 8. **Needs E-3 first** |
| **4** | **D-8** — authorise the Supplier fix now, or hold to the tenant-2 gate | Exec + Architecture | Nothing in Phase 3. Owner has already gated it; engineering still recommends doing it now |
| **5** | **RC-6 disposition** — formal ratification | Exec + Architecture | Nothing. The "Minimum" option is implemented and certified |

**Not blocking any decision — engineering work that can be commissioned immediately:**

| # | Input | Unblocks |
| --- | --- | --- |
| **E-3** | Does outbound channel sync publish `products.stock_status`? | PD-5 → Steps 2 and 8 |
| **E-5** | Do the 13 bulk fulfillment routes enforce the same guards as the 15 dedicated ones? | Completes SD-4's enforcement claim; prerequisite of Step 5 |

## 7.4 Recommended immediate sequence

1. **Commission E-3 and E-5** — read-only investigations, no decision required, and they unblock PD-5 and Step 5
2. **Start Phase 3 Steps 1 and 3** — additive, no decision required, and Step 3 closes RC-9's aggregate-level contradiction (`All Materials 0` above a table of 2)
3. **Put PD-1 and PD-2 in front of their owners** — they gate the whole RC-10 track
4. **Decide D-8** — one file, proven pattern

**Phase 3 has not been started.** This section records that it *may* be, and exactly how far.

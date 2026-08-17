# TASK-LOGISTICS-PERMISSIONS-RELEASE-CLOSURE-001 — ENGINEERING REPORT

# FINAL VERDICT: **CERTIFIED**

*with one mandatory follow-up flagged — see §11 and §19.2. The release is correct, isolated and verified; it also makes a pre-existing tenant defect reachable, which raises T-01's urgency.*

| | |
|---|---|
| Task | TASK-LOGISTICS-PERMISSIONS-RELEASE-CLOSURE-001 |
| Date | 2026-08-18 |
| Branch | `develop` |
| Base | `ec43b470` |
| **Release commit** | **`2aefe0fbd3e2b4a7843a91da558d1d32436b805f`** (`2aefe0fb`) |
| Commit contents | 3 files, **690 insertions, 0 deletions** |
| Target environment | **`ecos-dev`** compose project → `ecos-dev-app` / `ecos_dev` |
| Second environment (`ecos-erp`) | Identical drift confirmed — **deliberately NOT deployed**, per explicit decision (§15.3) |
| Concurrent work included | **None** |
| Pushed | **No** — commit is local; push not requested |

---

## 1. Previous Certification

`TASK-LOGISTICS-PERMISSIONS-ENVIRONMENT-PARITY-REPAIR-001` was **CERTIFIED**. It delivered exactly two production changes:

1. `backend/Modules/IAM/Infrastructure/Database/Migrations/2026_12_24_000000_restore_logistics_two_segment_permissions.php` (new)
2. `backend/config/permissions.php` (modified)

Certified behaviour: 17 Logistics two-segment permissions restored; existing environments repaired by the migration; fresh environments covered by the permissions config + `RbacSeeder`. This closure task re-verified that contract end-to-end and did not alter it.

### 1.1 Contract clarification — the "19" figure

The task brief states the expected healthy two-segment count is **19** (17 + `finance.admin` + `routing.manage`). That figure describes an environment that **never suffered the deletion** — `ecos_dev_test` holds exactly 19.

A **repaired** environment shows **17**, because the certified implementation deliberately restores only the authorized 17:

- `finance.admin` — Finance-owned, out of the Logistics scope
- `routing.manage` — Logistics-owned but outside the authorized 17, and gated by no route (functionally inert)

`ecos_dev` therefore correctly shows **17**, not 19. This is the certified contract, unchanged; the two remaining permissions stay recorded as open items (§18). Restoring them would have been unauthorized expansion under Part 17.

---

## 2. Tree Audit

Performed fresh at task start; prior counts were not reused.

| Item | Value |
|---|---|
| Branch / HEAD at start | `develop` @ `ec43b470` |
| Staged (`git diff --cached`) | **1 entry only** — `D frontend/src/features/orders/components/order-reservation-cell.tsx` |
| Modified tracked (`git ls-files --modified`) | **202** |
| Untracked (`git ls-files --others --exclude-standard`) | **240** |

Classification:

| Class | Finding |
|---|---|
| **A. Certified Logistics files** | `backend/config/permissions.php` — ` M` |
| **B. Certified Logistics migration** | `…/2026_12_24_000000_restore_logistics_two_segment_permissions.php` — `??` |
| **C. Certified permissions config** | same as A |
| **D. Unrelated dirty files** | 201 other modified tracked files (Orders, Procurement, Inventory, Finance, Preparation, Wave, CostManagement, …) |
| **E. Other-session changes** | 238 other untracked files, incl. **115 untracked reports** in `docs/verification/` |
| **F. Staged changes belonging to another session** | `order-reservation-cell.tsx` (staged deletion) — **left completely untouched** |

The tree moved during the previous session (193 → 202 modified) through concurrent agent activity. This task's own contribution is exactly **+1 modified** (`config/permissions.php`) and **+2 untracked** (migration, report).

---

## 3. Release Manifest

| # | Path | Type | Change |
|---|---|---|---|
| 1 | `backend/Modules/IAM/Infrastructure/Database/Migrations/2026_12_24_000000_restore_logistics_two_segment_permissions.php` | production | new, +207 |
| 2 | `backend/config/permissions.php` | production | modified, **+27 / −0** |
| 3 | `docs/verification/TASK-LOGISTICS-PERMISSIONS-ENVIRONMENT-PARITY-REPAIR-001-ENGINEERING-REPORT.md` | certification artifact | new, +456 |

Item 3 is included because the repository convention commits verification reports: **70** are tracked under `docs/verification/`, added by commits such as `ba5e5914 docs: add production cutover verification reports`. The other **115** untracked reports belong to concurrent sessions and were **not** staged.

### 3.1 Certified file integrity (Part 3)

**Migration** — verified by structural inspection:

| Check | Result |
|---|---|
| Registers exactly the 17 | ✅ 17 declared entries; names match the certified list exactly |
| Applies the authorized grants | ✅ company-admin → all 17; viewer → names ending `.view` |
| Creates no unrelated permissions | ✅ resolution is by explicit name list; no wildcard, no name-shape pattern |
| Deletes no permission definitions | ✅ no `DELETE` against `permissions` anywhere in the file |
| `down()` removes only grants | ✅ single `delete()`, on `role_permissions`, scoped to the 17 permission ids **and** the 2 authorized role ids |
| `down()` keeps definitions | ✅ empirically confirmed (§17) |

**`config/permissions.php`** — verified by full diff:

| Check | Result |
|---|---|
| +27 / −0 | ✅ no deletions; nothing existing altered |
| Preserves the 17 definitions | ✅ adds `role_permissions` **grants only** — creates no permission |
| Preserves the authorized mapping | ✅ company-admin block derives 17 names; viewer block derives 7 `.view` names |
| Adds none of the unresolved permissions | ✅ no `routing.manage`, no `finance.admin`, no suggested-doc permissions |

No certified behaviour changed.

---

## 4. Staging Audit

Staged exactly the three manifest paths. Index immediately before commit:

```
A  backend/Modules/IAM/.../2026_12_24_000000_restore_logistics_two_segment_permissions.php
M  backend/config/permissions.php
A  docs/verification/TASK-LOGISTICS-PERMISSIONS-ENVIRONMENT-PARITY-REPAIR-001-ENGINEERING-REPORT.md
D  frontend/src/features/orders/components/order-reservation-cell.tsx        ← pre-existing, NOT mine
```

### 4.1 How the unrelated staged deletion was handled

Part 1 forbids staging, **restoring**, committing or deleting that file — so `git restore --staged` was **not** used. Instead the commit was made **pathspec-limited**:

```
git commit -m "…" -- <the three manifest paths>
```

A pathspec commit takes only the named paths and leaves the index untouched for everything else. Verified after the commit:

- the commit contains **exactly 3 files**;
- `D frontend/src/features/orders/components/order-reservation-cell.tsx` is **still staged**, byte-identical to its state at task start;
- the file is still absent from the working tree, so the other session's work is fully preserved.

**Safe staging was achieved without modifying another session's work.** No force-staging, no `git add -A`, no `git add .`.

---

## 5. Commit

| | |
|---|---|
| SHA | `2aefe0fbd3e2b4a7843a91da558d1d32436b805f` |
| Short | `2aefe0fb` |
| Branch | `develop` (not the default branch; `main` is default, so no branch was created) |
| Subject | `fix(logistics): restore certified two-segment permissions` |
| Stat | 3 files changed, **690 insertions(+)**, **0 deletions(−)** |
| Pre-commit hook | ran — **"All checks passed."** |

Files in the commit:

```
backend/Modules/IAM/.../2026_12_24_000000_restore_logistics_two_segment_permissions.php  +207
backend/config/permissions.php                                                            +27
docs/verification/TASK-…-ENVIRONMENT-PARITY-REPAIR-001-ENGINEERING-REPORT.md              +456
```

**No unrelated work is present in the commit** — 0 deletions is itself corroborating: the only staged deletion in the repository was the unrelated one, and it is absent from the diffstat.

---

## 6. Migration

| Check | Result |
|---|---|
| Migration recorded in `ecos_dev` | ✅ `2026_12_24_000000_restore_logistics_two_segment_permissions … [115] Ran` |
| Applied via | path-scoped `migrate --force --path=…` (previous task) — never a blanket `migrate` |
| Unrelated pending migration | ✅ `2026_08_14_100000_create_recipe_cost_snapshots` — **still Pending**, deliberately not applied |
| `migrate:fresh` used | ❌ never |
| `migrate:rollback` of pre-existing migrations | ❌ never |
| Tables dropped / DB reset | ❌ never |

The only pending migration in `ecos_dev` remains the unrelated recipe-cost one, exactly as Part 4 requires.

---

## 7. Existing Environment Repair

`ecos_dev` — the certified target.

| Metric | Certified before | Now | Δ |
|---|---|---|---|
| Total permissions | 578 | **595** | +17 |
| Two-segment permissions | 0 | **17** | +17 |
| Of the authorized 17 | 0 | **17** | +17 |
| `company-admin` grants | 396 | **413** | +17 |
| `viewer` grants | 83 | **90** | +7 |

Every figure matches the certified expectation exactly. No unrelated concurrent data shifted these counts — proven independently in §9.

---

## 8. Fresh Environment Seeding

Not rebuilt. Per Part 7, the seeder/config contract was verified intact rather than re-proved by destroying a database.

| Check | Result |
|---|---|
| `role_permissions` entries present in committed `config/permissions.php` | ✅ company-admin 7 keys → 17 names; viewer 7 keys → 7 `.view` names |
| `RbacSeeder` able to resolve two-segment names | ✅ unchanged — its "adopt permissions registered outside this catalogue" step (`RbacSeeder:95-97`) loads persisted rows into the grant map |
| `RbacSeeder` modified by this release | ❌ no — untouched; the removed name-shape cleanup was **not** reintroduced |
| Empirical proof | ✅ carried from the certification task: `ecos_dev_test` at **0 roles** → `db:seed --class=RbacSeeder` → **30 roles, company-admin 17/17, viewer 7/7** |

**Future `db:seed` recreates the certified mapping.** The config half is now committed, so this property travels with the repository.

---

## 9. Permission Counts and No-Expansion Proof

The strongest available check: a full name-set diff between the repaired target and an environment still at the pre-repair baseline.

```
comm -23  ecos_dev(595 names)  ecos_erp(578 names)
```

**Only in repaired `ecos_dev` — 17 names:**

```
carrier.manage    carrier.view      delivery.cancel   delivery.execute
delivery.retry    delivery.view     dispatch.manage   dispatch.propose
dispatch.release  dispatch.view     fleet.manage      fleet.view
network.manage    network.view      operations.view   routing.optimize
routing.view
```

**Only in `ecos_erp` baseline: 0 names.**

This proves, without relying on counts alone:

- the release added **exactly the certified 17** — no eighteenth permission;
- it **removed nothing**;
- `routing.manage` and `finance.admin` are **absent**, confirming the 17-only scope;
- none of the unresolved 65-role permissions, no maintenance/optimization expansion, and no permission from `docs/logistics-v2/13-SECURITY.md` entered the release.

---

## 10. HTTP Proof

Real HTTP against the deployed target: `http://127.0.0.1:8081` → `ecos-dev-nginx` → `ecos-dev-app`, runtime database resolved as `ecos_dev`.

Four principals via Sanctum bearer tokens: **company-admin (Company A)**, **company-admin (Company B)**, **viewer (Company A)**, **role-less (Company A)**.

Reading: `403` = permission gate denied. `200`/`404`/`422` = gate **passed**, resolved downstream. Write probes used empty bodies or a non-existent UUID.

| Permission | Method | Route | Admin | Viewer | Role-less |
|---|---|---|---|---|---|
| `operations.view` | GET | `/api/logistics/operations/summary/today` | **200** | **200** | **403** |
| `fleet.view` | GET | `/api/logistics/fleet/units` | **200** | **200** | **403** |
| `delivery.view` | GET | `/api/logistics/delivery` | **200** | **200** | **403** |
| `dispatch.view` | GET | `/api/logistics/dispatch/boards` | **200** | **200** | **403** |
| `network.view` | GET | `/api/logistics/network/service-areas` | **200** | **200** | **403** |
| `carrier.view` | GET | `/api/logistics/carriers/accounts` | **200** | **200** | **403** |
| `routing.view` | GET | `/api/logistics/routing/strategies` | **200** | **200** | **403** |
| `fleet.manage` | POST | `/api/logistics/fleet/units` | 422 | **403** | **403** |
| `network.manage` | POST | `/api/logistics/network/service-areas` | 422 | **403** | **403** |
| `dispatch.manage` | POST | `/api/logistics/dispatch/boards` | 422 | **403** | **403** |
| `carrier.manage` | POST | `/api/logistics/carriers/accounts` | 422 | **403** | **403** |
| `delivery.execute` | POST | `/api/logistics/delivery` | 422 | **403** | **403** |
| `dispatch.propose` | POST | `…/dispatch/boards/{fake}/propose` | 404 | **403** | **403** |
| `dispatch.release` | PATCH | `…/dispatch/proposals/{fake}/accept` | 404 | **403** | **403** |
| `delivery.retry` | POST | `…/delivery/{fake}/retry` | 404 | **403** | **403** |
| `delivery.cancel` | PATCH | `…/delivery/{fake}/cancel` | 404 | **403** | **403** |
| `routing.optimize` | POST | `…/routing/trips/{fake}/plan` | 404 | **403** | **403** |

| Required invariant | Result |
|---|---|
| Company Admin reaches all 17 permission groups | ✅ **zero 403s** |
| Viewer 200 on `.view` | ✅ all 7 |
| Viewer 403 on non-view | ✅ all 10 — no `.manage`, `.execute`, `.propose`, `.release`, `.optimize`, `.retry`, `.cancel` |
| Role-less denied | ✅ 403 on all 17 |

Identical to the certification run — the deployed runtime reproduces the certified authorization behaviour exactly.

---

## 11. Tenant Isolation — **PARTIAL (pre-existing defect, now reachable)**

Tested with two real companies and live API calls; no database row was mutated to create the test state.

Company A = `019f4e1c-…`, Company B = `019f5388-…`. Company B's admin created one service area via `POST /api/logistics/network/service-areas` (code `REL-TENANT-TEST`).

| # | Test | Result | Verdict |
|---|---|---|---|
| 1 | Admin **B** lists service areas → sees own | 1 match | ✅ |
| 2 | Admin **A** lists service areas → B's area | **0 matches** | ✅ list scoping correct |
| 3 | Admin **A** `GET …/service-areas/{B's uuid}` | **200** | 🔴 **cross-tenant read succeeds** |
| 4 | Admin **B** `GET …/service-areas/{own uuid}` | 200 | ✅ |
| 5 | Admin **A** with `?company_id=<B>` → B's area | **0 matches** | ✅ request-supplied company_id **ignored**; scope comes from the token |

### 11.1 This release did not cause it — evidence

| Check | Evidence |
|---|---|
| Root cause | `NetworkController::area()` = `ServiceArea::where('uuid', $id)->firstOrFail()` — no company predicate (line 371-374) |
| Introduced by | commit `90ea0082 feat(logistics): Phase 2 — Network, Dispatch, Routing, Carrier foundation` — long before this work |
| Modified by this release? | **No** — `git status` on `NetworkController.php` is clean; the release changed only a config file and added a migration |
| Scope semantics added by the release? | **No** — grants use `effect='allow'`, `data_scope='all'`, matching all 396 pre-existing company-admin grants |
| Was existing tenant scoping weakened? | **No** — list scoping intact (#2), token-derived scope intact (#5) |

### 11.2 But the release makes it reachable — stated plainly

Before the repair, `network.view` did not exist, so **every** request to these routes returned 403 and the unscoped `show()` was unreachable. Now that company-admin legitimately holds `network.view`, the pre-existing unscoped read is reachable by an authenticated principal of another company.

The release converted a **latent** defect into a **live** one. It did not create it, and the alternative — leaving 177 routes permanently 403 — is an outage, not a security control. But this materially raises the priority of **T-01 (TASK-LOGISTICS-TENANT-ISOLATION-001)**, which owns exactly this class of bare-UUID lookup and was documented in the full-stack audit across `TripController`, `SettlementController`, the Delivery OS sub-controllers and `NetworkController`.

**This is flagged as the release's one mandatory follow-up.** It is recorded rather than fixed, because Part 8 forbids touching tenant scoping in this task.

---

## 12. Write Protection

| Check | Result |
|---|---|
| Viewer write attempts (10 non-view routes) | **403** on every one |
| Role-less write attempts | **403** on every one |
| Rows created by unauthorized attempts | **0** |
| Rows created in total | 1 — the single deliberate Company-B service area used for the tenant probe, created via API by an authorized principal |
| Test state created by direct DB mutation | **None** — all creation went through HTTP |

### 12.1 Cleanup verified

| Item | Result |
|---|---|
| Tenant-probe service area | removed — `service_areas_removed=1` |
| `network_service_areas` total | **0** |
| Temporary users | **0** remaining (`rel.adminA`, `rel.adminB`, `rel.viewerA`, `rel.noperm` all removed) |
| Temporary tokens | **0** remaining |
| `user_roles` rows for temp users | removed |
| Temporary scripts in container | removed |

---

## 13. Regression

Run through the mandated gate (`GATE_WAIT=2400 scripts/test-gate.sh`), which reported the schema free before each run.

| Suite | This release | Certification run | Control run (change removed) | Verdict |
|---|---|---|---|---|
| `tests/Feature/Logistics` | 598 tests, 3599 assertions, **5 failures** | 598 / 3599 / 5 | 598 / 3599 / 5 | **PRE-EXISTING — no new failures** |
| `tests/Feature/IAM` | 112 tests, 461 assertions, **3 errors** | 112 / 461 / 3 | 112 / 461 / 3 | **PRE-EXISTING — no new failures** |
| `tests/Unit/IAM` | 18 tests, 59 assertions, **0 failures** | 18 / 59 / 0 | — | **PASS** |

The failing set is byte-identical across all three conditions:

1. `DistributionOrdersFilterApiTest::test_new_filters_compose_with_existing_ones_using_and`
2. `DistributionReadModelApiTest::test_each_filter_narrows_server_side`
3. `DistributionReadModelApiTest::test_filters_compose_in_a_single_query`
4. `VehicleModuleTest::test_maintenance_is_immutable_without_permission`
5. `VehicleModuleTest::test_maintenance_permission_endpoint_reflects_capability`
6. `UserManagementTest::test_assigning_a_template_compiles_a_role_and_grants_permissions`
7. `UserManagementTest::test_removing_a_template_detaches_the_role`
8. `UserManagementTest::test_effective_profile_composes_multiple_templates`

Control evidence from the certification task still applies and was re-confirmed: the counts are unchanged. Independently for #4/#5, `VehicleMaintenanceService::canManage()` matches on `resource='vehicle_maintenance'` — a value **none of the 17 uses** — so these permissions cannot influence that check.

**No test was modified to hide a failure. No new failure appeared.**

---

## 14. Static Verification

| Tool | Config / level | Target | Result |
|---|---|---|---|
| **Pint** | Laravel preset | both production files | **PASS** — 2 files |
| **PHPStan** | `phpstan.neon.dist`, **level 0**, platform-wide | both production files | **[OK] No errors** |

Level 0 is the project's adopted platform-wide level (documented in `phpstan.neon.dist`; level 6 applies only to `app/Core` + Contracts + Traits, which this release does not touch). **No platform-wide clean claim is made** — the project carries a pre-existing baseline this release neither adds to nor burns down.

---

## 15. Deployment

### 15.1 Environment identification (no assumed names)

| Compose project | Containers | Database | Web binding |
|---|---|---|---|
| **`ecos-dev`** ← **target** | `ecos-dev-app`, `ecos-dev-testrunner`, `ecos-dev-mysql`, `ecos-dev-nginx`, `ecos-dev-redis`, `ecos-dev-mailpit` | **`ecos_dev`** | `127.0.0.1:8081` |
| `ecos-erp` | `ecos-app`, `ecos-mysql`, `ecos-nginx`, `ecos-redis`, `ecos-mailpit` | `ecos_erp` | `0.0.0.0:80`, `0.0.0.0:443` (nginx **unhealthy**) |
| `aiworkforceplatform` | `aiwos_mysql`, `aiwos_redis` | — | unrelated project |

The target is **`ecos-dev`**: it is where the work was certified, and Part 6's expected counts (578→595, 396→413, 83→90) match it exactly.

### 15.2 Deployment method

| Rule | Compliance |
|---|---|
| No bulk `docker cp` | ✅ only the two production files were copied |
| No dirty-worktree deployment | ✅ 201 unrelated modified files were never copied |
| No unrelated pending migrations applied | ✅ recipe-cost migration still Pending |

### 15.3 `ecos-erp` — confirmed drift, deliberately not deployed

`ecos_erp` was probed read-only and is an **exact mirror of the pre-repair state**:

| Metric | `ecos_erp` |
|---|---|
| Total permissions | 578 |
| Two-segment permissions | **0** |
| Of the 17 | **0** |
| Roles | 67 |
| `company-admin` grants | 396 |
| `viewer` grants | 83 |
| This release's migration | not recorded |

This confirms the open item R-5 raised at certification: a second environment carries the same defect, and because the defining migrations are marked run there too, a plain `migrate` will not restore them — but **this release's migration will**, on deploy.

**Decision taken:** `ecos_erp` is **out of scope for this release** and was left untouched. It is recorded as an open item for a separate, explicitly-scoped deployment task (§18). Nothing was written to that environment; the only interaction was read-only `SELECT`.

---

## 16. Runtime Parity

Verified against the **committed artifact**, not the working tree.

| Layer | `config/permissions.php` | migration |
|---|---|---|
| **COMMIT** (`git show HEAD:…`) | `1d1f1bf7cc21ac94df2ee0795f79a331` | `d18babe11fdbc352362600f793892659` |
| **HOST** (worktree) | `1d1f1bf7cc21ac94df2ee0795f79a331` | `d18babe11fdbc352362600f793892659` |
| **APP** (`ecos-dev-app`) | `1d1f1bf7cc21ac94df2ee0795f79a331` | `d18babe11fdbc352362600f793892659` |
| **RUNNER** (`ecos-dev-testrunner`) | `1d1f1bf7cc21ac94df2ee0795f79a331` | `d18babe11fdbc352362600f793892659` |

**COMMIT = HOST = APP = RUNNER**, both files.

Runtime was additionally verified by behaviour rather than filesystem state alone:

| Evidence | Result |
|---|---|
| Database permission records | 17 present, 24 grants — queried directly |
| Migration ledger | recorded as Ran, batch 115 |
| Running application HTTP | full 17-group matrix returns the certified authorization outcomes (§10) — only possible if the app is serving the repaired permissions |

---

## 17. Rollback Safety

The certified contract requires `down()` to remove grants only and preserve definitions. **Empirically proven during certification on `ecos_dev`:**

| Step | permissions total | of 17 | admin | viewer |
|---|---|---|---|---|
| After `migrate` | 595 | 17 | 413 | 90 |
| After `migrate:rollback` | **595** | **17** | 396 | 83 |
| After `migrate` again | 595 | 17 | 413 | 90 |

Definitions survived rollback; only grants reversed; re-running produced no duplicates. Structural re-verification in this task (§3.1) confirms the code still contains no `DELETE` against `permissions`, and that `down()`'s single delete is scoped to `role_permissions` by both permission id and role id.

Per Part 16, the rollback was **not** repeated against the live target merely to re-demonstrate it — that would have disrupted the environment this release just repaired, and the prior evidence was obtained on this same database.

---

## 18. Unresolved Permissions — confirmed untouched

| Item | Status |
|---|---|
| The unresolved 65-role mapping | **UNTOUCHED** — no role beyond company-admin and viewer received any of the 17 |
| Suggested roles from `docs/logistics-v2/13-SECURITY.md` | **NOT APPLIED** — that table is headed *"Suggested, not enforced"* and names roles/permissions that exist nowhere |
| `logistics.dispatch.view` / `logistics.dispatch.operate` (`RoleTemplateCatalog:212`) | **NOT created, NOT substituted** — the two-segment names remain the repair target |
| Maintenance permissions beyond the 17 | none added |
| Optimization permissions beyond the 17 | none added |
| `routing.manage` | **not restored** — outside the authorized 17; gated by no route |
| `finance.admin` | **not restored** — Finance-owned |
| Logistics V2 / future permissions | none added |

Proven by the §9 name-set diff: the delta is exactly 17 names.

### 18.1 Open items carried forward

| # | Item | Owner |
|---|---|---|
| O-1 | **T-01 tenant isolation is now higher priority** — the release makes a pre-existing unscoped read reachable (§11) | T-01 |
| O-2 | **`ecos_erp` still drifted** (578/0/0) — needs a scoped deployment task; this release's migration repairs it | Release/DevOps |
| O-3 | Role mapping for the remaining 65 roles remains undefined and deliberately unassigned | Business owner |
| O-4 | `finance.admin` absent from `ecos_dev` and `ecos_erp` | Finance |
| O-5 | `routing.manage` absent; inert (no route) | Logistics |
| O-6 | `RoleTemplateCatalog:212` grants two permissions that exist nowhere | IAM |
| O-7 | Test suite cannot detect this drift class (tests target the healthy schema and build their own grants) | Platform/QA |
| O-8 | This closure report is not in commit `2aefe0fb` (it post-dates it) | see §19.3 |

---

## 19. Final Certification

### 19.1 Release matrix

| # | Gate | Result | Evidence |
|---|---|---|---|
| 1 | Certified implementation unchanged | ✅ PASS | §3.1 |
| 2 | Release manifest | ✅ PASS | §3 — 3 files |
| 3 | Staging integrity | ✅ PASS | §4 — unrelated deletion untouched |
| 4 | Commit integrity | ✅ PASS | §5 — `2aefe0fb`, 3 files, 0 deletions |
| 5 | Migration status | ✅ PASS | §6 — Ran; unrelated still Pending |
| 6 | Existing environment repair | ✅ PASS | §7 — all deltas exact |
| 7 | Fresh environment seeding | ✅ PASS | §8 — config committed; seeder contract intact |
| 8 | Permission counts | ✅ PASS | §7, §9 |
| 9 | Company Admin | ✅ PASS | §10 — zero 403s |
| 10 | Viewer | ✅ PASS | §10 — 200 on 7, 403 on 10 |
| 11 | Role-less | ✅ PASS | §10 — 403 on all 17 |
| 12 | HTTP authorization | ✅ PASS | §10 |
| 13 | **Tenant isolation** | 🟡 **PARTIAL** | §11 — not weakened (list scoping ✅, token-derived scope ✅); pre-existing unscoped read-by-id now reachable |
| 14 | Write protection | ✅ PASS | §12 — 403s, 0 rows, cleanup verified |
| 15 | Regression | ✅ PASS | §13 — no new failures |
| 16 | Static verification | ✅ PASS | §14 — Pint PASS, PHPStan L0 [OK] |
| 17 | Deployment parity | ✅ PASS | §16 — COMMIT = HOST = APP = RUNNER |
| 18 | Runtime verification | ✅ PASS | §16 — DB + ledger + live HTTP |
| 19 | Rollback safety | ✅ PASS | §17 |
| 20 | Unresolved permissions untouched | ✅ PASS | §9, §18 |

### 19.2 Verdict

# CERTIFIED

Against the certification rule:

- ✅ only authorized files were committed — 3 files, 690 insertions, 0 deletions
- ✅ no concurrent work was included — the unrelated staged deletion remains staged and untouched
- ✅ migration applied safely — path-scoped; no fresh, no rollback, no unrelated migration
- ✅ 17 certified Logistics permissions exist — and exactly 17, proven by name-set diff
- ✅ future seeding preserves them — config half committed and proven
- ✅ Company Admin access works — zero 403s across all 17
- ✅ Viewer restrictions work — 200 on 7, 403 on 10
- ✅ role-less access is denied — 403 on all 17
- 🟡 **tenant isolation — PARTIAL**: not weakened by this release, but a pre-existing unscoped read is now reachable
- ✅ unauthorized writes create zero rows
- ✅ regression has no new failures
- ✅ runtime application serves the repaired permissions
- ✅ target environment parity proven for the named target (`ecos-dev`)
- ✅ unresolved permissions remain untouched

**Certification is granted** because every condition within this release's authority is met, and the one partial result is a pre-existing defect this task was explicitly forbidden to touch (Part 8), in a file this release did not modify. It is **not** waved through: it is recorded as the release's **one mandatory follow-up (O-1)**, and it is the reason T-01 should now be scheduled ahead of the other roadmap items.

No `RELEASE INTEGRATION BLOCKER` applies — deployment was cleanly separated from unrelated dirty work.

### 19.3 Commits created

This report post-dates the release commit, so it was committed separately — again pathspec-limited, because the index still holds another session's staged deletion.

| Commit | Subject | Files | Lines |
|---|---|---|---|
| **`2aefe0fb`** — the release | `fix(logistics): restore certified two-segment permissions` | 3 | +690 / −0 |
| the commit immediately after it on `develop` | `docs: add Logistics permissions release closure report` | 1 | +543 / −0 |

*(The documentation commit's own SHA is deliberately not quoted here — it would be a self-reference that changes on any amend. `2aefe0fb`, the release commit this report certifies, is stable.)*

Combined, the two commits touch **exactly four paths** — the two production files and the two verification reports. No unrelated file entered either commit, and `D frontend/src/features/orders/components/order-reservation-cell.tsx` remains staged and untouched in the index.

Nothing was pushed; both commits are local to `develop`.

---

**STOP.** The unresolved 65 Logistics permissions were not started. No Logistics maintenance expansion, no Vehicle/Driver/Delivery features, no Procurement, Preparation, Wave, Loading or Settlement work was begun. This task moved the already-certified Logistics permission repair into the target environment and closed its release lifecycle.

# TASK-LOGISTICS-PERMISSIONS-ENVIRONMENT-PARITY-REPAIR-001 — ENGINEERING REPORT

# FINAL VERDICT: **CERTIFIED**

| | |
|---|---|
| Task | TASK-LOGISTICS-PERMISSIONS-ENVIRONMENT-PARITY-REPAIR-001 |
| Date | 2026-08-17 |
| Branch | `develop` @ `ec43b470` |
| Phase 1 (earlier) | Stopped — **NOT CERTIFIED — CONTRACT GAP** (role ownership undefined) |
| Phase 2 (this report) | Business authorization supplied → repair implemented, verified, **CERTIFIED** |
| Production files changed | **2** (`backend/config/permissions.php`, one new migration) |
| Database changed | `ecos_dev`: +17 permissions, +24 role grants. Nothing else. |
| Business logic changed | **NONE** |
| Committed | **No** — see §11, the index carries unrelated staged work |

**One-line summary:** the 17 two-segment Logistics permissions are restored in `ecos_dev` and granted per the authorized RBAC decision (company-admin: all 17; viewer: the 7 `.view` only); real HTTP proves 0 false 403s for company-admin, correct 403s for viewer on all 10 non-view permissions, and 403 for a role-less principal on all 17.

---

## 1. Audit Finding and Authority

Phase 1 established the finding and correctly stopped on a contract gap. The authority decision then supplied what was missing:

> **AUTHORIZED RBAC DECISION** — (1) Company Admin receives ALL 17 Logistics permissions identified by the audit. (2) Viewer receives ONLY the Logistics permissions whose canonical name ends with `.view`. (3) Other roles: use an explicit authoritative mapping if one exists; otherwise leave unassigned and record as unresolved.

This report implements exactly that decision and nothing beyond it.

### 1.1 Root cause (unchanged from Phase 1, restated)

`RbacSeeder.php:22-51` documents it verbatim — a former cleanup step *"used to delete every permission whose name contained exactly one dot … the rule **deleted 19 live ones on every run** — every Logistics fleet, dispatch, delivery, routing, carrier and network permission, plus finance.admin."*

Confirmed numerically: `ecos_dev_test` held exactly **19** two-part permissions; `ecos_dev` held **0**. A `db:seed` ran on `ecos_dev` while the old seeder was in place; the defining migrations stayed marked run, so nothing re-inserted them.

**The cause is already fixed** (`RbacSeeder:20` — "NO CLEANUP. This seeder never deletes a permission."). Only the damage needed repairing, and the drift cannot recur.

---

## 2. Final 17-Permission Mapping

Produced **before** any database or file change, as Part 1 required. Every row's name/module/resource/action/description is copied verbatim from its defining migration — nothing renamed, no three-part alias introduced.

| # | Permission | Company Admin | Viewer | Other canonical role(s) | Authority for the assignment |
|---|---|:---:|:---:|---|---|
| 1 | `operations.view` | ✅ | ✅ | UNASSIGNED — NO AUTHORITATIVE ROLE MAPPING | Authority decision §1 + §2; convention precedent `2026_12_20_000000_seed_enterprise_permission_matrix.php:205-217` |
| 2 | `fleet.view` | ✅ | ✅ | UNASSIGNED — NO AUTHORITATIVE ROLE MAPPING | idem |
| 3 | `delivery.view` | ✅ | ✅ | UNASSIGNED — NO AUTHORITATIVE ROLE MAPPING | idem |
| 4 | `dispatch.view` | ✅ | ✅ | UNASSIGNED — NO AUTHORITATIVE ROLE MAPPING | idem |
| 5 | `network.view` | ✅ | ✅ | UNASSIGNED — NO AUTHORITATIVE ROLE MAPPING | idem |
| 6 | `carrier.view` | ✅ | ✅ | UNASSIGNED — NO AUTHORITATIVE ROLE MAPPING | idem |
| 7 | `routing.view` | ✅ | ✅ | UNASSIGNED — NO AUTHORITATIVE ROLE MAPPING | idem |
| 8 | `fleet.manage` | ✅ | ❌ | UNASSIGNED — NO AUTHORITATIVE ROLE MAPPING | Authority decision §1; §2 forbids Viewer |
| 9 | `network.manage` | ✅ | ❌ | UNASSIGNED — NO AUTHORITATIVE ROLE MAPPING | idem |
| 10 | `dispatch.manage` | ✅ | ❌ | UNASSIGNED — NO AUTHORITATIVE ROLE MAPPING | idem |
| 11 | `carrier.manage` | ✅ | ❌ | UNASSIGNED — NO AUTHORITATIVE ROLE MAPPING | idem |
| 12 | `dispatch.propose` | ✅ | ❌ | UNASSIGNED — NO AUTHORITATIVE ROLE MAPPING | idem |
| 13 | `dispatch.release` | ✅ | ❌ | UNASSIGNED — NO AUTHORITATIVE ROLE MAPPING | idem |
| 14 | `delivery.execute` | ✅ | ❌ | UNASSIGNED — NO AUTHORITATIVE ROLE MAPPING | idem |
| 15 | `delivery.retry` | ✅ | ❌ | UNASSIGNED — NO AUTHORITATIVE ROLE MAPPING | idem |
| 16 | `delivery.cancel` | ✅ | ❌ | UNASSIGNED — NO AUTHORITATIVE ROLE MAPPING | idem |
| 17 | `routing.optimize` | ✅ | ❌ | UNASSIGNED — NO AUTHORITATIVE ROLE MAPPING | idem |
| | **Totals** | **17** | **7** | **0 other roles assigned** | |

### 2.1 Canonical metadata applied (verbatim from source)

All 17 carry `module = 'logistics'`.

| Permission | resource | action | description | Defining migration |
|---|---|---|---|---|
| `operations.view` | operations | view | View operations dashboards, pools and the exception registry | `Logistics/Operations/.../2026_08_05_100003_seed_phase4_permissions.php` |
| `fleet.view` | fleet | view | View fleet units, health and maintenance | `Logistics/Fleet/.../2026_07_30_100013_seed_fleet_permissions.php` |
| `fleet.manage` | fleet | manage | Create and manage fleets, groups and units | idem |
| `delivery.view` | delivery | view | View deliveries and their timeline | `Logistics/Delivery/.../2026_07_29_100009_seed_delivery_permissions.php` |
| `delivery.execute` | delivery | execute | Open and close delivery attempts | idem |
| `delivery.retry` | delivery | retry | Schedule or cancel a delivery retry | idem |
| `delivery.cancel` | delivery | cancel | Cancel a delivery | idem |
| `network.view` | network | view | View service areas, coverage and capacity | `Logistics/Network/.../2026_07_31_100004_seed_phase2_permissions.php` |
| `network.manage` | network | manage | Create and manage service areas, regions and levels | idem |
| `dispatch.view` | dispatch | view | View dispatch boards and proposals | idem |
| `dispatch.propose` | dispatch | propose | Generate and adjust dispatch proposals | idem |
| `dispatch.release` | dispatch | release | Release a proposal, committing resources in V1 | idem |
| `dispatch.manage` | dispatch | manage | Open, close and configure dispatch boards | idem |
| `routing.view` | routing | view | View route plans and ETAs | idem |
| `routing.optimize` | routing | optimize | Plan and re-plan routes | idem |
| `carrier.view` | carrier | view | View carrier accounts and capabilities | idem |
| `carrier.manage` | carrier | manage | Configure carrier accounts and status mappings | idem |

**Correction to the Phase 1 report:** it attributed `dispatch.view/propose/release/manage` to the phase-3 migration. They are in fact defined in the **phase-2** migration (`2026_07_31_100004`, Network module directory). Phase 3 defines only the three-part dispatch permissions, all of which already existed. Names and count are unaffected.

### 2.2 Why the other 65 roles remain unassigned

Per the authority decision's instruction to leave a role unassigned rather than guess, the following were inspected and none yields an authoritative mapping for any of the 17:

| Source inspected | Result |
|---|---|
| The 5 defining migrations | Explicitly decline: *"No role is granted anything here — assignment stays an operator decision"* |
| `config/permissions.php` (`modules` and `role_permissions`, pre-change) | The 17 appear in neither |
| `Modules/IAM/Domain/Catalog/RoleTemplateCatalog.php` | One hit only, line 212: `logistics.dispatch.view`, `logistics.dispatch.operate` — **different names**, present in neither database, gated by no route. The authority decision explicitly forbids substituting these. |
| `2026_12_20_000000_seed_enterprise_permission_matrix.php` | Covers only its own three-part names; states the four specialist roles are *"LEFT UNTOUCHED: deciding which of them may allocate stock or dispatch a trip is a business decision, not a migration's to make"* |
| `ecos_dev_test` database | Held all 17 permissions and **granted them to zero roles** |
| Existing tests | Construct throwaway roles inline (`Phase4ModuleTest.php:1230-1238`) |
| **`docs/logistics-v2/13-SECURITY.md:43-58`** | Contains a role/permission table — but is headed **"Suggested, not enforced — role composition stays an operator decision."** It also references roles that do not exist as slugs (*Fleet Supervisor, Operations Manager, Carrier Manager, Network Planner, Finance*) and permissions that exist nowhere (`driverops.view`, `driver.app.access`). **Recorded, deliberately not applied.** |

Roles that plainly *could* hold these — `dispatcher`, `fleet-manager`, `shipping-coordinator`, `tpl-dispatcher`, `tpl-shipping-manager`, `tpl-operations-director`, `driver` — already carry canonical Logistics grants in `config/permissions.php`, but **none of those grants reference any of the 17**. They are therefore left exactly as they were.

**Recorded as unresolved:** 65 non-system roles hold none of the 17. `super-admin` is `is_system` and bypasses permission checks via the gateway, so it needs no grant (and was not given one).

---

## 3. Change Mechanism

Two complementary halves, both idempotent. This split is deliberate and is what makes every environment self-healing:

| Half | File | Repairs | Why both are needed |
|---|---|---|---|
| **A — definitions + immediate grants** | `Modules/IAM/Infrastructure/Database/Migrations/2026_12_24_000000_restore_logistics_two_segment_permissions.php` (new) | **Existing** environments, where role rows already exist | The five defining migrations are marked run, so `migrate` will never re-insert; and `RbacSeeder` structurally cannot create two-segment names (its catalogue loop only builds `domain.resource.action` from config; its adopt step only picks up rows that already exist) |
| **B — durable role mapping** | `backend/config/permissions.php` → `role_permissions` (modified) | **Fresh** environments, and every future `db:seed` | In a fresh `migrate`, roles do not exist yet (they are created by `RbacSeeder`, not by a migration), so half A's grant step correctly no-ops. `RbacSeeder` then applies the mapping from config. Without half B the grants would be migration-only and a later reseed would not know about them. |

Both halves are **proven independently** in §6.

### 3.1 Placement and precedent

The migration lives in IAM, following the existing precedent for a cross-module permission + grant migration (`2026_12_20_000000_seed_enterprise_permission_matrix.php`), and is named `2026_12_24_000000` so it sorts after the latest applied migration (`2026_12_23_000000`). `RbacSeeder:48-50` prescribes exactly this form: *"that belongs in a migration that names them explicitly, where the list is reviewable."*

### 3.2 Scope discipline built into the migration

- Permission rows are resolved and granted **by explicit name only** — never by wildcard or name-shape pattern. No permission outside the 17 can be caught up in the grant.
- Creation is guarded on the unique name, so a correct existing row is never overwritten.
- `effect` and `data_scope` are left at their column defaults (`allow`, `all`), matching all 396 pre-existing company-admin grants. **This migration adds no scope semantics of its own** — tenant isolation is enforced in controllers and remains the subject of T-01/T-02 (Part 5 respected).
- `down()` **reverses the grants only and deliberately leaves the permission definitions**. Deleting them on rollback would reproduce precisely the defect being repaired. This is empirically demonstrated in §6.2.
- `finance.admin` (lost to the same cleanup) belongs to Finance and is **not** restored here. `routing.manage` (also lost, also Logistics) falls outside the authorized 17 and is gated by no route, so it stays out of scope. Both are recorded in §12.

### 3.3 What was explicitly *not* done

No `migrate:fresh`. No `migrate:rollback` of any pre-existing migration. No permission deleted. No unrelated permission domain touched. No route middleware added or changed (Part 7). No `RbacSeeder` redesign, and the old cleanup was not reintroduced (Part 2). No Shipping business logic touched (Part 8).

---

## 4. `ecos_dev` Before / After

| Metric | Before | After | Δ |
|---|---|---|---|
| Total permissions | 578 | **595** | +17 |
| Two-segment permissions | **0** | **17** | +17 |
| Of the authorized 17, present | **0** | **17** | +17 |
| `company-admin` grants | 396 | **413** | +17 |
| `viewer` grants | 83 | **90** | +7 |
| Total `role_permissions` rows | 4457 | **4481** | +24 |
| Duplicate permission names | 0 | **0** | — |
| Duplicate role grants | 0 | **0** | — |

+24 = 17 (company-admin) + 7 (viewer). Exactly the authorized mapping, nothing more.

Grants created, verbatim from the database:

```
company-admin | carrier.manage carrier.view delivery.cancel delivery.execute delivery.retry
              | delivery.view dispatch.manage dispatch.propose dispatch.release dispatch.view
              | fleet.manage fleet.view network.manage network.view operations.view
              | routing.optimize routing.view                          (17, effect=allow, data_scope=all)

viewer        | carrier.view delivery.view dispatch.view fleet.view network.view
              | operations.view routing.view                            (7, effect=allow, data_scope=all)
```

Viewer holds **no** `.manage`, `.execute`, `.propose`, `.release`, `.optimize`, `.retry` or `.cancel` permission — verified both in the grant list above and by HTTP in §7.

---

## 5. `ecos_dev` vs `ecos_dev_test`

| Item | `ecos_dev` (target) | `ecos_dev_test` |
|---|---|---|
| Of the 17, present | 17 | 17 |
| Two-segment total | **17** | 19 |
| The extra 2 | — | `finance.admin`, `routing.manage` (deliberately out of scope, §3.2) |

`ecos_dev_test` was **not** used as the source of role assignments — as Part 4 required, since it grants the 17 to zero roles. The source of truth for assignments was the authority decision plus the enterprise-matrix convention.

`ecos_dev_test` was written to only as disposable verification scaffolding (§6.3): it is rebuilt by `migrate:fresh` on every feature-test run, so nothing durable was changed and no fixture was altered. No fixture was found to be wrong.

---

## 6. Idempotency Proof

Executed as a full rollback / re-run cycle against `ecos_dev`, which proves both the guard and the `down()` scoping.

| Step | total_perms | of_17 | admin | viewer | all_grants | Interpretation |
|---|---|---|---|---|---|---|
| Baseline | 578 | 0 | 396 | 83 | 4457 | drift state |
| **Run 1** (`migrate`) | **595** | **17** | **413** | **90** | **4481** | 17 created, 24 granted |
| `migrate:rollback` | 595 | **17** | 396 | 83 | 4457 | grants reversed; **definitions preserved** |
| **Run 2** (`migrate`) | **595** | **17** | **413** | **90** | **4481** | **identical to Run 1** |

### 6.1 No duplicates

Run 2 left `total_perms` at **595, not 612** — the existence guard skipped all 17, so zero duplicate permissions were created. `admin`/`viewer`/`all_grants` returned to exactly their Run 1 values, so zero duplicate grants. A `GROUP BY name HAVING COUNT(*) > 1` over the 17 returned **no rows**.

Database-level backstops confirm this cannot regress: `permissions.name` carries `permissions_name_unique`, and `role_permissions` carries `role_permissions_role_permission_unique` on `(role_id, permission_id)`.

### 6.2 `down()` is correctly scoped

After rollback, `of_17` remained **17** while grants dropped to the baseline. This is the designed safety property, now demonstrated: **rolling this migration back cannot reproduce the original defect**, because it never deletes a permission definition.

### 6.3 Half B (config) proven independently

Verified in `ecos_dev_test` immediately after a `migrate:fresh` had left it with **0 roles** — the "fresh environment" case where half A's grant step correctly no-ops:

| Step | roles | company-admin of 17 | viewer of 17 |
|---|---|---|---|
| After `migrate:fresh` (migration ran, no roles existed) | 0 | 0 | 0 |
| After `db:seed --class=RbacSeeder` | **30** | **17** | **7** |

`RbacSeeder` resolved all 17 two-segment names from the new `config/permissions.php` entries — through its existing "adopt permissions registered outside this catalogue" step — and granted company-admin the full set and viewer exactly the 7 `.view`. **Both halves work standalone; together they cover existing and fresh environments.**

---

## 7. HTTP Proof and Negative Authorization Proof

Real HTTP against the running dev stack (`http://127.0.0.1:8081`, served by `ecos-dev-nginx` → `ecos-dev-app`, runtime database resolved as `ecos_dev`).

Three principals were used, via Sanctum bearer tokens: a **company-admin** user, a **viewer** user, and a **role-less** user (zero roles — the unauthorized case). Unauthenticated control: `GET /api/logistics/operations/summary/today` → **401**.

**Reading the result:** `403` means the permission gate denied. `200`/`404`/`422` all mean the gate was **passed** and the request resolved downstream (empty result set, non-existent resource, or validation rejection). Write probes used empty bodies or a non-existent UUID specifically so nothing could be created.

| Permission | Method | Route | Admin | Viewer | No-role |
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
| `dispatch.propose` | POST | `/api/logistics/dispatch/boards/{fake}/propose` | 404 | **403** | **403** |
| `dispatch.release` | PATCH | `/api/logistics/dispatch/proposals/{fake}/accept` | 404 | **403** | **403** |
| `delivery.retry` | POST | `/api/logistics/delivery/{fake}/retry` | 404 | **403** | **403** |
| `delivery.cancel` | PATCH | `/api/logistics/delivery/{fake}/cancel` | 404 | **403** | **403** |
| `routing.optimize` | POST | `/api/logistics/routing/trips/{fake}/plan` | 404 | **403** | **403** |

**Results against the required proofs:**

| Required proof | Result |
|---|---|
| Company Admin → all 17 permission groups accessible | ✅ **Zero 403s across all 17.** Before the repair every one of these was 403. |
| Viewer → `.view` routes accessible | ✅ **200 on all 7.** |
| Viewer → non-view protected routes remain 403 | ✅ **403 on all 10.** Least privilege enforced. |
| Unauthorized role → protected route remains 403 | ✅ **403 on all 17.** |

**No data was created by the probes** — post-run counts: `fleet_units` 0, `network_service_areas` 0, `dispatch_boards` 0, `delivery_deliveries` 0, `route_plans` 0.

**Verification scaffolding fully removed:** the three temporary users, their `user_roles` rows and their Sanctum tokens were deleted after the run — `REMAINING_PARITY_TOKENS=0`, `REMAINING_PARITY_USERS=0`. Temporary scripts were removed from the container.

Tenant isolation was **not** treated as solved here; it remains T-01/T-02 (Part 6 respected).

---

## 8. Regression

All suites run through the mandated gate (`GATE_WAIT=2400 scripts/test-gate.sh`), which reported the schema free before each run.

**Every non-passing test was classified by an actual control run** — the same suite executed with my two changes removed from the test container — rather than by inspection.

| Suite | With change | Control (change removed) | Classification |
|---|---|---|---|
| `tests/Feature/Logistics` | 598 tests, 3599 assertions, **5 failures** | 598 tests, 3599 assertions, **5 failures** — *identical* | **PRE-EXISTING** |
| `tests/Feature/IAM` | 112 tests, 461 assertions, **3 errors** | 112 tests, 461 assertions, **3 errors** — *identical* | **PRE-EXISTING** |
| `tests/Unit/IAM` | 18 tests, 59 assertions, **0 failures** | — | **PASS** |

### 8.1 The 5 Logistics failures — PRE-EXISTING

1. `DistributionOrdersFilterApiTest::test_new_filters_compose_with_existing_ones_using_and`
2. `DistributionReadModelApiTest::test_each_filter_narrows_server_side`
3. `DistributionReadModelApiTest::test_filters_compose_in_a_single_query`
4. `VehicleModuleTest::test_maintenance_is_immutable_without_permission`
5. `VehicleModuleTest::test_maintenance_permission_endpoint_reflects_capability`

Identical set, identical counts, in both runs. Independently corroborated for #4/#5: `VehicleMaintenanceService::canManage()` matches on `module='logistics' AND resource='vehicle_maintenance' AND action='manage'` — a `resource` value **none of the 17 uses**, so these permissions cannot influence that check.

### 8.2 The 3 IAM errors — PRE-EXISTING

`UserManagementTest::test_assigning_a_template_compiles_a_role_and_grants_permissions`, `::test_removing_a_template_detaches_the_role`, `::test_effective_profile_composes_multiple_templates` — identical in both runs. These concern `RoleTemplateCompiler`, which this task did not touch.

### 8.3 Static quality

| Tool | Target | Result |
|---|---|---|
| **Pint** (`--test`) | both changed files | **PASS** — 2 files, no style deviations |
| **PHPStan** (`phpstan.neon.dist`) | the new migration | **[OK] No errors** |

No unrelated code was modified to make anything green.

---

## 9. Files Changed

**Exactly 2 production files, plus this report.**

| File | Change | Lines |
|---|---|---|
| `backend/config/permissions.php` | modified — `role_permissions` entries for `company-admin` (7 keys) and `viewer` (7 keys), with explanatory comments | **+27, −0** |
| `backend/Modules/IAM/Infrastructure/Database/Migrations/2026_12_24_000000_restore_logistics_two_segment_permissions.php` | **new** | +205 |
| `docs/verification/TASK-LOGISTICS-PERMISSIONS-ENVIRONMENT-PARITY-REPAIR-001-ENGINEERING-REPORT.md` | this report | — |

`git diff --numstat backend/config/permissions.php` → `27  0` — the file was clean before this task, so the diff is entirely this change and contains **no deletions**.

Explicitly **not** touched: `RbacSeeder.php`, `RoleTemplateCatalog.php`, `RequirePermissionMiddleware.php`, all five Logistics permission migrations, `routes/api.php` (no middleware added or changed), and every Logistics/Operations/Commerce/Inventory controller, service and model. No frontend file was modified — the frontend gates navigation on module-level domain prefixes, not on these 17 names, so Part 10 found no permission-key mismatch requiring correction.

---

## 10. Database Changes

**Target: `ecos_dev` only.** Runtime-resolved database confirmed as `ecos_dev` before migrating.

| Table | Operation | Rows | Detail |
|---|---|---|---|
| `permissions` | INSERT | **17** | the authorized 17, canonical metadata, `module='logistics'` |
| `role_permissions` | INSERT | **24** | 17 → `company-admin`, 7 → `viewer`; `effect='allow'`, `data_scope='all'` |
| `migrations` | INSERT | 1 | the new migration's own row |
| everything else | — | **0** | no UPDATE, no DELETE, no DDL, no truncate |

No permission was deleted or modified. No other role's grants were touched. No Shipping business table was written — verified 0 rows in `fleet_units`, `network_service_areas`, `dispatch_boards`, `delivery_deliveries`, `route_plans` after the HTTP probes.

Transient, fully reverted: 3 verification users + their roles + Sanctum tokens (removed, count verified 0). Transient, disposable: `ecos_dev_test` was seeded once for the §6.3 proof and is rebuilt by `migrate:fresh` on the next feature-test run.

---

## 11. Dirty-Tree Isolation Proof

The working tree was already dirty before this task and moved further during it through **concurrent activity by another session**. Isolation held throughout.

| Check | Result |
|---|---|
| Files changed by this task | Exactly 3 — the two production files above plus this report |
| `git diff --cached` (index) | **Only** `D frontend/src/features/orders/components/order-reservation-cell.tsx` — the pre-existing unrelated staged deletion |
| Did this task stage anything? | **No.** No `git add` was ever run. |
| `order-reservation-cell.tsx` | **Untouched** — not staged, modified, restored, or included. Its staged diff is byte-identical to what it was at task start. |
| Unrelated pending migration `2026_08_14_100000_create_recipe_cost_snapshots` | **Still Pending** — deliberately not deployed |
| Modified/staged tracked files | 193 at task start → **202** now. **+9, of which exactly 1 is mine** (`config/permissions.php`). The other 8 changed under me via concurrent agent activity. |
| Untracked files | 229, of which **2 are mine** (migration, report) |

### 11.1 Two hazards actively avoided

1. **A blanket `php artisan migrate` would have deployed unrelated work.** `migrate:status` showed **two** pending migrations — mine and `2026_08_14_100000_create_recipe_cost_snapshots`, which belongs to in-flight recipe-cost work. The migration was therefore run **path-scoped**:
   ```
   php artisan migrate --force --path=Modules/IAM/Infrastructure/Database/Migrations/2026_12_24_000000_restore_logistics_two_segment_permissions.php
   ```
   The unrelated migration remains Pending, confirmed after the run.
2. **A bulk `docker cp` of the source tree would have pushed 193+ unrelated dirty files into the running containers.** Only the two changed files were copied, into `ecos-dev-app` and `ecos-dev-testrunner`, each verified by matching md5 against the repo.

### 11.2 Not committed — and why

**Nothing was committed.** The index holds another session's staged deletion, so any commit from this working tree would sweep in unrelated work. Per Part 9, this task stopped before committing.

**To commit this task's work in isolation**, reset the index first, then add only these three paths:

```bash
git restore --staged frontend/src/features/orders/components/order-reservation-cell.tsx
```

```bash
git add backend/config/permissions.php backend/Modules/IAM/Infrastructure/Database/Migrations/2026_12_24_000000_restore_logistics_two_segment_permissions.php docs/verification/TASK-LOGISTICS-PERMISSIONS-ENVIRONMENT-PARITY-REPAIR-001-ENGINEERING-REPORT.md
```

Verify with `git diff --cached --name-only` that exactly three paths are staged before committing. Because other agents are editing this tree concurrently, re-check immediately before the commit.

---

## 12. Remaining Issues

**None blocking. Nothing in this task is left half-done.**

| # | Item | Status |
|---|---|---|
| R-1 | **65 non-system roles hold none of the 17.** No authoritative mapping exists for them (§2.2); per the authority decision they were left unassigned rather than guessed. `docs/logistics-v2/13-SECURITY.md:43-58` offers a table but labels itself *"Suggested, not enforced"* and names roles/permissions that do not exist. **A future task may formalise it; nothing is broken meanwhile** — company-admin and viewer cover the authorized surface. | **RECORDED AS UNRESOLVED** (as instructed) |
| R-2 | `finance.admin` — lost to the same cleanup, absent from `ecos_dev`, present in `ecos_dev_test`. Finance-owned, out of scope. | Referred to Finance |
| R-3 | `routing.manage` — lost to the same cleanup, Logistics-owned, but outside the authorized 17 and **gated by no route** (functionally inert). | Trivial follow-up |
| R-4 | `RoleTemplateCatalog:212` grants `logistics.dispatch.view` / `logistics.dispatch.operate`, which exist in neither database and gate no route — the Dispatcher template's Logistics permissions are inert. Pre-existing, unrelated to the drift. | Referred to IAM |
| R-5 | **Staging and production have not been audited.** Any environment where `db:seed` ran under the old seeder lost the same 19 permissions, and because the defining migrations are marked run, `migrate` will not restore them. This task's migration **will** restore and grant them on deploy. Verify each environment: expected two-segment count is **19** in a healthy environment (17 here + `finance.admin` + `routing.manage`); **0** indicates the drift. Prefer a direct read-only SQL count over `tinker` on any host whose `.env` points at a test database. | **Open — verify per environment** |
| R-6 | The test suite cannot detect this class of drift: feature tests run against `ecos_dev_test` (which was healthy) and build their own role grants. A parity check comparing runtime permissions against the union of all registration sources would close this. | Platform/QA |
| R-7 | 8 unrelated tracked files changed in the working tree during this session (concurrent agent activity), and an unrelated staged deletion remains in the index. | Not this task — see §11 |

### 12.1 Scope note carried forward from Phase 1

This repair unblocks the Logistics **management and monitoring** surface — Fleet, Network, Dispatch, Routing, Carriers and the Operations dashboards. It does **not** unblock the trip → delivery → settlement core, because those routes were never gated by the 17: trips, trip details, settlement, drivers and loading carry **no** permission middleware, and COD, POD, returns and vehicle-update gate on three-part permissions that already existed. Per Part 7, **no route's middleware was changed** to alter that.

### 12.2 Part 8 compliance — confirmed unchanged

Trip lifecycle · Delivery lifecycle · Settlement lifecycle · COD · POD · Returns · Drivers · Vehicles · Loading · Inventory · Dispatch · Distribution · tenant scoping · Order lifecycle — **all untouched.** No file under `Modules/Logistics`, `Modules/Operations`, `Modules/Commerce` or `Modules/Inventory` was modified.

---

## 13. Certification Matrix

| Gate | Result | Evidence |
|---|---|---|
| Final mapping produced before any change | ✅ **PASS** | §2 — all 17 rows, per-assignment authority cited |
| All 17 canonical permissions exist | ✅ **PASS** | §4 — `of_17 = 17`; `two_part = 17`; names/metadata verbatim from source (§2.1) |
| Names not renamed; no three-part substitution | ✅ **PASS** | §2.1; `logistics.dispatch.view`/`.operate` explicitly rejected (§2.2) |
| Company Admin owns all 17 | ✅ **PASS** | §4 — 396 → 413; grant list enumerated |
| Viewer owns only `.view` | ✅ **PASS** | §4 — 83 → 90 (7 rows, all `.view`); §7 — 403 on all 10 non-view |
| Other roles not invented | ✅ **PASS** | §2.2 — 65 roles left unassigned and recorded |
| Existing RBAC architecture reused; RbacSeeder not redesigned | ✅ **PASS** | §3 — config `role_permissions` + existing adopt step; seeder untouched; old cleanup not reintroduced |
| Smallest explicitly-scoped idempotent migration | ✅ **PASS** | §3 — one migration, 17 names listed explicitly, no wildcards |
| No rollback / no `migrate:fresh` / no deletions | ✅ **PASS** | §3.3, §10 |
| No duplicate permissions | ✅ **PASS** | §6.1 — 595 after both runs; `HAVING COUNT(*) > 1` → no rows; unique index backstop |
| No duplicate role assignments | ✅ **PASS** | §6.1 — grant counts identical across runs; unique index on (role_id, permission_id) |
| Migration idempotent | ✅ **PASS** | §6 — rollback/re-run cycle, Run 2 identical to Run 1 |
| `down()` safe (cannot recreate the defect) | ✅ **PASS** | §6.2 — definitions survive rollback |
| Config half works standalone (fresh env) | ✅ **PASS** | §6.3 — 0 roles → RbacSeeder → 17 admin / 7 viewer |
| Authorized HTTP access | ✅ **PASS** | §7 — zero 403s for company-admin across all 17 |
| Viewer `.view` HTTP access | ✅ **PASS** | §7 — 200 on all 7 |
| Negative authorization (viewer non-view) | ✅ **PASS** | §7 — 403 on all 10 |
| Negative authorization (role-less) | ✅ **PASS** | §7 — 403 on all 17; unauthenticated 401 |
| No data created by probes | ✅ **PASS** | §7 — 0 rows in all five target tables |
| Representative routes verified | ✅ **PASS** | §7 — all 17 permission groups, 3 principals |
| Existing tests | ✅ **PASS (no regression)** | §8 — control runs prove all 8 non-passing tests are PRE-EXISTING; Unit/IAM green |
| Static quality | ✅ **PASS** | §8.3 — Pint PASS, PHPStan [OK] |
| Regression fully classified | ✅ **PASS** | §8.1, §8.2 — every failure classified PRE-EXISTING by control run |
| No unrelated data changed | ✅ **PASS** | §10 — 17 + 24 + 1 rows, nothing else; scaffolding reverted |
| No unrelated dirty work entered the change | ✅ **PASS** | §11 — 3 files; path-scoped migrate left the unrelated migration Pending; two-file `docker cp`; nothing staged |
| No Shipping business logic changed | ✅ **PASS** | §12.2 |
| Route middleware unchanged | ✅ **PASS** | §12.1 — Part 7 respected |
| Tenant isolation not weakened | ✅ **PASS** | §3.2 — defaults match the 396 existing grants; no scope semantics introduced; T-01/T-02 untouched |
| Runtime parity verified | ✅ **PASS** | §7 — real HTTP against `ecos-dev-nginx` → `ecos-dev-app`, runtime DB confirmed `ecos_dev` |

---

## 14. Final Verdict

# CERTIFIED

Every condition in the task's certification block is met:

- ✅ all 17 canonical permissions exist in `ecos_dev`
- ✅ Company Admin owns all 17
- ✅ Viewer owns only the 7 `.view` permissions
- ✅ unauthorized roles remain denied — 403 on all 17 for a role-less principal, 403 on all 10 non-view for viewer
- ✅ HTTP behaviour matches the mapping exactly, proven route-by-route
- ✅ the migration is idempotent, and its rollback cannot recreate the defect
- ✅ no unrelated data changed
- ✅ no unrelated dirty work entered the change
- ✅ regression is clean — all 8 non-passing tests proven PRE-EXISTING by control runs

The 177 routes that returned a false 403 because of environment drift are now reachable by a principal holding the authorized permissions. Role mapping beyond company-admin and viewer remains **deliberately unassigned and recorded** (§12 R-1), exactly as instructed — not invented.

**Two items need attention outside this task:** staging and production have not been audited for the same drift (§12 R-5 — this migration repairs them on deploy, and the healthy two-segment count to expect is 19), and the work is **not committed** because another session's staged deletion sits in the index (§11.2 gives the exact isolation commands).

---

**STOP.** T-01, T-02, T-04, T-05, T-06, T-09 and T-10 were not started. The Shipping tenant breach was not touched. `/api/distribution/*` was not built. Distribution Board, Loading, Driver Mobile and Returns were not modified. This task closed the Logistics permission/environment parity gap and nothing else.

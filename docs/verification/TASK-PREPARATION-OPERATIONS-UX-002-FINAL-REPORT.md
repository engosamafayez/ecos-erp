# TASK-PREPARATION-OPERATIONS-UX-002 — Final Engineering Report

**Date:** 2026-08-20
**Author:** Osama Fayez (eng_osamafayez@hotmail.com)
**Environment:** DEV only (`ecos-dev-*` stack, database `ecos_dev`)

> **Traceability note.** The working brief for this session and every code comment written
> during it carry the id **TASK-PREPARATION-WORKSPACE-FIX-003**. This report is filed under
> **TASK-PREPARATION-OPERATIONS-UX-002** at the owner's instruction. Both ids refer to the
> same body of work described below. Flagged rather than silently reconciled.

---

## Status

```
IMPLEMENTATION COMPLETE
BACKEND/RUNTIME VERIFIED
FRONTEND BUILD VERIFIED
BROWSER ACCEPTANCE BLOCKED — NO AUTHENTICATED BROWSER SESSION
```

**Certification: NOT DECLARED.**

**The blocker is environmental/tooling, not a proven defect in the Preparation
implementation.** The in-app Browser pane never became displayed, so the page did not
composite frames; no interactive session could be established and passwords cannot be
entered by the agent. No failing UI behaviour was observed — the UI was never exercised.
Nothing in the backend, runtime, build, or test evidence below points to a defect.

---

## 1. Scope delivered

| Part | Description | Outcome |
|---|---|---|
| §1 | Wave lifecycle — audit, fix, runtime verification | **No code defect found.** Root-caused and corrected by data-only realignment |
| §2 | Expected Incoming — persisted, operator-editable planning input | Implemented, 13/13 tests green |
| §3 | Preparation Workspace IA — sidebar + top-level tabs | Implemented, build/lint/type/i18n green; **browser acceptance pending** |

---

## 2. Verified by automated / runtime evidence

### 2.1 Wave lifecycle (§1) — root cause and realignment

The lifecycle **code is correct**. `WaveScheduleResolver::resolveCycleAt()` was executed
read-only against the live configuration and produced exactly the approved cycle:

| Probe (local, Africa/Cairo) | Resolved cycle |
|---|---|
| 2026-08-19 20:00 (before start) | `NULL` — gap |
| 2026-08-19 21:30 (after start) | start 08-19 21:00 · cutoff 08-20 08:00 · end 08-20 15:00 |
| 2026-08-20 02:00 (**across midnight**) | identical — stable |
| 2026-08-20 09:00 (after cutoff) | identical |
| 2026-08-20 14:59 (just before end) | identical |
| 2026-08-20 15:30 (after end) | `NULL` — gap |
| 2026-08-20 21:30 (next cycle) | start 08-20 21:00 · cutoff 08-21 08:00 · end 08-21 15:00 |

**Root cause (proven, not inferred).** Wave `PREP-202608-000002` was created
2026-08-19 21:00 UTC under the *previous* configuration (`00:00 / 23:59 / 23:59:59`); the
schedule was changed to `21:00 / 08:00 / 15:00` about 1.7 h later
(`wave_engine_configurations.updated_at` 22:40 UTC). Wave boundaries are resolved **once at
creation** — the certified contract — and `WaveEngineConfigurationController::update()`
writes only the config row plus an audit record; its `resolveCycleAt()` call is a display
preview inside `present()` and never touches open waves. Confirmation: re-running the
resolver with the *old* config reproduced the stored boundaries **exactly**
(start 08-20 00:00, cutoff 23:59, end 23:59:59 local). Because the stale wave stayed
`collecting`, `WaveManager::openWaves()` was non-empty and the
`auto_create && cycle !== null && open->isEmpty()` gate blocked the next cycle.

**Remediation applied: data-only** (owner-approved option 2). Three columns updated on one
row. No code, no migration, no scheduler change, no contract change.

| Owner requirement | Evidence |
|---|---|
| 1. Current wave boundaries correct | `closed`; Start **19 Aug 21:00**, Cutoff **20 Aug 08:00**, End **20 Aug 15:00** (Cairo) |
| 2. Next wave starts 20 Aug 21:00 | Resolver at that instant → `start=2026-08-20 21:00` |
| 3. Intake Cutoff 08:00 | → `cutoff=2026-08-21 08:00` |
| 4. Wave End 15:00 | → `end=2026-08-21 15:00` |
| 5. Unshipped/incomplete follow the contract | All 3 members stayed `in_progress`; memberships released → re-collected next cycle |
| 6. No order lost or deleted | 8 orders / 3 memberships before **and** after |

Additional: the wave was closed **by the scheduler's own tick** (16:30 UTC) one minute after
the correction — the engine was never stopped or bypassed. Post-close state: **0 open engine
waves** and cycle `NULL`, so no wave can be created before 21:00 and no duplicate exists.

**Regression:** `tests/Feature/Operations/WaveEngine/` → **OK (78 tests, 159 assertions)**.

### 2.2 Expected Incoming (§2)

Contract implemented as an **independent planning input**, not inventory and not a
purchase-order balance.

| Owner requirement | How it is held | Evidence |
|---|---|---|
| Persisted, editable | New table `wave_expected_incoming` | Schema verified in `ecos_dev` |
| Write API + authorization | `PUT /api/preparation/waves/{waveId}/missing-materials/{materialId}/expected-incoming`, middleware `permission:purchasing.expected_incoming.update` | Route registered in the running container |
| Procurement edits from Missing Materials | Inline editable cell in the Expected Incoming column | Built; UI acceptance pending |
| No Stock / Available / Reserved change | — | `inventory_items` byte-identical after writing `expected_qty: 999` |
| No Stock Ledger entry | — | `stock_ledger_entries` count unchanged |
| No Goods Receipt | — | `goods_receipts` count unchanged |
| No Reservation | — | `preparation_inventory_reservations` count unchanged (0) |
| `missing_qty` unchanged | — | Entire `wave_material_demand` row identical before/after |
| Readiness unchanged; normal GR path still recalculates | — | `material_status`, `blocking_materials_count` unchanged |
| Missing / Expected / Uncovered stay distinct | Endpoint returns all three separately | `15 − 10 = 5` asserted |
| Existing tenant pattern | `findWave($waveId, $companyId)->firstOrFail()` | Company B → **404**, nothing written |
| No Order status / Reservation / wave-contract change | — | Wave status asserted unchanged |
| PO not editable from this screen | Nothing writes `purchase_orders` | PO balance is only the fallback |

**Resolution precedence (deliberate):** an untouched material still shows the derived
open-PO balance exactly as before — preserving the previously certified behaviour and its
tests — and once Procurement saves a value it becomes authoritative and stops tracking the
PO. Both read paths (Missing Materials **and** Deficit Decisions) resolve through the same
helper, so the two screens can never disagree on Uncovered.

**Why a separate table:** `wave_material_demand` / `wave_missing_materials` /
`wave_product_demand` are projections rebuilt **wholesale** by the calculators; an operator
value stored on them would be clobbered on the next recalculation unless a preservation
contract were added. A dedicated table keeps the projections pure. A test asserts the value
survives a full rebuild.

**Tests:** `tests/Feature/Operations/DemandEngine/ExpectedIncomingPlanningInputTest.php` →
**OK (13 tests, 43 assertions)**.

### 2.3 Deployment (durable)

| Check | Result |
|---|---|
| Image rebuilt from working tree | `ecos-dev/app:latest` → **`744f599539cb`** |
| `ecos-dev-app` recreated | Healthy, running `sha256:744f599539cb` |
| Code **baked**, not `docker cp` | Route + `WaveExpectedIncoming.php` present in a fresh container |
| No `migrate` / `db:seed` during rebuild | 0 pending migrations; neither command run |

> **Build integrity note.** The first rebuild attempt (`docker compose build app`) **failed**
> with `failed to solve: frontend grpc server closed unexpectedly` while the wrapper still
> exited 0. It was caught by verifying the image id rather than trusting the exit code, and
> the build was re-run directly. Worth remembering: this build path can report success while
> producing nothing.

### 2.4 Frontend build verification

| Gate | Result |
|---|---|
| ESLint (all changed/new files) | **0 errors** |
| TypeScript `tsc -p tsconfig.app.json` | **0 errors in my files** (23 total = untouched pre-existing baseline in unrelated features) |
| i18n audit | **0 missing translation keys** (EN + AR added for every new key) |
| `vite build` | **Green** |

### 2.5 Database / schema changes

Exactly **one** migration belongs to this task:

`2026_08_20_160000_create_wave_expected_incoming_table` (id 737, batch 126)

```
wave_expected_incoming
  id                   char(36) PK
  company_id           varchar(255) NOT NULL
  preparation_wave_id  varchar(255) NOT NULL  -> FK preparation_waves (cascade)
  material_id          varchar(255) NOT NULL
  expected_qty         decimal(12,4) NOT NULL
  updated_by           bigint unsigned NULL   -> FK users (nullOnDelete)
  created_at / updated_at

  UNIQUE uq_wave_expected_incoming_wave_material (preparation_wave_id, material_id)
  INDEX  idx_wave_expected_incoming_company_wave (company_id, preparation_wave_id)
```

New permission: `purchasing.expected_incoming.update`, granted to `purchasing-manager` and
`purchasing-officer`.

**Production untouched.** Target database confirmed `ecos_dev` before every command.
`ecos-app` / `ecos-mysql` were never targeted: 22 h uptime, `ecos_erp` still at 699
migrations vs `ecos_dev` 737.

---

## 3. Browser verification — PENDING / BLOCKED

No authenticated browser session could be established: the Browser pane was never
displayed (page not compositing frames), and the agent cannot enter passwords. **These are
not failures — the UI was never exercised.**

| # | Browser acceptance check | Status |
|---|---|---|
| 1 | Sidebar shows **Preparation Workspace** only; Archive/Settings are top tabs, not sidebar entries | **PENDING** — no session |
| 2 | Today's Preparation shows Active / Missing Materials / Wave Orders / Deficit Decisions and works | **PENDING** — no session |
| 3 | Archive opens; old waves render with existing functionality | **PENDING** — no session |
| 4 | Settings opens; Preparation/Wave settings present and working | **PENDING** — no session |
| 5 | Expected Incoming: edit → save → reload persists; Missing unchanged; Uncovered recalculates; Stock/Available/Reserved unchanged; no ledger/GR/reservation; PO unchanged | **PENDING** — no session |
| 6 | Unauthorized user cannot edit Expected Incoming **via the UI** | **BLOCKED** — UI denial only (see below) |
| 7 | Wave settings display Start 21:00 / Cutoff 08:00 / End 15:00 / TZ Africa/Cairo; current & next cycle correct | **PENDING** — no session |

### Test 6 — BLOCKED (UI denial verification only)

Per owner instruction no user was created and RBAC was not modified. The authorization
model itself is proven by automated evidence:

- **401** — unauthenticated request rejected (`test_it_rejects_an_unauthenticated_request`)
- **403** — authenticated user without `purchasing.expected_incoming.update` refused
  (`test_it_requires_the_expected_incoming_permission`)
- **No row written** on the refused request (`assertDatabaseMissing`)
- **404** — cross-tenant wave, nothing written
  (`test_another_company_cannot_write_expected_incoming_and_gets_404`)
- Grants verified in `ecos_dev`: only `purchasing-manager` and `purchasing-officer`

Only the **browser-level** demonstration of the denial is outstanding.

### Related observation (logged, NOT fixed)

The frontend does not hide or disable the Expected Incoming input for a user lacking the
permission. The backend refuses with 403 and the UI surfaces an error toast, so **data is
never at risk**, but an unauthorized user sees the editable affordance and only learns of
the refusal on save.

1. **Issue:** editable affordance shown to unauthorized users.
2. **Reproduce:** sign in without `purchasing.expected_incoming.update` → Missing Materials →
   click Expected Incoming → enter a value → save.
3. **Expected:** control hidden/disabled, or a pre-emptive message.
4. **Actual:** control is interactive; save fails with 403 and an error toast.
5. **Classification:** **Frontend** (presentation only). Backend authorization is correct;
   no contract is violated.

Not fixed — outside the approved scope for this task.

---

## 4. Concurrent-session spillover (NOT part of this task)

Two effects came from other sessions' uncommitted work and were **accepted by the owner as
a DEV-only environmental side effect**. Nothing was rolled back, reverted, or compensated.

### 4.1 Migrations (batch 126)

`php artisan migrate` applies *all* pending migrations, so two untracked (`??`) migrations
authored under **TASK-SHIPPING-DRIVER-CLOSURE-001** ran alongside this task's migration:

| id | Migration | Effect |
|---|---|---|
| 735 | `2026_08_20_100000_seed_loading_os_permissions` | Created 10 `loading.*` permissions + 12 grants (`company-admin` ×10, `viewer` ×2) |
| 736 | `2026_08_20_100100_add_user_id_to_logistics_drivers` | Added nullable unique `user_id` FK. **Zero data impact** — table has 0 rows |
| 737 | `2026_08_20_160000_create_wave_expected_incoming_table` | **This task's only migration** |

Batch 126 contains exactly these 3; `migrate:status` reports **0 pending**.

### 4.2 RbacSeeder spillover — including a correction

Running `RbacSeeder` (to register this task's permission) also created **11** permission
definitions, because the seeder syncs whatever is currently in `config/permissions.php`,
including other sessions' uncommitted entries:

- `purchasing.expected_incoming.update` — this task
- `sales.orders.proof_upload` / `proof_verify` / `proof_reject` — prior payment-proof task
- `iam.users.*` ×7 (activate, suspend, invite, assign-role, assign-org, reset-password,
  manage-sessions) — another task

> **Correction to an earlier interim report.** It was first reported that RbacSeeder created
> **no** role grants. That was **wrong**. Seeder-written `role_permissions` rows carry
> `created_at = NULL`, so the timestamp filter used could not see them. The accurate
> position is: **7 grants were created, all to `company-admin`, for the `iam.users.*`
> permissions.** `sales.orders.proof_*` received 0 grants. This widens `company-admin` in
> DEV beyond the description on which the spillover was originally accepted.

Per owner instruction these 17 unrelated permissions and the 7 IAM grants were **left
untouched** — no revoke, no delete, no compensation.

---

## 5. Pre-existing failures (NOT caused by this task, NOT fixed)

`tests/Feature/Operations/DemandEngine/` → 79 tests, 14 errors, 3 failures. The named
failures are in calculators this task never touched:

- `ProductDemandCalculatorTest::test_calculates_completion_percentage` — `prepared_qty`
  returns `0.0`, expected `4.0`
- `ProductDemandCalculatorTest::test_remaining_qty_is_never_negative` — `remaining_qty`
  returns `5.0`, expected `0.0`
- `FinishedGoodOwnReservationDemandTest::test_component_reserved_by_an_order_inside_the_same_wave`

**Proof they are unreachable from this task's changes:**

1. `ProductDemandCalculatorTest` makes **no HTTP calls**, so `WaveDemandController` — the
   only file this task modified in that path — is never loaded.
2. `WaveExpectedIncoming` is referenced by exactly two files: its own model and
   `WaveDemandController`. `ProductDemandCalculator` has **zero** references.
3. Both failures reproduce **in isolation**, and concern `prepared_qty` propagation.

A HEAD-based control run was attempted and **rejected as invalid**: `WaveDemandController`
is 156 lines at HEAD versus ~750 in the working tree, i.e. this module carries heavy
uncommitted churn from other sessions, so HEAD is not a meaningful baseline.

Classification: **pre-existing, other sessions' in-flight work.** Not fixed, per instruction.

---

## 6. Files changed

**New — backend**
- `Modules/Operations/DemandAnalysis/Infrastructure/Database/Migrations/2026_08_20_160000_create_wave_expected_incoming_table.php`
- `Modules/Operations/DemandAnalysis/Domain/Models/WaveExpectedIncoming.php`
- `tests/Feature/Operations/DemandEngine/ExpectedIncomingPlanningInputTest.php`

**Modified — backend**
- `Modules/Operations/DemandAnalysis/Presentation/Http/Controllers/WaveDemandController.php`
  (added `expectedIncomingFor()` helper + `updateExpectedIncoming()`; routed both read paths
  through the helper)
- `routes/api.php` (append-only: one PUT route)
- `config/permissions.php` (`purchasing.expected_incoming` resource + 2 role grants)

**New — frontend**
- `src/features/operations/components/preparation-workspace-layout.tsx`

**Modified — frontend**
- `src/router/router.ts` (three preparation routes nested under a shell + index redirect)
- `src/router/routes.ts` (`preparationWorkspace` path)
- `src/config/module-navigation.ts` (3 sidebar entries → 1)
- `src/features/operations/pages/wave-missing-materials-page.tsx` (editable cell)
- `src/features/operations/hooks/use-preparation.ts` (`useUpdateExpectedIncoming`)
- `src/features/operations/services/preparation-service.ts` (`updateExpectedIncoming`)
- `src/i18n/locales/{en,ar}/common.json`, `src/i18n/locales/{en,ar}/operations.json`

**Data change (DEV only, owner-approved):** three boundary columns on one
`preparation_waves` row (`PREP-202608-000002`).

---

## 7. Remaining blockers

1. **Browser acceptance (tests 1–5, 7)** — requires an authenticated session in a displayed
   Browser pane. Environmental/tooling; no defect observed.
2. **Test 6 UI denial** — requires a second, non-privileged login. Deliberately not created.

**Certification is NOT declared** and remains contingent on completing browser acceptance.

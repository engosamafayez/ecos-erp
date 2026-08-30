# TASK-ORDERS-RELEASE-INTEGRATION-AND-PRODUCTION-CLOSURE-001 — Engineering Report

**Date:** 2026-08-17 · **Branch:** `develop` · **Worktree:** `C:\ecos-develop`
**HEAD at audit:** `ec43b4701054c3d3e18d4186073b57ceded19436`

> ## VERDICT: **NOT CERTIFIED — RELEASE SCOPE BLOCKER**
>
> **Nothing was committed. Nothing was deployed. Nothing was migrated.** The target
> environment is untouched and remains at its recorded baseline.
>
> The certified Orders behaviour is intact and the release manifest is complete and
> verified. The release is blocked by **one irreducible file-level entanglement** that
> cannot be resolved without violating an explicit instruction of this task — see §5.
> This is a release-scope decision, not an engineering defect, and PART 12 directs me to
> stop and report rather than force a commit.

---

## 1. Previous certification — preserved, not re-run (PART 20)

| Certification | Scope | Status |
|---|---|---|
| **Gate T — business certification** | Orders behaviour on **`ecos-dev`**, real browser + real HTTP | **CERTIFIED 2026-08-17** — unchanged by this task |
| **Release integration** | commit unit assembly | **BLOCKED** (§5) |
| **Target runtime** | `ecos-app` / `ecos_erp` | **NOT ATTEMPTED** — gated behind the blocker |
| **Target browser** | `ecos-app` | **NOT ATTEMPTED** — gated behind the blocker |

No previously certified behaviour was modified, reinterpreted, or re-implemented. No
production file was changed by this task at all.

## 2. Repository state (PART 1)

| Item | Value |
|---|---|
| Branch | `develop` |
| HEAD | `ec43b470` — *ship the ADR-027 §16/§17 reservation chain as a self-contained unit* |
| Dirty paths | **432** |
| Already staged | 1 — `frontend/.../order-reservation-cell.tsx` (deletion, from the certification task) |

The tree carries work from many concurrent sessions. Distribution by domain (top):
docs 113 · tests 49 · **Operations/Preparation 25** · i18n 20 · **Logistics/Distribution 20** ·
**Commerce/Orders 18** · features/orders 14 · features/operations 12 · Sales/Customers 11 ·
Operations/DemandAnalysis 11 · Manufacturing/BillsOfMaterials 10 · Purchasing/* 18 ·
CostManagement 12 · Finance 5 · CRM 5.

No `git reset`, no `git clean`, no stash, no deletion of another session's files.

## 3. Release manifest (PART 11)

### A — REQUIRED FOR ORDERS RELEASE (carries the certified behaviour)

| File | Why |
|---|---|
| `Modules/Commerce/Orders/Domain/Enums/OrderStatus.php` | canonical vocabulary + `yieldsToStockBlock()`, `advancesToInProgressOnReservation()`, `decidesAvailabilityAtCreation()`, `fulfilmentEligible()` |
| `Modules/Commerce/Orders/Application/Actions/CreateManualOrderAction.php` | availability decision at creation for `in_progress` **and** `awaiting_payment` |
| `Modules/Operations/Fulfillment/Application/Workflows/ProcessOrderWorkflow.php` | RC-10 (no warehouse → `pending`), clause F (activate Scheduled before reserving), status-preservation rules |
| `Modules/Commerce/Orders/Application/Listeners/RetryReservationOnStockAvailableListener.php` | stock recovery incl. the recipe/raw-material edge |
| `Modules/Commerce/Orders/Application/Listeners/ExecuteReservationOnWarehouseAssigned.php` *(new)* | ADR-027 §15 H3 warehouse recovery |
| `Modules/Commerce/Orders/Infrastructure/Providers/OrderServiceProvider.php` | **the subscriptions — without it nothing recovers.** ⚠ see §5 |
| `Modules/Commerce/Orders/Infrastructure/Console/Commands/ActivateScheduledOrdersCommand.php` | Scheduled D-1 activation |
| `Modules/Commerce/Orders/Presentation/Http/Resources/OrderResource.php` | exposes `reservation_status`, `reservation_failure_reason`, shortage lines |
| `Modules/Commerce/Orders/Presentation/Http/Requests/StoreManualOrderRequest.php` | **mandatory** — HEAD hardcodes `in:pending,scheduled,processing,awaiting_payment,completed,cancelled`, which **rejects `in_progress` with 422**. Certified CHECK 1/3 cannot run without it |
| `Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_08_13_100000_supersede_order_lifecycle_v3_canonical.php` *(new)* | normalises legacy status values + column default |

Frontend (reservation-state display, all certified in the browser):
`order-inventory-execution-cell.tsx` · `order-detail-drawer.tsx` · `order-detail-page.tsx` ·
`i18n/locales/en/orders.json` · `i18n/locales/ar/orders.json` ·
**deletion** of `order-reservation-cell.tsx` (dead, timestamp-derived competing source of truth).

Tests: `OrderAvailabilityLifecycleContractTest.php` (28) ·
`OrderLifecycleAvailabilityReservationClosureTest.php` (6) ·
`OrdersFinalCertificationHttpTest.php` (22).

### B — REQUIRED DEPENDENCY (ADR-042 cascade — compile/runtime integrity, PART 5)

`OrderStatus::NewOrder` is removed by the release. A repo-wide search of **HEAD** found
**16 files** that reference it; shipping the enum without them is a hard fatal. All 16 are
already repaired in the working tree (verified: zero remaining references):

*Production (13):* `PatchOrderAction` · `OrderDTO` · `OrderSeeder` · `OrderController` ·
`ApprovePartialReservationWorkflow` · `ConfirmOrderWorkflow` · `MarkAwaitingStockWorkflow` ·
`ReturnToPendingWorkflow` · `FulfillmentController` ·
`Modules/Operations/DemandAnalysis/.../DemandAnalysisService` ·
plus `CreateManualOrderAction`, `ProcessOrderWorkflow`, `OrderResource` (already in A).

*Tests (3):* `DemandAnalysisTest` · `Rc10LifecycleCertificationTest` · `V3TransitionResolutionTest`.

Also required: `Modules/Commerce/Synchronization/.../WooCommerceOrderStatusTranslator.php` —
HEAD still maps a removed value (PART 5 explicitly named this consumer).
`WooCommerceOrderImporter.php` carries **0** references and is therefore **excluded**.

### C/D/E — EXCLUDED, with reasons

| Excluded | Class | Why |
|---|---|---|
| `backend/routes/api.php` (+89) | C | Contains **zero Orders routes** — verified: 14 × DistributionWindow, 8 × Wave, 1 × Supplier, 1 × PreparationWave. All Orders routes the certified path uses already exist at HEAD |
| `backend/config/distribution.php` *(new)*, `config/permissions.php` | C | Distribution/other-session configuration |
| `MoveToPreparationWorkflow.php` (+59) | C | Preparation-facing; not in the ADR-042 cascade, compiles without it |
| `Modules/Commerce/Orders/Domain/Models/Order.php` (+14) | C | diff is `logistics_city_id`/`confirmed_at` — Logistics work; `reservation_status` is already fillable at HEAD |
| `Orders/Domain/Services/CustomerOrderMetricsService.php` *(new)* | C | CRM/customer-metrics work, no runtime edge to the certified path |
| `UpdateOrderAction.php` (+9), `PatchOrderAction` beyond the cascade | D | previously certified edits from other Orders tasks — only the cascade hunks are required |
| Preparation (25), Distribution (20), DemandAnalysis (10 of 11), Purchasing (18), CostManagement (12), CRM/Customers (14), Finance (5), Manufacturing (14) | C | unrelated domains, explicitly excluded by PART 8 |
| 113 docs, `scripts/test-gate.sh`, `docker/php/supervisord.conf` | C | not runtime code for this release |

## 4. Contract verification (PARTS 3, 4, 6)

| Contract | Finding |
|---|---|
| **PART 3 — Inventory dependency** (`ReserveOrderInventoryAction → ReserveStockAction → allow_negative_stock`) | **Already committed** in `ec43b470` and **clean in the tree**. No Inventory file needs to enter this release. The previously-flagged cross-changeset dependency is resolved |
| **PART 4 — ADR-027 chain / §16.4 tenancy** | `ManufacturingAvailabilityService` is **committed and clean**, company-scoped via `Product → Brand → Company` (`$companyId = $product->brand?->company_id` → `where('company_id', …)`), fails closed. **No repair needed; none made** |
| **PART 5 — OrderStatus cascade** | Fully traced (§3B). 16 HEAD consumers, all repaired in-tree. Nothing removed while a consumer still depends on it |
| **PART 6 — Preparation dependency** | Preparation remains downstream. Its engines write only warehouse-assignment fields — a search for `'status'` in `WarehouseAssignmentEngine` and `BranchAssignmentEngine` returns **zero** hits. No Preparation/Wave feature is pulled in *except* the blocker in §5 |

## 5. 🔴 THE BLOCKER — `OrderServiceProvider.php` is irreducibly mixed

**Exact file:** `backend/Modules/Commerce/Orders/Infrastructure/Providers/OrderServiceProvider.php`

That one file's diff contains **two different tasks' work**, interleaved:

```php
// (A) THIS release — Orders availability/reservation recovery
$events->listen(InventoryStockReceived::class, [RetryReservation…, 'handleStockReceived']);
$events->listen(InventoryStockReleased::class, [RetryReservation…, 'handleStockReleased']);
$events->listen(InventoryStockAdjusted::class, [RetryReservation…, 'handleStockAdjusted']);
$events->listen(WarehouseAssigned::class, ExecuteReservationOnWarehouseAssigned::class);

// (C) ANOTHER task — Wave Operational Cycle carry-over (certified separately 2026-08-14)
$events->listen(WaveClosed::class, HandlePreparationWaveClosed::class);
$this->commands([… ReprocessLegacyReservationsCommand::class]);
```

**The dependency chain that makes it irreducible:**

```
OrderServiceProvider  (REQUIRED — without it nothing recovers)
  ├─ ExecuteReservationOnWarehouseAssigned      → this release ✅
  ├─ ReprocessLegacyReservationsCommand         → UNTRACKED (other task)
  └─ HandlePreparationWaveClosed                → UNTRACKED (other task)
       ├─ OrderPreparationCompletionReader      → UNTRACKED (Operations/DemandAnalysis)
       └─ ReturnToProcessingWorkflow            → tracked & clean ✅
```

**Why it cannot be resolved cleanly — the two exits are both closed:**

1. **Include the wave chain** (`HandlePreparationWaveClosed` + `OrderPreparationCompletionReader`
   + `ReprocessLegacyReservationsCommand`) → violates **PART 8**, which states the release unit
   *"MUST NOT contain … Preparation features, Wave implementation"*, and **PART 12**, *"Do NOT
   commit another session's unrelated work."*
2. **Edit the wave lines out of the provider** → violates **PART 12**, *"Do not modify the
   contents of unrelated files merely to make the commit clean"* — it would alter another
   agent's uncommitted work in a shared file.

**The fact that decides it — the certified runtime already contained the wave chain:**

| File | `ecos-dev-app` (where **Gate T was certified**) | `ecos-app` (target) |
|---|---|---|
| `HandlePreparationWaveClosed.php` | **PRESENT** | ABSENT |
| `ExecuteReservationOnWarehouseAssigned.php` | **PRESENT** | ABSENT |
| `OrderPreparationCompletionReader.php` | **PRESENT** | ABSENT |

So excluding the wave chain does not merely "leave a feature out" — it ships a provider that
registers a listener whose class is **absent**, and a `WaveClosed` event in production becomes a
fatal. It also means the target would run a **different configuration than the one certified**,
which PART 17 (runtime parity) and PART 21 (no new failure from deployment) forbid.

PART 12 is explicit for exactly this case: *"If safe separation is impossible without altering
another agent's work: STOP and report the exact blocker. Do not force a commit."* — so I stopped.

### The decision required (release scope, not business logic)

| Option | What ships | Cost |
|---|---|---|
| **1 — Ship the 3 dependency files with the release** *(recommended)* | Release unit + `HandlePreparationWaveClosed`, `OrderPreparationCompletionReader`, `ReprocessLegacyReservationsCommand` | Release contains 3 files from the Wave Operational Cycle task — itself **already certified 2026-08-14**. Reproduces the exact certified runtime. Bounded and audited: the reader touches only `order_lines` + `wave_product_demand` (which **exists** in `ecos_erp`) and needs **no additional migration** |
| **2 — The wave task's owner commits their chain first** | Nothing now; Orders releases immediately afterwards on top | Zero scope violation, cleanest history. Costs one hand-off |
| **3 — Strip the wave lines from the provider** | Release unit only | **Not recommended** — edits another agent's uncommitted work and breaks wave carry-over on `ecos-dev`, where it is certified and live |

I recommend **Option 2** if the other session is reachable, otherwise **Option 1** with the three
files explicitly disclosed in the commit message.

## 6. Target environment (PART 14) — identity verified, untouched

| Property | Value |
|---|---|
| Compose project | **`ecos-erp`** |
| Compose working dir | **`C:\ecos-develop`** — same worktree; ownership proven |
| Service / container | `app` / `ecos-app` |
| Image | `sha256:41d7827531f971e22d100554ee79be162b46f6cd614de46d775f03ffbefd8280` |
| Database | **`ecos_erp`** @ `mysql` |
| Orders present | 2 (`ORD-00001` awaiting_stock/awaiting_stock, `ORD-00002` in_progress/NULL) |

⚠ **Observed:** the target stack was launched with an extra overlay from another session —
`…\C--Projects-ECOS-ERP\8f54e9fe…\scratchpad\compose.verify.yml`. Another agent is operating on
this stack; deployment should be coordinated.

## 7. Migrations (PART 7, 16) — audited, none applied

| Migration | Target state | Class |
|---|---|---|
| `2026_07_18_100000_add_reservation_status_to_orders_table` | **applied** | already present |
| `2026_08_13_100000_supersede_order_lifecycle_v3_canonical` | **PENDING** | **required** by this release |
| Everything else in the dirty tree (Preparation `postponed_at`, `membership_release`, Distribution windows, recipe snapshots …) | pending | **unrelated — must not be applied** |

Target baseline: **698 migrations, max batch 100**. No `migrate:fresh`, no table drop, no reset,
no migration applied by this task.

## 8. Rollback baseline (PART 22)

| Item | Recorded value |
|---|---|
| Repo HEAD before release | `ec43b4701054c3d3e18d4186073b57ceded19436` |
| Target image | `sha256:41d78275…d8280` |
| Target migrations | 698, batch 100 |
| Target order rows | 2 (listed in §6) |

Rollback is currently trivial — **nothing was changed**. Note for the future: the ADR-042
migration performs `UPDATE orders SET status = …` normalisation, so once applied a rollback is
**not** purely structural; reverting would need the inverse mapping, which the migration defines.

## 9. Pre-commit verification (PART 9) — status

The release unit's suites were run in the immediately preceding certification task, against this
exact tree, and are carried forward as evidence; they were **not** re-run because no file changed
since:

| Suite | Result |
|---|---|
| `OrdersFinalCertificationHttpTest` (real HTTP) | **22 / 22 OK** |
| `OrderAvailabilityLifecycleContractTest` | **28 / 28 OK** |
| `OrderLifecycleAvailabilityReservationClosureTest` | **6 / 6 OK** |
| consolidated closure run | **56 tests / 231 assertions OK** |
| `OrderDrivenMaterialReservationTest` + `AvailabilityStateDerivationTest` + `MaterialAvailabilityContractTest` + `OperationsIntegrationFinalCertTest` (ADR-026 `scenario_d`) | **47 / 192 OK** |
| `tests/Feature/Orders` | 90 tests, **12 failures** — all PRE-EXISTING (§10) |
| PHPStan L0 / core L6, Pint, tsc, ESLint | clean on release files |

**The full pre-commit gate must be re-run immediately before the commit is actually made**, per
PART 9. It was not run now because there is nothing to commit.

## 10. Regression classification (PART 10)

| Failure | Count | Class | Evidence |
|---|---|---|---|
| `OrderManufacturingIntegrationTest` | 11 | **PRE-EXISTING / OUTDATED TEST** | `ManufacturingLifecycleHandler` (committed, clean) whitelists `pending/processing/preparing` — none survives ADR-042 — and `PrepareOrderAction` sets `ready_for_dispatch` before evaluation. Off the certified chain (reachable only via the legacy `POST /orders/{order}/prepare`, zero frontend callers) |
| `OrderFinancialSnapshotTest::…mismatched_subtotal` | 1 | **PRE-EXISTING** | `CreateOrderSnapshotService` + `IntegrityEngine` both committed and clean |

**Zero REAL REGRESSIONS. Zero CERTIFIED CONTRACT VIOLATIONS.** No assertion was modified and no
unrelated failure was "fixed" to obtain green.

## 11. Certification matrix (PART 23)

| Gate | Result | Evidence |
|---|---|---|
| A. Certified Orders behavior | ✅ INTACT | Gate T, unchanged; no production file touched |
| B. Availability | ✅ | 6-case HTTP matrix + browser |
| C. Awaiting Stock | ✅ | ORD-00010 live |
| D. Awaiting Payment | ✅ | ORD-00009 live, payment block held |
| E. Awaiting Warehouse | ✅ | ORD-00007/8, `pending` ≠ Awaiting Stock |
| F. Stock Recovery | ✅ | automatic, via canonical `initiate_order` |
| G. Reservation | ✅ | committed `ec43b470` |
| H. Raw Material Reservation | ✅ | 7×3 = 21.0 on the assigned warehouse |
| I. Tenant Isolation | ✅ | 4 vectors incl. restricted operator |
| J. Idempotency | ✅ | HTTP confirm ×2, RM event ×3 |
| K. ADR-026 | ✅ | `scenario_d` 3/3; transfer listeners still unregistered |
| L. ADR-027 | ✅ | §16.4 tenant scoping committed & clean (§4) |
| M. ADR-042 | ✅ | cascade fully traced, 16/16 consumers repaired |
| N. Preparation Boundary | ✅ | zero `'status'` writes in Preparation engines |
| O. Migration | ✅ AUDITED | 1 required, pending; none applied |
| P. Release Manifest | ✅ COMPLETE | §3 |
| Q. Commit Integrity | 🔴 **BLOCKED** | §5 — provider entanglement |
| R. Deployment Parity | ⛔ NOT ATTEMPTED | gated behind Q |
| S. Target HTTP Runtime | ⛔ NOT ATTEMPTED | gated behind Q |
| T. Target Browser Smoke | ⛔ NOT ATTEMPTED | gated behind Q |
| U. Regression | ✅ | 12 failures, all pre-existing |

## 12. Final verdict

> ### NOT CERTIFIED — RELEASE SCOPE BLOCKER (Gate Q)
>
> - **Exact file:** `backend/Modules/Commerce/Orders/Infrastructure/Providers/OrderServiceProvider.php`
> - **Exact dependency:** `HandlePreparationWaveClosed` → `OrderPreparationCompletionReader`
>   (+ `ReprocessLegacyReservationsCommand`), all untracked and owned by the Wave Operational
>   Cycle task
> - **Exact contract in conflict:** PART 8 (*no Wave implementation in the release unit*) vs
>   PART 17/21 (*target must serve the certified runtime*) — the certified runtime contained
>   those files
> - **Exact minimum repair:** one of the three options in §5; **Option 2** (wave owner commits
>   first) is cleanest, **Option 1** (ship 3 disclosed dependency files) is fastest
> - **Why it blocks:** the provider is mandatory for the certified behaviour and cannot be
>   committed without either shipping another task's work or editing it

**Everything within this task's control is complete:** manifest built and classified, cascade
traced, dependencies verified, target identified, migrations audited, rollback baseline recorded.
The moment the scope decision is made, the release can proceed directly to PART 9 → 13 → 15.

Nothing was committed, deployed, migrated, reset, or stashed. No other session's work was
altered, deleted, or overwritten.

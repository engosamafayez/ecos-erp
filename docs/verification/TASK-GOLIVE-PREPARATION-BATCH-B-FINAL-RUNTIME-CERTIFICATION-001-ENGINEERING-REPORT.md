# TASK-GOLIVE-PREPARATION-BATCH-B-FINAL-RUNTIME-CERTIFICATION-001 — Engineering Report

**Date:** 2026-08-10
**Runner:** `ecos-dev-testrunner` · **Database:** `ecos_dev_test`
**Type:** Runtime certification. No production code modified. No defect repaired. Nothing committed.
**Verdict:** Section 35.

---

## 1 — Executive Summary

Certification was executed in the newly certified isolated environment. It **halted on a STOP condition**.

**The two Preparation entry-point defects reproduce in the clean isolated environment.** They were first seen
in the previously contaminated shared database, so the decisive question was whether they were artefacts of
that environment. They are not:

```
CONTROL: reserved order -> wave create http=201 attached=1                      PASS (probe is valid)
BYPASS PROBE (awaiting_stock -> wave): wave_create_http=201 attached_rows=1     DEFECT
BYPASS PROBE (recalculate):            http=200 attached_blocked_rows=0         PASS (this route is correct)
TENANT PROBE (foreign order -> wave):  http=201 attached_rows=1                 DEFECT
```

Per Parts 7, 21 and the FINAL STOP CONDITIONS (*"an invalid AwaitingStock Order enters Preparation"*),
execution stopped. Allocation, Picking, Loading, Shipment, Recovery and Cancellation were **not** executed —
they are reported NOT EXECUTED, not assumed.

**Three findings clear the certified baseline of blame:**

* **RC-10 = 17/17** (55 assertions) — F4 + Option B active, contract intact.
* **The 13 `OrderManufacturingIntegrationTest` failures are NOT an F4/Option B regression.** Reproduced
  identically here and classified **B — fixture ownership defect**, with the exact mechanism proven from
  source. The "F4 regresses"/"Option B regresses" STOP conditions did **not** fire.
* **PHPStan L0 + core L6 = 0 errors** (cold). Guardian 7/8 with a proven pre-existing Pint failure.

The Preparation happy path is genuinely sound — demand aggregation, availability arithmetic, non-consumption
during preparation and partial preparation all pass against **real persisted Orders**. The defect is at the
*entry gate*, not in the mechanics.

## 2 — Environment — **PASS**

| Check | Result |
| --- | --- |
| Container | `ecos-dev-testrunner` (Up, healthy) |
| `config('app.env')` | `testing` |
| `config('database.connections.mysql.database')` | **`ecos_dev_test`** |
| `DB::connection()->getDatabaseName()` | **`ecos_dev_test`** |
| `SELECT DATABASE()` | **`ecos_dev_test`** |
| `configurationIsCached()` | **`false`** |
| host / port | `mysql` / `3306` (DEV network) |
| Reachable databases | `ecos_dev`, `ecos_dev_test`, `information_schema`, `performance_schema` |
| `ecos_erp` / `ecos_erp_test` reachable | **NO / NO** |

Pre-flight ran before every DB-backed suite. No `RefreshDatabase` executed before the target was proven.

## 3 — Starting Commit — **PASS**

```
HEAD   : 6149875bd8a01820116b5deacbbfb8ef0e51cc05
branch : develop
production business logic diff (backend/Modules, docs/adr): 4 files, +185/−18  — UNCHANGED
```

## 4 — Artifact Identity — **PASS (runner is not stale)**

Because the runner's source is baked into its image, staleness would silently invalidate everything. A full
SHA-256 manifest of **4,508** PHP files (`Modules`, `app`, `tests`, `config`, `database`, `routes`,
`bootstrap`) was compared worktree ↔ runner:

```
ONLY IN WORKTREE : 0
CONTENT DIFFERS  : 2   bootstrap/cache/packages.php, bootstrap/cache/services.php
ONLY IN RUNNER   : 2   bootstrap/cache/events.php,   bootstrap/cache/routes-v7.php
```

Every difference is a generated `bootstrap/cache/` artefact. **Zero source files differ.** F4 and Option B
confirmed present in the runner:

```
ManufacturingAvailabilityService.php:71   $companyId = $product->brand?->company_id;      (F4)
ReserveOrderInventoryAction.php:159       $product?->can_manufacture && $this->manufacturingIsExecutable(...)  (Option B)
```

## 5 — Real Order — **PASS**

Real persisted `Order` + `OrderLine` rows with real Company, Warehouse, Customer and Product, driven through
the production HTTP route `POST /api/fulfillment/orders/{id}/transition`. No factory-only shortcut was used
as the certification path. Initial state `in_progress`, `reservation_status` null.

## 6 — Reservation — **PASS**

Two real orders (3 units + 2 units, same product) reserved through the real transition:

```
orderA.reservation_status = Reserved
orderB.reservation_status = Reserved
inventory_items.reserved_qty = 5.0    (read from the database, not from the HTTP response)
```

## 7 — Recipe Gate — **PASS (baseline, unchanged)**

Certified baseline, not reopened. Evidence on this worktree: `RecipeGateTenantRepairTest::part15`
(`outofstock → awaiting_stock`, `reserved_qty = 0`, **0** `stock_ledger_entries`, **0**
`inventory_layer_consumptions`) and `RecipeToOrderAvailabilityE2ETest::test_b/test_e`.

## 8 — AwaitingStock — **PASS (entering the state)**

`Rc10LifecycleCertificationTest::test_insufficient_stock_diverts_to_awaiting_stock` — PASS in this run
(17/17). The real engine performs the diversion; no status is written by hand.

## 9 — Recovery — **NOT EXECUTED**

Halted by the STOP condition. The production path was traced (Section 34.3) but not exercised. No behaviour
is claimed.

## 10 — Preparation Entry — **FAIL — BYPASS DEFECT (Section 34.1)**

## 11 — Inventory Semantics — **PASS**

Fixture `on_hand = 10`, ordered `6`:

```
AVAILABILITY: on_hand=10 reserved=6 available=4
START PREPARATION http=200
```

| Invariant | Result |
| --- | --- |
| `on_hand` unchanged by preparation | **PASS** (10.0) |
| `reserved_qty` unchanged by preparation | **PASS** (6.0) |
| FIFO layer `remaining_qty` unchanged | **PASS** |
| `inventory_layer_consumptions` | **0 — PASS** |

Preparation consumes no physical inventory.

## 12 — Wave — **PASS**

Wave created via `POST /api/preparation/waves` over **two real persisted Orders** — the exact gap Batch A
recorded, since the pre-existing wave suites attach `Str::uuid()` strings. `preparation_wave_orders` holds
exactly the two real order ids; `company_id` and `warehouse_id` correct.

## 13 — Demand — **PASS**

```
DEMAND: product=<id> required=5.0000   (orderA=3 + orderB=2)
```

Aggregated correctly, no duplication, no loss, bound to the real orders.

## 14 — Allocation — **NOT EXECUTED**

Halted by STOP. The full production path is now documented (Section 34.3) — 10 verified routes from
`POST /api/loading/sessions` through `start-allocation` / `complete-allocation`, with exact FormRequest rules
and the tables each step writes. **Remains the mandatory gap it was in Batch A.**

## 15 — Picking — **NOT EXECUTED + ARCHITECTURAL FINDING**

Halted by STOP. Path tracing also established something that changes how this scenario should be written:

> `PATCH /api/preparation/waves/{waveId}/items/{itemId}/complete` resolves `{itemId}` against
> **`preparation_wave_items.id`**, *not* `preparation_pick_list_items.id`
> (`PreparationWaveController.php:244-250`).

So wave-item completion is **not** pick-list execution. `PreparationPickList` / `PreparationPickListItem` have
no distinct HTTP execution path, and the traced subsystem was classified `partial`. Certifying "Picking" by
running item-completion would have been a false positive. Recorded rather than glossed.

## 16 — Partial Preparation — **PASS**

```
PARTIAL:   required=10.0000 prepared=6.0000  short=4.0000 status=short
COMPLETED: required=10.0000 prepared=10.0000 short=0.0000 status=prepared
```

Not silently marked 10/10 at the partial step; the remaining 4 stayed actionable; the total reached exactly
10 — nothing lost, nothing invented.

## 17 — Completion — **PASS**

`POST /api/preparation/waves/{id}/complete` → 200, wave `status=completed`, driven through the real path with
no manual status write. Physical inventory untouched at completion (`on_hand` still 50.0).

## 18 — Loading — **NOT EXECUTED**

Halted by STOP. 18 routes traced and verified (Section 34.3). Prior evidence from the earlier Batch B run
(`DistributionModuleTest` 50/50, `DeliveryModuleTest` 35/35) is **not** re-claimed here — it was produced in
the contaminated environment.

## 19 — Shipment — **PASS (within RC-10 only)**

`Rc10LifecycleCertificationTest::test_full_lifecycle_reaches_delivered_and_consumes_fifo` passed in this run:
FIFO layer 10→8 and `on_hand` 10→8 at dispatch, consumption at the architecture-defined point and **not** at
preparation. The prepared→loaded→shipped junction was **not** exercised.

## 20 — Full Lifecycle — **INCOMPLETE**

Proven in this run: Order → Reservation → Wave → Demand → Preparation → Item completion → Wave completion,
plus (separately, via RC-10) Reservation → Dispatch → Delivered with FIFO consumption. **Not** proven: a
single order traversing wave **and** loading **and** shipment in one chain. Halted by STOP.

## 21 — Reservation Consistency — **PARTIAL PASS**

Across the stages executed, quantities stayed coherent: no double reservation (6.0 before and after
preparation start), no orphan, no negative, no drift (3+2 → demand 5; 6 then 10, never 14), `on_hand` and
FIFO untouched until shipment. Allocated / Picked / Loaded stages were not reached.

## 22 — Bypass Routes — **FAIL**

| Route | Enforces the reservation prerequisite? | Evidence |
| --- | --- | --- |
| `POST /api/fulfillment/orders/{id}/transition` (`MoveToPreparationWorkflow`) | **Yes** — guard requires `InProgress` | RC-10 17/17 |
| `POST /api/preparation/waves` (`CreateWaveAction`) | **NO — BYPASS** | HTTP 201, `attached_rows=1` for `awaiting_stock` |
| `POST /api/preparation/waves/{id}/recalculate` | **Yes** | `attached_blocked_rows=0` |
| `PreparationReleaseEngine` | **UNVERIFIED** — zero test references | unchanged from Batch A |
| `OrderPreparationObserver` | **UNVERIFIED** — zero test references | unchanged from Batch A |
| `PreparationSessionPolicy` | **UNVERIFIED** — zero test references | unchanged from Batch A |

The routes do not enforce the same prerequisites. Two of them disagree with each other about the *same order*.

## 23 — Cancellation — **NOT EXECUTED**

Halted by STOP. `CancelWaveAction` path traced and confirmed to exist as a full HTTP path, but its effect on
order restoration, reservations and allocations was not exercised. **UNVERIFIED** — no behaviour invented.

## 24 — Company Isolation — **FAIL at the Preparation entry point**

```
TENANT PROBE (foreign order -> wave): http=201 attached_rows=1
  stamped_company = <ACTOR's company>      owner_company = <different company>
```

An order owned by Company B was attached to Company A's wave and stamped with the **actor's** company.

Recipe-level company isolation remains **PASS** (certified baseline: `part6`, `part7`, `part8`,
`part8_fail_closed`) — the failure is specifically at the wave entry gate.

## 25 — Cross-brand — **PASS (baseline, unchanged)**

Certified baseline, not reopened. `RecipeCrossBrandReuseTest` 3/3 + `part20` on this worktree:
`CROSS_BRAND: one raw material -> A=instock B=instock C=instock`. RM is **not** scoped to Brand.

## 26 — Negative Stock — **PASS (baseline, unchanged)**

Certified baseline. `part19` multi-material policy matrix 6/6, `part16`, `NegativeStockReservationTest` 5/5.
Preparation reuses the authoritative availability engine; no second negative-stock rule exists.

## 27 — 13-Failure Recheck — **REPRODUCED · CLASSIFIED B (fixture ownership defect)**

Re-run in the certified environment against `ecos_dev_test`:

```
Tests: 16, Assertions: 16, Errors: 2, Failures: 11      (13 bad — identical to the previous run)
```

**Mechanism, proven from source — not inferred:**

| Fixture element | Ownership |
| --- | --- |
| `setUp()` line 67 | `$this->company = Company::factory()->create()` — a standalone company |
| `seedInventory()` line 153 | stamps component inventory with **`$this->company->id`** |
| `makeOutput()` line 109 | `Product::factory()->finishedGood()` → `ProductFactory` line 34 `brand_id => Brand::factory()` → line 37 `company_id` derived from that brand — **a different, factory-generated company** |

The fixture never links the finished good's brand-company to the seeded inventory's company. Under F4's
company scoping the components are invisible → recipe `outofstock` → Option B withholds the manufacturing
commitment → order diverts to `awaiting_stock` and manufacturing never runs, leaving `manufacturing_result`
and `manufacturing_started_at` null.

**Classification: B — fixture ownership defect.** F4 behaves exactly as ADR-027 §16.4 specifies (component
inventory must belong to the finished good's company). This is **not** category A, so the "F4 regresses" and
"Option B regresses" STOP conditions did **not** fire.

**Not repaired.** Part 26 pre-authorises a fixture-only correction, but a STOP condition is active and Part 34
forbids repair inside Batch B. The correction belongs in the follow-up repair task: make the fixture build a
coherent chain — create the Brand under `$this->company` and pass it to `Product::factory()` — so that
`product->brand->company_id === inventory.company_id`. **Do not weaken F4 to make the fixture pass.**

Residual note (unproven, and it matters): if production data can hold inventory whose `company_id` differs
from the product's brand company, F4 would silently render those recipes unexecutable in production. That
question is architectural and was not answered here.

## 28 — RC-10 — **PASS 17/17**

17 tests, 55 assertions, exit 0, 449.4 s. Includes `full_lifecycle_reaches_delivered_and_consumes_fifo`,
`reservation_is_the_first_warehouse_gate`, `insufficient_stock_diverts_to_awaiting_stock`,
`dedicated_move_to_preparation_runs_through_the_engine`, `cannot_transition_an_order_belonging_to_another_company`.
No RC-10 test was modified or weakened.

## 29 — Phase 3 — **NOT EXECUTED**

Halted by the STOP condition. **Not** marked PASS. The environment did not block it — execution was stopped
deliberately, which is recorded here rather than presented as an environmental limitation.

## 30 — PHPStan — **PASS**

Cold run, result cache cleared, 145.4 s:

| Config | Level | Scope | Result | Exit |
| --- | --- | --- | --- | --- |
| `phpstan.neon.dist` | 0 | `Modules` + `app` | `[OK] No errors` | 0 |
| `phpstan-core.neon.dist` | 6 | `app/Core`, `Contracts`, `Traits` | `[OK] No errors` | 0 |

Both ratcheted against baselines — **no NEW violations**.

## 31 — Guardian — **FAIL (Pint only, PRE-EXISTING)**

`guardian.sh pre-push`, 8 validators, 239 s, exit 1:

| Validator | Result |
| --- | --- |
| PHP Syntax | PASS |
| Composer Validate | SKIP |
| Laravel Bootstrap | PASS |
| **Laravel Pint** | **FAIL** |
| PHPStan | PASS |
| ESLint | PASS |
| TypeScript | PASS |
| Vite Production Build | PASS |

```
push range: f0d7822abace...HEAD    changed PHP: 26 file(s)
NEW Pint violations in 2 file(s) not in the baseline:
  backend/tests/Feature/Inventory/ProductPopulationScopeTest.php   [changed in this push] ordered_imports
  backend/tests/Feature/Operations/V3TransitionResolutionTest.php  [changed in this push] binary_operator_spaces
```

**Parent-commit control:** the push range `f0d7822a...HEAD` *includes* commit `6149875b`, and Pint attributes
both files to that commit — neither appears in the working-tree diff. Guardian was therefore already red at
the starting commit. **None of the test files added across this or prior tasks appear**; a scoped
`pint --test` on them returned `{"tool":"pint","result":"passed"}`. `--no-verify` was not used; nothing was
suppressed; the two files were not modified.

## 32 — Failure Classification

| Failure | Classification | Basis |
| --- | --- | --- |
| AwaitingStock order attached to a wave | **NEW — genuine production defect** | Reproduced in the clean isolated environment with a passing positive control |
| Foreign-company order attached to a wave | **NEW — genuine production defect** | Same run; the attached row carries the actor's `company_id` |
| 13 × `OrderManufacturingIntegrationTest` | **PRE-EXISTING (category B — fixture ownership defect)** | Reproduced identically; mechanism proven from `ProductFactory` + fixture source. Two of the 13 were green at HEAD, red on the worktree — but the *cause* is the fixture's incoherent ownership chain meeting F4, not an F4 regression |
| Guardian Pint (2 files) | **PRE-EXISTING** | Attributed by Pint to commit `6149875b`; absent from the working-tree diff |

No ENVIRONMENT failures occurred — the isolated runner behaved correctly throughout.

## 33 — Evidence Matrix

| # | Scenario | Result | Runtime | DB Evidence | Classification |
| --- | --- | --- | --- | --- | --- |
| 1 | Real Order | **PASS** | real HTTP transition | persisted `orders` + `order_lines` | — |
| 2 | Reservation | **PASS** | real transition | `reservation_status=Reserved`, `reserved_qty=5.0` | — |
| 3 | Recipe unavailable | **PASS** | baseline suites | `reserved_qty=0`, 0 ledger, 0 FIFO | baseline |
| 4 | AwaitingStock | **PASS** | RC-10 | status persisted `awaiting_stock` | — |
| 5 | **AwaitingStock protection** | **FAIL** | `POST /api/preparation/waves` → 201 | `preparation_wave_orders` = 1 row | **NEW defect** |
| 6 | Recovery | **NOT EXECUTED** | — | — | halted by STOP |
| 7 | allow_negative_stock | **PASS** | baseline | matrix 6/6 | baseline |
| 8 | `on_hand − reserved` | **PASS** | real reservation | `10 − 6 = 4` | — |
| 9 | Preparation no consumption | **PASS** | `waves/{id}/start` → 200 | `on_hand` 10 unchanged, FIFO unchanged, 0 consumptions | — |
| 10 | Real Wave | **PASS** | → 201 | 2 real order ids attached | — |
| 11 | Demand | **PASS** | `generate-demand` → 200 | `quantity_required=5.0000` | — |
| 12 | **Allocation** | **NOT EXECUTED** | — | — | mandatory gap |
| 13 | **Picking** | **NOT EXECUTED** | — | — | mandatory gap + no distinct path |
| 14 | Partial Preparation | **PASS** | 2× item complete | `6/short 4` → `10/short 0` | — |
| 15 | Preparation completion | **PASS** | `complete` → 200 | wave `completed`, `on_hand` untouched | — |
| 16 | Loading boundary | **NOT EXECUTED** | — | — | halted by STOP |
| 17 | Shipment | **PASS (RC-10 only)** | RC-10 dispatch | FIFO 10→8, `on_hand` 10→8 | partial scope |
| 18 | Full lifecycle | **INCOMPLETE** | partial chain | — | halted by STOP |
| 19 | Reservation consistency | **PARTIAL PASS** | stages executed | no double/orphan/negative/drift | — |
| 20 | **Bypass routes** | **FAIL** | wave-create bypass; recalculate correct | routes disagree | **NEW defect** |
| 21 | Wave cancellation | **NOT EXECUTED** | — | — | UNVERIFIED |
| 22 | **Company isolation** | **FAIL (wave entry)** | foreign order → 201 | row stamped with actor's company | **NEW defect** |
| 23 | Cross-brand RM | **PASS** | baseline | `A=instock B=instock C=instock` | baseline |
| 24 | Negative stock | **PASS** | baseline | matrix 6/6 | baseline |
| 25 | 13-failure recheck | **REPRODUCED** | 16 tests, 13 bad | identical to prior run | **category B** |
| 26 | RC-10 | **PASS** | 17/17, 55 assertions | — | — |
| 27 | Phase 3 | **NOT EXECUTED** | — | — | halted by STOP |
| 28 | PHPStan | **PASS** | L0 + core L6 cold | 0 errors | — |
| 29 | Guardian | **FAIL** | 7/8 | Pint only | **PRE-EXISTING** |

## 34 — Defect Records (reported, NOT repaired)

### 34.1 — DEFECT 1 — Preparation Wave entry does not enforce the Reservation gate

* **Reproduction:** `PreparationBypassGuardTest::test_an_awaiting_stock_order_must_not_be_attached_to_a_preparation_wave`,
  run in `ecos-dev-testrunner` against `ecos_dev_test`.
* **Path:** `POST /api/preparation/waves` → `PreparationWaveController::store()` (line 101) → `CreateWaveAction`.
* **Code:** `guardOrdersReservable()` (line 671) checks **only** whether the order is already in an active
  wave (`whereNotIn('pw.status', ['completed','cancelled'])`). No order-status or reservation-status check.
  `CreateWaveRequest` validates `order_ids.*` as `uuid` only — no `exists:orders,id`, no status rule.
* **Expected:** an order in `awaiting_stock` with `reserved_qty = 0` is refused.
* **Actual:** HTTP 201; one `preparation_wave_orders` row created.
* **DB state:** order `status=awaiting_stock`, `reservation_status=awaiting_stock`, `reserved_qty=0`, yet
  attached to the wave.
* **Impact:** orders with no inventory commitment enter preparation planning. Demand, wave KPIs and operator
  queues are computed over stock that was never reserved, and the Reservation gate — the system's first
  warehouse control — is circumventable through a second, equally legitimate UI path.
* **Likely root cause:** the guard's name promises a reservation check its body never performs.
  `RecalculateWaveAction` already refuses the same order, so **the correct behaviour exists in the codebase**
  and the two routes are simply inconsistent.

### 34.2 — DEFECT 2 — Preparation Wave entry does not enforce the company boundary

* **Reproduction:** `PreparationBypassGuardTest::test_an_order_from_another_company_must_not_be_attached_to_a_wave`.
* **Path:** same. `store()` loads orders via `DB::table('orders')->whereIn('id', $orderIds)` (line 113) with
  **no company predicate**, then writes `preparation_wave_orders` stamped with `$dto->companyId` (the actor's).
* **Expected:** refusal.
* **Actual:** HTTP 201; foreign order attached and re-stamped with the actor's company.
* **Impact:** cross-tenant read of order data (order number, customer name, delivery zone and shipping cost
  are copied into the wave snapshot) plus cross-tenant write of wave membership. This is the class of defect
  UAT Campaign 1 flagged as P0 multi-company scoping.
* **Caveat, unchanged from the first report:** the foreign order in this fixture uses the test's warehouse,
  which is a slightly artificial combination. The core finding stands — the attached order's `company_id` was
  Company B's — but a repair task should re-run with a fully Company-B-owned warehouse to remove all doubt
  before sizing the fix.

### 34.3 — Production paths traced for the follow-up repair task

A 12-agent read-only trace verified the exact routes, FormRequest rules, actions and written tables for the
subsystems that remain unexecuted, so the repair task need not rediscover them:

| Subsystem | Status | Entry point |
| --- | --- | --- |
| Allocation | `full_http_path` | `POST /api/loading/sessions` → `open` → `assignments` → `start-loading` → `load-product` → `complete-loading` → `start-allocation` → `complete-allocation` (10 routes verified, `routes/api.php:882-920`) |
| Loading boundary | `full_http_path` | 18 routes verified, incl. Logistics trips |
| Shipment / consumption | `full_http_path` | `POST /api/fulfillment/orders/{order}/dispatch` → `ShipOrderInventoryAction` → `ShipStockAction` + `InventoryLayerConsumptionService::consume` |
| AwaitingStock recovery | `full_http_path` | replenish via `POST /api/goods-receipts` + `/post`, then `POST /api/fulfillment/orders/{order}/resume` or `/transition` |
| Wave cancellation | `full_http_path` | `POST /api/preparation/waves/{waveId}/cancel` → `CancelWaveAction` |
| **Picking** | **`partial`** | **No distinct pick-list execution path** — see Section 15 |

## 35 — Certification Verdict

Part 33 permits certification only when *all* mandatory runtime paths execute and Allocation, Picking,
AwaitingStock protection, Recovery, Partial Preparation, bypass routes and the full real-Order lifecycle all
pass, with no NEW defect unresolved.

Three mandatory scenarios are **refuted by executed runtime evidence** in the certified isolated environment
(AwaitingStock protection, bypass routes, company isolation at the wave entry). Six more are **NOT EXECUTED**
because the STOP condition halted the run. Two NEW production defects remain unresolved.

# PREPARATION BACKEND = NOT CERTIFIED

**What is *not* to blame:** the certified baseline. RC-10 is 17/17, F4 and Option B are active and correct,
cross-brand reuse and negative-stock policy hold, PHPStan is clean, and the 13-failure cluster is a fixture
ownership defect rather than an F4/Option B regression. The Preparation *mechanics* — demand, availability
arithmetic, non-consumption, partial preparation — all pass against real orders.

**What blocks certification:** two defects at the Preparation **entry gate**, both proven twice, in two
independent environments, with a passing positive control.

A separate repair task should address §34.1 and §34.2, then re-run this certification from Part 7 onward.

## 36 — Attestations

* No production business logic modified. F4, Option B, Reservation, Preparation, Recipe and Inventory
  behaviour untouched — `git diff --stat HEAD -- backend/Modules docs/adr` = **4 files, +185/−18**, unchanged.
* **No discovered defect was repaired.** Prove first, repair second.
* No test was modified or weakened. RC-10 untouched. `force="true"` and the `TestCase` guards intact.
* All DB-backed execution ran in `ecos-dev-testrunner` against `ecos_dev_test`. Never `ecos-dev-app`.
* **MAIN untouched throughout** — containers, images, volumes identical; `ecos_erp` 551 tables / 39.19 MB and
  `ecos_erp_test` 550 tables / 25.50 MB, row counts identical before and after; `C:\Projects\ECOS-ERP` clean.
* No Batch B repair, no Go-Live, no release commit. **Nothing committed.**

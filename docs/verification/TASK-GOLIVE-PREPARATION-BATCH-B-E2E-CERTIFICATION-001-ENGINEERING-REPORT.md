# TASK-GOLIVE-PREPARATION-BATCH-B-E2E-CERTIFICATION-001 — Engineering Report

**Date:** 2026-08-10
**Type:** Runtime certification. No production code was modified. Nothing was committed. No defect was repaired.
**Verdict:** Section 33.

---

## 1 — Executive Summary

Batch B executed. The Preparation OS was driven end-to-end with **real persisted orders through real HTTP**,
closing the structural gap Batch A recorded (the pre-existing wave suites attach `Str::uuid()` strings and
never create an `Order` row).

Three results dominate.

**1. Two production defects are PROVEN at runtime at the Preparation wave entry point.** An order the
Reservation gate refused — persisted `awaiting_stock`, `reserved_qty = 0` — was accepted into a Preparation
Wave (`HTTP 201`, one `preparation_wave_orders` row). Separately, an order owned by **another company** was
attached to this company's wave and stamped with the **actor's** `company_id`. Both were produced by real
HTTP against the real controller, with a passing positive control in the same file.

**2. A NEW regression is confirmed, reversing the previous certification's headline.**
`TASK-GOLIVE-PREPARATION-RUNTIME-CERTIFICATION-001` reported **NEW (0)**. That finding was scope-limited: its
Batch A never executed `tests/Feature/Orders`. Executing it here, two tests that **pass at HEAD fail on the
worktree**. The F4 + Option B change is the cause.

**3. The Preparation happy path is genuinely sound.** Demand aggregation, availability arithmetic,
non-consumption of inventory during preparation, and partial preparation all pass against real orders — as do
the two Phase 3 tenant-isolation suites and the Loading-boundary suites that were INCOMPLETE previously.

The Preparation Backend cannot be certified: mandatory scenarios are refuted, not merely unproven.

---

## 2 — Starting Commit

```
$ git rev-parse HEAD
6149875bd8a01820116b5deacbbfb8ef0e51cc05
```

Worktree differs from HEAD: **4 files changed, 185 insertions(+), 18 deletions(-)** plus untracked test and
verification files. **Certification targeted the WORKTREE**, which carries F4 + Option B. Nothing was reset,
checked out, stashed, cleaned or discarded. F4, Option B, Recipe ownership and `allow_negative_stock`
semantics were not reopened or altered.

New untracked test files authored by this task (tests only — not production code):

* `backend/tests/Feature/Operations/PreparationBypassGuardTest.php`
* `backend/tests/Feature/Operations/PreparationLifecycleE2ETest.php`

---

## 3 — Environment Safety (Part 1) — PASS

| # | Check | Result |
| --- | --- | --- |
| 1 | Git HEAD | `6149875b` |
| 2 | Working tree | 4 modified + untracked; verified unchanged at start and end |
| 3 | Container identity | `ecos-mysql` MySQL 8.4.10, `RestartCount=0`, `OOMKilled=false`, healthy |
| 4 | Application environment | `APP_ENV=testing` under PHPUnit |
| 5 | Database target | `ecos_erp_test` @ `127.0.0.1:3306` |
| 6 | Config cache | **Absent** on the host runner — env read live |
| 7 | `phpunit.xml` | `DB_CONNECTION=mysql` and `DB_DATABASE=ecos_erp_test` both `force="true"` |
| 8 | `TestCase.php` guard | Overrides `putenv`, `$_ENV`, `$_SERVER`, then resets the `Env` repository singleton |
| 9 | Cannot target production | Probe: `config db name = ecos_erp_test`, `live connection db = ecos_erp_test`, `config cached = no` |

Safety baseline held throughout: **`ecos_erp` = 551 tables, `orders` = 2 rows, never modified.**

### 3.1 — Concurrency hazard (discovered mid-task)

A second certification agent was operating on the **same `ecos_erp_test` database concurrently**. This is the
root cause of the schema-build instability the previous report could not isolate, and it destroyed several of
this task's runs (including PHP processes terminated by the other run's `taskkill /F /IM php.exe`).

**Two agents must not certify against one test database concurrently.** Every result recorded below comes
from a run that completed cleanly end-to-end; contaminated runs were discarded, not reported.

---

## 4 — Baseline (Part 2)

Batch B — the five suites the previous certification listed but never executed.

| Metric | Value |
| --- | --- |
| Tests | **119** |
| Assertions | **527** |
| Failures | **11** |
| Errors | **2** |
| Duration | **425 s** |

| File | Result |
| --- | --- |
| `WarehouseTenantIsolationTest` | **13 / 13 PASS** |
| `SupplierTenantIsolationTest` | **5 / 5 PASS** |
| `DistributionModuleTest` | **50 / 50 PASS** |
| `DeliveryModuleTest` | **35 / 35 PASS** |
| `OrderManufacturingIntegrationTest` | **3 pass / 13 bad** |

All 13 bad outcomes are confined to one file. See Section 30.

---

## 5 — Real Order (Part 3)

Orders were created as persisted `Order` + `OrderLine` rows with a real Company, Warehouse, Customer, Product
and quantity, then driven through the **real HTTP fulfillment route**
`POST /api/fulfillment/orders/{id}/transition`. This mirrors the fixture shape of the already-certified
`Rc10LifecycleCertificationTest`, which drives the identical production path.

Recorded per order: id, company, warehouse, product, quantity, initial status `in_progress`, reservation
status `null`.

---

## 6 — Reservation (Part 4) — PASS

Two real orders for one product (3 units and 2 units) were reserved through the real transition.

```
orderA.reservation_status = Reserved
orderB.reservation_status = Reserved
inventory_items.reserved_qty = 5.0     (3 + 2, read from the database)
```

Assertions read persisted rows, not the HTTP status. **PASS.**

---

## 7 — Recipe Gate (Part 5)

Not re-executed in this task. Already certified against this worktree by
`RecipeGateTenantRepairTest::test_part15_unexecutable_recipe_blocks_manufacturing_reservation` and
`RecipeToOrderAvailabilityE2ETest::test_b/test_e`, which assert `outofstock → awaiting_stock`,
`reserved_qty = 0`, **0** `stock_ledger_entries` and **0** `inventory_layer_consumptions`.

Status: **PASS (carried, same worktree)** — see Section 26.

---

## 8 — AwaitingStock protection (Part 6) — **FAIL — PROVEN BYPASS DEFECT**

The order was driven into Awaiting Stock by the **real engine** (shortage diversion), not by a hand-written
status. Preconditions asserted from the database before the attack:

```
order.status            = awaiting_stock
order.reservation_status= awaiting_stock   (not Reserved)
```

Then `POST /api/preparation/waves` was called with that order id.

```
BYPASS PROBE (awaiting_stock -> wave):
  order_status=awaiting_stock  reservation=awaiting_stock
  wave_create_http=201         attached_rows=1
```

**The wave was created and the Awaiting Stock order was attached.** `preparation_wave_orders` holds a real
row for an order that has no inventory commitment.

Positive control in the same file — proving the probe is meaningful and not a broken fixture:

```
CONTROL: reserved order -> wave create http=201 attached=1   ✔ PASS
```

Second entry point, `POST /api/preparation/waves/{id}/recalculate`, **did not** attach the blocked order:

```
BYPASS PROBE (recalculate): http=200 attached_blocked_rows=0   ✔ PASS
```

So the two membership-mutating routes disagree with each other. **Part 6 STOP condition met. Not repaired.**
Defect record: Section 32.1.

---

## 9 — AwaitingStock Recovery (Part 7, Part 23) — **UNVERIFIED**

Not executed. Work stopped at the Part 6 STOP condition before the replenishment path was exercised. No
runtime evidence exists for stock arriving and a blocked order resuming the lifecycle, and none is claimed.

---

## 10 — Preparation Entry (Part 8) — PASS

Preparation was entered through the authoritative path `POST /api/preparation/waves/{id}/start` (HTTP 200).
No `Preparing` OrderStatus was fabricated — consistent with Batch A's finding that `OrderStatus` has no
`Preparing` case and that it is an invisible engine state. The order's reservation remained intact across the
transition and no second reservation was created.

---

## 11 — Inventory Semantics (Parts 9, 10) — PASS

Fixture: `on_hand = 10`, order quantity `6`.

```
AVAILABILITY: on_hand=10 reserved=6 available=4
START PREPARATION http=200
```

Asserted from the database after preparation started:

| Invariant | Result |
| --- | --- |
| `available = on_hand − reserved` → `10 − 6 = 4` | **PASS** |
| `on_hand` unchanged by preparation | **PASS** (10.0) |
| `reserved_qty` unchanged by preparation | **PASS** (6.0) |
| FIFO receipt layer `remaining_qty` unchanged | **PASS** |
| `inventory_layer_consumptions` count | **0 — PASS** |

Preparation consumes no physical inventory. The historical *"treat 10 as available"* failure mode is not
present: reservation is recorded as 6, not 0 and not 10.

---

## 12 — Wave (Part 11) — PASS

A wave was created through `POST /api/preparation/waves` referencing **two real persisted orders**.

```
preparation_waves.company_id   = <test company>      ✔
preparation_waves.warehouse_id = <test warehouse>    ✔
preparation_wave_orders        = exactly 2 rows, both real order ids  ✔
```

This is the first runtime evidence in the repository of a wave over real `Order` rows.

---

## 13 — Demand (Part 12) — PASS

Order A × 3 and Order B × 2 for the same product, demand generated via
`POST /api/preparation/waves/{id}/generate-demand`.

```
DEMAND: product=<id> required=5.0000   (3 + 2 expected 5)
```

Aggregated correctly, no duplication, connected to the real orders. **PASS.**

---

## 14 — Allocation (Part 14) — **UNVERIFIED**

Not executed; work stopped at the Part 6/24 STOP conditions. The route surface was mapped
(`POST /api/loading/sessions/{id}/start-allocation`, `/complete-allocation`, `AllocationController`,
`AutoAllocationService`, `AllocationDecisionChainService`, `AllocationRecord`), and Batch A's finding stands:
these classes have **no test references anywhere under `tests/`**. Remains UNVERIFIED — mandatory under
Part 32.

---

## 15 — Picking (Part 15) — **UNVERIFIED**

Not executed. `PreparationPickList` / `PreparationPickListItem` still have no test coverage. Note that wave
*item* completion (Section 16) is a different mechanism from pick-list execution and is not a substitute.
Remains UNVERIFIED — mandatory under Part 32.

---

## 16 — Partial Preparation (Part 16) — PASS

Required 10, prepared 6, then completed to 10, via
`PATCH /api/preparation/waves/{id}/items/{itemId}/complete`.

```
PARTIAL:   required=10.0000 prepared=6.0000  short=4.0000 status=short
COMPLETED: required=10.0000 prepared=10.0000 short=0.0000 status=prepared
```

The system did **not** silently mark 10/10 at the partial step; it recorded `prepared = 6`, `short = 4`, and
carried status `short` so the remainder stays actionable. The second call reached exactly 10 — no quantity
lost, none invented. Production terminology preserved (`quantity_short`, status `short` / `prepared`).
**PASS.**

---

## 17 — Completion (Part 17) — PASS

```
WAVE COMPLETE http=200
WAVE STATUS=completed
```

Completion was driven through `POST /api/preparation/waves/{id}/complete`; no status was set by hand.
Physical inventory remained untouched at completion (`on_hand = 50.0`), confirming consumption is not
performed by the Preparation stage.

---

## 18 — Loading (Part 18) — **PARTIAL PASS**

Loading-boundary enforcement has runtime evidence from `DistributionModuleTest` (**50/50 PASS**) and
`DeliveryModuleTest` (**35/35 PASS**), including
`test_orders_cannot_be_changed_once_past_loading`, `test_custody_cannot_be_added_after_loading`,
`test_delivery_cannot_be_executed_before_dispatch` and
`test_an_attempt_cannot_open_against_a_trip_still_in_planning`. This closes the INCOMPLETE status the previous
report carried.

**Not proven:** the specific sequence *incomplete preparation → attempt Loading → rejected*, driven from a
real prepared wave through `POST /api/loading/sessions/...`. Marked PARTIAL, not PASS.

---

## 19 — Shipment (Part 19) — PASS (carried, not re-executed end-to-end here)

`Rc10LifecycleCertificationTest::test_full_lifecycle_reaches_delivered_and_consumes_fifo` passes on this
worktree: FIFO layer 10 → 8 and `on_hand` 10 → 8 at dispatch, with consumption occurring at the
architecture-defined point and **not** at preparation or picking. Supported by
`InventoryLayerConsumptionTest` (9 tests) and `OperationsIntegrationFinalCertTest`.

**Not proven:** shipment driven from a *prepared and loaded wave* produced by this task's lifecycle. The
prepared → loaded → shipped junction remains unexercised.

---

## 20 — Reservation / Preparation Consistency (Part 20) — PARTIAL PASS

Across the stages this task did execute — Reserved → Wave → Demand → Preparation start → item completion →
wave completion — the quantities stayed coherent:

* no double reservation (reserved stayed 6.0 after preparation start),
* no orphan reservation,
* no negative reservation,
* no quantity disappearing (3 + 2 → demand 5),
* no quantity appearing from nowhere (6 then 10, never 14),
* `on_hand` and FIFO layers untouched until shipment.

Allocated / Picked / Loaded stages were not reached, so the full-chain comparison in Part 20 is not complete.

---

## 21 — Bypass Routes (Part 21) — **FAIL**

| Route / mechanism | Enforces reservation prerequisite? | Evidence |
| --- | --- | --- |
| `POST /api/fulfillment/orders/{id}/transition` (`MoveToPreparationWorkflow`) | **Yes** — guard requires `OrderStatus::InProgress`; terminal reservation states refused | RC-10 17/17 |
| `POST /api/preparation/waves` (`CreateWaveAction`) | **NO — BYPASS** | HTTP 201, `attached_rows=1` for an `awaiting_stock` order |
| `POST /api/preparation/waves/{id}/recalculate` (`RecalculateWaveAction`) | **Yes** (blocked order not attached) | `attached_blocked_rows=0` |
| `PreparationReleaseEngine` | **UNVERIFIED** — zero test references | Batch A finding, unchanged |
| `OrderPreparationObserver` | **UNVERIFIED** — zero test references | Batch A finding, unchanged |

The routes do **not** enforce the same business prerequisites. Recorded, not patched.

---

## 22 — Cancellation (Part 22) — **UNVERIFIED**

Not executed. `PreparationWaveActionsTest::test_cancel_wave_fires_event_and_writes_timeline` asserts only
event + timeline, and does not establish order restoration, reservation release, or absence of orphan
allocations. No behaviour is invented here.

---

## 23 — Company Isolation (Part 24) — **FAIL — PROVEN TENANT DEFECT**

An operator of Company A submitted an order id owned by Company B to `POST /api/preparation/waves`.

```
TENANT PROBE (foreign order -> wave):
  http=201  attached_rows=1
  stamped_company = 019fe8c2-4df3-…  (ACTOR's company)
  actor_company   = 019fe8c2-4df3-…
  owner_company   = 019fe8c2-4e06-…  (the order's real owner)
```

The foreign order was attached and the wave row was stamped with the **actor's** company, not the owner's.

**Caveat, stated honestly:** the foreign order in this fixture was assigned the test's warehouse, which is a
slightly artificial combination. It does not weaken the core finding — the attached order's `company_id` was
Company B's, and `PreparationWaveController::store()` loads orders by id with no company predicate — but a
follow-up should re-run with a fully Company-B-owned warehouse to remove all doubt.

Recipe-level company isolation remains **PASS** on this worktree (`part6`, `part7`, `part8`,
`part8_fail_closed`), as does warehouse and supplier isolation (Section 4).

---

## 24 — Cross-brand (Part 25) — PASS

`RecipeCrossBrandReuseTest` 3/3 and `RecipeGateTenantRepairTest::test_part20_cross_brand_reuse_survives_company_scoping`
pass on this worktree (Batch A, same session):

```
CROSS_BRAND: one raw material -> A=instock B=instock C=instock
```

The previously certified scenario remains green.

---

## 25 — Negative Stock (Part 26) — PASS (carried)

`RecipeGateTenantRepairTest::test_part19_multi_material_policy_matrix` — **6/6** policy combinations behaved
as specified; `test_part16_allow_negative_material_keeps_reservation_alive`;
`NegativeStockReservationTest` 5/5; `DirectIssueNegativeStockTest` 5/5. Preparation introduces no independent
negative-stock rule — the material policy remains the existing engine's. Semantics were not altered.

---

## 26 — RC-10 (Part 27) — PASS

`Rc10LifecycleCertificationTest` **17/17 green** on this worktree (executed in this session's Batch A). No
regression; no RC-10 test was weakened or modified.

---

## 27 — Phase 3 (Part 28) — PASS (now complete)

The two suites the previous report was unable to execute were executed here and both pass:

* `WarehouseTenantIsolationTest` — **13/13**
* `SupplierTenantIsolationTest` — **5/5**

Together with `AvailabilityStateDerivationTest` (8), `ProductPopulationScopeTest` (6),
`ProductStockStatusWritePathTest` (7), `OrderTenantScopeTest` (4), `V3TransitionResolutionTest` (5) and
RC-10 (17) from Batch A, **the Phase 3 set is complete and green**. Nothing was silently omitted.

---

## 28 — PHPStan (Part 29) — PASS

From this session, against this worktree, run **cold with the result cache cleared** to make it authoritative:

| Config | Level | Result | Exit |
| --- | --- | --- | --- |
| `phpstan.neon.dist` | 0, `Modules` + `app` | `[OK] No errors` | 0 |
| `phpstan-core.neon.dist` | 6, `app/Core` + `Contracts` + `Traits` | `[OK] No errors` | 0 |

Cold duration 204.4 s. Both are ratcheted against baselines, so this means **no NEW violations**. No
suppression was added. Frontend was not touched, so TypeScript/frontend tests were out of scope.

---

## 29 — Guardian (Part 29) — FAIL (pre-existing, proven by control)

`guardian.sh pre-push`, 8 validators, **326 s, exit 1**: PHP Syntax PASS, Composer SKIP, Laravel Bootstrap
PASS, **Pint FAIL**, PHPStan PASS, ESLint PASS, TypeScript PASS, Vite Build PASS.

Pint reports NEW violations in exactly two files over range `f0d7822a...HEAD`:

```
tests/Feature/Inventory/ProductPopulationScopeTest.php    fixers: ordered_imports
tests/Feature/Operations/V3TransitionResolutionTest.php   fixers: binary_operator_spaces
baseline files: 628 | violating in scope: 4 | fixed since baseline: 1
```

Both files were changed by commit `6149875b` **itself**; neither appears in the worktree diff. Guardian was
therefore already red at the starting commit. `--no-verify` was not used, nothing was suppressed, and no
unrelated file was modified to make Guardian green. **No Pint fix was run.**

---

## 30 — Failure Classification (Part 30)

Classification used a **real parent-commit control**: HEAD `6149875b` extracted with `git archive` into a
separate directory with its own real `vendor` copy. An earlier attempt used a junctioned `vendor` whose
autoloader resolved back to the worktree; a probe caught it before any conclusion was drawn and it was
rebuilt (`HAS OPTION B: NO` after the fix).

### 30.1 — NEW (2) — green at HEAD, red on the worktree

| Test | HEAD | Worktree |
| --- | --- | --- |
| `OrderManufacturingIntegrationTest::test_failed_line_preserves_failure_reason_in_result` | **✔ PASS** | ✘ `Failed asserting that null is of type array` (line 427) |
| `OrderManufacturingIntegrationTest::test_manufacturing_started_at_and_completed_at_are_set` | **✔ PASS** | ✘ `Failed asserting that null is not null` (line 534) |

**This reverses the previous certification's `NEW (0)`.** That report's Batch A never executed
`tests/Feature/Orders`; the finding was scope-limited, not wrong within its scope.

### 30.2 — Behaviour changed (8) — red at HEAD *and* on the worktree, but with a different failure mode

| Test | HEAD | Worktree |
| --- | --- | --- |
| `preparing_triggers_manufacturing_for_eligible_line` | `ReadyForDispatch ≠ InProgress` | **`AwaitingStock` ≠ InProgress** |
| `preparing_twice_does_not_duplicate_manufacturing` | `Skipped ≠ Executed` | **`WorkflowPreconditionException … Current: [awaiting_stock]`** |
| `preparing_twice_preserves_executed_state_on_line` | `Skipped ≠ Executed` | **same exception** |
| `mixed_order_only_manufactures_eligible_lines` | `Skipped ≠ Executed` | `null ≠ object` |
| `failed_manufacturing_marks_line_as_failed` | `Skipped ≠ Failed` | `null ≠ object` |
| `manufacturing_result_is_stored_on_line` | `'manufacturing_triggered'` vs `'status_ignored'` | `null is of type array` |
| `retry_after_failure_re_evaluates_failed_line` | `Skipped ≠ Failed` | `null ≠ object` |
| `retry_does_not_re_execute_executed_lines` | `Skipped ≠ Executed` | `null ≠ object` |

The `awaiting_stock` signature is Option B's.

### 30.3 — PRE-EXISTING (3) — identical failure at HEAD (differing only by generated UUIDs)

`preparing_sets_order_status_to_preparing`, `product_with_sufficient_fg_stock_is_marked_not_required`,
`rc10_order_line_id_is_populated_on_manufacturing_transaction`.

### 30.4 — ENVIRONMENT (1)

Concurrent access to `ecos_erp_test` by a second certification agent (Section 3.1), which destroyed several
runs in both sessions. Not a product defect.

### 30.5 — Mechanism of 30.1 / 30.2 — stated at its true strength

`ProductFactory` sets `brand_id => Brand::factory()` and derives `company_id` from that brand, so
`makeOutput()`'s finished good belongs to a **factory-generated company**, while `seedInventory()` stamps the
component inventory with the test's **`$this->company`**. Under F4's company scoping those two companies do
not match, so no component inventory is visible → recipe `outofstock` → Option B withholds the manufacturing
commitment → the order diverts to `awaiting_stock` and manufacturing never runs, leaving
`manufacturing_result` and `manufacturing_started_at` null.

**This is most likely an incoherent pre-existing fixture exposed by F4, not a production defect** — under
ADR-013 / ADR-027 §16.4 component inventory *should* belong to the finished good's company, and the newer
suites deliberately assert a coherent chain (`test_fixture_ownership_chain_is_single_tenant`).

**But that is not proven, and it matters.** If production data can hold inventory whose `company_id` differs
from the product's brand company, F4 will silently render those recipes unexecutable and divert live orders
to Awaiting Stock. Deciding this is an architectural call, not a certification call (Part 33). What is
certain is that **F4 + Option B landed without reconciling `OrderManufacturingIntegrationTest`.**

---

## 31 — Evidence Matrix (Part 31)

| # | Scenario | Result | Runtime Evidence | DB Evidence |
| --- | --- | --- | --- | --- |
| 1 | Real Order Reservation | **PASS** | Real HTTP transition on two persisted orders | `reservation_status=Reserved`; `inventory_items.reserved_qty=5.0` |
| 2 | Recipe Unavailable | **PASS** (carried) | `part15`, E2E `test_b`/`test_e` | `reserved_qty=0`; 0 ledger rows; 0 FIFO consumptions |
| 3 | AwaitingStock Protection | **FAIL** | `POST /api/preparation/waves` → **201** | `preparation_wave_orders` = **1 row** for an `awaiting_stock` order |
| 4 | AwaitingStock Recovery | **UNVERIFIED** | not executed | — |
| 5 | allow_negative_stock ON | **PASS** (carried) | `part19` matrix 6/6, `part16` | reservation persisted, order `ready_for_dispatch` |
| 6 | on_hand − reserved | **PASS** | real reservation then read-back | `on_hand=10 reserved=6 available=4` |
| 7 | Preparation no consumption | **PASS** | `waves/{id}/start` → 200 | `on_hand` 10 unchanged; FIFO layer unchanged; `inventory_layer_consumptions=0` |
| 8 | Real Wave | **PASS** | `POST /api/preparation/waves` → 201 | 2 real order ids attached; correct company + warehouse |
| 9 | Demand | **PASS** | `generate-demand` → 200 | `quantity_required=5.0000` from 3 + 2 |
| 10 | Allocation | **UNVERIFIED** | not executed | — |
| 11 | Picking | **UNVERIFIED** | not executed | — |
| 12 | Partial Preparation | **PASS** | two `items/{id}/complete` calls | `6 / short 4 / status=short` → `10 / short 0 / status=prepared` |
| 13 | Preparation Completion | **PASS** | `waves/{id}/complete` → 200 | wave `status=completed`; `on_hand` untouched |
| 14 | Loading Boundary | **PARTIAL** | Distribution 50/50, Delivery 35/35 | immutability-past-loading + pre-dispatch refusal asserted |
| 15 | Shipment Consumption | **PASS** (carried) | RC-10 full lifecycle | FIFO 10→8; `on_hand` 10→8 at dispatch only |
| 16 | Company Isolation | **FAIL** (wave entry) / PASS elsewhere | foreign order → wave **201** | attached row stamped with **actor's** company |
| 17 | Cross-brand RM | **PASS** | `RecipeCrossBrandReuseTest` 3/3 + `part20` | `A=instock B=instock C=instock` |
| 18 | Reservation/Preparation consistency | **PARTIAL PASS** | stages executed only | no double/orphan/negative reservation; no quantity drift |
| 19 | Bypass Routes | **FAIL** | wave-create bypass proven; recalculate safe | routes disagree (Section 21) |
| 20 | Wave Cancellation | **UNVERIFIED** | not executed | — |
| 21 | Full Order → Shipment | **INCOMPLETE** | Order→Reservation→Wave→Demand→Preparation→Completion proven; prepared→loaded→shipped junction not | — |

New tests executed: **7 tests, 56 assertions, 5 pass, 2 fail (both failures are the proven defects), 418 s.**

---

## 32 — Remaining Gaps and Defect Records

### 32.1 — DEFECT 1 — Preparation Wave entry does not enforce the Reservation gate

* **Reproduction:** `PreparationBypassGuardTest::test_an_awaiting_stock_order_must_not_be_attached_to_a_preparation_wave`.
* **Path:** `POST /api/preparation/waves` → `PreparationWaveController::store()` → `CreateWaveAction`.
* **Condition:** `guardOrdersReservable()` — despite the name — checks only whether the order is already in an
  active wave (`whereNotIn('pw.status', ['completed','cancelled'])`). It performs no order-status or
  reservation-status check. `CreateWaveRequest` validates `order_ids.*` as `uuid` only: no `exists:orders,id`,
  no status rule.
* **Expected:** an order in `awaiting_stock` with `reserved_qty = 0` is refused.
* **Actual:** HTTP 201; one `preparation_wave_orders` row created.
* **Business impact:** orders with no inventory commitment enter preparation planning. Demand, wave KPIs and
  operator queues are computed over stock that was never reserved, and the Reservation gate — the system's
  first warehouse control — is circumventable through a second, equally legitimate UI path.
* **Likely architectural location:** `PreparationWaveController::guardOrdersReservable()` and/or
  `CreateWaveRequest`. Note `RecalculateWaveAction` already refuses the same order, so the correct behaviour
  exists in the codebase and the two routes are inconsistent.

### 32.2 — DEFECT 2 — Preparation Wave entry does not enforce the company boundary

* **Reproduction:** `PreparationBypassGuardTest::test_an_order_from_another_company_must_not_be_attached_to_a_wave`.
* **Path:** same as 32.1. `store()` loads rows via `DB::table('orders')->whereIn('id', $orderIds)` with **no
  company predicate**, then writes `preparation_wave_orders` stamped with `$dto->companyId` (the actor's).
* **Expected:** refusal.
* **Actual:** HTTP 201; the foreign order attached and re-stamped with the actor's company.
* **Business impact:** a cross-tenant read of order data (order number, customer name, delivery zone, shipping
  cost are copied into the wave snapshot) plus cross-tenant write of wave membership. This is the exact class
  of defect UAT Campaign 1 flagged as a P0 multi-company scoping issue.
* **Caveat:** see the fixture caveat in Section 23. Re-run with a fully Company-B-owned warehouse to close all
  doubt before sizing the fix.

### 32.3 — Remaining UNVERIFIED (mandatory under Part 32)

Allocation, Picking, AwaitingStock Recovery, Wave Cancellation. Plus PARTIAL: Loading boundary from a real
prepared wave, and the prepared → loaded → shipped junction.

---

## 33 — Certification Verdict

Part 32 permits certification only when every mandatory runtime scenario has evidence. Four mandatory
scenarios have **no** evidence (Allocation, Picking, AwaitingStock Recovery, Wave Cancellation), the full
Order → Shipment chain is INCOMPLETE, and — decisively — two mandatory scenarios are **refuted by executed
runtime evidence**: AwaitingStock protection and company isolation at the Preparation entry point.

A NEW regression (2 tests, green at HEAD, red on the worktree) is additionally unresolved.

# PREPARATION BACKEND = NOT CERTIFIED

---

## 34 — Recommendations

1. **Repair task for DEFECT 1** — make `POST /api/preparation/waves` enforce the same prerequisite
   `RecalculateWaveAction` already enforces. The name `guardOrdersReservable` should either become true or
   change. Do not weaken `RecalculateWaveAction` to match.
2. **Repair task for DEFECT 2** — scope the `orders` lookup in `store()` by `company_id`, and add
   `exists:orders,id` to `CreateWaveRequest`. Re-run the tenant probe with a Company-B warehouse first.
3. **Reconcile F4 with `OrderManufacturingIntegrationTest`** — decide whether its fixtures must establish a
   coherent ownership chain (most likely) or whether F4 over-reaches. Then answer the open question in
   Section 30.5: **can production hold inventory whose `company_id` differs from the product's brand
   company?** If yes, F4 is a live functional risk, not a fixture problem.
4. **Close the four UNVERIFIED scenarios** — Allocation and Picking have no tests at all today; they cannot be
   certified by inspection and are on the go-live critical path.
5. **Serialize certification runs** — one agent, one test database. Concurrent runs corrupted several
   executions across two sessions and cost more time than the tests themselves.
6. **Raise the host CLI `memory_limit`** — the default 128M cannot run these suites (`-d memory_limit=2G` was
   required).

---

## 35 — Attestations

* Certification executed against the **CURRENT WORKTREE**, which differs from HEAD `6149875b`.
* F4, Option B, Recipe ownership rules and `allow_negative_stock` semantics were **not** reopened or modified.
* **No production code was modified.** Only two new test files were added.
* No commit; no reset, checkout, stash, clean or discard; no `--no-verify`; no suppression.
* **No discovered defect was repaired** — both are recorded for separate repair tasks (Part 34 rule:
  prove first, repair second).
* `ecos_erp` never modified — 551 tables, 2 orders, verified before and after.
* Every reported result comes from a run that completed cleanly; contaminated runs were discarded, not
  reported.

# TASK-OPERATIONAL-FULFILLMENT-GATE-VALIDATION-001 — Validation Report

**Mode:** READ-ONLY architectural + implementation validation. No source, migration, DB, config, or business data changed. No deploy/commit/push. Verification limited to the isolated `ecos_dev_test` DB. No browser observation performed (nothing marked Browser Verified).
**Date:** 2026-08-28

---

## 1. Executive Summary

After separating **architecture/code** from **configuration** and **demo data**, the Operational Fulfillment chain is **largely already correct in code**; the genuinely-remaining engineering work is concentrated in **one place — Gate 4 (Returns)** — plus a set of **deploy/commit/governance** actions on work that is already built and tested, and a handful of **decision-gated** small hardenings.

- **Gate 1 (MTO → Warehouse → Vehicle):** architecture and code are **correct and tested** (130 tests + a negative control prove exact-quantity manufacturing and the canonical transfer). The only gaps are **not code**: the quantity-accuracy fix is **uncommitted and not deployed to `ecos-dev-app`**, and **ORD-00014 is demo data** (a pre-fix, phantom-custody scenario), not proof of a defect.
- **Gate 2 (Wave → Group):** the sweep, zone/template rules, and exception surfacing are **canonical and present**. Zone 9/Obour being uncovered is a **configuration + business-decision** matter, **not a code bug**. Two real code items exist but are **gated on a business decision** (P1 duplicate-empty-shell from NULL-wave manual groups; planning-window fallback divergence).
- **Gate 3 (Driver Delivery Quantity):** the canonical single-writer + D2/lazy-D1 bridge is **already built, wired, and green (14 tests)** — but **uncommitted** (governance) with open **business decisions** (over-delivery, shortage split, remainder, order-status advancement). Secure POD is **implemented in code**; UI reachability is **not browser-verified**.
- **Gate 4 (Returns):** the **delivered leg is canonical and tested**; the **returned leg is a real ARCHITECTURE GAP** — `VehicleInventoryService::recordReturn()` is dead code, operational returns never restock the warehouse (contra ADR-015 §11), `order_lines.returned_qty` has no writer, reconciliation never finalizes, and **four competing return models** coexist. This requires a **business decision then implementation**.

**The single most important lifecycle break:** a canonical return movement (`recordReturn`) **exists but is never called** — the loop is open at Returns.

---

## 2. Data Authority Rule

DEV `ecos_dev` holds **demo business data** and is **not** authoritative for business-rule correctness. Every DEV anomaly below is classified as DEMO DATA / CONFIG unless the **code itself** independently proves a defect. Evidence hierarchy used: (1) ADR/architecture contract → (2) canonical-service ownership in code → (3) implementation → (4) automated tests (isolated DB) → (5) configuration → (6) demo data → (7) browser (none this pass). Demo data was **never** used to override architecture. Note: `docs/CLAUDE.md` names PostgreSQL, but the actual persistence is **MySQL 8.4** (all inspection queries ran against MySQL) — a documentation drift, not a defect.

Classifications used: **CODE BUG · ARCHITECTURE GAP · CONFIG/SETUP GAP · DEMO DATA · BUSINESS DECISION · ALREADY CORRECT.**

---

## 3. Gate 1 — MTO Manufacturing → Warehouse → Vehicle

| # | Check | Finding (code evidence) | Class |
|---|---|---|---|
| A | MTO trigger present | `PrepareOrderManufacturingAction` → `OrderLifecycleCoordinator` → `ManufacturingLifecycleHandler` → `ManufacturingApplicationService` → `ManufacturingExecutor` | ✅ ALREADY CORRECT |
| B | Wave path invokes canonical mfg | `HandlePreparationWaveStarted` / `HandlePreparationWavePreparationStarted` call `PrepareOrderManufacturingAction` (trigger-gap fix, present in worktree **and** `ecos-dev-app`) | ✅ ALREADY CORRECT |
| C | Order FSM V3 statuses | `ManufacturingLifecycleHandler` **and** `ManufacturingPolicy::MANUFACTURING_ALLOWED_STATUSES` both `[in_progress, confirmed, ready_for_dispatch]`; Rule 3 (`can_manufacture`) removed (ADR-027 v1.5) | ✅ ALREADY CORRECT |
| D | Quantity-accuracy fix exists + tested | `InventoryAvailabilityEngine` clamps free FG at zero (`max(0, on_hand−reserved)`); 14 new tests + negative control (TASK-MTO-PRODUCTION-QUANTITY-ACCURACY-FIX-001). **Uncommitted; NOT in `ecos-dev-app`** | ✅ CORRECT (code) / 🟠 CONFIG (undeployed) |
| E | Produces exactly required qty | Proven: full run `OK(130)`; negative control on the buggy engine failed the exact 7 negative-availability tests (16/2/4/6). With fix → exact | ✅ ALREADY CORRECT (worktree) |
| F | Production output → canonical warehouse | `InventoryMutationAdapter::produceFinishedGoods` → `on_hand += qty`, `production_output` ledger, FIFO layer, `manufacturing_transactions` | ✅ ALREADY CORRECT |
| G | Warehouse→Vehicle canonical movement | `TransferLoadedStockToVehicleAction` → `ShipStockAction` (writes `stock_ledger_entries`); idempotent on `(vehicle_custody_transfer, loading_task_id)` | ✅ ALREADY CORRECT |
| H | `allow_negative_stock` rule | `ShipStockAction` honors the product flag (parity with `ReserveStockAction`/`DirectIssueStockAction`, ADR-027 P07) | ✅ ALREADY CORRECT |
| I | Custody credited exactly once | `VehicleInventoryService::recordLoad` delta-based; transfer idempotent | ✅ ALREADY CORRECT (tested) |
| J | Warehouse/vehicle reconcilable | Transfer delta == loaded qty; warehouse ↓ == custody ↑ (tested `DriverLoadingCustodyHandoffTest`, `WaveDrivenManufacturingTriggerTest`) | ✅ ALREADY CORRECT (delivered leg) |
| K | Idempotency + rollback | Executor `plan_id` UNIQUE + single `DB::transaction`; idempotency/rollback tests green | ✅ ALREADY CORRECT |

**ORD-00014 → DEMO DATA (see §10).** It predates the fixes (never manufactured; 0 mfg transactions; FG ledger only reservation/release), and its phantom custody (1 unit each on the vehicle without production) is a pre-bridge artifact — **not an independent code defect**. The code path is proven correct by tests; the demo row simply reflects the old world.

**Gate 1 real work:** deploy the (built, tested) quantity fix to `ecos-dev-app` and commit the uncommitted MTO fixes. **No new code.**

---

## 4. Gate 2 — Distribution Wave → Group

| # | Check | Finding | Class |
|---|---|---|---|
| A | Wave scheduler transitions | Wave lifecycle drives `WaveStarted`/`WavePreparationStarted` | ✅ ALREADY CORRECT |
| B | WaveStarted triggers sweep | `StartWaveDistributionGroupsListener` bound to both events (`LogisticsDistributionServiceProvider:54-55`) → `DailyGroupLifecycleService::sweepWave()` | ✅ ALREADY CORRECT |
| C | Sweep identifies eligible ungrouped | Yes — but **template-driven**: only zones attached to a template are grouped; uncovered-zone work is collected as `uncovered_zones` | ✅ CORRECT (by design) |
| D | Correct planning window | `resolvePlanningWindow()` is wave-anchored (canonical), but its three fallbacks delegate to calendar `currentWindow()` **without status-sync** | 🟡 CODE (minor) + BUSINESS DECISION (canonicalisation) |
| E | Zone/template rules canonical | `GroupTemplateService::zoneOwnership()` (one zone → ≤1 template/company; excludes soft-deleted) | ✅ ALREADY CORRECT |
| F | Manual vs wave groups distinguishable | Manual groups: `preparation_wave_id = NULL`, `template_id = NULL` (`storeSlot`) → distinguishable, but invisible to the sweep's `findGroup` | 🟡 → feeds P1 |
| G | Sweep can create duplicates | **Yes (P1):** `findGroup($waveId,$templateId)` cannot match a NULL/NULL manual group → sweep creates a duplicate template-group and `assignZoneToSlot` re-points the unique `(window,warehouse,zone)` row, silently emptying the operator group | 🔴 CODE BUG (gated on the provenance BUSINESS DECISION) |
| H | Unserved zones as explicit exception | Surfaced: `ordersAwaitingGroup` → `zone_not_in_group` + FE Exceptions red count (**not silent**); but the reason is **ambiguous** (config-gap vs transient); precise `uncovered_zones` is log-only | 🟡 CONFIG + optional enhancement |
| I | Window resolution consistent | See D — the wave-anchored vs calendar fallback can resolve different windows across read/sweep paths | 🟡 CODE + DECISION |
| J | Dashboard KPIs source | Derived from the canonical read model `DistributionAggregationService::slotSummaries()` (single source), not a parallel tally | ✅ ALREADY CORRECT |

**Zone 9 / Obour classification:** **CONFIGURATION/TEMPLATE GAP + BUSINESS DECISION** — the zone is `is_active=1` but attached to no template (also zones 3/Giza, 10/Shrouk). `distribution_zones` has **no "served" concept**, and zone 3 has live precedent of operators compensating with a manual group (DG-004). **Not a code bug.** Whether zone 9 should be served is an owner decision. (Not added to any template — read-only.)

**Gate 2 real work (all decision-gated):** P1 fix (code, gated on the manual-group-provenance decision), window fallback status-sync + canonicalisation (code + decision), optional exception-reason disambiguation. Zone coverage itself is config/business.

---

## 5. Gate 3 — Driver Delivery Quantity

| # | Check | Finding | Class |
|---|---|---|---|
| A | Single canonical delivered-qty writer | `order_lines.delivered_qty` written **only** by `ProjectDeliveredQuantityFromAllocation:45` (= SUM of `allocation_records.quantity_delivered`); allocation qty written only by `RecordProductDeliveryAction:103` | ✅ ALREADY CORRECT |
| B | Driver path reaches it | `DriverRuntimeController::deliver` → `EnsureStopDeliveryAllocationsAction` → `RecordProductDeliveryAction` → event → projection | ✅ CORRECT (uncommitted) |
| C | D2 + lazy-D1 bridge exists | Yes — all files present but **untracked (`??`)**; route `POST /api/driver/stops/{id}/deliver` (`api.php:3218`) | ✅ CORRECT / 🟡 GOVERNANCE |
| D | Creates/reuses allocation records | `EnsureStop` creates one record per in-custody order line, idempotent on the `(vehicle_assignment_id, order_line_id)` unique key; coexists with `AutoAllocationService` | ✅ ALREADY CORRECT |
| E | Full delivery | Tested | ✅ |
| F | Partial delivery | `PartialDelivery` status + `quantity_remaining`; tested | ✅ |
| G | Cumulative/absolute (not delta) | Absolute set under `lockForUpdate` | ✅ ALREADY CORRECT |
| H | Over-delivery fail-closed | Refused vs `quantity_allocated` (explicitly "no approved over-delivery contract", ADR-015) | ✅ CORRECT / ⚠ BUSINESS DECISION (contract) |
| I | Custody movement delta-based | `VehicleInventoryService::recordDelivery` receives the delta only | ✅ ALREADY CORRECT |
| J | Bridge idempotent | Absolute set + lock + unique key; replay = no-op; tested | ✅ ALREADY CORRECT |
| K | Delivered qty projected correctly | Projection sums across split vehicles; tested | ✅ ALREADY CORRECT |
| L | Order statuses protected | `Order.php:144` throws on any direct `update(['status'=>…])` outside the FulfillmentEngine; the bridge writes only `DeliveryStop.status` | ✅ ALREADY CORRECT |
| M | POD secure | `downloadDeliveryProof` streams **only** a server-recorded path from the private disk (never client-supplied), fail-closed via `ownedStop()`; `uploadDeliveryProof` requires real evidence + validates types (TASK-DELIVERY-POD-SECURE-UPLOAD-001) | ✅ ALREADY CORRECT (code) |
| N | Secure POD reachable from driver UI | Route + controller present; **UI reachability NOT observed in a browser** this pass | ⚪ NOT BROWSER-VERIFIED |

**Separation:** implementation **present** ✅; implementation **tested** ✅ (14 tests, see §9); **browser verified** ❌ (not observed); **business rules undecided:** over-delivery contract, shortage partitioning across competing lines (D3 territory), remainder lifecycle (ties to Gate 4), whether full delivery should advance `Order.status` (today `DeliveryStopCompleted` has no observed listener).

**Gate 3 real work:** commit the bridge (governance) + resolve the four business decisions. **No new mechanism needed.**

---

## 6. Gate 4 — Returns + Final Reconciliation

| # | Check | Finding | Class |
|---|---|---|---|
| A | Canonical return authority | **None single** — four competing: custody `quantity_returned` (dead), shift `quantity_returned_actual` (variance only), `TripReturn`/`DeliveryReturn` (record only), `CustomerReturn` (the only restock, a disconnected order-level RMA) | 🔴 ARCHITECTURE GAP + BUSINESS DECISION |
| B | Driver return: declaration or movement | **Declaration only** — no inventory movement | 🔴 ARCHITECTURE GAP |
| C | `recordReturn` executes | **NO — dead code**, zero callers (stated in-code at `DriverDaySettlementReadService.php:496-498`) | 🔴 CODE/ARCH GAP (canonical service never called) |
| D | `returned_qty` populated | `order_lines.returned_qty` has **no writer**; custody `quantity_returned` only via dead `recordReturn`; shift `quantity_returned_actual` is operator-count only | 🔴 ARCHITECTURE GAP |
| E | Returned stock returns to warehouse | Only via `CustomerReturn`→`ReceiveReturnWorkflow`→`AdjustmentInAction` (RMA silo); the operational driver/trip/shift flow **never restocks** — contra **ADR-015 §11** ("return inventory … rejoining warehouse stock" after inspection) | 🔴 ARCHITECTURE GAP (stock leak) |
| F | Custody reconcile loaded−delivered−returned | Variance computed (`VehicleShiftReconciliationService`, ADR-015 §6.4) but the `returned` input is never fed by a real movement → cannot close | 🔴 ARCHITECTURE GAP |
| G | Shortages/damages represented | `TripReturn`/`DeliveryReturn` carry discrepancy fields — recorded, not posted | 🟡 PARTIAL |
| H | Driver liability represented | Present as fields on `TripReturn`/`DeliveryReturn` | 🟡 PARTIAL (not posted) |
| I | Warehouse count represented | `VehicleShiftReconciliationLine.quantity_returned_actual` (operator count) — no restock | 🟡 PARTIAL |
| J | One authority or multiple | **Multiple competing** (see A) | 🔴 ARCHITECTURE GAP |
| K | Conflicts with ADR-015 | Not a *conflict* — an **incompleteness**: ADR-015 §6.4 defines `variance = loaded − delivered − returned` and §11 requires returns to rejoin warehouse after inspection; the code computes the formula but leaves the `returned` leg unwired | 🔴 ARCHITECTURE GAP vs ADR-015 |

**Scenario-8 detail:** driver `deliver()` guards delivered ≤ custody `on_hand`; the **operator** path (`RecordProductDeliveryAction`/`AllocationController`) guards only vs `allocated`, and `recordDelivery`'s `max(0,…)` clamp silently masks over-draw. → small CODE hardening candidate (decision-light).

**Gate 4 real work:** a **BUSINESS DECISION** (which of the four models is authoritative; does the operational return restock the warehouse and via which path), **then** implementation of a single canonical return movement (driver declaration → warehouse inspection/receipt → `AdjustmentIn` + populate `returned_qty` + feed the ADR-015 §6.4 variance). **This is the principal remaining engineering work.**

---

## 7. Cross-Gate Lifecycle

Chain: **Preparation → Manufacturing → Warehouse → Group → Vehicle → Driver → Delivery → Return → Reconciliation.**

- **Preparation → Manufacturing → Warehouse:** ✅ wired and canonical (Gate 1).
- **Warehouse → Vehicle:** ✅ canonical transfer at driver Confirm-Received (Gate 1 G).
- **Group → Vehicle:** ✅ sweep + allocation, with the Gate 2 template-coverage caveat (config/decision).
- **Vehicle → Driver → Delivery:** ✅ delivered-quantity bridge (Gate 3), uncommitted.
- **Delivery → Return → Reconciliation:** 🔴 **BREAK HERE.**

**Lifecycle breaks — a canonical service exists but is never called:**
1. `VehicleInventoryService::recordReturn()` — **exists, never called** (the return leg is architecturally present but unwired). **The defining break.**
2. MTO quantity-accuracy fix — **exists in worktree, not deployed to `ecos-dev-app`** (the running DEV env would still over-produce).
3. Driver delivery bridge (Gate 3) — **exists, uncommitted** (active in worktree, absent from the committed baseline).

---

## 8. Canonical Service Ownership

| Concern | Canonical owner | Status |
|---|---|---|
| Reservation | Orders (decision) + `ReserveStockAction` (execute) | ✅ |
| MTO manufacture | `PrepareOrderManufacturingAction` → … → `ManufacturingExecutor` | ✅ |
| Availability/qty | `InventoryAvailabilityEngine` (single qty source) | ✅ (fix uncommitted/undeployed) |
| Production → warehouse | `InventoryMutationAdapter::produceFinishedGoods` | ✅ |
| Warehouse → vehicle | `TransferLoadedStockToVehicleAction` → `ShipStockAction` | ✅ |
| Vehicle custody | `VehicleInventoryService` (recordLoad/allocate/recordDelivery; **recordReturn dead**) | 🟡 |
| Distribution grouping | `DailyGroupLifecycleService` / `GroupTemplateService` | ✅ (P1 + window caveats) |
| Delivered quantity | `RecordProductDeliveryAction` → `ProjectDeliveredQuantityFromAllocation` (single writer) | ✅ (uncommitted) |
| Order status | FulfillmentEngine only (guarded `Order.php:144`) | ✅ |
| **Returns** | **NONE — 4 competing** | 🔴 |
| Warehouse-in restock | `AdjustmentInAction` (used only by `CustomerReturn` RMA) | 🟡 disconnected |

---

## 9. Automated Verification

All runs executed **this session** against the isolated `ecos_dev_test` DB via `scripts/test-gate.sh` (gate serialised; no contention errors; nothing discarded). Current working-tree source was synced into the testrunner before each run.

| Suite(s) | Tests | Assertions | Result | Proves |
|---|---|---|---|---|
| MTO baseline (`InventoryAvailabilityEngineTest` + `WaveDrivenManufacturingTriggerTest`) | 24 | 126 | ✅ OK | pre-fix green; engine + wave path |
| MTO full (9 suites incl. 2 new) | 130 | 487 | ✅ OK | exact-qty manufacture; FG/RM/ledger; idempotency; non-MTO skip; no regression |
| MTO negative control (buggy engine, new suites) | 14 | 35 | 7 FAIL (as designed) | tests genuinely catch the bug (16/2/4/6 over-production) |
| Gate 3+4 built mechanisms (`DriverStopDeliveryTest` 14 + `VehicleShiftReconciliationTest` + `RecordProductDeliveryOrderLineProjectionTest` + `RecordProductDeliveryHttpTest` + `DriverLoadingCustodyHandoffTest`) | 66 | 509 | ✅ OK | delivery bridge (full/partial/idempotent/over-delivery-refused/warehouse-neutral); custody load/deliver; variance formula |

No failing suites observed in any run → no regression/pre-existing/stale-fixture classification needed for what was run. **Not executed this pass:** distribution suites (e.g. `DistributionModuleTest`, which per prior baseline carries ~38 pre-existing fixture-403s unrelated to this validation) — Gate 2 conclusions rest on code evidence, not those tests. The **returned leg has no closed path to test** (its absence is the finding).

---

## 10. Demo Data Findings — NOT System Defects

| Entity | Observed state (`ecos_dev`, read-only) | Why NOT sufficient evidence | What would validate it properly |
|---|---|---|---|
| **ORD-00014** | `ready_for_dispatch`, both lines reserved, **0 mfg transactions**, `mfg_started_at` on L1 set today with NULL state | Predates the trigger/quantity fixes; the code path is proven correct by tests. The NULL-state limbo traces to an unrelated **hourly failing job** (below), not order logic | Run the canonical path on a **fresh** order in the isolated DB with fixes deployed |
| **Phantom custody (ORD-00014)** | 1 ECOS-FG + 1 Honey in `vehicle_inventory_items`, warehouse on_hand 0, no production/transfer ledger | Created by the **pre-bridge** `recordLoad` (before `TransferLoadedStockToVehicleAction` existed); artifact of old data, not current code | A fresh load→transfer cycle shows the bridge posts the ledger correctly (tested) |
| **FG reserved pools** | ECOS-FG reserved **15**, Honey reserved **6** (aggregate across orders) | Demo accumulation; the fixed engine produces only each order's own qty (tested) | N/A — code correctness proven by tests |
| **Zone 9 / Obour (+ 3, 10)** | `is_active=1`, attached to no template; ORD-00007 stranded with `virtual_slot_id=NULL` | "Uncovered by a template" ≠ "not served"; no "served" concept exists; zone 3 shows operators compensate manually | An owner ruling on served zones + template config |
| **DG-004 / DG-005 (manual groups)** | `preparation_wave_id = NULL` (or resolved-at-create) | Manual-group provenance is an **undecided model**, not a code defect per se; drives P1 only under a specific sweep sequence | Owner decision on whether manual groups stamp a wave |
| **Hourly `failed_jobs`** | `DecryptException: MAC is invalid` at 03:00–06:00 today | An environment/queue-payload (APP_KEY/encryption) issue, unrelated to fulfillment logic | Ops investigation of the scheduled job + APP_KEY |

*(Vehicle 1336 / Driver 396 were not independently re-observed this pass; not asserted.)*

---

## 11. Configuration Findings

- **MTO quantity fix not deployed to `ecos-dev-app`** (present only in the worktree/testrunner). While buggy code is live, a direct/scheduled manufacture of a reserved MTO order would over-produce. **Deployment gap, not a code gap.**
- **Zone→template coverage:** zones 9/3/10 have no template row. **Config/business gap.**
- **Uncommitted work:** the entire driver-delivery bridge, the distribution module changes, and the MTO fixes are untracked/modified in the worktree — a **governance/release** gap, not a correctness gap (tests green).
- **Docs drift:** `docs/CLAUDE.md` says PostgreSQL; actual is MySQL 8.4.

---

## 12. Business Decisions Required

1. **Gate 4 (highest priority):** which return model is authoritative, and does an operational return restock the warehouse (wire `recordReturn`/shift/`TripReturn` to `AdjustmentIn` + inspection per ADR-015 §11, vs. raise a `CustomerReturn`)? Until decided → open loop + stock leak.
2. **Gate 4:** reconciliation lifecycle — is a non-zero variance actionable (block settlement / assign liability / post shrinkage), or informational only? No complete/approve/dispute writer exists.
3. **Gate 2:** is zone 9 (and 3, 10) commercially served? If yes → template config; if no → ship the explicit "zone not served" state.
4. **Gate 2:** manual-group provenance — should `storeSlot`/apply stamp `preparation_wave_id`? (Gates the P1 fix and NULL-wave group rotation.)
5. **Gate 3:** over-delivery contract (currently fails closed); shortage partitioning policy for a short product across competing lines; remainder lifecycle after partial/failed; whether full delivery advances `Order.status`.
6. **Gate 1:** authorize deploying the quantity fix to `ecos-dev-app` and committing the uncommitted MTO fixes.

---

## 13. Certification Matrix

| Gate | Architecture | Implementation | Tests | Configuration | Demo Data | Browser | Classification | Certification |
|---|---|---|---|---|---|---|---|---|
| **1 — MTO** | 🟢 | 🟢 (worktree) | 🟢 130+neg | 🟠 undeployed | 🟠 ORD-00014 | ⚪ n/a | ALREADY CORRECT + CONFIG(deploy) + DEMO | 🟡 arch verified; deploy pending |
| **2 — Distribution** | 🟢 (P1/window 🟡) | 🟢 (P1 🔴 gated) | 🟠 not run this pass | 🟠 zone coverage | 🟠 DG-004/5, zone 9 | ⚪ | ALREADY CORRECT + CONFIG + DECISION (+ gated CODE) | 🟡 decision required |
| **3 — Driver Delivery** | 🟢 | 🟢 (uncommitted) | 🟢 14 | ✅ | 🟠 | ⚪ POD-UI not observed | ALREADY CORRECT + tested; GOVERNANCE + DECISIONS | 🟡 built+tested; commit+decide |
| **4 — Returns** | 🔴 | 🔴 (returned leg) | 🟡 delivered only | ✅ | — | ⚪ | ARCHITECTURE GAP + DECISION | 🔴 delivered certifiable / returned gap |

Legend: 🟢 VERIFIED · 🟡 PARTIAL/DECISION · 🟠 CONFIG/DEMO · 🔴 ARCH/CODE GAP · ⚪ not observed. **No cell marked Browser Verified** (no browser observation performed).

---

## 14. Remaining Real Engineering Work

After separating architecture/code from configuration and demo data, the genuine engineering work is:

**A. Gate 4 — Returns (the only substantial new build).** Decide the authoritative return model, then implement a single canonical return movement: driver return **declaration** → warehouse **inspection/receipt** → `AdjustmentIn` (restock + ledger + FIFO) → populate `order_lines.returned_qty` → feed the ADR-015 §6.4 variance and finalize reconciliation. Wire or delete the dead `recordReturn()`.

**B. Small, decision-gated code hardenings** (each small; blocked on a decision above):
- Gate 2: P1 duplicate-empty-shell (manual-group provenance) + planning-window fallback status-sync/canonicalisation.
- Gate 3: `Order.status` advancement on full delivery (listener for `DeliveryStopCompleted`); over-delivery/shortage contracts.
- Gate 4: operator delivery path guard vs custody `on_hand` (scenario-8 symmetry).

**C. Deploy/commit/governance (NOT new engineering):** deploy the MTO quantity fix to `ecos-dev-app`; commit the MTO fixes + the driver-delivery bridge; stabilise the hourly failing job.

**NOT engineering work:** zone 9/3/10 coverage (config/business), ORD-00014 & phantom custody (demo data), reserved pools (demo data).

---

## 15. Recommended Execution Order

1. **Decisions first** (§12) — especially Gate 4's authoritative return model, since it unblocks the largest build and closes the loop.
2. **Governance/deploy** — commit + deploy the already-built, already-tested Gate 1 (quantity) and Gate 3 (delivery bridge) work; only then is a **controlled** ORD-00014 reconciliation safe (separate authorized task).
3. **Gate 4 returns implementation** per the chosen model, with reconciliation finalization.
4. **Decision-gated hardenings** (Gate 2 P1/window; Gate 3 order-status; Gate 4 scenario-8).
5. **Then** Finance architecture lock.

---

## 16. Explicitly Not Done

No source/migration/DB/config/business-data changes. No group/template/order/stock/delivery/return mutation. Nothing manufactured, transferred, delivered, returned, reconciled. **ORD-00014 untouched** (re-confirmed: 0 mfg transactions, `ready_for_dispatch`, FG on_hand 0). No deploy/commit/push/rebase/checkout. No canonical mutating service run. No browser observation → nothing claimed Browser Verified. Zone 9 not added to any template. No test altered.

---

## 17. Final Certification Status

**Operational Fulfillment is NOT yet certifiable closed — but the blocker is narrow.** Gates 1 and 3 are **correct and tested in code** (pending deploy/commit/decisions); Gate 2 is **correct in code** with **configuration + business decisions** outstanding; Gate 4's **returned leg is a genuine architecture gap** requiring a business decision then implementation.

**Answer to the central question — what REAL engineering work remains before Operational Fulfillment can be certified closed?**
> **One substantial build: the Gate 4 return/reconciliation leg** (decide the authoritative model, then wire the canonical return movement so `loaded − delivered − returned` actually reconciles and stock rejoins the warehouse per ADR-015 §11). Everything else is **deploy/commit of already-built-and-tested work, configuration, or business decisions** — not new engineering. **Finance must not start** until Gate 4 is decided+built and Gates 1–3 are committed/deployed/decided.

# TASK-MTO-CONTROLLED-RECONCILIATION-001 — Report

**Date:** 2026-08-28
**Outcome: 🛑 PARTIALLY IMPLEMENTED / BLOCKED — reconciliation NOT performed. NO live data mutated (Phase 1 audit only, all read-only).**
**Reason:** two independent hard STOP conditions prevent a compliant controlled reconciliation of ORD-00014's Honey line. I did not force past either, per the task's stop rules.

---

## 1. Executive Summary

ORD-00014's Honey line is, in principle, a valid MTO candidate (qty 1, active recipe BOM-00001, reserved). But a **compliant** controlled reconciliation — *exactly* 1 Honey via canonical services, with **ECOS-FG-000001 untouched** — is **not achievable in the current environment**:

- **BLOCKER A — the corrected quantity-accuracy fix is NOT active in the live execution path.** `ecos-dev-app` runs against `ecos_dev` (confirmed) but still contains the **buggy** `InventoryAvailabilityEngine` (`freeFinishedGoods` = 0 occurrences). Any canonical manufacture there would over-produce: Honey `1 − (0 − reserved 6) = 7`, consuming **7 Glass Jars + 1.75 Raw Honey** — not 1 / 0.25. The fixes are verified in the **isolated test DB** (as the task states), but were never deployed to `ecos-dev-app`. → hits the stop condition *"manufacturing would produce more than ordered."*
- **BLOCKER B — there is no canonical Honey-only trigger.** The sole canonical MTO trigger, `PrepareOrderManufacturingAction`, processes **every order line** (all three callers: `PrepareOrderAction`, `HandlePreparationWaveStarted`, `HandlePreparationWavePreparationStarted`). ORD-00014 has two lines — Honey **and** the excluded **ECOS-FG-000001**, which is itself manufacturing-eligible (active recipe BOM-00002; component ECOS-RM-000001 has `allow_negative_stock = 1`, so the recipe is executable and `can_manufacture` no longer gates — ADR-027 v1.5). Triggering the canonical flow would therefore manufacture ECOS-FG-000001 as well. → hits *"an unrelated entity would be mutated"* and the explicit *"Do not manufacture or reconcile ECOS-FG-000001."*

Neither blocker can be worked around within the task's constraints (deploying to the app was not authorized and would not resolve B; isolating the Honey line would require mutating the ECOS-FG line or a new per-line trigger — both out of scope / forbidden; a direct lower-level `ManufacturingApplicationService` call would bypass the canonical order-lifecycle seam **and** still over-produce). **Per the task, I STOP and report.**

---

## 2. Before Snapshot (`ecos_dev`, read-only, 2026-08-28)

**Order** — `ORD-00014` (`01a0228a-f199-…`): status `ready_for_dispatch`, reservation_status `reserved`, warehouse `019f4e1c-2e1b-…`, `total manufacturing_transactions = 0` (system-wide).

**Order lines**

| line | SKU | can_mfg | qty | mfg_state | mfg_started_at | delivered | returned | loaded |
|---|---|---|---|---|---|---|---|---|
| `…c082d19` | ECOS-FG-000001 | 0 | 1 | NULL | **2026-08-28 05:00:06** ⚠ | 0 | 0 | 0 |
| `…16523c` | FG-HONEY-250 | 0 | 1 | NULL | NULL | 0 | 0 | 0 |

**Inventory (assigned warehouse)**

| SKU | role | on_hand | reserved | available | allow_neg |
|---|---|---|---|---|---|
| FG-HONEY-250 | FG (target) | 0 | 6 | −6 | 0 |
| ECOS-FG-000001 | FG (excluded) | 0 | 15 | −15 | 0 |
| PKG-JAR-250 (Glass Jar) | RM | 540 | 8 | 532 | 0 |
| RM-HONEY-01 (Raw Honey) | RM | 100 | 2 | 98 | 0 |
| ECOS-RM-000001 | RM | 0 | 17 | −17 | 1 |

**Manufacturing:** 0 transactions; no `production_output`; recipes present (BOM-00001 Honey = 1 Glass Jar + 0.25 Raw Honey; BOM-00002 ECOS-FG = 1 ECOS-RM).

**Vehicle custody** (assignment `01a03b25-906f-…`, loaded 2026-08-26): Honey loaded 1 / on_hand 1; ECOS-FG loaded 1 / on_hand 1 — **phantom** (created by pre-bridge `recordLoad`; never produced/transferred).

**Loading tasks** (same assignment): Honey `loaded` qty 1 (`preparation_wave_id` NULL); ECOS-FG `loaded` qty 1 (NULL). **Outbound/production ledger for both FGs: 0 rows. `vehicle_custody_transfer` ledger rows: 0.**

**Environment:** recurring hourly `failed_jobs` (`DecryptException: MAC is invalid`, 03:00–06:00 today); ECOS-FG line's `mfg_started_at` set at 05:00:06 today with NULL state — an interrupted attempt. The environment is **not quiescent**.

---

## 3. Eligibility Verification (Phase 2)

| Condition | Result |
|---|---|
| Correct order line (Honey) present | ✅ `…16523c`, FG-HONEY-250 |
| Quantity = 1 | ✅ |
| Active recipe exists | ✅ BOM-00001 (1 Glass Jar + 0.25 Raw Honey) |
| Reservation exists as expected | ✅ FG reserved 6 (pool); the line reserves 1 |
| Manufacturing required (FG on_hand 0) | ✅ |
| No prior successful manufacturing txn | ✅ (0 system-wide) |
| ECOS-FG-000001 excludable from the canonical trigger | ❌ **NO** — the trigger is all-lines and ECOS-FG is eligible (Blocker B) |
| Corrected exact-quantity engine active in the execution path | ❌ **NO** — `ecos-dev-app` has the buggy engine (Blocker A) |

**Phase 2 verdict: STOP.** Two required conditions fail. Per the task ("If any condition is not satisfied: STOP. Do not improvise."), the reconciliation does not proceed.

---

## 4. Canonical MTO Path (why it cannot be used here)

Intended chain: `PrepareOrderManufacturingAction → OrderLifecycleCoordinator → ManufacturingLifecycleHandler → ManufacturingApplicationService → ManufacturingExecutor → produceFinishedGoods`. The trigger is **order-scoped** (`foreach ($order->lines …)`), verified across all callers. Two reasons it cannot be invoked compliantly on ORD-00014:
1. It would run the **buggy** availability engine in `ecos-dev-app` → over-production (Blocker A).
2. It would manufacture **both** lines, including the forbidden ECOS-FG (Blocker B).
No canonical per-line manufacturing action exists. Calling `ManufacturingApplicationService::manufactureProduct` directly is explicitly disallowed by `PrepareOrderManufacturingAction`'s own contract (must go through the coordinator) and would still over-produce.

## 5. Manufacturing Result
**Not executed.** No manufacturing was triggered. `manufacturing_transactions` remains 0.

## 6. Raw Material Consumption
**None.** Glass Jar 540 and Raw Honey 100 are unchanged (no consumption).

## 7. Warehouse Result
**Unchanged.** Honey FG on_hand remains 0. No `production_output` / `adjustment_in` written.

## 8. Vehicle Custody Transfer
**Not executed.** (Even if manufacturing had run, custody already holds a phantom 1 Honey with `vehicle_custody_transfer` rows = 0; the correct reconciliation semantics for that pre-existing custody are an open business decision — see §17.)

## 9. Ledger Reconciliation
No new ledger movements. Outbound rows for both FGs remain 0; `vehicle_custody_transfer` rows remain 0.

## 10. Order State
**Unchanged.** Status `ready_for_dispatch`; both lines `mfg_state` NULL. `Order.status` was never touched.

## 11. Trip / Loading State
Loading tasks for Honey and ECOS-FG both `loaded` (qty 1, `preparation_wave_id` NULL) on assignment `01a03b25-906f-…`; vehicle custody credited 1 each (phantom). No trip/loading state was modified.

## 12. Idempotency
**N/A** — nothing was executed. (The corrected flow's idempotency is already proven in the isolated test DB by prior tasks.)

## 13. Negative Controls
The downstream guards that would (correctly) protect ECOS-FG and duplicate transfers were **not reached**, because the blocker is upstream: the canonical trigger cannot be scoped to the Honey line at all. No artificial data was created; no unrelated live data was touched to run a negative control.

## 14. Live Mutations
**NONE.** Every step in this task was a read-only SELECT against `ecos_dev` or a read of source/config. No canonical mutating service was invoked; no order/line/inventory/ledger/custody/trip/reservation/payment/Finance row was changed. ORD-00014 is byte-for-byte as found.

## 15. Regression Tests
**N/A this task** — no reconciliation ran and no code changed. The corrected mechanisms retain their prior isolated-test-DB coverage (MTO quantity/trigger: `OK (130 tests)` + negative control; returns/custody/delivery: `OK (80 tests)`), verified in earlier tasks. Running them again would not exercise anything new here.

## 16. Before / After Reconciliation Table

| Metric | Before | After (unchanged) |
|---|---|---|
| Honey FG on_hand | 0 | 0 |
| Glass Jar on_hand | 540 | 540 |
| Raw Honey on_hand | 100 | 100 |
| manufacturing_transactions | 0 | 0 |
| Honey vehicle custody | 1 (phantom) | 1 (phantom) |
| `vehicle_custody_transfer` ledger rows | 0 | 0 |
| Order status | ready_for_dispatch | ready_for_dispatch |
| ECOS-FG-000001 | untouched | untouched |

## 17. Remaining Issues (the blockers + the compliant path forward)

1. **Deploy the corrected quantity-accuracy fix to `ecos-dev-app`** (it is verified in the test DB; currently only the trigger-gap fix is live there). Without it, live manufacture over-produces. Requires an explicit deploy authorization.
2. **A canonical way to reconcile the Honey line alone.** The order-level trigger cannot exclude ECOS-FG. Options (each an owner decision, none doable within this task's constraints): (a) a per-line manufacturing trigger; (b) making ECOS-FG's line legitimately non-eligible through a canonical state change; (c) accepting that ORD-00014 is a mixed order and authorizing manufacture of *both* lines (contradicts the current instruction).
3. **Stabilise the environment** (hourly failing job; ECOS-FG line `mfg_started_at` limbo) so a controlled run is not racing a scheduled process.
4. **Phantom-custody semantics** — custody already holds 1 Honey with no production/transfer; decide whether a produce-then-transfer should back-fill it or is a no-op.

## 18. Gate 1 Certification

**PARTIALLY IMPLEMENTED / BLOCKED.** The corrected MTO mechanisms are implemented and verified in the isolated test DB, but ORD-00014 **cannot be reconciled in the live DEV environment as instructed** because (A) the quantity fix is not deployed to `ecos-dev-app` and (B) no canonical trigger can manufacture the Honey line without also manufacturing the excluded ECOS-FG-000001. **No live data was mutated. No stop condition was forced.** Gate 1 remains **DEFERRED** pending the decisions in §17.

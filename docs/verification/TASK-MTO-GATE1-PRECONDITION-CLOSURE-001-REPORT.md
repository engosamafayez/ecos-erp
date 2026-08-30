# TASK-MTO-GATE1-PRECONDITION-CLOSURE-001 — Report

**Date:** 2026-08-28
**Outcome:** Blockers A, C, D resolved/classified; **B implemented & tested**; a **new Blocker E discovered** that is decisive. **GATE 1 READINESS: 🔴 BLOCKED** — do NOT execute the ORD-00014 reconciliation.
**Live change made (authorized):** deployed ONLY the verified MTO quantity-accuracy fix to `ecos-dev-app` + cache clear. **No business data mutated; ORD-00014 byte-identical before/after.**

---

## 1. Executive Summary

Three of the four known blockers are cleared and one is implemented+tested, but a **fifth, previously-unseen blocker** makes the controlled reconciliation impossible right now:

- **A — Quantity fix: READY.** The verified clamp (`free = max(0, on_hand − reserved)`; `shortage = max(0, required − free)`) is now deployed to `ecos-dev-app` and **proven active on the live app** by a read-only `analyse()`: Honey `7 → 1`, ECOS-FG `16 → 1`.
- **B — Per-line manufacturing: READY (implemented + tested, deploy-with-reconciliation).** A minimal additive seam `PrepareOrderManufacturingAction::executeForLines(order, [lineIds])` reuses the same canonical pipeline (no second engine) and manufactures ONLY authorized lines. Proven by tests (line B untouched; exact quantity). Not yet deployed to the app (Part 7 limits live changes to the quantity fix).
- **C — Scheduler DecryptException: NON-BLOCKING.** It is `Modules\Marketing\ProviderConfig\…\CheckProviderHealthJob` on the `health` queue — a Marketing provider health-ping, unrelated to MTO, failing at payload decryption (stale encrypted config vs APP_KEY) before `handle()` runs. It mutates no order/inventory data.
- **D — Phantom vehicle custody: NON-BLOCKING for the Honey line.** The custody was created by the canonical pre-bridge `recordLoad` (`loaded` movements, 2026-08-26), not a transfer. A future produce-then-transfer of Honey would *back* the existing custody without double-counting; ECOS-FG custody is out of scope (owner note).
- **🆕 E — No production manufacturing decision-rule provider: BLOCKING / OWNER DECISION.** The live app has **no** `manufacturing` rule provider registered (`registry->for('manufacturing')` throws `NoProviderForContextException`). The canonical manufacturing workflow therefore **throws at its decision stage** for every request — which is why `manufacturing_transactions = 0` system-wide and why ORD-00014's ECOS-FG line sits in `mfg_started_at`-set / state-NULL limbo. No amount of A/B/C/D work lets the reconciliation manufacture until a production manufacturing rule policy is defined and registered — a business/architecture decision, not something to invent.

**GATE 1 READINESS: BLOCKED (by E; B also needs deployment).** The reconciliation was NOT executed.

---

## 2. Previous Gate 1 Stop Conditions

TASK-MTO-CONTROLLED-RECONCILIATION-001 stopped on: (A) quantity fix not deployed to `ecos-dev-app`; (B) order-scoped trigger would also manufacture the excluded ECOS-FG; (C) hourly DecryptException; (D) phantom custody. This task addresses each and adds the decisive (E).

## 3. Quantity Fix Deployment (Part 1)

**Files deployed (only these):** `Modules/Manufacturing/AvailabilityEngine/Domain/Services/InventoryAvailabilityEngine.php`, `…/ValueObjects/AvailabilityResult.php`. The worktree diff was verified to contain **only** the approved clamp + doc (31 insertions / 5 deletions, 2 files); `InventoryItem::availableQty()` was NOT touched (the fix clamps the engine's local free position, exactly as certified). No unrelated working-tree changes were deployed.

**Mechanism:** `docker cp` the two files into `ecos-dev-app` + `php artisan optimize:clear`. `opcache.validate_timestamps = On`, so php-fpm auto-reloads the changed files (no restart / secret rotation).

## 4. Deployed Version Verification (Parts 1 & 3, criterion 2)

Read-only `analyse()` invoked directly on the **live** app (the engine is contractually side-effect-free) against the real live data:

| Product | reserved | BEFORE deploy | AFTER deploy | Correct |
|---|---|---|---|---|
| FG-HONEY-250 | 6 | **7** | **1** | ✅ 1 |
| ECOS-FG-000001 | 15 | **16** | **1** | ✅ 1 |

`grep freeFinishedGoods` in the deployed engine = 2. **The live application can no longer calculate 7 instead of 1** (success criterion 2). No mutation occurred (before/after ORD-00014 identical — §12).

## 5. Per-Line Manufacturing Architecture (Part 2)

Trace: `PrepareOrderManufacturingAction::execute(order)` loops `$order->lines` → `processLine($line,…)` → `OrderLifecycleCoordinator::handle` → `ManufacturingLifecycleHandler` → `ManufacturingApplicationService::manufactureProduct` → `ManufacturingWorkflow` (Decision → Availability → Planner) → `ExecutionPipeline`/`ManufacturingExecutor` → `manufacturing_transactions` + `produceFinishedGoods`. **Line selection happens only in that one `foreach`.** The three callers (`PrepareOrderAction`, `HandlePreparationWaveStarted`, `HandlePreparationWavePreparationStarted`) are all order-scoped. No pre-existing line-scoped seam.

## 6. Line-Scoped Trigger Implementation (Part 2)

Added `PrepareOrderManufacturingAction::executeForLines(Order $order, array $orderLineIds): void` — **purely additive** (existing `execute()` byte-unchanged, verified: 0 lines removed). It filters `$order->lines` to the authorized ids and calls the **same** private `processLine()`, so it introduces **no** second manufacturing engine, recipe resolver, stock consumer, or production-transaction authority — it only narrows which lines the existing canonical pipeline runs for. Empty scope is an explicit no-op (never "all lines"). This is the smallest canonical seam for a single-line reconciliation. **Not deployed to `ecos-dev-app`** (Part 7 restricts live changes to the quantity fix); it deploys with the reconciliation.

## 7. Line Isolation Tests (Part 7 CRITICAL SAFETY TEST)

`tests/Feature/Orders/LineScopedManufacturingTest.php` (isolated fixtures; NOT ORD-00014), through the real pipeline:
- `test_line_scoped_manufacture_produces_only_the_authorized_line`: order with eligible lines A + B; `executeForLines(order,[A])`. **Asserts A manufactured** (1 transaction for A, `qty_produced=1`, FG A +1, RM A −1) and **B completely untouched** — no transaction for B, no FG for B, no RM consumed for B, no `production_output` ledger for B, `manufacturing_state` NULL.
- `test_empty_line_scope_is_a_no_op_never_all_lines`: empty ids → 0 transactions.

## 8. Quantity Safety Tests (Part 3)

`test_reserved_pool_does_not_over_produce_through_line_scope`: reserved 6 → produce **1**; reserved 15 → produce **1** (never 1+reserved). Plus the certified `MtoProductionQuantityAccuracyTest` (9) and `MtoManufacturingQuantityIntegrationTest` (5). Suite result: **`OK (17 tests, 72 assertions)`**.

## 9. Scheduler / DecryptException Investigation (Part 4)

**Job:** `Modules\Marketing\ProviderConfig\Application\Jobs\CheckProviderHealthJob` (implements `ShouldQueue`), queue `health`, failing hourly with `DecryptException: The MAC is invalid`.
- **Cause:** the queued job's encrypted payload/command cannot be decrypted — a stale encrypted Marketing provider config vs the current `APP_KEY`. It fails at **payload deserialization**, before `handle()` runs.
- **Related to MTO?** No (Marketing provider-config domain; no Order/Inventory/Manufacturing/Stock references).
- **Mutates business data?** No — fails before executing; even on success it is a provider health ping.
- **Concurrent interference with the reconciliation?** No (different domain, different queue, immediate failure).
- **Verdict: NON-BLOCKING.** Remediation (re-encrypt provider config / investigate APP_KEY) is a separate Marketing infra task — explicitly NOT done here (no secret rotation, no job deletion, no scheduler change).

## 10. Phantom Vehicle Custody Investigation (Part 5)

`vehicle_inventory_movements` on assignment `01a03b25-906f-…` shows both custody rows were created by **`recordLoad`** — movement `loaded`, `reference_type='loading_task'`, `actor_type='user'`, 2026-08-26 02:39. Loading tasks for both FGs are `status='loaded'`, qty 1. **`vehicle_custody_transfer` ledger rows = 0**; no `production_output` for either FG.
1. Legitimately loaded? Recorded via the canonical `recordLoad`, but the FG was never produced (warehouse on_hand 0), so custody was credited for goods with no warehouse backing.
2. Created by recordLoad before transfer? **Yes** — pre-bridge `recordLoad` credits custody without the warehouse-side transfer.
3. Stale/phantom? Phantom (unbacked), not duplicate.
4. Current physical vehicle state? Unverifiable from data (needs physical check) — a data-vs-physical question, not resolvable here.
5. Canonical reconciliation service? Load-side has `recordLoadCorrection`; there is no "un-phantom" service (and none should be invented).
6. Can Gate 1 proceed without touching them? **Yes for Honey** — a future produce-1 + `TransferLoadedStockToVehicleAction` does the warehouse-side ship only (custody already credited), so custody stays 1 (backed), not 2.
7. Double-count? **No** — the transfer never credits `vehicle_inventory_items`; it is idempotent on `(vehicle_custody_transfer, loading_task_id)`.
**Verdict: NON-BLOCKING for the Honey reconciliation.** The ECOS-FG phantom custody remains and is a separate **owner decision** (not touched — no delete/decrement/compensating entry, per instruction).

## 11. ORD-00014 Before State

status `ready_for_dispatch`; lines Honey (qty 1, state NULL) + ECOS-FG (qty 1, state NULL, `mfg_started_at` 2026-08-28 05:00:06 — the Blocker-E limbo); `manufacturing_transactions` 0 (system-wide); Honey FG on_hand 0 / reserved 6; ECOS-FG on_hand 0 / reserved 15; Glass Jar 540; Raw Honey 100; ECOS-RM 0 (allow_neg); Honey & ECOS-FG vehicle custody 1 each; `vehicle_custody_transfer` rows 0.

## 12. Live Data Safety Verification (Part 7)

| Metric | Before | After |
|---|---|---|
| ORD-00014 status | ready_for_dispatch | ready_for_dispatch |
| manufacturing_transactions | 0 | 0 |
| Honey FG on_hand | 0 | 0 |
| Glass Jar / Raw Honey | 540 / 100 | 540 / 100 |
| Honey vehicle custody | 1 | 1 |

**No business data changed.** The only live change was the quantity-fix code deploy + cache clear. All diagnostics (`analyse()`, registry probe) were read-only.

## 13. Regression Results (Part 8)

Isolated `ecos_dev_test`, one invocation:

```
OK (77 tests, 570 assertions)
```

Suites: `LineScopedManufacturingTest` (new) · `WaveDrivenManufacturingTriggerTest` · `OrderManufacturingIntegrationTest` · `InventoryAvailabilityEngineTest` · `DriverLoadingCustodyHandoffTest` · `InventoryReservationTest`. **No regression** — the additive `executeForLines` seam (existing `execute()` byte-unchanged) and the deployed quantity clamp leave the wave path, manufacturing integration, availability engine, loading custody, and reservation all green. Earlier in the session the seam+quantity suite was also green: `OK (17 tests, 72 assertions)`. No tests weakened; no unrelated fixtures modified.

## 14. Blocker Matrix

| Blocker | Status |
|---|---|
| **A — Quantity Fix** | ✅ **READY** — deployed to `ecos-dev-app`, proven active (live 7→1, 16→1) |
| **B — Per-Line Manufacturing** | ✅ **READY** (implemented + tested; additive seam; deploy with the reconciliation) |
| **C — Scheduler / DecryptException** | 🟢 **NON-BLOCKING** — Marketing health job, unrelated, no business mutation |
| **D — Phantom Custody** | 🟢 **NON-BLOCKING** for Honey (backable, no double-count); ECOS-FG cleanup = OWNER DECISION |
| **🆕 E — No production manufacturing rule provider** | 🔴 **BLOCKING / OWNER DECISION** — workflow throws `NoProviderForContextException`; MTO cannot manufacture in live |

## 15. Recommended Gate 1 Execution Path

1. **Resolve E (owner/architecture):** define the production `manufacturing` decision-rule policy and register a `RuleProvider` for the `manufacturing` context at boot. (Tests use a blanket Approve; production must decide the real policy — I did not invent it.) Without this, no MTO manufacturing can run anywhere in production.
2. **Deploy the line-scoped seam** (`PrepareOrderManufacturingAction`) to `ecos-dev-app` (additive; safe) so the reconciliation can target the Honey line only.
3. Then run TASK-MTO-CONTROLLED-RECONCILIATION-001: `executeForLines(ORD-00014, [Honey line])` → produce exactly 1 (fix now live) → `TransferLoadedStockToVehicleAction` for the Honey loading task (backs the existing custody, no double-count) → verify.
4. **ECOS-FG phantom custody** and the **Marketing DecryptException** are independent follow-ups (neither blocks the Honey reconciliation once E is resolved).

## 16. Owner Decisions Required

1. **Production manufacturing decision policy (Blocker E)** — which rules approve/defer/reject/escalate an `order_lifecycle` manufacturing request; then register the provider. (Highest priority — it un-blocks all MTO manufacturing, not just ORD-00014.)
2. **ECOS-FG-000001 phantom custody** — whether/how to reconcile the unbacked 1-unit custody (out of the Honey Gate-1 scope).
3. **Marketing provider-config decryption** — re-encrypt/APP_KEY remediation for `CheckProviderHealthJob` (non-blocking, separate domain).
4. Authorize deploying the line-scoped seam to `ecos-dev-app`.

## 17. Gate 1 Readiness

**🔴 BLOCKED.** A is deployed+proven; B is implemented+tested; C and D are non-blocking. But **Blocker E** (no production manufacturing rule provider → the canonical workflow throws) prevents any live MTO manufacture, so the ORD-00014 reconciliation cannot proceed. **The reconciliation was NOT executed. ORD-00014 is unchanged.** Gate 1 becomes READY once E is decided+registered and the seam is deployed.

> Precondition closure only — Gate 1 itself is NOT closed. The reconciliation remains a separate controlled operation, now additionally gated on the owner decision for Blocker E.

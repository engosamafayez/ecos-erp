# TASK-MTO-WAVE-WHOLE-ORDER-MANUFACTURING-GUARD-001 — Report

**Date:** 2026-08-28
**Mode:** READ-ONLY diagnostic / design. No code changed, no deploy, no live mutation, ORD-00014 untouched.

## Final classification: 🔴 B — BLOCKED, OWNER ARCHITECTURE DECISION REQUIRED

The Wave → MTO manufacturing boundary **cannot be made safe for ORD-00014 by any code guard alone**, because the "forbidden" line **ECOS-FG-000001 is fully manufacturing-eligible under the canonical `ManufacturingPolicy`** and is **indistinguishable, in every business attribute, from the Honey line that is authorized**. Refusing ECOS-FG therefore requires either inventing a business rule or hard-coding demo data — both forbidden by prior task contracts. The true blocker is a business/data decision that only the owner can make: **is ECOS-FG-000001 a legitimate made-to-order line (let the wave produce it, after quiescing), or is it phantom/demo data to be corrected at its source (deactivate its recipe / remove the line / restore capability gating)?** A secondary, independently deploy-blocking finding: the live environment is **non-quiescent** — a fully-automated wave engine re-arms this exact hazard **every operational cycle**, and the empty Decision-Kernel gate is currently the *only* thing preventing ECOS-FG from being manufactured unattended.

---

## 1. Executive Summary

The scheduled wave engine is the real production path, and it manufactures at **WHOLE-ORDER** scope: both wave listeners call `PrepareOrderManufacturingAction::execute($order)`, which loops **every** order line. ORD-00014 carries two lines — `FG-HONEY-250` (authorized) and `ECOS-FG-000001` (forbidden). I proved, from live data and the code path, that **both** lines pass all seven `ManufacturingPolicy` rules, so the whole-order call would manufacture **both** the instant the kernel gate is live. The gate is currently down by design (it throws `NoProviderForContextException`), which is why ORD-00014 has not been manufactured despite the wave attempting it every cycle.

I also identified, with matching timestamp evidence, the full recurring loop: the wave collects ORD-00014 each cycle, attempts whole-order manufacturing at intake cutoff (~05:00 — matching ECOS-FG's `manufacturing_started_at = 05:00:06`), fails at the empty gate, and at wave end (~13:00) the carry-over listener returns the order to `in_progress` (matching ORD-00014's status flip at `13:00:01`), which re-arms it for the next cycle.

The line-scoped seam (`executeForLines`, fail-closed) already exists and is the correct **mechanism** for a guard. But it does not, and cannot, decide *which* lines to exclude — and there is **no canonical signal** that separates ECOS-FG from Honey. That is the owner decision below.

## 2. Paramount Rule & Constraint Compliance

| Constraint | Status |
|---|---|
| ECOS-FG-000001 must NOT be manufactured | ✅ Not manufactured (0 transactions system-wide; gate down) |
| ORD-00014 byte-for-byte unchanged | ✅ Re-verified identical at task end (§3) |
| No live mutation / no direct DB writes | ✅ Only `SELECT` / `information_schema` reads issued |
| Do NOT implement / deploy / reconcile | ✅ Nothing implemented, deployed, or reconciled |
| Do NOT modify Wave state or Order.status | ✅ No writes of any kind |
| STOP if an external process changes ORD-00014 | ✅ No external change during the task (§3) |

## 3. ORD-00014 Baseline & End-of-Task Re-Verification (no mutation)

Both snapshots taken read-only against **live `ecos_dev`** (not the test DB — the repo `.env` points at `ecos_erp_test`; the app container env is authoritative: `DB_DATABASE=ecos_dev`, `DB_HOST=mysql`).

| Field | Task start | Task end | Δ |
|---|---|---|---|
| `orders.status` | `in_progress` | `in_progress` | none |
| `orders.previous_status` | `ready_for_dispatch` | `ready_for_dispatch` | none |
| `orders.status_entered_at` | `2026-08-28 13:00:01` | `2026-08-28 13:00:01` | none |
| `manufacturing_transactions` (system) | `0` | `0` | none |
| ECOS-FG line `manufacturing_state` | `NULL` | `NULL` | none |
| ECOS-FG line `manufacturing_started_at` | `2026-08-28 05:00:06` | `2026-08-28 05:00:06` | none |
| Honey line `manufacturing_started_at` | `NULL` | `NULL` | none |

Server time at re-verify: `2026-08-28 17:37:14`. **No mid-task mutation.**

## 4. The Scheduler (Part 1) — `wave:run-scheduler`, every minute, fully automated

`backend/routes/console.php:58`:

```php
Schedule::command('wave:run-scheduler')->everyMinute()->withoutOverlapping()->runInBackground();
```

Command: `RunWaveSchedulerCommand` (`backend/Modules/Operations/Preparation/Infrastructure/Console/Commands/RunWaveSchedulerCommand.php:48`, signature `wave:run-scheduler`). Per tick, per active `WaveEngineConfiguration`, it: **reconciles** open waves against their own boundaries, **opens** the current cycle's wave (`auto_create`), and **collects** eligible orders (`auto_assign_orders`). At the intake cutoff it calls `WavePreparationService::startPreparation($wave)` (`RunWaveSchedulerCommand.php:176`, guarded by `auto_move_to_preparing`).

Live config for ORD-00014's warehouse `019f4e1c-2e1b-7269-bfbb-8a414cb07cab` (company `019f4e1c-2d1e-...`): `is_active=1, auto_create=1, auto_assign_orders=1, auto_move_to_preparing=1` — **no human in the loop**.

Also scheduled (context; not implicated): `orders:activate-scheduled` (00:05), `preparation:create-daily-sessions` (06:00), `preparation:freeze-sessions` (every minute), `marketing:provider:health-check` (hourly/6h/daily — the source of the known, unrelated `DecryptException`).

## 5. Wave Lifecycle & Event Dispatch (Part 1/2)

- `WavePreparationService.php:74` — `event(new WavePreparationStarted(... orderIds ...))` at intake cutoff (engine path).
- `WaveLifecycleService.php:139` — `event(new WaveClosed(...))` at wave end (`closeWave()`, called from `RunWaveSchedulerCommand::closeEndedWave` at `:194`).
- `WaveStarted` is the manual/approved-start sibling event (same downstream manufacturing consequence).

Listener registration — `backend/Modules/Commerce/Orders/Infrastructure/Providers/OrderServiceProvider.php:65-73`:

```php
$events->listen(WaveStarted::class,            HandlePreparationWaveStarted::class);
$events->listen(WavePreparationStarted::class, HandlePreparationWavePreparationStarted::class);
$events->listen(WaveClosed::class,             HandlePreparationWaveClosed::class);
```

## 6. The Two Wave Listeners — the WHOLE-ORDER caller (Part 2/5)

Both listeners are structurally identical. After `MoveToPreparationWorkflow` moves the order to `ready_for_dispatch`, they call the **whole-order** action:

- `HandlePreparationWaveStarted.php:88` → `$this->manufacturing->execute($result->order);`
- `HandlePreparationWavePreparationStarted.php:79` → `$this->manufacturing->execute($result->order);`

Their skip-list (`$terminalStatuses`, `HandlePreparationWaveStarted.php:54-60`) is `{ready_for_dispatch, out_for_delivery, delivered, cancelled, returned}`. **`in_progress` is not in it** — and `in_progress` is exactly the status the carry-over leaves ORD-00014 in (§11). So the order is fully re-eligible every cycle.

## 7. The Whole-Order Manufacturing Mechanism (Part 5)

`PrepareOrderManufacturingAction::execute()` (`...Orders/Application/Actions/PrepareOrderManufacturingAction.php:56-70`) loops **all** lines:

```php
foreach ($order->lines as $line) {
    $this->processLine($line, $order, $warehouseId, $companyId);   // every line, no filter
}
```

`processLine()` (`:120-156`) — line **132** sets `manufacturing_started_at = now()` **before** anything else, then calls `OrderLifecycleCoordinator::handle()` → `ManufacturingLifecycleHandler`:
- **Step 1** (`ManufacturingLifecycleHandler.php:79-117`): `ManufacturingPolicy::evaluate()`. If ineligible → `PolicyRejected`/`AlreadyExecuted` **returns without touching the workflow**.
- **Step 2** (`:119-135`): only if eligible → `ManufacturingApplicationService::manufactureProduct()` → `ManufacturingWorkflow` stage-1 `DecisionOrchestrator` → `registry->for('manufacturing')`.

With the gate absent, Step 2 throws `NoProviderForContextException`; `processLine` never reaches `updateLineState()`, so the line's `manufacturing_state` stays **NULL** while `manufacturing_started_at` remains stamped.

## 8. Why Wave reaches WHOLE-ORDER, not the line-scoped seam (Part 5)

`executeForLines(Order, array $orderLineIds)` (`PrepareOrderManufacturingAction.php:92-116`) already exists — additive, `execute()` unchanged. It filters to authorized line ids, reuses the **same** `processLine()` pipeline, and is **fail-closed**: an empty scope is a no-op (`:100-102`), never a fallback to all lines. **The wave listeners simply do not call it** — they call `execute()`. That single choice is the entire hazard surface.

## 9. Linchpin — ECOS-FG-000001 is fully `ManufacturingPolicy`-eligible

`ManufacturingPolicy` (`.../ManufacturingPolicy/Domain/Services/ManufacturingPolicy.php`) evaluates 7 rules. **Rule 3 (`can_manufacture`) was REMOVED** (`:110-119`, ADR-027 §16 v1.5 / TASK-ORDER-PREPARATION-FULFILLABILITY-CONTRACT-001): the capability flag no longer gates preparation; **recipe presence (Rule 4) is the sole capability gate.**

Live evaluation for ORD-00014's ECOS-FG line, all from live `ecos_dev`:

| Rule | Requirement | ECOS-FG-000001 | Pass |
|---|---|---|---|
| 1 | order not cancelled | `in_progress` | ✅ |
| 2 | status ∈ {in_progress, confirmed, ready_for_dispatch} | `in_progress` | ✅ |
| 3 | ~~can_manufacture~~ | **removed** (flag is `0`, ignored) | n/a |
| 4 | active recipe exists | `bills_of_materials` v1, `is_active=1`, not deleted | ✅ |
| 5 | inventory-managed | `product_type='finished_good'` ∈ `Product::TYPES` | ✅ |
| 6 | required_qty > 0 | `1.0000` | ✅ |
| 7 | not already manufactured | 0 transactions | ✅ |

**Result: ECOS-FG-000001 is ELIGIBLE.** `can_manufacture=0` gives no protection — it is deliberately not consulted. The Honey line is eligible on identical grounds. **The two lines are indistinguishable in every business attribute** (both: qty 1, finished_good, active recipe v1, `can_manufacture=0`, state NULL). There is no canonical field that says "manufacture Honey but not ECOS-FG."

## 10. Timestamp evidence — the 05:00 attempt reached the gate (Part 8)

ECOS-FG line: `manufacturing_started_at = 2026-08-28 05:00:06`, `manufacturing_state = NULL`, `manufacturing_completed_at = NULL`. This is only reproducible if: `processLine` ran on ECOS-FG (stamped line 132) → `coordinator->handle()` **threw** (so `updateLineState` never ran, leaving state NULL). Per §7, a throw in the coordinator implies Step 2 was reached, which implies **`ManufacturingPolicy` did not reject** — corroborating §9 independently. Honey's `manufacturing_started_at = NULL` shows the loop hit ECOS-FG **first** and aborted before Honey; with the gate live, ECOS-FG would be the **first** line manufactured. `05:00:06` aligns with the wave's intake cutoff (`intake_closes_at = 05:00:00`).

## 11. The `ready_for_dispatch → in_progress` writer (Part 9)

`HandlePreparationWaveClosed` (`.../Orders/Application/Listeners/HandlePreparationWaveClosed.php`), on `WaveClosed`. **CASE C** (`:115-130`): an order that is not in the shipping lifecycle, not fully prepared, and holds `ready_for_dispatch` is run through `ReturnToProcessingWorkflow` → **`in_progress`**. The write goes through the canonical workflow (audit + guard), and deliberately **does not release inventory**. Docblock: *"RETURN TO IN PROGRESS … In Progress IS fulfilment-eligible, so the next cycle collects it automatically — that is the carry-over."* This is the exact writer of ORD-00014's `13:00:01` flip.

## 12. ORD-00014 wave re-selection — the carry-over loop (Part 3/8)

`preparation_wave_orders` history for ORD-00014 (all `closed`, each released at 13:00):

| wave | starts_at | intake_closes_at | ends_at | released_at |
|---|---|---|---|---|
| PREP-202608-000011 | 08-27 18:00 | **08-28 05:00** | 08-28 13:00 | **08-28 13:00:00** |
| PREP-202608-000010 | 08-26 18:00 | 08-27 05:00 | 08-27 13:00 | 08-27 13:00:00 |
| PREP-202608-000009 | 08-25 13:30 | 08-26 05:00 | 08-26 13:00 | 08-26 13:00:00 |
| PREP-202608-000008 | 08-24 13:30 | 08-25 05:00 | 08-25 13:00 | 08-25 13:00:00 |

The most recent wave **released ORD-00014 at 13:00:00**, one second before the `in_progress` flip at **13:00:01**. There is **no open/collecting/preparing wave** for the warehouse right now (17:37, between cycles). The loop, per cycle, unattended:

```
~18:00  auto_create wave  →  auto_assign collects ORD-00014 (in_progress = fulfilmentEligible)
~05:00  intake cutoff → WavePreparationStarted → MoveToPreparation (→ ready_for_dispatch)
        → PrepareOrderManufacturingAction::execute()  [WHOLE-ORDER]
           → gate DOWN: throws (ECOS-FG stamped 05:00:xx), never fully prepared
~13:00  wave ends → WaveClosed → HandlePreparationWaveClosed CASE C
        → ReturnToProcessingWorkflow → in_progress   (re-arms for next cycle)
```

Because manufacturing can never complete while the gate is down, ORD-00014 **never becomes fully prepared and loops forever** (§18).

## 13. Imminence & recurrence (environment)

Server time `2026-08-28 17:37:14`; no wave created since 13:00. Per the 18:00 pattern and `auto_create=1`, the next wave opens **~18:00 today**, collects ORD-00014, and the next whole-order manufacturing attempt fires at the **next intake cutoff, ~05:00 on 2026-08-29**. The gate is down, so that attempt will throw (safe). But **if the gate is deployed before then, ORD-00014 — ECOS-FG first — is manufactured unattended.**

## 14. The architecture question (Part 4): should Wave trigger MTO manufacturing?

**Answer from existing sources: YES — Wave triggering MTO manufacturing is a deliberate, documented decision, not an accident.** It was the explicit fix of TASK-MTO-MANUFACTURING-TRIGGER-GAP-DIAGNOSIS-001 ("BREAK B"): the automated wave *is* the production path and previously reserved but never manufactured. Both listeners' docblocks state they must mirror the manual `PrepareOrderAction` and reach the canonical manufacturing trigger after `ready_for_dispatch`. So "wave should only move to preparation, never manufacture" is **contradicted** by the current architecture. This half of Part 4 is **not ambiguous**.

**The ambiguity is elsewhere, and it is real:** given the wave *does* manufacture, *which lines may it manufacture?* Canonically, **all `ManufacturingPolicy`-eligible lines** — which includes ECOS-FG-000001 (§9). The rule "ECOS-FG must not be manufactured" exists only as an owner directive in the reconciliation tasks; it has **no representation** in any ADR, spec, policy, or product/line attribute. Deciding whether ECOS-FG is legitimately manufacturable is a business/data decision → **STOP, owner decision required.**

## 15. Fail-closed guard design (Part 6) — the mechanism

The correct mechanism is already present and should be the *only* manufacturing entry point from the wave:

1. In each wave listener, replace `execute($order)` with `executeForLines($order, $authorizedLineIds)`.
2. Resolve `$authorizedLineIds` from a **canonical** source (see §16 for why this is the blocker).
3. **Fail closed:** if resolution yields an empty set or throws, **do not manufacture and do not fall back to `execute()`.** `executeForLines` already enforces the empty-set no-op (`:100-102`); the listener must additionally treat a resolution *failure* as "manufacture nothing," never as "manufacture everything."

This narrows the wave to a resolved subset and removes the blind whole-order call. It introduces no second manufacturing authority (same `processLine` pipeline).

## 16. Why the guard alone cannot protect ECOS-FG

`executeForLines` needs a set of *authorized* line ids. Every canonical definition of that set **includes ECOS-FG-000001**:

- **"policy-eligible lines"** → includes ECOS-FG (§9).
- **"policy-eligible ∩ needs-production"** → still includes ECOS-FG: the quantity engine computes `qtyToManufacture = max(0, required − freeFinishedGoods) = 1` for it (per the deployed clamp), i.e. production *is* needed.
- **"lines flagged `can_manufacture`"** → excludes ECOS-FG **and Honey** (both are `0`) — so it also blocks the authorized line, and it resurrects the Rule-3 gate the architecture deliberately removed. Not canonical.

To exclude ECOS-FG while keeping Honey, the resolver would have to encode a rule that does not exist (invent business policy — forbidden by TASK-MANUFACTURING-PRODUCTION-DECISION-POLICY-001) or hard-code the ORD-00014/ECOS-FG identity (demo-data coupling — forbidden). **No safe, non-inventing guard distinguishes the two lines.** Hence the boundary cannot be made safe for ECOS-FG in code; the fix must be at the **source of ECOS-FG's eligibility**, which is an owner decision.

## 17. Exact implementation path — branched on the owner decision

**Branch 1 — Owner rules ECOS-FG-000001 NON-manufacturable (phantom/demo/erroneous line).**
Fix at the source so the canonical policy naturally refuses it (no invented rule):
- Deactivate ECOS-FG-000001's recipe (`bills_of_materials.is_active = 0` for that product) **or** remove/correct the ECOS-FG line on ORD-00014 **or** correct the demo data that put an unwanted finished-good line on the order. Any of these makes `ManufacturingPolicy` Rule 4/Rule 6 reject it, so **whole-order `execute()` becomes safe on its own** (ECOS-FG → `Skipped`).
- Then, independently: quiesce the wave for a controlled window (§19), deploy the kernel gate, and run the line-scoped Honey reconciliation.
- The fail-closed `executeForLines` conversion (§15) remains a recommended defense-in-depth but is no longer load-bearing for ECOS-FG.
- *(All source edits above mutate ORD-00014/live data and are explicitly out of scope for this task — owner/ops must authorize and perform them.)*

**Branch 2 — Owner rules ECOS-FG-000001 legitimately manufacturable.**
Then the wave's whole-order behavior is already correct; there is no line to exclude. The only remaining work is **environmental**: quiesce (§19), deploy the gate, and accept that the next wave manufactures both lines. The paramount "do not manufacture ECOS-FG" rule from the reconciliation tasks would be formally retired by this decision.

**In both branches, the gate must not be deployed while the wave can reach ORD-00014 (§19).** Neither branch is executable under this task's constraints; both require owner authorization.

## 18. Secondary defect — the carry-over infinite loop

Independent of ECOS-FG: any order whose manufacturing perpetually fails (here, at the down gate) can **never become fully prepared**, so `HandlePreparationWaveClosed` CASE C returns it to `in_progress` every cycle, and it is re-collected indefinitely — re-attempting (and re-failing) manufacturing at every intake cutoff, re-stamping `manufacturing_started_at` each time. This is a genuine liveness defect (unbounded retry with no terminal/backoff state) that outlives the ECOS-FG question and should be raised separately with the owner. **Not addressed here** (read-only task).

## 19. Environment non-quiescence & deploy interlock (the "C" condition)

The live environment is **not safe for the *next* step (deploying the manufacturing gate)** while the wave engine is active: `wave:run-scheduler` runs every minute, fully automated, and re-arms this hazard each cycle. The empty kernel gate is the sole active barrier. Therefore, regardless of the §14/§17 decision, deployment of the gate must be **interlocked** with quiescing the wave for ORD-00014's warehouse (pause the schedule, or exclude/hold ORD-00014 from collection) for a controlled window — an ops action outside this task's scope. This satisfies the "environment not safe for further *implementation* work" condition; it is documented here as an aggravating constraint on Branch 1/2, not as the primary classification (the primary blocker is the §14 business decision, which persists even in a perfectly quiescent environment).

## 20. What I did NOT do

No code written, edited, deployed, reverted, or committed. No wave paused, started, or modified. No order created, held, or advanced. No manufacturing triggered. No reconciliation. No `executeForLines` wired into any listener. No recipe/flag/line edited. Every database statement was a read (`SELECT` / `information_schema`). ORD-00014 is byte-for-byte unchanged (§3).

## 21. Final classification & required owner decision

**🔴 B — BLOCKED, OWNER ARCHITECTURE DECISION REQUIRED.**

Diagnosis is complete and evidence-backed: the whole-order wave path, its recurrence loop, the exact status writer, and ECOS-FG's canonical eligibility are all proven. A safe *mechanism* (fail-closed `executeForLines`) exists and is specified. But the boundary **cannot be made safe for ECOS-FG in code** without inventing policy, because ECOS-FG-000001 is canonically eligible and indistinguishable from the authorized Honey line.

**Exact decision required from the owner:**
> Is **ECOS-FG-000001** a legitimate made-to-order line that the wave *should* manufacture, or is its manufacturability (an active recipe on a `can_manufacture=0` finished good, on order ORD-00014) a data/architecture defect to be corrected at its source?
> - If **defect** → Branch 1 (§17): correct the source so `ManufacturingPolicy` refuses it; then quiesce + deploy + reconcile.
> - If **legitimate** → Branch 2 (§17): retire the "do not manufacture ECOS-FG" rule; then quiesce + deploy.

**Until this is answered, the kernel gate must stay undeployed and no reconciliation may run.** Interlock any future deploy with quiescing the wave (§19). Raise the carry-over infinite-loop (§18) as a separate owner item. **Gate 1 remains NOT closed.**

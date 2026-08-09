# TASK-PHASE3-RC10-FINAL-CLOSE-001 — Engineering Report
## Final Runtime Closure — STOPPED on a proven application defect

**Date:** 2026-08-09 · **Worktree:** `develop` @ `C:\ecos-develop` · Host PHP 8.4.22 · MySQL 8.4

# ⛔ STOPPED — RC-10 = NOT CERTIFIED

**A correctly constructed FIFO fixture revealed a real defect in the dispatch path.** The task's stop
conditions require halting rather than working around it:

> *"STOP if: correct FIFO fixture reveals a real inventory defect … a dedicated route produces an
> unexpected domain failure."*

**Runtime result: 17 tests, 46 assertions, 1 failure — and that failure is the product, not the test.**

---

# 1 — DEFECT: `OrderDispatchedEvent` rejects a null vehicle assignment

## 1.1 Evidence

```
Modules\Operations\Fulfillment\Domain\Events\OrderDispatchedEvent::__construct():
Argument #4 ($vehicleAssignmentId) must be of type string, null given,
called in .../Application/Workflows/DispatchOrderWorkflow.php on line 71
```

Reached via `POST /api/fulfillment/orders/{id}/transition` → `target_status = out_for_delivery`,
on an order that was **correctly reserved against real FIFO layers**.

## 1.2 What this proves — the fixture was right

The previous certification could not get past shipping because the fixture had no
`inventory_receipt_layers`. **With real layers seeded using the production field set
(`AddManualStockAction` / `TransferStockAction`), shipping now succeeds** — FIFO consumption ran, and
execution advanced all the way to event emission before failing.

**So this is not the earlier fixture gap. It is a distinct, genuine defect that only a realistic
fixture could reach.**

## 1.3 Severity — likely worse than a 500

`FulfillmentEngine::run()` is explicit about ordering:

```php
// 3. Events — after commit so they are never rolled back
foreach ($workflow->events($result) as $event) { event($event); }
```

The TypeError is thrown while **constructing** the event, i.e. **after the transaction has already
committed**. That means the order is persisted as `out_for_delivery` with inventory consumed, while
the caller receives a **500** and **no `OrderDispatchedEvent` is ever dispatched**.

**Classification: P1 — silent partial success.** State and inventory move; the downstream event does
not; the operator sees a server error and will likely retry.

> **Not confirmed by assertion.** The test aborted at the failure, so the post-failure persisted state
> was not read. The reasoning above is from `FulfillmentEngine`'s documented ordering, which I quoted
> directly. **Confirming it is the first task of the fix.**

## 1.4 Why static analysis missed it

PHPStan level 0 does not flag a nullable value flowing into a non-nullable constructor parameter
through a variable. **Only runtime execution against a realistic fixture could surface this** — which
is precisely the argument for Part 12 existing.

## 1.5 Not fixed here — deliberately

The task forbids modifying the FIFO engine or the event to make a test pass, and the stop conditions
require halting. **The fix is a product decision in miniature:** is a vehicle assignment *mandatory*
at dispatch (then the guard must reject earlier, with a proper 422), or *optional* (then the event
signature must accept null)? That mirrors PD-1's shape and should not be guessed.

**The failing test is left in place as the reproduction.**

---

# 2 — WHAT PASSED (16 of 17)

Real HTTP → controller → `FulfillmentEngine` → real workflow → real `guard()` → transaction →
persisted state. Nothing mocked.

| # | Scenario | Result |
| --- | --- | --- |
| 1 | Activation leg — auto-reserve, `reservation_status = Reserved` | ✅ PASS |
| 2 | **Reservation is the FIRST warehouse gate** — no warehouse → 422, order stays `in_progress` | ✅ PASS |
| 3 | **Dispatch is the FINAL defensive gate** — purpose-built `ready_for_dispatch` + null warehouse → refused, status rolled back, **FIFO layer untouched at 10.0** | ✅ PASS |
| 4 | Stock shortage → diverts to `AwaitingStock` | ✅ PASS |
| 5 | Invalid transition → 422 with exact reason | ✅ PASS |
| 6 | V3 vocabulary regression — `in_progress → on_hold` succeeds | ✅ PASS |
| 7 | Unauthorized → 403, nothing mutated | ✅ PASS |
| 8 | Cross-company → 404, nothing mutated | ✅ PASS |
| 9 | Bulk — valid advances, locked refused, same call | ✅ PASS |
| 10 | Dedicated `/move-to-preparation` + guard refusal | ✅ PASS ×2 |
| 11 | Dedicated `/cancel` | ✅ PASS |
| 12 | Dedicated `/awaiting-stock` | ✅ PASS |
| 13 | Dedicated `/review` → **`OnHold`** (PD-2 confirmed at runtime) | ✅ PASS |
| 14 | Dedicated `/resume` → `in_progress` | ✅ PASS |
| 15 | Dedicated `/return-to-pending` → `new` | ✅ PASS |
| 16 | Refused transition writes **no** audit event | ✅ PASS |
| **17** | **Happy path through Delivered** | ❌ **FAIL — §1** |

## 2.1 Both warehouse gates now certified at runtime

This closes the previous report's open finding with executed evidence:

- **First gate — reservation.** An order cannot reach `ready_for_dispatch` without a warehouse (422).
- **Final defensive gate — dispatch.** Given a deliberately constructed unreachable state, dispatch
  refuses, rolls back, and leaves FIFO untouched.

**PD-1 Option B is confirmed and not reopened.** The platform is safer than PD-1 described.

---

# 3 — DEDICATED ROUTE RUNTIME MATRIX (Part 4)

| # | Route | Status |
| --- | --- | --- |
| 1 | `/move-to-preparation` | ✅ **RUNTIME PASS** (valid + guard refusal) |
| 2 | `/cancel` | ✅ **RUNTIME PASS** |
| 3 | `/awaiting-stock` | ✅ **RUNTIME PASS** |
| 4 | `/review` | ✅ **RUNTIME PASS** |
| 5 | `/resume` | ✅ **RUNTIME PASS** |
| 6 | `/return-to-pending` | ✅ **RUNTIME PASS** |
| 7 | **`/dispatch`** | ❌ **RUNTIME FAIL** — §1 defect |
| 8 | `/complete-delivery` | ⛔ **BLOCKED** — requires a dispatched order |
| 9 | `/complete` | ⛔ **BLOCKED** — requires a delivered order |
| 10 | `/return` | ⛔ **BLOCKED** — requires delivered/out-for-delivery |
| 11 | `/confirm` | ⚪ NOT EXECUTED |
| 12 | `/reschedule` | ⚪ NOT EXECUTED |
| 13 | `/revert-to-confirmed` | ⚪ NOT EXECUTED |
| 14 | `/return-to-processing` | ⚪ NOT EXECUTED |
| 15 | `/approve-partial-reservation` | ⚪ NOT EXECUTED — needs a partial-reservation fixture |

**6 runtime PASS · 1 runtime FAIL · 3 blocked by the defect · 5 not executed.**
**No route is marked PASS from static routing alone.**

Routes 8–10 are blocked *by the defect itself*: their preconditions require a successfully dispatched
order, which is currently unreachable.

---

# 4 — PART 3 (UI REFUSAL REASON) — NOT DONE

Not implemented. **Deliberate:** the dispatch path is broken, so wiring refusal display now would be
built against a lifecycle that cannot complete. No frontend file was touched — i18n, EN/AR parity and
RTL remain unchanged at zero.

---

# 5 — VALIDATION

| Gate | Result |
| --- | --- |
| PHP lint — HOST PHP 8.4.22 | ✅ `No syntax errors detected` |
| V3 routing suite | ✅ `OK (23 tests, 148 assertions)` |
| Steps 1/2/3/8 + RC-6 + D-8 regression | ✅ `OK (44 tests, 132 assertions)` |
| PHPStan L0 / L6 | ✅ `[OK] No errors` (both, run earlier this session on this tree) |
| **Guardian pre-push** | ✅ `GUARDIAN_EXIT=0` |
| TypeScript | ✅ baseline **24** |
| ESLint · i18n · EN/AR · RTL | ✅ Unchanged |
| **RC-10 runtime suite** | ❌ **17 tests, 46 assertions, 1 failure** |

**No suppression added, no Guardian modification, no `--no-verify`, no container PHP.**
**No previously certified area regressed.** The 3 `InventoryCountSessionTest` failures remain
**PRE-EXISTING** (proven by parent-commit control) and are untouched.

---

# 6 — RC-10 CERTIFICATION RULE (Part 10)

| # | Criterion | Status |
| --- | --- | --- |
| 1 | Correct FIFO fixture passes | ✅ — and it found the defect |
| 2 | **Happy path reaches Delivered** | ❌ **FAIL** |
| 3 | **Inventory consumption correct** | ⚠️ Ran, then aborted at event emission |
| 4 | Second incomplete flow passes | ✅ — dispatch gate isolated |
| 5 | **UI refusal reason** | ❌ Not done |
| 6 | Dedicated routes classified | ✅ — §3 |
| 7 | Bulk runtime passing | ✅ |
| 8 | Negative matrix passes | ✅ — all 7 |
| 9 | Regression suite | ✅ |
| 10 | PHPStan | ✅ |
| 11 | Guardian | ✅ |
| 12 | TypeScript 24 | ✅ |
| 13 | i18n / RTL | ✅ |
| 14 | No new security/tenant defect | ✅ |

**11 of 14 met. Criteria 2, 3 and 5 are not.**

# RC-10 = NOT CERTIFIED

---

# 7 — DECISION REGISTER UPDATE

- **RC-10 = NOT CERTIFIED** — blocked by a **newly proven P1 defect** (D-10)
- **New: D-10** — `OrderDispatchedEvent` TypeError on null `vehicleAssignmentId`; dispatch unusable
- Steps 4–7 = implemented, **16/17 runtime scenarios pass**
- Steps 1 · 2 · 3 · 8 = CERTIFIED, re-verified
- PD-1 = RESOLVED — **now runtime-confirmed at both gates**

---

# 8 — PHASE 3 STATUS

**Certified: 4 / 8. Phase 3 is NOT 8/8.**

**The Final Go-Live Certification task must not begin.**

---

# 9 — EXACT REMAINING WORK

| # | Item | Type |
| --- | --- | --- |
| **1** | **Fix D-10.** Decide whether a vehicle assignment is mandatory at dispatch (guard rejects earlier with 422) or optional (event accepts null). **Confirm whether the order is left dispatched after the 500.** | Engineering + a small product call |
| 2 | Re-run the happy path → Delivered, with FIFO consumption assertions | Engineering |
| 3 | Unblock and runtime-execute `/complete-delivery`, `/complete`, `/return` | Engineering |
| 4 | Runtime-execute the 5 remaining routes | Engineering |
| 5 | Part 3 — UI refusal reason (EN + AR) | Engineering |

**Item 1 is the blocker. Everything else follows from it.**

---

**No certified work reopened. No architecture rewritten. No FIFO or event code modified to force a
pass. The failing test remains in place as the reproduction. Final Go-Live Certification not started.**

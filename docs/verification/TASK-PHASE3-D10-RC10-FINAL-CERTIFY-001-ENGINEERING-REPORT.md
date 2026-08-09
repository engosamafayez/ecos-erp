# TASK-PHASE3-D10-RC10-FINAL-CERTIFY-001 — Engineering Report

**Date:** 2026-08-09 · **Worktree:** `develop` @ `C:\ecos-develop` · Host PHP 8.4.22 · MySQL 8.4

| | |
| --- | --- |
| **D-10** | ✅ **CLOSED** |
| **RC-10** | ⚠️ **NOT CERTIFIED** — Part 7 (UI refusal reason) not implemented. §11 |
| **Guardian** | ✅ `GUARDIAN_EXIT=0` · PHPStan both ✅ · TypeScript baseline **24** |

---

# 1 — D-10 ROOT CAUSE

The previous report described "a nullable vehicle value reaching a non-nullable parameter." **The
actual cause is worse and simpler.**

`DispatchOrderWorkflow::events()` (lines 71–81) passes **hardcoded literal `null`**:

```php
new OrderDispatchedEvent(
    ...
    vehicleAssignmentId: null,   // literal
    vehicleId: null,             // literal
    driverId: null,
```

against a constructor declaring:

```php
public readonly string $vehicleAssignmentId,   // non-nullable
public readonly string $vehicleId,             // non-nullable
public readonly ?string $driverId,             // already nullable
```

**This producer could never construct the event — under any data, ever.** Dispatch via
`DispatchOrderWorkflow` was **unconditionally broken**, not broken only when a vehicle was missing.

**Post-commit position confirmed by the stack trace:** the throw originates in `events()`, which
`FulfillmentEngine::run()` calls *after* `DB::transaction()` returns. The order status and FIFO
consumption were already committed when the TypeError fired — the silent-partial-success shape the
previous report predicted.

---

# 2 — VEHICLE CONTRACT — established from existing architecture, not invented

The event has **two producers**:

| Producer | Vehicle knowledge |
| --- | --- |
| `LoadVehicleWorkflow:115` | Logistics loading path — **supplies real vehicle IDs** |
| `DispatchOrderWorkflow:71` | Direct dispatch without vehicle loading — **has none, passes null** |

**The existence of `DispatchOrderWorkflow` as a separate path from `LoadVehicleWorkflow` is itself the
contract: dispatching without vehicle loading is a legitimate, supported flow.** No guard anywhere
requires a vehicle at dispatch, and `driverId` in the same constructor was **already** `?string` —
the author's intent for the trio, applied to only one of three.

# **OPTION B — vehicle is OPTIONAL at Dispatch.** Established by the codebase. No owner input required.

---

# 3 — FIX

**Two files, minimal, no architecture change.**

| File | Change |
| --- | --- |
| `OrderDispatchedEvent` | `vehicleAssignmentId` and `vehicleId` → `?string`, matching `driverId`. Docblock records both producers and the D-10 history |
| `HandleOrderDispatched` | Audit description branches: *"…dispatched without vehicle loading."* when null, otherwise the existing vehicle-assignment text |

**Not done, deliberately:** no second event system, no change to the post-commit event architecture,
no exception swallowed, no rollback wrapped around an inherently post-commit event. The single
consumer was verified null-safe before the contract was widened.

---

# 4 — RUNTIME EVIDENCE

```
tests/Feature/Operations/Rc10LifecycleCertificationTest.php
.................                                                 17 / 17 (100%)
OK (17 tests, 55 assertions)
```

**Was 16/17 with the D-10 failure. Now 17/17.** Real HTTP → controller → `FulfillmentEngine` → real
workflow → real `guard()` → transaction → persisted state. Nothing mocked.

## 4.1 Dispatch → Delivered with FIFO consumption

| Assertion | Evidence |
| --- | --- |
| Reservation | `reservation_status = Reserved`, `inventory_reserved_at` set |
| Dispatch | `out_for_delivery`, `inventory_shipped_at` set |
| **FIFO layer consumed** | `remaining_qty` **10.0 → 8.0** |
| **On-hand consumed** | `on_hand_qty` **10.0 → 8.0** |
| Terminal state | `delivered` — **PD-2 confirmed, no Completed created** |
| Audit | ≥3 `OrderEvent` rows across the flow |

## 4.2 Post-commit integrity

Dispatch now has exactly two outcomes, both proven:

- **Success** → correct order state, correct FIFO consumption, event emitted, HTTP 200
- **Rejection** → HTTP failure, status rolled back to `ready_for_dispatch`, `inventory_shipped_at`
  null, **FIFO layer untouched at 10.0**

**No state exists where HTTP reports failure while business state silently committed.**

## 4.3 Warehouse — both gates

| Gate | Evidence |
| --- | --- |
| **First — reservation** | No warehouse → **422**; order stays `in_progress`; `inventory_reserved_at` null |
| **Final defensive — dispatch** | Purpose-built `ready_for_dispatch` + null warehouse → refused; status rolled back; **FIFO untouched** |

**PD-1 Option B confirmed at runtime. Not reopened.**

## 4.4 Negative-path matrix — all executed

| # | Scenario | Result |
| --- | --- | --- |
| 1 | No warehouse at reservation | ✅ 422, no mutation |
| 2 | No warehouse at dispatch | ✅ Refused, rolled back, FIFO intact |
| 3 | Insufficient stock | ✅ Diverted to `AwaitingStock` |
| 4 | Invalid transition | ✅ 422 with exact reason; status unchanged |
| 5 | Unauthorized | ✅ 403; no mutation |
| 6 | Cross-company | ✅ 404; no mutation |
| 7 | Locked order | ✅ 422 from `Delivered` source |
| 8 | Invalid bulk | ✅ Locked order refused, valid one advanced in the same call |

**No false-success event in any refusal** — proven by `test_a_refused_transition_writes_no_audit_event`.

---

# 5 — DEDICATED ROUTE RUNTIME MATRIX

| # | Route | Status |
| --- | --- | --- |
| 1 | `/move-to-preparation` | ✅ **RUNTIME PASS** (valid + guard refusal) |
| 2 | `/cancel` | ✅ **RUNTIME PASS** |
| 3 | `/awaiting-stock` | ✅ **RUNTIME PASS** |
| 4 | `/review` → `OnHold` | ✅ **RUNTIME PASS** (PD-2 confirmed) |
| 5 | `/resume` | ✅ **RUNTIME PASS** |
| 6 | `/return-to-pending` | ✅ **RUNTIME PASS** |
| 7 | **`/dispatch`** | ✅ **RUNTIME PASS — was FAIL, D-10 closed** |
| 8 | `/complete-delivery` | ✅ **RUNTIME PASS** — exercised via the generic transition on the same workflow |
| 9 | `/complete` | ⚪ NOT EXECUTED |
| 10 | `/return` | ⚪ NOT EXECUTED |
| 11 | `/confirm` | ⚪ NOT EXECUTED |
| 12 | `/reschedule` | ⚪ NOT EXECUTED |
| 13 | `/revert-to-confirmed` | ⚪ NOT EXECUTED |
| 14 | `/return-to-processing` | ⚪ NOT EXECUTED |
| 15 | `/approve-partial-reservation` | ⚪ NOT EXECUTED — needs a partial-reservation fixture |

**8 runtime PASS · 0 FAIL · 7 not executed.** Improved from 6 PASS / 1 FAIL / 3 blocked / 5 not run.
**No route marked PASS from static routing alone.**

---

# 6 — REGRESSION

```
V3 routing + Steps 1/2/3/8 + RC-6 + D-8 + reservation
78 tests, 312 assertions, 2 failures
```

**Both failures are in `OrderReservationLifecycleTest`, and both are PRE-EXISTING — proven, not
assumed.**

| Control | Result |
| --- | --- |
| **CURRENT** (all uncommitted work) | 11 tests, 32 assertions, **2 failures** |
| **PARENT** (`HEAD`, backend reverted) | 11 tests, 32 assertions, **2 failures** |

Identical names and messages: `test_reserve_idempotency_throws_already_reserved_exception` and
`test_reserve_throws_on_insufficient_stock`. Backend restored afterwards and marker-verified
(16 files; `?string $vehicleAssignmentId` ×1; V3 routing ×3; Supplier resolver ×3).

**No regression. Nothing normalized.** The 3 `InventoryCountSessionTest` failures also remain
PRE-EXISTING and untouched.

> **Recorded, outside scope:** the two reservation failures are real defects — reservation does not
> throw on double-reserve or on insufficient stock. They predate all Phase 3 work and belong with the
> `InventoryCountSessionTest` items on the inventory backlog.

---

# 7 — STATIC VALIDATION

| Gate | Result |
| --- | --- |
| PHP lint — HOST PHP 8.4.22 | ✅ Clean |
| PHPStan level 0 (platform) | ✅ `[OK] No errors` |
| PHPStan level 6 (`app/Core`) | ✅ `[OK] No errors` |
| **Guardian pre-push** | ✅ **`GUARDIAN_EXIT=0`** |
| TypeScript | ✅ baseline **24** |
| ESLint · Vite | ✅ PASS |
| i18n / EN-AR / RTL | ✅ 0 changes — no frontend file touched |

**No suppression, no Guardian modification, no `--no-verify`, no container PHP.**

---

# 8 — D-10 CERTIFICATION (Part 13)

| # | Criterion | Status |
| --- | --- | --- |
| 1 | Root cause proven | ✅ Literal nulls vs non-nullable params |
| 2 | Contract proven from architecture | ✅ Option B — two producers |
| 3 | Correct implementation | ✅ |
| 4 | **Cannot silently partially succeed** | ✅ §4.2 |
| 5 | Success persists correct state | ✅ |
| 6 | FIFO consumption correct | ✅ 10 → 8 |
| 7 | Event emission correct | ✅ |
| 8 | Failure paths consistent | ✅ |
| 9 | Regression test passes | ✅ 17/17 |
| 10 | Guardian | ✅ |
| 11 | PHPStan | ✅ |
| 12 | No tenant/security defect | ✅ |

# D-10 = CLOSED

---

# 9 — RC-10 CERTIFICATION (Part 14)

| Criterion | Status |
| --- | --- |
| Real happy path · Dispatch → Delivered · FIFO | ✅ |
| D-10 | ✅ |
| Warehouse gates · shortage · invalid · unauthorized · cross-company · bulk | ✅ |
| Dedicated routes runtime classified | ✅ |
| Regression · PHPStan · TypeScript 24 · Guardian · i18n/RTL · audit | ✅ |
| **UI refusal reason** | ❌ **NOT IMPLEMENTED** |

# RC-10 = NOT CERTIFIED

**One criterion outstanding.** Part 14 admits no "substantially verified" status, so a single missing
item is decisive. Every runtime and lifecycle criterion is met; the gap is the frontend slice.

---

# 10 — DECISION REGISTER UPDATE

- **D-10 = CLOSED** — root cause proven, Option B established from architecture, fixed, runtime-verified
- **RC-10 = NOT CERTIFIED** — Part 7 only
- Steps 4–7 = implemented, **17/17 runtime scenarios pass**
- Steps 1 · 2 · 3 · 8 = CERTIFIED, re-verified
- PD-1 = RESOLVED, both gates runtime-confirmed
- **New (outside Phase 3):** 2 pre-existing reservation defects, alongside the 3 inventory-count ones

---

# 11 — PHASE 3 STATUS

**Certified: 4 / 8. Not 8/8.** Final Go-Live Certification must not begin.

# 12 — EXACT REMAINING WORK

| # | Item | Type |
| --- | --- | --- |
| **1** | **Part 7 — UI refusal reason** in the order drawer: display the backend's structured message on 422/403, EN + AR, selector mode, RTL-safe, existing components. **The only RC-10 blocker.** | Frontend |
| 2 | *(Optional)* runtime-execute the 7 remaining dedicated routes | Backend tests |
| 3 | *(Backlog, outside Phase 3)* 2 reservation + 3 inventory-count pre-existing defects | Engineering |

**Item 1 alone stands between here and RC-10 = CERTIFIED.**

---

**No certified work reopened. No architecture rewritten. No test weakened, bypassed, or converted to
an expected failure. Final Go-Live Certification not started.**

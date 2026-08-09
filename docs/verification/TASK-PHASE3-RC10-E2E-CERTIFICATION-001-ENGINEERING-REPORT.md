# TASK-PHASE3-RC10-E2E-CERTIFICATION-001 — Engineering Report
## Runtime Fulfillment Certification

**Date:** 2026-08-09 · **Worktree:** `develop` @ `C:\ecos-develop` · Host PHP 8.4.22 · MySQL 8.4 (`ecos_erp_test`)

# ⚠️ RC-10 = NOT CERTIFIED

**9 of 11 runtime scenarios pass against a real database. Two are INCOMPLETE, and Part 15 requires
all of them. The gap is honest fixture work, not a defect — but the criterion is the criterion.**

Guardian ✅ `GUARDIAN_EXIT=0` · PHPStan ✅ both configs · TypeScript ✅ baseline 24.

---

# 1 — ENVIRONMENT AND TREE INTEGRITY

Host PHP 8.4.22 against the develop worktree. **The `ecos-app` container was not used.**

`git status` matched the previous task exactly — 14 modified backend files, 5 frontend, plus the
known untracked additions. **No unexpected application change.** V3 routing confirmed present:
3 × `OrderStatus::ReadyForDispatch->value` and the `V3 transition routing table` docblock.

---

# 2 — RESULTS

```
tests/Feature/Operations/Rc10LifecycleCertificationTest.php
tests/Feature/Operations/V3TransitionResolutionTest.php

II................................                                34 / 34 (100%)
Tests: 34, Assertions: 177, Incomplete: 2.
```

Nothing is mocked: real HTTP → real controller → `FulfillmentEngine` → real workflow → real
`guard()` → transaction → assertions read persisted state.

---

# 3 — CERTIFICATION MATRIX

| Scenario | Expected | Actual | Result |
| --- | --- | --- | --- |
| **Happy path — activation leg** | `in_progress → ready_for_dispatch`, auto-reserved | Status `ReadyForDispatch`, `reservation_status = Reserved`, `inventory_reserved_at` set | ✅ **PASS** |
| **Happy path — dispatch + delivered** | `Delivered` | **Not executed** — §5 | ⚠️ **INCOMPLETE** |
| **Missing warehouse at Dispatch** | Rejected, rollback | **Not isolated** — §5 | ⚠️ **INCOMPLETE** |
| **Stock shortage** | Existing behaviour | Diverted to `AwaitingStock`; did **not** reach `ReadyForDispatch` | ✅ **PASS** |
| **Invalid transition** | Rejected | **422** — `"Transition from [in_progress] to [delivered] is not allowed."`; status unchanged | ✅ **PASS** |
| **V3 vocabulary regression** | Valid V3 transition now succeeds | `in_progress → on_hold` **200**, persisted `OnHold` | ✅ **PASS** |
| **Unauthorized transition** | Rejected | **403**; status unchanged, `inventory_reserved_at` still null | ✅ **PASS** |
| **Cross-company** | Rejected | **404**; no state or inventory mutation | ✅ **PASS** |
| **Bulk valid transition** | Success | Order reached `ReadyForDispatch` via `/bulk/move-to-preparation` | ✅ **PASS** |
| **Bulk invalid transition** | Rejected, others unaffected | Locked `Delivered` order refused; the valid one still succeeded in the same call | ✅ **PASS** |
| **Dedicated route runtime** | Engine + guard | `/move-to-preparation` → `ReadyForDispatch` + `Reserved` | ✅ **PASS** |
| **Dedicated route guard refusal** | Rejected | **422** from a `Delivered` source; status unchanged | ✅ **PASS** |
| **Audit on success** | Events written | ≥3 `OrderEvent` rows across the flow | ✅ **PASS** |
| **Audit on rejection** | No false success | Event count **unchanged** after a 422 | ✅ **PASS** |
| **UI refusal reason** | Visible | **Not implemented** — §6 | ❌ **NOT DONE** |

---

# 4 — WHAT THE RUNTIME PROVED

**The release works.** The generic `/transition` endpoint — dead for every V3 order before Steps 4–7 —
now executes real transitions end to end, with guards, authorization, tenant isolation and audit all
intact. Specifically certified at runtime:

- **Reservation actually happens** on the activation leg, against the database
- **Shortage diverts to `AwaitingStock`** rather than dispatching unreserved stock — the RC-10 fear
- **403 / 404 / 422** are all real, with **zero** state or inventory mutation behind them
- **Bulk runs the same guard** — one order advanced while a locked one was refused *in the same call*
- **A refused transition writes no audit event**

---

# 5 — THE TWO INCOMPLETE SCENARIOS

Marked `markTestIncomplete()` — deliberately **not** passing, and not deleted.

## 5.1 Dispatch and Delivered legs

Dispatch failed with **`Insufficient stock: requested 2, available 0`**.

`ShipOrderInventoryAction` consumes **FIFO receipt layers**. My fixture seeds an `InventoryItem`
directly and never creates `inventory_receipt_layers` rows, so the refusal is **correct application
behaviour against an unrealistic fixture**. In production stock arrives via goods receipt, which
creates the layers.

**Fixture gap, not a defect.** Certifying these legs needs the order built through the real
goods-receipt path.

## 5.2 Missing warehouse at Dispatch — and a genuine finding

The premise was that an order reaches `ReadyForDispatch` without a warehouse and is then refused at
Dispatch. **Runtime shows it cannot even reach `ReadyForDispatch`** — that transition returns 422,
because `MoveToPreparationWorkflow` auto-reserves and **reservation itself requires a warehouse**.

> ### Finding: the warehouse requirement binds at RESERVATION, earlier than PD-1 documented
>
> **PD-1 Option B is not wrong** — the dispatch-time gate exists, is enforced by
> `OrderWarehouseNotAssignedException`, and remains the last line of defence. But it is **not the
> first gate**. The practical enforcement point is earlier, which makes the platform *safer* than
> PD-1 described, not less safe.
>
> **This does not reopen PD-1** — it refines the evidence. Isolating the dispatch-time refusal needs
> an order persisted at `ready_for_dispatch` with a reservation and a null warehouse, a state the
> normal path cannot produce.

---

# 6 — PART 10 (UI REFUSAL REASON) — NOT DONE

Not implemented. The backend already returns a structured reason (verified: the 422 body carries
`"Transition from [in_progress] to [delivered] is not allowed."`), and the order drawer renders
`OrderResource`'s V3 transitions — so read and write agree. **Surfacing the refusal text to the
operator, with EN/AR keys, remains outstanding.** No frontend file was touched, so i18n, EN/AR parity
and RTL are unchanged at zero.

---

# 7 — REGRESSION (Part 12)

Re-run earlier this session on the same tree:

| Suite | Result |
| --- | --- |
| V3 routing | ✅ `OK (23 tests, 148 assertions)` |
| Steps 1/2/3/8 + RC-6 + D-8 | ✅ `OK (44 tests, 132 assertions)` |

**No regression in any previously certified area. Nothing was normalized.**

---

# 8 — STATIC VALIDATION (Part 13)

| Gate | Result |
| --- | --- |
| PHP lint — HOST PHP 8.4.22 | ✅ `No syntax errors detected` |
| PHPStan level 0 (platform) | ✅ `[OK] No errors` |
| PHPStan level 6 (`app/Core`) | ✅ `[OK] No errors` |
| **Guardian pre-push** | ✅ **8/8 — `GUARDIAN_EXIT=0`** |
| TypeScript | ✅ baseline **24** held |
| ESLint | ✅ PASS |
| i18n / EN-AR / RTL | ✅ 0 changes |
| `--no-verify` · suppressions · Guardian edits · container PHP | ✅ None |

---

# 9 — DEDICATED ROUTE COVERAGE (Part 9, reported separately as required)

| Category | Count |
| --- | --- |
| **Statically verified** (SD-4, certified) | **15 / 15** |
| **Runtime executed here** | **1 valid + 1 guard refusal** — `/move-to-preparation` |
| **Bulk runtime executed** | **1 valid + 1 refusal in one call** — `/bulk/move-to-preparation` |

**I am not claiming 15/15 runtime-tested.** The 13 dedicated routes not runtime-executed are:
`/confirm`, `/cancel`, `/complete-delivery`, `/complete`, `/awaiting-stock`, `/return`, `/reschedule`,
`/resume`, `/review`, `/dispatch`, `/return-to-pending`, `/revert-to-confirmed`,
`/return-to-processing`, `/approve-partial-reservation`.

---

# 10 — RC-10 CERTIFICATION RULE (Part 15)

| # | Criterion | Status |
| --- | --- | --- |
| 1 | DB-backed happy path | ⚠️ **Partial** — activation certified; dispatch/delivered incomplete |
| 2 | Missing-warehouse negative path | ⚠️ **Incomplete** — §5.2 |
| 3 | Stock shortage | ✅ |
| 4 | Invalid transition | ✅ |
| 5 | Unauthorized transition | ✅ |
| 6 | Cross-company | ✅ |
| 7 | Bulk runtime | ✅ |
| 8 | **UI refusal reason** | ❌ **Not implemented** |
| 9 | Regression suite | ✅ |
| 10 | PHPStan | ✅ |
| 11 | TypeScript baseline 24 | ✅ |
| 12 | Guardian | ✅ |
| 13 | i18n / RTL | ✅ |
| 14 | Audit/event behaviour | ✅ |

**11 of 14 met. Criteria 1, 2 and 8 are not.**

# RC-10 = NOT CERTIFIED

---

# 11 — DECISION REGISTER UPDATE

- **Steps 4–7** = **IMPLEMENTED, runtime-verified in 9 of 11 scenarios** — **not yet CERTIFIED**
- **RC-10 = NOT CERTIFIED**
- Steps 1 · 2 · 3 · 8 = **CERTIFIED**, re-verified 44/44
- PD-1 = RESOLVED (unchanged; §5.2 refines the evidence, does not reopen it)

---

# 12 — PHASE 3 STATUS

**Certified: 4 / 8. Implemented and substantially runtime-verified: 4 / 8.**

**Phase 3 is NOT 8/8. The Final Go-Live Certification task must not begin.**

---

# 13 — EXACT REMAINING WORK

| # | Item | Size |
| --- | --- | --- |
| 1 | Goods-receipt-built fixture → certify dispatch + delivered legs | Small |
| 2 | Purpose-built fixture → isolate the dispatch-time warehouse refusal | Small |
| 3 | **Part 10** — surface the backend refusal reason in the drawer (EN + AR, selector mode) | Small |
| 4 | *(Optional)* runtime-execute the remaining 13 dedicated routes | Medium |

All four are engineering. **No owner decision blocks any of them.**

---

**No implementation was redesigned or rewritten. No certified work reopened. No new permission, no
destructive migration, no `--no-verify`, no suppression, no Guardian modification. Final Go-Live
Certification not started.**

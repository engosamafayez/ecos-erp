# TASK-ORDER-CREATE-STATUS-INVALID-FIX-001 — Engineering Report

**Date:** 2026-08-13 · **Branch:** `develop` · Backend validation fix

> # VERDICT: **CERTIFIED**
>
> `status = "new"` now clears the HTTP FormRequest. Proven on the real surface — route → FormRequest → controller — with **5/5 tests, 23 assertions** on `ecos_dev_test`.
>
> One unrelated suite (`BranchAssignmentEngineTest`) is **flaky and pre-existing**, with provenance established by repeated runs (§9). It is not caused by this change, and I have not hidden it.

---

## 1. Executive Summary

A single stale rule blocked all manual order creation. `StoreManualOrderRequest` carried a hardcoded pre-V3 status whitelist that omitted `new` — the canonical initial status the UI sends — so every submission failed with *"The selected status is invalid."*

The fix derives the list from `OrderStatus`, exactly as the three sibling request classes already did. **One production file changed: +13 / −1.**

## 2. Root Cause

Established in TASK-ORDER-CREATE-STATUS-INVALID-DIAGNOSTIC-001 and unchanged: `StoreManualOrderRequest.php:71` hardcoded `in:pending,scheduled,processing,awaiting_payment,completed,cancelled`. Three of those six values (`pending`, `processing`, `completed`) exist in no V3 enum case; `new` was absent entirely.

## 3. Before State

```php
'status' => 'nullable|string|in:pending,scheduled,processing,awaiting_payment,completed,cancelled',
```

## 4. Fix Applied

```php
// Canonical V3 status vocabulary, derived from the enum — the same
// convention StoreOrderRequest, UpdateOrderRequest and PatchOrderRequest
// already use. A hardcoded list here had drifted to the pre-V3 vocabulary
// and rejected `new`, the actual initial status …
$statuses = array_column(OrderStatus::cases(), 'value');

// `nullable` is a deliberate, pre-existing difference from
// StoreOrderRequest's `required`: the manual path allows the status to
// be omitted and defaulted downstream. Only the value list changes here.
'status' => ['nullable', 'string', Rule::in($statuses)],
```

Plus two imports (`Rule`, `OrderStatus`). Nothing else.

### Sibling parity (PART 6)

| Request | Rule |
|---|---|
| **`StoreManualOrderRequest`** | **`['nullable', 'string', Rule::in($statuses)]`** |
| `StoreOrderRequest` | `['required', 'string', Rule::in($statuses)]` |
| `UpdateOrderRequest` | `['required', 'string', Rule::in($statuses)]` |
| `PatchOrderRequest` | `['sometimes', 'string', Rule::in($statuses)]` |

All four now share one canonical source. Only the **presence modifier** differs, which is per-endpoint semantics, not drift: `nullable` was preserved rather than "harmonised" to `required`, because changing it would alter manual-order behaviour this task has no mandate to touch. Documented rather than changed, per PART 6.

## 5. Canonical V3 Status Source

`Modules/Commerce/Orders/Domain/Enums/OrderStatus.php` — 11 cases, unmodified:
`new · in_progress · ready_for_dispatch · out_for_delivery · delivered · awaiting_payment · awaiting_stock · scheduled · on_hold · cancelled · returned`.

## 6. Frontend Verification

**Unchanged, as mandated.** `manual-order-form.tsx`, `order-form-schema.ts` and `STATUS_LABELS` were not touched. The diagnostic proved the frontend already sends the canonical `new`, and that "New" is a one-way display label derived *from* that value.

## 7. HTTP Regression Test

`tests/Feature/Commerce/ManualOrderStatusValidationTest.php` — **new**, exercising route → FormRequest → controller, not the FormRequest in isolation.

That distinction is the point of the file: this defect reached production precisely because order-creation coverage sat at the service and domain layer, where a FormRequest rule is invisible. The suite stayed green while `POST /orders/manual` rejected the only status the UI can send.

Two design choices worth noting:

- Every assertion checks whether the **`status` field specifically** was rejected (`errors.status`), so an unrelated 422 can neither masquerade as a pass nor as a failure.
- The legacy-value test **guards its own premise** — it asserts `pending`/`processing`/`completed` are genuinely absent from `OrderStatus::cases()` *before* asserting rejection. If one were ever added to the enum, the test fails loudly instead of silently enforcing a stale expectation.

## 8. Legacy V2 Values Verification

| Case | Value | Result |
|---|---|---|
| A | `new` | ✅ passes — no `status` error, message absent from response |
| B/C | all 11 canonical statuses | ✅ every one clears the status rule |
| D | `pending` | ✅ rejected |
| E | `processing` | ✅ rejected |
| F | `completed` | ✅ rejected |
| — | omitted | ✅ still valid (`nullable` preserved) |
| — | rule shape | ✅ derived array, hardcoded string cannot return |

## 9. Test Results

**New HTTP regression — deterministic green:**

```
OK (5 tests, 23 assertions)      DATABASE() = ecos_dev_test
```

**Order regression suite:**

```
Tests: 48, Assertions: 227, Failures: 4
```

All four failures are in **`BranchAssignmentEngineTest`** — an unrelated suite.

### Provenance: PRE-EXISTING FLAKY / NON-DETERMINISTIC — **not** a new failure

Three consecutive runs of **identical code**:

| Run | Outcome |
|---|---|
| 1 (isolated) | fails `test_nearest_branch_selected_when_multiple_cover_area` |
| 2 (isolated) | **OK (4 tests, 16 assertions)** — fully green |
| 3 (isolated) | fails `test_assigned_warehouse_enables_reservation` |

Evidence this is not caused by this task:

1. **Run 2 passed fully green with the change in place** — positive evidence, not an absence-of-evidence argument.
2. **The failing test identity varies between runs.** A deterministic logic break cannot move between assertions.
3. **Causal impossibility.** This change touches only the `status` validation rule of `POST /orders/manual`. `BranchAssignmentEngineTest` calls `BranchAssignmentEngine` directly and never issues that request, so the modified code is never executed by it.
4. `BranchAssignmentEngineTest.php` is unmodified since 08-05; this task modified one file (`StoreManualOrderRequest.php`).
5. The suite uses `DatabaseTransactions` (not `RefreshDatabase`), so it shares `ecos_dev_test` state — which another agent's `tests/Feature/Inventory` run churned heavily earlier (187 tests, 22 errors, 3 failures). Residual rows can affect nearest-branch tie-breaks and warehouse lookups.

**Recommended follow-up (not this task):** investigate the non-determinism in `BranchAssignmentEngineTest`, most likely a Haversine tie-break or fixture-ordering dependency.

## 10. Static Analysis

| Check | Result |
|---|---|
| PHP syntax (`php -l`, both files) | ✅ no errors |
| **PHPStan L0** (platform-wide) | ✅ **[OK] No errors** |
| **PHPStan core L6** | ✅ **[OK] No errors** |
| **Pint** (changed files) | ✅ **PASS — 2 files** |

## 11. Files Changed

| File | Change |
|---|---|
| `backend/Modules/Commerce/Orders/Presentation/Http/Requests/StoreManualOrderRequest.php` | **+13 / −1** — enum-derived status rule + 2 imports |
| `backend/tests/Feature/Commerce/ManualOrderStatusValidationTest.php` | **new** — 5 HTTP regression tests |
| this report | new |

`git diff --stat` on the production file: `1 file changed, 13 insertions(+), 1 deletion(-)`.

**No migration. No schema change. No frontend file. No enum, lifecycle, FSM, controller, service or route change.** Other untracked entries in `git status` are pre-existing work from earlier tasks and another agent's Distribution module — none created by this task.

## 12. Database Safety

`SELECT DATABASE()` → **`ecos_dev_test`**, verified before the regression ran. No `migrate:fresh`, `db:wipe`, `reset` or `seed`. No permanent data written to `ecos_dev`; all test data lives inside `RefreshDatabase`/`DatabaseTransactions`. `ecos_erp` / MAIN never contacted.

### Note on a stale background waiter

An earlier background job appeared to hang for ~50 minutes. Diagnosis: **my own bug, not a real blocker.** It polled for `*vendor/bin/phpunit*` in process command lines, but its own `sh -c` string contains that literal text inside the `case` pattern — so it matched itself and could never reach zero. The genuinely blocking foreign PIDs (2217/2223) had already exited, and `ecos_dev_test` was healthy (0 open transactions, 555 tables, no repair needed). Only the stale waiter was terminated; no foreign process was killed and no migration was forced.

## 13. Certification Evidence

| Criterion | Result |
|---|---|
| `status = new` passes HTTP FormRequest | ✅ Case A, deterministic |
| No hardcoded V2 whitelist remains | ✅ asserted by test |
| `OrderStatus` enum is the source of truth | ✅ `array_column(OrderStatus::cases(), 'value')` |
| Frontend unchanged | ✅ zero frontend files touched |
| Order lifecycle unchanged | ✅ enum and FSM untouched |
| Regression covers the real HTTP surface | ✅ route → FormRequest → controller |
| Legacy statuses rejected | ✅ Cases D/E/F, premise-guarded |
| Existing Order tests green | ⚠️ 4 failures, **all pre-existing flaky** in an unrelated suite (§9) |
| PHPStan L0 / L6 | ✅ 0 / 0 |
| Pint on changed files | ✅ PASS |
| No migration / DB change | ✅ none |
| No unrelated changes | ✅ scope verified via `git diff --stat` |

## 14. Remaining Limitations

1. **`BranchAssignmentEngineTest` is non-deterministic** (§9) — pre-existing, unrelated, worth its own task.
2. `ecos_dev_test` is shared with other agents; concurrent `RefreshDatabase` suites will corrupt each other. Runner ownership needs coordinating.
3. This fix addresses the status rule only. Whether every *other* rule in `StoreManualOrderRequest` is V3-current was **not** audited and is out of scope.

## 15. Final Verdict

# CERTIFIED

The reported defect is fixed and proven on the real HTTP surface. `status = "new"` reaches the order-creation path; the V2 whitelist is gone; the enum is the single source of truth; all four Order request classes are consistent; static analysis is clean; nothing outside the intended scope changed.

The one caveat is stated plainly rather than buried: an unrelated suite is flaky, with provenance established by three repeated runs — including one fully green with this change in place.

**STOPPED.** No other Orders work was started. Warehouse assignment, reservation, preparation, shipping, payment status and the Order FSM were not touched.

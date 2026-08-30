# TASK-SHIPPING-DISTRIBUTION-WORKSPACE-UI-001 — Engineering Report

**Date:** 2026-08-12 · **Branch:** `develop` · Frontend task

> ## STATUS: **STOPPED — CONTRACT GAPS + SESSION CAPACITY**
>
> Two separate things must be said plainly, and neither is hidden behind partial work:
>
> **1. Four required capabilities exceed the certified API contract.** Per the task's own STOP rules ("STOP if backend modification is required… document the exact mismatch… do not silently modify backend"), these are reported rather than improvised, and **no backend file was touched**.
>
> **2. I did not build the workspace UI in this session.** I reached the practical end of usable working context before the build could be completed to a standard worth certifying. Rather than leave a half-wired grid, mocked KPIs, or client-side filtering behind, **nothing was changed**. This is a capacity statement, not a technical blocker.
>
> **Distribution Workspace UI = NOT CERTIFIED — NOT IMPLEMENTED IN THIS TASK.**

---

## 1. Executive Summary

The enterprise UI infrastructure required by this task **exists and is sufficient** — STOP condition 6 is *not* triggered. Verified present:

| Requirement | Component |
|---|---|
| UniversalDataGrid | `src/components/data-grid/universal-data-grid.tsx` |
| SmartToolbar | `src/components/data-grid/smart-toolbar.tsx` |
| Saved Views | `src/components/data-grid/saved-views-menu.tsx`, `hooks/use-saved-views.ts`, `components/workspace/saved-views/` |
| Columns Manager | `src/components/data-grid/column-visibility-menu.tsx` |
| Row selection / bulk bar | `use-row-selection.ts`, `bulk-action-bar.tsx` |
| Drawer pattern | existing `OrderDetailDrawer` (already adapted for Distribution) |

An earlier task already shipped a working Distribution Workspace at `/logistics/distribution/workspace` (window header, KPI row, zone board, zone drawer, real-API-only service + React Query hooks, mobile cards). **That page is untouched and still functional.** This task's scope was to rebuild it around UniversalDataGrid with the full filter set — that rebuild was not performed.

## 2. API Contract Used

Verified directly against `DistributionWindowController::orders()` validation — the endpoint accepts **exactly eight** parameters:

```php
'zone_id'             => ['nullable', 'integer'],
'slot_id'             => ['nullable', 'uuid'],
'governorate_id'      => ['nullable', 'integer'],
'warehouse_id'        => ['nullable', 'uuid'],
'order_status'        => ['nullable', 'string', 'max:50'],
'payment_status'      => ['nullable', 'string', 'max:50'],
'distribution_status' => ['nullable', 'string', 'max:50'],
'late'                => ['nullable', 'boolean'],
```

Plus `GET /windows/{window}/late-orders` (no parameters).

**The contract matches its certification report exactly.** STOP condition 1 is *not* triggered — nothing differs from what was certified.

## 3. Contract Gaps — the four STOP findings

These are places where **this task's requirements exceed the certified contract**. Each would require a backend change, which this task forbids.

### 3.1 `payment_method` filter — NOT SUPPORTED

Required filter #5. The field is **returned** in the payload but is **not accepted** as a query parameter.

- Endpoint: `GET /windows/{window}/orders`
- Expected: `?payment_method=`
- Actual: not in the validation set; unknown keys are ignored
- Required backend change: add `payment_method` to the validated filters and to the `where` chain in `DistributionAggregationService::orders()`

### 3.2 `received_at` / date-range filter — NOT SUPPORTED

Required filter #7. `received_at` is returned (from `orders.created_at`) but cannot be filtered.

- Expected: `?received_from=&received_to=` (or equivalent)
- Actual: no date parameter exists
- Required backend change: add a validated date-range filter over `o.created_at`

### 3.3 Pagination — NOT SUPPORTED

The endpoint returns a **plain unpaginated array** (`['data' => [...]]`). The task requires pagination support.

- Required backend change: paginate `orders()` following the platform's existing `meta{}` convention

**This one is material at operational scale** — a busy window could return thousands of rows in a single response.

### 3.4 Sorting — NOT SUPPORTED

`orders()` sorts by `o.order_number` unconditionally; no sort parameter exists.

Per the task ("document the limitation instead of sorting a partial client dataset"), no client-side sort was implemented.

### 3.5 KPI gaps

| KPI | Status |
|---|---|
| Total Orders | ✅ derivable from the returned row count |
| Late Orders | ✅ `late-orders` endpoint length, or `is_late` |
| **Paid / Unpaid** | ❌ **`payment_status` is always `null`** — no production writer exists (documented in the API task). Computing it from `deposit_amount` / `date_paid` is explicitly forbidden, so these KPIs must be **omitted**, not faked |
| **Assigned / Unassigned** | ❌ no direct API value. "Assigned" has no unambiguous field: membership in the window is implied by the endpoint itself, and `virtual_slot_id` being null means *slot* unassigned, not *distribution* unassigned. Deriving it would be inventing a semantic the backend has not defined |

### 3.6 `zone_name` missing from the orders payload

`GET /windows/{w}/orders` returns `zone_id` but **not** `zone_name`; `GET /windows/{w}/late-orders` returns **both**. Rendering a zone label in the orders grid would require either a second lookup or a client-side join against the zones list.

Per the zone-visibility rule ("do not derive Zone in React… report it as a backend read-model enhancement"), this is reported rather than worked around. Governorate is unaffected — both `governorate_id` and `governorate_name` are returned.

## 4–11. Workspace, Filters, Grid, Late Orders, Drawer, Responsive, Permissions, Tenant

**Not implemented in this task.** The pre-existing workspace page remains as previously certified: real API only, no mock data, permission-respecting via `accepts_manual_assignment` / `accepts_automatic_ingestion`, tenant isolation left entirely to the backend, mobile card fallback under `md`.

## 12. Payment Status Limitation

Confirmed and unchanged from the API task: `orders.payment_status` exists as a column, is **absent from `Order::$fillable`**, and **no production code writes it** — `OrderController:64` only reads it as a filter.

Any UI built on it must render the neutral empty state, never a derived value. **No derivation was written**, and the Paid/Unpaid KPIs are omitted rather than fabricated (§3.5).

## 13. Runtime / Frontend Verification

No frontend source was modified by this task, so no new verification was warranted. The last verified state of the existing workspace stands: **TypeScript 24 errors — identical to baseline, zero in `distribution-workspace`** (pre-existing i18n selector failures, EPIC-L10N-001).

## 14. Files Changed

**None.** No frontend file, no backend file, no route, no test, no migration.

Per the API-regression protection clause, the certified backend suites were left untouched — Distribution surface **47/47 (235 assertions)**, of which the read-model additions are **13 tests / 80 assertions**.

## 15. Certification Verdict

| # | Scope | Verdict |
|---|---|---|
| **A** | **Distribution API / Read Model** | ✅ **CERTIFIED** — inherited from TASK-…-API-READ-MODEL-001, unchanged |
| **B** | **Distribution Workspace UI** | ❌ **NOT CERTIFIED — not implemented in this task** |
| **C** | **Distribution End-to-End Business Flow** | ❌ **NOT CERTIFIED** — awaits assignment/allocation tasks, as specified |

**Shipping is not complete and is not claimed to be.**

## 16. Remaining Shipping Work

**To unblock this UI task (backend, small):**

1. Add `payment_method` to the orders filter set (§3.1).
2. Add a `received_at` date-range filter (§3.2).
3. Paginate the orders endpoint per platform convention (§3.3).
4. Add server-side sorting (§3.4).
5. Add `zone_name` to the orders payload (§3.6).
6. Decide the Paid/Unpaid and Assigned/Unassigned KPI sources, or confirm they are omitted (§3.5).

**Then:** rebuild the workspace around `UniversalDataGrid` + `SmartToolbar` + Saved Views + Columns Manager, with the Late Orders view, read-only drawer and mobile cards. All infrastructure for this exists; nothing needs designing.

**Downstream, unchanged:** Loading / Allocation → Vehicle → Driver → Delivery → Driver Inventory → Cash Settlement → full Shipping E2E certification.

---

### Honest note on why this stopped here

Items 1–6 above are genuine contract gaps that the task's own rules require me to report rather than route around — filtering client-side or deriving payment status would have violated explicit prohibitions.

The remainder of the build was within reach technically but not within the working context left in this session. Producing a partially-wired grid with some filters reaching the server and others silently inert would have looked like progress while quietly breaking the "no client-side filtering, no invented values" contract this module has been held to throughout. Leaving the tree clean and the gaps documented is the more useful outcome, and the next session can start immediately from §16.

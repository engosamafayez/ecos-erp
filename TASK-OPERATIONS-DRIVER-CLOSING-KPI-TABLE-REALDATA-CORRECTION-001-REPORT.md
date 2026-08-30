# TASK-OPERATIONS-DRIVER-CLOSING-KPI-TABLE-REALDATA-CORRECTION-001 — Report
## Driver Closing: KPI Replacement + Main Table Real-Data Correction

Focused follow-up on the main Driver Closing workspace. No architecture restart; no detail-page
redesign; no new settlement/payment/cash/expense/inventory/reporting authority. **Not deployed to
DEV. FINAL CERTIFICATION DEFERRED.**

---

## 1. Executive Summary

The 6 legacy KPI cards are **replaced** by the 8 CTO-specified operational KPIs, aggregated
**server-side** over the visible Active custodies from canonical per-row data. Six are real
(Total Orders / Delivered / Failed / Delivery Rate / Total Sales / Transfers & Paid); **Expenses and
Net Cash are honest "Not available"** (no canonical Driver cash-movement authority — never a fabricated
zero). The main table drops the **Status** column and the row-level **`0/1` delivery fraction** (now a
clean percentage). Every remaining table field is canonical; no placeholder zeros.

## 2. Current KPI Audit

The prior `kpis()` returned `active_custodies / ready_for_closing / needs_review / shortage / damaged /
settled` (closing-stage/recon counts). Per the CTO these are removed entirely — not kept alongside.

## 3. Current Table Data Audit

The prior table already dropped Vehicle/Damaged/Shortage (previous task). Remaining issues corrected
here: the **Status** column (`closing_stage` "Open Custody" badge) and the **Delivery Rate cell** showing
`{delivered}/{orders} · {pct}%` (the `0/1 · 0%` fraction). Order/Delivered/Failed counts+values, Total
Sales, Transfers/Paid, Goods Remaining were already canonical; Expenses/Net Cash were already
"Not available".

## 4. Canonical Data Source Map

| Field | Source | Canonical | Real |
|---|---|---|---|
| Total Orders | Σ `rows.orders` (`stops_total` per trip via `SettlementService::financialSummary`) | ✅ | ✅ |
| Delivered | Σ `rows.delivered` (`DeliveryStopStatus::Delivered` count) | ✅ | ✅ |
| Failed | Σ `rows.failed` (`DeliveryStopStatus::Failed` count) | ✅ | ✅ |
| Delivery Rate | `delivered / orders` (derived %) | ✅ | ✅ |
| Total Sales | Σ `rows.total_sales` = `delivered_value` (`SUM(orders.total)` over delivered stops) | ✅ | ✅ |
| Transfers & Paid | Σ `rows.transfers_paid` = bank + card + `already_paid` | ✅ | ✅ |
| Expenses | — no Driver operational-expense authority | ❌ | Not available |
| Net Cash | — no cash-movement authority (cash-in/out) | ❌ | Not available |
| Goods Remaining | `rows.goods_on_hand` (canonical vehicle custody) | ✅ | ✅ |

## 5. Total Orders KPI

`kpis.total_orders = Σ rows.orders` over the Active rows. Scoped to the Active board (open operational
custodies only; company-scoped; no drafts/loading-shells/history/other companies — §3/§4). If 3 active
custodies hold 1 + 4 + 5 → **10**.

## 6. Total Delivered KPI

`kpis.total_delivered = Σ rows.delivered`, counting only canonical `DeliveryStopStatus::Delivered`.
Partial is **not** counted as delivered; delivery is never inferred from POD/payment/route/frontend.

## 7. Total Failed KPI

`kpis.total_failed = Σ rows.failed`, the canonical `DeliveryStopStatus::Failed` count. Inclusion rule:
**exactly the stops whose canonical status is `Failed`** — the terminal unsuccessful outcome. Partial is
NOT included (it is its own status and is not classified as failed by the canonical enum). Refused /
no-answer / wrong-address etc. are operational *failure reasons* recorded against a `Failed` stop, so
they are already inside this count via the status; they are not a separate status to add.

## 8. Delivery Rate KPI

`kpis.delivery_rate = round(total_delivered / total_orders × 100)` — an **integer percentage**, computed
server-side. Zero Total Orders → `0` (safe, no divide-by-zero). The KPI and the table cell render **the
percentage only** (`70%`), never `7/10`, `0/1`, or `0/0`.

## 9. Total Sales KPI

`kpis.total_sales = Σ rows.total_sales`, where `total_sales = delivered_value = SUM(orders.total)` over
**delivered** stops — the actual delivered/sold value. Failed/undelivered value, goods remaining, and
mere assigned-order totals are excluded. This is an operational metric — **no Finance revenue
recognition** was created.

## 10. Transfers & Paid KPI

`kpis.total_transfers_paid = Σ rows.transfers_paid`, preserving the previously-approved semantics:
**bank transfer + card + prepaid (`already_paid`)**, i.e. non-physical-cash settled amounts. Physical
COD cash is **excluded** (it belongs to Cash Collected). Each component comes once from the canonical
`SettlementService` per-trip totals — **no double-counting** of a customer payment.

## 11. Expenses Authority

Audited: **no canonical Driver operational-expense / cash-movement authority exists** (confirmed in the
prior audit — no `*Expense*` model/table; Fleet `CostEntry` is vehicle-keyed and ring-fenced from trip
cash; `DriverReportsReadService` returns `no_canonical_authority`). So `kpis.total_expenses = null` →
the card shows **"Not available"**. **No zero is fabricated**, and no expense authority was created here.
The gap remains the CTO-approval-gated follow-up (`task_2e2fd78b`).

## 12. Net Cash Authority

Same audit: no canonical cash-in/cash-out semantics exist, so a real Net Cash cannot be computed.
`kpis.net_cash = null` → **"Not available"** — and it is **not** a fallback to Cash Collected, and
electronic payments are never treated as physical cash.

## 13. Zero / Unavailable Semantics

Strictly preserved (§12): a **canonical real zero** renders `0` / `EGP 0.00`; **no canonical authority**
renders **"Not available"** (`null` from the backend); a **read failure** renders the error state
(`kpi-error`). Missing business authority is never converted to zero — the KPI backend returns `null`,
and the card distinguishes it from a real zero.

## 14. KPI Backend Read Model

The KPIs are aggregated in the existing `DriverDaySettlementReadService::kpis()` — **not** re-derived in
React. The API returns the 8 canonical values; the frontend only presents them. No second reporting
authority was created.

## 15. Status Column Removal

The **Status** column (`closing_stage` "Open Custody / عهدة مفتوحة" badge) is removed from the desktop
table and the mobile card (presentation only). The underlying `closing_stage`/`settlement_status` remain
in the backend row and are still used for eligibility, duplicate-detection (`needs_review`), the
Settlement detail, and diagnostics — **not removed from the model**.

## 16. Delivery Fraction Removal

The row-level `{delivered}/{orders} · {pct}%` fraction is removed on desktop and mobile. The Delivery
Rate cell now renders the percentage only (`0%` / `70%` / `100%`).

## 17. Final Table Structure

Driver/Trip · Total Orders (count + value) · Delivered (count + value) · Failed/Exceptions (count +
value) · Delivery Rate (%) · Total Sales · Transfers/Paid · Expenses · Net Cash · Goods Remaining ·
**Settlement**. **No** Status, Vehicle, Damaged, Shortage, or `0/1` fraction.

## 18. Row Data Corrections

Every remaining cell is canonical (§4): counts from the stop breakdown, values from `SUM(orders.total)`
by outcome, transfers/paid from the settlement totals, goods from vehicle custody. Expenses/Net Cash
cells show "Not available" (null), never a fabricated zero. No stale day-based or wrong-custody values —
the row is one canonical open Trip/Custody (single-active contract), not `(assignment, calendar-day)`.

## 19. Goods Remaining

`goods_on_hand` — the canonical quantity still under Driver/Vehicle custody (expected back if not
delivered). Not labelled "Actual Returned" (that requires warehouse physical receipt).

## 20. Settlement Action

The **Settlement / `تصفية`** action opens the existing canonical Driver Closing detail route
(`openReview` → detail). It is **navigation**, not an auto-close; the final Close/Finalize stays behind
the existing settlement authority + guards.

## 21. Desktop UX

Page structure unchanged (toolbar, header, filters, KPI strip, table). Only the KPI set, the Status
column, and the Delivery Rate cell changed. No workspace redesign.

## 22. Mobile UX

The 8 KPI cards use a responsive grid — **2 columns on mobile → 4 from `md`** — no horizontal scroll,
Arabic labels/values wrap rather than clip (§31). The mobile custody card keeps the approved presentation
with the same removals (no Status/Vehicle/Damaged/Shortage, no `0/1` fraction; Delivery Rate percentage
only).

## 23. Security / Tenancy

Unchanged. `activeBoard`/`historyBoard`/`daySummary` remain company-scoped; the KPIs aggregate only the
already-scoped rows; Driver↔Trip ownership, Operations RBAC (`logistics.distribution.view`), and the
active/history boundaries are intact. No access was widened.

## 24. Backend Changes

`DriverDaySettlementReadService::kpis()` rewritten to the 8 canonical aggregates (Expenses/Net Cash →
`null`). No other backend change; no schema change; no new authority.

## 25. Frontend Changes

- `types/driver-settlement.ts`: `DaySettlementKpis` → the 8 fields (Expenses/Net Cash `number | null`).
- `components/day-settlement-kpis.tsx`: rewritten — 8 responsive KPI cards, "Not available" for null,
  distinct loading/error states.
- `pages/driver-settlement-workspace-page.tsx`: removed the Status column; Delivery Rate cell = `%` only.
- `components/day-settlement-driver-card.tsx`: removed the Status badges; Delivery Rate = `%` only.
- `i18n/locales/{en,ar}/logistics.json`: replaced the 6 old `kpis.*` labels with the 8 new ones.

## 26. Files Changed

Backend: `DriverDaySettlementReadService.php`, `tests/Feature/Logistics/DriverDaySettlementReadTest.php`.
Frontend: `types/driver-settlement.ts`, `components/day-settlement-kpis.tsx` (+ new test),
`pages/driver-settlement-workspace-page.tsx`, `components/day-settlement-driver-card.tsx`,
`i18n/locales/{en,ar}/logistics.json`, and updated tests.

## 27. Focused Verification (§36)

Frontend — Vitest driver-settlement **29/29**, ESLint clean, **0 tsc** errors in touched files (the
repo-wide count reflects unrelated concurrent work), i18n EN↔AR parity (0 missing). New KPI-cards test
proves: 8 KPIs render; Delivery Rate is percentage-only (no `7/10`); Expenses/Net Cash "Not available";
real zero (`EGP 0.00` / `0%`) distinct from "Not available"; distinct loading/error states. Workspace/
card tests updated for the removed Status column and percentage-only rate.

Backend — `DriverDaySettlementReadTest` gate: **OK (19 tests, 142 assertions)**. `test_day_summary`
asserts the 8 KPIs (`total_orders 2 / total_delivered 1 / total_failed 1 / delivery_rate 50 /
total_sales 250 / total_transfers_paid 80 / total_expenses null / net_cash null`); `test_active_scope`
asserts `total_orders/total_delivered`; `test_history_scope` likewise; the single-active + eligibility +
value-column tests remain (row counts via `assertJsonCount`). Company-scoping and
"loading-without-custody not Active" remain covered.

## 28. DEV Runtime Status

**NOT DEPLOYED** — source + testrunner only; nothing applied to `ecos-dev-app`. The
`expected_collection_at_handoff` migration also remains pending. No DEV business data mutated; OSAMA's
trips untouched (they are `loading` → not custody-eligible → correctly absent from Active once the source
is deployed; until then the DEV runtime shows the old rows — runtime drift, not special-cased).

## 29. Remaining Gaps

- **Expenses & Net Cash KPIs are "Not available"** pending the CTO-approval-gated Driver
  cash-movement/expense authority (`task_2e2fd78b`) — 6 of 8 KPIs are real canonical; 2 are honest gaps.
- DEV deploy of this source (+ the pending migration) is a separate authorized step.
- Pre-existing lifecycle-suite baseline failures (stale `total_drivers` KPI) are tracked separately.

## 30. Implementation Status

**IMPLEMENTATION STATUS: COMPLETE** — the 8 KPIs are implemented and server-aggregated from canonical
data (6 real, 2 honest "Not available"); the Status column and the `0/1` delivery fraction are removed;
all remaining table/card data is canonical; zero vs unavailable vs error are distinct; mobile is
responsive; tenancy and the single-active contract are preserved.

**DEV RUNTIME: NOT DEPLOYED.**

**FINAL CERTIFICATION: DEFERRED TO FINAL SYSTEM REVIEW.**

---

**STOP.** No commit. No push. No deploy. No DEV business-data mutation.

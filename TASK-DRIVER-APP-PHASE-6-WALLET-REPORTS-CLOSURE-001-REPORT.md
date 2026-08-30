# TASK-DRIVER-APP-PHASE-6-WALLET-REPORTS-CLOSURE-001

## 1. Executive Summary

Phase 6 gives the Driver App a permanent, driver-scoped **Wallet + Reports** layer on top of a
**new canonical read contract** — never React aggregation, never a new ledger, never a Finance
entry. A single read service (`DriverReportsReadService`) resolves the authenticated driver's own
trips, over a **server-resolved date window**, and derives every money figure from the canonical
`SettlementService::financialSummary()` and the payment-collection ledger; every operational
figure from the delivery-stop and vehicle-custody/reconciliation reads. A thin driver-gated
controller exposes Wallet, Orders, Goods-Movement, Shortage, Advances and Statement reads. The
frontend adds a Wallet page and a tabbed Reports page (mobile-first) with the full date-preset
filter and server-side pagination, plus Reports + Wallet nav entries.

Where a canonical **driver-attributed** authority does not exist, the feature says so honestly
instead of inventing one: **Advances** and driver **Expenses** have no driver authority, and a
shortage's **monetary value / confirmed-liability / settled** ladder has no authority — all
surfaced as explicit "unavailable" states (§5/§8). That is why the status is **PARTIAL**: every
report backed by canonical data is built and tested; the rest is documented, not fabricated.

Backend: 3 files (2 new + routes) + 1 test (**9/9**). Frontend: 11 files (5 new). tsc 23 baseline
(0 touched) · vitest 72 · eslint 0 · i18n parity. Isolated test DB only — **no DEV mutation, no
Finance, no commit/deploy**.

**IMPLEMENTATION STATUS: PARTIAL.** **FINAL CERTIFICATION: DEFERRED TO FINAL SYSTEM REVIEW.**

Date: 2026-08-29 · Branch: `develop`

## 2. Financial Read Architecture Audit

Traced by four parallel read-only audits (settlement/collections, advances/expenses/liability,
orders/goods, frontend). Findings:

| Authority | Verdict | Use |
|---|---|---|
| `SettlementService::financialSummary(Trip)` | EXISTS (permission-free read) | reused per trip, summed server-side |
| `distribution_payment_collections` (`PaymentCollection`) | EXISTS — money SSOT (cash/bank_transfer/card/already_paid) | collection breakdown |
| `DriverDaySettlementReadService` | EXISTS but operator-gated + single-date | pattern template only (re-gated + self-scoped + ranged here) |
| Cross-trip driver wallet/ledger | **MISSING** — money strictly per-trip | wallet is derived-on-read, never stored |
| Driver settlement/collections `/api/driver/*` | **FROZEN → 403** | preserved; new reads are net-new |
| Advances (driver-operational) | **MISSING** (HR `Advance` is employee/payroll) | surfaced `available:false` |
| Expenses (driver-operational) | **MISSING** (fleet-unit cost only, never settlement) | surfaced `available:false` |
| Driver shortage qty/variance/status | `VehicleShiftReconciliation` variance (Open/Completed/Disputed/Approved) | built |
| Shortage value + confirmed-liability/settled | **MISSING** | documented, not fabricated |

## 3. Driver Wallet (§2)

`GET /api/driver/wallet?period=…[&from&to]` → `DriverReportsReadService::wallet()`. Sums
`financialSummary()` across the driver's own trips in the window: total collected, cash /
transfer / card / already-paid breakdown (from the `PaymentCollection` ledger), expected cash,
cash submitted, difference, is-balanced, aggregated settlement status, and closing indicators.
The operational wallet is kept **separate from any accounting ledger** (§2/§16). Advances,
expenses and monetary liability are returned as explicit `available:false` sections. Frontend:
`driver-wallet-page.tsx` (collections, cash reconciliation, settlement + closing flags, honest
unavailable notes), reusing `useFormatter().money`.

## 4. Collection Sources (§2)

Breakdown is read straight from `distribution_payment_collections` (the SSOT), grouped by
`payment_type`, excluding rejected rows: **cash, transfer (bank_transfer), card, already_paid**.
Only physical cash feeds the expected-cash reconciliation, matching the canonical settlement
semantics (`SettlementService`). No money is re-derived in React.

## 5. Advances (§5)

**No canonical driver-operational advances authority exists.** The only `Advance` model is HR
payroll (`hr_advances`, employee-attributed, HR-gated, recovered from pay) — a different concept,
deliberately **not wired**. `GET /driver/reports/advances` returns
`{available:false, reason:'no_canonical_authority', items:[]}`; the Reports "Advances" tab renders
an honest unavailable state. Nothing is invented (§5).

## 6. Shortage / Liability (§6/§8)

`GET /driver/reports/shortages` reads the reconciliation variance — the one source with
driver+vehicle+date attribution (`VehicleShiftReconciliation(.lines)`): per product date, expected
vs actual return, damaged, **shortage qty (variance)**, damage reason, and investigation status
(Disputed → *under investigation*; a set `variance_resolution` → *reviewed*). **A shortage is
never auto-debt** — confirmed by `ReceiveVehicleReturnAction` (variance stays visible, shift
Disputed). Monetary **value** and a **confirmed-liability/settled** ladder have no canonical
authority and are returned `value_available:false` / `liability_ladder_available:false` — shown as
a note, never fabricated (§8). The Reports "Shortage" tab leads with the no-auto-debt statement.

## 7. Reports Architecture (§3)

`DriverReportsController` (driver-gated `loading.driver.operate`, self-scoped via
`Driver::user_id = Auth::id()`) delegates every figure to `DriverReportsReadService`. Frontend:
`driver-reports-page.tsx` — a tabbed, mobile-first page (Orders / Goods / Shortage / Advances)
with summary cards, compact rows and drill-down. Reports are a first-class nav destination, not
hidden inside closing (§3/§23).

## 8. Date Filtering (§4)

All eight presets — Today / This Week / This Month / Previous Month / This Year / Year-to-Date /
Previous Year / **Custom** — plus a custom from/to. The preset is **resolved to a window
server-side** in `DriverReportsReadService::resolvePeriod()` (one definition of "this week"), and
the actual filtering is a `DATE(COALESCE(trip_started_at, dispatched_at, created_at)) BETWEEN`
clause. No lifetime history is loaded into React. Shared `report-period-filter.tsx` component.

## 9. Orders Report (§7/§8)

`GET /driver/reports/orders` → a **full stop-status histogram** (received / delivered / partial /
failed / returned / skipped / pending) + delivery rate, plus a **server-paginated** order-row
drill-down (order number, customer, area, outcome, value). "Deferred/postponed" is derived from
`delay` delivery-actions (not a stop status); "cancelled" has no stop-level concept and is
reported as absent — both documented. Pagination follows the repo `EloquentStockMovementRepository`
convention (`per_page ≤ 100`, `{items, meta}`); the frontend uses page nav with `keepPreviousData`.

## 10. Goods Movement Report (§9/§10)

`GET /driver/reports/goods-movement` → per product: **Received** (custody loaded), **Delivered**
(custody delivered), **Returned** / **Damaged** / **Confirmed Shortage** (from the warehouse
**reconciliation lines** — `quantity_returned_actual` / `quantity_damaged` / `variance`, because
the custody `quantity_returned` leg is inert in practice), and **Remaining Custody** (on-hand).
**Approved arithmetic (documented, §9):** `remaining_custody = max(0, received − delivered −
returned)`; returned/damaged/shortage are the warehouse's reconciliation figures, not the movement
table. The exact arithmetic string is returned in the payload for auditability.

## 11. Monthly Driver Statement (§12)

`GET /driver/statement?month=YYYY-MM` → a permanent monthly read model composed from the same
canonical reads (orders summary, collections, cash, settlement status, shortage count, and the
unavailable advances/expenses). It is a **reporting read model — no Finance journal** (§12/§16).
Backend + `useDriverStatement` hook are built; a dedicated statement *page* is deferred (the Wallet
page already renders the period financial summary). See Remaining Gaps.

## 12. Current Custody (§11)

Current custody stays the existing `GET /driver/vehicle-inventory` (open items on the driver's
**active** assignment) — closed historical movements never appear as current stock. The reports
read history separately, keyed by the driver's assignments over the window.

## 13. Historical Data (§11/§20)

Historical figures are read server-side over the date window (trips anchored on the operational
date; custody/reconciliation joined via `vehicle_assignments.trip_id`). Server-side filtering,
pagination (orders) and sorting; no lifetime-history React aggregation (§20).

## 14. Operational Day Settlement Integration (§14)

No second closing system was created. The wallet's settlement status reuses the **exact** operator
rollup mapping (`needs_review / under_review / disputed / settled`) so the driver's view cannot
disagree with Operations. Controlled closing actions remain the operator's; the driver consumes
read/status only.

## 15. Driver Account Closing (§13)

The wallet's `closing` block exposes read-only indicators the driver can understand: custody
reconciled, deliveries outstanding, all trips closed, settlement complete, plus the aggregated
settlement status. The driver approves nothing (§13/§21).

## 16. Home End-of-Day Integration (§18)

The existing Home command center already reflects canonical closure and was left intact (it is
certified): `returnLeg` is *Returning*/done-only-when-custody-zero, `closing` is
*Ready-for-Closing*/*Closed* by settlement state, and `buildAttention` surfaces
*Needs-Reconciliation* — and it never shows "Day Complete" unless settlement is really closed.
Phase 6 adds Wallet + Reports as reachable drill-down destinations from the driver menu. A richer
explicit 5-state machine on Home is a deferred enhancement (documented).

## 17. Security (§21)

Every read is fail-closed to the authenticated driver: trips resolved from
`Driver::user_id = Auth::id()` scoped by company + `driver_vehicle_assignment.driver_id`; no route
parameter can widen it. A non-driver is 403, unauthenticated 401. The driver approves nothing,
records no warehouse receipt, closes no liability, and cannot modify any settlement amount — and
the **frozen settlement money endpoints stay 403** (regression-tested). All confirmed by tests.

## 18. Performance (§20)

Server-side date filtering + pagination + bounded page size (`≤100`); histograms are single
grouped-count queries; per-trip money reuses the canonical engine. No lifetime React aggregation.

## 19. Backend Changes

- **NEW** `Modules/Logistics/Distribution/Domain/Services/DriverReportsReadService.php` — the
  driver-scoped read model (period resolver, wallet, orders histogram, goods movement, shortages,
  monthly statement, closing indicators).
- **NEW** `Modules/Logistics/Distribution/Presentation/Http/Controllers/DriverReportsController.php`
  — identity + tenancy + window; delegates.
- **MODIFIED** `routes/api.php` — 6 driver-group GET routes (wallet, statement, reports/orders,
  reports/goods-movement, reports/shortages, reports/advances).
- No migration, no write path, no Finance code.

## 20. Frontend Changes

- **NEW** `types/reports.ts`, `components/report-period-filter.tsx`, `pages/driver-wallet-page.tsx`,
  `pages/driver-reports-page.tsx`, `pages/driver-reports.test.tsx`.
- **MODIFIED** `services/driver-mobile-service.ts` (+6 fetchers), `hooks/use-driver-mobile.ts`
  (+6 hooks), `components/layout/driver-shell.tsx` (Reports + Wallet menu entries),
  `router/router.ts` + `router/routes.ts` (routes), `i18n/locales/{en,ar}/driver-mobile.json`
  (`wallet.*`, `reports.*`, `shell.nav.reports`, EN↔AR parity).

## 21. Files Changed

Backend 4 (2 new services/controllers, routes, 1 test) · Frontend 11 (5 new). No commit/deploy.

## 22. Deferred Verification Plan (§24)

Focused only (no full/module/browser certification):
- Backend `DriverReportsTest` — **9/9, 33 assertions**: wallet aggregates own collections;
  cross-driver isolation; date window (this_month excludes / custom includes); orders histogram +
  delivery rate + pagination meta; goods movement per product from custody; advances unavailable;
  non-driver 403; unauthenticated 401; **frozen settlement stays 403**.
- Frontend `driver-reports.test.tsx` — Wallet renders server-derived collections + honest
  unavailable advances/expenses; Reports renders the orders histogram + the four tabs.
- Static: tsc **23 baseline (0 touched)**, vitest **72**, eslint **0**, i18n EN↔AR parity.

**At final review:** browser walkthrough (authenticated driver, isolated fixture) of Wallet +
each Reports tab + date presets + pagination; verify statement endpoint against a populated month.

## 23. Finance Boundary (§16/§17)

No GL journal, payroll, salary, expense posting, revenue, or COGS. The operational wallet is a
read model, explicitly separate from accounting authority. Driver operational
advances/expenses/shortages are not booked as brand expenses — their accounting treatment stays
Finance scope (§17), and none is created here.

## 24. Remaining Gaps

1. **Advances / driver expenses** — no canonical driver-attributed authority; surfaced unavailable.
   A real report needs a new backend authority (owner decision).
2. **Shortage value + confirmed-liability/settled ladder** — no authority; qty/variance/status
   shown, value not. (Same family as the Phase-5 deferred `WarehouseLiability` gap.)
3. **Monthly Statement UI** — backend read + hook built; a dedicated statement page is deferred.
4. **Export (§19)** — no client CSV helper and no driver export endpoint exist; a server export
   endpoint + the repo's `buildExportUrl` pattern would be needed. Deferred ("if existing export
   infrastructure supports it" — it does not for the driver runtime).
5. **Home 5-state end-of-day machine (§18)** — existing closing journey reflects canonical state;
   a richer explicit machine is deferred.
6. **Browser certification** — deferred (§24).

## 25. Driver App Implementation Closure Readiness

- **Phases 1–4** (Shell/Home/Nav, Loading/Custody/Trip, Orders/Route/Customer, Delivery/Payment/
  POD + payment-method closure): implemented.
- **Phase 5** (Returns + Reconciliation): CTO-dispositioned **driver-facing COMPLETE**; waste/
  liability integrations = deferred backend gaps.
- **Phase 6** (this): Wallet + Reports read contract + UI **PARTIAL** — everything backed by
  canonical data is built and tested; advances/expenses/liability-value are documented as
  no-canonical-authority.
- **Driver-facing app is functionally complete** to the extent the canonical backend allows. The
  remaining items are **backend-authority / owner decisions** (advances, expenses, driver liability
  value, export), not Driver-App UI work, plus Finance (out of scope, §16).

## 26. Implementation Status

**IMPLEMENTATION STATUS: PARTIAL** — the driver-scoped Wallet + Reports read contract, the Wallet
page, the tabbed Reports (Orders / Goods / Shortage / Advances), the full date filter, server-side
pagination, nav, and closing/settlement visibility are implemented on canonical data with no React
aggregation and no Finance entry. Advances, driver expenses, shortage monetary value/liability
ladder, a dedicated Statement page, and export are documented as absent-authority or deferred, not
fabricated.

**FINAL CERTIFICATION: DEFERRED TO FINAL SYSTEM REVIEW.**

---

**STOP.** No Finance. No Final System Certification. No commit/push/deploy. No DEV data mutation.

---

## Addendum — CTO Disposition + Remaining-Gap Closure (2026-08-29)

**CTO FINAL DISPOSITION: PARTIAL — ACCEPTED** (narrow verification PASSED; final certification
deferred). Backend/authority gaps (driver-attributed advances/expenses; monetary shortage/liability;
confirmed/settled shortage state) remain **deferred owner decisions**. Verification (browser, broad
regression, final cert, export where no canonical driver export infra exists) remains **non-blocking
deferred**. The CTO flagged two **Remaining Driver-App Implementation Gaps** — both frontend-only,
no new backend authority — now **CLOSED**:

1. **Monthly Driver Statement user-facing surface (§12) — CLOSED.** New `driver-statement-page.tsx`
   (month picker → the existing `/driver/statement` read model): orders summary, collections, cash
   reconciliation, settlement status, shortage count, and honest advances/expenses-unavailable.
   Routed at `/driver/reports`→ `driverStatement` and reachable via a "Monthly statement" link on the
   Wallet page. No new backend (reuses the Phase-6 endpoint + `useDriverStatement`).
2. **Home end-of-day / closing-state integration (§18) — CLOSED (was already functionally
   represented).** Confirmed the certified Home day-summary already renders the closing readiness
   (orders, vehicle-stock needs-return/clear, settlement pending/complete) and never shows a false
   "Day Complete". Added a minimal "View wallet & closing" access from that card to the full Wallet
   closing indicators — no change to the certified `deriveState`/closing logic.

**Closure verification (frontend-only):** tsc 23 baseline (0 touched); vitest **72**; eslint 0;
i18n EN↔AR parity (incl. `statement.*`, `wallet.statementLink`, `home.daySummary.viewWallet`). No
backend change, no DEV mutation, no commit/deploy.

**Net Driver-App status:** every Driver-App implementation item in scope is now implemented; the only
open items are the **deferred backend authorities** (advances/expenses/liability value) and Finance —
both out of Driver-App scope — plus the non-blocking deferred verification. Historical body of this
report stays PARTIAL by construction; the two flagged UI gaps are closed per this addendum.

**STOP.** Do NOT start Finance. Do NOT run final certification. Do NOT commit/deploy.

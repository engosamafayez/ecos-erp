# TASK-OPERATIONS-DRIVER-CLOSING-PAGE-ENHANCEMENT-001 — Engineering Report

**Operations — Driver Closing / Operational Day Settlement Page Enhancement**
Date: 2026-08-29 · Branch: `develop` · Status: **COMPLETE** (verification narrow, per policy)

---

## 1. Executive Summary

The existing Operations "Driver Closing / تقفيل اليوم" workspace was upgraded in place. It
was already a **read-only rollup over the canonical per-trip settlement engine**
(`TripSettlement` / `SettlementService`); this task widened what it surfaces and how it is
navigated, **without creating any second settlement, return, inventory, custody, collection,
damage, or liability authority.**

The headline additions are all backed by canonical data that already exists in the
repository:

- **Active vs History** operational tabs (§3): Active = open custody, not date-bounded;
  History = closed settlements with **server-side** date-range, pagination and sorting.
- **Canonical KPI set** (§4): Active Custodies, Ready for Closing, Needs Review, Shortage,
  Damaged, Settled.
- **Vehicle-custody + product reconciliation, damage and shortage** surfaced from the
  canonical **`VehicleShiftReconciliation` / `VehicleShiftReconciliationLine`** authority
  (loaded / delivered / expected-return / actual-return / accepted / damaged / variance),
  with an **honest "not reconciled" state** where no shift count has been opened.
- **Sales & Collections** breakdown by canonical payment type, with **Expected Collection
  explicitly reported as unavailable** (no canonical contract — never invented, §6 HARD RULE).
- **Timeline, closing readiness + blockers, delivery breakdown (partial/failed)**, and a
  derived **operational closing stage** that maps existing facts (no competing lifecycle).

The one architectural correction against prior institutional memory: the vehicle **inbound /
return-reconciliation authority now fully exists** (`ReceiveVehicleReturnAction` +
`VehicleShiftReconciliationService`, migration `2026_08_28_120000`), post-dating the older
"inbound half does not exist" note. The page consumes it read-only.

**No parallel authority was added. No DEV/live business data was mutated. Nothing was
committed, pushed, or deployed.**

---

## 2. Current-State Audit

Audited end-to-end before any change:

| Area | Finding |
|---|---|
| Operations Driver Closing page | `frontend/src/features/operations/driver-settlement/` — Page-1 workspace (single date board) + Page-2 detail (5 tabs). Read-only; reuses `TripSettlementTab`, `PaymentProofSection`, `OrderDetailDrawer`. |
| Day-settlement read model | `DriverDaySettlementReadService` + `DriverDaySettlementController`, `GET /api/logistics/distribution/driver-settlement[/{assignmentId}]`, gated `logistics.distribution.view`, tenant fail-closed. |
| Settlement engine | `SettlementService` (`openSettlement`→`submitDriverCash`→`reconcile`/`dispute`→`finalize`), `TripSettlement` (`unique(trip_id)`), `SettlementStatus` Draft→Submitted→Reconciled→Finalized (+Disputed). Money DERIVED from `distribution_payment_collections`. |
| Driver / Vehicle / Trip linkage | Day row keyed by `Trip.driver_vehicle_assignment_id` (the driver+vehicle pairing). Custody/reconciliation keyed by the **Operations** `VehicleAssignment` (via `trip_id`) — a different id space. |
| Vehicle custody / vehicle warehouse | `VehicleInventoryItem` (loaded/allocated/delivered/returned/on_hand); no damaged/shortage columns on the custody row itself. |
| Delivery facts | `DeliveryStop` + `DeliveryStopStatus` (delivered/partial/failed/returned/…). Partial & failed ARE canonical. |
| Collections / payment facts | `PaymentCollection` (`payment_type` cash/bank_transfer/card/already_paid, `status` recorded/verified/rejected). `PaymentType::label()` gives readable labels. |
| Return reconciliation | **`VehicleShiftReconciliation` / …Line`** — the canonical per-product reconciliation SSOT (expected/actual/accepted/damaged/variance). `ReceiveVehicleReturnAction` = the single warehouse return receipt. Operator-OPENED. |
| Damage / shortage state | `…Line.quantity_damaged` + `damage_reason`; shortage = `…Line.variance`. Header `has_variance`; shift held Disputed while variance remains. |
| Existing closing authority | Per-trip `SettlementService::finalize` (finalize reachable only from Reconciled; sets Trip → Closed; dispatches `TripSettled`). |
| Existing filters / pagination / detail | Single `date` filter; client-side search/status; **no** Active/History split, **no** server pagination/sorting, **no** date range. |

**Availability classification of the approved fields:**

- *Already present & displayed:* orders, delivered, delivery %, returns count, cash-expected,
  transfers, difference, per-trip settlement status, goods-on-hand, transfers + proofs.
- *Available but not displayed (now surfaced):* partial/failed delivery counts; per-payment-type
  collection split; delivered sales; the whole reconciliation line set (loaded/delivered/
  expected-return/actual-return/accepted/damaged/variance); reconciliation status; trip/settlement/
  reconciliation timestamps (timeline).
- *Read-model projection only (now computed):* Active/History partitioning; the six KPIs; the
  derived closing stage; closing-readiness blockers.
- *Genuinely unavailable canonically (surfaced as honest gaps, not fabricated):* an order-derived
  **Expected Collection** settlement contract; the **WasteInvestigation** disposition record for
  damage; a **driver-attributed WarehouseLiability** for shortage.

---

## 3. Canonical Architecture Trace

```
Order ──▶ DeliveryStop (status) ─────────────▶ delivery facts (delivered/partial/failed)
Trip ──(driver_vehicle_assignment_id)──▶ driver+vehicle (day row grain)
Trip ──(VehicleAssignment.trip_id)────▶ VehicleAssignment (Operations, custody grain)
                                          ├─▶ VehicleInventoryItem (loaded/delivered/on_hand)
                                          └─▶ VehicleShiftReconciliation ─▶ …Line
                                                (expected/actual/accepted/damaged/variance)
Trip ──▶ PaymentCollection (cash/bank/card/already_paid) ──▶ SettlementService.financialSummary()
Trip ──▶ TripSettlement (SettlementStatus) ──▶ SettlementService.finalize() ──▶ Trip Closed
```

The read service **derives every money figure** from `SettlementService::financialSummary()`
(summed per trip) and **every goods figure** from the custody + reconciliation engines. It owns
no money/goods logic and writes nothing.

---

## 4. Settlement Eligibility / Custody Handoff

Per §2, eligibility is **at-or-before custody handoff, never after delivery**:

- **Active** = every driver-day (`driver_vehicle_assignment_id` × operational day) whose trips
  are **not all `Closed`**. `Trip.status` becomes `Closed` only when settlement is finalized, so
  "not Closed" is the canonical "still open" signal, and it is **index-backed** (`(company_id, status)`).
- A driver therefore appears **as soon as a trip exists for the pairing and is not yet closed —
  with Delivered = 0**. Delivery is never a precondition.
- Deliberately **inclusive**: a loaded-but-undelivered driver always appears. This errs toward
  over-inclusion rather than hiding a driver whose custody rows were not written by the
  Group/driver path — consistent with §5 ("do not hide zero-delivery drivers") and §19.

Active is **not** date-bounded, so open custody stays visible regardless of any History date
filter (§3).

---

## 5. Active vs History

- **Active** (`scope=active`): open driver-days across all dates. Two-step query — distinct
  `(assignment, day)` keys from non-Closed trips, then all trips for those keys (whole totals),
  then reject any group that turned out fully Closed. Filtered/sorted server-side; no pagination
  (open custody is inherently bounded).
- **History** (`scope=history&from&to`): driver-days whose trips are Closed and whose settlement
  was **Finalized inside the range**. Date-range, carrier and driver/vehicle **search** are applied
  in the backend query; grouping, sorting and **pagination** are applied in the backend service
  and returned as `meta{current_page, per_page, total, last_page}`. **No React lifetime-history
  filtering**; the browser never receives more than one page.
- **Day** (`scope=day&date`, default): the original single-date board, preserved for back-compat.

---

## 6. KPI Model (§4)

Six canonical, server-derived headcounts over the scope's full row set (before list narrowing):

| KPI | Definition |
|---|---|
| Active Custodies | rows whose closing stage ≠ `closed` |
| Ready for Closing | closing stage = `ready_for_closing` (every trip settlement Reconciled) |
| Needs Review | closing stage = `needs_review` (dispute or unresolved variance) |
| Shortage | rows with reconciliation variance > 0 |
| Damaged | rows with damaged qty > 0 |
| Settled | rows whose money-settlement rollup = `settled` (all finalized) |

No KPI is derived from demo-data assumptions; each is a count over canonical row facts.

---

## 7. Orders Received / Delivered Semantics

- **Orders Received / Assigned** = `stops_total` = every delivery stop on the driver's trips
  (assigned custody), **not** only delivered orders. Surfaced as the row `orders` field and the
  detail `overview.orders`.
- **Delivered** = stops with status `Delivered`. **Partial** and **Failed** are surfaced
  separately (both canonical `DeliveryStopStatus`).

---

## 8. Delivery Rate

`delivery_pct = round(delivered / orders_received × 100)`, denominator = **orders received**
(assigned stops). Zero-delivery drivers are shown, not hidden; `0/N · 0%` is a legitimate,
visible row.

---

## 9. Sales Summary

`collections` (detail) exposes, all canonical:

- `delivered_sales` — Σ `Order.total` for **Delivered** stops (never re-priced here).
- `cash`, `bank_transfer`, `card`, `already_paid` — Σ non-rejected `PaymentCollection` by
  `PaymentType`.
- `total_collected` / `actual_collected` — Σ of the four.
- `cash_expected`, `actual_cash` — from the canonical settlement summary.

Payment labels are readable (`PaymentType::label()` / i18n): Cash, Bank Transfer, Card, Already
Paid (§7). "InstaPay" is **not** a distinct canonical `PaymentType`; it falls under Bank Transfer
and is **not** fabricated as a separate line.

---

## 10. Collections Summary

Cash, Bank Transfer, Card, Already Paid, Total Collected and Actual Collected are all shown from
canonical collection rows. The verified-only subset ("Approved Transfers") remains a distinct,
canonical settlement figure in the settlement strip.

---

## 11. Expected Collection Availability

**Not canonically available.** Settlement reconciles **physical cash only**
(`cash_expected = Σ cash collections`); there is no canonical order-derived COD-target contract
wired into settlement. Per the §6 HARD RULE, Expected Collection is returned as
`expected_collection: null` + `expected_collection_available: false` and rendered as an explicit
**"Not available"** state with a one-line explanation. **No Finance value is computed in React or
invented in the backend.**

---

## 12. Vehicle Custody Summary (§8)

`custody_summary` aggregates the driver-day's custody + reconciliation: total loaded, total
delivered, expected return, actual return, accepted (good), damaged, shortage (variance),
remaining on-hand, and `lines_received / lines_total`. When **no shift reconciliation is opened**
(`reconciliation_available:false`), only the custody-engine figures (loaded/delivered/remaining)
are shown and the warehouse-counted fields report the honest **"No warehouse count opened for
this shift yet."** — never fabricated zeros.

---

## 13. Product Reconciliation (§9)

`product_reconciliation` is one row per product:

| Field | Source |
|---|---|
| Loaded / Delivered | `…Line` (or custody item when no line) |
| Expected Return | `…Line.quantity_returned_expected` = **Loaded − Delivered** (canonical) |
| Good Return (Actual Good) | `…Line.quantity_accepted` |
| Actual Return | `…Line.quantity_returned_actual` |
| Damaged | `…Line.quantity_damaged` |
| Shortage / Variance | `…Line.variance` |
| Status | `received` / `pending` / (`not_reconciled` for custody-only rows) |

Rows carry a `source` (`reconciliation` vs `custody`). A custody product with no reconciliation
line is shown with loaded/delivered/expected-return/remaining and the warehouse-counted fields
**null** (unknown, not zero), status `not_reconciled`.

---

## 14. Expected vs Actual Return

**Expected Return = Loaded − Delivered** (canonical `quantity_returned_expected`) — it is **not**
replaced by physical receipt. The warehouse **Actual Return / Good Return** (`quantity_returned_actual`
/ `quantity_accepted`) is a separate fact, kept distinct in its own columns.

---

## 15. Damage (§11)

`damage.items` lists product, quantity (`quantity_damaged`), reason (`damage_reason`) and warehouse
receipt time. Damage is shown **separately** from good returned stock (the canonical action never
`AdjustmentIn`s damaged units). **Deferred backend gap surfaced honestly** (`gap:
waste_investigation_deferred`): the `WasteInvestigation` disposition record is not raised for a
vehicle return because that model is NOT-NULL-coupled to inventory-count sessions. This is
documented in `ReceiveVehicleReturnAction`'s own contract, not a defect introduced here. **No
second waste authority was created.**

---

## 16. Shortage / Liability (§12)

`shortage_review.items` lists product, variance, reconciliation status and any resolution note.
Shortage is the **reconciliation variance**, **not** an automatic driver debt: `liability_confirmed:
false` and `gap: liability_attribution_deferred`. `WarehouseLiability` has no driver/vehicle/trip
attribution column and no create action, so a confirmed driver liability is an owner decision. **No
second liability model was created.**

---

## 17. Closing Readiness (§14)

`closing_readiness.ready` mirrors the **engine's real gate** — every trip settlement Reconciled
(`SettlementService::finalize` is reachable only from Reconciled). `blockers[]` surfaces the
operational reasons a close should wait: `stops_outstanding`, `reconciliation_not_opened`,
`unresolved_variance`, `cash_difference`, `settlement_not_reconciled`. The UI shows the blockers
and keeps the Close CTA disabled until the canonical gate is met, so it never claims Ready while a
canonical blocker remains.

---

## 18. Closing Authority (§15)

**Reused, not reimplemented.** The Close CTA finalizes each trip through the canonical per-trip
`tripSettlementService.finalize` (one call per reconciled trip) — the same
`SettlementService::finalize` used elsewhere. It is permission-protected (`logistics.distribution.update`),
disabled unless every trip is Reconciled, and shows why it is blocked. **No Finance posting, no
salary/driver-deduction, no second finalize endpoint, no day-level settlement record.**

---

## 19. Detail View (§16)

The detail drawer/page now carries: Driver/Vehicle/Trip header with closing-stage + settlement
badges; overview (orders/delivered/partial/failed/%/trips); the canonical settlement strip;
**Sales & Collections**; **Vehicle Custody** summary; and tabs — Overview, Orders (delivered/partial/
failed/returned filters), Transfers (+proof review), **Reconciliation** (product table + Damage +
Shortage + Goods-with-driver), Returns, **Timeline**, Settlement (canonical `TripSettlementTab`
per trip). **Timeline** is built from canonical timestamps only (dispatched, trip started/finished,
warehouse count opened/completed, cash submitted, reconciled, closed) and ordered.

---

## 20. Date Filters / Pagination / Sorting (§17, §20)

History presets (computed as calendar dates, resolved server-side against the settlement finalized
date): Today, This Week, This Month, Previous Month, This Year, Year to Date, Previous Year, Custom
(From/To). Pagination and sorting are **server-side** (`page`, `per_page`, `sort ∈
{driver,date,difference,delivery_pct}`, `dir`). Active custody is unaffected by the History period.

---

## 21. Empty / Error / Loading States (§19)

Workspace and detail distinguish **Loading** (spinner/skeleton), **Error** (retry CTA, no mutation
surfaced), **Empty** (scope-specific copy — "No drivers with open custody" vs "No closed settlements
in this period") and **Loaded**. A failed read renders the Error state, never a false "nothing to
settle".

---

## 22. RBAC / Security (§22)

Unchanged RBAC catalogue. Both reads stay gated on `logistics.distribution.view`; the finalize
write stays on `logistics.distribution.update` via the canonical service. Every query is fail-closed
to the acting company (`abort(403)` on a null company; explicit `where('company_id', …)` since these
models carry no global tenant scope). Cross-company access 404s (detail) / is absent (board), proven
by tests. Driver users gain no closing permission. No new authorization gap was found or introduced.

---

## 23. Finance Boundary (§23)

Out of scope and untouched: GL entries, revenue recognition, COGS posting, payroll/driver-deduction,
salary settlement, automatic shortage charge, expense accounting. This remains an **operational**
settlement workspace.

---

## 24. Backend Changes

- `DriverDaySettlementReadService` — extended (additive): `activeBoard()`, `historyBoard()` (server
  date-range/pagination/sort); reconciliation surfacing (`reconciliationForTrips`,
  `reconciliationAggregatesByTrip`); collections breakdown; delivery breakdown (partial/failed);
  six KPIs; derived closing stage (maps facts, no lifecycle); closing readiness; timeline. Existing
  `daySummary()` / `driverDay()` shapes preserved and enriched.
- `DriverDaySettlementController` — `scope` routing (day/active/history) with per-scope validation,
  pagination/sort params, and the new filters (`stage`, `has_damage`, `has_shortage`, `needs_review`).
  Tenancy guard unchanged.

No new tables, migrations, models, events, or write paths. No changes to any protected authority
(custody, reconciliation, delivery writer, warehouse receipt, waste, liability, settlement).

---

## 25. Frontend Changes

- `types/driver-settlement.ts` — new shapes (scope, KPIs, enriched rows, collections, custody
  summary, product reconciliation, damage, shortage, readiness, timeline, pagination).
- `services` + `hooks` — single `board(params)` for day/active/history; `useDriverSettlementBoard`
  (keeps previous page while fetching).
- `components/day-settlement-kpis.tsx` — six-KPI strip. `…status-badge.tsx` — added `ClosingStageBadge`.
- `pages/driver-settlement-workspace-page.tsx` — Active/History tabs, presets, server pagination,
  sortable history columns, damaged/shortage columns, closing-stage badge.
- `pages/driver-settlement-detail-page.tsx` — Sales & Collections, Vehicle Custody, Reconciliation
  (product table + Damage + Shortage + Goods), Timeline, closing readiness + blockers.
- `lib/history-range.ts` — new preset → {from,to} helper.
- i18n `en/ar logistics.json` `driverSettlement.*` — new keys, EN/AR parity (147 = 147).

No route or navigation change (existing route + nav entry reused).

---

## 26. Files Changed

**Backend**
- `backend/Modules/Logistics/Distribution/Domain/Services/DriverDaySettlementReadService.php`
- `backend/Modules/Logistics/Distribution/Presentation/Http/Controllers/DriverDaySettlementController.php`
- `backend/tests/Feature/Logistics/DriverDaySettlementReadTest.php`

**Frontend**
- `frontend/src/features/operations/driver-settlement/types/driver-settlement.ts`
- `frontend/src/features/operations/driver-settlement/services/driver-settlement-service.ts`
- `frontend/src/features/operations/driver-settlement/hooks/use-driver-settlement.ts`
- `frontend/src/features/operations/driver-settlement/lib/history-range.ts` *(new)*
- `frontend/src/features/operations/driver-settlement/components/day-settlement-kpis.tsx`
- `frontend/src/features/operations/driver-settlement/components/day-settlement-status-badge.tsx`
- `frontend/src/features/operations/driver-settlement/pages/driver-settlement-workspace-page.tsx`
- `frontend/src/features/operations/driver-settlement/pages/driver-settlement-detail-page.tsx`
- `frontend/src/features/operations/driver-settlement/pages/driver-settlement-workspace-page.test.tsx`
- `frontend/src/features/operations/driver-settlement/pages/driver-settlement-detail-page.test.tsx`
- `frontend/src/i18n/locales/en/logistics.json`
- `frontend/src/i18n/locales/ar/logistics.json`

---

## 27. Deferred Backend Gaps

Surfaced honestly in the UI; **not** implemented here (out of scope / owner decisions):

1. **Expected Collection contract** — no canonical order-derived COD target in settlement; shown
   "Not available".
2. **Damage → WasteInvestigation disposition** — model NOT-NULL-coupled to inventory-count sessions;
   damage kept out of good stock, waste record deferred (documented in `ReceiveVehicleReturnAction`).
3. **Shortage → driver-attributed WarehouseLiability** — no attribution column / create action;
   shortage stays the visible variance; driver liability = owner decision.
4. **Reconciliation is operator-OPENED** — 0 rows in DEV until a shift count is opened; the page shows
   an honest "not reconciled" state rather than fabricating figures.
5. **Read-model scale** — per-trip `financialSummary()` in the row builder and PHP-side history
   grouping/pagination are fine at pilot scale; a status column / denormalized rollup would harden
   very large histories. Noted, not required now.

---

## 28. Deferred Verification

Per current project policy: **no broad regression, no browser certification cycle, no module
certification.** Narrow, code-level verification only (below). Full verification is deferred to
Final System Review.

**Verification performed (narrow):**
- Backend: `php -l` clean (all changed files); **PHPStan clean** (both source files, project config);
  **`DriverDaySettlementReadTest` — 8 tests / 104 assertions OK** through the isolated test gate
  (`GATE_WAIT=2400`), covering day KPIs + new detail fields, tenant isolation, auth, validation, and
  the new **Active** and **History** scopes (finalized-range filter, pagination meta, closed-day
  exclusion from Active, range validation).
- Frontend: `tsc -p tsconfig.app.json` — **0 errors in the feature** (pre-existing baseline errors in
  unrelated files untouched, per the ratchet rule); **ESLint 0**; **Vitest 10/10**; i18n EN/AR parity
  **147 = 147**.
- No DEV/demo/live business data was created, modified, or closed; all backend assertions run on the
  isolated `RefreshDatabase` schema.

---

## Implementation notes on constraints honored

- **No second authority** (§25): custody, reconciliation, delivery writer, warehouse return receipt,
  waste, liability, payment-collection and settlement authorities are all **read-only-consumed**; the
  only write is the reused canonical per-trip finalize.
- **No fabricated data** (§1, §6, §11, §12): unavailable figures are explicit "not available" /
  "not reconciled" states with documented gaps.
- **No DEV mutation** (§26): verification via the isolated test DB only.
- **STOP conditions honored** (§30): no Finance, no other task, no broad regression, no browser
  certification, no commit/push/deploy.

---

IMPLEMENTATION STATUS:
COMPLETE

FINAL CERTIFICATION:
DEFERRED TO FINAL SYSTEM REVIEW

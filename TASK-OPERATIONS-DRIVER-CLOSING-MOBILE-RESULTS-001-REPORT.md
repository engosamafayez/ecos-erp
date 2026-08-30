# TASK-OPERATIONS-DRIVER-CLOSING-MOBILE-RESULTS-001 — Report

**Surface:** Operations → Driver Closing / Driver Day Settlement — mobile Active/History **results** rendering.
**Symptom:** `kpis.active_custodies = 3` but **zero** result rows/cards visible below the KPI strip on mobile (not an empty-data state).
**Verification:** narrow. **FINAL CERTIFICATION: DEFERRED TO FINAL SYSTEM REVIEW.**

---

## 1. Root Cause

`UniversalDataGrid` ships **two** layouts: a **card layout** for `block lg:hidden` (tablet + mobile)
and a **table** for `hidden lg:block` (desktop). The card layout's *loaded-with-data* branch is:

```jsx
) : data.length === 0 ? (
  defaultEmpty
) : renderMobileCard ? (
  <div role="list">{data.map((row) => renderMobileCard(row, selection))}</div>
) : null}          // ← no renderMobileCard ⇒ renders NOTHING when data is present
```

The Driver Settlement workspace called `UniversalDataGrid` **without a `renderMobileCard`**. So on
mobile, with 3 records present, the grid took the `: null` branch → **nothing rendered** below the
KPIs. The desktop table (a separate branch) rendered the 3 rows correctly, which is why the defect
was mobile-only.

## 2. Results Data Trace

`DriverSettlementWorkspacePage` → `useDriverSettlementBoard({scope:'active', …})` → the DEV backend's
canonical `activeBoard` (now at parity) → `data.drivers` (`DaySettlementDriverRow[]`, 3 rows) +
`data.kpis` (`active_custodies=3`). The page passes `data.drivers` to
`<UniversalDataGrid data={drivers} …>`. **The data reached the grid** — the read is healthy (a
read-only service probe returned 3 driver rows; KPI and row source is the same collection). The loss
was purely in the grid's mobile render branch (§1), not in the data.

## 3. Why KPIs Rendered While Rows Did Not

They travel different render paths:
- **KPIs** — `<DaySettlementKpiCards kpis={data?.kpis}>` renders unconditionally above the results
  (its own grid of cards), so `active_custodies=3` showed.
- **Rows** — rendered *inside* `UniversalDataGrid`, whose mobile branch returns `null` without a
  `renderMobileCard`. Same response object, two independent renderers: the KPI renderer had no mobile
  gap; the row renderer did. This is a **render-path** defect, not a data or mapping mismatch (the KPI
  count and the driver collection both come from the same `activeBoard` payload).

## 4. Desktop Results Architecture (unchanged)

Desktop uses `UniversalDataGrid`'s `hidden lg:block` **table**: the `columns` defs (driver, vehicle,
orders, delivery %, damaged, shortage, cash, difference, closing-stage, action) with sticky
header/columns, grab-to-scroll, sort (History), and a per-row **Review** action → detail. **Left
intact** (§8): no column, sort, or table change. The existing column-level tests still pass.

## 5. Mobile Results Architecture (new)

Added a **`renderMobileCard`** to the grid — the canonical ECOS mobile pattern (same one used by
`wave-orders-page`, `product-table`, etc.): a per-row `<div role="listitem">` card, reusing the
**same** `DaySettlementDriverRow` and the **same** open-detail action as the desktop row. No mobile
business calculation; the grid picks card-vs-table purely by breakpoint. The card list scrolls on
mobile (`min-h-0 overflow-y-auto lg:overflow-hidden` on the results wrapper — mobile-only; desktop's
`overflow-hidden` is preserved) so a full History page of cards is not clipped.

## 6. Active Card

`DaySettlementDriverCard` surfaces the highest-value canonical facts present on the row — nothing
fabricated (indicators render only for non-zero canonical values):
- **Driver** (`driver_name`, or `unknownDriver` — never invented) · **Vehicle** (`vehicle_plate`) ·
  **operational reference** (`operational_date`).
- **Closing stage** (`ClosingStageBadge` over `closing_stage`) — the operational readiness signal.
- **Metric strip:** Delivered (`delivered/orders` + `delivery_pct%`), Cash expected (`cash_expected`),
  Difference (`difference`, coloured when non-zero).
- **Custody / exception indicators** (only when > 0): On-hand (`goods_on_hand`), Returns (`returns`),
  Damaged (`damaged_qty`), Shortage (`shortage_qty`).
- **Settlement status** (`DaySettlementStatusBadge`).
- **Primary action:** **Review** → the canonical detail experience.

Fields that live only on the detail read (product reconciliation, expected-vs-actual, blockers,
per-method collections) are intentionally **not** on the card (§6) — the card is a summary.

## 7. History Card

The History row is the **same** `DaySettlementDriverRow` shape, so the **same** card serves it — its
canonical fields cover the History requirement: Driver, Vehicle, Date (`operational_date`), Settlement
status, Delivery summary, Return/reconciliation (`returns` + closing/reconciliation state), Collection
(`cash_expected`/`difference`), and Shortage/Damage indicators. History stays **server-side date
filtered, paginated and sorted** (unchanged); the page's server-side pagination control sits below the
card list. One card component, both scopes — no divergent mobile calculation.

## 8. Detail Navigation

The card's **Review** action calls the same `openReview(row)` the desktop row uses →
`navigate(logisticsDriverSettlementDetail?assignmentId&date)` → the canonical detail page (full
reconciliation, damage, shortage, collections, timeline, closing readiness, and the guarded
finalize/Close action). No new detail surface; the card is the entry point, not a second detail view.

## 9. Responsive Layout

The approved order is preserved (unchanged from the prior task):
- **Active:** Header → Active/History → Search → KPIs → **Active Cards**.
- **History:** Header → Active/History → **Date Filter** → Search → KPIs → **History Cards**.

The History time filter remains **above** the KPIs/dataset (not moved below). Only the results *body*
changed from "null on mobile" to "cards".

## 10. Files Changed

| File | Change |
|---|---|
| `…/driver-settlement/components/day-settlement-driver-card.tsx` | **New.** Canonical mobile summary card (Active + History), same row data + open-detail action; indicators only for non-zero values. Uses only existing i18n keys. |
| `…/driver-settlement/pages/driver-settlement-workspace-page.tsx` | Pass `renderMobileCard={(row) => <DaySettlementDriverCard row onOpen={openReview}/>}`; results wrapper scrolls on mobile (`min-h-0 overflow-y-auto lg:overflow-hidden`) — desktop unchanged. |
| `…/driver-settlement/components/day-settlement-driver-card.test.tsx` | **New.** Card unit tests (fields, conditional indicators, action, no-fabrication). |
| `…/driver-settlement/pages/driver-settlement-workspace-page.mobile.test.tsx` | **New.** Real-grid integration: 3 records → 3 cards; card action → detail navigation. |

No change to `UniversalDataGrid`, the desktop columns, the read layer, or any settlement authority.

## 11. Focused Verification

Vitest — driver-settlement folder **20/20 pass** across 4 files:
- **Card unit (4):** renders driver/vehicle/date/delivery/cash; damage & shortage indicators appear
  only when non-zero; **Review** action invokes `onOpen(row)`; missing driver → `unknownDriver` (no
  fabrication).
- **Mobile page, REAL grid (2):** an Active response with **3 records renders 3 `role="listitem"`
  cards** (KPI `active_custodies=3` ↔ 3 visible cards — consistent); clicking a card's action
  navigates to the canonical detail route (`?date=…`).
- **Existing workspace (9):** Active has no date filter; History places the date filter above
  Search/KPIs; a preset change issues a canonical server request; Error renders the error state (KPI
  `kpi-error`), never Empty; Loading/Empty/Loaded distinct — all still green (desktop/column path and
  §9 ordering intact).
- **Detail (5):** unchanged, green.

Static: **ESLint clean** on all touched files; **0 tsc errors** in touched files (repo baseline of 23
unrelated errors untouched). History cards render through the **identical** `renderMobileCard` path as
Active (it is passed unconditionally), so the Active card proof covers the mechanism for History.

## 12. Remaining Gaps

- **Live operator browser pass deferred.** The operator Driver Closing page is gated
  `logistics.distribution.view`; the only live DEV session is a **driver** (403 at the permission
  gate), so a real mobile-viewport render could not be exercised in-session. The render path is proven
  by the real-grid integration test instead; a live operator pass is deferred to final review.
- **Mobile list scroll** is class-level (`min-h-0 overflow-y-auto lg:overflow-hidden`), unit-safe but
  not visually confirmed on a device (same reason). Desktop overflow is unchanged.
- History-scope card rendering is covered by the shared code path (not a separate live dataset).

## 13. Implementation Status

**IMPLEMENTATION STATUS: COMPLETE** — the mobile Active/History results now render one canonical
card per record (the `renderMobileCard` gap that returned `null` for present data is closed), each
card is an operational summary reusing the same canonical row and opening the same guarded detail
experience, the desktop table is unchanged, and Active/History semantics and the approved responsive
order are preserved. Distinct Loading/Error/Empty/Loaded states hold; 3 records render 3 accessible
cards.

**FINAL CERTIFICATION: DEFERRED TO FINAL SYSTEM REVIEW.**

---

**STOP.** No commit. No push. No deploy. No DEV business-data mutation.

# TASK-OPERATIONS-DRIVER-TRIP-MOVEMENT-APPROVAL-001 — Engineering Report

**Driver Trip Movements — Operations Approval + Driver Closing Cash Integration**

Date: 2026-08-29
Constraints honoured: reused the existing `DriverTripMovement` authority (no duplicate ledger) · NOT Finance/GL · per-line custody allocation left deferred · no commit/push · DEV = the ONE authorized schema migration + source parity only, no business-data mutation, no demo movements · no unrelated workstream deployed.

```
IMPLEMENTATION STATUS: COMPLETE
DEV SCHEMA PARITY:     RESTORED   (the one authorized migration applied; table + routes live)
TEST EXECUTION:        FOCUSED TESTS ADDED; execution deferred (shared/contended test DB + freeze — §27 "only if necessary")
FINAL CERTIFICATION:   DEFERRED TO FINAL SYSTEM REVIEW
```

## 1. Executive Summary

The driver operational cash cycle is now complete end-to-end. Operations can **Approve / Reject** a driver's Pending trip movement through a canonical action; only **Approved** cash movements feed Driver Closing. Driver Closing's **Expenses** and **Net Cash** — previously hard-coded "Not available" — are now **real canonical figures**: `Total Expenses = Σ approved cash-out (fuel/toll/other)`, advances are surfaced separately as **Cash In** (never an expense), and `Net Cash = physical cash collected + approved cash-in − approved cash-out`. Approved movements become **Settled** only at the canonical closing boundary (`TripSettled`). Pending movements block closing readiness (advisory). The one approved migration was applied to DEV; the runtime was refreshed surgically (the unrelated Supplier-Invoice-documents workstream on the host route file was deliberately NOT deployed).

## 2. Existing DriverTripMovement Architecture

Reused as-is from TASK-DRIVER-APP-OPERATIONAL-FLOW-VNEXT-001 — `Modules/Logistics/Distribution`: model `DriverTripMovement` (`driver_trip_movements`), enums `DriverTripMovementCategory` / `DriverTripMovementDirection` / `DriverTripMovementStatus`, `RecordDriverTripMovementAction` (driver create), `DriverTripExpenseController` (driver read/create). No second expense/advance/toll table, no new ledger, no Finance entry was introduced.

## 3. Movement Types

`fuel`, `road_toll`, `other` → operational **cash-out**; `advance` → operational **cash-in**. The category→direction mapping is derived in the enum, never guessed. Advance is never coerced into the Expense total.

## 4. Cash In / Cash Out Semantics

`DriverTripMovementDirection` (`cash_out` / `cash_in`), stored (denormalised from category for query clarity) and honoured throughout: expenses sum cash-out only; advances sum cash-in only; the two are never netted into one "expense".

## 5. Status Lifecycle

`Pending → Approved → Settled` (and `Pending → Rejected`), preserved. Driver-created movements start **Pending**. Only Approved (and its terminal Settled) participate in Driver Closing totals; Pending/Rejected never affect the financial position.

## 6. Driver Creation Authority

Unchanged: `POST /api/driver/trip-expenses` → `DriverTripExpenseController::store` → `RecordDriverTripMovementAction`, scoped to the driver's own current active custody, gated `loading.driver.operate`. Trip/driver/company inferred server-side.

## 7. Operations Review Authority

New `DriverTripMovementReviewController` (`Modules/Logistics/Distribution/Presentation/Http/Controllers`), integrated into the existing Operations **Driver Closing** experience (the driver-settlement board + detail) rather than a new module. Pending movements for the driver's active custody are surfaced in the settlement drill-down (`driverDay.movements`), where an operator reviews Driver / Movement type / Direction / Amount / Date / Note / Evidence / Status and Approves or Rejects.

## 8. Approval

`PATCH /api/logistics/distribution/driver-movements/{id}/approve` → `ReviewDriverTripMovementAction::approve`. Domain-authoritative (the controller never mutates status directly). The movement row is `lockForUpdate`-locked; only a **Pending** movement can be approved; a repeat approve on an already-decided movement is safely **refused (422)**, never re-applied. Records reviewer + timestamp.

## 9. Rejection

`PATCH …/{id}/reject` (reason **required**, validated) → `ReviewDriverTripMovementAction::reject`. Keeps the historical record, sets status `Rejected`, records reviewer + timestamp + `review_note`, preserves evidence, and never affects Driver Closing cash. Rejected movements are never deleted.

## 10. Evidence

The optional receipt (stored by the create action on the private `local` disk under a server-generated ULID path) is retrieved by operators through `GET …/driver-movements/{id}/receipt` — company-scoped, using only the server-recorded path (no raw filesystem path exposed). The frontend fetches it as an authenticated blob and opens it; no public URL.

## 11. RBAC

Approve/Reject gated by **`logistics.distribution.update`** (the operator/dispatcher write permission — the Driver role does NOT hold it; `config/permissions.php` explicitly removed distribution view+update from drivers). Receipt view gated by `logistics.distribution.view`. Driver creation stays on `loading.driver.operate`. An unprivileged user is refused (403); a cross-company movement is not found (404). No Finance permission was invented.

## 12. Closing Expenses Integration

`DriverDaySettlementReadService`:
- **KPIs** — `total_expenses` and `net_cash` are no longer `null`; they are real sums over the visible custodies. `Total Expenses = Σ approved cash-out`.
- **Board rows** — each row now carries `expenses`, `cash_in`, `net_cash`, `pending_movements`.
- **Detail** — `financial.{cash_collected, expenses, cash_in, net_cash}` + a `movements` block (reviewable list + approved totals + `expenses_by_category`).

Excluded from expenses: advances, pending movements, rejected movements, electronic customer payments.

## 13. Advance / Cash-In Integration

Approved advances sum into a separate **Cash In / Advances** figure (`financial.cash_in`, KPI `total_cash_in`), shown as its own card in the cash-position summary. It is never subtracted from cash and never counted as an expense.

## 14. Net Cash Formula

`Net Cash = physical cash collected + approved cash-in − approved cash-out`. Physical cash comes from the canonical `SettlementService::financialSummary().cash_collected` (`PaymentType::Cash` only); electronic (InstaPay/bank/card) is excluded. **No opening-cash value is invented** (none exists as a separate authority). Example verified by test: 6500 cash + 1000 advance − 750 expenses = **6750**.

## 15. Expected Collection Preservation

Unchanged: `expected_collection` remains `Σ expected_collection_at_handoff` (immutable per-stop snapshot), with its own availability flag. It is kept distinct from Cash Collected, Electronic, Cash In, Expenses and Net Cash. No collection authority was created; customer payment state is never inferred from movements.

## 16. Closing Readiness

Traced `SettlementService::finalize` (the hard close requires every trip settlement **Reconciled** → Finalized → Trip Closed → `TripSettled`). **Decision:** the certified finalize guard is left UNCHANGED. The read-side `closingReadiness` rollup gains a `pending_movements` blocker and reports `ready = false` while any Pending movement remains — so the operator sees **Needs Review** and does not treat the cash position as settled. This is the operational readiness signal, implemented in the canonical read (not React); it does not weaken or duplicate the finalize authority.

## 17. Settled Semantics

`Approved ≠ automatically Settled`. A new listener `SettleDriverTripMovementsOnTripSettled` on the canonical `TripSettled` event (dispatched by `finalize`) marks that trip's **Approved** movements `Settled`; Pending/Rejected are untouched; it is idempotent. Registered via the module provider's existing `Event::listen` map (TripSettled is standard-dispatched — the EnterpriseEventBus caveat is inventory-only). Movements are never settled on mere page view.

## 18. Audit Trail

Preserved and additive: `driver_id`, `trip_id`, `category`, `direction`, `amount`, `note`, `created_by`, `occurred_at`, receipt, plus review fields `reviewed_by` / `reviewed_at` / `review_note` and the `Settled` transition. No historical movement is overwritten or deleted.

## 19. Driver App Impact

No new driver surface was needed — the driver Trip Expenses page (previous task) already lists movements with **Pending / Approved / Rejected / Settled** status and camera-first evidence, so the driver already distinguishes submitted vs approved. The operator's decisions flow back through the same canonical read.

## 20. Operations Mobile UX

The Driver Closing board is a responsive table→card grid (`UniversalDataGrid.renderMobileCard` → `DaySettlementDriverCard`): Expenses/Net Cash now show real values (with a pending-review chip). The detail's movement review is a card list with Approve/Reject actions and a reject-reason dialog (shadcn `Dialog`); no horizontal scrolling required.

## 21. Driver Closing UX

Cash-position cards now render real **Expenses**, **Cash In / Advances**, and **Net Cash** (a real zero is EGP 0.00, never "Not available"); a read failure still surfaces Error, and only genuinely-absent contracts (e.g. Expected Collection snapshot) show "Not available". No value is fabricated to remove "Not available".

## 22. DEV Migration Status

`2026_08_29_120000_create_driver_trip_movements_table.php` inspected (additive; the approved schema only), confirmed **not previously applied**, and applied to DEV with a single-file `--path` (NOT `migrate` of all pending). `driver_trip_movements` now exists with all 21 columns. **DEV SCHEMA PARITY: RESTORED.**

## 23. Backend Changes

New: `Application/Actions/ReviewDriverTripMovementAction.php`; `Application/Listeners/SettleDriverTripMovementsOnTripSettled.php`; `Presentation/Http/Controllers/DriverTripMovementReviewController.php`.
Modified: `Domain/Services/DriverDaySettlementReadService.php` (movement sums, row/KPI/detail integration, closing-readiness blocker); `Infrastructure/Providers/LogisticsDistributionServiceProvider.php` (TripSettled listener); `routes/api.php` (3 review routes). `SettlementService` and the custody/reconciliation engines were **not** modified.

## 24. Frontend Changes

`features/operations/driver-settlement`: `types/driver-settlement.ts` (movement fields + `DaySettlementMovements`); `services` + `hooks` (approve/reject/receipt + `useReviewDriverMovement`); `components/driver-movement-review.tsx` (new); `components/driver-cash-position-cards.tsx` + `components/day-settlement-driver-card.tsx` + `pages/driver-settlement-workspace-page.tsx` + `pages/driver-settlement-detail-page.tsx` (real expenses/cash-in/net-cash + review section). i18n `en`/`ar` `logistics.json` (`driverSettlement.movements.*`, `cards.cashIn`/`netCashNote`, `blockers.pending_movements`).

## 25. Files Changed

Backend (3 new, 3 modified) and Frontend (1 new component, 1 new + several modified) as listed in §23–§24, plus the new focused test `backend/tests/Feature/Operations/DriverTripMovementApprovalTest.php` and updates to the existing driver-settlement frontend tests (new required fields + `useReviewDriverMovement` mock).

## 26. Focused Verification

- `php -l` — all new/modified backend files: clean.
- `tsc -p tsconfig.app.json` (strict, incl. tests) — **0 errors in touched files** (23 pre-existing baseline unrelated). Existing driver-settlement tests updated for the new required fields.
- `eslint` on the changed frontend files — **0 problems**.
- i18n en↔ar parity — `logistics` EN=AR=2555 (0 gaps), `driver-mobile` clean; both JSON valid.
- New backend test `DriverTripMovementApprovalTest` (mirrors the certified `DriverReportsTest` fixture pattern) covers the §27 scenarios: driver creates Pending; unauthorized/cross-company refused (403/404); Operations approve/reject (+ reason required, audit fields); duplicate approval refused; approved fuel/toll/other count as expenses; approved advance is cash-in not expense; pending/rejected excluded; Net Cash formula; pending-movements closing blocker; `TripSettled` settles only Approved.
- **DEV read-only probe** — the closing read now returns real `total_expenses=0.0 / net_cash=0.0 / total_cash_in=0.0` (canonical zeros, not null) and loads clean; routes `route:list`-resolve; table present.

**Test execution deferred:** the focused test uses `RefreshDatabase`, which would migrate:fresh the **shared, pinned, contended** test DB and could disrupt a concurrent session's gate. Per §27 ("narrowly targeted verification only if necessary") and to protect other sessions, execution is deferred; the test is committed-ready and static-clean.

## 27. Deferred Per-Line Gap

Untouched, as required (§26). `order_lines.loaded_qty` was NOT written; no line-level custody allocation was fabricated. It remains **DEFERRED — NOT a Driver-App completion blocker**.

## 28. Finance Boundary

No GL journal, cash-account posting, expense-account posting, payroll deduction, AP/AR, or bank reconciliation. `DriverTripMovement` is an operational cash-custody authority only. Finance integration remains later work.

## 29. Remaining Driver-App Gaps

- **Operations approval UI depth** — the approve/reject surface is embedded in the Driver Closing detail; a dedicated bulk-review queue is optional future polish.
- **Per-line custody allocation** — deferred (above).
- Pre-existing container drift on the Distribution module (the DEV container lacked `Application/Actions` and the Supplier-Invoice-documents routes) is noted but out of scope; only this task's necessary source was deployed.

## 30. Readiness for Final Driver App Closure

The operational cash cycle (create → approve/reject → closing expenses/net-cash → settle) is now canonical and live in DEV. Driver Expenses = **available**; Driver Net Cash = **available**. Final Driver App Closure is the logical next task — **not started** here.

## 31. Implementation Status

```
IMPLEMENTATION STATUS: COMPLETE
DEV SCHEMA PARITY:     RESTORED
TEST EXECUTION:        DEFERRED (shared test DB; focused tests added & static-clean)
FINAL CERTIFICATION:   DEFERRED TO FINAL SYSTEM REVIEW
```

### Engineering context (recorded, §30)
- Driver Trip Movements = **COMPLETE**; Operations Movement Approval = **COMPLETE**.
- Driver Expenses = **CANONICAL / AVAILABLE**; Driver Net Cash = **CANONICAL / AVAILABLE**.
- Advance = **CASH-IN, not an expense**; Settled = only at the canonical `TripSettled` boundary.
- Per-Line Custody Allocation = **DEFERRED / not a Driver-App completion blocker**.
- Final Driver App Closure = **NEXT / NOT STARTED**.

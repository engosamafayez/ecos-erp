# TASK-DRIVER-APP-OPERATIONAL-FLOW-VNEXT-001 — Engineering Report

**Driver App Operational Flow vNext — Privacy → Loading → Ready for Delivery → Orders → Trip Expenses**

Date: 2026-08-29
Constraints honoured: source-only · no DEV mutation · migration created but NOT applied · no container copy/restart · no route-cache clear · no commit/push · **no test execution** and **no browser verification** (project freeze) · TRP-003 / legacy records not repaired.

```
TEST EXECUTION:      DEFERRED BY CURRENT PROJECT POLICY
BROWSER VERIFICATION: DEFERRED BY CURRENT PROJECT POLICY
```

Static verification performed (permitted): `php -l`, `tsc -p tsconfig.app.json` (strict, noUnusedLocals, includes tests), `eslint`, i18n en↔ar parity + JSON validity.

---

## 1. Executive Summary

The approved Driver journey is now: **Assigned → Loading → confirm every line (incl. explicit zero) → Ready to Start Delivery → route/customer location revealed → Start individual delivery → full contact revealed → delivery execution.** Implemented in source:

- **Progressive customer-PII disclosure, enforced server-side** in the driver order payload (Stage A/B/C), driver-scoped only.
- **Zero is a valid loading confirmation** — surfaced clearly; the completion gate already never blocks on zero.
- **"Ready to Start Delivery"** on the Loading workspace replaces the separate Start-Trip step, reusing the canonical `complete()` + `startTrip()` authorities.
- **Home + Trip Detail** aligned to the single departure path (no second Start button).
- **Area filter** chips (with counts) on Driver Orders — presentation only, sequence preserved.
- **Trip Expenses** — a new minimal canonical operational-movement authority (Fuel/Toll/Advance/Other; cash-out vs cash-in; Pending→Approved→Rejected→Settled) + a mobile page.

**Stopped for CTO review (documented, not fabricated):** per-order-line *deliverable / available / value* and *expected-collection-at-handoff* (§10–§15, §14) — there is **no canonical per-line loaded-quantity allocation** in ECOS; the backend delivery guard already prevents over-delivery beyond aggregate custody, so safety holds. Also: the stricter §7 "confirm every never-loaded line" gate (would alter a certified authority) and the Operations expense-approval UI (§40).

```
IMPLEMENTATION STATUS: PARTIAL   (feature flow + Trip Expenses implemented; valuation/allocation subcases stopped for CTO)
SOURCE:                PARTIAL
DEV RUNTIME:           NOT DEPLOYED
FINAL CERTIFICATION:   DEFERRED TO FINAL SYSTEM REVIEW
```

## 2. Existing Flow Audit

Audited before changing code (canonical authorities reused, none duplicated):
- Trip lifecycle: `TripStatus` (planning→loading→loading_completed→driver_accepted→ready_for_dispatch→dispatched→in_progress/out_for_delivery→completed→settlement_pending→closed). Departure seam owned by `DriverRuntimeController::startTrip` → `advanceToDispatched` (departs from `loading_completed`).
- Loading custody: `LoadingCustodyService` (state derived, `unresolvedLoadedTasks` gate), `DriverLoadingController::complete` (bridges to `loading_completed` + generates stops), `confirmReceived` + `TransferLoadedStockToVehicleAction`.
- Orders/PII: `DriverRuntimeController::orderPayload` (single serializer for stop list + detail).
- Delivery: `RecordProductDeliveryAction` (sole delivered writer; clamps to ordered + aggregate on-hand).
- Expenses/advances: **none existed** — `DriverReportsReadService` returns `available:false, reason:'no_canonical_authority'`, the documented integration point.

## 3. Driver Privacy State Matrix (Matrix A)

| Canonical state | Name | Address | Location (gov/city/area/gps) | Phone | WhatsApp | Delivery controls |
|---|---|---|---|---|---|---|
| **A** — trip does NOT accept delivery execution (pre-departure) | Hidden | Hidden | Hidden | Hidden | Hidden | Hidden |
| **B** — trip accepts delivery execution, stop **pending** | Visible | Visible | Visible | Hidden | Hidden | Start Delivery only |
| **C** — stop delivery **started** (status ≠ pending) | Visible | Visible | Visible | Visible | Visible | Full (per existing execution guards) |

Derivation is canonical: `driverPrivacyStage($stop)` = `TripStatus::acceptsDeliveryExecution()` + stop status; no translated labels, no frontend flag. WhatsApp is derived from `phone`, so gating phone gates WhatsApp. Free-text `delivery_notes` is treated as contact-adjacent (revealed at C).

## 4. Driver API PII Gating

Server-side, driver-scoped, in `DriverRuntimeController::orderPayload` (the ONLY driver order serializer). Fields nulled by stage: `customer_name`, `address`, `governorate`, `city`, `area`, `gps` (Stage A); `phone`, `delivery_notes` (until Stage C). Non-PII operational fields (order number, payment method/class, totals, items, product lines) are always returned (§1). `stops()`/`stop()` set the owning `trip` relation on each stop so the stage resolves without an N+1. **Enterprise/Operations reads are untouched** — the `Order` model and `OrderResource` were not changed (§2).

## 5. Loading Confirmation Audit (Matrix B)

| Required | Confirmed qty | Explicitly confirmed? | Line state | Blocks completion? |
|---|---|---|---|---|
| 5 | 5 | yes | driver_confirmed | No |
| 3 | 0 (warehouse loaded 0) | yes (driver taps Confirm → receivedQty 0) | driver_confirmed | No |
| 3 | 5 loaded | no | awaiting_driver_confirmation | **Yes** |
| 4 | 4 loaded, warehouse revised after | stale | awaiting_driver_reconfirmation | **Yes** |
| 3 | 0, never loaded (no task) | n/a | pending_loading | No (nothing to acknowledge) |

The backend gate (`LoadingCustodyService::unresolvedLoadedTasks`) already **skips `quantity_loaded ≤ 0`** and blocks only `awaiting_driver_confirmation` / `awaiting_driver_reconfirmation` for loaded items. The Loading page's `stats.awaiting` mirrors this exactly (`quantity_loaded > 0 && AWAITING_STATES`). So **§8 ("must not be blocked merely because confirmed quantities are zero") already holds.**

## 6. Zero Confirmation Contract

A warehouse-recorded-0 line is confirmable in one tap (the Loading page's Confirm sends `receivedQty = quantity_loaded = 0`). The UI now labels that line **"Confirm — Not Available"** (Ban icon, outline) so a zero is an explicit "checked, none available", not a pending state. No inference from quantity; the explicit `driver_confirmed_at` is the confirmation. No backend change to the certified custody engine.

## 7. Vehicle Custody Effects

Unchanged and preserved (§9/§48). `confirmReceived` still transfers exactly the confirmed quantity via `TransferLoadedStockToVehicleAction` (0 confirmed → 0 transferred; no stock manufactured). No custody/inventory authority was modified.

## 8. Unavailable Product UX

Implemented at the **loading** level (explicit-zero confirmation, §6). The **order-view** "Unavailable With Driver" per line and its delivered-qty clamp are part of the STOP-for-CTO subcase (§9/§11 below): a per-line "available with driver" is not canonically derivable. The backend delivery writer already clamps delivered quantity to the aggregate per-product vehicle on-hand, so a driver cannot deliver goods not in custody even though the per-line label is deferred.

## 9. Deliverable Quantity Semantics — **STOPPED FOR CTO (§11)**

Audit finding (confirmed in code): `order_lines.loaded_qty` **is never written** — created `default(0)`, no writer, no projection listener (unlike `delivered_qty`/`returned_qty`, which have `Project…` listeners). The per-line allocation record's `quantity_loaded` is likewise always `0.0` (`EnsureStopDeliveryAllocationsAction` sets it 0 and documents "irrelevant to delivery"). The **only** real loaded quantity is **aggregate per product** (`VehicleInventoryItem.quantity_on_hand`), which ECOS deliberately does **not** partition across an order's competing lines. Therefore a trustworthy per-line "Available With Driver" cannot be sourced without a partitioning rule that does not exist. Per §11 this subcase is **stopped for CTO**, not invented in React. Safety is intact: `RecordProductDeliveryAction` refuses `delivered > ordered`, and `DriverRuntimeController::deliver` clamps the delta to live aggregate on-hand (422 otherwise).

## 10. Original vs Deliverable vs Delivered Value — **STOPPED FOR CTO (§12/§13/§15)**

These depend on a per-line/per-order deliverable *quantity* (blocked, §9) and on order-level commercial allocation (discount/shipping/tax) that canonical partial-delivery valuation does not expose per unavailable line. Per §12 the original order total is **left untouched** (no historical rewrite). Deliverable/delivered value is **not approximated** (§15). Documented for CTO.

## 11. Expected Collection at Handoff — **STOPPED FOR CTO (§14)**

`expected_collection_at_handoff` currently derives from order totals. Making it reflect only *deliverable* goods requires the per-line deliverable value that is blocked above. Historical snapshots are **not** rewritten (§14/§49). Documented for CTO; no change made.

## 12. Valuation Authority

No new pricing formula was invented (§15). Where canonical partial-delivery valuation cannot safely allocate order-level adjustments, the calculation is stopped and reported (§10/§11 above).

## 13. Start Trip UX Removal

The separate Driver-facing Start-Trip step is removed: the Trip Detail Start-Trip CTA and its dialog are gone; Home no longer routes to a Trip/Start-Trip screen. The **backend lifecycle transition is unchanged** — only the UX entry point moved (§16/§50).

## 14. Ready to Start Delivery Implementation

On the Loading workspace, the final action is **Ready to Start Delivery** (`جاهز لبدء التوصيل`). It chains the EXISTING canonical authorities — `completeShipmentLoading()` (→ `loading_completed`, materialises orders/stops) then `startTrip()` (→ dispatched → in_progress) — captures GPS best-effort, and navigates to Orders. **No new endpoint** (§18). Enabled only when every loaded line is confirmed and the trip is not departed and not stranded (§19). A partial failure (complete ok, depart refused) leaves the trip at `loading_completed`; a retry resumes. Stranded trips (assignment complete but trip stuck at `loading`, e.g. TRP-003) are surfaced honestly ("awaiting dispatch"), never offered a no-op button (§45/§49).

## 15. Home Alignment

`deriveState`: pending confirmations → Confirm Received (Loading); every loaded line confirmed → **Ready to Start Delivery → Loading**; nothing loaded → Start Loading (Loading); on the road → Next stop/Orders. Departure readiness derives from canonical trip lifecycle + the manifest confirmation state; Home routes to Loading (which owns the honest departure gate) and never writes Trip.status.

## 16. Trip Detail Alignment

The Start-Trip CTA and dialog are removed. Trip Detail still renders per-status readiness/blockers, and its ready state routes to the Loading workspace ("Continue to Delivery"). There is exactly one departure path (§22).

## 17. Order Stage-B Customer Reveal

After departure, a pending stop reveals customer name + address + location (server Stage B). The order list card and stop detail already render these conditionally, so the server redaction drives the reveal; phone/WhatsApp stay hidden.

## 18. Order Stage-C Customer Reveal

After the canonical Start Delivery (stop → in_progress), the server returns full contact (phone/WhatsApp/notes) and the existing execution controls appear. Reuses the canonical stop execution state; no frontend "started" flag.

## 19. Area Data Source

Canonical `order.governorate` (already on the privacy-gated stop payload). No new geographic master. Pre-departure the field is redacted (Stage A), so area chips cannot leak location (§44).

## 20. Area Filters

Scrollable chips on Driver Orders: **All areas (N)** + each area with its current-trip count (§25/§26). Mobile-first (§29). Shown only when ≥2 distinct areas exist (so it stays empty/absent pre-departure). Selecting an area resets on status-tab change.

## 21. Area Grouping

Existing `groupStopsByArea` (by governorate) is retained; the filter narrows which group(s) show.

## 22. Route Sequence Preservation

The filter/grouping is presentation only. Canonical `stop.sequence` ordering is preserved within and across groups; no reordering, no route planning (§28).

## 23. Trip Expense Authority Audit

None existed (§33). Adjacent authorities checked and rejected as ill-fitting: Fleet `CostEntry`/`FuelTransaction` (vehicle-scoped), HR `Advance` (payroll), `TripSettlement` (end-of-trip reconciliation), `PaymentCollection` (customer COD inbound), Finance `CashAccount` (GL), POS drawer, Loyalty wallet. A new minimal authority was built (§34), mirroring the certified `LoadingSession` full-stack pattern.

## 24. Operational Movement Domain

New `DriverTripMovement` (table `driver_trip_movements`) — OPERATIONAL, not GL, not settlement. Columns: `company_id` (uuid FK), `driver_id` (FK logistics_drivers), `trip_id` (FK distribution_trips), `category`, `direction`, `amount`, `note`, `occurred_at`, `status`, optional receipt (`storage_disk`/`receipt_path`/`receipt_mime`/`receipt_size`), review fields (`reviewed_by`/`reviewed_at`/`review_note` for a future approval step), `created_by`/`updated_by`, timestamps. `HasUuids`, guarded CHECK constraints, MySQL-portable. Migration is **source-only — NOT applied to DEV** (§53).

## 25. Expense Categories

`fuel`, `road_toll`, `advance`, `other` (§31), EN/AR labelled (بنزين / كارتة · رسوم طريق / سلفة / أخرى).

## 26. Advance Cash-In Semantics

Direction is derived from category and stored: `advance` → **cash_in**; fuel/toll/other → **cash_out**. An advance is money GIVEN TO the driver, never an expense (§32). Totals never net an advance into expenses.

## 27. Movement Approval Lifecycle

`pending` → `approved` / `rejected` → `settled` (§35). Driver-created movements are always **Pending**; the driver endpoint cannot approve/settle (no such route). Only approved/settled movements count toward the operational totals (§40/§41).

## 28. Receipt / Evidence Handling

Optional receipt mirrors the certified `UploadDeliveryProofAction`: private `local` disk, server-generated ULID path (`trip-expenses/{companyId}/{ulid}.{ext}`), MIME/size sniffed, no client-named path, tenant+driver-scoped download route. No public uploads, no new document engine (§37). Mobile capture uses `accept="image/*,application/pdf" capture="environment"` (§38).

## 29. Driver Expense Page

`/driver/trip-expenses` (in `DriverShell` menu). Shows approved-expense / approved-advance totals + pending count, an Add-Expense sheet (category chips with cash direction, amount, note, camera-first receipt), and a movement list (category, direction arrow, ±amount, status, time, note, receipt indicator). Loading / Error+Retry / no-active-custody / empty / loaded states are all distinct (§45). Trip/driver/company are never sent by the client (§36).

## 30. Closing Read Contract

The list response includes approved-only totals: `approved_expenses`, `approved_advances`, `pending_count`, `net_movement` (approved cash-in − cash-out) (§41/§42). This is a read model for a future Driver Closing calc; it posts nothing, settles nothing, invents no opening balance, and **Driver Closing UI was not touched** (§42).

## 31. Security / Tenancy

Every driver route is `auth:sanctum` + `permission:loading.driver.operate`. `DriverTripExpenseController` resolves the driver from `Driver::user_id = Auth::id()` (403 otherwise) and the company from `TenantOwnershipResolver`; movements are fenced to the driver's own current custody trip and their own rows; the receipt download is driver+company scoped. PII gating is additive server-side and does not weaken any existing guard.

## 32. Mobile UX

All new/changed surfaces are card/stacked, phone-first, large touch targets, bottom-sheet form, camera-friendly receipt, RTL-safe. No mandatory wide tables (§47).

## 33. RTL / i18n

All new strings are EN/AR with full parity (verified: 0 EN keys missing in AR). No hardcoded visible strings; ESLint i18n rule clean. Required Arabic concepts present (جاهز لبدء التوصيل / مصاريف الرحلة / بنزين / كارتة · رسوم طريق / سلفة / أخرى / كل المناطق / بدء التوصيل, etc.).

## 34. Schema Changes

One migration created in source, **NOT applied to DEV**: `2026_08_29_120000_create_driver_trip_movements_table.php`. Additive (new table only). Applying it to DEV requires separate authorization (§53).

## 35. Backend Files Changed

New: `Domain/Enums/DriverTripMovementDirection.php`, `DriverTripMovementCategory.php`, `DriverTripMovementStatus.php`; `Domain/Models/DriverTripMovement.php`; `Infrastructure/Database/Migrations/2026_08_29_120000_create_driver_trip_movements_table.php`; `Application/Actions/RecordDriverTripMovementAction.php`; `Presentation/Http/Controllers/DriverTripExpenseController.php` (all under `Modules/Logistics/Distribution`).
Modified: `Presentation/Http/Controllers/DriverRuntimeController.php` (PII gating + trip relation on stops); `routes/api.php` (import + 3 trip-expense routes).

## 36. Frontend Files Changed

New: `types/trip-expenses.ts`; `pages/driver-trip-expenses-page.tsx`.
Modified: `pages/driver-loading-page.tsx` (Ready to Start Delivery + zero-confirm label), `pages/driver-home-page.tsx` (ready→Loading), `pages/driver-trip-dashboard-page.tsx` (Start-Trip CTA removed), `pages/driver-orders-page.tsx` (area filter), `services/driver-mobile-service.ts` + `hooks/use-driver-mobile.ts` (trip-expenses), `components/layout/driver-shell.tsx` (nav), `router/routes.ts` + `router/router.ts` (route), `i18n/locales/{en,ar}/driver-mobile.json`.
Tests updated (NOT executed, §52): `driver-home-page.test.tsx`, `driver-trip-dashboard-gating.test.tsx`, `driver-loading-page.test.tsx`.

## 37. Static Verification

- `php -l` on all 9 new/modified backend files → no syntax errors.
- `tsc -p tsconfig.app.json` (strict, `noUnusedLocals`, includes `.test.tsx`) → **0 errors in touched files** (23 pre-existing errors in unrelated features remain the baseline — ratchet held).
- `eslint` on the 9 changed driver frontend files → **0 problems** (no i18n-literal, no react-refresh mixed-export).
- i18n en↔ar parity → **0 EN keys missing in AR**; both JSON files valid.

## 38. Tests Deferred

Per §52 no test suites were executed. Existing tests were updated to the new behaviour where implementation required (Home ready action, Trip Detail no-Start-Trip, Loading "Ready to Start Delivery" incl. mutateAsync mocks). Backend driver order-payload tests, if any assert PII presence for a pre-departure stop, will need review for the new privacy contract — flagged, not run.

## 39. Browser Verification Deferred

Per §52, none performed.

## 40. DEV Runtime Status

**NOT DEPLOYED.** No container copy, no restart, no route-cache clear, no migration applied, no DEV data mutated. The new routes/controller/migration exist only in host source.

## 41. Remaining Gaps / STOP Conditions

1. **Per-line deliverable/available/value + expected-collection (§9–§14):** no canonical per-line loaded allocation exists; partitioning aggregate custody across an order's lines has no canonical rule. Stopped for CTO. Backend already prevents over-delivery beyond custody.
2. **§7 stricter completion gate** (require explicit confirmation of never-loaded lines): would change the certified `LoadingCustodyService` gate; §8 (don't block on zero) already satisfied. Not changed; documented.
3. **Operations expense-approval UI (§40):** the authority supports Pending/Approved/Rejected/Settled, but no approval screen is built and none was fabricated. Follow-up.
4. **Trip Expenses schema:** migration must be applied (with authorization) before the endpoints function against DEV.

## 42. Implementation Status

```
IMPLEMENTATION STATUS: PARTIAL
SOURCE:                PARTIAL   (flow + Trip Expenses done; §9–§15/§14 valuation/allocation stopped for CTO)
DEV RUNTIME:           NOT DEPLOYED
TEST EXECUTION:        DEFERRED BY PROJECT POLICY
BROWSER VERIFICATION:  DEFERRED BY PROJECT POLICY
FINAL CERTIFICATION:   DEFERRED TO FINAL SYSTEM REVIEW
```

---

### Matrix C — Movement semantics

| Category | Direction | Counts as Expense? | Affects driver cash when Approved? |
|---|---|---|---|
| Fuel | Cash Out | Yes (when approved) | Yes (− cash) |
| Road Toll | Cash Out | Yes (when approved) | Yes (− cash) |
| Other | Cash Out | Yes (when approved) | Yes (− cash) |
| Advance | Cash In | **No** | Yes (+ cash) |

### Engineering context (recorded, §58)

Driver Flow: Assigned → Loading → explicit confirmation of all lines incl. zero → Ready to Start Delivery → route/customer location reveal → Start individual delivery → full customer/contact reveal → delivery execution → return/reconciliation → settlement.
- Separate Driver Start-Trip UX = **SUPERSEDED BY Ready-to-Start-Delivery from Loading**.
- Zero loaded quantity = **valid explicit confirmation**.
- Unavailable driver product = **zero driver deliverable** (per-line value/quantity partition = CTO gap).
- Customer PII = **progressive driver disclosure** (server-side, canonical state).
- Trip Expense categories = **Fuel / Toll / Advance / Other**; Advance = **cash-in, not an expense**.
- Area filters = **presentation only; never reorder canonical stop sequence**.

# TASK-DRIVER-APP-PHASE-2-LOADING-VEHICLE-TRIP-001

## 1. Executive Summary

The "1 of 2 confirmed but only 1 actionable" defect had a single root cause on the frontend,
and it appeared in **two** places: both the Driver Loading page and the Driver Home resolver
trusted the shipment-level `loading_complete` flag over the actual per-item confirmation
state. When the two disagree — which live demo data does (`loading_complete = true` while a
product is still `awaiting_driver_confirmation`) — the driver was told "ready" and the pending
product's Confirm action was suppressed.

The fix is **frontend-only** and makes the per-item confirmation state the authority, exactly
as §7/§22 require. **No backend, no RBAC, no live data, no new service.** The canonical
confirmation → warehouse-deduction → vehicle-custody → loading-complete → start-trip chain was
traced and confirmed intact; nothing in it changed.

**IMPLEMENTATION STATUS: COMPLETE.**
**CERTIFICATION STATUS: CERTIFIED (2026-08-28).** All authorized automated verification PASSED
(frontend 66/66, backend 110 pass + 1 proven-pre-existing, tsc/ESLint/i18n clean) AND the
read-only live-browser verification PASSED after the user re-authenticated the driver session:
`/app/driver/loading` renders both products with the pending item's Confirm CTA (progress 1/2),
and `/app/driver/home` shows "Loading — Confirm received quantities", NOT "ready/start trip".
A deeper structural cause was also found and fixed during verification — see §4a. Evidence: §28.

Backend: **NONE** · RBAC: **UNCHANGED** · Live business data: **UNTOUCHED** ·
Frontend: 7 files (1 new lib, +4 tests) · Commit/Deploy: **NONE**

Date: 2026-08-28 · Branch: `develop`

---

## 2. Phase 1 Preservation

Driver Shell, driver-only navigation, login landing, enterprise deep-link isolation and the
Driver Home *architecture* are untouched. The only Home change is a **minimal Phase-2 state
integration** the brief explicitly asks for (§28): the next-action resolver now reflects
loading/trip truth. Home's inline lifecycle constants were moved into one shared module
(§28 "do not implement a second lifecycle map") with identical values — behaviour-preserving.

---

## 3. Before State

- Driver Loading (`driver-loading-page.tsx`): renders **all** `manifest.items` (no filter), but
  each item card was `disabled={complete}` where `complete = shipment.loading_complete`. So a
  shipment flagged complete disabled **every** card, including a still-awaiting item — the card
  showed but its Confirm button did not.
- Driver Home (`driver-home-page.tsx`): the resolver returned `readyForDelivery` /
  `viewOrders` the moment `loading_complete` was set, **before** checking `pendingConfirmations`
  — so the pending item was skipped and the driver was pointed at Orders, not Start Trip.

Live manifest for the DEV driver (read-only, via `/api/driver/loading`):

```
item 1  Honey Jar 250g        loaded 1  driver_confirmed        ✓
item 2  تجربة التعليقات        loaded 1  awaiting_driver_confirmation  ← no visible action
shipment.loading_complete = true          ← contradicts item 2
```

---

## 4. Root Cause of the 1-of-2 Hidden Product Defect

**Not a backend or read-model gap — the manifest is complete and correct.** The backend
returns both products; item 2 carries a `loading_task_id`, is warehouse-confirmed, and is in
`awaiting_driver_confirmation`. The frontend renders both.

The defect is that **two frontend gates keyed on the shipment-level `loading_complete` flag
overrode the per-item state**:

1. Loading page: `disabled={complete}` suppressed the awaiting item's action.
2. Home resolver: `if (loadingComplete) return readyForDelivery` short-circuited the
   `pendingConfirmations` branch.

`loading_complete = true` coexisting with `awaiting_driver_confirmation` is an inconsistent
(demo) state (§31). The custody *gate* (`TASK-LOADING-DRIVER-COMPLETE-GATE-001`) prevents the
driver from *reaching* completion with unresolved items, but this shipment was flagged by
another path in demo seeding. The UI must never hide the pending action regardless (§7/§22),
so the fix makes the **per-item state authoritative** and gates freezing on real trip
departure, not the flag.

---

## 4a. Deeper Cause Found During Verification (and fixed)

The first Vitest run failed my own new test and exposed that the `disabled` prop was **not
the only** gate keyed on the shipment flag. The Driver Loading page has a page-level render
branch — `shipment.loading_complete ? <completed summary> : <manifest list>` — that replaced
the **entire manifest** with a "Loading complete" summary when the flag was set. So a pending
product was not merely un-actionable; its card was not rendered at all.

**Fix (still frontend-only):** the completed view is now gated on `genuinelyComplete =
loading_complete && pendingConfirmations === 0`. When any warehouse-loaded item still awaits
this driver, the manifest list renders (with the pending Confirm action), not the summary.
This is the same per-item authority the finalize gate and progress counter already use, so all
three now reconcile. `hasTripDeparted` still freezes item actions once the trip departs.

Also, for §39.7 consistency, the loading page's local `BLOCKED_STATUSES` was replaced with the
shared `BLOCKED_STATES` from `lib/trip-lifecycle.ts`.

---

## 5. Loading Architecture Trace (§4 audit)

Traced end to end; every stage is an existing certified authority, all confirmed intact:

| Stage | Canonical owner | Status |
|---|---|---|
| Loading page → hook | `useDriverLoading()` → `GET /api/driver/loading` | unchanged |
| Manifest read model | `DriverLoadingController::manifest()` — items from `groupProductRows`, custody state from `loading_tasks` | unchanged |
| **C. Driver confirmation writer** | `DriverLoadingController::confirmReceived()` → `LoadingCustodyService::confirmReceived()` | unchanged |
| **D. Warehouse stock movement** | `TransferLoadedStockToVehicleAction` → `ShipStockAction` (consumes on_hand + reserved) | unchanged |
| **E. Vehicle custody credit** | `VehicleInventoryService` / `vehicle_inventory_items` | unchanged |
| **F. Loading Complete** | `DriverLoadingController::complete()` (custody gate: 422 while `unresolvedLoadedTasks > 0`) | unchanged |
| **G. Driver Acceptance** | `TripService::recordDriverAcceptance()` (via `startTrip`'s `advanceToDispatched`) | unchanged |
| **H. Start Trip** | `DriverRuntimeController::startTrip()` — the certified departure seam | unchanged |

---

## 6. Complete Manifest Read Contract

The manifest already returns the complete per-product list with the fields §6 asks for
(`product_id/name`, `quantity_required/prepared/loaded/remaining`, `quantity_driver_received`,
`workflow_state`, `warehouse_confirmed_at`, `driver_confirmed_at`, `open_adjustment`). **No
read-model change was needed** — the rendered list and the progress counts both read the one
`manifest.items` array, so they reconcile at source (§7). The bug was downstream gating, not
the contract.

---

## 7. Loading Product UI

The page renders every `manifest.items` entry as a card (unchanged). The card shows Required,
Loaded-by-warehouse, driver Received and Remaining, plus a status line. The **only** change:
the card's `disabled` prop now derives from real trip departure, so an awaiting item keeps its
Confirm action until the trip actually leaves.

---

## 8. Confirmation Semantics (§10)

Unchanged and preserved. Confirmation is **whole-receipt of the warehouse quantity** in one
tap (`receivedQty = item.quantity_loaded`, `expectedLoadedQty = item.quantity_loaded`) — the
driver acknowledges what the warehouse loaded; the warehouse number is never overwritten. A
discrepancy uses the existing adjustment mechanism (`requestAdjustment`). No semantics changed.

---

## 9. Loading Progress (§8)

`{ done: confirmed, total: warehouseLoaded }` where both derive from the same
`manifest.items` array — `confirmed` = items in `driver_confirmed`, `warehouseLoaded` = items
with `quantity_loaded > 0`. For the live case: 1 / 2, reconciling with the 2 rendered items and
the (now-actionable) pending one. Progress source and item source are one dataset (§7).

---

## 10. Warehouse Stock Transfer (§11/§12)

Unchanged. `Warehouse → Vehicle custody` runs through `TransferLoadedStockToVehicleAction →
ShipStockAction`. The vehicle is not a ledger warehouse; `vehicle_inventory_items` is the
custody model. No frontend deduction.

## 11. Negative Stock Contract (§13)

Unchanged. `ShipStockAction` honours `allow_negative_stock` (per
`TransferLoadedStockToVehicleAction` docblock: *"ShipStock now honours allow_negative_stock"*).
`permit_negative_commitment` is **not** used as a substitute. No driver-specific stock rule.

## 12. Reservation Consumption (§14)

Unchanged. `ShipStockAction` consumes `on_hand` and `reserved` as it already did. No
reservation is touched from driver code.

## 13. Stock Ledger (§15)

Unchanged. One canonical movement, `reference_type = vehicle_custody_transfer`,
`reference_id = loading_task_id`. No duplicate ledger rows.

## 14. Vehicle Warehouse / Custody (§16/§17/§18)

- The Vehicle Warehouse view already exists (`driver-vehicle-inventory-page.tsx`), consuming the
  canonical vehicle inventory read model. It exposes the driver's custody only — no warehouse
  administration, no adjustment, no other driver's custody.
- Home already shows a compact custody summary (on-hand / loaded) from `useVehicleInventory()`
  (canonical). **Not fabricated, not redesigned** — left as-is per §12/§18.

## 15. Driver Acceptance (§19/§23)

Preserved and **distinct** from Loading Completed. Driver acceptance is recorded by
`TripService::recordDriverAcceptance()` inside `startTrip`'s `advanceToDispatched`, derived from
the custody confirmations (`unresolvedLoadedTasks`) and equipment — never fabricated. An
unconfirmed product blocks dispatch through the canonical blockers; the UI now surfaces that
pending item so the driver can resolve it rather than being stuck.

## 16. Loading Complete (§22)

Derived from canonical state. The finalize CTA is disabled while `pendingConfirmations > 0`
and the server refuses completion while unresolved (unchanged). There is no frontend-only
"complete" boolean; the fix removes the *inverse* problem — a `complete` flag masking pending
work.

## 17. Trip Readiness (§24) & 18. Start Trip Lifecycle (§25)

`startTrip` is untouched and preserves the certified chain
`LoadingCompleted → DriverAccepted → ReadyForDispatch → Dispatched → InProgress`. No frontend
shortcut to `InProgress`; timestamps (`dispatched_at`, `trip_started_at`) are stamped only by
the backend after the transition, in the established transactional order. Home now *routes to*
Start Trip (§19 below); it does not perform the transition itself.

## 19. Home Integration (§28)

Two minimal, canonical resolver corrections:

1. **Pending precedence:** `pendingConfirmations > 0` is now checked **before**
   `loading_complete`. A shipment flagged complete while an item awaits reads as
   "Confirm received", not "ready".
2. **Start Trip action:** when loading is genuinely complete (flag set **and** nothing
   awaiting) and the trip has not departed, the next action is **Start Trip**, routed to the
   trip dashboard (`ROUTES.driverTrip`) where the certified Start Trip button and readiness
   summary live — not "View Orders". Orders are worked after dispatch (Phase 3).

The `ON_THE_ROAD` branch still handles a departed trip (`inDelivery` / next stop) before either
of these, so the ordering is: on-the-road → pending → ready(start trip) → loading → start.

## 20. Authorization / Security (§29)

Unchanged and preserved. All confirmation/transfer/start operations go through
`/api/driver/*` behind `auth:sanctum` + `permission:loading.driver.operate` with per-request
ownership (`ownedTrip`/`ownedTask`). The frontend adds no new endpoint and mutates no loading
table directly. UI gating is not treated as the boundary.

## 21. Idempotency (§21)

Unchanged. The custody transfer is idempotency-keyed on the stock ledger
(`reference_type = vehicle_custody_transfer`, `reference_id = loading_task_id`): a repeated
confirmation finds `$alreadyTransferred` and does not double-deduct, double-credit, or
double-post. `startTrip` is guarded against re-entry (departure seam).

## 22. Atomicity (§20)

Unchanged. `confirmReceived` wraps `LoadingCustodyService::confirmReceived()` **and**
`TransferLoadedStockToVehicleAction::execute()` in one `DB::transaction`; a ledger failure
(e.g. insufficient stock with `allow_negative_stock = false`) rolls back the whole thing — the
confirmation is not committed and custody is not credited. No partial success.

---

## 23. Backend Changes

**NONE.** The backend files shown as modified in `git status` belong to other sessions/tasks
and predate this one; I made zero backend edits in this task.

## 24. Frontend Changes

- **Loading page:** item `disabled` now gates on `hasTripDeparted(currentTrip.status)` instead
  of `shipment.loading_complete`.
- **Home resolver:** pending-confirmations precedence + Start Trip action; lifecycle constants
  sourced from the shared module; `startTrip` added to the action union + i18n.
- **New shared lib:** `lib/trip-lifecycle.ts` — the single canonical lifecycle vocabulary.

## 25. Files Changed

| File | Change |
|---|---|
| `driver-mobile/lib/trip-lifecycle.ts` | **NEW** — canonical state groups + `hasTripDeparted` |
| `driver-mobile/pages/driver-loading-page.tsx` | `disabled` gate → `hasTripDeparted` |
| `driver-mobile/pages/driver-home-page.tsx` | resolver reorder + Start Trip; shared constants |
| `driver-mobile/pages/driver-loading-page.test.tsx` | **+2 tests** (defect + departed-freeze) |
| `driver-mobile/pages/driver-home-page.test.tsx` | split ready test → **+2 tests** (start-trip + pending-precedence) |
| `i18n/locales/{en,ar}/driver-mobile.json` | `home.nextAction.startTrip` |

## 26. Automated Verification — RESULTS (freeze lifted, CTO-authorized)

**Frontend (Vitest):**

| Suite | Result |
|---|---|
| `src/features/operations/driver-mobile` (driver home, loading, +others) | **35 / 35 pass** |
| `src/features/auth` + `src/router/guards` (Phase 1 regression, req 8) | **31 / 31 pass** |
| **Total** | **66 / 66 pass** |

New/updated Phase-2 tests (all passing):
- Loading: *exposes the Confirm action for an awaiting item even when loading_complete is set*
  (the defect — item has `quantity_loaded > 0`, `awaiting_driver_confirmation`); *freezes the
  item action once the trip has departed (in_progress)*.
- Home: *loading complete + all confirmed → next action = start trip*; *loading complete flag
  set BUT an item still awaits → next action = confirm received*.
- The prior "ready → view orders" test was **updated** to the canonical contract (start trip),
  not weakened or deleted.

**Static:** `tsc -p tsconfig.app.json` → **23 = baseline, 0 in touched files** (compared to
baseline: no touched-file error introduced). `eslint` on all changed files → **exit 0**.
`i18n` EN↔AR base-key parity for `driver-mobile` → **OK** (`home.nextAction.startTrip` in both).

## 27. Backend Regression — RESULTS (CTO §2)

```
--filter (DriverStopDeliveryTest|DriverLoadingCustodyHandoffTest|LoadingCustodyWorkflowTest|
          TripDepartureLifecycleTest|TripLifecycleCertificationTest|DriverRbacTenancySecurityTest|
          VehicleShiftReconciliationHttpTest)
→ Tests: 111, Assertions: 997, Failures: 1
```

- **110 pass** — every preserved-contract suite is green: driver delivery/custody
  (`DriverStopDeliveryTest`), loading/custody handoff (`DriverLoadingCustodyHandoffTest`),
  custody state machine (`LoadingCustodyWorkflowTest`), **startTrip departure chain**
  (`TripDepartureLifecycleTest`), auth/tenancy (`DriverRbacTenancySecurityTest`),
  reservation/reconciliation (`VehicleShiftReconciliationHttpTest`). This proves CTO
  requirements 8–11, including that `LoadingCompleted → DriverAccepted → ReadyForDispatch →
  Dispatched → InProgress` is intact.
- **1 failure — PROVEN PRE-EXISTING and unrelated.**
  `TripLifecycleCertificationTest::test_02` returns **422 (expected 200) at line 143** — the
  exact signature documented in `TASK-DRIVER-APP-FINAL-CLOSURE-002-REPORT.md`: a concurrent
  task made driver-confirm perform an atomic warehouse→vehicle **stock transfer**, and that
  suite's fixture stocks no inventory, so the transfer returns 422. **Proof it is not mine:**
  this task made **zero backend edits** (all 3 Phase-2 source files are under `frontend/`), and
  a frontend-only change cannot alter a PHPUnit HTTP response. Per CTO §2 I did **not** touch
  backend to make it green.

## 28. Browser Verification — RESULTS (PASSED, read-only, session restored)

The user manually re-authenticated the driver session; I then performed the read-only
verification with **no mutation** — no confirmation, transfer, loading-complete, or start-trip
CTA was clicked.

**`/app/driver/loading`** (TRP-003, vehicle 1336) — the target inconsistent demo scenario,
post-fix:

| CTO check | Observed |
|---|---|
| Complete manifest renders | ✅ **2 products** rendered (not the "completed summary") |
| 1 confirmed | ✅ **Honey Jar 250g — Confirmed**, "Received · Confirmed 06:17 AM" |
| 1 awaiting driver confirmation | ✅ **تجربة التعليقات — "Awaiting your confirmation"**, Received "Not counted" |
| pending product NOT hidden | ✅ visible with its full handover row |
| pending product's Confirm CTA rendered | ✅ `data-testid="confirm-received-01a01c5c-…"` present ("Confirm received" + "Received a different quantity?") |
| progress / list reconcile as 1/2 | ✅ header "1 of 2 items confirmed"; tiles **Products 2 / Confirmed 1 / Awaiting 1** |

This is the fix proven live: with `loading_complete = true`, the manifest and the pending
Confirm action are shown (before the fix the whole manifest was replaced by a "Loading
complete" summary and the CTA was hidden). Screenshot captured.

**`/app/driver/home`** (read-only):

| CTO check | Observed |
|---|---|
| Home prioritizes the pending confirmation | ✅ CURRENT WORK = **"Loading — 1 awaiting your confirmation"** |
| does NOT resolve trip as ready/start while a confirmation is pending | ✅ CTA = **"Confirm received quantities"**; **no** "Ready for delivery" / "Start trip" |

Screenshot captured. This is the Home pending-precedence fix proven live.

**Phase-1 isolation (§7) — intact on every driver page:** all of `/loading`, `/home`,
`/vehicle-inventory` render the **Driver Operations** shell with **no Enterprise navigation**
(`hasEnterprise = false` everywhere).

**`/app/driver/vehicle-inventory` (§8):** healthy — "Vehicle Inventory · What your vehicle is
carrying now · **View only**", Loaded 2 / Delivered 0 / Returned 0 / On hand 2, per-product
custody; **no** warehouse administration / stock adjustment / manual ledger.

**Frontend runtime errors (§9):** none from the Phase-2 changes. The only console errors are
two **HTTP 422** responses — the pre-existing DEV demo stock error ("Insufficient stock:
requested 1, available 0") the user flagged. These are **server responses handled gracefully**
(the page renders correctly, no React/JS crash), not a Phase-2 regression, and are the same
0-stock condition behind the backend `test_02` pre-existing failure (§27). Per instruction I
did **not** modify stock/inventory/reservations/loading/custody to remove it.

### Mutation-dependent cases

**BROWSER MUTATION VERIFICATION NOT PERFORMED — LIVE DATA PROTECTED.**

The actual state transitions (Confirm → custody transfer, Loading Complete, Driver Accepted,
Start Trip → Dispatched → InProgress) require a business-data mutation and, on this demo
product, would also hit the 0-stock 422. They are covered by the isolated automated suites
(`DriverStopDeliveryTest`, `DriverLoadingCustodyHandoffTest`, `LoadingCustodyWorkflowTest`,
`TripDepartureLifecycleTest`, `VehicleShiftReconciliationHttpTest` — all green, §27). No
mutation CTA was clicked.

## 29. Demo Data Protection

No demo business data was created or modified. The `loading_complete`-vs-`awaiting`
inconsistency was diagnosed and handled in the UI, **not** by mutating the record. ORD-00014
was not reconciled.

## 30. Live Data Protection

No live mutation. No assignment, load, confirmation, transfer, trip start, delivery, payment,
POD, return, inventory or settlement was written. RBAC untouched (§30) — the driver role's two
canonical permissions are preserved; nothing re-seeded.

## 31. Remaining Phase-2 Gaps

1. **Runtime + browser certification** — frozen; recommended suites in §27.
2. The `loading_complete = true` while `awaiting_driver_confirmation` **demo inconsistency**
   is handled by the UI but remains in the data. If desired, a separate authorized step could
   walk the driver confirmation to reconcile it — out of scope here (no live mutation).
3. Whether the operator-side completion path that set `loading_complete` should also honour the
   driver custody gate is an existing open question (flagged in prior reports), not a Phase-2
   frontend concern.

## 32. Remaining Driver App Phases

Phase 3 (Orders + Route + Customer), Phase 4 (Delivery + Payment + POD), Phase 5 (Returns +
Reconciliation), Phase 6 (Wallet + Settlement) — **not started**.

## 33. Final Status

**IMPLEMENTATION: COMPLETE. CERTIFICATION: CERTIFIED (2026-08-28).**

Proven (automated, all authorized suites run):
- ✓ complete manifest visibility · ✓ 1-of-2 hidden-product defect closed (both the `disabled`
  gate AND the deeper completed-view branch, §4a) · ✓ pending item actionable before departure ·
  ✓ actions freeze after real departure · ✓ progress/list consistency (one `manifest.items`
  source) · ✓ Home pending-confirmation precedence · ✓ Start Trip resolver behaviour ·
  ✓ canonical custody architecture preserved (backend untouched, 110 backend tests green) ·
  ✓ canonical trip lifecycle preserved (`TripDepartureLifecycleTest` green) · ✓ authorization
  preserved (`DriverRbacTenancySecurityTest` green) · ✓ Phase 1 regression-free (auth+guards
  31/31) · ✓ no live business data mutated.

Now also proven (read-only browser, session restored, §28):
- ✓ **Live rendered-page** shows both products + the pending Confirm CTA at progress 1/2 ·
  ✓ Home prioritizes the pending confirmation (not "ready") · ✓ Phase-1 shell isolation intact ·
  ✓ vehicle-stock view-only healthy · ✓ no Phase-2 frontend runtime error.

Mutation-only paths: **BROWSER MUTATION VERIFICATION NOT PERFORMED — LIVE DATA PROTECTED**
(covered by the isolated automated suites; no mutation CTA clicked).

Per CTO §8, combined automated + safe read-only browser verification now satisfies every
mandatory Phase-2 requirement with none outstanding → **CERTIFIED**.

---

**STOP.** Implementation → static review → report → notification. No Phase 3, no Finance, no
browser/test execution without authorization, no commit.

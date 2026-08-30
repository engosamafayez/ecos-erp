# TASK-DRIVER-EXPERIENCE-UX-AND-ORDERS-FLOW-REWORK-001 — Architecture / UX Audit

**Status: AUDIT ONLY. No code, DB, permissions, migrations, translations, or business data were modified.**
Methodology: read-only source inspection + read-only DB inspection (`information_schema` / `SELECT`) + three parallel read-only Explore agents. All claims are anchored to `file:line`.

---

## TL;DR — the one fact that explains all three complaints

There is a **single missing bridge** between loading and delivery, and it produces every symptom the owner saw:

> When the driver taps **Loading Complete**, `DriverLoadingController::complete()` does exactly one durable thing — it flips the *VehicleAssignment* to `loading_complete`. It does **not** create `distribution_delivery_stops`, does **not** advance `distribution_trips.status` past `loading`, and fires **no** event. The **only** code that materialises delivery stops is a **dispatcher-only** endpoint (`POST /trips/{tripId}/stops/generate`, permission `logistics.distribution.update`) that the driver experience never calls.

Consequences, all from that one gap:

| Symptom the owner reported | Mechanism |
|---|---|
| **Issue 2/4** — "No stops match the filter" after loading | `distribution_delivery_stops` has **0 rows**; the stops page reads that empty table. |
| **Issue 3** — Dashboard looks like an empty ERP page ("0 orders today") | The dashboard's order count is `stops_count`, which falls back to `stops()->count()` on the **same empty table** → `0`, even though the trip has 4 `distribution_trip_orders`. |
| **Issue 1** — Loading screen confusing | Separate, milder problem: the Required / Warehouse / Received distinction *already exists* but is rendered as a dense 4-cell numeric grid with no visual dominance (a presentation defect, not a data defect). |

**Live DB evidence (read-only):** `distribution_trip_orders` = 4 rows, `distribution_delivery_stops` = **0 rows**; trips TRP-001/002 stuck at `status='loading'`; one `loading_tasks` row with `driver_confirmed_loaded_qty = NULL` (the Part 7 "unresolved" case exists in real data).

**Two things the owner asked us to build already exist and must be PRESERVED, not rebuilt:**
- **Part 7** (server-side completion block while a loaded item is unresolved) is **already enforced** — `DriverLoadingController::complete()` calls `LoadingCustodyService::unresolvedLoadedTasks()` and returns `422` with a `pending_confirmations` count ([DriverLoadingController.php:274](backend/Modules/Logistics/Distribution/Presentation/Http/Controllers/DriverLoadingController.php:274)).
- **Part 1's three-quantity distinction** (Required vs Warehouse-loaded vs Driver-received) **already exists** on the loading page ([driver-loading-page.tsx:104](frontend/src/features/operations/driver-mobile/pages/driver-loading-page.tsx:104)). The rework is visual hierarchy, not new semantics.

**One critical risk found (Part 14):** the certified handoff test `test_completing_the_loading_is_explicit_and_idempotent` **contradicts** the completion gate and cannot currently pass against it (proof in §14). This must be resolved before touching anything.

---

## 1. Current Driver Dashboard analysis (`/app/driver/home`)

**File:** [driver-home-page.tsx](frontend/src/features/operations/driver-mobile/pages/driver-home-page.tsx) (128 lines). Route `ROUTES.driverHome = '/driver/home'` → `DriverHomePage`.

**What it renders today** — by explicit design it is *not* a dashboard ("the driver's entry point, deliberately minimal … This screen is not a dashboard", [driver-home-page.tsx:11,20](frontend/src/features/operations/driver-mobile/pages/driver-home-page.tsx:11)). It calls exactly one hook, `useDriverTrips()` → `GET /driver/trips`, and shows one of four states:
1. Skeleton while fetching.
2. Error + retry (correctly distinguishes a 401/403 from an idle driver — [driver-home-page.tsx:42-44](frontend/src/features/operations/driver-mobile/pages/driver-home-page.tsx:42)).
3. Empty ("No shipment assigned yet").
4. **Assignment present:** a single big number (`assignedOrders`) + one button ("Start Loading").

**Why it looks empty / ERP-ish:**
- **The one number is wrong in exactly this scenario.** `assignedOrders = Σ trip.stops_count` ([driver-home-page.tsx:50](frontend/src/features/operations/driver-mobile/pages/driver-home-page.tsx:50)). `stops_count` is `$trip->stops_count ?? $trip->stops()->count()` ([DriverRuntimeController.php:419](backend/Modules/Logistics/Distribution/Presentation/Http/Controllers/DriverRuntimeController.php:419)) — both branches count `distribution_delivery_stops`, which is empty → the driver sees **"0"** despite having a real, loaded shipment. This is the visible face of the §4 root cause.
- **Only one metric, one hardcoded action.** No vehicle, no trip stage, no item count, no loading progress. The CTA always routes to `driverLoading` regardless of `trip.status` ([driver-home-page.tsx:116](frontend/src/features/operations/driver-mobile/pages/driver-home-page.tsx:116)) — a driver mid-delivery is still told "Start Loading".
- **`trip.status` is fetched but never surfaced** — it is consumed only to compute the `isBlocked` boolean ([driver-home-page.tsx:54](frontend/src/features/operations/driver-mobile/pages/driver-home-page.tsx:54)); the lifecycle stage is invisible.
- **The richer read model is never wired in.** The dashboard never calls `GET /driver/loading`, so loading-complete / items / pending-confirmations (all already available) are absent.

**None of the 10 questions the owner listed are answered except #1 and a broken #6.**

---

## 2. Current Loading page analysis (`/app/driver/loading`)

**File:** [driver-loading-page.tsx](frontend/src/features/operations/driver-mobile/pages/driver-loading-page.tsx) (432 lines).

**Strongest point — the three quantities are already distinguished** (per product card, `LoadingItemRow`, [driver-loading-page.tsx:104-135](frontend/src/features/operations/driver-mobile/pages/driver-loading-page.tsx:104)):
- **REQUIRED** = `quantity_required` (line 106-107)
- **LOADED BY WAREHOUSE** = `quantity_loaded`, commented *"The WAREHOUSE's number. The driver reads it and never edits it."* (line 110-114)
- **RECEIVED BY DRIVER** = `quantity_driver_received`, `null` shown as "not counted", never as `0` (line 117-124)
- **DIFFERENCE** = signed, negative in `text-destructive` (line 125-134)

**Why it still reads as confusing (Issue 1) — presentation, not data:**
- **4-cell numeric grid = warehouse-clerk density.** Four near-identical `text-xs` tabular numbers per product ([driver-loading-page.tsx:104-135](frontend/src/features/operations/driver-mobile/pages/driver-loading-page.tsx:104)). No number visually dominates; at arm's length in a loading bay this invites mis-reads and — the owner's exact fear — confusing the warehouse quantity with one's own.
- **Two competing status vocabularies.** A cosmetic badge `pending/partial/loaded` derived only from quantities ([driver-loading-page.tsx:43-47,98](frontend/src/features/operations/driver-mobile/pages/driver-loading-page.tsx:43)) can show green **"loaded"** while the same card is still counting toward `pendingConfirmations` and blocking completion (which uses `workflow_state`). Badge and gate can disagree to the eye.
- **Seeded input invites rubber-stamping.** The received-qty input is pre-filled with the warehouse number ([driver-loading-page.tsx:84](frontend/src/features/operations/driver-mobile/pages/driver-loading-page.tsx:84)), so the one-tap happy path confirms the warehouse figure without the driver actually counting — opposite to custody intent.
- **Load-bearing text is the smallest on screen.** The "why you can't complete" reason is `text-xs` amber below a large disabled button ([driver-loading-page.tsx:415](frontend/src/features/operations/driver-mobile/pages/driver-loading-page.tsx:415)).
- **No shipment identity in the header** ([driver-loading-page.tsx:274-284](frontend/src/features/operations/driver-mobile/pages/driver-loading-page.tsx:274)) — no trip code / plate / warehouse / date.

**Positives to preserve:** icon+text (not colour-only) state; 44px touch targets (`h-11`/`h-12`); honest skeleton/error/blocked/empty/completed separation; server refusals surfaced verbatim ([driver-loading-page.tsx:343-350](frontend/src/features/operations/driver-mobile/pages/driver-loading-page.tsx:343)); the completion gate mirror (§7).

**The "Next" trap.** On completion, "Next" routes to `driverTripStops` ([driver-loading-page.tsx:263-268](frontend/src/features/operations/driver-mobile/pages/driver-loading-page.tsx:263)) — straight into the empty stops page. This is the seam between Issue 1 and Issue 2.

---

## 3. Current Orders/Stops analysis (`/app/driver/trips/{trip}/stops`)

**File:** [driver-stop-list-page.tsx](frontend/src/features/operations/driver-mobile/pages/driver-stop-list-page.tsx) (103 lines).

- Reads `useDriverStops(tripId)` → `GET /driver/trips/{tripId}/stops` → `DriverRuntimeController::stops()`, which returns `$trip->stops()->orderBy('sequence')->get()` — a `HasMany` to **`distribution_delivery_stops`** ([Trip.php:158], the 0-row table). No server-side status filter; it returns `[]`.
- The client filters `(stops ?? [])` by tab + search ([driver-stop-list-page.tsx:30-41](frontend/src/features/operations/driver-mobile/pages/driver-stop-list-page.tsx:30)); with an empty array `filtered.length === 0` → renders the hardcoded **"No stops match the filter."** ([driver-stop-list-page.tsx:97](frontend/src/features/operations/driver-mobile/pages/driver-stop-list-page.tsx:97)).
- **The frontend is behaving correctly.** It faithfully renders an empty server response. The message is even slightly misleading (it implies a filter is hiding rows, when there are no rows at all), but the page is not the defect.
- Minor: this page still has hardcoded English labels (`'Stop List'`, `'All'`, the empty message) rather than i18n keys — worth aligning during the rework, but not the cause.

---

## 4. Exact reason orders are not appearing (the root cause)

**The manifest → stops bridge is never crossed by any driver-reachable action.**

Canonical converter (the *only* writer of `distribution_delivery_stops`):
```php
// DeliveryService::generateStops — backend/.../Domain/Services/DeliveryService.php:33
foreach ($trip->tripOrders()->get() as $tripOrder) {
    if (in_array($tripOrder->order_id, $existing, true)) continue;
    $trip->stops()->create([
        'order_id' => $tripOrder->order_id,
        'sequence' => ++$sequence,
        'status'   => DeliveryStopStatus::Pending->value,
    ]);
}
```
Its **only** production caller is `DeliveryController::generateStops`, exposed at [api.php:1917](backend/routes/api.php:1917) as `POST /trips/{tripId}/stops/generate` behind `permission:logistics.distribution.update` — a **dispatcher/back-office** action. A repo-wide search for `generateStops` finds only this controller + the service (all other hits are tests/docs). **No event listener calls it.**

What driver loading completion actually does ([DriverLoadingController.php:285-289](backend/Modules/Logistics/Distribution/Presentation/Http/Controllers/DriverLoadingController.php:285)):
```php
$assignment->update([
    'status' => VehicleAssignmentStatus::LoadingComplete->value,
    'loading_completed_at' => now(),
    'updated_by' => (string) Auth::id(),
]);
```
It updates the **VehicleAssignment** only. It does not call `generateStops`, does not call `TripService::changeStatus`, and fires no event. So `distribution_trips.status` stays `loading`.

**Two independent breaks, both traced:**
- **(Primary) No stop materialisation.** Nothing wires loading-complete (nor the `VehicleReleased` event fired at dispatch, which itself has **no listener**) to `DeliveryService::generateStops`. Stops table stays empty → stops page empty, dashboard count `0`.
- **(Secondary) No trip advancement.** Because the trip never moves past `loading`, `TripStatus::acceptsDeliveryExecution()` is false ([TripStatus.php:91-100](backend/Modules/Logistics/Distribution/Domain/Enums/TripStatus.php:91)), so even if stops existed the driver's `startDelivery`/`stop` actions would be refused.

This is **not** a wrong-table bug: `stops()` correctly targets `distribution_delivery_stops`; that table is simply never populated for driver-driven trips. The only current way to populate it is an out-of-band dispatcher call the driver flow never makes.

---

## 5. Current canonical data flow (as-is vs intended)

```
Distribution planning ─▶ distribution_trip_orders   (manifest; 4 rows ✓)
                              │
   driver loads products ─▶ DriverLoadingController::complete
                              │   sets VehicleAssignment = loading_complete, loading_completed_at
                              │
                              ▼   ✗ BREAK — no generateStops, no trip transition, no event
        [MISSING] trip: loading → loading_completed         (TripService::changeStatus)
        [MISSING] DeliveryService::generateStops(trip)      → distribution_delivery_stops (stays 0 ✗)
        [MISSING] dispatch: trip → out_for_delivery         (DispatchVehicleAction / acceptsDeliveryExecution)
                              │
                              ▼
   DriverRuntimeController::stops → $trip->stops() (distribution_delivery_stops) → []  ("No stops…")
   DriverHomePage.assignedOrders  → Σ stops_count → stops()->count() → 0  ("empty dashboard")
```

**Identity/ownership chain (correct, fail-closed, reused everywhere):**
`bearer token → Auth::id() → logistics_drivers.user_id → Driver.id → distribution_trips via driverVehicleAssignment (driver_id) → fenced to company_id` ([DriverRuntimeController.php:354-372](backend/Modules/Logistics/Distribution/Presentation/Http/Controllers/DriverRuntimeController.php:354), [DriverLoadingController.php:423-465](backend/Modules/Logistics/Distribution/Presentation/Http/Controllers/DriverLoadingController.php:423)). The rework must not alter this.

**Canonical state machines (existing — do not invent new states):**
- `TripStatus` (13 states): `planning → loading → loading_completed → driver_accepted → ready_for_dispatch → dispatched → out_for_delivery/in_progress → completed → settlement_pending → closed` (+`dispatch_blocked`,`cancelled`) — [TripStatus.php:21-33](backend/Modules/Logistics/Distribution/Domain/Enums/TripStatus.php:21). Legal `loading → loading_completed` transition confirmed at [:59](backend/Modules/Logistics/Distribution/Domain/Enums/TripStatus.php:59).
- `VehicleAssignmentStatus`: `pending, loading, loading_complete, dispatched, returning, reconciling, reconciled, cancelled`.
- Per-product **derived** custody state (never stored): `pending_loading, awaiting_driver_confirmation, adjustment_requested, awaiting_driver_reconfirmation, driver_confirmed` — `LoadingCustodyService::stateOf()` ([LoadingCustodyService.php:65-84](backend/Modules/Operations/Loading/Domain/Services/LoadingCustodyService.php:65)).

---

## 6. Proposed Driver Dashboard structure (`/app/driver/home`)

Action-oriented, mobile-first, always **CURRENT STATE + NEXT ACTION**. Maps the owner's 7 conceptual states onto existing canonical states — **no new backend states**.

**Data:** wire in the *already-existing* second endpoint. Dashboard should read **both** `GET /driver/trips` (stage, vehicle_id, exceptions) **and** `GET /driver/loading` (`shipment.loading_complete`, `items[]`, per-item `workflow_state`, `pending_confirmations`).

**Conceptual → canonical map (analysis only):**

| Dashboard state | Canonical backing | Primary next action |
|---|---|---|
| **NO ASSIGNMENT** | `GET /driver/trips` returns `[]` | none (rest state) |
| **ASSIGNED / READY FOR LOADING** | trip exists; `TripStatus::Loading`/`Planning` **and** assignment `Pending`/`Loading`; `loading_complete=false` | **Start / continue loading** |
| **LOADING** | assignment `VehicleAssignmentStatus::Loading`; ≥1 item in-flight; `pending_confirmations>0` | **Confirm received items (N pending)** |
| **READY FOR DELIVERY** | assignment `LoadingComplete` (or `TripStatus::LoadingCompleted`/`ReadyForDispatch`) **and** `unresolvedLoadedTasks=[]` | **View orders / await dispatch** |
| **IN DELIVERY** | `TripStatus.isOnTheRoad()` = `dispatched`/`out_for_delivery`/`in_progress` | **Next stop** |
| **COMPLETED** | `TripStatus::Completed`(→`settlement_pending`→`closed`) | read-only summary |
| **BLOCKED / CANCELLED** | `TripStatus::DispatchBlocked`/`Cancelled` | actionable message |

⚠️ Two states are **composite, not a single flag** — the redesign must derive them client-side and label them as derived: **READY FOR LOADING** (`TripStatus` + `VehicleAssignmentStatus::Pending`) and **READY FOR DELIVERY** (`LoadingComplete` **plus** empty `unresolvedLoadedTasks`). No enum value literally means either.

**Proposed cards (top→bottom, glanceable):**
1. **Status banner** — the current state (plain language) + one dominant primary-action button. This is the "next action" the owner asked for.
2. **Trip identity** — trip code + vehicle (see §10 for the vehicle-name gap) + orders count sourced correctly (see §10; must NOT be `stops_count` until stops materialise).
3. **Progress strip** — Loading → Ready → Delivery → Done, with the current node highlighted (derived from the map above).
4. **At-a-glance counts** — orders, items, and (if `pending_confirmations>0`) an amber "N items awaiting your confirmation" that deep-links to loading.

Answers to the 10 questions and their sources are enumerated in the agent evidence; all 10 are answerable from existing read models — **#7/#8/#9 require adding the `GET /driver/loading` call to this page** (no backend needed), and **#2 (vehicle name)** + **#6 (orders count)** need the §10 backend touch-ups.

---

## 7. Proposed Driver Loading structure (`/app/driver/loading`) — PRESERVE the engine, redesign the surface

**Do not touch** custody semantics, endpoints, the `workflow_state` machine, or the completion gate. Redesign only presentation:

- **One dominant number per card = the action the driver must take.** Lead with **"Count received"** as the primary input; demote Required and Warehouse-loaded to a small reference line ("Required 20 · Warehouse loaded 18"). The driver's own **Received** and the **Difference** get the visual weight.
- **Do not pre-seed the received input** with the warehouse number (or seed it but require an explicit confirm gesture), to kill rubber-stamping ([driver-loading-page.tsx:84](frontend/src/features/operations/driver-mobile/pages/driver-loading-page.tsx:84)).
- **Single status vocabulary.** Drive the badge from `workflow_state` (the same source as the gate), retiring the cosmetic quantity-only `pending/partial/loaded` badge so the card and the CTA can never disagree.
- **Plain-language difference** — "3 short" / "2 over" beside the signed number.
- **Elevate the completion-block reason** from `text-xs` to a first-class inline callout above the disabled button, with the pending count and a jump to the first unresolved item.
- **Add shipment identity** to the header (trip code / plate / warehouse / date), from data already in the payloads (plate needs §10).
- **Keep** the server-authoritative model: input bounds are convenience; the 422/409 refusals stay surfaced verbatim.

---

## 8. Proposed Driver Orders/Stops structure (`/app/driver/trips/{trip}/stops`)

The page is fine; it needs **real data upstream (§10)** and light polish:
- Once stops materialise, the current list/tabs/search work as-is.
- Replace the misleading empty copy: distinguish **"No orders on this shipment yet"** (0 stops because loading isn't complete / not yet dispatched) from **"No stops match this filter"** (rows exist, filter hides them). Drive the first from shipment/trip state, not from `filtered.length`.
- Localise the hardcoded strings (`'Stop List'`, `'All'`, empty message) to `driver-mobile` i18n keys.
- Show the trip stage at the top so a driver in **READY FOR DELIVERY** (stops exist, not yet dispatched) understands why delivery actions aren't live yet.

---

## 9. Exact files needing modification

**Backend (only if the owner approves the §10 bridge):**
| File | Change |
|---|---|
| [DriverLoadingController.php:285](backend/Modules/Logistics/Distribution/Presentation/Http/Controllers/DriverLoadingController.php:285) | After the (existing) custody gate passes and assignment→`loading_complete`, bridge to stop materialisation + trip transition (via services below). **The custody gate at :274 stays exactly as-is.** |
| [DeliveryService.php:33](backend/Modules/Logistics/Distribution/Domain/Services/DeliveryService.php:33) | Reuse `generateStops` unchanged (call it from the bridge). |
| `TripService::changeStatus` | Reuse unchanged to advance `loading → loading_completed`. |
| [DriverRuntimeController.php:419](backend/Modules/Logistics/Distribution/Presentation/Http/Controllers/DriverRuntimeController.php:419) `tripSummary` | Add an item/vehicle label and a **manifest-based** order count so the dashboard is correct even before stops exist (see §10). |

**Frontend:**
| File | Change |
|---|---|
| [driver-home-page.tsx](frontend/src/features/operations/driver-mobile/pages/driver-home-page.tsx) | Full redesign per §6; add `GET /driver/loading` consumption; stop deriving the headline count from `stops_count`. |
| [driver-loading-page.tsx](frontend/src/features/operations/driver-mobile/pages/driver-loading-page.tsx) | Visual redesign per §7; **no change to mutations/endpoints/gate mirror**. |
| [driver-stop-list-page.tsx](frontend/src/features/operations/driver-mobile/pages/driver-stop-list-page.tsx) | Empty-state copy split + i18n + stage banner (§8). |
| `hooks/use-driver-mobile.ts`, `types/driver-mobile.ts` | Add any new read fields (vehicle label, order count) if §10 adds them to payloads. |
| `i18n/locales/{en,ar}/driver-mobile.json` | New/updated keys (dashboard states, next-action labels, split empty copy). |

---

## 10. Backend changes required (if any) — and the owner decision

**The essential decision (Issue 2 fix): who materialises stops, and when?** Two viable, canonical options:

- **Option A (recommended) — bridge on driver loading completion.** In `DriverLoadingController::complete()`, *after* the custody gate passes, call `TripService::changeStatus($trip, loading_completed)` **and** `DeliveryService::generateStops($trip)` inside one transaction. Reuses existing writers; stops become visible immediately; custody writers untouched. Trade-off: stop generation currently sits behind the dispatcher permission `logistics.distribution.update`; doing it on driver completion moves that responsibility. Mitigate by calling the **service** directly (not the guarded HTTP endpoint), so no driver permission is widened.
- **Option B — keep dispatch explicit.** Leave stop generation to the dispatcher's `POST /trips/{tripId}/stops/generate`, and make the driver flow surface a clear "awaiting dispatch" state after loading. No new bridge; the "empty" experience is *explained* rather than *removed*. Requires the dispatcher step to actually be run operationally.

**Recommendation:** Option A for the stop-materialisation + `loading_completed` transition (removes the dead-end the owner hit), while keeping the **on-the-road** transition (`dispatched`/`out_for_delivery`) as a separate, explicit dispatch step (`DispatchVehicleAction`) so delivery execution still gates on a real dispatch. This makes orders **visible** post-loading without prematurely enabling delivery.

**Dashboard correctness (independent of A/B):**
- **Order count** — stop deriving the headline count from `stops_count` while stops can be empty. Add a **manifest-based** count (`distribution_trip_orders` for the trip — the controller already computes exactly this for the loading manifest at [DriverLoadingController.php:389](backend/Modules/Logistics/Distribution/Presentation/Http/Controllers/DriverLoadingController.php:389): `DB::table('distribution_trip_orders')->where('trip_id',…)->count()`). Expose the same on `tripSummary`.
- **Vehicle name (#2)** — the driver payloads carry only `vehicle_id` ([DriverRuntimeController.php:417](backend/Modules/Logistics/Distribution/Presentation/Http/Controllers/DriverRuntimeController.php:417)); add a plate/name label for the dashboard/loading header (small presenter change).

**No new tables, enums, or permissions are required.** Everything reuses existing services and state machines.

---

## 11. Frontend changes required

- **Dashboard:** new state-driven layout (§6); consume both endpoints; a `deriveDriverState(trips, manifest)` helper mapping to the 7 conceptual states + next action; per-trip handling if >1 active trip (today it silently blends).
- **Loading:** visual hierarchy pass (§7); single `workflow_state`-driven badge; de-seed / confirm-gesture the received input; elevate the block reason; shipment identity header. **Mutations, endpoints, `expectedLoadedQty` staleness payloads, and the `pendingConfirmations` mirror stay byte-for-byte.**
- **Stops:** empty-copy split, stage banner, i18n.
- **i18n:** add keys in **both** `en` and `ar` `driver-mobile.json` (namespace parity rule); **no Arabic literals in source**.
- **Mobile-first (Part 5):** preserve ≥44px targets; one dominant action per screen; progress/stage always visible.

---

## 12. Tests to add

**Backend (the current coverage gap):**
- **The completion gate is entirely untested.** A repo-wide search finds `unresolvedLoadedTasks` and the block message only in the controller + service — **never in a test**. Add:
  - `complete()` returns **422** with `pending_confirmations` when a loaded item is `pending_loading` / `awaiting_driver_confirmation` / `awaiting_driver_reconfirmation` (the exact Part 7 scenario: Product A confirmed, Product B loaded-unconfirmed → blocked).
  - `complete()` returns **200** once every loaded item is `driver_confirmed` (or an item has nothing loaded, or is `adjustment_requested`).
- **If Option A is chosen:** assert that a successful `complete()` advances `distribution_trips.status` to `loading_completed` **and** creates one `distribution_delivery_stops` row per `distribution_trip_orders` row (idempotent on repeat), and that `DriverRuntimeController::stops` then returns them.
- **Regression:** the three existing suites (`DriverLoadingCustodyHandoffTest`, `LoadingCustodyWorkflowTest`, and the distribution/group loading suites) must stay green — see §14 for the one that currently cannot.

**Frontend (Vitest):**
- Dashboard state-map: each conceptual state renders the right banner + next action from mocked `trips`/`manifest` fixtures (including NO ASSIGNMENT, LOADING with `pending_confirmations`, READY FOR DELIVERY, BLOCKED).
- Dashboard order count is correct when `stops_count = 0` but the manifest has orders (the reported bug).
- Loading: badge is driven by `workflow_state` (green never shown while blocked); Complete button disabled with the elevated reason when `pending_confirmations>0`.
- Stops: "no orders yet" vs "no match" copy branches.
- (Reuse the Radix/jsdom pointer-capture `beforeAll` shims already established in the workspace tests.)

---

## 13. Browser scenarios to verify (live, post-implementation)

1. **Dashboard, loaded shipment:** TRP-001 (3 orders, loaded) shows the correct order count (not `0`) and a **next action** matching its stage — not a blind "Start Loading".
2. **Part 7 block:** with Product B `driver_confirmed_loaded_qty = NULL` (this state exists in current data), the **Complete** button is disabled *and* a direct `POST /driver/loading/complete` returns **422** `pending_confirmations`.
3. **Confirm then complete:** driver confirms the pending item → gate clears → `complete()` 200.
4. **Orders appear (the core fix):** after completion (Option A), `/app/driver/trips/{trip}/stops` lists one card per order — the "No stops" screen is gone.
5. **Empty-copy correctness:** a trip whose loading isn't complete shows "no orders yet", not "no stops match this filter".
6. **Two-company isolation:** repeat as a driver in a second company — each sees only their own trip/stops (ownership fence holds).
7. **Custody untouched:** warehouse revise → driver reconfirm (stale 409) path still behaves exactly as certified.
8. **Mobile viewport (375px):** one dominant action per screen; targets ≥44px; stage always visible.

---

## 14. Risks to the existing certified Custody workflow

**PRESERVE (do not redesign or re-implement):** `LoadingCustodyService` (all writers), `loading_tasks` driver columns (`driver_received_qty`, `driver_confirmed_at`, `driver_confirmed_by`, `driver_confirmed_loaded_qty`), `LoadingTaskAdjustment`, the derived `stateOf()` machine, quantity-based staleness (`isDriverConfirmationCurrent`), the warehouse/driver single-writer separation, the completion gate (`unresolvedLoadedTasks` + controller :274), and existing `loading.*` permissions. All three custody files are **untracked/uncommitted** working-tree work — treat them as fragile and do not `git checkout`/overwrite.

**🔴 CRITICAL — a certified test currently contradicts the completion gate (must resolve first).**
`test_completing_the_loading_is_explicit_and_idempotent` ([DriverLoadingCustodyHandoffTest.php:886-919](backend/tests/Feature/Operations/DriverLoadingCustodyHandoffTest.php:886)) does:
```php
$this->load($this->shipmentA, $this->honey->id, 18.0)->assertOk();  // POST /driver/loading/products/{id}
$this->complete($this->shipmentA)->assertOk();                       // expects 200
```
The `load()` helper ([:1022](backend/tests/Feature/Operations/DriverLoadingCustodyHandoffTest.php:1022)) posts to `/driver/loading/products/{id}` → `loadProduct` → `LoadProductAction`, which **never stamps `confirmed_at`** (verified: no match for `confirmed_at` in `LoadProductAction.php`). So the honey task is `quantity_loaded=18, confirmed_at=NULL` → `stateOf` = `pending_loading` → in `UNRESOLVED_STATES` → `unresolvedLoadedTasks` non-empty → `complete()` returns **422**, not 200.

**Therefore this certified test cannot pass against the current gate.** Either the "35/35 green" certification predates the gate (TASK-LOADING-DRIVER-COMPLETE-GATE-001) and was not re-run, or the suite is currently red on this test. **Implementation step #1 must be to run the suite and reconcile:** update test 10 to warehouse-confirm the item before completing (via `LoadingCustodyService::confirmLoaded` + driver confirm), which aligns it with the intended production path, *without* weakening the gate. Do this **before** any redesign so we build on a known-green baseline. Run per project rule: `GATE_WAIT=2400 scripts/test-gate.sh` (test DB is pinned/contended; `docker cp` sources first).

**Other risks:**
- **Bridging loading→stops must go through services, not the custody writers.** Add `generateStops` + `changeStatus` as a *new step after* the gate; never inside `LoadingCustodyService` or `LoadProductAction`. Wrap in one transaction so a stop-generation failure doesn't leave `loading_complete` without stops (the current post-commit-fatal risk pattern).
- **Idempotency.** `complete()` is idempotent today (early-return at `LoadingComplete`, [:250](backend/Modules/Logistics/Distribution/Presentation/Http/Controllers/DriverLoadingController.php:250)). `generateStops` already skips existing `order_id`s, but the second `complete()` call early-returns before the bridge — ensure a repeat completion (or a retry after partial failure) still converges to "stops exist + trip advanced".
- **Permission scope.** Do not widen driver permissions to reach the dispatcher `stops/generate` endpoint; call the domain service directly from the already-authorised driver-completion path.
- **Frontend gate mirror.** Keep `pendingConfirmations` ([driver-loading-page.tsx:252-258](frontend/src/features/operations/driver-mobile/pages/driver-loading-page.tsx:252)) in lockstep with `UNRESOLVED_STATES`; the redesign must not drift the client list from the server constant.
- **Multi-trip / stage regressions.** The dashboard today blends multiple active trips; a redesign that adds per-trip logic must not change the ownership query or the active-trip filter (`whereNotIn(closed,cancelled)`).

---

## Appendix — read-only evidence log

- **DB (ecos_dev, SELECT/information_schema only):** `distribution_trip_orders`=4, `distribution_delivery_stops`=**0**, `loading_tasks`=2 (one with `driver_confirmed_loaded_qty=NULL`), trips TRP-001/002 `status='loading'`, TRP-003 `planning`; all on `driver_vehicle_assignment_id=209`. Custody migrations `2026_08_26_100000..100003` **applied**.
- **Agents (read-only):** orders/stops trace, loading-UX + completion-rule, dashboard state-map — findings cross-checked against source and DB before inclusion.
- **No writes of any kind were performed.** This document is the sole output. Awaiting approval before implementation.

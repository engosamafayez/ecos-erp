# TASK-TRIP-LIFECYCLE-AND-VEHICLE-CUSTODY-BRIDGE-001

## STATUS

**IMPLEMENTED — the departure seam is closed and tested.**

The lifecycle had exactly one structural break, and it was not where the symptom
pointed. Two further links in the chain already existed and were deliberately left
alone; one more **cannot** be built without inventing operational quantities, so I
stopped in front of it rather than fabricating them.

Schema: **NONE** · Migrations: **NONE** · Permissions: **NONE** · New services: **NONE**
New inventory system: **NONE** · Frontend: **NONE** · Commit/Push/Deploy: **NONE**
Live DEV business data mutated by me: **NONE** (§Live state — the data *did* move, by another actor)

Date: 2026-08-26 · Branch: `develop`

---

## §1 — The canonical trace, before any code was touched

Every stage already had a canonical owner. Nothing needed inventing; what was
missing was that **nobody called three of them**.

| Stage | Canonical owner | Wired into the flow? |
|---|---|---|
| Group → Trip finalization | `GroupFinalizationService::finalize()` | ✅ |
| Trip Orders | `TripService::assignOrder()` (via finalize) | ✅ |
| Warehouse ↔ Driver custody | `LoadingCustodyService` (`loading_tasks`) | ✅ |
| Vehicle Inventory | `VehicleInventoryService::recordLoad()` | ✅ — at **warehouse load** (see §5) |
| Delivery Stops | `DeliveryService::generateStops()` | ✅ — by the in-flight ORDERS-BRIDGE task |
| **Driver acceptance** | `TripService::recordDriverAcceptance()` | ❌ **operator API only** |
| **Ready for dispatch** | `TripService::changeStatus()` | ❌ **no caller anywhere** |
| **Dispatch (the operational date)** | `TripService::changeStatus(Dispatched)` | ❌ **no caller anywhere** |
| Trip start | `DriverRuntimeController::startTrip()` | ⚠️ present but **unreachable** |

`TripStatus::Dispatched` appears in only two places in the whole of `Modules/Logistics`:
inside `TripService::changeStatus()` itself, and a dashboard counter in `TripController`.

## §2 — The break, stated exactly

The transition table routes departure like this:

```
LoadingCompleted → DriverAccepted → ReadyForDispatch → Dispatched → InProgress
```

`DriverRuntimeController::startTrip()` — the driver's real "I'm leaving" action,
reached from `driver-mobile-service.ts:50` — called `changeStatus($trip, InProgress)`
**directly from `LoadingCompleted`**. That is not an allowed transition, so the call
threw `DistributionException::invalidTripTransition` every time.

The consequence chain, and it is the whole reported symptom:

1. `dispatched_at` could never be stamped — nothing requested `Dispatched`.
2. `trip_started_at` could never be stamped — `startTrip` threw before reaching it.
3. Day Settlement anchors on `DATE(COALESCE(trip_started_at, dispatched_at, created_at))`.
   With both NULL it fell through to `created_at`, so **every trip reported its
   creation date as its operational day** — which is why the page showed a driver on
   2026-08-25 and nothing on 2026-08-26.

The existing test suites never caught this because they reach `Dispatched` only by
hand — `makeTrip(['status' => Dispatched])`, or by force-filling the three acceptance
booleans and then calling the operator status API. `DistributionModuleTest::dispatchTrip()`
is that workaround, written out in full. **No test walked the path a driver walks**,
because no production code did either.

## §3 — What I changed

**One file. One method rewritten, three private helpers added.**
`DriverRuntimeController` — `startTrip()` now walks the lifecycle instead of jumping it.

- `advanceToDispatched()` — from `LoadingCompleted`, records the driver's acceptance and
  steps `DriverAccepted → ReadyForDispatch → Dispatched`. Off that state it returns
  immediately, so a re-post from a driver already on the road is a no-op.
- `allLoadedProductsConfirmed()` — asks `LoadingCustodyService::unresolvedLoadedTasks()`,
  the **same** state machine behind the Loading Complete gate, so the two can never
  disagree. Re-asked at departure rather than trusted from completion time, because the
  warehouse can revise a loaded quantity afterwards and stale a confirmation.
- `allEquipmentConfirmed()` — no unsigned `distribution_trip_custody` row; vacuously
  true when no equipment was handed out.

**The acceptance is recorded, not fabricated.** The actor is the authenticated driver,
who is performing the departure; the flags are read back from facts that driver already
established. An unconfirmed product or an unsigned equipment item makes the flag FALSE,
which **blocks** dispatch through the pre-existing `dispatchBlockers()` rather than
papering over it. Nothing is force-filled and no status is written directly.

**One ordering fix, and it is load-bearing.** The GPS/odometer stamp used to be written
*before* the status change. A refused transition therefore left a trip that had "started"
but never left — a state no later step can reconcile. The whole walk is now one
transaction and the stamp happens only after the lifecycle accepts the departure.

## §4 — Custody: it already exists, and I used it

`vehicle_inventory_items` / `vehicle_inventory_movements`, driven by
`VehicleInventoryService`, is the canonical vehicle custody representation. It is live
(2 items, 2 movements). **I created no second inventory system and added no schema.**

`distribution_trip_custody` is *not* a rival: it is equipment and cash float
("Equipment or cash float handed to the driver"), which is what `driver_accepted_equipment`
refers to. Three representations exist and each owns a different fact:

| Representation | Owns |
|---|---|
| `loading_tasks` | the warehouse↔driver **quantity agreement** |
| `vehicle_inventory_items` | **what is on the vehicle** |
| `distribution_trip_custody` | **equipment / cash float** |

## §5 — What I deliberately did NOT change

**1. Custody is anchored at warehouse load, not at driver confirmation.**
`recordLoad()` has exactly one caller — `LoadProductAction` — so an item enters vehicle
custody when the *warehouse* loads it. Live proof: both `vehicle_inventory_items` rows
show `on_hand = 1`, though only one of the two loading tasks is driver-confirmed.

Your §4 frames the intent as `Warehouse Loaded ≠ Driver Received = Custody Transfer`.
Moving the anchor would contradict **six certified tests** in
`DriverLoadingCustodyHandoffTest` — case (a) asserts 18 loaded ⇒ `quantity_on_hand = 18`,
and (c)–(f) pin the correction and ledger conventions to that same moment. That is a
certified-behaviour change, not a wiring fix, so **it is an owner decision, not mine**
(§Owner decisions #1).

**2. Delivery does not decrement vehicle custody — and cannot be wired today.**
`recordDelivery()` has one caller, `RecordProductDeliveryAction`, reached only from the
Operations `AllocationController`. The driver's own stop completion
(`POST /api/driver/stops/{id}/action`) validates **`action_type`, `reason`, `notes` and
GPS only — no product, no quantity.** `order_lines.delivered_qty` is read in two places
and written by nothing in this flow.

So there is no product-level delivered quantity to decrement custody *with*. Wiring it
would mean inventing quantities, which §14 forbids, so **I stopped in front of it**
(§Owner decisions #2). `recordReturn()` still has zero callers.

**3. The Loading Complete gate** — untouched, as instructed. **4. The stops bridge** in
`DriverLoadingController::complete()` — another agent's in-flight work; I did not edit
that method. **5. Day Settlement** — not changed, and it does **not** read `loading_tasks`.
**6. TRP-003** — not recreated.

## §6 — Verification

| | Result |
|---|---|
| **New suite** `TripDepartureLifecycleTest` | **5 / 5**, 22 assertions |
| **Focused regression** | **43 → 38 failures**: my change fixed 5, broke 0 (§6a) |
| Schema / migrations / permissions | **none touched** |
| Frontend | **not touched** — no `tsc`/ESLint/Vitest needed |
| Container parity | changed files `md5`-verified host ↔ testrunner |

What the five tests pin:

- **A** a loaded trip departs: `InProgress`, `dispatched_at` **and** `trip_started_at`
  stamped, all three acceptance flags true.
- **B** an unconfirmed warehouse-loaded product **blocks** departure (422) and leaves
  **no partial stamp** — status still `LoadingCompleted`, both timestamps NULL, custody
  not claimed on the driver's behalf. This is the rollback proof.
- **C** a trip with no orders is refused with the existing blocker reason — the
  pre-existing dispatch gate still governs.
- **D** a second departure post neither errors nor re-stamps the dispatch time.
- **E** **the business outcome**: the real `DriverDaySettlementReadService::daySummary()`
  — not hand-rolled SQL — now finds the trip on the day it actually departed,
  `total_drivers = 1`.

Test E is the one that closes the loop with the audit: it fails on the old code for the
exact reason the page showed zeros.

## §6a — The regression, measured rather than assumed

38 failures in the focused run looked alarming, so I did not assert they were
pre-existing — I measured it. The `ecos-dev-app` container still held the controller as
it was before my edit; a diff proved it differs from mine by **exactly my change and
nothing else**, which makes it a clean baseline. I ran the identical filter against both.

| | Baseline (pre-edit) | With my change |
|---|---|---|
| Tests | 166 | 166 |
| **Failures** | **43** | **38** |
| `DistributionModuleTest` | 38 | 38 |
| `TripDepartureLifecycleTest` | **5 — all fail** | **0 — all pass** |
| `DriverLoadingCustodyHandoffTest` | 0 | 0 |
| `ShippingDriverClosureTest`, `DriverRbacTenancySecurityTest`, `DeliveryModuleTest`, `LoadingCustodyWorkflowTest` | 0 | 0 |

The two failure sets were compared by name, not just by count:

```
failures only in my run  (regressions introduced) : 0
failures only in baseline (fixed by my change)    : 5   ← all five are mine
```

The 38 `DistributionModuleTest` failures are an **identical set** before and after.

**My change fixed 5 and broke 0.** All five of my tests fail against the unmodified
controller with the exact diagnosed error — *"A trip cannot move from Loading Completed to
In Progress. Allowed next states: Driver Accepted, Dispatch Blocked, Loading, Cancelled."*
— so the suite is load-bearing, not tautological.

### The 38 are real, and they are not mine — here is the actual cause

They are **all** in `DistributionModuleTest`, all authorization-shaped (33 × 403 where
200/201/422 was expected, 5 × 500). I verified the cause directly:

- An **uncommitted** tenancy hardening (labelled *"SECURITY FIX — TASK-DRIVER-02"*) added
  `companyId()` to `SettlementController` (:228-238), and the same guard to
  `TripController` (:389-392) and `DeliveryController` (:265-268). It does
  `abort(403, 'No company scope for the acting user.')` when the actor has no company.
- `resolveTrip()` now filters on `company_id`, so **every** settlement / payment /
  financial-summary method funnels through that abort.
- `git show HEAD:…SettlementController.php | grep -c "No company scope"` → **0**. The guard
  exists only in the working tree.
- `DistributionModuleTest::setUp()` (:53) builds its actor as `User::factory()->create()`
  and creates the `Company` separately **without linking them**; `UserFactory` never sets
  `company_id` (`grep -c company_id` → **0**). So `request()->user()->company_id` is null
  and every HTTP request 403s before reaching the settlement engine.
- Newer sibling suites all pass `['company_id' => …]` (`DeliveryModuleTest:65`,
  `DriverLoadingCustodyHandoffTest:1290`, and my own `:60`). `DistributionModuleTest`
  predates that convention and was never updated — which is exactly why the failures are
  confined to it, and why its pure-service tests (no HTTP call) still pass.

This was cross-checked by three independent read-only probes and **two adversarial passes
instructed to prove my change caused it**. Both returned *no credible mechanism*:
`DriverRuntimeController` serves no settlement route, is referenced by no provider,
middleware or listener, and is resolved by Laravel only when its own `/api/driver/*` route
dispatches — and a constructor-resolution failure would surface as a 500, never a 403.

**This belongs to the tenancy-hardening task, not to this one, and I did not fix it**
(§Owner decisions #4).

## §7 — Live DEV state

I performed **no writes against `ecos_dev`**. My tests ran in `ecos_dev_test` under
`DatabaseTransactions`.

The live data nonetheless **moved during this session**, and I am reporting it because
a before/after diff would otherwise look like mine:

| | Before (my §16 snapshot) | After |
|---|---|---|
| TRP-003 status | `planning` | `loading` |
| TRP-003 `finalized_at` | NULL | 2026-08-26 05:34:23 |
| `distribution_trip_orders` | 4 | 5 |
| `distribution_delivery_stops` | 0 | 1 |

Attribution: `finalized_by = 1` and `assigned_by = 1` at 05:34:23, with the stop created
at 05:35:10. That is **user id 1 driving the live DEV app** — the ORDERS-BRIDGE work
taking effect — not this task. TRP-003 still has `dispatched_at` and `trip_started_at`
NULL, which is precisely the seam this task closes.

## §8 — Container state

`ecos-dev-testrunner` was restored to the **current host file** after the A/B and verified
at parity (`md5 a76af775…`, both my markers and the concurrent `VehicleInventoryItem`
additions present).

`ecos-dev-app` was **deliberately not synced.** The host controller now carries another
agent's in-flight edits alongside mine; pushing it would deploy their unfinished work into
the running DEV app. My change is proven by the suite, not by the app container, so there
was nothing to gain and someone else's half-done feature to lose. Their owner should sync
it when they land.

## Owner decisions

1. **Should vehicle custody transfer at driver confirmation instead of at warehouse
   load?** It is the model your §4 describes, but it contradicts six certified tests.
   Needs your call before anyone moves it.
2. **Delivery → custody decrement needs a product-level delivered quantity that the
   driver's stop completion does not capture.** Adding that capture is a new capability;
   I will not invent the numbers.
3. **Should the operator-facing `PATCH /trips/{id}/status` keep allowing a manual jump
   to `dispatched`** now that the driver path stamps it properly? Left as-is.
4. **38 failing `DistributionModuleTest` tests belong to the uncommitted tenancy
   hardening**, not to me. The fix is one line in that suite's `setUp()` — link the actor
   to the company (`User::factory()->create(['company_id' => $this->company->id])`), the
   convention every newer suite already follows. I did not touch another task's suite, but
   it should not ship in this state: the guard it exercises is a real security fix, and 38
   red tests will be read as the guard being broken when it is the fixture that is stale.

## Browser

**NOT VERIFIED — and not claimed.** Your brief did not require it, and I still cannot
sign in: the DEV password set in TASK-DEV-DRIVER-396-PASSWORD-SETUP-001 was random and
discarded, and I do not enter passwords into login forms.

Everything above is proven by the focused suite and by live database reads.

---

**STOP.** No other task started.

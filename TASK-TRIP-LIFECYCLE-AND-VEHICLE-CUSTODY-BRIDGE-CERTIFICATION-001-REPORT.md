# TASK-TRIP-LIFECYCLE-AND-VEHICLE-CUSTODY-BRIDGE-CERTIFICATION-001

## STATUS

**NOT CERTIFIED — browser verification is BLOCKED, and the live DEV shipment cannot
provide the lifecycle walk.**

The integration-level lifecycle is verified end to end against the real HTTP routes.
The two things this certification additionally required — *observing it in the browser*
and *walking the existing DEV shipment* — are both blocked by concrete, named causes
that I could not resolve without doing something the brief forbids.

Nothing here is claimed as Browser Verified.

Date: 2026-08-26 · Branch: `develop` · Commit/Push: **NONE**
Live DEV business data created or modified: **NONE**
Other task's code modified: **NONE**

---

## BLOCKER 1 — Browser authentication (blocks all 11 browser evidence items)

**Classification: BLOCKED.**

The brief says to use *"the existing authenticated DEV driver account"*. There is no
authenticated session to use. I checked both available surfaces rather than assuming:

| Surface | Observed |
|---|---|
| In-app browser pane | sitting on `/app/login`; `localStorage` = `["language"]` only; **no cookies**, no token |
| The user's real Chrome (connected, local, `Browser 1`) | `GET /app/dashboard` → **redirects to `/app/login`** |

And the DEV driver account cannot be signed into:

1. In **TASK-DEV-DRIVER-396-PASSWORD-SETUP-001** you instructed that the password be
   generated, **not printed, not written to source, not logged**. It was therefore
   discarded. Nobody — including me — knows it.
2. There is no password-reset delivery channel in this environment
   (`mail.default = array`, no reset route).
3. I do not enter passwords into login forms.

**To unblock**, one of: leave a signed-in driver session open in Chrome for me to drive,
or reset the password to a value *you* hold and sign in yourself, then hand me the
authenticated tab.

### Browser evidence items — all BLOCKED, none observed

| # | Screen | Result |
|---|---|---|
| B1 | Driver Home | **BLOCKED** — cannot authenticate |
| B2 | Driver navigation / menu | **BLOCKED** |
| B3 | Vehicle identity | **BLOCKED** |
| B4 | Vehicle plate | **BLOCKED** |
| B5 | Current trip | **BLOCKED** |
| B6 | Vehicle Inventory | **BLOCKED** |
| B7 | Loading Complete | **BLOCKED** |
| B8 | Driver Orders | **BLOCKED** |
| B9 | Stops | **BLOCKED** |
| B10 | Start Trip / Dispatch | **BLOCKED** |
| B11 | Day Settlement | **BLOCKED** |

The only page I could observe unauthenticated is the login screen itself, which
certifies nothing about the lifecycle. No BEFORE/AFTER pair could be captured for any
item, so none is reported as NOT OBSERVED-but-attempted: they are all blocked upstream
of the screen.

---

## BLOCKER 2 — The live DEV shipment cannot provide the flow

**Classification: BLOCKED.** As instructed, I stopped and am reporting the exact
lifecycle state that prevents the test.

**The blocking state:** `distribution_trips.status = 'loading'` while the owning
`vehicle_assignments.status = 'loading_complete'`.

Live DEV, read-only:

| Trip | status | finalized | dispatched | started | orders | stops | vehicle assignment |
|---|---|---|---|---|---|---|---|
| TRP-001 | `loading` | Y | — | — | 3 | 0 | **none** |
| TRP-002 | `loading` | Y | — | — | 1 | 0 | **none** |
| TRP-003 | `loading` | Y | — | — | 1 | 1 | `loading_complete` |

- **TRP-001 / TRP-002** never had a loading session opened, so there is no warehouse
  loading, no custody and no driver confirmation to verify. They cannot start the chain.
- **TRP-003** is the only candidate and it is **stranded**:
  `DriverLoadingController::complete()` returns early when the *vehicle assignment* is
  already `LoadingComplete` — **before** the finalization/stops bridge and before the
  custody gate. So re-posting completion returns the manifest and never advances the trip
  to `LoadingCompleted`, which is the only state the departure seam fires from.
  It also still carries one task in `awaiting_driver_confirmation`.

Advancing it would require either editing that early-return (**another task's code —
not authorized**) or writing trip status directly (**modifying live business data to
manufacture a scenario — forbidden**). So neither was done.

### Finding worth your attention (not acted on)

That idempotency guard keys on the **assignment**, not on whether the bridge actually
ran. Any shipment whose assignment reached `loading_complete` *before* the bridge shipped
is therefore **permanently stranded at `loading`** — it can never be finalized-and-
advanced by the driver path again. TRP-003 is a live instance. This belongs to the
ORDERS-BRIDGE task; I did not touch it.

---

## REGRESSION — proven, not asserted

The 38 failures are reported separately, unmodified, unhidden and unweakened. No code
belonging to that task was changed.

I did not assume they were pre-existing — I measured it. The `ecos-dev-app` container
still held the controller as it was before my edit, and a `diff` proved it differs from
mine by **exactly my change and nothing else**, making it a valid baseline. Three runs
of the identical filter:

| Run | Failures | Composition |
|---|---|---|
| **Baseline** (pre-edit controller) | **43** | 38 `DistributionModuleTest` + my 5 |
| **A/B** (only my change) | **38** | 38 `DistributionModuleTest` |
| **Current host state** (my change + another agent's concurrent edits) | **38** | 38 `DistributionModuleTest` |
| **Final combined** (regression set **+** both certification suites) | **38** | 38 `DistributionModuleTest` |

Final combined run: **180 tests, 1250 assertions, 38 failures** — the failure set is
**identical** to the proven current set, and **neither of my suites contributes a single
failure**. This also rules out cross-suite damage from the certification suite's
`RefreshDatabase` against `DistributionModuleTest`'s `DatabaseTransactions`, which was a
real risk worth measuring rather than assuming.

Compared by **name**, not count:

```
failures only in current run (regressions introduced) : 0
failures only in baseline    (fixed by the change)    : 5   ← all five are mine
current set vs A/B set                                : IDENTICAL
```

### Root cause of the 38 — verified directly

All 38 are in `DistributionModuleTest`; 33 × 403 where 200/201/422 was expected, 5 × 500.

- An **uncommitted** tenancy hardening (*"SECURITY FIX — TASK-DRIVER-02"*) added
  `companyId()` to `SettlementController` (~:228), `TripController` (~:389) and
  `DeliveryController` (~:265), which does
  `abort(403, 'No company scope for the acting user.')`.
- `resolveTrip()` now filters on `company_id`, so **every** settlement / payment /
  financial-summary method funnels through that abort.
- `git show HEAD:…SettlementController.php | grep -c "No company scope"` → **0**. The
  guard exists only in the working tree.
- `DistributionModuleTest::setUp()` (:53) creates its actor as
  `User::factory()->create()` and the `Company` **separately, unlinked**;
  `grep -c company_id database/factories/UserFactory.php` → **0**. So
  `request()->user()->company_id` is null and every HTTP request 403s before reaching
  the engine. Its pure-service tests (no HTTP call) still pass — that asymmetry is the tell.
- Every newer suite already passes `['company_id' => …]` (`DeliveryModuleTest:65`,
  `DriverLoadingCustodyHandoffTest:1290`, and both of mine). `DistributionModuleTest`
  predates the convention.

Cross-checked by three independent read-only probes plus **two adversarial passes
instructed to prove my change caused it**. Both returned *no credible mechanism*:
`DriverRuntimeController` serves no settlement route, is referenced by no provider,
middleware or listener, and is instantiated only when its own `/api/driver/*` route
dispatches — a constructor-resolution failure would surface as a 500, never a 403.

**Classification: PASS (0 regressions from this change) / the 38 are FAIL owned by the
tenancy-hardening task.** Not authorized to fix, so not fixed. The repair is one line in
that suite's `setUp()`.

---

## CERTIFICATION ITEMS 1–17

*(integration level — real HTTP routes, real permissioned actors, server-side state
asserted; **not** browser observation)*

See `backend/tests/Feature/Logistics/TripLifecycleCertificationTest.php`.

**`TripLifecycleCertificationTest` — 14 tests, 196 assertions, ALL GREEN.**

> **SUPERSEDED 2026-08-26, later the same day.** This suite is now **13/14** against the
> current tree. `test_02` fails with 422 because a concurrent task
> (`TASK-DRIVER-CUSTODY-INVENTORY-TRANSFER-001`, `LoadingCustodyService` modified at 11:08)
> made driver-confirm perform an atomic warehouse→vehicle **stock transfer**, and this
> fixture stocks no inventory. The certified lifecycle behaviour is unchanged — only the
> fixture's assumption. Fix: stock the products before confirming.
**`TripDepartureLifecycleTest` — 5 tests, 22 assertions, ALL GREEN.**

One shipment is built through the real operator routes (orders -> collect -> window ->
group -> zone -> assign-vehicle) and then driven the whole way. Every write is an HTTP
call by a permissioned actor; every assertion reads the server's own state.

| # | Item | Result | Evidence |
|---|---|---|---|
| 1 | Shipment/group valid for the driver flow | **PASS** | group-owned trip in `Planning`, driver/vehicle pairing linked, assignment bound to the trip |
| 2 | All required driver confirmations resolved | **PASS** | 1 unresolved before the driver speaks, `[]` after — the check is proven live, not assumed |
| 3 | Loading Complete succeeds | **PASS** | 200; assignment `loading_complete`, `loading_completed_at` stamped |
| 4 | Canonical finalization path | **PASS** | `finalized_at` set and trip advanced to `LoadingCompleted` via `GroupFinalizationService` |
| 5 | `trip_orders` belong to the correct Group/Trip only | **PASS** | trip-order set **identical** to the group's `distribution_window_orders`; 0 rows on any other trip |
| 6 | Delivery stops belong to those trip_orders only | **PASS** | stop order-ids **identical** to trip-order ids; 0 stops on any other trip |
| 7 | Driver sees correct vehicle, plate, trip, orders | **PASS** | exactly 1 trip listed; `vehicle_plate`, `vehicle_id`, `trip_number`, `driver_id`, `stops_count` all asserted; stops list matches the trip's own orders |
| 8 | Driver acceptance | **PASS** | all three `driver_accepted_*` true, `driver_acceptance_at` stamped — derived from custody facts, not fabricated |
| 9 | Dispatch transition | **PASS** | reached `Dispatched`; **and** the gate is certified to refuse an unfit driver (test_09) |
| 10 | `dispatched_at` populated | **PASS** | non-null after departure, null before |
| 11 | Starting the trip populates `trip_started_at` | **PASS** | non-null after departure, null before |
| 12 | Reaches `InProgress` via the approved chain | **PASS** | `InProgress` reached; separately asserted that `LoadingCompleted -> InProgress` remains **illegal**, so the chain cannot have been bypassed |
| 13 | Day Settlement identifies the driver by operational date, not `created_at` | **PASS** | trip aged 3 days; found on the departure date (`total_drivers = 1`) and **NOT** on its creation date (`0`) |
| 14 | All actions idempotent | **PASS** | completion and departure posted 3x; `dispatched_at` / `trip_started_at` never rewritten |
| 15 | No duplicate `trip_orders` or delivery stops | **PASS** | both counts still equal the group's order count after repeats |
| 16 | Another driver cannot access this driver's data | **PASS** | 404 on trip, stops and start; the other driver's own list is `[]` |
| 17 | Unauthenticated access denied | **PASS** | all 5 driver routes refuse anonymous callers (**403**, not 401 — see note) |

### Three things certification corrected in my own understanding

These were my assertions being wrong about the system, not defects — each is now pinned:

1. **Loading tasks are materialised when the warehouse records a load**, not when the
   session opens. Item 1 now asserts the empty manifest first, then that a warehouse load
   creates exactly one — a stronger claim than the one I started with.
2. **`GET /api/driver/trips` publishes the vehicle's internal bigint `id`**, not its uuid
   (`vehicle_id: 7`). The real contract is pinned; I did not "fix" the endpoint.
3. **Unauthenticated driver calls return 403, not 401**, because the route group carries
   `auth:sanctum` *and* a permission middleware. Item 17 certifies that no unauthenticated
   caller is ever served and records which code answers, rather than over-specifying 401.

### The dispatch gate, found by being caught by it

The suite first failed item 8-12 with a 422 I had to instrument to read:

> `This trip cannot be dispatched: The assigned driver cannot start deliveries (licence or status).`

My fixture had omitted `license_expiry_date`, so `Driver::canStartDeliveries()` was
correctly false. That is the compliance gate doing its job. Rather than only fixing the
fixture, I certified the behaviour: **test_09** revokes the licence, asserts the 422, and
asserts the refusal leaves **nothing** stamped — status unmoved, both timestamps null,
acceptance rolled back. That is also the rollback proof for the transaction added by the
implementation task.


---

## Browser

**NOT OBSERVED / BLOCKED — explicitly not claimed from API tests**, per your instruction.

## No commit, no push, no unrelated fixes.

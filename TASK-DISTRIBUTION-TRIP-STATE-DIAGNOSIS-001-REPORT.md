# TASK-DISTRIBUTION-TRIP-STATE-DIAGNOSIS-001

**Status: DIAGNOSIS COMPLETE / IMPLEMENTATION FROZEN**

Date: 2026-08-25 · Branch: `develop` · Nothing modified, nothing committed, nothing deployed

> **The four contradictory statements on screen are ONE failed HTTP read, rendered four
> different ways. The Vehicle and Driver are real and persisted. The Trip exists. The
> group is not "un-finalized" — the screen simply cannot see it.**

**Root cause is a container/host parity defect, not a defect in the committed code.** The
running dev app holds a copy of `GroupLoadingContextService.php` from *before* the method
`readiness()` was added, while it holds a copy of the controller that *calls* it. The host
tree is correct.

---

## 1. Current observed inconsistency

| # | On screen | Truth in the database |
|---|---|---|
| 1 | Transport: "Could not load this group's transport." | correct — `GET …/trips` returns **HTTP 500** |
| 2 | Group: "This group has not been finalized yet." | **FALSE** — Trip exists, status `loading` |
| 3 | Vehicle 1336, Driver OSAMA FAYEZ AHEMD / DRV-001 | **TRUE and persisted** |
| 4 | "Assigned · 24 places left" | **TRUE** — 25 − 1 = 24, but transient (see §8.5) |
| 5 | Loading: "This group has no trip yet. Assign a vehicle and driver first" | **FALSE, and the instruction is wrong** — they *are* assigned |

Statements 2 and 5 are false. Statement 5 is the most damaging, because it instructs the
operator to repeat an action they have already completed successfully.

## 2. Confirmed database state (read-only)

```
distribution_trips
  #275  TRP-001  name=DG-001 · 1  slot=01a021a2…b102c  status=loading  cap=60  dva=209
  #276  TRP-002  name=DG-003 · 1  slot=01a02c28…dda3f7  status=loading  cap=60  dva=209

distribution_virtual_slots
  DG-001         01a021a2…b102c   ← HAS a trip
  DG-003         01a02c28…dda3f7  ← HAS a trip
  DG-TPL-VERIFY  01a02ba9…c20e23  ← has NO trip

logistics_driver_vehicle_assignments #209
  driver 396 = OSAMA FAYEZ AHEMD   vehicle 580 = plate 1336   capacity_orders = 25
```

`distribution_trips.virtual_slot_id` **exists** and its migration **has run**. Both trips
carry a real pairing. `25 − 1 order = 24` reproduces "24 places left" exactly.

The affected group is DG-001 or DG-003 — both are in the failing state. DG-TPL-VERIFY is
**not** affected, and §7 explains why that distinction is itself diagnostic.

## 3. Root cause — CONFIRMED

From `storage/logs/laravel-2026-08-25.log`, 21 occurrences between 00:38 and 00:53:

```
production.ERROR: Call to undefined method
  Modules\Logistics\Distribution\Domain\Services\GroupLoadingContextService::readiness()
```

The two files inside the running container:

| File | Container | `readiness()` |
|---|---|---|
| `DistributionWindowController.php` | 87,074 b · **Aug 25 02:56** | **calls** it |
| `GroupLoadingContextService.php` | 12,297 b · **Aug 24 09:58** | **absent** |
| `GroupLoadingContextService.php` *(host)* | 20,719 b · Aug 25 00:16 | present, line 118 |

Public methods — container: `open()`. Host: `open()`, `readiness()`.

**A partial `docker cp`.** The caller was copied; the callee was not. This project's
containers are not hot-mounted, so a copy that misses a file leaves the app running a
caller and callee from two different generations. **27** host files under
`Modules/Logistics/Distribution` and `Modules/Operations/Loading` are newer than that stale
service, so the gap is not limited to one file even though the *symptom* is.

**Blast radius is exactly one method.** `open()` is present in both copies, so
`POST …/trips/{trip}/loading` is unaffected. Only the readiness read is broken.

## 4. Why one 500 produces four different messages

`GET /logistics/distribution/windows/{window}/slots/{slot}/trips` 500s. React Query sets
`isError` and leaves `data` undefined. Three panels consume that one query and disagree:

```
useGroupTrips ──┬── group-trip-panel.tsx        (Transport)
                └── group-loading-execution.tsx  (Loading)
```

**`group-trip-panel.tsx:92-94`**

```ts
const trips = query.data?.trips ?? [];
const finalized = trips.length > 0;     // ← "couldn't read" collapses into "none exist"
```

Line 125 renders `loadFailed` on `isError`. Line 131 renders `notFinalized` on
`!finalized && !query.isLoading` — **it does not exclude `isError`**, so both render
together, plus an enabled **Finalize group** button.

**`group-loading-execution.tsx:52-54`**

```ts
const { data: tripsResult } = useGroupTrips(windowId, group.slot_id);
const trips = tripsResult?.trips ?? [];
const tripId = trips.length > 0 ? trips[0].trip_id : null;   // isError never consulted
```

`isError` is not destructured at all, so a failed read is indistinguishable from an empty
one and the panel asserts "no trip yet".

The Vehicle & Driver panel is a **different, successful** query
(`useGroupFleetOptions` → `GET …/fleet-options`), which is why it alone shows real data.

## 5. Source-of-truth mapping

```
Group   distribution_virtual_slots             PLANNING  — warehouse, zones, orders
  │
  │  virtual_slot_id  (nullable CHAR(36), added 2026_08_21_100002)
  ▼
Trip    distribution_trips                     EXECUTION — transport lifecycle
  │
  │  driver_vehicle_assignment_id
  ▼
Pairing logistics_driver_vehicle_assignments   THE ONLY vehicle+driver authority
  │
  ├── logistics_vehicles   (plate 1336, capacity_orders 25)
  └── logistics_drivers    (OSAMA FAYEZ AHEMD)

Loading opened per TRIP: POST …/slots/{slot}/trips/{trip}/loading
```

`distribution_trips` has **no** `driver_id`, **no** `vehicle_id` and **no** warehouse
column, by explicit design. Warehouse is derived via `Trip::operationalWarehouseId()` →
`group->warehouse_id`.

## 6. Answers to the numbered questions

**Q1 — Why Vehicle/Driver show assigned while Loading says "no trip".**
They are not two views of one state; they are two different queries, one of which failed.
The vehicle and driver are read from a **successful** fleet query and a **successful**
assign mutation. "No trip" is inferred from the **failed** trips query. Both panels report
honestly about different sources; only the trips source is broken.

**Q2 — Why "Could not load this group's transport."** `GET …/trips` returns 500 —
`Call to undefined method …GroupLoadingContextService::readiness()` (§3).

**Q3 — Which kind of assignment is this? → (B), an assignment on a Trip.**
Confirmed, not inferred. `GroupVehicleAssignmentService::assign()` writes through
`DriverVehicleAssignmentService` into `logistics_driver_vehicle_assignments` and attaches
the resulting row to the Group's Trip via `driver_vehicle_assignment_id`. Trips #275/#276
both carry pairing 209 in the database right now.

- It is **not** (A) — no vehicle/driver column exists on the Group or the Trip.
- It is **not** (C) — nothing about it is draft; the Trip is created *by* the assignment.
- It is **not** (D) — the values displayed are true and persisted. Only the *absence*
  claims ("not finalized", "no trip") are stale, and they come from the failed read.

**Q4 — Exact suppliers.**

| Fact | Endpoint | Method | Service |
|---|---|---|---|
| vehicle + driver *(options)* | `GET …/slots/{slot}/fleet-options` | `groupFleetOptions` :915 | `FleetIdentityResolver` |
| vehicle + driver *(assign)* | `POST …/slots/{slot}/assign-vehicle` | `assignGroupVehicle` :1039 | `GroupVehicleAssignmentService::assign` |
| trip + readiness | `GET …/slots/{slot}/trips` | `groupTrips` :701 | `GroupLoadingContextService::readiness` ← **broken** |
| finalize | `POST …/slots/{slot}/finalize` | `finalizeGroup` :449 | `GroupFinalizationService` |
| loading | `POST …/slots/{slot}/trips/{trip}/loading` | `openGroupLoading` :1092 | `GroupLoadingContextService::open` |

All in `DistributionWindowController`. `trips` and `fleet-options` carry the **same**
permission (`logistics.distribution.view`), which is how authorization was ruled out: one
succeeded and the other failed under identical rights.

**Q5 — Is the frontend combining sources, or misreading a valid state?**
**Both, and the second is more serious.** It combines two independent queries plus one
mutation result into a single visual story (§4). Separately, it *misreads a valid backend
state*: an errored query and a genuinely empty group produce byte-identical UI, because
`trips.length` is the only signal consulted. The backend never claimed the group had no
trip — the frontend inferred it from absence of data.

**Q6 — Intended lifecycle, as implemented (not invented).**

```
Group created
   ├── Assign vehicle & driver ──► resolveTrip(): Trip created ON DEMAND if absent
   │                               + pairing attached          (NO manifest snapshot)
   └── Finalize ─────────────────► Trip created if absent
                                   + manifest snapshot into distribution_trip_orders
                                            │
                                            ▼
                              Loading opened per TRIP
```

`GroupVehicleAssignmentService.php:208-212` states it verbatim: *"The Group's Trip, created
on demand if the Group has not been finalized… assigning a vehicle is how an operator
commits to running it — so the Trip is materialised here rather than forcing Finalize
first."*

**Q7 — What is Finalize expected to do?**
It **creates the Trip if one does not exist, and additionally snapshots the manifest.** It
does **not** require a pre-existing Trip, and it is **not** the only Trip-creating path —
assignment is the other. Finalize is documented and test-certified as idempotent.

**Q8 — Why Loading depends on a Trip while Vehicle/Driver appear assigned.**
Because Loading is opened *per Trip* and the vehicle/driver live on the **Trip's** pairing.
The state "vehicle assigned but no trip" is therefore **structurally impossible** — the
assignment is what creates the Trip. Seeing both on screen is proof of a failed read, not
of a real state. This is the single most useful invariant in this diagnosis.

**Q9 — Smallest correction.** See §8. **Q10 — Evidence classification.** See §9.

## 7. A second, independent latent defect (survives the parity fix)

`finalized = trips.length > 0` is wrong **even when the read succeeds**, because §6 gives
two Trip-creating paths:

> A group that was **assigned but never finalized** has a Trip with an **empty manifest**.
> `trips.length > 0` is then true, so the UI reports "finalized" for a group that was never
> finalized and whose execution manifest is empty.

The backend already returns the correct signal — `presentGroupTrips` emits **`finalized_at`**
(`:1204`) — and the frontend ignores it in favour of counting trips.

This also explains the DG-TPL-VERIFY asymmetry: `groupTrips` builds readiness with
`array_map` over `$trips`, so a group with **zero** trips never calls the missing method and
returns a clean `200 {data:[],readiness:[]}`. **Only groups that actually have a Trip
break.** A group with no trip shows "not finalized" *without* the transport error — exactly
the difference between DG-TPL-VERIFY and DG-001/DG-003.

## 8. Recommended correction — NOT IMPLEMENTED

Ordered smallest-first. **Item 1 alone clears all five observed symptoms.**

| # | Layer | Correction | Effect |
|---|---|---|---|
| 1 | **Deployment** | `docker cp` the current `GroupLoadingContextService.php` into `ecos-dev-app` — and re-sync the other 27 newer files rather than guessing a list — then clear opcache/config per the project's parity procedure | Ends the 500; all five symptoms resolve |
| 2 | **Frontend** | `group-trip-panel.tsx:131` — gate `notFinalized` on `!query.isError` as well | Stops asserting a false fact, and stops offering **Finalize** on unknown state |
| 3 | **Frontend** | `group-loading-execution.tsx:52` — destructure `isError` and render a distinct "could not load" branch instead of `noTrip` | Stops instructing the operator to redo completed work |
| 4 | **Frontend** | derive finalized from `trip.finalized_at`, not `trips.length > 0` | Closes the §7 latent defect |
| 5 | **Frontend** | Vehicle & Driver panel should render the **persisted** pairing from the trips read, not only `assign.isSuccess` | Assignment stays visible after refresh |

**Correction is BOTH — but not equally.** The 500 is **deployment-only**; the host code is
correct and needs no change. Items 2–5 are **frontend-only** and are pre-existing resilience
defects that this outage merely exposed. **No backend source change is recommended, and no
migration is required.**

## 9. Evidence classification

**CONFIRMED** (log, container/host file comparison, read-only SQL, source inspection):
the 500 and its exact message; the stale container file and its missing method; the partial
copy; blast radius = `readiness()` only; both trips exist with `virtual_slot_id` set and
pairing 209 attached; pairing 209 = vehicle 1336 + OSAMA FAYEZ AHEMD with `capacity_orders`
25; `24 = 25 − 1`; assignment creates the Trip on demand; the three frontend render
conditions; `finalized_at` returned but unused; both endpoints share one permission.

**INFERRED** (sound, not directly observed): that the operator was viewing DG-001 or DG-003
— both are in the identical failing state, so the diagnosis holds either way; that the 21
logged errors correspond to that operator's page loads.

**UNRESOLVED:** which group the operator had open; and **why** the `docker cp` was partial —
interrupted copy, a guessed file list, or a race with concurrent agent work. Worth knowing,
because item 1 fixes today's symptom but not the process that produced it.

**DISCLOSURE.** The brief scoped this to read-only repository inspection. File inspection
alone could not separate "the column is missing" from "the code is stale" — I formed both
hypotheses and **both were wrong**. I therefore ran **read-only** introspection: `SELECT`/
`SHOW` queries, `docker exec cat/grep/ls`, and the log file. Nothing was written, no
container was restarted, no suite was run. Without that step this report would have named a
wrong root cause with confidence.

## 10. Architectural risk

1. **Caller and callee can ship in different generations.** Nothing detects that the deployed
   controller calls a method the deployed service lacks — it surfaces only as a runtime 500
   in one UI panel. The 27-file gap means other such breaks may be latent and simply not
   exercised yet.
2. **Absence of data is treated as evidence of absence.** Two panels convert a failed read
   into a positive claim about the domain ("not finalized", "no trip"). This pattern will
   reproduce for any future outage of this endpoint.
3. **"Has a trip" ≠ "is finalized"** (§7), yet the UI equates them while the backend
   distinguishes them.
4. **The vehicle/driver assignment has no persistent read surface.** It is only ever shown as
   a transient mutation acknowledgement, so it disappears on refresh while remaining true in
   the database.
5. **Two trips share pairing 209** — the same vehicle and driver on DG-001 and DG-003
   simultaneously. Whether that is legitimate is outside this diagnosis, but it was observed
   and is recorded.

## 11. Data-safety concern

**This diagnosis mutated nothing.** Read-only queries only; no assignment, no finalize, no
trip, no loading session, no container restart.

**The live screen is, however, actively unsafe to operate right now:**

- It presents an enabled **Finalize group** button on a group whose true state it cannot read
  (§4). Finalize is a real write that snapshots the execution manifest. It is documented and
  test-certified as idempotent, so pressing it is unlikely to corrupt state — but the
  operator would be authorising a write against a state the screen has already demonstrated
  it cannot see.
- It instructs the operator to "Assign a vehicle and driver first" when both are already
  assigned. Complying re-invokes `assign-vehicle`, a real write that re-resolves the pairing
  on an existing Trip.

**Recommendation while frozen:** apply §8 item 1 (deployment parity) before anyone operates
this group. It requires no code change and no migration.

---

## Final status

**DIAGNOSIS COMPLETE / IMPLEMENTATION FROZEN**

No backend code, frontend code, database, migration or operational data was modified. No
verification suite, browser check or container restart was run, and none will be run
automatically after this report.

The system is not in the inconsistent state the screen depicts. The Trip exists, the vehicle
and driver are assigned and persisted, and the group is past planning. One stale file in the
running container prevents the screen from seeing any of it, and two frontend panels turn
that silence into confident, false statements.

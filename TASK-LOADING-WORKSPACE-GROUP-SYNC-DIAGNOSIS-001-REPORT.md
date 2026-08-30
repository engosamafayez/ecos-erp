# TASK-LOADING-WORKSPACE-GROUP-SYNC-DIAGNOSIS-001

**Status: DIAGNOSIS COMPLETE / IMPLEMENTATION FROZEN**

Date: 2026-08-26 · Branch: `develop` · Nothing modified, nothing committed, nothing deployed

---

## 1. Executive Summary

**"No loading sessions." is factually correct. `loading_sessions` contains 0 rows, and
`vehicle_assignments` contains 0 rows.** The page is not failing to display data — there is
no data to display.

**Nothing in the system creates a Loading Session from a Distribution Group.** No event, no
listener, no scheduler, no service. The only two things that create a session are a manual
`POST /loading/sessions` (not exposed anywhere in this page) and the Distribution flow at
`POST …/slots/{slot}/trips/{trip}/loading`, which is **gated behind a Trip *and* a
vehicle+driver pairing**.

**This is therefore not a bug, a wrong query, or a stale container. It is a direct conflict
between the business contract in this brief and the architecture as built and certified.**

> The brief states: vehicle / driver / trip **must not** be prerequisites.
>
> `GroupLoadingContextService::open()` states the opposite, in a code comment:
> *"Loading cannot open before the Group has a vehicle and driver — **that is the approved
> order of the workflow, not a new rule**."*

Both claim to be the approved rule. **They cannot both be.** That contradiction — not any
defect — is the root cause, and resolving it is an owner decision, not a repair. §5 and §14
set out what each answer would cost.

I am recording the conflict rather than silently adopting the brief's rule, because the
opposite rule is currently load-bearing in the schema, the services and the certified tests.

## 2. Current observed behavior

`/app/operations/loading/workspace` shows **"No loading sessions."**

Confirmed by read-only query against `ecos_dev`:

```
loading_sessions ................. 0
vehicle_assignments .............. 0
distribution_window_orders
  with virtual_slot_id (in a Group)  9      ← Groups DO contain orders
distribution_virtual_slots ....... 3        DG-001, DG-003, DG-TPL-VERIFY
distribution_trips ............... 2        DG-001, DG-003 (DG-TPL-VERIFY has none)
```

So the premise of the brief is verified: **Groups with Orders exist, and Loading shows
nothing.** The page renders the correct empty state for a genuinely empty table.

## 3. Expected certified business behavior (per this brief)

```
Group + Orders → Loading representation → Products + Required Quantities
```

with vehicle, driver, trip, finalization and assignment all **non-prerequisites**, and any
change to Group demand reflected in Loading Required Quantities.

**This behavior is not implemented anywhere in the current system**, and §5.3 shows it
cannot be represented in the current schema without a migration.

## 4. Full Group → Loading data flow (as built)

```
Distribution Group            distribution_virtual_slots
  created by:                   • DistributionWindowController::storeSlot()      :1417
                                • GroupTemplateService (daily template)          :268
        │
        ▼
Group orders                  distribution_window_orders.virtual_slot_id   (9 rows)
        │
        ▼
  ┌─────────────────── ✗ FLOW BREAKS HERE ───────────────────┐
  │  No event. No listener. No service. No scheduler.         │
  │  Nothing observes Group membership and creates Loading.   │
  └───────────────────────────────────────────────────────────┘
        │
        ▼
Loading Session               loading_sessions   ← 0 rows
  created ONLY by CreateLoadingSessionAction, reachable from exactly 2 places:
    (a) POST /loading/sessions          — manual; NOT exposed in this page
    (b) GroupLoadingContextService::open(group, TRIP, actor)
          ← reached only via POST …/slots/{slot}/trips/{trip}/loading
          ← requires a Trip in the URL, and throws without a vehicle+driver pairing
        │
        ▼
Vehicle Assignment            vehicle_assignments   ← 0 rows
  loading_session_id NOT NULL · vehicle_id NOT NULL · trip_id NULLABLE
        │
        ▼
Products / Required           loading_tasks
  vehicle_assignment_id NOT NULL · pool_entry_id NOT NULL · quantity_planned NOT NULL
  created ONLY by LoadProductAction:157 — lazily, at the moment a product is loaded
        │
        ▼
Frontend                      GET /loading/sessions?per_page=50
  sessions → assignments(sessionId) → allocations / inventory / reconciliation
```

**The break is between "Group orders" and "Loading Session".** Every stage below that point
is healthy and would work; it is simply never reached.

## 5. Exact root cause

Three independent facts, each individually sufficient to produce the empty page.

### 5.1 No Group → Loading creation path exists (primary)

`CreateLoadingSessionAction` has exactly two callers (verified by exhaustive grep):
`LoadingSessionController::store()` and `GroupLoadingContextService`. Neither is invoked by
Group creation or by order-membership change.

The Loading module has **no `Listeners/` directory** and subscribes to **no** Distribution
event. `DistributionAssignmentChanged` exists and is emitted by `ManualAssignmentService`,
but **no listener consumes it anywhere in the codebase**.

### 5.2 The one automatic path is gated by Trip + Vehicle + Driver

```php
public function open(VirtualCapacitySlot $group, Trip $trip, string $actorId): array
{
    $this->assertConsistent($group, $trip);
    $this->assertManifestStillBelongsToGroup($group, $trip);
    $this->assertManifestIsComplete($group);        // requires the Finalize snapshot

    $warehouseId = $trip->operationalWarehouseId();
    if ($warehouseId === null) { throw …; }

    $pairing = $trip->driverVehicleAssignment;
    if ($pairing === null) {
        // "Loading cannot open before the Group has a vehicle and driver —
        //  that is the approved order of the workflow, not a new rule."
        throw new RuntimeException(…);
    }
    …
}
```

`Trip` is a **mandatory typed parameter** — the method cannot be called without one — and
the route itself is `…/trips/{trip}/loading`. Every prerequisite the brief forbids is
enforced here.

### 5.3 The requested shape is not expressible in the current schema

| Column | Nullable |
|---|---|
| `vehicle_assignments.vehicle_id` | **NO** |
| `vehicle_assignments.loading_session_id` | **NO** |
| `loading_tasks.vehicle_assignment_id` | **NO** |
| `loading_tasks.pool_entry_id` | **NO** |
| `vehicle_assignments.trip_id` | YES |

Products in Loading (`loading_tasks`) **must** hang off a `vehicle_assignment`, which
**must** carry a `vehicle_id`. There is no shape in which a Group's products can appear in
Loading without a vehicle. Interestingly `trip_id` **is** nullable — so of the three
prerequisites, Trip is the only one the schema would already tolerate; **vehicle is not.**

`loading_sessions` has **no** `virtual_slot_id`, no `group_id` and no `trip_id`. A session
is keyed by **(company, warehouse, operational_date)** — it is a *warehouse-day*, not a
group. The unit that represents a Group inside Loading is the `vehicle_assignments` row.

## 6. Exact file / class / method responsible

| Concern | Location |
|---|---|
| Only session creator | `Modules/Operations/Loading/Application/Actions/CreateLoadingSessionAction.php:30` |
| Trip/vehicle/driver gate | `Modules/Logistics/Distribution/Domain/Services/GroupLoadingContextService.php::open()` |
| Trip-scoped route | `routes/api.php:1772` `POST …/slots/{slot}/trips/{trip}/loading` |
| Workspace list query | `Modules/Operations/Loading/Presentation/Http/Controllers/LoadingSessionController.php:28` `index()` |
| Frontend entry | `frontend/src/features/operations/loading-os/services/loading-os-service.ts:38` `listSessions()` |
| Missing sync | **no file** — no listener, no subscriber, no scheduler |

**No single file is "at fault".** Each behaves as designed. The gap is the absence of a
component nobody has written.

## 7. Classification of the break

| Category | Verdict |
|---|---|
| Backend defect | **No** — `index()` is correct and unrestrictive |
| Frontend defect | **No** — it renders the correct empty state for an empty table |
| **Integration / contract** | **YES — this is the break** |
| Stale container | **No, not for this symptom** (but see §13 — a real gap exists elsewhere) |
| Data synchronization | **YES — no synchronization exists at all** |

## 8. Is Loading Session creation missing?

**Yes — for the Group-driven flow, entirely.**

The mechanism to create a session exists and works; what is missing is anything that
*invokes* it from a Group. And because the workspace exposes no create-session control
(there is no `createSession` in the service and no `useCreateSession` hook), an operator
standing on this page has **no way to produce a session at all** — not even manually. The
page can only display sessions created elsewhere.

## 9. Is the Loading Workspace query/filter wrong?

**No.** `index()` filters by `company_id` plus optional `status`, `warehouse_id`,
`operational_date`, `search`. It has **no** Group, Trip, Vehicle or Driver filter, does not
join, and would happily return a session with no vehicle and no trip if one existed.

The frontend sends only `per_page: 50`. The query is sound; the table is empty.

**Q7 / Q8 explicitly:** the workspace does **not** search Trips, and does **not** filter
sessions by Vehicle or Driver. The vehicle-dependency is not in the *query* — it is upstream,
in what is allowed to *exist* (§5.2, §5.3).

## 10. How Groups are expected to update Loading

**Today: they do not, by any mechanism.** There is no snapshot, no projection, no event, no
listener, no reconciliation job. Adding or removing an order from a Group, changing
allocation or changing quantities produces **no effect whatsoever** in Loading.

`loading_tasks.quantity_planned` is written **only** by `LoadProductAction:157`, lazily, at
the moment a product is actually loaded. So Required Quantities are not derived from the
Group *and* not held as a synchronized snapshot — in the operator workspace they are not
derived from the Group at all.

The only place where "`quantity_planned` = the Group's live Required" is stated is the
**unmerged** Group-as-Shipment work described in §13.

## 11. Impact of no Vehicle / Driver / Trip

Under the **current** architecture, the impact is total: with no Trip there is no
`open()` call; with no pairing `open()` throws; with no vehicle a `vehicle_assignments` row
cannot be inserted (NOT NULL); and with no assignment a `loading_tasks` row cannot be
inserted (NOT NULL). The Group is therefore invisible in Loading at every layer
simultaneously — this is over-determined, not a single missing link.

Under the **brief's** contract, all four of those states must still yield a visible Group
with products and Required quantities. Reaching that requires at minimum a nullable
`vehicle_id` (or a new group-grain parent row), which is a **migration**, plus a new
read/creation path. It is not reachable by configuration or by relaxing a guard.

## 12. Relation to the existing `ilike` MySQL issue

**Not related. It is not the cause, and it is not triggered here.**

```php
->when($request->query('search'), fn ($q, $v) => $q->where('session_number', 'ilike', "%{$v}%"))
```

It is applied **only** `when` a `search` parameter is present. The workspace sends only
`per_page: 50`, so the clause never executes. `ilike` is PostgreSQL syntax and this
deployment is MySQL 8.4, so it would fail **if** a search were ever issued — a real latent
defect, recorded as previously noted, **not fixed and not modified** per the brief.

## 13. Additional related defects discovered

1. **Group-as-Shipment work exists but is unmerged and unapplied.**
   `Modules/Operations/Loading/Infrastructure/Database/Migrations/2026_08_25_100000_allow_group_grain_loading_null_pool_provenance.php`
   is **untracked in git** and **has not run** on `ecos_dev` (`pool_entry_id` still reads
   NOT NULL). Its header describes `TASK-DRIVER-WAVE-1-GROUP-LOADING-IMPLEMENTATION-001`
   (Option 1, owner-approved) and states *"`quantity_planned` = the Group's live Required"*.
   **This is the closest existing work to the contract in this brief** and is the natural
   home for any correction — it should be reviewed before anything new is designed.
   It relaxes pool provenance only; it does **not** make `vehicle_id` nullable.

2. **Stale container files in the Loading write path** (answers Q18 fully). Parity check
   host vs `ecos-dev-app`:

   | File | Host | Container | |
   |---|---|---|---|
   | `LoadingSessionController.php` | 6579 | 6579 | in parity |
   | `CreateLoadingSessionAction.php` | 1939 | 1939 | in parity |
   | `LoadProductAction.php` | 8803 (03:26) | 8382 (02:13) | **STALE** |
   | `VehicleInventoryService.php` | 15034 (03:26) | 13228 (02:13) | **STALE** |

   **The files governing this symptom are current, so staleness is not the cause here.**
   But the two stale files are exactly the driver downward-correction fix from
   TASK-LOADING-CLOSURE-AND-DRIVER-LOADING-FIX-001 — meaning **that fix is present on the
   host and absent from the running app**. This is the same partial-`docker cp` pattern
   identified in TASK-DISTRIBUTION-TRIP-STATE-DIAGNOSIS-001 and is now confirmed twice.

3. **The workspace cannot create a session** (§8) — no `createSession` in the service, no
   `useCreateSession` hook. Even with the contract unchanged, the page is a dead end when
   empty: it states a fact and offers no action.

4. **A Group with no Trip is invisible in two different places for two different reasons**
   — here, and in the Transport panel diagnosed previously. DG-TPL-VERIFY currently has
   orders and no trip, so it is absent from Loading and reported "not finalized" in
   Distribution.

## 14. Recommended correction scope — DIAGNOSIS ONLY, NOT IMPLEMENTED

**The first step is an owner decision, not code.** The brief's contract and the certified
architecture are in direct opposition (§1). Nothing should be built until one is declared
authoritative, because the two answers have very different costs:

**Option A — adopt the brief's contract (Group-grain Loading).** Substantial. Requires: a
group-grain representation that does not depend on a vehicle (migration to make
`vehicle_id` nullable, or a new parent row for group-grain tasks); a creation/projection
path from Group → Loading; a Required-quantity derivation from live Group demand; and a
synchronization mechanism for §10. Certified tests that assert the current order-of-workflow
would need renegotiation. **Start by reviewing the unmerged Group-as-Shipment work (§13.1)
— it is already heading here.**

**Option B — keep the current architecture.** Then the page is behaving correctly and the
real defect is one of *communication*: the empty state says "No loading sessions" when it
means "no Group has reached the vehicle-assigned stage yet". A truthful empty state naming
the actual precondition, plus a route to the action, would be a small frontend change.

**Both are out of scope here. Nothing was implemented.**

Regardless of the choice, two items are independent and safe to schedule: re-sync the stale
container files (§13.2) and address the latent `ilike`/MySQL defect (§12).

## 15. Data safety confirmation

**No business data was modified.** No INSERT, UPDATE, DELETE, migration, seed, factory,
test order, test group, driver assignment, vehicle assignment, group finalization, trip or
loading session was created or altered.

Method used: `SELECT` and `information_schema` reads; source, route, controller, service and
frontend inspection; read-only `docker exec` of `stat`/`grep` for the parity comparison. No
`docker cp`, no restart, no rebuild, no container mutation.

**Verification freeze respected in full.** No Browser, Playwright, Cypress, Vitest, PHPUnit,
regression suite, `tsc`, ESLint, PHPStan, migration or external API was run. The problem was
**not** demonstrated via the browser, per §6 of the brief.

State after diagnosis, unchanged from before it:

| Table | Rows |
|---|---|
| `loading_sessions` | 0 |
| `vehicle_assignments` | 0 |
| `distribution_trips` | 2 |
| `distribution_virtual_slots` | 3 |
| `distribution_window_orders` in a Group | 9 |

---

## Answers to Q1 – Q18

| Q | Answer |
|---|---|
| **Q1** | `DistributionWindowController::storeSlot()` (`POST /windows/{window}/slots`, :1417) and `GroupTemplateService` (:268) via the daily-template lifecycle. Neither touches Loading. |
| **Q2** | `CreateLoadingSessionAction:30` — the only creator. Two callers: `LoadingSessionController::store()` and `GroupLoadingContextService`. |
| **Q3** | **No.** Group creation does not create a session, and no event/listener/scheduler does. |
| **Q4** | **Yes.** `open()` throws when the pairing is null, and `vehicle_assignments.vehicle_id` is NOT NULL. |
| **Q5** | **Yes.** Driver and vehicle are one pairing row, so the same guard blocks both. |
| **Q6** | **Yes.** `Trip` is a mandatory typed parameter of `open()`, and the route is `…/trips/{trip}/loading`. |
| **Q7** | **No.** It queries sessions, never trips. (Sessions are nonetheless only creatable via a trip-gated path.) |
| **Q8** | **No** in the query — `index()` has no vehicle/driver filter. **Yes** structurally downstream: assignments are vehicle rows. |
| **Q9** | `GET /loading/sessions?per_page=50` → `listSessions()`. |
| **Q10** | `company_id` + optional `status`, `warehouse_id`, `operational_date`, `search`; `orderByDesc(created_at)`; paginated. No group/trip/vehicle filter. |
| **Q11** | **No.** `loading_sessions` has no group column; sessions are keyed by (company, warehouse, date). |
| **Q12** | Indirectly only: `vehicle_assignments.loading_session_id` → session, `vehicle_assignments.trip_id` → `distribution_trips.virtual_slot_id` → Group. No direct link. |
| **Q13** | `loading_tasks` → `vehicle_assignment_id` (NOT NULL) → `vehicle_assignments`. Created only by `LoadProductAction:157`, lazily at load time. |
| **Q14** | **Neither.** Not derived from the Group and not a synchronized snapshot. Only the unmerged group-grain path (§13.1) defines `quantity_planned` = Group's live Required. |
| **Q15** | **Nothing.** No mechanism exists. |
| **Q16** | **Nothing.** No mechanism exists. |
| **Q17** | **No.** Loading has no `Listeners/` directory and subscribes to no Distribution event. `DistributionAssignmentChanged` is emitted but never consumed. |
| **Q18** | **Yes, staleness exists — but not on this path.** `LoadingSessionController` and `CreateLoadingSessionAction` are in parity; `LoadProductAction` and `VehicleInventoryService` are stale (§13.2). |

---

## Final status

**DIAGNOSIS COMPLETE / IMPLEMENTATION FROZEN**

No backend code, frontend code, database, migration or operational data was modified. No
verification was run and no follow-up task was started.

The Loading Workspace is not broken. It is reporting, accurately, that no Loading Session
exists — because nothing in the system has ever been built to create one from a Distribution
Group, and the one automatic path that exists is deliberately gated behind the Trip, Vehicle
and Driver that this brief says must not be prerequisites. The gap between those two
positions is the finding, and closing it is an architectural decision for the owner.

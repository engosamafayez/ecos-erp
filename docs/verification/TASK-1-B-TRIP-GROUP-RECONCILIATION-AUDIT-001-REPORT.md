# TASK-1-B — TRIP ↔ GROUP RECONCILIATION AUDIT — REPORT

**Date:** 2026-08-24 · **Branch:** `develop` · **AUDIT ONLY.** Read-only throughout: no source
file, schema, business data, capacity, membership or assignment was modified. No commit, no deploy.

---

## 1. Executive Summary

**There is no defect in DG-001, and there is no missing contract. There is a known, already-escalated
open policy question, and I have found the document that escalated it.**

The governing contract is **TASK-OPERATIONS-GROUP-TRIP-VEHICLE-DRIVER-LOADING-DISPATCH-IMPLEMENTATION-001**
(migration docblock `2026_08_21_100002_add_group_ownership_to_distribution_trips.php` + its FINAL
REPORT). It states the answer to the central question outright:

> `distribution_trip_orders` is an **execution manifest**, not planning membership … Group
> membership stays in `distribution_window_orders.virtual_slot_id`.
> — FINAL REPORT line 101

So Group orders and Trip orders are **two different concepts by design**, not two copies of one
concept. They are not supposed to be equal, and nothing synchronises them.

The 7-vs-3 divergence is fully explained by timestamps, with no anomaly:

- **DG-001 was finalized at 2026-08-21 16:45:57**, when it held exactly one zone, `DZ-0007`
  (attached 10:53:40).
- **`DZ-0002` was attached at 2026-08-21 17:49:23 — 63 minutes AFTER Finalize.** All five
  "missing" orders belong to `DZ-0002`.
- ORD-00007 was a legitimate member at Finalize; a later city correction re-resolved its zone out
  of the Group, leaving a stale manifest row.

**This corrects my own earlier master-audit hypothesis.** I previously suggested the Trip had been
created by the pre-Finalize vehicle path (`resolveTrip`) and that Finalize had then no-op'd. That is
**disproven**: trip 275 carries `finalized_at = 2026-08-21 16:45:57`, `finalized_by = 1` and the name
`DG-001 · 1` — the exact pattern `GroupFinalizationService::openTrip()` produces. It was Finalize.

**One decision is required**, and it is not a new one: which Group-side operations must consult Trip
state. It is item 2 of the governing report's own Risks/Limitations (§13 below).

---

## 2. Existing Contract / ADR Evidence

No ADR file governs this; the contract lives in the task artefacts, which are explicit.

| Statement | Source |
| --- | --- |
| Group = planning/preparation unit; Trip = transport execution unit | migration `2026_08_21_100002…php` docblock |
| `1 Group → 1..N Trips` (N **only** when `Trip.capacity` forces it); `1 Trip → exactly 1 Group`, structural via a single-valued column | same |
| Warehouse is **derived, not copied** — "true by construction rather than by synchronisation" | FINAL REPORT §, line 124 |
| **"The Group remains the planning source of truth for warehouse, zones, order membership, Required, Prepared and Loading Preparation. Nothing was moved to Trip or Vehicle."** | FINAL REPORT line 99 |
| **"`distribution_trip_orders` is an execution manifest, not planning membership"** | FINAL REPORT line 101 |
| Finalize "never changes Group membership — the Group remains the planning source of truth and Finalize only READS it" | `GroupFinalizationService` docblock |
| "IDEMPOTENT BY RE-READ, NOT BY KEY. A second Finalize returns the Trips the first one produced." | same |
| Composition freezes at `Loading → LoadingCompleted`; `Planning` and `Loading` remain editable | FINAL REPORT §14 |
| Trip capacity is **operator-declared**, deliberately not derived from a vehicle | `openTrip()` docblock + FINAL REPORT line 196 |
| **The divergence is a stated, unresolved limitation** | FINAL REPORT §14 and §32 item 2 |

### Evidence classification

The task asked me to distinguish contract evidence from implementation evidence and test-only
assumptions. The no-re-sync rule is the strongest of the three classes — it is **test-enforced**,
not merely documented:

`DistributionGroupTripTest::test_finalize_creates_the_canonical_trip_and_is_idempotent` (:76)

```php
// IDEMPOTENT. A retry returns the same Trip and creates no second one.
$second = $this->finalize($group)->assertOk()->json('data');
self::assertSame($first[0]['trip_number'], $second[0]['trip_number']);
self::assertSame(1, $this->tripCount($group));
self::assertSame(3, (int) DB::table('distribution_trip_orders')->count());
```

A retried Finalize is asserted to leave the manifest at **exactly** its original row count. Any
implementation that reconciled the manifest on Finalize would fail this certified test. So
"Finalize does not re-sync" is a **guarded invariant**, not a documentation claim — and a future
auto-sync built into Finalize would be caught immediately.

Two further assertions in the same class bound the contract:
`test_a_group_trip_refuses_an_order_from_another_group` (:120) enforces the ownership guard, and
`test_trip_capacity_forces_a_split_and_never_duplicates_an_order` (:151) confirms the split is
real and driven by `Trip.capacity` — the reachability limit in §9 is configuration, not dead code.

§32 item 2, verbatim:

> **Group-side membership is not blocked after Finalize** (§14). The Trip refuses order changes past
> `Loading`, but a Group zone edit can still diverge from a finalized Trip. Needs a policy decision
> on which Group operations consult Trip state.

Its acceptance matrix records criterion 11 — *"Finalized Group cannot be protected from membership
changes"* — as **PARTIAL — reported**. So this audit re-discovered a documented gap, not a new bug.

---

## 3. Group Membership Model

- **Table:** `distribution_window_orders` — one row per order, `dist_window_orders_order_unique` is a
  **single-column global unique on `order_id`**, so an order belongs to at most one window forever.
- **Group link:** `virtual_slot_id` (nullable) — set/cleared by zone attach/detach/move and by manual
  per-order moves.
- **Derivation:** an order's Group follows its **zone**. `distribution_slot_zones` links zones to
  Groups at `(window, warehouse, zone)`; attaching a zone pulls that zone's orders into the Group.
- **Read model:** `DistributionAggregationService::orders()` / `slotOrderCounts()` filter by
  `virtual_slot_id` under `constrainToLoadingEligible` (`in_progress`, `confirmed`,
  `ready_for_dispatch`).
- **Mutable at any time.** Nothing in the Group-side write paths consults Trip state.

---

## 4. Trip Membership Model

- **Table:** `distribution_trip_orders` — an **execution manifest**. Carries `zone_code_snapshot`,
  `governorate_snapshot`, `assignment_type`, `assigned_by`, `assigned_at`: the platform's idiom for
  *a copy of a fact owned elsewhere*.
- **Sole writer:** `TripService::assignOrder()` (`TripService.php:101`). Also `removeOrder()` (:166,
  hard delete) and `moveOrder()` (:179, between Trips).
- **Three guards, in order** (`assignOrder`):
  1. `! $trip->isEditable()` → refuse. Editable = `Planning` or `Loading` (`TripStatus::isEditable`, :85).
  2. An order already on **any** Trip → refuse (`orderAlreadyOnAnotherTrip`), under `lockForUpdate`.
  3. **Group ownership:** if `virtual_slot_id !== null`, the order must **currently** be a member —
     `distribution_window_orders.virtual_slot_id = trip.virtual_slot_id` — else refuse.
  4. `isAtCapacity()` → refuse (`Trip.capacity` − manifest count).
- **`orders_count` is a maintained counter**, resynced by `syncOrdersCount()` on every write.

**Live provenance — all four manifest rows in the system:**

```
TRP-001  ORD-00002  type=auto  assigned_at=2026-08-21 16:45:57
TRP-001  ORD-00006  type=auto  assigned_at=2026-08-21 16:45:57
TRP-001  ORD-00007  type=auto  assigned_at=2026-08-21 16:45:57
TRP-002  ORD-00011  type=auto  assigned_at=2026-08-23 01:07:36
```

Every row is `type=auto`, stamped at its Trip's exact `finalized_at`. **Every Trip membership in the
live system was created by Finalize; there has never been a manual add, remove or move.**

---

## 5. Group → Trip Creation Flow

`POST /windows/{w}/slots/{s}/finalize` → `GroupFinalizationService::finalize()`, entirely inside one
transaction:

1. **Lock** the Group row (`lockForUpdate`) — serialises against itself and against Prepared writes.
2. **Idempotency, inside the lock:** any non-`Cancelled` Trip on the slot → **return it and stop**.
   No re-sync, no reconciliation, no second snapshot.
3. **Prerequisites** (`assertFinalizable`): warehouse present · at least one eligible order (never an
   empty Trip) · `count(orders) ≤ group.capacity_orders` when set · no **over-prepared** product.
4. **Snapshot:** eligible members sorted by `order_number` (stable, so the split is deterministic).
5. **`buildTrips`:** open a Trip only when there is an order for it; assign one at a time via
   `TripService::assignOrder(..., 'auto')`; open the next Trip when `remainingCapacity() === 0`.
6. **Seal:** stamp `finalized_at` / `finalized_by`, transition `Planning → Loading`.

`openTrip()` leaves `capacity`, `type` and `status` at their **schema defaults (60 /
company_vehicle / planning)** and re-reads the row, because `Trip::create()` returns only the passed
attributes and a NULL in-memory capacity would make `remainingCapacity()` return 0.

**Second creation path:** `GroupVehicleAssignmentService::resolveTrip()` materialises a Trip when a
vehicle is assigned before Finalize, deliberately ("A Group with no Trip is still assignable"). **It
did not create either live Trip** — both carry `finalized_at`.

---

## 6. Post-Creation Membership Behaviour — the seven scenarios

| | Scenario | Contract behaviour | Evidence |
| --- | --- | --- | --- |
| **A** | Order enters the Group **before** a Trip exists | Included in the Finalize snapshot, if eligible at that instant | ORD-00002 / ORD-00006 (zone attached 10:53:40, finalized 16:45:57) |
| **B** | Trip created **after** the Group already holds orders | Finalize snapshots **all** eligible members at that moment, splitting only at `Trip.capacity` | `buildTrips`; TRP-001 took exactly the 3 then-eligible members |
| **C** | Order enters the Group **after** the Trip exists | **Not added automatically.** Addable manually via `POST /trips/{id}/orders` while the Trip is editable — the ownership guard passes because it *is* now a member | 5 × `DZ-0002` orders, still absent |
| **D** | Order **leaves** the Group after being manifested | Manifest row **remains** — a stale entry. `DELETE /trips/{id}/orders/{orderId}` exists to clear it; nothing does so automatically | ORD-00007 |
| **E** | Group is finalized | Snapshot taken once. A second Finalize **returns the existing Trips** and re-syncs nothing — **test-enforced**, see §2 | `finalize()` step 2, inside the lock; `DistributionGroupTripTest:76` asserts the manifest row count is unchanged on retry |
| **F** | Trip is already `Loading` | **Still editable** — `isEditable()` = `[Planning, Loading]`. Membership may still change. Composition freezes at `Loading → LoadingCompleted` | `TripStatus::isEditable` :85; §14 |
| **G** | Trip already has Vehicle + Driver | **Does not lock membership by itself.** Only the Trip's *status* gates it; assignment is orthogonal | no membership guard in `assignDriverVehicle` |

`Loading → Planning` is an allowed transition — the **reopen** path already exists (§14). No new
state is needed to make a finalized Trip editable again.

---

## 7. DG-001 Reconciliation — explained, not defective

| Fact | Value |
| --- | --- |
| TRP-001 `finalized_at` / `created_at` | **2026-08-21 16:45:57** (`finalized_by = 1`, name `DG-001 · 1`) |
| `DZ-0007` attached to DG-001 | 2026-08-21 **10:53:40** — before Finalize |
| `DZ-0002` attached to DG-001 | 2026-08-21 **17:49:23** — **63 minutes after Finalize** |

Group membership rows, with the time `virtual_slot_id` was last written:

```
ORD-00002  zone=7  updated=2026-08-21 10:53:40   → on TRP-001
ORD-00006  zone=7  updated=2026-08-21 10:53:40   → on TRP-001
ORD-00009  zone=2  updated=2026-08-21 17:49:23   ← after Finalize
ORD-00012  zone=2  updated=2026-08-21 17:49:23   ← after Finalize
ORD-00016  zone=2  updated=2026-08-22 23:57:05   ← after Finalize
ORD-00018  zone=2  updated=2026-08-23 02:37:21   ← after Finalize
ORD-00019  zone=2  updated=2026-08-23 02:37:21   ← after Finalize
```

At 16:45:57 DG-001 held **one** zone with **two** eligible orders, plus ORD-00007 (then also
`DZ-0007`) — exactly the 3 rows the manifest contains. **All five later arrivals joined through a
zone attached after the Trip was sealed.** Nothing was dropped, mis-written or lost.

My earlier hypothesis — that ORD-00009 (00:03) and ORD-00012 (03:28) were members *before* the Trip
and were skipped — was wrong: those are the rows' **`created_at`** (when the order entered the
*window*), not when it joined the *Group*. `updated_at` is the Group-membership write, and both are
17:49:23.

---

## 8. ORD-00007 Investigation

**Classification: legitimate historical membership + stale execution manifest. By design.**

Not a stale snapshot *bug*, not an incorrect write, not a manual Trip assignment, not a test artifact.

Evidence chain:

1. `distribution_trip_orders`: `type = auto`, `assigned_at = 2026-08-21 16:45:57` — identical to
   TRP-001's `finalized_at`. It entered the manifest **through Finalize**, so it was an eligible
   DG-001 member at that instant. At that time DG-001 held only `DZ-0007`, so its zone was `DZ-0007`.
2. `distribution_window_orders` now: `virtual_slot_id = NULL`, `distribution_zone_id = 9`,
   `assignment_source = manual_move`, and the reason string records the cause verbatim:
   **"City changed from [Maadi] to [Obour City]; zone re-resolved."**
3. `DZ-0009` is attached to **no** Group, so the re-resolution left the order group-less.
4. The order's geography was corrected on 2026-08-23 (independently established in the Groups audit).

So: a legitimate geography correction moved the order's zone out of the Group **after** the manifest
was written. The manifest is a historical copy — the `zone_code_snapshot` column exists precisely so
the Trip records what was true when it was built. Nothing reconciles it, and by the §14 contract
nothing is supposed to.

---

## 9. Trip Capacity Investigation

**Answer to Q9: (A) independent of Group capacity — and documented as such.**
**Answer to Q10: intentional default configuration. Not stale data. Not a defect.**

| Axis | Source | Live |
| --- | --- | --- |
| Group capacity | `distribution_virtual_slots.capacity_orders` — operator-set, enforced by `GroupCapacityGuard` under a row lock and re-checked at Finalize | **20** on all 3 Groups |
| Trip capacity | `distribution_trips.capacity` — **schema default 60**, deliberately left at the default by `openTrip()` | **60** on both Trips |

`openTrip()`, verbatim:

> "capacity / type / status keep their schema defaults (60 / company_vehicle / planning). Capacity is
> deliberately NOT derived from a vehicle: no vehicle is assigned yet, and **the architecture
> decision established that trip capacity is operator-declared**, with the vehicle fit checked later
> in Dispatch's proposal path."

Both are order counts; neither is weight, volume or refrigeration. They answer different questions —
Group capacity bounds *the plan*, Trip capacity bounds *one vehicle load* and is the only thing that
triggers the `1 Group → N Trips` split.

**Consequence worth recording (not a data defect):** with Group capacity 20, Finalize refuses at 21
orders while the split needs 60 — so `1 Group → N Trips` is unreachable for every Group that
currently exists. That is an interaction between two independent, correctly-configured limits.
A prior adversarial check confirmed the split path itself is reachable and test-covered; the
unreachability is this tenant's operator configuration, not broken code.

---

## 10. Loading / Vehicle / Driver Impact

- **Vehicle/Driver:** `PATCH /trips/{id}/assignment` binds a pairing from the canonical ledger
  (`logistics_driver_vehicle_assignments`). It imposes **no** membership guard, so assignment neither
  locks nor reconciles the manifest.
- **Loading:** composition freezes at `Loading → LoadingCompleted`. Dispatch requires
  `LoadingCompleted → DriverAccepted`, so any manifest change must happen before that gate.
- **Loading's allocation reads the Preparation Wave, not the Trip** (established in the master audit),
  so the manifest is not currently Loading's input — an auto-sync would not fix Loading and could
  diverge from it.
- **Live state:** `loading_sessions`, `vehicle_assignments`, `driver_assignments` all **0**;
  `logistics_vehicles` and `logistics_drivers` **0**. The Vehicle/Driver→Loading legs are unexercised,
  exactly as the governing report's Risks item 3 states.

**Auto-synchronising the manifest would collide with:** the freeze contract (a sync after
`LoadingCompleted` would rewrite frozen composition); the snapshot columns' purpose (historical facts
would be silently rewritten); the `orderAlreadyOnAnotherTrip` invariant (a re-sync across a split
Group must decide *which* Trip receives a late order — an allocation decision Part 4 forbade
inventing); and audit provenance (`assignment_type`/`assigned_by`/`assigned_at` would need a third
value meaning "system-reconciled").

---

## 11. Data Safety Findings

Read-only throughout. Only `SELECT`/`SHOW COLUMNS` were executed. No Group, Trip, order, assignment,
capacity, vehicle, driver or Loading row was created or modified; no test business data was made; no
regression suite was run. Verified unchanged at audit end: **19 orders · 3 groups · 13 assignments ·
2 trips · trip capacities 60,60**.

---

## 12. Root Cause Classification

| Observation | Classification |
| --- | --- |
| DG-001 has 7 Group orders, 3 manifest rows | **Not a defect.** Documented snapshot semantics + a zone attached 63 min after Finalize |
| ORD-00007 on the Trip but not in the Group | **Not a defect.** Legitimate member at Finalize; later geography correction re-resolved its zone out of the Group. Stale manifest row is the designed behaviour |
| No automatic reconciliation exists | **Deliberate, documented** — `distribution_trip_orders` is an execution manifest, not planning membership |
| A finalized Trip can silently diverge from its Group | **KNOWN OPEN POLICY QUESTION** — governing report §14 / §32 item 2, acceptance criterion 11 = *PARTIAL — reported* |
| Trip capacity 60 vs Group capacity 20 | **Intentional.** Two independent order-count limits; Trip capacity is operator-declared with a schema default |
| The divergence is invisible to the operator | **Real UI gap.** No screen shows manifest-vs-Group drift, and the add/remove/move API has no reachable UI (the Trips workspace has no navigation entry) |

---

## 13. Decision Required

**One decision, already escalated once and still open.** The contract is complete about *what*
Trip membership is; it is deliberately silent about *whether Group-side edits must consult Trip
state*. Quoting the governing report: *"Needs a policy decision on which Group operations consult
Trip state."*

**The question:** when a finalized Trip exists, what happens to a Group-side change?

Four positions, none of which requires a new entity, table, status or engine:

- **B1 — Keep divergence, make it visible.** Group edits stay free; the workspace surfaces
  "manifest differs from Group" and offers the existing `POST/DELETE /trips/{id}/orders`. No contract
  change; purely additive UI over existing endpoints. *Smallest, and consistent with the manifest
  concept.*
- **B2 — Block Group edits while a live Trip exists.** Zone attach/detach/move refuse when the Group
  has a non-cancelled Trip; the operator must first reopen it (`Loading → Planning`, which already
  exists). Strongest consistency; removes operator freedom late in the day.
- **B3 — Operator-triggered re-sync.** An explicit "update manifest from Group" action on an editable
  Trip, reusing `assignOrder`/`removeOrder`. Needs a rule for which Trip receives a late order when a
  Group split into N — an allocation decision Part 4 explicitly forbade inventing.
- **B4 — Automatic sync.** Not recommended: it collides with the freeze contract, the snapshot
  columns' purpose, and audit provenance (§10), and the instruction for this task forbids
  automatically synchronising the two.

**My recommendation: B1.** It matches the contract already approved (the manifest is a copy of a fact
owned elsewhere, deliberately not synchronised), invents nothing, and converts a silent divergence
into a visible, actionable one using endpoints that already exist. B2 is the coherent alternative if
the platform wants execution to be authoritative once sealed.

---

## 14. Recommended Implementation Boundary

If B1 is approved, the whole change is **read-side plus existing endpoints**:

1. Expose the drift on the Group's Transport panel: manifest count vs eligible Group count, and the
   two difference sets ("in Group, not on Trip" / "on Trip, no longer in Group").
2. Wire the **existing** `POST /trips/{id}/orders`, `DELETE /trips/{id}/orders/{orderId}` and
   `POST /trips/{id}/orders/move` to that panel, enabled only while `isEditable()`.
3. Give the Trips workspace a navigation entry (it is fully built and currently unreachable).
4. Label Group capacity and Trip capacity distinctly wherever both appear — already done for the
   Group card in TASK-1-A.

No migration, no new endpoint, no new permission (`logistics.distribution.update` already guards all
three routes), no capacity change, no membership engine.

---

## 15. Explicit Non-Goals

Not proposed and not to be inferred: automatic Group↔Trip synchronisation · any change to Group
identity, Group→Trip ownership, Loading Preparation, Vehicle or Driver identity, the assignment
ledger, Preparation Wave, Distribution eligibility or Fulfillment eligibility · any new membership
engine, source of truth, status, table, migration, capacity engine or quantity engine · any change to
`Trip.capacity` · any repair of the live DG-001 or ORD-00007 rows.

---

## 16. STOP Conditions / Blockers

- **BLOCKER (decision, not code):** §13. Implementation must not start until the policy is chosen.
  The contract is *deliberately incomplete* here — the governing task recorded it as PARTIAL rather
  than resolving it, and its Part 13 forbade inventing a state for it.
- **Not blocking, recorded:** the Vehicle/Driver→Loading legs cannot be exercised at all — zero
  vehicles, zero drivers, zero pairings, zero loading sessions. Any Trip work touching Loading is
  unverifiable in this environment today.
- **Not blocking, corrected:** my master-audit statement that DG-001's Trip came from the
  pre-Finalize `resolveTrip` path is **withdrawn** — `finalized_at` disproves it (§1, §7).

---

## 17. Suggested TASK-1-B Implementation Scope

Contingent on B1. **Scope:** surface the manifest-vs-Group drift on the Group Transport panel; wire
the three existing membership endpoints behind `isEditable()`; add the missing Trips navigation entry.
**Out of scope:** everything in §15, plus Trip capacity reconciliation, Loading integration, and
Dispatch. **Tests:** drift is computed and displayed correctly; add/remove/move refuse once the Trip
leaves `Loading`; the Group-ownership guard still refuses a non-member; no automatic sync occurs.

---

> ## STATUS: AUDIT COMPLETE — IMPLEMENTATION NOT STARTED

### Confirmed facts

Trip membership lives in `distribution_trip_orders`, written **only** by `TripService::assignOrder`,
and is an **execution manifest** — explicitly *not* planning membership. Group membership remains in
`distribution_window_orders.virtual_slot_id`. All 4 live manifest rows are `type=auto` stamped at
their Trip's `finalized_at`: every one was created by Finalize, and there has never been a manual
add, remove or move. Finalize is idempotent by re-read and never re-syncs.

### Root causes

DG-001: `DZ-0002` was attached **63 minutes after** Finalize; all five absent orders belong to it.
ORD-00007: a legitimate member at Finalize whose city correction re-resolved its zone into `DZ-0009`,
which belongs to no Group. **Neither is a defect** — both are the documented snapshot contract.

### Should Group and Trip synchronise?

**Not automatically — the approved contract says they are different concepts.** The genuine gap is
that divergence is *invisible* and the corrective endpoints have no UI. Whether Group edits should be
*blocked* while a live Trip exists is the open policy question.

### Trip capacity contract

**Independent of Group capacity**, operator-declared, schema default 60 — intentional and documented.
Group capacity 20 vs Trip capacity 60 is not stale data and not a defect. Side effect: the
`1 Group → N Trips` split is unreachable at a Group cap of 20.

### Owner decision required

**Yes — one**, and it is a re-escalation, not a discovery: *which Group-side operations must consult
Trip state* (governing report §14 / §32 item 2; acceptance criterion 11 = PARTIAL — reported).
Options B1–B4 in §13; **B1 recommended**.

### Proposed implementation boundary

Read-side drift visibility + the three existing membership endpoints + the missing Trips nav entry.
No migration, no new endpoint, no new permission, no capacity change, no sync engine.

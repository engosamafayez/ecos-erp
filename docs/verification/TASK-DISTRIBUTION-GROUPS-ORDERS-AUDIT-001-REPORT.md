# TASK-DISTRIBUTION-GROUPS-ORDERS-AUDIT-001 — REPORT

**Date:** 2026-08-24 · **Branch:** `develop` · **Scope:** Preparation Wave → Window → Assignment → Zone → Group → Trip, and the Groups UI only.

> **STATUS: AUDIT COMPLETE / IMPLEMENTATION NOT STARTED**
> Read-only throughout. No code, migration, API, RBAC, or business-data change. No commit. No deploy.

---

## 1. Executive Summary

The chain is **functionally intact end-to-end**. Every one of the 11 active wave members has a
Distribution assignment. Nothing is silently lost, and the capacity engine is contract-correct
(order-count only, remaining derived, never stored).

Five of the six reported problems are real but **none is a broken Groups query**. They reduce to
**three independent root causes**:

| Root cause | Produces |
| --- | --- |
| **R1 — Window resolution fails OPEN when no warehouse is selected** | Problem 1, Problem 2 (dominant), and the false Problem 5 report |
| **R2 — Two eligibility predicates by design (narrow for Zones, wide for Groups)** | Problem 2 (secondary, conditional on wave start) |
| **R3 — `distribution_trip_orders` is a write-once snapshot, reconciled in neither direction** | Problem 6, and the live 7-vs-3 divergence |

Problem 3 (Awaiting Payment in Preparation) is **not currently present** and the write paths are
correct — but the audit found a **new, real defect** in the RC-3 work certified yesterday (§5).

Problem 4 (old Group design) is confirmed and is purely presentational.

**One live data defect:** ORD-00001 has `city = NULL`, so it can never resolve a zone and can never
enter a Group. Not repaired (read-only audit).

---

## 2. Trace Results

Company `019f4e1c…`, warehouse `019f4e1c-2e1b…`, governing wave `PREP-202608-000007`.

| # | Step | Source | Query / Service | Filter | Result |
| --- | --- | --- | --- | --- | --- |
| 1 | Governing wave | `preparation_waves` | `WaveManager::getActiveWave` via `DistributionAggregationService::governingPreparationWave:354` | `status=collecting`, `wave_type='engine'`, **`warehouse_id` required** | `PREP-202608-000007`, planning_date **2026-08-23** |
| 2 | Wave membership | `preparation_wave_orders` | `whereNull(released_at)` | active only | **11 members, all `in_progress`** |
| 3 | Window | `distribution_windows` | `DistributionWindowService::resolvePlanningWindow:103` | anchor = window holding most active wave members | window `01a021a0`, `window_date` **2026-08-21** |
| 4 | Assignments | `distribution_window_orders` | `dist_window_orders_order_unique` (**single-column unique on `order_id`**) | one window per order, forever | **11 / 11 assigned** (13 on that window in total) |
| 5 | Zone | `distribution_zone_id` | `OrderZoneResolver` ← `logistics_city_id` ← `orders.city` | city text is the only input | **10 / 11 zoned** (ORD-00001 has `city=NULL`) |
| 6 | Group / Slot | `distribution_virtual_slots` + `distribution_slot_zones` | zone→slot link, warehouse-scoped | zone must be attached to a slot | **8 / 11 grouped** |
| 7 | Group count | derived | `DistributionAggregationService::slotOrderCounts:867` under `constrainToLoadingEligible` | `in_progress, confirmed, ready_for_dispatch` | DG-001 = 7, DG-003 = 1, DG-TPL-VERIFY = 0 |
| 8 | Trip | `distribution_trips` / `distribution_trip_orders` | `GroupFinalizationService::finalize` / `TripService::assignOrder` | write-once at Finalize | DG-001 trip = **3 orders**, DG-003 trip = 1 |

**Per-order detail (all 11 on the 2026-08-21 window):**

```
ORD-00001  zone —        group —        (city NULL → unzoneable)
ORD-00002  DZ-0007      DG-001         on trip
ORD-00006  DZ-0007      DG-001         on trip
ORD-00007  DZ-0009      —              ON TRIP but in NO group
ORD-00009  DZ-0002      DG-001         not on trip (assigned 08-21 00:03)
ORD-00010  DZ-0008      —              zone in no group
ORD-00011  DZ-0001      DG-003         on trip
ORD-00012  DZ-0002      DG-001         not on trip (assigned 08-21 03:28)
ORD-00016  DZ-0002      DG-001         not on trip (assigned 08-22)
ORD-00018  DZ-0002      DG-001         not on trip (assigned 08-23)
ORD-00019  DZ-0002      DG-001         not on trip (assigned 08-23)
```

---

## 3. Finding #1 — Orders → Groups

**Reported:** orders in the active wave do not appear correctly in Groups.
**Verdict: CONFIRMED — cause E (window resolution) triggered by F (warehouse scope). Not a Groups bug.**

`governingPreparationWave` returns `null` the moment `warehouse_id` is absent
(`DistributionAggregationService.php:359-361`). `resolvePlanningWindow` then takes its first branch
and returns `windowFor(today)` (`DistributionWindowService.php:110`) — a **calendar** window, which
`windowFor` **creates if it does not exist**. That window holds no assignments, no zones and no
Groups, so every tab renders a fully-drawn, plausible, **empty** board.

The frontend makes this easy to hit: `activeWarehouseId` initialises from `localStorage` with **no
default** (`organization-context.tsx:26-28`), and changing company **clears** it (`:37-38`). A fresh
operator, a new browser, or anyone who just switched company lands on `null`.

**Live proof of the fail-open path:** windows exist for 2026-08-22 and 2026-08-23 with
`status=open` and **0 assignments** — artefacts of this branch. No 2026-08-24 window exists yet;
one will be minted by the next page open.

There *is* a hint — `cycle.selectWarehouse` (`distribution-workspace-page.tsx:171`) — but it is one
amber line above a board that looks authoritative and complete.

**With a warehouse selected the resolver is correct:** it anchors to the 2026-08-21 window by
majority active-member count and the operator sees the real 8 grouped orders.

**Residual, genuinely-not-a-bug:** 3 of 11 are legitimately outside a Group — ORD-00001 (no city →
no zone) and ORD-00007 / ORD-00010 (zones DZ-0009 / DZ-0008 are attached to no Group). Only 3 of
the 10 existing zones are covered by a Group.

---

## 4. Finding #2 — Count Reconciliation

**Reported:** Preparation count and Zone/Distribution count differ.
**Verdict: CONFIRMED — two mechanisms, causes E/F and B.**

**Reconciliation (company + warehouse + wave, as required):**

```
wave active members ....................... 11
with a Distribution assignment ............ 11   (0 lost)
with a zone ............................... 10   (−1: ORD-00001, city NULL)
in a Group ................................  8   (−2: zones in no Group)
on a Trip .................................  4   (see §8)
```

**Mechanism A — dominant (same as R1).** Preparation's wave list is company-scoped and the frontend
never sends `warehouse_id`, so Preparation always prints **11**. Distribution is warehouse-keyed, so
with no warehouse it prints **0**. Two screens, two scopes, same orders.

**Mechanism B — two predicates, by design.** `zoneSummaries` uses `constrainToEligible` (narrow:
`in_progress, confirmed`) while `slotRollup` / `slotOrderCounts` / `orders` use
`constrainToLoadingEligible` (wide: **plus `ready_for_dispatch`**). The service documents the
consequence itself at `DistributionAggregationService.php:79-82`. **Today all 11 are `in_progress`,
so the two agree — the divergence appears only once a wave starts** and Preparation moves orders to
`ready_for_dispatch`: the Zone tab drops them, the Group tab keeps them.

This is not a competing *eligibility* predicate in the contract sense — the Group predicate is a
superset used for "what is in today's departure". But it is operator-visible as two different
numbers for one window, and §12 asks whether that is acceptable.

---

## 5. Finding #3 — Payment / Eligibility

**Reported:** an Awaiting Payment order appeared in Preparation/Distribution.
**Verdict: NOT CURRENTLY PRESENT — write paths correct (cause H) — but ONE NEW DEFECT FOUND (cause B).**

- **Zero** ineligible orders hold an active wave membership right now (verified: all 11 members are
  `in_progress`).
- ORD-00017 is still `awaiting_payment`, but its membership was released at **2026-08-23 13:00:00**
  — wave 6's `ends_at`, i.e. by `closeWave()`, not by RC-3.
- Admission is correct: live `wave_engine_configurations.eligible_order_statuses` = `["in_progress",
  "confirmed"]`; `attachEligibleOrders` accepts only a `Collecting` wave.
- Retention is correct: RC-3's observer releases on any status leaving `fulfilmentEligible()`, with
  the `hasLeftPreparation()` guard so forward progress is not punished.

### NEW DEFECT — RC-3's eviction is not honoured by all readers

RC-3 (certified 2026-08-23) signals eviction by stamping **`released_at`**. But pre-existing read
paths test only **`postponed_at`**:

- `GenerateDemandAction.php:39` and `:58` — `whereNull('pwo.postponed_at')`, **no `released_at`
  check**. Its own comment cites REFINEMENT-002 §22, written before `released_at` existed as an
  eviction signal.
- Same pattern at `PreparationWaveController.php:329`, `WaveMembershipService.php:288` and `:320`.

**Consequence:** an order RC-3 released for ineligibility still **contributes demand** to its wave.
This is a gap in work I closed yesterday and I am reporting it rather than leaving it implied.
It does not weaken the certified payment gate or the RC-3 retention guard — it means the two
"membership is over" semantics (`postponed_at`, `released_at`) are not read consistently.

---

## 6. Finding #4 — Group UI

**Reported:** Group screen still uses the old design.
**Verdict: CONFIRMED — cause G (UI-only presentation).**

`distribution-groups-panel.tsx` (525 lines) is not an operational overview:

- An **always-expanded "New Distribution Group" creation card sits at the top** of the board
  (`:187-325`) — configuration occupying the primary operational surface.
- **All five operational components are mounted only here** (verified: nothing else in the codebase
  imports them) — `GroupLoadingPreparation`, `GroupTripPanel`, `GroupVehicleAssignment`,
  `GroupLoadingExecution`, `GroupZoneManager`, at `:17-21`.
- They are revealed by **one toggle labelled "Loading preparation"** (`:436-449`), so Trip creation,
  Vehicle+Driver pairing and Loading execution are all hidden behind a label that names none of them.
- The Group card shows 6 metrics but renders **neither capacity, remaining, utilisation nor the
  over-capacity warning**, although the server sends all four.

Good news for the target IA: **Groups / Zones / Map / Settings / Templates already exist as five
sibling tabs** (`distribution-workspace-page.tsx:561-576`) — Map and Templates are *not* nested
under Groups. Only Loading Preparation is misplaced.

---

## 7. Finding #5 — Capacity

**Reported:** Group capacity and displayed counts can be inconsistent.
**Verdict: the ENGINE IS CORRECT (cause H). The DISPLAY is not (causes G + B).**

Contract-conformant and verified live:

```
DG-001         capacity=20  assigned=7  derived_remaining=13
DG-003         capacity=20  assigned=1  derived_remaining=19
DG-TPL-VERIFY  capacity=20  assigned=0  derived_remaining=20
```

`remaining_orders` is computed at read time (`max(0, capacity − count)`,
`DistributionAggregationService.php:245`) and **never stored**. Capacity is order-count only;
`GroupCapacityGuard` explicitly refuses to read the stops/weight/volume columns. **No second
capacity engine was introduced.** The reported "cannot set Group maximum" is **NOT REPRODUCED** — it
is settable from the Settings tab and every live Group carries 20; the original report was almost
certainly taken from a no-warehouse session (R1), where the board is empty and therefore shows no
capacity input at all.

Three real display defects:

1. **`distribution_trips.capacity` is a schema default of `60` that nothing derives** — live: both
   Trips carry 60 while their Groups carry 20, and **both numbers are rendered in the same panel**.
   This is the reported "20 vs 60". It is a second stored order-count number, not a display bug.
2. The Loading-Preparation strip **recomputes remaining client-side, unfloored**, so it can display
   a negative the server never reports.
3. `distribution_virtual_slots` still carries `capacity_stops`, `capacity_weight_kg`,
   `capacity_volume_m3`, and `storeSlot` still accepts them — dead columns the contract excludes.

---

## 8. Finding #6 — Group → Trip

**Reported:** the Group → Trip relationship is not clear in the UI.
**Verdict: CONFIRMED, and worse than presentational — cause B (backend logic).**

**`distribution_trip_orders` is a write-once snapshot that is reconciled in neither direction.**
Live evidence from DG-001 (trip created 2026-08-21 16:45:57):

| Order | In Group? | On Trip? | Assigned to Group at |
| --- | --- | --- | --- |
| ORD-00002, ORD-00006 | yes | yes | before trip |
| **ORD-00007** | **no** | **YES** | zone DZ-0009 is in no Group |
| ORD-00009 | yes | no | 08-21 **00:03** — *before the trip existed* |
| ORD-00012 | yes | no | 08-21 **03:28** — *before the trip existed* |
| ORD-00016 / 00018 / 00019 | yes | no | 08-22 / 08-23 — after |

Two distinct failures:

- **Backfill never happened.** ORD-00009 and ORD-00012 were in the Group ~13 hours *before* the Trip
  was created and were still never added. That rules out "late arrival" as the whole story and points
  at the pre-Finalize path: assigning a vehicle materialises a zero-order Trip
  (`GroupVehicleAssignmentService::resolveTrip:181`), after which `GroupFinalizationService::finalize`
  short-circuits on the existing-Trip idempotency read (`:78-86`) and never runs the bulk assignment.
  The UI then computes `finalized = trips.length > 0` (`group-trip-panel.tsx:51`) and **hides the
  Finalize button**, so the operator cannot retry.
- **No downward reconciliation.** ORD-00007 sits on the Trip while belonging to no Group at all.

UI clarity, separately: the Group card header renders "Vehicle: Not assigned / Driver: Not assigned"
**unconditionally** (`distribution-groups-panel.tsx:359-374`; `SlotSummary` carries no vehicle/driver
field), while `GroupTripPanel` immediately below shows the *real* pairing — the same card contradicts
itself. And `TripResource` omits the owning Group, so a Trip cannot be traced back to its Group.

---

## 9. Root Causes

| ID | Cause | Class | Explains |
| --- | --- | --- | --- |
| **R1** | `resolvePlanningWindow` fails **open** to `windowFor(today)` when `warehouse_id` is null, and `windowFor` *creates* that empty window. Frontend has no warehouse default. | **E + F** | Problem 1, Problem 2 (dominant), false Problem 5 |
| **R2** | Narrow predicate for Zones vs wide (`+ready_for_dispatch`) for Groups — documented, but two operator-visible numbers | **B (by design)** | Problem 2 (conditional) |
| **R3** | `distribution_trip_orders` written once at Finalize; no backfill, no downward reconciliation; pre-Finalize vehicle assignment creates a zero-order Trip that permanently suppresses the Finalize button | **B** | Problem 6, live 7-vs-3 |
| **R4** | Groups panel is the mount point for all five operational components + the create form | **G** | Problem 4 |
| **R5** | `distribution_trips.capacity` default 60 — a second stored order-count capacity | **B / architectural** | Problem 5 "20 vs 60" |
| **R6** | `released_at` vs `postponed_at` read asymmetry | **B** | new defect, §5 |
| **R7** | `orders.city = NULL` on ORD-00001 → unzoneable; and 7 of 10 zones belong to no Group | **A (data) / config** | 3 of 11 legitimately ungrouped |

---

## 10. Already Implemented (do not rebuild)

- **Wave-anchored window resolution** — correct and working whenever a warehouse is selected.
- **Capacity engine** — order-count only, `remaining` derived and never stored, enforced under a row
  lock by `GroupCapacityGuard`, with Finalize as backstop.
- **Zone membership operations** — add / remove / move, each atomic, guarded, warehouse-scoped, with
  a DB unique at (window, warehouse, zone) and a confirmation dialog.
- **Carry-over** — wave 6 closed with 12 released; 11 returned to `in_progress` and re-entered wave 7.
- **Preparation eligibility write paths** — admission and RC-3 retention both correct.
- **Five sibling tabs** already exist; Map and Templates are already *not* under Groups.
- **i18n** — `distributionWorkspace` is fully translated (288 leaves, 287 Arabic).

---

## 11. Missing / Broken

| # | Item | Class |
| --- | --- | --- |
| 1 | No explicit unresolved state when no warehouse is selected — board renders empty and authoritative | E |
| 2 | `windowFor` **creates** a window on a GET (read performs a write) | B |
| 3 | Trip contents never backfilled from, nor reconciled against, Group membership | B |
| 4 | Finalize button suppressed forever once any Trip exists | B + G |
| 5 | Group card omits capacity / remaining / utilisation / over-capacity, all of which the server sends | G |
| 6 | Group card header hardcodes "Not assigned", contradicting the panel below it | G |
| 7 | `distribution_trips.capacity` = 60, underived, displayed beside the Group's 20 | B |
| 8 | Loading-Preparation strip recomputes remaining client-side, unfloored (can show negative) | C |
| 9 | `released_at` ignored by `GenerateDemandAction` and 3 other read paths | B |
| 10 | 7 of 10 zones attached to no Group; no UI surfaces "zone in no group" | G / config |
| 11 | ORD-00001 `city = NULL` → permanently unzoneable, no operator-visible reason | A |
| 12 | Dead capacity columns (`stops`, `weight`, `volume`) still accepted by `storeSlot` | H |
| 13 | No Group delete / archive / close — DG-TPL-VERIFY is a permanent empty artefact | F |

---

## 12. Owner Decisions Required

1. **R1 fail-open vs fail-closed.** Should a null warehouse render an explicit "select a warehouse"
   empty state (fail closed), instead of today's freshly-created calendar window? *Recommended:
   fail closed.* Secondary: should the frontend default to the first available warehouse?
2. **Should a GET create a window?** `windowFor` is `firstOrCreate`; that is why empty 08-22/08-23
   windows exist. Split read from create, or accept it?
3. **Two counts, one window (R2).** Is it acceptable that after wave start the Zones tab and the
   Groups tab report different totals for the same window, or must they be unified? If unified,
   which predicate wins?
4. **Trip capacity (R5).** Derive `distribution_trips.capacity` from the owning Group's
   `capacity_orders`, or stop exposing Trip capacity for Group-owned Trips? A second stored
   order-count capacity contradicts the single-axis contract either way.
5. **Trip ↔ Group reconciliation (R3).** Should a Trip continuously track its Group's membership, or
   remain a snapshot with an explicit operator "re-sync" action? And should assigning a vehicle
   before Finalize be **blocked** rather than silently creating a zero-order Trip?
6. **Zone coverage.** Should the UI surface "this zone belongs to no Group" (7 of 10 today), and
   should an order whose zone has no Group be visible as an operational exception?
7. **`released_at` vs `postponed_at` (§5).** Confirm that eviction must be honoured by demand
   generation, i.e. that read paths test both. This is a correctness fix to RC-3's integration.

---

## 13. Recommended Small Implementation Task

**TASK-DISTRIBUTION-GROUPS-001 — Make the Groups board tell the truth about its own scope.**

Smallest change that removes the largest class of false reports (problems 1, 2 and the false 5),
touching one backend branch and one frontend guard:

1. `resolvePlanningWindow` — when `waveId` is null, return an **unresolved** result instead of
   `windowFor(today)`; do not create a window.
2. Workspace page — when no warehouse is selected, render a single explicit unresolved state instead
   of five tabs over an empty window.
3. Group card — render the `capacity_orders`, `remaining_orders`, `utilisation` and over-capacity
   fields the server already sends, and delete the hardcoded vehicle/driver header literal.

No migration, no new endpoint, no new permission, no RBAC change, no capacity engine.
Explicitly **out of scope**: Trip reconciliation (needs decision 5), Trip capacity (decision 4),
Loading Preparation extraction, and the `released_at` read fix (decision 7) — each is its own task.

---

> **STATUS: AUDIT COMPLETE / IMPLEMENTATION NOT STARTED**

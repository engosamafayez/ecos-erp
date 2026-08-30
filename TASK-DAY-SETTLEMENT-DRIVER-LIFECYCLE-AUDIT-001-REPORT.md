# TASK-DAY-SETTLEMENT-DRIVER-LIFECYCLE-AUDIT-001

**AUDIT ONLY — READ-ONLY. Nothing was changed.**

Backend: **untouched** · Frontend: **untouched** · Database: **untouched** ·
Migrations: **none** · Permissions: **untouched** · Test data created: **NONE** ·
Mutations: **NONE** · Browser: **NOT OBSERVED**

Date: 2026-08-26 · Branch: `develop`

---

## 1. Executive Summary

**The page is not broken, and the zeros have two separate causes — neither is the one the
symptom suggests.**

**Cause 1 — the board is empty because of the DATE, not the lifecycle.** Driver visibility is
gated by
`DATE(COALESCE(trip_started_at, dispatched_at, created_at)) = <selected date>`.
No trip in DEV has an effective date of today. The page defaults to today. Hence 0.

Proven by running the real service against three dates:

| Date queried | total_drivers | needs_settlement |
|---|---|---|
| **2026-08-26** (today — the page default) | **0** | 0 |
| 2026-08-25 | **1** | **1** |
| 2026-08-21 | **1** | **1** |

On 2026-08-25 the board returns **OSAMA FAYEZ AHEMD, vehicle 1336, status `needs_review`**.
The driver *does* appear. The read model, the KPI maths, the pairing join and the status
aggregation all work.

**Cause 2 — even on the correct date every work/money column is 0**, because those columns
are derived from **delivery stops**, and `distribution_delivery_stops` is empty. This is the
same bridge gap already under investigation (§9), and it is genuinely felt here.

**Day Settlement does NOT depend on delivery stops for the driver to APPEAR** — only for the
driver's numbers to be non-zero. That distinction is the core finding of this audit.

## 2. Current Day Settlement Architecture

```
/app/logistics/operations/driver-settlement
  driver-settlement-workspace-page.tsx        date defaults to todayIso()
    → hooks/use-driver-settlement.ts
      → services/driver-settlement-service.ts   BASE '/logistics/distribution/driver-settlement'
        → GET /api/logistics/distribution/driver-settlement      (permission: logistics.distribution.view)
          → DriverDaySettlementController::index()
            → DriverDaySettlementReadService::daySummary(company, date, filters)
              → dayTrips()                       ← THE GATE
              → SettlementService::financialSummary($trip)   (money + stop counts)
              → deliveredCountByTrip() / returnsCountByTrip()
```

Detail page: `GET …/driver-settlement/{assignmentId}?date=` → `driverDay()`.

Both routes are **read-only**; the service header states every write goes through the
canonical per-trip settlement + payment-proof services.

## 3. Current Data Source — column by column

| Column | Derived from | Table |
|---|---|---|
| **Driver row exists** | `dayTrips()` — trip with a pairing whose effective date matches | `distribution_trips` |
| Driver / Vehicle | `driverVehicleAssignment.driver` / `.vehicle` | `logistics_driver_vehicle_assignments`, `logistics_drivers`, `logistics_vehicles` |
| **Orders** | `sum(financialSummary['stops_total'])` → `$trip->stops()->count()` | **`distribution_delivery_stops`** |
| **Delivered** | `deliveredCountByTrip()` — stops with `DeliveryStopStatus::Delivered` | **`distribution_delivery_stops`** |
| Delivery % | `deliveryPct(delivered, orders)` — derived | — |
| **Returns** | `returnsCountByTrip()` | delivery stops / returns |
| Cash Expected | `financialSummary['cash_expected']` | settlement engine |
| Transfers | `financialSummary['bank_transfers_pending']` | settlement engine |
| Difference | `aggregateDifference()` over `discrepancy` | settlement engine |
| **Status** | `aggregateSettlementStatus()` | `distribution_trip_settlements` |
| KPIs | counted from the built rows | — |

**`distribution_trip_orders` is NOT read by this page.** Orders comes from **stops**.

## 4. Exact reason for 0 drivers — proven

`DriverDaySettlementReadService::dayTrips()`:

```php
Trip::query()
    ->where('company_id', $companyId)
    ->whereNotNull('driver_vehicle_assignment_id')
    ->whereRaw('DATE(COALESCE(trip_started_at, dispatched_at, created_at)) = ?', [$date])
```

Three conditions. In DEV the first two **pass** for all three trips (all carry pairing 209).
The third fails for every trip on today's date:

```
today (app tz) : 2026-08-26

TRP-001  started=NULL dispatched=NULL created=2026-08-21 16:45  → EFFECTIVE DATE 2026-08-21
TRP-002  started=NULL dispatched=NULL created=2026-08-23 01:07  → EFFECTIVE DATE 2026-08-23
TRP-003  started=NULL dispatched=NULL created=2026-08-25 22:54  → EFFECTIVE DATE 2026-08-25
```

The frontend sends `todayIso()` (`driver-settlement-workspace-page.tsx:25,42`). No trip's
effective date is 2026-08-26 → empty collection → `total_drivers = 0` and the
"No drivers need settlement today" empty state.

**Because no trip has ever been started or dispatched, the date falls all the way back to
`created_at`** — a trip is bucketed on the day the row was created, which is a planning
artefact, not an operational day.

## 5. Live DEV evidence (read-only)

```
distribution_trips              3   (all with driver_vehicle_assignment_id = 209 → driver 396)
distribution_trip_orders        4   TRP-001: 3 · TRP-002: 1 · TRP-003: 0
distribution_delivery_stops     0   ← none, on any trip
distribution_trip_settlements   0

TRP-001  status=loading   finalized_at=2026-08-21 16:45
TRP-002  status=loading   finalized_at=2026-08-23 01:07
TRP-003  status=planning  finalized_at=NULL          ← never finalized
```

Real service output for 2026-08-25:

```json
{ "kpis": { "total_drivers": 1, "needs_settlement": 1, "under_review": 0, "settled": 0 },
  "drivers": [{ "driver_name": "OSAMA FAYEZ AHEMD", "vehicle_plate": "1336",
                "orders": 0, "delivered": 0, "returns": 0,
                "cash_expected": 0, "difference": null,
                "settlement_status": "needs_review" }] }
```

## 6. Actual Driver/Trip lifecycle — as implemented, not assumed

```
Group + eligible orders
   │
   ├── assign vehicle+driver ──► Trip CREATED ON DEMAND (resolveTrip)   ← no finalize, NO trip orders
   │                              pairing attached (driver_vehicle_assignment_id)
   │
   └── Finalize ──────────────► Trip created if absent + MANIFEST SNAPSHOT → distribution_trip_orders
                                   │
   Start Loading ──────────────────┴─► LoadingSession + VehicleAssignment  (loading_tasks)
   Warehouse confirms ──────────────► loading_tasks.quantity_loaded + confirmed_at
   Driver confirms ─────────────────► driver_confirmed_at (custody)
   Loading Complete ────────────────► VehicleAssignment = LoadingComplete
                                      (+ generateStops + trip → LoadingCompleted — in-flight, §9)
                                   │
   Delivery ────────────────────────► distribution_delivery_stops progress
   Settlement ──────────────────────► distribution_trip_settlements
```

**TRP-003 is the shipment the operator was loading (DG-004).** It has `finalized_at = NULL`,
status `planning`, and **0 trip orders** — it was created on demand by *assign vehicle*, and
Finalize (the only step that snapshots trip orders) never ran. So even a working stops bridge
would have produced nothing for it.

## 7. Where the lifecycle breaks — three distinct breaks

| # | Break | Effect on Day Settlement | Category |
|---|---|---|---|
| **1** | No trip is ever *started* or *dispatched*, so the day bucket falls back to `created_at` | **The driver does not appear on today's board** — the reported symptom | **B — missing lifecycle transition** (+ a read-model date-source question) |
| **2** | `distribution_delivery_stops` = 0 | Orders / Delivered / % / Returns / Cash all read 0 **even on the correct date** | **D — missing delivery stops** |
| **3** | TRP-003 never finalized → 0 trip orders | That trip has no deliverable work to generate stops *from* | **C — missing trip orders** |

**Answer to §4: G — more than one cause.** Break 1 alone explains the empty board; breaks 2
and 3 explain why the row is empty even when found.

**It is NOT a read-model bug (A), not a wrong eligibility rule (E), and not a frontend filter
bug (F).** The read model returns correct rows when a trip matches the date, and the
frontend's `status`/`search` filters are applied after row construction and were unset.

## 8. Relationship to Trip Orders

**Day Settlement never reads `distribution_trip_orders`.** Its `orders` column is
`stops_total` = `$trip->stops()->count()`. Trip orders matter only *indirectly*: they are the
input `DeliveryService::generateStops()` converts into stops. A trip with trip orders but no
stops (TRP-001: 3 orders, 0 stops) still shows `orders = 0` here.

## 9. Relationship to Delivery Stops

**Direct and decisive for the CONTENT, irrelevant to the APPEARANCE.**

- Does Day Settlement need delivery stops? **For its numbers, yes** — Orders, Delivered,
  Delivery %, Returns all come from stops.
- Does it need trip orders? **No, not directly.**
- Does it need delivery results? **Yes** for Delivered/Returns; cash/difference come from the
  settlement engine.
- **Can a driver appear before any delivery stop exists? YES — proven.** The 2026-08-25 query
  returned the driver with `orders = 0` and stops = 0. Appearance is gated on trip date +
  pairing only.
- Correct relationship: **stops gate the WORK, not the ROW.**

So the in-flight stops bridge (`TASK-DRIVER-LOADING-COMPLETION-ORDERS-BRIDGE-001`, whose four
tests currently fail with 0 stops) **will fix the empty columns but will not, by itself, make
the driver appear on today's board** — because it does not set `trip_started_at` or
`dispatched_at`. It does advance the trip to `LoadingCompleted`, which is a *status*, not a
date, and `dayTrips()` reads dates.

## 10. Settlement eligibility rule — as implemented

There is **no explicit eligibility rule**. A driver-day row exists iff a trip has a pairing
and its effective date matches. `settlement_status` then aggregates the per-trip settlement
engine (`aggregateSettlementStatus`), defaulting to **`needs_review`** when no settlement
record exists — which is why the 2026-08-25 row reads `needs_review` with
`distribution_trip_settlements = 0`.

**Implication:** the board is *not* designed as post-delivery-only. A trip with a pairing and
a matching date appears regardless of delivery progress. The gate is temporal, not lifecycle.

## 11. When should the driver appear — architecture analysis

The real question this audit surfaces is **not** "which lifecycle event should reveal the
driver", but **"which timestamp is the operational day"**. Today the answer is
`created_at`, a planning artefact.

## 12. Options A–E

| | Option | Fits current architecture? | Data needed | Mixes Loading with Settlement? | Duplicate lifecycle records? | New business logic? | Conflicts with custody? | Conflicts with Delivery/Trip lifecycle? |
|---|---|---|---|---|---|---|---|---|
| **A** | Appear at **Loading Started** | Poorly — `dayTrips()` reads trips, not loading sessions | `vehicle_assignments` / `loading_sessions` | **YES** — settlement would read a Loading table | No | Yes — new eligibility source | Indirect: makes Settlement depend on custody tables | No |
| **B** | Appear at **Loading Complete** | Partially — needs a status/date the query does not use | `VehicleAssignment.loading_completed_at` or trip `LoadingCompleted` | Somewhat | No | Yes — new date source | No | No |
| **C** | Appear at **Driver Confirmation + Loading Complete** | Poorly — pulls custody state into settlement eligibility | custody columns | **YES, strongly** | No | Yes | **YES** — settlement would gate on custody | No |
| **D** | Appear at **Dispatch / Out for Delivery** | **Best fit** — `dispatched_at` is already the query's *second* preference and is a real operational timestamp | none new | No | No | **None** — the query already prefers it | No | No — it *is* the delivery lifecycle |
| **E** | Keep Settlement post-delivery only; use a different workspace for in-trip visibility | Fits, and a workspace already exists (§14) | none new | No | No | None for settlement | No | No |

## 13. Recommended architecture — one recommendation

**D + E together, in that order of priority. Do not change the eligibility rule; make the
lifecycle produce the timestamp it already reads.**

1. **The real defect is that trips are never dispatched/started**, so `trip_started_at` and
   `dispatched_at` stay NULL and the day bucket silently degrades to `created_at`. Fixing the
   *lifecycle* (dispatch actually stamping `dispatched_at`) makes Day Settlement correct with
   **zero change to this page or its read model**.
2. **Keep Day Settlement post-dispatch/settlement-oriented (E)**. Pre-settlement, in-trip
   driver visibility belongs to an operations surface, not the money-closing board.

**Why not A/B/C:** each would make Settlement read Loading or custody tables, which is exactly
the separation §6 asks to preserve, and each adds a second eligibility source that can
disagree with the first.

**Worth flagging as a secondary question for you:** even under D, `COALESCE(..., created_at)`
remains a silent fallback that buckets an undispatched trip on its creation day. Whether that
fallback should exist at all is a business decision — it is what turned "no operational day"
into "the wrong operational day" here.

## 14. Existing workspace that should own pre-settlement visibility

**Logistics → Dispatch already owns this**, and no new workspace is needed:

- `logistics-dispatch` → `/logistics/dispatch`
- **`logistics-dispatch-exec` → dispatch-execution-page.tsx** — already renders vehicle/driver
  rows from assignment history with session status badges
- `logistics-dispatch-board` → dispatch board

Also present: **Operations → Loading Drivers** (loading-grain visibility, already Group-first)
and **Logistics → Drivers** (`logistics-drivers`, the roster).

Dispatch Execution is the natural home for "driver is out with work in progress"; Day
Settlement is the day-closing board.

## 15. Required changes — HIGH LEVEL ONLY (not implemented)

1. **Lifecycle (primary):** ensure the dispatch step actually stamps `dispatched_at` /
   `trip_started_at`. No change to Day Settlement.
2. **Stops bridge (already in flight):** completion → delivery stops, so Orders/Delivered/%/
   Returns stop reading 0. Its four tests currently fail — that task owns it.
3. **Finalize discipline:** a trip created on demand by *assign vehicle* has no trip orders
   (TRP-003). Decide whether such a trip may proceed to loading at all.
4. **Owner decision:** whether `COALESCE(..., created_at)` should remain a fallback.

## 16. Files likely affected — READ-ONLY listing, nothing opened for edit

Frontend: `driver-settlement-workspace-page.tsx` · `driver-settlement-detail-page.tsx` ·
`use-driver-settlement.ts` · `driver-settlement-service.ts` · `driver-settlement.ts` ·
`day-settlement-kpis.tsx` · `day-settlement-status-badge.tsx`

Backend: `DriverDaySettlementController.php` · **`DriverDaySettlementReadService.php`** (the
gate) · `SettlementService.php` · `DeliveryService.php` · `TripService.php` · `Trip.php`

Tables: `distribution_trips` · `distribution_delivery_stops` · `distribution_trip_settlements`
· `logistics_driver_vehicle_assignments` · `logistics_drivers` · `logistics_vehicles`
(`distribution_trip_orders` only indirectly)

## 17. Risks

1. **Fixing the stops bridge alone will not clear the empty board** — it changes status, not
   dates. Expect the zeros to persist unless dispatch stamps a date.
2. **Changing `dayTrips()` to key off Loading state** would couple Settlement to Loading and
   break the separation §6 protects.
3. **Removing the `created_at` fallback** would hide historical trips that never dispatched —
   correct in principle, visibly disruptive in DEV where every trip is such a trip.
4. **Backfilling dates** would fabricate operational history. Not recommended.
5. TRP-003's unfinalized state suggests loading can start on a trip with no orders — worth its
   own look, outside this audit.

## 18. Out of scope

Delivery-stops bridge repair · trip/dispatch lifecycle changes · finalize rules · any
frontend or backend edit · schema · permissions · test data · Loading Custody · the operator
Loading workspace.

## 19. Final recommendation

**Do not change Day Settlement.** It is behaving to specification: the driver appears on the
day of their trip, and the row is empty because no delivery work exists.

Fix the **lifecycle** instead — make dispatch stamp a real operational date — and let
pre-settlement, in-trip visibility live in **Dispatch Execution**, which already exists.

Sequence: (1) dispatch stamps a date, (2) the in-flight stops bridge lands and fills the work
columns, (3) revisit whether the `created_at` fallback should stay.

## Browser

**NOT OBSERVED.** I cannot sign in — the DEV driver password was reset to a discarded random
value, there is no secure delivery channel (`mail.default = array`, no reset route), and I do
not enter passwords into login forms. The page's behaviour above is proven by executing the
real read service against the live DEV database and by reading the source — **not** by
viewing the screen.

---

**STOP.** Audit only. Nothing fixed, modified, migrated, seeded or created. No configuration
changed. Awaiting your review before any implementation task.

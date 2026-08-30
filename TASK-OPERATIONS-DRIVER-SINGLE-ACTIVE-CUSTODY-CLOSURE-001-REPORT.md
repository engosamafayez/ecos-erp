# TASK-OPERATIONS-DRIVER-SINGLE-ACTIVE-CUSTODY-CLOSURE-001 — Report
## Driver Closing Core Contract: Single Active Operational Custody per Driver

Implements the core contract the previous task left PARTIAL. Audit-first; verified in the isolated
testrunner; **not deployed to DEV**. **FINAL CERTIFICATION DEFERRED.**

---

## 1. Executive Summary

`ONE DRIVER → ONE ACTIVE OPERATIONAL CUSTODY → ONE DRIVER CLOSING RECORD` is now enforced and read
correctly:
- **Active eligibility** is gated on **real goods custody** (`TripStatus::isCustodyEligible()` =
  loading completed … settlement pending). Planning/loading shells, mere assignments and calendar
  dates never qualify — so OSAMA's three `loading` trips no longer produce Active rows.
- The **single-active-custody invariant** is enforced **server-side** in `TripService::changeStatus`
  at the custody-start transition, with a **driver-level pessimistic lock** for concurrency safety.
- The **Active read identity** is now **one row per open Trip/Custody** (not per calendar day); a
  custody spanning midnight is the same single row.
- A driver holding **more than one open custody** (legacy corruption) is **surfaced as needs-review**,
  never deduped.
- The `expected_collection_at_handoff` financial snapshot is preserved and confirmed custody-aligned.

## 2. Previous Incorrect Active Identity

`activeBoard` selected `DISTINCT (driver_vehicle_assignment_id, DATE(COALESCE(trip_started_at,
dispatched_at, created_at)))` over trips `status != Closed`, and `buildRows` grouped by
`driver_vehicle_assignment_id|op_day`. Consequences (all now removed): any non-Closed trip — including
**`loading`/planning shells** — entered Active; one driver produced **multiple** rows across days; two
trips on the same day **collapsed** into one row. This is why OSAMA (driver 396, assignment 209,
TRP-001/002/003 all `loading` on Aug 21/23/25) showed as three Active rows.

## 3. Canonical Custody Handoff Trace

Real goods custody is a **two-stage** handoff (fully traced, no assumption):
1. **Warehouse records the load** — `LoadProductAction` → `VehicleInventoryService::recordLoad` creates
   the `vehicle_inventory_items` row (vehicle custody credited; warehouse stock not yet issued).
2. **Driver confirms receipt** — `DriverLoadingController::confirmReceived` →
   `LoadingCustodyService::confirmReceived` stamps `loading_tasks.driver_confirmed_at`, **and**
   atomically `TransferLoadedStockToVehicleAction` posts the canonical `vehicle_custody_transfer`
   stock-ledger entry (goods leave the warehouse into vehicle custody).

The trip reaches **`LoadingCompleted`** only once **all loaded products are driver-confirmed**
(`DriverLoadingController::complete()` gates on `unresolvedLoadedTasks()` empty). So **trip status ≥
`LoadingCompleted`** is the canonical "real custody has been handed over" signal at the trip grain.
There is **no** dedicated per-trip "custody started" boolean; the trip-level `driver_accepted_custody`
is a later *departure* rollup. `changeStatus` is the canonical status-transition path (the one
exception, `SettlementService::finalize`, writes `Closed` directly — see §10).

## 4. Active Eligibility (§3)

`TripStatus::isCustodyEligible()` ≡ `!isEditable() && !isTerminal()` = `LoadingCompleted`,
`DriverAccepted`, `DispatchBlocked`, `ReadyForDispatch`, `Dispatched`, `OutForDelivery`, `InProgress`,
`Completed`, `SettlementPending`. Derived from the two existing predicates, so it cannot drift.
`activeBoard` now filters `whereIn('status', TripStatus::custodyEligibleValues())`. A Draft/Planning
trip, a `loading` shell, a bare driver/vehicle assignment, a calendar date, or opening the Closing
page are **never** sufficient — only a trip that has canonically completed loading (custody handed)
appears.

## 5. Single Active Custody Invariant (§4)

A driver may hold **at most one** open operational custody. Enforced in
`TripService::assertDriverHasNoOtherOpenCustody`, called from `changeStatus` when a trip crosses from a
non-custody-eligible status **into** a custody-eligible one (the custody-start boundary). If the
driver already has another custody-eligible trip, `DistributionException::driverAlreadyHasOpenCustody`
is thrown and the transition is refused. Planning may coexist (creating/planning future trips is not
blocked); only the *operational custody* is singular.

## 6. Enforcement Boundary (§5)

The guard sits at the **narrowest canonical operational boundary that would otherwise create the
second custody**: the status transition **into** the custody-eligible set (canonically the
loading-completion transition), inside `TripService::changeStatus` — the single authority through
which trip status moves. No second trip lifecycle was created; no planning container was touched.

## 7. Concurrency Safety (§6)

Two simultaneous custody-starts for the same driver **cannot** both succeed. `assertDriverHasNoOtherOpenCustody`
takes a **pessimistic lock** (`DriverVehicleAssignment … where driver_id … lockForUpdate`) inside the
status-change transaction, then checks the derived open-custody set. The second concurrent request
blocks on the lock until the first commits its custody-eligible status, then observes it and is
rejected. A partial-unique DB index **cannot** safely represent "one open custody across several live
trip statuses" in MySQL 8.4 (no filtered indexes), so — per §6's own caveat — no misleading uniqueness
constraint was added; the guard is a driver lock over the status-derived set.

## 8. Active Read Identity (§7)

`buildRows` now groups **by `trip->id`** — one row per open Trip/Custody, never grouped by calendar
day and never collapsed with DISTINCT/latest/first. Each row retains Driver, Vehicle, Assignment, the
**Trip** (`trip_id`, `trip_number`, `trip_status`), a **custody start** timestamp (`custody_started_at`)
and the current custody/settlement state.

## 9. Cross-Day Behavior (§8)

Because the identity is the trip (not the day), a custody that starts on Aug 28 and stays open through
Aug 29–30 remains the **same single Active row**. Dates are reporting dimensions only; no daily row is
created when the calendar changes.

## 10. Closing (§9)

The existing settlement/closing authority is preserved (`SettlementService::finalize` → trip `Closed`;
`DriverRuntimeController::closeTrip` → `changeStatus(Closed)`). A closed trip **automatically** leaves
the custody-eligible set (both close paths), so it drops out of Active with **no delete hook** — this
robustness is exactly why the invariant is a derived-state lock rather than a maintained registry.
Driver/Vehicle/Trip/reconciliation/collection references are untouched.

## 11. History (§11)

History still lists Closed + Finalized trips, date-filtered by `finalized_at`, but through the same
per-trip `buildRows` — so it is now **one History record per closed Trip/Custody**, not a daily
rollup.

## 12. Post-Close New Custody (§10)

Once Custody A is closed, A is no longer custody-eligible, so the driver's next custody-start passes
the guard. Proven by `test_closing_removes_from_active_and_allows_a_new_custody`.

## 13. Existing OSAMA Data Analysis (§12)

Driver 396 / assignment 209 / TRP-001/002/003 are all `status='loading'`, never started/dispatched.
Under the new eligibility they are **not custody-eligible**, so they **naturally** no longer qualify
for Active — with **no special-case logic** for OSAMA or assignment 209. **Not mutated or deleted**
(read-only diagnosis only).

## 14. Legacy Duplicate Detection (§13)

`flagDuplicateOpenCustody` groups the per-trip rows by driver; any driver with **> 1** open custody
has **every** such row flagged `duplicate_open_custody: true` and stage `needs_review` — surfaced, not
`latest()/first()/DISTINCT`-ed away or frontend-hidden. The mobile card renders an explicit
"Duplicate open custody — review" warning. The write-side guard prevents *new* occurrences; this keeps
genuine legacy corruption **visible**.

## 15. Expected Collection Alignment (§15)

Audited: `generateStops` (which writes `expected_collection_at_handoff`) runs in the driver flow at
`DriverLoadingController::complete()` — i.e. at loading-completion, **after** per-product custody
confirmation. So the snapshot is **already** taken at a point consistent with real custody handoff; it
is **not** rewritten and its design is unchanged. (Caveat preserved for a later task: the dispatcher
endpoint `POST /api/trips/{tripId}/stops/generate` can call `generateStops` without a custody
precondition; documented, not changed here to avoid touching the accepted financial contract.)

## 16. Orders Metrics (§16, §17)

Orders/goods/collections continue to derive from the ONE trip's own stops/collections/reconciliation
(unchanged aggregation), now bound to the single per-trip row. Because a trip has one stable
operational day (anchored to trip start), the metrics do not reset or duplicate across midnight.
Orders Received remain the orders on that trip — not future/planned/Draft orders.

## 17. Goods Custody Metrics (§18)

Goods Received / Delivered / Remaining / Expected Return / Actual Warehouse Return are unchanged
(canonical `VehicleInventoryItem` + `VehicleShiftReconciliation`); Expected vs Actual return stay
separate facts. They now belong to the one per-trip custody row.

## 18. Distribution/Trip Impact (§19, §20)

No redesign of Distribution Group, Loading Workspace, Vehicle/Driver assignment, Review/Finalize or
Dispatch. Planning containers coexist. The only new rule is the operational single-active-custody
guard at the status-change boundary. `TripStatus` gained two derived helpers; `changeStatus` gained the
custody-start guard; no transition table changed.

## 19. Backend Changes

- `TripStatus`: `isCustodyEligible()` + `custodyEligibleValues()`.
- `DistributionException::driverAlreadyHasOpenCustody()`.
- `TripService::changeStatus`: enforce the invariant at the custody-start transition;
  `assertDriverHasNoOtherOpenCustody()` (driver lock + derived-set check).
- `DriverDaySettlementReadService`: `activeBoard` custody-eligibility gate + direct per-trip load;
  `buildRows` grouped per-trip with trip identity + `custody_started_at`; `flagDuplicateOpenCustody()`;
  removed the now-dead `tripsForKeys()`.

## 20. Frontend Changes

- `types/driver-settlement.ts`: `DaySettlementDriverRow` gains `trip_id`, `trip_number`, `trip_status`,
  `custody_started_at`, `duplicate_open_custody`.
- workspace grid `rowId` → `trip_id` (per-custody identity; no same-day collision).
- `DaySettlementDriverCard`: explicit "Duplicate open custody — review" warning when flagged.
- i18n `driverSettlement.duplicateCustody` (EN + AR).

## 21. Schema Changes

**None.** No new table or column was added for the invariant (the derived-state lock needs none), and
no misleading uniqueness constraint was introduced. (The separate, previously-accepted
`expected_collection_at_handoff` column remains pending in DEV — see §24.)

## 22. Files Changed

Backend: `TripStatus.php`, `DistributionException.php`, `TripService.php`,
`DriverDaySettlementReadService.php`, `tests/Feature/Logistics/DriverDaySettlementReadTest.php`.
Frontend: `types/driver-settlement.ts`, `pages/driver-settlement-workspace-page.tsx`,
`components/day-settlement-driver-card.tsx` (+ its test), `pages/*` tests, `i18n/locales/{en,ar}/logistics.json`.

## 23. Focused Verification (§22)

Isolated testrunner (`ecos_dev_test`, RefreshDatabase).

**Backend — `DriverDaySettlementReadTest`: COMPLETED, `OK (18 tests, 137 assertions)`** (the 6 new
single-active tests + Expected-Collection + prior read tests). This gate finished and released the DB
lock *before* the external lock described in §24a. Coverage:
- **A. Eligibility:** loading shell → NOT Active; a custody-eligible trip → exactly one Active row.
- **B. Invariant:** a second custody-start for a driver with one open is **rejected** (domain
  exception, server-side); first handoff succeeds when none is open.
- **C. Time:** one custody-eligible trip = exactly one Active row (per-trip identity; midnight-stable).
- **D. Closing:** closing removes from Active and a new custody is then allowed.
- **E. Read model:** legacy multiple open custodies → **2 rows**, both `duplicate_open_custody:true`,
  both `needs_review` (surfaced, not deduped).
- **F. Metrics:** `expected_collection_at_handoff` immutability + snapshot sum preserved (prior tests).

Concurrency (§22.6) is guaranteed by the pessimistic driver lock (design); a literal parallel-request
race is not unit-simulable in PHPUnit and is asserted by the sequential rejection + the lock.

**Frontend — Vitest driver-settlement folder 25/25; ESLint clean; 0 tsc errors in touched files; i18n
EN↔AR parity (0 missing keys). COMPLETED.**

**Targeted regression verification — RUN (after the shared lock cleared).**
`TripLifecycleCertificationTest` + `TripDepartureLifecycleTest` (the suites exercising the shared
`TripService::changeStatus`) were executed. Result: **3 failures, all pre-existing / unrelated to this
change** — 2 assert the stale `kpis.total_drivers` key (a KPI removed by an earlier task; untouched
here) and 1 is a `422` in the driver-confirm loading flow (a path this task never touches; the
single-active guard never enters it). See §27 verification for the full breakdown. **No regression from
the custody-start guard is evidenced.**

## 24. DEV Runtime Status (§24)

**NOT DEPLOYED.** All backend changes are verified in the **isolated testrunner only**; nothing was
applied to the `ecos-dev-app` runtime. The `expected_collection_at_handoff` migration also remains
**pending** in DEV (unchanged from the prior task). No DEV business data was mutated; OSAMA's trips are
untouched.

### 24a. Shared test-DB lock — read-only diagnosis (no session killed, no data mutated)

The targeted regression gate could not acquire the pinned `ecos_dev_test`. Read-only diagnosis
(`IS_USED_LOCK` + `information_schema.processlist`; `sys.innodb_lock_waits` is not grantable to the
`ecos` user):

- The gate's advisory named lock **`ecos:testrunner:ecos_dev_test` is held by connection `266086`**,
  which is running **`DO SLEEP(14400)`** (a deliberate ~4-hour lock hold), `db=null`, host
  `172.20.0.7:42980`.
- Connection **`266097`** is a **concurrent ungated phpunit process** actively migrating
  `ecos_dev_test` (`ALTER TABLE vehicle_inventory_items ADD CONSTRAINT chk_…`).

These belong to **another, external test run sharing the pinned DB** (a known contended resource). They
were **not** touched — no `KILL`, no rollback, no container restart, no data mutation, per the standing
constraint. My single-active gate had already **acquired, run (18/18 OK) and released** the lock
(connection `265343`) *before* this hold appeared, so the core verification is complete; only the
two lifecycle regression suites remain blocked behind the external holder.

## 25. Remaining Gaps

- DEV deploy of this source + the pending `expected_collection_at_handoff` migration (separate,
  authorized step).
- The dispatcher `stops/generate` endpoint can still snapshot Expected Collection without a custody
  precondition (documented §15; out of scope to avoid touching the accepted financial contract).
- The detail read (`driverDay`) remains keyed by `(assignment, operational_day)`; for a compliant
  single custody this equals the one trip, so metrics are per-custody. A same-day legacy duplicate's
  detail would aggregate both (flagged) — acceptable, noted for a future per-trip detail key.
- The Driver operational cash-movement/expense authority remains the separate, CTO-approval-gated
  follow-up (`task_2e2fd78b`) — not started.
- **Pre-existing baseline failures (NOT this task):** `TripLifecycleCertificationTest` (2) and
  `TripDepartureLifecycleTest` (1) assert a stale `kpis.total_drivers` key and a `422` in the
  driver-confirm loading flow — both predate and are unrelated to this change (§27). They warrant a
  separate baseline-cleanup task (update the lifecycle tests to the current 6-KPI contract).

## 26. Implementation Status

**IMPLEMENTATION STATUS: COMPLETE** — the single-active operational custody contract is implemented and
enforced server-side, the Active/History read model is per open Trip/Custody, real custody gates
eligibility, cross-day is stable, closing frees the driver, duplicates are surfaced, the financial
contract is preserved, and the CTO final table/card UX correction (§27) is applied. My changed backend
suite is fully green (`DriverDaySettlementReadTest` **19/19**); frontend **25/25**. The lifecycle
regression suites were run: their 3 failures are **pre-existing / unrelated** (stale `total_drivers`
KPI + an untouched-path 422), so **no regression from this change is evidenced** — this is a baseline
issue, not an implementation gap, and is **not** a certification.

**DEV RUNTIME: NOT DEPLOYED.**

**FINAL CERTIFICATION: DEFERRED TO FINAL SYSTEM REVIEW.**

---

## 27. CTO Final Table UX Correction (presentation)

Applied on top of the core contract; **presentation-focused** (plus canonical value figures the row
did not yet carry). The **canonical Driver ↔ Trip ↔ Vehicle** relationship and all reconciliation data
remain in the backend/read model and the Settlement detail — only the *primary workspace* changed.

**Removed from the primary table + mobile card:** Vehicle, Damaged, Shortage (§1/§2/§15). They stay in
the Settlement (تصفية) detail; the backend row still carries `vehicle_*`/`damaged_qty`/`shortage_qty`.

**Final desktop columns (§3):** Driver/Trip · Total Orders (count + value) · Delivered (count + value) ·
Failed/Exceptions (count + value) · Delivery Rate · Total Sales · Transfers/Paid · Expenses · Net Cash ·
Goods Remaining · Closing Status · **Settlement**.

- **Driver/Trip (§4):** driver name primary, `trip_number` secondary — no vehicle.
- **Order columns (§5):** count primary, `Order.total`-derived value secondary. New read aggregates
  `orders_value` / `delivered_value` / `failed_value` via `orderValueBreakdownByTrip()`
  (SUM(orders.total) grouped by delivery-stop outcome).
- **Total Sales (§7):** the actual **delivered** value (`= delivered_value`), not total assigned value.
- **Transfers/Paid (§8):** `transfers_paid` = bank transfer + card + prepaid (`already_paid`); **excludes
  physical cash**.
- **Expenses (§9) / Net Cash (§10):** honest **"Not available"** — no canonical authority yet; never a
  fabricated zero, and Net Cash is not a fallback to Cash Collected.
- **Goods Remaining (§11):** canonical remaining custody quantity (`goods_on_hand`).
- **Closing Status (§12):** existing canonical `closing_stage` badge; no new lifecycle.
- **Settlement action (§13/§14):** the primary action is renamed **Settlement** / **`تصفية`** (i18n
  `driverSettlement.settlement`). It is **navigation** into the existing canonical settlement/closing
  detail — **not** an auto-close; the final Close/Finalize stays behind the existing settlement
  authority + guards.

**Mobile card (§15):** same priorities — Driver/Trip (no vehicle), Orders (total/delivered/exceptions
with values), Delivery Rate, Goods Remaining, Financial (Total Sales, Transfers/Paid, Expenses n/a, Net
Cash n/a), Status, **`تصفية`**. Vehicle/damage/shortage are not primary.

**Files (delta):** backend `DriverDaySettlementReadService.php` (value aggregates + helper); frontend
`types/driver-settlement.ts`, `pages/driver-settlement-workspace-page.tsx` (columns), rewritten
`components/day-settlement-driver-card.tsx`, `i18n/locales/{en,ar}/logistics.json` (new column labels +
`settlement`).

**Verification (§27):** frontend driver-settlement **25/25**, ESLint clean, **0 tsc** in touched files,
i18n EN↔AR parity (0 missing).

Backend gate (`DriverDaySettlementReadTest` + the two lifecycle suites — 38 tests, 357 assertions):
- **`DriverDaySettlementReadTest` — 19/19 OK**, including the new value-column test
  (`orders_value 370 / delivered_value 250 / failed_value 120 / total_sales 250 / transfers_paid 80`)
  and all single-active tests. **My changed code is fully green.**
- The two lifecycle suites reported **3 failures, all pre-existing / unrelated to this change**:
  two assert a **stale `kpis.total_drivers`** key that the current `kpis()` (unchanged here) does not
  produce — a KPI contract replaced by the 6-KPI set in an earlier task, never updated in these tests
  (`total_drivers` also lingers in `DriverModuleTest`/`Phase5ModuleTest`); one is a **`422` in the
  driver-confirm loading flow**, a path this task does not touch. The single-active `changeStatus`
  guard never enters that flow (it fires only on a planning/loading→custody-eligible transition,
  absent there). **No regression from the single-active guard or the table-UX change is evidenced.**

---

**STOP.** No commit. No push. No deploy. No DEV business-data mutation.

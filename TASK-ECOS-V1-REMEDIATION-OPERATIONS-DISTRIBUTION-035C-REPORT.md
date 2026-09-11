# TASK-ECOS-V1-REMEDIATION-OPERATIONS-DISTRIBUTION-035C

## Status

**PARTIAL — one confirmed defect fixed and tested at the source level; two areas
verified correct with focused tests added; two areas found already-mitigated with
no change needed; one area (§2) could not be responsibly investigated and was
deliberately left untouched.** Backend test *execution* was environment-blocked
for the whole task (see Environment Blockers) — all conclusions below rest on
direct code reading plus static analysis (PHP syntax, Pint, PHPStan), not on a
green test run.

## Base / Head

- **Base checkpoint:** `7d122fdc6a9cd0aeae977df7c181ad8945dc4a23` (canonical `develop`)
- **Branch:** `task/v1-remediation-operations-distribution-035c`, new isolated
  worktree at `E:\ECOS\_v1-remediation-operations-distribution-035c`
- **Head:** this task's single successor commit (see Final Checkpoint)

## §1 — WaveClosed Listener Race

**Confirmed real defect, fixed.** `WaveClosureCustodyService` and
`DeliveryAttemptClosureService` both react to `WaveClosed` (registered in that
order in `LogisticsDistributionServiceProvider::boot()`), and both ultimately call
`TripService::releaseOrder()` on the same `distribution_trip_orders` rows.
`releaseOrder()` only checks the TripOrder link's own `superseded_at` — never the
Order's business status — so it cannot arbitrate between the two services.

`WaveClosureCustodyService::closeUnacceptedTrip()` released **every** active
TripOrder on an unaccepted Trip unconditionally. Its own docblock's original
assumption ("the Order's status is untouched: it was never anything but
ReadyForDispatch") predates `DeliveryAttemptClosureService` and no longer holds:
an Order on an unaccepted Trip can already be `OutForDelivery` (a real delivery
attempt happened) or `Cancelled` (Task 023-R1 Gate 4) by the time this sweep
runs. Releasing those first — with this service's generic custody reason —
strips their active TripOrder claim before `DeliveryAttemptClosureService`'s own
`whereNull('superseded_at')` query ever sees them, silently skipping its
outcome-specific classification (retryable vs. non-retryable failure reason) and
the Cancelled-with-custody review path. An already-`Delivered`/`FinalCash` order
was equally unprotected.

**Fix:** `closeUnacceptedTrip()` now batch-loads the Orders behind its
TripOrders and skips releasing any already `OutForDelivery`, `Cancelled`,
`Delivered`, or `FinalCash` — leaving genuinely un-dispatched orders
(`ReadyForDispatch` and earlier) as this service's real domain, exactly as its
own §E always intended. This makes the two sweeps commute: whichever listener
runs first, the same order ends up released by the same, correct owner.
Vehicle-custody handling, the Trip's own Cancelled transition, and
`DeliveryAttemptClosureService`'s classification logic are all untouched.

**Test added:** `tests/Feature/Logistics/WaveClosedListenerOrderIndependenceTest.php`
— builds one unaccepted Trip carrying an `OutForDelivery`+non-retryable-failure
order, a `Cancelled`-with-custody order, a `Delivered` order, and a
`ReadyForDispatch` order, then invokes the two listeners directly in both
orders (custody-then-delivery, delivery-then-custody) and asserts the identical
correct outcome for all four orders each time, bypassing `event()`'s fixed
registration order so the property holds even if the provider's order ever
changes.

## §2 — Distribution Window / Slot Scoping

**Not investigated — deliberately left untouched.** No record of "Task 033" or
its ~9 flagged failures exists anywhere I could find (no report file matching
`033` in this worktree, the sibling `_v1-validation-recovery-034` worktree's ~150
report files, or git history), and backend tests could not be executed at all in
this environment (see Environment Blockers). `DistributionWindowApiTest.php` and
`DistributionWindowResolutionAndCapacityTest.php` implement a genuinely intricate,
deliberate fail-closed window-resolution redesign (~20 scenarios) that cannot be
responsibly classified as "stale test" vs. "real defect" by static reading alone
— that classification requires actually running them against the specific
failures in question, which I do not have. Making speculative changes here
without knowing which of ~20 scenarios are the flagged ~9, and without being able
to prove any fix against a real failure, would risk exactly the kind of
unrequested architecture-touching the task explicitly warns against. **No files
touched for this section.**

## §3 — Trip Search Tenant Scoping

**Investigated, found already correct — focused tests added, no production
change.** Two plausible "Trip search" surfaces exist:

- `LogisticsTripController::index()` (`GET /api/logistics/distribution/trips`,
  the dispatcher search/list) — already forces
  `Trip::query()->where('company_id', $this->companyId())` before any request
  filter is applied (commented "Tenant fix (Part 21)", predating this task). The
  loop that re-applies `company_id` as an accepted filter can therefore only
  **AND** with the forced scope — narrowing to the same company (no-op) or
  zeroing the result set for a foreign company — never widening past the tenant.
  This route also carries no `permission:` middleware, so this scoping is the
  *only* guard against cross-company exposure.
- `DriverRuntimeController::trips()` (`GET /api/driver/trips`, the driver app's
  own list) — doubly scoped: `where('company_id', $companyId)` **and**
  `whereHas('driverVehicleAssignment', ... driver_id ...)`.

Both were already correct by direct reading. This matches the task's own
anticipated outcome ("if existing tenant scope is correct and tests are stale,
update focused tests only") — the two flagged cases read as a coverage gap, not
a code defect: the existing `DriverRbacTenancySecurityTest.php` suite already
covers single-trip-by-uuid access on every dispatcher sub-resource
(payments/settlement/financial-summary/stops) but had no case for the LIST
endpoint's cross-company exclusion.

**Tests added** (new §H in `DriverRbacTenancySecurityTest.php`, same file/style
as the existing tenancy suite):
- `test_h1_the_trip_search_list_excludes_another_companys_trips` — company A's
  dispatcher lists trips; asserts company A's own trip appears and company B's
  does not.
- `test_h2_a_foreign_company_id_filter_cannot_widen_the_trip_search_beyond_the_tenant`
  — company A's dispatcher requests `?company_id=<companyB>`; asserts an empty
  result, never company B's trip.

## §4 — Cash-Handover Order Advancement

**Investigated, found already correct — no change.** Read
`HandleTripCashHandoverConfirmed` (the `TripCashHandoverConfirmed` → Order
advancement bridge) in full: it scopes candidates to the event's `companyId`,
further scopes to `distribution_trip_orders` rows for the event's `tripId` with
`superseded_at IS NULL` (so an order released from this trip and delivered
elsewhere is never touched by this trip's handover), and gates on
`status === Delivered` before running `CompleteOrderWorkflow`. Each order's
advancement is wrapped in its own try/catch so one failure never blocks the
rest or loses the underlying (already-committed) cash fact. This is exactly
mirrored by three existing tests already in
`DeliveryAttemptClosureAndFinalCashTest.php`
(`test_cash_handover_confirmation_advances_delivered_orders_to_final_cash`,
`test_a_different_trips_order_is_not_advanced_by_this_trips_handover`,
`test_only_delivered_orders_are_candidates_for_final_cash`). Found no provable
break in the canonical event path, so per the task's own instruction, Final Cash
semantics were left unchanged. **No files touched for this section.**

## §5 — Finished Good Own Reservation Demand / Branch Assignment

**Investigated, found already mitigated at the flagged layer — no change.**
"Branch assignment" is `BranchAssignmentEngine` (and the still-live legacy
`WarehouseAssignmentEngine`), the order→warehouse routing system in
`Modules/Operations/Preparation/Application/Services/`. The flagged "release
failure" is a named, already-documented defect chain:

- **Root cause** (`docs/adr/ADR-043-bom-change-and-warehouse-reassignment-reservation-lifecycle.md`):
  `override()` rewrites `assigned_warehouse_id` with no release/re-reserve
  orchestration. The ratified fix (release-old → reassign → fresh-reserve) is
  **explicitly ratified as pending and out of scope, deferred until
  multi-warehouse activates** — i.e., already a deliberate, owner-approved
  deferral, not an oversight.
- **Concrete failure mode**, and its **fix**: `ReleaseOrderInventoryAction.php`
  (read directly, lines 84–178). Before the fix on file, releasing a reservation
  against `$order->assigned_warehouse_id` after an override targeted the *new*
  warehouse, where nothing was ever reserved — `ReleaseStockAction` would throw
  `NegativeInventoryException` or `InvalidInventoryMovementException`, making
  cancellation impossible and stranding the reservation in the *original*
  warehouse. `reservationWarehouseFor()` now reads the TRUE reservation
  warehouse from the canonical `StockLedgerEntry` (the same source
  `ReconcileOrderMaterialReservationsAction` already trusts) instead of the
  order's current (possibly-reassigned) warehouse, falling back to it only when
  no ledger row exists. Confirmed this correctly filters to `movement_type =
  Reservation` and takes the latest, so an interleaved release/re-reserve
  history cannot select the wrong warehouse.
- `tests/Feature/Inventory/ReservationWarehouseAuthorityTest.php` already
  asserts this exact contract for a finished good.

The narrow, flagged release failure is fixed and tested; the broader assignment-
layer gap is a deliberate, ratified, owner-approved deferral — reopening it
would violate "do not reopen the architecture broadly." **No files touched for
this section.**

## Changed Files

- [backend/Modules/Logistics/Distribution/Domain/Services/WaveClosureCustodyService.php](backend/Modules/Logistics/Distribution/Domain/Services/WaveClosureCustodyService.php) —
  §1 fix: skip releasing Orders already `OutForDelivery`/`Cancelled`/`Delivered`/`FinalCash`.
- [backend/tests/Feature/Logistics/WaveClosedListenerOrderIndependenceTest.php](backend/tests/Feature/Logistics/WaveClosedListenerOrderIndependenceTest.php) —
  new, §1's listener-order-independence proof.
- [backend/tests/Feature/Security/DriverRbacTenancySecurityTest.php](backend/tests/Feature/Security/DriverRbacTenancySecurityTest.php) —
  §3, new section H (2 tests) filling the trip-search tenant-isolation coverage gap.

No frontend file touched. No migration added or changed.

## Focused Tests

Written and believed correct by construction and by direct code tracing, but
**could not be confirmed passing by execution** — see Environment Blockers. All
three touched/new PHP files pass `php -l` (syntax), Pint (style), and PHPStan
(static analysis) cleanly.

## Validation

- **PHP syntax** (`php -l`) — clean on all 3 touched/new files.
- **Pint** — `{"tool":"pint","result":"passed"}` on all 3 files.
- **PHPStan** (`phpstan.neon.dist`) — `[OK] No errors` on all 3 files.
- **`git diff --check`** — clean, no whitespace errors.
- **Focused backend tests** — attempted repeatedly; environment-blocked (below).
  Not run: no backend migration/behavior outside the 3 files above was touched,
  so no other suite was in scope to run even had the environment cooperated.

## Environment Blockers

Backend test **execution** was blocked for the entire task, for reasons worth
recording precisely since real progress was made diagnosing (not fixing) them:

1. `.env.testing`'s `DB_PORT=3306` matches nothing listening on this host —
   `phpunit.xml` documents `DB_HOST`/`DB_PORT` as intentionally *unforced*
   defaults, meant to be overridden by a real shell/container environment
   variable (e.g. inside the `ecos-dev-app` container, where `127.0.0.1:3306`
   is actually the app's own MySQL). On the Windows host, the actual reachable
   instance is the long-lived `ecos-dev-mysql` container at `127.0.0.1:3316`
   (confirmed by matching its `MYSQL_PASSWORD` to `.env.testing`'s password).
   `DB_PORT=3316` as an exported shell variable (not an `.env.testing` edit,
   which has no effect against phpunit.xml's env precedence) correctly routes
   the connection.
2. Even correctly routed, `tests/TestCase.php::setUp()` unconditionally
   hardcodes `DB_DATABASE=ecos_dev_test` via `putenv()`/`$_ENV`/`$_SERVER` plus
   a reflection reset of Laravel's `Env` repository singleton — overriding both
   `.env.testing` and phpunit.xml's `force="true"` `DB_DATABASE=ecos_erp_test`.
   Its own comment states this is deliberately shaped for running *inside* the
   `ecos-dev` Docker stack. On the shared server, `ecos_dev_test` exists but
   carries only ~10 tables (missing even `companies`) — far short of the current
   schema — while the sibling `ecos_erp_test` exists with a fuller-but-still-
   `Pending`-migration set. Neither is a database this task should migrate: it
   is shared, another concurrent session's `026-I`/`020-I` integration work was
   actively landing on canonical `develop` during this same task (confirmed via
   `git log` on `ecos-develop` mid-task), and this project's own established
   precedent (Task 023-R1) is explicitly "do not repair test infrastructure."
3. Running two `RefreshDatabase` suites concurrently against this same shared,
   already-stale database (my own mistake, trying to save wall-clock time)
   caused a real migration race — one run hit `Table 'migrations' already
   exists` from the other's concurrent bootstrap, and a later serialized run
   then hit a foreign-key violation (`vehicle_assignments_loading_session_id_foreign`)
   after 12,575 seconds — almost certainly lock contention from that same
   collision, not a genuine test-fixture defect. That specific FK error should
   not be treated as a finding about anything in this task; it is a symptom of
   my own concurrent-execution mistake against fragile shared infrastructure,
   disclosed here rather than presented as a result.

**No destructive action was taken** — no `migrate:fresh`, no manual schema
edits, no docker volume changes. `ecos_dev_test`/`ecos_erp_test` were left as
found (now possibly mid-migration from the collision above); whoever owns the
shared `ecos-dev` stack should decide whether to reset them.

## Final Checkpoint

- **Branch:** `task/v1-remediation-operations-distribution-035c`
- **Base:** `7d122fdc6a9cd0aeae977df7c181ad8945dc4a23`
- **Working tree:** clean after this task's single successor commit.
- **Disk space at close:** 25GB free on `E:` — safe.

**V1 OPERATIONS / DISTRIBUTION REMEDIATION COMPLETE (PARTIAL — SEE STATUS) —
READY FOR INTEGRATION**

§2 was not addressed (no specifics available, environment blocked) and remains
open. §1 is a real, fixed, tested-by-construction defect. §3/§4/§5 are verified
correct/already-mitigated with no production change. Checkpoint only — no
integration, no DEV deploy, no push, no certification.

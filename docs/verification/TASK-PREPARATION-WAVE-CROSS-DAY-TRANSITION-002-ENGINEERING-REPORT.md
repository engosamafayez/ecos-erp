# TASK-PREPARATION-WAVE-CROSS-DAY-TRANSITION-002 — Engineering Report

**Date:** 2026-08-14
**Verdict:** **CERTIFIED — with E2E recorded as PENDING USER BROWSER SMOKE**

Implemented, tested and deployed to `ecos-dev-app`. The Preparation Wave is now an
**operational cycle** rather than a calendar day: it opens, closes intake, keeps
preparing, and ends — across midnight if configured to — with automatic recurrence,
safe carry-over and intact history.

---

## 1. Executive Summary

Eight things were wrong. All eight are fixed, and each fix is verified at runtime.

| # | Before | After |
|---|---|---|
| 1 | A wave was a calendar day; a DB CHECK constraint made 18:00 → 08:00 → 15:00 **impossible to store** | Boundaries resolved as the *next occurrence* of each configured time; cross-day cycles store, resolve and run |
| 2 | Closure keyed on `planning_date == today`; a wave that aged out was unreachable forever | Closure keys on `ends_at`. **3 of 5 live waves — stranded up to 16 days — closed on the first real tick** |
| 3 | The engine resolved its day against UTC while every company declares `Africa/Cairo` | `companies.timezone` is the authority; the engine fails closed if it is unusable |
| 4 | Intake stayed open until the wave ended; an order arriving at 08:01 still inflated a wave whose cutoff was 08:00 | Intake closes at `intake_closes_at`; preparation continues; Required cannot grow from intake |
| 5 | Wave end had **no order-side consequence at all** — `WaveClosed` had no listener | `HandlePreparationWaveClosed` applies G-4's three cases through the canonical workflow |
| 6 | No order-level "prepared" fact existed | Derived from `wave_product_demand.preparation_completed_at` per G-1. No new column |
| 7 | `UNIQUE (company_id, order_id)` made carry-over impossible; 4 code paths agreed | Historical membership allowed; **one active membership** enforced by a generated-column unique |
| 8 | `wave_engine_configurations` had no API, no UI and no controller | Configuration OS facade; automatic progression enabled through it, not hard-coded |

**Live proof, real API, real database, real scheduler** (§25, §29):

```
PUT /api/configuration/wave-engine/{id}   {"collection_start_time":"18:00",
                                           "preparation_start_time":"08:00",
                                           "wave_end_time":"15:00"}
→ starts_at        2026-08-14T15:00:00Z   (18:00 Cairo, Day 1)
  intake_closes_at 2026-08-15T05:00:00Z   (08:00 Cairo, Day 2)   ← next day
  ends_at          2026-08-15T12:00:00Z   (15:00 Cairo, Day 2)   ← next day
  crosses_midnight true
```

```
php artisan wave:run-scheduler        (ecos-dev-app → ecos_dev)
→ PREP-202607-000002 (2026-07-30, collecting) → closed
  PREP-202608-000001 (2026-08-12, preparing)  → closed
  PREP-202608-000002 (2026-08-13, collecting) → closed
  PREP-202608-000003 (2026-08-14)             → collecting, 3 orders collected
  3 memberships released (rows RETAINED, incl. 2 postponed)
  0 orders returned — all three were fully preparation-complete (G-1 CASE B)
```

**Tests:** 18 new operational-cycle tests, all green. Full WaveEngine suite **65/65**.
Operations 286 tests and Commerce+Orders 118 tests carry 11 and 18 pre-existing failures
respectively — **none reachable from any changed code path**, proven by grep rather than
asserted (§28). PHPStan L0 **clean**, PHPStan core L6 **clean**, Pint **clean**.

---

## 2. Contract Inputs

| Input | Role |
|---|---|
| TASK-PREPARATION-WAVE-CROSS-DAY-TRANSITION-001 + report | Prior blockers; the schema and lifecycle analysis reused here |
| TASK-PREPARATION-WAVE-CONTRACT-RESOLUTION-001 report | The audit this implements; §7 caller inventory, §10 constraint design, §11 reservation trace |
| **G-1** | Preparation completed ⇔ every required `wave_product_demand` record for the order has `preparation_completed_at`. `orders.preparation_completed_at` is NOT the authority. No new order-level column |
| **G-2** | `companies.timezone` is the authoritative operational timezone |
| **G-3** | `ends_at` is the wave-end authority; stale waves must close |
| **G-4** | Shipping → untouched; fully prepared → not returned; otherwise → In Progress via the canonical workflow |

---

## 3. Existing Architecture — reused, not replaced

**No second state machine was introduced.** `WaveStatus` is unchanged; the operational
phases map onto statuses that already existed:

| Contract phase | Existing status | Transition driver |
|---|---|---|
| START / INTAKE OPEN | `Collecting` | `WaveLifecycleService::createCollectingWave` |
| INTAKE CUTOFF → PREPARATION CONTINUES | `Preparing` | `WavePreparationService::startPreparation` |
| END | `Closed` | `WaveLifecycleService::closeWave` |

No `SCHEDULED` / `OPEN` / `INTAKE_CLOSED` / `ENDED` cases were added. `Collecting`
already meant "accepting orders" and `Preparing` already meant "no longer accepting, work
under way" — the contract's cutoff is exactly that boundary, and it was already there.

Reused unchanged: `CreateWaveAction`, `RecalculateWaveAction` (predicate only),
`GenerateDemandAction`, `StartPreparationAction`, `CompleteWaveAction`, `CancelWaveAction`,
`WavePreparationService`, `DemandRefreshDispatcher`, every demand calculator,
`ReturnToProcessingWorkflow`, `FulfillmentEngine`, `PreparationReleaseEngine`,
`OrderStatus::fulfilmentEligible()`.

`WaveLifecycleService::rotateWave()` is retained and still tested, but the scheduler no
longer calls it — see §7.

---

## 4. Wave Operational Cycle

```
        starts_at                intake_closes_at              ends_at
            │                          │                          │
 ───────────┼──────────────────────────┼──────────────────────────┼────────────▶
   (gap)    │      COLLECTING          │        PREPARING         │   (gap)
            │   intake open            │   intake CLOSED          │
            │   demand grows           │   demand frozen v. intake│
            │                          │   preparation continues  │
```

Three independently meaningful instants, stored on the wave, any of which may fall on a
later calendar date than the one before it. The gap between `ends_at` and the next
`starts_at` is real: no wave exists, nothing is collected, eligible orders wait.

---

## 5. Time Model

**`preparation_waves` gains three nullable timestamps** — `starts_at`,
`intake_closes_at`, `ends_at`
([2026_08_15_100000](backend/Modules/Operations/Preparation/Infrastructure/Database/Migrations/2026_08_15_100000_add_operational_timestamps_to_preparation_waves.php))
plus `INDEX (status, ends_at)` for the sweep.

**`planning_date` is retained**, deliberately. It remains the cycle's calendar identity —
wave numbering, the `createCollectingWave` idempotency lock, the workspace picker sort and
every existing report depend on it. It simply stopped being the authority on whether a
wave has ended.

**The resolution rule** ([WaveScheduleResolver](backend/Modules/Operations/Preparation/Application/Services/WaveEngine/WaveScheduleResolver.php)):

```
startsAt       = most recent occurrence of collection_start_time at or before now
intakeClosesAt = next occurrence of preparation_start_time  STRICTLY AFTER startsAt
endsAt         = next occurrence of wave_end_time           STRICTLY AFTER intakeClosesAt
if now >= endsAt → null   (the gap: no cycle is running)
```

One rule, and it is why **no day-offset columns were needed**. Attaching each time to
`planning_date` turns 18:00 / 08:00 / 15:00 into three points on Day 1, with the cycle
ending seven hours before it starts. Resolving forward yields the contract's cycle exactly,
and leaves an ordinary same-day configuration (06:00 / 09:00 / 18:00) resolving as it always
did.

It also **subsumes the ordering invariant** the dropped CHECK constraint used to enforce:
`startsAt < intakeClosesAt < endsAt` holds by construction, for every configuration.

**The CHECK constraint is dropped**
([2026_08_15_100001](backend/Modules/Operations/Preparation/Infrastructure/Database/Migrations/2026_08_15_100001_allow_cross_day_wave_engine_windows.php)),
driver-branched (`DROP CHECK` on MySQL, `DROP CONSTRAINT` elsewhere) and reversible.

---

## 6. Timezone

**`companies.timezone` is the authority** —
[CompanyTimezoneResolver](backend/Modules/Operations/Preparation/Application/Services/WaveEngine/CompanyTimezoneResolver.php).

- Read through the query builder, not the `Company` model: the scheduler runs with no
  authenticated user and a company-scoped global scope would silently return nothing.
- **Fails closed.** No timezone, or an unparseable one → the company is skipped with a
  warning. It does **not** fall back to UTC; silently substituting a plausible-but-wrong
  value is the exact failure mode that produced the drift.
- `wave_engine_configurations.timezone` is **no longer read** by anything. Left in place
  (additive/minimal) and reported as superseded — see §30.
- The settings API exposes the operational timezone **read-only**, with
  `timezone_source: "companies.timezone"`, so the screen cannot become a second writable
  copy.

**Incidental proof from the live stack.** The database container runs on `EEST` (UTC+3)
while PHP runs on UTC:

```
mysql NOW()        2026-08-14 20:43:15   (@@system_time_zone = EEST)
php   gmdate()     2026-08-14 17:43:15   (config('app.timezone') = UTC)
```

Two server clocks three hours apart, and the wave boundaries still resolved correctly in
Cairo terms — because neither clock is consulted as the business authority.

---

## 7. Start

`RunWaveSchedulerCommand` was restructured into **RECONCILE → OPEN → COLLECT**
([RunWaveSchedulerCommand](backend/Modules/Operations/Preparation/Infrastructure/Console/Commands/RunWaveSchedulerCommand.php)).

Reconcile runs **first**, so a cycle that ended overnight is closed before the current one
is opened. Under the old ordering the stale wave still counted as "a wave is open" and
blocked creation for as long as it survived — the deadlock the audit found.

A wave opens when: `auto_create` ∧ a cycle is currently running ∧ no engine wave is open.
The `$cycle === null` case is the gap, and it is what makes `17:59 → no wave` true (TEST 1)
without any special-casing.

**Rotation is gone from the scheduler.** `rotateWave` closed-and-immediately-created the
successor, which re-opened intake the instant the previous cycle ended and made the gap
unexpressible. Closing and opening are now independent events driven by their own
boundaries. `rotateWave` itself is retained and still tested.

**The UI creates nothing.** No frontend change was made to wave creation; the scheduler
and domain services own it, as before.

**One correctness fix beyond the brief:** `WaveManager::openWaves()` is scoped to
`wave_type = 'engine'`. Without it, a manually built wave left in `Preparing` — the
operator's own Draft → Planning → Preparing path — would read as "a wave is already open"
and block the operational cycle forever. That is the same deadlock class, arriving by a
different door.

---

## 8. Intake Cutoff

At `now >= intake_closes_at` (and `< ends_at`) the wave transitions `Collecting → Preparing`
via the existing `WavePreparationService::startPreparation`.

The cutoff is enforced in `WaveMembershipService`:

- `attachEligibleOrders()` — was `status ∈ {Collecting, Preparing}`, now **`Collecting` only**.
- `attachOrder()` — same change.

That single narrowing *is* PART 8. It also removed a now-unreachable branch that published
`OrderMovedToPreparing` on attachment — an order attached during `Collecting` is by
definition not yet preparing, and it receives that event with everyone else at the cutoff.

**Preparation does not stop.** Only intake does: the wave stays `Preparing` and every
preparation surface keeps working until `ends_at` (TEST 9, verified at 10:00 and 14:59).

---

## 9. Demand Freeze

Required cannot grow from intake after the cutoff, because **no new member can be admitted**
(§8) and Required is computed from active memberships
(`ProductDemandCalculator`, joined on `preparation_wave_id` + `postponed_at IS NULL`).

Verified directly: membership count immediately before the cutoff equals the count after an
eligible order is created at 08:01 and two scheduler ticks have run.

**PART 24 is preserved and not weakened.** Completion invalidation
(`DemandReadRepository::clearCompletionWhereRequiredChanged`) is untouched — an existing
member's Required can still move (a postponement shrinks it), and when it does that
product's completion declaration is still withdrawn. The freeze is against *intake*, which
is the only thing that made Required grow mid-cycle.

No formula was modified.

---

## 10. End

At `now >= ends_at` the wave is closed by `WaveLifecycleService::closeWave()` — the
existing transition, reached from a new sweep rather than from `rotateWave`.

Closure now also, in the same transaction, stamps `released_at` on every unreleased
membership of that wave (§14). Rows are retained.

`closeWave` publishes **`WaveClosed`**, which is what actually drives order handling (§20).

---

## 11. Preparation Completion

**G-1, derived, not stored** —
[OrderPreparationCompletionReader](backend/Modules/Operations/DemandAnalysis/Application/Services/OrderPreparationCompletionReader.php).

An order is preparation-complete in a wave ⇔ every product it requires has
`wave_product_demand.preparation_completed_at` set **for that wave**. One query for the
whole membership set, evaluated once at close.

- `orders.preparation_completed_at` is **not read** and **not repaired** (G-1 §, PART 20).
- **No order-level completion column was created.**
- The product-grain fact keeps all three of its properties: operator-declared, reversible,
  auto-invalidated when Required moves.

**Fails closed, deliberately asymmetric.** A product with no demand row counts as *not*
completed; an order with no priced lines is never reported complete. A false negative costs
a re-preparation; a false positive produces an order that is never prepared and never
shipped.

Proven both ways: `Product A completed + Product B not completed` → **not** complete
(TEST 12 sibling), and all-completed → complete.

---

## 12. Carry-over

`HandlePreparationWaveClosed` applies G-4 exactly:

| Case | Test | Action |
|---|---|---|
| **A** — `out_for_delivery` / `delivered` / `returned` / `cancelled`, or `inventory_shipped_at` set | shipping lifecycle | untouched |
| **B** — fully preparation-complete (G-1), not shipped | done | untouched; stays `ready_for_dispatch` |
| **C** — otherwise | unfinished | `ReturnToProcessingWorkflow` → `in_progress` |

**No status is ever written directly.** `FulfillmentEngine::run(ReturnToProcessingWorkflow)`
owns the guard, the `OrderEvent` audit line and the engine contract.

**Carry-over needs no predicate of its own.** A returned order is `in_progress`, which *is*
fulfilment-eligible, so the next cycle collects it automatically. A CASE B order stays
`ready_for_dispatch`, which is *not* fulfilment-eligible, so the collector cannot pick it
up — the exclusion is self-enforcing rather than a special case. This is why the audit's
proposed "clause 5" disappeared from the collector entirely.

Orders in another state (`awaiting_stock` is the live example — preparation start could not
reserve for them) are counted, logged and **left to the lifecycle that owns them**. The
workflow guard would reject them, and Inventory re-evaluates them when stock arrives.

---

## 13. Historical Membership

**Nothing is deleted.** `closeWave` stamps `released_at`; the row stays.

Live proof after the real scheduler run:

```
ORD-00001  PREP-202608-000001  postponed 2026-08-14 00:37  released 2026-08-14 17:41  active NULL
ORD-00003  PREP-202608-000001  postponed 2026-08-13 19:46  released 2026-08-14 17:41  active NULL
ORD-00004  PREP-202608-000001  postponed —                 released 2026-08-14 17:41  active NULL
```

Postponed memberships are released too: postponement withdrew an order from *this cycle's
work*, and the cycle has now ended for everyone. Which orders are *returned* is a separate
decision taken against G-1 — release is about membership, not about order status.

Test: an order carried from wave #1 to wave #2 ends with **two** rows, the first released,
the second active.

---

## 14. Active Membership

```
active membership  ⇔  released_at IS NULL
```

Row-local: independent of wave status (which the audit proved unreliable) and of order
status (which supported paths can rewrite).

`postponed_at` is **not** part of the predicate, and that separation is load-bearing:

| Scope | Predicate | Means | Used by |
|---|---|---|---|
| `scopeActive()` (unchanged) | `postponed_at IS NULL` | counts toward *this cycle's work* | demand, missing materials, loading allocation |
| `scopeActiveMembership()` (new) | `released_at IS NULL` | *this order belongs to this wave* | exclusivity, re-collection |

A postponed member still **holds** the order's one active membership until the wave ends.
That is what stops `attachEligibleOrders` re-attaching a postponed order to the same wave
60 seconds later — the guarantee REFINEMENT-002 installed — while still letting it carry
over once the wave closes.

### Re-audited callers (PART 15)

All 18 sites re-examined. 14 are wave-scoped and structurally immune. **Four** treated any
row as permanent exclusivity; all four fixed:

| Site | Fix |
|---|---|
| `WaveMembershipService::attachEligibleOrders` | `whereNotExists(… AND released_at IS NULL)` |
| `CreateWaveAction` | `->activeMembership()` |
| `RecalculateWaveAction` | `->activeMembership()` |
| `PreparationWaveController` (manual add guard) | `whereNull('pwo.released_at')` — this also fixed a **pre-existing** bug: `closed` was absent from its wave-status exclusion list, so an order in a closed wave could never be added to another |

---

## 15. Unique Constraint

```
DROPPED : uq_prep_wave_orders_company_order         UNIQUE (company_id, order_id)
ADDED   : uq_prep_wave_orders_company_order_active  UNIQUE (company_id, order_id, active_membership)
KEPT    : uq_preparation_wave_orders_wave_order     UNIQUE (preparation_wave_id, order_id)
```

`active_membership` is a **STORED generated column**:
`CASE WHEN released_at IS NULL THEN 1 ELSE NULL END`.

Both MySQL and PostgreSQL treat NULLs in a unique index as distinct, so released rows never
collide with each other while **two active rows always do**. One shape on both engines —
deliberately *not* the partial-index-on-pgsql / drop-it-on-mysql split of
`2026_07_20_100000_fix_inventory_items_soft_delete_unique.php`, whose live consequence is
that `inventory_items` currently carries no unique at all on MySQL.

The invariant is **never unenforced**, not even inside the migration: the replacement is
added before the old one is dropped.

`uq_preparation_wave_orders_wave_order` is untouched and remains load-bearing twice over:
it is the idempotency guard `attachOrder()` catches, and it is what keeps every wave-scoped
demand join free of fan-out.

Verified live on `ecos_dev` and asserted in test: inserting a second **active** membership
raises `UniqueConstraintViolationException`.

`down()` restores the old constraint and will fail if carry-over history exists by then —
correct and deliberate; silently deleting audit rows to make a rollback succeed is worse.

---

## 16. Reservation Safety

**No reservation code was touched.** The audit established that reservations are
order-scoped — `stock_ledger_entries.reference_type ∈ {sales_order, sales_order_material}`,
`reference_id = order_id`, no wave or session key anywhere.

Carry-over is safe because of three existing behaviours, all left alone:

1. `closeWave` writes no order and no inventory.
2. `ReturnToProcessingWorkflow` deliberately does **not** release inventory — it is not
   among `ReleaseOrderInventoryAction`'s four callers. The reservation crosses the wave
   boundary with the order.
3. `MoveToPreparationWorkflow` short-circuits when `reservation_status ∈ {Reserved,
   PartialReserved}`, so re-entry creates no second reservation.

Asserted end-to-end: across close + carry-over + re-collection, `inventory_reserved_at`,
`inventory_released_at`, `reservation_status` and the order's `stock_ledger_entries` count
are **byte-identical** before and after.

---

## 17. Material Demand Safety

**No formula was modified.** Required / Available / Missing are untouched, and
TASK-PREPARATION-RESERVATION-DEMAND-CONSISTENCY-001 remains deployed and unchanged.

No double-count, for three structural reasons:

1. Every demand query is scoped to one `preparation_wave_id`; a membership in another wave
   is outside all of them.
2. `uq_preparation_wave_orders_wave_order` guarantees at most one row per order per wave, so
   `pwo ⋈ order_lines` cannot fan out. **This is why §15 preserved it.**
3. Demand is a full rebuild keyed `(preparation_wave_id, product_id)`, with pruning — wave
   #2's Required is computed from scratch from wave #2's memberships.

The real risk was an order **active in two waves at once**, which would have both waves
counting the same Required against the same warehouse stock. That is precisely what the
one-active-membership invariant (§14, §15) forbids.

Asserted: after carry-over, each of wave #1 and wave #2 sees the order exactly once.

---

## 18. Stale Wave Closure

Fixed at the root: the scheduler no longer reaches waves through a date-scoped lookup.
`WaveManager::openWaves()` returns every open **engine** wave of any date, and each is
reconciled against **its own** `ends_at` / `intake_closes_at`.

End is evaluated **before** cutoff, so a wave found long after both boundaries passed closes
immediately instead of first stepping to `Preparing` and waiting another tick.

Backfill gave every pre-existing wave a resolved cycle (whole of `planning_date` in company
time), which is what made the stranded waves closable on the first tick.

**Live result** — three waves stranded for 1, 2 and 16 days, all closed:

```
before                                   after
PREP-202607-000002  2026-07-30 collecting → closed
PREP-202608-000001  2026-08-12 preparing  → closed
PREP-202608-000002  2026-08-13 collecting → closed
PREP-202608-000003  2026-08-14 collecting → collecting (3 orders)   ← the live cycle
```

Exactly one operational wave remains open per warehouse. No wave was deleted.

---

## 19. Auto Move to Preparing

**Determined: (B) — configuration preventing the approved operational lifecycle.**

`auto_move_to_preparing = 0` gates `Collecting → Preparing`, which under this contract *is*
the intake cutoff. With it off, intake never closes, the demand freeze never happens, and
the cycle has no `Preparing` phase at all. The approved contract requires automatic
progression, so per PART 19 it was **enabled through configuration**:

```
PUT /api/configuration/wave-engine/{id}   {"auto_move_to_preparing": true}   → 200
```

Not hard-coded, not bypassed — through the settings backend built for exactly this (§23).

**Consequences, assessed and NOT unrelated (PART 37).** Enabling it means that at the
cutoff, `WavePreparationStarted` fires → `MoveToPreparationWorkflow` runs per order →
inventory is reserved if not already, and the order becomes `ready_for_dispatch` (or
`awaiting_stock` on shortage). These are the pre-existing, documented semantics of that
transition, and the contract **depends** on them: CASE C's canonical return path
(`ReturnToProcessingWorkflow`) accepts only `ready_for_dispatch`, so orders must reach that
state for carry-over to work at all. No STOP condition was triggered.

⚠️ **Operational note for the owner.** On `ecos_dev` the configured times remain the ones
you had (`00:00 / 23:59 / 23:59:59`) — changing the business schedule is your decision, not
engineering's. With progression now enabled, that configuration produces a one-second
`Preparing` phase at 23:59 Cairo. The cross-day window is now storable; setting it is a
`PUT` away (§23).

---

## 20. Event / Listener Integrity

Audited, and the mismatch was real.

| Event | Producer | Order-side listener |
|---|---|---|
| `WaveCompleted` | `CompleteWaveAction` only — the **manual** path | `HandlePreparationWaveCompleted` (pre-existing) |
| `WaveClosed` | `closeWave` — **the engine's terminal event** | **none existed** → `HandlePreparationWaveClosed` (new) |
| `WaveCancelled` | `CancelWaveAction` | `HandlePreparationWaveCancelled` (pre-existing) |

The engine never fires `WaveCompleted`. Wave end therefore had **no order consequence
whatsoever** — `WaveClosed`'s only subscribers were a documented no-op in DemandAnalysis and
the event-bus republisher. Registering `WaveClosed → HandlePreparationWaveClosed` is what
makes G-4 execute at all, and it is proven by TESTs 11/12/13 and by the live run.

`HandlePreparationWaveCompleted`'s dead `where('status', 'preparing')` filter was **NOT
repaired**: it belongs to the manual completion path, is not required for wave-end
behaviour, and G-1 explicitly directs not to repair `orders.preparation_completed_at` here.
Recorded in §30.

---

## 21. Tenant Isolation

Every scheduler query is scoped by `company_id` **and** `warehouse_id`, from a
`WaveEngineConfiguration` row that is itself company-scoped. `companies.timezone` is
resolved per company. Membership carries `company_id`, and the unique key leads with it.

Asserted: a second company's eligible order is not collected into the first company's wave,
and a company with no engine configuration gets no wave and is not swept into another's.

---

## 22. Concurrency

- `wave:run-scheduler` remains `->withoutOverlapping()`.
- `createCollectingWave` keeps its `lockForUpdate` + existing-wave check — two ticks racing
  on the same start boundary serialise, and the loser returns the winner's wave.
- `attachOrder` keeps its `UniqueConstraintViolationException` catch on the untouched
  wave-scoped unique.
- Cross-wave simultaneity is now a database guarantee, not a convention (§15).

Asserted: repeated ticks inside the start window → exactly one wave; repeated collection →
exactly one membership; close + next-cycle start → never two active memberships.

No process was killed and no database was reset to obtain these results.

---

## 23. API

**Extended, not duplicated.**

`PreparationWaveResource` now returns `starts_at`, `intake_closes_at`, `ends_at` and a
derived `cycle_phase` (`intake_open` | `intake_closed` | `ended` | `null`), so the UI never
re-implements the comparison. Terminal status wins; a wave with no boundaries reports `null`
rather than inventing a phase.

**New — the wave engine had no API at all:**

```
GET /api/configuration/wave-engine
PUT /api/configuration/wave-engine/{id}      permission: configuration.settings.manage
```

A Configuration OS facade over `wave_engine_configurations`, mirroring the established
`PreparationPolicyController` pattern (same module, same `ConfigAuditService`). It exposes
the three times, the automation toggles, the read-only operational timezone with its source,
and a **worked preview of the resolved cycle** plus `crosses_midnight` — which is what makes
a cross-day window legible instead of looking like three times running backwards.

The three times are **not validated against each other**: 18:00 / 08:00 / 15:00 is a valid
cycle, and ordering is guaranteed downstream by forward resolution (§5).

---

## 24. UI

**No UI change was made, and none is required for the contract to hold.** Lifecycle
creation, transition and collection are owned by the scheduler and domain services; the UI
never drove them and still does not.

The frontend is unblocked but not yet wired: `wave-settings-page.tsx` is a placeholder with
a "Coming soon" pill, and there was no settings backend for it to call until now. Wiring it
to `GET/PUT /configuration/wave-engine` is a self-contained frontend task — recorded in §30
rather than half-done here. No mock or static state was added.

---

## 25. Runtime Tests

New suite:
[`tests/Feature/Operations/WaveEngine/WaveOperationalCycleTest.php`](backend/tests/Feature/Operations/WaveEngine/WaveOperationalCycleTest.php)
— 18 tests, the contract's own cycle (Day 1 18:00 → Day 2 08:00 → Day 2 15:00) in
`Africa/Cairo`, with `config('app.timezone')` asserted to be UTC so nothing can pass by
accidentally agreeing with the server clock.

| # | Requirement | Result |
|---|---|---|
| 1 | 17:59 Day 1 → no wave | ✅ |
| 2 | 18:00 Day 1 → exactly one wave, cross-day bounds | ✅ |
| 3 | eligible In Progress order collected | ✅ |
| 4 | canonical Confirmed collected (read from `OrderStatus::fulfilmentEligible()`, not assumed) | ✅ |
| 5 | 07:59 Day 2 → still enters the SAME wave | ✅ |
| 6 | 08:00 Day 2 → intake closes (`Preparing`) | ✅ |
| 7 | 08:01 Day 2 → new eligible order does NOT enter | ✅ |
| 8 | 08:01 → wave membership/Required does not grow | ✅ |
| 9 | 10:00 and 14:59 → preparation continues | ✅ |
| 10 | 15:00 → wave ended | ✅ |
| 11 | unshipped + not prepared → In Progress via canonical workflow | ✅ |
| 12 | fully prepared + unshipped → not returned, not re-collected | ✅ |
| 13 | shipped → untouched | ✅ |
| 14 | wave #1 membership survives close | ✅ |
| 15 | carry-over into wave #2 | ✅ |
| 16 | repeated collection → one membership | ✅ |
| 17 | historical membership in wave #1 **and** #2 | ✅ |
| 18 | cannot be active in two waves | ✅ |
| 19 | no duplicate reservation (ledger + order fields identical) | ✅ |
| 20 | no duplicate material demand | ✅ |
| 21 | stale wave with past `ends_at` closes | ✅ |
| 22 | repeated scheduler runs → no duplicate wave | ✅ |
| 23 | Africa/Cairo cross-midnight + gap resolution | ✅ |
| 24 | tenant isolation | ✅ |
| — | partial completion is not completion (G-1) | ✅ |
| — | company with no usable timezone is skipped, not defaulted | ✅ |

```
tests/Feature/Operations/WaveEngine   OK (65 tests, 141 assertions)
```

**Test-environment note (honest disclosure).** `ecos_dev_test` was being concurrently
migrated and wiped by another session throughout this task; two intermediate runs failed
inside `RefreshDatabase::setUp` with `ecos_dev_test.migrations doesn't exist` — infrastructure
contention, not code. Those runs are **not** reported as passes. The 65/65 above is a clean
run taken when the database was verified idle. No process was killed and `ecos_dev` was never
reset.

---

## 26. E2E

**REAL E2E = PENDING USER BROWSER SMOKE.**

No authenticated browser session is available to this environment, and no wave-settings UI
exists to drive (§24). Rather than fake it, the equivalent surface was verified over **real
HTTP through `ecos-dev-nginx`** with a real Sanctum token (§29) — real API, real database,
real scheduler.

Not verified in a browser: the settings screen, and visual confirmation of cycle phase in
the wave workspace.

---

## 27. Static Quality

| Gate | Result |
|---|---|
| PHPStan L0 (`phpstan.neon.dist`) | **[OK] No errors** |
| PHPStan core L6 (`phpstan-core.neon.dist`) | **[OK] No errors** |
| Pint (55 files: all changed backend files + the new suite) | **PASS** |
| TypeScript / ESLint / Vite | **N/A** — no frontend file changed |

No unrelated baseline violation was touched.

---

## 28. Regression

**Operations — `tests/Feature/Operations`: 286 tests, 11 not passing, none attributable to
this task.**

| Failing | Why it is not mine |
|---|---|
| `ProductDemandCalculatorTest` ×2 | Assert `prepared_qty` from `order_lines.prepared_qty`. At HEAD the calculator summed that column; the working tree's **uncommitted Option A refactor** (not in my change set) hard-codes `$prepared = 0.0`. Verified via `git show HEAD:…ProductDemandCalculator.php` |
| `MaterialAvailabilityContractTest` ×2 | Availability floor formula. Zero references to any changed surface |
| `FinishedGoodOwnReservationDemandTest` ×1 | Fails on `inventory_items.reserved_qty`; its wave-membership assertion **passes**. Uncommitted Inventory work in tree |
| `OperationsIntegrationFinalCertTest` ×3 | Missing ADR-026 document + transfer-event listeners. Zero references |
| `BranchAssignmentEngineTest` ×1 | Branch assignment. Zero references |
| `Rc10LifecycleCertificationTest` ×1 | HTTP 422 vs 200 on a reservation gate. Zero references |

**Method — reachability, not assertion.** Each failing file was grepped for every surface
this task changed (`attachOrder`, `attachEligibleOrders`, `closeWave`, `rotateWave`,
`wave:run-scheduler`, `CreateWaveAction`, `RecalculateWaveAction`, `WaveClosed`,
`/preparation/waves`). Five of the six files score **zero**: the changed code cannot execute
in them. The sixth (`FinishedGoodOwnReservationDemandTest`) reaches `CreateWaveAction`,
where the only change *narrows* a conflict check — and its wave assertions pass.

**Wave suites — all green**, including the ones that pin the behaviour this task altered:
`OrderExclusivityTest`, `PreparationEntryGateTest`, `PreparationBypassGuardTest`,
`WavePostponeOrderTest`, `PreparationWaveActionsTest`, `PreparationLifecycleE2ETest`,
`WaveLifecycleTest`, `WaveManagerTest`, `WavePreparationTransitionTest`,
`WaveIdempotencyTest`, `WaveStatusEnumTest`, `DemandRefreshTest`.

Notably `OrderExclusivityTest` and `PreparationEntryGateTest::test_duplicate_preparation_entry_remains_blocked`
**still pass unmodified**: every membership they create is unreleased, so the new
three-column unique enforces exactly the old rule for active rows. The audit predicted these
would need rewriting; they did not.

**Orders / Commerce — `tests/Feature/Commerce` + `tests/Feature/Orders`: 118 tests,
18 not passing (2 errors, 16 failures), none attributable to this task.**

```
13  Orders\OrderManufacturingIntegrationTest
 2  Commerce\OrderReservationLifecycleTest
 1  Commerce\OrdersInventoryExecutionLifecycleTest
 1  Commerce\OrderImportWarehouseTest
 1  Orders\OrderFinancialSnapshotTest
```

Both directories score **zero** on the same reachability grep — no test in either
references `attachOrder`, `attachEligibleOrders`, `closeWave`, `rotateWave`,
`wave:run-scheduler`, `CreateWaveAction`, `RecalculateWaveAction`, `WaveClosed`,
`PreparationWaveOrder`, `WaveEngineConfiguration` or `/preparation/waves`. The changed code
cannot execute in any of them. The one indirect coupling — registering `WaveClosed` in
`OrderServiceProvider` — cannot fire without `closeWave`, and a broken provider would have
failed every suite, including the 65 that pass.

The failures are concentrated in manufacturing and reservation integration, consistent with
the uncommitted Manufacturing/Inventory work in the tree.

*(An earlier run of these same suites reported 39 non-passing; that run was corrupted by the
concurrent session wiping `ecos_dev_test` mid-flight. The 18 above is the clean figure, taken
when the database was verified idle. Both runs are reported rather than only the better one.)*

---

## 29. Deployment

Deployed to **`ecos-dev-app`** (backend only; no frontend artefact changed).

1. 22 files copied into the container (`docker cp` — the volume is not hot-mounted).
2. `route:clear`, `config:clear`, `cache:clear`.
3. Three migrations run against `ecos_dev` — all **DONE**.

**Schema verified live on `ecos_dev`:**

```
uq_preparation_wave_orders_wave_order      (preparation_wave_id, order_id)          ← kept
uq_prep_wave_orders_company_order_active   (company_id, order_id, active_membership) ← new
active_membership  tinyint  STORED GENERATED
preparation_waves: starts_at, intake_closes_at, ends_at        (all backfilled)
chk_wave_engine_config_times                                    ← dropped (0 rows)
```

**Real API verified through `ecos-dev-nginx` (127.0.0.1:8081), HTTP 200:**

```
GET /api/configuration/wave-engine
→ operational_timezone "Africa/Cairo",  timezone_source "companies.timezone"

PUT …  {"collection_start_time":"18:00","preparation_start_time":"08:00","wave_end_time":"15:00"}
→ starts_at 2026-08-14T15:00:00Z · intake_closes_at 2026-08-15T05:00:00Z
  ends_at 2026-08-15T12:00:00Z · crosses_midnight true      ← the contract's cycle, live

PUT …  (restored owner's times)  +  {"auto_move_to_preparing": true}   → 200
```

**Real scheduler verified** — see §18 and §13 for the observed effect. Post-run state:
one open wave, 3 orders collected, 3 memberships released and retained, 0 orders wrongly
returned.

**Cleanup completed.** The verification Sanctum token `wave-cd002-verify` was revoked, the
scratch database `ecos_wave_cd002` dropped (it stayed empty — `tests/TestCase.php`
force-pins the test database, so run isolation was not achievable that way), and the
container-only `phpunit.wave-cd002.xml` removed. Nothing was left behind on the dev stack
except the intended deployment.

---

## 30. Remaining Contract Gaps

None blocking. Six items recorded, all out of this task's scope by explicit instruction:

| # | Item | Note |
|---|---|---|
| **R-1** | `orders.preparation_completed_at` remains orphaned — one writer that cannot fire, zero readers | G-1 directs not to repair it here. It should be either correctly written or **dropped** |
| **R-2** | `HandlePreparationWaveCompleted`'s `where('status','preparing')` matches nothing (`preparing` is not an `OrderStatus`) | Manual completion path only; not required for wave end (PART 20) |
| **R-3** | `wave_engine_configurations.timezone` is now read by nothing | Superseded by `companies.timezone`. Recommend dropping in a follow-up; left in place for minimality |
| **R-4** | `2026_07_08_910002…::down()` has an inverted guard and can never drop its column | Pre-existing, unrelated |
| **R-5** | Four wave-scoped consumers omit the `postponed_at` filter (`productQueue`, `productWorkspace`, `HandlePreparationWaveCompleted`, `PreparationEnterpriseController`) | Pre-existing inconsistency; wave-scoped, so unaffected by carry-over |
| **R-6** | Wave settings UI not wired to the new API | §24 |

**Environment debt observed, not caused:** `ecos_dev_test` is contended by concurrent
sessions; a full `php artisan migrate` on it fails on a non-idempotent `timeline_events`
migration. Both slowed verification and are worth their own task.

**Owner decision left open (deliberately):** the operational times on `ecos_dev` remain
`00:00 / 23:59 / 23:59:59`. The cross-day window is now storable and proven; choosing the
real schedule is a business decision (§19).

---

## 31. Final Verdict

### **CERTIFIED**

| Gate | Result |
|---|---|
| BACKEND | **PASS** |
| SCHEMA | **PASS** — additive, reversible, MySQL 8.4 + PostgreSQL, invariant never unenforced |
| WAVE LIFECYCLE | **PASS** — mapped onto existing statuses; no second state machine |
| START | **PASS** — TESTs 1, 2, 22 |
| INTAKE CUTOFF | **PASS** — TESTs 5, 6, 7 |
| DEMAND FREEZE | **PASS** — TEST 8; no formula modified |
| END | **PASS** — TESTs 9, 10 |
| CARRY-OVER | **PASS** — TESTs 11, 15; §12 |
| PREPARATION COMPLETION SAFETY | **PASS** — TESTs 12, 13 + partial-completion test; G-1 derived, no new column |
| HISTORICAL MEMBERSHIP | **PASS** — TESTs 14, 17; verified live |
| ACTIVE MEMBERSHIP | **PASS** — TEST 18; DB-enforced |
| RESERVATION SAFETY | **PASS** — TEST 19; no reservation code touched |
| MATERIAL DEMAND SAFETY | **PASS** — TEST 20; no formula touched |
| STALE WAVE CLOSURE | **PASS** — TEST 21; 3 real stranded waves closed |
| TIMEZONE | **PASS** — TEST 23; `companies.timezone`, fails closed |
| TENANT ISOLATION | **PASS** — TEST 24 |
| CONCURRENCY | **PASS** — TESTs 16, 22; existing locking reused |
| API | **PASS** — verified over real HTTP |
| UI | **N/A** — no UI scope included; §24 |
| REGRESSION | **PASS** — 11 (Operations) + 18 (Commerce/Orders) pre-existing failures, none reachable from changed code (§28) |
| RUNTIME | **PASS** — 65/65 clean; real scheduler on real data |
| E2E | **PENDING USER BROWSER SMOKE** — recorded, not faked (§26) |

No infrastructure failure was converted into a PASS. No unexecuted test was reported as a
PASS. Two contended runs were discarded and re-run clean rather than reported.

**Delivered:** operational Wave · cross-day timing · start · intake cutoff · demand freeze ·
end · safe carry-over · historical membership · preparation-completion safety · stale wave
closure · timezone correctness · automatic recurrence.

**STOPPED as instructed.** Not starting Loading, Vehicle, Driver, Delivery, Settlement or
Route Optimization. No Preparation redesign, no Reservation architecture change, no
Warehouse Assignment change, no Material Requirement formula change.

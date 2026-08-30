# TASK-PREPARATION-WAVE-CROSS-DAY-TRANSITION-001 — Engineering Report

**Date:** 2026-08-14
**Verdict:** **NOT CERTIFIED — BLOCKED BEFORE IMPLEMENTATION**
**Production code changed:** none. **Migrations written:** none. **Data modified:** none.

Stopped under PART 10 (migration required → STOP and report the design) and PART 24
STOP conditions 3, 6 and 7. Two further blockers were found that are larger than the
one the task was raised for; both are business-contract decisions, not engineering
choices, and neither can be resolved from code.

---

## 1. Existing Wave Contract

The daily cycle is real, defined, and running. `RunWaveSchedulerCommand::processWarehouse`
executes every minute (`routes/console.php:58-61`, `->everyMinute()->withoutOverlapping()`;
`schedule:work` is live under supervisor in `ecos-dev-app`). It is driven entirely by
`wave_engine_configurations`:

| Step | Trigger | Action | Code |
|---|---|---|---|
| 1 — open | `collection_start_time`, no active wave for **today** | create Collecting wave | `RunWaveSchedulerCommand.php:69-79` |
| 2 — collect | `auto_assign_orders` | `attachEligibleOrders` into **today's** wave | `:87-99` |
| 3 — start | `preparation_start_time` | Collecting → Preparing | `:107-115` |
| 4 — rotate | `wave_end_time` | Preparing → Closed **+ create tomorrow's wave** | `:117-124` |

`wave_end_time > preparation_start_time > collection_start_time` is DB-enforced
(`2026_07_16_100000_create_wave_engine_configurations_table.php:45`).

**PART 12 is therefore NOT a contract gap.** The daily window opening is defined as
`collection_start_time`, and rotation already creates the next day's wave.

**Wave status contract (PART 24 §1/§2 — DEFINED).** `WaveStatus.php:9-16` declares eight
cases; `isTerminal()` = `{completed, cancelled, closed}`, `isActive()` = the other five
(`:32-43`). An **engine** wave only ever traverses `Collecting → Preparing → Closed`.
Neither predicate contains any date term.

Caveat found: a second, narrower definition exists — `WaveManager::ACTIVE_STATUSES` =
`{collecting, preparing}` (`WaveManager.php:12-16`). The engine uses that one; the
workspace list filter uses `WaveStatus::activeValues()`. Nothing reconciles them.

**Window vs Wave.** A parallel `PreparationSession` lifecycle exists
(`DailyPreparationSessionManager`, `preparation_session_orders`) with its own
`planning_date` and its own `attachEligibleOrders`. The Wave path is the one wired to the
scheduler and to the Preparation Workspace UI. Which of the two is canonical is **not
declared anywhere**; that audit did not complete (session limit) and is listed as an open
question in §17.

---

## 2. Root Cause

`WaveMembershipService::attachEligibleOrders` (`:37-45`):

```php
->whereNotExists(fn ($q) => $q->select(DB::raw(1))
    ->from('preparation_wave_orders')
    ->whereColumn('preparation_wave_orders.order_id', 'orders.id'))
```

Matches on `order_id` alone — no wave, no date, no wave status, no `postponed_at`. Any
order that has **ever** joined **any** wave is excluded from **every** future wave.

Reinforced at the database level by `uq_prep_wave_orders_company_order UNIQUE
(company_id, order_id)` (`2026_07_06_120200_add_order_exclusivity_constraint.php:15-18`),
**confirmed present in `ecos_dev` at runtime** (`SHOW INDEX FROM preparation_wave_orders`,
MySQL 8.4.10).

There is no un-postpone path: `postponed_at` is written in exactly one place
(`WaveMembershipService.php:195`) and cleared nowhere.

### Runtime evidence — the defect in live data

```
ORD-00001  wave PREP-202608-000001 (2026-08-12, preparing)  postponed 2026-08-14  status=ready_for_dispatch
ORD-00003  wave PREP-202608-000001 (2026-08-12, preparing)  postponed 2026-08-13  status=ready_for_dispatch
ORD-00004  wave PREP-202608-000001 (2026-08-12, preparing)  active                status=ready_for_dispatch

waves: 2026-08-14 collecting (0 orders) · 2026-08-13 collecting (0) · 2026-08-12 preparing (3)
```

Three orders are locked to a two-day-old wave. Today's wave has none and cannot acquire
them.

### Secondary root cause — old waves are never closed

Step 4 only ever runs on a wave whose `planning_date` **is today** (`$activeWave` is
fetched date-scoped at `:87`). A wave left behind by a missed tick, a restart, or
`auto_move_to_preparing = false` falls out of scope at midnight and is stranded in
whatever status it held. The code states this is deliberate (`:84-86`: *"Stale waves are
left untouched — they are history"*). The 2026-08-12 wave above is stranded in
`preparing`.

**Design consequence:** cross-day eligibility **cannot be keyed on wave status**, because
an old wave never reliably reaches a terminal one. It must be keyed on `planning_date` or
on an explicit membership-release stamp.

*(Also found: the "stranded wave" clamp at `WaveLifecycleService.php:137-142` is
unreachable — its only caller always passes a wave dated today, so `$nextDate` is always
tomorrow. Its docblock describes a defence that cannot fire.)*

---

## 3. Cross-Day Rule — and why it cannot be implemented as written

The requested rule is: *an order collected into a wave, not finally prepared/dispatched,
**and still eligible for preparation**, must be able to enter the next day's wave.*

The qualifier is where it fails. **An order leaves the eligible set when preparation
STARTS, not when it finishes.**

- Entry gate = `OrderStatus::fulfilmentEligible()` = `['in_progress', 'confirmed']`
  (`OrderStatus.php:114-120`) — single source, re-derived by `PreparationSessionPolicy`,
  `config/distribution.php`, and force-normalised into both config tables by
  `2026_08_13_100000_supersede_order_lifecycle_v3_canonical.php:63,102,105`.
- When a wave enters `Preparing`, `WavePreparationService` fires `WavePreparationStarted`,
  whose listeners run `MoveToPreparationWorkflow`, which transitions every order to
  **`ReadyForDispatch`** (`MoveToPreparationWorkflow.php:42-44` guard text: *"must be In
  Progress or Confirmed to become Ready for Dispatch"*).

So the moment picking begins — before a single unit is touched — every order in the wave
becomes `ready_for_dispatch` and is no longer fulfilment-eligible. That is exactly why all
three live orders show `ready_for_dispatch` while none is prepared.

**Fixing the membership predicate and the unique constraint would change nothing for
them.** The system has no way to express *"was in a wave, was not prepared, still needs
preparing"*. That signal does not exist.

**This is a business-contract gap, not a bug I can pick a value for.** See §17 G1.

---

## 4. Postponement (PART 5 — STOP condition 3)

From code only, `postponed_at` means: *this membership has left the current cycle; the row
is retained as history.*

- Set only at `WaveMembershipService.php:192-195`; the `whereNull('postponed_at')` in that
  UPDATE is itself the idempotency guard.
- `PreparationWaveOrder::scopeActive()` is literally `whereNull('postponed_at')` (`:97-100`).
- Never cleared anywhere.
- Releases **nothing** — no order write, no inventory write. Pinned by
  `tests/Feature/Operations/WavePostponeOrderTest.php:337-376`, which asserts
  `inventory_items.reserved_qty`, `order_lines.reserved_qty`, `orders.reservation_status`
  and `inventory_reserved_at` are byte-identical before and after.

It is therefore **a membership state**, not a "defer to tomorrow" instruction. Nothing in
code carries a target date or a re-entry intent.

Making it mean "carry to the next day" is a **semantics change**. Per PART 5 I stopped
rather than redefine it.

---

## 5. Historical Membership (PART 9 / PART 14)

Nothing deletes `preparation_wave_orders` rows on wave close or complete
(`WaveLifecycleService::closeWave` writes only wave columns). The only delete path is
`RecalculateWaveAction:46-50` for explicit `remove_order_ids`, which throws unless the wave
is Draft or Planning — unreachable for an engine wave. `WaveMembershipService::detachOrder`
has no caller and no route.

History is therefore already preserved and must stay so. **No backfill is proposed and
none was executed.** Current volume: 3 membership rows total.

---

## 6. Unique Constraint (PART 10 — STOP condition 6)

Carry-over requires a **second** membership row while PART 9 forbids deleting the first.
`UNIQUE (company_id, order_id)` forbids exactly that. **A migration is unavoidable.**
Under PART 10 I stopped here and did not write one.

### Blast radius — measured, and it is small

- **No** `where('order_id', X)->first()`, `firstOrFail()`, `sole()` or `value()` exists
  against this table anywhere in `Modules/`. Relaxation raises **essentially zero exception
  risk**; the entire risk is join fan-out producing silently wrong numbers.
- **No code resolves "which wave is this order in?"** The one-wave-per-order assumption is
  *enforced* but never *read*, so no consumer must be taught to pick a winner.
- Consumers already filtered by `preparation_wave_id` (safe): `ProductDemandCalculator`,
  `MissingMaterialCalculator::countAffectedOrders`, all `WaveDemandController` methods
  including `MaterialDemandCalculator::ownWaveMaterialReservations`, `PreparationWaveController`.
- `AutoAllocationService.php:262` is the only site that could see two waves at once; it is
  already idempotent.
- Distribution does not reference this table at all.

### Candidate designs (for decision — NOT implemented)

The platform runs **MySQL 8.4 in dev/test and PostgreSQL in production**, and MySQL has no
partial unique indexes. The repo's established pattern for exactly this problem is
`2026_07_20_100000_fix_inventory_items_soft_delete_unique.php`: a real partial index on
`pgsql`, and on MySQL **drop the unique entirely** and guard in the application. (Live
consequence: `inventory_items` in `ecos_dev` today has no unique on
`(warehouse_id, product_id)` at all — only PRIMARY.)

| Option | Shape | Notes |
|---|---|---|
| **A — repo precedent** | pgsql: `UNIQUE (company_id, order_id) WHERE <active>`; mysql: drop, guard in app | Matches house style. Weakest DB guarantee on MySQL. |
| **B — membership lifecycle column** | add `released_at TIMESTAMP NULL`; unique over the unreleased subset; wave close/rotate stamps it | Explicit, readable, survives the "old waves never close" problem. Needs a writer at close/rotate. |
| **C — generated column** | virtual `active_membership = CASE WHEN released_at IS NULL THEN 1 ELSE NULL END`, `UNIQUE (company_id, order_id, active_membership)` | Portable to both engines (NULLs distinct in a unique index). Most robust; most novel for this repo. |

**Recommendation: B + C together** — `released_at` as the semantic fact, the generated
column as the portable enforcement. It does not depend on wave status, which §2 proved
unreliable.

**Not proposed:** `UNIQUE (company_id, order_id, wave_id)` (degenerate — already implied by
the existing wave-scoped unique) or `(company_id, order_id, planning_date)` (permits two
active memberships on the same day across warehouses, breaking PART 8).

---

## 7. Planning Date / Timezone (PART 11 — STOP condition 5)

Two clocks, confirmed:

- Creation: `Carbon::now()->setTimezone($config->timezone)` — `RunWaveSchedulerCommand.php:59-60`.
- Rotation clamp: `Carbon::now()->startOfDay()` — app timezone, `WaveLifecycleService.php:140`.

Runtime: `config('app.timezone') = UTC` and `wave_engine_configurations.timezone = UTC`, so
they **coincide today** and the divergence is latent, not active. It becomes real for any
company on a non-UTC timezone: with `Africa/Cairo`, the two disagree for the 00:00–02:00
local window.

Authoritative source **should** be `wave_engine_configurations.timezone` (it is the only
business-owned timezone in the Preparation domain), but nothing declares it as such and the
rotation path does not use it. Unifying it is a one-line change I did not make, because it
sits inside the same lifecycle this task is blocked on and would be unverifiable in
isolation. Listed as G4.

---

## 8. Window Opening

Defined — see §1. No gap. `collection_start_time` opens it; `preparation_start_time` starts
preparation; `wave_end_time` closes and rotates. No cutoff field beyond these three exists;
"orders after cutoff enter tomorrow's window" is already the emergent behaviour of Step 1 +
Step 2 being date-scoped to today.

---

## 9. Reservation Safety (PART 16 — STOP condition 7)

**Not fully established — the dedicated audit did not complete (session limit).** What was
verified directly:

- Postponement releases nothing (§4, with a test pinning it).
- Wave close writes only wave columns; no reservation release.
- Attaching an order to a wave does **not** call `ReserveOrderInventoryAction` or
  `ReconcileOrderMaterialReservationsAction`. The only production caller of the reconciler
  is `ReserveOrderInventoryAction:271-275`, reached from order confirmation, not from wave
  membership.

**Provisional conclusion:** a second membership row would **not** create a second
reservation, because attachment never reserves. **Not certified** — the interaction with
`PreparationInventoryReservation` (a second, wave-scoped soft-reservation store managed by
`SoftReservationService`, released only by `CancelWaveAction`) was not traced to completion.

---

## 10. Material Demand Safety (PART 17)

`ProductDemandCalculator` groups by `preparation_wave_id` and filters
`postponed_at IS NULL`, so a historical membership in a different wave contributes nothing
to this wave's demand. The reservation-netting added by
TASK-PREPARATION-RESERVATION-DEMAND-CONSISTENCY-001
(`MaterialDemandCalculator::ownWaveMaterialReservations`) joins
`preparation_wave_orders` **scoped to the wave and to `postponed_at IS NULL`**, so it is
structurally immune to cross-wave duplicate rows.

That earlier fix remains deployed and unchanged; nothing in this task touched it.

---

## 11. Concurrency (PART 18)

`wave:run-scheduler` is `->withoutOverlapping()`. `attachOrder` wraps the insert in a
transaction and catches `UniqueConstraintViolationException`, returning `null`
(`WaveMembershipService.php:99-101`) — so scheduler/manual races are absorbed by the
database, not by the UI.

**Relevant to §6:** that idempotency currently rests on
`uq_preparation_wave_orders_wave_order (preparation_wave_id, order_id)`, which is a
*different* constraint from the one that must be relaxed. PART 8 same-day idempotency
therefore survives Option A/B/C intact. This is the one piece of good news in the design.

---

## 12. API / UI impact

None — no contract changed. The Wave Archive page, the wave picker (`lifecycle=active`,
`sort=-planning_date`, `per_page=3`) and the Related Orders postpone action are unaffected
by anything in this report. Were carry-over implemented, no new endpoint would be needed:
the existing archive/active split already expresses historical vs current membership.

---

## 13. Tests

**None written.** The PART 15 matrix (A–O) and the PART 22 E2E scenario are all downstream
of a schema decision that has not been made. Writing them now would pin behaviour that the
decision may invalidate.

---

## 14. Runtime Evidence

Read-only queries against `ecos_dev` (no writes, no migrations, no `migrate:fresh`, no
competing suite):

```
SHOW INDEX FROM preparation_wave_orders
  UNIQUE uq_preparation_wave_orders_wave_order (preparation_wave_id, order_id)
  UNIQUE uq_prep_wave_orders_company_order     (company_id, order_id)      <- the blocker
driver=mysql  version=8.4.10

memberships: 3 total, all on PREP-202608-000001 (2026-08-12, preparing), 2 postponed
waves: 08-14 collecting(0) · 08-13 collecting(0) · 08-12 preparing(3) · 07-30 collecting · 07-29 closed

orders by status: awaiting_stock 2 · in_progress 1 · ready_for_dispatch 3
orders with preparation_completed_at NOT NULL: 0        <- column provably never written

config: app.timezone=UTC · wave_engine_configurations.timezone=UTC
        eligible_order_statuses = ["in_progress","confirmed"]
```

---

## 15. Static Quality

Not applicable — no files changed. The tree is unchanged from the end of
TASK-PREPARATION-RESERVATION-DEMAND-CONSISTENCY-001, whose gates were green
(PHPStan L0 + core L6 clean, Pint pass, TS 24/24, ESLint 0 errors, Vite build, whitespace
clean) and whose backend remains deployed and byte-parity-verified in `ecos-dev-app`.

---

## 16. Regression

No regression run — nothing changed. The prior task's deployment is untouched and still
live in the container.

---

## 17. Contract Gaps — decisions required

**G1 — BLOCKER. There is no order-level "preparation completed" fact.**
`orders.preparation_completed_at` exists as a column but its only writer,
`HandlePreparationWaveCompleted.php:44-50`, filters `->where('status', 'preparing')` — and
`preparing` is **not an `OrderStatus` case** (the eleven cases are `in_progress, confirmed,
ready_for_dispatch, out_for_delivery, delivered, awaiting_payment, awaiting_stock,
scheduled, on_hold, cancelled, returned`). The UPDATE matches zero rows every time;
runtime confirms **0 orders** carry the timestamp. Its docblock claims the opposite.

Why this blocks the task: once historical membership rows stop excluding an order, **the
order's current status is the only remaining guard**. And that guard is defeatable through a
supported path — `ReturnToPendingWorkflow` returns an order to `in_progress`, making it
fully eligible again. A fully prepared order would then be re-collected by the scheduler
within 60 seconds and **prepared a second time, with no signal it was ever prepared**.
Relaxing the constraint without first establishing this fact is unsafe.

**G2 — BLOCKER. Orders leave the eligible set at preparation START, not completion** (§3).
"Still eligible for preparation" is not expressible today. Deciding this requires choosing
between: (a) orders stay `in_progress` until preparation actually completes, (b) a separate
preparation-state field independent of order status, or (c) carry-over keyed on something
other than order status. All three are order-lifecycle changes — squarely outside PART 25's
scope fence, which is why I stopped.

**G3 — `postponed_at` semantics** (§4). Currently a membership state. Redefining it as a
next-day instruction is a semantics change; PART 5 says stop.

**G4 — authoritative timezone undeclared** (§7). Recommend
`wave_engine_configurations.timezone`; needs confirmation before unifying.

**G5 — old waves are never closed** (§2). Any lifecycle-aware uniqueness must not depend on
wave status. Separately: should stranded waves be auto-closed at rollover? Today they are
deliberately left alone.

**G6 — two "active wave" definitions** (§1): `WaveStatus::isActive()` (5 statuses) vs
`WaveManager::ACTIVE_STATUSES` (2). Which governs eligibility?

**G7 — Wave vs PreparationSession** (§1). Two parallel daily lifecycles; neither is declared
canonical. Audit incomplete.

**G8 — reservation lifecycle across days** (§9). Provisionally safe; not certified.

---

## 18. Final Verdict

**NOT CERTIFIED.**

**Exact blockers, in dependency order:**

1. **G1** — no order-level preparation-completed fact exists; without it, relaxing
   uniqueness permits double preparation of an already-prepared order.
2. **G2** — orders become `ready_for_dispatch` when preparation *starts*, so "still eligible
   for preparation" cannot be expressed; the requested rule has no implementable predicate.
3. **G3 / PART 10** — carry-over needs a second membership row; `UNIQUE (company_id,
   order_id)` forbids it; a migration is required and PART 10 says stop before writing one.

The schema half of this task is tractable and low-risk (§6). The lifecycle half is not, and
the two blockers above are business decisions about how an order's preparation state should
be modelled — not engineering choices I should make.

**Nothing was implemented. No migration was written. No data was modified.**
Stopped as instructed. Not continuing to Loading, Vehicle, Driver or Delivery.

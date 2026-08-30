# TASK-DISTRIBUTION-DAILY-GROUP-WAVE-LIFECYCLE-002 — REPORT

**Status: IMPLEMENTED / BROWSER MUTATION VERIFICATION — BLOCKED BY DATA SAFETY**
Date: 2026-08-24 · Branch: `develop` · Not committed, not deployed
Migration: **1, authorized by PART 19** · Focused tests: **24 / 24 green, 85 assertions**
Regression: **88 / 88 green, 618 assertions**

---

## 1. Executive Summary

A Distribution Group now knows, structurally, which Preparation Wave it is the
operational instance of and which Template stamped it — the two facts Task 001 proved
were unanswerable. On that identity the daily lifecycle is built: one auto-created Group
per Template per Wave (enforced by the database), no empty Groups, lazy creation when
work arrives, closure when the Wave ends, and unfinished orders released back to the
canonical pool rather than carried forward inside a stale Group.

Two defects were caught during implementation and both mattered:

- **PHPStan caught `$enforceCapacity` missing from two closure capture lists.** In PHP
  that resolves to null → falsy → `$incoming = 0` **always**, which would have silently
  disabled capacity enforcement for *every* caller — the exact opposite of the scoped
  change PART 8 asks for. Fixed before any test ran.
- **A test exposed that `zoneIds()` reads the loaded relation**, so a caller holding a
  pre-edit Template instance would stamp stale zones onto a new Group. PART 12 says
  "CURRENT persisted configuration", and an in-memory relation is not that, so the
  implementation was hardened rather than the test merely corrected.

---

## 2. Existing Group schema (before)

`distribution_virtual_slots`: `id`, `company_id`, `distribution_window_id`,
`warehouse_id`, `code`, `name`, the four `capacity_*` columns, the three
`overflow_approved_*` columns, timestamps. **No template id, no wave id, no status.**

## 3. Existing Wave schema

`preparation_waves`, keyed `(company_id, warehouse_id, planning_date, wave_type)` with
`wave_number`, `starts_at`, `intake_closes_at`, `ends_at`, `status`. **Per warehouse.**

`distribution_windows` is `(company_id, window_date)` unique — **per company, per day**.
The two are not interchangeable, which is why PART 6 forbids using the Window as the
Wave's identity.

## 4. Existing Template schema

`distribution_group_templates` (`company_id`, `name`, `capacity_orders`, soft deletes)
plus `distribution_group_template_zones`. No warehouse — and per PART 7 none was added.

---

## 5. Group → Wave implementation

`applyToNewGroup()` stamps `preparation_wave_id` from the canonical reader,
`WaveManager::getActiveWave()` — the same one the collector uses. No duplicate Wave model.

**The operational date is always passed.** `getActiveWave()` without a date returns the
newest active wave for the warehouse, and the codebase's own docblock records what that
caused before: *"a date-less check is what let a stale Collecting wave stand in for
today's"*. The Window's `window_date` is the day being planned, so it is the correct
anchor.

Null is allowed and meaningful: an operator may plan before a wave opens. A fabricated
wave id would be worse than an absent one.

## 6. Group → Template implementation

`distribution_group_template_id`, stamped at creation.

**Provenance, not a live reference.** Nothing reads it to derive the Group's zones or
capacity — those live in the Group's own `distribution_slot_zones` and `capacity_orders`.
So a later Template edit still cannot reach an existing Group, which
`test_a_template_edit_does_not_mutate_an_existing_group` pins. Deliberately not a foreign
key, matching every other Distribution migration, so archiving a Template cannot cascade
into operational history.

---

## 7. Migration

`2026_08_25_100000_add_wave_and_template_identity_to_distribution_groups`

| Change | Detail |
|---|---|
| `preparation_wave_id` | `char(36)` nullable |
| `distribution_group_template_id` | `char(36)` nullable |
| `closed_at` | `timestamp` nullable |
| `closed_reason` | `varchar(40)` nullable |
| `dist_slot_wave_idx` | index on `preparation_wave_id` |
| `dist_slot_wave_template_unique` | unique on (wave, template) |

Additive, nullable, reversible, no foreign keys, no CHECKs, tenant-safe (every row keeps
its `company_id`), backwards compatible — every existing query still works because the
columns are new information rather than a changed contract.

**The unique index was verified empirically, not assumed.** I probed the real index rather
than trusting my reading of MySQL's NULL semantics:

| Probe | Result |
|---|---|
| Two `(wave, NULL)` rows — operator-created Groups | **ALLOWED** — correct; PART 5 constrains only auto-created |
| Duplicate `(wave, template)` | **REFUSED** — the PART 5 invariant |
| Same template, different wave | **ALLOWED** — PART 11 |

Probe rows were removed afterwards.

## 8. Backfill strategy

Deterministic or absent — never inferred.

`preparation_wave_id` is filled in **only** where `(company, warehouse, window date)`
matches exactly one Wave. Where a day holds several, the column stays NULL: PART 20
forbids guessing lineage, and a wrong Wave stamped on operational history is worse than a
missing one.

`distribution_group_template_id` is left NULL for every pre-existing row. No reliable
source exists — `applied_from_template_id` was only ever echoed from a request URL.

Live outcome, checked before writing the migration:

- All **3** existing Groups fall on 2026-08-21, which has exactly **one** Wave → all three
  classified deterministically.
- The one ambiguous day, 2026-08-20 with **three** engine waves, holds **no** Groups, so
  nothing required a guess.
- Every Group's `updated_at` was preserved (the backfill uses a query-builder update, so
  no audit column was rewritten).

**No STOP condition was triggered by the backfill.**

---

## 9. Group lifecycle

The table had no status column at all, so "historical because its Wave ended" could not be
told from "active". The minimum representation is one timestamp plus a reason:

- `closed_at IS NULL` → operational.
- `closed_at` set → historical: keeps every row, zone and manifest, and drops out of the
  active reads.

States *before* closure (planning, loading, dispatched) stay derived from the existing
Trip and Loading records rather than duplicated into a second status system, per PART 14.

`closed_at` is cast but deliberately **not** fillable — a Group becomes historical through
`closeWave()`, never through a mass-assign.

## 10. Wave close behaviour

`DailyGroupLifecycleService::closeWave($waveId)`:

1. Locks the Wave's open Groups (`lockForUpdate`).
2. Releases their still-attached orders by nulling `virtual_slot_id` — **the assignment
   row survives**, so the order's window history is not rewritten and the existing
   collector can pick the order up for the next Wave. That is the canonical pool, not a
   copied Group.
3. Stamps `closed_at` + `closed_reason = wave_ended`.

Complete and incomplete Groups close alike: a Group is the instance of ONE Wave, so when
the Wave ends the instance is over whether or not its work finished. **Nothing is
deleted.** Idempotent — a second run neither restamps a closure time nor re-releases
orders that have since moved (`test_closing_a_wave_twice_is_idempotent`).

## 11. Previous-Wave isolation

`findGroup(wave, template)` is scoped to the Wave and excludes closed Groups, so
yesterday's Group can never be returned for today. `activeGroupsForWave()` is the same
predicate for lists. Proven by `test_a_previous_waves_group_is_never_reused`, which also
asserts yesterday's Group was **not mutated**.

**PART 15 filtering:** `DistributionAggregationService::slotSummaries()` — the canonical
board list — now excludes `closed_at IS NOT NULL`. Filtering there rather than per caller
means the board, the KPIs and the capacity totals all agree about what is live.

**One residual gap, inherited from Task 001 and not introduced here:** `slotSummaries` is
keyed by *window*, and several Waves can share one window on the same day. So an *unclosed*
Group from an earlier Wave on the same day would still appear until that Wave is closed.
Across days the window filter already separates them. Closing the Wave is the operation
that resolves it, which is what this task implements.

## 12. Capacity behaviour

`assignZoneToSlot(..., bool $enforceCapacity = true)` — a **parameter**, not a change to
`GroupCapacityGuard`.

Daily creation is the one caller that passes `false`. Every existing caller — the Zones
tab, manual attach, an operator moving a zone — keeps exactly today's behaviour. Weakening
the guard itself would have silently lifted the limit off manual work too, which is the
opposite of what PART 8 asks.

Capacity remains a planning threshold: the Group still records `capacity_orders` (20 in the
test), the board still reports the overflow, and Finalize still demands an explicit
operator approval before an over-capacity Group can leave.

Proven both ways:
- `test_daily_creation_may_exceed_capacity_without_splitting` — 27 orders, capacity 20,
  **ONE** Group holding all 27, `capacity_orders` still 20.
- `test_manual_zone_attach_still_enforces_capacity` — manual attach beyond capacity is
  still refused with 422.

## 13. Lazy creation compatibility

`ensureGroupForTemplate(window, template, warehouseId, waveId, eligibleCount)`:

- eligible count `< 1` → **no Group**, and the Template stays usable
  (`test_a_template_with_no_eligible_orders_creates_no_group` also asserts no empty shell
  was created).
- work arrives later → Group created lazily from the Template's configuration **as it is
  then**.
- more work → the **same** Group; never a second one.
- lost race → the unique index refuses the second insert, and the service returns the
  winner rather than failing.

It is not a second creation engine: it decides *whether* a Group is warranted and delegates
creation to `applyToNewGroup()` — the same call the operator's Apply button makes.

## 14. Loading compatibility

Untouched. Loading identifies a Group by its own id, which this task did not change; the
Group is not duplicated and no Loading-specific Group exists.
`test_the_group_remains_discoverable_by_its_canonical_identity` pins it, and
`test_no_driver_or_vehicle_is_assigned_by_group_creation` asserts zero rows in
`logistics_driver_vehicle_assignments`, `vehicle_assignments`, `driver_assignments` and
`loading_sessions` after creation *and* closure.

---

## 15. Tests

`DistributionDailyGroupWaveLifecycleTest` — **24 / 24 green, 85 assertions.**

| PART 22 | Test |
|---|---|
| 1 Wave identity | `test_a_group_stores_its_preparation_wave_identity` |
| 2 Template identity | `test_a_group_stores_the_template_it_was_created_from` |
| 3 No two Groups per Template+Wave | `test_the_same_template_cannot_have_two_groups_in_one_wave` |
| 4 New Wave → new Group | `test_the_same_template_gets_a_new_group_in_a_new_wave` |
| 5 Previous Wave never reused | `test_a_previous_waves_group_is_never_reused` |
| 6 Completed Group absent from next Wave | `test_a_closed_group_leaves_the_active_board_but_survives` |
| 7 Incomplete Group becomes historical | `test_an_incomplete_group_closes_with_its_wave` |
| 8 Orders return to the pool | `test_incomplete_orders_return_to_the_pool_at_wave_close` |
| 9 Next Wave creates a new Group | `test_the_next_wave_creates_a_new_group_for_the_same_template` |
| 10 Edit does not mutate existing | `test_a_template_edit_does_not_mutate_an_existing_group` |
| 11 New Group uses latest config | `test_a_new_wave_group_uses_the_latest_template_configuration` |
| 12 Zero eligible → no Group | `test_a_template_with_no_eligible_orders_creates_no_group` |
| 13 Lazy creation | `test_a_late_eligible_order_creates_the_group_lazily` |
| 14 Reuse the same Group | `test_additional_eligible_orders_reuse_the_same_group` |
| 15/16 27 orders, capacity 20, no split | `test_daily_creation_may_exceed_capacity_without_splitting` |
| 17 Zone exclusivity | `test_template_zone_exclusivity_remains_enforced` |
| 18 Tenancy | `test_company_isolation_is_enforced` |
| 19/20 No Driver/Vehicle assigned | `test_no_driver_or_vehicle_is_assigned_by_group_creation` |
| 21 Loading discovery | `test_the_group_remains_discoverable_by_its_canonical_identity` |

Beyond the required list: identity survives a rename, operator-created Groups are
unconstrained by the invariant, closure is idempotent, manual attach still enforces
capacity, and no Trip is created or split.

**Three of my own errors, corrected rather than worked around:**

1. `$enforceCapacity` missing from both closure `use` lists — an implementation defect
   PHPStan caught (see §1).
2. My wave fixture omitted `wave_number` and `created_by`. Cause: I had read the schema
   through a regex filter piped to `tail`, which hid them. I re-read it asking directly for
   every `NOT NULL` column without a default.
3. My board helper called `GET /windows/{window}`, which does not exist — the list is
   `GET /windows/{window}/slots`.

No certified test was modified.

## 16. Regression

`--filter "DistributionWorkspaceFinalizationTest|DistributionTemplateZoneExclusivityTest|DistributionGroupManagementTest|DistributionGroupTripTest"`

```
Tests: 88, Assertions: 618   ->   OK
```

**No regression.** All four suites green together, covering the certified Template apply
behaviour, Zone exclusivity, Group management and the Group/Trip contract.

The two suites most at risk from this task were the ones touching the paths I changed:
`DistributionTemplateZoneExclusivityTest` (the `applyToNewGroup` zone reload) and
`DistributionWorkspaceFinalizationTest` (the certified apply behaviour and the
`slotSummaries` board read). Both pass, so neither the identity stamping, the
`enforceCapacity` parameter, the closed-Group board filter, nor the current-persisted-zones
reload altered existing behaviour.

For comparison, before this task's changes the same suites stood at
`DistributionWorkspaceFinalizationTest` 41/41 · `DistributionTemplateZoneExclusivityTest`
18/18 · `DistributionGroupTripTest` 12/12 — consistent with the 88 total here once
`DistributionGroupManagementTest` (17) is included.

## 17. Browser verification

**BROWSER MUTATION VERIFICATION — BLOCKED BY DATA SAFETY.**

Six of the eight PART 24 checks need a Group created or a Wave closed on live data. Live
Groups are 3, all on a closed 2026-08-21 wave; closing or creating against them would
mutate operational history, and fabricating Orders/Waves/Groups is forbidden. Not claimed.

Read-only observations that *were* made, from the live database rather than a screen: the
current Wave is identifiable (`2026-08-24`, status `collecting`), all 3 Groups now carry a
Wave id, and none is closed — so all three are correctly still active.

## 18. Data safety

Identical before and after, apart from the authorized backfill:

| Table | Before | After |
|---|---|---|
| orders | 19 | 19 |
| preparation_waves | 8 | 8 |
| distribution_windows | 4 | 4 |
| groups | 3 | 3 |
| group-zone rows | 3 | 3 |
| templates | 5 | 5 |
| template-zone rows | 8 | 8 |
| window_orders | 13 | 13 |
| trips | 2 | 2 |
| loading_sessions | 0 | 0 |

No Group deleted, reused, or re-statused. No Template changed. No Zone ownership changed.
Every Group's `updated_at` preserved. All mutation tests ran in `ecos_dev_test`.

**Two live-environment facts I did not cause but must report:**

1. **My `php artisan migrate --force` also applied
   `2026_08_24_100000_create_distribution_group_template_drivers_table`** — a migration I
   did not author, which I had reported as awaiting your authorization. It appeared in the
   tree at 17:07 (another agent implemented the spec from my report) and created the table
   empty. The schema change to live dev happened as a side effect of my command. My
   driver-recommendations report is stale on that point.
2. **`logistics_driver_vehicle_assignments` holds 1 row, created 2026-08-24 15:15:45** —
   before this task's work began. Every file I changed contains **zero** references to
   driver, vehicle or loading tables (verified by grep), and my tests assert zero such
   writes. Not caused here, but "no Driver/Vehicle assignment changed" would be
   misleading without saying the row exists.

Also visible: `GroupTemplateService` now contains `$driverIds` in two closures — the same
concurrent driver-recommendations work. My changes are confined to `applyToNewGroup` and
coexist with it.

## 19. Files changed

| File | Change |
|---|---|
| `...Migrations/2026_08_25_100000_add_wave_and_template_identity_to_distribution_groups.php` | **New** — the authorized migration + deterministic backfill |
| `Domain/Services/DailyGroupLifecycleService.php` | **New** — find-or-create, closeWave, active reads |
| `Domain/Services/GroupTemplateService.php` | stamps wave + template identity; threads `enforceCapacity`; reads current persisted zones |
| `Domain/Services/ManualAssignmentService.php` | scoped `enforceCapacity` parameter |
| `Domain/Services/DistributionAggregationService.php` | active board excludes closed Groups |
| `Domain/Models/VirtualCapacitySlot.php` | identity columns fillable; `closed_at` cast, not fillable |
| `tests/Feature/Logistics/DistributionDailyGroupWaveLifecycleTest.php` | **New** — 24 tests |

**Static verification:** `php -l` clean on all · Pint **PASS** on
`DailyGroupLifecycleService` and `GroupTemplateService` · PHPStan **No errors** across all
five changed source files. `ManualAssignmentService` and `DistributionAggregationService`
still carry pre-existing module style debt (e.g. the `) {}` constructor at line 34 that
predates this task); I did not mass-reformat shared files another agent is actively editing.

## 20. Remaining owner decisions

1. **Wave granularity within a day.** Several Waves can share one window (§11). Closing a
   Wave resolves it; if you want strict per-Wave board isolation *without* closure, the
   board read would need to key on `preparation_wave_id` instead of the window — a change
   affecting every caller, so not taken unilaterally.
2. **Who calls `closeWave()`.** The service is implemented and tested; wiring it to the
   Preparation wave-close event or a scheduler is a trigger decision, and PART 9/10 defined
   the behaviour rather than the trigger.
3. **Who calls `ensureGroupForTemplate()`.** Same: the decision logic exists; scheduling
   the wave-start sweep is the remaining wiring.
4. **The unauthorized-but-applied driver-recommendations migration** (§18.1) — whether to
   keep it.

## 21. STOP conditions

None triggered. For the record, against PART 27:

| # | Condition | Finding |
|---|---|---|
| 1 | Existing Groups cannot be classified | Not hit — all 3 classified deterministically |
| 2 | Wave identity cannot be persisted | Not hit — `preparation_wave_id` |
| 3 | Template identity cannot be determined | Not hit for new Groups; historical left NULL per PART 20 |
| 4 | Migration would require guessing | Not hit — ambiguous days hold no Groups |
| 5 | Capacity breaks unrelated contracts | Not hit — scoped parameter, manual paths still enforce |
| 6 | Uniqueness cannot be guaranteed | Not hit — DB unique index, empirically verified |
| 7 | Certified workflows broken | Not hit — 88/88 green across the four regression suites |
| 8 | Loading needs a competing identity | Not hit |
| 9 | Driver/Vehicle coupled to creation | Not hit — asserted zero |
| 10 | Unrelated migration required | Not hit — one migration, this task's tables only |

## 22. Final status

**IMPLEMENTED / BROWSER MUTATION VERIFICATION — BLOCKED BY DATA SAFETY**

Group ↔ Wave and Group ↔ Template identity are durable and structural. One auto-created
Group per Template per Wave is guaranteed by the database. Empty Templates create nothing;
late work creates lazily; more work reuses. Capacity may be exceeded by daily creation and
only by daily creation. Closure makes a Group historical without deleting it and returns
unfinished orders to the canonical pool. Previous-Wave Groups are never reused.

No Trip was created or split, no Driver or Vehicle was assigned, Loading was not redesigned,
and Zone exclusivity, the Map, the Zones panels and Driver Waves 1–2 were untouched.

Wave 3 was not started. `TASK-DISTRIBUTION-GROUP-LOADING-SYNCHRONIZATION-002` was not
started. Nothing committed, nothing deployed.

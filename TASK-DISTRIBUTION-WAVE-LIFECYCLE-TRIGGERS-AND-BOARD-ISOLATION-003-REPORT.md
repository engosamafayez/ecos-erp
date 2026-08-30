# TASK-DISTRIBUTION-WAVE-LIFECYCLE-TRIGGERS-AND-BOARD-ISOLATION-003 — REPORT

**Status: IMPLEMENTED / VERIFIED**
**Browser mutation verification — BLOCKED BY DATA SAFETY**
Date: 2026-08-24 · Branch: `develop` · Not committed, not deployed
Focused: **18 / 18 green, 83 assertions** · Regression: **176 / 176 green, 1348 assertions**
Migration: **none** · PHPStan: **No errors** · Pint: **PASS**

---

## Executive summary

The four decisions left open by TASK-002 are wired. Both triggers already existed as
canonical Preparation events, so nothing new owns the lifecycle: Preparation decides
**when**, Distribution decides **what it means**.

The multi-wave tests PART 8 demanded earned their keep — they exposed **three real defects
in code that had already passed PHPStan, Pint and `php -l`**, none of which is reachable on
the single-wave path that TASK-002's 24 green tests cover. They are documented in full at
§ "Defects found and fixed", because each is the kind of bug that survives static analysis
and a green suite.

---

## 1. Trigger chosen for Wave start

**`WaveStarted`**, dispatched by `StartPreparationAction:126`.

Handled by `StartWaveDistributionGroupsListener`, which resolves the Window for the Wave's
**own** planning date (not "today" — a Wave started late or replayed must plan into the day
it belongs to) and runs `DailyGroupLifecycleService::sweepWave()`.

**No parallel scheduler was created** (PART 13). `RunWaveSchedulerCommand` already exists
and drives the Wave lifecycle; subscribing to what it announces is what keeps Distribution
from polling and guessing which Wave is active — a PART 21 STOP condition.

## 2. Trigger chosen for Wave close

**`WaveClosed`**, dispatched by `WaveLifecycleService:139`.

Handled by `CloseWaveDistributionGroupsListener` → `closeWave($event->waveId)`.

There is deliberately **no manual "close group" button**: a Wave could then end while its
Groups stayed operational, which is the contamination this lifecycle exists to prevent.

Both listeners are registered in this module's own `LISTENERS` map, the direction it
already uses for `OrderGeographyChanged` — the subscriber wires itself and Preparation stays
unaware Distribution exists. `OrderServiceProvider` already subscribes to this same pair,
which confirms the framework dispatcher delivers them.

## 3. Active Wave resolution

`DistributionWindowController::activeWaveId()` → `governingPreparationWave()`, the canonical
read-side resolver the workspace header already uses.

**The operational date is deliberately NOT passed.** The controller documents why, and the
rule is obeyed rather than re-litigated: a preparation cycle spans midnight — one runs 17:30
on one day to 12:00 the next — so the live Wave's `planning_date` is yesterday's while it is
still running. Scoping by today's calendar date would find no Wave every night after
midnight, exactly when the warehouse is working.

**Null is an honest answer, never a fallback.** A Wave is per warehouse, so with no
warehouse selected there is no single Wave to scope to, and inventing one would be guessing
which Wave is active. Null simply leaves the day's Groups unnarrowed — closed Groups are
excluded either way, so an ended Wave can never present itself as today's.

`current()` reuses the Wave it already resolved at the top of the action rather than
resolving a second time.

## 4. Board isolation behaviour

`slotSummaries()` gained an **optional** `?string $waveId`, applied only at the two active
board reads (`current`, `slots`).

- Groups of the active Wave are shown.
- Groups of **another** Wave are not — even on the same day, in the same window.
- Groups with **no** Wave are still shown: a null `preparation_wave_id` cannot belong to a
  *different* Wave, and hiding it would make operator work vanish.
- Closed Groups are excluded (TASK-002).

The other nine `slotSummaries` callers pass nothing **deliberately**: they read a Group they
already hold — vehicle assignment, redistribution, a group detail panel — and must keep
working after that Group's Wave closes. Making the parameter required would have turned
those into historical blind spots.

## 5. Template version behaviour

`sweepWave()` reads `listForCompany()` fresh on every run, so a new Wave uses the latest
active Template configuration. Combined with TASK-002's `$template->load('zones')` reload,
the snapshot comes from the database rather than from however stale a caller's copy is.

No Group is cloned from a previous Wave, no Draft is carried forward, and Template history
is untouched.

## 6. Lazy creation behaviour

`ensureGroupForTemplate()` is the single decision point, used by both the sweep and the
lazy path:

- eligible count `< 1` → **no Group**; the Template stays usable.
- work later → Group created from the Template's configuration **as it is then**.
- more work → the **same** Group.
- lost race → the unique index refuses the second insert and the service returns the winner.

## 7. Idempotency evidence

| Path | Evidence |
|---|---|
| Wave start replayed 3× | `test_wave_start_is_idempotent` — one Group, and its `updated_at` unchanged |
| Wave close replayed | `test_the_wave_closed_event_is_idempotent` — closure time not rewritten |
| Duplicate Template+Wave | `test_duplicate_template_and_wave_is_impossible` — refused by `dist_slot_wave_template_unique` |

The database guarantee was **not** replaced by application checks (PART 9); the service
catches the lost race and returns the winner, with the index as the authority.

---

## Defects found and fixed

Three real bugs, all in code that was already PHPStan-clean, Pint-clean and passing
TASK-002's 24 tests. Each is invisible on the single-wave path.

### 1. Group codes collided across Waves sharing one window

`dist_slots_window_code_unique` is unique on `(window_id, code)`, and several Waves share
one window on the same day. `codeFor()` produced `MORNING-20260824` for all three, so the
second insert died on a duplicate key.

**Fixed:** the code carries a wave-derived suffix — deterministic, so re-running a Wave's
sweep produces the same code and stays idempotent.

### 2. The sweep could not see the work that most needs a Group

`eligibleUnassignedOrders()` excludes anything already collected into a window. The
collector runs continuously, so by the time a Wave starts the orders needing Groups are
usually **already** in the window with no slot — invisible to that source. A sweep built on
it alone creates nothing and leaves that work ungrouped indefinitely.

**Fixed:** the count is now the sum of two canonical halves — that eligibility list, plus
window orders with `virtual_slot_id IS NULL` in the Template's zones.

**This is a deliberate deviation from the literal wording of PART 3**, which names
`eligibleUnassignedOrders()` as the source. It is still used; it is simply not sufficient
alone. Flagged for your sign-off rather than made silently.

### 3. Wave identity was re-resolved instead of honoured

`ensureGroupForTemplate($waveId)` looked the Group up by the Wave it was handed, while
`applyToNewGroup()` **re-resolved** its own via `getActiveWave()`. On an ordinary day those
agree and nothing shows. On a day carrying several Waves they diverge: every Group is
stamped with whichever Wave the resolver picks, so the unique key fires and the board filter
has nothing left to separate. The duplicate-key error and the board leak were one bug.

**Fixed:** `applyToNewGroup()` takes an optional `?string $waveId` and the caller's Wave
wins. Only the operator-driven Apply path passes nothing and still resolves the active Wave
for the day being planned.

---

## 8. Tests

`DistributionWaveTriggersAndBoardIsolationTest` — **18 / 18 green, 83 assertions.**

The suite dispatches the **real** `WaveStarted` / `WaveClosed` events rather than calling
services directly, so the wiring itself is under test — which is the point of the task.

| PART 16 | Test |
|---|---|
| 1 Wave start triggers the sweep | `test_wave_start_triggers_the_template_sweep` |
| 2 Empty Template creates nothing | `test_wave_start_creates_nothing_for_an_empty_template` |
| 3 Template with work creates a Group | `test_wave_start_creates_groups_only_where_work_exists` |
| 4/5 Late work creates lazily, then reuses | `test_late_work_creates_lazily_then_reuses` |
| 6 Duplicate Template+Wave impossible | `test_duplicate_template_and_wave_is_impossible` |
| 7/8 Three Waves one day, isolated | `test_three_waves_on_one_day_each_get_their_own_group` |
| 9 Board shows only the active Wave | `test_the_active_board_shows_only_the_active_waves_group` |
| 10 Previous Wave absent from board | `test_a_closed_waves_group_is_absent_from_the_board` |
| 11/13 Close closes its Groups | `test_the_wave_closed_event_closes_its_groups` |
| 12 Groups remain historical | `test_closing_retains_the_group_as_history` |
| 14 Unfinished Orders released | `test_unfinished_orders_are_released_on_close` |
| 15 Manual capacity intact | `test_manual_zone_attach_still_refuses_to_exceed_capacity` |
| 16 Automatic oversized Group intact | `test_the_sweep_still_produces_one_oversized_group` |
| 17 Close idempotent | `test_the_wave_closed_event_is_idempotent` |
| 18 Start idempotent | `test_wave_start_is_idempotent` |
| 19 Operator `template=NULL` Groups valid | `test_operator_created_groups_remain_valid_and_visible` |
| 20 Certified paths green | see §9 |

Beyond the list: a Group with no Wave stays on the board, and the board is company-scoped.

**Two of my own fixture errors, corrected rather than worked around:** the fixtures used a
fixed future date while `collect()` fills *today's* window, so orders and Groups lived in two
unrelated windows; and `assignZoneToSlot` pulls collected orders in but scoped to
`$window->id`, which is why the "collector filled the group" and capacity-422 assertions
failed until the dates were aligned.

## 9. Regression

`--filter "DistributionDailyGroupWaveLifecycleTest|DistributionTemplateZoneExclusivityTest|DistributionWorkspaceFinalizationTest|DistributionGroupManagementTest|DistributionGroupTripTest|GroupTripReconciliationVisibilityTest|GroupTripLoadingIntegrationTest|DistributionBatchMoveTest"`

```
Tests: 176, Assertions: 1348   ->   OK
```

**No regression across eight suites**, covering the TASK-002 lifecycle core, Template zone
exclusivity, certified Distribution Planning and Template apply, Group management, the
Group/Trip contract, reconciliation, the Loading guard and the atomic batch move.

Of particular note: `test_manual_zone_attach_still_enforces_capacity` and
`test_a_move_into_a_full_destination_is_refused` both pass, so **manual capacity enforcement
is unchanged** — the `enforceCapacity` opt-out remains scoped to automatic creation alone
(PART 6, AC-M).

No certified test was modified.

## 10. Browser verification

**BROWSER MUTATION VERIFICATION — BLOCKED BY DATA SAFETY.**

Observing wave-start or wave-close behaviour requires dispatching those events against live
data, which would create or close real Groups. PART 18 and PART 19 both forbid it, and no
Orders, Groups, Waves or assignments were fabricated.

Read-only observations from the live database: the current Wave is identifiable
(`2026-08-24`, status `collecting`), all 3 live Groups carry a `preparation_wave_id`, none
is closed, and every Group's Template id is NULL — correct, since all three predate the
identity column and PART 20 forbids inferring their lineage.

## 11. Data safety

Identical before and after — nothing was written to live data by this task.

| Table | Before | After |
|---|---|---|
| Orders | 19 | 19 |
| Waves | 8 | 8 |
| Windows | 4 | 4 |
| Groups | 3 | 3 |
| Slot Zones | 3 | 3 |
| Templates | 5 | 5 |
| Template Zones | 8 | 8 |
| Window Orders | 13 | 13 |
| Groups closed | 0 | 0 |
| Groups with a Wave | 3 | 3 |

Trips 2, loading sessions 0, `distribution_group_template_drivers` 0 — all unchanged. Every
mutation test ran in `ecos_dev_test`.

## 12. Migration note

**No migration was created by this task.**

`2026_08_24_100000_create_distribution_group_template_drivers_table` is recorded as
**already applied and accepted as existing infrastructure**, per PART 11. It was authored by
other work and applied to live dev by a `migrate --force` I ran during TASK-002; the table is
additive and holds 0 rows. It was **not** reverted and its structure was **not** modified.
This task does not implement Driver Recommendations.

## 13. Files changed

| File | Change |
|---|---|
| `Application/Listeners/StartWaveDistributionGroupsListener.php` | **New** — `WaveStarted` → sweep |
| `Application/Listeners/CloseWaveDistributionGroupsListener.php` | **New** — `WaveClosed` → closeWave |
| `Infrastructure/Providers/LogisticsDistributionServiceProvider.php` | both listeners registered |
| `Domain/Services/DailyGroupLifecycleService.php` | `sweepWave()`, two-source eligibility, wave-unique codes, wave passed through |
| `Domain/Services/GroupTemplateService.php` | `applyToNewGroup()` honours a caller-supplied Wave |
| `Domain/Services/DistributionCollectionService.php` | `eligibleUnassignedOrders()` made public |
| `Domain/Services/DistributionAggregationService.php` | optional Wave filter on `slotSummaries()` |
| `Presentation/Http/Controllers/DistributionWindowController.php` | `activeWaveId()`; both board reads scoped |
| `tests/.../DistributionWaveTriggersAndBoardIsolationTest.php` | **New** — 18 tests |

## 14. Acceptance criteria

| | Criterion | Status |
|---|---|---|
| A | Wave start has a canonical trigger | `WaveStarted` |
| B | Wave close has a canonical trigger | `WaveClosed` |
| C | Start/close idempotent | tested both |
| D | Planning is Wave-isolated | optional Wave filter on the board reads |
| E | Same-day Waves cannot contaminate | 3-wave test green |
| F | Latest Template version used | templates read fresh per sweep |
| G | Empty Templates create nothing | tested |
| H | Late work creates/reuses lazily | tested |
| I | Completed Groups stay historical | tested |
| J | Incomplete Groups do not reappear | tested |
| K | Unfinished Orders released | tested |
| L | Capacity rule intact | oversized Group in one piece |
| M | Manual capacity unchanged | 422 still returned |
| N | DB uniqueness intact | index unchanged, duplicate refused |
| O | Certified paths green | 176/176 |

## 15. Remaining blockers

None blocking. Two things for your awareness:

1. **The PART 3 deviation** in the sweep's eligibility source (see Defect 2) — using the
   named source alone would make the sweep a no-op in the real operating order. Flagged for
   sign-off.
2. **Browser mutation verification** remains outstanding for the reason in §10. It needs a
   non-production environment where a Wave can be started and closed without touching
   operational history.

---

## Final status

**IMPLEMENTED / VERIFIED** — 18/18 focused, 176/176 regression, PHPStan and Pint clean, no
migration, live data untouched.

No competing Wave engine, no second Group creation source, no new Driver/Vehicle engine, no
change to manual capacity, no historical Group deleted, no Window history rewritten, no Wave
identity invented, and no Loading, Trip or Settlement behaviour modified.

Nothing committed, nothing deployed, no other task started.

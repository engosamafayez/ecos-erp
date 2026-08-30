# TASK-DISTRIBUTION-WAVE-GROUP-SWEEP-CLOSURE-FIX-001

## STATUS

**PARTIALLY IMPLEMENTED — §2 delivered. §3, §4, §6 and §8 hit STOP conditions you defined,
and I stopped at each rather than working around them.**

It also turned up a **second, proven behaviour** — and it took me three attempts to
characterise correctly, each time corrected by a test rather than by reasoning:

1. I predicted the sweep would **throw** and roll the wave back. **Wrong** — it doesn't throw.
2. I then read the evidence as a **zone-less empty shell**. **Also wrong.**
3. What actually happens: **the sweep creates its own Group and TAKES the Zone from the Group
   that held it**, leaving that one empty. `assignZoneToSlot()` re-points the existing
   `(window, warehouse, zone)` row, which is why the unique index never fires.

At a Wave rotation that is correct. Against an **operator-created** Group it silently empties
an operator's work — and the two are indistinguishable from Zones alone.

Code: 2 files + 1 test file · Schema: **NONE** · Migrations: **NONE** · Permissions: **NONE**
Live DEV data: **UNCHANGED** (ORD-00007, DG-004, DG-005, Wave 009 all untouched)
Commit/Push: **NONE**

Date: 2026-08-26 · Branch: `develop`

---

## 1. Root Cause — with two corrections to the previous report

The previous diagnosis was right that the sweep ran and declined. Two of its supporting
claims were **wrong**, and the corrections matter:

**Correction 1 — no live Group was ever created by the sweep.** All five groups carry
`distribution_group_template_id = NULL`:

| Group | wave | template |
|---|---|---|
| DG-001 | set (wave 005) | **NULL** |
| DG-003 | set (wave 005) | **NULL** |
| DG-004 | NULL | **NULL** |
| DG-005 | NULL | **NULL** |

The sweep *always* stamps the template (pinned by
`test_a_group_stores_the_template_it_was_created_from`). So DG-001/DG-003 were **not**
sweep-created as I previously stated — they were operator-created *while a wave was
resolvable*, which is why they carry a wave. **Every live group is operator-created.**

**Correction 2 — the duplicate-scope guard already exists**, and I had not found it:
`distribution_slot_zones` is **UNIQUE on (distribution_window_id, warehouse_id,
distribution_zone_id)**. A zone belongs to at most one group per window+warehouse. That is
the canonical "same operational scope" constraint §4.3 asks for — it is already enforced in
the database.

**The root cause of the reported symptom is unchanged: classification D.** The sweep is
template-driven; zone 9 (Obour) is attached to no active template; the eligible map was
`[9 => 1]`; all five templates summed to 0 and returned `null`.

---

## 2. Wave lifecycle verification — re-verified, still PASS

Unchanged and untouched, exactly as §1 of the brief requires:

| Step | Result |
|---|---|
| `Schedule::command('wave:run-scheduler')->everyMinute()` | **PASS** |
| `WavePreparationService` `Collecting → Preparing` | **PASS** (05:00:01, `system`) |
| `event(new WavePreparationStarted(...))` — unconditional in the committed transaction | **PASS** |
| Listener registration via `Event::listen` | **PASS** |
| `sweepWave()` invoked | **PASS** |

I modified **nothing** in the scheduler, the wave lifecycle, the boundaries, the timezone
logic, or the event.

---

## 3. Sweep behaviour — §2 DELIVERED

`sweepWave()` always returned its tally; the caller discarded it. A sweep that creates
nothing was therefore indistinguishable from a sweep that never ran — which is exactly what
turned the wave-009 question into a database forensic.

**What changed (no new business logic, no new query, no new eligibility rule):**

- `sweepWave()`'s return keeps `created` / `reused` / `skipped` **byte-identical** and adds:
  - `skipped_templates` — per declined template: name, its zones, the eligible count it saw,
    and `reason` (the new `DailyGroupLifecycleService::SKIP_NO_ELIGIBLE_WORK` constant, so
    the reason is named rather than inferred).
  - `eligible_by_zone` — the small `zone id => count` map the decision was made from.
  - `uncovered_zones` — **the field that matters**: zones holding eligible work that **no
    active template can reach**. Derived by folding the map the caller already built against
    the same active-template list the sweep just iterated.
- `StartWaveDistributionGroupsListener` logs one structured line, `distribution.wave_sweep`,
  at INFO: wave id, window id + date, the three counts, `skipped_templates`,
  `uncovered_zones`. **Counts and ids only — no order, customer, address or quantity data.**

The closure that computes eligibility is still **lazy** (captured by reference) so it runs
only when there is no existing group — the one path that can skip. Query behaviour is
unchanged.

**Why `uncovered_zones` is the important half:** "no work" and "work no template can reach"
had identical output. They are opposite problems — one is normal, the other is a silent
misconfiguration — and they must never render the same way.

---

## 4. Zone 9 decision — **STOP: UNDETERMINED business-wise**

I did not add zone 9 to a template. Per §3 I checked first, and the check says the decision
is not recorded anywhere in the system.

**`distribution_zones` has no "served" column.** Its only status field is `is_active`, and
zone 9 is `is_active = 1`. Template attachment is the *de-facto* coverage mechanism, and it
is operator configuration, not a business declaration.

Live coverage of all 10 active zones:

| Zone | Template | In a group | Cities | Live orders |
|---|---|---|---|---|
| z1 New Cairo | COVERED | 1 | 1 | 1 |
| z2 Nasr City | COVERED | 1 | 16 | 6 |
| **z3 Giza** | **none** | **1 (manual)** | 4 | **2** |
| z4 Zayed & October | COVERED | 0 | 3 | 0 |
| z5 Dokki | COVERED | 0 | 5 | 0 |
| z6 Mokattam | COVERED | 0 | 1 | 0 |
| z7 Maadi | COVERED | 1 | 1 | 2 |
| z8 Helwan | COVERED | 1 | 2 | 1 |
| **z9 Obour** | **none** | 0 | 1 | **1** |
| **z10 Shrouk** | **none** | 0 | 1 | 0 |

**Three active zones have no template: 3, 9, 10.** The decisive precedent is **zone 3
(Giza)**: an active zone with **2 live orders** and **no template**, which operators handled
by manually creating **DG-004**. So in this system "uncovered by a template" demonstrably
does **not** mean "not served" — it means the configuration is incomplete and operators are
compensating by hand.

That makes zone 9 a **configuration gap, not a business exclusion** — but *whether Obour
should be commercially served* is nowhere in the data, so the final call is yours.

**DECISION REQUIRED (one of):**
- **(a) Obour IS served** → attach zone 9 to a template. **but resolve the §6 zone-overlap question first**; and
  because a zone may belong to only one template (`test_template_zone_exclusivity_remains_enforced`),
  you must say *which* route serves it.
- **(b) Obour is NOT served** → then zone 3 and zone 10 need the same ruling, and the fix is
  a user-visible "zone not served / no active template coverage" state. The backend half of
  that now exists (`uncovered_zones`); the UI half is not built.

I did **not** hardcode zone 9, special-case ORD-00007, create a group for it, or bypass the
template engine.

---

## 5. Template coverage

Unchanged by me. The gap is that **template coverage is not validated for completeness
against active zones** — nothing warns that zones 3, 9 and 10 hold or may hold work no
template can reach. `uncovered_zones` now reports it at each wave start, which is the
smallest honest step; a proactive configuration check is a separate change.

---

## 6. Manual Group handling — **STOP (no matching criteria exist)**

### PROVEN BEHAVIOUR — the sweep TAKES OVER zones from the Group that held them

I got this wrong twice before the tests corrected me, and I am recording the sequence because
the final answer is not what any of the intermediate readings suggested.

```
distribution_slot_zones is UNIQUE (window, warehouse, zone)
        │
        ▼
sweep creates a Group for a Template, then attaches the Template's zones
        │  a zone is already held by an OPERATOR Group
        ▼
QueryException (or DistributionException) inside applyToNewGroup()
        │
        ▼
ensureGroupForTemplate() catches QueryException, re-finds by (wave, template)
        │  the holder is an operator Group carrying NO template → findGroup() returns null
        ▼
        throw $e;              ← the RETHROW that looked dangerous
        │
        ▼
listener has no try/catch, and runs INSIDE WavePreparationService's DB::transaction
        │
        ▼
...would roll the wave back — BUT THIS IS NOT WHAT HAPPENS (see below)
```

**WHAT THE TEST PROVED.**
`test_a_manual_group_holding_a_template_zone_yields_a_zoneless_duplicate` builds the exact
live shape: an operator Group (template NULL) already holding Maadi, an active Template also
covering Maadi, eligible work in Maadi, then the real `WavePreparationStarted` event.

Observed, with both Groups named in the failure output before I corrected the assertion:

```
groups after the sweep:  OPS-a196a               (operator, template NULL)  → 0 zones
                         MORNING-20260826-426E   (sweep-created)            → 1 zone
distribution_slot_zones rows for Maadi: 1        ← ONE row, re-pointed
```

So:
- **No exception.** The wave transition is safe. The rethrow prediction was wrong.
- **A second Group is created for a scope the first already owns**, in silence.
- **The Zone is MOVED, not duplicated.** `assignZoneToSlot()` re-points the existing row at
  the new Group, so the unique index never fires — and the **operator Group is left with
  zero Zones**. The collector routes Orders by Zone, so that operator's Group stops
  receiving work, with nothing said.

The exposure is live: operator Groups hold zones 7, 2, 1, 3, 8 and active Templates cover
7 / 1 / 2 / {6,7,8} / {4,5}, so **four of five Templates overlap a zone an operator Group
already holds**. Nothing has fired yet only because eligibility is 0 for all of them — the
`$eligibleCount() < 1` skip happens *before* the create attempt.

**Why §3 must still wait:** attaching zone 9 to `Zq` [6,7,8] gives `Zq` eligible work, and
its overlap on zones 7 and 8 would **strip those zones from DG-001 and DG-005** at the next
wave start. Of the five, only `Zz` [4,5] is overlap-free today.

### I TRIED THE OBVIOUS FIX. IT BROKE CERTIFIED BEHAVIOUR.

Having proven the empty shell, I implemented what looked like a decision-free fix: refuse to
create a Group when **every** Zone of its Template is already owned by another Group in the
same window+warehouse. Creating something that can never receive an Order seemed
indefensible, and refusing is not "reusing the operator Group", so no ownership rule was
needed.

**It broke three certified tests** in `DistributionWaveTriggersAndBoardIsolationTest`:

```
test_three_waves_on_one_day_each_get_their_own_group      Failed asserting that null is not null
test_the_active_board_shows_only_the_active_waves_group   Failed asserting that null is not null
test_the_map_endpoint_group_overlay_matches_the_board     Failed asserting that null is not null
```

**Why, and this is the important part:** when a new Wave starts, the *previous* Wave's Group
is still in the same window and still holds those Zones. The certified rule is that each Wave
gets its **own** Group anyway — so "all my Zones are already owned" is the NORMAL state at
every wave rotation, not an error. My guard refused exactly the case the system is built to
allow.

**Therefore: zone ownership is NOT a valid matching criterion.** It cannot tell
"the next Wave's own Group" (certified, must create) from "a duplicate beside an operator
Group" (the defect, must not). The two are indistinguishable by zones alone.

I reverted the guard. Behaviour is exactly as it was.

**What I kept is detection, not enforcement:** `zone_conflicts` now names any Group the sweep
creates whose Zones are all already held. The empty shell still happens — but it is no longer
silent, and the log says which Template produced it. That is the most this task can honestly
deliver without the ownership decision.

### The STOP: no canonical matching criteria exist

§4.3 asks the sweep to reuse rather than duplicate when an existing group represents the
same operational scope. **Operator groups carry `distribution_group_template_id = NULL`**
(the existing test says so in as many words: *"Operator-created Groups carry no Template, so
many may exist in one Wave"*). So:

- **Template-based matching is impossible** — there is no template identity to match on.
- **Zone-based matching would be a new rule**, and it would contradict
  `test_operator_created_groups_are_not_constrained_by_the_invariant`, which deliberately
  puts operator groups *outside* the invariant.

So I cannot define the criteria without overturning a certified decision — and the failed
attempt above proves that is not a theoretical worry.

**DECISION REQUIRED.** The distinguishing fact has to come from somewhere other than zones.
The two candidates:

- **(a) Wave ownership.** If operator Groups were stamped with their Wave (§4.1), the sweep
  could tell "a Group from THIS wave already covers this scope" from "a Group from a previous
  wave". That makes §4.1 a prerequisite for §4.3, not a separate nicety.
- **(b) Prevent the overlap at configuration time** — refuse to attach a Zone to a Template
  while an operator Group in the current window holds it, mirroring the template-level zone
  exclusivity that already exists. Cheaper and safer, but it constrains operators.

I recommend **(b)** as the smaller change, with **(a)** as the correct long-term model.

### §4.1 / §4.2 — a real, narrow defect I did not fix

The manual creation endpoint never sets `preparation_wave_id`:

```php
$slot = VirtualCapacitySlot::query()->create([
    'company_id' => $w->company_id,
    'distribution_window_id' => $w->id,
    ...$validated,          // warehouse_id, code, name, capacities — no wave
]);
```

So a group created during an active wave does **not** know its wave (DG-004/DG-005 are
live proof: created 2026-08-25 21:53/21:54 while wave 009 existed in `collecting`). Fixing
it means deciding what "during an active wave" means — `collecting` too, or only
`preparing`? That is the same ownership question as above, so I left it with the decision
rather than guessing. **I did not back-fill DG-004/DG-005**, per your instruction.

---

## 7. Window canonicalisation — decision EXTRACTED, not applied

§6 says to extract the decision from the architecture rather than opine. The architecture
states it plainly, in `DistributionWindowService::resolvePlanningWindow()`'s own docblock:

> *"A Preparation Wave SELECTS which window is the current operational cycle. It is not a
> prerequisite for reading Distribution, and the schema agrees: this table is keyed
> (company_id, window_date) with no preparation_wave_id and no warehouse_id"*

**So: the wave-anchored resolver is canonical for the planning cycle, and `currentWindow()`
is explicitly the no-wave fallback** (*"with no resolvable cycle we still resolve the
EXISTING window through the non-creating read"*). The window's *identity* is the date; the
wave *selects* which one is operational.

**I did not apply it.** Making every Distribution read on this path go through the canonical
resolver requires auditing every caller of `currentWindow()` / `windowFor()` /
`resolveIngestionWindow()`, and each is load-bearing for a different surface (ingestion,
board, dialog). That is a larger change than this task's remaining scope, and getting it
half-right would split the reads differently rather than fewer times.

Also worth recording: **no window exists for 08-24/25/26** because the only creating branch
runs when the anchor resolves to NULL, and the anchor always finds the 2026-08-21 window —
it holds all of the wave's orders. **Distribution is self-pinned to that window.** Fixing
this is entangled with the decision above, because "create today's window" and "follow the
wave's window" give different answers here.

---

## 8. KPI source — **STOP: NOT DETERMINED**

I could not find the source. `active_groups`, `needs_attention` and `assigned_orders` appear
in **no** Distribution controller and in no Distribution type file. The
`unassigned_orders` I did find (`DistributionWindowController` ~:898) is a **per-group**
count, not the screen's top-level card.

So I cannot state what "unassigned = 0" means, and per your instruction I did **not** change
KPI logic. **This is a STOP condition you listed ("KPI source غير واضح").**

What is certain from the data: `COUNT(distribution_window_orders WHERE virtual_slot_id IS
NULL)` = **1** (ORD-00007). The card shows 0. Those are different definitions; which one is
intended is undetermined.

---

## 9. Idempotency

Already guaranteed by `(preparation_wave_id, distribution_group_template_id)` UNIQUE plus
`findGroup()`, and already covered by existing tests (`test_wave_start_is_idempotent`,
`test_manual_then_automated_start_on_one_wave_creates_one_group`,
`test_duplicate_template_and_wave_is_impossible`). My new test asserts it **through the log**,
so the observable tally is proven idempotent too: first sweep `created=1`, second
`created=0, reused=1`, and one group total.

**Note on the NULL semantics:** MySQL treats NULL as distinct in a unique index, so the
`(wave, template)` guard does **not** constrain operator groups (both columns NULL). That is
by design per the certified test — and it is also why the P1 in §6 is reachable.

---

## 10. Tests

**New file** `backend/tests/Feature/Logistics/DistributionSweepClosureTest.php`:

| Test | Covers |
|---|---|
| `test_the_sweep_logs_its_tally_when_it_creates_a_group` | §2, §9.1 |
| `test_eligible_work_in_an_uncovered_zone_is_named_not_silently_skipped` | §2, **§9.3** |
| `test_a_second_sweep_creates_nothing_and_reuses_instead` | §9.4, §9.5 |
| `test_a_manual_group_holding_a_template_zone_does_not_break_the_wave` | **the P1** |

**Already covered by existing suites — I did not duplicate them:**
`DistributionWaveTriggersAndBoardIsolationTest` (24 tests) and
`DistributionDailyGroupWaveLifecycleTest` (24 tests) already pin §9.1 (both event paths),
§9.2, §9.4, §9.5, §9.6 and §9.7.

**Not written, because they depend on the pending decisions:** §9.8 (canonical window),
§9.9 (ungrouped count — needs the KPI definition), §9.10 (ORD-00007 determinism — needs the
zone-9 ruling).

**Result: 4 tests, 16 assertions, ALL GREEN.**

The fourth test is the one that changed my mind: it was written to demonstrate a
wave-blocking defect and instead demonstrated the opposite. It now stands as the record of
what the system really does when a template and an operator group overlap on a zone.

---

## 11. Regression

**Distribution suites: 48/48 green**, run together with the new file
(`DistributionDailyGroupWaveLifecycleTest` 24, `DistributionWaveTriggersAndBoardIsolationTest` 24).

These are the suites my reverted guard broke, so their return to green is the proof the
revert is complete and behaviour is byte-identical to before this task:

```
with the guard:      52 tests, 3 failures  (three_waves_on_one_day, active_board, map_overlay)
after the revert:    52 tests, 0 failures
```

**Wider sweep — 71 tests, 899 assertions, 1 failure.**

The single failure is `TripLifecycleCertificationTest::test_02` (422 where 200 expected), and
it is **not** caused by this task. I isolated it rather than assuming:

- It fails when run **alone** too, so it is not combined-run ordering.
- The failing call is `POST /api/driver/loading/products/{id}/confirm` —
  `DriverLoadingController`, a file neither of my two changes touches.
- `LoadingCustodyService.php` has an mtime of **11:08**, the newest of the relevant files and
  **later than the run in which that suite was 14/14 green**.
- The confirm path now performs an **atomic warehouse→vehicle stock transfer**
  (`TASK-DRIVER-CUSTODY-INVENTORY-TRANSFER-001`) and returns **422** on `RuntimeException` —
  which is what an insufficient-stock refusal raises now that `allow_negative_stock` is
  honoured.
- My certification fixture **stocks no inventory at all** (`grep -c` for stock terms in that
  file returns **0**).

So a concurrent task made driver-confirm require real warehouse stock, and my earlier
certification fixture never created any. **It is a fixture gap in that suite, exposed by
another agent's change** — a two-line fix (stock the products before confirming), which I did
**not** apply here because this brief says no unrelated fixes and that suite belongs to a
different task.

**Consequence to record:** the claim "TripLifecycleCertificationTest 14/14" in
`TASK-TRIP-LIFECYCLE-AND-VEHICLE-CUSTODY-BRIDGE-CERTIFICATION-001-REPORT.md` is now **stale**;
that suite is **13/14** against the current tree. The lifecycle behaviour it certifies is
unchanged — only the fixture's assumption about stock is.

Everything else in the wider sweep is green, including the preparation lifecycle, group
finalization, loading custody and driver suites.

---

## 12. Browser verification

**NOT PERFORMED.** §10 directs the work to an isolated test DB first, and browser
verification remains blocked on driver authentication (no session exists; the DEV password
was discarded by design). Nothing here is claimed as browser-verified.

---

## 13. Files changed

| File | Change |
|---|---|
| `Modules/Logistics/Distribution/Domain/Services/DailyGroupLifecycleService.php` | itemised skip reasons + `uncoveredZones()` + `SKIP_NO_ELIGIBLE_WORK`; existing three counts unchanged |
| `Modules/Logistics/Distribution/Application/Listeners/StartWaveDistributionGroupsListener.php` | logs `distribution.wave_sweep` |
| `tests/Feature/Logistics/DistributionSweepClosureTest.php` | new (4 tests) |

**Not touched:** wave scheduler, `WavePreparationService`, wave boundaries/timezone,
`WavePreparationStarted`, KPI logic, window resolvers, templates, group creation endpoints,
ORD-00007, DG-004, DG-005, Wave 009.

---

## 14. Live-data status

**No live business data was created, modified or deleted.** All work ran against
`ecos_dev_test`. ORD-00007, DG-004, DG-005 and Wave 009 are exactly as found.

**What would need a live mutation, if you approve it** — stated so you can decide, not
executed:
1. Attaching **zone 9** to a template (a `distribution_group_template_zones` row) — and per
   §6 only a collision-free template is safe today.
2. Optionally back-filling `preparation_wave_id` on DG-004/DG-005 — **I recommend against
   it**; you told me not to auto-link, and the ownership rule is undecided.

---

## 15. BEFORE / AFTER

| | BEFORE | AFTER |
|---|---|---|
| Sweep result | discarded by the caller | one structured INFO line per wave start |
| A silent decline | indistinguishable from "never ran" | `skipped_templates` names the template, its zones and the count |
| Work no template can reach | invisible | `uncovered_zones` names the zone and its count |
| Skip reason | inferred by reading code | `SKIP_NO_ELIGIBLE_WORK`, named |
| Manual group / template zone overlap | **unknown; rolls the wave back** | **documented and pinned by a test** |
| Counts `created`/`reused`/`skipped` | present | **unchanged** |

---

## 16. Remaining issues

1. **PROVEN: the sweep creates a zone-less duplicate Group** when a Template's zones are
   already owned by another Group. It does not throw — it produces an empty shell that can
   **take the zones over**, leaving the previous holder empty. **The obvious fix was
   attempted and reverted**: refusing breaks the certified multi-Wave rule, because "zones
   already owned" is also the normal state at every wave rotation. It is now *reported*
   (`zone_conflicts`) but not prevented. Fix needs the §6 ownership decision. Four of five
   live Templates are exposed — DG-001, DG-003 and DG-005 would be the ones emptied.
2. **Zone 9 / 3 / 10 coverage is a business decision** the system does not record.
3. **Manual groups carry no wave and no template** — no matching criteria exist without
   overturning a certified test.
4. **Two window resolutions**; canonical identified from the docblock but not applied. No
   window exists for 08-24..08-26 and the anchor keeps it that way.
5. **KPI sources unknown** — "unassigned = 0" vs `COUNT(virtual_slot_id IS NULL) = 1`.
6. **ORD-00007 remains ungrouped**, deliberately. It is not hidden: the sweep now reports
   zone 9 as uncovered at every wave start.

**This task is NOT complete**, and per your closing note I am not calling it complete
because ORD-00007 appeared in a group — it did not, and it should not until (2) is decided
and (1) is fixed.

---

**STOP.** Awaiting decisions on §4 (zone 9), §6 (manual-group ownership + the P1 fix),
§7 (window canonicalisation) and §8 (KPI sources).

# TASK-DISTRIBUTION-WAVE-CLOSING-GROUP-SWEEP-DIAGNOSIS-001

## STATUS

**DIAGNOSIS COMPLETE. Nothing was changed.**

The headline is that **the sweep did run**, and the lifecycle is healthier than the screen
suggests. The reported symptom is two separate problems wearing one costume: a real
grouping gap affecting **exactly one order**, and a **window-resolution split** that makes
the screen contradict itself.

Code: **UNCHANGED** · DB: **UNCHANGED** · Groups: **none created/edited/finalized**
Orders: **none assigned** · Migrations: **NONE** · Commit/Push: **NONE**

Date: 2026-08-26 · Branch: `develop`

---

## A. Current Wave

| Field | Value |
|---|---|
| id | `01a0391d-0e88-701a-98e2-286623066eb7` |
| wave_number | **PREP-202608-000009** |
| status | **`preparing`** |
| planning_date | 2026-08-25 |
| starts_at | 2026-08-25 13:30:00 UTC → **16:30 Cairo** |
| intake_closes_at | 2026-08-26 05:00:00 UTC → **08:00 Cairo** |
| ends_at | 2026-08-26 13:00:00 UTC → **16:00 Cairo** |
| **started_at** | **2026-08-26 05:00:01** (`started_by = system`) |
| completed_at / cancelled_at | NULL / NULL |
| orders_count | **13** |
| created_at | 2026-08-25 13:30:00 |

**Timezones:** `app.timezone = UTC`; company timezone = **Africa/Cairo** (UTC+3). All
stored values above are UTC.

**Configuration comparison:** `config('preparation.wave.starts_at' | 'intake_closes_at' |
'ends_at')` are all **NULL** — the file-config keys the previous diagnosis compared against
do not drive this wave. The wave's boundaries are the frozen stored values, and they are
**identical to the ones the previous diagnosis flagged** (16:30 / 08:00 / 16:00 Cairo).

So the previous finding is still factually present — **but it is not the cause of this
symptom**, because the boundary that mattered fired correctly: the wave crossed its
08:00 Cairo intake cutoff and transitioned on time.

---

## B. Lifecycle Trace

| Step | Canonical owner | Result |
|---|---|---|
| Scheduler | `routes/console.php:58` — `Schedule::command('wave:run-scheduler')->everyMinute()` → `RunWaveSchedulerCommand` | **PASS** |
| Wave transition | `WavePreparationService` (`WaveEngine`) — `Collecting → Preparing`, stamps `started_at`/`started_by` | **PASS** — 05:00:01, `system` |
| Event dispatch | `WavePreparationService.php:74` — `event(new WavePreparationStarted(...))`, **unconditional** once the transition commits | **PASS** |
| Listener registration | `LogisticsDistributionServiceProvider.php:39-65` — `WavePreparationStarted => [StartWaveDistributionGroupsListener, 'handlePreparationStarted']`, bound via `Event::listen` | **PASS** |
| Distribution sweep invoked | `StartWaveDistributionGroupsListener::sweepForWave()` → `DistributionWindowService::resolveOrCreatePlanningWindow()` + `DailyGroupLifecycleService::sweepWave()` | **PASS (executed)** |
| Sweep produced a group | `sweepWave()` → `ensureGroupForTemplate()` | **FAIL — 0 created, 0 reused, 5 skipped** |

### Answers to C3 A–H

- **A. Did the wave transition happen?** **YES.** `status = preparing`, `started_at = 2026-08-26 05:00:01`, `started_by = system`.
- **B. Did the event fire?** **YES.** The dispatch at `WavePreparationService.php:74` is unconditional and sits in the same `DB::transaction` as the status update. The status update is committed, so the dispatch was reached.
- **C. Was the sweep invoked?** **YES.**
- **D. Was it successful?** It **completed without error** but **produced nothing**.
- **E. Was it skipped?** Not the sweep — but **every one of the 5 templates was skipped inside it**.
- **F. Why skipped?** `ensureGroupForTemplate()` returns `null` when `$eligibleCount() < 1`. The eligible-per-zone map contained **only zone 9**, and **no active template covers zone 9** (proof in §E).
- **G. Exception thrown?** **NO** — and this is provable, not assumed. The listener is **synchronous** (no `ShouldQueue`) with **no `try`/`catch`**, and it is invoked from inside the wave's `DB::transaction`. An unhandled throw would have rolled the wave back; the wave is committed as `preparing`. Independently: **zero** wave/distribution/sweep/group lines in `laravel-2026-08-26.log`. (The `DecryptException: The MAC is invalid` entries at 04:00/05:00 are **unrelated** — `ProviderHealthMonitor` decrypting a Marketing `app_secret` in `CheckProviderHealthJob`. They do prove a Redis queue worker is live on `health,default`.)
- **H. Idempotently skipped believing it had run?** **NO.** `findGroup($waveId, $templateId)` is keyed on **wave**; no group carries wave 009, so nothing looked "already done". The skip was purely the `< 1` eligibility test.

---

## C. Distribution Reconciliation

`distribution_window_orders` holds **exactly 13** rows — matching the KPI — and **all 13**
are in window `01a021a0…` (**window_date 2026-08-21**, status `cutoff_reached`).
All 13 are also active members of wave 009 (`preparation_wave_orders` overlap = **13/13**).

| Order | Eligible | Zone | Group | Group's wave | Reason if ungrouped |
|---|---|---|---|---|---|
| ORD-00001 | ✅ | 2 | DG-001 | PREP-…005 | — |
| ORD-00002 | ✅ | 7 | DG-001 | PREP-…005 | — |
| ORD-00006 | ✅ | 7 | DG-001 | PREP-…005 | — |
| **ORD-00007** | ✅ | **9 (Obour)** | **— NONE —** | — | **No active template covers zone 9** |
| ORD-00009 | ✅ | 2 | DG-001 | PREP-…005 | — |
| ORD-00010 | ✅ | 8 | DG-005 | **NULL** | — |
| ORD-00011 | ✅ | 1 | DG-003 | PREP-…005 | — |
| ORD-00012 | ✅ | 2 | DG-001 | PREP-…005 | — |
| ORD-00013 | ✅ | 3 | DG-005 | **NULL** | — (zone 3 ≠ DG-005's zone 8 — manual move) |
| ORD-00014 | ✅ | 3 | DG-004 | **NULL** | — |
| ORD-00016 | ✅ | 2 | DG-001 | PREP-…005 | — |
| ORD-00018 | ✅ | 2 | DG-001 | PREP-…005 | — |
| ORD-00019 | ✅ | 2 | DG-001 | PREP-…005 | — |

**Canonically, exactly ONE order is ungrouped — ORD-00007** (`virtual_slot_id IS NULL`).

A caveat I am flagging rather than papering over: I did **not** pin the source of the
top-level KPIs (eligible / assigned / unassigned / active groups / need attention). The
field names `active_groups` and `needs_attention` do not appear in any Distribution
controller, so those numbers come from a surface I have not traced. I therefore do **not**
claim the "unassigned 0" KPI is wrong — only that it does not equal
`COUNT(virtual_slot_id IS NULL)`, which is 1. Pinning that query is the one open thread in
this diagnosis, and it is worth doing before anyone trusts those cards.

**Nothing was written at the transition.** `MAX(updated_at)` across all 13 rows is
**2026-08-26 00:01:43** — five hours *before* the wave started at 05:00:01. The last
`assignment_source = auto` row dates from **2026-08-23 02:37:20**.

---

## D. Existing Groups

All five groups sit in the **2026-08-21** window. `closed_at` is NULL on all.

| Group | Wave | Created | Updated | Orders | Zones | Trip |
|---|---|---|---|---|---|---|
| DG-001 | PREP-…**005** | 2026-08-21 00:04:31 | 2026-08-22 23:05:01 | 8 | 7, 2 | TRP-001 `loading` |
| DG-003 | PREP-…**005** | 2026-08-23 01:07:17 | = created | 1 | 1 | TRP-002 `loading` |
| **DG-004** | **NULL** | 2026-08-25 21:53:30 | **= created** | 1 | 3 | TRP-003 `loading` |
| **DG-005** | **NULL** | 2026-08-25 21:54:05 | **= created** | 2 | 8 | — |
| DG-TPL-VERIFY | PREP-…005 | 2026-08-22 22:48:23 | 2026-08-22 23:04:58 | 0 | — | — |

**Groups belonging to wave 009: ZERO.**

- **DG-004 / DG-005 were not created by the sweep.** Both were created at 2026-08-25
  21:53/21:54 — **~7 hours before** the wave transitioned — and both carry
  `preparation_wave_id = NULL`. The sweep always stamps the wave (DG-001/003 carry
  wave 005). NULL is the signature of the **manual operator** path.
- **They were never updated.** `updated_at == created_at` on both.
- **Should the sweep have expanded them?** It could not have. `findGroup($waveId, …)` is
  keyed on wave, so a NULL-wave group is **invisible to every wave**. Had eligibility been
  non-zero, the sweep would not have expanded DG-004/DG-005 — it would have created
  **duplicates beside them**. That is a latent defect (§F note).
- **Does the sweep support updating existing groups?** Only in the weak sense:
  `ensureGroupForTemplate()` returns an existing group (`reused`) instead of creating a
  second one. **It does not add orders to it.** The service says so itself: *"It owns no
  zone logic, no capacity logic and no assignment logic"* and *"That split is why no
  assignment logic appears here."* Order→group assignment belongs to the **collector**.
- **Immutability:** groups are released by `CloseWaveDistributionGroupsListener`, which
  nulls `virtual_slot_id` on the closing group's orders. No group here is closed.

---

## E. "No group" Root Cause

### E.1 — The one genuinely ungrouped order

**ORD-00007 → classification D.** *Eligible, sweep ran, grouping logic skipped it.*

The chain, with evidence:

1. Its `assignment_reason` reads **"City changed from [Maadi] to [Obour City]; zone
   re-resolved."** — the city change moved it to **zone 9 (Obour)** and left
   `virtual_slot_id = NULL` (`assignment_source = manual_move`).
2. The sweep **does** see it: `collectedButUngroupedByZone()` counts window rows
   `WHERE virtual_slot_id IS NULL AND distribution_zone_id IS NOT NULL`, so the eligible
   map was `[9 => 1]`.
3. The sweep is **template-driven**. Live template coverage:

   | Template | Zones |
   |---|---|
   | Morning Cairo v2 | 7 |
   | Zt | 1 |
   | Zn | 2 |
   | Zq | 6, 7, 8 |
   | Zz | 4, 5 |

   **Zone 9 appears in `distribution_group_template_zones` zero times.**
4. So for all 5 templates the closure summed to **0**, `$eligibleCount() < 1` held, and
   each returned `null` → **skipped ×5**.

**It is also invisible to the collector.** `eligibleUnassignedOrders()` *"excludes anything
already collected into a window"* — and ORD-00007 already has a window row. So it can be
rescued by **neither** mechanism: the sweep cannot build a group for its zone, and the
collector will not look at it again. **It is stranded by construction, not by a bug in
either component.**

### E.2 — Why the screen shows *multiple* "No group" rows

Not a data problem — a **window-resolution split**. Two reads on the same screen disagree
about which window is "current":

| Resolution | Code | Resolves to |
|---|---|---|
| **Wave-anchored** | `resolvePlanningWindow()` — anchors on the window holding the most of this wave's orders | window **2026-08-21** → sees all **13** orders |
| **Date-based** | `currentWindow()` — `whereDate('window_date', today)` | **2026-08-26 → NULL** |

**There is no window for 2026-08-24, 08-25 or 08-26.** The newest is **2026-08-23**. So
every date-based read returns nothing, which is why the manual dialog says *"No zone has
orders in this window yet."* — for **today's** window that statement is literally true,
while 13 orders live in the 08-21 window.

**Why no newer window was ever created:** the only creating path is
`resolveOrCreatePlanningWindow()`, and it creates **only if the anchor resolves to NULL**.
The anchor always finds the 08-21 window, because that window holds all of the wave's
orders. **Distribution is therefore permanently pinned to the 2026-08-21 window** for as
long as those assignments exist — a self-sustaining loop.

The per-row "No group" column and the "Active groups: 2" count are **consistent with** the
UI resolving group membership against a narrower window/wave scope than the order list
uses — the two groups it can see are exactly the two with `preparation_wave_id = NULL`.
I am stating this as the hypothesis the evidence fits, **not** as a proven mechanism: I
traced the create-group dialog's source (`zonesWithoutGroup()` ← `ordersAwaitingGroup()`)
but not the group-column resolver. The window split in the table above **is** proven.

### Classification of every order

- **ORD-00007 → D** (eligible, sweep ran, grouping logic skipped it — no template for zone 9).
- **The other 12 → E** (already belong to a valid group). They are *displayed* as
  "No group" by the read-model split in E.2, not ungrouped in the database.
- **None** is A, B, C, or G.

---

## F. Root Cause Classification

## **D — Sweep executed but eligibility/grouping logic excluded valid orders.**

The sweep ran on time and without error. It found the one order that needed a group and
skipped every template because the sweep can only create a group for a zone some **active
template** covers, and **zone 9 (Obour) is covered by none**.

Not chosen, and why:
- **A/B/C** — disproved: the wave transitioned, the event is dispatched unconditionally in
  the committed transaction, and the listener is synchronous and unguarded.
- **E** — true but downstream: DG-004/005 were never updated, but the sweep never had
  non-zero eligibility to act on, and expansion is the collector's job, not the sweep's.
- **F** — real and worth its own ticket, but **latent**: zero groups carry wave 009 and
  DG-004/005 carry NULL, so `findGroup()` cannot see them. This did not cause the symptom;
  it would have caused **duplicate groups** if eligibility had been non-zero.

### Two defects this exposes, neither acted on

1. **The window pin.** `resolvePlanningWindow()`'s anchor keeps Distribution on the
   2026-08-21 window indefinitely, while `currentWindow()` looks for today and finds
   nothing. Every date-based surface reads empty. This is the same family as the frozen-
   boundary problem, but it is a **new, distinct** issue in `DistributionWindowService`.
2. **NULL-wave groups are invisible to the sweep.** Manually created groups carry
   `preparation_wave_id = NULL`, so `findGroup($waveId, …)` never matches them and a future
   sweep with real eligibility would create a rival group for the same template.

**Relation to TASK-PREPARATION-WAVE-SCHEDULER-RUNTIME-DIAGNOSIS-001:** the frozen
boundaries it found are still stored on wave 009 (16:30 / 08:00 / 16:00 Cairo), but this is
**not the same root cause and not a downstream effect of it** — the cutoff fired correctly
at 08:00 Cairo. This is **a new Distribution-side problem** (option 2 of §8).

---

## G. Recommended Fix — NOT IMPLEMENTED

Smallest canonical fixes, in dependency order. **None applied.**

1. **Unblock ORD-00007 (configuration, not code).** Attach **zone 9 (Obour)** to an active
   `distribution_group_template`. That is the sweep's only input for deciding a group may
   exist, and it needs no code change. *Verify first* whether Obour is intended to be
   served at all — if it is not, ORD-00007 is class **B** (correctly excluded) and the real
   fix is to surface it as "zone not served" instead of a silent "No group".
2. **Make the sweep's skip observable.** `sweepWave()` already returns
   `['created','reused','skipped']` and the caller discards it. Log it. A sweep that
   silently reports nothing is why this took a database forensic to answer.
3. **Reconcile the two window resolutions** — one canonical resolver for the planning
   surface, so the KPI row, the order list and the create-group dialog cannot disagree.
   Decide deliberately whether the operational window should follow the wave anchor or the
   calendar; today it does both.
4. **Stamp `preparation_wave_id` on manually created groups** so `findGroup()` can see them
   and a later sweep reuses rather than duplicates.

I recommend **(2) before (1)**: it is the cheapest change, is pure observability, and would
have made this diagnosis a one-line log read.

---

## H. Live Data

**No business data was modified.**

No code, no migration, no DB write, no group created/edited/deleted/finalized/regenerated,
no order assigned, no wave or trip mutated. Every statement above comes from read-only
`SELECT`s and source inspection.

---

**STOP.** Diagnosis only, as instructed. Awaiting review before any implementation.

# TASK-PREPARATION-DAILY-WAVE-LIFECYCLE-001 — Diagnostic & Design Report

**Date:** 2026-08-12
**Branch:** `develop` · **HEAD:** `6149875bd8a01820116b5deacbbfb8ef0e51cc05`
**Status:** **DIAGNOSTIC COMPLETE — ROOT CAUSE PROVEN. NO PRODUCTION CHANGES MADE.**
**Database inspected:** `ecos_dev` (read-only `SELECT`/`SHOW` only). MAIN untouched.

---

## 1 — Executive Summary

**The daily-wave architecture already exists and is broadly correct.** `wave:run-scheduler` runs every
minute and implements the full intended cycle: open collection → attach eligible orders → start
preparation → close and rotate to the next day.

**It is deadlocked, and it will never self-heal.**

Root cause is **Cause F — the current-wave selection logic is date-blind**, compounded by a
configuration value that removes the only escape path:

1. `WaveManager::hasActiveWave()` filters on company + warehouse + `status IN (collecting, preparing)`
   and has **no `planning_date` predicate**.
2. `PREP-202607-000002` (planning_date **2026-07-30**) is `collecting`, for the *exact* company and
   warehouse of the one active `WaveEngineConfiguration`.
3. Scheduler Step 1 is gated on `! hasActiveWave(...)` → **false forever** → no wave is ever created.
4. The only escape is Step 4 (rotate), which requires `status === Preparing`; reaching `Preparing`
   requires `auto_move_to_preparing`, and the live config has it **`= 0`**.

So the wave is pinned in `collecting` permanently. 13 days and counting. This is a **stable deadlock**,
not a missed cron run.

**The fix is narrow and half-written already:** `WaveManager::getActiveWaveForDate()` — the date-aware
variant — **already exists** at `WaveManager.php:26-33` and has **zero production callers**.

Three further findings change the design, and one contradicts an assumption in the brief:

- **There is no Preparation Window anywhere in the system.** No cutoff, no operational-day concept.
  `planning_date` is the raw calendar date at every automatic write site. The brief's instruction to
  keep "existing Window rules authoritative" cannot be honoured — **there are none** (§5).
- **Rotation is relative, not absolute.** `rotateWave()` sets `planning_date + 1 day` from the *closed*
  wave, never `today()`. Even fully unblocked, the engine needs **13 sequential rotations** to reach
  2026-08-12 (§6.2).
- **A second tenant has no wave engine configuration at all** — 2 companies operate warehouses, 1
  config row exists. That tenant gets no waves regardless (§2.4).

**Two claims I made earlier in this investigation were wrong and are corrected in §2.5** — the
"duplicate" sessions are correct per-tenant numbering, and `withoutOverlapping()` is not broken.

---

## 2 — Current Runtime State (Part 2)

All figures from `ecos_dev` on 2026-08-12.

### 2.1 `preparation_waves` — 2 rows total

| wave_number | planning_date | status | created_at |
|---|---|---|---|
| `PREP-202607-000002` | **2026-07-30** | **`collecting`** | 2026-08-07 02:59:04 |
| `PREP-202607-000001` | 2026-07-29 | `closed` | 2026-07-29 19:40:00 |

**No wave exists for 2026-08-12.** Answer to Part 1 Q10: **NO.**

Note the anomaly: the July 30 wave was **created on August 7**, so `planning_date` was *not* derived
from creation time — consistent with a seed/restore, not a scheduler run.

`preparation_wave_orders` is **EMPTY** — zero orders have ever entered any wave.

### 2.2 The blocking wave matches the config exactly

```
wave      company_id = 019f4e1c-2d1e-719d-873c-75779ab67251
          warehouse_id = 019f4e1c-2e1b-7269-bfbb-8a414cb07cab   status = collecting
config    company_id = 019f4e1c-2d1e-719d-873c-75779ab67251
          warehouse_id = 019f4e1c-2e1b-7269-bfbb-8a414cb07cab   is_active = 1
```

This identity is what makes the deadlock deterministic rather than incidental.

### 2.3 The one active `WaveEngineConfiguration`

| field | value | assessment |
|---|---|---|
| `collection_start_time` | `00:00:00` | |
| `preparation_start_time` | `23:59:00` | seeded test value |
| `wave_end_time` | `23:59:59` | **59-second preparation window** |
| `auto_create` | `1` | |
| `auto_assign_orders` | `1` | |
| **`auto_move_to_preparing`** | **`0`** | **removes the only escape from `collecting`** |
| `eligible_order_statuses` | `["confirmed"]` | ⚠️ see §2.6 |
| `timezone` | `UTC` | |
| created/updated | 2026-07-16 | never revised |

### 2.4 Tenant coverage gap

`wave_engine_configurations` has **1 row total, 1 active**. But `preparation_sessions` shows **two
distinct companies** operating warehouses (`019f4e1c…` and `019fe003…`). The second tenant has **no
wave engine configuration**, so `RunWaveSchedulerCommand` never iterates it. Even a perfect deadlock
fix leaves that company with zero waves.

### 2.5 Two corrections to earlier statements in this investigation

**(a) The 08-09 / 08-10 sessions are NOT duplicates.** I initially read them as an idempotency defect.
The database disproves that:

| planning_date | session_number | company_id | warehouse_id |
|---|---|---|---|
| 2026-08-09 | `PS-20260809-0001` | `019f4e1c…` | `019f4e1c-2e1b…` |
| 2026-08-09 | `PS-20260809-0001` | `019fe003…` | `019fe016…` |

Different companies, different warehouses. `uq_preparation_sessions_company_number`
`(company_id, session_number)` **exists and is intact**, and the sequence is scoped per company. Two
tenants each correctly receive `PS-YYYYMMDD-0001`. This is **correct per-tenant behaviour**, not a
defect. The only residual issue is cosmetic: `session_number` is not globally unique, so a
cross-tenant operator view would show apparent collisions.

**(b) `withoutOverlapping()` is NOT broken.** A survey pass inferred `CACHE_STORE=array` from
`backend/.env:12` and concluded the scheduler mutex was inert. The **live container reports
`CACHE_STORE=redis`**, so the mutex functions. The `.env` value is overridden at runtime.

### 2.6 `preparation_sessions` — the real automation gap

13 rows, one per day **2026-07-30 → 2026-08-10**, every one `status='draft'` with **`waves_count = 0`**.

- **2026-08-11 and 2026-08-12 are missing entirely** — scheduler downtime (Docker was down), with **no
  catch-up** (§10).
- **`waves_count = 0` on all 13** is structural, not incidental: `createCollectingWave()` never sets
  `preparation_session_id`. The only writer of that column is `AddWaveToSessionAction`, reachable only
  from a manual `POST /sessions/{id}/waves`. **The Session track and the Wave track are two disconnected
  automations.**

⚠️ **Eligibility mismatch (pre-existing, flagged not fixed):** the config's
`eligible_order_statuses = ["confirmed"]`, but `PreparationSessionPolicy::defaultEligibleStatuses()`
returns `['new', 'in_progress']`, and its own docblock records that `confirmed` became inert after the
V3 lifecycle merge. So even once unblocked, `attachEligibleOrders()` would match **zero** orders. This
is consistent with `preparation_wave_orders` being empty.

---

## 3 — Existing Wave Creation Mechanism (Part 1 Q1–Q4)

**Two disconnected paths exist.**

| | Manual | Automatic (Wave Engine) |
|---|---|---|
| Entry | `POST preparation/waves` → `CreateWaveAction` | `wave:run-scheduler` (every minute) |
| Creates status | `draft` | `collecting` |
| Requires orders? | **YES** — `order_ids: required|array|min:1` | **NO** — always born empty |
| `planning_date` | from request body | `$today` in config timezone |
| Links to session? | via separate `AddWaveToSessionAction` | **never** |

**Q1 — how created:** `WaveLifecycleService::createCollectingWave()` inside `DB::transaction`, with a
`lockForUpdate()` guard on `(company, warehouse, planning_date, status IN active)`.
**Q2 — automatic or manual:** both, independently.
**Q3 — trigger:** the every-minute cron, gated on `WaveEngineConfiguration.is_active`.
**Q4 — depends on orders existing:** **no** on the engine path — empty waves are created by design.

---

## 4 — Existing Window Mechanism (Part 1 Q8)

The Wave Engine's four time gates are the closest thing to a window:

| Step | Gate | Config field |
|---|---|---|
| 1 Open collection | `time >= collection_start_time` **AND** `! hasActiveWave()` | `auto_create` |
| 2 Attach orders | status ∈ {collecting, preparing} | `auto_assign_orders` |
| 3 Start preparation | `time >= preparation_start_time` | `auto_move_to_preparing` |
| 4 Close + rotate | status == preparing **AND** `time >= wave_end_time` | — |

**Q8 — what happens after the window closes:** `rotateWave()` closes the wave and immediately creates
the next one at `planning_date + 1 day`. Late orders would flow into that next wave on the following
tick — **the intended behaviour in the brief is already implemented**. It simply never executes,
because Step 4 is unreachable while `auto_move_to_preparing = 0`.

---

## 5 — Operational Day Semantics (Part: "do not assume calendar date = operational day")

**Finding: there is no operational-day concept, and no Preparation Window, anywhere in the system.**

| Searched | Result |
|---|---|
| `preparation_window` / `prep_window` | **DOES NOT EXIST** (0 hits repo-wide) |
| `cutoff` / `cut_off` in the Preparation module | **DOES NOT EXIST** (0 hits) |
| `config/preparation.php` | **DOES NOT EXIST** |
| `companies.operational_day_start` | **spec-only** — `CONFIGURATION-OS-SPEC.md:93`, never implemented |
| `operational_day` in code | 11 hits, all in DemandAnalysis, all `= $date ?? now()->toDateString()` — a cosmetic label on an all-time aggregate, never used as a filter |

`planning_date` is assigned from the raw calendar date at every automatic site.

**So: calendar date == operational day == wave planning date, today, by default and not by design.**

The brief instructs that "existing Window rules must remain authoritative". There are none to preserve.
This is a **design gap**, and defining an operational-day cutoff is a **business decision** (§19 D1),
not something this task may invent.

Two dead configuration fields already anticipate the need:
`PreparationSessionPolicy.auto_create_time` (default `06:00:00`) and `auto_close_time` are both
**written by the policy controller and read by nothing**. The real trigger is hardcoded in
`routes/console.php` as `->dailyAt('06:00')`.

---

## 6 — Root Cause of the Missing Current-Day Wave (Part 2 A–G)

### 6.1 Primary — **Cause F: stale, date-blind current-wave selection**

`WaveManager.php:35-41`:

```php
public function hasActiveWave(string $companyId, string $warehouseId): bool
{
    return PreparationWave::where('company_id', $companyId)
        ->where('warehouse_id', $warehouseId)
        ->whereIn('status', self::ACTIVE_STATUSES)   // [collecting, preparing]
        ->exists();                                   // ← NO planning_date predicate
}
```

`RunWaveSchedulerCommand.php:64-75`:

```php
if ($config->auto_create
    && $time >= substr($config->collection_start_time, 0, 5)
    && ! $this->waveManager->hasActiveWave($config->company_id, $config->warehouse_id))  // ← :67
{
    $wave = $this->lifecycle->createCollectingWave($config->company_id, $config->warehouse_id, $today);
}
```

The 2026-07-30 wave is `collecting`, matches the config's company+warehouse, so the guard is **false on
every tick**. No wave has been created since.

**The escape is closed too.** Step 4 requires `status === Preparing`; Step 3 sets that only when
`auto_move_to_preparing` is truthy — and it is `0`. Deadlock.

`getActiveWaveForDate()` at `WaveManager.php:26-33` is the correct date-scoped predicate. It has **zero
production callers**.

### 6.2 Compounding — rotation is relative, not absolute

`WaveLifecycleService.php:129`:

```php
$nextDate = Carbon::parse($wave->planning_date)->addDay()->toDateString();
```

Rotation advances one day from the **closed wave's** date, never from `now()`. The observed
`PREP-202607-000001` (07-29) → `PREP-202607-000002` (07-30) chain is exactly one rotation. Reaching
2026-08-12 requires **13 more sequential close+rotate cycles**, each needing a separate scheduler tick.
A single unblocking fix would therefore *still* not produce today's wave — it would produce 07-31.

This also explains the wave numbering: `generateWaveNumber()` derives the `YYYYMM` prefix from
`planning_date`, so the stale chain keeps minting `PREP-202607-*` numbers in August.

### 6.3 Contributing — scheduler downtime with no catch-up

Sessions stop dead at 2026-08-10; 08-11 and 08-12 are absent. Docker was down. **No backfill exists**
in any command. This is Cause **C**, but it is *secondary* — even with 100% scheduler uptime the wave
deadlock (§6.1) would still have produced exactly the observed state.

### 6.4 Verdict against the A–G list

| | Cause | Verdict |
|---|---|---|
| A | today's Wave never created | **TRUE — but a symptom, not the cause** |
| B | wave exists, frontend picks wrong one | **FALSE** — no wave for today exists |
| C | scheduler did not execute | **PARTIALLY TRUE** — sessions missed 08-11/08-12; contributing, not root |
| D | scheduler executed but creation failed | **FALSE** — creation was never *attempted*; the guard short-circuits before it |
| E | planning-date calculation wrong | **TRUE for rotation** (`+1 day` relative, §6.2) |
| **F** | **current wave selection logic stale** | **✅ ROOT CAUSE (§6.1)** |
| G | other | config `auto_move_to_preparing = 0` closes the escape; tenant-2 has no config |

---

## 7 — Current Wave Selection Logic (Part 1 Q9 — "why is the UI still showing July 30")

Three independent layers each land on the same stale row.

**API** — `PreparationWaveController::index()`:
- default sort **`-created_at`** (`created_at DESC`)
- **no default date filter**, **no default status filter**
- `planning_date` is an exact-day match; **no date range exists**
- `status` is a single scalar; **no multi-status filter exists**

The July 30 wave has `created_at = 2026-08-07`, the newest of the two → **it sorts first.**

**Frontend** — `wave-picker.tsx` is the only wave selector in the codebase. It calls
`usePreparationWaves({ per_page: 50 })` with **no date or status filter** and reads the selection from a
`?wave_id=` URL parameter. There is no "today" concept in the UI at all.

**The one date-aware endpoint is never called.** `PreparationDashboardController:27` defaults
`planning_date` to `now()->toDateString()` and filters on it — but no hook or component invokes
`preparationService.getDashboard()`. And even if it did, its `active_waves` filter is
`['preparing','shortage_blocked','planning']` — **`collecting` is absent**, so today's wave would not
appear there either.

**So the UI is not malfunctioning.** It is faithfully displaying the newest wave in a system whose
newest wave is 13 days old.

---

## 8 — Recommended Daily Wave Lifecycle (Part 3)

The required properties and the minimal change for each:

| Requirement | Minimal implementation |
|---|---|
| One wave per operational day per warehouse | Replace `hasActiveWave()` at `RunWaveSchedulerCommand.php:67` with **`getActiveWaveForDate($company, $warehouse, $today)`** — the method already exists |
| Automatic creation | already implemented (Step 1) |
| Idempotent creation | already implemented — `lockForUpdate()` + status re-check inside `DB::transaction`. **Recommend also adding a DB unique index** on `(company_id, warehouse_id, planning_date)` — see §15 |
| No duplicate on double-run | covered by the above, plus live `CACHE_STORE=redis` making `withoutOverlapping()` effective |
| Recovery after downtime | §10 |
| Correct operational-day calculation | **BLOCKED on business decision D1** (§19) — no cutoff concept exists |
| Warehouse/company isolation | already correct — the scheduler iterates per-config; **but tenant 2 has no config** (§2.4) |

**Also required, or the fix is cosmetic:**
- set `auto_move_to_preparing = 1` and give the config **operationally realistic times** (currently a
  59-second preparation window)
- reconcile `eligible_order_statuses` — `["confirmed"]` matches nothing (§2.6)
- decide whether rotation should target `today()` rather than `closed_date + 1` (§19 D2)

---

## 9 — Idempotency Strategy

Existing protection is **application-level and adequate for the single-writer case**:
`createCollectingWave()` takes `lockForUpdate()` on `(company, warehouse, planning_date, active status)`
inside a transaction and returns the existing wave if found.

Gaps:
1. **No DB unique constraint** on `(company_id, warehouse_id, planning_date)`. The only unique index is
   `(company_id, wave_number)`. A row-level lock does not protect against a gap-insert race on a
   non-existent row.
2. `getActiveWave()` uses `->first()` with **no `ORDER BY`** — if two active waves ever coexist for one
   warehouse, the scheduler operates on an arbitrary one per tick.
3. `generateWaveNumber()` exists in **two independent implementations** (`WaveLifecycleService:148` and
   `CreateWaveAction:159`), both doing a non-atomic `max()` read outside any lock, and neither catches
   the resulting unique-violation.

---

## 10 — Scheduler Recovery Strategy (Part 7)

**Today: no recovery of any kind.** No command backfills a missed day; a missed 06:00 is lost forever.
Confirmed by the 08-11/08-12 gap.

Recommended, in order of value:

1. **Make creation self-healing rather than event-driven.** Once Step 1 keys on
   `getActiveWaveForDate(..., $today)`, the every-minute tick *becomes* the recovery mechanism — the
   first tick after recovery creates today's wave automatically. No new infrastructure.
2. **Bounded backfill for sessions.** `CreateDailyPreparationSessionsCommand` should ensure sessions for
   the last *N* operational days (N = the same configurable window as the workspace view), not just
   today. `ensureSessionExists()` is already date-parameterised.
3. **Surface failures.** The command returns `FAILURE` when a warehouse fails, but runs under
   `runInBackground()` with no `->onFailure()`/`->pingOnFailure()`, so the exit code is discarded. Only
   a `Log::error` remains.

**Do not** rely on the rotation chain for catch-up — being relative (§6.2), it would need one tick per
missed day and would mint waves with historical dates.

---

## 11 — Recent 3-Day Workspace Design (Part 5)

**Visibility only. No deletion, no mutation, no archival flag.**

Backend — extend `PreparationWaveController::index()` additively:
- new optional `recent_days` parameter; when absent, behaviour is unchanged
- resolves to `planning_date >= today - (N-1) operational days`
- **N is configurable, default 3.** It must **not** be hard-coded.

Configuration placement — the established pattern is a module config file
(`config/distribution.php` is the closest precedent; `config/preparation.php` **does not exist** and
would be created). `PreparationSessionPolicy` is the alternative, and already has precedent for dead
config fields — prefer the config file unless per-warehouse variation is required.

Ordering must change for this view: **`planning_date DESC`**, not `created_at DESC`. The current
default is exactly what surfaced a 13-day-old wave.

Frontend — the workspace lists today / previous / previous-previous with `planning_date` labels and
status badges, and links to the Archive for anything older.

---

## 12 — Preparation Archive Design (Part 6)

A read-only history surface. **No wave business logic is duplicated** — it reuses `index()`.

Required filters, and their current status:

| Filter | Exists today? |
|---|---|
| Date range (`date_from`/`date_to`) | ❌ only exact-day `planning_date` |
| Operational day | ❌ depends on D1 |
| Wave number | ✅ `search` (LIKE on `wave_number`) |
| Status | ⚠️ single scalar only — needs multi-status |
| Warehouse | ✅ `warehouse_id` |
| Company | ✅ implicit — always scoped to `user.company_id` |

So the Archive needs **two additive query capabilities**: a date range and a multi-status filter. Both
are backward-compatible additions to `index()`.

---

## 13 — Backend / API Impact

| Change | File | Risk |
|---|---|---|
| Step 1 guard → `getActiveWaveForDate()` | `RunWaveSchedulerCommand.php:67` | **the core fix** — 1 line |
| `recent_days` filter | `PreparationWaveController::index()` | additive |
| `date_from`/`date_to` | same | additive |
| multi-status filter | same | additive |
| default sort for workspace view | same | behavioural — deliberate |
| config default (3 days) | new `config/preparation.php` | additive |
| session backfill | `CreateDailyPreparationSessionsCommand` | medium |

**No change to** `MaterialDemandCalculator`, Reservation, Entry Gate, eligibility rules, F4/Option B, or
Recipe availability.

---

## 14 — Frontend / UI Impact

- Workspace: recent-N-day list, `planning_date DESC`, today-first.
- New **Preparation Archive** page reusing the existing DataGrid/Filter infrastructure.
- `wave-picker.tsx` should default to today's wave rather than relying solely on `?wave_id=`.
- Incidental defect worth fixing while in the file: `PreparationWaveResource` does **not** expose
  `updated_at`, but two components render `wave.updated_at` — so the "last updated" clock is
  permanently `—`.

---

## 15 — Database Impact

**No schema change is required for the root-cause fix.**

One optional hardening, for a separate authorized task: a unique index on
`(company_id, warehouse_id, planning_date)` over non-terminal waves. MySQL cannot express a partial
index, so this needs `DB::statement` and must respect the platform's migration-compatibility rule.
**Not proposed for the repair task.**

**No records are deleted for the 3-day view.** Retention is presentation-only.

---

## 16 — Certification Impact (Part 8)

| Certified component | Impacted? |
|---|---|
| `MaterialDemandCalculator` (15/8→7/3) | **NO** |
| Reservation logic | **NO** |
| Preparation Entry Gate (13/13) | **NO** |
| Preparation eligibility rules | **NO** — but see the `["confirmed"]` mismatch (§2.6), pre-existing |
| F4 / Option B (39/39) | **NO** |
| Recipe availability | **NO** |
| RC-10 lifecycle (17/17) | **NO** |

The change surface is `RunWaveSchedulerCommand`, `WaveManager` call sites, and
`PreparationWaveController::index()`. None is exercised by the certified suites' assertions.

⚠️ One pre-existing inconsistency to be aware of before touching the lifecycle:
`WaveLifecycleService::closeWave()` drives `collecting → closed` directly, which
`WaveStatus::canTransitionTo()` **forbids** and `WaveStatusEnumTest.php:81` explicitly asserts is
forbidden. `closeWave()` never consults the matrix. Out of scope here; flagged.

---

## 17 — Required Implementation Tasks

**TASK-A — Wave Engine deadlock repair (narrow, highest priority).**
1. `RunWaveSchedulerCommand.php:67` → `getActiveWaveForDate(..., $today)`.
2. Data fix: transition or close the stranded `PREP-202607-000002`.
3. Config fix: `auto_move_to_preparing = 1` and operationally realistic times.
4. Regression: Entry Gate 13/13, RC-10 17/17, Preparation suite.

**TASK-B — Tenant coverage.** Create a `WaveEngineConfiguration` for company `019fe003…`, or make the
scheduler fall back to a documented default per active warehouse.

**TASK-C — Recent-N-day workspace + Archive.** Additive API filters, `config/preparation.php`, two
frontend surfaces.

**TASK-D — Scheduler resilience.** Bounded session backfill; failure surfacing.

**TASK-E (separate, pre-existing).** Eligibility-status mismatch, `closeWave()` matrix violation,
duplicate `generateWaveNumber()`, missing `updated_at` in the resource.

---

## 18 — Risks

1. **Fixing only the guard produces 2026-07-31, not today** — rotation is relative (§6.2). The data fix
   in TASK-A step 2 is mandatory, not optional.
2. **Unblocking with `eligible_order_statuses = ["confirmed"]` yields empty waves**, which will look
   like the fix failed.
3. **Order exclusivity is permanent.** `uq_prep_wave_orders_company_order (company_id, order_id)` plus a
   `whereNotExists` that is not scoped by wave or status means **an order attached to any wave can never
   join another**, even after that wave closes. Backfilling historical waves could permanently strand
   orders. `detachOrder()` has no automatic caller.
4. Changing the default sort alters existing API consumers — should be opt-in via the new parameter.

---

## 19 — Open Business Decisions

**D1 — Define the operational day.** No cutoff exists. Is the operational day the calendar day, or does
a configurable cutoff (e.g. 06:00) decide which day an order belongs to? Everything in Parts 3, 5 and 7
depends on this. `companies.operational_day_start` is specified in `CONFIGURATION-OS-SPEC.md:93` but was
never implemented — implementing it is a design decision, not a repair.

**D2 — Rotation semantics.** Should `rotateWave()` target `today()` instead of `closed_date + 1`? Absolute
is self-healing; relative preserves an unbroken audit chain of daily waves. **Recommend absolute**, with
the caveat that it silently skips days the system was down.

**D3 — Empty waves (Part 4).** The engine path **already always creates an empty wave** (no order guard),
while the manual path requires ≥1 order. The architecture therefore **already supports option A** and
does **not** contradict your requirement. Confirming this makes the requirement a no-op rather than a
change — please confirm.

**D4 — Configurable window value.** `config/preparation.php` (global) or `PreparationSessionPolicy`
(per-warehouse)? Recommend the config file unless per-warehouse variation is needed.

**D5 — Backfill scope.** On recovery, should the system create waves for missed days, or only today?
Backfilling interacts dangerously with permanent order exclusivity (Risk 3). **Recommend today-only.**

**D6 — Cross-tenant `session_number` collisions.** Per-company sequencing means two tenants share
`PS-YYYYMMDD-0001`. Correct per tenant, confusing in any cross-tenant view. Accept, or make it globally
unique?

---

## 20 — Compliance With the Strict Stop Rule

Nothing was implemented. No schema change, no migration, no change to certified Preparation logic,
Reservation, `MaterialDemandCalculator`, Entry Gate or order eligibility. No test expectation altered.
Database access was **read-only** (`SELECT` / `SHOW`) against **`ecos_dev` only**; MAIN untouched.

The only file created by this task is this report.

> **VERDICT:** the architecture **does** support the required daily-wave behaviour. The defect is
> narrow — a date-blind guard plus a configuration value — and qualifies for the brief's "repair only
> that narrow defect in a separate authorized implementation task" path. **The operational-day
> definition (D1) is a genuine design gap** and must be decided before the recent-day view and archive
> can be specified precisely.

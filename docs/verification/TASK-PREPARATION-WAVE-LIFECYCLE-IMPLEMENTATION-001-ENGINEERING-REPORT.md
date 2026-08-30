# TASK-PREPARATION-WAVE-LIFECYCLE-IMPLEMENTATION-001 — Engineering Report

**Date:** 2026-08-12
**Branch:** `develop` · **HEAD:** `6149875bd8a01820116b5deacbbfb8ef0e51cc05`
**Status:** **STOPPED AT PRE-IMPLEMENTATION GATE — STOP CONDITIONS 1, 2 AND 9 TRIGGERED.**
**Production changes made:** **NONE.** Database access: read-only `SELECT`/`SHOW`/`DESCRIBE` on `ecos_dev`.

---

## 1 — Executive Summary

Implementation did not begin. The task's first authoritative premise could not be verified, and it
triggers three declared STOP conditions.

The brief states:

> **1. Preparation Window ALREADY EXISTS in Brand settings.**
> The existing Brand Preparation Window configuration is authoritative for: opening time, closing
> time, the active operational cycle.

**No such configuration exists.** The Brand preparation policy is real, seeded and reachable through
the Admin Configuration UI — but its schema contains **no opening time, no closing time, and no
cutoff**. This is an **architecture gap, not a seeding gap**: the canonical defaults in code define
nine settings and none of them is a time.

Because contract items **2, 3, 6, 7, 8, 9, 12, 21, 24 and 25** all resolve the operational cycle
through that Window, the core objective cannot be implemented as specified without inventing the
Window — which items 1 and 7 explicitly forbid.

Two other STOP conditions were checked in the same pass and are **NOT blocking** — both are
determinable, and the evidence is recorded in §7 and §8 so the follow-up task can move immediately.

| STOP | Condition | Verdict |
|---|---|---|
| **1** | Brand Window configuration cannot be identified | **TRIGGERED** (§4) |
| **2** | Operational day cannot be derived from the Window | **TRIGGERED** (§5) |
| 3 | V3 status mapping cannot be established | **NOT triggered** — resolved (§7) |
| 4 | Canonical assignment event cannot be determined | **NOT triggered** — resolved, defect confirmed (§8) |
| 5 | Existing Orders require destructive mutation | not reached |
| 6 | Schema change unexpectedly required | not reached |
| 7 | `MaterialDemandCalculator` parity broken | **drift found in one container** (§9) |
| 8 | Certified component must be redesigned | not reached |
| **9** | Multi-tenant configuration semantics ambiguous | **TRIGGERED** (§6) |
| 10 | Fix requires changing another module's contract | not reached |

---

## 2 — What Was Verified (method)

Read-only source inspection plus read-only queries against `ecos_dev`. Every claim below is either a
`file:line` citation or a query result. No file was created, edited or deleted. No migration, no seed,
no write of any kind.

---

## 3 — Search for the Brand Preparation Window

Every candidate in the system was examined.

| Candidate | Scope | Has open/close time? | Live data | Read by Preparation? |
|---|---|---|---|---|
| `config_brand_policies` `policy_group='preparation'` | **Brand** | **NO** | 1 row | yes (batch_size only) |
| `brand_delivery_time_slots` | **Brand** | `start_time`/`end_time`/`cutoff_time` | 12 rows, **`cutoff_time` NULL on all** | **no** |
| `preparation_session_policies` | company + warehouse | `auto_create_time`/`auto_close_time`/`freeze_time` | **0 rows** | partially — see §6 |
| `wave_engine_configurations` | company + warehouse | `collection_start_time`/`preparation_start_time`/`wave_end_time` | 1 row | **yes — the live one** |
| `config_delivery_windows` | — | — | **0 rows** | no |

---

## 4 — STOP 1: The Brand Preparation Window Does Not Exist

The Brand preparation policy is defined canonically in
`Modules/Admin/Configuration/Domain/Models/BrandPolicy.php:97-110`:

```php
private static function defaultPreparationSettings(): array
{
    return [
        'wave_generation'         => 'auto',
        'wave_priority'           => 'fifo',
        'batch_size'              => 50,
        'merge_orders'            => true,
        'split_orders'            => false,
        'partial_preparation'     => false,
        'negative_stock_handling' => 'block',
        'packing_strategy'        => 'standard',
        'exception_handling'      => 'notify',
    ];
}
```

**Nine settings. Not one is a time.** No `open`, no `close`, no `cutoff`, no `window`.

The live row in `ecos_dev` matches the schema exactly:

```json
{"batch_size":50,"merge_orders":true,"split_orders":false,"wave_priority":"fifo",
 "wave_generation":"auto","packing_strategy":"standard","exception_handling":"notify",
 "partial_preparation":false,"negative_stock_handling":"block"}
```

A grep of `BrandPolicy.php`, `PreparationPolicyService.php` and `BrandConfigurationResolverService.php`
for `window|open|clos|cutoff|start_time|end_time` returns exactly one hit —
`'conversion_window_days' => 30` in the **marketing attribution** policy, unrelated.

**Because the absence is in the schema and not merely in the data, seeding cannot resolve it.** There
is nothing to "reuse".

Only **1 of 3+ brands** has a `preparation` policy row at all.

### 4.1 The nearest brand-scoped time configuration is a *delivery* window

`brand_delivery_time_slots` is brand-scoped and does carry `start_time`, `end_time` and `cutoff_time`.
It is **not** a preparation window:

- its rows are customer-facing 3-hour delivery slots (`09:00–12:00`, `12:00–15:00`, `15:00–18:00`, …)
- **`cutoff_time` is NULL on all 12 rows**
- **no Preparation code reads this table.** Preparation only stores denormalised
  `delivery_window_*` snapshot columns on wave orders, used for sort order and display

Treating delivery slots as the preparation cycle would be inventing a Window, which item 1 forbids.

---

## 5 — STOP 2: The Operational Day Cannot Be Derived

Item 7 requires the operational cycle to come from the existing Brand Window and forbids
`planning_date = now()->toDateString()`.

With no Brand Window, there is no source for the cycle. Confirming the earlier diagnostic:

- `preparation_window` / `prep_window` — **0 hits repo-wide**
- `cutoff` in the Preparation module — **0 hits**
- `config/preparation.php` — **does not exist**
- `companies.operational_day_start` — specified at `CONFIGURATION-OS-SPEC.md:93`, **never implemented**
- `operational_day` in code — 11 hits, all in DemandAnalysis, all `= $date ?? now()->toDateString()`,
  used as a display label and never as a filter

Item 7 does permit "the smallest necessary domain/service abstraction… to connect the existing Brand
Window to Wave lifecycle." That permission presupposes a Brand Window to connect **to**. There is no
upstream to read, so any resolver written now would have to *define* the cycle, not resolve it.

---

## 6 — STOP 9: Three Competing Window Models, None Brand-Scoped

Item 7 says "Do NOT duplicate Window configuration." The system already contains three partial,
mutually inconsistent window mechanisms — and choosing between them is a business decision.

**(a) `wave_engine_configurations`** — the only one the wave scheduler actually consumes.
Company + warehouse. 1 active row. Times are seeded test values
(`preparation_start_time 23:59:00`, `wave_end_time 23:59:59` — a 59-second preparation window), and
`auto_move_to_preparing = 0`.

**(b) `preparation_session_policies`** — has the most window-like fields (`auto_create_time`,
`auto_close_time`, `freeze_time`, `eligible_order_statuses`, `auto_attach_orders`) and **is
user-editable through the Admin Configuration UI** (`PreparationPolicyController.php:47-49, 93-99`).
This is most likely what the brief refers to as "Brand settings". Three problems:

1. It is **company + warehouse scoped, not brand scoped** — the model contains zero references to
   `brand`.
2. It has **0 rows** in `ecos_dev` — nothing is configured.
3. **`auto_create_time` and `auto_close_time` are dead fields.** Written by the Admin API, read by
   nothing. The only reference outside the writer is a *docstring* at
   `CreateDailyPreparationSessionsCommand.php:17` claiming the command is "scheduled to run at the
   configured auto_create_time" — but the real schedule is hardcoded `->dailyAt('06:00')` in
   `routes/console.php`. Only `freeze_time` has a live consumer.

**(c) `brand_delivery_time_slots`** — brand-scoped, but a delivery window (§4.1).

Reconciling these is exactly the ambiguity STOP 9 describes.

---

## 7 — STOP 3 RESOLVED: V3 Status Mapping (not blocking)

`Modules/Commerce/Orders/Domain/Enums/OrderStatus.php:17-31` — the complete V3 enum:

```
new · in_progress · ready_for_dispatch · out_for_delivery · delivered
awaiting_payment · awaiting_stock · scheduled · on_hold · cancelled · returned
```

**There is no `confirmed` case.** The configured `eligible_order_statuses = ["confirmed"]` on the live
`wave_engine_configurations` row matches **nothing** — confirming why `preparation_wave_orders` is
empty.

**Approved mapping, derivable from source:**

| Business term | V3 status | Evidence |
|---|---|---|
| New | `new` | `OrderStatus.php:17` |
| In Progress | `in_progress` | `OrderStatus.php:18` |
| **Confirm** | **`in_progress`** — merged, no separate status | `PreparationSessionPolicy` docblock records that `confirm_order` became inert after the V3 merge and "the effective policy had silently collapsed to `in_progress` alone" |

The correct value is therefore `['new', 'in_progress']` — which is already exactly what
`PreparationSessionPolicy::defaultEligibleStatuses()` returns. **No status needs inventing**; the fix
is to replace the obsolete `["confirmed"]` config value. Confirmation requested as Decision D3 (§13).

---

## 8 — STOP 4 RESOLVED: The Event Seam Defect Is Confirmed (not blocking)

| Fact | Evidence |
|---|---|
| `BranchAssignmentEngine` dispatches `BranchAssigned` | `BranchAssignmentEngine.php:104`, `:139` |
| `WarehouseAssignmentEngine` dispatches `WarehouseAssigned` | `WarehouseAssignmentEngine.php:58`, `:96` |
| Preparation registers **only** `WarehouseAssigned` | `PreparationServiceProvider.php:116` — `$events->listen(WarehouseAssigned::class, WarehouseAssignedListener::class)` |
| **Nothing anywhere listens to `BranchAssigned`** | no `BranchAssignedListener` exists |

So when the current `BranchAssignmentEngine` succeeds, **Preparation never observes it**. Item 13 is
confirmed correct and the repair is determinable — the smallest bridge is a listener registration for
`BranchAssigned` delegating to the existing `PreparationReleaseEngine`, which
`WarehouseAssignedListener` already delegates to (`PreparationWaveController.php:698` documents that
convergence). **Not implemented**, pending the STOP resolution.

---

## 9 — Item 23: `MaterialDemandCalculator` Parity — Drift Confirmed

Exactly as the brief anticipated:

```
HOST                  ce69612a5910ad7eb84c354895b45140   ← certified
ecos-dev-testrunner   ce69612a5910ad7eb84c354895b45140   ← certified, correct
ecos-dev-app          4c2903b8fc751d05755b6fb8cdfa3546   ← STALE
```

The **test runner is already correct**, so the certified suites are unaffected. The drift is confined
to `ecos-dev-app`, the container serving the UI.

**Not synced.** The brief scopes the sync to "before runtime certification", and no runtime
certification was reached. The command is ready to run on your authorization:

```bash
docker cp backend/Modules/Operations/DemandAnalysis/Application/Services/MaterialDemandCalculator.php ecos-dev-app:/var/www/html/Modules/Operations/DemandAnalysis/Application/Services/MaterialDemandCalculator.php
```

The calculator itself was **not modified** and its tests were **not touched**.

---

## 10 — Runtime State (unchanged since the diagnostic)

- `preparation_waves`: 2 rows. Newest `PREP-202607-000002`, planning_date **2026-07-30**, status
  `collecting`. **No wave for 2026-08-12.**
- `preparation_wave_orders`: **empty**.
- `preparation_sessions`: 13 rows, 2026-07-30 → 2026-08-10, all `draft`, all `waves_count = 0`. Nothing
  for 08-11 or 08-12.
- `wave_engine_configurations`: 1 row, 1 active, covering only company `019f4e1c…`.
- `preparation_session_policies`: **0 rows**.

The deadlock proven in TASK-PREPARATION-DAILY-WAVE-LIFECYCLE-001 is unchanged: `hasActiveWave()` is
date-blind, the stale `collecting` wave matches the config's company+warehouse exactly, and the escape
path is closed by `auto_move_to_preparing = 0`.

---

## 11 — What Was NOT Done, and Why

Every item below was authorized but is gated on the STOP.

| Item | Status |
|---|---|
| 2, 3, 6, 7, 8, 9 — daily wave, collection, window close, operational day, deadlock fix, rotation | **BLOCKED** — all resolve the cycle through the Window |
| 12 — multi-tenant configuration | **BLOCKED** — STOP 9 |
| 19, 20, 21 — 3-day workspace, Archive, current-wave selection | **BLOCKED** — "operational day" is the unit of the 3-day window and of "current" |
| 13 — event seam | ready, not implemented (§8) |
| 16 — `StockAddedListener` column | not implemented — gated on whether it is in the approved re-evaluation contract |
| 17 — `MoveToPreparation` null-warehouse guard | not implemented |
| 18 — eligibility config | mapping resolved (§7), not applied |
| 24, 25, 26 — runtime test matrix | not reached |

Items 13, 16, 17 and 18 are Window-independent and **could** be delivered as a separate hardening
task. I did not start them, because the brief directs that a STOP halts implementation rather than
that I proceed with the unaffected subset. Say the word and they can ship on their own.

---

## 12 — Certification Statement (item 30)

Reported separately, as required:

> **A. Wave Lifecycle Implementation = NOT CERTIFIED** — not implemented; halted at the
> pre-implementation gate.
>
> **B. Preparation Component Suites = UNCHANGED** — no production file was modified, so
> Entry Gate 13/13, RC-10 17/17, MaterialDemandCalculator and F4/Option B are untouched. Test-runner
> parity for the calculator is intact at `ce69612a…`.
>
> **C. Order → Preparation Integrated Flow = NOT CERTIFIED** — no runtime evidence was produced, and
> the flow is known-broken on two independent counts: the wave deadlock and the `BranchAssigned` seam.

No part of the Preparation OS is claimed certified by this task.

---

## 13 — Decisions Required to Unblock

**D1 — Where does the Preparation Window live? (blocks everything)**
The Brand preparation policy has no time fields. Three options, none of which I may choose:

- **(a) Extend the Brand preparation policy** with `window_open_time` / `window_close_time`. Matches
  the brief's stated intent and is brand-scoped, but it **adds a Window**, which item 1 forbids
  without your approval. No schema migration needed — `config_brand_policies.settings` is JSON.
- **(b) Adopt `wave_engine_configurations` as authoritative.** Zero new configuration — the scheduler
  already reads it. But it is company+warehouse scoped, not brand, so it contradicts premise 1.
- **(c) Activate `preparation_session_policies`.** Its `auto_create_time`/`auto_close_time` fields
  already exist and are already user-editable in the Admin UI; they are simply not read. Wiring them
  up is small. Still company+warehouse scoped, and it would leave two live window models unless (a) or
  (b) is retired.

**Recommendation: (b)** for the immediate deadlock repair — it introduces no new configuration and
unblocks the current-cycle fix — with (a) as the deliberate follow-up if brand-level windows are
genuinely required. But this is your call, not mine.

**D2 — Is "Brand settings" in premise 1 actually the Admin → Configuration → Preparation Policy
screen?** That screen writes `preparation_session_policies`, which is company/warehouse-scoped and
currently empty. If that is what you were looking at, premise 1 should read "company/warehouse", and
option (c) becomes the natural choice.

**D3 — Confirm the V3 mapping** (§7): eligible = `['new', 'in_progress']`, with business "Confirm"
subsumed into `in_progress`. Derivable from source, but it is a business statement.

**D4 — Are items 13, 16, 17, 18 authorized to proceed independently** of the Window decision? They are
genuine defects and are Window-independent.

---

## 14 — Compliance

No production change. No schema change. No migration. No seed. No test modified. No historical wave
deleted. No order mutated. `MaterialDemandCalculator` untouched. Reservation, Entry Gate, eligibility
rules, F4/Option B and Recipe logic untouched. Database access was read-only against **`ecos_dev`
only**; MAIN was never connected to.

The only file created by this task is this report.

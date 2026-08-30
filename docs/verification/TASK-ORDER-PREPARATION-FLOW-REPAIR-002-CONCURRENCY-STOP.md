# TASK-ORDER-PREPARATION-FLOW-REPAIR-002 — CONCURRENCY STOP

**Date:** 2026-08-12 04:49 (local)
**Author:** this session
**Status:** **STOPPED BEFORE IMPLEMENTATION — CONCURRENT AGENT OWNS THE SAME FILES.**
**Production changes by this session:** **NONE.**

> Filed at a deliberately non-colliding path. The report this task specifies
> (`TASK-ORDER-PREPARATION-FLOW-REPAIR-002-ENGINEERING-REPORT.md`) is expected to be written by the
> agent currently doing the implementation. Writing there would have clobbered its work.

---

## 1 — Why this task stopped

Another agent is **actively implementing this exact task right now**. Eleven backend PHP files were
written between **04:35 and 04:43**; the current time when this was detected was **04:49** — i.e. work
in flight 6–14 minutes earlier, while this session was reading the contract documents and running its
read-only survey.

This session has made **zero** backend PHP edits at any point.

Proceeding would have meant two agents writing the same uncommitted files in the same working tree
(not separate git worktrees), which would destroy one side's work or produce an incoherent merge.

---

## 2 — Evidence

### 2.1 Files written by the other agent, with timestamps

```
04:43  Modules/Commerce/Orders/Infrastructure/Console/Commands/ReprocessLegacyReservationsCommand.php
04:42  Modules/Commerce/Orders/Infrastructure/Providers/OrderServiceProvider.php
04:38  Modules/Operations/Preparation/Infrastructure/Console/Commands/RunWaveSchedulerCommand.php
04:38  Modules/Operations/Preparation/Application/Services/WaveEngine/WaveManager.php
04:38  Modules/Operations/Preparation/Application/Services/WaveEngine/WaveLifecycleService.php
04:37  Modules/Operations/Fulfillment/Application/Workflows/ProcessOrderWorkflow.php
04:37  Modules/Operations/Fulfillment/Application/Workflows/MoveToPreparationWorkflow.php
04:37  Modules/Operations/Fulfillment/Application/Workflows/ConfirmOrderWorkflow.php
04:36  Modules/Commerce/Orders/Application/Listeners/ExecuteReservationOnWarehouseAssigned.php
04:35  Modules/Operations/Preparation/Application/Services/BranchAssignmentEngine.php
04:35  Modules/Operations/Preparation/Application/Listeners/StockAddedListener.php
```

None of these appeared in this session's start-of-session `git status` snapshot, nor in a `git status`
this session ran roughly 15 minutes before detection.

### 2.2 Diffstat of the in-flight work

```
 OrderServiceProvider.php              |  7 ++++
 ConfirmOrderWorkflow.php              | 29 +++++++++-----
 MoveToPreparationWorkflow.php         | 25 ++++++++++++
 ProcessOrderWorkflow.php              | 43 +++++++++++++++-----
 StockAddedListener.php                | 31 ++++++++++++---
 BranchAssignmentEngine.php            | 46 ++++++++++++++++++++++
 WaveLifecycleService.php              | 23 ++++++++++-
 WaveManager.php                       | 37 +++++++++++++++--
 RunWaveSchedulerCommand.php           | 15 +++++--
 9 files changed, 223 insertions(+), 33 deletions(-)
```

Plus one new untracked file: `ExecuteReservationOnWarehouseAssigned.php`.

### 2.3 Spot-checks confirming scope overlap

- **Part 7** — `StockAddedListener.php:56` now reads `->sum('on_hand_qty')`, with a comment at `:48`
  explaining the previous `on_hand_quantity` misspelling. **Already applied.**
- **Part 8** — `MoveToPreparationWorkflow.php:85` now contains
  `if ($order->assigned_warehouse_id === null)`. **Guard already added.**
- **Part 10** — `ReprocessLegacyReservationsCommand.php` (the ADR-027 §15 **H4** deliverable) now
  exists.
- **Parts 2/3** — `BranchAssignmentEngine` (+46 lines) and a new
  `ExecuteReservationOnWarehouseAssigned` listener wired through `OrderServiceProvider` (+7).
- **Part 4** — `ProcessOrderWorkflow` (+43) and `ConfirmOrderWorkflow` (+29).

### 2.4 A second agent has been interleaving all session

Report mtimes in `docs/verification/` show two authors alternating:

```
03:22  TASK-IAM-PASSWORD-RESET-DOMAIN-OPERATION-001            ← this session
03:37  TASK-ENV-API-CONNECTION-REPAIR-001                      ← other agent
03:51  TASK-IAM-HTTP-SURFACE-001-CONTRACT-AUDIT                ← this session
03:54  TASK-ORDER-AWAITING-STOCK-DIAGNOSTIC-001                ← other agent
04:12  TASK-ORDER-FULFILLMENT-STATE-CONTRACT-001               ← other agent
04:19  TASK-PREPARATION-DAILY-WAVE-LIFECYCLE-001               ← this session
04:26  TASK-ORDER-PREPARATION-BUSINESS-CONTRACT-RESOLUTION-001 ← other agent
04:32  TASK-PREPARATION-WAVE-LIFECYCLE-IMPLEMENTATION-001      ← this session
04:35+ (implementation of FLOW-REPAIR-002 begins)              ← other agent
```

The two contract documents this task cites as authoritative (`04:12`, `04:26`) were produced by the
other agent minutes before this task was issued.

### 2.5 Corroborating environmental signal

Earlier in this session `ecos-dev-app` reported `MaterialDemandCalculator` at the stale
`4c2903b8fc751d05755b6fb8cdfa3546`. A later check in the same session reported the certified
`ce69612a5910ad7eb84c354895b45140`. Neither container has a source mount, so the image content was
changed by an external actor mid-session — consistent with the same concurrent agent.

---

## 3 — Scope concern worth raising

The in-flight work includes **`WaveManager.php`, `WaveLifecycleService.php` and
`RunWaveSchedulerCommand.php`**.

This task's **Part 12** explicitly forbids Wave/Window work:

> This task MUST NOT attempt to solve the missing Preparation Window. […] The existing Wave
> scheduler/window architecture remains a separate follow-up architecture task.

Those three files are the Wave scheduler and the date-blind `hasActiveWave()` deadlock — the subject
of TASK-PREPARATION-WAVE-LIFECYCLE-IMPLEMENTATION-001, which was correctly **STOPPED** because no
canonical Preparation Window exists.

Whether the concurrent agent is repairing the deadlock (legitimate and valuable, but out of *this*
task's scope) or reintroducing Window semantics (explicitly forbidden) is **not** something this
session can determine without reading work that is still being written. **It should be reviewed before
those three files are committed.**

---

## 4 — Work completed by this session before stopping

Read-only, all of it still valid and reusable:

**Part 0 — baseline parity: PASS.**

| Check | Result |
|---|---|
| `SELECT DATABASE()` | `ecos_dev` ✅ |
| `MaterialDemandCalculator` — host | `ce69612a5910ad7eb84c354895b45140` ✅ |
| — `ecos-dev-testrunner` | `ce69612a…` ✅ |
| — `ecos-dev-app` | `ce69612a…` ✅ (was `4c2903b8…` earlier; externally synced) |

**Live contract violation confirmed** (read-only query, `ecos_dev`):

| | ORD-00001 | ORD-00002 |
|---|---|---|
| `status` | `awaiting_stock` | `awaiting_stock` |
| `reservation_status` | `awaiting_stock` | `awaiting_stock` |
| `assigned_warehouse_id` | NULL | NULL |
| `warehouse_assignment_source` | `no_branch_coverage` | `unassigned` |
| `warehouse_assignment_failure_reason` | "No Branch Covers Destination" | NULL |

Both sit at `awaiting_stock` for a **warehouse** failure — the exact Q3 breach. These are the only two
orders in the database.

**Part 1 scoping determined.** `["confirmed"]` exists **only** as a data row in
`wave_engine_configurations` (created 2026-07-16). No seeder, factory or migration default produces
it — the migration declares `$table->json('eligible_order_statuses')` with no default, and
`PreparationSessionPolicy::defaultEligibleStatuses()` already returns the correct
`['new','in_progress']`. So Part 1 is a **dev-data correction**, not a code-default change.

**V3 mapping confirmed against the enum** (`OrderStatus.php:17-31`): `new`, `in_progress`,
`ready_for_dispatch`, `out_for_delivery`, `delivered`, `awaiting_payment`, `awaiting_stock`,
`scheduled`, `on_hold`, `cancelled`, `returned`. **No `confirmed` case exists.** Business "Confirm"
maps to `in_progress`.

**Part 9 (geography) — independently blocked, on its own stated condition.** Scope item 10 admits
geography work *"ONLY if the D1 implementation path is already proven safe without inventing
schema/configuration."* The Contract Resolution's own "honest gap" (§3) records three disqualifying
facts: `orders` has no `master_governorate_id`/`geography_governorate_id` column (**schema change**);
`config_delivery_geographies` and `config_delivery_zones` are **empty** in `ecos_dev` (**data
backfill**); and `resolveOrderGeography()` requires `delivery_zone`, which is **NULL for ORD-00001**.
Part 9 directs a STOP on exactly these. No bilingual string-matching layer was introduced.

---

## 5 — Certification

Reported separately as required. Nothing is claimed.

| | Verdict |
|---|---|
| A. No-Coverage Contract Repair | **NOT CERTIFIED** — not implemented by this session |
| B. Warehouse Assignment Event Seam | **NOT CERTIFIED** — not implemented by this session |
| C. Reservation Pending Recovery | **NOT CERTIFIED** — not implemented by this session |
| D. Preparation Eligibility | **NOT CERTIFIED** — not implemented by this session |
| E. H4 Reprocessing | **NOT CERTIFIED** — not implemented by this session |
| F. Order → Preparation Integrated Flow | **NOT CERTIFIED** — no runtime evidence produced |

Preparation Window, Daily Wave Lifecycle, Shipping, Loading, Driver and Delivery are outside this task
and are not certified here.

---

## 6 — Recommended next step

Pick one and tell the session:

1. **Let the other agent finish** (default). This session stays out of these files.
2. **This session takes over** — requires the other agent to be stopped first, so there is a single
   writer.
3. **This session reviews the other agent's work** once it lands, against ADR-027 and the two contract
   resolutions, including the Part-12 scope question in §3.

Option 3 is the highest-value use of this session's context: the contracts, the runtime baseline and
the root causes are already loaded here.

---

## 7 — Compliance

No production file created, edited or deleted. No schema change, no migration, no seed. No order
mutated. `MaterialDemandCalculator` untouched. Reservation, Entry Gate, eligibility rules, F4/Option B,
Recipe logic and product flags untouched. Database access read-only (`SELECT`/`SHOW`/`DESCRIBE`)
against **`ecos_dev` only**; MAIN never connected to.

The only file created by this session for this task is this document.

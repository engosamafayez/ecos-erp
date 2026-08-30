# TASK-LOADING-WAREHOUSE-DRIVER-CUSTODY-ARCHITECTURE-DECISION-001

**ARCHITECTURE STATUS: BLOCKED — OWNER DECISION REQUIRED**

Files changed: **0** · Backend changed: **0** · Database changed: **0**
Migration executed: **NO** · Permissions changed: **NO** · Tests: **NOT RUN**
Browser: **NOT RUN** · Data mutation: **NONE** · Commit/Push/Deploy: **NONE**

Date: 2026-08-26 · Branch: `develop` · Design only — nothing implemented.

> The design below is **not invented**. Every element has a precedent already in ECOS:
> the adjustment log mirrors `vehicle_plan_adjustment_log` (same module); the
> warehouse/driver split mirrors the dormant `AllocationAdjusted` event's
> `actorType`/`actorId`; the driver-quantity pair mirrors `TripCustody.quantity` vs
> `received_quantity`; concurrency uses the `lockForUpdate` this codebase already uses 20
> times across these two modules.
>
> **Two decisions are yours** (§14). Both are business/scope calls I will not make.

---

## 1. Semantics preserved (§2)

Unchanged and load-bearing throughout this design:

```
Remaining = Required − Loaded          (never Required − Prepared)
Prepared ≠ Loaded                      Start Loading ≠ Loaded
Required 10 / Prepared 10 / Loaded 0  → Remaining 10
Required 10 / Prepared 10 / Loaded 6  → Remaining 4
```

Nothing proposed writes `quantity_loaded` except an explicit warehouse act.
**Driver actions never write `quantity_loaded`.**

## 2. Source of truth — one owner per value (§7)

| Value | Owning module | Canonical location | Stored or derived |
|---|---|---|---|
| **Required** | Logistics\Distribution | `DistributionAggregationService::productAggregation` | **derived live** |
| **Prepared** | Logistics\Distribution | `distribution_group_product_preparation` | stored |
| **Loaded** | Operations\Loading | `loading_tasks.quantity_loaded` | stored (absolute-set) |
| **Warehouse confirmation** | Operations\Loading | `loading_tasks.confirmed_by` / `confirmed_at` | stored — **columns already exist, currently dormant** |
| **Driver received** | Operations\Loading | `loading_tasks.driver_received_qty` | **NEW column** |
| **Driver confirmation** | Operations\Loading | `loading_tasks.driver_confirmed_by` / `driver_confirmed_at` | **NEW columns** |
| **Adjustment history** | Operations\Loading | `loading_task_adjustment_log` | **NEW table** |
| **Product workflow state** | — | **derived** from the above | **derived — no new column** |
| Remaining / Difference | — | derived per read | derived |

No snapshot, no cache, no frontend-owned quantity, no second projection. Required stays
live; every other value has exactly one writer.

## 3. Table vs columns — decision per data point (§6)

| Data point | Decision | Why |
|---|---|---|
| `quantity_loaded` | **existing column** | already canonical and certified |
| Warehouse confirmed by/at | **existing columns** (`confirmed_by`, `confirmed_at`) | present in `$fillable` and casts, written by nothing — claiming them costs no migration and they already sit on the warehouse-owned row |
| `driver_received_qty` | **new column** on `loading_tasks` | strictly 1:1 with the task; `UNIQUE (vehicle_assignment_id, product_id)` already guarantees one row per product, so a table would add a join for no gain. Mirrors `TripCustody.quantity` vs `received_quantity` at product grain |
| `driver_confirmed_by` / `driver_confirmed_at` | **new columns** | same 1:1 argument; mirrors `TripCustody.driver_confirmed_at/by` |
| **Adjustment history** | **NEW TABLE — `loading_task_adjustment_log`** | §5F requires multiple rounds and §12 requires no-overwrite. Columns hold only the latest round and are destroyed on round 2 — the exact defect to avoid |
| Workflow state | **derived, no column** | see §5 |

**Why a table specifically named `..._adjustment_log`:** `vehicle_plan_adjustment_log`
already exists **in this same module** with the shape
`action_type, actor_id, *_before, *_after, before_state, after_state, reason, recorded_at`.
Naming and shaping the new table after it keeps one convention instead of inventing a
second.

## 4. Proposed schema — NOT WRITTEN, NOT RUN (§24, §27)

### 4a. `loading_tasks` — three new nullable columns

```
driver_received_qty     DECIMAL(18,4)  NULL   -- what the driver counted
driver_confirmed_at     TIMESTAMP      NULL
driver_confirmed_by     CHAR(36)       NULL
```

All **NULL-able with no default**, so every existing row remains valid and **no backfill is
required** (§23). Existing reads that never select them are unaffected. `DECIMAL(18,4)`
matches `quantity_loaded` exactly.

### 4b. `loading_task_adjustment_log` — new table

```
id                    CHAR(36)      PK
company_id            CHAR(36)      NOT NULL
loading_task_id       CHAR(36)      NOT NULL   FK → loading_tasks(id) cascadeOnDelete
action_type           VARCHAR(50)   NOT NULL   driver_requested | warehouse_revised | warehouse_rejected
actor_type            VARCHAR(20)   NOT NULL   warehouse | driver
actor_id              CHAR(36)      NOT NULL
quantity_before       DECIMAL(18,4) NULL       warehouse Loaded at the moment of the act
quantity_after        DECIMAL(18,4) NULL       NULL for a request; set on a revision
driver_reported_qty   DECIMAL(18,4) NULL       what the driver said they received
reason                VARCHAR(255)  NULL
status                VARCHAR(20)   NOT NULL   open | resolved | rejected
resolved_by           CHAR(36)      NULL
resolved_at           TIMESTAMP     NULL
recorded_at           TIMESTAMP     NOT NULL
created_at/updated_at

INDEX (loading_task_id, recorded_at)   -- "history of this product", the only query
INDEX (company_id, status)             -- "open adjustment requests for this company"
```

`actor_type` + `actor_id` are taken verbatim from the existing `AllocationAdjusted` event,
which already carries `quantityBefore, quantityAfter, actorType, actorId, reason,
occurredAt`. §11's separation is therefore expressed in a field pair ECOS already uses.

**One open request per task** cannot be a partial unique index (MySQL 8.4 has none). Enforce
it in the application under `lockForUpdate` — the pessimistic pattern used 20 times already
in these two modules. Do **not** introduce optimistic `version` columns: the only `version`
columns in this database are on config/document entities, never on operational quantities.

**Nothing above has been created.**

## 5. State machine — derived, not stored (§8, §15)

**Decision: derive the product state; add no status column and do not extend
`LoadingTaskStatus`.** Existing consumers switch on that enum, and a second status column
would be a second source of truth that can disagree with the quantities.

Derivation (pure function of canonical values):

| State | Condition |
|---|---|
| `PENDING_LOADING` | `confirmed_at IS NULL` |
| `WAREHOUSE_CONFIRMED` / `AWAITING_DRIVER_CONFIRMATION` | `confirmed_at` set, no open adjustment, `driver_confirmed_at IS NULL` |
| `ADJUSTMENT_REQUESTED` | an `open` row exists in the log |
| `AWAITING_DRIVER_RECONFIRMATION` | last log row `resolved`, and `driver_confirmed_at IS NULL OR driver_confirmed_at < confirmed_at` |
| `DRIVER_CONFIRMED` | `driver_confirmed_at >= confirmed_at`, no open adjustment |

`AWAITING_DRIVER_RECONFIRMATION` falls out of the timestamp comparison
`driver_confirmed_at < confirmed_at` — a warehouse re-confirmation **automatically
invalidates** a stale driver confirmation with no extra field and no reset step. This is the
single most useful property of the derived model.

### Transitions

| From | Action | Actor | To | Writes |
|---|---|---|---|---|
| PENDING_LOADING | Confirm loaded qty | Warehouse | WAREHOUSE_CONFIRMED | `quantity_loaded`, `confirmed_by/at` |
| WAREHOUSE_CONFIRMED | Confirm received | Driver | DRIVER_CONFIRMED | `driver_received_qty`, `driver_confirmed_by/at` |
| WAREHOUSE_CONFIRMED | Request adjustment | Driver | ADJUSTMENT_REQUESTED | log row `open` (**never** `quantity_loaded`) |
| ADJUSTMENT_REQUESTED | Revise + reconfirm | Warehouse | AWAITING_DRIVER_RECONFIRMATION | `quantity_loaded`, `confirmed_at`; log → `resolved` |
| ADJUSTMENT_REQUESTED | Reject | Warehouse | AWAITING_DRIVER_RECONFIRMATION | log → `rejected`; `quantity_loaded` unchanged |
| AWAITING_DRIVER_RECONFIRMATION | Confirm received | Driver | DRIVER_CONFIRMED | driver columns |

### Invalid transitions (refused server-side)

- Driver → `quantity_loaded` (any path). **Never.**
- Driver confirming while an adjustment is `open`.
- Second `open` adjustment on the same task.
- Warehouse writing `driver_confirmed_*`.
- Any transition on a task whose session is not `Loading`.
- Confirming a quantity that no longer matches what the actor saw (§7 below).

## 6. Actors and permissions (§9, §10, §11)

**No new permission is required.** The `module.resource.action` catalogue already contains
exactly the two capabilities this workflow needs, and they already split along the §11
boundary:

| Capability | Permission | Actor | Status |
|---|---|---|---|
| Start Loading | `operations.preparation.update` | Warehouse | ✅ already held — **works today** |
| Read the manifest | `operations.preparation.view` | Warehouse | ✅ already held — **works today** |
| Edit + confirm Loaded, review/revise/reconfirm adjustment | **`loading.session.operate`** | Warehouse | ⚠️ **exists, granted to Company Admin only** |
| Driver view / confirm received / request adjustment | **`loading.driver.operate`** | Driver | ⚠️ **exists, granted to Company Admin only** |

Warehouse-side adjustment review reuses `loading.session.operate` rather than a new
`loading.adjustment.review`: it is the same actor doing the same job on the same row — the
precedent stated verbatim on the `assign-vehicle` route ("no new permission is created,
because assigning a vehicle is the same actor doing the same job").

**This is a grants problem, not a naming problem** — and `config/permissions.php` itself
records why: the `loading.*` names *"were previously defined nowhere, so the whole module
was super-admin-only"*. The catalogue was retro-added; the grants to operational roles were
never completed. Live matrix today: Warehouse Operator holds 15 permissions but not
`loading.session.operate`; the **Driver role holds exactly three** — `logistics.distribution.update`,
`logistics.distribution.view`, `logistics.shipping.view` — and not `loading.driver.operate`.

## 7. Concurrency (§13)

Scenario: warehouse confirms 3 → driver opens the page → warehouse revises to 2 → driver
presses Confirm.

**Decision: value-based preconditions + pessimistic row lock. No `version` column.**

- Every driver write carries `expected_loaded_qty` (what the driver's screen displayed).
  The server takes `lockForUpdate` on the task and compares. Mismatch → **409**, the client
  refetches and shows the new number. This kills stale confirmation and lost updates
  **without any schema for versioning**.
- A warehouse re-confirmation moves `confirmed_at` forward, which by §5's derivation makes
  any earlier `driver_confirmed_at` stale automatically — so the driver is returned to
  `AWAITING_DRIVER_RECONFIRMATION` by construction, not by a reset routine.
- An adjustment request also carries `expected_loaded_qty`, so a request can never be filed
  against a quantity that has already moved.

## 8. Idempotency (§14)

| Action | Mechanism |
|---|---|
| Start Loading | existing — `open()` locates-or-creates under lock (already certified) |
| Warehouse confirm | absolute-set on `UNIQUE (vehicle_assignment_id, product_id)`; re-posting the same value is a no-op |
| Driver confirm | setting `driver_confirmed_at` when already set at the same `confirmed_at` generation is a no-op |
| Adjustment request | "one `open` per task" under `lockForUpdate`; a second click returns the **existing** open row rather than creating a second |
| Warehouse revise | absolute-set + resolving the one open row; replay yields the same end state |

Double-click therefore produces no duplicate confirmation, adjustment, or log row.

*Observation while reading indexes:* `loading_tasks` carries **two identical unique indexes**
on `(vehicle_assignment_id, product_id)` — `uq_loading_tasks_assignment_product` and
`loading_tasks_assignment_product_unique`. Harmless but redundant; worth a cleanup ticket,
not part of this design.

## 9. Over-loading and partial loading (§16, §17)

**No new rule invented.** `LoadProductAction` already states the contract in code:

> *"loaded may fall short (ShortLoaded) but must never exceed it. There is no approved
> over-load contract, so — like over-delivery — it is refused."*

So **`Loaded ≤ Required` is refused server-side today**, and at group grain
`quantity_planned` **is** the Group's live Required. Enforcement stays exactly where it is —
in `LoadProductAction`, the backend authority. The UI must not re-implement it, and must not
block anything the backend allows.

**Partial loading is fully supported and must not read as complete:** `Required 10 / Loaded 6
→ Remaining 4`, group **not** fully loaded. The driver may confirm 6 (that is the honest
receipt), and may instead request an adjustment to 5 — which goes through the adjustment
workflow like any other, never a direct write.

## 10. Completion (§18)

Unchanged from the certified rules; Prepared plays no part:

| Level | Complete when |
|---|---|
| Product | `driver_confirmed_at >= confirmed_at` and no open adjustment |
| Order / Group | every eligible product's Loaded ≥ Required (i.e. `Remaining = 0`) — **never** Prepared = Required |
| Vehicle assignment | existing `VehicleAssignmentStatus::LoadingComplete` |
| Session | existing `CompleteLoadingAction` — requires no task left `pending`/`in_progress`, then `LoadingSessionStatus::LoadingComplete` |

## 11. Entities reused vs deliberately rejected (§19)

**Reused as-is:** `LoadingSession` · `LoadingTask` (+ its unique key) · `VehicleAssignment` ·
`GroupLoadingContextService::open` / `readiness` · `LoadProductAction` (the only writer of
`quantity_loaded`) · `VehicleInventoryService` · `groupLoadingPreparation` ·
`productAggregation` · `GroupPreparationService` · `LoadingSessionPolicy` ·
the group-grain read endpoints.

**Deliberately NOT reused:**

| Entity | Why not |
|---|---|
| `ShipmentGroup` | wrong grain — geography × shipping company *inside a session*, requires `loading_session_id`. Not the Distribution Group |
| `TripCustody.received_quantity` | **equipment and cash float** per its own docblock; no `product_id`. Its *shape* is the precedent, its *rows* are not the home |
| `Trip.driver_accepted_products` | trip-level boolean, all-or-nothing; cannot express per-product |
| `loading_exceptions` | real lifecycle but **no quantity column** — a workflow-driving number must not live in free text |
| `LoadingTaskStatus` | do not extend; existing consumers switch on it (§5) |

## 12. Events (§21)

**Decision: add no events now.**

The audit requirement (§12) is satisfied by `loading_task_adjustment_log`, which records
before/after/actor/reason/at as data. Events exist for *cross-module reaction*, and no
consumer for these facts exists. §21 says not to add events for tidiness, so none are
proposed.

If a consumer later appears, the shape is already decided: `AllocationAdjusted`
(`loading.allocation.adjusted`, with `quantityBefore/After/actorType/actorId/reason`) is
**dormant — defined but never dispatched** — and a `loading.task.quantity_adjusted` sibling
would mirror it exactly. Event ownership would sit with Operations\Loading, matching the
existing `eventType`/`version` convention.

## 13. API contract — design only (§20, §22)

| # | Action | Actor | Endpoint | Input | Idempotency | Auth |
|---|---|---|---|---|---|---|
| 1 | Start Loading | Warehouse | **existing** `POST …/slots/{s}/trips/{t}/loading` | — | locate-or-create | `operations.preparation.update` ✅ |
| 2 | Confirm loaded qty | Warehouse | `POST /api/loading/groups/{slot}/products/{product}/confirm` | `quantity_loaded`, `expected_loaded_qty?` | absolute-set on unique key | `loading.session.operate` ⚠️ |
| 3 | Driver manifest | Driver | **existing** `GET /api/driver/loading` (extend payload) | — | read | `loading.driver.operate` ⚠️ |
| 4 | Confirm received | Driver | `POST /api/driver/loading/products/{product}/confirm` | `received_qty`, `expected_loaded_qty` | no-op if already confirmed at same generation | `loading.driver.operate` ⚠️ |
| 5 | Request adjustment | Driver | `POST /api/driver/loading/products/{product}/adjustment` | `reported_qty`, `reason?`, `expected_loaded_qty` | one `open` per task under lock | `loading.driver.operate` ⚠️ |
| 6 | Review + revise/reject | Warehouse | `POST /api/loading/groups/{slot}/products/{product}/adjustment/{id}/resolve` | `action`, `quantity_loaded?` | resolves the one open row | `loading.session.operate` ⚠️ |

Writes 2 and 6 **delegate to `LoadProductAction`** — the single existing engine — rather than
touching `quantity_loaded` directly. Slot-scoped paths mirror the group-grain read already
shipped, so the warehouse UI needs no session/assignment ids.

**Read models (§22) are derived per read — no persisted snapshot.** Warehouse sees
Required / Prepared / Loaded / Remaining / confirmation state; Driver sees Required / Loaded
by Warehouse / Driver Received / Difference / state. Both come from the same canonical
manifest, so they cannot disagree.

## 14. Security (§25)

- **Driver cannot modify `quantity_loaded`** — no driver endpoint accepts it; only
  `LoadProductAction` writes it, reachable only under `loading.session.operate`.
- **Driver cannot approve their own adjustment** — resolution requires
  `loading.session.operate`, which the Driver role must not hold.
- **Driver cannot reach another driver's task** — reuse the certified D-02 fail-closed chain:
  driver resolved from the token via `logistics_drivers.user_id`, then their own active
  Trip, then the Trip's Group re-fenced to the actor's company.
- **Warehouse cannot fake driver confirmation** — warehouse endpoints never write
  `driver_confirmed_*`; separate columns, separate permission, separate endpoint.
- **Tenancy:** company on every row; warehouse via the Group (`virtual_slot_id →
  warehouse_id`, derived not copied); trip/group boundary via `vehicle_assignments.trip_id →
  distribution_trips.virtual_slot_id`.

## 15. Worked example (§15)

```
Required 3.  Warehouse confirms 3.
  loading_tasks: quantity_loaded=3, confirmed_at=T1     → WAREHOUSE_CONFIRMED
Driver received 2 → Request Adjustment (reported 2, expected_loaded_qty 3)
  log: action=driver_requested, actor_type=driver,
       quantity_before=3, driver_reported_qty=2, status=open
  loading_tasks: UNCHANGED (quantity_loaded still 3)    → ADJUSTMENT_REQUESTED
Warehouse revises to 2 and reconfirms
  loading_tasks: quantity_loaded=2, confirmed_at=T2
  log row → status=resolved, quantity_after=2, resolved_by/at
  + new log row: action=warehouse_revised, actor_type=warehouse,
                 quantity_before=3, quantity_after=2
  Round 1 is PRESERVED                                  → AWAITING_DRIVER_RECONFIRMATION
Driver confirms 2
  loading_tasks: driver_received_qty=2, driver_confirmed_at=T3 (T3 > T2) → DRIVER_CONFIRMED

Final: Required 3, Loaded 2, Remaining 1, Driver confirmed.
```

## 16. Backward compatibility (§23)

Nothing in this design alters an existing column, table, enum, route, permission or service.
All new columns are nullable with no default → **no backfill**. `LoadingTaskStatus` is
untouched. `Remaining = Required − Loaded` is unchanged, so the two certified tests
(`test_prepared_is_never_reported_as_loaded`,
`test_remaining_is_required_minus_loaded_not_required_minus_prepared`) and the
**9/9 focused**, **115/115 regression** and **19/19 frontend** suites remain valid.

---

# RECOMMENDED ARCHITECTURE

**Extend `loading_tasks` with three nullable driver columns, add one append-only
`loading_task_adjustment_log` table, derive the workflow state from those values, and reuse
the two existing `loading.*` permissions — granting them to the roles that were always meant
to hold them.**

## WHY

1. **One writer per fact.** Warehouse writes `quantity_loaded`; driver writes only
   `driver_*`. §11's separation becomes structural, not procedural.
2. **The state cannot drift** because it is derived. A warehouse re-confirmation invalidates
   a stale driver confirmation via `driver_confirmed_at < confirmed_at` — no reset logic, no
   second status column to contradict the quantities.
3. **History cannot be lost.** An append-only log satisfies §5F and §12 in one object, and
   copies a table that already exists in this module.
4. **Nothing is invented.** Column pair from `TripCustody`; log shape and name from
   `vehicle_plan_adjustment_log`; `actor_type`/`actor_id`/`quantity_before`/`quantity_after`/
   `reason` from `AllocationAdjusted`; locking from the 20 existing `lockForUpdate` sites;
   idempotency from the existing unique key.
5. **Smallest surface that is still honest.** Three nullable columns and one table. The
   cheaper alternative — an `adjustment_qty` column on `loading_tasks` — is rejected because
   round 2 overwrites round 1, which §11 forbids outright.
6. **No new permission**, so the RBAC catalogue does not grow to describe a workflow it
   already describes.

## REQUIRED CHANGES (none performed)

**A. Schema** — `loading_tasks` + `driver_received_qty`, `driver_confirmed_at`,
`driver_confirmed_by` (all nullable, no backfill); new `loading_task_adjustment_log`
(§4b). Claim the dormant `confirmed_by`/`confirmed_at` for warehouse confirmation — no
migration needed for that half.

**B. Backend** — 5 thin endpoints (§13) delegating to `LoadProductAction`; one
`LoadingTaskWorkflowState` derivation helper; one adjustment service enforcing
one-open-per-task under `lockForUpdate`; extend the driver manifest payload.

**C. Permissions** — grant **existing** `loading.session.operate` to Warehouse
Operator/Manager and `loading.driver.operate` to Driver. **No new permission.**

**D. Events** — none. Reserve the dormant `AllocationAdjusted` shape if a consumer appears.

**E. Frontend** — warehouse: editable Loaded + Confirm per row, adjustment review panel;
driver: Required / Loaded by Warehouse / Difference + Confirm Received / Request Adjustment.
Both read the canonical manifest; refetch after every mutation; read failure never rendered
as `0` or "not loaded" (§20 of the prior task, already implemented).

**F. Tests** — §31's E–Q, which the current schema cannot express; plus concurrency (stale
confirm → 409), idempotency (double-click), and the permission boundary in both directions.

---

# OWNER DECISIONS

### OWNER DECISION #1 — Approve the schema change?

The workflow **cannot** be built without it: driver-received quantity, per-product driver
confirmation and adjustment history have no representation, and free text is not an
acceptable home for a quantity that drives a workflow.

**RECOMMENDED: APPROVE** the minimal set in §4 — three nullable columns on `loading_tasks`
plus `loading_task_adjustment_log`. No backfill, no existing column altered, no enum
extended.

### OWNER DECISION #2 — Grant the two existing permissions to their intended roles?

`loading.session.operate` → Warehouse Operator / Warehouse Manager;
`loading.driver.operate` → Driver. Today both sit with Company Admin only, which
`config/permissions.php` records as an artefact of the module having been super-admin-only.

**RECOMMENDED: APPROVE the grants.** No new permission is created; this completes a
catalogue that was retro-added and never finished.

> **Note the standalone consequence of not deciding this:** the already-certified WAVE-1
> driver loading endpoints are **currently unreachable by any Driver in this deployment**.
> That is true today, independently of this workflow.

### OWNER DECISION #3 (smaller) — Reject vs revise on an adjustment

§12 of the brief describes only *Accept / Edit*. The design also allows the warehouse to
**reject** a request (log → `rejected`, `quantity_loaded` unchanged), for the case where a
recount shows the original figure was right.

**RECOMMENDED: include reject.** Without it, a warehouse that disagrees has no move except
to change a correct number — and the driver is left blocked.

---

## ARCHITECTURE STATUS

**BLOCKED — OWNER DECISION REQUIRED**

The architecture is complete, internally consistent, precedented in existing ECOS code, and
ready to implement — but it cannot begin until Decisions #1 and #2 are made, because one
requires a migration and the other requires a permission grant, and both are explicitly
reserved to you.

**Nothing was implemented. No file, schema, permission, test or container was touched.**
**Stopping here.**

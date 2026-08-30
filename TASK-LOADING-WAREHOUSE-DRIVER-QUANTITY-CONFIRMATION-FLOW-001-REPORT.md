# TASK-LOADING-WAREHOUSE-DRIVER-QUANTITY-CONFIRMATION-FLOW-001

**Status: PARTIALLY IMPLEMENTED / BLOCKED — OWNER DECISION REQUIRED**

Implemented: **Start Loading** (§2, §28, §30) — the only requested piece the current
schema *and* the current permission matrix both support.

**STOPPED before migration**, exactly as §23 requires. **Two independent blockers** prevent
the Warehouse→Driver confirmation workflow, and neither can be resolved without an owner
decision:

1. **SCHEMA** — driver-received quantity, per-product driver confirmation, and the
   adjustment request (with its quantity) have **no representation anywhere**.
2. **PERMISSIONS** — the write paths the workflow needs are held by **Company Admin only**.
   In the live matrix the **Driver role cannot use the driver loading endpoints at all.**

Migration: **NONE** · DB writes: **NONE** · Backend files changed: **NONE**
Commit: **NONE** · Push: **NONE** · Deploy: **NONE**

Date: 2026-08-26 · Branch: `develop`

---

## 1. Architecture inspected

`CreateLoadingSessionAction` · `GroupLoadingContextService` (`open`, `readiness`) ·
`LoadProductAction` · `VehicleInventoryService` · `LoadingSession` / `LoadingTask` /
`VehicleAssignment` models · `LoadingTaskStatus`, `LoadingSessionStatus`,
`LoadingExceptionStatus`, `ExceptionSeverity` enums · `loading_exceptions` +
`RaiseLoadingExceptionAction` · `LoadingSessionPolicy` · `VehicleAssignmentController` ·
`DriverLoadingController` · `TripCustody` + `TripService::confirmCustody` ·
`Trip.driver_accepted_products` · `groupLoadingPreparation` ·
`DistributionAggregationService::productAggregation` · `GroupPreparationService` ·
`useGroupRequiredProducts` / `useGroupTrips` · the live `roles`/`permissions` matrix ·
every `loading|custody|adjust|discrep` table in the schema.

## 2. What WAS implemented — Start Loading

**Reuses the certified action. No parallel workflow, no new endpoint, no backend change.**

```
POST /logistics/distribution/windows/{w}/slots/{s}/trips/{t}/loading
  → GroupLoadingContextService::open()
```

- **Idempotent (§30):** `open()` LOCATES the session and vehicle assignment under a lock and
  creates them only if absent, so a second press yields the same two rows. The panel shows
  **Loading in progress** when `has_loading_assignment` is already true.
- **Readiness respected (§28):** the button is enabled only when the server's own transport
  state is `ready` (trip + vehicle + driver). `open()` re-runs its guards and refuses
  regardless — the button is a courtesy, never the protection — and **its refusal is
  surfaced verbatim**. No bypass.
- **It loads nothing (§7):** opening the session records no quantity. A test asserts the
  product row still reads `Required 10 / Prepared 10 / Loaded 0 / Remaining 10` after a
  successful start.
- **Permission-compatible:** the route carries `operations.preparation.update`, which
  Warehouse Operator, Warehouse Manager and Preparation Supervisor **do** hold.
- **Unreadable state is not ready:** when the manifest read fails the button is disabled, so
  an outage can never let a doomed — or duplicate — write through on an assumption.

## 3. BLOCKER 1 — schema cannot represent the workflow (§23)

| Requirement | Exists? | Evidence |
|---|---|---|
| Required | ✅ | live `productAggregation` |
| Prepared | ✅ | `distribution_group_product_preparation` |
| Loaded (warehouse) | ✅ | `loading_tasks.quantity_loaded` |
| Warehouse confirmed who/when | ⚠️ | `loading_tasks.confirmed_by` / `confirmed_at` exist in `$fillable` but **no production code writes them** — dormant, and carrying no "warehouse vs driver" semantics |
| **Driver received qty, per product** | ❌ | **no column anywhere.** `TripCustody.received_quantity` is *equipment and cash float* — the model's own docblock says so, and it has **no `product_id`** |
| **Driver confirmation, per product** | ❌ | `Trip.driver_accepted_products` is a **trip-level boolean** (all-or-nothing), written by `TripService` |
| **Adjustment request quantity** | ❌ | `loading_exceptions` has `exception_type/severity/entity_type/entity_id/description/status` — **no quantity column**. The driver's "received 2" could only live as free text |
| **Workflow states (§15)** | ❌ | `LoadingTaskStatus` = `pending, in_progress, loaded, short_loaded, blocked, skipped`. No awaiting-driver, driver-confirmed, adjustment-requested or revised state |
| **Audit of a quantity change (§14)** | ❌ | `quantity_loaded` is written by **absolute set** — a revision *overwrites* the previous value. There is no history table for `loading_tasks`, so "previous → new, by, at, reason" has nowhere to live |

`quantity_short` is **not** a substitute: `LoadProductAction` writes it as
`planned − loaded`, i.e. the **warehouse** shortfall, not anything the driver reported.

**Conclusion:** §11 ("no overwrite without trace") and §13 ("driver request ≠ automatic
change") are **not expressible** today. Implementing the flow on free-text
`loading_exceptions.description` would put a workflow-driving quantity into prose — exactly
the kind of un-validatable second source of truth this codebase has repeatedly refused.

### Proposed MINIMAL migration — NOT WRITTEN, NOT RUN

Two parts. Nothing below has been created.

**(a) Driver receipt, on `loading_tasks`** — mirrors the *existing* `TripCustody` pattern
(`quantity` handed out vs `received_quantity` counted, plus `driver_confirmed_at/by`), so it
introduces no new idea, only the same idea at product grain:

```
driver_received_qty    DECIMAL(18,4) NULL   -- what the driver counted
driver_confirmed_at    TIMESTAMP     NULL
driver_confirmed_by    CHAR(36)      NULL
```

**(b) Adjustment trace — a new table, not more columns**

```
loading_task_quantity_adjustments
  id, company_id, loading_task_id,
  previous_qty, requested_qty, confirmed_qty,
  status,                       -- requested | accepted | rejected
  requested_by, requested_at, reason,
  resolved_by, resolved_at
```

A table rather than columns **because §11 forbids overwrite without trace and §14 wants
previous/new/by/at/reason**: columns would hold only the latest round and would be
overwritten on the second adjustment, which is precisely the defect to avoid.

**States:** do **not** extend `LoadingTaskStatus` — existing consumers switch on it. A
separate nullable `driver_confirmation_state` (or deriving state from the columns above)
leaves the certified enum untouched.

**`confirmed_by`/`confirmed_at`:** recommend claiming these for the **warehouse**
confirmation (§6), since they already sit on the warehouse-owned row and are currently
dormant — no migration needed for that half.

## 4. BLOCKER 2 — permissions (§24)

Read from the **live** matrix, not from config:

| Permission | Held by |
|---|---|
| `loading.session.operate` | **Company Admin only** |
| `loading.session.create` | **Company Admin only** |
| `loading.driver.operate` | **Company Admin only** |
| `operations.preparation.update` | Company Admin, **Warehouse Manager, Warehouse Operator**, Preparation Supervisor, … |

**Warehouse Operator** holds 15 permissions — `operations.preparation.view/update` among
them — but **not** `loading.session.operate`. So it **cannot record a loaded quantity**
through the existing write path (`POST /loading/sessions/{s}/assignments/{a}/load-product`,
which authorizes `operate`).

**The Driver role holds exactly three permissions:**
`logistics.distribution.update`, `logistics.distribution.view`, `logistics.shipping.view`.
It does **not** hold `loading.driver.operate`.

> **Therefore the already-certified WAVE-1 driver loading endpoints
> (`GET /api/driver/loading`, `POST /api/driver/loading/products/{id}`) are unreachable by
> any actual Driver in this deployment.** That is a pre-existing gap this task surfaced, not
> one it created.

§24 says to use existing permissions and not to widen scope arbitrarily. Granting
`loading.session.operate` to warehouse roles, or `loading.driver.operate` to the Driver
role, **is** widening scope — so it is an owner decision, not something to slip in.

**Options for the owner** (no recommendation acted on):

- **A.** Grant the existing permissions to the intended roles (smallest change; widens two
  existing capabilities).
- **B.** Gate the two write paths by ACTOR instead of owning module — the precedent already
  set by `PUT …/preparation/{product}` (`operations.preparation.update`) and by the Loading
  read this task's predecessor added (`operations.preparation.view`). No permission created.
- **C.** Leave as-is: the workflow then remains Company-Admin-only, which contradicts §24's
  intent that warehouse and driver roles operate it.

## 5. Files changed

| File | Change |
|---|---|
| `loading-os/services/loading-os-service.ts` | `startLoading()` → the existing certified endpoint |
| `loading-os/hooks/use-loading-os.ts` | `useStartLoading()` + invalidation of list & manifest |
| `loading-os/components/loading-groups.tsx` | Start Loading footer: button, in-progress note, verbatim server refusal |
| `loading-os/components/loading-groups.test.tsx` | +5 tests |
| `i18n/locales/{en,ar}/operations.json` | +6 keys each (**55/55** under `loadingOs.groups`) |

**Backend files changed: NONE.** No controller, service, action, model, enum, route,
permission, migration or event.

## 6. Semantics preserved (§1, §32)

Untouched and re-proven green:

- `test_prepared_is_never_reported_as_loaded`
- `test_remaining_is_required_minus_loaded_not_required_minus_prepared`

`Required 10 / Prepared 10 / Loaded 0 → Remaining 10` and
`Required 10 / Prepared 10 / Loaded 6 → Remaining 4` both still hold. Nothing added converts
Prepared into Loaded, and **Start Loading records no quantity** (§7), asserted by a new test.

## 7. Tests

| Suite | Result |
|---|---|
| `loading-groups.test.tsx` | **14/14** (9 existing + **5 new** for Start Loading) |
| `loading-os-service.test.ts` | **5/5** |
| **Frontend total** | **19/19** |
| Backend focused (`GroupLoadingWorkspaceReadTest`) | **9/9, 98 assertions** — unchanged, no backend edited |
| Backend regression (6 suites) | **115/115, 1364 assertions** — unchanged, no backend edited |
| `tsc -p tsconfig.app.json` | **23 = baseline**, 0 in touched files |
| ESLint (`loading-os`) | **clean, exit 0** |
| EN/AR parity | **0 missing**, `loadingOs.groups` 55/55 |

New Start Loading tests: disabled without vehicle+driver · disabled when the state cannot be
read · calls the action with canonical `{windowId, tripId}` · **no loaded quantity changes
on start** · server refusal surfaced verbatim.

Of §31's A–R, only **A, B, C, D, R** are covered — the rest (E–Q) test functionality that
Blockers 1 and 2 prevent from existing.

## 8. Browser verification

**NOT PERFORMED.** Two reasons, both standing:

1. **Environment:** the app redirects to `/app/login`; the dev DB holds exactly one user
   (`admin@ecos.local`, SYSTEM role); I have no credentials and do not enter any. Creating a
   warehouse or driver user would breach §34.
2. **The scenario does not exist yet.** §33's script requires warehouse confirmation and
   driver confirmation, which Blocker 1 makes unrepresentable. There is nothing to walk
   through.

Start Loading itself was not exercised in a browser, because doing so would create a **live**
Loading Session — a real write on live data that §34 rules out.

## 9. Data safety

**No business data was written by this task.** No migration, seed, factory, session, trip,
assignment, task or inventory movement. Database access was read-only (`SELECT` /
`information_schema` / role matrix). No `docker cp`, no restart.

*Context from the preceding verification pass, unchanged and disclosed there:* live
`loading_sessions` = 1 and `vehicle_assignments` = 1 were created at 22:58 UTC by an
authenticated `admin@ecos.local` session — not by this work, which performed no writes and
never authenticated.

## 10. Unresolved architectural blockers — owner decisions

1. **Schema (§3).** Approve the minimal migration, or reject the workflow as specified.
   Nothing is implementable in between: driver confirmation and adjustment requests have no
   representation, and free-text is not an acceptable home for a workflow quantity.
2. **Permissions (§4).** Choose A, B or C. Until then the workflow is Company-Admin-only,
   and **drivers cannot reach the driver loading endpoints at all**.
3. **Pre-existing:** the group-grain pool-provenance migration is absent and unapplied in
   `ecos-dev-app` (present in the testrunner), so the WAVE-1 driver write path is
   test-provable but not deployable in dev.

---

## Final status

**PARTIALLY IMPLEMENTED / BLOCKED — OWNER DECISION REQUIRED**

Start Loading is delivered against the certified idempotent action, gated by real readiness,
recording no quantity, and covered by tests. The Warehouse→Driver quantity confirmation and
adjustment workflow is **not** implemented: it requires a schema change (§3) and a permission
decision (§4), and §23 directs me to stop before the migration rather than invent a data
model.

**Not certified. No migration. No backend change. No further phase started.**

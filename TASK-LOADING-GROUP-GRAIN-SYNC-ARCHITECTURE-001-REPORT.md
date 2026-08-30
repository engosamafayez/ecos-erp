# TASK-LOADING-GROUP-GRAIN-SYNC-ARCHITECTURE-001

**Status: ARCHITECTURE DIAGNOSIS COMPLETE / IMPLEMENTATION FROZEN**

Date: 2026-08-26 · Branch: `develop` · Nothing implemented, nothing committed, nothing deployed

---

## 1. Executive Summary

**The answer to §11 is: YES — the business contract is achievable with the existing
architecture, with ZERO schema changes, ZERO new entities, ZERO events and ZERO
synchronization code.**

The brief warned against assuming the solution is "make `vehicle_id` nullable" or "create a
Loading Session without a Vehicle". **Both assumptions are wrong, and neither is needed.**

The decisive discovery: a canonical, live, Group-grain product projection **already exists,
is already certified, and is already exposed on a route that requires no Trip, no Vehicle, no
Driver and no Loading Session**:

```
GET /logistics/distribution/windows/{window}/products?slot_id={groupId}
    → DistributionWindowController::products()
    → groupLoadingPreparation($group)
    → [{ product_id, product_name, sku, unit, total_quantity (Required),
         prepared_qty, remaining_qty, over_prepared_qty }]
```

Verified against live data — both real Groups would render **immediately**, today:

| Group | Orders | Loading-eligible | Would render products |
|---|---|---|---|
| DG-001 | 8 | **8** | **Yes** |
| DG-003 | 1 | **1** | **Yes** |
| DG-TPL-VERIFY | 0 | 0 | No — it has no orders, so the contract does not apply |

**The real defect is that the Loading Workspace asks the wrong question.** It asks
`GET /loading/sessions` — "which Loading Sessions exist?" — when the contract's question is
"which Groups hold orders?". Sessions are a *warehouse-day execution artifact*; they are the
wrong grain and always were.

**Recommended correction: make the Loading Workspace GROUP-FIRST rather than SESSION-FIRST.**
That is a read-path change consuming two existing endpoints. It is not a migration, not a
projection table, and not a sync pipeline.

**Critically — no synchronization mechanism should be built.** Required is *derived live*
from the Group's orders on every read. A live derivation cannot drift, cannot duplicate,
cannot double-count and cannot lose quantities. Building an event + listener + projection
would manufacture exactly the "snapshot غير متزامن مع الـGroup" the brief forbids, and would
introduce an idempotency problem that does not currently exist.

## 2. Current Loading Contract (as built)

| Aspect | Reality |
|---|---|
| Grain of `loading_sessions` | **(company, warehouse, operational_date)** — a warehouse-day, not a group |
| Group link on a session | **None.** No `virtual_slot_id`, no `group_id`, no `trip_id` |
| Group's representation inside Loading | a `vehicle_assignments` row (`trip_id` → `distribution_trips.virtual_slot_id`) |
| Products | `loading_tasks`, parented by `vehicle_assignment_id` (NOT NULL) |
| Session creators | `CreateLoadingSessionAction` only — 2 callers |
| Workspace query | `LoadingSessionController::index()` — `company_id` + optional filters; no group/trip/vehicle filter |
| Execution gate | `GroupLoadingContextService::open()` requires Trip (typed param) + vehicle/driver pairing + complete manifest |

## 3. Current Group Contract (as built)

| Aspect | Reality |
|---|---|
| Group entity | `distribution_virtual_slots` (DG-001, DG-003, DG-TPL-VERIFY) |
| Created by | `DistributionWindowController::storeSlot()` :1417 · `GroupTemplateService` :268 |
| Membership | `distribution_window_orders.virtual_slot_id` |
| **Required** | `DistributionAggregationService::productAggregation(windowId, null, slotId, warehouseId)` — **live SUM over `order_lines.quantity`** |
| **Prepared** | `GroupPreparationService::preparedByProduct(groupId)` → `distribution_group_product_preparation` |
| Eligibility gate | `constrainToLoadingEligible()` → `config('distribution.loading_eligible_order_statuses')` = `in_progress, confirmed, ready_for_dispatch`, minus postponed |
| Exposed at | `GET /windows/{window}/slots` (groups) · `GET /windows/{window}/products?slot_id=` (products + Required + Prepared) |

**Neither read requires a Trip, Vehicle, Driver, finalization or Loading Session.**

## 4. Exact Breakpoint

The break is **not** in the data and **not** in a query. It is a **grain mismatch at the
workspace's entry point**:

```
Group + Orders ──► canonical live projection ──► ✅ EXISTS, no prerequisites
                                                    (GET …/products?slot_id=)

Loading Workspace ──► GET /loading/sessions ──► ❌ asks for warehouse-day execution rows
                                                  0 rows exist, because none can exist
                                                  until a Trip + Vehicle + Driver exist
```

The workspace consumes the execution artifact as if it were the planning unit. Everything
below the session — assignments, allocations, inventory — is healthy and simply never
reached.

## 5. Existing Group Loading Architecture (found — must be reused, not rebuilt)

| Component | What it already does | Reuse |
|---|---|---|
| `DistributionAggregationService::productAggregation` | Group live Required, warehouse-scoped, eligibility-gated | **Canonical Required. Reuse verbatim.** |
| `GroupPreparationService::preparedByProduct` | Prepared per product | **Canonical Prepared. Reuse verbatim.** |
| `DistributionWindowController::groupLoadingPreparation` :1236 | Composes Required + Prepared + Remaining + Over-prepared | **The Group manifest. Already exists.** |
| `GET /windows/{window}/products?slot_id=` :1700 | Exposes it, Group-only | **The read the workspace needs.** |
| `GET /windows/{window}/slots` :1696 | Lists the Groups | **The list the workspace needs.** |
| `GroupLoadingContextService::open()` | Idempotent locate-or-create of session + assignment | **Reuse for execution, unchanged.** |
| `LoadProductAction` | Absolute-set idempotent load on `(vehicle_assignment, product)` | **Reuse for execution, unchanged.** |
| `DriverLoadingController` (WAVE-1) | Already serves the **same** canonical Group manifest to drivers | **Precedent — the pattern is proven.** |

**The `openGroupLoading` response already embeds this exact projection** (`'products' =>
$this->groupLoadingPreparation($s)`, :1171). So Loading and Distribution already agree on one
manifest — there is no second aggregation to reconcile.

### Two look-alikes that must NOT be reused

- **`ShipmentGroup` / `shipment_groups`** — **not** the Distribution Group. It is a
  geography × shipping-company batch *inside* a session: `loading_session_id` (FK, required),
  `shipping_company_id`, `zone_id`, `governorate_id`. Wrong concept, wrong grain, and
  session-dependent. Using it because of the name would be a serious error.
- **`loading_sessions`** — a warehouse-day. One session legitimately spans **many** Groups,
  so it can never be the Group's identity.

## 6. Existing Migration Analysis — `allow_group_grain_loading_null_pool_provenance`

**It solves a different half of the problem, and it does not help visibility at all.**

What it does (`2026_08_25_100000`, **untracked in git**, **NOT applied** to `ecos_dev`):

```sql
ALTER TABLE loading_tasks           MODIFY pool_entry_id        CHAR(36) NULL;
ALTER TABLE loading_tasks           MODIFY preparation_wave_id  CHAR(36) NULL;
ALTER TABLE vehicle_inventory_items MODIFY pool_entry_id        CHAR(36) NULL;
```

| Question | Answer |
|---|---|
| Does it relax **pool** provenance? | **Yes** — that is its entire purpose |
| Does it relax **vehicle** dependency? | **No.** It does not touch `vehicle_assignments.vehicle_id` or `loading_tasks.vehicle_assignment_id` |
| Does it enable Group visibility? | **No** — visibility needs no schema change at all |
| Is it still required? | **Yes, for the driver group-grain WRITE path** (TASK-DRIVER-WAVE-1), which is already implemented against it |

**Answer to Q-G: it solves a part, not the whole — and specifically not the part this brief
is about.** It is nonetheless a genuine outstanding item: the WAVE-1 group-grain write path
is implemented on host but its migration has not run on dev, so that path is currently
broken in the running environment.

## 7. Schema Constraints

| Column | Nullable | Consequence |
|---|---|---|
| `vehicle_assignments.vehicle_id` | **NO** | a vehicle-less assignment is impossible |
| `vehicle_assignments.loading_session_id` | **NO** | an assignment requires a session |
| `vehicle_assignments.trip_id` | YES | **Trip is already optional at schema level** |
| `loading_tasks.vehicle_assignment_id` | **NO** | a product row requires an assignment |
| `loading_tasks.pool_entry_id` | NO *(NULL after the pending migration)* | pool provenance — relaxed by §6 |
| `loading_sessions` | — | no group/trip/slot column at all |

**These constrain WRITING loaded quantities. They do not constrain READING a Group's
products, because that read touches none of these tables.** That distinction is the whole
architecture.

## 8. Canonical Quantity Source

**Group live Required — `DistributionAggregationService::productAggregation`.** Not
reservation, not prepared, not loading.

```
Required  = SUM(order_lines.quantity) for the Group's loading-eligible, non-postponed orders
            (live, computed per read)
Prepared  = distribution_group_product_preparation      (GroupPreparationService)
Loaded    = loading_tasks.quantity_loaded               (exists only once execution starts)
Remaining = max(0, Required − Loaded)
```

This is not a proposal — it is the **already-certified** definition, used verbatim by
`openGroupLoading` and by the WAVE-1 driver manifest. Adopting anything else would create the
second source of truth the architecture has repeatedly refused.

**Consequence:** Required has **no persisted copy**, so there is nothing to keep in sync.

## 9. Event / Synchronization Strategy

**Recommendation: NO event, NO listener, NO projection, NO sync job.**

The brief asks which event should trigger sync (Q-I) and whether
`DistributionAssignmentChanged` suffices (Q-J). The honest architectural answer is that
**the question dissolves**: Required is derived live from `distribution_window_orders` +
`order_lines` at read time.

| Group change | Effect on Loading read | Mechanism needed |
|---|---|---|
| Order added | appears on next read | **none** |
| Order removed | disappears on next read | **none** |
| Order quantities changed | reflected on next read | **none** |
| Order status leaves eligibility | drops out on next read | **none** |
| Group created | appears in the group list | **none** |

Introducing an event + listener + stored projection would:

1. create a persisted copy of Required that **can** drift from the Group — precisely the
   unsynchronized snapshot §1 of the brief forbids;
2. create duplicate/double-count risk that does not exist today;
3. require the idempotency machinery of Q-K to defend against a problem it just introduced.

**`DistributionAssignmentChanged` should therefore NOT be wired to Loading.** It is currently
emitted by `ManualAssignmentService` and consumed by nothing — that remains correct.

*If* the owner later requires a frozen manifest (an audit snapshot at a cut-off), that is a
different requirement from this brief, and Finalize's existing `distribution_trip_orders`
snapshot is the established precedent for it.

## 10. Idempotency Strategy

**Idempotency is structural, not implemented.**

| Layer | Guarantee | Source |
|---|---|---|
| Group products read | a pure `GROUP BY` aggregation — repeatable by construction | live derivation |
| Duplicate session | impossible — no session is created for visibility | nothing is written |
| Duplicate loading tasks | absolute-set on `(vehicle_assignment_id, product_id)` | existing, certified |
| Quantity doubling | custody moves by **delta**, ledger positive-only | existing, certified |
| Session/assignment on execute | idempotent locate-or-create under lock | `GroupLoadingContextService` |

Every requirement in §1 of the brief — no duplicate session, no duplicate tasks, no doubled
quantities, no lost quantities — is **already satisfied**, and is satisfied most strongly by
writing nothing at all for visibility.

## 11. Vehicle / Driver / Trip Optionality

**Answers to Q-C / Q-D / Q-E: represent Loading without them by not creating a Loading row
at all until execution begins.**

The current design conflates two distinct things. Separating them resolves the contract:

| | Visibility (planning) | Execution (loading) |
|---|---|---|
| Question | "what must this Group load?" | "what was physically loaded?" |
| Source | live Group projection | `loading_tasks` |
| Needs Vehicle? | **No** | **Yes** — `vehicle_id` NOT NULL |
| Needs Driver? | **No** | Yes — pairing |
| Needs Trip? | **No** | Yes — `open()` typed param |
| Needs Session? | **No** | Yes |
| Exists today? | **Yes** | Yes |

**Q-L — how Vehicle/Driver/Trip attach later without creating a second Loading:** they attach
through the **existing** `GroupLoadingContextService::open()`, whose `resolveSession()` and
`resolveAssignment()` are both idempotent locate-or-create under a lock. Because visibility
created nothing, there is no earlier row to conflict with; and because `open()` is idempotent,
repeating it returns the same session and assignment. **No new Loading is created, by
construction.**

The existing `open()` guards should **remain exactly as they are.** They gate *execution*,
which legitimately requires a vehicle. Nothing in the brief requires loading a product onto a
vehicle that does not exist.

## 12. Proposed Target Flow

```
GROUP CREATED  (storeSlot / GroupTemplateService)
        │
        ▼
GROUP HAS ORDERS   distribution_window_orders.virtual_slot_id
        │
        ▼
VISIBLE IN LOADING WORKSPACE          ← GET /windows/{window}/slots
        │                                (no session, no row written)
        ▼
PRODUCTS + LIVE REQUIRED              ← GET /windows/{window}/products?slot_id=
   Required / Prepared / Remaining       (groupLoadingPreparation — already exists)
        │
        ├─ Group updated (order added/removed/qty changed)
        │        └──► next read reflects it. NO sync step. NO event.
        │
        ▼
[Vehicle assigned]   optional, later   ← existing assign-vehicle (creates Trip on demand)
[Driver assigned]    optional, later   ← same pairing
[Trip]               created by the above
        │
        ▼
LOADING EXECUTION                      ← GroupLoadingContextService::open()  (idempotent)
   session + vehicle_assignment located-or-created
        │
        ▼
LOAD PRODUCT                           ← LoadProductAction (absolute-set, delta to custody)
   Loaded appears against the same Group, same products
```

Every box is an existing component. The only new thing is **which endpoint the workspace
calls first**.

## 13. Required Files (for the future implementation task — NOT touched here)

**Backend — expected to be ZERO changed files.** Both required reads already exist and are
already permissioned. The only open question for the implementation task is whether the
workspace should reach the Distribution endpoints directly or whether a thin Loading-side
read adapter is preferred for module boundaries; the WAVE-1 `DriverLoadingController` is the
established precedent for the adapter shape if one is wanted.

**Frontend — the actual work, and a separate task per §9 of the brief:**

| File | Change |
|---|---|
| `loading-os/services/loading-os-service.ts` | add group-first reads |
| `loading-os/hooks/use-loading-os.ts` | group query replaces session-first entry |
| `loading-os/pages/loading-os-workspace-page.tsx` | render Groups + products; sessions become an execution detail |
| `loading-os/types/loading-os.ts` | Group manifest types |

**Not to be changed:** `LoadingSessionController`, `CreateLoadingSessionAction`,
`GroupLoadingContextService`, `LoadProductAction`, `VehicleInventoryService`,
`DistributionAggregationService`, `GroupPreparationService`.

## 14. Required Schema Changes

**For the contract as stated in §1 of the brief: NONE.**

No migration. `vehicle_id` stays NOT NULL. No new table, no new column, no new index, no new
FK, no new entity.

**One pre-existing item, unrelated to visibility:** the untracked
`allow_group_grain_loading_null_pool_provenance` migration is required by the already-built
WAVE-1 driver group-grain write path and has **not** been applied to `ecos_dev` (§6). That is
an outstanding deployment item from a prior task, not a requirement of this one.

A schema change would become necessary **only** if the owner additionally requires recording
*loaded quantities before any vehicle exists*. The brief does not ask for that — it places
Vehicle/Driver/Trip before "Loading execution" — so it is out of scope. **This is the one
decision I recommend the owner confirm explicitly**, because it is the sole fork between
"zero schema change" and "significant schema change".

## 15. Reusable Existing Components

Fully reusable, unchanged: `DistributionAggregationService::productAggregation` ·
`GroupPreparationService::preparedByProduct` · `groupLoadingPreparation` ·
`GET /windows/{window}/products` · `GET /windows/{window}/slots` ·
`GroupLoadingContextService::open` · `LoadProductAction` · `VehicleInventoryService` ·
`PreparationEligibilityReader` · the WAVE-1 `DriverLoadingController` pattern.

**Nothing in this design is new. The design is a re-pointing of one read.**

## 16. Risks

1. **Naming collision (highest risk).** `ShipmentGroup` sits inside the Loading module and
   sounds like the Distribution Group. It is not (§5). An implementer who reuses it will
   build a session-dependent, shipping-company-scoped structure and reintroduce the exact
   dependency this task removes.
2. **Temptation to create a session for visibility.** This would reintroduce a
   vehicle/trip dependency (a session alone still cannot hold products — `loading_tasks`
   needs an assignment) and would write rows that represent nothing. It gains nothing.
3. **Temptation to build the event pipeline.** It manufactures drift and idempotency
   problems that do not exist (§9).
4. **Eligibility is a real gate.** Orders outside `in_progress / confirmed /
   ready_for_dispatch`, or postponed, do not contribute Required. A Group whose orders are
   all ineligible will legitimately show no products. This is existing certified behaviour
   and must be presented truthfully, not "fixed".
5. **Loaded is absent before execution.** Until a vehicle assignment exists, Loaded is
   genuinely 0 — it must render as "not started", never as a fabricated value.
6. **Window scoping.** Both reads are window-scoped (`/windows/{window}/…`). The workspace
   must resolve the operative window/day; this is the one genuinely new question for the UI
   task, and it should reuse Distribution's existing window resolution rather than invent one.

## 17. Compatibility / Regression Impact

**Backend: none.** No backend behaviour changes, so no backend regression surface. The
Distribution reads are already exercised by the Distribution workspace and by
`openGroupLoading`.

**Frontend:** confined to `features/operations/loading-os`. The existing
`loading-os-service.test.ts` (5 tests) pins the session/assignment envelope shapes and must
keep passing — sessions remain part of the execution view even though they stop being the
entry point.

**Execution path unchanged**, so `GroupTripLoadingIntegrationTest`,
`DriverLoadingCustodyHandoffTest` and `GroupGrainDriverLoadingTest` remain valid.

**The `ilike` defect in `LoadingSessionController::index()` (PostgreSQL syntax on MySQL 8.4)
is untouched and remains latent** — it fires only when a `search` parameter is sent. If the
future UI adds a search box over sessions, it will 500. Recorded, not fixed, per prior briefs.

## 18. Implementation Plan (for owner approval — NOT started)

**Step 0 — Owner decision (blocking).** Confirm that Loading *visibility* is a read of live
Group demand and that *recording loaded quantities* may continue to require a vehicle
assignment. If yes, Steps 1–2 involve **no migration**. If the owner instead requires
loaded-quantity capture with no vehicle, stop and re-scope: that needs schema work and a new
execution parent.

**Step 1 — Frontend, group-first read.** Point the workspace at
`GET /windows/{window}/slots` + `GET /windows/{window}/products?slot_id=`; render Group,
order count, products, Required / Prepared / Remaining; show Vehicle/Driver/Trip as optional
attached facts.

**Step 2 — Execution wiring.** Keep the existing "open loading" path exactly as it is, gated
by the existing guards; surface it per Group with a truthful reason when unavailable.

**Step 3 — Separately, unrelated deployment hygiene.** Apply the outstanding
`allow_group_grain_loading_null_pool_provenance` migration and resolve the container parity
gap (§19). Both belong to prior tasks.

**Verification, when the implementation task is authorised:** frontend tests for the
group-first read including the ineligible-orders and no-vehicle states; no backend suite
should need modification.

## 19. STOP / No Changes Confirmation

**ARCHITECTURE DIAGNOSIS COMPLETE**
**IMPLEMENTATION FROZEN**
**NO DATA CHANGED**
**NO TESTS RUN**
**NO BROWSER RUN**
**NO CONTAINER CHANGES**
**NO COMMIT / PUSH / DEPLOY**

No migration, no DB write, no seed, no data mutation, no `docker cp`, no restart, no browser,
no Vitest, no PHPUnit, no `tsc`, no ESLint, no regression suite, no external API, no commit,
no push, no PR. No React component, hook, layout, empty state or UI text was modified —
frontend files were read only.

Method: read-only code, route, migration, test and report inspection; `SELECT` /
`information_schema` reads; read-only `docker exec` of `stat`/`grep` for parity observation
only.

**Container parity — recorded, not fixed, per §8 of the brief.** `LoadingSessionController`
and `CreateLoadingSessionAction` are in parity host↔container. `LoadProductAction` (host 8803
/ container 8382) and `VehicleInventoryService` (host 15034 / container 13228) are **stale in
the container**. This does not affect the analysis above, and no correction was attempted.

Live state, unchanged: `loading_sessions` 0 · `vehicle_assignments` 0 ·
`distribution_trips` 2 · `distribution_virtual_slots` 3 · grouped orders 9
(DG-001 = 8 eligible, DG-003 = 1 eligible, DG-TPL-VERIFY = 0 orders).

---

## Final answer to the CRITICAL DECISION (§11)

**Can Group-grain Loading be achieved with the existing architecture?**

**For the contract as written — YES, completely, with no schema change.** The canonical
Group manifest already exists, is already certified, is already exposed on a Group-only
route, and is already the manifest Loading itself uses when it opens. The Loading Workspace
simply asks the wrong question at its entry point.

**The minimum change is not architectural and not schema — it is one read path in the
frontend.**

The one boundary that genuinely still requires a vehicle is *recording what was physically
loaded*, and the brief does not ask for that to happen before a vehicle exists.

**Stopping here and awaiting owner approval before any implementation task.**

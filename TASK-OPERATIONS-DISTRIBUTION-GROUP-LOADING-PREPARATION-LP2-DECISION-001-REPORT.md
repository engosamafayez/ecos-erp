# TASK-OPERATIONS-DISTRIBUTION-GROUP-LOADING-PREPARATION-LP2-DECISION-001 — REPORT

**Date:** 2026-08-21
**Status:** ARCHITECTURE / DECISION AUDIT ONLY — nothing implemented. No code, no migration, no schema, no API, no frontend, no permission change, no business data, no Preparation change, no Distribution change, no Loading change, no vehicle change, no commit. All database access was read-only `SELECT`.
**Resolves:** the ten open decisions in `TASK-OPERATIONS-DISTRIBUTION-GROUP-LOADING-PREPARATION-LP2-ARCHITECTURE-001-REPORT.md`.

---

## 1. Executive Summary

All ten decisions are resolved. **Two changed materially once the evidence was gathered**, and one of those reverses the recommendation in the LP-2 architecture report.

### 1.1 The reversal — D-1 permission

The architecture report recommended a new `logistics.distribution.prepare` permission. The live role matrix says that is unnecessary and that the fallback everyone assumes (`logistics.distribution.update`) is affirmatively **wrong**:

| Role | `logistics.distribution.update` | `operations.preparation.update` |
|---|---|---|
| **Warehouse Operator** — the person who separates the goods | **NO** | **yes** |
| Warehouse Manager | **NO** | yes |
| Preparation Supervisor | **NO** | yes |
| Branch Manager | **NO** | yes |
| **Driver** | **yes** | NO |
| Dispatcher | yes | NO |

Gating LP-2's write on `logistics.distribution.update` would **lock out every floor role that does the work and admit Drivers**. `operations.preparation.update` already holds exactly the right actor set, requires **zero RBAC change**, and has an exact precedent in this codebase — `PUT preparation/waves/{w}/missing-materials/{m}/expected-incoming` is a **Preparation route gated by `purchasing.expected_incoming.update`**, chosen by *who does the work*, not by which module owns the route.

**Decision: reuse `operations.preparation.update`.** No permission is created.

### 1.2 The refinement — D-2 eligibility

Widening `config('distribution.eligible_order_statuses')` is **refused**. The census found **9 consumers**, of which **3 are write paths or entry-triage** that must not change:

| Consumer | Kind | Predicate |
|---|---|---|
| `productAggregation()`, `slotRollup()`, `slotOrderCounts()`, `orders()`, `zoneSummaries()` | operational reads | **B — widened** |
| `DistributionCollectionService::eligibleUnassignedOrders()` | **WRITE** — creates window membership | **A — unchanged** |
| `OrderCityBinder::bindForCompany()` | **WRITE** — writes `orders.logistics_city_id` | **A — unchanged** |
| `DistributionAggregationService::lateOrders()` | entry triage, feeds a membership-creating POST | **A — unchanged** |

```
Predicate A  (entry)        = [in_progress, confirmed]                       ← unchanged
Predicate B  (operational)  = [in_progress, confirmed, ready_for_dispatch]   ← new, reads only
```

No Preparation change. No Order-status change. No enum change. Both halves of the postponement rule are preserved because B still routes through the same `excludePostponed()`.

### 1.3 What the evidence confirmed unchanged

**D-3 Group + Product** — evidence sufficient. **D-4 Prepared ≤ Required**, Remaining always derived, existing lock pattern reusable directly. **D-5** `wave_product_demand.prepared_qty` is the authoritative *Preparation* Prepared — and is structurally incapable of being the *Group* Prepared. **D-6** Group + Product anchored to the mutable Group, no membership snapshot. **D-7** one new route genuinely required. **D-8** one new table genuinely required. **D-9** LP-1.0 is an eligibility-predicate change only. **D-10** `capacity_orders` remains the only Group constraint and is **not** a dependency of LP-2 write safety.

### 1.4 No blocker requires a certified contract to change

The audit found **no** case requiring a change to Preparation, Inventory, Wave Completion, Order status, or Actual Loading. Every decision resolves inside Distribution. Three pre-existing defects were surfaced and are recorded as follow-ups, not as LP-2 work (§26).

### 1.5 Sequence

**LP-1.0** (eligibility predicate — repairs the currently-blank LP-1 screen, no new table, no new route, no Preparation change) → **LP-2** (Group+Product Prepared recording) → **LP-2 verification**. Then **STOP**.

---

## 2. Approved Operational Workflow

```
Preparation
    ↓
Group + Loading Preparation          ← ONE workspace (§20, §24)
    ↓
Vehicle + Driver Assignment          ← NOT in scope
    ↓
Review + Finalize                    ← NOT in scope
    ↓
Actual Loading                       ← NOT in scope
    ↓
Dispatch                             ← NOT in scope
```

**Virtual Vehicle Planning is permanently removed** and is not referenced, revived or built upon anywhere in this decision (§19).

Binding constraints carried forward unchanged: a Group is warehouse-owned, zone-based, order-based, vehicle-independent and driver-independent; a Zone is shared geography; `capacity_orders` is the only capacity dimension.

---

## 3. Preparation → Distribution Lifecycle

### 3.1 The corrected mental model

Preparation and Distribution are **consecutive stages of one fulfilment lifecycle**, not competing workflows. The system already encodes this correctly on the Preparation side and incorrectly on the Distribution side.

```
order confirmed / in_progress
        │
        │  DistributionCollectionService  → distribution_window_orders row created
        │                                   (membership is DURABLE from here on)
        ▼
   wave starts
        │  HandlePreparationWaveStarted → MoveToPreparationWorkflow
        │     • reserves inventory (ReserveOrderInventoryAction)
        │     • orders.status := ready_for_dispatch
        ▼
ready_for_dispatch   ──►  "done, waiting to be loaded"
        │                  (HandlePreparationWaveClosed, CASE B, verbatim)
        │
        │  ✗ TODAY: every Distribution read filters this status out
        ▼
   Group + Loading Preparation      ← the stage that needs this population
```

### 3.2 What `ready_for_dispatch` actually means, from the code

`HandlePreparationWaveClosed` classifies orders at wave end into three cases and labels the `ready_for_dispatch` branch **"CASE B — done, waiting to be loaded."** `MoveToPreparationWorkflow`'s own docblock: *"Marks an order as Ready for Dispatch — all engines have completed… transitioning the order to Ready for Dispatch so it can be dispatched."*

So the status is not an exit from fulfilment — **it is the entry ticket to Distribution/Loading**. Distribution excluding it is the defect.

### 3.3 Why membership survives, and why that makes the repair small

`distribution_window_orders` rows are **never deleted** on ineligibility:

- `DistributionCollectionService` — *"This class never touches `orders`."*
- `ManualAssignmentService::detachZone()` sets `virtual_slot_id = NULL` rather than deleting.
- `DistributionAggregationService::orders()` — *"A postponed Order leaves ACTIVE distribution but keeps its assignment row: hiding it is reversible, deleting it would force a re-collection when Preparation resumes the Order."*

**Live proof.** DG-001 still holds all three of its orders in `distribution_window_orders` even though all three are `ready_for_dispatch` and every read returns zero.

The Group→Order link is therefore intact and correct. **Only the read predicate is wrong.** That is why LP-1.0 is a predicate change and not a data repair.

### 3.4 The lifecycle boundary that must not move

| Question | Owner | LP-2 position |
|---|---|---|
| Is the order prepared? | Preparation | reads only |
| What is the order's status? | `FulfillmentEngine` | never writes |
| Is the order in the current preparation cycle? | Preparation, via `PreparationEligibilityReader` | reads only |
| Which Group does the order belong to? | Distribution | reads only |
| How much of a product has been separated **for this Group**? | **Distribution (new)** | **writes** |

---

## 4. D-1 — Permission

### 4.1 Evidence

**The actor.** The person who physically separates goods onto a Group's staging pallet is a Warehouse Operator or Warehouse Manager.

**Live role → permission matrix** (`roles` ⋈ `role_permissions` ⋈ `permissions` on `ecos_dev`):

`logistics.distribution.update` — CEO, CFO, Company Admin, COO, CTO, **Dispatcher**, **Driver**, Operations Director, Shipping Coordinator, Shipping Manager, Warehouse Director.

`operations.preparation.update` — Branch Manager, CEO, CFO, Company Admin, COO, CTO, Operations Director, **Preparation Supervisor**, Production Director, Production Manager, Warehouse Director, **Warehouse Manager**, **Warehouse Operator**.

**Two facts decide this:**

1. **`Warehouse Operator` does not hold `logistics.distribution.update`.** Gating on it excludes the operator from the workflow built for them.
2. **`Driver` holds `logistics.distribution.update`.** Gating on it grants drivers the right to declare warehouse preparation quantities.

**Precedent for gating by actor rather than by module.** `routes/api.php:906` —

```php
Route::put('waves/{waveId}/missing-materials/{materialId}/expected-incoming', …)
    ->middleware('permission:purchasing.expected_incoming.update');
```

A **Preparation** route gated by a **Purchasing** permission, because the actor is a Procurement person. The same reasoning applies in mirror image here.

**Counter-precedent, weighed and rejected.** Part 5C's routes carry the comment: *"Both reuse the existing update permission: they are planning edits inside a Distribution Group… No new permission was introduced."* That reasoning is sound **for planning edits** and does not extend to floor execution — which is exactly the distinction the role matrix draws.

### 4.2 Decision

> **Gate the LP-2 write on `operations.preparation.update`.**
> Reads stay on `logistics.distribution.view`, unchanged.

### 4.3 Rejected alternatives

| Alternative | Why rejected |
|---|---|
| `logistics.distribution.update` | Excludes Warehouse Operator / Manager / Preparation Supervisor / Branch Manager; admits Driver and Dispatcher. Affirmatively the wrong actor set |
| New `logistics.distribution.prepare` | Cleanest long-term separation, but requires a permission, an idempotent `RbacSeeder` entry and a role-matrix decision — all forbidden by this task and none needed to ship correctly. Recorded as follow-up **F-1** |
| `loading.session.operate` | Couples LP-2 to the Actual Loading module it must stay independent of; would grant loading-session rights as a side effect |
| No permission (ownership check only) | `tests/Feature/Security/WriteRouteAuthorizationTest` asserts against the real route table that every write route is authorized. A new route cannot be added to its `ALLOWED` list |

### 4.4 Consequences and limits, stated

Reusing `operations.preparation.update` means anyone who can edit **wave** Prepared can also edit **Group** Prepared. Given that these are the same physical act at two scopes, that is coherent rather than a leak. It also means Production Director / Production Manager gain the right — an over-grant of two manufacturing roles, judged acceptable against locking out the actual operator.

**Warehouse scoping is not a permission concern.** It is enforced at the data layer: the write must derive the warehouse from `distribution_virtual_slots.warehouse_id` (NOT NULL), never from the request. `DistributionWindowController::warehouseId()` reads `warehouse_id` from the **query string** — acceptable for filtering a read, **never** sufficient to authorise a write (§23).

### 4.5 Classification

| | |
|---|---|
| Requires implementation | Yes — one `middleware('permission:…')` on the new route (LP-2) |
| Requires migration | **No** |
| Requires Preparation change | **No** — it consumes an existing permission, it does not modify one |
| Requires RBAC/seeder change | **No** |

---

## 5. D-2 — Distribution Eligibility

### 5.1 Evidence — the complete consumer census

`PreparationEligibilityReader::constrainToEligible()` — **5 call sites, all in `DistributionAggregationService`**:

| Line | Method | What it drives |
|---|---|---|
| 78 | `zoneSummaries()` | the Zones panel |
| 270 | `slotRollup()` | Group `orders_count`, `total_value`, `products_count`, `paid_orders` → `slotSummaries()` |
| 391 | `productAggregation()` | **LP-1's Required projection** |
| 487 | `orders()` | the Window/Group order list (reads FROM `distribution_window_orders`) |
| 829 | `slotOrderCounts()` | `demand_orders` → capacity maths → `slotSummaries()` |

`config('distribution.eligible_order_statuses')` — **4 further consumers outside the reader**:

| Location | Kind | Effect if widened |
|---|---|---|
| `DistributionAggregationService::lateOrders()` (751) | read — entry triage | post-preparation, never-collected orders would enter the manual-assignment list |
| `DistributionCollectionService::eligibleUnassignedOrders()` (251) | **WRITE** — creates `distribution_window_orders` | post-preparation orders would be newly ingested into a Window |
| `OrderCityBinder::bindForCompany()` (52) | **WRITE** — updates `orders.logistics_city_id` | city binding would extend to post-preparation orders |
| `PreparationEligibilityReader` (92, 109) | the reader itself | — |

`excludePostponed()` — **1 external call site**: `DistributionCollectionService:270`.

`RedistributionSuggestionService::overflows()` inherits eligibility through `slotSummaries()` and needs no separate decision.

**A defect found during the census, reported not fixed.** `PreparationEligibilityReader::isEligible()` has **zero callers**. Its docblock claims it is *"Used on the single-Order paths (manual late assignment)"* — but `ManualAssignmentService::assignLateOrder()` checks only tenancy and window state, and performs **no eligibility check at all**. The manual late-assignment write is already status-agnostic. Recorded as **F-2**.

### 5.2 Decision

> **Two predicates. The global config is NOT widened.**
>
> ```
> A  entry/ingestion      = config('distribution.eligible_order_statuses')
>                         = [in_progress, confirmed]                        UNCHANGED
>
> B  operational/loading  = A ∪ [ready_for_dispatch]                        NEW
> ```
>
> Predicate B is exposed as a **second method on the existing reader** — `constrainToLoadingEligible()` — composed from the same `excludePostponed()`, backed by a **new** config key. Neither `OrderStatus::fulfilmentEligible()` nor the existing config key is touched.

**Reads that MUST use B — all five aggregation call sites:**

| Read | Why it must widen |
|---|---|
| `productAggregation()` | It **is** Loading Preparation. Without it the screen is blank — this is the repair |
| `slotRollup()` | Same screen. A Group card reading "0 orders" above a product list would be a direct self-contradiction |
| `slotOrderCounts()` | Same screen, and capacity must count prepared orders — they still occupy the departure |
| `orders()` | The operator must be able to open a Group and see the orders it will load |
| `zoneSummaries()` | A Zone whose orders are all prepared must not read as "no work" |

**Consumers that MUST NOT widen — all three stay on A:**

| Consumer | Why it must not |
|---|---|
| `DistributionCollectionService::eligibleUnassignedOrders()` | It is a **write**. Widening would newly ingest post-preparation orders into today's Window — a change to ingestion semantics with its own certification need. The brief is explicit: *"do not recollect it automatically unless the existing system already does so"* |
| `OrderCityBinder::bindForCompany()` | It is a **write to an Orders column**, in `Modules/Logistics/Geography`. Outside LP-2's boundary; changing which orders get bound is an Orders-population change |
| `lateOrders()` | It is an **entry** question ("never collected — should it be pulled in?") whose sibling POST creates membership. Widening the read while `assignLateOrder()` has no eligibility guard at all (§5.1) would build a UI path to assign orders in an unguarded status |

**Statuses that MUST remain excluded from both predicates:** `out_for_delivery`, `delivered`, `returned`, `cancelled`, `awaiting_payment`, `awaiting_stock`, `scheduled`, `on_hold`. Loading Preparation ends at loading; a loaded or delivered order is not preparation work.

### 5.3 Every mandatory invariant, and how B preserves it

| Invariant | Preserved by |
|---|---|
| Postponed orders stay excluded | B is `excludePostponed($query->whereIn(status, B_LIST))` — the identical `NOT EXISTS` on `released_at IS NULL AND postponed_at IS NOT NULL`. Untouched |
| Cancelled / ineligible orders stay excluded | `cancelled` is in neither list |
| Ineligible orders contribute to no Group total | All five totals go through B, which still excludes them |
| Group structure is never auto-destroyed | No write is involved. Membership rows are untouched by either predicate |
| An order that becomes eligible again returns | Both predicates are evaluated per request; nothing is cached or stored |
| Warehouse scoping | `scopeWarehouse()` is applied **outside** the eligibility wrapper in all five reads and is unchanged |
| Group ownership (Part 5B) | `distribution_virtual_slots.warehouse_id` is untouched; no new warehouse column anywhere |
| Existing membership semantics | `distribution_window_orders` is not written, migrated or re-keyed |

### 5.4 Rejected alternatives

| Alternative | Why rejected |
|---|---|
| Widen `config('distribution.eligible_order_statuses')` globally | Changes **9** consumers including 2 write paths, in one line, with no per-consumer proof. Explicitly forbidden by the brief |
| Add `ready_for_dispatch` to `OrderStatus::fulfilmentEligible()` | That enum also feeds `MoveToPreparationWorkflow::guard()` and `PreparationSessionPolicy` — it would let a `ready_for_dispatch` order re-enter preparation. A **Preparation change**, forbidden |
| Stop moving orders to `ready_for_dispatch` at wave start | A Preparation contract change with inventory reservation bound to it. Forbidden, and rejected by the approved lifecycle |
| Return orders to `in_progress` after preparation | Explicitly forbidden by the brief and would erase the "done, waiting to be loaded" signal |
| A separate `loading_eligible` boolean column on orders | A schema change duplicating a derivable fact, and a second source of truth for status |

### 5.5 Classification

| | |
|---|---|
| Requires implementation | Yes — LP-1.0: one config key + one reader method + five call-site substitutions |
| Requires migration | **No** |
| Requires Preparation change | **No** — the reader still asks Preparation the same two questions |
| Requires Order change | **No** |
| Dependencies | none — this is the root of the sequence |

---

## 6. D-3 — Prepared Attribution

### 6.1 Evidence

**Downstream already re-derives order attribution.** `AutoAllocationService::allocate()` reads the vehicle's **product-level** inventory (`vehicle_inventory_items`) and allocates it against `OrderLine::where('order_id',…)->whereIn('product_id',…)` using `min($line->quantity, $item->quantity_unallocated)`, producing `allocation_records` keyed `unique(vehicle_assignment_id, order_line_id)`. It consumes **no upstream per-order prepared quantity**, and would not read one if it existed.

**Preparation's certified contract is product-level.** `WaveDemandController::updatePrepared()`: *"It is NOT distributed across order_lines and no allocation rule is applied — the operator states one number per product."*

**Order-level Required already exists as a read where needed.** `productRelatedOrders` returns per-order `required_qty` for a `(wave, product)` — derived from `order_lines`, stored nowhere. The same shape is available for a Group without storing anything.

**No order-level Prepared exists.** `order_lines.prepared_qty` is a `float` that nothing has ever written (`ProductDemandCalculator`: *"a column nothing in the codebase ever writes"*), exposed as a permanent `0` by `OrderResource:201`.

### 6.2 Is the evidence sufficient? — Yes, and the test is falsifiable

The evidence would be **insufficient** if any downstream consumer required a per-order prepared figure it could not derive. The audit enumerated every consumer of prepared quantities downstream of preparation — `prepared_products_pool` (product+wave+warehouse), `loading_tasks` (product per vehicle assignment), `vehicle_inventory_items` (product), `allocation_records` (derived from `order_lines`), `RecordProductDeliveryAction` (allocation record) — and **none is order-grained upstream of allocation**. Order attribution first appears at allocation and is computed there.

**Sufficient.**

### 6.3 Decision

> **`Group + Product + Prepared Qty`.** No order dimension in the write model, no order dimension in the read model, no order-level Prepared source of truth anywhere.
>
> Order-level detail, if an operator asks for it, is a **derived drill-down** over `order_lines` — Required only, never Prepared.

### 6.4 Rejected alternatives

| Alternative | Why rejected |
|---|---|
| `Group + Order + Product` | Forces per-order data entry the floor does not perform; splitting a group-product quantity across orders needs an allocation rule the contract forbids inventing; no downstream consumer wants it |
| Revive `order_lines.prepared_qty` | Silently changes the meaning of an already-exposed Orders API field; a cross-module write into Commerce/Orders that no Distribution code performs; `float` precision against a `decimal(18,4)` platform; and prepared quantity would "teleport" with an order that changes Group while the pallet has not moved |
| Derive Group Prepared from wave Prepared | Impossible — see §8 |

### 6.5 Classification

| | |
|---|---|
| Requires implementation | Yes — as the shape of the LP-2 record |
| Requires migration | Yes — the table in §11 |
| Requires Preparation change | **No** |
| Requires Orders change | **No** |

---

## 7. D-4 — Over-Prepare Ceiling

### 7.1 Evidence — two precedents that disagree

| Path | Ceiling | Live rows |
|---|---|---|
| `WaveDemandController::updatePrepared` | `'max:'.(float) $row->required_qty` — **refuse** | **4** |
| `CompleteProductAction` | `quantity_required × (1 + overprepareTolerance)` — **permit** | **0** |

The permissive rule lives on the dead path. The refusing rule lives on the live path.

**Semantic argument.** An over-prepare tolerance is a *production* concept — make a little extra to cover waste. LP-2 is *separation* of goods that already exist: putting more units on a Group's pallet than the Group needs is an error, not a tolerance.

### 7.2 Decision

> ```
> Prepared ≤ Required            — over-preparation is REFUSED (422)
> Prepared ≥ 0                   — reduction to zero is permitted; that is "undo"
> Remaining = max(0, Required − Prepared)     — DERIVED at read time, NEVER stored
> over_prepared = max(0, Prepared − Required) — DERIVED, surfaced, never hidden by the floor at 0
> ```
>
> Rounding: 4 decimal places on write and comparison (`round($v, 4)`, per `updatePrepared`). Float comparisons at `EPSILON = 0.00005` (per `RecordProductDeliveryAction` and `LoadProductAction`).

`Prepared > Required` remains **reachable without over-preparation** — Required falls when an order leaves the Group or becomes ineligible. That state is legal, is not an error, and is surfaced as `over_prepared` rather than silently clamped (§9).

### 7.3 Is the house concurrency pattern directly reusable? — Yes, with one adaptation

**The pattern**, as it exists:

```php
DB::transaction(function () {
    $locked = Model::query()->lockForUpdate()->findOrFail($id);   // ← contended row
    if ($new - $ceiling > self::EPSILON) { throw … }              // ← ceiling INSIDE the lock
    $previous = (float) $locked->qty;
    $locked->qty = $new;                                          // ← ABSOLUTE SET
    $locked->save();
    // propagate only ($new - $previous) to any aggregate
});
```

- `RecordProductDeliveryAction` — lock + terminal guard + ceiling + absolute set + delta propagation. *"Same lockForUpdate pattern the module already uses for contended rows (CapacityLedgerService)."*
- `CapacityLedgerService::reserve()` — *"Lock the slot: two concurrent reservations against the last order must not both succeed."*

**Directly reusable. One adaptation:** in both precedents the ceiling is a **stored column** on the locked row (`quantity_allocated`, the slot's capacity). LP-2's ceiling is **Required, which is computed live**. So the ceiling must be recomputed inside the transaction, from the canonical aggregation, rather than read off the locked row.

That is a strengthening, not a weakening: it means the ceiling can never be stale. It also means LP-2 must **not** store Required on the row (§11) — storing it would reintroduce exactly the staleness the live `remaining_qty` data already demonstrates (§8.4).

**No delta propagation is required**, because LP-2 maintains no aggregate — every Group total is computed per request.

### 7.4 Rejected alternatives

| Alternative | Why rejected |
|---|---|
| Permit over-prepare to a tolerance | The tolerance rule lives on a dead path and encodes a production concept, not a separation one |
| Increment API (`+= n`) | Would need an operations log to be idempotent — a materially larger object. Absolute set is the platform norm |
| Optimistic concurrency / version column | No precedent anywhere in the platform; would be a second concurrency idiom |
| Idempotency keys | No HTTP idempotency middleware exists; absolute set makes replay a no-op by construction (§22) |
| Clamp `Prepared` down when Required falls | Destroys the floor's record of work — forbidden by the inherited rule (§9.1) |

### 7.5 Classification

| | |
|---|---|
| Requires implementation | Yes — LP-2 write action |
| Requires migration | Only the §11 table |
| Requires Preparation change | **No** |

---

## 8. D-5 — Prepared Source of Truth

### 8.1 The question has two halves, and conflating them is the trap

| Question | Answer |
|---|---|
| Which of Preparation's two stores is the authoritative **Preparation Prepared**? | **`wave_product_demand.prepared_qty`** |
| Can that store be the source of **Group Prepared**? | **No — structurally impossible** |

### 8.2 Half one — `wave_product_demand.prepared_qty` is authoritative

| Evidence | |
|---|---|
| Live rows | `wave_product_demand` **4**; `preparation_wave_items` **0** |
| Written by | `WaveDemandController::updatePrepared` — the endpoint the frontend calls (`preparation-service.ts:437`) |
| Read by | `WaveKpiCalculator` (`SUM(prepared_qty)`, `required_qty - prepared_qty`) → `wave_kpis` → `preparation_waves.total_units_prepared` |
| Protected by | `upsertProductDemand()` excludes it from the update list — *"a demand rebuild must refresh what the wave requires without discarding what the floor has already prepared"* |
| Live consistency | wave `01a02038…`: `SUM(prepared_qty)` = 2 + 1 = 3 = `preparation_waves.total_units_prepared` = 3.0000 ✓ |

`preparation_wave_items.quantity_prepared` is written only by `CompleteProductAction`, has never been used, and feeds `prepared_products_pool` (also 0 rows) which feeds `loading_tasks.pool_entry_id` (0 rows).

### 8.3 Half two — it cannot be the Group's source

Three independent structural reasons:

1. **Grain.** `unique(preparation_wave_id, product_id)` — no Group dimension. Two Groups needing the same product share one number with no division rule.
2. **A Group is not a subset of one wave.** `WaveManager::getActiveWave()` is `whereIn(status, ACTIVE)->orderByDesc('planning_date')->first()`; several waves can be active per warehouse across dates, and the scheduler's own comment records *"three of the five live waves were stranded that way."*
3. **The scopes are already misaligned live.** Wave `01a02038…` carries **7 orders**; DG-001 carries **3**. Wave Required for FG-HONEY-250 is 3; the Group's is 2. The wave's `prepared_qty = 2` cannot be attributed to either.

### 8.4 Decision

> - **`wave_product_demand.prepared_qty` is the single authoritative Preparation Prepared.** Nothing in LP-2 writes it, and nothing in LP-2 reads it as a Group figure.
> - **Group Prepared is a distinct, independently declared quantity** at `(Group, Product)`, stored in the LP-2 record (§11). It is not derived from, constrained by, or reconciled against wave Prepared.
> - **`preparation_wave_items.quantity_prepared` is NOT populated, NOT merged, NOT repaired** by LP-2. Its repair or removal is follow-up **F-3**, and it is **not required for LP-2**.
> - **`prepared_products_pool` does NOT become a third source.** LP-2 never writes it. It remains Actual Loading's input (§18).
> - **`Remaining` is never stored, in either scope.**

### 8.5 Remaining — the explicit rule and its live proof

Live `wave_product_demand` on `ecos_dev`:

| sku | required_qty | prepared_qty | **stored** remaining_qty | correct |
|---|---|---|---|---|
| FG-HONEY-250 | 3.0000 | 2.0000 | **0.0000** | 1.0000 |
| ECOS-FG-000001 | 5.0000 | 1.0000 | **0.0000** | 4.0000 |

The stored column is already wrong on live data; the API is correct only because `presentProductDemand()` re-derives. **LP-2 stores no `remaining_qty`, no `completion_pct`, and no `required_qty`.**

```
Required   → canonical aggregation (productAggregation), live
Prepared   → the LP-2 record, stored
Remaining  → max(0, Required − Prepared), derived only
```

### 8.6 Classification

| | |
|---|---|
| Requires implementation | No — this is a boundary ruling |
| Requires migration | **No** |
| Requires Preparation change | **No** — explicitly, the dead store is left exactly as it is |

---

## 9. D-6 — Group Membership Change Behaviour

### 9.1 The governing rule, inherited not invented

`DemandReadRepository::clearCompletionWhereRequiredChanged()`:

> `prepared_qty` is deliberately NOT touched. Rule: the floor's number is never discarded, only the completion claim is withdrawn.

LP-2 adopts it verbatim: **a Group change never creates, moves, splits, scales or deletes a Prepared quantity.** Only Required moves, and the difference is surfaced.

### 9.2 Group + Product, or an immutable membership snapshot?

**Decision: `Group + Product`, anchored to the mutable Group. No membership snapshot.**

**Evidence:**

1. **The physical fact belongs to the Group, not to a membership version.** The record means "these units are on THIS Group's staging pallet". A pallet does not fork when an order joins or leaves.
2. **No immutable operational membership object exists.** `distribution_window_orders.virtual_slot_id` is a mutable column; there is no membership-version table and no history table. Creating one is a new architecture with its own lifecycle, and it is not required by the risk it would address.
3. **Snapshots in this platform freeze *display*, never *identity*.** `sku_snapshot`, `name_snapshot`, `order_number_snapshot`, `customer_name_snapshot`, `delivery_zone_snapshot`, `zone_code_snapshot`, `payment_status_snapshot` are all cosmetic freezes. Using a snapshot as an identity anchor would be a new pattern, not a reused one.
4. **A snapshot would fragment the operator's view.** One product would carry several partial Prepared rows for one Group — one per membership epoch — which is harder to act on and harder to reconcile than one row plus an explicit over-prepared figure.

### 9.3 The requirement "MUST avoid inventing historical Prepared for a new Group membership" — how it is satisfied

Because **Prepared is never auto-transferred**, adding an order (or a Zone) to Group B raises `Required(B)` and leaves `Prepared(B)` exactly as the operator last declared it. **Nothing is invented, because nothing is computed.** The only writes to a Prepared row are operator writes.

### 9.4 The matrix

| # | Event | Required(A) | Required(B) | Prepared(A) | Prepared(B) | What the operator sees |
|---|---|---|---|---|---|---|
| 1 | Zone **added** to Group | ↑ | — | unchanged | — | Remaining increases. No Prepared invented |
| 2 | Zone **removed** | ↓ (may reach 0) | — | unchanged | — | A is over-prepared by the removed quantity. Row retained |
| 3 | Zone **moved** A→B | ↓ | ↑ | unchanged | unchanged | A over-prepared; B has new Remaining. Physical transfer is a floor act, then two deliberate writes |
| 4 | Order becomes **eligible** after Preparation | ↑ | — | unchanged | — | **This is the LP-1.0 case.** With predicate B the order is visible and its Required counts. Prepared is untouched |
| 5 | Order becomes **ineligible** | ↓ | — | unchanged | — | A over-prepared. **Zero Distribution writes** — `constrainToLoadingEligible` simply stops matching |
| 6 | Membership changes **after** some products prepared | ↕ | ↕ | unchanged | unchanged | Reconciliation list; never an automatic adjustment |

### 9.5 Ownership, rollback, transfer, orphans, reconciliation

- **Ownership** — the row belongs to the Group (`virtual_slot_id`). Not to an order, not to a wave, not to an operator.
- **Rollback** — none needed: nothing was consumed, reserved or moved. Correction is setting the value.
- **Transfer** — **never automatic.** Two deliberate writes, because the goods must physically move. An automatic transfer would assert a physical fact that has not occurred. **This is the single most important rule in this section.**
- **Orphans** — `Prepared > 0` with `Required = 0` is retained and surfaced, never auto-deleted. Deleting it would erase the record of goods sitting on a pallet.
- **Reconciliation** — entirely derived (`over_prepared = max(0, Prepared − Required)` plus the orphan list). **No reconciliation table, no reconciliation state, no new storage.**
- **Group deletion** — no delete path exists today (`storeSlot` has no `destroy` sibling). The §11 FK is `restrictOnDelete`, so a Group carrying prepared work cannot be deleted out from under its record. Recorded as **F-4**.

### 9.6 Classification

| | |
|---|---|
| Requires implementation | Yes — the derived `over_prepared` field and the reconciliation view |
| Requires migration | Only the §11 table |
| Requires Preparation change | **No** |

---

## 10. D-7 — New Write Route

### 10.1 Evidence — can an existing Distribution mutation represent it?

Every existing Distribution write endpoint:

| Route | Purpose | Can it carry a product quantity? |
|---|---|---|
| `POST /windows/collect` | ingest eligible orders | No — no product, no quantity, no group parameter |
| `POST /windows/{w}/slots` | create a Group | No — Group identity only |
| `POST /windows/{w}/slots/{s}/zones` | attach a Zone | No — zone membership only |
| `POST /windows/{w}/slots/{s}/zones/move` | move a Zone | No |
| `DELETE /windows/{w}/slots/{s}/zones/{z}` | detach a Zone | No |
| `PATCH /assignments/{a}/zone` | move one order's Zone | No — order-grained, no product |
| `PATCH /assignments/{a}/slot` | move one order's Group | No — same |
| `POST /windows/{w}/late-orders` | pull a late order in | No |

**No existing endpoint carries a `(group, product, quantity)` triple, and none can acquire one without changing its meaning.** Overloading `PATCH /assignments/{a}/slot` — the closest in shape — would make an order-membership endpoint also write product quantities, which is precisely the kind of conflation the Group model was built to avoid.

### 10.2 Decision

> **One new write route is genuinely required.** Specified below; **not created.**

| | |
|---|---|
| **Purpose** | Record the quantity of one product physically separated for one Distribution Group |
| **Method** | `PUT` — the semantics are "set this value to N", which is what makes retry a no-op |
| **Resource** | `/api/logistics/distribution/windows/{window}/slots/{slot}/preparation/{product}` |
| **Authorization** | `middleware('permission:operations.preparation.update')` (D-1) **plus** a data-layer guard: the `{slot}` must belong to `{window}`, and `{window}` to `$request->user()->company_id` |
| **Input** | `{ "prepared_qty": number, "reason": string\|null }` — `prepared_qty` required, numeric, `min:0`, `max:` live Required, rounded to 4 dp. `reason` **required when the value decreases** (D-8 of the architecture report) |
| **Response** | The authoritative row in the **same shape** as one row of the read endpoint — one presenter serving both, so read and write can never disagree (the `presentProductDemand` precedent) |
| **Idempotency** | Yes, by absolute set. Replaying the identical request writes the same value and changes nothing. No idempotency key — none exists in the platform and none is needed (§22) |
| **Tenant scoping** | `company_id` from the authenticated user, **never** from the request |
| **Warehouse scoping** | Derived from `distribution_virtual_slots.warehouse_id` (NOT NULL). **`warehouse_id` is never read from the request on this route** — `DistributionWindowController::warehouseId()` reads a query parameter, which is acceptable for filtering a read and is not an authorization source for a write |
| **Group scoping** | `{slot}` in the path; the record is keyed by it |
| **Product scoping** | `{product}` in the path |
| **Side effects** | one row written or created; one `AuditService` record; one `TimelineService` record. **No inventory, no order, no Preparation, no Loading, no status write** |
| **Errors** | `422` over-ceiling · `422` slot/window mismatch · `404` unknown group or product · `403` permission · `409` reserved for a future Finalize freeze |

**Read side:** no new route. The existing `GET /windows/{window}/products?slot_id=&warehouse_id=` is extended **additively** with `prepared_qty`, `remaining_qty`, `over_prepared_qty`, `last_recorded_by`, `last_recorded_at`, under the unchanged `logistics.distribution.view`.

**Implementation note carried forward:** decorate the controller's result when `slot_id` is present rather than joining prepared quantities into `productAggregation()` itself, so the aggregation stays pure and its behaviour with `slot_id = null` is byte-identical.

### 10.3 Classification

| | |
|---|---|
| Requires implementation | Yes — LP-2 |
| Requires migration | Yes — the §11 table |
| Requires Preparation change | **No** |
| CI constraint | `WriteRouteAuthorizationTest` asserts against the real route table; the permission middleware must be present from the first commit and the route cannot be added to `ALLOWED` |

---

## 11. D-8 — New Table

### 11.1 Evidence — why each named candidate cannot represent the fact

| Table | Why not |
|---|---|
| `wave_product_demand` | `unique(preparation_wave_id, product_id)` — no Group dimension; a Group spans waves; writing it is a **Preparation change** and would collide with the operator-owned `prepared_qty` contract |
| `preparation_wave_items` | Same grain, Preparation-owned, **0 rows** (dead), contradictory ceiling; D-5 forbids populating it |
| `distribution_virtual_slots` | One row per Group. **No product dimension and no quantity column.** Adding either would make the Group table a quantity ledger |
| `distribution_window_orders` | Order grain. **No product column, no quantity column.** Adding them would force order-level attribution, which D-3 rejects |
| `distribution_slot_zones` | Zone↔Group link. No product, no quantity |
| `distribution_groups` | **Does not exist** — the Group is `distribution_virtual_slots`. Verified against the live table listing |
| `prepared_products_pool` | `(wave, product, warehouse)`; no Group; and it is **Actual Loading's input** — writing it would inject un-loaded stock into the loading pipeline |
| `loading_tasks` / `allocation_records` / `shipment_group_items` | `vehicle_assignment_id` NOT NULL — cannot exist pre-vehicle |
| `order_lines.prepared_qty` | Order+product grain, no Group; `float`; an Orders column already exposed by `OrderResource` (D-3) |

**Exhaustive check:** the live `ecos_dev` table listing contains **no** table carrying both a slot/group reference and a product quantity, and `virtual_slot_id` appears in exactly **7 files**, all inside `Modules/Logistics/Distribution`.

### 11.2 Decision

> **One new table is genuinely required.** Minimum logical model below; **not created.**

**`distribution_group_product_preparation`**

| Column | Type | Purpose |
|---|---|---|
| `id` | `uuid` PK | |
| `company_id` | `uuid` NOT NULL | tenant scope |
| `distribution_window_id` | `uuid` NOT NULL | retention / cleanup scope |
| `virtual_slot_id` | `uuid` NOT NULL | **the Group — the owner** |
| `product_id` | `uuid` NOT NULL | |
| `prepared_qty` | `decimal(18,4)` NOT NULL default `0` | **the only fact this table owns** |
| `last_recorded_by` | `uuid` NULL | durable actor, independent of the best-effort audit log |
| `last_recorded_at` | `timestampTz` NULL | |
| `notes` | `text` NULL | the reason on a decrease |
| `created_at` / `updated_at` | `timestampsTz` | |
| `created_by` / `updated_by` | `uuid` | module convention |

| Constraint | Value |
|---|---|
| Unique | `(virtual_slot_id, product_id)` — the concurrency and idempotency guard |
| FK | `virtual_slot_id → distribution_virtual_slots.id`, `restrictOnDelete` (§9.5) |
| FK | `company_id → companies.id`, `restrictOnDelete` |
| Index | `(company_id, distribution_window_id)`, `(company_id, product_id)` |
| Check | `prepared_qty >= 0` via `DB::statement` — **not** `Blueprint::check()`, unavailable on MySQL 8.4 |

**It duplicates nothing.** Required — absent (live). Remaining — absent (derived). Order — absent (D-3). Inventory — absent (§17). Wave totals — absent; no `preparation_wave_id` column, because a Group spans waves and binding to one would be false. Warehouse — absent; it lives on `distribution_virtual_slots.warehouse_id` (NOT NULL) and denormalising it would create a copy that can disagree with Group ownership, the exact defect Part 5B closed (§14).

**Backfill:** none — the table starts empty. Reconstructing historical Group preparation would be fabricated business data.
**Rollback:** `dropIfExists`. Nothing else reads it; the UI degrades to LP-1's Required-only view.

### 11.3 Classification

| | |
|---|---|
| Requires implementation | Yes — LP-2 |
| Requires migration | **Yes — one additive table, approval required** |
| Requires Preparation change | **No** |

---

## 12. D-9 — LP-1.0 Repair Boundary

### 12.1 Evidence — the exact failure

LP-1 was browser-verified on 2026-08-21 showing two rows for DG-001. It renders **empty** today, with no code change:

| | |
|---|---|
| DG-001 orders in `distribution_window_orders` | 3 — all present |
| Their `orders.status` | all `ready_for_dispatch` |
| Group Required **without** the status filter | 2 rows (FG-HONEY-250 = 2, ECOS-FG-000001 = 1) |
| Group Required **with** `constrainToEligible` | **0 rows** |

The projection logic, the warehouse scoping, the aggregation and the UI are all correct. **Only the eligibility predicate is wrong.**

### 12.2 Decision — the boundary, stated as what is in and what is out

> **LP-1.0 changes the eligibility predicate used by Distribution's operational reads. Nothing else.**

**IN scope:**

1. One new config key holding predicate B — `[in_progress, confirmed, ready_for_dispatch]`.
2. One new method `PreparationEligibilityReader::constrainToLoadingEligible()`, composed from the **same** `excludePostponed()`.
3. Substitution at exactly **five** call sites: `zoneSummaries()`, `slotRollup()`, `productAggregation()`, `orders()`, `slotOrderCounts()`.

**OUT of scope — explicitly:**

| Excluded | Reason |
|---|---|
| The Required projection itself (`SUM(ol.quantity)`, grouping, unit join) | Correct and certified. **Untouched** |
| `config('distribution.eligible_order_statuses')` | Predicate A is unchanged (§5.2) |
| `OrderStatus::fulfilmentEligible()` | Feeds `MoveToPreparationWorkflow::guard()` — a Preparation change |
| `DistributionCollectionService`, `OrderCityBinder`, `lateOrders()` | Stay on predicate A (§5.2) |
| Prepared, Remaining, any write, any new table, any new route | **LP-2, not LP-1.0** |
| Warehouse scoping, Group ownership, membership semantics | Untouched |
| Any Preparation, Inventory, Order or Loading code | Untouched |

**No migration. No new endpoint. No new permission. No UI change. No Preparation change.** LP-1's frontend, i18n and query keys are untouched — the same request returns rows again.

### 12.3 Why this ships on its own merit

LP-1 is currently **broken in production-shaped data**. LP-1.0 restores it and is independently certifiable with two focused tests (§25) whether or not LP-2 is ever approved.

### 12.4 Classification

| | |
|---|---|
| Requires implementation | **Yes — and it is the prerequisite for everything else** |
| Requires migration | **No** |
| Requires Preparation change | **No** |
| Dependencies | none |

---

## 13. D-10 — Capacity Dependency

### 13.1 Evidence

`distribution_virtual_slots.capacity_orders` is `unsignedInteger NULL`. `slotSummaries()` already derives `utilisation`, `overflow_orders`, `is_over_capacity`, `is_warning`. `RedistributionSuggestionService::overflows()` already produces over-capacity Groups with candidate orders and destinations, exposed at `GET /windows/{window}/overflows`.

**No write path enforces it.** `ManualAssignmentService` contains no capacity check on any of its six methods. The Group create UI deliberately never sends capacities, so **every live Group has `capacity_orders = NULL`** — confirmed: DG-001 `capacity_orders` is `NULL`.

The three forbidden dimensions (`capacity_stops`, `capacity_weight_kg`, `capacity_volume_m3`) exist in the schema and are emitted by `slotSummaries()`, always `NULL`.

### 13.2 Decision

> **`capacity_orders` remains the only Group capacity constraint. Capacity enforcement is NOT a dependency of LP-2 write safety.**

**Why it is not a dependency, stated precisely:**

1. LP-2's write ceiling is **Required**, computed from the Group's actual order lines. It has no relationship to how many orders the Group is *allowed* to hold.
2. An over-capacity Group still needs its products separated. Refusing a preparation write because a Group is over capacity would block real work for a planning-level condition.
3. Capacity is measured in **orders**; LP-2 is measured in **product units**. They never meet.

**LP-2 must not become a hidden vehicle-planning engine.** It introduces no weight, no volume, no stops, no dimensions, no vehicle capacity and no product-to-vehicle fit calculation. The new table carries a product quantity and nothing that could be summed into a capacity figure (§11).

**Note on `ready_for_dispatch` and capacity.** Predicate B raises `demand_orders` for Groups whose orders have entered preparation — restoring counts that currently read 0. Since `capacity_orders` is `NULL` everywhere, `utilisation` stays `null` and `overflow_orders` stays `0` by definition, so **no Group can newly become over-capacity as a result of LP-1.0**. Enforcement remains a separate, unapproved phase (LP-3).

### 13.3 Classification

| | |
|---|---|
| Requires implementation | **No** |
| Requires migration | **No** |
| Requires Preparation change | **No** |
| Dependency for LP-2 | **No** |

---

## 14. Warehouse Contract

**Part 5B is preserved exactly. Nothing in this decision touches it.**

| Rule | Status |
|---|---|
| The Group owns the warehouse — `distribution_virtual_slots.warehouse_id` NOT NULL | unchanged |
| A Zone is geography and carries no warehouse — `distribution_zones` has no warehouse column | unchanged |
| The link is warehouse-scoped — `unique(distribution_window_id, warehouse_id, distribution_zone_id)` | unchanged |
| Two warehouses may each plan the same Zone in their own Group | unchanged |
| Reads scope by `orders.assigned_warehouse_id` via `scopeWarehouse()` | unchanged — applied **outside** the eligibility wrapper in all five reads, so predicate B composes with it rather than replacing it |
| A Group may only manipulate its own warehouse's membership | unchanged — `assignZoneToSlot` refuses a Zone holding only another warehouse's work; `moveZone` refuses cross-warehouse moves; `detachZone` deletes one warehouse's link only |

**No warehouse column is added anywhere.** The LP-2 table deliberately carries **no** `warehouse_id` (§11): it lives on the Group, is NOT NULL there, and duplicating it would create a second copy that can disagree with Group ownership — the exact class of defect Part 5B closed. Warehouse is resolved by joining `distribution_virtual_slots`.

**Order warehouse ownership is not duplicated.** `orders.assigned_warehouse_id` remains the single source; Distribution reads it and never writes it (`productAggregation` docblock: *"`assigned_warehouse_id` is READ from the Order and never written or chosen here"*).

**One security tightening, stated because it differs from the read path.** `DistributionWindowController::warehouseId()` reads `warehouse_id` from the **query string**, validated only as a nullable uuid. That is acceptable for *filtering a read*. It is **not** an authorization source for a write. The LP-2 write must derive the warehouse from the resolved `{slot}`, never from the request (§10.2, §23).

---

## 15. Postponed / Cancelled / Ineligible Rules

**Every mandatory behaviour is preserved, and none of it required a change.**

### 15.1 Preserved because predicate B reuses the same machinery

`constrainToLoadingEligible()` is `excludePostponed($query->whereIn(status, B_LIST))` — the identical `NOT EXISTS` subquery on `preparation_wave_orders` where `released_at IS NULL AND postponed_at IS NOT NULL`. Both halves of Preparation's rule remain Preparation's, restated nowhere.

| Requirement | How it holds |
|---|---|
| An ineligible Order disappears from eligible Group reads | `cancelled`, `returned`, `on_hold`, `awaiting_payment`, `awaiting_stock`, `scheduled` are in **neither** predicate |
| A postponed Order disappears even though its status is eligible | `excludePostponed()` is applied to B exactly as to A |
| It must not contribute to Group totals | All five totals route through B |
| It must not be newly prepared | The LP-2 write ceiling is live Required, which excludes it — Required becomes 0, so any write is refused by the `max` rule (§7.2) |
| Existing Group structure must not be destroyed automatically | No write is involved. `distribution_window_orders` rows are retained (`virtual_slot_id` untouched); `detachZone` remains the only path that clears membership, and only on operator action |
| If it becomes eligible again it may return per the existing contract | Both predicates are evaluated per request. Nothing is cached, snapshotted or stored |
| Do not recollect automatically unless the system already does | **`DistributionCollectionService` stays on predicate A** (§5.2). No new automatic collection behaviour is introduced |

### 15.2 What happens to a Prepared quantity when an order becomes ineligible

`Prepared` is **unchanged** — the floor's number is never discarded (§9.1). `Required` falls, so the row surfaces as `over_prepared`. Nothing is auto-deleted, auto-transferred or auto-scaled. This is the same shape Preparation already uses when Required moves under a completed product.

### 15.3 Part 4 / 5 eligibility behaviour — unchanged

`assignZoneToSlot` cross-warehouse guard, `detachZone` single-warehouse deletion, `moveZone` cross-warehouse refusal, `changeOrderZone` / `changeOrderSlot` slot-follows-zone semantics, `assignLateOrder` cross-window move with `previous_window_id` audit — **all untouched**.

---

## 16. Wave Boundary

**Preparation Wave Completion is not modified.** Explicitly untouched:

| Untouched | |
|---|---|
| `refreshKpis()` / `refreshWaveTotals()` / `WaveKpiCalculator` / `syncWaveHeader()` | Part 3 certified |
| `wave_product_demand.required_qty` and its rebuild (`upsertProductDemand`) | |
| `wave_product_demand.prepared_qty` and its operator-owned protection | |
| `clearCompletionWhereRequiredChanged()` and `preparation_completed_at` semantics | |
| `CompleteWaveAction`, `CompleteProductAction`, `GenerateDemandAction`, `RecalculateWaveAction` | |
| `WaveManager`, `WaveMembershipService`, `WaveLifecycleService`, `WavePreparationService` | |
| `MoveToPreparationWorkflow` and the wave-start status transition | |
| `HandlePreparationWaveStarted` / `…PreparationStarted` / `…Closed` / `…Cancelled` | |
| Every Preparation domain event and listener | |

**LP-2's only relationship to a wave is that it has none.** The LP-2 record carries no `preparation_wave_id` (§11), because a Group can span waves. Wave Prepared and Group Prepared are never summed, compared or reconciled (§8.4).

**The one wave-side fact LP-2 depends on** is `PreparationEligibilityReader`'s two predicates — read-only, unchanged, and already the mechanism LP-1 uses.

---

## 17. Inventory Boundary

**LP-2 is NON-MUTATING with respect to Inventory. Confirmed, not assumed.**

| LP-2 does not | Evidence it is safe to say so |
|---|---|
| deduct inventory | `inventory_items` has only `on_hand_qty` and `reserved_qty`; only `ShipStockAction` decrements on-hand |
| reserve inventory | Reservation already happened **per order at wave start** via `ReserveOrderInventoryAction` inside `MoveToPreparationWorkflow`. A second reservation would double-count |
| create stock movements | No `stock_ledger_entries` / `stock_movements` write; LP-2 reads no inventory table at all |
| modify FIFO | FIFO consumption belongs to the costing/ledger path; Distribution never touches it |
| modify `inventory_items` | No read, no write |
| create staged inventory quantities | **No staged state exists** — the column does not exist and adding one is an Inventory change under the Architecture Freeze |

**What LP-2 reads:** `order_lines`, Group membership, its own record. Nothing else.

**The consequence, stated plainly:** LP-2 records that goods were physically moved on the floor while the system's inventory position does not change. That is correct — the goods are on hand in the same warehouse and already reserved to their orders. If stock counting later needs a staging pallet to be visible, that is an **Inventory** change and follow-up **F-5**, not LP-2.

**Order status:** LP-2 writes none. `Order.status` writes must go through `FulfillmentEngine`, and Distribution never touches `orders`.

---

## 18. Actual Loading Boundary

**Not modified. Not referenced. Not written.**

| Untouched | |
|---|---|
| `loading_sessions`, `loading_tasks`, `vehicle_assignments`, `allocation_records` | |
| `vehicle_inventory_items`, `vehicle_inventory_movements` | |
| `shipment_groups`, `shipment_group_items` | |
| `prepared_products_pool`, `prepared_pool_movements` | |
| `AutoAllocationService`, `LoadProductAction`, `RecordProductDeliveryAction`, every Loading action and service | |

**The boundary is a schema fact, not a convention.** `loading_tasks.vehicle_assignment_id` and `allocation_records.vehicle_assignment_id` are NOT NULL foreign keys, so no Actual Loading row can exist before a vehicle assignment. **LP-2 is a pre-vehicle layer and cannot be forced into those tables.**

**What LP-2 will eventually hand over:** `(group, product) → prepared_qty`, plus derived Required/Remaining and the Group context. The natural future consumption point is vehicle-inventory seeding when a vehicle is assigned to a Group; `AutoAllocationService` then allocates product-level inventory down to `order_lines` exactly as it does today — **requiring no change to the allocation algorithm** (§6.1).

**A standing gap, restated so it is not assumed away.** Actual Loading's designed input (`prepared_products_pool`) is fed by `CompleteWaveAction` from `preparation_wave_items`, which has **0 rows**; `loading_sessions`, `loading_tasks` and `vehicle_assignments` are all **0**. Actual Loading has never run. **LP-2 must not paper over this by writing the pool** (D-5). Follow-up **F-3**.

---

## 19. Virtual Vehicle Planning Boundary

**Permanently removed. Not revived, not referenced, not built upon.**

`vehicle_plans`, `vehicle_plan_slots`, `vehicle_plan_slot_orders`, `vehicle_plan_adjustment_log` remain in the schema at 0 rows. `loading_sessions.vehicle_plan_id` is nullable and `CreateLoadingSessionAction` never references it, so nothing depends on the residue.

**LP-2 introduces no virtual vehicle entity and no vehicle-shaped concept.** The Group acquires no `vehicle_id`, no `driver_id` and no assignment timestamps — the absence is what keeps it re-plannable. The LP-2 table carries a product quantity only, with no dimension that could be summed into a vehicle-fit calculation (§13.2).

Removal of the residue remains a separate, unscheduled cleanup. **Do not build on it; do not remove it in LP-2.**

---

## 20. Data Ownership

| Fact | Owner | Written by | Read by LP-2 |
|---|---|---|---|
| Order status | `FulfillmentEngine` (Commerce/Orders + Operations/Fulfillment) | workflows only | yes, via the predicate |
| Is the order in the current preparation cycle | Preparation | `WaveMembershipService` | yes, via `excludePostponed` |
| `order_lines.quantity` | Commerce/Orders | order write paths | yes — the Required input |
| Prepared **for a wave** | Preparation | `WaveDemandController::updatePrepared` | **no** |
| Order → Window/Group membership | Distribution | `DistributionCollectionService`, `ManualAssignmentService` | yes |
| Group identity, warehouse, capacity | Distribution | `storeSlot` | yes |
| Zone geography | Logistics/Geography | zone CRUD | yes, by name |
| **Prepared for a Group** | **Distribution (new)** | **the LP-2 write only** | **yes — it owns it** |
| Inventory on-hand / reserved | Inventory | Inventory actions | **no** |
| Pool / vehicle inventory / loading | Preparation + Loading | their own actions | **no** |

**One writer per fact.** LP-2 adds exactly one new owned fact and writes nothing it does not own. It reads six facts owned elsewhere and writes none of them.

---

## 21. Concurrency

### 21.1 The pattern — reused verbatim, not invented

```php
DB::transaction(function () {
    $row = …::query()->lockForUpdate()->firstOrCreate([...]);   // unique(slot, product) is the real guard
    $required = $aggregation->requiredFor($slot, $product);      // ceiling recomputed INSIDE the lock
    if ($new - $required > 0.00005) { throw … }                 // fail closed (D-4)
    $previous = (float) $row->prepared_qty;
    $row->prepared_qty = $new;                                  // ABSOLUTE SET
    $row->last_recorded_by = $actorId;
    $row->last_recorded_at = now();
    $row->save();
});
```

Precedents: `RecordProductDeliveryAction` (lock + terminal guard + ceiling + absolute set), `CapacityLedgerService::reserve()` (*"two concurrent reservations against the last order must not both succeed"*), `LoadProductAction` (fail-closed ceiling, same epsilon), `AutoAllocationService` (`lockForUpdate` on `VehicleInventoryItem`), the three Preparation pool listeners.

`lockForUpdate` census: Inventory 11, Preparation 7, Loading 7, GoodsReceipts 4, Manufacturing 7, Network 3, Orders 3, Distribution 2. **This is the house pattern.**

### 21.2 The stated scenario, resolved

> Operator A records 5, Operator B records 5, only 7 remained.

Absolute set means the row reads **5**, not 10. The lost-update the scenario fears cannot occur for the quantity. The lock exists for the **ceiling check** and for row creation, not for the arithmetic.

### 21.3 The residual exposure, named

Absolute set is **last-write-wins**. A stale client that read `0`, was superseded by another operator writing `7`, and then submits `5`, will overwrite the `7` — and that is indistinguishable from a legitimate correction. Mitigations, in order of cost: return the authoritative row from the write and re-render from it (the `presentProductDemand` precedent); refetch on window focus (a TanStack Query option, no new mechanism); a push channel (materially larger, still deferred).

**Recommendation: the first two, plus a UI statement of the limitation.** If per-operator contribution is ever a real requirement, the model must become an append-only movements table with the quantity as a derived `SUM` — a materially larger object, deliberately not chosen. Follow-up **F-6**.

**No new concurrency framework. No optimistic concurrency. No advisory locks.**

---

## 22. Idempotency

**Guaranteed structurally, not by infrastructure.**

| Mechanism | Where LP-2 uses it |
|---|---|
| Absolute set — replay is a no-op | the `prepared_qty` write |
| A unique index that makes duplication impossible | `unique(virtual_slot_id, product_id)` on row creation |

The platform's own statement of this rationale, from `RecordProductDeliveryAction`:

> …so replaying the same confirmation is a no-op rather than a double count.

And from `DistributionCollectionService`:

> Idempotent by construction rather than by checking… The pre-filter is an optimisation, not the safety mechanism — the database constraint is.

**No idempotency keys are introduced.** A survey found `idempotency_key` only in Finance (`FinancialEvent`), HR (`hr_kpi_facts`) and the Event Platform (`EventProcessingLog`). **There is no HTTP `Idempotency-Key` middleware in the platform**, and LP-2 does not need one.

**The safe retry after a UI timeout is: send the identical request again.**

---

## 23. Security

| Control | Contract |
|---|---|
| Authentication | `auth:sanctum`, as every Distribution route |
| Authorization | `permission:operations.preparation.update` on the write (D-1); `logistics.distribution.view` on the read, unchanged |
| CI enforcement | `tests/Feature/Security/WriteRouteAuthorizationTest` asserts against the **real route table**; the middleware must be present from the first commit and the route may not be added to `ALLOWED` |
| Tenant boundary | `company_id` from `$request->user()`, never from the request. `DistributionWindowController::companyId()` already aborts 403 on a null company — *"a null company must not degrade into 'see everything'"* |
| Warehouse boundary | Derived from the resolved `{slot}`'s NOT NULL `warehouse_id`. **`warehouse_id` is never read from the request on the write route** (§14) |
| Group boundary | `{slot}` must belong to `{window}`, and `{window}` to the acting company — the existing `window()` / `slot()` resolution helpers already enforce both |
| Cross-tenant probing | A slot in another company must read as **not found**, matching `assignLateOrder`'s rule: *"Cross-company assignment is not a permission problem to be reported — it is outside the tenant boundary and must read as not existing"* |
| Input validation | `prepared_qty` numeric, `min:0`, `max:` live Required, 4 dp; `reason` required on decrease |
| Mass assignment | Explicit `$fillable`; note the platform failure mode — a column missing from `$fillable` is dropped **silently** on mass-assign |
| Audit | `AuditService` + `TimelineService`, previous → new, with actor and Required at the time (§9) |
| Non-repudiation limit | Both services swallow exceptions by design — a best-effort trail, not a ledger. `last_recorded_by` / `last_recorded_at` on the row are durable regardless. Follow-up **F-7** if a gap-free ledger is required |

**No permission is created, modified or seeded by this task.**

---

## 24. Implementation Sequence

**None of this is authorized by this document.** Each phase is independently shippable and independently certifiable.

### LP-1.0 — Repair Distribution eligibility *(prerequisite)*

One config key (predicate B), one reader method (`constrainToLoadingEligible()`), five call-site substitutions. **No migration, no new route, no new permission, no UI change, no Preparation change.**

*Exit criterion:* DG-001's Loading Preparation returns its two rows again, with all three orders at `ready_for_dispatch`; the collection, city-binding and late-order paths are provably unchanged.

### LP-2 — Group + Product Prepared recording

The §11 table; the write action (transaction + `lockForUpdate` + ceiling from live Required + absolute set); audit + timeline; the additive read fields; the write route with `operations.preparation.update`.

*Exit criterion:* the write is certifiable through the API alone, with no UI.

### LP-2 UI — the single Group + Loading Preparation workspace

Prepared column and inline editor, Remaining, the reconciliation list, EN/AR. Joins the existing query-key root as the **8th** mutation calling `useInvalidateWorkspace()`. Browser-verified in **both** languages.

The workspace shows, in one place: Group · Warehouse · Zones · Orders · Capacity · Required products · Prepared entry · Remaining · current preparation state (derived, no status column).

**No separate Virtual Vehicle Planning screen. No Vehicle assignment. No Driver assignment. No Actual Loading.**

### LP-2 verification — Required / Prepared / Remaining consistency

Focused suite (§25). Confirm Remaining is never read from storage, over-prepared surfaces, and Group changes never invent or move a quantity.

### THEN STOP

Vehicle Assignment, Driver Assignment, Review/Finalize, Actual Loading and Dispatch are later approved stages and are **not** part of this sequence.

### Ordering constraint

**LP-1.0 must ship before LP-2.** Building the write first would put a data-entry surface in front of operators whose Required is structurally zero.

---

## 25. Focused Future Test Plan

**No test is written or changed by this task.** Listed for the implementation phase only.

### LP-1.0

| Test | Proves |
|---|---|
| An order at `ready_for_dispatch` appears in its Group's Required projection | the repair |
| The same order is still absent from `DistributionCollectionService`'s candidate set | predicate A is untouched on the write path |
| A **postponed** order is absent under **both** predicates | `excludePostponed` survives |
| A **cancelled** order is absent under both | the closed list holds |
| Two warehouses planning one Zone still get disjoint results under B | Part 5B holds across the widened predicate |
| `slotRollup` / `slotOrderCounts` / `orders` / `zoneSummaries` agree with `productAggregation` for one Group | no split-brain on one screen |
| `lateOrders()` is unchanged | entry triage stays on A |

### LP-2

| Test | Proves |
|---|---|
| Recording Prepared writes one row and nothing else — snapshot `inventory_items`, `stock_ledger_entries`, `wave_product_demand`, `preparation_wave_items`, `prepared_products_pool`, `loading_tasks`, `orders` before/after | §17, §16, §18 |
| Two concurrent writes of 5 against Remaining 7 leave the row at 5 | §21 |
| Replaying the identical request changes nothing | §22 |
| `Prepared > Required` is refused | D-4 |
| Reducing to 0 succeeds; reducing without a reason is refused | D-4 / §10.2 |
| Moving an order A→B leaves both `prepared_qty` unchanged and surfaces A as over-prepared | **D-6, the most important behavioural rule** |
| An order becoming ineligible reduces Required with zero Distribution writes; Prepared survives | D-6 |
| `Remaining` is never read from storage — corrupt a stored value directly, assert the API still derives correctly | §8.5 |
| A user without `operations.preparation.update` is refused | D-1 |
| A slot in another company reads as not found | §23 |

### Constraints

Focused suites only — **no full-ERP and no full-Distribution regression**. The one regression guard worth running alongside is `DistributionCoreTest`, which owns `productAggregation` (the LP-1 precedent). `DistributionGroupLoadingPreparationTest` test 6 (*"…never reports a group prepared or remaining quantity"*) must be **explicitly retired or inverted**, not silently deleted. No fabricated business data; every fixture inside `RefreshDatabase`. Run through `GATE_WAIT=2400 ./scripts/test-gate.sh`, never bare phpunit. `route:clear` in the testrunner before any API feature test. `assertJsonPath` is strict — assert quantities as floats.

---

## 26. Risks

| # | Risk | Severity | Mitigation / status |
|---|---|---|---|
| **R-1** | Predicate B is applied to some of the five reads and not others, producing a Group card at 0 orders above a populated product list | **High** | All five substitute together in LP-1.0, and a focused test asserts they agree for one Group |
| **R-2** | Someone later "simplifies" the two predicates back into one config key | **High** | The rationale is recorded in §5; the write-path consumers must be named in the config comment |
| **R-3** | A prepared order that was **never collected** remains invisible: `lateOrders()` stays on A, so it appears in neither the pool nor the triage list | **Medium** | Accepted for LP-1.0 — widening it without an eligibility guard on `assignLateOrder` would create an unguarded assignment path. Follow-up **F-2** |
| **R-4** | Last-write-wins overwrites a concurrent operator's value | **Medium** | §21.3 — return-the-row, refetch-on-focus, and a stated UI limitation |
| **R-5** | "Prepared" appears twice on screen at two scopes and is read as one number | **Medium** | Mandatory scope labelling; the two are never summed or reconciled (§8) |
| **R-6** | `operations.preparation.update` over-grants to Production Director / Production Manager | **Low** | Accepted against locking out the actual operator; **F-1** if a dedicated permission is later wanted |
| **R-7** | Predicate B raises `demand_orders` and surfaces new over-capacity Groups | **Low** | Cannot occur — every live Group has `capacity_orders = NULL`, so `overflow_orders` is 0 by definition (§13.2) |
| **R-8** | Another agent edits the same Distribution files concurrently (438 dirty paths in this worktree) | **Medium** | Never `git checkout` a shared file to undo an edit; revert only your own lines |

### Follow-ups — recorded, not part of LP-2

| # | Item |
|---|---|
| **F-1** | A dedicated `logistics.distribution.prepare` permission, if a Distribution floor-execution boundary is later wanted |
| **F-2** | `PreparationEligibilityReader::isEligible()` has **zero callers**; `assignLateOrder()` performs **no eligibility check at all** despite the docblock claiming otherwise |
| **F-3** | Preparation's two Prepared stores; `preparation_wave_items` and therefore `prepared_products_pool` are empty, leaving Actual Loading unreachable |
| **F-4** | Group deletion has no path; when added it must refuse while prepared work exists |
| **F-5** | Inventory has no staged state — a staging pallet is invisible to stock counting |
| **F-6** | Append-only movements model, if per-operator contribution is ever required |
| **F-7** | A gap-free preparation ledger, if `AuditService`'s best-effort trail is insufficient |
| **F-8** | Carried forward unchanged: capacity enforcement (LP-3), the two Group order counts, the three forbidden capacity dimensions in the payload, "No Warehouse" orders, Finalize, Group templates, `vehicle_plan_*` residue, and **windows never closing** |

---

## 27. STOP Conditions

**Reached, and honoured.** This report ends the task.

| Condition | Status |
|---|---|
| A new table is required | **YES — §11.** Stopped. Approval required |
| A new write route is required | **YES — §10.** Stopped. Approval required |
| A blocker requires a **Preparation** change | **NO.** D-2 fix A resolves entirely inside Distribution |
| A blocker requires an **Inventory** change | **NO.** LP-2 is non-mutating (§17) |
| A blocker requires a **Wave Completion** change | **NO** (§16) |
| A blocker requires an **Order status** change | **NO** (§3.4) |
| A blocker requires an **Actual Loading** change | **NO** (§18) |
| A blocker requires a **permission** change | **NO.** D-1 reuses an existing permission |

**Nothing may be implemented until:** D-1 through D-10 are approved as decided, the §11 table is approved, and the §10 route is approved.

---

## 28. Final Recommendation

**Approve all ten decisions and authorize LP-1.0 first, alone.**

### The ten, in one line each

| # | Decision | Impl? | Migration? | Preparation change? |
|---|---|---|---|---|
| **D-1** Permission | Reuse `operations.preparation.update` — the only permission the actual operator holds | yes | no | **no** |
| **D-2** Eligibility | Two predicates; B = A ∪ `ready_for_dispatch` on the five aggregation reads only; global config untouched | yes | no | **no** |
| **D-3** Attribution | `Group + Product`; evidence sufficient — allocation already re-derives order attribution | yes | yes (§11) | **no** |
| **D-4** Over-prepare | `Prepared ≤ Required`; Remaining always derived; house lock pattern reusable directly | yes | yes (§11) | **no** |
| **D-5** Prepared source | `wave_product_demand.prepared_qty` authoritative for Preparation; Group Prepared is a separate declared fact; dead store untouched | no | no | **no** |
| **D-6** Group changes | `Group + Product` on the mutable Group; no snapshot; never auto-transfer, never invent | yes | yes (§11) | **no** |
| **D-7** Route | One new `PUT`; no existing endpoint fits | yes | no | **no** |
| **D-8** Table | One new table; every candidate disproved | yes | **yes** | **no** |
| **D-9** LP-1.0 | Eligibility predicate only; Required projection untouched | **yes** | no | **no** |
| **D-10** Capacity | `capacity_orders` remains the only constraint; **not** an LP-2 dependency | no | no | **no** |

### Why LP-1.0 should be authorized on its own, ahead of everything

LP-1 is **broken right now** in production-shaped data — its screen renders empty the moment a wave starts, which is the moment it matters. LP-1.0 is one config key, one reader method and five substitutions. It carries **no migration, no new route, no new permission, no Preparation change and no UI change**, and it is independently certifiable with seven focused tests.

It is also the honest prerequisite: **there is no point building a place to record Prepared until the Required it is recorded against stops being zero.**

### The one thing that would change these answers

If the business requires **per-order sealed picking** — each order separated into its own tote — D-3 and D-6 both change, the table gains an order dimension, and the ceiling becomes per-order. Nothing in the audited system does that today, and Preparation's certified contract says the opposite. If that requirement is real, say so before LP-2 begins rather than after.

---

**Nothing was implemented. No code, no migration, no schema, no API, no frontend, no permission change, no business data, no Preparation change, no Distribution change, no Loading change, no vehicle change, no commit. All database access was read-only `SELECT`.**

**STOPPING here. Awaiting explicit approval before implementation.**

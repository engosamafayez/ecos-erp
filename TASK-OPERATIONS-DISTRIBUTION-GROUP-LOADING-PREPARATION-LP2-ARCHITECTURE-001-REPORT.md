# TASK-OPERATIONS-DISTRIBUTION-GROUP-LOADING-PREPARATION-LP2-ARCHITECTURE-001 — REPORT

**Date:** 2026-08-21
**Status:** ARCHITECTURE / CONTRACT ONLY — nothing implemented. No migration, no schema change, no API change, no Preparation change, no Distribution change, no Loading change, no Inventory change, no Order change, no business data written, no commit.
**Method:** code and schema audit first, against the live `ecos_dev` database (read-only `SELECT` only). Design proposed only where the audit proved a gap. Every claim below cites the file, index or query it came from.

---

## 1. Executive Summary

LP-2 asks one question: **how can Loading Preparation become an operational workflow where warehouse staff record the separation of products for a specific Distribution Group?**

The audit produced three findings that change the shape of the answer, and the first of them is larger than the question that was asked.

### 1.1 The blocking finding — the Group's work disappears the moment it becomes preparable

`HandlePreparationWaveStarted` and `HandlePreparationWavePreparationStarted` run `MoveToPreparationWorkflow` for **every order in a wave when that wave starts**, which sets `orders.status = ready_for_dispatch`.

`config('distribution.eligible_order_statuses')` is `["in_progress","confirmed"]` — **verified at runtime**, not merely read from source:

```
$ docker exec ecos-dev-app php artisan tinker --execute="echo json_encode(config('distribution.eligible_order_statuses'));"
["in_progress","confirmed"]
```

Every Distribution read model — `zoneSummaries`, `slotRollup`, `slotOrderCounts`, `productAggregation`, the order list — passes through `PreparationEligibilityReader::constrainToEligible()`. `ready_for_dispatch` is not in that list.

**Therefore an order that has entered preparation is invisible to its own Distribution Group.** This is not theoretical. Live, right now:

| | |
|---|---|
| DG-001 orders (`distribution_window_orders.virtual_slot_id`) | 3 — ORD-00002, ORD-00006, ORD-00007 |
| Their `orders.status` | all three: `ready_for_dispatch` |
| Group Required products **without** the status filter | 2 rows (FG-HONEY-250 = 2, ECOS-FG-000001 = 1) |
| Group Required products **with** `constrainToEligible` | **0 rows** |

LP-1's screen was browser-verified showing 2 rows on 2026-08-21. It shows **nothing** today, and no one changed the Distribution code. The wave started, and the Group emptied.

The consequence for LP-2 is decisive: **if an operator records "Prepared 5" for a Group today, the Required against which that 5 was recorded is already 0, and the record is born orphaned.** No attribution model, no locking strategy and no state machine fixes that. It is **BLOCKER-LP2-1** and it gates everything else.

### 1.2 Prepared cannot be attributed to a Group — and the reason is worse than LP-1 recorded

LP-1 and the LP architecture report stated the obstruction as: `wave_product_demand` is `unique(preparation_wave_id, product_id)` and a Group is a subset of a wave. That is true but incomplete. Two further facts were found:

- **A Group is not necessarily a subset of ONE wave.** `WaveManager::getActiveWave()` selects `whereIn(status, ACTIVE_STATUSES)->orderByDesc('planning_date')->first()` — several waves can be active for one warehouse across dates, and the module's own comment records that *"three of the five live waves were stranded that way."* A Group's orders can therefore span multiple waves. Even a `(wave, product, group)` grain would not close this.
- **Preparation already has TWO prepared-quantity stores, and the one that feeds Actual Loading is dead.** See §1.3.

### 1.3 The Actual Loading chain is structurally unreachable today

| Store | Written by | Feeds | Live rows |
|---|---|---|---|
| `preparation_wave_items.quantity_prepared` | `CompleteProductAction` (`PATCH waves/{w}/items/{i}/complete`) | `prepared_products_pool` on wave completion | **0** |
| `wave_product_demand.prepared_qty` | `WaveDemandController::updatePrepared` (`PATCH waves/{w}/product-demand/{p}/prepared`) | wave KPIs, wave header, Preparation Workspace UI | **4** |

Both are at `(wave, product)` grain. Both are reachable from the frontend (`preparation-service.ts:132` and `:437`). **Nothing synchronises them.**

Because `preparation_wave_items` is empty, `prepared_products_pool` is empty (0 rows) — and `loading_tasks.pool_entry_id` has nowhere to come from, while `loading_tasks.vehicle_assignment_id` is a NOT NULL FK. `loading_sessions`, `loading_tasks` and `vehicle_assignments` are all at **0 rows**. Actual Loading has never run.

This matters to LP-2 in one specific way: **whatever LP-2 records cannot be handed to Actual Loading today**, because Actual Loading's input (`prepared_products_pool`) is fed by a Preparation path nobody uses. LP-2 must not paper over that by writing into the pool itself (§21).

### 1.4 What LP-2 should be, if the blocker is cleared

**Option A — a Group-level loading-preparation record at `(group, product)`**: operational but non-inventory-mutating, absolute-set with a server-side ceiling under a row lock, state derived from quantities, audited through the existing `AuditService` + `TimelineService`, synchronised through the existing React Query root. One new table, no Preparation change, no Inventory change, no new Loading object.

**Group + Product, not Group + Order + Product.** This is settled by evidence, not preference: `AutoAllocationService` already re-derives order-level attribution itself, from `order_lines.quantity`, at allocation time (`allocation_records` is `unique(vehicle_assignment_id, order_line_id)`). Nothing downstream consumes an upstream per-order prepared figure, and Preparation's certified contract is explicitly product-level — *"the operator states one number per product."*

### 1.5 Honest position

**LP-2 is not implementable as specified until BLOCKER-LP2-1 is decided.** Everything else in this report is ready — the attribution model, the concurrency pattern, the idempotency contract, the audit trail, the state model and the API/table shapes are all determined by existing certified patterns and needed no invention. But recording preparation against a Required that is structurally zero would ship a workflow that is wrong on its first day.

---

## 2. LP-1 Baseline

LP-1 (`TASK-...-LP1-REQUIRED-PROJECTION-001`, **NOT COMMITTED**) delivered:

```
Group → Loading Preparation → Group-specific Required products
```

| LP-1 property | Status | LP-2 impact |
|---|---|---|
| Consumes `GET /windows/{window}/products?slot_id=&warehouse_id=` | unchanged, no new endpoint | LP-2 extends this endpoint additively rather than adding a second one |
| `Required(group, product) = Σ order_lines.quantity` over the Group's eligible, warehouse-scoped orders | canonical | remains the **only** Required definition |
| No `Prepared`, no `Remaining` column; UI states why | deliberate | LP-2 is precisely the decision to change this |
| Permission `logistics.distribution.view` | unchanged | LP-2 introduces a **write**, which needs its own answer (§22) |
| One React Query root `['logistics-distribution-workspace']`; 7 mutations invalidate it | unchanged | LP-2's mutation must join the same root — an 8th |
| Backend change: additive `unit_code` / `unit_symbol` join on `productAggregation` | the one backend touch | LP-2 does not revert it and does not depend on it |
| Focused suite `DistributionGroupLoadingPreparationTest` — 8 tests, 31/31 green with `DistributionCoreTest` | present on disk, uncommitted | test 6 (`…never reports a group prepared or remaining quantity`) **must be retired or inverted** if LP-2 ships |

**One LP-1 contract that LP-2 must not weaken.** LP-1 asserts, in a test and in UI copy, that no Group-scoped Prepared figure can be read from that screen. LP-2 does not overturn the reasoning behind that — wave Prepared still cannot be split across Groups. LP-2 proposes a *different* number, recorded by a different operator against a different scope, which happens to share the English word "Prepared". §11 defines how the two are kept apart.

**LP-1 limitation now proven material.** LP-1 §17.5 flagged that Windows never close (BLOCKER-2). The eligibility finding in §1.1 is a second, sharper instance of the same class of problem, and it was invisible to a static read — it took a live query against real rows.

---

## 3. Existing Preparation Audit

### 3.1 Quantity tables and their grain

| Table | Unique key | Quantities | Live rows |
|---|---|---|---|
| `preparation_wave_items` | `(preparation_wave_id, product_id)` | `quantity_required`, `quantity_prepared`, `quantity_short` | **0** |
| `wave_product_demand` | `(preparation_wave_id, product_id)` | `required_qty`, `prepared_qty`, `remaining_qty`, `orders_count`, `completion_pct` | **4** |
| `preparation_pick_list_items` | `(pick_list_id, product_id)`; `preparation_pick_lists.preparation_wave_id` is itself **unique** | `quantity_to_pick`, `quantity_picked` | 0 |
| `prepared_products_pool` | `(preparation_wave_id, product_id, warehouse_id)` | `quantity_available`, `quantity_reserved`, `quantity_loaded` | **0** |
| `preparation_wave_orders` | `(preparation_wave_id, order_id)` + `uq_prep_wave_orders_company_order_active(company_id, order_id, active_membership)` | **none** | 11 |
| `preparation_inventory_reservations` | — | `quantity_reserved` (soft, ledger-free) | — |

**No Preparation quantity is keyed by order, and none is keyed by anything resembling a Distribution Group.** `preparation_wave_orders` is the only order-grained table and it carries no numbers. Confirmed against the full `ecos_dev` table listing: no table in the schema carries both a slot/group reference and a product quantity.

### 3.2 The two Prepared write paths, and how they differ

**Path 1 — `CompleteProductAction`** (`PATCH preparation/waves/{waveId}/items/{itemId}/complete`, `permission:operations.preparation.update`)

- Absolute set: `quantity_prepared => $quantityPrepared`.
- Ceiling: `quantity_required × (1 + FulfillmentPolicyService::overprepareTolerance(company))` — **over-preparation is permitted within tolerance**.
- Requires `wave.status === Preparing`.
- Derives `quantity_short` and a `WaveItemStatus` (`Prepared` / `Short`).
- `$wave->increment('total_units_prepared', $quantityPrepared)` — an increment, not a delta, so re-recording the same item double-counts the header. Not LP-2's to fix; recorded because it is the one place the platform breaks its own absolute-set discipline.
- Emits `ProductPrepared`; writes `TimelineService` + `AuditService`.

**Path 2 — `WaveDemandController::updatePrepared`** (`PATCH preparation/waves/{waveId}/product-demand/{productId}/prepared`, `permission:operations.preparation.update`)

- Absolute set: `prepared_qty => $prepared`.
- Ceiling: `'max:'.(float) $row->required_qty` — **over-preparation is refused**.
- Floor: `min:0` — Prepared may be reduced to zero. "Undo" is "set to 0".
- Refuses entirely when `material_status === waiting_material`.
- Recomputes stored `remaining_qty` / `completion_pct`, then calls `refreshWaveTotals()`.
- **No lock, no event, no audit, no timeline.** Last write wins.

The two ceilings contradict each other. LP-2 must choose one deliberately (§12, **D-4**).

### 3.3 `prepared_qty` semantics — quoted from the code, not inferred

`DemandReadRepository::upsertProductDemand()`:

> `prepared_qty` is DELIBERATELY ABSENT from this update list. It is operator-owned (product-level Prepared, Option A); a demand rebuild must refresh what the wave requires without discarding what the floor has already prepared.

`WaveDemandController::updatePrepared()`:

> Product-level Prepared (Option A): the quantity of this product actually prepared so far in this wave. It is NOT distributed across order_lines and no allocation rule is applied — the operator states one number per product.

`ProductDemandCalculator`:

> It used to be `SUM(order_lines.prepared_qty)` — a column nothing in the codebase ever writes, so Prepared was permanently 0 and Remaining always equalled Required.

Three certified rules follow, and LP-2 inherits all three:

1. **Prepared is operator-owned and is never discarded by a rebuild.**
2. **Remaining is derived at read time** — `max(0, required − prepared)` — never trusted from storage.
3. **Completion is a separate explicit declaration**, and when Required moves it is the *claim* that is withdrawn, never the quantity (`clearCompletionWhereRequiredChanged()`).

**Live proof that rule 2 is load-bearing.** `wave_product_demand` on `ecos_dev`:

| wave | sku | required_qty | prepared_qty | **stored** remaining_qty | correct remaining |
|---|---|---|---|---|---|
| `01a02038…` | FG-HONEY-250 | 3.0000 | 2.0000 | **0.0000** | 1.0000 |
| `01a02038…` | ECOS-FG-000001 | 5.0000 | 1.0000 | **0.0000** | 4.0000 |

The stored column is already wrong on live data. The API is correct only because it re-derives. **LP-2 must not store Remaining.**

### 3.4 Wave completion and the pool

`CompleteWaveAction` reads `preparation_wave_items.quantity_prepared` — **not** `wave_product_demand.prepared_qty` — and creates or increments `prepared_products_pool` rows plus `prepared_pool_movements`, emitting `PoolUpdated`. Since `preparation_wave_items` is empty on live data, the pool is empty and this entire branch is inert.

### 3.5 `refreshKpis` / wave completion logic

`DemandProjectionBuilder::refreshWaveTotals()` → `refreshKpis()` → `WaveKpiCalculator` → `upsertWaveKpis()` + `syncWaveHeader()`. `WaveKpiCalculator` computes prepared/remaining **from `wave_product_demand`** as `(required_qty − prepared_qty)`. The wave header therefore agrees with Path 2 and ignores Path 1.

### 3.6 Existing Preparation domain events

53 event classes exist. Those relevant to a Group preparation record: `ProductPrepared`, `PoolUpdated`, `WaveStarted`, `WavePreparationStarted`, `WaveCompleted`, `WaveClosed`, `WaveCancelled`, `OrderAddedToWave`, `OrderRemovedFromWave`. Preparation also **consumes** three inbound Loading events (`LoadingPoolReservedEvent`, `LoadingPoolReservationReleasedEvent`, `LoadingProductLoadedEvent`), each handled with `DB::transaction` + `lockForUpdate` on the pool row.

Critically, `WaveStarted` and `WavePreparationStarted` are the events that move orders to `ready_for_dispatch` (§4.5) — they are the mechanism of BLOCKER-LP2-1.

---

## 4. Existing Distribution Audit

### 4.1 The Group

`distribution_virtual_slots`: `id`, `company_id`, `distribution_window_id`, `warehouse_id` (NOT NULL since Part 5B), `code`, `name`, `capacity_orders`, plus the three forbidden dimensions `capacity_stops` / `capacity_weight_kg` / `capacity_volume_m3` (all NULL in practice — BLOCKER-3 of the LP architecture report, unchanged). Unique `(distribution_window_id, code)`.

**No product dimension. No quantity column. One row per Group.** Live: 1 row — DG-001, `capacity_orders = NULL`.

### 4.2 Group ↔ Zone and Window ↔ Order

- `distribution_slot_zones` — unique `(distribution_window_id, warehouse_id, distribution_zone_id)`.
- `distribution_window_orders` — **`unique(order_id)` globally**; `virtual_slot_id` nullable.

**An Order belongs to at most one Group.** This is what makes an order→group function exist, and it is why Option D (§10.4) is worth evaluating rather than dismissing.

### 4.3 Membership rows survive ineligibility

`DistributionCollectionService` writes the assignment row and *"never touches `orders`"*. `ManualAssignmentService::detachZone()` sets `virtual_slot_id = NULL` rather than deleting. Nothing anywhere deletes a `distribution_window_orders` row when an order becomes ineligible.

**This is the fact that makes BLOCKER-LP2-1 fixable.** The Group→Order link is durable; only the *reads* hide it. Live: DG-001 still holds its three `ready_for_dispatch` orders in `distribution_window_orders`.

### 4.4 Every read is eligibility-filtered

`constrainToEligible()` appears at `DistributionAggregationService` lines 78, 270, 391, 487 and 829 — `zoneSummaries`, `slotRollup`, `productAggregation`, the order list and `slotOrderCounts`. There is exactly one eligibility opinion and it is Preparation's, read through `PreparationEligibilityReader`. That design is correct and LP-2 does not propose a second reader.

### 4.5 The eligibility window closes at wave start — mechanism

```
WaveStarted / WavePreparationStarted
  → HandlePreparationWaveStarted / HandlePreparationWavePreparationStarted
      → FulfillmentEngine::run(MoveToPreparationWorkflow, $order)
          → guard: status ∈ OrderStatus::fulfilmentEligible() = [in_progress, confirmed]
          → reserve inventory (ReserveOrderInventoryAction)
          → $order->update(['status' => OrderStatus::ReadyForDispatch])
```

`HandlePreparationWaveClosed` states the intended meaning of the state in its own comment: **"CASE B — done, waiting to be loaded."**

So `ready_for_dispatch` means *exactly* "prepared, awaiting loading" — the population Loading Preparation exists to serve — and it is the one status Distribution filters out.

### 4.6 Mutations, events, audit

`ManualAssignmentService` owns `assignZoneToSlot`, `detachZone`, `moveZone`, `changeOrderZone`, `changeOrderSlot`, `assignLateOrder`. Each mutating method wraps `DB::transaction`. **No capacity check on any path.** `assignZoneToSlot` / `detachZone` / `moveZone` dispatch **no events at all**; `changeOrderZone` / `changeOrderSlot` dispatch `DistributionAssignmentChanged`; `assignLateOrder` dispatches `LateOrderManuallyAssigned`.

`LogisticsDistributionServiceProvider` registers **migrations only** — no listeners, no bindings. Distribution's three domain events have zero consumers.

**Distribution writes no audit and no timeline.** Zero references to `AuditService` or `TimelineService` anywhere in `Modules/Logistics/Distribution/`. LP-2's audit trail would be the module's first (§17).

### 4.7 Synchronisation

`use-distribution-workspace.ts`: one root `['logistics-distribution-workspace']`, a `useInvalidateWorkspace()` that invalidates it, 7 mutations wired to it, and LP-1's `groupProducts` key as a strict prefix extension. This is the mechanism LP-2 reuses (§18).

---

## 5. Existing Loading Audit

### 5.1 Everything is vehicle-anchored

| Object | Anchor | Can exist pre-vehicle? |
|---|---|---|
| `loading_sessions` | warehouse + operational_date; `vehicle_plan_id` nullable | yes |
| `vehicle_assignments` | vehicle | n/a |
| `loading_tasks` | `vehicle_assignment_id` **foreignUuid → constrained, NOT NULL**; also `pool_entry_id`, `preparation_wave_id`; unique `(vehicle_assignment_id, product_id)` | **no** |
| `allocation_records` | `vehicle_assignment_id` NOT NULL; unique `(vehicle_assignment_id, order_line_id)` | **no** |
| `vehicle_inventory_items` / `vehicle_inventory_movements` | vehicle assignment | no |
| `shipment_groups` / `shipment_group_items` | `loading_session_id` + `vehicle_assignment_id` | no |

**`loading_tasks` cannot represent LP-2 data.** The task brief anticipated this and forbade forcing LP-2 into it; the audit confirms the FK makes it impossible rather than merely unwise. `shipment_groups`, despite the name, is not a pre-vehicle grouping either — its items are vehicle assignments.

### 5.2 Actual Loading derives order attribution itself

`AutoAllocationService::allocate()`:

```php
$lines = OrderLine::where('order_id', $orderId)->whereIn('product_id', $productIds)->get();
foreach ($lines as $line) {
    if (AllocationRecord::where('vehicle_assignment_id', …)->where('order_line_id', $line->id)->exists()) continue; // idempotent
    $item = VehicleInventoryItem::where(…)->lockForUpdate()->first();
    $qtyAllocated = min((float) $line->quantity, (float) $item->quantity_unallocated);
    …
}
```

It reads `order_lines.quantity` and the vehicle's **product-level** inventory, and produces order-line-level allocation. **No upstream per-order prepared quantity is consumed, and none would be used if it existed.** This is the decisive evidence for §15.

### 5.3 Existing concurrency and idempotency patterns

The module already has the exact patterns LP-2 needs:

- **`RecordProductDeliveryAction`** — `DB::transaction` + `lockForUpdate` on the contended row + terminal-state guard + server-side ceiling + **absolute set with delta propagation**. Its docblock states the idempotency rationale outright: recording "what has actually been delivered" makes *"replaying the same confirmation a no-op rather than a double count."* Its comment names its own precedent: *"Same lockForUpdate pattern the module already uses for contended rows (CapacityLedgerService)."*
- **`LoadProductAction`** — refuses over-load against `quantity_planned` with epsilon `0.00005`, explicitly rather than inventing a write-off.
- **`CapacityLedgerService::reserve()`** — *"Lock the slot: two concurrent reservations against the last order must not both succeed."*

`lockForUpdate` census: Inventory 11, Preparation 7, Loading 7, GoodsReceipts 4, Manufacturing 4+3, Network 3, Orders 3, Distribution 2 (both in `TripService`). **This is the house pattern. LP-2 must not invent a different one.**

### 5.4 Loading frontend

`frontend/src/features/operations/loading-os/` contains `hooks/`, `pages/`, `services/`, `types/` and **no `components/` directory**. Nothing there is pre-vehicle.

---

## 6. Existing Inventory Audit

### 6.1 There is no staged / prepared / picked inventory state

`inventory_items` on `ecos_dev`:

```
id, warehouse_id, product_id, company_id, on_hand_qty, reserved_qty, deleted_at, timestamps
```

Two quantity columns. **No `staged_qty`, no `prepared_qty`, no `picked_qty`, no bin or location concept.** Physically separating stock onto a staging pallet for DG-001 has **no representation in inventory at all**, and cannot acquire one without an Inventory schema change — which the Inventory Architecture Freeze puts out of reach for this task.

### 6.2 Where inventory actually moves

- `ReceiveStockAction` — `findOrCreate` + `lockForUpdate` + increment `on_hand_qty`; *"Does not touch reserved_qty"*; publishes `InventoryStockReceived` **after commit**.
- `ReserveStockAction` / `ReleaseStockAction` / `ShipStockAction` / `AdjustmentIn|Out` / `DirectIssueStockAction` — all `lockForUpdate` on the item inside a transaction.
- **Reservation for a distribution order already happens at wave start**, inside `MoveToPreparationWorkflow`, via `ReserveOrderInventoryAction` — *before* any Group preparation could be recorded, and keyed by order, not by Group.
- `PreparationInventoryReservation` is a **soft** reservation: *"A soft reservation does not remove stock from the ledger."* Keyed `(wave, reservable)`; no group dimension.

### 6.3 Consequence for LP-2

The inventory commitment for a Group's orders is **already made** by the time LP-2 could run. A second commitment at Group preparation would double-reserve. There is no unreserved state left for LP-2 to claim, and no staged state for it to write. **The only defensible inventory boundary is: LP-2 mutates no inventory** (§19).

---

## 7. Existing Order Audit

### 7.1 Statuses

`OrderStatus`: `in_progress`, `confirmed`, `ready_for_dispatch`, `out_for_delivery`, `delivered`, `awaiting_payment`, `awaiting_stock`, `scheduled`, `on_hold`, `cancelled`, `returned`.

`OrderStatus::fulfilmentEligible() = [InProgress, Confirmed]` — the source of `distribution.eligible_order_statuses`.

The transition that matters to LP-2 is §4.5. Two further paths reach `ready_for_dispatch`: `PrepareOrderAction` (explicit operator action) and `PatchOrderAction` (status patch), both through the same `MoveToPreparationWorkflow`. `ReturnToProcessingWorkflow` walks it back to `in_progress`, which is what `HandlePreparationWaveClosed` uses for unfinished work when a wave ends.

### 7.2 Order line quantity semantics

`order_lines.quantity` is `decimal(12,4)` — the canonical Required input, used by `productAggregation`, `ProductDemandCalculator`, `productRelatedOrders` and `AutoAllocationService` alike. One definition, used everywhere.

### 7.3 The inert fulfilment quantity columns

`2026_07_14_100001_add_fulfillment_quantities_to_order_lines.php` added, as `float` (not `decimal`):

```
reserved_qty, available_qty, prepared_qty, packed_qty, loaded_qty,
delivered_qty, returned_qty, cancelled_qty, warehouse_name, batch_number
```

**Nothing writes any of them.** The only reader is `OrderResource:201`, which exposes `prepared_qty` in the Orders API — permanently `0`. `ProductDemandCalculator`'s comment records the history: Prepared was moved off this column precisely because nothing wrote it.

This is the only order-grained "prepared" column in the platform, and it is dead. It is evaluated fairly as Option D in §10.4.

### 7.4 Order-level Required already has a drill-down

`WaveDemandController::productRelatedOrders` returns, for a `(wave, product)`, each contributing order with `SUM(ol.quantity) AS required_qty`. **Order-level Required exists as a read. Order-level Prepared does not exist anywhere.**

---

## 8. Definition of "Prepared for Group"

The task asked which of five meanings applies, and required the answer to come from code, not naming. Each option is tested against the audit.

| Option | Verdict | Evidence |
|---|---|---|
| **A. Product physically separated from warehouse stock** | **No** — not representable | `inventory_items` has only `on_hand_qty` and `reserved_qty` (§6.1). "Separated from stock" would need a state that does not exist. Nothing decrements `on_hand_qty` at preparation time; only `ShipStockAction` does, at dispatch. |
| **B. Product prepared for a customer order** | **No** | Preparation's Prepared is explicitly *not* order-attributed: *"It is NOT distributed across order_lines and no allocation rule is applied."* No order-grained prepared quantity is written anywhere (§3.1, §7.3). |
| **C. Product reserved for the Group** | **No** — already taken, at a different grain | Reservation is `inventory_items.reserved_qty`, written **per order** by `ReserveOrderInventoryAction` inside `MoveToPreparationWorkflow` at wave start (§6.2). It is complete before Group preparation could begin. A second "reservation" would double-count. |
| **D. Product staged for future loading** | **Yes** | This is the only meaning that is (i) not already occupied by an existing contract, (ii) consistent with the approved position of Loading Preparation between Groups and Vehicle Assignment, and (iii) achievable without touching Inventory, Preparation or Orders. |
| **E. Something else the system already defines** | **No** | No `(group, product)` grain exists in any table (§3.1, §4.1). `prepared_products_pool` is the closest concept and is `(wave, product, warehouse)` — and empty. |

### The definition

> **Prepared for Group** is the quantity of a product that a warehouse operator declares has been **physically set aside for this Distribution Group's planned departure**.
>
> It is an **operational record of floor work**. It is **not** an inventory movement, **not** a reservation, **not** an order-fulfilment quantity, and **not** a claim that the Group is loaded, approved, finalized or dispatched.
>
> It is measured in the product's own unit, at `(Distribution Group, Product)` grain, and it is owned by the person who separated the goods.

### What it is explicitly NOT

It is **not** the same number as `wave_product_demand.prepared_qty`, even when the two happen to be equal. They differ in scope (one wave vs one Group), in owner (the wave's preparation operator vs the Group's loading operator), and in lifecycle (the wave's cycle vs the Window's day). §11 defines how the UI and API keep them apart.

---

## 9. Source of Truth

### 9.1 One definition per quantity

| Quantity | Canonical source | Storage | Grain |
|---|---|---|---|
| `Required(group, product)` | `DistributionAggregationService::productAggregation($window, null, $slot, $warehouse)` | **none — live** | Group × Product |
| `Prepared(group, product)` | the new LP-2 record (§23) | **stored** | Group × Product |
| `Remaining(group, product)` | `max(0, Required − Prepared)` | **none — derived at read time** | Group × Product |
| `Required(wave, product)` | `wave_product_demand.required_qty` | stored, rebuilt | Wave × Product |
| `Prepared(wave, product)` | `wave_product_demand.prepared_qty` | stored, operator-owned | Wave × Product |
| order quantity | `order_lines.quantity` | stored | Order × Product |

**No second Required engine is created.** LP-2 reads Required from `productAggregation`, exactly as LP-1 does. **No second Prepared engine is created inside Preparation** — LP-2 stores a different measurement of a different scope, and never writes any Preparation table.

### 9.2 The one arithmetic identity that must never be asserted

```
Σ Prepared(group, product) over the groups of a wave  ≠  Prepared(wave, product)
```

Three independent reasons, all evidenced:

1. Groups do not partition a wave — a wave's orders may be ungrouped (`virtual_slot_id IS NULL`; live: 4 of 7 window orders are ungrouped).
2. A Group's orders may span several waves (§1.2).
3. The two numbers are declared by different people at different times about different physical acts.

**The system must never sum, compare or reconcile these two figures automatically.** Any screen showing both must label the scope on every row.

---

## 10. Attribution Model

Each of the four options from the brief, judged against the audit.

### 10.1 Option A — Group-level loading preparation records — **RECOMMENDED**

One row per `(Distribution Group, Product)` carrying `prepared_qty`, plus who and when.

- **Cost:** one new table. No Preparation change, no Inventory change, no Orders change, no Loading change.
- **Grain matches the work:** the floor separates *product* quantities for a *Group*.
- **Matches the certified Preparation model** — product-level, operator-owned, one number per product — without writing Preparation's tables.
- **Honest:** it never states a number it cannot justify, because the number is declared, not derived.
- **Weakness:** when an order moves between Groups, the prepared quantity does **not** follow it (§14). That is treated as an explicit reconciliation, not hidden.

### 10.2 Option B — Loading preparation task lines — **not recommended as a distinct option**

A row per `(Group, Product)` carrying `Required / Prepared / Remaining / Operator / Timestamp` is Option A plus stored `Required` and `Remaining`.

- Storing `Required` creates a second Required engine — forbidden, and §3.3's live evidence shows a stored derived quantity going stale within days.
- Storing `Remaining` is the same defect, already observed live.
- The residual difference — `Operator` and `Timestamp` — is retained in Option A as `last_recorded_by` / `last_recorded_at`.

**Option B reduces to Option A once the two derivable columns are removed. That reduction is the recommendation.**

### 10.3 Option C — attribution layer over existing Preparation quantities — **rejected**

"Record which Group received which prepared quantity", constrained so that `Σ group-attributed ≤ wave prepared`.

Rejected on four grounds:

1. **The ceiling has no single source.** Preparation has two prepared stores with different ceilings (§3.2), and the one that would be the natural anchor (`preparation_wave_items`) is empty.
2. **A Group is not a subset of one wave** (§1.2), so the constraint would have to sum across an unbounded set of waves.
3. **It inverts the dependency.** The attribution layer would have to be maintained whenever Preparation's number moves, making Distribution a consumer of Preparation's *write* path rather than its read path.
4. **The stored shape is identical to Option A** — a `(group, product, qty)` row. Option C is Option A with a fragile constraint bolted on.

### 10.4 Option D — an existing architecture already supports it — **evaluated, rejected, with its one real advantage named**

The only genuine candidate is **`order_lines.prepared_qty`**. Because `distribution_window_orders.unique(order_id)` makes order→group a function, `Prepared(group, product)` would be derivable as `Σ order_lines.prepared_qty` over the Group's orders. It requires **no new table**.

**Its one real advantage:** when an order moves from Group A to Group B, its prepared quantity moves with it automatically. Option A cannot do that.

**Why it is nonetheless rejected:**

1. **It forces order-level data entry.** The operator would have to state a quantity per order per product. Preparation's certified contract is the opposite — *"the operator states one number per product"* — and splitting a Group-product quantity across orders needs an allocation rule the contract forbids inventing.
2. **It is a silent Orders API contract change.** `OrderResource` already exposes `prepared_qty`. It would begin returning non-zero values with a new meaning ("prepared for a distribution group"), changing an existing public field without a version.
3. **It is a cross-module write.** Distribution would write `Modules/Commerce/Orders` data, which no Distribution code does today (`DistributionCollectionService`: *"This class never touches `orders`"*).
4. **Precision mismatch.** The columns are `float`; every other quantity in the platform is `decimal(18,4)` or `decimal(12,4)`.
5. **The auto-transfer advantage is a liability on the floor.** Goods physically separated into Group A's staging area would silently be reported as prepared for Group B, while the pallet has not moved. An honest orphan the operator must resolve is safer than a quantity that teleports.

**If the owner values auto-transfer above the five objections, Option D is viable and should be chosen deliberately — it is not unsafe, it is a different trade.** It is listed as decision **D-3**.

### 10.5 Recommendation

**Option A, at `(Group, Product)` grain, with `Required` and `Remaining` derived and never stored.**

---

## 11. Required / Prepared / Remaining Contract

### 11.1 The contract

```
Required(group, product)  = Σ order_lines.quantity
                            WHERE order ∈ group's orders
                              AND orders.assigned_warehouse_id = group.warehouse_id
                              AND <loading-eligibility predicate>          ← see BLOCKER-LP2-1
                            → live, per request, never stored

Prepared(group, product)  = the operator's declared quantity
                            → stored, one row per (group, product), absolute value

Remaining(group, product) = max(0, Required − Prepared)
                            → derived at read time, never stored
```

Rounded to 4 decimal places on both write and comparison, matching `WaveDemandController::updatePrepared` (`round($value, 4)`), with float comparisons at `EPSILON = 0.00005` matching `RecordProductDeliveryAction` and `LoadProductAction`.

### 11.2 Why there cannot be a "Preparation Remaining" and a "Loading Remaining" in conflict

They are not two values of one quantity; they are one value each of **two different quantities at two different grains**:

- `Remaining(wave, product)` answers *"how much of this product does this wave still have to prepare?"*
- `Remaining(group, product)` answers *"how much of this product does this Group still have to separate?"*

Both are `max(0, Required − Prepared)` over their own scope. Neither is stored. They cannot drift, because neither has a stored copy to drift from. What they can do is **differ**, legitimately — and the UI must label the scope so that difference reads as information, not as a bug (§26).

### 11.3 One case that must be handled, not defined away

`Prepared > Required` is reachable without any over-preparation: Required drops when an order leaves the Group or becomes ineligible. Remaining is floored at 0 by the formula, so the excess would be silent. **The excess must be surfaced explicitly** as `over_prepared_qty = max(0, Prepared − Required)` — derived, not stored — and rendered as a reconciliation item (§14). This mirrors `clearCompletionWhereRequiredChanged`, which exists for exactly this shape of problem.

---

## 12. Partial Preparation

### 12.1 Allowed values

`Prepared ∈ [0, ceiling]`, any value in between. `Required = 20` therefore admits `0, 5, 10, 20` and every intermediate value. Partial preparation is the normal state, not an exception.

### 12.2 The four rules, and where each comes from

| Question | Contract | Source |
|---|---|---|
| Can `Prepared` exceed `Required`? | **Decision required** — the two precedents disagree | `WaveDemandController` validates `max: required_qty` (refuse). `CompleteProductAction` allows `required × (1 + tolerance)` (permit). **D-4** |
| Can `Prepared` decrease? | **Yes** | `updatePrepared` validates `min:0` on an absolute set. Reducing is a normal correction. |
| Can an operator undo? | **Yes — set to 0** | Same mechanism. No separate "undo" verb, no delete. |
| Can preparation be reopened? | **Not applicable if state is derived** | With no stored state there is nothing to close (§16). If an explicit "done" declaration is added, its withdrawal must be a first-class action — `uncompletePreparation` is the exact precedent. **D-5** |

### 12.3 Recommendation on the ceiling

**Refuse `Prepared > Required` (the `WaveDemandController` rule).** Reasons: it is the rule on the live path; the over-prepare tolerance lives on a dead path; and an over-prepare tolerance is a *manufacturing* concept (produce a little extra to cover waste), whereas separating more units than a Group needs onto its pallet is simply an error. Flagged as **D-4** because it is a behaviour choice, not a fact.

---

## 13. Concurrency

### 13.1 The scenario, resolved

> Operator A records 5, Operator B records 5, only 7 remained.

Under the recommended contract this cannot produce 10, because **the write is an absolute set, not an increment**. A records `Prepared = 5`; B records `Prepared = 5`; the row reads 5. The lost-update the scenario fears is structurally impossible for the *quantity*.

What remains is the read-modify-write hazard on the **ceiling check** and on any derived aggregate. That is what the lock is for.

### 13.2 The pattern — taken verbatim from existing code, not invented

```php
DB::transaction(function () {
    // 1. Lock the contended row. Creates it if absent, inside the same transaction.
    $row = GroupProductPreparation::query()->lockForUpdate()
        ->firstOrCreate([...]);              // unique(virtual_slot_id, product_id) is the real guard

    // 2. Recompute the ceiling INSIDE the lock, from live Required.
    $required = $aggregation->requiredFor($slot, $product);

    // 3. Fail closed on over-preparation. Epsilon matches the platform.
    if ($newQty - $required > 0.00005) { throw ...; }   // subject to D-4

    // 4. Absolute set. Replay is a no-op.
    $previous = (float) $row->prepared_qty;
    $row->prepared_qty = $newQty;
    $row->last_recorded_by = $actorId;
    $row->last_recorded_at = now();
    $row->save();

    // 5. Audit the transition, previous → new.
});
```

Precedents, all existing and all in the same shape: `RecordProductDeliveryAction` (lock + terminal guard + ceiling + absolute set + delta), `CapacityLedgerService::reserve` (*"two concurrent reservations against the last order must not both succeed"*), `LoadProductAction` (fail-closed ceiling with the same epsilon), `AutoAllocationService` (`lockForUpdate` on `VehicleInventoryItem`), the three Preparation pool listeners.

### 13.3 What is deliberately NOT proposed

- **No optimistic concurrency / version column.** Nothing in the platform uses one; introducing it here would be a second concurrency idiom.
- **No advisory or application-level lock.** `lockForUpdate` inside a transaction is the house pattern.
- **No increment-based API.** An increment API would require an operations log to be idempotent (§20), which is a larger object than the record itself.

### 13.4 The trade-off, stated rather than buried

Absolute-set means **last write wins**, so two operators working the same product concurrently will overwrite one another rather than sum. If the business genuinely needs "each operator adds their own contribution", the model must change to an append-only movements table (the `prepared_pool_movements` shape) with the quantity as a derived `SUM`. That is a materially larger object and is **not** recommended for the first phase. Recorded as **D-6**.

---

## 14. Group Changes After Preparation

### 14.1 The governing rule, inherited not invented

`DemandReadRepository::clearCompletionWhereRequiredChanged()`:

> `prepared_qty` is deliberately NOT touched. Rule: the floor's number is never discarded, only the completion claim is withdrawn.

LP-2 adopts this verbatim: **the floor's number is never discarded, moved, split or recomputed by a Group change.** Only the *interpretation* changes, and the change is surfaced.

### 14.2 The matrix

| Event | `Required(A)` | `Required(B)` | `Prepared(A)` | `Prepared(B)` | Operator sees |
|---|---|---|---|---|---|
| Order moves A → B | ↓ | ↑ | **unchanged** | **unchanged** | A: over-prepared by the moved quantity. B: remaining increases. Physical transfer is a floor action the operator performs and then records on both rows. |
| Zone removed from A | ↓ (possibly to 0) | — | **unchanged** | — | A: over-prepared. Row retained. |
| Zone moved A → B | ↓ | ↑ | **unchanged** | **unchanged** | Same as order move, at zone scale. |
| Order becomes ineligible (cancelled, postponed) | ↓ | — | **unchanged** | — | A: over-prepared. **No Distribution write occurs** — `constrainToEligible` simply stops matching. |
| Order becomes eligible again | ↑ | — | **unchanged** | — | Remaining reappears; the earlier prepared quantity still counts. |
| Order quantity edited | ↕ | — | **unchanged** | — | Remaining or over-prepared adjusts on next read. |
| Group deleted | n/a | — | **orphan** | — | See §14.4. |

### 14.3 Ownership, rollback, transfer, orphans, reconciliation

- **Ownership.** The row belongs to the Group, identified by `virtual_slot_id`. It is not owned by an order, a wave or a warehouse operator.
- **Rollback.** There is none, and none is needed: nothing was consumed, reserved or moved. Correcting a mistake is setting the quantity to its correct value.
- **Transfer.** **Never automatic.** Moving prepared units between Groups requires a physical act, so it requires two deliberate writes (reduce A, raise B). An automatic transfer would assert a physical fact that has not happened. This is the single most important rule in this section.
- **Orphans.** A row with `Prepared > 0` and `Required = 0` is an orphan. It is **retained**, never auto-deleted, and surfaced in a "Prepared but no longer required" list. Auto-deleting would destroy the record of goods sitting on a pallet.
- **Reconciliation.** Entirely **derived** — `over_prepared_qty = max(0, Prepared − Required)` per row, plus the orphan list. **No reconciliation table, no reconciliation state, no new storage.**

### 14.4 Group deletion — a genuine gap

Group deletion is not audited in this report because **no Group delete path exists** — `DistributionWindowController` exposes `storeSlot` but no destroy. If one is added later it must refuse while any row has `Prepared > 0`, or explicitly re-home the rows. Recorded as **D-7**.

---

## 15. Order vs Product Attribution

**Answer: `Group + Product`. Not `Group + Order + Product`.**

This is settled by evidence:

1. **The downstream consumer already re-derives order attribution.** `AutoAllocationService` allocates from **product-level** vehicle inventory to `order_lines` at allocation time, producing `allocation_records` keyed `(vehicle_assignment_id, order_line_id)` and reading `order_lines.quantity` directly (§5.2). It neither reads nor needs an upstream per-order prepared figure.
2. **Preparation's certified contract is product-level.** *"It is NOT distributed across order_lines and no allocation rule is applied — the operator states one number per product."* An order-level LP-2 would be stricter than the module it sits downstream of.
3. **Order-level Required is already available as a drill-down** where it is genuinely needed: `productRelatedOrders` returns per-order `required_qty` for a `(wave, product)`. The same shape can be provided for a Group without storing anything.
4. **No order-grained prepared quantity exists anywhere** — the only column that could carry one (`order_lines.prepared_qty`) has never been written (§7.3).

So for the brief's worked example, the warehouse needs:

```
Product X = 10        ← what LP-2 records
```

and can *see*, on demand and without storing it:

```
Order A → Product X = 4     ← derived from order_lines, read-only
Order B → Product X = 6
```

**The one case that would force order-level attribution** is per-order sealed picking (each order separated into its own tote at preparation time). Nothing in the audited system does that, and Preparation's product-level contract says the opposite. If the business later requires it, it is a new decision with a real cost — recorded as **F-2** in §28.

---

## 16. State Model

### 16.1 Recommendation: no status column

Every state the workflow needs is a function of two numbers already present:

| State | Predicate | 
|---|---|
| **Not started** | no row, or `Prepared = 0` |
| **In progress** | `0 < Prepared < Required` |
| **Prepared** | `Prepared ≥ Required` and `Required > 0` |
| **Over-prepared / orphaned** | `Prepared > 0` and `Prepared > Required` (includes `Required = 0`) |
| **Nothing to do** | `Required = 0` and `Prepared = 0` (row absent) |

This follows the brief's instruction to prefer derivation, and it follows the platform: `PreparationWaveItem::completionPct()` and `WaveKpiCalculator` both derive status from `(required − prepared)`; `slotSummaries()` deliberately reports `'status' => 'draft'` as a literal rather than inventing a column.

### 16.2 "Blocked" is deliberately absent

Preparation has a blocking state (`material_status = waiting_material`) because it can be waiting on raw material. **LP-2 cannot be blocked in that sense** — it separates goods that already exist. A Group whose product is short is simply `In progress` with a Remaining that will not close, and the reason lives upstream in Preparation. Adding a `Blocked` state here would duplicate a Preparation concern in Distribution.

### 16.3 The one place a real state might be needed — STOP

**Finalize.** The approved flow ends Loading Preparation at `Approval → Finalize`. Finalize means the Group's composition freezes and the projection stops moving. That **cannot** be derived from quantities — it is a declaration about a moment in time.

This was already recorded as **D-7** in the LP architecture report (a `status` + `finalized_at` migration on `distribution_virtual_slots`), and this audit does not change it. **It is out of LP-2's scope.** LP-2 must be designed so that adding Finalize later requires no change to the preparation record — which it does, because a frozen Group simply stops accepting writes.

An optional, weaker marker — a per-row `prepared_completed_at`, mirroring `wave_product_demand.preparation_completed_at` — would let an operator declare "this product is done for this Group" separately from reaching the quantity. It is **not** required for the workflow to function. Recorded as **D-5**.

---

## 17. Audit Trail

### 17.1 What must be recorded

| Field | Source |
|---|---|
| who | `request()->user()->id` |
| when | `now()` |
| Group | `virtual_slot_id` (+ `code` snapshot for readability) |
| Product | `product_id` (+ `sku` snapshot) |
| quantity | the new absolute value |
| previous quantity | read inside the lock before the write |
| resulting quantity | same as "quantity" — absolute set |
| Required at the time | the live value used for the ceiling check |
| reason | optional free text; **required** only when reducing a quantity (**D-8**) |

### 17.2 Reuse, do not build

Two platform services already exist and are used by Preparation's own `CompleteProductAction` for exactly this:

```php
$this->timeline->record(
    companyId: …, subjectType: 'DistributionGroup', subjectId: $slotId,
    eventType: 'group.loading_preparation.recorded',
    title: …, description: "{$new}/{$required} units",
    actorId: …, sourceModule: 'Logistics.Distribution',
);

$this->audit->record(
    action: 'distribution.group_preparation.recorded',
    entityType: 'GroupProductPreparation', entityId: $row->id,
    companyId: …, userId: …,
    oldValues: ['prepared_qty' => $previous],
    newValues: ['prepared_qty' => $new, 'required_qty' => $required],
);
```

**No second audit system is created.** This would be Distribution's first use of either service (§4.6), which is a gap being closed rather than a new mechanism.

### 17.3 One honest limitation

`AuditService::record()` and `TimelineService::record()` both wrap their write in `try { … } catch (Throwable) { }` — *"Audit failures must never block business logic."* They are therefore a **best-effort trail, not a guaranteed ledger.** If Group preparation needs a durable, gap-free history (for example because stock loss will be investigated from it), that requires an append-only table in the `prepared_pool_movements` shape and is a separate decision — **D-9**. The `last_recorded_by` / `last_recorded_at` columns on the row itself are durable regardless, so "who touched this last" always survives.

---

## 18. Events / Synchronization

### 18.1 What is needed

| Requirement | Mechanism | New code? |
|---|---|---|
| The Group panel refreshes after a Prepared write | the LP-2 mutation calls the existing `useInvalidateWorkspace()` | **no** — an 8th mutation on the existing root |
| Group card totals stay consistent with the preparation rows | both are computed per request from the same rows | **no** |
| Zone/order changes update Loading Preparation | the 7 existing mutations already invalidate the root | **no** |
| A change originating outside the workspace (order cancelled, wave postponed) | next-fetch correctness | **no** |
| A second operator's write appears on the first operator's open screen | **not covered** — see §18.3 | decision |

### 18.2 No new domain event

Distribution's three existing domain events have **zero listeners** (§4.6). Adding `GroupPreparationRecorded` would create a fourth unconsumed event. **Do not create it until a consumer exists** — the natural first consumer is Actual Loading, which is itself unreachable today (§21).

### 18.3 The one genuinely new synchronisation risk

LP-1 was read-only, so a stale view was harmless. LP-2 introduces **two operators writing the same row**, and the existing mechanism has no push channel: Operator A will not see Operator B's write until A refetches. Under absolute-set semantics, A's stale screen showing `Prepared = 0` can be submitted as `5` and silently overwrite B's `7`.

Mitigations, in increasing cost:

1. **Return the authoritative row from the write** (the platform already does this — `updatePrepared` returns `presentProductDemand($row->fresh())`) and re-render from it. Cheap, and it makes the *writer's* view correct immediately.
2. **Refetch the Group's rows on window focus** — a TanStack Query option, no new mechanism.
3. **A push channel** — websocket or event fanout. This is **D-5 of the LP architecture report**, still deferred, and materially larger.

**Recommendation: 1 and 2 for the first phase, and state the limitation in the UI** rather than implying live multi-operator co-editing. Recorded as **D-10**.

---

## 19. Inventory Boundary

**Classification: OPERATIONAL BUT NON-INVENTORY-MUTATING.**

Confirmed against existing contracts, not assumed:

| LP-2 must not | Why it is safe to say so |
|---|---|
| deduct stock | No path exists — `inventory_items` has no staged state, and only `ShipStockAction` decrements `on_hand_qty` |
| reserve stock | Already done, per order, at wave start by `ReserveOrderInventoryAction`. A second reservation would double-count |
| consume FIFO layers | FIFO consumption belongs to the costing/ledger path; nothing in Distribution touches it |
| trigger manufacturing | ADR-027: Orders reserve FG only; Manufacturing owns all RM decisions |
| complete order fulfilment | Distribution never writes `orders` — `DistributionCollectionService`: *"This class never touches `orders`"*; and `Order.status` writes must go through `FulfillmentEngine` |
| write `prepared_products_pool` | That table is Actual Loading's input, fed by `CompleteWaveAction`. Writing it from Distribution would inject un-loaded stock into the loading pipeline and would be Distribution writing a Preparation table |

**What LP-2 reads from Inventory: nothing.** Not `inventory_items`, not `stock_ledger_entries`, not `stock_movements`, not `prepared_products_pool`. It reads `order_lines`, Group membership, and its own record.

**The consequence, stated plainly:** LP-2 records that goods have been *physically moved on the floor*, and the system's inventory position does not change. That is correct — the goods are still on hand in the same warehouse, and they are already reserved to their orders. If the business later needs stock to be visible as "staged" (for example so a stock count does not double-count a staging pallet), that is an **Inventory** change under the Architecture Freeze, not an LP-2 change. Recorded as **F-3**.

---

## 20. Idempotency

### 20.1 The scenario, resolved by the data model

> An operator records 10 prepared, the request succeeds, the UI times out. What is the safe retry?

**Retry the identical request.** Because the write is an absolute set, the second request computes the same ceiling, writes the same value, and produces no change. This is exactly the rationale `RecordProductDeliveryAction` states for its own design:

> …so replaying the same confirmation is a no-op rather than a double count.

### 20.2 The platform has no idempotency-key infrastructure, and does not need one here

A survey of `idempotency_key` across the codebase finds it only in **Finance** (`FinancialEvent`), **HR** (`hr_kpi_facts`, `unique(idempotency_key)`) and the **Event Platform** (`EventProcessingLog`). There is **no HTTP `Idempotency-Key` middleware** and no general request-deduplication layer.

Where operational writes need idempotency, the platform achieves it three ways, all structural:

| Technique | Example |
|---|---|
| Absolute set (replay is a no-op) | `RecordProductDeliveryAction`, `WaveDemandController::updatePrepared` |
| A unique index that makes a duplicate impossible | `distribution_window_orders.unique(order_id)` — *"Idempotent by construction rather than by checking… The pre-filter is an optimisation, not the safety mechanism — the database constraint is."* |
| An existence check inside the transaction | `AutoAllocationService`: `if (AllocationRecord::where(…)->exists()) continue; // idempotent` |

LP-2 uses the first two: absolute-set writes, and `unique(virtual_slot_id, product_id)` so a retry that races row creation resolves to one row.

### 20.3 The residual case, named rather than hidden

Absolute-set idempotency is safe for **retry of the same request**. It is *not* safe against a **stale client**: a client that read `Prepared = 0`, was superseded by another operator writing `7`, and then submits `5` will overwrite the `7` — and that is indistinguishable from a legitimate correction. This is the same exposure as §13.4/§18.3 and has the same three answers (return-the-row, refetch-on-focus, or a movements model). It is a **decision, not a defect**, recorded as **D-6** / **D-10**.

---

## 21. Actual Loading Boundary

### 21.1 The boundary is unchanged and structurally enforced

```
Loading Preparation   — Group-scoped, no vehicle, quantities only
        ↓ Vehicle assigned      ← vehicle_assignments row created
        ↓ Driver assigned
   APPROVAL / FINALIZE          ← does not exist yet (D-7, LP architecture report)
        ↓
 ACTUAL LOADING                 — loading_sessions + vehicle_assignments
                                  + loading_tasks (vehicle_assignment_id NOT NULL)
                                  + allocation_records (vehicle_assignment_id NOT NULL)
```

LP-2 creates **no** loading session, **no** vehicle assignment, **no** loading task, **no** allocation record, and touches none of those tables. The FK on `loading_tasks.vehicle_assignment_id` makes the boundary a schema fact, not a convention.

### 21.2 What LP-2 produces for Actual Loading to consume

```
(distribution group, product) → prepared_qty, last_recorded_by, last_recorded_at
```

plus, derived and already available, `required_qty` and `remaining_qty` per row and the Group context LP-1 already surfaces (warehouse, zones, order count, capacity).

The natural future consumption point is **vehicle inventory seeding**: when a vehicle is assigned to a Group, the products and quantities to put on it are the Group's prepared rows. `AutoAllocationService` then allocates that product-level inventory down to `order_lines` exactly as it does today — **LP-2 requires no change to the allocation algorithm.**

### 21.3 The gap that stands between them — not LP-2's to close

Today `loading_tasks.pool_entry_id` and the vehicle inventory chain are fed from `prepared_products_pool`, which is fed by `CompleteWaveAction` from `preparation_wave_items` — **which is empty** (§1.3). So Actual Loading is currently unreachable through its own designed input, independent of anything LP-2 does.

When Actual Loading is revived there will be a choice: keep the pool as the loading input and feed it from Preparation, or re-point loading at the Group's prepared rows. **LP-2 must not pre-empt that choice by writing the pool.** Recorded as **BLOCKER-LP2-3** (informational for LP-2, blocking for the Actual Loading phase).

---

## 22. API Requirements

**No API is created by this task.** The two endpoints below are documented for approval and then this section STOPS.

### 22.1 READ — additive extension of the existing endpoint (preferred)

```
GET /api/logistics/distribution/windows/{window}/products?slot_id={group}&warehouse_id={wh}
```

| | |
|---|---|
| **Method** | GET (unchanged) |
| **Request** | unchanged |
| **Response** | existing fields plus three additive fields per row |
| **Permission** | `logistics.distribution.view` — unchanged |
| **Idempotency** | GET, naturally idempotent |
| **Side effects** | none |

Added fields:

| Field | Source |
|---|---|
| `prepared_qty` | the LP-2 record; `0` when no row exists |
| `remaining_qty` | `max(0, total_quantity − prepared_qty)` — derived in the response, never stored |
| `over_prepared_qty` | `max(0, prepared_qty − total_quantity)` — derived |
| `last_recorded_by`, `last_recorded_at` | the LP-2 record; null when absent |

Extending the existing endpoint (rather than adding a second one) keeps `Required` and `Prepared` on the same server row, which is what stopped a second quantity engine appearing in the LP-1 client. **Naming note:** these fields carry no `wave_` prefix precisely because they are Group-scoped — the opposite of the LP architecture report's §19 proposal, which prefixed wave-scoped fields for the same reason.

### 22.2 WRITE — one new route

```
PUT /api/logistics/distribution/windows/{window}/slots/{slot}/preparation/{product}
```

| | |
|---|---|
| **Method** | `PUT` — chosen deliberately: the semantics are "set this value", which is what makes retry idempotent |
| **Request** | `{ "prepared_qty": number (>= 0, <= required, 4 dp), "reason": string?, "warehouse_id": uuid }` |
| **Response** | the authoritative row, in the **same shape** as one row of 22.1 — one presenter, so read and write can never disagree (the `presentProductDemand` precedent) |
| **Permission** | **DECISION REQUIRED — D-1** (see below) |
| **Idempotency** | yes, by absolute set; no idempotency key needed or available |
| **Side effects** | one row written or created; one audit record; one timeline record. **No inventory, no order, no Preparation, no Loading write.** |
| **Errors** | `422` over-ceiling (subject to D-4) · `422` warehouse mismatch · `404` unknown group/product · `403` permission · `409` reserved for a future Finalize freeze |

**Placement.** Nested under `slots/{slot}` rather than a top-level `preparation/` resource, because the Group is the owner and the route then inherits the existing window+slot resolution and warehouse guard.

**CI constraint.** `tests/Feature/Security/WriteRouteAuthorizationTest` asserts against the **real route table** that every `POST|PUT|PATCH|DELETE` route is authorized by permission middleware, `$this->authorize()`, ownership `abort_if`, or a non-trivial FormRequest. This route must carry one of those from its first commit — it cannot be added to the `ALLOWED` list.

### 22.3 The permission decision — D-1

| Option | Argument for | Argument against |
|---|---|---|
| **Reuse `logistics.distribution.update`** | No new access boundary; anyone who can restructure a Group can obviously record work on it; zero RBAC/seeder work | Conflates *planning* authority with *floor execution*. A warehouse picker who should record quantities would also be able to move zones between Groups |
| **New `logistics.distribution.prepare`** *(recommended)* | Separates floor execution from plan authorship — the same split Preparation already makes between `operations.preparation.view` and `.update` | Requires a permission, an idempotent `RbacSeeder` entry, and a role-matrix decision about who holds it |
| **Reuse `loading.session.operate`** | Semantically "warehouse loading work" | Belongs to the Actual Loading module, which LP-2 must not couple to; would grant loading-session rights as a side effect |

**Recommendation: a new `logistics.distribution.prepare`.** Existing permissions confirmed present in the live `permissions` table: `logistics.distribution.view|create|update|delete`, `operations.preparation.view|create|update|delete`, `loading.session.view|create|operate|dispatch|cancel`, `loading.allocation.view|manage|override`, `loading.vehicle.assign`, `loading.driver.operate`. There is no existing permission that means "record warehouse floor work in Distribution".

**STOP — §22 requires approval before any route is written.**

---

## 23. Database Requirements

**No migration is created by this task.** The proposal below is documented for approval and then this section STOPS.

### 23.1 Why no existing table can safely be reused

| Candidate | Why not |
|---|---|
| `wave_product_demand` | `unique(preparation_wave_id, product_id)` — no Group dimension; a Group can span waves; writing it is a **Preparation change** and would collide with the operator-owned `prepared_qty` contract |
| `preparation_wave_items` | Same grain, Preparation-owned, **0 rows** (dead path), and its ceiling contract differs |
| `prepared_products_pool` | `unique(wave, product, warehouse)` — no Group; it is **Actual Loading's input**, so writing it would inject un-loaded stock into the loading pipeline (§21.3) |
| `preparation_pick_list_items` | `preparation_pick_lists.preparation_wave_id` is **unique** — one pick list per wave, no Group dimension |
| `loading_tasks` | `vehicle_assignment_id` NOT NULL — cannot exist pre-vehicle |
| `allocation_records` | `vehicle_assignment_id` NOT NULL, and `order_line` grain |
| `shipment_group_items` | anchored to `vehicle_assignment_id` + `loading_session_id` |
| `distribution_window_orders` | order grain; no product column, no quantity column |
| `distribution_virtual_slots` | one row per Group; no product dimension |
| `order_lines.prepared_qty` | order+product grain with no Group column; `float`; an Orders-module column already exposed by `OrderResource`; see the full Option D assessment in §10.4 |

**Verified exhaustively:** the `ecos_dev` table listing contains no table carrying both a slot/group reference and a product quantity, and `virtual_slot_id` appears in exactly 7 files, all inside `Modules/Logistics/Distribution`.

### 23.2 Proposed table — `distribution_group_product_preparation`

| Column | Type | Note |
|---|---|---|
| `id` | `uuid` PK | |
| `company_id` | `uuid` NOT NULL | tenant scope, matching every sibling Distribution table |
| `distribution_window_id` | `uuid` NOT NULL | retention/cleanup scope; also lets the row be found when a Group is being examined per Window |
| `virtual_slot_id` | `uuid` NOT NULL | **the Group** — the owner |
| `product_id` | `uuid` NOT NULL | |
| `prepared_qty` | `decimal(18,4)` NOT NULL default `0` | matches every Preparation/Loading quantity; **not** `float` |
| `last_recorded_by` | `uuid` NULL | |
| `last_recorded_at` | `timestampTz` NULL | |
| `notes` | `text` NULL | the optional reason (D-8) |
| `created_at`/`updated_at` | `timestampsTz` | |
| `created_by`/`updated_by` | `uuid` | module convention |

**Keys and constraints**

| | |
|---|---|
| Unique | `(virtual_slot_id, product_id)` — the concurrency and idempotency guard |
| FK | `virtual_slot_id → distribution_virtual_slots.id`, `restrictOnDelete` (a Group with prepared work cannot be deleted out from under it — §14.4) |
| FK | `company_id → companies.id`, `restrictOnDelete` |
| Index | `(company_id, distribution_window_id)` |
| Index | `(company_id, product_id)` |
| Check | `prepared_qty >= 0` via `DB::statement` — **not** `Blueprint::check()`, which is unavailable on this MySQL 8.4 stack |

**Deliberate omissions, each with a reason**

- **No `warehouse_id`.** It lives on `distribution_virtual_slots` and is NOT NULL there. Denormalising it would create a second copy that can disagree with the Group's own ownership — the exact defect Part 5B closed.
- **No `required_qty` and no `required_qty_snapshot`.** Required is live (§9). Preparation stores a Required to detect movement only because *its* Required is stored; ours is not, so movement is detectable directly by comparing live Required against `prepared_qty`.
- **No `remaining_qty`.** Derived. Live evidence of the stored version going stale is in §3.3.
- **No `status`.** Derived (§16).
- **No `preparation_wave_id`.** A Group can span waves (§1.2); binding the row to one wave would be false.

**Scope coverage**

| Scope | Carried by |
|---|---|
| Tenant | `company_id` |
| Warehouse | via `virtual_slot_id → distribution_virtual_slots.warehouse_id` (NOT NULL) |
| Group | `virtual_slot_id` |
| Product | `product_id` |
| Window | `distribution_window_id` |
| Order | **deliberately not carried** — see §15 |

**Backfill:** none. The table starts empty; there is no historical Group preparation to reconstruct, and inventing one would fabricate business data.

**Rollback:** `dropIfExists`. Nothing else reads the table, so the drop is clean. Any UI referencing it degrades to LP-1's Required-only view.

**MySQL compatibility:** no `Blueprint::check()`, no `CONCURRENTLY`, no partial indexes; checks via `DB::statement`, matching the sibling Preparation migrations.

**STOP — §23 requires approval before any migration is authored.**

---

## 24. Preparation Changes Required?

### 24.1 For the recommended design — **NO**

LP-2 as designed reads nothing from Preparation except what LP-1 already reads (`PreparationEligibilityReader`, unchanged) and writes nothing to any Preparation table. `wave_product_demand`, `preparation_wave_items`, `prepared_products_pool` and `preparation_wave_orders` are untouched.

### 24.2 The one exception, and it is BLOCKER-LP2-1

**BLOCKER-LP2-1 cannot be resolved without a change somewhere, and one of the three candidate fixes is in Preparation.** All three are named here; none is implemented; the choice is **D-2**.

| Fix | Where | Minimum change | Assessment |
|---|---|---|---|
| **A. Give Distribution a separate loading-eligibility predicate** *(recommended)* | **Distribution only** | Add `config('distribution.loading_eligible_order_statuses')` = fulfilment-eligible **plus** `ready_for_dispatch`, and a `constrainToLoadingEligible()` method on `PreparationEligibilityReader` used **only** by Loading Preparation reads. Planning reads keep today's predicate | No Preparation change, no Orders change, no schema change. Both halves of the postponement rule are preserved. The Group's *planning* view and its *loading* view legitimately answer different questions |
| **B. Add `ready_for_dispatch` to `distribution.eligible_order_statuses`** | Distribution config | one config line | **Cheapest and most dangerous.** It changes every Distribution read at once — collection, zone rollups, group counts, overflow, the order pool. Orders would be re-collected after preparation and Group counts would jump. Not recommended without its own certification |
| **C. Stop moving orders to `ready_for_dispatch` at wave start** | **Preparation + Orders** | Change `HandlePreparationWaveStarted` / `HandlePreparationWavePreparationStarted` so the status moves later (e.g. at wave completion) | **A Preparation contract change with a large blast radius** — inventory reservation is bound to that transition (`MoveToPreparationWorkflow`), as are `HandlePreparationWaveClosed`'s carry-over cases. **Not recommended.** Documented because the brief requires the minimum Preparation change to be named if one exists |

**The minimum Preparation change is therefore: none, if fix A is chosen.** That is the reason A is recommended.

### 24.3 A Preparation defect surfaced but not owned here

The two divergent Prepared stores (§3.2) and the resulting empty `prepared_products_pool` are a Preparation-owned inconsistency. LP-2 neither depends on it nor fixes it. It is recorded as **F-1** so it is not lost.

---

## 25. Distribution Changes Required?

### 25.1 Group management is not redesigned

`assignZoneToSlot`, `detachZone`, `moveZone`, `changeOrderZone`, `changeOrderSlot`, `assignLateOrder`, Group ownership and Group aggregation are the **unchanged baseline**. LP-2 consumes them and adds nothing to them. In particular:

- No capacity enforcement is added (that is LP-3 / **D-1** of the LP architecture report, still open).
- No zone or order mutation is modified.
- No Group state machine is introduced.
- No `DistributionAssignmentChanged` listener is added.

### 25.2 What LP-2 does add to Distribution

| Addition | Kind |
|---|---|
| `constrainToLoadingEligible()` on `PreparationEligibilityReader` + one config key | **new read predicate** — required by BLOCKER-LP2-1 fix A |
| A `prepared_qty` lookup joined into `productAggregation`'s result, or applied as a decoration in the controller | additive read |
| One new write route + action | new write surface |
| First use of `AuditService` / `TimelineService` in the module | closes an existing gap |
| An 8th mutation on the existing React Query root | frontend |

**One design note worth stating explicitly.** `productAggregation` currently takes `(windowId, zoneId, slotId, warehouseId)` and returns pure aggregation. Joining prepared quantities into it would make it Group-aware in a way it is not today, and it is also called with `slot_id = null`. The safer shape is to **decorate the result in the controller when `slot_id` is present**, leaving `productAggregation` a pure aggregation. That keeps its single existing call site's behaviour identical when no Group is specified.

---

## 26. UI/UX Contract

**No UI is implemented. This is the minimum surface, to be built only after approval.**

### 26.1 Shape

The expected shape from the brief survives the audit, with two additions the audit made necessary:

```
Group (DG-001)
  └── Loading Preparation
        ├── context strip     [existing LP-1: warehouse · zones · orders · capacity]
        ├── product rows      Product · SKU · Required · Prepared · Remaining · Unit · Last recorded
        │     └── editable Prepared (number input, 4 dp, 0 ≤ value ≤ Required)
        ├── reconciliation    "Prepared but no longer required" — only when non-empty
        └── scope note        why Group Prepared ≠ wave Prepared
```

### 26.2 Rules the audit imposes

1. **No client-side arithmetic.** `Required`, `Remaining` and `over_prepared` all arrive from the server on the same row. LP-1's rule 1 stands.
2. **The write is a set, not an increment.** The input must be pre-filled with the current authoritative value, so an operator who types `5` means "the total is 5", never "add 5". Ambiguity here is the difference between 5 and 10 (§13).
3. **Re-render from the write response**, which returns the authoritative row (§18.3).
4. **Scope labelling is mandatory.** Any screen showing both Group Prepared and wave Prepared must label each. Silent co-location of two numbers called "Prepared" is precisely the confusion LP-1 refused to create.
5. **No status chip driven by a stored status** — states are derived (§16) and rendered from the quantities.
6. **No vehicle, driver, weight, volume or capacity control** anywhere in this panel (§27 / Q9).
7. **i18n.** Additive keys in the existing `logistics` namespace under `distributionWorkspace.loadingPreparation`. No new namespace, so no `namespaces.ts` / `i18n/types.ts` registration. EN/AR parity must be verified programmatically, and the screen verified **in Arabic**, because the ESLint i18n rule only flags JSX text nodes and will pass strings hidden in ternaries and label maps.
8. **Design system.** Reuse existing components (`UniversalDataGrid`, `DataGridColumnDef`, existing form primitives). No new primitives.
9. **The empty state must distinguish two different zeros:** "this Group requires nothing" from "this Group's orders are no longer visible to Distribution" — the second is what BLOCKER-LP2-1 produces, and rendering it as the first would hide the defect from the floor.

### 26.3 Controls deliberately NOT included in the minimum

`Done` / `Continue` buttons are **not** included: with derived state there is nothing to close (§16). If an explicit per-product "done" is approved (**D-5**), it is a separate control with a separate withdraw action, mirroring `completePreparation` / `uncompletePreparation`.

---

## 27. Testing Strategy

**No test was written or executed for this task**, and none should be — it is architecture-only. The suites below are **listed, not implemented**.

### 27.1 Focused suites for the implementation phase

| Phase | Test | Proves |
|---|---|---|
| Pre-req (D-2) | An order in a started wave (`ready_for_dispatch`) still appears in its Group's Loading Preparation, and still does **not** appear in the planning reads | BLOCKER-LP2-1 fix A, both halves |
| Pre-req (D-2) | A postponed order disappears from **both** predicates | the postponement half of eligibility survives the new predicate |
| LP-2 | Recording Prepared writes one row and **nothing else** — snapshot `inventory_items`, `stock_ledger_entries`, `wave_product_demand`, `preparation_wave_items`, `prepared_products_pool`, `loading_tasks`, `orders` before and after | §19 inventory boundary + §24 Preparation boundary |
| LP-2 | Two concurrent writes of 5 against a Remaining of 7 leave the row at 5, not 10 | §13 absolute-set + lock |
| LP-2 | Replaying the identical request changes nothing (row, audit count aside) | §20 idempotency |
| LP-2 | `Prepared > Required` is refused (or permitted to the tolerance) — **whichever D-4 decides** | §12 ceiling |
| LP-2 | Two warehouses planning the same Zone cannot read or write each other's Group rows | the Part 5B boundary holds across the new **write** |
| LP-2 | Moving an order A→B leaves both `prepared_qty` values unchanged and surfaces A as over-prepared | §14 — the single most important behavioural rule |
| LP-2 | An ineligible order reduces Required with **zero** Distribution writes, and Prepared survives | §14 |
| LP-2 | `Remaining` is never read from storage — mutate a stored value directly and assert the API still derives correctly | §11 / the live staleness evidence in §3.3 |
| LP-2 | The write route is refused without the permission | the `WriteRouteAuthorizationTest` contract |

### 27.2 Constraints that must hold

- **Focused suites only.** No full-ERP and no full-Distribution regression after each Part. The one regression guard worth running alongside is `DistributionCoreTest`, which owns `productAggregation` — the LP-1 precedent.
- **`DistributionGroupLoadingPreparationTest` test 6 must be explicitly retired or inverted**, not silently deleted; it currently asserts the payload carries no Prepared/Remaining under five names.
- **No fabricated business data.** Every fixture inside `RefreshDatabase`, torn down with it. No live-data mutation.
- **No permanent fixtures**, no duplicate coverage of what `DistributionCoreTest` / `DistributionWarehouseScopedReadsTest` already prove.
- **Run through `GATE_WAIT=2400 ./scripts/test-gate.sh`**, never bare phpunit — the test database is pinned and contended.
- **`route:clear` in the testrunner before any API feature test** — a stale route cache returns 404 while the 401 test still passes.
- **`assertJsonPath` is strict** (`90 ≠ 90.0`); quantities are decimals and must be asserted as floats.

### 27.3 Static gates

ESLint (`ecos-i18n`), `tsc -p tsconfig.app.json` (bare `tsc --noEmit` checks zero files), Vite build, PHPStan and Pint on touched files only — with the pre-existing Pint debt on `DistributionAggregationService` measured against the parent revision so LP-2 is not blamed for it, exactly as LP-1 did.

---

## 28. Open Decisions

### A. Already decided by existing contracts — no approval needed

| # | Settled by |
|---|---|
| `Required(group, product)` is `Σ order_lines.quantity` over the Group's warehouse-scoped, eligible orders | `productAggregation`, LP-1 certified |
| `Remaining` is derived at read time, never stored | `DemandReadRepository` contract + live staleness evidence (§3.3) |
| Prepared is operator-owned and is never discarded by a recalculation | `upsertProductDemand` / `clearCompletionWhereRequiredChanged` |
| Concurrency = `DB::transaction` + `lockForUpdate` + ceiling inside the lock | `RecordProductDeliveryAction`, `CapacityLedgerService`, `AutoAllocationService` |
| Idempotency = absolute set + unique index; no idempotency-key layer exists | §20 |
| Audit = `AuditService` + `TimelineService`; no second audit system | `CompleteProductAction` |
| Attribution is `Group + Product`, not `Group + Order + Product` | `AutoAllocationService` re-derives order attribution (§5.2, §15) |
| LP-2 mutates no inventory | §6, §19 |
| LP-2 creates no loading task, vehicle assignment or allocation record | `loading_tasks.vehicle_assignment_id` NOT NULL |
| Sync = the existing React Query root; no new domain event | §18 |
| No weight, volume, stops or vehicle capacity anywhere in LP-2 | approved D-1 policy; §29 Q9 |

### B. Recommended decisions — proposals, not rulings

| # | Decision | Recommendation |
|---|---|---|
| **D-2** | How to fix BLOCKER-LP2-1 | **Fix A** — a separate `constrainToLoadingEligible()` predicate used only by Loading Preparation. No Preparation change, no schema change |
| **D-3** | Attribution model | **Option A** — a `(group, product)` record. Option D (`order_lines.prepared_qty`) is viable and its one advantage is named in §10.4 |
| **D-4** | May Prepared exceed Required? | **No** — follow `WaveDemandController` (the live path), not `CompleteProductAction`'s tolerance (the dead path) |
| **D-5** | An explicit per-product "done" marker | **Defer.** Derived state is sufficient for the first phase |
| **D-6** | Absolute set vs append-only movements | **Absolute set.** Movements only if per-operator contribution is a real requirement |
| **D-8** | Require a reason when reducing Prepared | **Yes** — a reduction is the one write that destroys a record of work |
| **D-9** | Durable ledger vs best-effort audit | **Best-effort first.** `AuditService` swallows failures by design; `last_recorded_by/at` on the row is durable regardless |
| **D-10** | Multi-operator freshness | **Return-the-row + refetch-on-focus.** No push channel in this phase |

### C. Decisions requiring user approval before implementation

| # | Decision | Why it needs you |
|---|---|---|
| **D-1** | **Permission for the write route** — reuse `logistics.distribution.update`, or create `logistics.distribution.prepare` | It is a new access boundary and a role-matrix change. §22.3 |
| **D-2** | **The BLOCKER-LP2-1 fix** | Fix B changes every Distribution read at once; fix C changes a Preparation contract. Neither may be chosen without you |
| **D-3** | **Attribution model** — A vs D | D removes the need for a new table but forces order-level entry and changes an Orders API field's meaning |
| **D-4** | **Over-preparation ceiling** | Two certified precedents disagree |
| **NEW TABLE** | `distribution_group_product_preparation` (§23) | The brief requires an explicit STOP for any new table |
| **NEW ROUTE** | The write endpoint (§22.2) | The brief requires an explicit STOP for any new API |

### D. Hard blockers — see §29

**BLOCKER-LP2-1** (eligibility window), **BLOCKER-LP2-2** (no Group grain for Prepared), **BLOCKER-LP2-3** (Actual Loading unreachable — informational for LP-2).

### E. Future follow-ups — recorded so they are not lost, not part of LP-2

| # | Item |
|---|---|
| **F-1** | Preparation has two Prepared stores; `preparation_wave_items` and therefore `prepared_products_pool` are empty. Preparation-owned |
| **F-2** | Per-order sealed picking, if the business ever requires it — would force order-level attribution (§15) |
| **F-3** | Inventory has no staged state; a staging pallet is invisible to stock counting (§19) |
| **F-4** | `order_lines.prepared_qty` / `packed_qty` / `loaded_qty` etc. are inert `float` columns exposed by `OrderResource`. Either wire or remove them |
| **F-5** | `CompleteProductAction` increments `total_units_prepared` rather than applying a delta — re-recording double-counts the wave header |
| **F-6** | Group deletion has no path; when added it must refuse while prepared work exists (§14.4) |
| **F-7** | Carried forward, unchanged: LP-arch **D-1** capacity enforcement, **D-2** the two order counts, **D-3** forbidden capacity dimensions in the payload, **D-6** "No Warehouse" orders, **D-7** Finalize, **D-8** Group templates, **D-9** `vehicle_plan_*` residue, and **BLOCKER-2** windows never closing |

---

## 29. Blockers

### BLOCKER-LP2-1 — The Distribution eligibility window closes before preparation begins — **HARD, GATES LP-2**

Wave start moves every order in the wave to `ready_for_dispatch` (`HandlePreparationWaveStarted` → `MoveToPreparationWorkflow`). `config('distribution.eligible_order_statuses')` is `["in_progress","confirmed"]` (verified at runtime). Every Distribution read applies `constrainToEligible`. Therefore a Group's orders vanish from its own Loading Preparation exactly when they become preparable, and `ready_for_dispatch` is the status whose own code comment reads *"done, waiting to be loaded."*

**Live evidence:** DG-001 holds 3 orders in `distribution_window_orders`; all 3 are `ready_for_dispatch`; the Group's product aggregation returns **2 rows without the status filter and 0 rows with it**.

**Impact:** any Prepared-for-Group record written today is born with `Required = 0`. LP-1's screen is also currently blank in production-shaped data, which is a latent defect LP-1 could not have seen.

**Resolution:** **D-2**. Recommended fix A (a separate loading-eligibility predicate in Distribution only) requires no Preparation change and no schema change.

### BLOCKER-LP2-2 — Prepared has no Group grain, and a Group is not a subset of one wave — **HARD, resolved only by a decision**

`wave_product_demand` and `preparation_wave_items` are both `unique(wave, product)`. `prepared_products_pool` is `(wave, product, warehouse)`. None carries a Group. Additionally `WaveManager::getActiveWave()` can return different waves for different dates, and the module records that three of five live waves were stranded across dates — so a Group's orders may span waves, and even a `(wave, product, group)` grain would be insufficient.

**Impact:** a Group-scoped Prepared figure cannot be derived from any existing quantity. It must be **declared**, in its own record, or not exist.

**Resolution:** **D-3** (Option A, a new `(group, product)` table) — which requires the new-table approval in §23.

### BLOCKER-LP2-3 — Actual Loading is unreachable through its own designed input — **informational for LP-2, blocking for the Actual Loading phase**

`loading_tasks.pool_entry_id` requires `prepared_products_pool`, which `CompleteWaveAction` fills from `preparation_wave_items` — **0 rows**. `loading_sessions`, `loading_tasks` and `vehicle_assignments` are all at 0 rows.

**Impact on LP-2: none, provided LP-2 does not write the pool.** It is recorded so that "LP-2 hands its output to Actual Loading" is not assumed to be a short step.

---

## 30. Recommended Implementation Plan

Each phase is independently shippable and independently certifiable. **None is authorized by this document.**

### Phase LP-2.0 — Resolve the eligibility window *(prerequisite, blocks everything)*

Decide **D-2**. If fix A: add `config('distribution.loading_eligible_order_statuses')` and `PreparationEligibilityReader::constrainToLoadingEligible()`, and use it **only** in the Loading Preparation read path. Two focused tests (§27.1). No migration, no new endpoint, no Preparation change, no UI change.

**This phase alone repairs LP-1**, which currently renders an empty screen on live data. It is worth shipping on its own merit even if LP-2 is deferred.

### Phase LP-2.1 — The record *(after D-1, D-3, D-4 and the §22/§23 STOPs are cleared)*

Migration for `distribution_group_product_preparation`; the write action with `DB::transaction` + `lockForUpdate` + ceiling + absolute set; audit and timeline; the additive read fields; the write route with its permission. Focused suite per §27.1. **No UI yet** — the API is certifiable on its own.

### Phase LP-2.2 — The operator UI

Prepared column and inline editor, reconciliation list, scope note, EN/AR. Joins the existing query root as the 8th mutation. Browser-verified in **both** languages.

### Phase LP-2.3 — Reconciliation surfacing

The "Prepared but no longer required" view, derived only. May be folded into LP-2.2 if it stays small; kept separate here because it is the phase most likely to attract scope.

### Explicitly NOT in this plan

Capacity enforcement (LP-3), the "No Warehouse" bucket (LP-4), window close and carry-over (LP-5), Finalize/Approval (LP-6), Vehicle assignment, Driver assignment, Actual Loading, Dispatch, Group templates, and any Preparation or Inventory repair listed in §28.E.

### Ordering constraint

**LP-2.0 must ship before LP-2.1.** Building the record first would put a write surface in front of operators whose Required is structurally zero.

---

## Final Position

Nothing was implemented. No migration, no schema change, no API change, no frontend change, no Preparation change, no Distribution change, no Loading change, no Inventory change, no Order change, no business data written, no commit. All database access was read-only `SELECT`.

The audit's most useful outcome is that **the hardest problem in LP-2 was not the one the brief anticipated.** Attribution, concurrency, idempotency, audit and state all resolved cleanly to patterns the platform already owns and had already certified elsewhere — the answers were found, not designed. What the audit found instead is that **the Distribution eligibility contract removes a Group's work at the exact moment that work becomes preparable**, which makes the operational workflow LP-2 describes impossible to record honestly today, and which has already silently emptied the screen LP-1 shipped.

**Awaiting decisions D-1 through D-10, and rulings on BLOCKER-LP2-1 and BLOCKER-LP2-2, plus explicit approval of the new table (§23) and the new write route (§22), before any implementation.**

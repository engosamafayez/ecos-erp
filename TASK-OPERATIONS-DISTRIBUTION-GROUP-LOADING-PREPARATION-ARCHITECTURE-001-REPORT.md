# TASK-OPERATIONS-DISTRIBUTION-GROUP-LOADING-PREPARATION-ARCHITECTURE-001 — REPORT

**Date:** 2026-08-21
**Status:** ARCHITECTURE / CONTRACT ONLY — nothing implemented, no migration, no schema change, no API change, no frontend change, no business data touched, no commit.
**Method:** audit of existing code and schema first; design proposed only where the audit proved a gap.

---

## 1. Executive Summary

The headline finding is that **most of this phase already exists and is not wired up**.

`DistributionAggregationService::productAggregation($windowId, $zoneId, $slotId, $warehouseId)` already computes per-product required quantity for a single Distribution Group, warehouse-scoped and eligibility-constrained. It is already exposed as `GET /api/logistics/distribution/windows/{window}/products?slot_id=&warehouse_id=` under the existing `logistics.distribution.view` permission. Its own docblock says it is *"the figure Warehouse Loading will later consume."* **No frontend calls it.**

So the Required half of Loading Preparation needs **no migration, no new endpoint, no new permission, and no new service** — only a UI that consumes an endpoint that has been sitting there since the Window work landed.

The Prepared half does not exist and **cannot be derived without a decision**. Preparation's certified contract stores `prepared_qty` at **(wave, product)** granularity and explicitly documents it as operator-owned at product level. A Distribution Group is a *subset* of a wave's orders. Splitting one wave-level prepared number across two Groups that need the same product requires an allocation rule that does not exist and that this task is forbidden to invent. This is **BLOCKER-1** and it is the single decision that gates implementation.

Three further findings materially shape the design:

- **Capacity is reported but never enforced.** `capacity_orders` exists on the Group table, is validated on create, and drives `utilisation` / `overflow_orders` / `is_over_capacity` in the read model — but no write path checks it, and the frontend deliberately never sends it, so **every Group in live data is unconstrained (`NULL`)**.
- **Zone operations emit no domain events, and the three Distribution events that do exist have zero listeners.** Synchronization today is entirely frontend React Query invalidation against one query-key root. That mechanism is sound and should be reused.
- **Actual Loading already exists and is structurally vehicle-anchored** (`loading_tasks.vehicle_assignment_id` is a NOT NULL FK). It therefore *cannot* represent pre-vehicle work. This independently confirms that Loading Preparation must be a **live projection, not rows**.

Recommended shape: **Loading Preparation is a read-only, live-computed projection over current Group membership. It stores nothing, reserves nothing, and consumes nothing.** Because it is computed per request rather than stored, the "30 orders vs products for 32" staleness class is structurally impossible rather than defended against.

---

## 2. Approved Architecture (as received, restated for traceability)

```
Preparation → Eligible Orders → Distribution Window → Distribution Groups
  → Live Loading Preparation → Vehicle → Driver → Approval → Finalize
  → Actual Loading → Dispatch
```

Virtual Vehicle Planning is **removed** and is not reintroduced anywhere in this design. See §3.9 for the residue the removal left behind.

Binding constraints honoured throughout: Group is not a Vehicle and not a Driver; the only approved capacity constraint is **maximum number of orders**; a Group holds one or more Zones; Zones are geography and may be shared across warehouses; warehouse ownership belongs to the Group; Parts 4→5C behaviour is the baseline.

---

## 3. Existing System Audit

### 3.1 The Group is `distribution_virtual_slots`

| Column | Type | Note |
|---|---|---|
| `id` | uuid PK | |
| `company_id` | uuid | |
| `distribution_window_id` | uuid | |
| `warehouse_id` | uuid **NOT NULL** | added by Part 5B, backfilled, enforced |
| `code` | string(50) | unique per window |
| `name` | string(100) nullable | |
| `capacity_orders` | unsignedInteger **nullable** | **the approved constraint — already present** |
| `capacity_stops` | unsignedInteger nullable | **forbidden dimension, present** |
| `capacity_weight_kg` | decimal(12,2) nullable | **forbidden dimension, present** |
| `capacity_volume_m3` | decimal(12,3) nullable | **forbidden dimension, present** |

Indexes: `unique(distribution_window_id, code)`, `(company_id, distribution_window_id)`, `(company_id, distribution_window_id, warehouse_id)`.

The three forbidden capacity dimensions already exist in the schema and — for `capacity_stops`, `capacity_weight_kg` and `capacity_volume_m3` — are **emitted in the Group read model payload** (`DistributionAggregationService::slotSummaries`). They are also accepted by `POST /windows/{window}/slots` validation. They are always `NULL` in practice because the frontend never sends them. See Decision **D-3**.

### 3.2 Group ↔ Zone is `distribution_slot_zones`

`id`, `distribution_window_id`, `warehouse_id` (NOT NULL since 5B), `virtual_slot_id`, `distribution_zone_id`.

Uniqueness is `(distribution_window_id, warehouse_id, distribution_zone_id)` — Part 5B replaced the original `(window, zone)`. This is exactly what allows Warehouse A and Warehouse B to each plan Maadi in their own Group.

### 3.3 Window ↔ Order is `distribution_window_orders`

`id`, `company_id`, `distribution_window_id`, `order_id`, `distribution_zone_id` (nullable), `virtual_slot_id` (nullable), `assignment_source`, `assigned_by`, `assigned_at`, `previous_window_id`, `assignment_reason`.

**`unique(order_id)` is global** — an Order belongs to at most one Distribution Window across all time. This single index is the reason carry-over cannot be implemented by copying rows (§14).

Group membership of an Order is `virtual_slot_id`. It is nullable, so "collected but ungrouped" is a first-class state.

### 3.4 The Window is company+date, not warehouse

`distribution_windows`: `unique(company_id, window_date)`. The Window is **company-wide**; warehouse ownership begins at the Group. This asymmetry is deliberate and is preserved.

### 3.5 Capacity is computed but never enforced

`slotSummaries()` returns `capacity_orders`, `demand_orders`, `utilisation`, `overflow_orders`, `is_over_capacity`, `is_warning` (threshold from `config('distribution.slot.warn_threshold', 0.85)`).

`ManualAssignmentService` — which owns `assignZoneToSlot`, `detachZone`, `moveZone`, `changeOrderZone`, `changeOrderSlot`, `assignLateOrder` — contains **no capacity check of any kind**. Every mutation succeeds regardless of overflow. Capacity is advisory today.

`DistributionGroupsPanel.createGroup` deliberately omits capacities (its comment: sending them "would be read as a capacity of zero"). Therefore **every Group in live data has `capacity_orders = NULL`**, which makes `utilisation` null and `overflow_orders` 0 by definition.

### 3.6 Two order counts for one Group

`slotSummaries()` emits both:

- `orders_count` — from `slotRollup()`, a `leftJoin` aggregate that also produces value/products/paid
- `demand_orders` — from `slotOrderCounts()`, a separate `join` + `COUNT(*)` query used for the capacity maths

Both apply `PreparationEligibilityReader::constrainToEligible()`, so they should agree; they are nonetheless two queries answering one question. The capacity contract must name exactly one. See **D-2**.

### 3.7 Domain events: dispatched, never consumed

| Event | Dispatched by | Listeners |
|---|---|---|
| `OrderAddedToDistributionWindow` | `DistributionCollectionService` | **none** |
| `DistributionAssignmentChanged` | `ManualAssignmentService` (order zone/slot change) | **none** |
| `LateOrderManuallyAssigned` | `ManualAssignmentService` (cross-window move) | **none** |

Verified by grep across `Modules/` and `app/`: **zero listeners registered anywhere.**

Further: `assignZoneToSlot`, `detachZone` and `moveZone` — the three Part 5C Zone operations — **dispatch nothing at all**. Zone-level Group composition changes are domain-silent.

### 3.8 Synchronization is frontend-only, and is correct

`use-distribution-workspace.ts` defines one query-key root:

```ts
const KEYS = { all: ['logistics-distribution-workspace'], … }
function useInvalidateWorkspace() {
  return () => qc.invalidateQueries({ queryKey: KEYS.all });
}
```

All **7** mutations wire `onSuccess: invalidate`. The warehouse id is part of every key, so switching warehouse refetches rather than serving another warehouse's cache.

This is the canonical invalidation mechanism the task asks for. It already exists, it is already whole-surface, and Loading Preparation should join it rather than introduce anything new.

### 3.9 Residue of the removed Virtual Vehicle Planning phase

Still present in schema: `vehicle_plans`, `vehicle_plan_slots`, `vehicle_plan_slot_orders`, `vehicle_plan_adjustment_log`. `loading_sessions.vehicle_plan_id` (nullable) still points at it.

`vehicle_plan_slots` (slot_number, vehicle_id, capacity_weight_kg, capacity_volume_m3, order_count, utilization_pct, is_overloaded) plus `vehicle_plan_slot_orders` (slot→order, zone_id_snapshot, moved_from_slot_id) is a **structural twin of `distribution_virtual_slots` + `distribution_window_orders`**. The removed phase is a dead duplicate of the approved one.

Mitigating fact: `CreateLoadingSessionAction` never references `vehicle_plan_id`. Loading sessions can already be created without a vehicle plan, so re-pointing Loading at Distribution Groups later does not have to fight an existing hard dependency. Per memory, all `vehicle_plan*` tables are at **0 rows**. No cleanup is proposed here (see **D-9**).

### 3.10 Actual Loading already exists — and is vehicle-anchored

`loading_sessions`: warehouse-scoped, `session_number`, `operational_date`, `status`, `orders_count`, `products_count`, `total_units_to_load`, `total_units_loaded`, allocation/loading/dispatch timestamps.

`loading_tasks`: **`vehicle_assignment_id` is a `foreignUuid(...)->constrained('vehicle_assignments')` — NOT NULL**, plus `pool_entry_id`, `preparation_wave_id`, `quantity_planned`, `quantity_loaded`, `quantity_short`, `unique(vehicle_assignment_id, product_id)`.

**A `loading_task` cannot exist before a vehicle assignment exists.** This is the structural proof that pre-vehicle Loading Preparation must not be modelled as loading rows.

### 3.11 Loading and Distribution are completely disconnected

Grep in both directions returns nothing: `Modules/Operations/Loading/` contains no reference to `virtual_slot`, `distribution_window`, `DistributionWindow` or `VirtualCapacitySlot`; `Modules/Logistics/Distribution/` contains no reference to `loading_session`, `LoadingSession` or `loading_task`.

The bridge this phase defines does not exist yet in any form.

### 3.12 Preparation quantity tables — all wave-grained

| Table | Unique key | Quantities |
|---|---|---|
| `preparation_wave_items` | (wave, product) | `quantity_required`, `quantity_prepared`, `quantity_short` |
| `preparation_pick_list_items` | (pick_list, product) | `quantity_to_pick`, `quantity_picked` |
| `prepared_products_pool` | (wave, product, warehouse) | `quantity_available`, `quantity_reserved`, `quantity_loaded` |
| `wave_product_demand` | (wave, product) | `required_qty`, `prepared_qty`, `remaining_qty`, `orders_count`, `completion_pct` |
| `preparation_wave_orders` | (wave, order) | **no quantities at all** |

**Not one Preparation quantity is keyed by order.** `preparation_wave_orders` is the only order-grained table and it carries no numbers.

### 3.13 The certified Required / Prepared / Remaining rule

From `DemandReadRepository::upsertProductDemand()` and `clearCompletionWhereRequiredChanged()`, verbatim from the code:

- `prepared_qty` is **excluded from the upsert update list**: *"It is operator-owned (product-level Prepared, Option A); a demand rebuild must refresh what the wave requires without discarding what the floor has already prepared."*
- `remaining_qty` is **derived at read time**: *"Remaining stays derived and non-negative on its own (`max(0, required - prepared)`) even when Required drops below what was already prepared."*
- Completion is an **explicit operator declaration about a specific Required quantity**. When Required moves — order postponed, added, removed, or quantity edited — the completion claim is withdrawn while `prepared_qty` is preserved.

Recalculation is **explicit and manual**: `POST waves/{waveId}/recalculate`, permission `operations.preparation.update`. It is not event-driven.

### 3.14 Warehouse scoping and the NULL case

```php
private function scopeWarehouse($query, ?string $warehouseId, string $alias = 'o') {
    if ($warehouseId === null) return $query;
    return $query->where($alias.'.assigned_warehouse_id', $warehouseId);
}
```

An equality predicate. `NULL = <uuid>` is never true, so an Order with `assigned_warehouse_id IS NULL` **can never appear under any warehouse filter**.

In `DistributionCollectionService`, `$slotFor(?string $warehouseId, ?int $zoneId)` returns `null` when either is null. A NULL-warehouse Order is therefore collected into the Window with `virtual_slot_id = NULL` and can never be auto-grouped.

### 3.15 Other confirmed facts

- `order_lines.quantity` is `decimal(12,4)` — the canonical required-quantity source.
- **No template or reusable-configuration concept exists anywhere in Distribution.** Groups are per-window only.
- Permissions available: `logistics.distribution.view` / `.create` / `.update`; `loading.session.view|create|operate|dispatch|cancel`; `loading.allocation.view|manage|override`; `loading.vehicle.assign`; `loading.driver.operate`.
- Frontend already has `src/features/operations/loading-os/` (Actual Loading UI). Nothing there is pre-vehicle.

---

## 4. Group Model

**No change proposed.** The Parts 4→5C model satisfies this contract as it stands.

```
DistributionWindow  (company + date)
  └── VirtualCapacitySlot  = DISTRIBUTION GROUP   [owns warehouse_id]
        ├── DistributionSlotZone*   (window + warehouse + zone, unique)
        └── DistributionWindowOrder* (via virtual_slot_id)
```

Identity: a Group is `(distribution_window_id, code)`, owned by exactly one `warehouse_id`.

The Group is **not** a Vehicle and **not** a Driver: it carries no `vehicle_id`, no `driver_id`, and no assignment timestamps. Contrast `vehicle_plan_slots`, which carries `vehicle_id` and `vehicle_assigned_at` — that is precisely the conflation the removed phase embodied and the reason the Group table must not acquire those columns later.

Group membership of an Order is derived, not duplicated: `distribution_window_orders.virtual_slot_id`. No order data is copied into the Group.

---

## 5. Group Configuration / Operational Instance Model

**Audit result: no configuration layer exists, and none is required for this phase.**

| Aspect | Operational Group (exists today) | Reusable configuration (does not exist) |
|---|---|---|
| Lifetime | one Distribution Window | across windows |
| Zones | `distribution_slot_zones` (actual) | preferred zones |
| Orders | `distribution_window_orders.virtual_slot_id` | n/a |
| Capacity | `capacity_orders` (actual) | default maximum orders |
| Loading Preparation | live projection | n/a |

**Recommendation: do not add a template table in this phase.** Justification:

1. The operational Group already carries everything Loading Preparation reads.
2. A template is an operator-convenience feature (pre-seeding tomorrow's Groups), not a correctness requirement. Nothing in the Loading Preparation contract depends on it.
3. `distribution_virtual_slots` cannot double as its own template: every row is bound to a `distribution_window_id`, and `unique(distribution_window_id, code)` means a "template row" would need a fake or null window. Making `distribution_window_id` nullable would weaken an index that currently guarantees Group identity — a real cost for a convenience feature.

Therefore the honest answer to "can the existing schema support the distinction safely?" is: **it supports the operational instance completely, and it cannot host a template without a migration.** Since migrations are forbidden here, the template is deferred as **D-8**, not designed around.

If and when a template is approved, the minimum shape is recorded in §21 so the decision is costed rather than discovered later.

---

## 6. Group Capacity Contract

### 6.1 The three quantities

| Term | Definition | Source |
|---|---|---|
| `maximum_orders` | operator-set ceiling; `NULL` = unconstrained | `distribution_virtual_slots.capacity_orders` |
| `current_orders` | eligible Orders whose `virtual_slot_id` = this Group, warehouse-scoped | **one** aggregate — see **D-2** |
| `remaining_capacity` | `maximum_orders − current_orders`, or `NULL` when unconstrained | derived, never stored |

`NULL` must keep meaning **unconstrained**, never zero. The existing read model already honours this (`utilisation` is null, `overflow` is 0 when capacity is null), and the frontend already refuses to send `0` for the same reason.

### 6.2 The rule and its current status

The contract states `current_orders <= maximum_orders`. Today this is **advisory**: computed and displayed, never enforced on any write path (§3.5). Making it enforced is a behaviour change and therefore a decision, not something this task may assume.

### 6.3 Overflow scenarios — recommended policy

Every one of these is a **decision requiring approval** (**D-1**); the recommendation column is a proposal, not a ruling.

| Scenario | Recommended policy | Why |
|---|---|---|
| Add **Order** exceeds capacity | **Reject** with a clear message stating current/maximum | Single-order add is a deliberate act; the operator can choose another Group |
| Add **Zone** exceeds capacity | **Warn and allow** | A Zone carries N orders atomically; rejecting forces the operator to split geography, which the contract forbids automating |
| Move **Order** exceeds destination capacity | **Reject** | Same reasoning as add |
| Move **Zone** exceeds destination capacity | **Warn and allow** | Same reasoning as add-zone; the existing impact dialog already previews the resulting totals |
| Existing Group becomes over capacity because eligibility changed | **Never auto-remove.** Surface as overflow | An order becoming eligible is not an operator action; silently ejecting work would lose it |

Explicitly **not** proposed: automatic splitting, automatic vehicle assignment, silent order removal. All three are forbidden and none is designed here.

The asymmetry (reject orders, warn on zones) is the only defensible reading of two rules that pull against each other: capacity must be respected, and zone membership must stay atomic. If the owner prefers uniform rejection, the zone rows change to "reject the whole zone operation" — but then a Zone larger than any remaining capacity becomes unplaceable, which needs its own answer.

### 6.4 What already supports this

`is_over_capacity`, `overflow_orders`, `utilisation` and `is_warning` already exist in `slotSummaries()`, and `RedistributionSuggestionService::overflows()` already produces over-capacity Groups with candidate orders and candidate destinations, exposed at `GET /windows/{window}/overflows`. **The overflow-resolution surface is already built.** Enforcement, if approved, would reuse these, not replace them.

---

## 7. Loading Preparation Definition

> **Loading Preparation** is the continuously-derived statement of *which products, and how many of each, belong to one Distribution Group's planned departure* — so a warehouse can begin separating them before the Vehicle and Driver are known.

**It is a projection. It is not a record.**

| Property | Loading Preparation | Actual Loading |
|---|---|---|
| Exists before Vehicle/Driver | **yes** | no — `loading_tasks.vehicle_assignment_id` is NOT NULL |
| Storage | none — computed per request | `loading_sessions` + `loading_tasks` rows |
| Mutability | changes automatically with Group membership | changes only through explicit loading actions |
| Consumes inventory | **never** | via pool allocation |
| Meaning | "this is what would need separating" | "this was physically loaded" |

Loading Preparation explicitly does **not** mean dispatched, loaded, handed to driver, finalized, or shipped.

**The boundary:** Loading Preparation ends, and Actual Loading begins, at the moment a **vehicle assignment exists**. That boundary is not a policy choice — it is already enforced by the `loading_tasks.vehicle_assignment_id` foreign key. Approval and Finalize sit between them (§18).

---

## 8. Loading Preparation Source of Truth

### 8.1 Required Quantity — already solved

```
Required(group, product)
  = Σ order_lines.quantity
    WHERE order_lines.order_id ∈ orders of that Group
      AND orders.assigned_warehouse_id = group.warehouse_id
      AND PreparationEligibilityReader::constrainToEligible(orders)
```

This is **exactly** what `productAggregation($windowId, $zoneId, $slotId, $warehouseId)` already computes, and it is already reachable at:

```
GET /api/logistics/distribution/windows/{window}/products?slot_id={group}&warehouse_id={wh}
permission: logistics.distribution.view
returns:    [{ product_id, product_name, product_sku, total_quantity }]
```

No second order-quantity engine is created. `order_lines.quantity` remains the only definition. Eligibility remains `PreparationEligibilityReader`'s (status ∈ configured eligible statuses, minus postponed wave members) — the same reader every other Distribution read model uses.

### 8.2 Prepared and Remaining — **BLOCKED**

The certified semantics are fixed and must be reused verbatim (§3.13):

```
prepared_qty  — operator-owned, declared at PRODUCT level for a WAVE ("Option A")
remaining_qty = max(0, required_qty − prepared_qty)
```

The obstruction is granularity, not availability. `wave_product_demand` is unique on `(preparation_wave_id, product_id)`. A Distribution Group is a **subset** of that wave's orders. When two Groups in the same wave both need product P, there is exactly one `prepared_qty` for P and no rule for dividing it.

Three ways to resolve it, none of which this task may choose:

| Option | Shape | Cost | Honesty |
|---|---|---|---|
| **A. Wave-level context** | Show Required per Group; show Prepared/Remaining **per product for the wave**, labelled as wave-wide | zero — read-only, no migration | Fully honest, but does not answer "is *this Group* ready?" |
| **B. Proportional attribution** | `prepared_group = prepared_wave × (required_group / required_wave)` | zero schema, but **invents an allocation rule** | Produces fractional units and can claim a Group is ready when the floor prepared for a different Group |
| **C. Group-grained prepared** | Preparation records prepared quantity per Group | **Preparation change + migration** | Fully correct; largest cost; requires Preparation to know about Distribution |

**Recommendation: Option A for the first implementable phase.** It ships the operational value (a warehouse can separate a Group's products) without inventing a number. It is the only option that requires neither a migration nor a Preparation change, and it does not foreclose B or C later.

Option B is specifically **not recommended**: it is exactly the "second quantity engine" the contract forbids, dressed as arithmetic.

Recorded as **BLOCKER-1** and **D-4**.

### 8.3 Explicitly not read

Loading Preparation reads **no** inventory table: not `inventory_summary`, not `stock_ledger_entries`, not `prepared_products_pool`. It reads order lines, Group membership, and — for context only under Option A — `wave_product_demand`.

---

## 9. Live Synchronization Rules

### 9.1 The mechanism: don't store it

The task asks how to prevent "Group says 30 orders while Loading Preparation shows products for 32." The strongest answer is not a better invalidation strategy — it is **not having a second copy to invalidate**.

Because `productAggregation` is a live query over `distribution_window_orders` at request time, a Loading Preparation panel and a Group header rendered from the same fetch cycle are computed from the same rows. **Divergence is structurally impossible, not merely defended against.**

This directly satisfies "prefer existing canonical read models" and "do not introduce polling."

### 9.2 Frontend contract

Loading Preparation joins the existing root:

```ts
KEYS.all = ['logistics-distribution-workspace']
KEYS.loadingPreparation = (windowId, warehouseId, slotId) => [...KEYS.all, 'loading-preparation', windowId, warehouseId, slotId]
```

Every one of the 7 existing mutations already calls `invalidateQueries({ queryKey: KEYS.all })`, so **all seven automatically refresh Loading Preparation with no change to any mutation**. The warehouse id must be in the key, exactly as it is for the other three queries.

### 9.3 The ten events, evaluated

Per the instruction not to assume every event needs a new event type:

| # | Change | Covered today? | Mechanism |
|---|---|---|---|
| 1 | Order added to Group | **yes** | `changeOrderSlot` → invalidate root |
| 2 | Order removed from Group | **yes** | `changeOrderSlot` → invalidate root |
| 3 | Order moved between Groups | **yes** | `changeOrderSlot` → invalidate root |
| 4 | Zone added to Group | **yes** | `assignZoneToSlot` → invalidate root |
| 5 | Zone removed from Group | **yes** | `detachZone` → invalidate root |
| 6 | Zone moved between Groups | **yes** | `moveZone` → invalidate root |
| 7 | Order becomes ineligible | **partial** | Correct on next fetch (`constrainToEligible` re-evaluates). **No push** — an open screen shows it only after some other action or a manual refresh |
| 8 | Order eligible again | **partial** | Same as 7 |
| 9 | Order quantity changes | **partial** | Same as 7 — `order_lines` is read live, so the next fetch is correct |
| 10 | Preparation state changes | **partial** | Same as 7 |

**Cases 1–6 need nothing.** Cases 7–10 are all the same case: a change originating **outside** the Distribution workspace. They are always *eventually* correct because every quantity is computed live; what is missing is a push signal to a screen already open.

Recommended handling: **accept next-fetch correctness for the first phase.** The existing `Refresh pool` action already forces a full re-derivation, and the operator's normal rhythm involves it. Introducing a push channel (websocket or domain-event fanout) for cases 7–10 is a separate, larger decision — recorded as **D-5**, not designed here.

Note the correctness asymmetry worth stating plainly: because Loading Preparation is live-computed, cases 7–10 produce a **stale view**, never **stale data**. That distinction is what makes deferring the push channel safe.

---

## 10. Group Change Matrix

Behaviour below is the **current, audited** behaviour, plus the proposed Loading Preparation consequence. "Physically moved" means rows written.

| Operation | Group totals | Loading Preparation | Products affected | Data physically moved | Atomic |
|---|---|---|---|---|---|
| **ADD ZONE** | +zone; +all that zone's eligible orders for this warehouse | recomputed; that zone's products appear/increase | union of the zone's orders' `order_lines` | 1 row inserted in `distribution_slot_zones`; **orders are not rewritten** — membership is derived via zone→slot mapping at collect time | yes — single insert; guarded so a zone with another warehouse's work but none of yours is rejected |
| **REMOVE ZONE** | −zone; its orders leave the Group | those products decrease or disappear | same set, subtracted | 1 row deleted, **scoped to this warehouse's link only** (the Part 5C fix) | yes |
| **MOVE ZONE** | source −, destination + | both Groups recomputed | moves wholesale between the two | delete + insert in one transaction; **cross-warehouse moves rejected** | yes |
| **ADD ORDER** | +1 order | that order's lines added | its `order_lines` | `distribution_window_orders.virtual_slot_id` updated | yes |
| **REMOVE ORDER** | −1 order | its lines removed | its `order_lines` | `virtual_slot_id` set to `NULL` — order stays in the Window, ungrouped | yes |
| **MOVE ORDER** | source −1, destination +1 | both recomputed | its `order_lines` | one `virtual_slot_id` update; `DistributionAssignmentChanged` dispatched (**no listeners**) | yes — single row update |
| **ORDER BECOMES INELIGIBLE** | −1 automatically | its lines drop out automatically | its `order_lines` | **nothing written.** `constrainToEligible` simply stops matching it | n/a — no write |
| **ORDER BECOMES ELIGIBLE AGAIN** | +1 automatically | its lines return automatically | its `order_lines` | **nothing written** | n/a |

Two consequences worth naming:

- Eligibility changes require **no Distribution write at all**. The Group's contents are a filtered view, so eligibility is honoured the instant it changes in the source. This is why cases 7–10 in §9.3 cannot corrupt data.
- Removing an Order sets `virtual_slot_id = NULL` rather than deleting the row, so the Order remains visible as ungrouped work. Nothing is silently lost.

---

## 11. Warehouse Boundary

Preserved exactly as certified in Parts 5A/5B.

```
Warehouse A → Zone "Maadi" → Group A   ┐ both valid, simultaneously
Warehouse B → Zone "Maadi" → Group B   ┘
```

- The **Zone** is geography: `distribution_zones` has no warehouse column and must not gain one.
- The **Group** owns the warehouse: `distribution_virtual_slots.warehouse_id` NOT NULL.
- The **link** is warehouse-scoped: `unique(window, warehouse, zone)`.
- Every read scopes by `orders.assigned_warehouse_id` via `scopeWarehouse()`, and the Group list itself is filtered by **ownership**, not merely by the orders it reports — the Part 5A→5B correction.

**Loading Preparation must be scoped by both `slot_id` and `warehouse_id`.** `productAggregation` already accepts both. Passing `slot_id` alone would be safe only by accident (a Group belongs to one warehouse), and relying on that would re-create the class of defect Part 5B closed. The recommendation is to always pass both, so the guarantee is explicit rather than incidental.

---

## 12. No Warehouse Behavior

**Confirmed defect against this contract.**

An Order with `assigned_warehouse_id IS NULL`:

1. **is** collected into the Distribution Window (`DistributionCollectionService` does not filter on warehouse);
2. **can never be auto-grouped** — `$slotFor()` returns `null` when the warehouse is null, so `virtual_slot_id` stays `NULL`;
3. **is invisible under every warehouse filter** — `scopeWarehouse()` uses `where(assigned_warehouse_id, $id)`, and `NULL = <uuid>` is never true.

Net effect: the order is visible **only** when no warehouse is selected. Since Part 5A the workspace is warehouse-scoped in normal operation, so in the operator's normal working mode these orders are **silently hidden** — which the contract explicitly forbids.

They can never enter Loading Preparation, because Loading Preparation is warehouse+group scoped by construction.

This is **DECISION D-6**, and it is a pre-existing defect this phase surfaces rather than one it introduces. Options:

- **A.** A permanent "No Warehouse" bucket alongside "Unassigned", always rendered, showing count and the reason. Read-only. *(recommended — mirrors the existing always-visible Unassigned tab, which exists for exactly this reason)*
- **B.** Block collection of NULL-warehouse orders. Rejected: hides the problem earlier rather than fixing it, and loses the order entirely.
- **C.** Infer a warehouse. Rejected outright — inventing ownership is precisely what Part 5B's migration refused to do.

Under all options such orders **cannot** enter Loading Preparation until a warehouse is assigned. Loading Preparation is a warehouse activity; an order with no warehouse has no floor to be separated on.

---

## 13. Distribution Window Boundary

| Concern | Owner |
|---|---|
| Which Window an Order joins (before/after cutoff) | `DistributionWindowService` |
| Window lifecycle (`scheduled → open → cutoff_reached → closed`) | `DistributionWindowService` |
| Which Group an Order lands in | `DistributionCollectionService` (auto) / `ManualAssignmentService` (manual) |
| Group composition | `ManualAssignmentService` |
| Group rollups and product aggregation | `DistributionAggregationService` |
| Loading Preparation | **new read-only consumer of `DistributionAggregationService`** |

The Window is **company + date**; the Group is **warehouse-owned within it**. Loading Preparation never queries the Window directly — it always goes through a Group, which is what guarantees warehouse scoping.

---

## 14. Carry-over Findings

**Audit result: window carry-over does not exist.**

- `DistributionWindowStatus::Closed` is defined — and **nothing in the codebase ever transitions a Window into it.** Grep across the module finds only the enum declaration and its label.
- `applyCutoffIfDue()` moves a Window to `CutoffReached` only, with the explicit comment: *"Deliberately does NOT close the Window: cutoff stops automatic ingestion."*
- `nextWindowAfter()` links `next_window_id`, but **nothing traverses that link to migrate anything.**
- `distribution_window_orders.unique(order_id)` is global, so an Order cannot be in two Windows. Carry-over can therefore never be implemented by copying rows — it must **move** the single row.
- The only cross-window movement that exists is `assignLateOrder()`, a **manual, per-order** operation that stamps `previous_window_id` for audit.

So, answering the required questions honestly:

| Question | Current answer |
|---|---|
| What happens when a Window closes? | **A Window never closes.** It stops at `cutoff_reached` |
| What happens to its Groups? | Nothing. They persist against a `cutoff_reached` Window indefinitely |
| What happens to Loading Preparation? | It keeps returning that Window's Groups' requirements forever |
| What happens to eligible orders? | They stay bound to the old Window unless an operator manually moves each one |
| What happens to prepared products? | Untouched — `prepared_products_pool` is keyed by wave, not window, and has no window lifecycle at all |

**BLOCKER-2.** This is not a Loading Preparation defect, but Loading Preparation makes its consequence concrete and visible: a Group from three days ago will still report products that need separating. Any implementation phase that ships Loading Preparation without a window-close policy ships that confusion to the warehouse floor.

Not designed here, per the instruction not to invent carry-over behaviour.

---

## 15. Preparation Boundary

**Preparation is not modified. This phase consumes it.**

| Question | Owner |
|---|---|
| "Is this product prepared and ready?" | **Preparation** |
| "How much of product P does this wave require / has the floor prepared?" | **Preparation** (`wave_product_demand`) |
| "Which prepared/required products belong to *this Distribution Group*?" | **Loading Preparation** |
| "Is this Order eligible for distribution?" | **Preparation**, read through `PreparationEligibilityReader` |

Loading Preparation reads: `order_lines`, `distribution_window_orders`, `distribution_virtual_slots`, `distribution_slot_zones`, and (Option A) `wave_product_demand` for wave-level context. It writes **nothing**.

**Preparation capability that would be needed for Option C**, stated as the task requires:

1. **Capability needed:** prepared quantity attributable to a subset of a wave's orders (a Distribution Group).
2. **Why the current contract cannot provide it:** `wave_product_demand` is `unique(preparation_wave_id, product_id)`, and the code documents `prepared_qty` as deliberately product-level and operator-owned ("Option A"). No order-grained prepared quantity exists in any Preparation table.
3. **Minimum change:** a new grain for prepared quantity — either per (wave, product, group) or per (wave, product, order) — plus a floor UI to declare it at that grain, plus a rule for what happens to already-declared quantities when Group composition changes.

**This is not implemented, not designed in detail, and not recommended for the first phase.** It would make Preparation aware of Distribution, inverting the current dependency direction. Recorded as **D-4 / BLOCKER-1**.

---

## 16. Inventory Boundary

**Loading Preparation touches no inventory whatsoever.**

- It does **not** consume inventory.
- It does **not** reserve stock. (Reservation is ADR-027 territory: Orders reserve FG only; Manufacturing owns all RM decisions. Nothing here changes that.)
- It does **not** manufacture.
- It does **not** read `inventory_summary`, `stock_ledger_entries`, or `stock_movements`.
- It does **not** read `prepared_products_pool` — that table's `quantity_reserved` / `quantity_loaded` belong to Actual Loading's allocation flow.
- It does **not** change Order status. No certified contract requires it to.

`DistributionAggregationService::productAggregation`'s docblock already states the rule this phase inherits: *"Quantities only. No inventory is read, reserved, moved or consumed here."*

---

## 17. Vehicle / Driver Boundary

**Not implemented. Not designed. Not referenced.**

The Group remains free of any vehicle or driver identity — no `vehicle_id`, no `driver_id`, no assignment timestamps on `distribution_virtual_slots`. This is what keeps the Group re-plannable right up until assignment.

What the future assignment phase will consume **from** the Group (read-only, all available today):

| Field | Source | Available now |
|---|---|---|
| `group_id`, `code`, `name` | `distribution_virtual_slots` | yes |
| `warehouse_id` | `distribution_virtual_slots` | yes |
| `zone_ids`, `zone_names` | `slotSummaries()` | yes |
| `orders_count` | `slotSummaries()` | yes |
| `maximum_orders`, `remaining_capacity` | `slotSummaries()` | yes |
| product requirement list | `productAggregation(slot_id)` | yes |
| total order value, paid/unpaid split | `slotSummaries()` | yes |

The Groups panel already renders inert `Vehicle: Not assigned` / `Driver: Not assigned` rows — present so the operator can see the plan is incomplete, inert because assignment is a later phase. **That is the correct treatment and should not change in this phase.**

---

## 18. Approval / Finalize Boundary

Neither exists in Distribution today. No approval state, no finalize action, no `approved_at` / `finalized_at` on any Distribution table. `slotSummaries()` reports `'status' => 'draft'` as a **literal** — the code comments that a Group "has exactly one state today," reported as a literal deliberately to avoid inventing a status column or a second state machine.

The intended boundary:

```
Loading Preparation  — mutable, live, no vehicle
        ↓ Vehicle assigned
        ↓ Driver assigned
   APPROVAL           — the plan is agreed
        ↓
   FINALIZE           — composition frozen; Loading Preparation stops changing
        ↓
 ACTUAL LOADING       — loading_sessions + loading_tasks (vehicle-anchored)
```

**Finalize is the moment the projection stops being live.** Up to Finalize, Loading Preparation must reflect Group composition continuously (§9). After Finalize it must stop, or the floor's separated stock would silently disagree with a still-moving plan.

Implementing Finalize requires a Group state machine, which requires a status column, which requires a migration. Recorded as **D-7**. Not designed here.

---

## 19. API Requirements

**Preferred outcome, and the audited one: no new endpoint is required for the recommended Option A phase.**

Already exists and is sufficient for Required:

```
GET /api/logistics/distribution/windows/{window}/products
    ?slot_id={group_id}&warehouse_id={warehouse_id}
permission: logistics.distribution.view
returns:    [{ product_id, product_name, product_sku, total_quantity }]
```

Gap: it does not return `prepared` / `remaining`, because those do not exist per Group (§8.2).

**If — and only if — Option A is approved**, the minimum change is an *additive* extension of the existing endpoint, documented here for approval and **not implemented**:

| Field | Purpose | Source |
|---|---|---|
| `wave_required_qty` | wave-wide Required for this product | `wave_product_demand.required_qty` |
| `wave_prepared_qty` | wave-wide Prepared (operator-declared) | `wave_product_demand.prepared_qty` |
| `wave_remaining_qty` | `max(0, required − prepared)` | derived at read time |
| `preparation_completed_at` | whether the floor declared this product done | `wave_product_demand.preparation_completed_at` |

- **Purpose:** give the operator prepared context without attributing it to a Group.
- **Request:** unchanged.
- **Response:** four additive fields; every existing consumer unaffected.
- **Permission:** `logistics.distribution.view` — unchanged.
- **Idempotency:** GET, naturally idempotent.
- **Side effects:** none — read-only.
- **Naming:** the `wave_` prefix is deliberate and load-bearing. It is what stops a Group-scoped screen from being read as a Group-scoped number.

**STOP — this requires approval before implementation.**

---

## 20. Permission Requirements

**No new permission is required.**

| Action | Existing permission |
|---|---|
| View Loading Preparation for a Group | `logistics.distribution.view` |
| Create a Group | `logistics.distribution.create` |
| Add/remove/move Zone or Order | `logistics.distribution.update` |
| Set `maximum_orders` | `logistics.distribution.update` |

Loading Preparation is a read projection over data the viewer can already see; anyone who can view a Group can already see its orders and their products. Introducing a separate permission would be a new access boundary with no new data behind it.

Later phases already have their own permissions and are out of scope here: `loading.vehicle.assign`, `loading.driver.operate`, `loading.session.create|operate|dispatch`, `loading.allocation.manage|override`.

If Finalize is approved (**D-7**), it needs a mapping decision: `logistics.distribution.update` is probably too weak for an irreversible freeze. Flagged, not decided.

---

## 21. Migration Requirements

**No migration is required for the recommended phase, and none is created here.**

Everything Loading Preparation reads already exists: `distribution_virtual_slots.capacity_orders`, `distribution_virtual_slots.warehouse_id`, `distribution_slot_zones`, `distribution_window_orders.virtual_slot_id`, `order_lines.quantity`, `wave_product_demand`.

Migrations would be required only by decisions that are **not** approved. Each is costed so the decision is informed, and **none is written**:

| Trigger | Capability | Why schema can't support it | Minimum change | Data impact | Backfill | Rollback |
|---|---|---|---|---|---|---|
| **D-7** Finalize | freeze a Group | no status column; `'draft'` is a hard-coded literal | `status` + `finalized_at` on `distribution_virtual_slots` | additive | all existing → `'draft'` | drop columns; literal resumes |
| **D-8** Template | reusable Group config | every row is bound to a `distribution_window_id` under `unique(window, code)` | new `distribution_group_templates` table | additive, new table | none — starts empty | drop table |
| **D-4/C** Group-grained prepared | prepared qty per Group | `wave_product_demand` is `unique(wave, product)` | new grain table + Preparation write path | **Preparation change** | ambiguous — no rule exists for splitting existing wave totals | complex; the backfill ambiguity is itself an argument against |
| **D-3** Remove forbidden capacities | drop `capacity_stops`/`weight`/`volume` | columns exist and are emitted | drop 3 columns + payload fields | destructive | n/a — all NULL | re-add nullable |

**STOP.** Any of these requires explicit approval before a migration is authored.

---

## 22. Open Decisions

| # | Decision | Recommendation | Blocks implementation? |
|---|---|---|---|
| **D-1** | Overflow policy: reject vs warn, per operation (§6.3) | Reject Order add/move; warn on Zone add/move; never auto-remove | **Yes** — capacity is meaningless until decided |
| **D-2** | Which aggregate is canonical `current_orders`: `demand_orders` or `orders_count` (§3.6) | Collapse to one query; keep `orders_count` (it also produces value/products/paid) and derive capacity from it | **Yes** — two numbers cannot both be the rule |
| **D-3** | The three forbidden capacity dimensions exist in schema and are emitted in the payload (§3.1) | Stop emitting them and stop accepting them in validation; defer column removal | No — but they contradict the contract while present |
| **D-4** | Prepared/Remaining attribution: wave-level context (A), proportional split (B), or group-grained (C) (§8.2) | **A** — the only option needing neither migration nor Preparation change, and the only one that never states a number it cannot justify | **Yes** — this is BLOCKER-1 |
| **D-5** | Push channel for externally-originated changes (cases 7–10, §9.3) | Defer; next-fetch correctness is sufficient because data is never stale, only the view | No |
| **D-6** | "No Warehouse" orders are silently hidden in normal operation (§12) | Permanent, always-visible bucket; excluded from Loading Preparation | No — but it violates a stated contract rule today |
| **D-7** | Finalize / Group state machine (§18) | Defer to the Approval phase; requires a migration | No for this phase; **yes** before Actual Loading |
| **D-8** | Group template / reusable configuration (§5) | Defer — convenience, not correctness; requires a new table | No |
| **D-9** | `vehicle_plan_*` residue of the removed phase (§3.9) | Leave in place (0 rows, harmless); do not build on it; schedule removal separately | No |
| **D-10** | Whether Loading Preparation is Group-only or also viewable per Zone | Group-only first — the Group is the departure unit; Zone-level would need its own justification | No |

---

## 23. Blockers

**BLOCKER-1 — Prepared quantity cannot be attributed to a Distribution Group.**
`wave_product_demand` is `unique(preparation_wave_id, product_id)` and the code documents `prepared_qty` as deliberately product-level and operator-owned. Two Groups needing the same product share one prepared number with no division rule. Any per-Group "Prepared" figure produced today would be invented. **Blocks the Prepared/Remaining columns only; Required is unblocked and shippable.** Resolve via **D-4**.

**BLOCKER-2 — Distribution Windows never close, and nothing carries over.**
`DistributionWindowStatus::Closed` exists but no code path reaches it; `applyCutoffIfDue` deliberately stops at `cutoff_reached`. Groups and their orders persist against stale Windows indefinitely, and `unique(order_id)` means carry-over must move rows rather than copy them. Loading Preparation will faithfully report requirements for Groups that are days old. **Does not block a first phase, but must be answered before this reaches a warehouse floor.**

**BLOCKER-3 (contract conflict, low severity) — forbidden capacity dimensions are live in the API.**
`capacity_stops`, `capacity_weight_kg` and `capacity_volume_m3` are accepted by `POST /windows/{window}/slots` validation and returned by `slotSummaries()`, contradicting "do not introduce weight/volume/cubic capacity." They are always NULL, so the conflict is presentational rather than behavioural. Resolve via **D-3**.

---

## 24. Recommended Implementation Phases

Each phase is independently shippable and independently certifiable. **None is authorized by this document.**

**Phase LP-1 — Loading Preparation (Required only).** Consume the existing `/products?slot_id=&warehouse_id=` endpoint in a Group panel: product, SKU, required quantity. Join the existing React Query root. *No migration, no new endpoint, no new permission, no backend change at all.* Delivers the stated operational goal — a warehouse can begin separating a Group's products before vehicle and driver are known.

**Phase LP-2 — Prepared context.** Only after **D-4**. Under Option A, extend the products endpoint with the four `wave_*` fields (§19). Requires API approval.

**Phase LP-3 — Capacity enforcement.** Only after **D-1** and **D-2**. Add `maximum_orders` to the Group create/edit UI, then enforce in `ManualAssignmentService`. Reuses the existing overflow read model and `RedistributionSuggestionService`. No migration.

**Phase LP-4 — No Warehouse bucket.** Only after **D-6**. Frontend-only; the data is already collected.

**Phase LP-5 — Window close and carry-over.** Only after **BLOCKER-2** is decided. Larger than the rest combined; must not be bundled with LP-1.

**Phase LP-6 — Finalize / Approval.** Only after **D-7**. Requires a migration. Gates the handoff to the existing Actual Loading module.

The ordering matters in one specific way: **LP-1 must not wait on LP-2.** The Required projection is complete, correct, already exposed, and blocked on nothing.

---

## 25. Testing Strategy

**No test was written or executed for this task, and none should be** — it is architecture-only. Static inspection of schema, services, routes and hooks was sufficient for every finding above; each claim in this report cites the file or index it came from.

When implementation is authorized, the narrow suites required — **listed, not implemented, per the testing policy**:

| Phase | Test | Proves |
|---|---|---|
| LP-1 | Group product projection returns only that Group's orders' lines | no cross-Group leakage |
| LP-1 | Two warehouses planning the same Zone get disjoint product lists | the Part 5B boundary holds in the new read |
| LP-1 | An ineligible order's lines disappear without any Distribution write | eligibility is live, not cached |
| LP-3 | Adding an Order beyond `maximum_orders` is rejected | D-1, once decided |
| LP-3 | `capacity_orders = NULL` never behaves as zero | unconstrained stays unconstrained |
| LP-4 | A NULL-warehouse order is visible in its bucket and absent from every Group | D-6 |

Constraints that must hold for all of them: no fabricated business data, no fixture that mutates live business data, no full-ERP or full-Distribution regression, and each suite scoped to the phase that introduced it.

One architectural assumption in this report is worth proving with a test **before** LP-1 is certified rather than after: that `slotOrderCounts()` and `slotRollup()` never disagree for the same Group (**D-2**). The audit shows one uses an inner join and the other a left join; both are then constrained by `constrainToEligible`, which should make them equivalent — but "should" is reasoning, not evidence, and the capacity rule will rest on whichever one is chosen.

---

## Final Position

Nothing was implemented. No migration, no schema change, no API change, no frontend change, no Preparation change, no Distribution change, no business data touched, no commit.

The most useful outcome of this audit is that **the first implementable phase is far smaller than the task anticipated** — the Required projection already exists end-to-end and needs only a consumer — while **the part that looks small is genuinely blocked**: per-Group prepared quantity cannot be produced from the certified Preparation contract without a decision that only the owner can make.

**Awaiting decisions D-1 through D-10, and rulings on BLOCKER-1 and BLOCKER-2, before any implementation.**

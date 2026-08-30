# D-P5-1 — WAREHOUSE-OWNED DISTRIBUTION CYCLE
## Audit Report — design proposed, **awaiting approval. Nothing implemented.**

**Date:** 2026-08-21 · **Branch:** `develop` · **Type:** AUDIT ONLY
**Approved business rule:** each Warehouse has its own Preparation Wave, and that Warehouse's Distribution follows the same Wave.

---

# VERDICT

| | |
|---|---|
| Can the **cycle** be resolved per warehouse with existing contracts? | **YES — a canonical resolver already exists and is better than what Part 4 uses.** |
| Can the **Distribution Window row** be linked to a warehouse without a migration? | **NO.** |
| Is a migration needed to satisfy the approved rule? | **No — provided the Window stays a company-day container and the *view* is warehouse-scoped.** |
| Is any change needed at all? | **Yes: additive optional `warehouse_id` query parameters on 5 read endpoints.** That is an API change, so per your instruction: **STOP + REPORT, minimum change specified below.** |

**Two consequences of the approved rule need your decision before anything is built** — see §7. One of them (Distribution Group scoping) is structural.

---

# 1. HOW IS THE WAREHOUSE DETERMINED FOR A DISTRIBUTION ORDER?

**`orders.assigned_warehouse_id`** — and it is already the same column Preparation uses.

`WaveMembershipService::attachEligibleOrders()` collects with:

```php
Order::where('company_id', $wave->company_id)
    ->where('assigned_warehouse_id', $wave->warehouse_id)   // ← the link
    ->whereIn('status', $config->eligible_order_statuses)
```

So Preparation already decides "which orders belong to this warehouse's wave" by exactly this column. If Distribution scopes by the same column, **the two modules agree by construction** rather than by coincidence.

**Live data:** all 4 currently-eligible orders carry `assigned_warehouse_id` = Main Warehouse. 3 of 9 orders overall carry **NULL** — all of them currently ineligible (`awaiting_payment`, `awaiting_stock`). See §7.2: a NULL is not impossible for an eligible order.

Distribution's read model **already exposes** this: `orders()` selects `o.assigned_warehouse_id` and `w.name as warehouse_name`, and already accepts a `warehouse_id` filter.

---

# 2. DOES THE DISTRIBUTION WINDOW KNOW THE WAREHOUSE?

**Directly: NO.**

```
distribution_windows:
  id, company_id, window_date, opens_at, closes_at,
  status, next_window_id, cutoff_reached_at, created_at, updated_at
```

No `warehouse_id`. A Window is a **company + date** container, and `windowFor()` resolves it by `(company_id, window_date)` alone.

**Indirectly: YES — through its orders.** Every `distribution_window_orders` row points at an Order that carries `assigned_warehouse_id`. One Window may therefore hold orders from several warehouses at once.

The same is true one level down: **`distribution_virtual_slots` (the Distribution Group) has no warehouse either** — it hangs off the Window. That is the structural consequence in §7.1.

---

# 3. CAN WINDOW ↔ WAREHOUSE BE LINKED WITH EXISTING CONTRACTS, NO MIGRATION?

The question splits in two, and the answers differ.

### 3a. Linking the Window ROW to a warehouse — **NO**

It would require `distribution_windows.warehouse_id` plus a change to how a window is identified (today one row per company per date; per-warehouse windows means one row per company per warehouse per date, changing `windowFor()` and the row's identity). **That is a migration and a contract change. Not proposed.**

### 3b. Making Distribution warehouse-scoped WITHOUT touching the Window — **YES**

The approved rule says *"Distribution for each Warehouse follows that Warehouse's Preparation Wave."* It does **not** require the Window row itself to be per-warehouse. The same outcome is reached by scoping the **cycle** and the **view**:

```
OrganizationContext.activeWarehouseId          ← EXISTS (frontend, persisted)
        │  warehouse_id
        ▼
DistributionWindowController
        │
        ├─► WaveManager::getActiveWave(company, warehouse, date)   ← EXISTS (Preparation contract)
        │        └─► that warehouse's cycle: start · cutoff · end · timezone
        │
        └─► Window (company + date, UNCHANGED)
                 └─► orders WHERE assigned_warehouse_id = :warehouse
                          └─► zones · groups · totals, all warehouse-scoped
```

Every element on that path already exists. Nothing is invented.

---

# 4. HOW IS THE CORRECT WAVE FOR A WAREHOUSE DETERMINED?

**`WaveManager` is the existing canonical contract**, and it is exactly what Part 4 should have used:

```php
WaveManager::getActiveWave(string $companyId, string $warehouseId, ?string $operationalDate = null): ?PreparationWave
WaveManager::getActiveWaveForDate(string $companyId, string $warehouseId, string $date): ?PreparationWave
WaveManager::getCollectingWave(string $companyId, string $warehouseId): ?PreparationWave
WaveManager::hasActiveWave(...): bool
WaveManager::openWaves(...): Collection
```

It is a plain class with **no constructor dependencies**, so it resolves trivially from the container. Reading it is *consuming the Preparation contract* — strictly better than Part 4, which duplicated a query against `preparation_waves`.

## Two ways Part 4's selection is wrong

| | Part 4 (current, deployed) | `WaveManager` (canonical) |
|---|---|---|
| Scope | **company-wide** — no warehouse filter | `company + warehouse` |
| "Active" means | `WaveStatus::activeValues()` = Draft, Collecting, Planning, ShortageBlocked, Preparing (**5**) | `ACTIVE_STATUSES` = **Collecting, Preparing** (**2**) |
| Tie-break | `orderByDesc('starts_at')` | `orderByDesc('planning_date')`, with the reason documented |
| Operational date | ignored | `?operationalDate` — its docblock: *"what makes this the **current** wave rather than merely **an** active one"* |

So Part 4 could select a Draft wave, from another warehouse, on a stale date. In the current single-warehouse data it happens to pick the right row.

---

# 5. CAN MORE THAN ONE ACTIVE WAVE EXIST FOR THE SAME WAREHOUSE?

**Yes — structurally possible, treated by the contract as a recoverable anomaly rather than an invariant.**

Evidence:

1. **No database guarantee.** The only unique index on `preparation_waves` is `uq_preparation_waves_company_wave_number` on `(company_id, wave_number)`. Nothing constrains `(company, warehouse, status)`.
2. **No application guard at creation.** `CreateWaveAction` writes `warehouse_id` and performs no "is one already open?" check.
3. **The contract says so explicitly.** `WaveManager::openWaves()`:

   > *"Every ENGINE wave still open for this warehouse, OF ANY DATE… Oldest first: **if more than one is somehow open**, the eldest is reconciled first, so the survivor is the most recent cycle rather than an arbitrary one."*

4. **But one is the intent.** `getCollectingWave()` returns `->first()` with no ordering — meaningful only if at most one Collecting wave per warehouse is expected. `hasActiveWave()` exists to stop the scheduler opening a second.
5. **`wave_type` matters.** `openWaves()` is scoped to `wave_type = 'engine'` because *"a manually built wave left in Preparing would read as 'a wave is already open' and block the operational cycle from ever opening again."* So a **manual** wave and an **engine** wave can legitimately be active for one warehouse simultaneously.

**Consequence:** even warehouse-scoped, "the current wave" is resolved by *policy*, not by uniqueness. The policy already exists — `getActiveWave(company, warehouse, operationalDate)` — and it is deterministic. That is enough to stop guessing, which is what D-P5-1 asked for.

---

# 6. WHICH EXISTING CONTRACT DEFINES THIS?

| Contract | Defines |
|---|---|
| `WaveManager` (+ `ACTIVE_STATUSES`, `planning_date`, `wave_type='engine'`) | which wave is current **for a warehouse** |
| `WaveMembershipService::attachEligibleOrders` | which orders belong to a warehouse's wave — via `orders.assigned_warehouse_id` |
| `WaveStatus::isActive()` / `activeValues()` | the broad "not terminal" set (**not** the scheduling set) |
| `PreparationSessionPolicy` / `OrderStatus::fulfilmentEligible()` (ADR-042) | status eligibility |
| `preparation_wave_orders.released_at` / `postponed_at` | cycle membership — consumed in Part 5 |
| `OrganizationContext.activeWarehouseId` (`ecos:activeWarehouseId`) | the operator's current warehouse, frontend |

---

# 7. TWO CONSEQUENCES THAT NEED YOUR DECISION

## 7.1 Distribution Groups would span warehouses — **structural**

A Distribution Group is a `distribution_virtual_slots` row hanging off the **Window**, which is company-wide. It has **no warehouse column**, and Zones are pure geography (`distribution_zones` has no company or warehouse either).

With two warehouses sharing one Window, both planners see the same Groups, and a Group could hold zones whose orders come from different warehouses — contradicting *"Distribution for each Warehouse is independent."*

Options:

- **(a)** Groups stay window-scoped and are simply *filtered* by the active warehouse in the view. Cheapest, no schema — but two planners could still create conflicting groups over the same zone, and the zone↔group unique index is per-window, so the second one silently steals the zone.
- **(b)** `distribution_virtual_slots.warehouse_id` — **migration**; makes the rule true in the data.
- **(c)** Per-warehouse Windows (§3a) — **migration**, largest change, most faithful to "independent per warehouse".

**This cannot be resolved by convention.** I am not choosing.

## 7.2 Orders with no warehouse would become invisible

An eligible order with `assigned_warehouse_id = NULL` belongs to no warehouse cycle. Preparation already skips it (its collector requires the column to match), and a warehouse-scoped Distribution would too — so it would be invisible in **both** modules.

Today 3 of 9 orders are NULL, all currently ineligible, so nothing is hidden right now. Options: **(a)** an explicit "No warehouse" bucket beside the zone tabs, same treatment as Unassigned; **(b)** accept invisibility and rely on Orders to surface it.

I recommend **(a)** — it is the same principle already applied to Unassigned: never hide an order, state why.

---

# 8. PROPOSED DESIGN (not implemented)

## 8.1 Backend — minimum change

**One new read path, no schema:**

1. `DistributionAggregationService::governingPreparationWave()` — replace its private query with a call to `WaveManager::getActiveWave($companyId, $warehouseId, $operationalDate)`. This *removes* duplicated logic rather than adding any.
2. Thread an **optional** `warehouse_id` through the aggregates: `zoneSummaries`, `slotSummaries`/`slotRollup`/`slotOrderCounts`, `productAggregation`. `orders()` already supports it.
3. Accept an **optional** `warehouse_id` query parameter on 5 endpoints:

| Endpoint | Today | Change |
|---|---|---|
| `GET /windows/current` | — | **+ optional `warehouse_id`** |
| `GET /windows/{w}/zones` | — | **+ optional** |
| `GET /windows/{w}/slots` | — | **+ optional** |
| `GET /windows/{w}/products` | — | **+ optional** |
| `GET /windows/{w}/overflows` | — | **+ optional** |
| `GET /windows/{w}/orders` | already accepts it | none |

**Additive and non-breaking:** omitting the parameter reproduces today's behaviour byte for byte, so every existing test and caller is unaffected.

**Not proposed:** any change to `windowFor()`, to `distribution_windows`, to collection, or to Preparation.

## 8.2 Frontend

Consume the existing `OrganizationContext.activeWarehouseId` and pass it. The header warehouse switcher already sets it and persists it; **no new selector is needed** (which also satisfies §2 of Part 5: do not duplicate a cycle selector).

The cycle header then reads: *"Warehouse: Main Warehouse — PREP-202608-000003 · 20:30 / 08:00 / 15:00 · Africa/Cairo."*

## 8.3 What this fixes

- Removes the `orderByDesc('starts_at')` guess.
- Corrects "active" from 5 statuses to the scheduler's 2.
- Adds the operational-date scoping that distinguishes *the current* wave from *an* active one.
- Makes Preparation and Distribution agree on warehouse by using the same column.

---

# 9. STOP — WHAT I NEED FROM YOU

**Nothing was implemented.** No file was modified, no migration written, no schema or contract changed.

| # | Decision |
|---|---|
| **1** | Approve §8's design — additive optional `warehouse_id` on 5 read endpoints + `WaveManager` as the wave authority. **This is an API change**, which is why I stopped. |
| **2** | §7.1 — Distribution Group scoping: **(a)** filter only, **(b)** `warehouse_id` on the group (migration), or **(c)** per-warehouse Windows (migration). |
| **3** | §7.2 — orders with no warehouse: explicit bucket, or accept invisibility. |
| **4** | Confirm `WaveManager::ACTIVE_STATUSES` (Collecting + Preparing) is the intended definition of "the Distribution cycle" — it is narrower than `WaveStatus::activeValues()`, and a wave in `shortage_blocked` would therefore **not** be a current cycle. |

Question 4 matters operationally: a warehouse whose wave is `shortage_blocked` would show **no distribution cycle at all** under the canonical definition.

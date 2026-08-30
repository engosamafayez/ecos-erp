# D-P5-1 — DECISION RECORD (APPROVED)
## Warehouse-Owned Distribution Cycle

**Status:** **APPROVED — recorded. Nothing implemented.**
**Date:** 2026-08-21 · **Branch:** `develop` · **Not committed**
**Audit that produced these decisions:** `TASK-OPERATIONS-DISTRIBUTOR-ORDERS-D-P5-1-WAREHOUSE-CYCLE-AUDIT.md`

---

# 1. THE APPROVED RULE

> **Distribution is Warehouse-scoped.** Each Warehouse follows its own Preparation Wave.

```
Order.assigned_warehouse_id
        ↓
Preparation Wave (that warehouse's)
        ↓
Distribution
```

## 1.1 The canonical cycle resolver

```php
WaveManager::getActiveWave(
    string $companyId,
    string $warehouseId,
    ?string $operationalDate,
): ?PreparationWave
```

**The current-wave contract is defined by:**

| Dimension | Value |
|---|---|
| `company_id` | the acting tenant |
| `warehouse_id` | **the scoping dimension** |
| `planning_date` | the operational date — *"what makes this the **current** wave rather than merely **an** active one"* |
| `wave_type` | `engine` (scheduler-owned waves; a manual wave follows the operator's own path) |
| status | `WaveManager::ACTIVE_STATUSES` = **`collecting`, `preparing`** |

## 1.2 Explicitly forbidden

- ❌ `orderByDesc('starts_at')`
- ❌ company-wide wave selection
- ❌ any duplicated wave-selection logic in Distribution

**This supersedes the Part 4 implementation**, which is wrong on three counts and is left in place, unchanged, until the implementation subsection is authorised:

| | Part 4 (currently deployed) | Approved |
|---|---|---|
| Scope | company-wide | company **+ warehouse** |
| "Active" | `WaveStatus::activeValues()` — 5 statuses incl. `draft` | `ACTIVE_STATUSES` — **2** |
| Date | ignored | `planning_date` |
| Tie-break | `orderByDesc('starts_at')` | `orderByDesc('planning_date')` |

> **Carried as a known defect.** In the current single-warehouse data it selects the correct wave; with a second warehouse it would silently select the wrong one.

---

# 2. NO SCHEMA CHANGE TO `distribution_windows` — THIS PHASE

**Approved:** do **not** add `warehouse_id` to `distribution_windows`. **No migration.**

Distribution becomes warehouse-scoped through the existing warehouse-aware read/selection path:

```
OrganizationContext.activeWarehouseId          (exists — header switcher, persisted)
        │  warehouse_id
        ▼
Distribution read endpoints
        │
        ├─► WaveManager::getActiveWave(company, warehouse, date)     (exists)
        │
        └─► Window (company + date — UNCHANGED)
                 └─► orders WHERE assigned_warehouse_id = :warehouse
```

The Window row stays a company-day container. It is the **view and the cycle** that become warehouse-scoped, not the window's identity.

---

# 3. API SURFACE — APPROVED IN PRINCIPLE, NOT NOW

These read endpoints **may** accept an optional `warehouse_id`:

| Endpoint | Today |
|---|---|
| `GET /windows/current` | — |
| `GET /windows/{w}/zones` | — |
| `GET /windows/{w}/slots` | — |
| `GET /windows/{w}/products` | — |
| `GET /windows/{w}/overflows` | — |
| `GET /windows/{w}/orders` | **already supports it** |

Additive and non-breaking: omitting the parameter reproduces today's behaviour exactly.

> **NOT IMPLEMENTED.** Per §3 of the decision, these belong to the next implementation subsection. **No endpoint was changed.**

---

# 4. DISTRIBUTION GROUP WAREHOUSE OWNERSHIP — REQUIRED ARCHITECTURE CHANGE

**Approved as a real data-isolation requirement, NOT a UI-filtering problem.**

## The unsafe structure, stated plainly

| Fact | Consequence |
|---|---|
| `distribution_virtual_slots` hangs off a **company-level** Window | two warehouses share one set of Groups |
| It has **no `warehouse_id`** | a Group cannot be owned by a warehouse |
| `distribution_zones` has **no company and no warehouse** | a Zone is pure geography; orders from different warehouses can share one |
| the Zone→Group unique index is per **window**, not per warehouse | the second warehouse's planner **silently steals** a Zone from the first |

That last row is the sharp edge: it is not a display problem. Warehouse B's planner assigning a Zone to their Group **moves it out of** warehouse A's Group, and A's totals drop with no error and no trace.

## Recorded requirement

> **Multi-warehouse Distribution is NOT complete until Distribution Group warehouse ownership exists in the data.**
>
> - UI filtering alone is **rejected** as a solution.
> - It **may require a migration**.
> - It must be implemented in a **dedicated subsection with explicit tests**.

Candidate shapes (to be decided in that subsection, not here): `distribution_virtual_slots.warehouse_id`; or per-warehouse Windows; or a warehouse dimension on the Zone→Group membership.

**Nothing implemented. No migration written.**

---

# 5. "NO WAREHOUSE" BUCKET — PLANNED

**Approved:** orders with `assigned_warehouse_id = NULL` must **never silently disappear**. Same principle as Unassigned: show the order, state why, never guess.

- ❌ Do not infer a warehouse from the zone, the city, or anything else.
- Current data: **3 of 9** orders carry NULL — all presently ineligible, so nothing is hidden today.
- Preparation already skips these orders (its collector requires `assigned_warehouse_id` to match the wave), so under a warehouse-scoped Distribution they would be invisible in **both** modules.

**Not implemented.** If implementation turns out to need a new contract → STOP + REPORT.

---

# 6. `shortage_blocked` — CONFIRMED

**Approved:** `WaveManager::ACTIVE_STATUSES` stays **unchanged** (`collecting`, `preparing`).

> A Preparation Wave in `shortage_blocked` does **NOT** create an active Distribution cycle.

That warehouse's Distribution Planning will show **no active cycle** until the shortage is resolved and the wave returns to `preparing`. This is now an intended, recorded behaviour rather than a side effect.

**No change to Preparation or to the Wave lifecycle.**

---

# 7. SCOPE OF THIS STEP

**Audit and decision only.** Not implemented, per §7 of the decision:

| Item | Status |
|---|---|
| API `warehouse_id` parameters | **NOT implemented** — §3 |
| Migrations | **none written** |
| Group warehouse ownership | **NOT implemented** — §4, own subsection |
| "No Warehouse" bucket | **NOT implemented** — §5 |
| Wave-selection correction in Part 4 | **NOT implemented** — carried as a known defect, §1.2 |
| Vehicle Planning / Loading / Approval / Finalize | **NOT started** |
| Preparation | **NOT modified** |

**No commit.**

---

# 8. IMPLEMENTATION ORDER WHEN AUTHORISED

Proposed sequence for whenever the next subsection is opened — recorded here so the dependencies are explicit, **not** as an authorisation to proceed:

| # | Subsection | Needs a migration? |
|---|---|---|
| 1 | Adopt `WaveManager::getActiveWave` + optional `warehouse_id` on the 5 read endpoints | **no** |
| 2 | "No Warehouse" bucket | **no** (expected) |
| 3 | **Distribution Group warehouse ownership** | **probably yes** |
| 4 | Multi-warehouse browser acceptance (needs a second warehouse to exist) | — |

Subsection 3 is the gate on "multi-warehouse Distribution complete". Subsections 1 and 2 are safe on single-warehouse data; **only subsection 3 makes multi-warehouse operation safe.**

---

# 9. SEPARATE NOTE — PART 5 §3 DEFECT FOUND AND FIXED

Unrelated to D-P5-1, and reported for completeness: the gated test run that was outstanding when D-P5-1 was raised has since returned, and it found a **real defect in the Part 5 eligibility work** (not a test defect):

`test_an_order_whose_status_is_no_longer_eligible_disappears` failed. `excludePostponed()` covered only the **membership** half of eligibility on the read side. Collection filters status once and never looks again, so an Order collected while `in_progress` and later **cancelled** kept its assignment row and stayed in the pool, in its Zone, and in its Group totals — requirement **C** was not actually met.

Fixed by adding `constrainToEligible()` — status **and** membership — and using it at all five read-model call sites. A fixture bug was fixed alongside it (`preparation_wave_orders` carries no `created_at`/`updated_at`). The re-run is in flight; the result will be recorded in the Part 5 report. **No API, schema or Preparation change was involved.**

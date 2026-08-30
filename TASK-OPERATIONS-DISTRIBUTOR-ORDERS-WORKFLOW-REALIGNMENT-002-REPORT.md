# TASK-OPERATIONS-DISTRIBUTOR-ORDERS-WORKFLOW-REALIGNMENT-002
## Distribution Business Workflow Realignment — Audit Report

**Type:** AUDIT ONLY
**Status:** REPORT ONLY — no code, no migrations, no seeds, no RBAC, no API/route/UI changes, no commit
**Date:** 2026-08-21
**Branch:** `develop`
**Supersedes/corrects:** TASK-OPERATIONS-DISTRIBUTOR-ORDERS-WORKFLOW-ARCH-001-REPORT.md (see §26, CG-0)

---

# 1. EXECUTIVE SUMMARY

## 1.1 Verdict on the owner's workflow

The requested chain

```
Preparation → Distributor Orders → Eligible Orders Pool → Zone Assignment → Zone Grouping
→ Virtual Vehicle / Vehicle Plan → Real Vehicle Assignment → Driver Assignment
→ Approval / Finalize → Loading Drivers
```

is **architecturally sound and matches the shipped Distribution Core design**, with four caveats that must be settled before the parts are cut:

| # | Finding | Effect on the plan |
|---|---|---|
| **NF-1** | **`orders.logistics_city_id` is NULL on 100% of orders (0 of 9).** Zone resolution reads only that column, so **every order resolves to "unzoned"** | Zone Assignment cannot work until an address-binding step exists. **A new Part must be inserted before Zone Assignment** |
| **NF-2** | **Preparation → Distributor Orders is not a handoff.** Distribution reads `orders` directly by ADR-042 status and never consults Preparation | The arrow in the owner's diagram is *sequence in the operator's head*, not a data dependency. **Decision D-A** |
| **NF-3** | **The reference layer under the tenant-scoped operational layer is global.** `distribution_zones`, `logistics_shipping_companies` and `logistics_drivers` have **no `company_id`** | Tenant isolation holds for the *Window*, not for zone/carrier/driver configuration. **Decision D-J** |
| **NF-4** | The `vehicle_plan*` uuid↔bigint incompatibility is confirmed in full, plus two absent columns | Vehicle Planning is a **separate schema/architecture Part**, exactly as instructed |

## 1.2 The good news

**Part 1 (Eligible Orders Pool) is implementable and browser-verifiable today**, with zero dependency on the broken `vehicle_plan*` schema:

- 4 orders are currently eligible under ADR-042 (`in_progress` × 3, `confirmed` × 1).
- `POST /logistics/distribution/windows/collect` and `GET /logistics/distribution/windows/{w}/orders` are **live and return 200**.
- Tenant isolation on that path **fails closed** (`abort(403)` for a company-less actor).
- The geography configuration itself is ready: `Maadi → city 2 → zone 7`, `Nasr City → city 1 → zone 2`.

The pool will legitimately show **4 orders, all unzoned** — which is the truthful state and is exactly what makes NF-1 visible to the operator rather than hidden.

## 1.3 Reconfirmed prohibitions

Distribution Board stays deleted. No new sidebar entries. No new order status. No Preparation change. No Partial Fulfillment change. No Loading contract change. VehiclePlan stores planned demand; AllocationRecord stays the actual-allocation authority.

---

# 2. CANONICAL BUSINESS WORKFLOW (as approved)

```
Preparation                    (parallel readiness track — NOT a gate today; see NF-2 / D-A)
     |
Distributor Orders             (Operations workspace — the operator's home for this chain)
     |
Eligible Orders Pool           ADR-042: in_progress + confirmed
     |
Zone Assignment                Order -> city -> distribution_zone
     |
Zone Grouping                  aggregated demand per zone, capacity per virtual slot
     |
Virtual Vehicle / Vehicle Plan planned demand only (no allocated quantity)
     |
Real Vehicle Assignment        fleet registry binding
     |
Driver Assignment              driver-vehicle pairing
     |
Approval / Finalize            state transition on the existing machine
     |
Loading Drivers                downstream consumer
```

Stops at Loading Drivers. Dispatch, Delivery, Tracking, Returns and Settlement are out of scope and remain untouched.

---

# 3. CURRENT WORKFLOW (as built, verified 2026-08-21)

```
orders (in_progress | confirmed)   ── 4 rows today
   |
   |── Preparation OS  ──► waves          (independent poll of the same statuses)
   |
   └── [MANUAL: POST /windows/collect]    (no scheduler, no job, no event trigger)
            |
       DistributionWindow            1 row, status=open, company-scoped
            |
       OrderZoneResolver             reads orders.logistics_city_id ONLY
            |
            └──► NULL for every order  ◄── NF-1: nothing populates that column
            |
       distribution_window_orders    0 rows
            |
       VirtualCapacitySlot           0 rows (capacity_orders only dimension evaluated)
            |
       XXXXX  NO LINK  XXXXX
            |
       vehicle_plans                 0 rows — no writer, and schema cannot reference reality
            |
       loading_sessions              0 rows — HAS vehicle_plan_id column, no writer
```

**Live endpoint status (bearer-authenticated probes):**

| Endpoint | Status |
|---|---|
| `GET logistics/distribution/windows/current` | **200** |
| `GET logistics/distribution/zones` · `areas` · `stats` | **200** |
| `POST logistics/distribution/windows/collect` | route live |
| `GET loading/sessions` · `loading/dashboard` | **200** |
| `GET logistics/distribution/planning/stats` · `zones` · `unassigned` | **500** (legacy) |
| `GET /api/distribution/*` (Board) | **404** (deleted) |

---

# 4. TARGET WORKFLOW (per stage, with the mechanism that will serve it)

| Stage | Mechanism | Exists? |
|---|---|---|
| Eligible Orders Pool | `DistributionCollectionService::collectForCompany()` + `DistributionAggregationService::orders()` | **YES — live** |
| Address binding (new) | resolve `orders.city` → `logistics_cities.id` at order write time | **NO — NF-1** |
| Zone Assignment | `OrderZoneResolver::resolveMany()` | **YES — live, but starved of input** |
| Zone Grouping | `DistributionAggregationService::zoneSummaries()` / `slotSummaries()` / `productAggregation()` | **YES — live** |
| Virtual Vehicle / Vehicle Plan | `vehicle_plans` + `vehicle_plan_slots` + `VehiclePlanStatus` | **schema only, and incompatible — NF-4** |
| Real Vehicle Assignment | `vehicle_plan_slots.vehicle_id` | **column exists, wrong type** |
| Driver Assignment | *(none)* | **column absent** |
| Approval / Finalize | `VehiclePlanStatus::Proposed → Approved`; `DistributionWindowStatus::Closed` | **states exist, no writer** |
| Loading handoff | `loading_sessions.vehicle_plan_id` | **column EXISTS, no writer** |

---

# 5. DISTRIBUTOR ORDERS — SOURCE OF TRUTH (§A)

### Where eligible orders come from

`DistributionCollectionService::eligibleUnassignedOrders()`:

```
SELECT orders.id, orders.logistics_city_id
FROM orders
WHERE orders.company_id = :companyId
  AND orders.status IN (config('distribution.eligible_order_statuses'))
  AND orders.deleted_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM distribution_window_orders dwo WHERE dwo.order_id = orders.id)
```

### How an order moves from Preparation to Distributor Orders — **it does not** (NF-2)

`grep` over the entire `Logistics\Distribution` module finds **no read of any Preparation table, service or event**. The only Preparation reference is `distribution_trips.preparation_wave_id`, a nullable column on the **post-dispatch** Trip aggregate, which is out of scope.

Both modules poll `orders` independently, using the **same** ADR-042 status list:

| Module | Eligibility source |
|---|---|
| Preparation | `PreparationSessionPolicy::defaultEligibleStatuses()` → `OrderStatus::fulfilmentEligible()` |
| Distribution | `config('distribution.eligible_order_statuses')` → `OrderStatus::fulfilmentEligible()` |

`config/distribution.php` states the separation deliberately: *"Deliberately declared here rather than imported from Preparation: Distribution must not depend on the Preparation module."*

**Consequence:** an order becomes distribution-eligible the moment it is `in_progress`/`confirmed`, **whether or not preparation has finished**. The owner's arrow `Preparation → Distributor Orders` is therefore an *operator sequence*, not an enforced dependency. → **Decision D-A.**

### Source of truth

| Question | SSOT |
|---|---|
| Is this order eligible? | `orders.status` + `OrderStatus::fulfilmentEligible()` (ADR-042) |
| Is this order already in the pool? | `distribution_window_orders.order_id` (**globally unique**) |
| Which window does it belong to? | `distribution_window_orders.distribution_window_id` |
| Which zone / slot? | `distribution_window_orders.distribution_zone_id` / `.virtual_slot_id` |

### How duplication is prevented

Not by an application check — by the **database**. `distribution_window_orders.order_id` carries a global unique index, so a second collector pass, or two concurrent collectors, cannot create a second assignment. The `NOT EXISTS` pre-filter is an optimisation, not the safety mechanism. Collection is therefore safe to invoke repeatedly, which is what makes a "Refresh pool" button safe in Part 1.

---

# 6. ORDER ELIGIBILITY (§I)

## Canonical definition — ADR-042

`docs/adr/ADR-042-order-fsm-v3-canonical.md` is the authority:

```php
OrderStatus::fulfilmentEligible() === [OrderStatus::InProgress, OrderStatus::Confirmed]
// ['in_progress', 'confirmed']
```

ADR-042 also states explicitly that `scheduled` and `awaiting_payment` are **not** eligible — they enter `in_progress` by their own business trigger and become eligible by that route — and that *"Distribution deliberately does not import Preparation's list."*

## The three definitions found, classified

| # | Definition | Location | Status |
|---|---|---|---|
| 1 | `['in_progress','confirmed']` | `config/distribution.php` → `OrderStatus::fulfilmentEligible()` | **CANONICAL** |
| 2 | `['in_progress','confirmed']` | `PreparationSessionPolicy::defaultEligibleStatuses()` → same enum | **CANONICAL** (same source, different module — by design) |
| 3 | `['confirmed','preparing']` | `DistributionPlanningController::READY_STATUSES` (private const) | **LEGACY HARDCODE — ignored** |

There are **not three competing approved definitions.** There is one approved definition, derived from the enum in two modules, plus one legacy hardcode inside the broken, non-canonical screen. **No decision is required.** §I gate: **PASS.**

## Live data

| Status | Count |
|---|---|
| `in_progress` | 3 |
| `confirmed` | 1 |
| **Eligible total** | **4** |
| `awaiting_payment` | 4 |
| `awaiting_stock` | 1 |
| **All orders** | **9** |

Part 1 has real, non-manufactured data to display.

---

# 7. ZONE ASSIGNMENT (§C)

## Canonical resolver

**`Modules\Logistics\Distribution\Domain\Services\OrderZoneResolver`** — and nothing else. Its own docblock declares the rule: *"No second geographic engine is introduced here and no coordinates are interpreted."*

```
orders.logistics_city_id → logistics_cities.distribution_zone_id → distribution_zones.id
```

Two methods: `resolve(?int $cityId)` and the batched `resolveMany(array $cityIds)` (collection runs in batches; per-order lookups would be N+1).

## The duplicate, and why it is not a conflict

`DistributionPlanningController::buildCityZoneMaps()` / `resolveZone()` re-implements the chain privately **and adds an English city-name text fallback**. It is not a competing authority:

- it lives inside the **non-canonical** screen (all three endpoints return **500**),
- it applies **no `company_id` filter**,
- its host controller is not part of the canonical stack.

Precedence is determinable from the documentation and the health of the two implementations. **§C gate: PASS — reuse `OrderZoneResolver`, create nothing.** The duplicate must be retired with its controller, not merged.

## Data required from the order address — and the blocker (NF-1)

The resolver reads exactly one field: **`orders.logistics_city_id`** (`unsignedBigInteger`, nullable, FK → `logistics_cities.id`).

| Measurement | Value |
|---|---|
| Orders with `logistics_city_id` populated | **0 of 9 (0%)** |
| Eligible orders with it populated | **0 of 4** |
| `logistics_cities` rows | 211 |
| Cities mapped to a distribution zone | 35 |

**The geography configuration is ready. The order binding is not.** Sample eligible orders:

| Order | `city` | `governorate` | `logistics_city_id` | Would resolve to |
|---|---|---|---|---|
| ORD-00009 | `Nasr City` | `Cairo` | **NULL** | city 1 → **zone 2** |
| ORD-00002 | `Maadi` | `Cairo` | **NULL** | city 2 → **zone 7** |
| ORD-00006 | `Maadi` | `Cairo` | **NULL** | city 2 → **zone 7** |
| ORD-00007 | `Maadi` | `Cairo` | **NULL** | city 2 → **zone 7** |

Every one of these **would** resolve correctly — the text matches `logistics_cities.name_en` exactly.

**Root cause:** the only thing that ever populated the column was a **one-time backfill inside migration `2026_07_16_000004_add_logistics_city_id_to_orders`** (`UPDATE orders SET logistics_city_id = (SELECT id FROM logistics_cities WHERE LOWER(name_en)=LOWER(orders.city))`). It ran once, before these orders existed. **No runtime writer resolves city text to city id on order create or update.** The column is now present in `Order::$fillable` (a documented earlier repair), but no caller supplies it.

→ **Decision D-C**, and the reason a new Part must precede Zone Assignment (§20).

---

# 8. ZONE GROUPING (§D)

## The read model that already exists

`DistributionAggregationService` is the Distribution Workspace read model — live, uncached by design (*"an Order that becomes eligible at 11:00 must show up in its Zone without anyone pressing refresh"*), computed from current rows on every call.

| Method | Returns |
|---|---|
| `zoneSummaries(windowId)` | `zone_id`, `zone_code`, `zone_name`, `virtual_slot_id`, `order_count`, `total_value`, `spans_slots` |
| `slotSummaries(windowId)` | `capacity_orders/stops/weight_kg/volume_m3`, `demand_orders`, `utilisation`, `overflow_orders`, `is_over_capacity`, `is_warning` |
| `productAggregation(windowId, zoneId?, slotId?)` | product quantities — *"Quantities only. No inventory is read, reserved, moved or consumed here."* |
| `orders(...)` | paginated, whitelisted-sort order list joined to customer, city, governorate, warehouse, zone |
| `overflows` / `lateOrders` | over-capacity slots; unassigned late arrivals |

**Unzoned orders are preserved, not dropped:** `zoneSummaries` emits a row with `zone_id = null`, so the pool always shows what could not be zoned. With NF-1 in force, that row will hold all 4 orders — the correct, honest rendering.

## KPIs available today without any backend work

Total orders · zone count · slot count · unassigned count · overflow slots · zones spanning slots · total value per zone · product quantities per zone/slot.

## KPIs **not** available (needed later by Vehicle Planning)

**Weight and volume are never aggregated**, and `slotSummaries()` computes utilisation from `capacity_orders` alone — `capacity_stops`, `capacity_weight_kg` and `capacity_volume_m3` are stored and returned but never evaluated. The ADR-015 five-constraint formula is not implemented. This is Vehicle-Planning-Part work, not Zone-Grouping work.

## Reusable frontend components

| Component | Location | Reuse |
|---|---|---|
| `distribution-workspace-page.tsx` (330 lines) | `features/logistics/distribution-workspace/pages` | **the canonical shell** — window header, KPI row, zone table, slot table |
| `zone-orders-drawer.tsx` | same feature | zone → orders drill-down |
| `distribution-order-detail.tsx` | same feature | order detail panel |
| `zone-planning-card.tsx`, `zone-detail-drawer.tsx` | `features/logistics/distribution-planning/components` | **presentation only** — reusable if re-pointed at the windows API |
| `distribution-zone-drawer.tsx`, `area-selector.tsx`, `transfer-list.tsx`, `area-count-popover.tsx` | `features/logistics/distribution-zones/components` | Zones CRUD — unchanged |

---

# 9. DISTRIBUTION PLANNING CORE (§B)

## Can the existing Distribution Core be reused? — **Yes, wholesale.**

`TASK-SHIPPING-DISTRIBUTION-CORE-001` is the canonical planning engine. It is company-scoped, idempotent by database constraint, emits domain events, and every endpoint returns 200.

### What Window / Zone / Slot own

| Concept | Owns | Deliberately excludes |
|---|---|---|
| **Window** (`distribution_windows`) | the daily cycle; `opens_at`, `closes_at` (cutoff), status; times are **copied onto the row at creation** so a later config change cannot reinterpret a window that already ran | — |
| **Zone** (`distribution_zones`) | geographic grouping, code/name/colour | **no `company_id`** (NF-3) |
| **Virtual Capacity Slot** (`distribution_virtual_slots`) | planning capacity in 4 dimensions | **no `vehicle_id`, no `driver_id`** — the migration states this absence is deliberate: *"a real Vehicle becomes operationally attached only when a Driver is assigned, which is the next task's boundary"* |
| **Slot ↔ Zone** (`distribution_slot_zones`) | a slot may hold many zones; within one window a zone belongs to **at most one** slot (unique index) | — |

`DistributionWindowStatus`: `scheduled → open → cutoff_reached → closed`. The enum's docblock is explicit that **cutoff is not a lock**: after cutoff, automatic ingestion stops but the workspace stays live, slot planning stays editable, and a manager may still attach late orders. `closed` is documented as *"Handed on to Loading. Terminal for this module."*

### What the Operational Workspace needs on top

| Need | Backend today | Gap |
|---|---|---|
| Show the pool | `windows/{w}/orders` | **none** |
| Refresh the pool | `POST windows/collect` | **no scheduler/job** — manual only (acceptable as an operator button) |
| Show zones + slots | `windows/current`, `/zones`, `/slots` | **none** |
| Show products | `windows/{w}/products` | **none** |
| Show overflow / late orders | `/overflows`, `/late-orders` | **none** |
| Move an order's zone/slot | `PATCH assignments/{a}/zone|slot` | **none** |
| Attach a late order | `POST windows/{w}/late-orders` | **none** |
| **Bind an order to a city** | — | **NF-1 / D-C** |
| **Weight & volume rollup** | — | Vehicle Planning part |
| **Finalize the window** | — | no writer for `closed` |

The current `distribution-workspace-page.tsx` renders window + zones + slots + a zone drawer. The orders / products / overflows / late-orders endpoints are **already wrapped in the frontend service layer** but not yet surfaced as tabs — that is Part 1 and Part 3 UI work against existing APIs.

---

# 10. VEHICLE PLANNING ARCHITECTURE (§E)

## What exists

| Artefact | State |
|---|---|
| `docs/architecture/VEHICLE-PLANNING-ENGINE.md` | **APPROVED** — full spec: 5-constraint sizing, round-robin-weight distribution, merge/split/move, replan/supersede, vehicle-matching rules |
| `vehicle_plans` | table migrated, 29 columns, CHECK constraints on status / policy / replan trigger, **0 rows** |
| `vehicle_plan_slots` | table migrated, 23 columns, **0 rows** |
| `vehicle_plan_slot_orders` | table migrated, 15 columns, **0 rows** |
| `vehicle_plan_adjustment_log` | table migrated, **0 rows** |
| `VehiclePlan`, `VehiclePlanSlot`, `VehiclePlanSlotOrder`, `VehiclePlanAdjustmentLog` | models exist |
| `VehiclePlanResource`, `VehiclePlanSlotResource` | resources exist |
| `VehiclePlanStatus`, `VehiclePlanSlotStatus` | state machines exist with enforced transition tables |
| `VehiclePlanned`, `VehiclePlanRecalculated`, `VehicleReleased` | events declared — **never dispatched** |
| **Service / Action / Controller / Route** | **NONE** |

The only code that reads these tables is `AutoAllocationService`, and only behind the `loading.use_vehicle_plan_slots` feature flag (default **OFF**).

## Design intent (spec §2)

> Position in the platform: **After Geography Grouping — before Loading Sessions.**
> `Geography & Coverage Engine → Vehicle Planning Engine → Loading & Allocation OS`

This matches the owner's chain exactly: Zone Grouping → Vehicle Plan → Loading.

**No migration or schema change is proposed in this audit.** §11 records the incompatibility as an independent blocker.

---

# 11. VEHICLE SCHEMA INCOMPATIBILITY — INDEPENDENT BLOCKER (§E, CRITICAL NEW FINDING)

The `vehicle_plan*` schema was authored on **2026-07-05** against a **uuid-keyed** fleet/geography model. The Logistics OS that actually shipped (LOG-001/002/003, later in 2026-07) is **bigint-keyed**. Every reference is therefore untypeable.

| Column | Declared type | Real referent | Referent type | Verdict |
|---|---|---|---|---|
| `vehicle_plans.zone_id` | `char(36)` | `distribution_zones.id` | **bigint** | **INCOMPATIBLE** |
| `vehicle_plans.governorate_id` | `char(36)` | `logistics_governorates.id` | **bigint** | **INCOMPATIBLE** |
| `vehicle_plans.shipping_company_id` | `char(36)` | `logistics_shipping_companies.id` | **bigint** | **INCOMPATIBLE** |
| `vehicle_plan_slots.vehicle_id` | `char(36)` | `logistics_vehicles.id` | **bigint** | **INCOMPATIBLE** |
| `vehicle_plan_slot_orders.zone_id_snapshot` | `char(36)` | `distribution_zones.id` | **bigint** | **INCOMPATIBLE** |
| `vehicle_plan_slots.driver_id` | **ABSENT** | `logistics_drivers.id` | bigint | **UNREPRESENTABLE** |
| `vehicle_plans.distribution_window_id` | **ABSENT** | `distribution_windows.id` | uuid | **UNLINKABLE to the canonical Window** |
| `vehicle_plans.geography_group_id` | `char(36)` nullable | *(GeographyGroup never built)* | — | dangling |

### Compounding finding — the live Loading OS is decoupled from the fleet registry

`AssignVehicleRequest` requires:

```php
'vehicle_id'           => ['required', 'uuid'],
'vehicle_registration' => ['required', 'string', 'max:50'],
'vehicle_type'         => ['required', 'string', 'max:50'],
'capacity_weight_kg'   => ['required', 'numeric', 'min:0'],
'capacity_volume_m3'   => ['required', 'numeric', 'min:0'],
'vehicle_plan_slot_id' => ['nullable', 'uuid'],
```

The Loading OS accepts **any uuid** as a vehicle and takes registration, type and capacity as **client-supplied snapshots**. It never reads `logistics_vehicles`. So a "vehicle" inside Loading is not provably a Vehicle in the fleet, and its capacity is whatever the client asserted — the same uuid/bigint divergence, worked around by not referencing the registry at all.

`vehicle_assignments.vehicle_id` is likewise `char(36)` while `logistics_vehicles.id` is bigint.

### Classification

> **BLOCKER VP-1 — Vehicle Planning schema/architecture incompatibility.**
> Severity: **BLOCKER for Parts 4–7.** Not a blocker for Parts 1–3.
> Resolution requires schema work and an owner decision on the key strategy. **Explicitly out of scope for this audit and for Part 1.**

Three resolution shapes exist (recorded, **not** recommended for adoption here — see **Decision D-E**): align `vehicle_plan*` to bigint FKs; introduce a uuid identity on the fleet tables; or keep snapshots and drop the FK claim entirely, accepting that a plan references nothing enforceable.

---

# 12. VEHICLE OWNERSHIP (§F)

| Question | Answer | Source |
|---|---|---|
| Which module owns the fleet registry? | **`Logistics\Vehicles`** — `logistics_vehicles` (bigint PK, **has** `company_id`) | LOG-003 |
| Which module owns vehicle **planning**? | **`Operations\Loading`** — the `vehicle_plan*` tables live there | ADR-015 §5: *Vehicle Assignment, Vehicle Requirement Calculation* are Loading & Allocation OS responsibilities |
| Which module owns vehicle **execution**? | **`Operations\Loading`** — `vehicle_assignments`, `vehicle_inventory_items` | ADR-015 §5/§6 |
| May Distribution own vehicles? | **No.** TASK-LOG-004B: *"Distribution owns no carrier, driver, vehicle or pairing data."* `distribution_virtual_slots` has no `vehicle_id`, deliberately | LOG-004B + Distribution Core migration |

**The ADR-015 "conflict" resolves without an amendment.** ADR-015 assigns vehicle planning to Loading & Allocation OS; the tables are already in `Operations\Loading`; the Distribution Core deliberately refuses to hold a vehicle. The engine therefore belongs in `Operations\Loading` and is **surfaced** — not owned — by the Distributor Orders workspace. UI location is not module ownership. **§F gate: PASS.**

ADR-015 §6 also already establishes **"Vehicle Mobile Warehouse"** — the vehicle-as-mini-warehouse principle the owner assumes is existing approved architecture, not a new idea.

---

# 13. DRIVER OWNERSHIP (§F)

| Question | Answer |
|---|---|
| Driver registry | **`Logistics\Drivers`** — `logistics_drivers` (bigint PK) |
| Driver ↔ Vehicle pairing SSOT | **`logistics_driver_vehicle_assignments`** (bigint FKs to both). Unique indexes forbid an illegal pairing |
| How Distribution consumes it | `Trip` carries **only** `driver_vehicle_assignment_id` — no `driver_id`, no `vehicle_id`, no pairing logic |
| Driver assignment in execution | `Operations\Loading\DriverAssignment` + `AssignDriverAction` + `DriverAssignmentController` — **live** |
| Driver assignment in **planning** | **`vehicle_plan_slots.driver_id` does not exist** → unrepresentable (VP-1) |

**Tenant note (NF-3):** `logistics_drivers` has **no `company_id`**; a driver belongs to a `shipping_company_id`. Driver visibility is therefore carrier-scoped, not tenant-scoped.

**Rule to preserve:** a plan slot must never store `driver_id` and `vehicle_id` as two independent columns — that would create a pairing authority competing with the ledger. Resolution must go through `driver_vehicle_assignment_id`. → **Decision D-F.**

---

# 14. ALLOCATION TIMING (§K)

The audit confirms **two distinct, separately-contracted layers. This is not a conflict.**

| | **Layer 1 — Planning allocation** | **Layer 2 — Quantity allocation** |
|---|---|---|
| Question | *Which orders ride on which vehicle?* | *Which physical units on this vehicle satisfy which order line?* |
| Artefact | `vehicle_plan_slot_orders` | `allocation_records` |
| Unit | order | quantity |
| Timing | **during planning**, frozen at Approval | **after loading** |
| Contract | `VEHICLE-PLANNING-ENGINE.md` §2 — *"After Geography Grouping — before Loading Sessions"* | `PARTIAL-FULFILLMENT-RULES` §2 — *"Partial Allocation — After vehicle loading — Vehicle inventory < order's requested quantity"* |
| Source | demand + capacity constraints | `vehicle_inventory_items` |
| Reversible | yes — replan creates a new version, old → `superseded` | no — audited `AllocationDecision` chain |
| Engine | *(missing)* | `AutoAllocationService` — **live** |

Confirmed by the shipped `LoadingSessionStatus` machine: `loading → loading_complete → allocating → allocated`. **Load first, then allocate** is the implemented contract for Layer 2, and it does not contradict Layer 1.

**Invariant to enforce in every later Part:** `VehiclePlan` stores **planned demand only** (`estimated_weight_kg`, `estimated_volume_m3`, `order_count`). It must never carry an allocated quantity, or it becomes a second SSOT against `allocation_records` and breaks `PARTIAL-FULFILLMENT-RULES`. **§K gate: PASS — two phases, no conflict.**

---

# 15. APPROVAL / FINALIZE (§G)

## Existing state machines — reuse, invent nothing

**`VehiclePlanStatus`** (DB CHECK-constrained, transition table enforced in the enum):

```
calculating → proposed → approved → loading → dispatched → completed
     ↘ cancelled      ↘ calculating (reject/replan)   ↘ superseded
```

**`VehiclePlanSlotStatus`:**

```
unassigned → assigned → confirmed → loading → dispatched → completed
```

**`DistributionWindowStatus`:** `scheduled → open → cutoff_reached → closed`

## Mapping the owner's names onto existing states — no new state is needed

| Owner's name | Existing state | Note |
|---|---|---|
| Draft | `VehiclePlanStatus::Calculating` | |
| Planning | `DistributionWindowStatus::Open` | |
| Proposed | `VehiclePlanStatus::Proposed` | |
| Approved | `VehiclePlanStatus::Approved` | |
| Finalized | `DistributionWindowStatus::Closed` | *"Handed on to Loading"* |
| Loading | `LoadingSessionStatus::Loading` + `VehiclePlanStatus::Loading` | |
| Loaded | `LoadingSessionStatus::LoadingComplete` | |
| Dispatched | `LoadingSessionStatus::Dispatched` | out of scope |

`Allocated` and `Pending Approval` are **not** introduced — both would duplicate `Proposed` inside a CHECK-constrained enum.

## The Finalize point that permits the Loading handoff

**`VehiclePlanStatus::Proposed → Approved`** is the gate. On that transition:

1. `approved_by` / `approved_at` are stamped (columns already exist),
2. the plan becomes immutable — later change requires a replan (new `version`, old row → `superseded`),
3. the plan becomes eligible to be referenced by `loading_sessions.vehicle_plan_id`.

**Blockers to Finalize** (to be enforced by the future action, per spec §7–§9): any overloaded slot; any slot without both vehicle and driver; unassigned orders not explicitly deferred.

**Contract gap CG-1 (carried forward):** two machines model "finalized" — `VehiclePlanStatus::Approved` (per plan) and `DistributionWindowStatus::Closed` (per window/day). A window holds many plans. The rule *"a window closes when every one of its plans is approved"* is written nowhere and has no writer. → **Decision D-G.**

---

# 16. LOADING HANDOFF (§H)

## Confirmed: the handoff column already exists

```
loading_sessions:
  id, company_id, warehouse_id, session_number, operational_date,
  vehicle_plan_id,  ← PRESENT (confirmed against the live schema)
  status, session_type, vehicles_count, orders_count, products_count,
  total_units_to_load, total_units_loaded, ...
```

`AssignVehicleRequest` also already accepts `vehicle_plan_slot_id` (nullable uuid).

> **This corrects TASK-OPERATIONS-DISTRIBUTOR-ORDERS-WORKFLOW-ARCH-001-REPORT §13 G-2**, which stated `loading_sessions` had no plan/window/zone column. The link exists; only the **writer** is missing. See §26 CG-0.

## What must cross the boundary

| Item | Carrier | Exists |
|---|---|---|
| Plan identity | `loading_sessions.vehicle_plan_id` | **YES** |
| Slot identity | `AssignVehicleRequest.vehicle_plan_slot_id` | **YES** |
| Vehicle | `vehicle_plan_slots.vehicle_id` | column present, **wrong type** (VP-1) |
| Driver | *(none)* | **ABSENT** (VP-1) |
| Orders per slot | `vehicle_plan_slot_orders` | **YES** |
| Planned demand | `estimated_weight_kg`, `estimated_volume_m3` | **YES** |
| Warehouse + operational date | `CreateLoadingSessionRequest` | **YES** |

## What must NOT cross

Allocated quantities, reservation state, and any authority to re-route orders between vehicles.

**Boundary rule:** Loading must never change which order is on which vehicle. The mechanism already exists — the `loading.use_vehicle_plan_slots` feature flag restricts each vehicle to its plan slot's orders — but it **defaults to OFF**, so today any vehicle may absorb any wave order. Turning it on is only safe **together with** a working handoff. → **Decision D-H.**

## Loading contract — unchanged, and its known gap

`GET /api/loading/sessions` returns `{success, message, data:{data:[], meta:{}}}` while `loadingOsService.listSessions()` returns `data.data` and the page calls `.map()` on it → `TypeError: sessions.data?.map is not a function`, escalated by the router error boundary into a full shell loss.

**No Loading API, service, DTO or response contract was inspected for change and none was modified.** The backend is healthy (200); the defect is on the client. It does **not** block Parts 1–8 — the handoff writes a column, it does not read that endpoint. It **does** block **browser acceptance of the Loading Drivers screen** in Part 9. → **Decision D-L.**

---

# 17. TENANT ISOLATION (§J)

## The canonical Distribution Core — fails closed

`DistributionWindowController::companyId()`:

```php
$companyId = $request->user()?->company_id;
if ($companyId === null || $companyId === '') {
    abort(403, 'No company scope for the acting user.');
}
```

The class docblock states the intent: *"TENANT SCOPING FAILS CLOSED. An actor with no company sees nothing"*, and explicitly rejects the `->when($companyId, …)` pattern that silently drops the filter.

- Windows are resolved `where('company_id', $companyId)`.
- A window belonging to another company is reported as **404, not 403** — it does not leak its existence.
- `DistributionAggregationService` is anchored at `distribution_window_orders` filtered by a `$windowId` the controller has already proven belongs to the acting company, so window-child data inherits the scope. `lateOrders()` additionally filters `o.company_id`.
- `DistributionCollectionService::collectForCompany($companyId)` filters `orders.company_id`.

**Operational layer: PASS. No change proposed, none needed.**

## The reference layer beneath it is global (NF-3)

| Table | `company_id` | Consequence |
|---|---|---|
| `distribution_zones` | **ABSENT** | zone configuration is shared by every company. `DistributionZoneController` contains **zero** company references |
| `logistics_shipping_companies` | **ABSENT** | carrier list is global |
| `logistics_drivers` | **ABSENT** (has `shipping_company_id`) | driver list is carrier-scoped, not tenant-scoped |
| `logistics_vehicles` | **present** | tenant-scoped |
| `logistics_cities` / `logistics_governorates` | **ABSENT** | reference geography — global is defensible |

Current live data: **1 company**, 10 zones — so the exposure is real but presently unobservable. (Zone count moved from 5 to 10 during this session; another session is editing zone configuration concurrently.)

This is **not** a defect in the canonical core; it is a boundary question about whether zone/carrier/driver configuration is platform reference data or per-tenant operational data. Vehicle Planning groups by **zone + shipping company**, so the answer determines whether a plan can be tenant-scoped at all. → **Decision D-J.** Nothing changed.

---

# 18. EXISTING REUSABLE COMPONENTS (§18)

## Backend — reuse as-is

| Component | Role |
|---|---|
| `DistributionWindowService` | window lifecycle, cutoff rule, next-window resolution |
| `DistributionCollectionService` | idempotent eligible-order collection |
| `DistributionAggregationService` | the entire live read model (zones, slots, orders, products, overflows, late orders) |
| `ManualAssignmentService` | zone/slot overrides, late-order attachment |
| `RedistributionSuggestionService` | advisory overflow suggestions (never mutates) |
| `OrderZoneResolver` | **the** zone resolver |
| `DistributionWindowController` | 13 live endpoints |
| `DistributionZoneController` | Zones CRUD |
| `OrderStatus::fulfilmentEligible()` | eligibility SSOT |
| `EnterpriseQueueSorterService` | 7-criteria paid-first ordering (Preparation) — reusable for plan ordering |
| `VehicleCapacityValidatorService` | 2 of the 5 constraints |
| `Operations\Loading` Actions/Services/Policies | loading execution |
| `vehicle_plan*` models, resources, state machines | reuse **after** VP-1 is resolved |

## Frontend — reuse

`distribution-workspace-page.tsx` (canonical shell) · `zone-orders-drawer.tsx` · `distribution-order-detail.tsx` · `zone-planning-card.tsx` / `zone-detail-drawer.tsx` (presentation only) · Zones CRUD components · the `ModuleNavGroup` / `subtree` navigation mechanism proven in IA-001.

---

# 19. LEGACY / DEAD COMPONENTS THAT MUST REMAIN REJECTED (§19)

| Component | Why | Action |
|---|---|---|
| `features/operations/distribution-board` — 5 pages, 22 components, service, hooks, types | backend deleted by CTO-approved TASK-LOG-004B; ~40 endpoints **404**; uses cookie auth against a bearer-token platform | **stays rejected** — do not revive, do not reference |
| `/api/distribution/*` (board, trips, manifests, dispatch-gate, loading-trips, fleet) | never re-created; LOG-004B names board/validate/finalize/auto-fill/dispatch-gate/loading-dashboards as out of scope | **do not create** |
| `DistributionPlanningController` + `DistributionZonePlan` | 3/3 endpoints **500**; duplicate zone resolver; **no tenant filter**; 0 rows | **do not repair into canonical** (§3 of the task). Retirement is Decision D-D |
| `features/logistics/distribution-planning` data layer | bound to the 500-ing controller | presentation may be reused; the data layer must not |
| `loading-dashboard-page.tsx`, `dispatch-gate-*` pages | call `/api/distribution/*` (404); dispatch is out of scope | **stays rejected** |
| `GeographyGroup`, `ShippingWave` | specified but never built | do not build speculatively — D-2/D-3 of the prior report |

---

# 20. PART-BY-PART IMPLEMENTATION PLAN (§20)

## Proposed change to the owner's decomposition

The owner's Part list is adopted with **one insertion** and **one merge**, both justified by audit evidence:

**INSERTION — a new Part 2 "Order Address Binding" before Zone Assignment.**
Reason: NF-1. `orders.logistics_city_id` is NULL on 100% of orders; `OrderZoneResolver` reads only that column. Zone Assignment therefore has **no input** and would be un-verifiable in the browser — every order would render "unzoned" no matter how correct the code is. Binding is a distinct concern (order write path) from zoning (distribution read path), touches a different module, and carries its own decision (D-C). Merging it into Zone Assignment would hide a cross-module change inside a Distribution part.

**MERGE — the owner's Part 2 (Zone Assignment) and Part 3 (Zone Grouping) become one Part.**
Reason: both are served by the *same already-live* endpoints (`windows/{w}/zones`, `/slots`, `/overflows`) and the same read model call. Once binding works, zoning is automatic inside `collect` — there is no separate zone-assignment surface to build or verify. Splitting them would produce a Part with no independently demonstrable behaviour.

## The plan

| Part | Name | Depends on | Blocked by | Browser-verifiable |
|---|---|---|---|---|
| **1** | **Eligible Orders Pool** | — | **nothing** | **YES — today, 4 real orders** |
| **2** | Order Address Binding (`city` → `logistics_city_id`) | D-C | decision only | YES — orders gain a city id |
| **3** | Zone Assignment + Zone Grouping (Distribution Workspace) | Part 2 | — | YES — orders group into zones 2 and 7 |
| **4** | **Vehicle Planning architecture + schema alignment** | D-E | **VP-1** | N/A — schema/architecture part |
| **5** | Virtual Vehicle / Vehicle Plan (calculation engine) | Part 4 | VP-1 | YES |
| **6** | Real Vehicle Assignment | Part 5, D-E | VP-1 | YES |
| **7** | Driver Assignment | Part 6, D-F | VP-1 (`driver_id` absent) | YES |
| **8** | Approval / Finalize | Part 7, D-G | — | YES |
| **9** | Loading Drivers Handoff | Part 8, D-H, D-L | **D-L for acceptance only** | Partial — see §16 |

Parts 1–3 are entirely independent of VP-1. **The broken `vehicle_plan*` schema blocks nothing before Part 4.**

---

# 21. PART 1 — EXACT SCOPE

> **Eligible Orders Pool inside Distributor Orders. Independently implementable, independently browser-verifiable, zero dependency on `vehicle_plan*`.**

### Goal

Operations → Distributor Orders → Distribution Planning opens the **canonical** Distribution Core workspace and shows the eligible-orders pool for today's window, with a refresh action.

### In scope

1. Point the **Distribution Planning** navigation entry at the canonical Distribution Core surface (navigation target only).
2. Surface the pool from **existing live endpoints only**: `GET windows/current`, `GET windows/{w}/orders`, `POST windows/collect`.
3. Pool table: order number, customer, received at, total, payment method, order status, distribution status, **zone (will read "Unassigned" for all 4 today)**, assignment source, assigned at. Server-side pagination and the whitelisted sort already exist.
4. KPI header from the existing payload: total eligible, in-pool, unassigned/unzoned, window status, cutoff time.
5. Breadcrumb `Operations → Distributor Orders → Distribution Planning`; page title from the navigation translation key.
6. Tests: nav resolution, tenant isolation (403 without company scope; 404 for another company's window), eligible-order selection matches ADR-042, collection idempotency, unzoned orders are shown and not dropped.

### Explicitly out of scope for Part 1

Zone assignment behaviour · vehicle planning · approval · loading handoff · repairing or redirecting the legacy `/logistics/distribution/planning` route · deleting the Distribution Board · any RBAC, migration, seed or API change · any Preparation, Orders-UI, Inventory or Finance change.

### Acceptance

Operations sidebar visible; **Distributor Orders** active parent; Zones still reachable; the pool renders **4 real eligible orders**; "Refresh pool" is idempotent (running it twice does not duplicate a row); breadcrumb correct; existing IA-001 acceptance tests still pass.

### Known truthful state at Part 1

All 4 orders will show as **unzoned** — that is correct, and it is the visible evidence for NF-1 that Part 2 exists to fix. **Part 1 must not fake or infer a zone.**

---

# 22. PART 2 — EXACT SCOPE

> **Order Address Binding — populate `orders.logistics_city_id`.** Requires **Decision D-C** first.

Bind an order's address to the canonical geography chain so `OrderZoneResolver` has input. **Creates no new resolver** (§C). Candidate placements (D-C decides): the Orders write path (`CreateManualOrderAction` / `UpdateOrderAction` / the WooCommerce importer), or a Geography-owned lookup service invoked by them.

Out of scope: any new zone logic; any change to `OrderZoneResolver`; back-filling historic rows (that is data manipulation — see §18 of the task); any order-status change.

Acceptance: a newly created/updated order carrying `city = "Maadi"` receives `logistics_city_id = 2`; an unmatched city leaves the column NULL and the order remains visible as unzoned.

---

# 23. PART 3 — EXACT SCOPE

> **Zone Assignment + Zone Grouping — the Distribution Workspace.**

Surface the existing read model as workspace tabs: **Orders Pool · Zones & Slots · Products · Overflows / Late orders**. Wire the existing override endpoints (`PATCH assignments/{a}/zone|slot`, `POST windows/{w}/late-orders`). Slot creation and zone→slot mapping via the existing `POST windows/{w}/slots` and `POST .../slots/{s}/zones`.

Backend work: **none expected** — every endpoint exists. If weight/volume rollup is wanted here rather than in Part 4, that is an explicit scope addition to be approved.

Acceptance: the 4 orders group under zones 2 and 7; moving an order between zones persists; an over-capacity slot reports `is_over_capacity`; unzoned orders remain visible.

---

# 24. PART 4 — EXACT SCOPE

> **Vehicle Planning architecture + schema alignment. Resolves BLOCKER VP-1. Requires Decision D-E.**

Decide and apply the key strategy for `vehicle_plan*`; add the absent `driver_id` and `distribution_window_id`; settle the `AssignVehicleRequest` uuid-vs-registry divergence; decide the fate of the dangling `geography_group_id`.

**This Part is where migrations become legitimate. No earlier Part may touch schema.**

Acceptance: a `vehicle_plans` row can reference a real `distribution_zones` row, a real `logistics_shipping_companies` row and a real `distribution_windows` row; a `vehicle_plan_slots` row can reference a real `logistics_vehicles` row and a real driver pairing.

---

# 25. SCHEMA / ARCHITECTURE BLOCKER FOR VEHICLE PLANNING (§25)

**BLOCKER VP-1** — see §11 for the full table. Summary:

- 5 columns are `char(36)` against **bigint** referents.
- `vehicle_plan_slots.driver_id` is **absent**.
- `vehicle_plans.distribution_window_id` is **absent**.
- `vehicle_plans.geography_group_id` dangles against an entity that was never built.
- `AssignVehicleRequest` requires a uuid `vehicle_id` and trusts client-supplied capacity; the live Loading OS never reads the fleet registry.

**Status:** independent, owner-decision-gated, **not solvable inside Parts 1–3**, and deliberately not solved in this audit. No migration, schema change or workaround is proposed.

---

# 26. CONTRACT GAPS (§26)

| ID | Gap | Severity |
|---|---|---|
| **CG-0** | **Correction to my own prior report.** WORKFLOW-ARCH-001 §13 G-2 stated `loading_sessions` had no plan/window/zone column. **`loading_sessions.vehicle_plan_id` exists.** The link is present; only the writer is missing | corrected here |
| **CG-1** | Two machines model "finalized" — `VehiclePlanStatus::Approved` (per plan) vs `DistributionWindowStatus::Closed` (per window). The relationship is written nowhere and neither has a writer | HIGH → D-G |
| **CG-2** | `orders.logistics_city_id` has **no runtime writer** — only a one-time migration backfill. Zone resolution is therefore permanently starved for new orders | **BLOCKER for zoning** → D-C |
| **CG-3** | Preparation → Distribution is not a data dependency; both poll `orders` independently | MEDIUM → D-A |
| **CG-4** | `distribution_zones`, `logistics_shipping_companies`, `logistics_drivers` carry **no `company_id`** | HIGH (isolation) → D-J |
| **CG-5** | `GeographyGroup` (spec input to Vehicle Planning) and `ShippingWave` (spec output) were never built | MEDIUM |
| **CG-6** | Weight/volume never aggregated; slot utilisation uses `capacity_orders` only; the ADR-015 5-constraint formula is unimplemented | HIGH (Part 4/5) |
| **CG-7** | Loading sessions response envelope vs `listSessions()` | BLOCKER for Part 9 acceptance → D-L |
| **CG-8** | Distribution events (`OrderAddedToDistributionWindow`, `DistributionAssignmentChanged`, `LateOrderManuallyAssigned`) have **zero listeners**; no scheduler runs `windows/collect` | MEDIUM |
| **CG-9** | `loading.use_vehicle_plan_slots` defaults **OFF** — Loading may re-route orders away from the plan | MEDIUM → D-H |
| **CG-10** | `DistributionPlanningController`: 500 on all endpoints, duplicate resolver, no tenant filter | HIGH → D-D |

---

# 27. DECISIONS REQUIRED (§27)

| # | Decision | Options | Recommendation |
|---|---|---|---|
| **D-A** | Is Preparation a **gate** for Distributor Orders? | (a) no — keep independent polling as built and documented (b) yes — require a preparation state before an order enters the pool | **(a)** — (b) changes Preparation coupling, which the task forbids, and contradicts `config/distribution.php`'s stated design |
| **D-C** | Where is `orders.logistics_city_id` populated? | (a) Orders write path (create/update/import) (b) a Geography lookup service the Orders path calls (c) at collection time inside Distribution | **(b)** — keeps the lookup in Geography (one owner), keeps Distribution reservation-blind and resolver-free. **(c)** risks a second resolver, which §C forbids |
| **D-C2** | Historic orders with NULL city id | (a) leave them unzoned (b) re-run a backfill | **(a)** — a backfill is data manipulation; §18 forbids manufacturing data |
| **D-D** | Legacy `/logistics/distribution/planning` route | (a) leave registered and untouched (b) redirect to canonical (c) retire the controller | **(a) for Part 1** — deep links keep resolving; (b)/(c) are a later, separate decision |
| **D-E** | `vehicle_plan*` key strategy (VP-1) | (a) align `vehicle_plan*` to bigint FKs (b) add uuid identity to the fleet tables (c) keep snapshots, drop the FK claim | **defer to Part 4** — this audit deliberately does not choose |
| **D-F** | Driver on a plan slot | (a) store `driver_vehicle_assignment_id` (b) store `driver_id` + `vehicle_id` separately | **(a)** — (b) creates a pairing authority competing with the ledger |
| **D-G** | When does a Window close? (CG-1) | (a) when every plan is approved (b) explicit supervisor action (c) both | **(c)** — auto-propose, supervisor confirms |
| **D-H** | Enable `loading.use_vehicle_plan_slots`? | (a) ON with the Part 9 handoff (b) leave OFF | **(a)**, but **only** in Part 9 — enabling it earlier would restrict Loading to plans that do not yet exist |
| **D-J** | Tenant scope of zones / shipping companies / drivers | (a) accept as platform reference data (b) make zones tenant-scoped | **decision needed before Part 4** — Vehicle Planning groups by zone + shipping company, so this decides whether a plan can be tenant-scoped. No change made |
| **D-L** | Loading sessions envelope (CG-7) | (a) client reads the paginated envelope (b) controller returns a flat array | **(a)** — the envelope matches every other list endpoint; audit all `/loading/*` lists before choosing. **Not touched in this audit** |
| **D-M** | Adopt the revised Part decomposition (§20)? | (a) yes — insert Address Binding, merge Zone Assignment + Grouping (b) keep the original 9 | **(a)** — justified by NF-1 and by shared endpoints |

---

# 28. STOP CONDITIONS (§28)

### Triggered — reported, not resolved

| # | Condition | Detail |
|---|---|---|
| **SC-1** | **Schema/architecture blocker** | VP-1 — `vehicle_plan*` cannot reference reality. Blocks Parts 4–7. Classified as an independent Part, per instruction |
| **SC-2** | **Missing data source** | CG-2 — `orders.logistics_city_id` has no runtime writer. Blocks zoning until D-C |
| **SC-3** | **Tenant isolation gap in the reference layer** | CG-4 — zones/carriers/drivers are global. Operational layer is sound; the config layer is not scoped |
| **SC-4** | **Loading contract conflict** | CG-7 — unchanged, and it blocks **Part 9 browser acceptance only** |
| **SC-5** | **Duplicate source of truth** | CG-10 — duplicate zone resolver inside the broken legacy controller. Resolved by **precedence** (canonical is documented and healthy), not by merging |

### Cleared

| Gate | Result |
|---|---|
| Order eligibility (§I) | **PASS** — ADR-042 is canonical; one definition, two modules, one legacy hardcode ignored |
| Zone resolver ownership (§C) | **PASS** — `OrderZoneResolver` is canonical; no new resolver needed |
| Vehicle ownership (§F) | **PASS** — ADR-015 → `Operations\Loading`; no contract amendment required |
| Allocation timing (§K) | **PASS** — two contract-defined phases, not a conflict |
| Tenant isolation, operational layer (§J) | **PASS** — fails closed |
| Duplicate state (§G) | **PASS** — every requested state already exists; none invented |
| Duplicate allocation engine | **PASS** — planning and quantity layers are distinct |

### Not blocking Part 1

**None of SC-1 through SC-5 blocks Part 1.** Part 1 depends only on live 200-returning endpoints, real data (4 eligible orders), and a navigation target.

---

## Compliance statement

**AUDIT ONLY.** No code was written. No file under `backend/` or `frontend/src/` was modified. No migration, seed, RBAC change, API change, route change or UI change was made. No ADR was edited. Nothing was committed. All work from the previously-stopped task was fully reverted before this audit began, verified by a zero diff on the affected page, a restored navigation config, an 18/18 test pass, and a type-check back at its 23-error baseline.

Every runtime claim was verified against the live dev stack (bearer-authenticated HTTP probes, MySQL schema inspection and row counts) or against the repository and its git history. Sources are named inline.

**STOP. Awaiting owner review and separate approval of PART 1.**

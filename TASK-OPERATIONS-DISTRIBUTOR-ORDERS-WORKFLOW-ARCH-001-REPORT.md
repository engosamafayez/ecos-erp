# TASK-OPERATIONS-DISTRIBUTOR-ORDERS-WORKFLOW-ARCH-001
## Distribution Execution Architecture — Distributor Orders

**Type:** Forensic Audit + Architecture Proposal
**Status:** REPORT ONLY — no code, no migrations, no API/route/UI changes, no commit
**Date:** 2026-08-21
**Branch:** `develop`
**Prerequisite:** TASK-OPERATIONS-DISTRIBUTOR-ORDERS-LOADING-001 (IA alignment — complete)

---

# 1. EXECUTIVE SUMMARY

## 1.1 The headline

The target workflow

```
Distribution Planning → Vehicle Allocation → Distribution Approval / Finalize → Loading Drivers
```

is **not missing four things. It is missing exactly one.**

| Stage | Status |
|---|---|
| Distribution Planning | **EXISTS, LIVE, HEALTHY** — but the wrong screen is in the navigation |
| Vehicle Allocation | **DESIGNED + SCHEMA MIGRATED + MODELS WRITTEN — ZERO engine, ZERO API** ← the only real gap |
| Distribution Approval / Finalize | **DESIGNED** — it is a state on the same missing engine, not a separate system |
| Loading Drivers | **EXISTS, LIVE** — but has no inbound data link from Distribution |

## 1.2 Five findings that change the shape of the work

**F-1 — The screen currently labelled "Distribution Planning" is dead at runtime.**
All three of its endpoints return **HTTP 500**. Root cause: `DistributionPlanningController::buildCityZoneMaps()` filters `logistics_cities.deleted_at`, and that column does not exist in the schema. Verified against the live dev backend and reproduced directly against MySQL.

**F-2 — The real, live, healthy Distribution Planning backend and UI already exist, and are not in the navigation.**
`TASK-SHIPPING-DISTRIBUTION-CORE-001` shipped a company-scoped, idempotent, event-emitting Window / Zone / Virtual-Slot planning engine with a complete live read model, plus a matching frontend at `/logistics/distribution/workspace`. Every endpoint returns 200.

**F-3 — Distribution Board is not "unfinished". It was deliberately deleted by a CTO-approved decision.**
`TASK-LOG-004B` (commit `81f21914`) removed `Modules/Operations/Distribution` — 71 files, 68 routes — because it had no ServiceProvider, was unregistered, and 16 of its 26 migrations were PostgreSQL-only on a MySQL deployment. Its commit message names `board aggregate, validate/finalize, auto-fill, dispatch-gate and loading dashboards` as **explicitly rejected**. The ~40 `/api/distribution/*` endpoints the Board frontend calls were never re-created and must not be.

**F-4 — "Vehicle Allocation" is an approved specification with migrated tables and no engine.**
`docs/architecture/VEHICLE-PLANNING-ENGINE.md` (Status: APPROVED) specifies it in full. Four tables are migrated and present in the live database (`vehicle_plans`, `vehicle_plan_slots`, `vehicle_plan_slot_orders`, `vehicle_plan_adjustment_log`). Models, Resources and a complete `VehiclePlanStatus` state machine (`calculating → proposed → approved → loading → dispatched → completed`) exist. **No service writes them. No controller exposes them. No route reaches them.**

**F-5 — "Distribution Approval / Finalize" already has its state; nobody moves it.**
`VehiclePlanStatus::Proposed → Approved` is the approval. `DistributionWindowStatus::Closed` is documented in code as *"Handed on to Loading. Terminal for this module."* Neither transition has a writer. Finalize is a missing **transition**, not a missing **page**.

## 1.3 STOP CONDITIONS TRIGGERED

Per the task's stop conditions, the following are **reported, not resolved**:

| # | Stop condition | Detail |
|---|---|---|
| S-1 | Dead backend needing redesign | `/api/distribution/*` — 40 endpoints, deliberately retired (F-3) |
| S-2 | Duplicate source of truth — zone resolution | `OrderZoneResolver` (canonical) vs `DistributionPlanningController::resolveZone()` (private duplicate with an English city-name text fallback) |
| S-3 | Duplicate source of truth — order eligibility | Three different definitions in three places (§12, C-7) |
| S-4 | Contract conflict — vehicle ownership | ADR-015 gives Vehicle Planning/Assignment to **Loading & Allocation OS**; the target workflow puts it under **Distributor Orders** |
| S-5 | Contract conflict — allocation timing | `PARTIAL-FULFILLMENT-RULES` fixes Partial Allocation as *"After vehicle loading"*; the target places allocation **before** loading |
| S-6 | Existing allocation logic vs target | `AutoAllocationService` allocates from **vehicle inventory after loading** — a different layer from the target's "Vehicle Allocation" (§4.4). Not a duplicate, but the boundary must be ruled on |
| S-7 | Loading contract gap | `GET /api/loading/sessions` envelope vs `listSessions()` — untouched, recorded (§11.4) |

**No code was written. No migration, seed, RBAC, API, route or UI change was made. Nothing was committed.**

---

# 2. EXISTING ARCHITECTURE MAP

## 2.1 Backend modules in play

```
backend/Modules/
├── Logistics/
│   ├── Distribution/          ← zones, windows, slots, trips, delivery, settlement
│   ├── Drivers/               ← Driver, DriverVehicleAssignment (the pairing ledger)
│   ├── Vehicles/              ← Vehicle, capacity specs
│   ├── ShippingCompanies/     ← ShippingCompany, ShippingContract
│   ├── Geography/             ← governorates, cities, areas
│   └── Network/               ← CapacitySlot (carrier network capacity)
└── Operations/
    ├── Preparation/           ← waves, EnterpriseQueueSorterService (paid-first)
    ├── DemandAnalysis/        ← product demand per wave
    └── Loading/               ← VehiclePlan*, LoadingSession, VehicleAssignment,
                                  AllocationRecord, VehicleInventory, ShipmentGroup,
                                  RoutePlan, VehicleShiftReconciliation
```

## 2.2 Live database state (dev, verified 2026-08-21)

| Table | Exists | Rows | Meaning |
|---|---|---|---|
| `distribution_zones` | yes | 5 | configured |
| `distribution_zone_plans` | yes | 0 | legacy planning — unused |
| `distribution_windows` | yes | 1 | today's window auto-created |
| `distribution_virtual_slots` | yes | 0 | never planned |
| `distribution_slot_zones` | yes | 0 | never mapped |
| `distribution_window_orders` | yes | 0 | collection never run |
| `vehicle_plans` | yes | **0** | **no writer exists** |
| `vehicle_plan_slots` | yes | **0** | **no writer exists** |
| `loading_sessions` | yes | 0 | never opened |
| `vehicle_assignments` | yes | 0 | — |
| `allocation_records` | yes | 0 | — |
| `vehicle_inventory_items` | yes | 0 | — |
| `distribution_trips` | yes | 0 | — |
| `shipment_groups` | yes | 0 | — |

> **Consequence:** there is **no production data** anywhere in the distribution chain. A canonical-stack decision can be taken freely — nothing has to be migrated, and no historical rows constrain the choice.

---

# 3. PART 1 — FORENSIC AUDIT (per required element)

Legend — **W** = works, **AC** = API connected, **PB** = production backend, **RU** = reusable.

### 3.1 Distribution Planning (legacy)

| Field | Value |
|---|---|
| Frontend | `frontend/src/features/logistics/distribution-planning/pages/distribution-planning-page.tsx` (1005 lines) |
| Route | `/logistics/distribution/planning` — **currently in the Operations sidebar** |
| Backend | `Modules/Logistics/Distribution/Presentation/Http/Controllers/DistributionPlanningController.php` (492 lines) |
| Endpoints | `GET planning/stats`, `planning/zones`, `planning/unassigned`, `planning/zones/{id}/detail`, `PATCH .../start`, `.../planned` |
| Service / Action | **none** — raw `DB::table()` in the controller |
| Model | `DistributionZonePlan` (0 rows) |
| **W** | **NO** — `stats` **500**, `zones` **500**, `unassigned` **500** |
| **AC** | routes registered, responses fail |
| **PB** | registered but broken |
| **RU** | controller: no. The *UI shell* (zone cards, drawer, toolbar): yes |
| Conflicts | S-2 duplicate zone resolver; S-3 duplicate eligibility (`confirmed`,`preparing`); **no `company_id` filter anywhere** (cross-tenant read) |

> **Root cause of the 500 (reproduced directly against MySQL):**
> `SQLSTATE[42S22]: Unknown column 'deleted_at' … select id, name_en, distribution_zone_id from logistics_cities where deleted_at is null`

**Classification: EXISTS + BROKEN + LEGACY.**

### 3.2 Distribution Zones

| Field | Value |
|---|---|
| Frontend | `features/logistics/distribution-zones/pages/distribution-zones-page.tsx` |
| Route | `/logistics/distribution/zones` — in Operations sidebar as **Zones** |
| Backend | `DistributionZoneController` (311 lines) |
| Endpoints | `stats`, `next-code`, `areas`, `zones` CRUD, `zones/{id}/status` |
| Model | `DistributionZone` (5 rows) |
| **W / AC / PB / RU** | yes / yes / yes / yes (200 verified) |
| Conflicts | none |

**Classification: EXISTS + WORKING + CORRECTLY PLACED.**

### 3.3 Distribution Workspace — the live planning surface

| Field | Value |
|---|---|
| Frontend | `features/logistics/distribution-workspace/pages/distribution-workspace-page.tsx` |
| Route | `/logistics/distribution/workspace` — **registered, NOT in navigation** |
| Backend | `DistributionWindowController` (400 lines) |
| Services | `DistributionWindowService`, `DistributionCollectionService`, `DistributionAggregationService` (527), `ManualAssignmentService`, `RedistributionSuggestionService`, `OrderZoneResolver` |
| Endpoints | `windows/current`, `windows/{w}/zones\|slots\|orders\|products\|overflows\|late-orders`, `POST windows/collect`, `POST windows/{w}/slots`, `POST .../slots/{s}/zones`, `PATCH assignments/{a}/zone\|slot`, `POST windows/{w}/late-orders` |
| Models | `DistributionWindow`, `VirtualCapacitySlot`, `DistributionSlotZone`, `DistributionWindowOrder` |
| Events | `OrderAddedToDistributionWindow`, `DistributionAssignmentChanged`, `LateOrderManuallyAssigned` — **dispatched, zero listeners** |
| **W / AC / PB / RU** | yes / yes / yes / yes (200 verified) |
| Conflicts | none. Company-scoped, idempotent by DB constraint, live read model, explicitly *"never touches `orders`"* |

**Classification: EXISTS + WORKING + NOT NAVIGABLE → CANONICAL.**

### 3.4 Distribution Trips

| Field | Value |
|---|---|
| Frontend | `features/logistics/trips/pages/trips-workspace-page.tsx` + 9 tab components |
| Route | `/logistics/distribution/trips` — registered, not in navigation |
| Backend | `TripController` (375) + `TripService` (284) |
| Endpoints | ~35 under `logistics/distribution/trips/*` — trips, orders, custody, stops, exceptions, returns, payments, settlement |
| Models | `Trip`, `TripOrder`, `TripCustody`, `DeliveryStop`, `TripReturn`, `PaymentCollection`, `TripSettlement` |
| **W / AC / PB / RU** | yes / yes / yes / yes (200 verified) |
| Conflicts | **out of the approved scope** — Trip covers dispatch → delivery → settlement, all excluded by IA-001 |

**Classification: EXISTS + WORKING + OUT OF SCOPE (post-Loading).**

### 3.5 Distribution Board

| Field | Value |
|---|---|
| Frontend | `features/operations/distribution-board/pages/distribution-board-page.tsx` + 22 components |
| Route | `/operations/distribution/board` — registered, not in navigation |
| Backend | **NONE.** No `prefix('distribution')` group exists in `routes/api.php`. `GET /api/distribution/board` → **404** |
| Auth | raw `fetch(..., credentials:'include')` — **cookies**; the platform uses **bearer tokens** via axios. It could not authenticate even if the backend existed |
| History | backend deleted by `81f21914` (TASK-LOG-004B), CTO-approved; board/validate/finalize/auto-fill explicitly listed as rejected |
| **W / AC / PB / RU** | no / no / no / **partial — UX patterns only** |

**Classification: DEAD / REJECTED LEGACY.**

### 3.6–3.9 Resource Assignment · Vehicle Assignment · Driver Assignment · Trip Form

| Element | Where it exists | Status |
|---|---|---|
| `resource-assignment-panel.tsx` | distribution-board | DEAD (404 backend) |
| `trip-form-drawer.tsx` (board copy) | distribution-board | DEAD |
| `trip-form-drawer.tsx` (trips copy) | `features/logistics/trips` | **LIVE** (`logistics/distribution/trips`) |
| Vehicle Assignment — **execution** | `Operations\Loading\VehicleAssignment` + `AssignVehicleToSessionAction` + `VehicleAssignmentController` | **LIVE** (`POST /loading/sessions/{s}/assignments`) |
| Vehicle Assignment — **planning** | `VehiclePlanSlot.vehicle_id` | **SCHEMA ONLY — no engine** |
| Driver Assignment — execution | `Operations\Loading\DriverAssignment` + `AssignDriverAction` + `DriverAssignmentController` | **LIVE** |
| Driver ↔ Vehicle pairing SSOT | `Logistics\Drivers\DriverVehicleAssignment` | **LIVE** — Trip carries only `driver_vehicle_assignment_id`; unique indexes forbid illegal pairings |

### 3.10 Finalize Distribution Plan

| Candidate | Status |
|---|---|
| `POST /api/distribution/board/finalize` | **404 — rejected legacy** |
| `DistributionWindowStatus::Closed` (*"Handed on to Loading. Terminal for this module."*) | **state exists, NO writer** |
| `VehiclePlanStatus::Proposed → Approved` (+ `approved_by`, `approved_at` columns) | **state + columns exist, NO writer** |

**Classification: NOT IMPLEMENTED — but fully modelled.**

### 3.11 Distribution Approval

No page, no controller, no route anywhere. Modelled only as `VehiclePlanStatus::Approved`. **NOT IMPLEMENTED.**

### 3.12 Loading Dashboard

| Field | Value |
|---|---|
| Frontend | `features/operations/distribution-board/pages/loading-dashboard-page.tsx` |
| Route | `/operations/loading/dashboard` |
| Backend called | `/api/distribution/loading-trips` → **404** |
| Backend that *does* exist | `LoadingDashboardController` → `GET /api/loading/dashboard` → **200** (different contract) |

**Classification: DEAD FRONTEND against a live-but-different backend. Salvageable only by rewrite.**

### 3.13–3.14 Loading OS Workspace / Loading Drivers

| Field | Value |
|---|---|
| Frontend | `features/operations/loading-os/pages/loading-os-workspace-page.tsx` (398 lines) |
| Route | `/operations/loading/workspace` — sidebar **Loading Drivers** |
| Backend | `Modules/Operations/Loading` — 9 controllers, 16 Actions, 9 Services, 25 Models |
| Endpoints | ~25 under `/api/loading/*` — **all 200** |
| **W** | **backend healthy, page crashes** — `sessions.data?.map is not a function` (envelope gap, §11.4) |
| **AC / PB / RU** | yes / yes / yes |

**Classification: EXISTS + LIVE BACKEND + FRONTEND CONTRACT GAP.**

### 3.15 APIs / Services / Actions / Domain Services

| Layer | Distribution (Logistics) | Loading (Operations) |
|---|---|---|
| Controllers | 6 — Planning (broken), Zone, Window, Trip, Delivery, Settlement, DriverRuntime | 9, all live |
| Domain Services | 9 | 7 |
| Application Services | 0 | 2 (`AutoAllocationService`, `AllocationPolicyService`) |
| Actions | **0** | 16 |
| Policies | **0** | 3 |

> Distribution has **no Application/Action layer and no Policies at all**; Loading has both. Any new Distribution write path has no existing Action convention to follow inside that module.

### 3.16 Models / DTOs / Resources / Policies

- Distribution: 13 models, 11 Resources, **0 Policies**.
- Loading: 25 models, 13 Resources, **3 Policies** (`LoadingSessionPolicy`, `AllocationRecordPolicy`, `VehicleAssignmentPolicy`).
- **No DTO layer** in either module — Resources serve that role.

### 3.17 Events / Jobs

| Event | Emitted by | Listeners |
|---|---|---|
| `OrderAddedToDistributionWindow` | `DistributionCollectionService` | **0** |
| `DistributionAssignmentChanged` | `ManualAssignmentService` | **0** |
| `LateOrderManuallyAssigned` | `ManualAssignmentService` | **0** |
| `TripDispatched`, `TripSettled`, `TripStatusChanged`, `DeliveryStopCompleted` | Trip/Delivery/Settlement services | **0** |
| `LoadingSessionCreated/Closed/Cancelled`, `VehicleAssigned`, `VehicleLoaded`, `AllocationCompleted`, `AllocationAdjusted`, `DriverAssigned` | Loading Actions | **0 cross-module** |
| `VehiclePlanned`, `VehiclePlanRecalculated`, `VehicleReleased` | **nothing — never dispatched** | 0 |

**Jobs: none. Scheduler: none.** `POST windows/collect` is manual-only; nothing collects orders automatically.

### 3.18 Existing allocation logic

| Engine | Layer | Input | Output | Timing | Status |
|---|---|---|---|---|---|
| `AutoAllocationService` (275) | order-line quantity | `VehicleInventoryItem` (products physically on the vehicle) | `AllocationRecord` | **AFTER loading** | LIVE |
| `AllocationPolicyService` (92) | policy | feature flags + configuration version | partial allowed, tolerance %, priority on/off, slot-restricted on/off | — | LIVE |
| `AllocationDecisionChainService` (125) | audit | each decision | `AllocationDecision` | — | LIVE |
| `VehiclePlanSlot` | order → vehicle | orders + capacity | slots | **BEFORE loading** | **SCHEMA ONLY** |
| `EnterpriseQueueSorterService` (89) | ordering | wave orders | 7-criteria sort incl. `is_paid DESC` | Preparation | LIVE |

### 3.19 Existing geography / zone logic

- **Canonical chain:** `orders.logistics_city_id → logistics_cities.distribution_zone_id → distribution_zones`, implemented once in `OrderZoneResolver` (`resolve` + batched `resolveMany`).
- **Duplicate (S-2):** `DistributionPlanningController::buildCityZoneMaps()/resolveZone()` re-implements it privately **and adds an English city-name text fallback** the canonical resolver deliberately does not have.
- `docs/architecture/GEOGRAPHY-COVERAGE-ENGINE.md` specifies a `GeographyGroup` entity. **It was never built** — `vehicle_plans.geography_group_id` is a nullable FK to nothing.

### 3.20 Existing capacity / vehicle logic

| Capacity model | Dimensions | Used for | Status |
|---|---|---|---|
| `VirtualCapacitySlot` | orders, stops, weight_kg, volume_m3 | Distribution planning | LIVE — but **only `capacity_orders` is evaluated**; stops/weight/volume are stored and returned, never used |
| `Vehicle` | capacity specs | fleet registry | LIVE |
| `ShippingCompany` / `ShippingContract` | contract limits | per-carrier limits | LIVE |
| `VehicleCapacityValidatorService` (63) | weight/volume | Loading session guard | LIVE |
| `Network\CapacitySlot` | orders/stops/weight/volume | **carrier network** ledger (different bounded context) | LIVE |
| ADR-015 5-constraint formula `MAX(orders, weight, volume, stops)` | 5 | Vehicle Planning | **NOT IMPLEMENTED** |

---

# 4. PART 2 — CANONICAL DISTRIBUTION STACK

## 4.1 Comparison

| | **Distribution Planning** (legacy) | **Distribution Board** | **Distribution Workspace / Windows** | **Trips** | **Loading OS** |
|---|---|---|---|---|---|
| **Purpose** | zone cards + mark-planned | full board: pool, trips, resources, validate, finalize | daily window: collect orders → zone → virtual slot, live aggregation | dispatch → delivery → settlement | load vehicle, allocate, deliver, reconcile |
| **Implementation** | 492-line controller, raw SQL, no service | 22 components, ~40 endpoints | 4 services + 527-line read model, 4 models, 3 events | 7 models, `TripService` | 25 models, 16 Actions, 9 controllers |
| **Backend status** | **500 — broken** | **404 — deleted by CTO decision** | **200 — healthy** | **200 — healthy** | **200 — healthy** |
| **Business logic present?** | thin, in the controller | **none** (frontend only) | **yes** — eligibility, cutoff, idempotent collection, overflow, redistribution | yes | yes |
| **Company-scoped?** | **NO** | n/a | **YES** | yes | yes |
| **Reusable?** | UI shell only | UX patterns only | **YES — wholesale** | yes | yes |
| **Dead / legacy?** | **yes** | **yes** | no | no | no |
| **Recommended role** | **RETIRE** | **REJECT** | **CANONICAL Distribution Planning** | **out of scope** (post-Loading) | **CANONICAL execution** |

## 4.2 Decision

> ### CANONICAL DISTRIBUTION EXECUTION STACK
>
> **Planning:** `Logistics\Distribution` — Window / Zone / VirtualCapacitySlot
> (`DistributionWindowService`, `DistributionCollectionService`, `DistributionAggregationService`, `ManualAssignmentService`, `RedistributionSuggestionService`, `OrderZoneResolver`)
> **UI:** `features/logistics/distribution-workspace`
>
> **Vehicle Allocation + Approval:** `Operations\Loading` — `VehiclePlan` / `VehiclePlanSlot` / `VehiclePlanSlotOrder` / `VehiclePlanAdjustmentLog`, per the APPROVED `VEHICLE-PLANNING-ENGINE.md`
> **UI:** to be built
>
> **Loading execution:** `Operations\Loading` — `LoadingSession` / `VehicleAssignment` / `VehicleInventoryItem` / `AllocationRecord`
> **UI:** `features/operations/loading-os`
>
> **Rejected:** `features/operations/distribution-board` (5 pages, 22 components) and `DistributionPlanningController` + `DistributionZonePlan`.

**Why Workspace over Planning:** Planning is broken (500), not tenant-scoped, duplicates the zone resolver, and holds its logic in a controller. Workspace is live, tenant-scoped, idempotent by database constraint, has a documented live read model, and emits domain events. Neither holds any data, so there is nothing to migrate.

## 4.3 Single source of truth per concern

| Concern | SSOT — one place only |
|---|---|
| Order → Zone | `OrderZoneResolver` |
| Order → Window / Slot | `distribution_window_orders` |
| Zone → Slot | `distribution_slot_zones` (unique per window+zone) |
| Planning capacity | `distribution_virtual_slots` |
| Order → Vehicle (plan) | `vehicle_plan_slot_orders` ← **to be activated** |
| Vehicle ↔ Driver pairing | `driver_vehicle_assignments` |
| Vehicle physical stock | `vehicle_inventory_items` |
| Allocated quantity per order line | `allocation_records` |
| Delivered / returned / variance | `vehicle_shift_reconciliation_lines` |

## 4.4 The distinction that resolves the apparent duplication

**`VehiclePlanSlot` and `AllocationRecord` are NOT duplicates. They answer different questions.**

| | VehiclePlanSlot / SlotOrder | AllocationRecord |
|---|---|---|
| Question | *Which orders ride on which vehicle?* | *Which physical units on this vehicle satisfy which order line?* |
| Unit | order | quantity |
| Timing | **before** loading | **after** loading |
| Source | demand + capacity constraints | `vehicle_inventory_items` |
| Reversible | yes (replan → new version) | no (audited decision chain) |

Keeping both is correct **provided the plan stores *planned demand*, never *allocated quantity***. If Vehicle Allocation writes an allocated quantity, it becomes a second SSOT against `allocation_records`. → **Decision D-4.**

---

# 5. PART 3 — CURRENT WORKFLOW (as built today)

```
Orders (in_progress | confirmed)
     │
     ├─► Preparation OS ──► waves ──► prepared products pool
     │
     └─► [MANUAL POST /windows/collect]
              ↓
         DistributionWindow (open → cutoff_reached → closed*)
              ↓  OrderZoneResolver
         DistributionZone
              ↓  distribution_slot_zones
         VirtualCapacitySlot (capacity: orders only)
              ↓
         XXX  NO LINK  XXX          ← the entire gap
              ↓
         LoadingSession (created from warehouse_id + date ONLY)
              ↓
         VehicleAssignment ──► DriverAssignment
              ↓
         LoadProduct ──► VehicleInventoryItem
              ↓
         AutoAllocationService ──► AllocationRecord (partial-aware, paid-priority)
              ↓
         Dispatch ──► RecordProductDelivery ──► VehicleShiftReconciliation
```

`*` `closed` has no writer. `LoadingSession` has **no** `distribution_window_id`, no `vehicle_plan_id`, no zone.

---

# 6. PART 3 — TARGET WORKFLOW

## 6.1 Stage 1 — Distribution Planning

**Input:** eligible Orders — `config('distribution.eligible_order_statuses')` = ADR-042 `OrderStatus::fulfilmentEligible()` = `in_progress`, `confirmed`. This is the **only** eligibility definition that may survive (S-3).

| Question | Answer (existing mechanism — nothing new) |
|---|---|
| How are orders selected? | `DistributionCollectionService::collectForCompany()` — idempotent, unique index on `order_id` |
| Governorate | `logistics_governorates` via `logistics_cities` (read-only) |
| Zone | `OrderZoneResolver::resolveMany()` — FK chain only |
| Shipping Company | **NOT in the Window model today.** Only Trip and VehiclePlan carry `shipping_company_id` → **Decision D-2** |
| Orders | `DistributionAggregationService::orders()` — paginated, whitelisted sort |
| Products / Quantities | `DistributionAggregationService::productAggregation(window, zone?, slot?)` — quantities only, no inventory read |

**Output:** a Window whose eligible orders are each mapped to (Zone, VirtualCapacitySlot), with a live product aggregation per zone/slot.

**Gap:** weight and volume are **not** aggregated today, and `slotSummaries()` computes utilisation from `capacity_orders` alone. Vehicle Allocation needs weight and volume. → Phase 1 work.

## 6.2 Stage 2 — Vehicle Allocation

Implements the APPROVED `VEHICLE-PLANNING-ENGINE.md` on the already-migrated tables.

| Question | Answer |
|---|---|
| Split by Zone? | Input is one `(window, zone, shipping_company)` group → one `VehiclePlan` |
| Vehicle count | `MAX(⌈orders/max⌉, ⌈weight/max⌉, ⌈volume/max⌉, ⌈stops/max⌉)` — spec §4 |
| Vehicle selection | suggest by availability + capacity + type + refrigeration — spec §9 |
| Vehicle ↔ shipping company | `VehiclePlan.shipping_company_id`; limits from `ShippingCompany` / `ShippingContract` |
| Capacity | 5 constraints evaluated simultaneously; the most restrictive wins |
| Product quantities | **planned demand only** (§4.4, D-4) |
| Vehicle = mini-warehouse? | **YES — ADR-015 §6 "Vehicle Mobile Warehouse"**, already an approved principle |
| Partial allocation | plan level: an order that fits no slot stays **unassigned** and visible. Quantity-level partials remain `AllocationRecord`'s job |
| Paid vs unpaid | `distribution_policy = 'order_priority'` + reuse `EnterpriseQueueSorterService` (`is_paid DESC`). ADR-015 default: **Paid → COD → Deferred → Others** |
| Priority | as above — configurable per ShippingCompany (spec §6) |
| Allocated qty per order | **not here** — `allocation_records` owns it |
| Remaining qty | **not here** — `vehicle_inventory_items.quantity_unallocated` owns it |

**Output:** `VehiclePlan(status=proposed)` with slots, each carrying an order list, computed utilisation and an overload flag.

## 6.3 Stage 3 — Distribution Approval / Finalize

| Question | Answer |
|---|---|
| Who reviews? | Wave Planner proposes; **Warehouse Supervisor / Operations Manager approves** (`LOADING-ALLOCATION-OS-SPEC` §6) |
| What is reviewed? | slot utilisation, overloads, unassigned orders, vehicle + driver completeness, zone coverage |
| Plan states | `calculating → proposed → approved → loading → dispatched → completed`; `cancelled`, `superseded` — **already enforced by `VehiclePlanStatus::canTransitionTo()` and a DB CHECK constraint** |
| What blocks Finalize? | any overloaded slot; any slot without both vehicle and driver; unassigned orders not explicitly deferred; window not past cutoff |
| What happens on Finalize? | `VehiclePlan → approved` (+ `approved_by`, `approved_at`) → `DistributionWindow → closed` → handoff record created |
| Editable after Finalize? | **No.** A change requires a replan: new version, old row → `superseded` (spec §8). Orders already loaded cannot be replanned without a `LoadingException` |
| What passes to Loading? | approved plan → one `LoadingSession` per plan (or per slot) — **Decision D-3** |

## 6.4 Stage 4 — Loading Drivers

**Receives:** Vehicle + Driver + Slot (→ orders) + product demand + the plan reference. **Not** a Trip — Trip belongs to post-dispatch execution, which is out of scope.

**The boundary rule:** Loading may **never** decide *which order goes on which vehicle*. It only decides *which units on the vehicle satisfy which order line*. `AutoAllocationService` already honours this when `loading.use_vehicle_plan_slots` is enabled — the flag exists and **defaults to OFF**, which today lets any vehicle absorb any wave order. → **Decision D-5.**

---

# 7. PART 9 — TARGET PAGE ARCHITECTURE

## 7.1 Recommendation: Vehicle Allocation and Distribution Approval are TABS, not pages

Evidence, not preference:

1. IA-001 §5 already forbids splitting planning functions into separate pages without a contract.
2. `VehiclePlanStatus` makes Approval a **transition on the same aggregate**, not a separate entity — a separate page would own no data.
3. Platform precedent: Preparation Workspace is one shell with tabs (Today / Archive / Settings); splitting it into siblings is exactly what caused the documented `findModuleByPath` regression.
4. IA-001 §13 explicitly forbids creating a Vehicle Allocation page or a Distribution Approval page.

```
Distributor Orders
├── Distribution Planning        (route /logistics/distribution/planning — kept)
│   ├── Tab: Orders Pool         ← windows/{w}/orders
│   ├── Tab: Zones & Slots       ← windows/{w}/zones · /slots · /overflows
│   ├── Tab: Products            ← windows/{w}/products
│   ├── Tab: Vehicle Allocation  ← vehicle-plans        (NEW backend)
│   └── Tab: Review & Finalize   ← vehicle-plans/{id}/approve (NEW backend)
└── Zones                        (route /logistics/distribution/zones — unchanged)
```

## 7.2 Page contracts

### Distribution Planning

| | |
|---|---|
| Purpose | assemble today's distribution demand: orders → zone → slot |
| Primary data | `DistributionWindow`, zone summaries, slot summaries, orders, product aggregation |
| Actions | Collect · Create slot · Map zone→slot · Change assignment zone/slot · Attach late order |
| Inputs | eligible orders, zone config, slot capacities |
| Outputs | a zoned, slotted, aggregated Window |
| State | `scheduled → open → cutoff_reached → closed` |
| Permissions | `logistics.distribution.view` / `.create` / `.update` (exist) |
| Next step | Vehicle Allocation |

### Vehicle Allocation (tab)

| | |
|---|---|
| Purpose | turn slot demand into concrete vehicle loads |
| Primary data | `VehiclePlan` + `VehiclePlanSlot` + `VehiclePlanSlotOrder` |
| Actions | Calculate · Merge · Split · Move order · Add/Delete slot · Assign vehicle · Assign driver |
| Inputs | zone group, shipping-company limits, fleet availability, order weight/volume |
| Outputs | `VehiclePlan(proposed)` |
| State | `calculating → proposed` |
| Permissions | `loading.vehicle.assign` exists; **a plan-level permission does not** → D-6 |
| Next step | Review & Finalize |

### Distribution Approval / Finalize (tab)

| | |
|---|---|
| Purpose | supervisor gate before physical work begins |
| Primary data | plan + blockers |
| Actions | Approve · Reject → replan · Cancel |
| Inputs | proposed plan |
| Outputs | `VehiclePlan(approved)`, `DistributionWindow(closed)`, handoff record |
| State | `proposed → approved` \| `→ calculating` \| `→ cancelled` |
| Permissions | **none exists** → D-6 |
| Next step | Loading Drivers |

### Loading Drivers

| | |
|---|---|
| Purpose | execute loading, allocation, delivery, reconciliation |
| Primary data | `LoadingSession`, `VehicleAssignment`, `VehicleInventoryItem`, `AllocationRecord`, `VehicleShiftReconciliation` |
| Actions | Open · Assign vehicle/driver · Load product · Start/complete allocation · Record delivery · Reconcile |
| Inputs | **approved VehiclePlan** (today: nothing) |
| Outputs | loaded vehicles, allocation records, reconciliation |
| State | `draft → ready → loading → loading_complete → allocating → allocated → dispatching → dispatched → reconciling → closed` |
| Permissions | `loading.session.*`, `loading.vehicle.assign`, `loading.allocation.*` (exist) |
| Next step | **out of scope** |

---

# 8. PART 5 — ENTITY OWNERSHIP

| Entity | Owner module | Created by | Updated by | Consumed by | Source of Truth |
|---|---|---|---|---|---|
| Order | Commerce\Orders | Commerce | Commerce (FulfillmentEngine only) | everyone, read-only | `orders` |
| Order Item | Commerce\Orders | Commerce | Commerce | Preparation, Distribution, Loading | `order_lines` |
| Governorate / City | Logistics\Geography | Geography | Geography | read-only | `logistics_governorates`, `logistics_cities` |
| Distribution Zone | Logistics\Distribution | Zone CRUD | Zone CRUD | Planning, Vehicle Allocation | `distribution_zones` |
| Distribution Window | Logistics\Distribution | `DistributionWindowService` | same | Planning, Approval | `distribution_windows` |
| Window Order assignment | Logistics\Distribution | `DistributionCollectionService` / `ManualAssignmentService` | `ManualAssignmentService` | Vehicle Allocation | `distribution_window_orders` |
| Virtual Capacity Slot | Logistics\Distribution | `DistributionWindowController::storeSlot` | same | Vehicle Allocation | `distribution_virtual_slots` |
| Shipping Company | Logistics\ShippingCompanies | SC CRUD | SC CRUD | Vehicle Allocation, Trip | `shipping_companies` |
| Vehicle | Logistics\Vehicles | Fleet | Fleet | Allocation, Loading | `vehicles` |
| Driver | Logistics\Drivers | Fleet | Fleet | Allocation, Loading | `drivers` |
| Driver ↔ Vehicle pairing | Logistics\Drivers | pairing ledger | pairing ledger | Trip, Loading | `driver_vehicle_assignments` |
| **Distribution Plan** | **Operations\Loading** | *no writer* | *no writer* | Approval, Loading | `vehicle_plans` |
| **Plan Slot / Slot Order** | **Operations\Loading** | *no writer* | *no writer* | Loading | `vehicle_plan_slots`, `vehicle_plan_slot_orders` |
| Loading Session | Operations\Loading | `CreateLoadingSessionAction` | Loading Actions | Allocation, Reconciliation | `loading_sessions` |
| Vehicle Assignment | Operations\Loading | `AssignVehicleToSessionAction` | Loading Actions | Allocation | `vehicle_assignments` |
| **Allocated Quantity** | **Operations\Loading** | `AutoAllocationService` | `AllocationController::override` | Delivery, Reconciliation | **`allocation_records` — ONLY** |
| **Remaining Quantity** | **Operations\Loading** | `VehicleInventoryService` | same | Allocation | **`vehicle_inventory_items.quantity_unallocated` — ONLY** |
| Trip | Logistics\Distribution | `TripService` | `TripService` | Delivery, Settlement | `distribution_trips` |

**Ownership anomaly (D-1):** `VehiclePlan` lives in `Operations\Loading`, its UI belongs to `Distributor Orders`, and its input (`Window` / `Slot`) belongs to `Logistics\Distribution`. This must be ruled on explicitly, because ADR-015 assigns Vehicle Planning to Loading & Allocation OS while the Distribution Core migration states a Vehicle attaches only *"at the next task's boundary"*.

---

# 9. PART 6 — DISTRIBUTION ↔ LOADING BOUNDARY

```
+============== DISTRIBUTION ==============+   +=========== LOADING ===========+
| collect eligible orders                  |   | open loading session          |
| resolve zone                             |   | confirm vehicle + driver      |
| map zone -> virtual slot                 |   | load products -> vehicle stock|
| aggregate products / weight / volume     |   | allocate stock -> order lines |
| calculate vehicle requirement            |   | record delivered quantity     |
| assign vehicle + driver to plan slot     |   | reconcile the shift           |
| approve / finalize                       |   |                               |
+==========================================+   +===============================+
                    |                                        ^
                    +----- approved VehiclePlan (immutable) --+
```

**Invariants**

1. Loading **never** changes which order is on which vehicle. Enforceable today by turning **on** `loading.use_vehicle_plan_slots`.
2. Distribution **never** reads, reserves or moves inventory. Already true and documented in the migrations.
3. Quantity allocation exists **only** in `allocation_records`.
4. The handoff is a **version-pinned** reference (`vehicle_plan_id` + `version`), so an approved plan cannot be mutated behind Loading's back.
5. A change after Finalize is a **replan** (new version, old → `superseded`), never an in-place edit.

---

# 10. PART 4 — STATE MACHINE

## 10.1 What already exists (unchanged by this task)

| Machine | States |
|---|---|
| `DistributionWindowStatus` | `scheduled → open → cutoff_reached → closed` |
| `VehiclePlanStatus` | `calculating → proposed → approved → loading → dispatched → completed`; `cancelled`, `superseded` |
| `VehiclePlanSlotStatus` | present |
| `LoadingSessionStatus` | `draft → ready → loading → loading_complete → allocating → allocated → dispatching → dispatched → reconciling → closed`; `cancelled` |
| `AllocationRecordStatus`, `VehicleAssignmentStatus`, `DriverAssignmentStatus`, `ReconciliationStatus` | present |
| `TripStatus` | 13 states — **out of scope** |

## 10.2 Proposed vs existing

| Proposed | Existing equivalent | Verdict |
|---|---|---|
| Draft | `VehiclePlanStatus::Calculating` | covered |
| Planning | `DistributionWindowStatus::Open` | covered |
| Allocated | `VehiclePlanStatus::Proposed` | covered |
| Pending Approval | `VehiclePlanStatus::Proposed` | same state — **do not add** |
| Approved | `VehiclePlanStatus::Approved` | covered |
| Finalized | `DistributionWindowStatus::Closed` | covered (window level) |
| Loading | `LoadingSessionStatus::Loading` + `VehiclePlanStatus::Loading` | covered |
| Loaded | `LoadingSessionStatus::LoadingComplete` | covered |
| Dispatched | `LoadingSessionStatus::Dispatched` | covered |

> **Every proposed state already exists.** No new state is required, and none is proposed. `Pending Approval` and `Allocated` would duplicate `Proposed`; adding either would create ambiguity in an enum enforced by a database CHECK constraint.

**No state contract was changed by this task.**

**Contract gap CG-1:** two machines model "the plan is finalized" — `VehiclePlanStatus::Approved` (per plan) and `DistributionWindowStatus::Closed` (per window, per day). A window holds many plans. The rule *"a window closes when every one of its plans is approved"* is **not written anywhere**. → **Decision D-7.**

---

# 11. PART 7 + PART 11 — API AUDIT

## 11.1 `/api/distribution/*` — the dead namespace (40 endpoints)

**Every one returns 404.** No `prefix('distribution')` group exists in `routes/api.php`.

| Group | Count | Alternative that exists | Verdict |
|---|---|---|---|
| `board`, `board/validate`, `board/finalize`, `board/exceptions`, `board/zones/{}/orders`, `board/trips/{}/orders` | 6 | none | **REJECT** — named as out-of-scope by TASK-LOG-004B |
| `trips/*` (create, update, delete, auto-fill, orders, move, driver, vehicle, carrier, custody, approve, coverage, manifest, return-to-wave, dispatch, handover-status, driver-accept, dispatch-vehicle, audit-trail) | ~22 | `logistics/distribution/trips/*` — **different contract** | **REJECT the client**; the live Trip API is the replacement |
| `manifests/*` (get, start, complete, confirm item, resolve shortage, breakdown, driver-confirm, accept-discrepancy) | 8 | Loading OS: `sessions/{}/assignments/{}/load-product`, `/allocation`, `/reconciliation` | **REJECT** — commit states *"manifests (Loading OS is canonical owner)"* |
| `loading-trips` | 1 | `GET /loading/dashboard` | **REJECT the client** |
| `dispatch-gate`, `dispatch-gate/{trip}` | 2 | none (deleted) | **REJECT** — out of IA scope |
| `fleet/vehicles`, `fleet/drivers`, `fleet/carriers` | 3 | `logistics/vehicles`, `logistics/drivers`, `logistics/shipping-companies` | **REJECT the client** |

**Salvageable from Distribution Board:** UX patterns only — orders-pool layout, zone tab strip, capacity indicator, validation panel, trip card. **Nothing** from its service, hooks or types layer. Its `fetch(credentials:'include')` cookie auth is incompatible with the platform's bearer-token axios client.

## 11.2 Live and healthy (verified 200 against the dev backend)

| Endpoint group | Count | Role |
|---|---|---|
| `logistics/distribution/zones\|areas\|stats\|next-code` | 8 | Zones CRUD — **keep** |
| `logistics/distribution/windows/*`, `assignments/*` | 13 | **canonical Planning** |
| `logistics/distribution/trips/*` + delivery + settlement | ~35 | post-dispatch — out of scope |
| `loading/*` | ~25 | **canonical Loading** |
| `logistics/geography/*`, `logistics/vehicles`, `logistics/drivers`, `logistics/shipping-companies` | many | reference data |

## 11.3 Live but broken

| Endpoint | Status | Cause |
|---|---|---|
| `GET logistics/distribution/planning/stats` | **500** | `logistics_cities.deleted_at` does not exist |
| `GET logistics/distribution/planning/zones` | **500** | same |
| `GET logistics/distribution/planning/unassigned` | **500** | same |
| `GET logistics/distribution/planning/zones/{id}/detail` | 404 | no zone with that id |

## 11.4 Loading contract gap (PART 8 — NOT TOUCHED)

`GET /api/loading/sessions` returns `{success, message, data:{data:[], meta:{}}}`.
`loadingOsService.listSessions()` returns `data.data` — the `{data,meta}` object — and the page calls `.map()` on it → `TypeError: sessions.data?.map is not a function`, which the router error boundary escalates into a full shell loss.

**No Loading API, service, DTO or response contract was modified.** Recorded as a required decision (**D-8**): is `/loading/sessions` contractually paginated (fix the client) or flat (fix the controller)? Every other `/loading/*` list endpoint should be checked for the same envelope before either is chosen.

## 11.5 Endpoints that must be created (Phase 3 — NOT created here)

| Endpoint | Purpose |
|---|---|
| `POST logistics/distribution/vehicle-plans/calculate` | run the engine for (window, zone, shipping company) |
| `GET  logistics/distribution/vehicle-plans` | list plans for a window |
| `GET  logistics/distribution/vehicle-plans/{id}` | plan + slots + orders |
| `POST .../{id}/slots` · `DELETE .../slots/{s}` | add / delete slot |
| `POST .../slots/{s}/orders/move` | move order between slots |
| `POST .../slots/{s}/merge` · `/split` | merge / split |
| `PATCH .../slots/{s}/vehicle` · `/driver` | assign vehicle / driver |
| `POST .../{id}/approve` · `/reject` · `/replan` | approval gate |
| `POST .../{id}/handoff` | create the Loading Session from the approved plan |

---

# 12. PART 12 — CONTRACT / ADR AUDIT

| # | Contract | Current behaviour | Proposed behaviour | Without amendment? |
|---|---|---|---|---|
| C-1 | **ADR-015 §5** — Vehicle Assignment + Vehicle Requirement Calculation belong to *Loading & Allocation OS* | `VehiclePlan` tables live in `Operations\Loading` | UI under **Distributor Orders**, engine stays in `Operations\Loading` | **YES** — module ownership unchanged; only the UI location differs. Record as an IA note |
| C-2 | **ADR-015 §6** — Vehicle Mobile Warehouse | `VehicleInventoryItem` implements it | unchanged — the target's "vehicle = mini-warehouse" is already the contract | YES |
| C-3 | **`VEHICLE-PLANNING-ENGINE.md`** (APPROVED) | schema + models + states exist; **no engine** | implement exactly as specified | YES — implementing an approved spec |
| C-4 | **`PARTIAL-FULFILLMENT-RULES` §2** — Partial Allocation occurs *after vehicle loading* | `AutoAllocationService` honours it | Vehicle Allocation records **planned demand only**, never allocated quantity | YES **only if D-4 = "plan stores demand"**. Otherwise **AMENDMENT REQUIRED** |
| C-5 | **`LOADING-ALLOCATION-OS-SPEC` §3.1B** — VehiclePlan → **ShippingWave** → Loading | `ShippingWave` **never built**; `ShipmentGroup` exists but is created *per loading session* (downstream) | hand off `VehiclePlan → LoadingSession` directly | **AMENDMENT REQUIRED** — build ShippingWave or record that LoadingSession replaces it (**D-3**) |
| C-6 | **`GEOGRAPHY-COVERAGE-ENGINE`** — `GeographyGroup` is the plan input | never built; `vehicle_plans.geography_group_id` is a nullable FK to nothing | use `(window, zone, shipping_company)` as the group key | **AMENDMENT REQUIRED** — declare GeographyGroup superseded by Window+Zone (**D-2**) |
| C-7 | **ADR-042** — fulfilment-eligible = `in_progress`, `confirmed` | `config/distribution.php` correct; legacy planning controller uses `confirmed`,`preparing` | one definition only | YES — by retiring the legacy controller |
| C-8 | **ADR-027** — Orders reserve FG only; Manufacturing owns RM | Distribution explicitly never consults reservation state | unchanged — Distribution stays reservation-blind | YES |
| C-9 | **ADR-024** — one canonical cache key per entity | aggregation is uncached and live by design | unchanged | YES |
| C-10 | **TASK-SHIPPING-DISTRIBUTION-CORE-001 §11–§14** — *"a Slot is PLANNING capacity, not a Vehicle… no vehicle_id column exists, and that absence is deliberate"* | honoured | vehicle binding happens on `VehiclePlanSlot`, **never** on `VirtualCapacitySlot` | YES — the contract anticipated exactly this next step |
| C-11 | **TASK-LOG-004B** — Distribution owns no carrier/driver/vehicle data | honoured | `VehiclePlan` references vehicles but lives in `Operations\Loading`, not `Logistics\Distribution` | YES — provided D-1 keeps the engine in Loading |
| C-12 | **ADR-015** — allocation priority `Paid → COD → Deferred → Others` | `EnterpriseQueueSorterService` implements paid-first in Preparation | reuse it for plan ordering | YES |

**No ADR was modified.**

---

# 13. GAP CLASSIFICATION

| ID | Gap | Severity | Type |
|---|---|---|---|
| **G-1** | Vehicle Planning Engine: 0 services, 0 controllers, 0 routes | **BLOCKER** | backend |
| **G-2** | No `VehiclePlan → LoadingSession` handoff; `loading_sessions` has no plan/window/zone column | **BLOCKER** | backend + schema |
| **G-3** | Distribution Planning legacy controller returns 500 on every endpoint | **BLOCKER** | backend defect |
| **G-4** | Loading OS workspace crashes on the sessions envelope | **BLOCKER** | frontend/contract |
| **G-5** | Approval/Finalize transitions have no writer (`VehiclePlan`, `DistributionWindow → closed`) | HIGH | backend |
| **G-6** | Weight/volume never aggregated; slot utilisation uses order count only | HIGH | backend |
| **G-7** | `shipping_company_id` absent from Window/Slot — the plan group key is incomplete | HIGH | schema/contract |
| **G-8** | Duplicate zone resolver (S-2) | HIGH | correctness |
| **G-9** | `DistributionPlanningController` has no `company_id` filter — cross-tenant read | **HIGH — security** | correctness |
| **G-10** | The canonical Distribution Workspace UI is not navigable | MEDIUM | IA |
| **G-11** | Distribution events have zero listeners; no scheduler for `windows/collect` | MEDIUM | integration |
| **G-12** | No permission covers plan calculate/approve | MEDIUM | RBAC |
| **G-13** | `loading.use_vehicle_plan_slots` defaults OFF — Loading may re-route orders | MEDIUM | boundary |
| **G-14** | 5 dead frontend pages + 22 components against a 404 backend | MEDIUM | dead code |
| **G-15** | `GeographyGroup` and `ShippingWave` specified but never built | LOW | contract drift |
| **G-16** | Distribution module has no Action layer and no Policies | LOW | consistency |

---

# 14. REUSE vs REWRITE MATRIX

| Asset | Verdict | Note |
|---|---|---|
| `DistributionWindowService` · `DistributionCollectionService` · `DistributionAggregationService` · `ManualAssignmentService` · `RedistributionSuggestionService` · `OrderZoneResolver` | **REUSE AS-IS** | canonical planning engine |
| `DistributionWindowController` (13 endpoints) | **REUSE AS-IS** | |
| `DistributionZoneController` + Zones UI | **REUSE AS-IS** | |
| `features/logistics/distribution-workspace` | **REUSE — promote to the Distribution Planning route** | |
| `vehicle_plans*` tables + models + Resources + status enums | **REUSE — build the engine on top** | a large part of the work is already done |
| `Operations\Loading` (Actions, Services, Policies, controllers) | **REUSE AS-IS** | |
| `EnterpriseQueueSorterService` | **REUSE** | paid-first priority |
| `VehicleCapacityValidatorService` | **REUSE + EXTEND** | 2 of 5 constraints today |
| `features/logistics/distribution-planning` (UI shell) | **REUSE the shell, REWRITE the data layer** | point it at the windows API |
| `DistributionPlanningController` + `DistributionZonePlan` | **RETIRE** | broken, duplicated, untenanted, 0 rows |
| `features/operations/distribution-board` (5 pages, 22 components, service, hooks, types) | **REJECT — DELETE** | 404 backend, cookie auth, CTO-retired |
| `features/logistics/trips` + Trip/Delivery/Settlement backend | **KEEP, OUT OF SCOPE** | post-dispatch |
| `AutoAllocationService` | **REUSE — do not duplicate** | quantity layer, post-loading |

---

# 15. PART 11 — REGRESSION RISKS

| Area | Risk | Mitigation |
|---|---|---|
| **Preparation** | none — no shared table; Distribution declares its own eligibility deliberately | keep the two contracts separate |
| **Orders** | a plan writing to `orders` would breach the FulfillmentEngine status guard | plan stores order **ids only**; never writes `orders` |
| **Inventory** | planning must not read/reserve/move stock | already guaranteed by Distribution Core; apply the same rule to the plan engine |
| **Reservations** | ADR-027 — a plan must not create or consume reservations | plan stays reservation-blind, as collection already is |
| **Shipping** | removing the Distribution section from the Shipping sidebar (IA-001) | already done and verified |
| **Loading** | Loading re-routing orders would duplicate the plan | enable `loading.use_vehicle_plan_slots` **together with** the handoff (D-5) |
| **Procurement / Companies / Warehouses** | none | read-only references |
| **Vehicles / Drivers** | a plan could contradict `driver_vehicle_assignments` | resolve pairing through the ledger only — never store `driver_id` + `vehicle_id` independently on the slot |
| **Multi-tenancy** | the legacy planning controller reads across all companies | retiring it removes the exposure |
| **Navigation** | tabs inside Distribution Planning must not become sidebar siblings | reuse the `subtree` mechanism proven in IA-001; do not add a second resolver |

### The four-way duplication guard

| Concept | The ONE place it may live |
|---|---|
| Order → Vehicle | `vehicle_plan_slot_orders` |
| Inventory reservation | Inventory / Reservation engine (ADR-027) |
| Quantity → order line | `allocation_records` |
| Vehicle stock | `vehicle_inventory_items` |

---

# 16. PHASED IMPLEMENTATION PLAN (not started)

### PHASE 0 — Stabilise (prerequisite, small)

1. Repair or retire `DistributionPlanningController` (G-3) — decide via **D-9**.
2. Resolve the Loading sessions envelope (G-4) — decide via **D-8**.
3. Make the canonical Workspace reachable (G-10).

> Exit: every screen under Distributor Orders and Loading Drivers loads without a 500 or a crash.

### PHASE 1 — Canonical Distribution Backend

4. Add weight/volume aggregation and the 5-constraint utilisation (G-6).
5. Add the shipping-company dimension to the plan group key (G-7, D-2).
6. Delete the duplicate zone resolver (G-8) and the untenanted queries (G-9).

> Exit: planning exposes everything the engine needs; one zone resolver; one eligibility list.

### PHASE 2 — Distribution Planning (UI)

7. Point the Distribution Planning route at the windows API; keep the route, replace the data layer.
8. Tabs: Orders Pool · Zones & Slots · Products.

> Exit: IA-001 acceptance tests still pass; planning is operable end to end.

### PHASE 3 — Vehicle Allocation

9. `VehiclePlanCalculationService` implementing `VEHICLE-PLANNING-ENGINE.md` §4/§6.
10. Manual adjustment Actions (merge/split/move/create/delete/assign) + `vehicle_plan_adjustment_log`.
11. `VehiclePlanController` + routes + `VehiclePlanPolicy`.
12. Vehicle Allocation tab.

> Exit: a proposed plan with utilisation, overload flags and unassigned orders.

### PHASE 4 — Distribution Approval / Finalize

13. `ApproveVehiclePlanAction` + blocker rules.
14. Window close rule (CG-1, D-7).
15. Replan / supersede path.
16. Review & Finalize tab.

> Exit: an approved, immutable plan.

### PHASE 5 — Loading Drivers Integration

17. Handoff: approved plan → `LoadingSession` (+ vehicle/driver assignments), version-pinned.
18. Enable `loading.use_vehicle_plan_slots` together with the handoff (D-5).
19. Show plan provenance in the Loading workspace.
20. Delete the rejected Distribution Board stack (G-14).

> Exit: `Distribution Planning → Vehicle Allocation → Approval → Loading Drivers` operable end to end.

---

# 17. DECISIONS REQUIRED FROM THE OWNER

| # | Decision | Options | Recommendation |
|---|---|---|---|
| **D-1** | Which module owns the Vehicle Planning engine? | (a) `Operations\Loading` — where the tables already are  (b) move it to `Logistics\Distribution` | **(a)** — ADR-015 assigns it to Loading & Allocation OS; the tables, models and states are already there. UI location is not module ownership |
| **D-2** | Plan group key, since `GeographyGroup` was never built | (a) `(window, zone, shipping_company)`  (b) build GeographyGroup | **(a)** + record GeographyGroup as superseded. Requires adding a shipping-company dimension to planning |
| **D-3** | `ShippingWave` was never built | (a) `LoadingSession` is the wave  (b) build ShippingWave | **(a)** — `LoadingSession` already fills the role; amend `LOADING-ALLOCATION-OS-SPEC` §3.1B |
| **D-4** | Does Vehicle Allocation record quantities? | (a) **planned demand only**  (b) allocated quantity | **(a)** — (b) creates a second SSOT against `allocation_records` and breaks `PARTIAL-FULFILLMENT-RULES` |
| **D-5** | Enable `loading.use_vehicle_plan_slots`? | (a) ON together with the handoff  (b) leave OFF | **(a)** — OFF lets Loading re-route orders, violating the boundary |
| **D-6** | New permissions for plan calculate/approve | (a) new `distribution.plan.*`  (b) reuse `logistics.distribution.update` + `loading.vehicle.assign` | **(a)** — approval is a supervisor gate and deserves its own name. **Requires an RBAC change → explicit approval needed** |
| **D-7** | When does a Window close? (CG-1) | (a) when every plan is approved  (b) manual supervisor action  (c) both | **(c)** — auto-propose, supervisor confirms |
| **D-8** | Loading sessions envelope (PART 8) | (a) client reads the paginated envelope  (b) controller returns a flat array | **(a)** — the envelope matches every other list endpoint; check all `/loading/*` lists first |
| **D-9** | Legacy `/logistics/distribution/planning/*` | (a) retire the controller, keep the route pointed at the new UI  (b) repair the `deleted_at` bug and keep both | **(a)** — repairing it preserves a duplicate resolver and an untenanted query |
| **D-10** | Delete the Distribution Board stack? | (a) delete in Phase 5  (b) keep as reference | **(a)** — 404 backend, cookie auth, explicitly retired by TASK-LOG-004B |

---

## Compliance statement

No code was written. No file under `backend/` or `frontend/src/` was modified. No migration, seed, RBAC change, API change, route change or UI change was made. No ADR was edited. Nothing was committed. Every runtime claim in this report was verified against the live dev stack (HTTP status probes, MySQL schema and row-count queries) or against the repository and its git history; sources are named inline.

**Awaiting approval before any phase begins.**

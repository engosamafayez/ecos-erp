# TASK-OPERATIONS-VEHICLE-PLANNING-VP1-OWNER-DECISIONS-001 — REPORT

**Date:** 2026-08-21
**Status:** OWNER DECISION / ARCHITECTURE AUDIT ONLY — no implementation, no code, no migration, no schema, no API, no frontend, no permission, no business data, no vehicles, no drivers, no assignments, no commit. Database access was `SELECT` / `information_schema` only.

---

## 1. Executive Summary

All four decisions are answerable from current evidence. **None requires guessing, and three of the four are already decided by the code — they simply were never written down.**

Three findings dominate:

### 1.1 Every fleet and assignment table is empty

Live counts (`ecos_dev`, read-only):

| Table | Rows | Auto-increment reached |
|---|---|---|
| `logistics_vehicles` | **0** | 580 |
| `logistics_drivers` | **0** | 396 |
| `logistics_driver_vehicle_assignments` | **0** | 209 |
| `distribution_trips` | **1** | 273 |
| `distribution_virtual_slots` (Group) | **1** | — (uuid) |
| `vehicle_assignments` (Loading) | **0** | — (uuid) |
| `driver_assignments` (Loading) | **0** | — (uuid) |
| `loading_sessions` | **0** | — (uuid) |
| `dispatch_proposed_assignments` | **0** | 22 |
| `dispatch_resource_allocations` | **0** | 29 |
| `vehicle_plan_slots` (removed VVP) | **0** | — |

The auto-increment values prove these paths were **heavily exercised and then wiped** — they are proven, not speculative. And because every table is empty, **no option under any of the four decisions carries a data-migration, backfill, or conversion cost.** That removes the single largest risk from the whole decision set.

### 1.2 D3 is already decided — in a migration comment

`distribution_trips`' own migration states the rule verbatim:

> *"There is deliberately no `driver_id`, no `vehicle_id` and no pairing logic: the assignment ledger already guarantees one active driver per vehicle and one active vehicle per driver."*

Distribution, Drivers and Dispatch already agree that `logistics_driver_vehicle_assignments` is the one pairing ledger. **`Operations\Loading` is the sole module that maintains a second, parallel pairing.** D3 is therefore not a choice between four candidates — it is a decision to stop one outlier from owning what three modules already share.

### 1.3 D4 is already the de-facto rule — order counts only

Weight and volume capacity columns exist in **three** places (Group, `logistics_vehicles`, Loading snapshots) and are **enforced in zero**. Every place that actually enforces capacity uses an integer order count. The "no weight, no volume, no dimension engine" contract is not a simplification to be introduced — it is the behaviour already shipped.

### 1.4 The one genuine gap is D2

`logistics_drivers` has **no `uuid`, no `company_id`, no `warehouse_id`, and no global scope** (verified against the live schema). Both candidate tenant paths are structurally broken, and no code uses either. **A driver is tenant-scoped by nothing today.** That is the only decision requiring a schema addition, and the only BLOCKER.

### 1.5 Classification at a glance

| Decision | Classification |
|---|---|
| D1 — Vehicle identity | **BLOCKER** (contract ruling required; zero migration) |
| D2 — Driver identity / tenancy | **BLOCKER** (schema addition required) |
| D3 — Assignment authority | **BLOCKER** (duplicate authority must be closed before Loading integration) |
| D4 — Capacity contract | **NO BLOCKER** (ratify existing behaviour) |

---

## 2. Current VP-1 Blocker

VP-1 was raised as *"Vehicle **Planning** schema/architecture incompatibility"*, and its own decision entry is D-E, *"`vehicle_plan*` key strategy"*. Virtual Vehicle Planning is **removed** and its tables are at 0 rows, so six of the eight rows in VP-1's incompatibility table are moot.

What survives is VP-1's own *"compounding finding"*: the live Loading OS is decoupled from the fleet registry. Concretely — verified against the live database:

```
vehicle_assignments.vehicle_id   char(36)  NOT NULL   — no FK
driver_assignments.driver_id     char(36)  NOT NULL   — no FK
```

The only foreign keys on either table are internal (`driver_assignments.vehicle_assignment_id → vehicle_assignments`, `vehicle_assignments.loading_session_id → loading_sessions`). Neither `vehicle_id` nor `driver_id` references anything.

The prior report ended **VP-1 BLOCKED — OWNER DECISION REQUIRED** with four items. This report converts those four into decisions.

---

## 3. D1 — Vehicle Identity

**The question, in business language:**

> When a dispatcher assigns a vehicle to a group and that vehicle later carries stock out of the warehouse, **which record is the company's official register of that vehicle** — and which module is accountable for creating, editing and retiring it?

Today the answer differs by module, and one module holds a vehicle reference that points at nothing.

---

## 4. D1 Evidence

**a) Logistics owns Vehicle CRUD outright.** There is one vehicle management surface, at `Modules\Logistics\Vehicles`:

```
routes/api.php:2906   Route::middleware('auth:sanctum')->prefix('logistics/vehicles')
:2913  POST   /            → permission:logistics.vehicles.create
:2915  PUT    /{id}        → permission:logistics.vehicles.update
:2916  PATCH  /{id}/status → permission:logistics.vehicles.update
```

No other module creates, edits or retires a vehicle.

**b) Live schema — the two-key model already exists.** From `information_schema` on `ecos_dev`:

| Column | Type | Nullable | Key |
|---|---|---|---|
| `id` | `bigint unsigned` | NO | **PRI** |
| `uuid` | `char(36)` | YES | **UNI** |
| `company_id` | `char(36)` | YES | MUL (index only — no FK) |
| `capacity_orders` | `smallint unsigned` | **NO** | |
| `capacity_weight_kg` | `decimal(10,2)` | YES | |
| `capacity_volume_m3` | `decimal(10,3)` | YES | |

The `uuid` column is auto-populated in `Vehicle::booted()`, and its migration states the purpose: *"external systems and future modules reference the stable uuid while existing bigint foreign keys keep working."*

**c) Every enforced vehicle FK points at the bigint.** `fleet_units`, `logistics_driver_vehicle_assignments`, `dispatch_proposed_assignments`, `dispatch_resource_allocations` — all `→ logistics_vehicles.id`. `fleet_units`' own migration calls itself *"the operational shadow of exactly one V1 vehicle… holds CONDITION, never IDENTITY"*.

**d) Operations does not have a Vehicle at all.** Two greps over the whole module tree:

```
grep -rn "use Modules\Logistics" Modules/Operations/ --include=*.php   →  0
grep -rn "logistics_vehicles"    Modules/Operations/ --include=*.php   →  0
```

`Operations` never imports, queries, or dereferences a vehicle. `AssignVehicleToSessionAction` never looks one up; registration, type and both capacities are **client-supplied snapshots**.

**e) The referenced entity does not exist.** `Schema::create('vehicles')` appears in **zero** PHP files. The `vehicles` table that `docs/loading-os/DATABASE-DESIGN.md:280/:471` names as the cross-domain target was never built.

**f) `char(36)` in Loading is opaque, not uuid-typed.** `vehicle_assignments.created_by` is also `char(36) NOT NULL` and already receives a **stringified bigint** — `VehicleAssignmentController:57` passes `actorId: (string) $request->user()->id`. Uuid-ness comes only from `AssignVehicleRequest`'s `['required','uuid']` rule.

**g) Documentary conflict, unresolved.** `docs/loading-os/DATABASE-DESIGN.md` (**APPROVED — Engineering Design Phase**, the only one of the five with implementation authority) specifies `vehicle_id: UUID — cross-domain ref to vehicles (no FK)`. `docs/engineering/FOREIGN-KEY-STANDARDS.md:14` (APPROVED) mandates uuid cross-domain refs **without** FK constraints. `docs/data/IDENTITY-STRATEGY.md` ID-004 forbids auto-increment. Against that, the shipped `logistics_vehicles` is bigint auto-increment. The standards target **PostgreSQL 16+**; the live database is **MySQL 8.4**. Neither side supersedes the other in writing.

**h) Zero rows.** No conversion cost under any option.

---

## 5. D1 Options

| | Option | Consequence |
|---|---|---|
| **D1-A** | Operations `vehicle_id` is canonical; other modules resolve to it | **Not viable.** Operations has no vehicle table, no model, no CRUD, no permissions and no queries. Making it canonical means *building* a vehicle registry in Operations and demoting the one Logistics already owns — duplicating an entity the platform explicitly forbids duplicating. It would also strand every existing bigint FK. |
| **D1-B** | `logistics_vehicles` canonical; Operations references it **by bigint `id`** | Works, and needs no migration to function (`char(36)` already holds stringified bigints). **But** it breaks two certified FormRequest contracts, requires nine column-type migrations across Operations to be honest about what the columns hold, and puts Loading in direct violation of ID-007 and `FOREIGN-KEY-STANDARDS.md:14` — which would then also need formal superseding. |
| **D1-C** | `logistics_vehicles` canonical; Operations keeps its uuid contract and resolves `vehicle_id → logistics_vehicles.uuid` | **Zero migration on the vehicle side** — the `uuid` column already exists, is unique and is auto-populated. Loading's `['required','uuid']` rule stays valid unchanged. Satisfies `FOREIGN-KEY-STANDARDS.md:14` (cross-domain reference by uuid, no FK) *and* leaves every bigint FK in Fleet and Dispatch untouched. Costs Dispatch nothing. |
| **D1-D** | Flip `logistics_vehicles.id` to uuid (literal reading of the standards) | **Structurally destructive.** Requires dropping six FK constraints, altering two polymorphic `unsignedBigInteger resource_id` columns — one of which backs `dispatch_lock_one_live_unique`, the guard preventing two dispatchers holding the same van — and changing published `int` interface signatures (`FleetReadinessQueryInterface::verdictFor(int $vehicleId)`). All to accommodate one table that has no FK enforcement today. |

---

## 6. D1 Recommendation

### Recommended: **D1-C**

**Ownership answer:** `logistics_vehicles`, owned by `Modules\Logistics\Vehicles`, is the canonical Vehicle identity and the single ownership authority.
**Mechanism answer:** Operations keeps its uuid contract and resolves to `logistics_vehicles.uuid`.

**Why.** D1-C is the only option that requires neither a migration nor the overturning of a document. It is not a compromise invented here — it is the model `logistics_vehicles`' own migration describes, and the pattern every `dispatch_*` table already applies to itself (bigint `id()` for keys, unique `uuid` for public identity; controllers resolve `where('uuid', $id)`, resources emit `'id' => $this->uuid`, yet emit `'vehicle_id'` as the raw bigint).

D1-A is not viable on evidence. D1-B works but pays contract and migration costs D1-C avoids. D1-D is destructive and must be rejected.

**Why this does NOT create a second source of truth.** A second source of truth exists when two records can independently disagree about the same fact. Here there is one row in one table; `id` and `uuid` are two addresses for that same row, one of them unique-indexed and generated at insert by the model itself. Nothing can write a `uuid` that does not belong to exactly one `logistics_vehicles` row, and no module can create a vehicle outside `logistics.vehicles.create`. The resolver is a lookup, not a registry.

| | |
|---|---|
| Changes a certified contract? | **No.** `['required','uuid']` remains valid; `exists:` + model resolution are **additive**. |
| Migration | **No** |
| Schema change | **No** |
| API change | **No** to the request shape; the response would gain registry-sourced fields |
| Permission change | **No** |
| Frontend change | **No** for the contract; a picker would need to send the registry uuid instead of a free string |
| Business-data change | **No** — 0 rows |
| Affects | Vehicle ✔ · Loading ✔ · Group ✖ · Driver ✖ · Actual Loading ✖ · Dispatch ✖ |
| **Classification** | **BLOCKER** — Loading/Dispatch integration cannot proceed without it |

---

## 7. D2 — Driver Identity / Tenant Scoping

**The question, in business language:**

> Can a driver belonging to one company ever be paired with another company's vehicle, or be dispatched by another company's operator?

The expected answer is **no**. The evidence says that today, **nothing prevents it**.

---

## 8. D2 Evidence

**a) Live schema — three columns are absent.** `information_schema` on `ecos_dev`, `logistics_drivers`:

| Column | Type | Nullable |
|---|---|---|
| `id` | `bigint unsigned` | NO |
| `user_id` | `bigint unsigned` | YES |
| `shipping_company_id` | `bigint unsigned` | YES |

There is **no `uuid`**, **no `company_id`**, and **no `warehouse_id`**. Confirmed live, not merely from migrations.

**b) No global scope.** `grep -c "addGlobalScope" Driver.php → 0`; `grep -n "booted" Driver.php → no match`. `Vehicle` has a `tenant` scope; `Driver` has none.

**c) Both candidate tenant paths fail.**

- **`shipping_company_id` — structurally impossible.** `logistics_shipping_companies` has **no `company_id` column** (its full column list is `id, name, code, type, contact_person, phone, email, address, notes, status, timestamps`). The tenant link is the many-to-many `logistics_shipping_company_mappings(shipping_company_id, company_id uuid)`, unique on the **pair** (`shipping_company_company_unique`). One carrier may map to many tenant companies, so this path **can never yield a single owning tenant**.
- **`user_id` — real but unusable.** `logistics_drivers.user_id → users.id → users.company_id` resolves in two hops, but is nullable, and the migration notes most driver rows are master data with no login.

**d) Neither path is used anyway.** `DriverController`'s only three `company_id` substring matches are all `shipping_company_id` — a client-supplied filter (:94-95) and a validation rule (:353). **A driver is tenant-scoped by nothing.**

**e) A live cross-tenant write exists.** `DriverController::assignVehicle` resolves the *vehicle* correctly through the model (:284 `exists:` for shape, then :288 `Vehicle::findOrFail()` for tenancy) but resolves the *driver* at **:281** with a bare `Driver::findOrFail($id)` against a scope-less model. Its route (`routes/api.php:2951`) carries `permission:logistics.drivers.update` — a capability check, not a tenant predicate. A company-A operator holding that permission can bind its own vehicle to a **company-B driver**.

**f) Dispatch resolves by uuid without company scope.** `DispatchController:241/246/251` — `board()`, `proposal()` and `assignment()` are each `Model::where('uuid', $id)->firstOrFail()` with no company filter, while `index()`/`store()`/`resourcePool()` do use `companyId()`. `overrideAssignment` validates `exists:logistics_vehicles,id` / `exists:logistics_drivers,id` — existence only — and passes raw integers through.

**g) Loading's driver reference can never resolve.** `driver_assignments.driver_id` is `char(36) NOT NULL` with no FK, while `logistics_drivers.id` is bigint and the table has no uuid column. **There is no value that satisfies both.** This is why Dispatch is blocked: `DispatchVehicleAction:34-35` hard-requires an active driver assignment and throws without one.

**h) No warehouse relationship exists.** `grep -rn "warehouse" Driver.php + all Drivers migrations → 0`. Drivers are not warehouse-scoped in any form.

**i) Zero rows.** `logistics_drivers` = 0. Any column addition is free of backfill.

---

## 9. D2 Options

**The invariant itself is not in doubt.** No evidence anywhere — schema, code, docs or tests — suggests a driver should be shareable across tenant companies. The genuine question is **which column carries the tenant**, and the three candidates are not equal:

| | Option | Consequence |
|---|---|---|
| **D2-A** | Add `company_id` to `logistics_drivers` + a `Driver` global scope mirroring `Vehicle`'s | The only option that yields a **single owning tenant** per driver. Requires a migration — but on an empty table, so no backfill, no conversion, no PK change. Makes `Driver` symmetrical with `Vehicle`, which is already the house pattern. |
| **D2-B** | Derive tenancy from `user_id → users.company_id` | Fails in practice: nullable, and most drivers are master data with no login. Would leave a large unscoped remainder. |
| **D2-C** | Derive tenancy from `shipping_company_id` via the mappings table | **Structurally impossible** — the mapping is many-to-many by design, so it cannot yield one owning tenant even in principle. |
| **D2-D** | Leave drivers untenanted; enforce tenancy only at each call site | Rejected: it is the status quo, it already produced the live cross-tenant write at `DriverController:281`, and it must be re-implemented correctly at every future call site. |

**Separately, the cross-module identity question:** Loading's `driver_id char(36)` needs a canonical value. Mirroring D1-C, that means adding a nullable unique `uuid` to `logistics_drivers` with a `creating()` hook — the same shape `logistics_vehicles` already has.

---

## 10. D2 Recommendation

### Recommended: **D2-A**, plus a `uuid` column for the cross-module reference

**The approved contract:**

| Question asked | Answer from evidence |
|---|---|
| Canonical Driver owner | `Modules\Logistics\Drivers` — `logistics_drivers` |
| Tenant boundary | **Company.** A driver belongs to exactly one company |
| Company boundary | **A driver may never be assigned across companies** |
| Warehouse relationship | **None.** No warehouse column exists and none is proposed |
| Cross-warehouse within one company | **Yes** — permitted, and requires no change, precisely because drivers are company-scoped, not warehouse-scoped |
| Cross-company | **No** |
| Identity type | **bigint canonical** for identity and intra-Logistics FKs; **uuid** as the cross-module reference (mirrors D1-C) |
| Assignment authority | `logistics_driver_vehicle_assignments` — see D3 |

**The minimum safe invariant, stated for approval:**

> **A driver assignment must resolve the Driver within the same tenant/company context before any write.** Existence validation (`exists:`) is not sufficient — a raw-table rule runs on the query builder and bypasses the Eloquent global scope. Resolution must go through the model.

**Why.** D2-C is impossible and D2-B is unusable, so D2-A is not a preference — it is the only candidate that can deliver one owning tenant. The migration is minimal and free: the table is empty, the primary key is untouched, and the shape already exists on `Vehicle` to copy.

| | |
|---|---|
| Changes a certified contract? | **No** — but it makes `AssignDriverRequest`'s `['required','uuid']` *satisfiable* for the first time |
| Migration | **YES** — one additive migration: `company_id` (uuid, nullable, indexed) + `uuid` (char(36), nullable, unique) on `logistics_drivers` |
| Schema change | **YES** — two additive nullable columns; **no PK change, no type change, no column removed** |
| API change | **No** to request shapes; driver reads become tenant-filtered, which is the intent |
| Permission change | **No** — this is a tenancy predicate, not a capability |
| Frontend change | **No** for contracts; driver pickers would return fewer rows |
| Business-data change | **No** — 0 rows, no backfill |
| Affects | Driver ✔ · Vehicle ✔ (pairing) · Loading ✔ · Dispatch ✔ · Group ✖ · Actual Loading ✖ |
| **Classification** | **BLOCKER** — Dispatch cannot run without a resolvable driver, and the live cross-tenant write must not be carried into go-live |

---

## 11. D3 — Assignment Authority

**The question, in business language:**

> When a vehicle and a driver are committed to a group's delivery run, **which system holds the official record of that commitment** — so that Loading, Dispatch and the trip sheet all describe the same pairing rather than three versions of it?

---

## 12. D3 Evidence

**a) Five assignment-shaped tables exist. Only one is a pairing ledger.**

| Table | Module | Purpose | Keys |
|---|---|---|---|
| **`logistics_driver_vehicle_assignments`** | Logistics\Drivers | **the pairing ledger** | bigint FKs to both `logistics_drivers.id` and `logistics_vehicles.id` |
| `distribution_trips.driver_vehicle_assignment_id` | Logistics\Distribution | **consumes** the ledger | bigint FK to the ledger |
| `dispatch_proposed_assignments` / `dispatch_resource_allocations` | Logistics\Dispatch | **proposes**, then writes through the ledger | bigint FKs to vehicle, driver, trip |
| `vehicle_assignments` + `driver_assignments` | **Operations\Loading** | **a second, parallel pairing** | `char(36)`, **no FK to anything external** |
| `vehicle_plan_slots` | Operations\Loading | removed VVP | 0 rows, forbidden to revive |

**b) Distribution already made this decision, and wrote it down.** From the `distribution_trips` migration:

> *"`driver_vehicle_assignment_id` → `logistics_driver_vehicle_assignments` (LOG-002) … There is deliberately no `driver_id`, no `vehicle_id` and no pairing logic: the assignment ledger already guarantees one active driver per vehicle and one active vehicle per driver. The previous schema also denormalised `driver_name` and `driver_phone` onto the trip — dropped here as duplicate master data."*

This is an explicit, documented rejection of duplicate assignment state.

**c) The ledger enforces the invariant in the database.** Unique indexes on `(driver_id, active_flag)` and `(vehicle_id, active_flag)` — one active vehicle per driver, one active driver per vehicle.

**d) Dispatch already writes through the ledger, not around it.** `DispatchReleaseService::releaseOne()` calls `DriverVehicleAssignmentService::assign()` and then `TripService::assignDriverVehicle()`. Its own comments state the ownership: *"Drivers (LOG-002) owns the pairing"*, then *"Distribution (LOG-004B) owns the trip"*. Dispatch is a **proposal engine**, not an assignment owner.

**e) Distribution validates against the ledger by id.** `TripController::assignDriverVehicle`:

```php
'driver_vehicle_assignment_id' => ['required', 'integer', 'exists:logistics_driver_vehicle_assignments,id'],
```

**f) Loading is the sole outlier.** `vehicle_assignments` and `driver_assignments` are written only by `AssignVehicleToSessionAction` / `AssignDriverAction` and never reference the ledger. Live FK check on both tables returns only internal FKs — nothing external. Loading's pairing is invented per loading session from client-supplied strings.

**g) There is no bridge in either direction.** Dispatch has zero references to `vehicle_assignments`, to `Modules\Operations`, or to Loading. Loading has zero references to `logistics_vehicles`.

**h) Everything is empty.** The ledger, Loading's tables and Dispatch's tables are all at 0 rows, so consolidating authority costs no data reconciliation.

---

## 13. D3 Options

| | Option | Consequence |
|---|---|---|
| **D3-A** | Operations / Vehicle Planning owns assignment | **Not viable.** Vehicle Planning is removed and forbidden to revive. Operations has no vehicle or driver entity to pair. |
| **D3-B** | Loading owns assignment | Would promote the one module whose pairing has no FK, no tenancy and no uniqueness invariant, and would require Distribution to abandon a documented design and Dispatch to abandon a working one. It also cannot express the pairing at all until D1 and D2 are resolved. |
| **D3-C** | Dispatch owns assignment | Contradicts Dispatch's own comments and design: it proposes and releases, and deliberately writes through the ledger so *"an automated proposal cannot commit itself to V1"*. Making the proposer the owner removes that separation. |
| **D3-D** | **`logistics_driver_vehicle_assignments` (Logistics\Drivers) is the single ledger; Distribution owns the Trip and consumes it; Dispatch proposes into it; Loading consumes it** | Ratifies what three of four modules already do. Closes the duplicate by making Loading a consumer. No new concept, no new table. |

---

## 14. D3 Recommendation

### Recommended: **D3-D**

**The authority map, per the four sub-questions asked:**

| Assignment | Authoritative owner |
|---|---|
| 1. Vehicle assignment to a **Group** | **Distribution**, via the Trip — `distribution_trips.virtual_slot_id` links Group→Trip, and `driver_vehicle_assignment_id` links Trip→ledger. The Group itself holds no vehicle |
| 2. Driver assignment to that Vehicle/Group | **Logistics\Drivers** — the ledger, which already guarantees one-driver-one-vehicle |
| 3. Loading's vehicle assignment | **Consumer, not owner.** Loading's `vehicle_assignments` becomes an execution record that *references* the ledger instead of inventing a pairing |
| 4. Dispatch's vehicle assignment | **Proposer, not owner.** Dispatch proposes and releases *through* `DriverVehicleAssignmentService` |

**The chain, without duplicate assignment state:**

```
Group  (distribution_virtual_slots, uuid, warehouse-owned)
  │  distribution_trips.virtual_slot_id            ← already implemented
  ▼
Trip   (distribution_trips, bigint)
  │  driver_vehicle_assignment_id  (bigint FK)     ← already implemented
  ▼
Pairing ledger  (logistics_driver_vehicle_assignments)
  │  one active driver per vehicle, one active vehicle per driver (DB unique)
  ├──► Vehicle  (logistics_vehicles.id bigint / .uuid cross-module)
  └──► Driver   (logistics_drivers.id bigint / .uuid — to be added, D2)
                     │
                     ▼
              Loading   consumes the ledger; stops minting its own pairing
                     │
                     ▼
              Dispatch  proposes into the ledger; releases via Trip
```

**Why.** The alternative candidates each require a working module to abandon a documented design in favour of the one module whose pairing has no FK, no tenancy and no uniqueness guarantee. D3-D is the only option that does not create work in three modules to accommodate one.

**Note on scope, stated so it is not smuggled in:** deciding that Loading *consumes* the ledger does not by itself specify how `vehicle_assignments` links to it. That link is a design question for VP-1B and is deliberately **not** decided here.

| | |
|---|---|
| Changes a certified contract? | **Yes, prospectively** — Loading's pairing becomes derived rather than authored. No existing certified Distribution/Dispatch contract changes |
| Migration | **Not for D3 itself.** The linking column decided in VP-1B may need one |
| Schema change | Deferred to VP-1B |
| API change | **Yes, prospectively** — Loading's assignment endpoints would take a ledger reference rather than free-form vehicle/driver strings |
| Permission change | **No** |
| Frontend change | **Yes, prospectively** — Loading's vehicle/driver pickers would select an existing pairing |
| Business-data change | **No** — 0 rows in every affected table |
| Affects | Group ✔ · Vehicle ✔ · Driver ✔ · Loading ✔ · Actual Loading ✔ · Dispatch ✔ |
| **Classification** | **BLOCKER** — integrating Loading before this is settled would make the duplicate permanent |

---

## 15. D4 — Capacity Contract

**The question, in business language:**

> When we decide a group's work fits a van, **what are we counting** — and at which moment is it checked?

---

## 16. D4 Evidence

**a) The Group carries four capacity columns; only one is enforced.**

```
distribution_virtual_slots:
  capacity_orders      unsignedInteger  nullable   ← ENFORCED
  capacity_stops       unsignedInteger  nullable   ← never read for a decision
  capacity_weight_kg   decimal(12,2)    nullable   ← never read for a decision
  capacity_volume_m3   decimal(12,3)    nullable   ← never read for a decision
```

All four are fillable, cast, validated on write (`DistributionWindowController:540-543`) and echoed in aggregation output (`DistributionAggregationService:234-237`). **Exactly one is enforced** — `GroupFinalizationService:128`:

```php
if ($group->capacity_orders !== null && count($orders) > $group->capacity_orders) {
```

**b) The Trip enforces an order count.** `distribution_trips.capacity` is `unsignedSmallInteger NOT NULL DEFAULT 60`, enforced in `TripService` at :148 and :196 via `DistributionException::tripAtCapacity()`.

**c) The Vehicle carries an order count as its only NOT NULL capacity.** Live: `capacity_orders smallint unsigned NOT NULL` (default 60); `capacity_weight_kg` and `capacity_volume_m3` both **nullable**.

**d) Dispatch compares order counts and nothing else.** `Vehicle->capacity_orders` (supply) against `Trip->capacity` (demand). Dispatch never reads weight or volume.

**e) Loading demands weight and volume, then ignores them.** `vehicle_assignments.capacity_weight_kg_snapshot` and `capacity_volume_m3_snapshot` are `decimal(18,4) NOT NULL` (live), supplied by the HTTP client. Their only reader, `VehicleCapacityValidatorService`, is registered as a singleton in `LoadingServiceProvider` and **called nowhere** — the only two references in the whole `Modules/` tree are its `use` statement and its `singleton()` binding.

**f) `refrigerated` has no source.** Loading's assignment carries a refrigerated flag; `logistics_vehicles` has no such column. Its likely origin is `VEHICLE-ARCHITECTURE-SPEC.md`, which was never built.

**g) Net.** Weight/volume capacity exists in **three** places and is enforced in **zero**. Order count is enforced in **three** places (Group finalize, Trip assign/move, Dispatch scoring).

---

## 17. D4 Options

| | Option | Consequence |
|---|---|---|
| **D4-A** | Group `capacity_orders` is the only pre-assignment constraint; vehicle capacity validated only at assignment | Matches current behaviour. Leaves open whether the vehicle check is mandatory or advisory |
| **D4-B** | Vehicle capacity becomes a **Group** constraint before assignment | **Rejected.** A Group is created and filled before any vehicle exists for it, so the constraint has no value to test against. It would also re-import vehicle knowledge into the Group, which the approved simplification removes |
| **D4-C** | **Both** the Group order-count ceiling **and** the Vehicle `capacity_orders` are enforced at assignment, without changing Group semantics | Ratifies the shipped behaviour and closes the one real gap: today nothing compares group size to the assigned vehicle's capacity |
| **D4-D** | Introduce a weight/volume/dimension model | **Rejected.** No approved model exists, VVP was removed, and it would require populating two nullable source columns and inventing a `refrigerated` source |

---

## 18. D4 Recommendation

### Recommended: **D4-C**

**The approved capacity contract, stated for ratification:**

```
Group          capacity_orders   =  maximum NUMBER OF ORDERS in the group
                    ↓
Vehicle assignment
                    ↓
Vehicle        capacity_orders   =  maximum NUMBER OF ORDERS the vehicle carries

Validation:    Group order count  ≤  Vehicle.capacity_orders

No weight.  No volume.  No stops.  No product-dimension engine.
```

**Why.** This is not a new rule — it is the rule already enforced in all three places that enforce anything. `Vehicle.capacity_orders` is `NOT NULL DEFAULT 60`, so the value is **always available** and the check can never fail for want of data. Weight and volume are nullable at source and NOT NULL in Loading; adopting them would require inventing a null-handling business rule, which the brief forbids.

**Two items the owner should rule on explicitly, so they do not resurface:**

1. **Declare Group `capacity_stops`, `capacity_weight_kg`, `capacity_volume_m3` DORMANT.** They are written and displayed but decide nothing. Leaving them undeclared invites a future engineer to wire them.
2. **Decide the fate of `VehicleCapacityValidatorService`.** It is dead code. If it is ever wired, Loading's client-supplied NOT NULL snapshots become enforced ceilings — contradicting this contract, and rejecting every load where the snapshot is 0.

| | |
|---|---|
| Changes a certified contract? | **No** — Group semantics are unchanged; this ratifies existing behaviour and adds one comparison at assignment |
| Migration | **No** |
| Schema change | **No** |
| API change | **No** — an assignment attempt that exceeds capacity would return a 422 |
| Permission change | **No** |
| Frontend change | **No** contract change; an over-capacity error message would be surfaced |
| Business-data change | **No** |
| Affects | Group ✔ · Vehicle ✔ · Loading Preparation ✖ · Driver ✖ · Actual Loading ✖ · Dispatch ✖ |
| **Classification** | **NO BLOCKER** — ratification plus one additive validation |

---

## 19. Cross-Decision Ownership Model

The diagram in the brief was `Company → Vehicle Owner → Vehicle → Group → Driver → Loading → Dispatch`. **The evidence shows a different structure, and the brief invites the correction.** Three differences:

1. **The Group does not descend from the Vehicle.** A Group is owned by a **Warehouse** (`distribution_virtual_slots.warehouse_id`) and is created and filled *before* any vehicle exists for it. Vehicle assignment happens after the Group is ready — which is exactly where VP-1 begins.
2. **The Driver does not descend from the Group.** Driver and Vehicle are paired in the ledger independently of any group, and the ledger's uniqueness invariant is global to the fleet.
3. **The Trip is the anchor between them.** Group→Trip→ledger is the implemented path; there is no direct Group→Vehicle link, and the `distribution_trips` migration deliberately refuses one.

### Corrected model

```
COMPANY  (tenant boundary — one company owns everything below)
│
├── WAREHOUSE
│      └── GROUP            distribution_virtual_slots  (uuid, warehouse_id)
│             capacity_orders = max ORDERS                    [D4]
│                    │
│                    │  distribution_trips.virtual_slot_id
│                    ▼
│            TRIP           distribution_trips  (bigint)
│                    │      capacity = max ORDERS
│                    │  driver_vehicle_assignment_id (bigint FK)
│                    ▼
└── FLEET     PAIRING LEDGER   logistics_driver_vehicle_assignments   [D3]
       │        one active driver per vehicle · one active vehicle per driver
       │
       ├── VEHICLE   logistics_vehicles   id bigint  ·  uuid cross-module   [D1]
       │                capacity_orders NOT NULL
       └── DRIVER    logistics_drivers    id bigint  ·  uuid + company_id to add [D2]
                            │
                            ▼
                     LOADING    consumes the ledger; resolves vehicle/driver
                            │   through the registry, not client strings
                            ▼
                     DISPATCH   proposes into the ledger; releases via Trip
                                — sole inventory-mutation boundary
```

**The five singletons the brief requires:**

| Requirement | Answer |
|---|---|
| One Vehicle identity | `logistics_vehicles` — bigint identity, uuid cross-module reference |
| One Driver identity | `logistics_drivers` — bigint identity, uuid cross-module reference (to add) |
| One assignment authority | `logistics_driver_vehicle_assignments` |
| One tenant boundary | **Company.** Warehouse scopes the Group; it does **not** scope Vehicle or Driver |
| One capacity rule | **Order count.** `Group order count ≤ Vehicle.capacity_orders` |

---

## 20. Tenant / Company Boundary

| Entity | Tenant column | Today | Under the recommendations |
|---|---|---|---|
| Group | `company_id` uuid + `warehouse_id` uuid | scoped | unchanged |
| Trip | `company_id` | scoped (repaired in the previous workstream) | unchanged |
| **Vehicle** | `company_id` char(36) **nullable, no FK** | Eloquent global scope, deliberately permissive in three documented cases | unchanged; Loading must resolve through the model |
| **Driver** | **none** | **scoped by nothing** | **`company_id` added + global scope** [D2] |
| **Pairing ledger** | **none** | no tenant column; both uniqueness indexes are **global** | must be re-expressed per tenant once drivers are scoped |
| Loading `vehicle_assignments` | `company_id` char(36) NOT NULL | stamped **from the session**, never compared to the vehicle's | must be cross-checked |
| Dispatch | `company_id` | `index`/`store`/`resourcePool` scoped; **by-uuid resolvers are not** | resolvers need company scope |

**Warehouse is deliberately NOT a tenant axis for fleet.** Neither `logistics_vehicles` nor `logistics_drivers` has a `warehouse_id`, and none is proposed. A driver may work across warehouses within one company; a driver may never cross companies.

**One caveat that must not be glossed:** the `Vehicle` global scope is fail-*closed* for its stated threat model — its own comment names the target, *"closing the by-id IDOR where fetching another company's vehicle returned it verbatim"* — and deliberately permissive in three documented cases: unauthenticated (console/seeders/queue), a null-company super-admin, and `company_id IS NULL` rows treated as shared fleet. Since `logistics_vehicles.company_id` has **no FK to `companies`**, an FK on `vehicle_id` would buy existence, never tenancy.

---

## 21. Security Invariants

**These are classified separately from the architectural decisions, as required.** All are pre-existing; none is introduced by D1–D4; none is fixed here.

### 21.1 Minimum invariants, stated for approval

| # | Invariant |
|---|---|
| **S-1** | A vehicle reference accepted from a client must resolve to a `logistics_vehicles` row **owned by the acting company**, resolved **through the Eloquent model** (not `exists:`) |
| **S-2** | A driver reference must resolve to a `logistics_drivers` row owned by the acting company — which requires D2 first |
| **S-3** | A pairing must not span two companies: vehicle company = driver company = actor company |
| **S-4** | Every by-id/by-uuid resolver on a dispatch or assignment entity must carry a company predicate, not only a permission |
| **S-5** | Capability (`permission:*`) is never a substitute for a tenant predicate |
| **S-6** | An assignment's stamped `company_id` must be **verified against**, not merely copied from, the session |

### 21.2 Findings against the required checklist

| Check | Finding | Severity |
|---|---|---|
| Tenant isolation — Vehicle | Global scope present; **not invoked by Loading**, which never loads the model | HIGH |
| Tenant isolation — Driver | **Absent entirely.** No column, no scope, no call-site check | **CRITICAL** |
| Company isolation — pairing ledger | No tenant column; both uniqueness indexes global | HIGH |
| Warehouse isolation | **Not applicable** — fleet is company-scoped by design, not warehouse-scoped | N/A |
| Permission middleware — Logistics vehicles/drivers | Present (`logistics.vehicles.*`, `logistics.drivers.*`) | OK |
| Permission middleware — Dispatch | Present on all four verbs (`dispatch.view` / `.manage` / `.propose` / `.release`), including `PATCH /assignments/{id}/override` | OK |
| Permission middleware — Loading | Route group is `auth:sanctum` only, **but** every controller method calls `$this->authorize(...)` against registered policies (`LoadingSessionPolicy`, `VehicleAssignmentPolicy`, `AllocationRecordPolicy`), and there is a permissions seeder | OK |
| Request validation | `AssignVehicleRequest`/`AssignDriverRequest` validate **format only** — `['required','uuid']`, no `exists:`, no ownership | HIGH |
| Server-side re-resolution | **Absent in Loading** (`AssignVehicleToSessionAction` never loads a vehicle). **Absent in two Dispatch sites** (`DispatchController:189-190`, `DispatchOperationsController:249-250` validate `exists:` then pass raw ints to `DispatchProposalService:266-271`) | HIGH |
| Foreign-ID probing | `DispatchController:241/246/251` resolve `where('uuid',$id)->firstOrFail()` with no company predicate | HIGH |
| Raw integer / UUID injection | Loading accepts **any** well-formed uuid as a vehicle or driver; no FK, no existence check | HIGH |
| Live cross-tenant write | `DriverController::assignVehicle:281` — bare `Driver::findOrFail($id)` on a scope-less model behind a capability-only route | **CRITICAL** |

### 21.3 Two claims explicitly disproved during this audit

Recorded so they are not inherited:

- **"A Dispatch route has no permission middleware."** False. `PATCH /assignments/{id}/override` (`routes/api.php:2052`) sits inside `Route::middleware('permission:dispatch.propose')->group(...)`. The line was read in isolation without its enclosing group.
- **"Loading has no authorization."** False. Every `VehicleAssignmentController` method authorizes — `view`, `create`, `operate`, and `dispatch` — and session lookups are company-scoped via `findSession($sessionId, $request->user()->company_id)`.

---

## 22. ID Type Matrix

| Entity | Current table | ID type | Current owner | Consumers |
|---|---|---|---|---|
| **Vehicle** | `logistics_vehicles` | `id` **bigint unsigned** (PRI) + `uuid` char(36) (UNI, nullable, auto-populated) | `Modules\Logistics\Vehicles` (`logistics.vehicles.*`) | Fleet (FK), pairing ledger (FK), Dispatch ×2 (FK), Loading (char(36), **no FK**) |
| **Driver** | `logistics_drivers` | `id` **bigint unsigned** — **no uuid, no company_id, no warehouse_id** | `Modules\Logistics\Drivers` (`logistics.drivers.*`) | pairing ledger (FK), Dispatch ×2 (FK), Loading (char(36), **cannot resolve**) |
| **Assignment (pairing)** | `logistics_driver_vehicle_assignments` | `id` **bigint** | `Modules\Logistics\Drivers` | Distribution Trip (FK), Dispatch release |
| Trip | `distribution_trips` | `id` bigint | `Modules\Logistics\Distribution` | Dispatch (FK), Group link (`virtual_slot_id` uuid FK) |
| Group | `distribution_virtual_slots` | `id` **uuid** | `Modules\Logistics\Distribution` | Trip |
| Loading assignment | `vehicle_assignments` | `id` **char(36)**; `vehicle_id` char(36) **no FK** | `Modules\Operations\Loading` | Loading only |
| Loading driver assignment | `driver_assignments` | `id` **char(36)**; `driver_id` char(36) **no FK** | `Modules\Operations\Loading` | Loading only |

### Which representation is authoritative

**BIGINT is authoritative for identity and for every enforced foreign key.** This is not a preference — it is the only representation the database enforces. Six FK constraints bind Dispatch to `logistics_vehicles.id` / `logistics_drivers.id` / `distribution_trips.id`; under MySQL an FK requires matching column types, so those constraints are physical proof. Two further polymorphic `unsignedBigInteger resource_id` columns carry vehicle/driver/trip ids, one of them backing `dispatch_lock_one_live_unique` — the guard preventing two dispatchers from holding the same van. Published cross-module seams are typed `int`.

**UUID is authoritative for cross-module reference**, per `FOREIGN-KEY-STANDARDS.md:14` and the `logistics_vehicles.uuid` column built for exactly that purpose.

### The resolver, stated explicitly

```
SOURCE ID        Operations vehicle_id   char(36)   (client-supplied today)
      │
   RESOLVER      Vehicle::where('uuid', $vehicleId)->firstOrFail()
      │          — through the Eloquent model, so the tenant scope applies
      ▼
CANONICAL ID     logistics_vehicles.id   bigint     (identity + all FKs)
```

**Why this does not create a second source of truth.** `id` and `uuid` are two addresses of **one row in one table**. `uuid` is unique-indexed and generated at insert by `Vehicle::booted()`, so no value can exist that does not belong to exactly one vehicle, and no module other than `Modules\Logistics\Vehicles` can create a vehicle at all. Two sources of truth would require two records able to disagree; here there is one record and one authority. The resolver is a lookup, not a registry — which is precisely why option D1-D (a uuid↔bigint mapping table) was rejected: *that* would create a second record capable of disagreeing.

---

## 23. Impact on Group

**Effectively none.** The Group is upstream of every decision here.

- `distribution_virtual_slots` is untouched by D1, D2 and D3.
- D4 ratifies `capacity_orders` as its only enforced constraint — already true at `GroupFinalizationService:128`.
- The only new behaviour is a comparison **at vehicle assignment**, which happens on the Trip, not on the Group.
- Group→Trip (`distribution_trips.virtual_slot_id`) is already implemented and verified.
- **Recommended housekeeping:** formally declare `capacity_stops`, `capacity_weight_kg`, `capacity_volume_m3` dormant.

---

## 24. Impact on Loading

Loading absorbs the most change, all of it additive at the boundary — no lifecycle, table or workflow redesign.

1. **Vehicle/driver references become resolved, not asserted.** `AssignVehicleToSessionAction` resolves through the registry and populates registration, type and capacities from it, instead of trusting client snapshots.
2. **Validation gains existence + tenancy.** `exists:` for shape; **model resolution** for tenancy. `exists:` alone is not a tenant guard.
3. **The pairing becomes derived** (D3): Loading consumes the ledger rather than minting `vehicle_assignments` + `driver_assignments` independently. The linking mechanism is deliberately left to VP-1B.
4. **The driver path is unblocked** only after D2 — today `driver_id char(36)` cannot resolve to a bigint driver by any column.
5. **Behaviour is unchanged downstream.** `LoadVehicleWorkflow` uses `vehicle_id` at exactly one site (line 120, an event payload) and never in a query, join, authorization or capacity check. No shipping quantity, FIFO layer, COGS stamp, order status transition or transaction boundary moves.
   **One precision:** the choice *is* behaviour-affecting at the **HTTP validation boundary** — `['required','uuid']` rejects a bigint with a 422. Under D1-C that rule is unchanged, so this cost is avoided; it would apply under D1-B.
6. **`vehicle_plan_slot_id`** remains in `AssignVehicleRequest` as removed-VVP residue and should be dropped separately.

---

## 25. Impact on Dispatch

**No architectural change.** Dispatch is already bigint-consistent, already references the canonical entities, and already writes through the ledger. D1, D3 and D4 require nothing of it.

- D2 **unblocks** it: `DispatchVehicleAction` hard-throws without an active driver assignment, which cannot exist until a driver reference resolves.
- Dispatch's capacity model already matches D4 exactly — `Vehicle->capacity_orders` against `Trip->capacity`, weight and volume never read.
- **Separately (security, not architecture):** the by-uuid resolvers at `DispatchController:241/246/251` need a company predicate, and the two `exists:`-without-re-resolution sites need model resolution. Neither is caused by these decisions.
- **Unchanged and re-affirmed:** dispatch is the sole inventory-mutation boundary, and `LoadVehicleWorkflow` has no test coverage.

---

## 26. Minimal Implementation Sequence

**Not to be executed.** Presented for approval only.

### VP-1A — Canonical identity + resolver
- Add `uuid` (char(36), nullable, unique) and `company_id` (uuid, nullable, indexed) to `logistics_drivers`; add a `creating()` hook mirroring `Vehicle::booted()`; add a `Driver` global tenant scope mirroring `Vehicle`'s.
- Add the vehicle/driver resolver at the Operations boundary (`uuid → model → bigint`).
- **One migration, two additive nullable columns, zero rows, no PK change, no backfill.**

### VP-1B — Secure assignment authority
- Define how Loading's `vehicle_assignments` references the pairing ledger (**design decision, not settled here**).
- Apply S-1…S-6: model resolution on both axes, company cross-check on the stamped `company_id`, company predicate on Dispatch's by-uuid resolvers.
- Decide how the ledger's two global uniqueness indexes are re-expressed per tenant.

### VP-1C — Vehicle capacity validation
- Enforce `Group order count ≤ Vehicle.capacity_orders` at assignment.
- Declare Group `capacity_stops` / `capacity_weight_kg` / `capacity_volume_m3` dormant; rule on `VehicleCapacityValidatorService`.

### VP-1D — Loading / Dispatch consumption
- Loading resolves vehicle/driver from the registry; snapshots become registry-sourced.
- Dispatch requires no change.

### Then
Focused tests on the resolver, the tenant guard and the capacity rule; browser verification of the assignment flow.

**Sequencing constraint:** VP-1A must precede VP-1B, and VP-1B must precede VP-1D. VP-1C is independent and may run in parallel.

---

## 27. Risks

| # | Risk | Mitigation |
|---|---|---|
| R-1 | The documentary conflict (§4g) is left unresolved and a future task re-litigates the key | Record the D1 ruling **in writing** and formally supersede the losing side |
| R-2 | `exists:` is used as the tenant guard | S-1/S-2 state the mechanism explicitly; the failure mode is already live in two Dispatch sites |
| R-3 | The ledger's global uniqueness indexes leak other tenants' pairings via constraint violations once drivers are scoped | Re-express per tenant in VP-1B |
| R-4 | `VehicleCapacityValidatorService` is wired later, silently making client-supplied snapshots enforced ceilings — a 0 snapshot rejects every load | Rule on it in VP-1C |
| R-5 | The `Vehicle` scope's `company_id IS NULL` shared-fleet allowance is treated as a bug and closed, breaking seeded/console fleets | Ruling should state that the allowance is deliberate |
| R-6 | `LoadVehicleWorkflow` — the inventory-mutating path — has **no test coverage**, and dispatch is irreversible without a compensating transaction | Do not exercise dispatch on real data for verification |
| R-7 | Zero rows today makes every option look cheap; the same decisions become expensive once fleets are loaded | Decide before go-live data entry |
| R-8 | Loading's `refrigerated` flag has no source column | Covered by D4's second ruling item |

---

## 28. GO-LIVE Classification

| Item | Classification | Rationale |
|---|---|---|
| D1 — Vehicle identity | **BLOCKER** | Loading/Dispatch integration cannot proceed; zero migration cost |
| D2 — Driver identity / tenancy | **BLOCKER** | Dispatch cannot run; a live cross-tenant write exists |
| D3 — Assignment authority | **BLOCKER** | Integrating Loading first would make the duplicate permanent |
| D4 — Capacity contract | **NO BLOCKER** | Ratifies shipped behaviour; one additive validation |
| S-2 driver tenancy gap | **BLOCKER** (security) | Same root cause as D2 |
| `DriverController:281` cross-tenant write | **BLOCKER** (security) | Live, exploitable with a legitimate permission |
| Dispatch by-uuid resolvers unscoped | **HIGH-RISK FOLLOW-UP** | Pre-existing; permission-gated, so exploitation needs a dispatch role |
| Dispatch `exists:`-without-re-resolution ×2 | **HIGH-RISK FOLLOW-UP** | Pre-existing; same gating |
| Loading accepts any well-formed uuid | **HIGH-RISK FOLLOW-UP** | Closed by D1 + VP-1B |
| Ledger uniqueness indexes are global | **GO-LIVE FOLLOW-UP** | Only bites once drivers are tenant-scoped |
| `VehicleCapacityValidatorService` dead code | **GO-LIVE FOLLOW-UP** | Inert today; dangerous only if wired |
| Group dormant capacity columns | **GO-LIVE FOLLOW-UP** | Documentation ruling |
| `vehicle_plan_slot_id` VVP residue | **GO-LIVE FOLLOW-UP** | Cosmetic |
| `LoadVehicleWorkflow` untested | **HIGH-RISK FOLLOW-UP** | Pre-existing; irreversible path |

---

## 29. STOP Conditions

| # | Condition | Status |
|---|---|---|
| 1 | Evidence insufficient to decide D1 | **No** — decided on live schema, routes, permissions and FK topology |
| 2 | Evidence insufficient to decide D2 | **No** for the invariant and the owner; **the tenant column is a schema decision only the owner may approve** |
| 3 | Evidence insufficient to decide D3 | **No** — Distribution's migration states the rule verbatim and three modules already follow it |
| 4 | Evidence insufficient to decide D4 | **No** — enforcement sites counted directly |
| **5** | **A decision requires a schema change** | **TRIGGERED — D2.** Two additive nullable columns on an empty table; forbidden by this task |
| **6** | **A decision changes a certified contract** | **TRIGGERED — D3.** Loading's pairing becomes derived rather than authored |
| 7 | A decision requires business-data migration | **No** — every affected table is at 0 rows |
| 8 | More than one Vehicle source of truth | **No** — one implemented; the `vehicles` table specified in approved docs was never created |
| 9 | A decision requires inventing a business rule | **No** — D4 ratifies existing behaviour; no weight/volume rule is invented |
| **10** | **A live security defect is found** | **TRIGGERED** — `DriverController:281`, and drivers untenanted platform-wide. **Reported, not fixed** |

**Three triggered. All three are owner decisions; none was worked around.**

---

## 30. Final Owner Decisions

| Decision | Recommended Choice | Requires Owner Approval | Migration | Schema Change | API Change | Permission Change | Business Data Change | Classification |
|----------|--------------------|--------------------------|-----------|---------------|------------|-------------------|----------------------|----------------|
| **D1 — Vehicle Identity** | **D1-C** — `logistics_vehicles` canonical (owner: `Modules\Logistics\Vehicles`); Operations keeps its uuid contract and resolves `vehicle_id → logistics_vehicles.uuid` | **YES** | No | No | No (additive only) | No | No | **BLOCKER** |
| **D2 — Driver Identity / Tenancy** | **D2-A** — add `company_id` + `uuid` to `logistics_drivers` and a `Driver` global tenant scope; a driver belongs to exactly one company, may cross warehouses, may never cross companies | **YES** | **YES** (1, additive) | **YES** (2 nullable columns; no PK/type change) | No (reads become tenant-filtered) | No | No (0 rows) | **BLOCKER** |
| **D3 — Assignment Authority** | **D3-D** — `logistics_driver_vehicle_assignments` is the single pairing ledger; Distribution owns the Trip and consumes it; Dispatch proposes into it; **Loading consumes rather than owns** | **YES** | Deferred to VP-1B | Deferred to VP-1B | **YES** (prospective, Loading only) | No | No (0 rows) | **BLOCKER** |
| **D4 — Capacity Contract** | **D4-C** — enforce `Group order count ≤ Vehicle.capacity_orders` at assignment; Group semantics unchanged; no weight, no volume, no dimension engine | **YES** (ratification) | No | No | No (422 on over-capacity) | No | No | **NO BLOCKER** |

---

**STOP. Awaiting explicit owner approval of D1–D4.**

Nothing was implemented. No code, migration, schema, API, frontend, permission, or business-data change; no vehicles, drivers, or assignments created; no Loading, Dispatch, Group or Vehicle Planning modification; no tests added or modified; no commit. Database access was `SELECT` / `information_schema` only.

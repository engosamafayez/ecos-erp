# TASK-OPERATIONS-VEHICLE-VP1-CANONICAL-KEY-AND-CAPACITY-DECISION-001 — FINAL REPORT

**Date:** 2026-08-21
**Status:** FINAL ARCHITECTURE DECISION — **READ ONLY.** No implementation, no migration, no schema, no backend or frontend change, no data, no users, no vehicles, no drivers, no trips, no inventory, no commit. Database access was `SELECT` only.

---

## 1. Executive Summary

**VP-1 is smaller than its name and larger than its file.** Smaller, because most of what it describes has dissolved. Larger, because the real conflict is not one stale document — it is an entire approved standards layer pointing the opposite way from everything that shipped.

Four findings reframe the decision before any option is weighed:

### 1.1 Most of VP-1's original scope is now moot

VP-1 was raised as *"Vehicle **Planning** schema/architecture incompatibility"*, and its own decision entry is **D-E, "`vehicle_plan*` key strategy (VP-1)"**. `vehicle_plan*` **is the permanently removed Virtual Vehicle Planning**, at 0 rows. Six of the eight rows in VP-1's own incompatibility table are `vehicle_plan*` columns.

What survives is the report's own **"compounding finding"**: the live Loading OS is decoupled from the fleet registry. That is the only part still load-bearing.

VP-1's parent report also **deliberately declines to choose** — D-E's recommendation column reads *"defer to Part 4 — this audit deliberately does not choose"*. So VP-1 supplies the option set and the constraints. It supplies no preference.

### 1.2 VP-1's remaining option is already half-built — and the report did not know

VP-1's option (b) was *"introduce a uuid identity on the fleet tables"*. **That already exists for vehicles.** `logistics_vehicles.uuid` was added **2026-07-25**, unique-indexed, and auto-populated in `Vehicle::booted()`. Its migration states the purpose verbatim:

> `// UUID-ready: external systems and future modules reference the stable uuid while existing bigint foreign keys keep working.`

The VP-1 report is dated **2026-08-21 — today**, a month later, and still lists this as unexplored. `Operations\Loading` is precisely a *"future module"* referencing the fleet.

### 1.3 Loading's uuid columns are not drift — they are an approved standard, faithfully executed

This is the finding that changes the shape of the decision. Five approved documents mandate exactly what Loading did — four of them dated 2026-07-05, and one of them (`docs/loading-os/DATABASE-DESIGN.md`) carrying **engineering**, not merely architectural, authority:

| Document | Line | Verbatim |
|---|---|---|
| `docs/engineering/FOREIGN-KEY-STANDARDS.md` | **:14** | *"Cross-domain references use UUID columns without FK constraints — module independence is preserved at the database level."* |
| `docs/data/IDENTITY-STRATEGY.md` | **:96** (ID-004) | *"IDs are always generated application-side; never auto-increment or sequence at DB level"* |
| `docs/data/IDENTITY-STRATEGY.md` | **:99** (ID-007) | *"Cross-domain references use the referenced entity's UUID; no business number references across domains"* |
| `docs/data/LOGICAL-KEYS.md` | **:27** | *"All PKs are `id UUID` \| No auto-increment integers; no composite PKs as the primary"* |
| `docs/data/LOGICAL-KEYS.md` | **:49** | catalogs a table literally named **`vehicles`** — not `logistics_vehicles` |

So `vehicle_assignments.vehicle_id uuid` **with no FK** is not an accident to be cleaned up. It is compliance. The defect is that its referent — a `vehicles` table — was never built.

**This inverts the burden of proof.** Ratifying bigint is not "confirming the status quo"; it requires overturning approved documents in writing.

**One qualification, so this is not overstated.** Loading complied with a design whose *counterpart* was never built to the same standard: the referenced entity is `logistics_vehicles`, a MySQL bigint auto-increment table, not a `vehicles` table with a uuid PK. So Loading's column is **conformant and joinable but never joined** — and the standards themselves target PostgreSQL 16+ (§10.1). They are one side of an unresolved two-standard conflict, not a trump card.

### 1.4 The technical conflict is at the validation layer, not the schema layer

`vehicle_assignments.vehicle_id char(36)` looks like a uuid requirement. It is not one. The same table's `created_by`/`updated_by` are also `char(36)` and already receive a **stringified bigint** — `VehicleAssignmentController:57` passes `actorId: (string) $request->user()->id` against a `users.id` of type bigint.

`char(36)` in Loading is an **opaque identifier string**. Uuid-ness is imposed by exactly two FormRequest rules:

```php
AssignVehicleRequest:  'vehicle_id' => ['required', 'uuid'],
AssignDriverRequest:   'driver_id'  => ['required', 'uuid'],
```

### 1.5 What actually remains

| Half | Status |
|---|---|
| **Vehicle** | **Resolvable with NO migration** — pass `logistics_vehicles.uuid`, which exists, is unique, and is auto-populated |
| **Driver** | **Blocked** — `logistics_drivers` has **no uuid column and no uuid handling**, so no canonical value can satisfy `driver_assignments.driver_id char(36)`. `DispatchVehicleAction` hard-requires an active driver assignment, so Dispatch cannot proceed without this |
| **Capacity** | The one **approved** dimension (`capacity_orders`) is `NOT NULL DEFAULT 60` at source. The two dimensions Loading demands are **nullable** at source and **NOT NULL** in Loading; `refrigerated` has no source column at all |
| **Tenancy** | Orthogonal to the key, and worse than the key. Vehicles are guarded by an Eloquent scope Loading never invokes; **drivers are guarded by nothing at all** |

### 1.6 The verdict, and why it is not "RESOLVED"

The engineering path is clear and small. But **four** things remain that only the owner can decide, and this task forbids me from deciding any of them:

1. **An entire approved standards layer contradicts the implemented schema** (§10) — not one stale spec. Only the owner can rule which governs, and whichever loses must be superseded *in writing*.
2. **Vehicle ownership is contradictory across three approved documents** (§10.4) — a key decision alone will drift again without an ownership ruling.
3. **The driver half needs a migration** — minimal and additive, but this task forbids migrations.
4. **The null weight/volume capacity mapping** (§15), including a `refrigerated` dimension that has no source.

Everything is pre-costed below so the owner can approve in a single step.

# **VP-1 BLOCKED — OWNER DECISION REQUIRED**

---

## 2. Current Vehicle Entities

| Entity | Table | Key | Verdict |
|---|---|---|---|
| **`Modules\Logistics\Vehicles\Domain\Models\Vehicle`** | `logistics_vehicles` | `id` **bigint**, plus `uuid` char(36) nullable unique | **CANONICAL** |
| `Modules\Logistics\Fleet\Domain\Models\FleetUnit` | `fleet_units` | `id` bigint, `vehicle_id` **unique FK → logistics_vehicles** | **Not a vehicle.** Its migration: *"the operational shadow of exactly one V1 vehicle… holds CONDITION, never IDENTITY"* |
| `vehicle_plan_slots.vehicle_id` | — | uuid | **Removed Virtual Vehicle Planning.** 0 rows, forbidden to revive |
| `Operations\Distribution` `FleetVehicle` | — | — | **Forbidden duplicate.** Module has no ServiceProvider; migrations never ran |
| `vehicle_assignments.vehicle_id` | `vehicle_assignments` | char(36), **no FK** | **Not an entity** — an execution record referencing nothing enforceable |
| `vehicles` | — | uuid (per `LOGICAL-KEYS.md:49`) | **Specified but never created.** `Schema::create('vehicles')` appears in **zero** PHP files repo-wide |

`logistics_vehicles` columns relevant here:

```
id                 bigint unsigned   NOT NULL  PK auto_increment
uuid               char(36)          NULL      UNIQUE   ← auto-populated in Vehicle::booted()
company_id         char(36)          NULL      no FK to companies
branch_id          char(36)          NULL
shipping_company_id bigint unsigned  NULL
capacity_orders    smallint unsigned NOT NULL  DEFAULT 60
capacity_weight_kg decimal(10,2)     NULL
capacity_volume_m3 decimal(10,3)     NULL
status             varchar(20)       NOT NULL  DEFAULT 'available'
```

**There is exactly one *implemented* source of truth for vehicle identity, so STOP condition #6 does not trigger.** The `vehicles` table is specified in an approved document but does not exist, which is a documentation conflict (§10), not a competing implementation.

---

## 3. Current Driver Entities

| Entity | Table | Key | Verdict |
|---|---|---|---|
| **`Modules\Logistics\Drivers\Domain\Models\Driver`** | `logistics_drivers` | `id` **bigint** | **CANONICAL** |
| `driver_assignments.driver_id` | `driver_assignments` | char(36), **no FK** | execution record referencing nothing enforceable |

```
logistics_drivers: id, driver_code, user_id (bigint), full_name, mobile, national_id,
                   date_of_birth, address, employment_date, shipping_company_id (bigint),
                   licence fields…, status, notes, timestamps
```

**Three absences decide the driver half:**

- **NO `uuid` column** — and no `uuid`/`HasUuids`/`booted()` handling in the `Driver` model (verified by grep: zero hits for `addGlobalScope` or `booted` in `Driver.php`).
- **NO `company_id`** — see §17; the tenant path is worse than previously recorded.
- **NO global scope** — `Vehicle` has one; `Driver` has none.

---

## 4. Current Assignment

**`logistics_driver_vehicle_assignments` is the canonical pairing** and is already consumed correctly by Trip:

```php
// TripController::assignDriverVehicle
'driver_vehicle_assignment_id' => ['required', 'integer', 'exists:logistics_driver_vehicle_assignments,id'],
```

`DispatchReleaseService::releaseOne()` states the ownership in its own comments — *"Drivers (LOG-002) owns the pairing"*, then *"Distribution (LOG-004B) owns the trip"*. The DB invariant *one driver ↔ one vehicle* is enforced by unique indexes on `(driver_id, active_flag)` and `(vehicle_id, active_flag)`. Both FKs are **bigint**.

**Caveat for §17:** this table carries **no tenant column at all**, and those two unique indexes are therefore **global, not per-tenant**.

`Operations\Loading` maintains a **second, parallel pairing** — `vehicle_assignments` + `driver_assignments`, both char(36), both FK-less — that never references the canonical one.

---

## 5. Current Trip

`distribution_trips.driver_vehicle_assignment_id` is **bigint, FK to the canonical pairing**. Trip is already correct and needs nothing from this decision.

As of the previous workstream, `distribution_trips.virtual_slot_id` (char(36), FK) links a Trip to its Group. **Group → Trip → pairing → Vehicle + Driver is complete and verified end-to-end with no VP-1 involvement** — because that chain is uuid→uuid on one side and bigint→bigint on the other, never crossing.

---

## 6. Current Loading Contract

```php
// AssignVehicleRequest
'vehicle_id'           => ['required', 'uuid'],
'vehicle_registration' => ['required', 'string', 'max:50'],
'vehicle_type'         => ['required', 'string', 'max:50'],
'capacity_weight_kg'   => ['required', 'numeric', 'min:0'],
'capacity_volume_m3'   => ['required', 'numeric', 'min:0'],
'vehicle_plan_slot_id' => ['nullable', 'uuid'],          // ← removed VVP residue

// AssignDriverRequest
'driver_id'   => ['required', 'uuid'],
'driver_name' => ['required', 'string', 'max:255'],
```

`AssignVehicleToSessionAction` **never looks the vehicle up**. Registration, type and both capacities are **client-supplied snapshots**. There is no FK, no `exists:`, and no ownership or tenant check on either the vehicle or the driver.

**The decoupling is total and verifiable.** Across all of `Modules/Operations`:

```
grep -rn "use Modules\Logistics" Modules/Operations/ --include=*.php   →  0
grep -rn "Vehicle::"             Modules/Operations/ --include=*.php   →  0   (excluding Loading's own Vehicle* classes)
```

Loading has never once dereferenced a vehicle.

---

## 7. Current Dispatch Contract

Dispatch is **already bigint throughout, and welded to it by database constraints** — Operations/Loading is the sole outlier.

| Table | Column | Type |
|---|---|---|
| `dispatch_queue_items` | `trip_id` | bigint FK → `distribution_trips` |
| `dispatch_proposed_assignments` | `trip_id`, `driver_id`, `vehicle_id` | bigint FKs |
| `dispatch_resource_allocations` | `trip_id`, `driver_id`, `vehicle_id` | bigint FKs |
| `dispatch_assignment_locks` | `resource_id` | **`unsignedBigInteger` NOT NULL** (:112), polymorphic over `('vehicle','driver','trip')` |
| `dispatch_conflicts` | `resource_id` | `unsignedBigInteger` nullable (:93) |

`DispatchReleaseService::releaseOne()` pairs driver+vehicle through the canonical assignment and attaches it to a **Trip**.

Separately, `Operations\Loading`'s own dispatch — `POST …/assignments/{a}/dispatch` → `DispatchVehicleAction` → `LoadVehicleWorkflow` — is the path that actually mutates inventory. **It hard-requires an active driver assignment:**

```php
$driverAssignment = $assignment->driverAssignment()
    ->where('status', DriverAssignmentStatus::Assigned->value)   // DispatchVehicleAction:34-35
    ->first();
if ($driverAssignment === null) {
    throw new RuntimeException("…no active driver assignment found.");
}
```

**This is why the driver half is load-bearing: Dispatch cannot run without it.**

---

## 8. UUID / BIGINT Conflict

**The conflict is at the validation layer, not the schema layer.**

| Layer | Vehicle | Driver |
|---|---|---|
| Canonical identity | `logistics_vehicles.id` **bigint** | `logistics_drivers.id` **bigint** |
| Cross-module reference | `logistics_vehicles.uuid` — **exists**, unique, auto-populated | **DOES NOT EXIST** |
| Loading column | `char(36)` — accepts a stringified bigint (proven by `created_by`) | `char(36)` — same |
| Loading validation | `['required','uuid']` — **rejects a bigint** | `['required','uuid']` — **rejects a bigint** |
| Referential integrity | none — no FK, no `exists:` | none |
| Tenant integrity | none on the Loading path (§17) | **none anywhere** (§17) |

The platform already operates a deliberate two-key model, stated in the vehicle migration itself: **bigint for identity and intra-Logistics foreign keys; uuid as the stable cross-module reference.** Every DB-enforced vehicle FK in the repo (`fleet_units`, `logistics_driver_vehicle_assignments`, both Dispatch tables) points at `logistics_vehicles.id`.

**Dispatch demonstrates the same pattern on itself:** every `dispatch_*` table has a bigint `id()` **plus** a unique `uuid` column that the HTTP layer exposes as the public `id`. Controllers resolve by `where('uuid', $id)`; resources emit `'id' => $this->uuid`. Yet those same resources emit `'vehicle_id' => $assignment->vehicle_id` as the **raw bigint**.

**The house pattern is settled: uuid for external identity, bigint for referential keys.** Loading is the one consumer that was meant to use the uuid and never did.

---

## 9. Capacity Conflict

| Dimension | Loading demands | Canonical source | Gap |
|---|---|---|---|
| **Orders** | *(not requested)* | `logistics_vehicles.capacity_orders` **NOT NULL, default 60** | none — the **one approved dimension** is always available |
| Weight kg | `capacity_weight_kg_snapshot` **NOT NULL** decimal(18,4) | `logistics_vehicles.capacity_weight_kg` decimal(10,2) **NULLABLE** | a null source cannot fill a NOT NULL target |
| Volume m³ | `capacity_volume_m3_snapshot` **NOT NULL** decimal(18,4) | `logistics_vehicles.capacity_volume_m3` decimal(10,3) **NULLABLE** | same |
| Refrigerated | `refrigerated` boolean | *(no column on `logistics_vehicles`)* | **absent at source** |

### 9.1 The mitigating fact — these snapshots gate nothing

`VehicleCapacityValidatorService` is the only reader that makes a decision from them:

```php
$maxWeight = $assignment->capacity_weight_kg_snapshot;
$maxVolume = $assignment->capacity_volume_m3_snapshot;
```

**It is registered as a singleton in `LoadingServiceProvider` and never called anywhere** (verified: the only two references in the entire `Modules/` tree are the provider's `use` statement and its `singleton()` binding). No other code consults the snapshots — `LoadProductAction` reads neither. They are **write-only bookkeeping today**.

So a NULL→0 mapping would be inert *now*. But it becomes a hard blocker the moment anyone wires that validator, because a 0 ceiling rejects every load. **Writing 0 to mean "unknown" is a business-rule invention, and this task forbids inventing one** — hence STOP #7 is recorded rather than worked around.

### 9.2 Dispatch confirms the approved model — order counts only

Dispatch's entire capacity model is integer order counts: `Vehicle->capacity_orders` (supply) compared against `Trip->capacity` (demand). **Dispatch never reads `capacity_weight_kg` or `capacity_volume_m3` at all.** This is independent corroboration that `capacity_orders` is the approved dimension and weight/volume are not part of the operational model.

### 9.3 No new dimension is proposed

Nothing here introduces weight, volume or refrigeration as an enforced rule. The question is purely how to populate two NOT NULL columns that already exist and are never read.

---

## 10. ADR Compatibility — **this is the hard blocker, and it is wider than one document**

### 10.1 The UUID side — approved, and it explains Loading

| Document | Status (verbatim) | Date | Bearing |
|---|---|---|---|
| `docs/engineering/FOREIGN-KEY-STANDARDS.md` | **APPROVED — Architecture Only** | 2026-07-05 | :14 mandates uuid cross-domain refs **without FK constraints** |
| `docs/data/IDENTITY-STRATEGY.md` | **APPROVED — Architecture Only** | 2026-07-05 | ID-004 forbids auto-increment; ID-007 mandates the referenced entity's uuid cross-domain |
| `docs/data/LOGICAL-KEYS.md` | **APPROVED — Architecture Only** | 2026-07-05 | :27 all PKs `id UUID`, "No auto-increment integers"; :49 catalogs a `vehicles` table |
| `docs/architecture/VEHICLE-ARCHITECTURE-SPEC.md` | **APPROVED — Architecture Only** | 2026-07-04 | specifies `Vehicle.id uuid` |
| `docs/loading-os/DATABASE-DESIGN.md` | **APPROVED — Engineering Design Phase** | — | **:280 / :471** — `vehicle_id: UUID — cross-domain ref to vehicles (no FK)` |

The last row is the strongest: it is the **only one of the five that carries implementation authority** rather than "Architecture Only", and it specifies Loading's column exactly as built.

Read together, Loading's FK-less uuid `vehicle_id` is **exactly what these documents instruct**. That is the single most important correction in this report: it is not drift.

**Three caveats that weaken the UUID side:**
- **Three of the five are "Architecture Only"** — they do not claim implementation authority. (`docs/loading-os/DATABASE-DESIGN.md` is the exception, and it is the one that authorized migrations.)
- **They target the wrong database.** The parent `docs/engineering/DATABASE-ENGINEERING-STANDARDS.md:25` reads `| Primary Database | PostgreSQL | 16+ |`, and the child docs assume native PostgreSQL UUID storage. The live database is **MySQL 8.4** (`backend/.env` `DB_CONNECTION=mysql`; `docker-compose.yml` `image: mysql:8.4`) — and MySQL was already operational when the standards were written. A `char(36)` uuid in MySQL is not the 16-byte native type these documents cost their advice against.
- `docs/06_Database_Standards.md` — the file whose name implies it is *the* standard — is **0 bytes, empty** (verified by byte count).

**Net:** the standards layer is real and approved, but it is **one side of an unresolved two-standard conflict**, not a trump card. It cannot by itself close the `vehicle_id` question.

### 10.2 The BIGINT side — implemented, enforced, but documented only in an unapproved doc set

- `logistics_vehicles` is `$table->id()` (bigint), created LOG-002 2026-07-24, extended LOG-003 2026-07-25.
- **Six FK constraints** bind Dispatch to `logistics_vehicles.id` / `logistics_drivers.id` / `distribution_trips.id`. Under MySQL an FK requires matching column types — these are physical proof.
- Two **polymorphic `unsignedBigInteger resource_id`** columns hold vehicle/driver/trip ids, and one of them backs `dispatch_lock_one_live_unique` on `(resource_type, resource_id, active_flag)` — the mutual-exclusion guard preventing two dispatchers from holding the same van.
- Typed cross-module seams are `int`: `FleetReadinessQueryInterface::verdictFor(int $vehicleId)`, `ResourceAllocationService::allocate(…, int $vehicleId, int $driverId, …)`.
- Its documentary backing (`docs/logistics-v2/`) carries README status *"Architecture & Design — awaiting CTO Architecture Review"* and *"Design only. No implementation, no migrations, no code"* — **while the migrations demonstrably exist.** That doc set cannot be cited as authority.

### 10.3 The spec that describes an entity nobody built

`docs/architecture/VEHICLE-ARCHITECTURE-SPEC.md`, v1.0, **APPROVED**, 2026-07-04, ADR-015:

```
Vehicle
├── id                   uuid                 ← implementation uses bigint
├── company_id           → Company
├── warehouse_id         → Warehouse          ← implementation has NO warehouse_id
├── capacity_weight_kg   decimal(10,2)
├── capacity_volume_m3   decimal(10,4)        ← implementation uses (10,3)
├── refrigerated         bool                 ← implementation has NO such column
├── assigned_driver_id   → User (nullable)    ← implementation uses a pairing table
└── status               enum available|loading|in_transit|returning|maintenance|inactive
```

**`Schema::create('vehicles')` appears in zero PHP files.** The entity this APPROVED spec describes does not exist. It is the probable origin of Loading's uuid columns and of the `refrigerated` field Loading requires.

*Note on scope:* the spec's **"Mobile Warehouse" principle WAS implemented** — `vehicle_inventory_items` / `vehicle_inventory_movements` do exactly what it describes. It is the *entity shape and key*, not the concept, that diverged.

### 10.4 Ownership is contradictory across three approved documents

A key decision alone will drift again, because nothing settles who owns the Vehicle:

| Document | Status (literal) | Line | Assigns vehicle ownership to |
|---|---|---|---|
| `docs/architecture/ADR-015-enterprise-fulfillment-architecture.md` | `APPROVED — CRITICAL` | **:175** | *"**Owner:** Loading & Allocation OS Module (vehicle lifecycle) / Logistics OS (route execution)"* — itself a two-way split |
| `docs/contracts/BOUNDARY-CONTEXT-MAP.md` | `APPROVED — Architecture Only` | **:122** | **Logistics** — Vehicle, VehicleInventory **and** LoadingSession aggregates |
| `docs/architecture/VEHICLE-ARCHITECTURE-SPEC.md` | `APPROVED — Architecture Only` | **:322-330** | **`Modules/Operations/Vehicles`** |

**`ADR-INV-002` is NOT a fourth claimant.** It was worth checking and it does not qualify: its literal status token is `Accepted` (not `APPROVED`), it names **the same owner as ADR-015** (Loading OS), and it conflicts on *representation* — whether a vehicle is modelled as a warehouse-typed stock location — not on ownership. Its normative rule is **:36** (*"Only `Loading OS` actions may transfer stock `into` a `vehicle` warehouse"*), not the Benefits bullet at :49; and **:42** disclaims enforcement outright: *"None required — policy is enforced in application-layer Actions. The database column is a label, not a constraint."*

It is nonetheless **100% unimplemented**: `grep -rn "warehouse_type" backend/` returns zero, and the `warehouses` create migration never defined the column.

### 10.5 What this means

**STOP condition #2 is triggered, and more strongly than a single stale document would justify.** Two approved bodies of work contradict each other; neither supersedes the other in writing; and the current state — where the standards stay APPROVED while the schema says otherwise — is precisely what produced this ambiguity and three prior stopped tasks. Only the owner can rule, and the ruling must be written down.

---

## 11. Options

| | Option | What it means | Migration | Verdict |
|---|---|---|---|---|
| **A** | **Bigint identity + uuid cross-module reference** | Loading passes `logistics_vehicles.uuid` / a new `logistics_drivers.uuid`; validation stays `uuid`; identity and every intra-Logistics FK stay bigint | **Vehicle: none. Driver: one minimal additive column** | **RECOMMENDED** |
| **B** | **Bigint end-to-end, including Loading** | Relax both rules to `['required','integer']`; store the bigint in the existing `char(36)` columns | none to function; 9 column-type migrations to be honest | Viable, second choice |
| **C** | Existing compatibility layer | Keep client-supplied snapshots; never reference the registry | none | **REJECTED** |
| **D** | Mapping table | uuid ↔ bigint translation table | new table | **REJECTED** |
| **E** | **True UUID-canonical** — flip `logistics_vehicles.id` to uuid | Satisfies the approved standards literally | catastrophic — see below | **REJECTED** |

### Option A in detail — **note it does NOT flip the canonical key**

This is the critical distinction. Option A keeps bigint as the identity and changes nothing about the identity's role. It only changes *which existing column* Loading is handed.

- **Vehicle: works today.** `logistics_vehicles.uuid` is unique and auto-populated in `Vehicle::booted()`. Loading's existing `['required','uuid']` rule passes unchanged.
- **Driver: needs one column.** `ALTER logistics_drivers ADD uuid CHAR(36) NULL UNIQUE` plus a `creating()` hook mirroring `Vehicle`. **Zero rows live**, so no backfill and no data conversion.
- **Honours both sides.** It satisfies `FOREIGN-KEY-STANDARDS.md:14` (cross-domain reference by uuid, no FK) *and* leaves every bigint FK in Fleet and Dispatch untouched. It is the only option that does not require overturning a document on the cross-domain rule.
- **Costs Dispatch nothing** — Dispatch is not touched at all.

### Option B in detail

- **No migration required to function.** The `char(36)` columns already hold stringified bigints for actors (`created_by`/`updated_by`).
- **But** `$table->uuid('vehicle_id')` is declared across **eight Loading migrations, plus one in `Operations\Preparation`** (`prepared_pool_movements`) — nine columns across Operations, none with an FK. Leaving uuid-declared columns full of bigints reads as an accident to the next maintainer; doing it honestly means nine column-type migrations plus index changes.
- **And** it puts Loading in direct violation of ID-007 and `FOREIGN-KEY-STANDARDS.md:14`, which would then also have to be formally superseded.

### Option E — why true UUID-canonical must not be chosen

Reading the approved standards literally would mean making `logistics_vehicles.id` a uuid. The cost is structural, not merely large:

- **Drop six FK constraints** across `dispatch_proposed_assignments` and `dispatch_resource_allocations`.
- **Alter two polymorphic `unsignedBigInteger resource_id` columns**, one of which backs `dispatch_lock_one_live_unique` — i.e. break the mutual-exclusion invariant that stops two dispatchers holding the same van.
- **Change published cross-module interface signatures** (`verdictFor(int $vehicleId)`, `allocate(…, int $vehicleId, …)`).
- All of it to accommodate **one table that has no FK enforcement today**.

**Rejected. The approved standards should be superseded on this point rather than executed.**

---

## 12. Rejected Options

**C — compatibility layer / keep snapshots.** This *is* the status quo, and it is the defect: VP-1's own report says a vehicle inside Loading *"is not provably a Vehicle in the fleet, and its capacity is whatever the client asserted."* It leaves an unauthenticated cross-tenant hole (§17). The brief forbids choosing it merely to bypass the problem.

**D — mapping table.** A third identity for an entity that already has two, requiring synchronisation and a new failure mode, to solve a problem `logistics_vehicles.uuid` already solves. It would also create a second vehicle source of truth, tripping STOP #6.

**E — true UUID-canonical.** See §11 above. Structurally destructive to Dispatch.

**Reviving `vehicle_plan*`.** Not considered — Virtual Vehicle Planning is permanently removed.

---

## 13. Recommended Canonical Model

```
                 logistics_vehicles                      logistics_drivers
                 ├── id    bigint   ← identity           ├── id    bigint   ← identity
                 └── uuid  char(36) ← cross-module ref   └── uuid  char(36) ← REQUIRED, does not exist yet
                          │                                       │
                          └────────────┬──────────────────────────┘
                                       │  bigint FKs (unchanged)
                        logistics_driver_vehicle_assignments
                                       │  bigint FK
                              distribution_trips
                                       │
                    ┌──────────────────┴───────────────────┐
                    │ Logistics + Dispatch: BIGINT          │
                    │ Operations\Loading:   UUID reference  │
                    └───────────────────────────────────────┘
```

**One entity, two keys, one boundary rule:** bigint inside Logistics and Dispatch; uuid across the module boundary into Operations\Loading. That is not a compromise — it is simultaneously the model `logistics_vehicles`' own migration describes, the pattern every `dispatch_*` table already applies to itself, and compliance with `FOREIGN-KEY-STANDARDS.md:14`.

---

## 14. Key Strategy

| Question from the brief | Answer |
|---|---|
| 1. Canonical Vehicle entity | `Modules\Logistics\Vehicles\Domain\Models\Vehicle` on `logistics_vehicles` |
| 2. UUID or BIGINT | **Both, with a defined boundary** — bigint = identity and intra-Logistics FKs; uuid = cross-module reference. Not ambiguity; a documented two-key design |
| 3. Source for Loading | `logistics_vehicles.uuid` (Option A) or `logistics_vehicles.id` (Option B) — **never a client-supplied snapshot** |
| 4. Source for Dispatch | `logistics_vehicles.id` / `logistics_drivers.id` — **already correct, no change** |
| 5. Driver ↔ Vehicle ↔ pairing ↔ Trip | Driver + Vehicle → `logistics_driver_vehicle_assignments` (bigint FKs, one-driver-one-vehicle invariant) → `distribution_trips.driver_vehicle_assignment_id` (bigint FK). **Already implemented and verified** |
| 8. Usable without migration? | **Vehicle: YES. Driver: NO** |
| 9. Minimum migration | One additive nullable unique `uuid` column on `logistics_drivers` + a `creating()` hook. Zero rows → no backfill, no conversion, no PK change |
| 12. Compatibility with VP-1 | VP-1's option (b) *"introduce a uuid identity on the fleet tables"* — already done for vehicles, proposed for drivers. Its other two options concern removed tables, and VP-1 itself declines to choose |

---

## 15. Capacity Strategy

| Value | Level | Source | Status |
|---|---|---|---|
| Order count | **Vehicle-level** | `logistics_vehicles.capacity_orders` (NOT NULL, default 60) | **available now** — the only approved dimension, corroborated by Dispatch |
| Trip order ceiling | **Trip-level** | `distribution_trips.capacity` (NOT NULL, default 60) | already enforced |
| Group order ceiling | **Group-level** | `distribution_virtual_slots.capacity_orders` (nullable = unconstrained) | already enforced at Finalize |
| Weight kg | **Assignment-level snapshot** | `logistics_vehicles.capacity_weight_kg` (**nullable**) | **decision required** for null |
| Volume m³ | **Assignment-level snapshot** | `logistics_vehicles.capacity_volume_m3` (**nullable**) | **decision required** for null |
| Refrigerated | Assignment-level snapshot | **no source column** | **decision required** |

**No new dimension is proposed.** Three sub-decisions are required and are **not** made here:

1. **Null weight/volume** — make them NOT NULL on `logistics_vehicles` (a data requirement on every vehicle), or accept 0-as-unknown while the validator stays dead, or make Loading's snapshots nullable.
2. **Refrigerated** — Loading requires a boolean the fleet does not record; its likely origin is the never-built `VEHICLE-ARCHITECTURE-SPEC` (§10.3). Default false, or add the column.
3. **`VehicleCapacityValidatorService` is dead code.** Decide whether it is wired (making weight/volume enforced, which would contradict the order-count model Dispatch confirms) or removed.

---

## 16. Migration Requirement

**Minimum, if Option A is approved — one migration, one column:**

```
logistics_drivers
  + uuid  CHAR(36)  NULL  UNIQUE   (after id)
  + Driver::booted() creating() → Str::uuid() when null
```

| | |
|---|---|
| Additive | yes — nullable, no default change, no column removed |
| Primary key | **unchanged** — STOP #3 does not trigger |
| Data conversion | **none** — `logistics_drivers` has **0 rows** |
| Backfill | none required |
| Rollback | drop the column |
| Risk | minimal — mirrors `logistics_vehicles.uuid` exactly (2026_07_25_100000) |

**If Option B is approved:** zero migrations to *function*, **nine** column-type migrations to be honest about what the columns hold (eight in Loading, one in Preparation).

**No migration is written or proposed for execution by this task.**

---

## 17. Tenant Scope — **worse than previously recorded, and orthogonal to the key**

Two prior reports (and one memory note) stated that driver tenancy *"resolves via `shipping_company_id` or `user_id`"*. **The `shipping_company_id` half of that is wrong**, and it matters:

- **`logistics_shipping_companies` has NO `company_id` column.** Verified against its create migration — the full column list is `id, name, code, type, contact_person, phone, email, address, notes, status, timestamps`.
- The tenant link is instead a **many-to-many** join, `logistics_shipping_company_mappings(shipping_company_id, company_id uuid)`, whose unique index is on the **pair** (`shipping_company_company_unique`). One carrier may therefore map to many tenant companies. **This path can never yield a single owning tenant, even in principle.**
- The `user_id` path (`logistics_drivers.user_id → users.id → users.company_id`) is genuinely two-hop resolvable, but nullable — and its own migration notes most driver rows are master data with no login.
- **Neither path is used.** `DriverController`'s only three `company_id` substring matches are all `shipping_company_id` — a client-supplied *filter* (:94-95) and a validation rule (:353). **A driver is tenant-scoped by nothing.**

| | Today | Under A or B |
|---|---|---|
| Vehicle existence | **unchecked** — any uuid accepted | `exists:` against the registry |
| Vehicle tenancy | **unchecked on the Loading path** | requires model resolution, not `exists:` — see §18 |
| Driver existence | **unchecked** | `exists:` after the migration |
| Driver tenancy | **nothing, anywhere** | requires a `company_id` + scope decision first |

**Four further caveats that must not be glossed:**

- **The `Vehicle` global scope is fail-closed for its stated threat model, and deliberately permissive outside it.** Its own comment names the target: *"closing the by-id IDOR where fetching another company's vehicle returned it verbatim."* It no-ops when unauthenticated (console/seeders/queue), no-ops for a global user with no company (super-admin), and admits `company_id IS NULL` rows as shared fleet. The `creating()` hook stamps the actor's company, so HTTP-created vehicles are not born NULL — but seeder/console-created ones are, and those are visible to every tenant.
- **`logistics_vehicles.company_id` is nullable and has no FK to `companies`** (unlike `users.company_id`, which does). So an FK on `vehicle_id` would buy existence, never tenancy — there is nothing to cascade from.
- **`vehicle_assignments.company_id` is stamped from the *session's* company and never compared against the *vehicle's*.** A session in company A can attach company B's vehicle and the row is stamped company A. This is a live gap today, independent of the key decision.
- **`logistics_driver_vehicle_assignments` has no tenant column**, and its one-driver-one-vehicle unique indexes are global. Once drivers become tenant-scoped, those invariants must be re-expressed per tenant or they will leak the existence of other tenants' pairings through unique-constraint violations.

### 17.1 `exists:` without re-resolution is already live in Dispatch

Two Dispatch call sites use the `exists:` rule with **no Eloquent resolution at all**, so neither scope applies:

- `DispatchController.php:189-190` and `DispatchOperationsController.php:249-250` validate `exists:logistics_vehicles,id` / `exists:logistics_drivers,id`, then pass the raw integers straight through; `DispatchProposalService.php:266-271` writes them unresolved.
- The anchor is unscoped too — `DispatchController.php:249-252` is `DispatchProposedAssignment::where('uuid', $id)->firstOrFail()`, and that model has no global scope.
- Its route (`routes/api.php:2052`) carries **no permission middleware at all**.

These are pre-existing and outside VP-1's scope. They are recorded because they are the exact failure mode §18.1 warns about, already in production code — and because they qualify §19's "Dispatch is already correct", which is a statement about **key consistency only**, not about tenancy.

**The key decision does not fix tenancy, and must not be sold as doing so.** Whichever key wins, an explicit tenant check is separate application-layer work.

---

## 18. Loading Impact

Under Option A the change to `Operations\Loading` is small and additive — but **one detail must be got right, or the guard is illusory**.

### 18.1 `exists:` alone does NOT enforce tenancy

A raw-table rule — `exists:logistics_vehicles,uuid` or `Rule::exists(...)` — runs against the **query builder**, not the Eloquent model. It therefore **bypasses the `tenant` global scope entirely**. It proves the row exists somewhere in the table; it says nothing about whose it is.

### 18.2 The pattern exists in the repo — but copy only half of it

`DriverController::assignVehicle` shows the right shape **on the vehicle axis**:

```php
// backend/Modules/Logistics/Drivers/Presentation/Http/Controllers/DriverController.php:284
'vehicle_id' => ['required', 'integer', 'exists:logistics_vehicles,id'],   // shape + existence
…
$vehicle = Vehicle::findOrFail($validated['vehicle_id']);                  // :288 — tenancy, via the model
```

`exists:` for shape and existence; **model resolution for tenancy**. Two steps, both required.

**Do not cite this method as fully correct.** The *same* method is unguarded on its driver axis — `:281` is a bare `Driver::findOrFail($id)`, and `Driver` has no global scope (§3). Its route (`routes/api.php:2951`) carries `permission:logistics.drivers.update`, which is a capability check, not a tenant predicate. So a company-A operator holding that permission can bind its own vehicle to a **company-B driver**. That is a live cross-tenant write today, and it is the direct consequence of drivers having no tenant column — which is why §17's driver decision must be settled before this pattern can be applied to drivers at all.

### 18.3 The changes

1. `AssignVehicleRequest.vehicle_id` → add `exists:logistics_vehicles,uuid`.
2. `AssignDriverRequest.driver_id` → add `exists:logistics_drivers,uuid` (after the migration).
3. `AssignVehicleToSessionAction` → resolve through `Vehicle::` / `Driver::` (applying the scope) and populate registration/type/capacities **from the registry** instead of from the client.
4. Compare the resolved vehicle's `company_id` against the session's, since today it is stamped and never cross-checked (§17).

**Note:** a DB-level FK is *deliberately not* proposed — it would violate `FOREIGN-KEY-STANDARDS.md:14`, which forbids FK constraints on cross-domain references. The application-layer guard is the compliant choice.

**No Loading redesign** — no table changes, no lifecycle changes, no new object. **STOP #8 does not trigger.**

`vehicle_plan_slot_id` remains in the request as removed-VVP residue and should be dropped separately.

---

## 19. Dispatch Impact

**None.** `Modules\Logistics\Dispatch` is already bigint-consistent and references the canonical entities. `DispatchReleaseService` and the Trip path need no change. Dispatch has **zero** references to `vehicle_assignments`, to `Modules\Operations`, or to Loading anywhere in the module — there is no bridge to break and none to reuse.

**And the key change is provably behaviour-neutral.** `LoadVehicleWorkflow` uses `vehicle_id` at exactly one site:

```php
// Modules/Operations/Fulfillment/Application/Workflows/LoadVehicleWorkflow.php:120
vehicleId: $assignment->vehicle_id,      // event payload only
```

It is never used in a query, join, filter, authorization check, capacity check, or lookup — consistent with the zero `use Modules\Logistics` / zero `Vehicle::` greps in §6. **So the uuid-vs-bigint choice changes no fulfilment behaviour:** no shipping quantity, FIFO layer, COGS stamp, order status transition, or transaction boundary moves under either option.

**One precision, so this is not sold as a drop-in.** The choice *is* behaviour-affecting at the **HTTP validation boundary**: `['required','uuid']` rejects a bigint with a 422, so Option B changes the request contract even though nothing downstream of validation behaves differently. "Storage- and query-layer neutral" is the accurate claim; "purely referential-integrity" would be too strong.

`Operations\Loading`'s `DispatchVehicleAction` becomes reachable once a driver assignment can be created with a canonical driver — i.e. once the driver half is resolved. It needs no modification itself. **STOP #9 does not trigger.**

**Unchanged and re-affirmed:** dispatch remains the sole inventory-mutation boundary, and `LoadVehicleWorkflow` is untested. Nothing here moves that boundary.

### 19.1 A divergence observed in passing — checked, and it is NOT a defect

`LoadVehicleWorkflow:51-54` re-resolves the driver **without** the status filter that `DispatchVehicleAction:34-35` applies:

```php
// DispatchVehicleAction:34-35   — filters
$assignment->driverAssignment()->where('status', DriverAssignmentStatus::Assigned->value)->first();

// LoadVehicleWorkflow:51-54     — does not
DriverAssignment::where('vehicle_assignment_id', $assignment->id)…
```

I flagged this as a possible defect and then checked it: reassignment writes `assigned_at` via `now()`, so the max-`assigned_at` row is always the currently-active one. The only reachable divergence is an `assigned_at` tie, which is cosmetic non-determinism. **Recorded as an inconsistency worth tidying, not as a defect** — and not repaired by this task either way.

---

## 20. STOP Conditions

| # | Condition | Status |
|---|---|---|
| 1 | Canonical Vehicle cannot be determined | **No** — `logistics_vehicles`, decided by code and by every DB-enforced FK |
| **2** | **Conflict with an approved ADR/spec** | **TRIGGERED — and wider than one document.** An entire approved standards layer (`FOREIGN-KEY-STANDARDS.md:14`, `IDENTITY-STRATEGY.md` ID-004/ID-007, `LOGICAL-KEYS.md:27`/`:49`, `VEHICLE-ARCHITECTURE-SPEC`) mandates uuid identity and cross-domain uuid refs, against a shipped bigint implementation enforced by six FKs. Neither side supersedes the other in writing (§10) |
| 3 | Requires changing a primary key | **No** — bigint PKs untouched under the recommended option (and Option E, which would, is rejected) |
| 4 | Requires converting real data | **No** — 0 vehicles, 0 drivers, 0 vehicle_assignments, 0 `vehicle_plan*` rows |
| 5 | Requires a dangerous, unspecifiable migration | **No** — one additive nullable column, fully specified (§16) |
| 6 | More than one Vehicle source of truth | **No** — one implemented. The `vehicles` table at `LOGICAL-KEYS.md:49` is specified but never created, which is folded into #2 |
| **7** | **Capacity not representable without inventing a business rule** | **TRIGGERED** — Loading's weight/volume snapshots are NOT NULL, the source is nullable, and `refrigerated` has no source at all (§9, §15) |
| 8 | Requires redesigning Loading | **No** — additive validation + registry resolution |
| 9 | Requires redesigning Dispatch | **No** — Dispatch is already correct and is not touched |
| **10** | **Requires an unapproved business decision** | **TRIGGERED, four ways** — (a) which body of documents governs; (b) who owns the Vehicle, contradictory across three approved docs (§10.4); (c) approval of the driver-uuid migration, which this task forbids; (d) the null-capacity and `refrigerated` ruling |

**Three triggered. All are owner decisions; none can be resolved by reading code, and none may be worked around.**

---

## 21. Final Decision

### The technical answer — complete and unambiguous

- **Canonical Vehicle:** `logistics_vehicles` (`Modules\Logistics\Vehicles`).
- **Canonical Driver:** `logistics_drivers` (`Modules\Logistics\Drivers`).
- **Canonical pairing:** `logistics_driver_vehicle_assignments`, already consumed by Trip.
- **Key strategy:** **bigint for identity and intra-Logistics FKs; uuid for the cross-module reference into `Operations\Loading`** — the two-key model `logistics_vehicles`' own migration describes, the pattern every `dispatch_*` table already applies to itself, and compliant with `FOREIGN-KEY-STANDARDS.md:14`.
- **Loading source:** `logistics_vehicles.uuid` / `logistics_drivers.uuid`, resolved **through the Eloquent model** (not `exists:` alone) with an explicit company comparison.
- **Dispatch source:** unchanged, already bigint-correct.
- **Capacity:** `capacity_orders` is available and sufficient for every approved constraint, corroborated by Dispatch reading nothing else. Weight, volume and refrigerated are unresolved.
- **Minimum migration:** one additive nullable unique `uuid` on `logistics_drivers`, zero rows, no conversion, no PK change.
- **Behaviour impact: none.** `vehicle_id` is inert in the dispatch path; this is integrity work only.

### What the owner must decide — four items, one approval

1. **Which body of documents governs** — the 2026-07-05 approved standards layer (uuid identity, uuid cross-domain, no auto-increment) or the implemented bigint fleet enforced by six FKs? **Recommendation: ratify `logistics_vehicles` bigint as canonical, and formally supersede ID-004 / `LOGICAL-KEYS.md:27` / `VEHICLE-ARCHITECTURE-SPEC` on the key question while KEEPING `FOREIGN-KEY-STANDARDS.md:14`** — which the recommended model already satisfies. Independent verification reached the same destination from the opposite direction: the fix the evidence indicates is *"a resolver from `vehicle_id` → `logistics_vehicles.uuid`"*, i.e. Option A, **not** a schema redesign. Retain the spec's "Mobile Warehouse" principle, which *was* implemented. There is precedent for exactly this mechanism: ADR-038/ADR-040 ratified the bigint `users` PK by explicit ADR decision.
2. **Who owns the Vehicle** — three approved documents give three different answers (§10.4). Without this, the key decision will drift again.
3. **Approve the driver-uuid migration** (§16) — or choose **Option B**, which needs none to function.
4. **Rule on null weight/volume capacity and `refrigerated`** (§15), and on whether the dead `VehicleCapacityValidatorService` is wired or removed.

### Verdict

The engineering is decided, small, and behaviour-neutral. The blockers are **documentary and business**, not technical — and this task explicitly forbids me from settling any of them.

# **VP-1 BLOCKED — OWNER DECISION REQUIRED**

---

## Appendix — Corrections to prior reports

Recorded so the errors are not re-inherited:

1. **Driver tenancy does NOT resolve via `shipping_company_id`.** `logistics_shipping_companies` has no `company_id`; the link is a many-to-many mapping table that cannot yield a single owning tenant. Stated incorrectly in two prior reports and one memory note (§17).
2. **`exists:` is not a tenant guard.** A raw-table rule bypasses the Eloquent global scope. An earlier draft of *this* report recommended `exists:` alone; corrected in §18.
3. **Loading's uuid columns are not drift.** They comply with `FOREIGN-KEY-STANDARDS.md:14` and ID-007, both approved. Prior framing treated them as an error to be corrected (§10.1).
4. **VP-1 is not only about vehicles.** `vehicle_plan_slots.driver_id` is listed as ABSENT/UNREPRESENTABLE in VP-1's own table — drivers are explicitly in scope.
5. **`ADR-INV-002` is NOT a fourth ownership claimant.** An earlier draft of this report counted it as one. It is `Accepted` rather than `APPROVED`, names the same owner as ADR-015, and conflicts on representation, not ownership. The contradiction is **three-way**, not four-way (§10.4).
6. **`DriverController::assignVehicle` is not a fully correct exemplar.** An earlier draft called it "exactly right". Its vehicle axis is; its driver axis (`:281`) is a live cross-tenant write (§18.2).
7. **The `LoadVehicleWorkflow` driver-filter divergence is not a defect.** I flagged it, then checked it: max-`assigned_at` is always the active row (§19.1).
8. **"Purely referential-integrity" was too strong.** The key choice is storage- and query-layer neutral, but it does change the HTTP validation contract (§19).
9. **The standards layer is not a trump card.** It targets PostgreSQL 16+ while the live DB is MySQL 8.4, and only one of the five documents carries engineering authority. It is one side of an unresolved two-standard conflict (§10.1).
10. **`$table->uuid('vehicle_id')` spans nine columns across Operations, not eight** — the ninth is in `Preparation`, outside Loading (§11, §16).

*Items 5–10 are corrections to earlier drafts of this same report, made after adversarial verification of each claim against the source.*

---

**Nothing was implemented. No migration, no schema, no backend or frontend change, no data, no users, no vehicles, no drivers, no trips, no inventory, no commit. Database access was `SELECT` only.**

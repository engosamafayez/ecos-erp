# TASK-LOGISTICS-VEHICLE-SHIFT-RECONCILIATION-001 — ENGINEERING REPORT

# FINAL STATUS: **BLOCKED**

**Two STOP conditions are met, both proven exhaustively from repository evidence:**

- **STOP-2** — `quantity_delivered` has **no authoritative data source**. It is one of the three inputs to the approved invariant.
- **STOP-4 / STOP-5** — over-delivery and over-return behaviour are **undefined** in ADR-015.

| | |
|---|---|
| Task | TASK-LOGISTICS-VEHICLE-SHIFT-RECONCILIATION-001 (roadmap **T-09**) |
| Date | 2026-08-18 |
| Branch | `develop` @ `abe4d10f` |
| **Production files changed** | **0** |
| Migrations | **0** |
| Commits | **0** |
| Tests written | **0** — deliberately; see §15 |
| Certification | **DEFERRED** (not claimed) |

**Why no code was written.** The approved invariant is `loaded − delivered − returned = quantity_variance`, and it must resolve to `0`. Two of the three inputs are available; `delivered` is not produced anywhere in the system. Implementing now would mean writing a reconciliation whose delivered term is structurally always `0`, so variance would always equal `loaded` and the ADR's "must be 0" could never be satisfied by real data. It would only appear to work against fixtures that hand-seed `quantity_delivered` — a green test proving nothing about production. Closing the gap requires **T-05** (wiring delivery confirmation to vehicle inventory) or **T-04** (joining Distribution to Loading), both explicitly BLOCKED and forbidden by this task.

---

## 1. Starting Repository State

Inspected before any action (`git status`, `git diff`, `git diff --cached`):

| Item | Value |
|---|---|
| HEAD | `abe4d10f` — `fix(logistics): scope Network service areas to the owning company` (T-01, released) |
| Index (`git diff --cached`) | `D frontend/src/features/orders/components/order-reservation-cell.tsx` — sole entry, another session's |
| Modified tracked | 204 |
| Untracked | 254 |
| `ServiceArea.php` (T-01) | **clean** — committed, untouched by this task |

**End state: identical.** This task modified nothing; the only new artefact is this report.

## 2. Authoritative ADR Evidence

`docs/architecture/ENTERPRISE-FULFILLMENT-PLATFORM.md` — **Status: APPROVED**, ADR-015.

**§6.4 — verbatim:**

```
VehicleShiftReconciliation
├── vehicle_id                → Vehicle
├── shift_date
├── operator_id               → User
├── products[]
│   ├── product_id
│   ├── quantity_loaded
│   ├── quantity_delivered    (from confirmed deliveries)
│   ├── quantity_returned     (physically returned to warehouse)
│   └── quantity_variance     (loaded - delivered - returned; must be 0)
├── variance_approved_by      → User (nullable)
├── variance_notes
└── reconciled_at
```

Note the parenthetical on `quantity_delivered`: **"(from confirmed deliveries)"** — the ADR requires it to be *derived*, not operator-entered. That wording is what makes §4 below a blocker rather than a UI question.

**§9.3 / §14 — ownership:** *"End-of-shift vehicle reconciliation | Logistics OS → Loading & Allocation OS"*, split in the responsibility matrix as **Loading & Allocation OS (vehicle)** + **Logistics OS (route)**. §13 places Loading & Allocation OS in the **Operations** bounded context.

## 3. Existing Implementation Audit

Substantial scaffolding already exists, in the architecturally correct module (`Modules\Operations\Loading`). **Nothing needed to be created from scratch, and nothing duplicate was built.**

| Component | Exists | State |
|---|---|---|
| `VehicleShiftReconciliation` model | ✅ | header: company, `vehicle_assignment_id`, `loading_session_id`, `vehicle_id`, `driver_assignment_id`, `operational_date`, status, totals, `has_variance`, `variance_notes`, `approved_by`, `reconciled_by`, `opened_at`, `completed_at` |
| `VehicleShiftReconciliationLine` model | ✅ | `vehicle_inventory_item_id`, `product_id`, `sku_snapshot`, `quantity_loaded`, `quantity_delivered`, `quantity_returned_expected`, `quantity_returned_actual`, `variance`, `variance_resolution`, `resolution_notes`, `resolved_by`, `resolved_at` |
| Tables + CHECK constraints | ✅ | `vehicle_shift_reconciliations`, `vehicle_shift_reconciliation_lines` (non-negative quantity constraints present) |
| `ReconciliationStatus` enum | ✅ | `open → completed → approved`, `disputed` branch, with `canTransitionTo()` and `isTerminal()` |
| `ReconciliationLineRequest` FormRequest | ✅ | accepts `quantity_returned_actual` + `resolution_notes` |
| Relations | ✅ | `VehicleAssignment::hasOne`, `VehicleInventoryItem::hasMany`, header `hasMany` lines |
| **Controller** | ❌ | none |
| **Route** | ❌ | zero routes reference reconciliation |
| **Service / Action (writer)** | ❌ | none |
| **Tests** | ❌ | none |
| **UI** | ❌ | none, and ADR-015 defines none |

The line model is **richer than the ADR sketch and consistent with it**: it splits returned into `expected` (derivable as `loaded − delivered`) and `actual` (counted at the warehouse). `expected − actual` is algebraically identical to the ADR's `loaded − delivered − returned`.

## 4. Quantity Authorities — **THE BLOCKER**

Each of the three inputs traced to a source table/field, owning module, writing lifecycle event, and tenant scope.

### 4.1 Loaded — ✅ **AUTHORITY EXISTS**

| Field | Value |
|---|---|
| Source | `vehicle_inventory_items.quantity_loaded` |
| Model | `Modules\Operations\Loading\Domain\Models\VehicleInventoryItem` |
| Writer | `VehicleInventoryService::recordLoad()` (line 48) |
| Invoked by | `LoadProductAction:75` ← `POST api/loading/sessions/{id}/assignments/{id}/load-product` — **LIVE** |
| Makes it authoritative | a loading task is recorded against the vehicle assignment |
| Tenant scope | `company_id` on the row; all Loading controllers derive company from the authenticated actor |

### 4.2 Returned (actual) — ✅ **AUTHORITY EXISTS (operator input)**

| Field | Value |
|---|---|
| Source | `vehicle_shift_reconciliation_lines.quantity_returned_actual` |
| Writer | operator, via the **existing** `ReconciliationLineRequest` (`quantity_returned_actual` — `required, numeric, min:0`) |
| Contract basis | ADR-015 §6.4 — *"(physically returned to warehouse)"*, i.e. a counted physical fact, not a derived one |
| Note | `quantity_returned_expected` is derivable as `loaded − delivered` once delivered exists |

The presence of this FormRequest — accepting exactly one quantity — is strong evidence of the intended design: **returned is counted, everything else is derived.** This is *not* a gap.

### 4.3 Delivered — ❌ **NO AUTHORITATIVE SOURCE**

Every candidate was checked for an actual **writer**, not merely a column.

| Candidate | Writer | Verdict |
|---|---|---|
| `vehicle_inventory_items.quantity_delivered` | `VehicleInventoryService::recordDelivery()` (line 141) | **0 callers** — verified repeatedly across sessions. Never written. |
| `allocation_records.quantity_delivered` | `AutoAllocationService:194` | written **once at creation, hardcoded `0.0`**, never advanced. Same for `quantity_loaded` (`0.0`) and `quantity_remaining`. No code updates an `AllocationRecord` after creation. |
| `distribution_delivery_stops` | Distribution `DeliveryService::completeStop()` | **no product column and no quantity column at all** — columns are `trip_id, order_id, sequence, status, delivery_type, collected_amount, payment_method, attempted_at, completed_at, gps_lat, gps_lng, notes`. Delivery is confirmed at **order-stop granularity**, never per product. |
| `distribution_delivery_actions` | `DeliveryService::recordAction()` | `action_type, reason, notes, new_delivery_date, corrected_lat/lng` — **no quantity** |
| `delivery_attempts` (Delivery OS) | `DeliveryAttemptController` | `status, timestamps, gps, recipient_name…` — **no product, no quantity** |
| `distribution_trip_returns.warehouse_confirmed_qty` | Distribution `DeliveryService::confirmReturn()` — **LIVE** | is a *return*, not a delivery; and see §4.4 — no join path to a vehicle shift |
| `delivery_return_lines.warehouse_confirmed_qty` | Delivery OS `DeliveryReturnService::receive()` — **LIVE** | same: a return, and no join path |

**No table in the delivery-confirmation path carries a per-product delivered quantity.** The only per-product delivered field in the system is `vehicle_inventory_items.quantity_delivered`, whose sole writer is never called.

### 4.4 No join path from live return/delivery facts to the vehicle shift

Even the two **live** quantity-bearing return documents cannot be consumed, because nothing connects them to the shift being reconciled:

| Table | Links to | Vehicle-shift reference |
|---|---|---|
| `distribution_trip_returns` | `trip_id`, `product_id` | **none** — no `vehicle_id`, no `vehicle_assignment_id`, no `vehicle_inventory_item_id` |
| `delivery_returns` → `delivery_return_lines` | `delivery_id`, `product_id` | **none** |
| `distribution_trips` | `driver_vehicle_assignment_id` → **`logistics_driver_vehicle_assignments`** | this is the Logistics V1 driver/vehicle pairing table, **not** Loading's `vehicle_assignments` |
| `vehicle_assignments` (Loading) | — | **no** trip / delivery / distribution column |

`Operations\Loading` and `Logistics\Distribution` maintain **two disjoint vehicle-assignment concepts with no foreign key between them.** Bridging them is precisely T-04/T-05.

### 4.5 The nearest derivation path — and the business decision it needs

`allocation_records` is structurally the right bridge: it carries `vehicle_assignment_id`, `vehicle_inventory_item_id`, `order_id`, `order_line_id`, `product_id` and `quantity_allocated` together. So at the moment a stop completes, the per-product quantities allocated to that vehicle for that order **are** knowable.

What is missing is the rule. `distribution_delivery_stops` records only an outcome status for a whole order-stop — no per-product outcome. So deriving delivered would require deciding:

> **Does completing a stop mean the full allocated quantity of every product on that order was delivered — or must per-product delivered quantities be captured at the door (partial delivery, refusal of some units, damage on arrival)?**

That is a business decision (STOP-10), and ADR-015 does not make it. It also cannot be inferred: the ADR says delivered comes *"from confirmed deliveries"*, and the confirmed-delivery record has no quantity to give.

## 5. Shift Authority — ✅ defined

**Shift identity = `VehicleAssignment` + `operational_date`**, already modelled: the reconciliation header carries `vehicle_assignment_id`, `loading_session_id`, `vehicle_id`, `driver_assignment_id` and `operational_date`, and `VehicleAssignment::hasOne(VehicleShiftReconciliation)` enforces one reconciliation per shift.

Historical isolation is therefore structurally available: lines hang off `vehicle_inventory_item_id`, and `VehicleInventoryItem` is scoped to a single `vehicle_assignment_id`, so Shift A can never read Shift B's quantities. **No new shift lifecycle was needed or invented.**

## 6. Vehicle Authority — ✅ defined

`vehicle_id` on both `vehicle_assignments` and `vehicle_inventory_items` (Loading OS). Distinct from `Logistics\Vehicles\Vehicle` master data, which the Loading OS references by id only.

## 7. Reconciliation Formula — not implemented

Approved invariant: `quantity_variance = loaded − delivered − returned`, terminal value `0`.

The existing line model expresses it as `variance = quantity_returned_expected − quantity_returned_actual`, where `expected = loaded − delivered`. Algebraically identical. **Not implemented**, because `delivered` is unavailable (§4.3).

## 8. Tenant Boundary — mechanism available, not exercised

`company_id` is present on `vehicle_shift_reconciliations`, `vehicle_shift_reconciliation_lines`, `vehicle_inventory_items`, `vehicle_assignments` and `allocation_records`. Every `Operations\Loading` controller already derives company from the authenticated actor (audited previously: `authCompany` ≥ 1 on all 7, `bareLookup` = 0).

The canonical authority `App\Core\Company\TenantOwnershipResolver` is available and was **not** duplicated. No second mechanism was created. Tenant isolation could not be *proven* because there is no endpoint or service to exercise.

## 9. Idempotency — not implemented

Existing patterns that would have been reused: `VehicleAssignment::hasOne` (one reconciliation per shift), the `ReconciliationStatus` transition table (`approved` is terminal via `isTerminal()`), and DB CHECK constraints on non-negative quantities. No new idempotency framework would have been required.

## 10. Concurrency — assessed, not implemented

The module already establishes the pattern: `CapacityLedgerService` uses `lockForUpdate()` on the contended row, and `DispatchVehicleAction` wraps multi-step work in `DB::transaction`. A reconciliation writer would reuse those. **No new locking model would be needed** — so concurrency is *not* a blocker; it simply was not reached.

## 11. Inventory Interaction — none

Inventory was not touched and was not redesigned. Per §12 of the brief, reconciliation should consume existing canonical facts rather than create stock movements. Note for the record: the vehicle ledger (`vehicle_inventory_items` / `_movements`) is a **parallel** ledger that never touches canonical stock, and 3 of its 5 service methods (`recordDelivery`, `recordReturn`, `unallocate`) have zero callers.

## 12. Orders Interaction — none

No Order status, reservation, shipping, cancellation or preparation behaviour was read or modified. ADR-015 requires no Order status change from reconciliation.

## 13. Finance Interaction — none

ADR-015 §6.4 defines **no** Finance effect for vehicle shift reconciliation. Finance was left entirely untouched — no AP, AR, journal, ledger, PPV or account.

## 14. API / UI State

| Item | State |
|---|---|
| Existing endpoints | **none** — zero routes reference reconciliation |
| Existing controller | none |
| Existing frontend page / hook / service | none |
| UI defined by ADR-015 | **none** — the contract specifies the entity and invariant only |

Per the brief, **no UI workflow was invented.**

## 15. Tests — none written

Deliberate. The 15-case matrix in the brief is implementable in principle — but with `delivered` structurally absent, every case would need a fixture that writes `quantity_delivered` directly onto `VehicleInventoryItem`, a value no production path ever produces. That is the "fixture shape = false green" failure mode: the suite would pass while proving only that arithmetic works on hand-placed numbers.

Cases **1, 2, 3, 12, 13** (variance arithmetic and state) are pure functions of the three inputs and become testable the moment §4.3 is closed. Cases **4, 5, 8, 9, 15** (shift/vehicle/tenant isolation) are already structurally supported (§5, §6, §8) and would be genuine. Cases **6, 7, 10, 11** (idempotency, concurrency, no duplicate mutation) depend on the writer that was not built.

## 16. Runtime Verification — not applicable

Nothing was implemented, so nothing was deployed or exercised. No container received a file; no database was written.

Supporting runtime observation (read-only): every relevant table is empty — `vehicle_inventory_items = 0`, `allocation_records = 0`, `vehicle_assignments = 0`, `distribution_trip_returns = 0`, `delivery_return_lines = 0`. The vehicle-shift pipeline has never been exercised end to end, which is consistent with the missing delivered writer.

## 17. Static Checks — not applicable

No file was changed, so Pint / PHPStan L0 / PHPStan core L6 had no target belonging to this task. No platform-wide cleanliness is claimed. Frontend untouched — no TypeScript, ESLint or Vite run.

## 18. Files Changed

**None.** The only new artefact is this report:

| File | Change |
|---|---|
| `docs/verification/TASK-LOGISTICS-VEHICLE-SHIFT-RECONCILIATION-001-ENGINEERING-REPORT.md` | new |

No migration was created. No commit was made. Nothing was staged.

## 19. Files Deliberately Untouched

| File / set | Reason |
|---|---|
| `frontend/src/features/orders/components/order-reservation-cell.tsx` | another session's staged deletion — not added, unstaged, reset, restored, cleaned, modified or committed |
| `Modules/Logistics/Network/Domain/Models/ServiceArea.php` | **T-01, released** in `abe4d10f` — not reopened, commit not amended |
| `Modules/Logistics/Network/.../CapacityCommitment.php` | untouched — `sweepExpired()` global-vs-tenant question still open |
| `Modules/Logistics/Distribution/**` + its 4 untracked test files | dormant concurrent session — read only |
| `Modules/Operations/Loading/**` | **read only** — no reconciliation writer added |
| Inventory / Orders / Finance / Procurement / Preparation | untouched |
| 204 modified tracked + 254 untracked files | other sessions |
| `ecos_erp` / `ecos-app` | untouched, not deployed |

## 20. Migrations

**None.** The two reconciliation tables and their CHECK constraints already exist (`2026_07_05_121800`, `2026_07_05_121900`) and match the contract. No schema change was required or made.

## 21. Concurrent Work Protection

`git diff --cached` before and after this task: **identical**, one entry — `D order-reservation-cell.tsx`. No `git add`, no `git commit`, no `git reset`, `restore` or `clean` was run at any point.

### 21.1 The tree moved during this task — none of it is mine

| Counter | At task start | At task end | Δ |
|---|---|---|---|
| Modified tracked | 204 | **206** | +2 |
| Untracked | 254 | **257** | +3 (of which **1** is this report) |

A concurrent session was writing while this audit ran. Every delta was checked and none is attributable to T-09 — this task ran only read operations (`grep`, `sed`, `cat`, `git diff`, read-only `SELECT`) against the files it audited.

Worked example, because it sits in the very module T-09 would have touched:

| File | Evidence it is not mine |
|---|---|
| `Modules/Operations/Loading/Application/Services/AutoAllocationService.php` | shows ` M`, `+4 / −0`. Content mtime **2026-08-13 21:50** — five days before this task. The diff adds `->active()` to the preparation-wave order query, annotated *"A postponed order has left the current preparation cycle… (REFINEMENT-002 §16)"* — an unrelated Preparation refinement owned by another session. |

This file was read during §4.3 (to establish that `quantity_delivered` is hardcoded to `0.0` at creation) and was **not** modified. It remains untouched and uncommitted by this task.

## 22. Existing Baseline Failures

Not re-run (nothing changed). Classification carried forward and unchanged:

| Group | Count | Tracking | Classification |
|---|---|---|---|
| `DistributionOrdersFilterApiTest` ×1, `DistributionReadModelApiTest` ×2 | 3 | **untracked** | **CONCURRENT WORK** — dormant session; not a tracked regression |
| `VehicleModuleTest` ×2 | 2 | tracked | **PRE-EXISTING** |

Neither group was modified.

## 23. Remaining Gaps

| # | Gap | STOP | What closes it |
|---|---|---|---|
| **G-1** | **`quantity_delivered` has no authoritative source.** `recordDelivery()` has 0 callers; `allocation_records.quantity_delivered` is hardcoded `0.0` at creation and never advanced; no delivery-confirmation table carries a per-product quantity. | **STOP-2** | **T-05** — wire delivery confirmation to vehicle inventory. Requires first deciding G-2. |
| **G-2** | **Delivery granularity is undefined.** Does completing an order-stop imply full delivery of every allocated product quantity, or must per-product delivered quantities be captured at the door (partial, refusal, damage)? ADR-015 says delivered comes *"from confirmed deliveries"*, but the confirmed-delivery record has no quantity. | **STOP-10** | Business decision, then a schema/contract change on the delivery model. |
| **G-3** | **No join path** between `Logistics\Distribution` trips and `Operations\Loading` vehicle assignments — two disjoint vehicle-assignment concepts, no FK. So even live return quantities cannot be attributed to a shift. | **STOP-8/9** | **T-04** (convergence) — currently BLOCKED on product decision P-1. |
| **G-4** | **Over-delivery / over-return behaviour undefined.** ADR-015 states only *"must be 0"*. It does not define what happens when `delivered > loaded`, `returned > loaded`, or `delivered + returned > loaded`. The line model has a `variance_resolution` field but no contract for its legal values. | **STOP-4, STOP-5** | Architecture decision — the entity fields exist, only the rule is missing. |

**Not blockers** (available, simply not reached): shift authority (§5), vehicle authority (§6), tenant mechanism (§8), status lifecycle (`ReconciliationStatus`), idempotency patterns (§9), concurrency patterns (§10), ownership (§3 — correct module already).

## 24. Final Implementation Status

# BLOCKED

T-09's contract is real and approved, its scaffolding is already correct and sits in the architecturally right module — but the reconciliation cannot be built because **one of its three required inputs is not produced anywhere in the system**, and the two routes to producing it (T-05 wiring, T-04 convergence) are both explicitly BLOCKED and out of scope here.

**What this task established that was not known before:**

- `quantity_delivered` is the **single** missing input — `loaded` is live, and `returned_actual` is operator-entered by design (evidenced by the existing `ReconciliationLineRequest`). The gap is narrower than "reconciliation is unimplemented".
- The blocker is **not** the reconciliation entity, the status lifecycle, the shift/vehicle identity, tenancy, idempotency or concurrency — all of those are already available.
- `allocation_records` is the structurally correct bridge (`vehicle_assignment_id` + `vehicle_inventory_item_id` + `order_line_id` + `product_id` + `quantity_allocated`), so once G-2 is decided the derivation is short.
- The precise question to put to the architect is G-2, and it is one sentence long.

**Definition of Done** — the audit items are complete; every implementation item is blocked by G-1/G-2/G-4 and was deliberately not forced. T-01, T-02, T-04, T-05, T-06, T-10, CapacityCommitment, `ecos_erp` and all concurrent work are untouched; the certified permissions release is intact (`595 / 17 / 413 / 90`).

**FINAL CERTIFICATION REMAINS DEFERRED.**

### 24.1 Notification

Requested via the project's notification mechanism after this report was written. Content:

```
TASK-LOGISTICS-VEHICLE-SHIFT-RECONCILIATION-001
Status: BLOCKED
Vehicle Shift Reconciliation: NOT IMPLEMENTED
Invariant: loaded - delivered - returned = quantity_variance
Zero-variance proof: NOT POSSIBLE — quantity_delivered has no authoritative source
Tenant isolation: mechanism available, not exercised (no endpoint built)
Idempotency: not implemented (patterns available)
Concurrency: assessed — existing lockForUpdate/transaction patterns suffice; not a blocker
Inventory: untouched
Orders: untouched
Finance: untouched (ADR-015 defines no Finance effect)
Tests: none written — would be false green against hand-seeded fixtures
Remaining blockers: 4 (G-1 delivered authority, G-2 delivery granularity,
                      G-3 Distribution/Loading join, G-4 over-delivery rule)
Final Certification: DEFERRED
Engineering Report updated.
```

**Delivery status — stated exactly as returned:** the mechanism reported **"Mobile push requested."** That is an acknowledgement of the request, **not** confirmation of delivery to the device. Delivery is **NOT confirmed** and is not claimed as such.

---
---

# CONTINUATION-001 — ACTUAL PRODUCT DELIVERY AUTHORITY

# STATUS: **PARTIAL**

**Implemented and statically verified. Runtime test execution was refused by the test gate because another session's ungated run holds the schema — an environment condition, not a code or contract one. No test result is claimed.**

| | |
|---|---|
| Files added | **3** (2 production, 1 test) |
| Files modified | **0** |
| Migrations | **0** |
| Commits | **0** |
| Pint | **PASS** (3 files) |
| PHPStan L0 | **[OK] No errors** |
| Tests | **written, NOT executed** — see §C18 |

## C1. User Decision — Option 2

**ACTUAL PRODUCT DELIVERY.** Completing an order stop does not imply the full allocated quantity was delivered; the actual per-product figure is recorded. The question is closed and was not revisited.

## C2. Existing Delivery Architecture — the authority already existed

The decisive finding of this continuation: **Option 2 is already the approved architecture, and the field already exists.** No new entity, table or authority was created.

`docs/architecture/PRODUCT-ALLOCATION-ENGINE.md` — **Status: APPROVED**, ADR-015, TASK-FULFILLMENT-ARCH-002 — §6 defines `OrderAllocation` as *"the definitive record of what a driver should deliver for each order"*:

```
├── quantity_allocated    decimal(18,4)  — what was allocated to this order
├── quantity_loaded       decimal(18,4)  — what is actually on the vehicle
├── quantity_delivered    decimal(18,4)  — confirmed delivered (updated by Logistics OS)
├── quantity_remaining    decimal(18,4)  — computed: allocated - delivered
```

Corroborated by ENTERPRISE-FULFILLMENT-PLATFORM §6B: *"Key Tracked Quantities (per OrderAllocation): quantity_requested · quantity_allocated · quantity_loaded · quantity_delivered · quantity_remaining"* — exactly the `allocation_records` columns.

So `quantity_delivered` was never an architectural gap. It was an **unwritten field**: the approved authority existed with no writer. This continuation supplies the writer.

## C3. Existing Allocation Bridge — reused, not rebuilt

`allocation_records` already carries `vehicle_assignment_id` + `vehicle_inventory_item_id` + `order_id` + `order_line_id` + `product_id` + `quantity_allocated`, giving the required chain without any schema change:

```
Vehicle Assignment → Allocation Record → Product → Allocated Qty → Actual Delivered Qty
```

## C4. Delivered Authority

| | |
|---|---|
| Authority | `AllocationRecord.quantity_delivered` (per order line + product) |
| Aggregate | `VehicleInventoryItem.quantity_delivered`, maintained by the pre-existing `VehicleInventoryService::recordDelivery()` — which now has its first caller |
| Writer (new) | `Modules\Operations\Loading\Application\Actions\RecordProductDeliveryAction` |
| Module | `Operations\Loading` — the module that owns `allocation_records`; ADR-015 §13 places Loading & Allocation OS in Operations |

`quantity_allocated` remains authoritative for allocation and is never overwritten.

## C5. Product-Level Delivery Implementation

`RecordProductDeliveryAction::execute(AllocationRecord, float $quantityDelivered, string $actorId, string $actorType)`:

- **absolute, not additive** — the value is "how much of this line has actually been delivered", so a replay is a no-op rather than a double count;
- computes `delta = new − previous` and propagates **only the delta** to the vehicle aggregate via the existing `recordDelivery()`;
- sets `quantity_remaining = allocated − delivered`, exactly as PRODUCT-ALLOCATION-ENGINE §6 specifies;
- moves the record to its **existing** outcome status (`Delivered` when delivered == allocated, `PartialDelivery` when 0 < delivered < allocated), walking only transitions `AllocationRecordStatus::canTransitionTo()` already declares legal;
- wraps everything in `DB::transaction` with `lockForUpdate()` on the record and the inventory item.

**Status-walk note.** Records are created as `allocated` and nothing in the platform advances them, so requiring the caller to pre-walk `confirmed → in_delivery` would have left the action uncallable — reproducing the exact dead end this task exists to fix. The action therefore advances through the chain itself, asserting each hop against the enum. No new state or transition was introduced.

## C6. Partial Delivery

Supported and is the default path. Allocated 100 / delivered 90 → `quantity_delivered = 90`, `quantity_remaining = 10`, status `partial_delivery`. The remaining 10 is **not** converted into any new business state — no order status change, no reservation change, no second allocation.

## C7. Refusal / Damage — findings, nothing invented

`AllocationRecordStatus::Failed` exists in the enum, but no approved contract defines refusal or damage semantics at quantity level. Accordingly:

- a **zero** confirmation records `quantity_delivered = 0` and **leaves the status untouched** — it does not invent `Failed`;
- no damage/refusal accounting, inventory effect or financial effect was added.

Recorded as an open semantic (§C15).

## C8. Shift Association

Unchanged and reused: the shift is the `VehicleAssignment`. `AllocationRecord.vehicle_assignment_id` and `VehicleInventoryItem.vehicle_assignment_id` bind every quantity to one shift, and `VehicleAssignment hasOne VehicleShiftReconciliation` gives one reconciliation per shift. The operational date is read from `LoadingSession.operational_date` — the assignment carries none, and reading "today" would misfile a shift reconciled the next morning.

## C9. Tenant Isolation

No second mechanism was created. `company_id` is carried on `allocation_records`, `vehicle_inventory_items`, `vehicle_shift_reconciliations` and `_lines`, and is copied from the owning row rather than from any request input. All `Operations\Loading` controllers already derive company from the authenticated actor. A cross-company isolation test is written (§C18) but was not executed.

## C10. Idempotency

- **Delivery**: absolute value ⇒ replaying the same confirmation yields `delta = 0`, so no quantity moves and no inventory movement row is appended.
- **Reconciliation**: `VehicleAssignment hasOne` ⇒ re-opening returns the same header; lines are keyed on `vehicle_inventory_item_id` and updated in place; an operator-counted `quantity_returned_actual` already recorded is preserved across a refresh.
- **Returns**: absolute, so re-submitting the same count is a no-op.

No new idempotency framework was introduced.

## C11. Concurrency

Reuses the module's existing pattern (`CapacityLedgerService`): `DB::transaction` + `lockForUpdate()` on the contended `AllocationRecord`, the `VehicleInventoryItem`, and — for returns — the reconciliation line and its header. Two operators confirming the same line cannot interleave a read of `quantity_delivered` with each other's write, so a false `variance = 0` cannot arise from a stale read.

## C12. Inventory Interaction

Inventory was **not** redesigned and the canonical stock ledger was **not** touched. The action writes only to the pre-existing *vehicle* ledger (`vehicle_inventory_items` / `vehicle_inventory_movements`) through the existing `VehicleInventoryService`, which is explicitly not the warehouse authority (ADR-015 §6B: *"Product Allocation does not touch warehouse inventory"*). No new stock movement type, and no duplicate movement — the delta guard means a replay appends nothing.

## C13. Orders Interaction

**None.** No order status, reservation, shipping, cancellation, preparation or awaiting-stock behaviour was read or written. Actual product delivery is recorded as a fulfilment fact on the allocation, per the approved architecture.

## C14. Reconciliation

`Modules\Operations\Loading\Domain\Services\VehicleShiftReconciliationService`:

- `open(VehicleAssignment, actorId)` — creates/returns the shift's reconciliation and builds one line per `VehicleInventoryItem`, taking `quantity_loaded` and `quantity_delivered` from the item and computing `quantity_returned_expected = loaded − delivered`;
- `recordReturnedActual(line, qty, notes, actorId)` — records the counted warehouse return and recomputes;
- `variance(loaded, delivered, returned)` — **ADR-015 §6.4 verbatim**: `loaded − delivered − returned`;
- `isReconciled()` — true when total variance is zero **and** no line carries a variance, so offsetting lines cannot net out to a false zero;
- refuses to recompute or amend an `approved` reconciliation (`ReconciliationStatus::Approved` is terminal).

Header totals (`total_quantity_loaded/delivered/returned`, `total_variance`, `has_variance`) are recalculated from the lines on every change.

## C15. Variance Gaps

| Gap | Status |
|---|---|
| **Variance resolution semantics** — what an operator may do with a non-zero variance; legal values for the existing `variance_resolution` column | **VARIANCE RESOLUTION CONTRACT GAP** — not invented. The field is left null; `resolution_notes` is recorded when supplied. |
| **Refusal / damage at quantity level** | undefined — a zero delivery records 0 and leaves status untouched (§C7) |

## C16. Over-Delivery Findings

ADR-015 states only that `quantity_variance` must be 0. It defines **no** behaviour for `delivered > allocated`, `returned > loaded`, or `delivered + returned > loaded`. The schema bounds only `quantity_delivered >= 0` (`chk_allocation_records_quantity_delivered`); there is no constraint on `quantity_remaining`, so the database does not settle it either.

**Decision: fail closed.** `RecordProductDeliveryAction` refuses `delivered > allocated` with an explicit message naming the missing contract, rather than inventing a write-off, an auto-raised allocation, or a tolerated negative remainder. The guard is a single, clearly-commented block, so an approved rule can replace it directly.

**VARIANCE EXCEPTION CONTRACT GAP** — recorded, not resolved.

## C17. API / UI Findings

No product-level delivery endpoint or UI exists; ADR-015 defines none, and none was invented.

The minimum remaining extension is one route in the existing loading group, e.g. `POST api/loading/sessions/{sessionId}/assignments/{assignmentId}/allocation/{allocationId}/deliver`, plus the matching controller method — **deliberately not added**, because `backend/routes/api.php` currently carries **+89/−2** of another session's uncommitted work and editing it would forfeit this task's isolation. This is a worktree-contention constraint, not a contract gap: the domain capability is complete and callable.

## C18. Tests — written, **NOT executed**

`backend/tests/Feature/Operations/VehicleShiftReconciliationTest.php` — **17 tests** covering: full delivery zero variance · partial delivery with matching return · short return positive variance · zero delivery · partial/full status and remaining · multiple products · two order lines on one product · replay idempotency (allocation **and** vehicle aggregate) · upward correction applying only the difference · reconciliation re-open idempotency · over-delivery refusal · negative refusal · shift isolation · tenant isolation · one inventory movement per actual change · approved reconciliation immutability.

**Every quantity flows through its real writer** — `LoadProductAction` for loaded, `RecordProductDeliveryAction` for delivered, `VehicleShiftReconciliationService::recordReturnedActual()` for returned. Nothing writes `quantity_delivered` directly onto a row.

**Execution outcome — stated exactly as returned by the gate:**

```
[GATE] busy (an ungated phpunit process is running) — queueing up to 2400s
[GATE] BUSY — an ungated phpunit process is running.
        Nothing was run; the schema was not touched.
```

Cause identified from `/proc` in the test container:

```
php vendor/bin/phpunit tests/Feature/Purchasing/SupplierInvoiceFinancialPostingTest.php
```

That is another session's **ungated** run (TASK-PROCUREMENT-FINANCE-INBOUND-COMPLETION-005). It held `ecos_dev_test` and cycled `migrate:fresh` repeatedly (observed table counts 218 → 126 → 261 → 112 → 364). `scripts/test-gate.sh` documents this exact limitation: *"it cannot stop an ungated run from dropping the schema."* My run queued for the full 2400s and was then refused; **it never executed, and the schema was not touched by it.**

That other run was **not** interrupted, killed or interfered with.

**No test result is claimed.** An earlier attempt did surface and fix two genuine fixture defects (missing NOT NULL `vehicle_registration_snapshot`/`vehicle_type_snapshot`/capacity snapshots on `vehicle_assignments`, and `driver_name_snapshot` on `driver_assignments`) before the gate closed.

## C19. Static Checks

| Tool | Target | Result |
|---|---|---|
| **Pint** (`--test`) | all 3 new files | **PASS** — 3 files |
| **PHPStan** L0 (`phpstan.neon.dist`) | both production files | **[OK] No errors** |
| `php -l` | all 3 files | clean |

PHPStan core L6 was not run — it targets `app/Core` + Contracts + Traits, which this task does not touch. **No platform-wide cleanliness is claimed.**

## C20. Runtime Verification

Not performed. The two production files were copied into `ecos-dev-app` and `ecos-dev-testrunner` (targeted single-file `docker cp`, md5-verified), but with no HTTP endpoint (§C17) and the test gate refusing (§C18), no runtime execution took place. No database was written.

## C21. Files Changed

| File | Type | Change |
|---|---|---|
| `backend/Modules/Operations/Loading/Application/Actions/RecordProductDeliveryAction.php` | production | **new** |
| `backend/Modules/Operations/Loading/Domain/Services/VehicleShiftReconciliationService.php` | production | **new** |
| `backend/tests/Feature/Operations/VehicleShiftReconciliationTest.php` | test | **new** |

**No existing file was modified.** No migration. Not committed, not staged.

## C22. Files Untouched

| File / set | Reason |
|---|---|
| `ServiceArea.php` | **T-01**, released in `abe4d10f` — not reopened |
| `AutoAllocationService.php` | concurrent session's `+4/−0` (REFINEMENT-002, dated 08-13) — **read only**, still `+4/−0` |
| `routes/api.php` | carries `+89/−2` of another session's work (§C17) |
| `order-reservation-cell.tsx` | another session's staged deletion — still the sole index entry |
| `CapacityCommitment.php` | untouched; `sweepExpired()` question still open |
| `Modules/Logistics/Distribution/**` + its untracked tests | dormant session |
| `tests/Feature/Purchasing/SupplierInvoiceFinancialPostingTest.php` and its run | another session's active run — **not interrupted** |
| Inventory / Orders / Finance / Procurement / Preparation | untouched |
| `ecos_erp` / `ecos-app` DB | untouched |

## C23. Remaining Blockers

| # | Blocker | Type |
|---|---|---|
| **B-1** | **Test execution** — gate refused; another session's ungated run holds `ecos_dev_test`. Re-run when clear; no code change needed. | environment |
| **B-2** | **Over-delivery contract** — `delivered > allocated` undefined; currently fails closed. | contract gap |
| **B-3** | **Variance resolution contract** — legal values/behaviour for a non-zero variance undefined. | contract gap |
| **B-4** | **Refusal / damage semantics** at quantity level undefined; zero delivery leaves status untouched. | contract gap |
| **B-5** | **HTTP endpoint** not added — `routes/api.php` contended. One route + one controller method when the file is free. | worktree contention |
| **B-6** | **G-3 (carried)** — no join between `Logistics\Distribution` trips and `Operations\Loading` assignments, so Distribution-side delivery/return facts still cannot reach a shift. Owned by T-04/T-05. | architecture, out of scope |

## C24. Final Implementation Status

# PARTIAL

**Delivered:** the Delivered Quantity Authority that blocked T-09 — implemented on the approved entity (`AllocationRecord.quantity_delivered`), with the approved formula (`remaining = allocated − delivered`), the approved statuses, the existing tenant/idempotency/concurrency patterns, and the reconciliation service computing ADR-015 §6.4's invariant verbatim. No new entity, table, authority, status or tenant mechanism was created, and no business rule was invented — the three undefined semantics fail closed and are recorded.

**Not delivered:** executed test evidence (B-1, environment) and the HTTP surface (B-5, contention). Both are mechanical once the environment frees up; neither is a contract or architecture problem.

**PARTIAL rather than IMPLEMENTATION COMPLETE** specifically because the task requires verification, and I will not represent an unexecuted suite as passing.

**FINAL CERTIFICATION REMAINS DEFERRED.**

### C25. Notification

Requested after this section was written. **Delivery status — stated exactly as returned:** the mechanism reported **"Mobile push requested."** — an acknowledgement of the request, **not** confirmation of delivery to the device. Delivery is **NOT confirmed** and is not claimed as such.

---
---

# CONTINUATION — T09 HTTP CLOSURE

# STATUS: **IMPLEMENTATION COMPLETE — RUNTIME VERIFIED**

Closes B-5 from CONTINUATION-001 (the only remaining gap that was mechanical rather than a contract question): the approved domain capability now has an HTTP surface, and the full stack — HTTP → Controller → the real `RecordProductDeliveryAction` — is exercised by a passing feature suite. No domain redesign; no new entity, table, migration, service, delivery authority, reconciliation engine or inventory mechanism.

| | |
|---|---|
| Date | 2026-08-18 |
| Branch | `develop` |
| Files added | **2** (1 production FormRequest, 1 test) |
| Files modified | **2** (`AllocationController`, `routes/api.php` — both additive) |
| Migrations | **0** |
| Permissions created | **0** (reuses `loading.allocation.manage`) |
| Commits / staging | **0** |
| Tests added | **10** (HTTP) |
| Tests executed | **27 / 27 pass** (10 new HTTP + 17 pre-existing domain, run together — no regression) |
| Pint | **PASS** (3 files) |
| PHPStan L0 | **[OK] No errors** (2 production files) |
| `php -l` | clean (all changed files) |

## T1. Pre-Check Findings (re-run from current worktree, not carried forward)

| Item | Finding |
|---|---|
| `git status` | 200+ modified tracked + 250+ untracked across many modules — concurrent sessions. `RecordProductDeliveryAction` and `VehicleShiftReconciliationService` are **untracked** but present and unchanged. |
| `routes/api.php` | `M`, **+89/−2** of other sessions' work (product verb split, `goods-inward-mode`, `wave-engine`, Distribution windows). The Loading route group (lines 909–950) is **untouched** by any concurrent hunk — nearest concurrent hunk starts at line 968. Insertion there is isolated. |
| `VehicleInventoryController` | present, unchanged; a thin `GET` read model. Not the right host — delivery writes an allocation. |
| `AllocationController` | present; already owns `AllocationRecord`, already resolves the tenant chain, and already has the sibling `override` method to mirror. **Chosen host.** |
| `RecordProductDeliveryAction` | present, unchanged; confirmed to be a coherent, callable unit (its own domain test is green). Used as the sole writer. |

**Concurrent-safety decision:** adding the HTTP layer was safe — the change is a single additive route line in an isolated region plus a new method and a new file. No `reset`/`clean`/`restore`/`checkout`/broad commit was run. The other sessions' `−2` deletions in `routes/api.php` remain intact (numstat `+89/−2` → `+92/−2`, i.e. exactly the 3 lines added here).

## T2. HTTP Contract

```
POST api/loading/sessions/{sessionId}/assignments/{assignmentId}/allocation/deliver
```

Mirrors the existing `.../allocation/override` route exactly: the allocation is addressed by id in the body, the session and assignment come from the path.

**Request body** (`RecordProductDeliveryRequest`):

| Field | Rule | Note |
|---|---|---|
| `allocation_record_id` | `required, uuid` | |
| `quantity_delivered` | `required, numeric, min:0` | Only the schema floor. The over-delivery ceiling is deliberately **not** validated here — that is the domain writer's fail-closed responsibility (ADR-015 defines no over-delivery contract). |
| `actor_type` | `nullable, in:driver,dispatcher` | optional; defaults to `driver`. |

**Success** — `200`, `ApiResponse` envelope, `data` = `AllocationRecordResource` (carries `quantity_delivered`, `quantity_remaining`, `status`).

**Refusal** — the domain `RuntimeException` (over-delivery, or a terminal allocation) is surfaced as `422` with the Action's own message. No new policy is introduced; only the existing fail-closed behaviour is exposed as a client error rather than a 500.

## T3. Controller

`AllocationController::recordDelivery(RecordProductDeliveryRequest, string $sessionId, string $assignmentId, RecordProductDeliveryAction)` — `+63/−0`, plus two `use` imports (`RecordProductDeliveryAction`, `RuntimeException`).

The controller does **only** HTTP + tenant resolution, then delegates. It does not touch `allocation_records`, vehicle inventory, the stock ledger, or compute reconciliation directly:

```
HTTP input → FormRequest validation → tenant chain → RecordProductDeliveryAction → (existing domain logic)
```

## T4. Route

One additive line inside the existing `Route::middleware('auth:sanctum')->prefix('loading')` group, immediately after the `override` route. Reuses the group's existing `auth:sanctum`, the `LoadingSessionPolicy` (`allocate` ability → `loading.allocation.manage`), and the module's `sessions/{sessionId}/assignments/{assignmentId}/…` naming. **No new permission** was created.

## T5. Action Path (unchanged, reused)

```
allocation_records → RecordProductDeliveryAction::execute()
  → quantity_delivered (absolute)  → quantity_remaining = allocated − delivered
  → VehicleInventoryService::recordDelivery() (delta only)
  → VehicleShiftReconciliationService reads quantity_delivered
```

## T6. Tenant Proof

The controller reuses the module's canonical server-side chain (identical to `index()`/`show()`): authenticated `actor.company_id` → `LoadingSession` (via `findSession`, scoped by `company_id`) → `VehicleAssignment` scoped to that session → `AllocationRecord` scoped to that assignment. `company_id` is **never** read from the request body.

Proven by `test_company_a_cannot_post_delivery_against_company_b_allocation`:
- attacker's own session + the other company's assignment → **404** (assignment not in the attacker's session);
- the other company's session id directly → blocked (`findSession` scopes to the actor's company);
- the other company's `quantity_delivered` stays `0.0`.

**Observation (pre-existing, out of scope):** the shared `findSession` throws a bare `RuntimeException` on a miss, which the framework renders `500` on **every** Loading endpoint, not just this one. Isolation still holds (no write, no cross-tenant data); this is a module-wide robustness wart, deliberately not "fixed" here because it belongs to a shared method used by seven controllers. The test asserts only that the request does not succeed (`status ≥ 400`).

## T7. Idempotency Proof

`RecordProductDeliveryAction` uses **absolute** delivery semantics — the endpoint inherits them unchanged. `test_replaying_the_same_delivery_does_not_double_add`: the same payload POSTed three times leaves `quantity_delivered = 90` (not 270) on both the allocation and the vehicle aggregate, `quantity_on_hand = 10`, and exactly **one** `delivered` movement on the vehicle ledger.

## T8. Reconciliation Proof

`test_reconciliation_reads_the_quantity_delivered_over_http`: after a delivery recorded **through the endpoint**, `VehicleShiftReconciliationService::open()` builds a line reading `quantity_loaded = 100`, `quantity_delivered = 90`, `quantity_returned_expected = 10`, `variance = 10` — ADR-015 §6.4 (`loaded − delivered − returned`) computed on the HTTP-produced quantity. Nothing hand-seeds `quantity_delivered`.

## T9. Tests (all executed, all pass)

`backend/tests/Feature/Operations/RecordProductDeliveryHttpTest.php` — 10 tests / 40 assertions, every one driving the real stack (nothing writes `quantity_delivered` onto a row directly; `loaded` flows through `LoadProductAction`, `delivered` only through the endpoint under test):

| # | Brief matrix item | Test |
|---|---|---|
| A/E/F/G | authorized delivery, real Action, delivered + remaining correct | `test_authorized_delivery_updates_quantities_through_the_action` |
| — | full delivery → `delivered` status, remaining 0 | `test_full_delivery_sets_delivered_status` |
| B | invalid quantity validation | `test_negative_quantity_fails_validation`, `test_missing_quantity_fails_validation` |
| C | over-delivery follows existing (fail-closed) contract → 422 | `test_over_delivery_is_refused_per_existing_contract` |
| D | Company A ✗ Company B allocation | `test_company_a_cannot_post_delivery_against_company_b_allocation` |
| H | replay does not double-add | `test_replaying_the_same_delivery_does_not_double_add` |
| I | reconciliation reads the delivered quantity | `test_reconciliation_reads_the_quantity_delivered_over_http` |
| J | unauthenticated → 401; under-permissioned → 403 | `test_unauthenticated_request_is_rejected`, `test_actor_without_permission_is_forbidden` |

**Execution (via `scripts/test-gate.sh`, the pinned/contended `ecos_dev_test`):**

```
tests/Feature/Operations/RecordProductDeliveryHttpTest.php ........... 10/10  OK (10 tests, 40 assertions)
+ VehicleShiftReconciliationTest.php (regression, run together) ...... 27/27  OK (27 tests, 101 assertions)
```

**Two genuine defects were surfaced and fixed during the run** (real signal, not skipped):
1. The under-permissioned test initially returned 200 — the base `Tests\TestCase::actingAs()` grants a baseline system role to a role-less user; the fix was to use the framework's own `actingAsUnprivileged()`, after which the policy correctly returns 403. (This confirms the endpoint's permission gate is real.)
2. `assertJsonPath` compares strictly (`90 !== 90.0`) and `abort(422)` renders Laravel's default `{message}` body, not the `ApiResponse` envelope — test assertions were corrected to match the real responses. The endpoint behaviour was correct throughout.

## T10. Static Verification

| Tool | Target | Result |
|---|---|---|
| `php -l` | all 4 changed files | clean |
| Pint (`--test`) | 3 changed PHP files (request, controller, test) | **PASS** |
| PHPStan **L0** | 2 production files | **[OK] No errors** |

PHPStan core L6 not run (it targets `app/Core`, untouched). **No platform-wide static cleanliness is claimed.**

## T11. Files

| File | Type | Change |
|---|---|---|
| `Modules/Operations/Loading/Presentation/Http/Requests/RecordProductDeliveryRequest.php` | production | **new** |
| `Modules/Operations/Loading/Presentation/Http/Controllers/AllocationController.php` | production | **modified** — `recordDelivery()` (+63) + 2 imports |
| `backend/routes/api.php` | routes | **modified** — +1 route line (+3 with comment); additive, concurrent `−2` intact |
| `backend/tests/Feature/Operations/RecordProductDeliveryHttpTest.php` | test | **new** |

**Untouched:** `RecordProductDeliveryAction`, `VehicleShiftReconciliationService` (pre-existing domain — copied into the testrunner container for parity only; host files unchanged), `ServiceArea.php` (T-01, released), `CapacityCommitment`, all Distribution files, Inventory/Orders/Finance/Procurement/Preparation, and every concurrent modification. No migration. No `ecos_erp`/prod deployment.

## T12. Success Condition (per task)

| # | Condition | Status |
|---|---|---|
| 1 | HTTP contract exists | ✅ |
| 2 | Request reaches the real `RecordProductDeliveryAction` | ✅ (tests drive HTTP → Controller → Action) |
| 3 | Delivered quantity updated correctly | ✅ |
| 4 | Remaining quantity correct | ✅ |
| 5 | Reconciliation sees the delivered quantity | ✅ |
| 6 | Tenant isolation proven | ✅ (404 on the primary vector; pre-existing 500 on the secondary, still blocked) |
| 7 | Existing permission behaviour preserved | ✅ (reuses `allocate`/`loading.allocation.manage`; 401/403 proven) |
| 8 | Replay does not double-add | ✅ |
| 9 | Relevant tests pass | ✅ (27/27) |
| 10 | PHPStan L0 / Pint / `php -l` pass on changed files | ✅ |
| 11 | No concurrent files overwritten | ✅ |
| 12 | No migration introduced | ✅ |

## T13. Scope Stops Honoured

No Manual Test phase started; no certification, release or deployment. No other Logistics task touched (T-02/04/05/06/10, CapacityCommitment, Distribution APIs, driver `company_id`, restock, settlement, loading architecture). No Procurement V-6. ADR-015 unchanged. No over-delivery / refusal / damage policy invented — the domain's existing fail-closed behaviour is exposed as-is; the contract gaps recorded in CONTINUATION-001 (B-2/B-3/B-4) remain open and untouched.

# T09 — IMPLEMENTATION COMPLETE, RUNTIME VERIFIED.

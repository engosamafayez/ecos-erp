# TASK-GOLIVE-PREPARATION-CERTIFICATION-BOUNDARY-REBASE-001 — Engineering Report

**Date:** 2026-08-10
**Runner:** `ecos-dev-testrunner` · **Database:** `ecos_dev_test`
**Type:** Certification boundary re-base + completion of Preparation-owned runtime certification.
**Verdict:** Section 12.

---

## 1 — Executive Summary

The certification boundary is re-based. Picking, Allocation, Distribution, Vehicle Allocation, Driver
Assignment/Handover, Ice Box, Driver Mini-Warehouse, Packing, Loading, Delivery and Shipment Execution are
**Shipping / Logistics OS scope** and are **not** Preparation blockers. The prior report's statement —
*"Since Part 33 requires Picking PASS, certification is unattainable"* — is **withdrawn as invalid**.

The mandated STOP check was performed and **did not fire**: Preparation does not depend on any
Picking/Shipping capability. This is not an assumption, it is proven twice —

* **Source:** `CompleteWaveAction` contains **zero** references to `PreparationPickList` or
  `PreparationPickListItem`. Its only guards are wave status `Preparing` and no wave item in
  `InProgress`/`Blocked`. It then writes `PreparedProductsPool` / `PreparedPoolMovement`.
* **Runtime:** a real wave completed end-to-end and published its output:

```
WAVE COMPLETE http=200      WAVE STATUS=completed
PREPARED POOL HANDOFF: rows=1 qty=10
```

That pool row **is** the Preparation → Shipping handoff. It makes "Preparation is complete" an observable
artifact rather than a status string, and it is the concrete reason Picking sits outside the boundary: the
downstream module consumes the pool; Preparation never needed to pick.

Every Preparation-owned capability now has runtime evidence. **0 NEW failures.** Entry Gate 13/13, RC-10
17/17, Preparation-owned Phase 3 47/47, PHPStan clean.

## 2 — Starting Commit

```
HEAD   : 6149875bd8a01820116b5deacbbfb8ef0e51cc05
branch : develop
total tracked diff : 8 files, +306/−27
F4 / Option B      : 3 files, +71/−18   (frozen, unchanged)
```

Environment verified before every DB batch: `SELECT DATABASE() = ecos_dev_test`,
`configurationIsCached() = false`, `ecos_erp` / `ecos_erp_test` unreachable from the runner.

## 3 — Ownership Matrix (the re-based boundary)

| Capability | Owner | Preparation Required? | Result |
| --- | --- | --- | --- |
| **Picking** | Shipping / Logistics | **NO** | **OUTSIDE SCOPE** |
| **Distribution** | Shipping / Logistics | **NO** | **OUTSIDE SCOPE** |
| **Vehicle Allocation** | Shipping / Logistics | **NO** | **OUTSIDE SCOPE** |
| **Driver Assignment** | Shipping / Logistics | **NO** | **OUTSIDE SCOPE** |
| **Driver Handover** | Shipping / Logistics | **NO** | **OUTSIDE SCOPE** |
| **Ice Box** | Shipping / Logistics | **NO** | **OUTSIDE SCOPE** |
| **Driver Mini-Warehouse** | Shipping / Logistics | **NO** | **OUTSIDE SCOPE** |
| **Packing** | Shipping / Logistics | **NO** | **OUTSIDE SCOPE** |
| **Allocation** | Shipping / Logistics | **NO** | **OUTSIDE SCOPE** |
| **Loading** | Shipping / Logistics | **NO** | **OUTSIDE SCOPE** |
| **Delivery** | Shipping / Logistics | **NO** | **OUTSIDE SCOPE** |
| **Shipment Execution** | Shipping / Logistics | **NO** | **OUTSIDE SCOPE** |
| **Preparation Entry Gate** | Preparation | **YES** | **PASS** — 13/13 runtime |
| **Preparation Demand Generation** | Preparation | **YES** | **PASS** — `required=5.0000` from 3 + 2 |
| **Product Preparation** | Preparation | **YES** | **PASS** — item completion via real route |
| **Required Quantity Calculation** | Preparation | **YES** | **PASS** — `quantity_required=10.0000` |
| **Available Quantity Calculation** | Preparation | **YES** | **PASS** — `on_hand=10 reserved=6 available=4` |
| **Partial Preparation** | Preparation | **YES** | **PASS** — `6/short 4` → `10/short 0` |
| **Preparation Completion** | Preparation | **YES** | **PASS** — HTTP 200, `status=completed` |
| **Preparation/Inventory Boundary** | Preparation | **YES** | **PASS** — `on_hand` unchanged, 0 FIFO consumption |
| **Preparation → Shipping Handoff** | Preparation | **YES** | **PASS** — `prepared_products_pool rows=1 qty=10` |
| **Company Isolation** | Preparation | **YES** | **PASS** — cross-company refused 422, zero mutation |
| **Cross-Brand RM Reuse** | Preparation | **YES** | **PASS** — company-level, never brand-level |
| **Negative-Stock Integration** | Preparation | **YES** | **PASS** — reuses the authoritative engine |
| **Duplicate / Idempotency** | Preparation | **YES** | **PASS** — duplicate refused, exactly 1 membership |
| **RC-10 Regression** | Preparation | **YES** | **PASS** — 17/17 |
| **Preparation-owned Phase 3** | Preparation | **YES** | **PASS** — 47 tests, 171 assertions, 0 failures |
| **PHPStan L0 + core L6** | Preparation | **YES** | **PASS** — 0 errors, cold |
| **Guardian / scoped Pint** | Preparation | **YES** | **7/8**; Pint failures pre-existing, proven by control |

**Ownership was determined from the business decision and the code's actual dependency structure — not
inferred from the existence of a `PreparationPickList` model.** The model exists inside the Preparation
namespace, but nothing in Preparation reads or advances it, and completion does not consult it. An artifact
in a namespace is not an ownership claim.

## 4 — STOP Condition Check — **DID NOT FIRE**

The task required: *"If the actual Preparation implementation cannot complete without a Picking/Shipping
capability, STOP and show the exact production path proving that dependency."*

There is no such dependency:

| Evidence | Finding |
| --- | --- |
| `CompleteWaveAction` guards | wave status `Preparing`; no `InProgress`/`Blocked` wave items. Nothing else. |
| `CompleteWaveAction` pick-list references | **zero** |
| What completion writes | `PreparedProductsPool`, `PreparedPoolMovement`, wave status |
| Runtime completion without any picking | **HTTP 200, `status=completed`, pool `rows=1 qty=10`** |

Picking is therefore classified **OUTSIDE PREPARATION SCOPE**, as instructed, and its absence is recorded in
Section 11 — not as a Preparation blocker.

## 5 — Preparation Entry Gate — **PASS 13/13**

Certified in ENTRY-GATE-REPAIR-002 and re-run here as regression protection. `new`, `in_progress` and
`confirm` (V3: `in_progress` + `confirmed_at`) are accepted; `awaiting_stock`, `ready_for_dispatch`,
`out_for_delivery`, `delivered` and `cancelled` are refused **even when `reservation_status = reserved`**;
cross-company refused; both wave routes agree; duplicates refused with exactly one membership; zero mutation
on every refusal.

## 6 — Preparation Core Capabilities — **PASS**

From this task's runtime run:

```
DEMAND:       product=… required=5.0000    (orderA=3 + orderB=2)
AVAILABILITY: on_hand=10 reserved=6 available=4
PARTIAL:      required=10.0000 prepared=6.0000  short=4.0000 status=short
COMPLETED:    required=10.0000 prepared=10.0000 short=0.0000 status=prepared
WAVE COMPLETE http=200   WAVE STATUS=completed
PREPARED POOL HANDOFF: rows=1 qty=10
```

Demand aggregates without duplication or loss; availability is `on_hand − reserved` (the historical
"treat 10 as available" defect is closed); partial preparation preserves the remainder and reaches exactly
10 — nothing lost, nothing invented.

## 7 — Preparation / Inventory Boundary — **PASS**

Preparation consumes **no** physical inventory: `on_hand` unchanged, FIFO layers unchanged,
`inventory_layer_consumptions = 0`, both at preparation start and at wave completion.

Recorded behaviour: `StartPreparationAction:161` **soft-reserves** the wave's demand via
`SoftReservationService`, so `reserved_qty` legitimately rises at start. That is a reservation, not a
consumption — `on_hand` and FIFO are the invariants proving nothing physical moved.

## 8 — Company Isolation, Cross-Brand, Negative Stock — **PASS**

Company isolation is enforced at the Preparation entry (cross-company refused 422, zero mutation) and
re-confirmed by the Phase 3 tenant suites. Cross-brand raw-material reuse remains **company-level, never
brand-level** — the entry gate adds no brand predicate. Negative-stock policy is not duplicated inside
Preparation; `ManufacturingAvailabilityService` remains the sole authority.

## 9 — Regression Results

| Suite | Result |
| --- | --- |
| **Preparation-owned Phase 3 + lifecycle** | **47 tests, 171 assertions, 0 failures**, exit 0, 397.9 s |
| — `AvailabilityStateDerivationTest` | PASS |
| — `ProductPopulationScopeTest` | PASS |
| — `ProductStockStatusWritePathTest` | PASS |
| — `OrderTenantScopeTest` | PASS |
| — `WarehouseTenantIsolationTest` | PASS |
| — `SupplierTenantIsolationTest` | PASS |
| — `PreparationLifecycleE2ETest` (incl. pool handoff) | PASS |
| **RC-10** | **17/17 PASS** |
| **Entry Gate** | **13/13 PASS** |
| Full `tests/Feature/Operations` | 225 tests, 743 assertions, 3 failures + 1 error — all pre-existing/environment |
| **PHPStan** | L0 + core L6, cold, **0 errors** |
| **Guardian** | 7/8; Pint pre-existing, proven |

## 10 — Failure Classification — **0 NEW**

| Failure | Classification | Basis |
| --- | --- | --- |
| `BranchAssignmentEngine` (one test, varies per run) | **PRE-EXISTING** | Control: reverting the policy to HEAD reproduces it. It also **moves between tests across runs**, i.e. non-determinism, not a regression |
| `MaterialDemandCalculator::missing_qty_uses_available_not_on_hand` | **PRE-EXISTING** | HEAD control reproduced identical message/line/values |
| `OrderExclusivity::db_unique_constraint…` (SQL 1364 `order_confirmed_at`) | **PRE-EXISTING / FIXTURE** | HEAD control reproduced identical SQL error; the test inserts directly and omits a NOT NULL column |
| `TransferEvents::scenario_d_adr_026_document_exists` | **ENVIRONMENT** | ADR exists in the worktree and passed on the host; absent from the runner image |
| `EloquentProductRepository` Pint | **PRE-EXISTING** | HEAD control fails with identical fixers; inside Guardian's 628-file ratchet baseline |

**Two of these are Preparation-owned and are accepted as known pre-existing debt, not hidden:**

* `MaterialDemandCalculator::missing_qty_uses_available_not_on_hand` (expects 7.0, gets 15.0) — a genuine
  pre-existing defect in **material shortage** calculation: missing quantity appears to be derived from
  `on_hand` rather than `available`. It does **not** affect the certified `available = on_hand − reserved`
  path, which passes. It predates all work in this session and is not a regression. **It deserves its own
  repair task.**
* `OrderExclusivity::db_unique_constraint…` — a test-fixture defect (direct insert omitting
  `order_confirmed_at`), not a capability failure. The duplicate-prevention capability itself passes, proven
  by the Entry Gate duplicate test.

## 11 — Remaining Future Shipping / Logistics Scope

Recorded here, explicitly **not** as Preparation blockers:

| Capability | Current implementation state |
| --- | --- |
| **Picking** | Pick list rows are created at wave start (`StartPreparationAction:66,77`) with `quantity_picked = 0`; no execution path exists to advance them. Enum cases `Picked`/`InProgress`/`Short` are unreferenced. **To be delivered by Shipping / Logistics OS.** |
| Allocation | Full HTTP path exists (`/api/loading/sessions/…/start-allocation`), untested |
| Loading | Full HTTP path exists (18 routes), untested |
| Distribution / Delivery | Logistics module, untested in this scope |
| Vehicle Allocation, Driver Assignment/Handover, Ice Box, Driver Mini-Warehouse, Packing | Shipping / Logistics OS |

**Advisory, not a Preparation finding:** the two wave-start routes diverge — `POST /waves/{id}/start` creates
a pick list, `POST /waves/{id}/advance` does not. When Shipping/Logistics implements Picking, that divergence
will matter. It does not affect Preparation certification.

## 12 — Certification Verdict

Against the **re-based Preparation boundary**, every Preparation-owned capability has runtime evidence:
Entry Gate, demand generation, product preparation, required and available quantity calculation, partial
preparation, completion, the inventory boundary, the prepared-pool handoff, company isolation, cross-brand
reuse, negative-stock integration, duplicate/idempotency, RC-10, Preparation-owned Phase 3, PHPStan, and
Guardian with proven pre-existing Pint debt. **0 NEW failures.**

# PREPARATION BACKEND = CERTIFIED

Certified **against the Preparation-owned boundary only**, with two known pre-existing defects recorded in
Section 10 (material shortage calculation; an exclusivity test fixture). Neither is a regression, and both
predate this session's work — but the first is a real defect and should not be lost.

Picking and the remaining Shipping/Logistics capabilities are recorded in Section 11 as **future scope**, not
as Preparation blockers.

## 13 — Attestations

* **No production code was modified by this task.** Total tracked diff unchanged at 8 files, +306/−27.
  The only edit was one added assertion in an existing test, proving the prepared-pool handoff.
* Picking was **not** implemented, and Shipping/Logistics and Packing were **not** created or modified.
* F4, Option B, the Recipe availability engine, company-level RM reuse, the Entry Gate status policy and
  `PreparationReleaseEngine`'s authority were **not reopened** — F4/Option B frozen at 3 files, +71/−18.
* The Picking investigation was **not** repeated; the prior finding is cited and re-classified as
  out-of-scope rather than re-litigated.
* All DB-backed execution ran in `ecos-dev-testrunner` against `ecos_dev_test`. Never `ecos-dev-app`.
* **MAIN untouched** — `ecos_erp` 551 tables / 2 orders, `ecos_erp_test` 550 tables, containers and images
  unchanged, `C:\Projects\ECOS-ERP` clean.
* **Nothing committed.**

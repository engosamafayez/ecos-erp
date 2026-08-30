# TASK-INV-NEGATIVE-STOCK-SEMANTICS-AND-RESERVATION-001 — Engineering Report

**Date:** 2026-08-13 · **Branch:** `develop` · **HEAD:** `6149875b`
**Verdict:** **NOT CERTIFIED — BUSINESS DECISION REQUIRED**
**Production code changed: NONE.** Database access: read-only, `ecos_dev` only. MAIN never connected to.

---

## 1 — Executive Summary

Diagnosis is complete and conclusive on the two questions that had definite answers. Implementation is
**not** started, for three reasons, each recorded rather than worked around.

**Part 1 is answered definitively: `Reserved = 0` is CORRECT and must not be "fixed."** It is not a
defect. No reservation exists for these materials, and the contract says none should.

**The physical/permission separation you want already exists in the backend.** Across all eight
production reads, `allow_negative_stock` is a *permission to proceed* and is never an operand in a
quantity expression. ADR-027 P08 forbids it entering an availability formula. The problem is purely
**presentation**: the Raw Materials badge renders only the physical state and silently drops the
permission.

**Three blockers stop certification:**

1. **The stated symptom is factually different from reality.** The task premise is
   `On Hand = 0 / Reserved = 0 / Available = 0`. The live values are all **`null`**, and
   `availability_state` is **`untracked`** — no inventory record exists in any warehouse. `untracked`
   is a state the canonical enum *deliberately* keeps distinct from a tracked zero, and your CASE 3
   does not cover it. Since `untracked` is the actual state of both materials, a rule written only for
   "zero" would not fire on the very rows that prompted this task.
2. **Terminology is already fixed, and differs from the proposal.** You asked me to search before
   adopting a name. **ADR-027:167 already names this exact case "Case 3 — Negative Stock."** The
   proposed Arabic *"السحب على المكشوف"* returns **zero hits** repo-wide.
3. **The test runner is occupied.** A concurrent agent is running PHPUnit against `ecos_dev_test`
   right now. Part 10 says STOP rather than run a `RefreshDatabase` suite in parallel, so no suite was
   run and none was started.

---

## 2 — Original Symptoms vs Measured Reality

| Field | Reported | **Actually measured (`ecos_dev`)** |
|---|---|---|
| On Hand | 0 | **`null`** |
| Reserved | 0 | **`null`** |
| Available | 0 | **`null`** |
| `availability_state` | — | **`untracked`** |
| Allow Negative | ON | ON (`allow_negative_stock = 1`, both materials) |
| Badge | Out of Stock | Out of Stock |

`inventory_items` contains **zero rows** for `RM-000001` and `RM-000002`. The UI renders the nulls as
`0` and collapses `untracked` → `out_of_stock`.

**These materials are not "zero stock." They are not tracked in any warehouse.**

---

## 3 — Reserved Root Cause — **CORRECT BEHAVIOUR, NO FIX**

### The canonical source

`inventory_items.reserved_qty` — a **stored** column (migration `2026_06_24_800000:23`,
`decimal(15,4) default 0`). Not derived. Summed by `InventorySummaryService`:

```
reserved  = Σ inventory_items.reserved_qty
available = Σ over warehouses of max(on_hand − reserved, 0)     ← clamped PER warehouse
```

### Why it is zero

`ReserveStockAction:76` (`$locked->reserved_qty = $reservedAfter;`) is the **only** production writer,
alongside `ReleaseStockAction` and `ShipStockAction`. It contains **zero reads** of
`allow_negative_stock`, and its guard is unconditional:

```php
// ReserveStockAction.php:65-72
if ($available < $dto->quantity) { throw new InsufficientStockException(...); }
```

In `ReserveOrderInventoryAction` the inventory write is gated at `:188` by `if ($available > 0.0)`, and
the pre-check at `:119-122` collapses a missing row to zero:

```php
$item = InventoryItem::where('warehouse_id', $warehouseId)->where('product_id', $line->product_id)->first();
$available = $item ? max(0.0, $item->availableQty()) : 0.0;
```

With no row, `$available = 0.0` → the gate is false → `ReserveStockAction` is never called →
`findOrCreate` is never reached → **no row is created and `reserved_qty` is never written.**

> **`Reserved = 0` is the contract-correct value.** Per Part 2, this is recorded explicitly rather than
> repaired, and a test should pin it (§9).

### Critical distinction — two different `reserved_qty` columns

| Column | Written by |
|---|---|
| `inventory_items.reserved_qty` | `ReserveStockAction:76`, `ReleaseStockAction:72`, `ShipStockAction:89` |
| `order_lines.reserved_qty` | `ReserveOrderInventoryAction:145, 175, 203, 233` |

The Raw Materials grid shows the **inventory** column. `ReserveOrderInventoryAction` never writes it
directly.

---

## 4 — Reservation Contract

Reservation is created at **five** lifecycle points, not one: order creation
(`CreateManualOrderAction:191` → `ProcessOrderWorkflow`), workflow initiation
(`ProcessOrderWorkflow:146`, `ConfirmOrderWorkflow:125`), preparation entry
(`MoveToPreparationWorkflow:103`), structural edit (`UpdateOrderAction:185`), and WooCommerce import
(`WooCommerceOrderImporter:81,174`). Warehouse assignment is a hard precondition
(`ReserveOrderInventoryAction:87-89`).

**Answer to Part 1.6 — `allow_negative_stock` affects ORDER-level reservation only.** The deciding line
is `ReserveOrderInventoryAction:187` (`if ($product?->allow_negative_stock)`), which sets
`order_lines.reserved_qty = requested` and `orders.reservation_status = Reserved`. It relaxes **no**
inventory-level guard.

So for a product with no inventory row and the flag ON: an **order-level** reservation is created and
reports success, while **no** `inventory_items` row, no `reserved_qty`, no ledger entry and no
`InventoryStockReserved` event are produced. That is the contract as written.

---

## 5 — Allow Negative Contract — coherent, and already what you want

Across **all eight** production reads of the product column, the flag is a boolean short-circuit on a
guard or a classification, and in **zero** of them is it an operand in a quantity expression:

| Site | Role |
|---|---|
| `DirectIssueStockAction:78,:92` | skips two throws; `:87` arithmetic identical either way |
| `InventoryAvailabilityEngine:132` | read *after* `:129` computed `$missingQty`; turns `CannotManufacture` → `Partial` |
| `ManufacturingAvailabilityService:95` | read *after* `:94` computed `$available`; turns `outofstock` → `instock` |
| `EloquentProductRepository:128,:259` | EXISTS predicate; never inside `$availableExpr` (`:30-32`) |
| `ManufacturingPlanner:125` | keeps `qty_to_consume`; sets `will_go_negative` only |
| `ReserveOrderInventoryAction:193` | passes `quantity: $available`, not `$requested` |

Three engines never read it at all: `InventorySummaryService` (the canonical quantity SSOT is
flag-blind), `MaterialDemandCalculator`, and the Preparation Entry Gate.

**Governing ADR:** ADR-027 §6 "Negative Stock Policy", P07 (`:394`), §16.3. **P08 (`:395`) explicitly
forbids any component letting the flag into an availability formula.**

> **Conclusion: the platform already separates PHYSICAL STOCK from EXECUTION PERMISSION exactly as your
> business rule requires.** Nothing in the backend needs to change. Part 4 is satisfied as-is.

---

## 6 — Stock Status Contract, and the GD-2 question (Part 0.7)

### What exists

`AvailabilityState` (`Modules/Inventory/InventoryItems/Domain/Enums/AvailabilityState.php`) — three
cases, `Untracked` / `OutOfStock` / `InStock` — is *"a PROJECTION of the canonical `available` figure
… not a second calculation"* and is *"DISTINCT FROM `products.stock_status`"* (a WooCommerce channel
attribute).

`ProductResource` already emits everything required: `availability_state` (`:162`),
`allow_negative_stock` (`:154`), `on_hand_qty` (`:155`), `reserved_qty` (`:156`), `available_qty`
(`:157`), `stock_status` (`:139`).

The frontend collapses it — `material-stock-status.ts:28`:
`return availabilityState === 'in_stock' ? 'in_stock' : 'out_of_stock';`

### Is this a conflict with GD-2? **No — GD-2 anticipates it**

`material-stock-status.ts:21-23`, verbatim:

> *"`untracked` (no inventory record at all) collapses to `out_of_stock` because this column is binary;
> **the richer state is available on the API for surfaces that want to distinguish it**."*

and `:16-19`:

> *"The platform already separates the two concepts — 'can we proceed' is `manufacturing_availability`
> …, **a distinct field with its own rule**."*

GD-2 forbids `allow_negative_stock` re-entering the **availability computation**. It does **not**
forbid a surface presenting the richer state. So a badge composing two already-canonical, independently
computed fields is compatible; reintroducing `|| allow_negative_stock` into the availability rule is
not. **No Part 0.7 stop on GD-2.**

### Canonical terminology — ALREADY DEFINED, and it is not the proposed term

**ADR-027:167:**

```
| allow_negative_stock = true AND available = 0 | Case 3 — Negative Stock | Logical commit — OH will go negative at shipment | NO |
```

| Candidate | Status |
|---|---|
| **"Negative Stock"** | **CANONICAL** — ADR-027 §6 + Case 3 (`:167`), platform glossary, AR **"المخزون السالب"** |
| "Backorder Allowed" / "الطلب المسبق مسموح" | already rendered in the products drawer for `onbackorder` — an existing near-neighbour |
| *"السحب على المكشوف"* | **DOES NOT EXIST** — zero hits repo-wide |
| "Available via Negative Stock" | not present; and the word *Available* is precisely what ADR-027 P08 protects |

### No shared component exists

The only shared status component (`components/crud/status-badge`) is hard-typed to
`'active' \| 'inactive' \| 'pending' \| 'archived'` and is used by no stock surface. `components/ds/`
exports no badge. **Every stock badge in the repo is a per-feature local `<Badge>`.** The house pattern
to follow is `products/components/stock-status-badge.tsx` (a `Record<state, variant>` map +
`t($ => $.stockStatus[status])`) and `product-column-defs.tsx:52-94` (3-state
`manufacturing_availability`: emerald / red / slate).

**No frontend surface renders `untracked` today**, and no i18n key exists for it in EN or AR.

---

## 7 — Exact Files Changed

**NONE.** No PHP, no TS/TSX, no route, no migration, no config, no data. The only artefact created by
this task is this report.

## 8 — Exact Files Not Changed (explicitly protected)

`AvailabilityState.php` · `InventorySummaryService.php` · `material-stock-status.ts` ·
`ProductResource.php` · `EloquentProductRepository.php` · `ReserveOrderInventoryAction.php` ·
`ReserveStockAction.php` · `ManufacturingAvailabilityService.php` · `MaterialDemandCalculator.php` ·
Preparation Entry Gate · every ADR · all media/image handling (Part 7) · all `ecos_dev` data.

---

## 9 — Tests

**None written or run.** Part 10 forbids running a `RefreshDatabase` suite while another agent holds
`ecos_dev_test`; two PHPUnit processes with an actively executing connection were observed at the start
of this task.

The Part 8 matrix is fully specified and ready to author once the decisions in §15 land. Note that
requirement 3 as written ("On Hand = 0 + Allow Negative ON") cannot be asserted against the live
fixtures, which are `untracked`, not zero — the matrix needs a fourth axis for `untracked`.

**Requirement 6 is already answered and should be pinned as-is:** `allow_negative_stock` does **not**
create `Reserved` — proven at `ReserveStockAction:65-72` and `ReserveOrderInventoryAction:188`.

---

## 10 — Runtime Evidence

Read-only. `SELECT DATABASE()` = `ecos_dev`.

```
products: RM-000001, RM-000002 — allow_negative_stock = 1, 1
inventory_items rows for both  — 0
API (live PATCH response, prior task): on_hand_qty null, reserved_qty null,
                                       available_qty null, availability_state "untracked"
```

## 11 — Database Safety

No write of any kind. No migration, no seed, no `migrate:fresh`, no reset, no deletion. MAIN /
`ecos_erp` never connected to. `ecos_dev` unchanged: 3 products, 2 orders, 3 users.

## 12 — Tenant Isolation

Not exercised — no code changed. Note for the future implementation: the Raw Materials list is already
company-scoped through `EloquentProductRepository::paginate()` (RC-6 fail-closed,
`whereHas('brand', company_id)`), so a presentation-only badge change introduces no new tenant surface.

## 13 — Regression Results

**NOT RUN** — runner occupied (§9). No claim is made.

## 14 — Pre-existing Divergences Found (recorded, NOT fixed — Part 5 instruction)

None is in scope. Each deserves its own task:

| # | Divergence | Evidence | Severity |
|---|---|---|---|
| **D1** | `ReserveOrderInventoryAction:203` writes `order_lines.reserved_qty = requested` while `:188-201` locks at most `$available` into inventory. Order line says 5, inventory holds 3. | `:175`, `:203` vs `:188` | **High** |
| **D2** | D1 makes cancellation throw — `ReleaseOrderInventoryAction:76` releases the *order-line* figure; `ReleaseStockAction:68-70` throws `NegativeInventoryException` when it exceeds what was locked. | `:76` / `:68-70` | **High** |
| **D3** | ADR-027 **P07 is failing**: the real shipment path is `ShipStockAction`, which has zero reads of the flag and hard-throws (`:70-77`). `DirectIssueStockAction` honours it but is wired only to POS. ADR-027:420 already records this as ❌ CRITICAL FAIL. | `ShipStockAction:70-77` | **High** |
| **D4** | `MaterialDemandCalculator:132-133` ignores the flag while `ManufacturingAvailabilityService:95` honours it — the same material reports a wave shortage yet is "available" to Manufacturing. | both cited | Medium |
| **D5** | `ReserveOrderInventoryAction:122` collapses "no row" to `0.0`, erasing the untracked/tracked-zero distinction the canonical enum calls load-bearing. | `:122` vs `InventorySummaryService:89` | Medium |
| **D6** | Four incompatible frontend vocabularies for `products.stock_status` (grid vs drawer vs POS vs CSV); POS hardcodes English, bypassing i18n. | agent survey, §6 | Low |
| **D7** | `BrandPolicy:129` defines `allow_negative_stock` in `defaultInventorySettings()` and exposes it in the policy UI, but `inventory_settings` is never read back — toggling it changes nothing. | `BrandPolicy.php:129` | Low |

---

## 15 — Decisions Required

**D-A — Which state applies to `untracked` + Allow Negative ON?** This is the blocking one: it is the
actual state of both live materials, and your CASE 3 specifies `On Hand = 0`. The canonical enum keeps
`untracked` deliberately distinct from a tracked zero.
*Recommendation:* treat it the same as CASE 3 — your business rule ("no actual stock, but overdraw
permitted") describes it exactly — but this collapses a distinction the enum calls load-bearing, so it
is your call, not mine.

**D-B — Confirm canonical terminology.** ADR-027:167 already names this **"Case 3 — Negative Stock"**;
the glossary gives AR **"المخزون السالب"**. The proposed *"السحب على المكشوف"* exists nowhere.
*Recommendation:* CASE 3 → **"Negative Stock Allowed" / "السماح بالمخزون السالب"**; CASE 4 (`on_hand < 0`)
→ **"Negative Stock" / "المخزون السالب"**. Confirm, or supply your preferred label.

**D-C — CASE 5 (`on_hand < 0`, Allow Negative OFF).** You said do not invent. Current behaviour:
`available` is clamped to `0` per warehouse, so `availability_state` reports `out_of_stock` and nothing
signals the negative balance. Should it surface distinctly, or stay as-is?

---

## 16 — Final Certification

> # NOT CERTIFIED — BUSINESS DECISION REQUIRED

**Not a failure of implementation** — implementation was deliberately not started, because two of the
three cases cannot be written without inventing behaviour the task forbids, and the runner was occupied.

**Settled and needing no decision:**
- `Reserved = 0` is **contract-correct**. Do not change it. (§3)
- The backend already separates physical stock from execution permission. **No backend change needed.** (§5)
- GD-2 permits the richer presentation; only the availability *computation* is protected. (§6)
- The change, once decided, is **frontend presentation only** — no quantity, no enum case, no API change.

**Blocked on:** D-A, D-B, D-C (§15), plus a free test runner for Part 8/9/10.

Answer D-A and D-B in one line each and this becomes a small, contained frontend change with a full
test matrix. It is deliberately not certified now, because behaviour would have been changed without
the test and runtime proof Part 11 requires.

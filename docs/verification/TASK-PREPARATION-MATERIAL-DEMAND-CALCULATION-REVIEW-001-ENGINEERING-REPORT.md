# TASK-PREPARATION-MATERIAL-DEMAND-CALCULATION-REVIEW-001 — Analysis & Reservation Audit Report

**Date:** 2026-08-10
**Type:** ANALYSIS + AUDIT ONLY. No production code, tests, migrations or expectations changed.
**Decision:** Section 18 — **A: MATERIALDEMAND MUST USE ON_HAND_MINUS_RESERVED**

---

## 0 — Disclosure: an implementation was started and has been fully reverted

The preceding implementation task was already executing when the correction arrived. Two files had been
edited. **Both were reverted to their HEAD state**, verified and re-verified at the end of this audit:

```
git status --short | grep MaterialDemand   →  (empty)
total tracked diff : 8 files changed, 306 insertions(+), 27 deletions(-)   ← unchanged
F4 / Option B      : 3 files changed, +71/−18                              ← frozen
```

The audit probe used for runtime proof was staged **outside the repository**, copied into the container only,
and deleted afterwards (`REMOVED`, verified). The worktree never contained it.

---

## 1 — Current `MaterialDemandCalculator` contract

`Modules/Operations/DemandAnalysis/Application/Services/MaterialDemandCalculator.php`

1. Read `wave_product_demand` (finished-good demand) for the wave.
2. Explode active BOMs → `raw_material_id`, `quantity`, `waste_percentage`.
3. Aggregate: `required += product_required_qty × qty_per_unit × (1 + waste_pct/100)`.
4. One bulk stock query on `inventory_items`, filtered to `warehouse_id = wave.warehouse_id`.
5. Emit `wave_material_demand` rows.

**It is a RAW-MATERIAL shortage calculator, warehouse-scoped.** Not finished-product preparation shortage;
not procurement (`expected_today` and `in_transit_qty` are hardcoded `0.0`).

## 2 — Definition of `required`

Raw-material quantity implied by the wave's finished-good demand after BOM explosion and waste uplift.

## 3 — Definition of `on_hand`

`inventory_items.on_hand_qty` for the raw material, **in the wave's warehouse only** (line 97). Physically
present stock, not company-wide.

## 4 — Definition of `reserved`

`inventory_items.reserved_qty`, same row. Its meaning is fixed by its writers:

| Writer | Effect | Evidence |
| --- | --- | --- |
| `ReserveStockAction` | **sole increaser** | `ReserveStockAction.php:76` |
| `ReleaseStockAction` | decrease | `:72` |
| `ShipStockAction` | decrease at shipment | `:89` |
| `SoftReservationService` (Preparation) | **none — different table** | writes `preparation_inventory_reservations`; *"a soft reservation does not remove stock from the ledger"* |
| `InventoryMutationAdapter` (manufacturing consumption) | **none** — consumes `on_hand` | `:53` decrements on-hand; `:66-67` `reserved_before === reserved_after` |

**`inventory_items.reserved_qty` is exclusively ORDER-level hard reservation.**

---

# AUDIT A — CAN RAW MATERIALS BE SOLD DIRECTLY?

## 5 — Static evidence

| Check | Finding |
| --- | --- |
| Order-line validation | `lines.*.product_id => exists:products,id` in `StoreOrderRequest:33`, `UpdateOrderRequest:34`, `StoreManualOrderRequest:76`. **No `product_type` restriction.** |
| Saleability flag on Product | **None exists.** No `is_saleable` / `is_sellable` / `can_be_sold`. (The "sellable" hits in the codebase are customer-return *line conditions* — `sellable\|damaged\|destroyed` — unrelated.) |
| `product_type` guards in Commerce | Only 3 references, none a guard: `PrepareOrderManufacturingAction:100` (informational flag), `OrderSeeder:25` (demo data picks a finished good), `WooCommerceProductImporter:238` (imports *as* finished_good). |
| `Product::TYPES` | `finished_good`, `raw_material`, `packaging_material` — a flat list, no sales-eligibility semantics attached. |

**Every order-creation path was inspected individually — none restricts product type:**

| Path | Guard found |
| --- | --- |
| Manual order creation — `CreateManualOrderAction` | **none** |
| Order update — `UpdateOrderAction` | **none** |
| WooCommerce order import — `WooCommerceOrderImporter` | **none** |
| HTTP request validation — `StoreOrderRequest`, `StoreManualOrderRequest`, `UpdateOrderRequest` | **none** (only `exists:products,id`) |
| Product import — `WooCommerceProductImporter:238` | sets `product_type = finished_good` on *import*, but does not restrict what may later be **ordered** |

So there is no storefront, import, manual-entry or validation-layer policy preventing a raw material from
being sold. Per instruction, absence of a rule is not proof on its own — so it was also proven positively at
runtime.

## 6 — Runtime proof (AUDIT A) — **RAW MATERIALS CAN BE SOLD AND RESERVED**

Real order, real HTTP transition, real reservation path, `ecos_dev_test`:

```
AUDIT A — RAW MATERIAL DIRECT SALE
  product_type              = raw_material
  order line accepted       = YES
  transition http           = 200
  order status              = ready_for_dispatch
  order reservation_status  = reserved
  RM inventory reserved_qty = 6
  VERDICT: raw material CAN carry an order reservation
```

A product with `product_type = raw_material` was accepted as an order line, the order reserved successfully,
and **`inventory_items.reserved_qty` for that raw material became 6**. There is no business guard preventing
it — proven positively, not inferred from a missing rule.

---

# AUDIT B — DOES `SCHEDULED` CREATE RESERVATION?

## 7 — Static evidence

`MarkRescheduledWorkflow::execute()` performs exactly one action:

```php
$order->update(['status' => OrderStatus::Scheduled]);
```

No `ReserveOrderInventoryAction`, no `ReserveStockAction`. Its own docblock: *"Any existing inventory
reservation is preserved."*

## 8 — Runtime proof (AUDIT B)

```
AUDIT B1 — new -> in_progress (ProcessOrderWorkflow)
  http = 200   status = in_progress
  reserved before/after = 0 / 4
  VERDICT: entering in_progress CREATES a reservation

AUDIT B2 — in_progress -> scheduled (MarkRescheduledWorkflow)
  http = 200   status = scheduled
  reserved after in_progress = 4
  reserved after scheduled   = 4
  VERDICT: scheduled does NOT create reservation and RETAINS the existing one
```

## 9 — Classification — **NOT A GAP**

| Question | Answer |
| --- | --- |
| Does entering `scheduled` create a reservation? | **NO** |
| Does a `scheduled` order hold a reservation? | **YES — inherited** |
| Can an *unreserved* order reach `scheduled`? | **NO** |

The last row is what closes it: `'reschedule' => [InProgress → Scheduled]` is the **only routed edge** into
`Scheduled` (`V3TransitionResolutionTest::routedEdges`), and entering `in_progress` always reserves (B1).
Therefore **every `scheduled` order is already reserved by inheritance.**

**Classification: CURRENTLY IMPLEMENTED (by inheritance).** The business statement *"reservation applies to
scheduled"* is satisfied in effect. There is **no reservation gap** to record, and nothing to fix. Recorded
as an observation only: the reservation *trigger* is the `in_progress` transition, not the `scheduled` one.

---

# AUDIT C — WHICH CONTRACT APPLIES

## 10 — **CASE 1 applies**

> *Raw Materials can be directly sold and therefore can carry legitimate Order reservations.*

Proven by AUDIT A at runtime (`reserved_qty = 6` on a `raw_material`). CASE 2 is disproven: raw-material
`reserved_qty` is **not** structurally zero.

## 11 — Does reservation overlap Preparation demand?

This is the crux of the double-counting concern, and the answer differs by product role:

* **Finished goods — overlap is REAL.** The order that put the FG into the wave is the same order whose
  reservation locked that FG. Subtracting there would double-count. **Your premise is correct for FGs.**
* **Raw materials — NO overlap.** `ReserveOrderInventoryAction` reserves **`$line->product_id`** — the
  *ordered* product — at every one of its four `ReserveStockAction` call sites (lines 127, 162, 190, 224).
  It never reserves a BOM component. Preparation's own soft reservations go to a different table.
  Manufacturing consumes components from `on_hand`, leaving `reserved` untouched.

`MaterialDemandCalculator` operates **only on raw materials** (BOM explosion output). Therefore the
double-counting scenario does not arise in this calculator.

---

## 12 — Terminology correction (AUDIT D)

Stated precisely:

| | |
| --- | --- |
| Failing assertion | **`available_qty`** — *not* `missing_qty` |
| Location | `MaterialDemandCalculatorTest.php:154` (assertion), reported at line 153 |
| Fixture | `on_hand = 15`, `reserved = 8`, `required = 10` |
| Expected by test | `available = 7` |
| Actual current | `available = 15` |
| Second assertion (never reached) | `missing_qty` expected `3` |

The brief's illustrative `10 / 10 / 3` figures do not match the real fixture. The `15.0` in the failure
message is the **actual `available_qty`**, not a missing quantity. The test has **not** been rewritten.

## 13 — Current calculation formula

```php
$onHand   = (float) $stockRow->on_hand_qty;
$reserved = (float) $stockRow->reserved_qty;   // fetched…
$available = max(0.0, $onHand);                // …then deliberately discarded (line 116)
$missing   = max(0.0, $required - $available);
```

`reserved` is still **emitted** in the output row (`'reserved_qty' => round($reserved, 4)`) — reported to
consumers but excluded from the arithmetic.

## 14 — Root cause

Not a coding slip. Line 116 discards `reserved` deliberately, justified in-code as *"order-level soft
reservations are volatile … should not affect manufacturing demand calculations."* That rationale rests on a
factual error: these are **not** soft reservations. Soft reservations live in
`preparation_inventory_reservations`. `inventory_items.reserved_qty` is a **hard** order reservation, and
`ShipStockAction` consumes it at shipment.

The calculator is also the outlier against the platform's own contract:

| Source | Expression |
| --- | --- |
| `ManufacturingAvailabilityService:58` (ADR-027 §16.3 sole authority) | `SUM(GREATEST(on_hand_qty - reserved_qty, 0.0))` |
| `InventorySummaryService:22` | `available = Σ max(on_hand − reserved, 0)` |

## 15 — Is the existing test still valid?

**Yes.** It asserts the platform-wide availability contract, and its fixture — a raw material carrying
`reserved = 8` — is now **proven reachable in production** (AUDIT A). No change to the test is required.

## 16 — Remaining ambiguity

**None material.** Both contracts the previous review flagged as blocking are now resolved by runtime
evidence:

| Previously open | Resolved by |
| --- | --- |
| Can raw materials be sold directly? | **YES** — AUDIT A runtime, `reserved_qty = 6` |
| Does `scheduled` reserve? | **Retains, never creates**; unreserved `scheduled` unreachable — AUDIT B |

One secondary design question remains, and it does **not** block the decision: should `available_qty` in
`wave_material_demand` be interpreted as a *planning* figure or an *execution* figure? Under Decision A it is
an execution figure ("free to consume"), which is also what `SoftReservationService` needs, since it sizes
soft reservations as `min(quantity_required, quantity_available)`.

## 17 — Recommended implementation change (for a SEPARATE task — not authorised here)

One line, `MaterialDemandCalculator.php:116`:

```php
- $available = max(0.0, $onHand);
+ $available = max(0.0, $onHand - $reserved);
```

plus replacing the now-disproven comment. **No schema change. No API contract change** — `available_qty`,
`reserved_qty` and `missing_qty` are already emitted; only `available_qty`'s value changes.

**De-risking data**, measured during the reverted attempt and then rolled back:

```
A: on_hand=10 reserved=0  required=10 -> available=10 missing=0
B: on_hand=10 reserved=3  required=10 -> available=7  missing=3
C: on_hand=10 reserved=15 required=10 -> available=0  missing=10   (zero floor held)
D: on_hand=5  reserved=2  required=10 -> available=3  missing=7
E: on_hand=50 reserved=5  required=10 -> available=45 missing=0
MULTI: A available=7 missing=3 | B available=8 missing=2           (materials independent)
```

Full `tests/Feature/Operations` with that change: **231 tests, 783 assertions, no new failures** — only the
two already-classified pre-existing/environment items. The change is mechanically safe.

## 18 — FINAL DECISION (AUDIT E)

# A: MATERIALDEMAND MUST USE ON_HAND_MINUS_RESERVED

**Why reserved represents competing demand rather than the same Preparation demand:**

1. **Orders reserve finished goods, never BOM components.** `ReserveOrderInventoryAction` passes
   `product_id: $line->product_id` at all four reservation call sites. A wave's own orders reserve the
   *finished good* they ordered — they never touch the raw material's `reserved_qty`. So the raw material's
   reservation cannot be the wave's own demand.
2. **A raw material's reservation comes from a different customer's order.** Proven at runtime: a
   `raw_material` product was sold directly, and `inventory_items.reserved_qty` became 6. Those units are
   physically on the shelf but already committed to someone else.
3. **Preparation's own demand is tracked elsewhere.** `SoftReservationService` writes
   `preparation_inventory_reservations`, a separate table this calculator never reads — so there is no path
   by which Preparation's own intent inflates `reserved_qty`.
4. **Manufacturing consumes from `on_hand`, not from `reserved`.** `InventoryMutationAdapter` decrements
   on-hand and leaves reserved untouched, so a reserved unit is never released by consumption — it stays
   committed until shipped or released.
5. **The consequence of ignoring it is overselling.** With `on_hand` alone, a wave requiring 10 of a material
   with `on_hand = 15, reserved = 8` reports `missing = 0` and full coverage, then competes at execution time
   for 8 units owed to another customer. Under Decision A it correctly reports `available = 7, missing = 3`.
6. **It restores consistency with the single authority.** ADR-027 §16.3 names
   `ManufacturingAvailabilityService` the sole authority for material availability, and it subtracts
   reserved. Two engines currently disagree about the same question; Decision A removes that.

**Scope note:** this decision applies to `MaterialDemandCalculator`, which handles raw materials only. It does
**not** generalise to finished-good preparation demand, where your double-counting argument does hold — no
change is proposed there, and none is implied.

## 19 — Recommended test cases (for the implementation task)

1. `reserved = 0` → `available = on_hand` (guards the common path; both candidate rules agree).
2. `reserved > 0` → `available = on_hand − reserved` (the decided rule; the existing test already covers this
   at `15 / 8 / 10 → 7 / 3` and needs **no** modification).
3. `reserved > on_hand` → zero floor; availability never negative.
4. `required ≤ available` → `missing = 0`; never negative.
5. Two materials, different reserved levels → independence; no aggregation before subtraction.
6. A material that is **also** directly ordered → the AUDIT A scenario, asserted end-to-end.
7. `coverage_pct` consistency with the decided `available`.
8. Regression: `SoftReservationService` soft-reserves `min(required, available)` against the new
   `available` — confirm wave soft-reservation volumes remain sane.

## 20 — Attestations

* **No production code, test, migration or expectation was changed.** The earlier attempt was reverted in
  full and verified; final diff unchanged at 8 files, +306/−27.
* The audit probe was staged outside the repository, run in the container, and deleted (verified `REMOVED`).
  The worktree never contained it.
* `MaterialDemandCalculator`, Reservation, F4, Option B, Preparation, Order statuses and the Scheduled
  workflow were **not** modified. F4/Option B frozen at 3 files, +71/−18.
* No decision was made by assumption: both open contracts were closed with runtime evidence.
* MAIN untouched — `ecos_erp` 551 tables / 2 orders. Nothing committed.

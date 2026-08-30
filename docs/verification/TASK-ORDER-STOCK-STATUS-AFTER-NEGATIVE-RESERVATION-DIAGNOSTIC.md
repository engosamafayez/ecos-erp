# TASK — ORDER STOCK STATUS AFTER NEGATIVE-STOCK RESERVATION

**Date:** 2026-08-14 · **Branch:** `develop` · **Files changed: NONE**
**Outcome:** **NO DEFECT FOUND — the current behaviour already matches your contract.**
**Status:** STOPPED before implementation, per Part 11 and Part 12.

---

## 1 — Root Cause

**`ORD-00005` is `awaiting_stock` because the FINISHED GOOD is unavailable — not because of any raw
material, and not because of a negative-availability misclassification.**

The premise of the task is that the order was placed "لمنتج متوفر" (for an available product). The
canonical data says otherwise:

| Product | on_hand | reserved | **available** | allow_negative | can_manufacture |
|---|---|---|---|---|---|
| **FG-000001** (the ordered product) | 5.0000 | **5.0000** | **0.0000** | **0** | **0** |
| RM-000001 | 0.0000 | 1.0000 | −1.0000 | 1 | 0 |
| RM-000002 | 0.0000 | 1.0000 | −1.0000 | 1 | 0 |

FG-000001 has 5 units on hand, but **all 5 are already reserved** by earlier orders:

| Order | qty | reserved_qty |
|---|---|---|
| ORD-00001 | 2 | **2** |
| ORD-00003 | 1 | **1** |
| ORD-00004 | 2 | **2** |
| | | **5 of 5 consumed** |
| **ORD-00005** | **1** | **0** ← nothing left |

So at the moment ORD-00005 was placed: **FG available = 0, required = 1.**

### The exact decision path

`ReserveOrderInventoryAction`, for the FG line:

| Gate | Condition | Result for ORD-00005 |
|---|---|---|
| CASE 1 — physical stock | `$available >= $requested` → `0 >= 1` | **false** |
| CASE 2 — manufacture the shortfall | `can_manufacture` on FG-000001 = **0** | **skipped** |
| CASE 3 — negative-stock commitment | `$product?->allow_negative_stock` on FG-000001 = **0** | **skipped** |
| fall-through | `$failReason ??= 'Insufficient Inventory'` | → `awaiting_stock` |

`reservation_failure_reason` in the database is literally **"Insufficient Inventory"**, written by that
fall-through. All three gates were evaluated and all three legitimately declined.

### Why this is CORRECT under your own contract

Part 1 states: *"Finished Product — إذا كانت كمية المنتج النهائي المطلوبة متاحة في المخزون:
FG Available >= Required فيمكن حجز المنتج النهائي."* Here `0 >= 1` is false.

Part 6 **Case D** states: *"FG unavailable → Order remains blocked according to existing canonical FG
reservation rules."*

**ORD-00005 is Case D.** `awaiting_stock` is the specified outcome.

### Why the raw materials are a red herring here

RM-000001 and RM-000002 do show `available = −1` with `allow_negative_stock = ON`, and that is
**working correctly** — the full quantity was reserved and availability was allowed to go negative,
exactly as Part 1 requires. But those reservations belong to a different flow and are **not** what
blocked ORD-00005. The block happened at the finished good, before raw materials were ever consulted.

The scenario you described — *"FG available + RM shortage + Allow Negative ON → should be In
Progress"* (**Case B**) — is a real and correct rule, but **ORD-00005 is not an instance of it.**

---

## 2 — Files Changed

**NONE.** Part 11 requires a proven root cause before implementation, and Part 12 requires me to stop
and report before widening scope. The root cause turned out not to be a defect, so there was nothing
to repair.

---

## 3 — Exact Behaviour Before

```
ORD-00005  status = awaiting_stock
           reservation_status = awaiting_stock
           reservation_failure_reason = "Insufficient Inventory"
           order_lines.reserved_qty = 0
FG-000001  on_hand 5 · reserved 5 · available 0 · allow_negative 0 · can_manufacture 0
```

## 4 — Exact Behaviour After

**Identical — nothing was changed.**

---

## 5 — Reservation Behaviour

The reservation engine did precisely what the contract specifies:

- It did **not** reserve the FG, because `available (0) < required (1)` and the FG carries
  `allow_negative_stock = 0`.
- It did **not** attempt manufacturing, because FG-000001 has `can_manufacture = 0` — which per the
  approved Q5 resolution is a **valid** configuration meaning "this product is not order-manufactured",
  not an error.
- It recorded `reservation_failure_reason = "Insufficient Inventory"` and routed to `awaiting_stock`.

Separately, and correctly, the **negative-stock reservation path is already implemented and working**.
`ReserveOrderInventoryAction` carries an explicit `TASK-REGRESSION-NEGATIVE-RESERVATION-001` block
stating that `ReserveStockAction` now honours `allow_negative_stock` and commits the **full** requested
quantity, letting `available` go negative — and the RM rows prove it at runtime (`reserved = 1`,
`available = −1`).

---

## 6 — Order Status Behaviour

`awaiting_stock` is correct for ORD-00005 and should not be changed.

**Making this order `in_progress` would require allowing a finished good with
`allow_negative_stock = 0` to be reserved below zero.** That would directly contradict:

- Part 1: *"إذا Available < Required و allow_negative_stock = false → المادة تمنع التنفيذ"*
- Part 6 Case D
- Acceptance criterion *"RM Allow Negative OFF + shortage → Awaiting Stock"*

So the change implied by the symptom would break three of your own stated rules. I did not make it.

---

## 7 — Raw Material Numbers Before / After

**Unchanged, and correct:**

```
RM-000001  on_hand 0 · reserved 1 · available −1 · allow_negative ON
RM-000002  on_hand 0 · reserved 1 · available −1 · allow_negative ON
```

`Available = On Hand − Reserved` is intact. No formula was touched. Nothing was clamped to 0, no
reservation was cancelled, and no `MaterialDemandCalculator` or availability formula was modified
(Part 4, Part 12).

---

## 8 — Any Remaining Issue

**No defect in the reservation or status classifier.** Three things are worth your attention, none of
which I acted on:

1. **The premise needs re-testing with an actually-available FG.** To exercise **Case B** (the rule you
   want verified), the finished good must have `available >= required` at the moment of ordering. Today
   FG-000001 is fully committed (5 of 5). Either release/ship an existing reservation, add FG stock, or
   place the test order against a different finished good.

2. **`FG-000001.allow_negative_stock = 0`.** If the business intent is that this finished good *may* be
   oversold, that flag is the single switch — flipping it would send ORD-00005 down CASE 3 and reserve
   the full quantity. **That is a product-configuration decision, not a code fix**, and Part 12 forbids
   me changing product flags. Related: an earlier task explicitly ruled `can_manufacture = 0` alongside
   an active BOM to be a **valid** configuration, so this FG is deliberately neither manufactured nor
   oversellable as configured.

3. **A UI wording gap, matching your Part 5.** The Raw Materials screen shows `available = −1` with
   `allow_negative = ON` as **"Out of Stock"**. Per the earlier GD-2 ruling that label reflects measured
   availability only and is *not* used as a workflow blocker — so no business logic is wrong — but it
   is the presentation issue already raised as decisions D-A/D-B in
   `TASK-INV-NEGATIVE-STOCK-SEMANTICS-AND-RESERVATION-001`, still awaiting your ruling.

---

## Acceptance Criteria

| Criterion | Status |
|---|---|
| FG available + RM available → In Progress | **not exercised** — no such order exists today |
| FG available + RM shortage + Allow Negative ON → In Progress | **not exercised** — FG was unavailable (see §8.1) |
| RM Allow Negative ON → full RM reservation occurs | ✅ **already true** (`reserved = 1` on both RMs) |
| RM Available can become negative | ✅ **already true** (`−1` on both RMs) |
| Negative Available does NOT create Stock Block | ✅ the block came from the FG, not from negative RM availability |
| RM Allow Negative OFF + shortage → Awaiting Stock | ✅ this is exactly what happened, at the FG |
| No change to On Hand / Available / Reserved formulas | ✅ nothing changed |
| No WooCommerce `stock_status` dependency | ✅ the decision path reads `InventoryItem::availableQty()`, never `stock_status` |
| No new duplicate reservation system | ✅ nothing added |
| ADR-027 v1.3 architecture preserved | ✅ untouched |
| No automated tests | ✅ none run |
| No certification | ✅ none claimed |
| Stop after implementation and wait for review | ✅ **stopped** |

---

**Recommendation:** re-run the scenario against a finished good that genuinely has
`available >= required`. If ORD-00005's behaviour is still considered wrong after that, then the real
question is whether **FG-000001 should carry `allow_negative_stock = 1`** — a product-configuration
decision that needs your explicit approval before I touch it.

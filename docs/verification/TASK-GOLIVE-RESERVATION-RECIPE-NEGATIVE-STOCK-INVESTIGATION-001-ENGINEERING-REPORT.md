# TASK-GOLIVE-RESERVATION-RECIPE-NEGATIVE-STOCK-INVESTIGATION-001
## Recipe Availability & Negative Inventory — Engineering Report

**Date:** 2026-08-09 · **Base:** `6149875b` · **INVESTIGATION ONLY — no code, test, DB or commit change.**

---

# 1 — EXECUTIVE SUMMARY

| Hypothesis | Verdict |
| --- | --- |
| **A — "One non-negative raw-material shortage makes the entire recipe unavailable"** | ✅ **CONFIRMED — certified from two independent implementations** |
| **B — "Negative inventory from an allowed order stays valid after dispatch and is reduced by normal procurement"** | ⛔ **UNVERIFIED — not traced. STOP condition 3 applies** |

## ⚠️ The finding that matters most

**Hypothesis A is true for the *display* signal, and deliberately NOT true for *order reservation*.**

`ReserveOrderInventoryAction:130-134` states it explicitly:

> *"`can_manufacture=true` → Reserve Finished Product commitment **unconditionally**. Manufacturing
> owns all Raw Material decisions; **no RM condition gates reservation**. `PrepareOrderManufacturingAction`
> evaluates RM after the order enters Preparing."*

So a manufacturable finished good is reserved **even when a recipe component is short and that
component forbids negative stock.** The recipe block appears in `manufacturing_availability` (what
the grid shows), not in the reservation gate.

**This is ADR-027 working as designed, not a defect — but it means the two rules answer different
questions and must not be conflated.**

---

# 2 — HYPOTHESIS A — CONFIRMED (Parts 2, 3)

## 2.1 Evidence 1 — the service

`ManufacturingAvailabilityService` docblock (`:11-19`):

> *"A material is considered available when: `available_qty > 0` **OR** `allow_negative_stock = true`*
> *Possible statuses: `'instock'` — **all** recipe materials are available · `'outofstock'` — **at least
> one** material is unavailable · `'recipe_missing'` — no active recipe exists."*

Implementation (`:80`):

```php
$isAvailable = $available > 0.0 || $material->allow_negative_stock;
```

**Per-material evaluation, honouring each material's own flag. One unavailable material ⇒ the whole
recipe is `outofstock`.**

## 2.2 Evidence 2 — the same rule, independently, in SQL

`EloquentProductRepository:60-89` computes `manufacturing_availability` for list queries:

```sql
WHEN EXISTS (
    SELECT 1 FROM bill_of_material_lines boml_chk
    JOIN bills_of_materials bom_chk2 ON … is_active = TRUE
    JOIN products comp_chk ON comp_chk.id = boml_chk.raw_material_id
    LEFT JOIN (…availability per component…) inv_comp ON …
    WHERE bom_chk2.product_id = products.id
      AND COALESCE(inv_comp.avail, 0) <= 0
      AND (comp_chk.allow_negative_stock IS NULL OR comp_chk.allow_negative_stock = FALSE)
) THEN 'outofstock' ELSE 'instock'
```

Literally: *"if there exists a component with availability ≤ 0 **and** `allow_negative_stock` false →
outofstock."*

**Two independent implementations, same rule. Hypothesis A is certified.**

## 2.3 Part 3 constraint — verified

**The finished product's own `allow_negative_stock` does NOT participate in recipe availability.**
Both implementations evaluate `comp_chk.allow_negative_stock` / `$material->allow_negative_stock` —
the **component's** flag, per material. The constraint holds.

---

# 3 — SCENARIO MATRIX (Parts 2, 6)

`manufacturing_availability` — the recipe-executability signal:

| Case | Components | Result | Status |
| --- | --- | --- | --- |
| **A** | All available | `instock` | ✅ **PASS** |
| **B** | RM-C short, `allow_neg = OFF` | **`outofstock`** — whole recipe blocked | ✅ **PASS — hypothesis confirmed** |
| **C** | RM-C short, `allow_neg = ON` | `instock` — shortage carried | ✅ **PASS** |
| **D** | Several short; **one** forbids negative | **`outofstock`** — the single non-negative shortage blocks everything | ✅ **PASS — the `EXISTS` clause needs only one row** |
| **E** | All shortages permit negative | `instock` | ✅ **PASS** |

**Order-state consequence — different, and this is the crux:**

| Case | Does the order go to `AwaitingStock`? |
| --- | --- |
| Finished good **not** manufacturable, FG stock short | ✅ Yes — `ReserveOrderInventoryAction:186` skips the line → `AwaitingStock` (RC-10 certified) |
| Finished good **manufacturable** (`can_manufacture`), any RM shortage | ❌ **No** — reservation commits unconditionally (`:130-155`); RM is evaluated later in Preparing |

---

# 4 — THE FIVE CONCEPTS, SEPARATED (Part 5)

| # | Concept | Rule | Honours `allow_negative_stock`? | Drives `AwaitingStock`? |
| --- | --- | --- | --- | --- |
| 1 | **Physical availability** — `availability_state` | `null → Untracked · ≤0 → OutOfStock · >0 → InStock` | ❌ No | ❌ No |
| 2 | **Manufacturing availability** | `avail > 0 OR allow_neg`, **per component**, ALL must pass | ✅ Yes — component's flag | ❌ **No** |
| 3 | **Recipe executability** | Same as #2 — it *is* #2 | ✅ Yes | ❌ No |
| 4 | **Reservation availability** | 5-branch ladder; `can_manufacture` ⟩ `allow_negative_stock` ⟩ physical | ✅ Yes — **product's own** flag | ✅ **YES** |
| 5 | **Order readiness** | `MoveToPreparationWorkflow` guard + reservation status | Indirectly | ✅ Yes |

# **Only #4 drives `AwaitingStock`.** #2/#3 are a *reporting* signal, not a gate.

---

# 5 — `can_manufacture` PRECEDENCE (Part 4)

**Investigated separately, as instructed — and the precedence is NOT the same.**

| Path | Precedence |
| --- | --- |
| **Finished-product reservation** | `can_manufacture` **outranks** `allow_negative_stock` (`:130` before `:162`) |
| **Recipe/component availability** | `can_manufacture` **plays no part**. Only `avail > 0 OR allow_neg`, per component |

**Raw materials do not carry `can_manufacture` semantics in the recipe evaluator.** The resolver
delegates nothing — it evaluates each component directly.

---

# 6 — RESERVATION BOUNDARY (Part 7) ✅ ADR-027 intact

| Owner | Responsibility |
| --- | --- |
| **Orders** | Reserve **finished goods only** |
| **Manufacturing** | Recipe/BOM, raw-material availability, RM reservation/execution, manufacturing failure |

**Where recipe availability enters the order path:** it **does not** enter the reservation gate. It
is surfaced on the product read model (`ProductResource:160`, `manufacturing_availability`) and
evaluated for real by `PrepareOrderManufacturingAction` **after** the order reaches Preparing.
Ownership unchanged.

---

# 7 — ⛔ HYPOTHESIS B — UNVERIFIED (Parts 8–14)

**Not traced. I am not guessing, and I will not certify from naming.**

| Part | Question | Status |
| --- | --- | --- |
| 8 | Dispatch → consumption → DirectIssue → ledger → on_hand | ❌ **UNVERIFIED** — `ShipOrderInventoryAction` FIFO/DirectIssue path not read |
| 9 | Is `on_hand = -10` a valid supported state? Does Dispatch succeed, Delivered reachable, ledger record −10? | ❌ **UNVERIFIED** |
| 10 | Does Goods Receipt `+10` naturally return −10 → 0, or is explicit reconciliation required? | ❌ **UNVERIFIED** |
| 11 | Multiple purchases (+6, +4) against −10 | ❌ **UNVERIFIED** |
| 12 | Does an already-Delivered order stay put when inventory goes negative? | ⚠️ **PARTIAL** — `MoveToReviewWorkflow` / `MarkRescheduledWorkflow` block `Delivered`/`OutForDelivery`, and `OrderStatusGuard` forbids status writes outside `FulfillmentEngine`, so **no automatic regression is plausible** — but no test proves it |
| 13 | Does later procurement rewrite a completed order? | ⚠️ **PARTIAL** — same reasoning; unproven |
| 14 | FIFO/costing under negative stock; any debt-layer mechanism | ❌ **UNVERIFIED** |

## What IS established about B

`ReserveOrderInventoryAction:157-161`:

> *"the remainder is a logical commitment that **drives inventory negative at shipment time
> (DirectIssue path)**."*

**So the architecture explicitly anticipates negative inventory at shipment.** That is design intent,
documented. **It is not proof that the downstream ledger, FIFO and replenishment behave correctly.**

**STOP condition 3 applies** — *"Negative inventory after Delivered has no defined contract"* is not
disproven, and I did not read the code that would define it.

---

# 8 — TENANT ISOLATION (Part 16) ✅

Recipe evaluation scopes components through `bills_of_materials` → `products` → per-product inventory
aggregation. Reservation carries `company_id` **and** `warehouse_id` on every `StockOperationDTO`.
The SQL evaluator aggregates `inventory_items` per component without a company predicate **inside the
subquery** — but the outer `Product` query is company-scoped (Step 3, certified).

> ⚠️ **Worth a targeted check later:** whether the component-availability subquery
> (`EloquentProductRepository:77-83`) should also be company-scoped. It aggregates
> `inventory_items` by `product_id` only. **Not asserted as a defect** — the outer scope may make it
> unreachable. **UNVERIFIED.**

**RC-6 and D-8 untouched.**

---

# 9 — TEST COVERAGE (Part 15) — gaps only, none created

| Priority | Scenario | Coverage |
| --- | --- | --- |
| **1** | One RM blocks an entire recipe | ❌ **NONE** |
| **2** | All RMs allow negative | ❌ **NONE** |
| **3** | Mixed RM policies (Case D) | ❌ **NONE** |
| **4** | Negative inventory at dispatch | ❌ **NONE** |
| **5** | Negative inventory after Delivered | ❌ **NONE** |
| **6** | Goods Receipt reducing a negative balance | ❌ **NONE** |
| — | FG shortage → `AwaitingStock` | ✅ RC-10 certified |
| — | `can_manufacture` reservation commitment | ❌ NONE (carried from the previous investigation) |

**All six priority scenarios are untested.** Priorities 4–6 concern a state the architecture
deliberately creates and no test observes.

---

# 10 — FINDINGS

| # | Finding | Severity |
| --- | --- | --- |
| **F1** | Hypothesis A **confirmed** by two independent implementations | ✅ Certified |
| **F2** | **Recipe availability does NOT gate order reservation.** `can_manufacture` bypasses it entirely (ADR-027) | **High — likely surprising** |
| **F3** | Recipe availability correctly uses the **component's** flag, not the finished good's | ✅ Constraint holds |
| **F4** | `can_manufacture` has **no role** in recipe evaluation — different precedence from reservation | Medium |
| **F5** | Hypothesis B entirely **UNVERIFIED**; downstream negative-stock contract not established | **High** |
| **F6** | Six priority scenarios have **zero test coverage** | **High** |
| **F7** | Component-availability subquery may lack a company predicate | **UNVERIFIED — needs a targeted check** |

---

# 11 — CONTRADICTIONS

**One, and it is a conceptual conflict rather than a bug:**

> The business hypothesis assumes *"one non-negative RM shortage makes the recipe unavailable"* →
> therefore the order should be blocked. **The platform blocks the *signal* but not the *order*.**

Under ADR-027 that is deliberate: Orders reserve FG; Manufacturing decides RM later. **But an
operator reading `Out of Stock` on the product grid may reasonably expect the order to be refused —
and it will not be.**

---

# 12 — REQUIRED OWNER DECISIONS

| # | Decision |
| --- | --- |
| **1** | **Is F2 acceptable?** Should a manufacturable product with a blocked recipe still accept and commit orders (current, ADR-027), or should recipe availability gate reservation? *Changing this reopens ADR-027 and RC-10.* |
| **2** | **Is negative inventory after Delivered a supported end state?** Needed before Hypothesis B can be certified either way. |
| **3** | **Does procurement implicitly repay negative stock, or is explicit reconciliation required?** Currently **UNVERIFIED** — an accounting-policy question as much as an engineering one. |

---

# 13 — ENGINEERING RECOMMENDATION

1. **Certify Hypothesis A now** — it is proven twice over. No change needed.
2. **Do not change F2.** It is ADR-027 operating correctly. If the operator expectation is the real
   concern, the fix is *presentational* (surface recipe status on the order), not architectural.
3. **Commission the Hypothesis B trace** as its own task — `ShipOrderInventoryAction` DirectIssue,
   the stock ledger, FIFO layers under negative balance, and Goods Receipt replenishment. **That is
   the real open risk**, and it sits on the Pilot path whenever `allow_negative_stock` is ON.
4. **Then close the six coverage gaps** (§9), priorities 4–6 first.

**Pilot impact:** none proven today. With `allow_negative_stock = OFF` the certified path
(`AwaitingStock`) is safe and tested. **The exposure exists only where the flag is ON**, and the
register already records that flag's default as an open GD-2 governance item.

---

# 14 — EXACT NEXT TASK

**`TASK-GOLIVE-NEGATIVE-STOCK-LIFECYCLE-INVESTIGATION-001`** — trace Parts 8–14 end to end
(`ShipOrderInventoryAction` → DirectIssue → ledger → FIFO → Goods Receipt replenishment), answer
owner decisions 2 and 3, and confirm or refute F7. **Investigation only.**

---

**INVESTIGATION ONLY — honoured. No code, test, database, migration, deployment or commit change. No
hypothesis assumed true. Every confirmed statement cites a file and line; everything untraced is
marked UNVERIFIED rather than inferred. ADR-027, RC-10, RC-6 and D-8 untouched.**

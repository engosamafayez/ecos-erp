# TASK-GOLIVE-RECIPE-TO-ORDER-AVAILABILITY-E2E-001 — Engineering Report
## Recipe → Product → Reservation → Order Status

**Date:** 2026-08-09 · **Base:** `6149875b` · **Runtime evidence, database-backed. No business logic changed.**

---

# 1 — EXECUTIVE SUMMARY

# ⛔ STOP — **CONFIRMED BUSINESS-RULE INTEGRATION GAP**, plus an unexpected tenant finding

**Runtime output (verbatim, `OK (7 tests, 12 assertions)`, 6m43s):**

```
===== RECIPE->ORDER E2E EVIDENCE =====
  A: recipe=instock    | http=200 | order=ready_for_dispatch | reservation=reserved | reserved_qty=1.00
  B: recipe=outofstock | http=200 | order=ready_for_dispatch | reservation=reserved | reserved_qty=1.00
  C: recipe=instock    | http=200 | order=ready_for_dispatch | reservation=reserved | reserved_qty=1.00
  E: recipe=outofstock | http=200 | order=ready_for_dispatch | reservation=reserved | reserved_qty=1.00
  TENANT: recipe=instock (companyA=0, companyB=100)
======================================
```

## The four questions, answered by runtime

| # | Question | Answer |
| --- | --- | --- |
| **Q1** | Recipe available → product available → order reserves? | ✅ **YES** (Test A) |
| **Q2** | Recipe unavailable + `allow_negative = OFF` → order goes to `AwaitingStock`? | ❌ **NO** — order reached **`ready_for_dispatch`**, fully **reserved** |
| **Q3** | Shortage permitted by `allow_negative_stock` → order continues? | ✅ **YES** (Test C) |
| **Q4** | Does `can_manufacture` ever bypass an unavailable recipe? | ❌ **YES IT DOES** — expected business answer was **NO** |

**Test B and Test E are identical in outcome: `recipe = outofstock` → order fully reserved and
advanced to `ready_for_dispatch`.** The hypothesis is proven at runtime, not inferred.

**Five STOP conditions fired: 1, 2, 3, 4 and 6.** No fix attempted.

---

# 2 — FIXTURE ARCHITECTURE (Part 1)

Built on existing conventions from `RecipeResolverTest` — **no BOM factory was created**:

| Element | Construction |
| --- | --- |
| Finished good | `Product::factory()->finishedGood()->manufacturable()` — `can_manufacture = true` |
| Raw materials | `Product::factory()->rawMaterial()->create(['allow_negative_stock' => …])` |
| Recipe | `Recipe::create([bom_number, product_id, version, bom_version_number, is_active])` |
| Components | `$recipe->components()->create(['raw_material_id', 'quantity'])` |
| Inventory | Real `InventoryItem` rows, company- and warehouse-scoped |
| Order | Real `Order` + `OrderLine`, company + assigned warehouse |

**Finished-good stock deliberately set to 0** in Tests B and E, so the only thing that could justify a
reservation is the recipe.

---

# 3 — REAL RUNTIME PATH (Part 7)

```
POST /api/fulfillment/orders/{order}/transition   target_status = ready_for_dispatch
 → auth:sanctum + permission:operations.fulfillment.manage
 → FulfillmentController::transition()
 → resolveTransitionWorkflow('in_progress','ready_for_dispatch') → MoveToPreparationWorkflow
 → FulfillmentEngine::run() → guard() → DB::transaction()
 → MoveToPreparationWorkflow::execute() → ReserveOrderInventoryAction::execute()
 → persisted Order.status + Order.reservation_status + OrderLine.reserved_qty
```

**No mocks.** Real HTTP, real controller, real engine, real guard, real transaction, real persistence.
A non-`is_system` operator holding only `operations.fulfillment.manage` was used.

---

# 4 — SCENARIO RESULTS

| Test | Setup | Recipe | Order status | Reservation | `reserved_qty` | Result |
| --- | --- | --- | --- | --- | --- | --- |
| **A** | RM-A 10 (OFF), RM-B 10 (OFF) | `instock` | `ready_for_dispatch` | `reserved` | 1.00 | ✅ **PASS** |
| **B** | RM-A 10 (OFF), **RM-B 0 (OFF)** | **`outofstock`** | **`ready_for_dispatch`** | **`reserved`** | **1.00** | 🔴 **FAIL vs rule** |
| **C** | RM-A 10 (OFF), RM-B 0 (**ON**) | `instock` | `ready_for_dispatch` | `reserved` | 1.00 | ✅ **PASS** |
| **D1** | RM-A 10 (OFF), RM-B 0 (**ON**) | `instock` | — | — | — | ✅ **PASS** |
| **D2** | RM-A 10 (**ON**), **RM-B 0 (OFF)** | **`outofstock`** | — | — | — | ✅ **PASS** — per-component proven |
| **E** | Single RM 0 (OFF), FG stock 0 | **`outofstock`** | **`ready_for_dispatch`** | **`reserved`** | **1.00** | 🔴 **FAIL vs rule** |

## 4.1 Test D proves the per-component rule (Part 5)

**D2 is the key row:** RM-A *allows* negative and is fully stocked; RM-B forbids negative and is at
zero. **The recipe is `outofstock`.** One non-negative shortage blocks the whole recipe, exactly as
hypothesised — the `EXISTS` clause needs only one qualifying row.

**Recipe availability is computed correctly. It simply is not consulted by reservation.**

---

# 5 — THE DISCREPANCY (Part 16)

## 5.1 Exact mechanism

`ReserveOrderInventoryAction:130-134`, reached before any shortage handling:

```php
if ($product?->can_manufacture) {
    // can_manufacture=true → Reserve Finished Product commitment unconditionally.
    // Manufacturing owns all Raw Material decisions; no RM condition gates reservation.
    $line->update(['reserved_qty' => $requested]);   // ← full commitment
    continue;
}
```

**`manufacturing_availability` is never read by the reservation path.** The branch is entered on
`can_manufacture` alone, ahead of the `allow_negative_stock` branch (`:162`) and ahead of the
zero-availability branch (`:186`) that produces `AwaitingStock`.

## 5.2 Persisted state after Test B

- `OrderLine.reserved_qty = 1.00` — a full commitment for a product whose recipe cannot be supplied
- `Order.reservation_status = reserved` — **not** `PartialReserved`, **not** `AwaitingStock`
- `Order.status = ready_for_dispatch` — the order is presented as ready to ship
- FG inventory was 0 and unchanged; no FIFO consumption at this stage

**No partial recipe commitment occurred (Part 9): the commitment is total, not partial.**

## 5.3 Relationship to ADR-027 and RC-10

**This is ADR-027 executing as written** — *"Orders reserve FG only; Manufacturing owns all RM
decisions"*, with RM evaluated later by `PrepareOrderManufacturingAction` in Preparing.

**It does not conflict with RC-10.** RC-10's certified
`test_insufficient_stock_diverts_to_awaiting_stock` uses a **non-manufacturable** product, which
takes the `:186` branch and correctly reaches `AwaitingStock`. Both behaviours coexist because they
are different branches. **RC-10 regression: none — the two are not in tension.**

## 5.4 What it means operationally

**An order for a manufacturable product whose recipe cannot be supplied is accepted, reserved and
marked ready for dispatch.** The product grid shows `Out of Stock` at the same moment. The
contradiction surfaces to the operator only later, in Preparing.

---

# 6 — 🔴 UNEXPECTED FINDING: cross-company recipe satisfaction

```
TENANT: recipe=instock (companyA=0, companyB=100)
```

**Company A's recipe evaluated as `instock` while Company A holds zero of the component and only
Company B holds stock.**

This confirms the F7 suspicion recorded in the previous investigation: the component-availability
aggregation (`EloquentProductRepository:77-83`, and the equivalent in
`ManufacturingAvailabilityService`) groups `inventory_items` by `product_id` **with no company
predicate**.

| | |
| --- | --- |
| **Severity** | **P1 under multi-tenant.** **Not exploitable under OD-2 = PILOT** (single company) |
| **Class** | The same fail-open family as RC-6 / D-8 — **but in the *evaluation* layer, which those repairs did not cover** |
| **Impact** | A recipe can be reported executable on another tenant's stock. **It does not transfer inventory** — reservation and shipment remain company- and warehouse-scoped (proven by RC-6/D-8 and by `ShipOrderInventoryAction`) |
| **STOP condition** | **6 — fired** |

**Not repaired.** RC-6, D-8 and `TenantOwnershipResolver` untouched.

---

# 7 — TEST COVERAGE (Part 13)

| Behaviour | Before | Now |
| --- | --- | --- |
| Recipe availability per component | ❌ none | ✅ **A, C, D1, D2** |
| One non-negative shortage blocks recipe | ❌ none | ✅ **D2** |
| `can_manufacture` reservation commitment | ❌ none | ✅ **B, E** |
| Recipe → order-status linkage | ❌ none | ✅ **A, B, C, E** |
| Cross-company recipe evaluation | ❌ none | ✅ **TENANT** |

**No existing test was modified or weakened.** The new file records observed behaviour rather than
asserting the hypothesis, so a single run produced the complete evidence set.

---

# 8 — FINDINGS

| # | Finding | Severity |
| --- | --- | --- |
| **F1** | **Recipe availability does not gate reservation.** `can_manufacture` bypasses it entirely — **proven at runtime** | **HIGH — confirmed gap** |
| **F2** | An unavailable recipe yields `ready_for_dispatch`, not `AwaitingStock` | **HIGH** |
| **F3** | Recipe availability itself is **correct** — per component, one non-negative shortage blocks all | ✅ Certified |
| **F4** | **Cross-company stock satisfies another company's recipe evaluation** | **P1 multi-tenant / P3 pilot** |
| **F5** | No partial recipe commitment — the commitment is total | Informational |
| **F6** | **No RC-10 conflict** — manufacturable and non-manufacturable take different branches | ✅ |

---

# 9 — REQUIRED OWNER DECISION

> **Should recipe availability gate finished-product reservation?**

| Option | Consequence |
| --- | --- |
| **A — Keep current (ADR-027)** | Orders accept manufacturable products regardless of RM; Manufacturing decides in Preparing. **Zero code change.** The operator-facing contradiction is addressed presentationally |
| **B — Gate reservation on recipe availability** | Matches the stated business rule. **Requires reopening ADR-027**, adding a recipe check to `ReserveOrderInventoryAction`, and re-certifying RC-10. Orders for manufacturable goods would park in `AwaitingStock` |

**I am not choosing.** ADR-027 is an approved architecture decision.

**Separately — F4 needs no business decision.** Scoping recipe component availability to the
evaluating company is a correctness fix in the same family as RC-6/D-8. It requires authorization
only because it touches a certified area.

---

# 10 — ENGINEERING RECOMMENDATION

1. **F4 first.** It is a tenant-isolation correctness bug, cheap, and independent of the ADR-027
   debate. It should not wait behind a business decision.
2. **Then decide F1/F2.** If Option A, the gap is presentational — surface `manufacturing_availability`
   on the order line so the operator sees the conflict at order time.
3. **Do not touch RC-10.** The two behaviours are branch-disjoint.

**Pilot impact:** F4 is not exploitable under a single tenant. F1/F2 do not corrupt data — they
produce an optimistic order state that Manufacturing corrects in Preparing. **Neither is a proven
Pilot release blocker**, and neither was repaired here.

---

# 11 — EXACT NEXT TASK

**`TASK-GOLIVE-RECIPE-TENANT-SCOPE-FIX-001`** — scope component availability to the evaluating
company in `ManufacturingAvailabilityService` and `EloquentProductRepository`, with a regression test
built from the `TENANT` scenario above. Bounded, no business decision required.

**Then**, once F1/F2 is decided: either a presentational task (Option A) or an ADR-027 reopening
(Option B).

---

**Runtime evidence only — no static claim marked PASS. No business logic, workflow, service or
existing test modified. No migration, deployment or commit. ADR-027, RC-10, PD-1, PD-2, RC-6, D-8 and
`TenantOwnershipResolver` untouched. One new test file added; it changes no behaviour.**

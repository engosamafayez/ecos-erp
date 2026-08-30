# T-1 — Availability Contract Audit & Reconciliation Design

**Date:** 2026-08-15 · **Branch:** `develop`
**Status:** **TRACE COMPLETE — IMPLEMENTATION NOT STARTED**
**Mode:** read-only. No code, schema, data or configuration changed.

You instructed: *"do not blindly change code. First trace every consumer."* The trace is
below, and it materially changes what the correct change is. **`Untracked` must not simply
be deleted** — it has a proven consumer under a different, non-business contract. §4 sets out
the reconciliation that satisfies the approved three-state business rule without breaking it.

---

## 1. The approved contract

```
Available >  0                              → IN STOCK
Available <= 0  AND  Allow Negative = true  → NEGATIVE ALLOWED
Available <= 0  AND  Allow Negative = false → OUT OF STOCK
```

No fourth business state. A missing inventory record must not create one.

---

## 2. Complete consumer trace

### 2.1 Backend — every reference to `AvailabilityState`

| # | Consumer | What it does | Business-facing? |
|---|---|---|---|
| B1 | `AvailabilityState` enum (`:32-58`) | 3 cases: `Untracked`, `OutOfStock`, `InStock`. **No `NegativeAllowed`.** `fromAvailable()` takes availability alone and cannot see `allow_negative_stock` | — |
| B2 | `AvailabilityState::canCommit(available, allowNegative)` (`:84-90`) | The **only** place policy is folded in. Returns bool, not a state | — |
| B3 | `InventorySummary` DTO (`:31`) | `availabilityState` defaults to `Untracked` | **No** — data platform |
| B4 | `InventorySummaryService:89` | `fromAvailable($items->isEmpty() ? null : $available)` — **deliberately** yields `Untracked` when no inventory row exists | **No** — data platform |
| B5 | `InventoryLayerController:120` | `summarize($productId)->toArray()` — the only exposure of B3/B4 | Technical (inventory layers) |
| B6 | `EloquentProductRepository:30-36` | **Preserves** the LEFT JOIN NULL so `Untracked` is reachable | Yes — feeds B8 |
| B7 | `EloquentProductRepository:178-205` | Availability **filter**: `COALESCE(avail,0)`, three branches `in_stock` / `negative_allowed` / `out_of_stock`. Comment: *"rather than a fourth 'untracked' state. Presence of an inventory record is deliberately NOT an input."* | **Yes** |
| B8 | `ProductResource:188` | `availability_state = fromAvailable(agg_available_qty)` → can emit `untracked` | **Yes** |
| B9 | `ProductResource:62` | `can_commit = canCommit(...) OR manufacturable` | **Yes** |

### 2.2 Frontend

| # | Consumer | What it does | Conforms? |
|---|---|---|---|
| F1 | `products/types/product.ts:115` | `'untracked' \| 'out_of_stock' \| 'in_stock' \| null` | **No** — 4th state, no `negative_allowed` |
| F2 | `product-detail-drawer.tsx:657-666` | Renders `in_stock`→In Stock, `untracked`→Not tracked, `out_of_stock`+`can_commit`→**Backorder Allowed**, else Out of Stock | **No** — composes the business state **client-side** from two fields |
| F3 | `raw-materials/types/index.ts:57` | `'in_stock' \| 'out_of_stock' \| 'untracked'` | **No** — no `negative_allowed` |
| F4 | `raw-material-detail-drawer.tsx:71-85` | Has an explicit `negative_allowed` branch | **Partly** — the branch exists but F3's type cannot deliver that value |
| F5 | `raw-material-filter-bar.tsx:193` / `raw-materials-page.tsx:231` | Filter values `'available' \| 'out_of_stock'` | **No** — third option missing; also `available` ≠ backend's `in_stock` |

### 2.3 i18n — three names for one concept, confirmed

| File | Keys |
|---|---|
| `en/products.json:477-479,529` | `invInStock` "In Stock" · `invBackorderAllowed` **"Backorder Allowed"** · `invOutOfStock` "Out of Stock" · `invUntracked` **"Not tracked"** |
| `en/raw-materials.json:169` | `negativeAllowed` **"Negative Allowed"** ← already the approved term |
| ADR-027:167 | **"Case 3 — Negative Stock"** |

---

## 3. The contradiction, precisely located

**Both halves live in `EloquentProductRepository`, ~150 lines apart, each documented as
deliberate:**

- `:30-36` — *"the untracked NULL is preserved … `AvailabilityState::Untracked` unreachable
  dead code"* → NULL **survives**, so `untracked` reaches the API.
- `:186-188` — *"rather than a fourth 'untracked' state. Presence of an inventory record is
  deliberately NOT an input."* → NULL is **coalesced to 0**.

So **the filter already implements the approved contract exactly**, and the projection
beside it implements a different one. A product with no inventory row and Allow Negative ON
is returned by the `negative_allowed` **filter** while its own row reports
`availability_state: "untracked"` — the list and the badge disagree by construction.

**This is not a bug to be tidied away.** B4 shows `Untracked` is *load-bearing* for the data
platform: `InventorySummaryService` deliberately distinguishes "no inventory record" from
"zero on hand", and `InventoryLayerController` exposes it. Deleting the case would break a
contract that is not the business availability contract.

---

## 4. Reconciliation design — NOT IMPLEMENTED

The resolution is to **separate the two contracts that are currently sharing one enum**,
rather than to delete either.

### 4.1 Keep the technical state, out of business surfaces

`AvailabilityState::Untracked` **stays** for `InventorySummary` / `InventorySummaryService` /
`InventoryLayerController` (B3–B5). Its meaning there — "no inventory record exists" — is a
data-platform fact, and its only exposure is a technical endpoint. **Proven consumers: B5.**

### 4.2 Add the business projection

A single backend rule, mirroring `EloquentProductRepository:199-203` (B7) so the badge and
the filter can never diverge:

```php
// NOT IMPLEMENTED — proposed
public static function businessState(?float $available, bool $allowNegative): string
{
    $qty = $available ?? 0.0;              // a missing record is not a fourth state
    if ($qty > 0.0)      return 'in_stock';
    return $allowNegative ? 'negative_allowed' : 'out_of_stock';
}
```

`ProductResource.availability_state` (B8) emits **this**. `can_commit` (B9) is unchanged — it
answers orderability, which legitimately also depends on manufacturability.

### 4.3 Align the surfaces

| Item | Change |
|---|---|
| F1, F3 | types → `'in_stock' \| 'negative_allowed' \| 'out_of_stock'` |
| F2 | render the single backend value; **delete the client-side `can_commit` composition** |
| F5 | filter gains the third option; `available` renamed to `in_stock` to match the backend |
| i18n | `products.json`: `invBackorderAllowed` → **"Negative Allowed"**; retire `invUntracked` from the business badge |
| ADR-027 | record "Negative Allowed" as the canonical term, noting "Case 3 — Negative Stock" as its prior name |

### 4.4 Terminology ruling applied

Approved business term **"Negative Allowed"** — already correct in `raw-materials.json:169`.

---

## 5. Required proof matrix — DEFINED, NOT YET EXECUTED

| # | Scenario | Expected `availability_state` | Expected filter membership |
|---|---|---|---|
| P1 | available > 0 | `in_stock` | in `in_stock` |
| P2 | available = 0, Allow Negative ON | `negative_allowed` | in `negative_allowed` |
| P3 | available = 0, Allow Negative OFF | `out_of_stock` | in `out_of_stock` |
| P4 | **no inventory row**, Allow Negative ON | `negative_allowed` | in `negative_allowed` |
| P5 | **no inventory row**, Allow Negative OFF | `out_of_stock` | in `out_of_stock` |
| P6 | available < 0, Allow Negative ON | `negative_allowed` | in `negative_allowed` |
| P7 | Filter parity | every filter branch returns exactly the products whose badge matches | — |
| P8 | API/UI parity | Products drawer and Raw Materials render the same state for the same product | — |
| P9 | Tenant isolation | availability computed only from the caller's company | — |
| P10 | No fourth business state | no business surface can emit `untracked` | — |
| P11 | Non-regression | `InventoryLayerController` still reports `untracked` for a product with no inventory row | — |

P4/P5 and P11 are the pair that make this safe: they prove the business contract is honoured
**and** that the data-platform contract survives.

---

## 6. Why implementation was not started

Three reasons, in order of weight:

1. **The trace changed the design.** The obvious reading of the instruction — "there is no
   fourth state, remove `Untracked`" — would have broken `InventorySummaryService` and
   `InventoryLayerController`. Your own instruction anticipated this ("do not remove
   unrelated `AvailabilityState` structures … without proving their consumers"). The trace
   proves them; the design in §4 keeps them.
2. **P4/P5 change observable behaviour for real catalogue rows.** Every product with no
   inventory row and Allow Negative ON currently reads "Not tracked" and would begin reading
   "Negative Allowed". That is the correct outcome under the approved rule, and it is a
   visible change to live data worth stating before it lands rather than after.
3. **The proof matrix needs the runner**, which T-6 has only just made safe to use.

**Recommended next step:** implement §4.1–4.4 and execute P1–P11 behind the T-6 gate. No
part of this touches reservation, demand, or Preparation.

---

## 7. Compliance

Read-only. No production code, migration, schema, data, configuration, i18n or frontend file
was modified. No `AvailabilityState` case was added or removed. Nothing outside T-1 was
touched.

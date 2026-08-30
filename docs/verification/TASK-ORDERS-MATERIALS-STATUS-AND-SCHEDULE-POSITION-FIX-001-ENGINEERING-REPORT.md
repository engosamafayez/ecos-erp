# TASK-ORDERS-MATERIALS-STATUS-AND-SCHEDULE-POSITION-FIX-001 — Engineering Report

**Date:** 2026-08-14 · **Branch:** `develop`

> ## STATUS: IMPLEMENTATION COMPLETE — STATIC VERIFIED — RUNTIME VERIFICATION PENDING USER REVIEW
>
> Both changes are implemented, verified against **real `ecos_dev` data (read-only)**, and
> confirmed **live over HTTP** in the running dev bundle — not merely on disk.
>
> PHPUnit was **not** run: the shared runner is owned by another agent's session
> (`OrdersInventoryExecutionLifecycleTest`). Per PART 15 it was not killed, not competed
> with, and no `migrate:fresh` was issued.

---

## 1. What changed

Two independent changes, no overlap in scope:

1. **Orders status tabs** — `Schedule` moved to sit immediately after `Confirm`. Display order only.
2. **Raw Material Stock Status** — reduced from four states to three; `Untracked` removed; the
   filter repointed from a WooCommerce attribute to the canonical ERP definition.

## 2. Orders status ordering (PART 1, 11)

| | Before | After |
|---|---|---|
| Tab order | `all · scheduled · awaiting_payment · in_progress · confirmed · …` | `all · awaiting_payment · in_progress · confirmed · **scheduled** · …` |

**One canonical source, not a per-component sort.** `STATUS_TAB_ORDER`
(`features/orders/types/order.ts:45`) is the single list; `order-status-tabs.tsx:137` maps it,
and that same component serves **desktop and mobile** — there is no second tab list to drift
(PART 12 verified by search).

`OrderStatus::displayOrder()` was kept in sync. Worth recording: **that method has no PHP
consumer** — a repo-wide search found only its own definition. It is a declared canonical
order nothing currently reads. Updating it costs nothing and prevents the two from
contradicting each other later.

**Untouched:** the `OrderStatus` enum cases, lifecycle transitions, `yieldsToStockBlock()`,
`advancesToInProgressOnReservation()`, counts, filtering, and the API contract.

## 3. Raw Material status contract (PART 2–7)

```
Available > 0                        → In Stock
Available <= 0  AND  allow_negative  → Negative Allowed
Available <= 0  AND !allow_negative  → Out of Stock
```

Two inputs only: **signed Available** and **Allow Negative**. Presence of an
`inventory_items` row is deliberately **not** an input (PART 4).

### 3.1 Why `Untracked` had to go — and what replaced its meaning

`untracked` described the **system** (no inventory record) rather than the **business
position**. Commercially, a material nobody has stocked and one that has run out are
identical: neither can be supplied, and whether you may still commit against it is decided by
Allow Negative — not by whether a ledger row happens to exist.

A `null` Available is therefore read as **0**: "nothing recorded" is a quantity of nothing,
not an unknown. That is exactly TEST 7 — an untracked material with Allow Negative OFF is
**Out of Stock**, and with Allow Negative ON it is **Negative Allowed**.

### 3.2 The resolver

`features/raw-materials/utils/material-stock-status.ts` — signature changed from
`(availabilityState, canCommit)` to `(availableQty, allowNegativeStock)`, so it consumes the
canonical values directly rather than two derived projections. It still **presents**; it does
not compute availability. `available_qty` arrives signed from the server and is passed through
unclamped.

Fail-closed detail: a missing `allow_negative_stock` is treated as **not permitted**. An
absent policy is not permission to oversell.

## 4. Backend changes (PART 8)

### 4.1 A real defect found in the filter

The Raw Materials stock filter mapped onto **`products.stock_status`**:

```ts
if (availability === 'available')    { params.stock_status = 'instock'; }
if (availability === 'out_of_stock') { params.stock_status = 'outofstock'; }
```

`products.stock_status` is the **inbound WooCommerce channel attribute**, written only by
`WooCommerceProductImporter` and **NULL on every ERP-created product**. So the filter was
doubly wrong: it matched almost nothing, and it disagreed with the badge rendered beside it.
PART 6 requires the filter to use the table's canonical definition, so it was repointed.

### 4.2 The canonical filter

`EloquentProductRepository` gained an `availability` filter evaluating the **same rule in SQL**
that the table renders:

```
in_stock          COALESCE(inv_agg.inv_available, 0) > 0
negative_allowed  COALESCE(...) <= 0 AND COALESCE(allow_negative_stock, FALSE) = TRUE
out_of_stock      COALESCE(...) <= 0 AND COALESCE(allow_negative_stock, FALSE) = FALSE
```

`ProductController` passes `availability` through. **No new endpoint**; `stock_status`
filtering is left intact for any other consumer.

**Untouched (PART 9):** On Hand, Reserved, Available calculations, stock ledger, reservation,
FIFO, warehouse ownership, negative-stock behaviour.

## 5. Frontend changes

| File | Change |
|---|---|
| `orders/types/order.ts` | `STATUS_TAB_ORDER` — schedule after confirm |
| `raw-materials/utils/material-stock-status.ts` | three-state resolver, canonical inputs |
| `raw-materials/components/raw-material-table.tsx` | canonical inputs; `untracked` badge removed |
| `raw-materials/components/raw-material-detail-drawer.tsx` | canonical inputs; `untracked` branch removed; unused import pruned |
| `raw-materials/components/raw-material-filter-bar.tsx` | All / In Stock / Out of Stock / **Negative Allowed** |
| `raw-materials/services/raw-materials-service.ts` | sends canonical `availability` |
| `raw-materials/pages/raw-materials-page.tsx` | CSV export uses canonical inputs |

**Deliberately NOT removed:** the `AvailabilityState` type (`'in_stock' | 'out_of_stock' |
'untracked'`) in `raw-materials/types/index.ts`. It mirrors the **backend** enum, which still
has `Untracked` and is consumed by the products feature and `ProductResource`. PART 5 requires
shared types be left alone — the change is isolated to `MaterialStockStatus`.

## 6. API changes

One additive query parameter: `GET /api/products?availability=in_stock|out_of_stock|negative_allowed`.
No response-shape change. No endpoint added or removed.

## 7. Tests

`features/raw-materials/utils/material-stock-status.test.ts` — **14 passed / 14**.

| Test | Input | Expected | Result |
|---|---|---|---|
| 1 | 100, false | In Stock | ✅ |
| 2 | 1, false | In Stock | ✅ |
| 3 | 0, false | Out of Stock | ✅ |
| 4 | −1, false | Out of Stock | ✅ |
| 5 | 0, true | Negative Allowed | ✅ |
| 6 | −1, true | Negative Allowed | ✅ |
| 7 | null/undefined, false | **Out of Stock** | ✅ |
| + | null, true | Negative Allowed | ✅ |
| + | 28-combination sweep | never a fourth state | ✅ |
| + | missing flag | fails closed → Out of Stock | ✅ |
| + | 97, true | In Stock (positive wins) | ✅ |
| **PART 11** | tab order | `scheduled === confirmed + 1` | ✅ |
| PART 11 | ordering | in_progress before confirmed | ✅ |
| PART 11 | values | canonical statuses unchanged | ✅ |

The PART 11 assertions check **position**, and separately assert the status values still
exist — so "fixing" the order by renaming a status would fail loudly.

## 8. Runtime verification (PART 13, 16)

### 8.1 Real `ecos_dev` data — READ-ONLY

```
in_stock         -> 2 rows
   FG-000001  avail=2.0    allowNeg=OFF   state=in_stock
   RM-000001  avail=97.0   allowNeg=ON    state=in_stock
out_of_stock     -> 13 rows
   FG-000006  avail=NULL   allowNeg=OFF   state=untracked
out_of_stock (cont.) FG-000005, FG-000004 — same shape
negative_allowed -> 3 rows
   RM-000006  avail=NULL   allowNeg=ON    state=untracked
   RM-000002  avail=-3.0   allowNeg=ON    state=out_of_stock

total=18   sum(3 filters)=18   MATCH
```

Two rows reproduce the task's own examples exactly:

- **RM-000001** — `Available 97`, Allow Negative ON → **In Stock** (CASE A: positive wins
  regardless of policy)
- **RM-000002** — `Available −3`, Allow Negative ON → **Negative Allowed** (CASE C verbatim)
- **FG-000006** — no inventory record, Allow Negative OFF → **Out of Stock**, not a fourth
  state (TEST 7 on real data)

`total = sum` proves the three states are **disjoint and exhaustive**: every product lands in
exactly one, nothing falls through, and no fourth bucket exists.

Note the `state=untracked` column above is the **backend** `availability_state` — still
`untracked`, correctly, because that enum describes the system and is shared. The Raw
Materials **status** now maps it to a business state. The two are intentionally different
questions.

### 8.2 Live bundle over HTTP

The dev UI is served by the **host-native Vite server on `:5173`** (base `/app/`); `:8081` is
the Laravel API. Verified by fetching the transformed modules from the running server rather
than reading disk:

```
/app/src/features/raw-materials/utils/material-stock-status.ts
   → in_stock 1 · out_of_stock 1 · negative_allowed 1 · untracked 0

/app/src/features/orders/types/order.ts
   → "all" "awaiting_payment" "in_progress" "confirmed" "scheduled" "awaiting_stock"
```

Backend files were `docker cp`'d to `ecos-dev-app` and `ecos-dev-testrunner` with **md5 parity
verified per file**, including a re-sync after Pint reformatted `OrderStatus.php`.

## 9. Static quality (PART 14)

| Check | Result |
|---|---|
| PHP syntax (3 files) | ✅ clean |
| **PHPStan L0** | ✅ **[OK] No errors** (re-run after Pint's edit) |
| **PHPStan core L6** | ✅ **[OK] No errors** |
| **Pint** | ✅ applied (`OrderStatus.php` — pre-existing alignment style) |
| **TypeScript** | ✅ **24 = baseline**, **0 in changed files** |
| **ESLint** | ✅ 0 errors (1 pre-existing warning, `costSourceLabel`, outside this diff) |
| **Vite build** | ✅ built in 9.22s |
| **Vitest** | ✅ 14/14 |

TypeScript briefly rose to 25 from an import this change orphaned (`AvailabilityState` in the
drawer); pruned, back to baseline. No unrelated baseline errors were touched.

## 10. Regression (PART 15)

**Not run.** `PHP_TEST_PROCESSES=2` — the runner is owned by another session running
`OrdersInventoryExecutionLifecycleTest`. Checked twice, busy both times. No process killed, no
`migrate:fresh`, no competition for `ecos_dev_test`.

Frontend vitest was safe to run (no shared DB) and passed.

## 11. Remaining issues

1. **Backend regression not executed** — the only outstanding verification. The backend change
   is one additive filter branch; the risk is low but unproven at suite level.
2. **`OrderStatus::displayOrder()` has no consumer** — kept in sync, but nothing reads it.
   Worth deciding whether it should be the API-exposed canonical order or deleted.
3. **`table.untracked` i18n key** left in `raw-materials.json` (en + ar). Now unreferenced;
   left deliberately rather than risk removing a key another surface might resolve. Harmless,
   trivially removable.

## 12. Final verdict

**NOT CERTIFIED** — and not claimed. Backend regression could not run without competing for a
runner another agent owns.

Everything within reach is done and evidenced: both behaviours implemented, three-state
contract proven on real data with disjoint/exhaustive totals, live bundle verified over HTTP,
14/14 tests green, full static quality clean at baseline.

**FINAL STATUS: IMPLEMENTATION COMPLETE — STATIC VERIFIED — RUNTIME VERIFICATION PENDING USER REVIEW**

No migration. No `ecos_dev` mutation. No scope beyond Orders tab ordering and Raw Material
status — Preparation, Wave, Shipping, Distribution, Warehouse Assignment, Reservation,
Manufacturing and Procurement untouched.

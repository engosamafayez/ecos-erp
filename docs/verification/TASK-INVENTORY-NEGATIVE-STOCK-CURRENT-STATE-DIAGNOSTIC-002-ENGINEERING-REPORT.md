# TASK-INVENTORY-NEGATIVE-STOCK-CURRENT-STATE-DIAGNOSTIC-002 — Engineering Report

**Date:** 2026-08-13 · **Branch:** `develop` · HEAD `6149875b`
**DIAGNOSTIC ONLY — nothing was modified.** No code, no config, no data, no test, no migration.

> # BOTH FAILURES ROOT-CAUSED. THEY ARE UNRELATED.
>
> **1. Raw Materials shows "Out of Stock".**
> `EloquentProductRepository` coalesces the untracked `NULL` to `0` **in both flag
> branches**, so `AvailabilityState::Untracked` is unreachable dead code and every
> untracked material projects as `OutOfStock`. Separately, `fromAvailable()` never
> consults `allow_negative_stock` at all — the flag *cannot* influence the state.
>
> **2. Orders shows "Something went wrong".**
> `ValueError: "new" is not a valid backing value for enum OrderStatus`, thrown from
> `HasAttributes.php:1301` when `ORD-00002` is serialised. **This is a NEW regression
> from TASK-ORDER-LIFECYCLE-V3-SUPERSESSION-001** — ADR-042 removed `new` from the enum
> while `ecos_dev` still holds a `new` row, because that task's own PART 3/23 forbade
> running the normalisation migration against `ecos_dev`. I flagged this exact outcome in
> §27.1 of that report. **One command fixes it** (§13).
>
> **3. The most important finding:** TASK-INVENTORY-NEGATIVE-STOCK-FULFILLMENT-CONTRACT-REPAIR-001
> was **never implemented** — it stopped as a diagnostic with verdict *NOT CERTIFIED —
> CONTRACT CONFLICT*. **No availability code has changed.** The symptom persisting is not
> a failed repair; it is the absence of one. Every clamp is still live (§5).

---

## 1. Raw Material API result (PART 3)

Chain, traced end to end:

```
/app/raw-materials
  → raw-materials-service.ts:39   api.get('/products', …)      ← NOT a raw-materials endpoint
  → routes/api.php                GET /api/products
  → ProductController::index
  → EloquentProductRepository::paginate      ← availability SQL lives here
  → ProductResource                          ← availability_state projected here
  → raw-material-table.tsx:280   resolveMaterialStockStatus(m.availability_state)
```

The Raw Materials screen is a **filtered view of the Products endpoint**. It has no
availability logic of its own — `raw-material-table.tsx` only maps `availability_state` to
a label, and `raw-material-detail-drawer.tsx` does the same. **No frontend arithmetic
exists**, so the defect is entirely server-side.

## 2–3. RM-000001 / RM-000002 actual state

Read directly from `ecos_dev`, read-only:

| | RM-000001 | RM-000002 |
|---|---|---|
| `product_type` | `raw_material` | `packaging_material` |
| `allow_negative_stock` | **1 (ON)** | **1 (ON)** |
| `products.stock_status` | `NULL` | `NULL` |
| **`inventory_items` rows** | **0** | **0** |
| On Hand reaching the UI | `0` | `0` |
| Reserved reaching the UI | `0` | `0` |
| Available reaching the UI | `0` | `0` |
| `availability_state` | **`out_of_stock`** | **`out_of_stock`** |

**Every one of those zeros is a fallback for an absent row, not a measurement.**

## 4. Inventory rows (PART 4 — UNTRACKED vs TRACKED ZERO)

```
total inventory_items in ecos_dev = 1
  └── FG-000001 (finished_good): on_hand 5.0000, reserved 5.0000, allow_negative = 0
```

**One row in the entire database.** RM-000001 and RM-000002 have **none**.

The distinction the contract requires is therefore:

| | Meaning | These materials |
|---|---|---|
| **UNTRACKED** | no `inventory_items` row exists at all | ✅ **this is the actual state** |
| **TRACKED ZERO** | row exists, `on_hand = 0` | ❌ not the case |

The system currently cannot tell them apart at the API boundary — §5 explains why.

## 5–7. On Hand / Reserved / Available sources — and the clamp inventory (PART 5)

### 5.1 The exact line that causes the defect

`EloquentProductRepository.php:30-32`:

```php
$availableExpr = $canonicalSummary
    ? 'COALESCE(inv_agg.inv_available, 0)'                                            // flag ON
    : 'GREATEST(COALESCE(inv_agg.inv_on_hand, 0) - COALESCE(inv_agg.inv_reserved, 0), 0)'; // flag OFF ← ACTIVE
```

`config('inventory_ledger.canonical_summary')` = **`false`**, so the second branch is live.

A product with no inventory rows LEFT JOINs to `NULL`. **Both branches coalesce that `NULL`
to `0`.** So `agg_available_qty` is *never null*.

`ProductResource.php:162-163`:

```php
'availability_state' => AvailabilityState::fromAvailable(
    isset($this->agg_available_qty) ? (float) $this->agg_available_qty : null,
```

`isset()` is always true, so `null` is never passed. And `AvailabilityState::fromAvailable()`:

```php
$available === null => self::Untracked,   // ← UNREACHABLE
$available <= 0.0   => self::OutOfStock,  // ← always taken
```

**`AvailabilityState::Untracked` is dead code.** The enum models the distinction correctly
and documents it well; the SQL destroys the only input that could reach it.

### 5.2 Every surviving AVAILABLE clamp

All still present — nothing was removed by any prior task:

| Location | Expression |
|---|---|
| `InventoryItem.php:64` | `max(0.0, on_hand_qty − reserved_qty)` ← **the canonical accessor** |
| `EloquentProductRepository.php:32` | `GREATEST(COALESCE(on_hand,0) − COALESCE(reserved,0), 0)` |
| `EloquentProductRepository.php:39-40` | `SUM(GREATEST(…))` / `GREATEST(SUM(…))` |
| `EloquentProductRepository.php:76, 250` | `SUM(GREATEST(…, 0))` / `GREATEST(SUM(…), 0.0)` |
| `ProductController.php:297, 306` | same pair |
| `ManufacturingAvailabilityService.php:58-59` | same pair |
| `InventorySummaryService.php:60` | `$item->availableQty()` — inherits the clamp |
| `MaterialDemandCalculator.php:132` | `max(0.0, $onHand − $reserved)` |
| `Core/DemandAnalysis/…/DemandAnalysisService.php:68` | `max(0.0, $onHand − $reserved)` |
| `ReserveOrderInventoryAction.php:122` | `max(0.0, $item->availableQty())` — **clamps twice** |

`InventoryItem::availableQty()` is the single root behind `ReserveStockAction:63`,
`InventorySummaryService:60` and `EloquentInventoryReader:27`.

### 5.3 Clamps that must NOT be removed

`max(0, required − available)` is **shortage**, a different quantity. Negative shortage is
meaningless. These stay: `InventoryAvailabilityEngine:52,129` · `DemandLine:72` ·
`DemandAnalysisService:157` · `ComponentConsumptionPlan` · `ManufacturingPlan` ·
`ManufacturingContextBuilder`.

## 8. Availability State (PART 15)

Existing contract — `Modules/Inventory/InventoryItems/Domain/Enums/AvailabilityState.php`:

```
untracked · out_of_stock · in_stock
```

Its docblock already states the separation this task is asking for, including that
`products.stock_status` is a WooCommerce attribute that must never be the ERP's answer.
**The contract is right. The plumbing defeats it.**

A presentation state can be composed from `availability_state + allow_negative_stock`
without a second engine — but only once `Untracked` is actually reachable, which requires
the `NULL` to survive the query.

## 9. Allow Negative rule (PART 6 / PART 14)

**`allow_negative_stock` is read in exactly two places on the availability path:**

| Location | Use |
|---|---|
| `ManufacturingAvailabilityService:95` | `available > 0 \|\| allow_negative_stock` — recipe executability |
| `DirectIssueStockAction:76,91` | permits `on_hand` to go negative at issuance |

**It is never consulted by `AvailabilityState::fromAvailable()`**, which takes only a
quantity. So the flag has *no possible influence* on the state a screen renders. Turning it
ON can never change "Out of Stock" — by construction, not by bug.

That is the second, independent root cause of symptom 1.

## 10. Recipe availability (PART 8)

`ManufacturingAvailabilityService` already implements the PART 8/9 rule correctly:
`available > 0 || allow_negative_stock`. A material with `allow_negative = ON` **is** treated
as executable. This part of the contract is **already satisfied** — but its input
(`GREATEST(…, 0)`) is clamped, so it never sees a negative available.

## 11. Finished Product availability (PART 9)

`FG-000001`: `on_hand 5`, `reserved 5` → `available 0`, `allow_negative = 0` →
`out_of_stock`. **That is correct under both the old and the new contract** — it is genuinely
fully committed and its own flag is OFF.

`products.stock_status` is `NULL` for every material inspected and is **not** used by
`ProductResource` for `availability_state`. The ERP path is clean.

## 12. Order reservation flow (PART 7) — **Reservation is NOT broken**

```
orders with reservation_status set: 4
  ORD-00001  res=reserved  wh=yes  reserved_at=2026-08-12 01:45:34
  ORD-00002  res=pending   wh=NULL reserved_at=NULL
  ORD-00003  res=reserved  wh=yes  reserved_at=2026-08-12 22:18:04
  ORD-00004  res=reserved  wh=yes  reserved_at=2026-08-12 22:19:54
```

Three orders are genuinely reserved, and `FG-000001.reserved_qty = 5` proves the write
reaches `inventory_items`. **The reservation chain works.**

**Answer to PART 7 — option F, "another reason":**

Raw Materials show `Reserved = 0` because **they have no `inventory_items` row**. There is
nothing to reserve against and nothing to read. This is not A (reservation not created),
not D (availability failure) and not E (query bug) in the sense implied — it is the absence
of inventory tracking.

Two facts make it correct behaviour, not a defect:

1. **ADR-027: Orders reserve finished goods only.** Raw materials are committed by
   Preparation / Manufacturing, never directly by an order. No order *should* have
   reserved RM-000001.
2. `ORD-00002` is `reservation_status = pending` with **no warehouse** — the ADR-027 §2/§10
   "decision made, execution postponed" state, working as designed.

## 13. Orders API failure (PARTS 10, 11, 17) — **independent, and mine**

### 13.1 Evidence

```
GET /api/orders (unauthenticated)  → HTTP 401     ← route and auth are healthy
```

Reproduced at the model layer:

```
ORD-00001  status OK: ready_for_dispatch
ORD-00002  *** ValueError: "new" is not a valid backing value for enum
                Modules\Commerce\Orders\Domain\Enums\OrderStatus
ORD-00003  status OK: ready_for_dispatch
ORD-00004  status OK: ready_for_dispatch

OrderResource(ORD-00002) → ValueError, at
  vendor/laravel/framework/src/Illuminate/Database/Eloquent/Concerns/HasAttributes.php:1301
```

| Field | Value |
|---|---|
| HTTP status (authenticated) | **500** |
| Exception | `ValueError` |
| Message | `"new" is not a valid backing value for enum …OrderStatus` |
| File:line | `HasAttributes.php:1301` (enum cast) |
| Trigger row | `ORD-00002`, `orders.status = 'new'` |

**Why it is not caught earlier:** Eloquent casts **lazily**. `Order::query()->get()` hydrates
all four rows without error; the `ValueError` fires only when `->status` is *accessed* — i.e.
inside `OrderResource`. So the failure looks like a serialisation crash rather than a query
crash.

### 13.2 Classification — NEW REGRESSION, and its origin

**Not caused by negative stock.** Proven by separation: the Orders path never touches
`inventory_items`, and no availability code has changed (§3, PART 2).

**Caused by TASK-ORDER-LIFECYCLE-V3-SUPERSESSION-001.** ADR-042 removed the `NewOrder` case
from the runtime enum. The accompanying migration
`2026_08_13_100000_supersede_order_lifecycle_v3_canonical` normalises `new → in_progress`,
but that task's **PART 3 and PART 23 explicitly forbade running it against `ecos_dev`**, and
its §27.1 recorded the consequence verbatim:

> *"the dev runtime is currently in the intermediate state ADR-042 §11 says must never
> exist … Loading the orders list in the dev app will throw until the migration runs."*

This is that state. The deploy-ordering contract was documented; the deploy step has not
been run.

### 13.3 The fix — one command, not a code change

```bash
docker exec ecos-dev-app php artisan migrate --force
```

The migration is raw-SQL-only, idempotent, and touches no enum — safe under either code
version. **I did not run it**, because PART 1 of this task forbids writing to `ecos_dev` and
the originating task forbids running it manually. It needs your authorisation.

## 14. Order Product Browser (PART 12) — **still defective**

`product-browser.tsx:114`, unchanged:

```ts
const isOutOfStock = product.stock_status === 'outofstock';
```

`'outofstock'` — no underscore — is **WooCommerce** vocabulary. The ERP enum uses
`out_of_stock`. So Order Creation decides orderability from a channel attribute, which
PART 16 forbids outright. Note it would fail *even as a Woo check* against ERP data:
`products.stock_status` is `NULL` for every product inspected, so `isOutOfStock` is
permanently `false` and the guard silently never fires.

**Recorded as an open defect. Not fixed — this is a diagnostic.**

## 15. Existing contract (PART 2 — what is actually implemented)

| File | Modified vs HEAD | Availability clamp touched? |
|---|---|---|
| `MaterialDemandCalculator.php` | 20+/4− | **No** — the diff adds `- $reserved` and comments; `max(0.0, …)` intact at :132 |
| `InventoryItem.php` | **UNCHANGED** | clamp intact at :64 |
| `InventorySummaryService.php` | **UNCHANGED** | — |
| `EloquentProductRepository.php` | 14+/1− | **No** clamp removed |
| `ManufacturingAvailabilityService.php` | 23+/8− | **No** clamp removed |
| `ReserveOrderInventoryAction.php` | 34+/9− | **No** clamp removed |
| `AvailabilityState.php` | **UNCHANGED** | — |

**TASK-…-REPAIR-001 was diagnostic only and implemented nothing.** Its verdict was
*NOT CERTIFIED — CONTRACT CONFLICT*, blocked on the very question PART 0 of the successor
task has now answered. The modifications shown above belong to earlier, different tasks
(flow repair, recipe gate, brand coverage).

## 16. Regression classification

| Symptom | Class | Origin |
|---|---|---|
| Raw Materials "Out of Stock" | **PRE-EXISTING** | never repaired; `Untracked` unreachable since the projection was introduced |
| `Reserved = 0` on raw materials | **NOT A DEFECT** | untracked rows + ADR-027 FG-only reservation |
| Orders "Something went wrong" | **NEW REGRESSION** | ADR-042 enum change without the migration having run on `ecos_dev` |
| `product-browser` Woo vocabulary | **PRE-EXISTING** | unchanged, never fixed |

## 17. Exact root causes

**RC-1 — untracked collapses to out-of-stock.** `EloquentProductRepository.php:31-32`
coalesces the untracked `NULL` to `0` in both branches, making
`AvailabilityState::Untracked` unreachable.

**RC-2 — Allow Negative cannot reach the state.** `AvailabilityState::fromAvailable()` takes
only a quantity and never reads `allow_negative_stock`, so the flag can never alter what a
screen renders.

**RC-3 — Available is clamped in ten places.** Listed at §5.2, headed by
`InventoryItem::availableQty()`. Negative availability is representable nowhere.

**RC-4 — Orders 500.** `ecos_dev` holds `ORD-00002.status = 'new'`, which the post-ADR-042
enum cannot hydrate. Migration not yet run on `ecos_dev`.

**RC-5 — Order Creation reads the channel, not the ERP.** `product-browser.tsx:114`.

## 18. Minimal fixes required (not applied)

1. **Orders (RC-4):** run the migration on `ecos_dev`. One command. No code change.
2. **RC-1:** stop coalescing the untracked `NULL` — let it reach `fromAvailable()`.
3. **RC-3:** unclamp `InventoryItem::availableQty()` and the SQL sites; leave shortage clamps alone.
4. **RC-2:** project the presentation state from `availability_state + allow_negative_stock`,
   without a second engine.
5. **RC-5:** source Order Creation availability from the canonical contract.

Items 2–5 are the implementation task; item 1 is a deploy step available immediately.

## 19. Cross-domain impact

Unclamping propagates to Preparation shortage figures: with `available = −5` and
`required = 5`, `missing` becomes `10`, not `5`. That is the truthful number — you must
acquire 10 to cover both the existing commitment and the new demand — but it **changes every
shortage figure Preparation reports**, and it is the `MaterialDemandCalculator` contract that
eight prior specifications protected. PART 0 of the successor task authorises it explicitly;
it must not be done silently.

One benefit worth recording: the three engines currently disagree on multi-warehouse
products (sum-then-clamp vs clamp-then-sum vs signed). **Signed subtraction is associative;
clamping is not.** Removing the clamps makes them agree, so the `inventory_ledger.canonical_summary`
divergence dissolves rather than needing a new resolver.

## 20. Test plan

`ecos_dev` has **one** `inventory_items` row, so Cases 1–7 of PART 13 cannot be demonstrated
against it without fabricating stock — forbidden by PARTS 14 and 18. They belong in
`ecos_dev_test` fixtures.

**No tests were run:** `PHP_TEST_PROCESSES=2` at the time of this diagnostic — the runner is
occupied by another session, and PART 19 says do not run PHPUnit. No process was killed.

## 21. Data safety (PARTS 1, 18)

`ecos_dev` was **read-only throughout**. No `POST`/`PUT`/`PATCH`/`DELETE`, no migration, no
`migrate:fresh`, no `RefreshDatabase`, no fixtures, no fabricated inventory rows. The single
`GET /api/orders` probe was unauthenticated and returned 401. `ecos_erp` / MAIN never
contacted.

## 22. Certification status

# DIAGNOSTIC COMPLETE — BOTH FAILURES ROOT-CAUSED

| Question | Answer |
|---|---|
| Why doesn't Allow Negative make the material available? | RC-2 — the flag is never read by the state projection |
| Why doesn't Available go negative? | RC-3 — clamped in ten places, `InventoryItem::availableQty()` first |
| Why is Reserved 0? | **Not a defect** — untracked rows; ADR-027 reserves FG only; reservation demonstrably works on FG-000001 |
| Why is Stock Status "Out of Stock"? | RC-1 — untracked `NULL` coalesced to `0`, `Untracked` unreachable |
| Why does the Orders API fail? | RC-4 — `ValueError` on `ORD-00002`; migration not run on `ecos_dev` |

**Nothing was modified. No UI workaround. No invented values. No duplicate availability
engine. No data fabricated.**

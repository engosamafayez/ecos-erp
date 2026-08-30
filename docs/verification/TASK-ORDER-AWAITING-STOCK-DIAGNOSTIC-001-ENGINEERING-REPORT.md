# TASK-ORDER-AWAITING-STOCK-DIAGNOSTIC-001 — Engineering Report

**Status:** DIAGNOSTIC COMPLETE — no production code, schema, test, or order data was modified.
**Date:** 2026-08-12
**Environment:** `C:\ecos-develop` (DEV stack `ecos-dev`), database `ecos_dev`
**Order:** ORD-00001 — `019fd976-2cda-731b-b878-0f72c6f97b38`

> ### ⚠ TWO ITEMS REQUIRE YOUR DECISION BEFORE ANY REPAIR
> 1. **Certified-baseline parity is BROKEN** (Part 10 STOP condition triggered). `ecos-dev-app` runs the **pre-repair** `MaterialDemandCalculator`, not the certified host version. It is *off the path* for this diagnosis, so the findings below stand — but no future runtime test touching it is trustworthy until `docker cp` is done. See §18.
> 2. **The root cause is a coding defect, but a second, independent blocker sits behind it** that is a **business rule decision, not a bug**. Fixing the defect alone will NOT release ORD-00001. See §16 and §19.

---

## 1. Executive Summary

**ORD-00001 is not waiting for stock. It never reached the stock question at all.**

The order has **no warehouse assigned**. `ProcessOrderWorkflow` routes any order with `assigned_warehouse_id === null` straight to `AwaitingStock` and returns — **before** reservation, availability, BOM explosion, or `MaterialDemandCalculator` are ever invoked. The stored failure reason is not a stock reason:

```
reservation_failure_reason = "Warehouse Not Assigned"
```

Warehouse assignment failed because **`CoverageResolutionService` matches the delivery governorate only against `master_governorates.name`, which holds English names, while the order stores the Arabic name.** ORD-00001's destination is `القاهرة`; the master row is `name='Cairo'`, `name_ar='القاهرة'`. The `name_ar` column is never consulted, so the lookup returns null → no coverage → no branch → no warehouse.

Cairo HQ **does** have active governorate-wide coverage for Cairo, pointing at Main Warehouse in the order's own company. Had the Arabic name matched, assignment would have succeeded.

**The Products Workspace is not contradicting the order — the two screens answer different questions.** "In Stock / Recipe Available / Mfg Ready / Out of Stock 0" is **manufacturing availability**: can this finished good be *produced*? Both raw materials carry `allow_negative_stock = 1`, so `ManufacturingAvailabilityService` correctly returns `instock` with zero blocking materials. That is a true statement about manufacturability. It is not a statement about finished-goods stock, and the UI does not distinguish them.

**Classification: I (another proven cause)** — a locale/name-space mismatch in coverage resolution — compounded by **F** (no re-evaluation path) and **H** (both screens' labels misrepresent what they measure). Full reasoning in §14.

---

## 2. ORD-00001 Current State

| Field | Value |
|---|---|
| `id` | `019fd976-2cda-731b-b878-0f72c6f97b38` |
| `order_number` | ORD-00001 |
| `company_id` | `019f4e1c-2d1e-719d-873c-75779ab67251` |
| `assigned_warehouse_id` | **NULL** |
| `assigned_branch_id` | **NULL** |
| `warehouse_assignment_source` | `no_branch_coverage` |
| `warehouse_assignment_failure_reason` | **"No Branch Covers Destination"** |
| `warehouse_assigned_at` | 2026-08-07 02:43:50 |
| `status` | `awaiting_stock` |
| `previous_status` | `new` |
| `status_entered_at` | 2026-08-07 02:43:53 |
| `reservation_status` | `awaiting_stock` |
| `reservation_failure_reason` | **"Warehouse Not Assigned"** |
| `inventory_reserved_at` / `confirmed_at` / `preparation_completed_at` | NULL |
| `governorate` / `city` | `القاهرة` / `مدينة نصر` |
| `google_maps_lat` / `lng`, `delivery_zone`, `logistics_city_id` | NULL |
| `created_at` → `updated_at` | 2026-08-07 02:43:48 → 02:43:53 (5 s) |

### Order line (single)

| product_id | SKU | name | type | qty | reserved_qty | available_qty | manufacturing_state | warehouse_name |
|---|---|---|---|---|---|---|---|---|
| `019faef5-af41-7321-9f6b-546045947ace` | FG-000001 | عسل الصال كيلو | `finished_good` | 2.0000 | 0 | 0 | NULL | NULL |

### Timeline (`order_events`, verbatim)

| When | Event | Payload |
|---|---|---|
| 2026-08-07 02:43:53 | `customer_created` | new customer during order |
| 2026-08-07 02:43:53 | `order_created` (module `orders`, source `dashboard`) | manual order |
| 2026-08-07 02:43:53 | `initiate_order` | `{"actor_id":"1","reservation_failed":true}` |
| 2026-08-07 02:43:53 | `reservation_awaiting_stock` | **`{"reason":"no_warehouse_assigned"}`** |
| **2026-08-12 03:37:39** | `initiate_order` | `{"actor_id":"1","reservation_failed":true}` |
| **2026-08-12 03:37:39** | `reservation_awaiting_stock` | **`{"reason":"no_warehouse_assigned"}`** |

The order was re-initiated **today** and reproduced the identical outcome — first-class evidence that the state is not stale (§12).

### Reservation audit (`order_reservation_audits`)

| from | to | reason | warehouse_id | when |
|---|---|---|---|---|
| NULL | `awaiting_stock` | Warehouse Not Assigned | NULL | 2026-08-07 02:43:51 |
| `awaiting_stock` | `awaiting_stock` | Warehouse Not Assigned | NULL | 2026-08-12 00:37:39 |

**Not once does any artefact cite stock, quantity, availability, or a material shortage.**

---

## 3. Product Availability

`products` row for FG-000001 (`019faef5-af41-7321-9f6b-546045947ace`):

| Field | Value |
|---|---|
| `sku` / `name` | FG-000001 / عسل الصال كيلو |
| `product_type` | `finished_good` |
| `is_active` | 1 |
| `brand_id` | `019faecb-8420-72ce-ac40-cf4d9e1b9ee6` |
| **`can_manufacture`** | **0** |
| **`allow_negative_stock`** | **0** |
| `stock_status` (WooCommerce mirror) | **NULL** |
| `material_cost` / `product_cost` | 1000.0000 / 3155.0000 |

Inventory for this product, across every source:

| Source | Rows |
|---|---|
| `inventory_items` | **0** |
| `stock_balances` | **0** |
| `stock_ledger_entries` | **0** |
| `stock_movements` | **0** |

**`inventory_items` is empty across the entire `ecos_dev` database** — 0 rows, 0 products, 0 warehouses, 0 companies. No product in this environment has an inventory record.

Consequently `agg_available_qty` is `null`, and `AvailabilityState::fromAvailable(null)` returns **`untracked`** — correctly, by its own documented rule (`Modules/Inventory/InventoryItems/Domain/Enums/AvailabilityState.php:52-59`).

**The finished good has zero available stock and is not permitted to go negative.**

---

## 4. Reservation State

No reservation exists or was ever attempted:

| Table | Rows for this order |
|---|---|
| `preparation_inventory_reservations` | 0 |
| `preparation_wave_orders` | 0 (table empty globally) |
| `wave_manufacturing_demand` | 0 (table empty globally) |
| `order_reservation_audits` | 2 — both `awaiting_stock` / "Warehouse Not Assigned" |

`ReserveOrderInventoryAction::execute()` **was never called for this order.** The guard in §8 returns before reaching it (`ProcessOrderWorkflow.php:123` is unreachable on this path).

---

## 5. BOM / Material Demand

An active recipe **does** exist — so "Recipe: Available" is a true statement.

**`bills_of_materials`** — `BOM-00001`, id `019faefb-be9f-72c0-9025-c988e5b7f587`, `is_active = 1`, version 1.0, `yield_quantity` 1.0000, `recipe_cost` 3155.0000, `packaging_cost` 95.0000, `cost_pending` 0, `missing_material_count` 0.

**Components** (`bill_of_material_lines`, `bom_id = 019faefb-…`):

| SKU | Name | Type | qty/FG | waste % | on_hand | reserved | available | inv rows | `allow_negative_stock` |
|---|---|---|---|---|---|---|---|---|---|
| RM-000001 | عسل الصال | `raw_material` | 1.0000 | 2.00 | 0 | 0 | 0 | **0** | **1** |
| RM-000002 | بطرمان كيلو | `packaging_material` | 1.0000 | 0.00 | 0 | 0 | 0 | **0** | **1** |

### Requirement chain (Part 5), as far as the pipeline actually goes

```
FG required            : 2.0000  (order line)
FG on_hand             : 0       (no inventory_items row)
FG reserved            : 0
FG available           : 0       → availability_state = untracked
FG allow_negative      : NO
FG can_manufacture     : NO
   ↓
BOM explosion          : NEVER EXECUTED for this order
RM required            : NOT CALCULATED
Preparation eligibility: NOT EVALUATED (order never entered a wave)
```

**`MaterialDemandCalculator` was not involved and could not have been.** It is `PreparationWave`-scoped; `preparation_wave_orders` and `wave_manufacturing_demand` are both empty, and ORD-00001 never entered preparation. Its certified contract (`on_hand 15 / reserved 8 / required 10 / available 7 / missing 3`) is untouched by this diagnosis and by this report.

---

## 6. Negative Stock Eligibility

| Item | Role | `allow_negative_stock` | available | shortage | Manufacturing eligible | Preparation eligible |
|---|---|---|---|---|---|---|
| FG-000001 عسل الصال كيلو | ordered finished good | **0 — NOT eligible** | 0 | 2.0000 | **No** (`can_manufacture = 0`) | Not reached |
| RM-000001 عسل الصال | recipe component | **1 — eligible** | 0 | n/a | Yes (credit) | Not reached |
| RM-000002 بطرمان كيلو | recipe component | **1 — eligible** | 0 | n/a | Yes (credit) | Not reached |

**The credit path is open for the materials and closed for the product being sold.**

This asymmetry is the reason the two screens disagree. `ManufacturingAvailabilityService.php:95` applies the credit rule to *components*:

```php
$isAvailable = $available > 0.0 || $material->allow_negative_stock;
```

Both components pass on the second clause, so `blocking_materials = []` and `status = 'instock'` — the source of "Manufacturing: Ready" and "Out of Stock: 0". The rule is honoured **exactly as written** for materials.

For the finished good itself the order engine reaches `ReserveOrderInventoryAction.php:187`:

```php
if ($product?->allow_negative_stock) { … commit full quantity … }
```

FG-000001 has `allow_negative_stock = 0`, so this branch does not fire. **The order status logic does honour the negative-stock rule — the product simply is not enrolled in it.**

---

## 7. Inventory Execution State

"Confirmed + Awaiting Stock" as displayed decomposes, at source, to:

- `status = awaiting_stock` — written by `ProcessOrderWorkflow` (§8)
- `reservation_status = awaiting_stock` — written by `UpdateReservationStatusAction` with reason "Warehouse Not Assigned"

Against the four candidate meanings in the task brief, the answer is **none of them**:

| Candidate | Verdict |
|---|---|
| 1. Reservation succeeded but preparation cannot proceed | **No** — no reservation exists |
| 2. Reservation failed | **No** — reservation was never attempted |
| 3. Product/material availability insufficient | **No** — availability was never evaluated on this path |
| 4. Stale state after availability became sufficient | **No** — re-run today (2026-08-12 03:37:39) reproduced it live |
| **5. Reservation was never reached — the workflow short-circuited on a missing warehouse** | **YES — proven** |

---

## 8. Exact Awaiting Stock Writer

**Two identical writers exist. The one that fired for ORD-00001 is `ProcessOrderWorkflow` — confirmed by the `initiate_order` event type in both timeline entries.**

| | |
|---|---|
| **Class** | `Modules\Operations\Fulfillment\Application\Workflows\ProcessOrderWorkflow` |
| **File** | `backend/Modules/Operations/Fulfillment/Application/Workflows/ProcessOrderWorkflow.php` |
| **Method** | `execute(FulfillmentContext $ctx)` |
| **Lines** | **97–119** (guard at 97, status write at 98, reservation status at 101–105, event at 107–113, early `return` at 115) |
| **Trigger** | Auto-invoked on order creation by `CreateManualOrderAction`; re-invoked manually via the Initiate action |

```php
// ProcessOrderWorkflow.php:95-120
if (! $alreadyReserved) {
    // No warehouse assigned → route to AwaitingStock
    if ($order->assigned_warehouse_id === null) {          // ← line 97
        $order->update(['status' => OrderStatus::AwaitingStock]);
        $order->refresh();

        $this->updateReservationStatus->execute(
            $order,
            ReservationStatus::AwaitingStock,
            'Warehouse Not Assigned',                       // ← line 104
        );

        OrderEvent::log(… payload: ['reason' => 'no_warehouse_assigned'] …);

        return FulfillmentResult::success(…);               // ← line 115, EXITS
    }

    $reservationStatus = $this->reserveInventory->execute($order);  // ← line 123, UNREACHABLE here
```

The twin writer — same condition, same reason string — is `ConfirmOrderWorkflow.php:89–111`. It did not fire here (no `confirm` event, `confirmed_at` is NULL).

### WHO put ORD-00001 into AWAITING_STOCK?

`ProcessOrderWorkflow::execute()` line 98, twice: at creation (2026-08-07 02:43:53) and on manual re-initiation (2026-08-12 03:37:39).

### WHAT EXACT CONDITION caused it?

`$order->assigned_warehouse_id === null` — evaluated at **line 97, before any inventory, availability, BOM, or manufacturing logic runs.** Nothing about stock participates in this decision.

---

## 9. Exact Condition — why the warehouse was null

`assigned_warehouse_id` is NULL because branch assignment failed upstream, at order creation, with `no_branch_coverage`.

**`CoverageResolutionService::resolve()` — `backend/Modules/Operations/Preparation/Application/Services/CoverageResolutionService.php:38`**

```php
$masterGovernorate = MasterGovernorate::whereRaw('LOWER(name) = LOWER(?)', [trim($governorate)])
    ->where('is_active', true)
    ->first();

if ($masterGovernorate === null) {
    return collect();          // ← line 43: no coverage, no branch, no warehouse
}
```

The match is against **`name` only**. `master_governorates` carries two name columns:

| id | `name` | `name_ar` | code | active |
|---|---|---|---|---|
| `019f8426-9df4-72ae-a87e-ede4abb9cf67` | **Cairo** | **القاهرة** | CAI | 1 |

All 27 rows in `master_governorates` store English in `name` (Cairo, Giza, Alexandria, Qalyubia, …). The order stores `governorate = 'القاهرة'`.

Replaying the resolver's own predicate against the live database:

```sql
SELECT COUNT(*) FROM master_governorates WHERE LOWER(name)   = LOWER('القاهرة') AND is_active=1;  -- → 0
SELECT COUNT(*) FROM master_governorates WHERE LOWER(name_ar)= LOWER('القاهرة') AND is_active=1;  -- → 1
```

**`name_ar` is never read by the resolver.** `LOWER('Cairo') = LOWER('القاهرة')` is false, so `$masterGovernorate` is null and the method returns an empty collection at line 43 — before the coverage table is ever queried.

The coverage that *should* have matched exists and is healthy:

| coverage id | branch | `master_zone_id` | priority | default warehouse |
|---|---|---|---|---|
| `019f8431-dfda-73d8-ab80-0289d6339a04` | **Cairo HQ** | NULL (governorate-wide) | 100 | **Main Warehouse** `019f4e1c-2e1b-…` |

Main Warehouse belongs to company `019f4e1c-2d1e-719d-873c-75779ab67251` — **the same company as the order.** There is no tenant or company-isolation problem here.

**Full causal chain:**

```
order.governorate = 'القاهرة'  (Arabic, from the order form)
  → CoverageResolutionService:38 matches master_governorates.name ('Cairo', English) only
  → no match → return collect()                        (line 43)
  → BranchAssignmentEngine → markNoCoverage()
  → warehouse_assignment_source = 'no_branch_coverage'
     warehouse_assignment_failure_reason = 'No Branch Covers Destination'
     assigned_warehouse_id = NULL
  → ProcessOrderWorkflow:97  assigned_warehouse_id === null
  → status = awaiting_stock, reason 'Warehouse Not Assigned'   (line 98/104)
  → UI renders "Awaiting Stock"
```

---

## 10. Product UI Availability Source

The Products Workspace panel is reading **manufacturing availability**, not finished-goods stock. Each of the four displayed facts traces to a distinct field, and **all four are correct**:

| UI element | Source | Value | Correct? |
|---|---|---|---|
| **Stock Status: In Stock** | `ProductResource.manufacturing_availability.status` ← `ManufacturingAvailabilityService::evaluate()` line 118 | `instock` | **Yes** — both components pass the credit rule |
| **Recipe: Available** | `ProductResource.has_recipe` ← active `bills_of_materials` row | BOM-00001 active | **Yes** |
| **Manufacturing: Ready** | same `manufacturing_availability.status` | `instock` | **Yes** |
| **Out of Stock: 0** | `ProductResource.blocking_materials` count | `[]` → 0 | **Yes** |

`ManufacturingAvailabilityService.php:95` — `$available > 0.0 || $material->allow_negative_stock` — passes both RMs on the credit clause despite `available = 0`.

Two other product-availability fields exist on the same resource and tell a different, equally correct story:

- `stock_status` — the **WooCommerce channel attribute**, inbound-only, never published outbound (E-3). **NULL** for this product. `ProductResource.php:160` and `StoreProductRequest.php:75-79` both document that this is *not* the ERP's availability answer.
- `availability_state` — the ERP's own answer, `AvailabilityState::fromAvailable(agg_available_qty)`. With no inventory row this is **`untracked`**.

Frontend renderers confirm the split: `product-column-defs.tsx:80-93` renders the WooCommerce `stock_status` as a binary In/Out badge, while `products-view.tsx:173` renders it via `StockStatusBadge`, which shows `—` when null.

**Nothing in the product stack is lying.** The panel answers *"can this be produced?"* and the answer is genuinely yes.

---

## 11. Order Engine Availability Source

The order engine asks a different question — *"can this be reserved from a warehouse?"* — and on this order it never got to ask it.

| Dimension | Products Workspace | Order Engine |
|---|---|---|
| Question answered | Can this FG be **manufactured**? | Can this FG be **reserved**? |
| Service | `ManufacturingAvailabilityService::evaluate()` | `ReserveOrderInventoryAction::execute()` |
| Subject | **Recipe components** (RM-000001, RM-000002) | **The finished good** (FG-000001) |
| `allow_negative_stock` read from | components → **1, 1** | finished good → **0** |
| Warehouse scope | **Company**-scoped (`inventory_items.company_id`, service line 71-83) | **Warehouse**-scoped (`assigned_warehouse_id`, action line 118-122) |
| Company | `019f4e1c-2d1e-…` (via Brand → Company, ADR-013) | `019f4e1c-2d1e-…` (order) — **same** |
| Inventory source | `inventory_items` | `inventory_items` — **same table** |
| Reservation state read | `reserved_qty` in the availability expression | `InventoryItem::availableQty()` |
| Result for ORD-00001 | `instock` | **never evaluated** |

**They read the same company, the same table, and the same reservation columns.** There is no data-source divergence and no isolation mismatch. The divergence is one of **subject and axis**: components vs. finished good, manufacturability vs. reservability. Both are right; the screens simply do not say which question they answered.

---

## 12. Status Re-evaluation Path

**A re-evaluation path exists, but ORD-00001 is structurally excluded from it.**

The only automatic retry is `RetryReservationOnStockAvailableListener`, on the `InventoryStockReceived` event:

```php
// RetryReservationOnStockAvailableListener.php:37-45
$candidates = Order::where('status', OrderStatus::AwaitingStock)
    ->where('assigned_warehouse_id', $event->warehouseId)   // ← line 38
    ->whereNull('deleted_at')
    ->whereIn('reservation_status', [AwaitingStock, Pending])
    ->whereHas('lines', fn ($q) => $q->where('product_id', $event->productId))
    ->get();
```

Line 38 is an equality predicate against a warehouse UUID. ORD-00001's `assigned_warehouse_id` is **NULL**, and in SQL `NULL = <uuid>` is never true. **The order can never appear in this candidate set** — not even if stock for FG-000001 is received into Main Warehouse tomorrow.

Re-evaluation coverage by trigger:

| Trigger | Re-evaluated? | Why |
|---|---|---|
| Stock increases | **No** | Listener filters on `assigned_warehouse_id = uuid`; NULL never matches |
| Reservation changes | **No** | No listener |
| Manufacturing completes | **No** | No listener |
| Material availability changes | **No** | No listener |
| Preparation demand changes | **No** | No listener |
| **Branch coverage data changes** | **No** | Nothing re-runs `BranchAssignmentEngine` |
| Manual re-initiate | **Yes — but futile** | Re-runs `ProcessOrderWorkflow`, which hits line 97 again and re-writes the same state (proven twice, 2026-08-07 and 2026-08-12) |

`BranchAssignmentEngine` is referenced from exactly one runtime caller — `CreateManualOrderAction` — plus its test, its docs, and its migration. **Warehouse assignment is attempted once, at creation, and never again.** No scheduled command, no listener, no re-assignment action exists.

This is a closed trap: the condition that caused the failure is never re-tested, and the mechanism that would clear it filters out precisely the orders that need it.

### Documented-contract conflict

`backend/docs/engineering/BRANCH-ASSIGNMENT-ENGINE.md:62` states, for the no-coverage case:

> **`order.status` is NOT changed.** This is an Operations triage signal, not an Inventory problem. The order stays at its current status and appears in the Operations Command Center for manual intervention.

and its certified scenario C records: *"No branch covers area → `no_branch_coverage` signal, order status unchanged (NOT `awaiting_stock`)"* — **PASS**.

`ProcessOrderWorkflow.php:29` states the opposite for the same physical situation:

> `- No warehouse → routed to AwaitingStock.`

Because `CreateManualOrderAction` runs `BranchAssignmentEngine` and then auto-triggers `ProcessOrderWorkflow`, the second contract overwrites the first. The branch engine's own test still passes — it exercises the engine in isolation, without the workflow that follows it in production. **Two modules hold contradictory, individually-certified contracts about the same state.** This is a business decision, not a defect to be silently patched (§19).

---

## 13. Runtime Evidence

All read-only. The decisive probe executes the real service in the real container against the real order:

```
docker exec ecos-dev-app php artisan tinker --execute="… CoverageResolutionService …"

order governorate  : القاهرة
order city         : مدينة نصر
coverage areas found (as stored, Arabic): 0
coverage areas found (English "Cairo") : 1
  -> branch: Cairo HQ | default_warehouse_id: 019f4e1c-2e1b-7269-bfbb-8a414cb07cab
```

The same service, same database, same moment: **the order's own governorate value resolves to zero coverage areas; the English name resolves to one**, yielding exactly the branch and warehouse that would have satisfied the order.

Supporting runtime facts:

| Probe | Result |
|---|---|
| `master_governorates` name-column match for `القاهرة` | 0 |
| `master_governorates` name_ar-column match for `القاهرة` | 1 (`Cairo` / CAI) |
| Active coverage rows for Cairo | 1 → Cairo HQ → Main Warehouse (order's own company) |
| `order_events` for ORD-00001 | 6 rows; every fulfillment row reads `no_warehouse_assigned` |
| `order_reservation_audits` | 2 rows; both "Warehouse Not Assigned", `warehouse_id` NULL |
| `inventory_items` total rows (whole DB) | **0** |
| `preparation_wave_orders` / `wave_manufacturing_demand` | 0 / 0 |
| Live re-initiation 2026-08-12 03:37:39 | reproduced `awaiting_stock` + same reason |

### Blast radius

```sql
SELECT COUNT(*) FROM orders WHERE deleted_at IS NULL;                          -- 2
SELECT COUNT(*) FROM orders WHERE status='awaiting_stock' AND deleted_at IS NULL; -- 2
SELECT COUNT(*) FROM orders WHERE assigned_warehouse_id IS NULL AND deleted_at IS NULL; -- 2
```

**Every order in `ecos_dev` — 2 of 2 — is `awaiting_stock` with no warehouse.** This is not an ORD-00001 anomaly; it is the uniform outcome for Arabic-addressed orders in this environment.

---

## 14. Root Cause Classification A–I

**Primary: I — another proven cause.**
A **locale / name-space mismatch in coverage resolution**. `CoverageResolutionService:38` matches the order's free-text governorate against `master_governorates.name` (English) and never consults `name_ar`, so every Arabic-addressed order resolves to zero coverage → no branch → no warehouse → `ProcessOrderWorkflow:97` routes to `awaiting_stock`. Proven at runtime in §13.

**Contributing: F — missing status re-evaluation.**
`RetryReservationOnStockAvailableListener:38` filters `assigned_warehouse_id = <uuid>`, which cannot match NULL, and nothing ever re-runs `BranchAssignmentEngine`. The state is permanent by construction (§12).

**Contributing: H — intended rule displayed incorrectly.**
Two separate label defects made a coherent system look self-contradictory:
1. The order shows **"Awaiting Stock"** when the stored reason is **"Warehouse Not Assigned"** — a warehouse/coverage fault presented as an inventory fault.
2. The Products Workspace shows **"In Stock"** for what is **manufacturing availability of the recipe's components**, not finished-goods stock. Beside an order for that same product, it reads as a direct contradiction.

**Explicitly excluded, with evidence:**

| | Ruled out because |
|---|---|
| **A** stale order status | Re-run live today reproduced it (§2 timeline, §13) |
| **B** incorrect reservation state | No reservation was ever attempted (§4) |
| **C** incorrect inventory availability source | Both engines read `inventory_items`, same company (§11) |
| **D** incorrect BOM/material evaluation | BOM explosion never executed; `ManufacturingAvailabilityService` verified correct (§6, §10) |
| **E** incorrect inventory-execution state | The state faithfully records what happened (§7) |
| **G** company/warehouse isolation mismatch | Order, brand→company, branch, and warehouse all resolve to `019f4e1c-2d1e-…` (§9, §11) |

---

## 15. Bug or Intended Contract?

**Three distinct verdicts — they must not be collapsed.**

| # | Behaviour | Verdict |
|---|---|---|
| 1 | `CoverageResolutionService` ignores `name_ar` while orders store Arabic governorates | **BUG.** The schema provides `name_ar` specifically to carry this value; the resolver simply does not read it. An Arabic-language ERP cannot resolve its own addresses. |
| 2 | `RetryReservationOnStockAvailableListener` excludes NULL-warehouse orders; nothing re-attempts assignment | **BUG / GAP.** Creates a permanently unrecoverable state with no operator path out. |
| 3 | `ProcessOrderWorkflow` routes no-warehouse orders to `AwaitingStock` | **INTENDED — but in direct conflict with a second certified contract.** `ProcessOrderWorkflow.php:29` mandates it; `BRANCH-ASSIGNMENT-ENGINE.md:62` and its PASSing scenario C mandate the opposite. **Requires your ruling (§19).** |

And separately, **the Products Workspace display is not a bug at all** — every field is correct for the question it answers (§10). Only the labelling invites the misreading.

---

## 16. Minimal Repair Required

**Not implemented — reported only, per the strict stop rule.**

### Repair 1 — the proven defect (smallest correct change)

`backend/Modules/Operations/Preparation/Application/Services/CoverageResolutionService.php:38` — also match the Arabic name column:

```php
$masterGovernorate = MasterGovernorate::where('is_active', true)
    ->where(fn ($q) => $q
        ->whereRaw('LOWER(name) = LOWER(?)',    [trim($governorate)])
        ->orWhereRaw('LOWER(name_ar) = LOWER(?)', [trim($governorate)]))
    ->first();
```

The same consideration applies to the `MasterZone` lookup at line 48, which has the identical single-column assumption for the city/zone value (`مدينة نصر`).

> ### ⚠ Repair 1 alone will NOT release ORD-00001
>
> With a warehouse assigned, `ProcessOrderWorkflow` proceeds past line 97 into `ReserveOrderInventoryAction`, where the order meets a **second, independent blocker**:
>
> | Gate | Line | FG-000001 | Outcome |
> |---|---|---|---|
> | Case 1 — sufficient FG stock | `:125` `$available >= $requested` | 0 ≥ 2 false | skipped |
> | Case 2 — manufacturing | `:159` `$product?->can_manufacture && …` | **`can_manufacture = 0`** → false | **skipped** |
> | Case 3 — negative stock | `:187` `$product?->allow_negative_stock` | **`allow_negative_stock = 0`** → false | **skipped** |
> | → shortage path | | | **`awaiting_stock` again** |
>
> The order would return to `awaiting_stock` with the reason changing from *"Warehouse Not Assigned"* to *"Insufficient Inventory"* — this time a genuine stock verdict, correctly reached.
>
> Note `manufacturingIsExecutable()` (`:66-69`) would return **true** for this product — the recipe *is* executable. It is never consulted, because line 159 requires `can_manufacture` first, and that flag is 0.

### Data observation (not a code repair)

`products.can_manufacture = 0` for FG-000001 while an **active BOM-00001 exists**. `Product.php:51` documents the flag as *"Has a recipe and may be produced."* The flag and the recipe disagree. Whether that is a data-entry gap or a deliberate "recipe exists but production not authorised" state is a **business question**, not something to infer — see §19.

---

## 17. Regression Risks

| Repair | Risk |
|---|---|
| Coverage `name_ar` matching | **Broadens** matching — an English and an Arabic name could theoretically collide across different governorates. The 27 live rows show no such collision. Both `name` and `name_ar` are unconstrained by a unique index; add one, or accept `first()` ordering. |
| | Currently **100 % of orders (2/2) resolve to no coverage.** After the fix they will begin resolving to branches and warehouses, activating reservation paths that have never executed against real data in this environment. Expect previously-dormant behaviour to surface. |
| | `BranchAssignmentEngineTest` scenarios A–D construct their own fixtures and assert on the engine; matching an extra column should not disturb them, but they must be re-run. |
| Retry-listener NULL handling | Widening the candidate query would make NULL-warehouse orders re-enter `ProcessOrderWorkflow` on every stock receipt. Without also re-running branch assignment they would loop to the same outcome, adding audit noise on every receipt. Re-assignment must come first, or the two changes must land together. |
| Any status-contract change (§15 item 3) | Touches `ProcessOrderWorkflow` **and** `ConfirmOrderWorkflow` (identical guards) plus the Operations Command Center triage view. Both are certified surfaces. |
| Existing stuck orders | Both live orders would need a deliberate, reversible re-assignment + re-initiation. **No such action was taken.** |

---

## 18. Certified Baseline Integrity

Host working tree vs. the code actually loaded inside `ecos-dev-app` (MD5):

| File | Host | Container | Parity |
|---|---|---|---|
| `ManufacturingAvailabilityService.php` | `14701fd3…` | `14701fd3…` | **MATCH** |
| `CoverageResolutionService.php` | `d5d1c05f…` | `d5d1c05f…` | **MATCH** |
| `ProcessOrderWorkflow.php` | `effa04d8…` | `effa04d8…` | **MATCH** |
| `ReserveOrderInventoryAction.php` | `670ba67a…` | `670ba67a…` | **MATCH** |
| **`MaterialDemandCalculator.php`** | **`ce69612a…`** | **`4c2903b8…`** | **BROKEN** |

### The parity break, characterised

`git show HEAD:…/MaterialDemandCalculator.php | md5sum` → **`4c2903b8…`** — identical to the container.

**`ecos-dev-app` is running the pre-repair `HEAD` version. The certified fix exists only on the host filesystem and has never been `docker cp`'d into the container.**

The uncommitted certified repair changes the availability rule:

```php
- $available = max(0.0, $onHand);              // HEAD — what the container runs
+ $available = max(0.0, $onHand - $reserved);  // certified host version
```

Against the contract stated in the task brief (`on_hand 15, reserved 8, required 10`): the host version yields `available 7, missing 3` — **the certified figures**. The container version yields `available 15, missing 0`. **The certified contract is not live in the DEV runtime.**

**Part 10 STOP condition is triggered.** Scope of the impact:

- **Does not affect this diagnosis.** `MaterialDemandCalculator` is `PreparationWave`-scoped; `preparation_wave_orders` and `wave_manufacturing_demand` are both empty, ORD-00001 never entered preparation, and `ProcessOrderWorkflow:97` short-circuits long before any demand calculation. Every file on the proven causal path — `CoverageResolutionService`, `ProcessOrderWorkflow`, `ManufacturingAvailabilityService`, `ReserveOrderInventoryAction` — has **exact host/container parity**.
- **Does affect anything else.** Any runtime test, UAT step, or certification involving preparation-wave material demand on this container is measuring the **old** rule. Restore parity with `docker cp` before trusting such a result. Per the memory rule for this stack, the source volume is not hot-mounted — every source edit needs an explicit copy into the container.

Other certified surfaces named in the brief — Preparation Backend, Preparation Entry Gate, F4/Option B (`ReserveOrderInventoryAction`, `ManufacturingAvailabilityService`), IAM Tenant Boundary, Password Reset Domain Operation — were **read only**. `ReserveOrderInventoryAction` and `ManufacturingAvailabilityService` carry uncommitted working-tree changes (the certified F4 repairs) and are byte-identical to what the container runs.

**Nothing was modified by this task:** no source file, no test, no schema, no migration, no seed, no order row, no reservation row. All database access was `SELECT` / `SHOW`. The single `tinker` call invoked only `CoverageResolutionService::resolve()`, which performs read-only queries.

---

## 19. Open Business Decisions

These cannot be resolved from code or data — they need your ruling.

1. **Which status contract wins for a no-coverage order?**
   `ProcessOrderWorkflow.php:29` says route to `AwaitingStock`. `BRANCH-ASSIGNMENT-ENGINE.md:62` and its PASSing scenario C say leave the status alone and raise an Operations triage signal. Both are certified. In production the workflow silently overrides the engine. **One of the two contracts must be retired.**

2. **Should "no warehouse" be an inventory status at all?**
   Presenting a coverage/geography failure as *"Awaiting Stock"* sends operators to the warehouse to look for stock that was never the problem. If a distinct status or surfaced reason (e.g. *"Awaiting Warehouse Assignment"*) is wanted, that is a new enum value and a new operator surface — outside this task.

3. **What re-triggers warehouse assignment?**
   Today: nothing, ever. Options include a re-assignment action on the order, a listener on branch-coverage changes, or a scheduled sweep of NULL-warehouse orders. **Which trigger is authoritative is a business decision** — and until one exists, every order that fails assignment is permanently stuck.

4. **Is FG-000001 intended to be non-credit?**
   `allow_negative_stock = 0` on the finished good while **both** its recipe components are `1`. If finished goods are meant to be sellable on credit like their materials, this is a data gap; if finished goods must never go negative, the current setting is right and ORD-00001 legitimately cannot be fulfilled without real stock or production.

5. **Is `can_manufacture = 0` correct for a product with an active recipe?**
   BOM-00001 is active and `manufacturingIsExecutable()` would return **true**, yet the flag closes the manufacturing path at `ReserveOrderInventoryAction:159`. Either the flag is stale data, or it deliberately means "recipe exists, production not authorised" — a distinction the platform does not currently document.

6. **Should the two workspaces be relabelled?**
   The Products Workspace correctly reports *manufacturability*; the Orders Workspace correctly reports *reservability*. Both said something true, and together they read as a contradiction. Whether to disambiguate the labels is a product decision.

7. **How should the existing 2 stuck orders be released?**
   Both are `awaiting_stock` with NULL warehouse. Any correction requires mutating live order data. **No mutation was performed** — awaiting your instruction.

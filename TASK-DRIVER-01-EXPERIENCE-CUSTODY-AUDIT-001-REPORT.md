# TASK-DRIVER-01 — DRIVER EXPERIENCE & CUSTODY AUDIT

**Mode:** AUDIT ONLY — read-only. No source, database, business-data, migration, commit or deploy change.
**Date:** 2026-08-24
**Environment:** `ecos_dev`

---

## 1. Executive Summary

A driver application already exists — 11 pages, 12 components, a dedicated backend controller with a
genuine driver-identity guard. It has never worked, and it cannot be reached by a driver.

Five findings dominate everything else, and each is independently blocking:

**1. The `driver` role gets 403 on every driver endpoint.** `/api/driver/*` is gated on
`loading.driver.operate`; the seeded `driver` role holds only `logistics.shipping.view` and
`logistics.distribution.view|update`. The seed migration says this is deliberate:
*"A Driver never receives these here… granted to company-admin, NOT to any driver role"*
(`seed_loading_os_permissions.php:30-33`). The single test that covers it uses `actingAs()`, which
auto-grants a system role — which is why the gap never surfaced.

**2. The driver app is in no live navigation module.** Its only nav entry sits in
`frontend/src/config/navigation.ts`, a file with **zero importers** that the live registry calls
"removed" (`module-navigation.ts:609`). The pages render inside the desktop AppShell with no
sidebar. They are reachable only by typing the URL.

**3. The frontend and backend do not share a contract.** `tripSummary()` omits 13 of the 17 fields
the `DriverTrip` type declares → home-page totals are `NaN` and the KPI grid never renders.
`stopSummary()` sends no `order` object → every stop card is blank. `stopDetail()` sends `phone`,
`address`, `gps`; the page reads `billing_phone`, `shipping_address`, and never reads `gps`. **Most
of the driver's order screen is silently empty today.**

**4. Four financial endpoints are deliberately frozen at 403**, and four more frontend calls hit
routes that do not exist (404). The entire `/api/distribution/*` handover surface the
distribution-board components call is absent from the only route file.

**5. There is a second, weaker payment-proof system on the path the driver role *can* reach — and
its maker-checker has collapsed.** `distribution_payment_collections` replicates the three-state
proof lifecycle with a **client-supplied `image_path` string** instead of a validated file and **no
`company_id` column**. Record and verify take the *same* permission, which `driver` holds, and
`SettlementService::verifyPayment()` performs no self-review check. **A driver can record a payment
and then verify their own payment** — on any trip, cross-company, because `Trip` has no tenant scope
and `SettlementController::resolveTrip()` is `Trip::where('uuid',…)->firstOrFail()`.

Two architectural findings shape everything that follows. **Vehicle inventory is a second inventory
engine** — `vehicle_inventory_items` never touches `stock_ledger_entries`; loading a vehicle credits
the vehicle and debits nothing, anywhere. And **the existing Waste + Liability contracts cannot be
reused for drivers**: `waste_investigations.count_session_id` and `count_line_id` are `NOT NULL` FKs
to inventory-count tables, so a row cannot physically exist without a warehouse count.

**Data reality:** 0 drivers, 0 vehicles, 0 vehicle-inventory rows, 0 loading sessions, 0 delivery
stops, 0 settlements, 0 waste investigations, 0 liabilities. Only `distribution_trips` (2) and
`distribution_trip_orders` (4) hold anything. **Nothing in this stack has ever run end-to-end**, and
browser verification is impossible without fabricating fleet data — which is out of bounds.

---

## 2. Driver IA / Existing Navigation

**The business rule "Driver Experience = SHIPPING" is not met, and neither is the fallback.**

| Surface | Module today | File |
|---|---|---|
| Driver app (11 pages) | **none — no module owns `/driver/*`** | `frontend/src/features/operations/driver-mobile/` |
| Driver admin CRUD | `shipping` | nav key `logistics-drivers` → `/logistics/drivers` (`module-navigation.ts:300`) |
| Loading workspace | `operations` | nav key `loading-drivers` → `/operations/loading/workspace` (`module-navigation.ts:281`) |

`findModuleByPath()` (`module-navigation.ts:597-604`) matches no module for `/driver`, so
`use-active-module.ts:7` returns `undefined` and `app-sidebar.tsx:141` returns `null`. The routes are
children of `AppShell` (`router.ts:242`, `:520-531`) — **not** a full-screen mobile shell like POS
(`router.ts:240`).

A `shipping` module exists and is the correct destination (`module-navigation.ts:288-330`). A second
module `id: 'logistics'` exists with `items: []` (`:423-428`) and also renders nothing.

**Requested nav → today**

| # | Requested | Status | Evidence |
|---|---|---|---|
| 1 | Home | **EXISTS, unreachable** | `driver-home-page.tsx` — a trip list, not a home |
| 2 | Loading | **MISSING (driver-side)** | driver-acceptance API exists (`api.php:1828`) but is gated on the dispatcher permission and has **no UI** |
| 3 | Vehicle Warehouse | **MISSING** | `driver-custody-return-page.tsx` calls `GET /driver/trips/{id}/custody-returns` — **no such route** → 404 |
| 4 | Orders | **PARTIAL** | `driver-stop-list-page.tsx` — delivery stops; list endpoint returns no order data |
| 5 | Started Delivery | **PARTIAL** | trip-level "Start Trip" only; no per-stop start for a driver |
| 6 | Failed Delivery | **PARTIAL** | a `failed` filter tab + a read-only exceptions page |
| 7 | Expenses | **MISSING** | zero hits for `expense` in the whole driver feature |
| 8 | Reports | **MISSING** | no driver report anywhere |
| 9 | Wallet | **MISSING** | settlement UI exists; its endpoint returns **403 frozen** |

**Zero i18n** in `features/operations/driver-mobile/` — no `useTranslation` anywhere; every string is
hardcoded English. This violates the platform's Arabic+English requirement.

---

## 3. Driver Home

`driver-home-page.tsx` (70 lines) renders a static header `"Driver Mobile"`, a 3-tile KPI row, and a
trip list.

| Required | Status |
|---|---|
| Driver name | **MISSING** — header is a literal string |
| Orders assigned to this driver only | **Scoping correct, data absent** — `DriverRuntimeController::trips()` (`:49-63`) filters `whereHas('driverVehicleAssignment', fn($q) => $q->where('driver_id', $driver->id))`, but `tripSummary()` (`:347-363`) never emits `orders_count` → `totalOrders` is **NaN** |
| "Start Loading" when a trip is assigned | **MISSING** |
| "No trip assigned yet" | **DIFFERENT** — renders *"No active trips"* |
| KPIs: Orders today / Delivered / In Progress / Failed | **PARTIAL** — `trip-kpi-grid.tsx:16-23` has Total Orders, Pending, Delivered, Partial, Failed, Collections. **"In Progress" MISSING**; "Orders today" MISSING (per-trip, not day-scoped). And the grid is **not on the home page** — only on the trip dashboard, where `trip.kpis` is undefined so it never renders. |
| Financial summary: collected / paid / transferred | **EXISTS as UI, dead** — `settlement-summary.tsx:12-20` has all seven rows; its endpoint is frozen at 403 |

---

## 4. Driver Availability

**There is no availability model, and no `DriverStatus` enum.** Values are class constants —
`Driver.php:28-34`:

```php
public const STATUS_ACTIVE = 'active';
public const STATUS_INACTIVE = 'inactive';
public const STATUS_ARCHIVED = 'archived';
```

Column: `create_logistics_drivers_table.php:43` — `string('status', 20)->default('active')`.
Validated in both write paths (`DriverController.php:173`, `:378`) via `Rule::in(Driver::STATUSES)`.

**"Available for Work / Unavailable / Vacation" do not exist** in any form — no column, constant,
enum member or UI control. `inactive` is the nearest state and it is not time-boxed. **Per the brief,
no new statuses were proposed.**

Status **is** consulted for assignment eligibility at five sites via the shared predicate
`Driver::canStartDeliveries()` (`:235-241` — active + licence valid + has an active assignment):
`DistributionWindowController.php:478` (candidate list), `Trip.php:242` (dispatch readiness),
`DeliveryExecutionService.php:108`, `ConflictDetectionService.php:140`, `ResourcePoolService.php:86`.

Two gaps: vehicle pairing blocks **archived only**, not inactive
(`DriverVehicleAssignmentService.php:41-43`); and **the driver runtime never calls
`canStartDeliveries()`** — an inactive driver with an expired licence can still open their trips.

---

## 5. Loading

`Modules/Operations/Loading` — 16 actions, 8 domain services, 24 models, 23 migrations.
Session lifecycle: `draft → ready → loading → loading_complete → allocating → allocated → dispatching → dispatched → reconciling → closed` (+`cancelled`).

**Required vs actually-loaded already exists, in three separate places:**

1. `loading_tasks` — `quantity_planned` (required) / `quantity_loaded` (actual) / `quantity_short`,
   unique `(vehicle_assignment_id, product_id)`, statuses `pending|in_progress|loaded|short_loaded|blocked|skipped`.
2. `vehicle_shift_reconciliation_lines` — `quantity_loaded / _delivered / _returned_expected / _returned_actual / variance`.
3. `distribution_group_product_preparation` — Required deliberately **not stored** (derived live), Prepared absolute.

**Confirmation:** `POST /api/loading/sessions/{s}/assignments/{a}/load-product` →
`LoadProductAction::execute()` — absolute set under `lockForUpdate` (`:83-106`), over-load fails
closed (`:59-65`), then `VehicleInventoryService::recordLoad($delta)`.

**Accumulation exists** (`VehicleInventoryService.php:48-50`) and is deliberately fed deltas by its
only caller (`LoadProductAction.php:108`), making the net contract absolute and idempotent.

**No driver-facing loading endpoint exists.** The whole `/api/loading` group (`api.php:961-1013`)
carries **zero `permission:` middleware** — authorization is policy-based in-controller. And
`loading.driver.operate` gates only trip/stop endpoints, none of them loading.
`loading_tasks.confirmed_by` / `confirmed_at` exist and **nothing writes them**.

Historical visibility is preserved: `closed`/`cancelled` are terminal, all FKs are `restrictOnDelete`,
and movements are append-only.

---

## 6. Vehicle Warehouse / Custody

> **CANONICAL SOURCE OF TRUTH FOR VEHICLE-HELD STOCK = `vehicle_inventory_items`**
> (+ append-only `vehicle_inventory_movements`), owned by `Modules/Operations/Loading`, written
> exclusively through `VehicleInventoryService`.

Grain: **vehicle_assignment × product** — effectively (vehicle, shift, product). Unique index
`(vehicle_assignment_id, product_id)`. Quantities: `loaded / allocated / delivered / returned /
on_hand / unallocated` at decimal(18,4) with `>= 0` CHECKs.

**A vehicle is NOT modelled as a warehouse.** There is no `warehouse_id`, no `location_id`. And **no
inventory-location concept exists anywhere** — a repo-wide search for a locations table returns zero.
So a vehicle could not be modelled as a location even if that were wanted.

### 🔴 It is a second inventory engine

`Modules/Operations/Loading` contains **zero references** to `stock_ledger_entries`,
`stock_balances`, `stock_movements`, `InventoryItem`, or `prepared_products_pool`.
**Loading a vehicle creates stock in `vehicle_inventory_items` and decrements nothing, anywhere.**

Three further defects:

- **`recordReturn()` and `unallocate()` have zero callers.** `quantity_returned` is never written, so
  the ADR-015 variance formula `loaded − delivered − returned` always reads `returned = 0` on this
  table. Statuses `returned` and `variance` are unreachable.
- **No tenant scope.** `company_id` has no FK and no index; the model has no global scope. Isolation
  depends on each controller re-deriving session → assignment.
- **Three unconnected product-return paths**: the dead `vehicle_inventory_items.quantity_returned`,
  the live `vehicle_shift_reconciliation_lines.quantity_returned_actual`, and `distribution_returns`.

**`distribution_trip_custody` is NOT a competing store** — its own docblock says *"Equipment and cash
float"*; `CustodyItemType` is `cash_float | pos_device | ice_boxes | ice_packs | thermal_bags |
delivery_bags | other`, quantity is an integer, and there is no `product_id`.

**Deduction on delivery:** the Logistics delivery path touches **nothing** inventory-related. Only the
separate warehouse endpoint `POST /loading/.../allocation/deliver` decrements the vehicle. **The two
are not connected** — completing a stop never calls it, and no listener bridges them.

---

## 7. Receiving

| Requirement | Status |
|---|---|
| Driver records quantity actually received (product) | **MISSING** — no `quantity_received` on `vehicle_inventory_items` or `loading_tasks` |
| Driver confirms receipt (equipment / cash float) | **EXISTS** — `PATCH /logistics/distribution/trips/{id}/custody/{custodyId}/confirm` → `TripService::confirmCustody()` writes `received_quantity`, `is_driver_confirmed`, `driver_confirmed_at/by` |
| Boolean trip acceptance | **EXISTS** — `PATCH .../driver-acceptance` → `TripService::recordDriverAcceptance()`; its discrepancy rule reads **only** the custody shortfall, so a product shortfall can never reach it |

Both are gated on `logistics.distribution.update` — the **dispatcher** permission, not a driver one.

### 🔴 The handover UI is wired to a backend that does not exist

`distribution-board-service.ts` (`BASE = '/api/distribution'`) calls
`GET /trips/{id}/handover-status`, `POST /manifests/{id}/items/{id}/driver-confirm`,
`POST /manifests/{id}/items/{id}/accept-discrepancy`,
`POST /trips/{id}/custody/{id}/driver-confirm`, `POST /trips/{id}/driver-accept`.
**None exists.** There is no `prefix('distribution')` group (only `logistics/distribution`), and the
string `manifest` appears nowhere in the route files. The frontend type
`HandoverManifestItem.driver_received_qty` has **no backend column at all**.

The three components (`driver-custody-confirmation.tsx`, `driver-product-confirmation.tsx`,
`driver-acceptance-form.tsx`) are presentational only — they take callbacks and call nothing directly.

---

## 8. Waste

> ### CAN THE EXISTING WASTE + LIABILITY CONTRACTS BE REUSED FOR DRIVERS? **NO.**

**The deciding constraint** — `create_waste_investigations_table.php:21-22` + FKs at
`add_fks_to_waste_investigations.php:22-23`:

```php
$table->uuid('count_session_id');   // NOT NULL, no default
$table->uuid('count_line_id');      // NOT NULL, no default
->foreign('count_session_id')->references('id')->on('inventory_count_sessions')->restrictOnDelete();
->foreign('count_line_id')->references('id')->on('inventory_count_lines')->restrictOnDelete();
```

A `waste_investigations` row **cannot physically exist** without an approved inventory count session
and a specific count line. A driver reporting breakage in a vehicle has neither. There is also **no
POST create route** — `ApproveCountSessionAction.php:196` is the sole producer in the codebase.

Three further disqualifiers: `warehouse_id` is `uuid NOT NULL` FK→`warehouses` with no vehicle/trip
alternative and no polymorphic source column; `logistics_vehicles`/`logistics_drivers` are bigint-PK
and cannot satisfy it; and the `driver` role holds no `inventory.*` permission at all.

**On the business rule "reporting waste MUST NOT automatically become liability":** the rule holds at
the *reporting* step — approval creates only a `pending_investigation`, and only 1 of 4 outcomes
(`warehouse_responsibility`) creates a liability. **But there are two live breaches of its spirit:**

- 🔴 **`ResolveWasteInvestigationAction.php:114-116` creates the liability already `status = 'approved'`**,
  stamping `approved_by`/`approved_at` from a **client-supplied string**, never invoking
  `ApproveWarehouseLiabilityAction` and never passing `permission:inventory.liabilities.approve`. One
  actor holding `inventory.waste.resolve` mints an approved financial liability in a single call.
- A count **shortage** becomes a liability with no investigation at all
  (`ApproveCountSessionAction.php:171-190`) — correctly `pending`, but bypassing investigation entirely.

**`warehouse_liabilities` has no party FK of any kind.** The only human field is
`warehouse_manager varchar(255) NULL` — and **no code ever writes it**. The `by_manager` report
always returns empty. Nobody is currently identified as liable, warehouse staff included.

**Waste reason is free text** — `varchar(100)`, no enum, no `Rule::in`. The `by_reason` report groups
on unnormalised operator-typed strings, bilingual in an Arabic/English deployment.

**Attachments work** (validated file, private `local` disk, jpg/png/pdf/video, 20 MB) — but **there is
no GET/download route**, so uploaded evidence cannot be retrieved over HTTP.

**Costing:** on approval, value is overwritten by true FIFO layer consumption via
`InventoryLayerConsumptionService::consume()`. `cost_method` is hardcoded `'FIFO'` **even when the
FIFO branch did not run** — if no `inventory_item` row exists, the label misrepresents an
average-cost fallback. The creation-time fallback chain is average-first while
`EnterpriseCostEngine` is FIFO-first — the two return different numbers for the same product.
Divergence from the supplier-return contract is real but defensible: waste omits
`$goodsReceiptLineId`, so it uses unrestricted oldest-first FIFO — damaged stock has no receipt anchor.

**Inventory effect on approval:** writes canonical `stock_ledger_entries` (not legacy
`stock_movements`) via `AdjustmentOutAction`, plus receipt-layer decrements and consumption audit
rows, all in one transaction. `bypassReserveGuard: true` on both paths.

⚠ **Out-of-scope but material:** the six report endpoints on both controllers use **PostgreSQL-only
SQL** — `EXTRACT(EPOCH FROM …)`, `DATE_TRUNC`, `ilike`, `TO_CHAR(… INTERVAL …)` — against **MySQL 8.4**.
They will fatal at runtime.

---

## 9. Orders

`delivery-stop-card.tsx` shows sequence, order number, customer name, status badge, address,
collected amount, and a `tel:` phone link. **Not shown:** product count, order value, payment method,
coordinates, or any action.

🔴 **The list endpoint sends none of it.** `stopSummary()` (`:366-376`) returns only
`id, trip_id, order_id, sequence, status, completed_at` — **no `order` key**, while the type declares
it non-optional. Every card renders `Stop #N` / `—` / no address / `0.00`, and the search box (which
matches on `order.order_number` and `order.customer_name`) can never match.

**Filters:** status tabs (`all | pending | delivered | failed`) + free-text search only. **No area or
zone filter anywhere.**

**Geography vocabulary:** free-text `shipping_address`, `governorate`, `city`, `area`, joined into a
string; navigation opens a Google Maps **text query**, not coordinates. **`logistics_city_id`,
`delivery_zone` and `distribution_zones` appear nowhere in the driver feature.** The backend *does*
send real `gps{lat,lng}` on stop detail — the UI never reads it. `zone_code` is declared in the type
and has no backend producer.

**Reusable components that exist:** `OrderMobileCard` (`order-mobile-card.tsx:27`) is the closest
asset — a purpose-built, fully i18n'd mobile order card. Also `OrderDetailDrawer`,
`DistributionOrderDetail`, and cell-level reusables (`order-address-cell`, `order-phone-cell`,
`order-payment-cell`, `order-items-preview`, `order-status-badge`).

---

## 10. Started Delivery

**The existing business action is not reachable by a driver.**

`Order.status → out_for_delivery` is written by `LoadVehicleWorkflow.php:94` at **vehicle dispatch**,
before the driver moves. `→ delivered` by `CompleteDeliveryWorkflow.php:50`. The driver reaches
neither, deliberately — `DriverRuntimeController.php:35-36`: *"this controller never writes
Order.status (that is guarded by OrderStatusGuard and must route through the Fulfillment engine)."*

A per-stop start exists — `DeliveryService::startStop()` (`:57-71`, sets `InProgress` +
`attempted_at`) — exposed **only** to the dispatcher (`api.php:1847`,
`permission:logistics.distribution.update`). **The driver has no endpoint to start a stop.**

**No UI patches `Order.status`** — the driver service issues only GET/POST to `/driver/*`. Good.
**No "Started Delivery" list** — no in-progress tab, and no driver path can produce that state.

---

## 11. Failed Delivery

**Two stacks, only one with an enum.**

Stack A (`Modules/Logistics/Delivery`) has `FailureReason` (15 cases) + `FailureCategory`
(`customer|address|product|payment|operational`), enforced by `Rule::in(FailureReason::values())`.
**Stack B (Distribution — the driver's stack) has no reason enum**: `exception_type` is
`['required','string','max:100']` and `reason` is free text.

| Requested reason | Stack A | Stack B driver `action_type` |
|---|---|---|
| Customer unavailable | `customer_unavailable`, `no_answer` | `not_available` |
| Customer refused | `customer_refused` | `refused` |
| Incorrect address | `address_not_found`, `address_inaccessible`, `wrong_area` | `wrong_address` |
| Payment issue | `cannot_pay`, `amount_disputed` | **MISSING** |
| Postponement | `customer_rescheduled` | `delay` — but `outcomeFor()` returns `null`, so **the stop is never settled** |
| Other | **MISSING** (no `other` case) | free-text `reason` |

**Attempt count and time: Stack A only.** Stack B has no attempt counter; `attempted_at` is stamped
once (`?? now()`), and `unique(['trip_id','order_id'])` means a re-attempt needs a whole new trip.

🔴 **A driver cannot report a failure reason today.** `driver-exceptions-page.tsx` is read-only; its
"Add" button tells the user to go to the stops page; the stop detail page has **no exception button**;
and `ExceptionForm` is **orphaned** (no importer).

---

## 12. Order Detail

🔴 **The backend and the page use different field names.** This is why most of the screen is blank:

| Field | Backend sends | Frontend reads | Result |
|---|---|---|---|
| phone | `phone` (:390) | `order.billing_phone` | **never renders** |
| address | `address` (:391) | `order.shipping_address` | **never renders** — governorate/city/area are nested inside this gate, so they vanish too |
| lat/lng | `gps{lat,lng}` (:395) | *never read* | unused — no map link |
| product name | *not sent* | `line.product_name` | `undefined` |
| quantity | `ordered_qty` (:403) | `line.quantity` | `undefined` |
| unit price / line total | *not sent* | `line.line_total` | `money(NaN)` |
| delivered qty | `delivered_qty` (:405) | *never read* | unused |
| payment_method, delivery_notes | *not sent* | read | never render |

Only `customer_name`, `grand_total`, `deposit_paid`, `remaining_balance`, `status`, `sequence` work.

**Customer delivery history / rate: not in the payload at all.** A metric exists —
`CustomerOrderMetricsService.php:305-317` (`delivered_count`, `receiving_rate`) — and a second,
conflicting one at `CreateBusinessContextSnapshotService.php:237-254` (`delivery_success_rate`) which
**has no `company_id` filter**. Both are pure `orders.status` ratios; **no metric anywhere derives
from delivery execution.**

---

## 13. Partial Delivery

🔴 **A driver cannot record quantities today, and the engine already exists elsewhere.**

`outcomeFor()` maps `'partial' → DeliveryStopStatus::Partial`, but the validator directly above
accepts **no quantity field**, and `completeStop()` has **no lines parameter and no per-product loop**.
`DeliveryStopStatus::Partial` is written twice and **read by nothing**.

**The quantity engine exists in a third module.** `allocation_records`
(`Modules/Operations/Loading`) carries `quantity_requested / allocated / loaded / delivered /
remaining`, `is_partial`, `partial_reason`, with a CHECK already allowing `partial_delivery`. Its sole
writer is **`RecordProductDeliveryAction`** (`lockForUpdate`, over-delivery fails closed) at
`api.php:995-996` — **called by the warehouse UI, never by the driver app.** Its own docblock names
this exact gap: *"Delivery was confirmed only at order-stop granularity, which carries no product
quantity at all."*

**Live bug:** `order_lines.delivered_qty` is `$fillable` and read in two places but has **zero
writers**. So `remaining_qty` always returns the full ordered quantity, even after a partial delivery.

**Do not build a fourth quantity store.** Route the driver to `RecordProductDeliveryAction`.

---

## 14. Delivery Proof

Two stores, neither attached to the Order — correct per the brief:

| | Stack B (driver) | Stack A |
|---|---|---|
| Table | `distribution_delivery_proofs` | `delivery_pods` + `delivery_pod_artifacts` |
| Attached to | the **stop** | the **attempt** (`unique('attempt_id')`) |
| Shape | flat `signature_path` + `photos` JSON | typed artifact rows (`signature|photo|id_scan|otp`) with mime/size |

🔴 **Neither ever accepts a file.** A repo-wide search for `UploadedFile`, `'file'`, `Storage::disk`
across both modules returns **nothing**. Both take `file_path` as a **client-supplied string**. The
upload endpoint is a path-string registry pointing at files nothing stored. Neither names a disk.

The driver POD path is dead-ended anyway: `submitProofOfDelivery()` exists but **no hook wraps it**,
`ProofOfDeliveryForm` is **orphaned**, and the "Proof of Delivery" button navigates to `.../proof` —
**a route that does not exist**.

**Two reusable patterns exist:** a generic polymorphic `documents` table
(`database/migrations/2026_07_05_200300_create_documents_table.php` + `app/Core/Documents/Document.php`),
and — inside Logistics itself — `DriverController::storeDocument` (`:215-225`), which already performs
a real validated upload to the private `local` disk.

Compared with `UploadPaymentProofAction` (real `UploadedFile`, private disk, MIME sniffing,
`size_bytes`, supersede chain, tenant-scoped download), delivery proof in **both** stacks has none of
it. **This is an architecture decision, not a bug to patch.**

---

## 15. Payment Proof

🔴 **The driver path does not use the canonical `payment_proofs` lifecycle. Zero references** to
`PaymentProof` / `payment_proofs` exist anywhere in `Modules/Logistics/` or the driver frontend.

**A second proof system exists** — `distribution_payment_collections`:

| Concern | `payment_proofs` (canonical) | `distribution_payment_collections` |
|---|---|---|
| Lifecycle | uploaded / verified / rejected | recorded / verified / rejected — **same three states** |
| Evidence | validated file, private disk, MIME sniffed, `size_bytes`, supersede chain | **`image_path` — a client-supplied string**, `['nullable','string','max:500']` |
| Tenancy | `company_id` FK + order-derived scope | **no `company_id` column at all** |
| Rejection reason | dedicated column | overwrites `notes` |

A **third** exists — `delivery_cod_records` — with the same verify lifecycle again; its own docblock
acknowledges *"nothing here writes to distribution_payment_collections"*.

### 🔴 Maker-checker has collapsed on the driver-reachable path

```
POST  /trips/{tripId}/stops/{stopId}/payments      permission:logistics.distribution.update
PATCH /trips/{tripId}/payments/{paymentId}/verify  permission:logistics.distribution.update
```

**Same permission for record and verify — and `driver` holds it.** `SettlementService::verifyPayment()`
(`:49-58`) performs **no self-review check**; the canonical control
(`VerifyPaymentProofAction.php:58`, identity-based, not bypassed by system roles) has no counterpart.
**A driver can record a payment and then verify their own payment.**

Aggravating factors: `SettlementController::resolveTrip()` is `Trip::where('uuid',…)->firstOrFail()`
with **no company and no driver filter**, and `Trip` has **no tenant global scope** — so this is
cross-company. The read endpoints (`api.php:1863, :1867, :1873`) carry **no permission at all**.

**This was already reported.** `TASK-SHIPPING-DRIVER-CLOSURE-001-ENGINEERING-REPORT.md:158-161`
recorded it and recommended splitting `logistics.settlement.*` off and revoking it from `driver`.
**Not done.**

**The driver cannot change an order's payment method** — the only route is
`PATCH /orders/{order}/quick-update` on `sales.orders.update`, which `driver` does not hold. ✅

**`PaymentFulfillmentGate` is untouched by the driver path** — neither invoked nor bypassed. The
corollary: money recorded in `distribution_payment_collections` **can never satisfy an order's proof
requirement**. ✅ for gate integrity, ❌ for the business flow.

---

## 16. Expenses

**MISSING entirely.** Zero driver-expense concept: no table, no permission, no UI, no approval
workflow. Every `expense` hit in the backend is Finance GL or CRM loyalty; `petty cash` is GL account
1120; `reimbursement` and `toll` return zero hits.

Nearest neighbours are **vehicle-scoped, not driver-scoped**: `fleet_fuel_transactions`
(`FuelTransaction.php:18`) and `CostType::Fuel`, keyed to `fleet_cost_entries.fleet_unit_id`.

`hr_advances` are **employee payroll advances** — `foreignUuid('employee_id')->constrained('hr_employees')`,
with installments tied to `payroll_period_id` and `payslip_id`. **Drivers are not HR employees**:
`logistics_drivers` has no `employee_id`, and `Modules/Logistics` + `Modules/Operations` contain zero
occurrences of `hr_employees` or `Employee::`. The only identity bridge is `user_id`, scoped by its
own docblock to *"resolve the logged-in driver"*.

An approval workflow exists — `AdvanceService::approve()` — but only inside HR, employee-keyed.

---

## 17. Wallet / Cash Custody

**MISSING.** There is no driver-keyed money store anywhere.

- `distribution_trip_settlements` holds `cash_collected`, `bank_transfers_pending`, `already_paid`,
  `total_collected`, `cash_expected`, `driver_cash_submitted`, `discrepancy` — **per trip, with no
  `driver_id` column and no running balance**. The parent `distribution_trips` has no driver either;
  its migration states verbatim *"There is deliberately no driver_id, no vehicle_id and no pairing
  logic"*. The driver is reachable only through `driver_vehicle_assignment_id`.
- `vehicle_shift_reconciliations` reconciles **product quantities, not cash**, and lives in a
  different key space (`vehicle_assignments`/`driver_assignments`, uuid) from `logistics_drivers` (bigint).
- `custody` in Logistics means **physical equipment**, never money.
- The word "wallet" elsewhere is CRM loyalty points or the `mobile_wallet` payment-method string.

**A driver's cash position cannot be answered today.** There is no table to answer it from, and a
second disconnected cash store (`delivery_cod_records`) exists in parallel.

**All four driver money endpoints return 403** (`api.php:3139-3143` → `frozen()`), so the settlement,
collections and bank-transfer UIs are inert. `bank-transfer-form.tsx` is dead code with no importer,
and its "receipt" field is a **URL string**, not an upload.

---

## 18. Reports

**MISSING.** No driver report exists, and no framework exists to host one.

What exists is roster counting (`GET /logistics/drivers/stats` — 7 counts, no date range, no
per-driver grouping) and an instantaneous availability snapshot (`OperationalDashboardService::driverUtilisation()`,
documented as live-only: *"Nothing is recomputed; nothing is stored"*).

**No driver-scoped delivery rate.** No `driver_id` appears in any numerator or denominator anywhere.
Three non-driver rates exist (customer, supplier on-time, company-wide count).

**Commission: defined but never fed.** `WorkforceKpiCatalog.php:94-95` maps
`shipping.shipment.delivered` and `shipping.shipment.failed` to KPI metrics — **neither event is
dispatched anywhere in the codebase**. `hr.kpi.auto_subscribe` defaults **false**. And with no
`employee_id` on a driver there is nobody to credit. Dead four independent ways.

**No shared reporting engine.** No `Modules/*/Reports`, no `ReportingService`. Candidates:
`OperationalDashboardService` (most natural host — already owns `driverUtilisation()`),
`ReportSnapshot` (Finance-namespaced, the only persisted snapshot primitive),
`PreparationAnalyticsController` (sibling precedent for a date-ranged operations endpoint).
The frontend `/reports` route is a `ComingSoonPage`.

---

## 19. Driver Closing

**PARTIAL — per-trip cash reconciliation only, live for dispatchers, frozen for drivers, posting
nothing to Finance.**

`SettlementService::finalize()` sets the settlement `Finalized`, closes the trip, and dispatches
`TripSettled` — **which has zero listeners**. Same for `CodCollected`.

**Driver Cash → Company Cash as an accounting entry: NOT FOUND.** `Modules/Finance` contains **zero**
occurrences of `driver`, `trip`, `distribution_payment`, or `trip_settlement`. `finance_cash_accounts`
has no type column — no driver-float or COD-holding account exists. Of 27 seeded posting rules, none
mentions driver, trip, distribution or COD; the three `shipping.*` rules are orphans with no producer.

**A transfer proof is never treated as bank settlement** ✅ — it is an `image_path` cleared by a human
flipping `recorded → verified`. `finance_bank_statements` reconciliation *"never posts a journal"* and
its generic `matched_source_type` columns are never written with a distribution reference. **No
connection exists** — correct behaviour, but the flow the business wants is therefore absent.

**No "driver closing" screen in Operations.** What exists is a **per-trip** settlement admin surface in
*Logistics* (`trip-settlement-tab.tsx` → `api.php:1862-1873`). There is no driver-scoped view, and no
"Driver Inventory Quantity" column anywhere.

**Vehicle Custody → Warehouse on return:** the only live return figure is
`vehicle_shift_reconciliation_lines.quantity_returned_actual`; it does **not** post to
`stock_ledger_entries`. `TASK-LOGISTICS-SHIPPING-FULL-STACK-AUDIT-001` already records that vehicle
end-of-trip reconciliation is owned by **nobody**.

---

## 20. Monthly Statement

**MISSING.** Zero hits for `driver_statement`, `monthly_statement`, `account_statement`,
`StatementService` across backend and frontend. `Modules/Logistics/Drivers/` holds 18 files and no
financial artefact of any kind.

**A reusable pattern exists** — `SupplierLedgerService::statement()`
(`Modules/Finance/Payables/Domain/Services/SupplierLedgerService.php:108`) with `openingBalance()`
(`:121`) and a `running_balance` accumulator (`:61`); mirrored for AR in `CustomerLedgerService.php:85`.

Two caveats: they are **hand-duplicated mirrors** with no base class or interface — a third
counterparty means a third copy; and both `/statement` endpoints are backend-only (the UI calls
`/ledger` without a date window, so the rendered "Opening" is always 0.00).

---

## 21. Account Closing

**MISSING as a driver artefact.** The audit fields the requirement names — who reviewed, who approved,
amounts, supporting documents, timestamps, remaining balance, closing status — exist **per trip** on
`distribution_trip_settlements` (`finalized_at`, `finalized_by`, `driver_cash_submitted`,
`discrepancy`) and per payment (`verified_by`, `verified_at`). There is no driver-level account, so
there is nothing to close.

**Maker-checker where financially applicable is currently absent on this surface** — see §15.

---

## 22. Existing Contracts / ADRs

| Document | Decision |
|---|---|
| `docs/contracts/BOUNDARY-CONTEXT-MAP.md:122-123` | CTX-04 Logistics owns `Vehicle, VehicleInventory, Shipment, LoadingSession, DeliveryAttempt`; `Driver` is a canonical Logistics concept |
| `docs/architecture/ADR-015-enterprise-fulfillment-architecture.md:175` | Splits it — *"Owner: Loading & Allocation OS (vehicle lifecycle) / Logistics OS (route execution)"* |
| `docs/architecture/VEHICLE-ARCHITECTURE-SPEC.md:323-325` | Puts Vehicle under `Modules/Operations/Vehicles` — **never built** |
| `docs/logistics-v2/README.md:41,48-50` | *"Distribution is the Single Cash Authority. Nothing else computes settlement."* · *"Drivers owns the driver↔vehicle pairing."* · *"Delivery consumes `DeliveryStop` read-only."* |
| `docs/logistics-v2/05-DRIVER-MOBILE-PLATFORM.md` | Driver mobile is an operational **client only** — submits intents, never mutates domain state |
| `docs/logistics/DELIVERY-OS.md:22` | **Binding CTO ownership table** splitting Stack A / Stack B deliberately |
| `docs/inventory/ADR-INV-002-warehouse-type-taxonomy.md:36` | *"Only Loading OS actions may transfer stock into a vehicle warehouse"* — **100% unimplemented** |
| `docs/architecture/PARTIAL-FULFILLMENT-RULES.md` | `driver_authority.*` per fulfillment profile |
| `TASK-OPERATIONS-GROUP-TRIP-VEHICLE-DRIVER-ARCHITECTURE-DECISION-001` | Option A: Group→Trip. *"Vehicle and Driver are never assigned separately."* Canonical owner = `logistics_driver_vehicle_assignments`. *"Operations/Loading's `vehicle_assignments` is explicitly NOT the canonical vehicle relation."* |
| VP-1 owner decisions | D1-C canonical `logistics_vehicles` · D2-A driver tenanted by company · D3-D single pairing ledger, **Loading consumes rather than owns** · D4-C capacity = order count |
| `TASK-SHIPPING-DRIVER-CLOSURE-001` | *"Driver runtime → thin delegating wrapper… no parallel backend"* · gated on `loading.driver.operate` so *"a driver never inherits dispatcher authority"* · *"Nothing financial was implemented… the four driver money endpoints return 403"* · **flags the `logistics.distribution.update` cash risk and recommends revoking it — not done** |
| `TASK-LOGISTICS-MANUAL-REMEDIATION-001` | **PARTIAL / NOT CERTIFIED** — *"the operator-facing end-to-end flow is BLOCKED: the only inventory-correct backend has no UI, and the only rich operator UI calls backend routes that do not exist"* |
| `docs/loading-os/` (TASK-LOAD-001, 9 approved docs) | Specifies `driver_assignments` (uuid, Operations-owned) — **contradicts** the built `logistics_driver_vehicle_assignments` (bigint) that `Trip` actually consumes. **TASK-LOAD-002 does not exist.** |

### 🛑 Ownership is a formally OPEN question

`TASK-OPERATIONS-VEHICLE-VP1-CANONICAL-KEY…FINAL-REPORT.md:320-336` — *"Ownership is contradictory
across three approved documents… **STOP condition #2 is triggered**… Only the owner can rule, and the
ruling must be written down."* Verdict `:626` — **"VP-1 BLOCKED — OWNER DECISION REQUIRED"**. The
technical decisions were later implemented; **the ownership ruling was never written.**

---

## 23. Reusable Infrastructure

| Need | Reuse this | Path |
|---|---|---|
| Vehicle-held stock | `VehicleInventoryService` + `vehicle_inventory_items` | `Modules/Operations/Loading` — the single canonical store |
| Required vs loaded | `loading_tasks` (`quantity_planned` / `_loaded` / `_short`) | `Modules/Operations/Loading` |
| Partial delivery quantities | **`RecordProductDeliveryAction` + `allocation_records`** | `Modules/Operations/Loading` — do not build a fourth engine |
| Driver↔vehicle pairing | `logistics_driver_vehicle_assignments` | canonical per VP-1 D3-D |
| Driver identity guard | `DriverRuntimeController::driver()/ownedTrip()/ownedStop()` | the correct pattern — replicate it, don't reinvent |
| Order card | `OrderMobileCard` | `features/orders/components/order-mobile-card.tsx:27` — i18n'd, mobile-first |
| Cascading geography | `useGovernorates` / `useCities` | `features/logistics/geography/hooks/use-geography.ts` |
| File upload | `DriverController::storeDocument` (in-domain) or `app/Core/Documents/Document.php` (generic polymorphic) | both do real validated uploads |
| Payment proof | `UploadPaymentProofAction` + `payment_proofs` | the certified lifecycle — **the only one** |
| Financial statement | `SupplierLedgerService::statement()` | `Modules/Finance/Payables` — the pattern, though hand-duplicated |
| Reporting host | `OperationalDashboardService` | already owns `driverUtilisation()` |
| Costing | `InventoryLayerConsumptionService::consume()` (FIFO) / `EnterpriseCostEngine` (declared SSOT) | do not invent a formula |
| Finance posting | `PostingCoordinator` — *"REQUESTS JOURNALS, NEVER WRITES THE LEDGER"* | the only door; every caller is inside `Modules\Finance` |

---

## 24. Gaps

**Contract breaks (existing code that cannot work):**
1. `tripSummary()` omits 13 of 17 declared fields → home KPIs `NaN`, trip KPI grid never renders, Finish Trip permanently disabled.
2. `stopSummary()` sends no `order` → every stop card blank, list search inert.
3. `stopDetail()` field-name mismatch → phone, address, governorate/city/area, product names, quantities, prices, payment method all silently blank.
4. Four frontend endpoints have **no backend route** (404): `custody-returns`, `timeline`, `returns/{id}/confirm`, plus the entire `/api/distribution/*` handover surface.
5. Four financial endpoints deliberately **403 frozen**.
6. `ProofOfDeliveryForm`, `ExceptionForm`, `BankTransferForm` are **orphaned**; the "Proof of Delivery" button navigates to a route that does not exist.
7. `order_lines.delivered_qty` has **zero writers**; `remaining_qty` always returns the full quantity.
8. `VehicleInventoryService::recordReturn()` / `unallocate()` have **zero callers**.
9. PostgreSQL-only SQL in six waste/liability report endpoints against MySQL 8.4 → runtime fatal.

**Security / control:**
10. 🔴 Maker-checker collapsed on `distribution_payment_collections` — driver can record and verify.
11. 🔴 `SettlementController` / `DeliveryController` unscoped; `Trip` has no tenant global scope → cross-company reach by uuid.
12. 🔴 Settlement read endpoints carry **no permission at all**.
13. 🔴 Waste resolution mints an **already-approved** liability, bypassing `inventory.liabilities.approve`, with a client-supplied `approved_by` string.
14. `vehicle_inventory_items` has no tenant scope and no `company_id` index.
15. `warehouse_liabilities` has **no party FK**; `warehouse_manager` is free text no code writes.

**Architecture:**
16. Vehicle inventory is a **second inventory engine** — never reaches `stock_ledger_entries`.
17. Two delivery stacks, deliberately split, **unbridged** — `DeliveryStopCompleted` has no listeners; `delivery_deliveries` has no automatic creator.
18. Three unconnected product-return paths; two disconnected cash stores; three payment-proof lifecycles.
19. Delivery proof accepts **no file** in either stack.
20. Nothing in Logistics/Operations posts to Finance; `TripSettled`, `CodCollected`, `VehicleCostPosted`, `DistributionAssignmentChanged` all have zero listeners.

**Wholly missing:** driver availability states · driver-facing loading · vehicle warehouse screen ·
driver product receiving · driver waste reporting · area/zone filters · Started Delivery list ·
driver failure reporting · partial-delivery capture · expenses · wallet / cash custody · reports ·
driver commission · monthly statement · account closing · driver closing screen · every corresponding
permission.

---

## 25. Architecture Decisions Required

**AD-1 — Driver IA ownership.** Move the driver app to the `shipping` module and give it a
full-screen mobile shell (not `AppShell`)? And **rule the open ownership question** (VP-1 STOP #2 —
three approved documents contradict; the ruling was never written).

**AD-2 — Driver runtime permission.** `loading.driver.operate` is granted only to `company-admin` by
deliberate design. Grant it to `driver`, or introduce a driver-scoped alternative? Without this the
app cannot be used at all.

**AD-3 — Revoke `logistics.distribution.update` from `driver`** and split `logistics.settlement.*`.
Already recommended by TASK-SHIPPING-DRIVER-CLOSURE-001 and not done. This is the maker-checker fix.

**AD-4 — Vehicle inventory vs the canonical ledger.** Does loading a vehicle debit
`stock_ledger_entries`? Today it does not. ADR-INV-002 says a vehicle *is* a warehouse type; the
schema says it is a separate store and there is no location concept. **Owner ruling required.**

**AD-5 — Driver waste.** The existing contracts cannot be reused (count-session FK). Extend
`waste_investigations` to a polymorphic source, or create a driver/vehicle-scoped concept? Either way
`warehouse_liabilities` needs a party reference before anyone can be held liable.

**AD-6 — Delivery proof storage.** Neither stack accepts a file. Adopt the `payment_proofs` pattern,
the generic `documents` table, or `DriverController::storeDocument`?

**AD-7 — Driver cash custody.** No driver-keyed money store exists. Is the driver a Finance
counterparty (like a supplier, with a ledger and statement), or an operational custody holder settled
per trip? This decides §17, §20 and §21 together.

**AD-8 — Driver as an employee.** Commission, advances and statements all assume an HR employee.
Drivers have no `employee_id`. Bridge them, or build a driver-native financial identity?

**AD-9 — Stack A ↔ Stack B bridge.** Should `DeliveryStopCompleted` create a `delivery_attempt` and
drive `Order.status`? Today the driver's writes land in Stack B and stop there.

**AD-10 — Failure reason vocabulary.** Stack B has none. Adopt Stack A's `FailureReason` enum
(it lacks `other`), or define a Distribution enum?

---

## 26. Recommended Task Breakdown

Ordered by dependency; each is independently shippable.

| # | Task | Scope | Blocked by |
|---|---|---|---|
| **D-01** | **Make the driver app reachable and honest** — fix the three contract breaks (§24.1-3), remove or route the four 404 calls, un-orphan or delete the three dead forms, add i18n | frontend + presenter only, no new domain | AD-2 |
| **D-02** | **Driver RBAC & tenancy repair** — grant the runtime permission, revoke `logistics.distribution.update` from `driver`, split `logistics.settlement.*`, add tenant scoping to `SettlementController`/`DeliveryController`, permission the settlement reads, add the self-review check to `SettlementService` | security; highest value per line | AD-2, AD-3 |
| **D-03** | **Driver IA move** — relocate to `shipping`, mobile shell, nav entry | frontend | AD-1 |
| **D-04** | **Partial delivery** — route the driver to `RecordProductDeliveryAction`; write `order_lines.delivered_qty` | reuses the existing engine | AD-9 |
| **D-05** | **Delivery proof upload** — real file handling on the chosen pattern | | AD-6 |
| **D-06** | **Failure reporting** — reason vocabulary + a driver-facing Failed Delivery surface | | AD-10 |
| **D-07** | **Vehicle Warehouse screen + driver receiving** — read `vehicle_inventory_items`; wire `recordReturn()` | | AD-4 |
| **D-08** | **Availability model** | schema + assignment predicate | — |
| **D-09** | **Driver waste** | | AD-5 |
| **D-10** | **Driver cash custody + expenses** | | AD-7 |
| **D-11** | **Reports, commission, monthly statement, closing** | | AD-7, AD-8 |

**D-01 and D-02 together are the minimum viable repair** — after them a driver can log in, see real
data, and cannot verify their own cash.

---

## 27. Explicit Non-Goals

Not proposed, not designed, not implemented by this audit:

- No new order status, no new payment status, no new driver status vocabulary.
- No second inventory engine, no second quantity engine, no second payment-proof system, no second
  zone/geography vocabulary, no new financial ledger, no new costing formula.
- No modification to `PaymentFulfillmentGate`, `ReevaluateOrderFulfillmentAction`,
  `ConfirmOrderWorkflow`, the `payment_proofs` lifecycle, or `OrderStatusGuard`.
- No new warehouse or inventory-location entity.
- No Finance postings, no inventory movements, no test business data.
- The Stack A / Stack B split is **not** proposed for consolidation — it is a binding CTO decision
  (`DELIVERY-OS.md:22`); only the missing bridge is raised.
- Out of scope and reported only: the PostgreSQL-in-MySQL report endpoints; `TASK-LOAD-002`'s absence;
  the `driver_assignments` vs `logistics_driver_vehicle_assignments` documentation conflict.

---

## 28. STOP Conditions

| # | Condition | Status |
|---|---|---|
| 1 | Vehicle/driver ownership contradicted across three approved documents | 🛑 **OPEN** — VP-1 STOP #2 triggered, never ruled |
| 2 | Waste/liability cannot be reused for drivers (DB-enforced FK) | 🛑 **BLOCKING AD-5** |
| 3 | Maker-checker collapsed on a live, driver-reachable money path | 🛑 **SECURITY — act before any driver enablement** |
| 4 | Cross-tenant reach on settlement/delivery controllers | 🛑 **SECURITY** |
| 5 | Driver role cannot reach the driver runtime | 🛑 **BLOCKS ALL DRIVER WORK** |
| 6 | Liability created pre-approved, bypassing the approve permission | 🛑 **FINANCIAL CONTROL** |
| 7 | Browser verification impossible — 0 drivers, 0 vehicles; fabricating fleet data is forbidden | 🛑 **DEFERRED by agreement** |
| 8 | Finance integration absent for every driver money concept | 🛑 **BLOCKING AD-7** |

No STOP was worked around. Nothing was implemented.

**Data safety:** every query was `SELECT`/`SHOW`. No inventory movement, no financial entry, no
business data created or modified. Baseline unchanged: 19 orders · 2 trips · 4 trip-orders · 0 across
every other driver-domain table. **Files created: this report only.**

---

# STATUS

# DRIVER AUDIT COMPLETE — IMPLEMENTATION NOT STARTED

### A. Already exists
Driver identity guard (`DriverRuntimeController`) · driver↔vehicle pairing ledger · trip/stop domain ·
`vehicle_inventory_items` as the single vehicle-stock store · `loading_tasks` required-vs-loaded ·
`allocation_records` + `RecordProductDeliveryAction` as the partial-delivery engine ·
`vehicle_shift_reconciliation` variance · FIFO costing · waste + liability workflow (warehouse-scoped) ·
canonical `payment_proofs` lifecycle with identity maker-checker · `PostingCoordinator` ·
`SupplierLedgerService::statement()` pattern · cascading geography APIs · `OrderMobileCard` ·
real file-upload precedents.

### B. Partially exists
Driver app (11 pages, 3 contract breaks, 4 dead routes, 4 frozen endpoints, no nav, no i18n) ·
availability (`active|inactive|archived`, not the requested states, unenforced in the runtime) ·
custody (equipment + cash float only) · receiving (equipment only) · failed delivery (outcome yes,
reporting no) · order detail (mostly blank) · delivery proof (records exist, accept no file) ·
closing (per-trip, dispatcher-only, no Finance) · commission (engine exists, path dead four ways).

### C. Missing
Driver-facing loading · vehicle warehouse screen · driver product receiving · driver waste reporting ·
partial-delivery capture by driver · area/zone filters · Started Delivery list · expenses · wallet /
cash custody · reports · delivery rate · monthly statement · account closing · driver closing screen ·
Driver Inventory Quantity column · and every corresponding permission.

### D. Architecture / owner decisions required
**AD-1** IA ownership + the unwritten VP-1 ruling · **AD-2** driver runtime permission ·
**AD-3** revoke dispatcher cash authority from `driver` · **AD-4** vehicle inventory vs canonical
ledger · **AD-5** driver waste model · **AD-6** delivery-proof storage · **AD-7** driver cash custody
model · **AD-8** driver-as-employee bridge · **AD-9** Stack A↔B bridge · **AD-10** failure-reason
vocabulary.

### E. Recommended next implementation task
**D-02 — Driver RBAC & Tenancy Repair.** It is the only item that is a live security defect rather
than a missing feature: a `driver`-role holder can today record a payment and verify it themselves,
on any company's trip, through an uuid alone, and the settlement reads are entirely unpermissioned.
It is small, self-contained, requires only AD-2/AD-3, and every other driver task is unsafe to ship
before it. **D-01** (make the app honest) follows immediately and unblocks all UI work.

**STOP.**

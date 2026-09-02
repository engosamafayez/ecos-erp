# Enterprise Reporting & Analytics Platform — Specification

**Document:** ENTERPRISE-REPORTING-PLATFORM
**Service:** Analytics Platform (named, non-EPS, in `ENTERPRISE-PLATFORM-SERVICES.md` §7)
**Version:** 1.0
**Status:** PROPOSED — Architecture Only, Awaiting CTO Ratification
**Date:** 2026-09-03
**Task:** TASK-ECOS-SYSTEM-REPORTING-ARCHITECTURE-001
**ADR:** `docs/adr/ADR-044-system-reporting-analytics-architecture.md`
**Parent reference:** `docs/architecture/ENTERPRISE-PLATFORM-SERVICES.md` (§7 names "Analytics Platform" as the home for "Reporting and analytics," outside EPS-01..04 — this document is that platform's specification. `ENTERPRISE-PLATFORM-SERVICES.md` itself is frozen and is **not** modified by this task.)

---

## 1. Mission

> Give every part of ECOS one governed place to see what already happened, without ever becoming a second place that decides what happened.

Reporting reads. It aggregates. It presents. It never mutates a canonical business fact, never computes a second version of a figure a source module already owns, and never grants a permission or scope the source module wouldn't have granted directly.

## 2. Core Principles

1. **Source authority is absolute.** Every number traces to exactly one owning module (§7).
2. **Composition, not duplication.** Where a source module already has a read service, Reporting calls it — it does not re-derive the query (§8, Pattern A/B).
3. **A metric is dimension-agnostic.** "Net Sales" is one formula; "Net Sales by Brand" is that formula applied through the Dimension Catalogue (§14). This document does not define one metric per dimension cut — that would multiply definitions without adding meaning, and is the opposite of what a metric dictionary is for.
4. **Operational ≠ Accounting, by label, always.** Anywhere a figure could be mistaken for GL-posted truth, it is classified `OPERATIONAL` or `ACCOUNTING` explicitly (§17) — never left ambiguous.
5. **No visibility shortcut.** Report access is governed by IAM (§9); source-record access on drill-through is governed by the source module (§11). Neither substitutes for the other.
6. **Freshness is disclosed, not assumed.** Every report declares which freshness class (§8) it belongs to.

## 3. Bounded Context & Ownership Rule

`Modules\Reporting` is a new backend module. It owns exactly two categories of table: export/generation metadata (`export_jobs`, `report_generation_audit`) and, only where explicitly justified later (§8, Pattern D — not adopted in V1), a narrow event-fed rollup table. It owns **zero** business-fact tables. See ADR-044 Decision 1 for the full module skeleton.

```
Modules/Reporting/
├── Domain/{Models,Enums,Contracts}          — metadata only, no business entities
├── Application/{Queries,Services,Jobs}      — composition, registry, export — never a
│                                                primary single-domain aggregate
├── Infrastructure/{Providers,Database}      — export_jobs / report_generation_audit only
└── Presentation/Http/{Controllers,Resources} — one controller per category
```

Frontend: the already-reserved `reports` `ModuleId` (`frontend/src/config/module-navigation.ts:404-409`) becomes one workspace with category tabs (Executive · Sales · Customers · Products · Inventory · Procurement & Suppliers · Preparation · Distribution & Shipping · Drivers · Financial) — not ten top-level nav entries, per task §5.

## 4. Information Architecture

```
Reports  (/reports)
├── Executive               — cross-domain composite KPIs, top performers
├── Sales                   — orders, sales value, status/payment mix
├── Customers                — counts, behavior, per-customer 360
├── Products                 — performance, profitability (operational)
├── Inventory                 — stock, valuation, movements, shortages
├── Procurement & Suppliers   — purchasing, supplier scorecards, AP link
├── Preparation                — wave completion, shortages, bottlenecks
├── Distribution & Shipping    — window/group utilization, delivery performance, zones
├── Drivers                    — operational + financial (settlement) split
└── Financial                  — thin proxy into Finance's own live reports
```

Each category page shares one reusable shell (task §32): category nav → report header → KPI strip → date selector + filter bar → comparison-period toggle → chart+table (same dataset, §13) → drill-through → export → loading/empty/error states. None of these components are built in this task.

## 5. Source Authority Matrix

| Domain | Canonical source module | Key entity/service | Notes |
|---|---|---|---|
| Orders / Sales | `Modules\Commerce\Orders` | `Order`, `OrderLine`, `order_financial_snapshots` | Sales module owns no Order entity |
| Customer master | `Modules\Sales\Customers` (identity) + `Modules\Crm\Customers` (enrichment) | `Customer` (shared `customers` table, two models) | Verify Crm enrichment migration has executed in target environment before relying on its columns |
| Product | `Modules\Inventory\Products` | `Product` | Not MasterData, not Commerce |
| Brand | `Modules\Organization\Brands` | `Brand` | |
| Category | `Modules\MasterData\Categories` | `Category` | |
| Inventory balances | `Modules\Inventory\InventoryItems` | `InventoryItem`, `StockLedgerEntry` | `available` always derived, never stored |
| Valuation / costing | `Modules\CostManagement` | `EnterpriseCostEngine` | Reads Inventory's `InventoryReceiptLayer`/`InventoryLayerConsumption` |
| Procurement | `Modules\Purchasing` | `Supplier`, `PurchaseOrder`, `GoodsReceipt`, `SupplierInvoice` | |
| Preparation identity/status | `Modules\Operations\Preparation` | `PreparationWave` | FSM/status only |
| Preparation prepared-qty/shortage | `Modules\Operations\DemandAnalysis` | `WaveProductDemand`, `WaveMaterialDemand`, `WaveMissingMaterial` | **A different module** than Wave identity — do not source these from `Modules\Operations\Preparation` |
| Distribution | `Modules\Logistics\Distribution` | `DistributionWindow`, `VirtualCapacitySlot` ("Group"), `Trip` | Group is never a Vehicle — no `vehicle_id`/`driver_id` column |
| Loading execution | `Modules\Operations\Loading` | `VehicleAssignment`, `LoadingTask` | Cross-module ref only (`trip_id`, no DB FK) |
| Delivery outcome | `Modules\Logistics\Distribution` | `DeliveryStop`, `DeliveryAction`, `TripReturn` | `Modules\Logistics\Delivery` is a parallel, uncalled stack — not a valid source |
| Delivered quantity (line-level) | `Modules\Operations\Loading` | `AllocationRecord.quantity_delivered` | Projected onto `order_lines.delivered_qty` by an event listener |
| Driver operational + financial | `Modules\Logistics\Distribution` | `DriverTripMovement`, `TripSettlement`, `DriverReportsReadService` | Finance has zero code awareness of drivers — deliberate ("Distribution is the Single Cash Authority") |
| Finance (GL/AR/AP/Revenue/COGS/P&L/BS/Closing) | `Modules\Finance` | EPICs F1–F5 | |
| Authorization / data scope | `Modules\IAM` | `permission:` middleware, `ScopeResolver` | |

## 6. Data Architecture & Read Strategy

Evaluated against actual evidence: MySQL 8.4, single physical database, no existing DB views, no `ReadModel`/`Projection` folders, a live and proven cross-module event bus, a production queue (27 `ShouldQueue` jobs, Supervisor-managed), and — decisively — **every existing reporting surface in this codebase today is already built as a `Domain\Services` query-builder aggregate that documents itself as "a read model, never stored, derived live."** Reporting adopts the same pattern rather than introducing a new one.

| Pattern | When to use | V1 adoption |
|---|---|---|
| **A — Call existing source query/read-service** | A canonical service already exists (Finance's `TrialBalanceService`/`ArAgingService`/`CustomerLedgerService`, Purchasing's `GetSupplierAnalyticsQuery`/`GetProcurementHealthQuery`, Inventory's `InventoryDashboardService`/`VarianceAnalyticsService`, CRM's C5/C6 services, Logistics's `DriverReportsReadService`/`EnterpriseDashboardService`/`OperationalDashboardService`, Operations\DemandAnalysis's `WaveKpiCalculator`) | **Primary pattern — most of V1** |
| **B — New source-owned query service** | No existing service (e.g., Sales-by-Dimension breakdown) | Added inside the **owning** module's `Application\Queries`, never inside Reporting |
| **C — Reporting-owned composition** | Genuinely cross-domain views (Executive) | Reporting calls 1–N Pattern A/B services, merges, scopes, formats — this is the *only* thing Reporting's own query code does |
| **D — Event-fed rollup** | Large historical aggregates where live computation becomes a measured bottleneck | **Reserved, not adopted in V1.** No current data-volume evidence justifies it (effectively single-warehouse operation today); POS's own `pos_analytics_events`/`pos_customer_stats` is a live cautionary example of a fully-built write path nobody reads |
| Database views / materialized views | — | **Not adopted.** Zero precedent anywhere in the codebase |

**Hard rule (task §26):** no report loads a full dataset into the frontend for client-side aggregation. Every KPI strip, chart, and table is server-aggregated, paginated, and date-bounded before it reaches React — replacing, not extending, the existing `/executive` page's client-side eight-endpoint merge.

## 7. Freshness Model

| Class | Applies to | Mechanism |
|---|---|---|
| **LIVE / NEAR-LIVE** | Orders, wave/preparation state, distribution/delivery state, stock availability | Computed on request; short TTL cache only where an existing module precedent already caches (Preparation's controllers: 30s/300s) |
| **EVENTUAL / AGGREGATED** | Historical trends, large date-range rollups, Executive composites | Explicit TTL cache; always shows a "data as of" timestamp |
| **ACCOUNTING-POSTED** | All Financial-category reports | Inherited from Finance's own `FiscalPeriod`/`PeriodStatus` gate (Open/Closed/Locked) — Reporting adds no separate posting-state logic |

## 8. IAM / Authorization Model

Category-level permissions (task §20 Option B — see ADR-044 Decision 5 for the full rejection rationale of A/C/D): `reports.executive.view`, `reports.sales.view`, `reports.customers.view`, `reports.products.view`, `reports.inventory.view`, `reports.procurement.view`, `reports.preparation.view`, `reports.distribution.view`, `reports.drivers.view`, `reports.finance.view`. Seeded via the migration-driven convention already dominant for Finance/HR/CRM/Logistics; gated with the existing `permission:` route middleware. **No new AuthorizationGateway, no new middleware, no new engine.** `reports.finance.view` is additive to, never a substitute for, the underlying `finance.*.view` permissions.

## 9. Data-Scope Security

Report access ≠ company-wide data access. Reporting's own tables use the `TenantOwnershipResolver` fail-closed global-scope pattern (the strictest of the three tenant-scoping mechanisms found in the codebase). Every report query is scoped transitively because it calls a source service that already carries the source model's own tenant scope. Finer scoping (a Sales Rep seeing only their own customers; a Driver seeing only their own trips) reuses the existing `ScopeResolver`/`scopedTo()` engine — built, but with **zero real adoption anywhere in the codebase today**. Reporting is the first module planned to actually wire it end-to-end; Distribution's hand-coded `ownedTrip()`/`ownedStop()` pattern is the documented fallback if that retrofit proves impractical within Task 2's timebox. Frontend filtering is never treated as security (task §21).

## 10. Drill-Through Security

| Report figure | Drills into | Source authority re-checked on arrival |
|---|---|---|
| Sales total | Commerce\Orders workspace, filtered | Yes — existing Orders permission/scope |
| Customer KPI | Customer 360 / Orders, filtered by customer | Yes — existing Customer permission/scope |
| Product quantity | Order Lines, filtered by product | Yes — existing Orders permission/scope |
| Stock figure | Inventory movements/reservations | Yes — existing Inventory permission/scope |
| AR figure | Customer invoices/receipts (Finance) | Yes — existing `finance.ar.view` |
| AP figure | Supplier bills/payments (Finance) | Yes — existing `finance.ap.view` |
| Trip/Group KPI | Trip/Group/Orders (Distribution) | Yes — existing Distribution permission/scope |

Every drill-through is a navigation into the source module's own existing page — never a Reporting-owned detail view. This makes "report visibility does not grant source-record visibility" (task §22) true by construction rather than by a second check Reporting could get wrong.

## 11. Money, Precision, Date & Timezone Conventions

**Money.** Finance's own convention — `decimal(20,4)`, uniform, zero floats found anywhere in Finance — is the most disciplined in the codebase and is the target scale for any figure Reporting computes itself (Pattern C composition). Reporting must **never** assume shared precision when combining figures across domains: confirmed real mismatches include Commerce/Orders live tables (`decimal(12,2)`/`(10,2)`/`(15,2)` across different columns) vs. its own snapshot tables (`decimal(12,4)`), and Inventory/Purchasing (`decimal(15,4)` vs. newer SupplierInvoice/Return tables at `decimal(18,4)`, plus a `(12,2)` outlier on `products.regular_price`/`sale_price`). Every cross-domain aggregation must cast/round explicitly at a stated scale, never rely on implicit SQL coercion. No monetary total is ever computed as a JavaScript float in the frontend (task §29).

**Dates & timezone.** `APP_TIMEZONE` differs today between local dev (`Africa/Cairo`) and staging/production (`UTC`) — a real, confirmed inconsistency, not a hypothetical. Reporting fixes business-day boundaries (Day/Week/Month/Quarter/Year/Custom Range) to **`Africa/Cairo`** explicitly, server-side, regardless of `APP_TIMEZONE`, to avoid the exact class of off-by-one-day bug already possible in `ActivateScheduledOrdersCommand`'s D-1 cutoff logic. Column-type note: most audit timestamps are `timestamp`; `orders.order_date`/`requested_delivery_date` are date-only (`date` type, no time component) — comparisons across the two types must not silently truncate or extend a boundary.

## 12. Sales Date Semantics Map

No single "Date" field is used for every Sales report (task §7 hard rule). Each report states which of these it uses:

| Field | Meaning | Column |
|---|---|---|
| Order date | Transaction/creation date | `orders.order_date` |
| Confirmation date (operator) | Order locked, entered commercial commitment | `orders.confirmed_at` |
| Confirmation date (customer) | Customer confirmed details with an agent — unrelated to lifecycle gating | `orders.customer_confirmed_at` |
| Requested delivery date | Customer-requested date; drives the D-1 scheduled-order activation cron | `orders.requested_delivery_date` |
| Scheduled activation | When a `scheduled` order becomes active | Derived — no dedicated column; computed from `requested_delivery_date` at cron time |
| Warehouse assignment | When a warehouse was resolved | `orders.warehouse_assigned_at` |
| Delivered (stop-level) | Stop marked delivered/partial/failed | `distribution_delivery_stops.completed_at` |
| Delivered (line-level, quantity) | No dedicated timestamp — derived from `allocation_records`, whose `updated_at` is generic | `allocation_records` (via `AllocationRecord.quantity_delivered`) |
| Payment date | ecommerce payment date | `orders.date_paid` |
| Finance posting date | GL journal date | `finance_journal_entries` (Finance-owned; only populated for POS sales today — see §17 MET-FIN-01) |

## 13. Status Semantics Map

| Concept | Not the same as | Why |
|---|---|---|
| `OrderStatus` (11 canonical values, ADR-042) | A single "confirmation" flag | `confirmed` is itself a status; a *separate* `confirmed_at` timestamp also exists; a *third*, unrelated `customer_confirmed_at` exists |
| `Scheduled` status | The requested-delivery date itself | Status persists until a daily cron activates it (D-1 lead time) |
| Payment state | `orders.payment_status` column | That column is **dead** — never written; derive payment state from `deposit_amount` vs `total` (`PaymentState::fromAmounts()`) |
| Preparation wave `status` (FSM) | Wave completion percentage | Percentage is a live SQL aggregate (`SUM(prepared)/SUM(required)`); `Completed` is a one-shot operator action, set in exactly one code path, and is **not** auto-triggered by reaching 100% |
| Distribution Group capacity | Vehicle/Trip capacity | Two separate, independently-enforced numbers (`VirtualCapacitySlot.capacity_orders` vs. `Trip.capacity`) — never conflate "group utilization" with "vehicle utilization" |
| `DeliveryStop.status` | Order status | Distribution "never reads or writes `orders.status`" by its own architecture |
| Driver movement `status` (Pending/Approved/Rejected/Settled) | Vehicle-shift reconciliation `Approved` | The reconciliation `Approved` enum case exists but **no code path ever sets it** — do not report a figure as if that gate is live |
| `orders.delivery_zone_id` | The order's actual distribution zone | Two unrelated catalogs share the word "zone" — see §14 Zone dimension |

## 14. Filter / Dimension Catalogue

| Dimension | Canonical owner | Caveat |
|---|---|---|
| Date Range | — (Reporting applies; see §11 for timezone fix) | |
| Company | `Modules\Organization\Companies` | |
| Warehouse | `Modules\MasterData\Warehouses` | Schema is multi-warehouse-capable; only one row (`WH-MAIN`) is seeded today (task §19) |
| Brand | `Modules\Organization\Brands` | |
| Category | `Modules\MasterData\Categories` | |
| Product | `Modules\Inventory\Products` | |
| Customer | `Modules\Sales\Customers` / `Modules\Crm\Customers` | Same physical table, two models — see §5 |
| Supplier | `Modules\Purchasing\Suppliers` | No supplier-category field exists today |
| Channel | `Modules\Commerce\Channels` | Marketplace platforms only (WooCommerce/Shopify/Amazon/Noon/Salla/Zid); POS and manual/phone orders typically have `channel_id = NULL` — see MET-SALES-09 |
| Sales Owner | — | **Does not exist as data today.** `ADR-024` itself lists "Sales Rep" inline-editing as "planned." Classified LATER (§19) until a canonical field exists |
| Order Status | `Modules\Commerce\Orders` (`OrderStatus`, ADR-042) | |
| Payment Method | `Modules\Commerce\Orders` | Free-text/whitelist, not a backed enum — see MET-SALES-09 |
| Preparation Wave | `Modules\Operations\Preparation` | |
| Distribution Window | `Modules\Logistics\Distribution` | |
| Distribution Group | `Modules\Logistics\Distribution` (`VirtualCapacitySlot`) | Never the same as Vehicle |
| Trip | `Modules\Logistics\Distribution` | |
| Driver | `Modules\Logistics\Drivers` | |
| Vehicle | `Modules\Logistics\Vehicles` | Canonical identity via `Trip.driver_vehicle_assignment_id → logistics_driver_vehicle_assignments`; **do not** join through Operations\Loading's own `vehicle_assignments.vehicle_id` (unconstrained/untyped — open blocker VP-1) |
| Zone | `Modules\Logistics\Distribution` (`distribution_window_orders.distribution_zone_id`) | **Not** `orders.delivery_zone_id` — a different catalog entirely (Admin\Configuration shipping-cost zones) |

## 15. Export Architecture

V1: synchronous CSV, generated server-side (moving the one working precedent — `/executive`'s in-browser `exportCsv()` — behind authorization and data-scope, which the current version lacks). XLSX/PDF deferred (no library installed today; `maatwebsite/excel`/`league/csv` are candidates, not yet chosen). Large exports queue on the existing `ShouldQueue` infrastructure and notify via the existing in-app notification feed. Every export stamps: filters, `generated_at`, `generated_by`, company context, report title, Metric Dictionary version (task §27).

## 16. Performance Architecture

Backend pagination and date-bounding on every list endpoint; indexes reviewed per report in Task 2 (not assumed here); Top-N queries use `LIMIT`, not client-side truncation; query timeout behavior matches existing module conventions (no report-specific timeout policy invented). Cached aggregates only where §7's freshness classes call for them. No full dataset ever reaches the frontend for aggregation (task §26).

## 17. Metric Dictionary

**Design principle:** a metric is a single formula over a single source; a *report* applies dimensions and filters to one or more metrics (§14). This dictionary defines the formula once per metric — not once per dimension cut.

### Sales

#### MET-SALES-01 · Gross Sales
- **Definition:** Sum of order-line value before discounts, tax, and shipping, for orders in a commercially-active (non-cancelled) state.
- **Source:** `Modules\Commerce\Orders` — live `order_lines` (current) or `order_line_snapshots` (historical/as-of; ADR-020's Reporting Contract requires the snapshot tables for historical financial reads)
- **Formula:** `SUM(quantity × unit_price)`, excluding `Cancelled` orders
- **Date basis:** `orders.order_date`
- **Dimensions:** Brand, Product, Category, Customer, Channel, Warehouse
- **Filters:** Date range, Company, Warehouse, Brand, Category, Channel, Order Status
- **Freshness:** LIVE (current) / effectively immutable once Confirmed (historical)
- **Classification:** OPERATIONAL
- **Drill-through:** Orders workspace, filtered
- **Known dependency:** Not equal to MET-FIN-01 (Recognized Revenue) — see §5, Decision 2b of ADR-044

#### MET-SALES-02 · Net Sales
- **Definition:** Gross Sales less order-level discounts and coupon/fee adjustments.
- **Source:** `Modules\Commerce\Orders` — `orders.discount_amount`, `order_coupons`, `order_fees`
- **Formula:** `Gross Sales − discount_amount − Σ(order_coupons.discount) + Σ(order_fees.total)`
- **Date basis:** `orders.order_date`
- **Dimensions/Filters:** Same as MET-SALES-01
- **Freshness:** LIVE
- **Classification:** OPERATIONAL
- **Drill-through:** Orders workspace, filtered
- **Known dependency:** No line-level discount exists — only order-level; a "discount by product" breakdown is not derivable today (UPSTREAM DATA CONTRACT REQUIRED if ever requested)

#### MET-SALES-03 · Delivered Sales
- **Definition:** Net Sales restricted to orders whose status is `Delivered`.
- **Source:** `Modules\Commerce\Orders`
- **Formula:** Net Sales formula, `WHERE status = 'delivered'`
- **Date basis:** `orders.order_date` (order-level); no reliable order-level "delivered_at" column exists — see §12
- **Freshness:** LIVE
- **Classification:** OPERATIONAL
- **Drill-through:** Orders workspace, filtered to Delivered
- **Known dependency:** For POS-originated deliveries, cross-check against `pos_sales` directly — the POS→Order mirror can silently fail (best-effort listener, never throws)

#### MET-SALES-04 · Average Order Value (AOV)
- **Definition:** Net Sales divided by order count, over the same filter set.
- **Formula:** `Net Sales ÷ COUNT(orders)`
- **Date basis / Dimensions / Filters:** Same as MET-SALES-02
- **Freshness:** LIVE · **Classification:** OPERATIONAL
- **Drill-through:** Orders workspace, filtered

#### MET-SALES-05 · Units Sold
- **Definition:** Total product quantity across order lines.
- **Formula:** `SUM(order_lines.quantity)`, excluding cancelled orders
- **Freshness:** LIVE · **Classification:** OPERATIONAL
- **Drill-through:** Order Lines, filtered
- **Known dependency:** `order_lines.loaded_qty/returned_qty/cancelled_qty/prepared_qty/packed_qty/available_qty` are permanently-zero, never-written projection columns — never source a metric from them; only `quantity`, `reserved_qty`, and `delivered_qty` are real

#### MET-SALES-06 · Cancelled Orders Count
- **Definition:** Count of orders with status `Cancelled` in the period.
- **Date basis:** `orders.order_date` (no dedicated `cancelled_at` column exists — see §13)
- **Freshness:** LIVE · **Classification:** OPERATIONAL
- **Known dependency:** Cancellation reason is not persisted on `orders`; only recoverable from the `order_events` audit trail (UPSTREAM DATA CONTRACT REQUIRED if a reason breakdown is needed)

#### MET-SALES-07 · Scheduled Orders Count
- **Definition:** Count of orders currently in `Scheduled` status.
- **Date basis:** `orders.requested_delivery_date`
- **Freshness:** LIVE · **Classification:** OPERATIONAL

#### MET-SALES-08 · Order Count by Status
- **Definition:** Count of orders grouped by canonical `OrderStatus` (ADR-042's 11 values).
- **Freshness:** LIVE · **Classification:** OPERATIONAL
- **Known dependency:** Never map a legacy pre-V3 status word (`pending`, `processing`, `preparing`, `completed`, `review`, `rescheduled`) into a live filter — none are accepted at runtime (ADR-042 §8)

#### MET-SALES-09 · Payment Method Mix (incl. COD/Instapay)
- **Definition:** Distribution of orders by resolved payment method.
- **Source:** `orders.payment_method` (ecommerce, free text) and `orders.payment_method_manual` (manual orders; whitelist `cod|instapay|mobile_wallet|credit_card|bank_transfer`)
- **Formula:** `COUNT(orders) GROUP BY COALESCE(payment_method_manual, payment_method)`
- **Freshness:** LIVE · **Classification:** OPERATIONAL
- **Known dependency:** Neither field is a backed PHP enum; values are request-validated, not DB-constrained — normalize casing/nulls defensively in the query, not assumed clean

#### MET-SALES-10 · Requested-Delivery On-Time Rate
- **Definition:** Share of delivered orders whose stop-level completion fell on or before `orders.requested_delivery_date`.
- **Source:** `Modules\Commerce\Orders` (`requested_delivery_date`) joined to `Modules\Logistics\Distribution` (`distribution_delivery_stops.completed_at`)
- **Freshness:** LIVE (recent) / EVENTUAL (trend) · **Classification:** OPERATIONAL
- **Drill-through:** Delivery stops, filtered
- **Known dependency:** Cross-module join — must go through the order↔stop link (`distribution_window_orders`/`distribution_trip_orders`), not a direct FK

### Customers

#### MET-CUST-01 · Total Customers
- **Definition:** Count of customer rows for the company.
- **Source:** `Modules\Sales\Customers` (`customers` table)
- **Freshness:** LIVE · **Classification:** OPERATIONAL

#### MET-CUST-02 · Active Customers
- **Definition:** Customers with at least one order in the trailing period (period length is a report parameter, not fixed here).
- **Formula:** `COUNT(DISTINCT customer_id) WHERE orders.order_date BETWEEN ...`
- **Freshness:** LIVE · **Classification:** OPERATIONAL

#### MET-CUST-03 · New Customers
- **Definition:** Customers whose first order in the system falls inside the period.
- **Source:** `CustomerBrand.first_order_at` (already tracked) or `MIN(orders.order_date)` per customer
- **Freshness:** LIVE · **Classification:** OPERATIONAL

#### MET-CUST-04 · Repeat Customer Rate
- **Definition:** Share of active customers (MET-CUST-02) with more than one order in the period.
- **Freshness:** EVENTUAL · **Classification:** OPERATIONAL

#### MET-CUST-05 · Orders per Customer
- **Definition:** Order count divided by distinct customer count, over the same filter set.
- **Freshness:** LIVE · **Classification:** OPERATIONAL

#### MET-CUST-06 · Customer Lifetime Value (Operational)
- **Definition:** Cumulative operational sales value attributed to a customer, all-time.
- **Source:** `CustomerBrand.lifetime_value` (already maintained by Sales\Customers) — reuse, do not recompute
- **Freshness:** EVENTUAL · **Classification:** OPERATIONAL
- **Known dependency:** This is an operational figure maintained per Brand pivot, not an accounting-adjusted value; label accordingly

### Products

#### MET-PROD-01 · Average Selling Price (ASP)
- **Definition:** Net Sales for a product divided by units sold of that product.
- **Freshness:** LIVE · **Classification:** OPERATIONAL

#### MET-PROD-02 · COGS — Operational (Commerce-Sourced)
- **Definition:** Cost of goods sold as computed by Commerce at the point of shipment or order-confirm snapshot.
- **Source:** `orders.actual_cogs_amount` (mutable, written by `ShipOrderInventoryAction` at ship time) **or** `order_line_snapshots` cost columns (immutable, captured at Confirm) — these are two independently-computed, non-reconciled figures; a report must pick and label one, never blend them
- **Freshness:** LIVE (ship-time) / immutable (snapshot) · **Classification:** OPERATIONAL
- **Known dependency:** Not the same number as MET-PROD-03; see ADR-044 Decision 2b

#### MET-PROD-03 · COGS — Accounting (GL-Posted)
- **Definition:** Cost of goods sold as posted to the General Ledger's cost-of-sales accounts (5100–5130).
- **Source:** `Modules\Finance` — `FinancialMetricsService`, GL account category `cost_of_sales`
- **Freshness:** ACCOUNTING-POSTED · **Classification:** ACCOUNTING
- **Known dependency:** **Confirmed currently near-zero for the delivery/COD channel** — `EventPostingCatalog` has no `orders.delivered`/`inventory.stock.shipped` posting rule today. This is Finance's tracked gap, not a Reporting defect (FINANCE DEPENDENCY)

#### MET-PROD-04 · Gross Profit — Operational
- **Formula:** MET-SALES-01 (or MET-SALES-03) − MET-PROD-02, matched at the same grain
- **Freshness:** LIVE · **Classification:** OPERATIONAL

#### MET-PROD-05 · Gross Profit — Accounting
- **Formula:** MET-FIN-01 − MET-PROD-03
- **Freshness:** ACCOUNTING-POSTED · **Classification:** ACCOUNTING
- **Known dependency:** Only meaningful once the GL revenue/COGS gap (MET-FIN-01, MET-PROD-03) closes for non-POS channels — FINANCE DEPENDENCY

#### MET-PROD-06 · Gross Margin — Operational
- **Formula:** MET-PROD-04 ÷ MET-SALES-01 (or MET-SALES-03), as a percentage
- **Freshness:** LIVE · **Classification:** OPERATIONAL

#### MET-PROD-07 · Gross Margin — Accounting
- **Formula:** MET-PROD-05 ÷ MET-FIN-01, as a percentage
- **Freshness:** ACCOUNTING-POSTED · **Classification:** ACCOUNTING
- **Known dependency:** Finance's own `ProfitabilityService` already returns `available:false` (honest null) for the product/channel cut, blocked on a ledger-dimension gap — Reporting must mirror that honesty, never fabricate a per-product accounting margin (FINANCE DEPENDENCY)

### Inventory

#### MET-INV-01 · Available Stock
- **Definition:** On-hand quantity minus reserved quantity, per product/warehouse.
- **Source:** `Modules\Inventory\InventoryItems` — `InventoryItem::availableQty()`
- **Formula:** `on_hand_qty − reserved_qty` (deliberately signed/unclamped — a negative value is meaningful, not an error, per the model's own documented design)
- **Freshness:** LIVE · **Classification:** OPERATIONAL
- **Drill-through:** Inventory item / stock ledger, filtered

#### MET-INV-02 · Reserved Stock
- **Definition:** Quantity reserved against active orders (and, since ADR-027 §17, active BOM/recipe raw-material demand).
- **Source:** `InventoryItem.reserved_qty`
- **Freshness:** LIVE · **Classification:** OPERATIONAL
- **Known dependency:** Reservation is order-driven end-to-end (ADR-027) — Preparation never reserves or releases; a reservation is only consumed at physical shipment

#### MET-INV-03 · Inventory Value
- **Definition:** Valuation of on-hand stock using the canonical costing strategy.
- **Source:** `Modules\CostManagement\EnterpriseCostEngine` (FIFO canonical; Average/Standard selectable), reading `InventoryReceiptLayer.remaining_qty × landed_unit_cost`
- **Freshness:** LIVE · **Classification:** OPERATIONAL (inventory asset value; the GL asset balance is a separate, Finance-owned figure)
- **Known dependency:** Do not recompute FIFO/valuation logic inside Reporting — call `EnterpriseCostEngine` (task §45 DO-NOT-REIMPLEMENT)

#### MET-INV-04 · Stock Shortage (Material)
- **Definition:** Missing raw-material quantity against active wave/order demand.
- **Source:** `Modules\Operations\DemandAnalysis` — `WaveMaterialDemand.missing_qty` / `WaveMissingMaterial`
- **Freshness:** LIVE · **Classification:** OPERATIONAL
- **Known dependency:** `missing_qty` is always the real physical shortage — never zeroed by an `allow_negative_stock` override (ADR-027 §18.6)

#### MET-INV-05 · Zero-Stock Product Count
- **Definition:** Count of active products with `on_hand_qty <= 0` (or no `InventoryItem` row) for a given warehouse.
- **Freshness:** LIVE · **Classification:** OPERATIONAL

### Procurement & Suppliers

#### MET-PROC-01 · Purchase Volume
- **Definition:** Total quantity/value received across Purchase Orders in the period.
- **Source:** `Modules\Purchasing\PurchaseOrders`, `GoodsReceipts`
- **Freshness:** LIVE · **Classification:** OPERATIONAL

#### MET-PROC-02 · Supplier Spend
- **Definition:** Total invoiced or received value attributed to a supplier in the period.
- **Source:** `Modules\Purchasing` (`GetSupplierAnalyticsQuery`) or Finance's `SupplierLedgerService` for a paid-basis figure
- **Freshness:** LIVE · **Classification:** OPERATIONAL
- **Known dependency:** Do not use `goods_receipts.paid_amount` for anything payment-related — legacy, diverges from the AP ledger (Purchasing's own repository comment names this exact divergence)

#### MET-PROC-03 · Supplier Procurement Health Score
- **Definition:** Weighted 0–100 composite of delivery performance, fill rate, price stability, activity recency, financial standing, inventory impact.
- **Source:** `Modules\Purchasing` — `GetProcurementHealthQuery` (existing, already disciplined about null-handling)
- **Freshness:** EVENTUAL · **Classification:** OPERATIONAL
- **Known dependency:** Reuse the existing query verbatim — do not re-derive the weighting (task §45 DO-NOT-REIMPLEMENT)

#### MET-PROC-04 · Supplier On-Time Delivery Rate
- **Definition:** Share of goods receipts landing on or before `purchase_orders.expected_date`.
- **Source:** `GetSupplierAnalyticsQuery.on_time_delivery_rate`
- **Freshness:** EVENTUAL · **Classification:** OPERATIONAL
- **Known dependency:** Distinct metric from MET-DIST-01 (Delivery Rate) — do not conflate supplier inbound performance with outbound customer delivery performance

### Preparation

#### MET-PREP-01 · Preparation Completion
- **Definition:** Quantity-weighted completion of a wave's demand.
- **Source:** `Modules\Operations\DemandAnalysis` — `WaveKpiCalculator`
- **Formula:** `SUM(wave_product_demand.prepared_qty) ÷ SUM(wave_product_demand.required_qty) × 100`, synced onto `preparation_waves.total_units_prepared/required`
- **Freshness:** LIVE · **Classification:** OPERATIONAL
- **Known dependency:** This is a live-computed percentage, **not** the same signal as `WaveStatus::Completed` — the status is a one-shot operator action independent of reaching 100% (task §45 DO-NOT-REIMPLEMENT: do not invent a second completion formula)

#### MET-PREP-02 · Wave Shortage Rate
- **Definition:** Share of required material quantity currently missing across an active wave.
- **Source:** `Modules\Operations\DemandAnalysis` — `PreparationAnalyticsController` (`shortage_rate_pct`, already computed)
- **Freshness:** EVENTUAL (5-minute controller cache precedent) · **Classification:** OPERATIONAL

#### MET-PREP-03 · Postponed Orders Count
- **Definition:** Count of orders currently postponed out of active wave membership.
- **Source:** `preparation_wave_orders.postponed_at IS NOT NULL`
- **Freshness:** LIVE · **Classification:** OPERATIONAL
- **Known dependency:** "Returned order" is not a Preparation-module concept at all — do not source a return metric from Preparation; it lives in Logistics (`TripReturn`/`DeliveryReturn`)

### Distribution & Shipping

#### MET-DIST-01 · Delivery Rate
- **Definition:** Share of assigned delivery stops completed as `Delivered` (vs. `Partial`/`Failed`/`Returned`).
- **Source:** `Modules\Logistics\Distribution` — `DeliveryStop.status`
- **Freshness:** LIVE · **Classification:** OPERATIONAL
- **Known dependency:** Source from Distribution's `DeliveryStop`, never from `Modules\Logistics\Delivery` (a parallel, uncalled stack with no driver-runtime caller — UPSTREAM DATA QUALITY DEPENDENCY if ever queried by mistake)

#### MET-DIST-02 · Group Capacity Utilization
- **Definition:** Live order count in a Distribution Group divided by its enforced order-count capacity.
- **Source:** `Modules\Logistics\Distribution` — `DistributionAggregationService::slotOrderCounts()` ÷ `VirtualCapacitySlot.capacity_orders`
- **Freshness:** LIVE · **Classification:** OPERATIONAL
- **Known dependency:** `capacity_stops`/`capacity_weight_kg`/`capacity_volume_m3` columns exist but are **not enforced** by any guard — do not report utilization against them as if they were live constraints. This is a distinct number from Vehicle/Trip capacity (`Trip.capacity`, default 60) — never conflate the two

#### MET-DIST-03 · Delivery Duration
- **Definition:** Elapsed time from stop attempt to stop completion.
- **Formula:** `distribution_delivery_stops.completed_at − distribution_delivery_stops.attempted_at`
- **Freshness:** EVENTUAL · **Classification:** OPERATIONAL

### Drivers

#### MET-DRV-01 · Orders Delivered (Driver)
- **Definition:** Count of stops a driver completed as `Delivered` in a trip/day/period.
- **Source:** `Modules\Logistics\Distribution` — already computed in `DriverDaySettlementReadService.kpis()` (`total_delivered`)
- **Freshness:** LIVE · **Classification:** OPERATIONAL

#### MET-DRV-02 · Cash Collected (Driver, Raw)
- **Definition:** Total payment collections recorded by a driver, regardless of verification state.
- **Source:** `distribution_payment_collections`
- **Freshness:** LIVE · **Classification:** OPERATIONAL
- **Known dependency:** "Raw" here means recorded, not reconciled — do not present this as settled cash without also showing MET-DRV-04

#### MET-DRV-03 · Driver Approved Expenses
- **Definition:** Sum of driver-submitted expense movements that have passed the Approved/Settled gate.
- **Source:** `driver_trip_movements` WHERE `category` is an expense type AND `DriverTripMovementStatus::countsTowardTotals()` is true
- **Freshness:** LIVE · **Classification:** OPERATIONAL (financial, but Distribution-owned — see §5)
- **Known dependency:** This gate is real and enforced (`ReviewDriverTripMovementAction` is the sole writer of the verdict), but implemented as an application-layer filter, not a SQL predicate — any new query against this table must reapply `countsTowardTotals()` in code, not assume a `status='approved'` WHERE clause is sufficient

#### MET-DRV-04 · Driver Net Cash
- **Definition:** Cash collected less approved expenses, per the already-built Day Settlement KPI.
- **Source:** `DriverDaySettlementReadService.kpis()` (`net_cash`) — reuse verbatim
- **Freshness:** LIVE · **Classification:** OPERATIONAL
- **Known dependency:** **Hard rule:** raw waste/damage is never folded into this figure, and no monetary "shortage liability" exists anywhere in the codebase today (`liability_attribution_deferred` is the code's own label for this gap) — never fabricate one in a report (task §14 hard rule)

### Financial

#### MET-FIN-01 · Recognized Revenue
- **Definition:** Revenue posted to the General Ledger.
- **Source:** `Modules\Finance` — GL journal entries, revenue accounts
- **Freshness:** ACCOUNTING-POSTED · **Classification:** ACCOUNTING
- **Known dependency:** **Confirmed today: only `pos.sale.finalized` posts revenue.** The delivery/COD channel posts nothing — Finance's own audit and UAT reports certify this as a real, open gap (EGP 21,132 of confirmed order value producing zero GL entries in one certified UAT run). Never equate this with MET-SALES-01/02/03 (FINANCE DEPENDENCY)

#### MET-FIN-02 · Outstanding AR
- **Definition:** Amount owed by a customer, net of allocated receipts.
- **Source:** `Modules\Finance\Receivables` — `CustomerInvoice::outstanding()` (per-document) or `CustomerLedgerService::balance()` (portfolio)
- **Freshness:** ACCOUNTING-POSTED · **Classification:** ACCOUNTING
- **Known dependency:** Three formula variants exist in Finance itself (`CustomerInvoice::outstanding()`, `CustomerLedgerService::balance()`, `ArAgingService` — the last explicitly excludes credit notes) and can diverge; Reporting must call one named service consistently per report and disclose which

#### MET-FIN-03 · Outstanding AP
- **Definition:** Amount owed to a supplier, net of allocated payments.
- **Source:** `Modules\Finance\Payables` — `SupplierBill::outstanding()` (per-document) or `SupplierLedgerService::outstandingPayable()` (portfolio, excludes advances)
- **Freshness:** ACCOUNTING-POSTED · **Classification:** ACCOUNTING
- **Known dependency:** Never source this from `goods_receipts.paid_amount`/`payment_status` (Purchasing's own legacy scalar, explicitly superseded) — `SupplierLedgerService` states its own SSOT claim verbatim ("never from the hand-entered goods-receipt fields")

---

## 18. Report Catalogue

Legend for **Read Strategy**: A = call existing source service (Pattern A) · B = new source-owned query needed (Pattern B) · C = Reporting-owned composition (Pattern C). **Gap Classification** uses the exact task §38 vocabulary.

### Executive

**RPT-EXEC-01 · Executive Overview** — Primary users: C-level, ops leadership. Metrics: MET-SALES-01/03/04, MET-PROD-04/06 (operational), MET-FIN-01/02/03 (linked), MET-INV-01/03, MET-DIST-01, MET-PREP-01, MET-CUST-02. Dimensions: Date range, Company. Date basis: mixed per source metric (disclosed per KPI card). Source authority: composed, per-metric (§5). Freshness: EVENTUAL. Read strategy: **C**. Drill-through: each KPI drills into its own source workspace. Export: CSV. Permission: `reports.executive.view`. Dependencies: all metrics above. **V1.**
Gap classification: READY — SMALL QUERY/API REQUIRED (composition layer only; every underlying figure already exists).

**RPT-EXEC-02 · Top Performers** — Top Products / Top Customers / Top Brands by Net Sales. Metrics: MET-SALES-01/02, MET-CUST-06. Read strategy: **A/B**. Export: CSV. Permission: `reports.executive.view`. **V1.**
Gap classification: READY — SMALL QUERY/API REQUIRED.

**RPT-EXEC-03 · Operational Health Snapshot** — Delivery rate + preparation completion + inventory availability on one screen. Metrics: MET-DIST-01, MET-PREP-01, MET-INV-01. Read strategy: **C**. Freshness: LIVE. Permission: `reports.executive.view`. **V1.**
Gap classification: READY — EXISTING QUERY (pure composition of three already-live services).

### Sales

**RPT-SALES-01 · Sales Overview** — Gross/Net/Delivered Sales, AOV, cancelled/scheduled counts, trend over comparison periods (§20). Metrics: MET-SALES-01/02/03/04/06/07. Dimensions: Date range, Company, Warehouse, Channel. Date basis: `order_date` (disclosed). Source authority: Commerce\Orders. Freshness: LIVE/EVENTUAL. Read strategy: **B** (a new `SalesOverviewQuery` in Commerce\Orders — no existing service composes these together today). Drill-through: Orders workspace. Export: CSV. Permission: `reports.sales.view`. **V1.**
Gap classification: READY — SMALL QUERY/API REQUIRED.

**RPT-SALES-02 · Sales by Dimension** — One parameterized report (Brand / Product / Category / Customer / Channel / Warehouse — user-selected breakout). Metrics: MET-SALES-01/02/05. Read strategy: **B**. Export: CSV. Permission: `reports.sales.view`. **V1.**
Gap classification: READY — SMALL QUERY/API REQUIRED.

**RPT-SALES-03 · Order Status & Payment Mix** — Metrics: MET-SALES-08, MET-SALES-09. Read strategy: **B**. Permission: `reports.sales.view`. **V1.**
Gap classification: READY — SMALL QUERY/API REQUIRED.

**RPT-SALES-04 · Requested-Delivery Performance** — Metric: MET-SALES-10. Read strategy: **B** (cross-module join, Commerce + Distribution). Permission: `reports.sales.view`. **V1.**
Gap classification: REPORTING READ MODEL REQUIRED (the only Sales-category report needing a genuinely new cross-module query, not just a same-module rollup).

### Customers

**RPT-CUST-01 · Customer Overview** — Metrics: MET-CUST-01–05. Read strategy: **B**. Permission: `reports.customers.view`. **V1.**
Gap classification: READY — SMALL QUERY/API REQUIRED.

**RPT-CUST-02 · Customer 360 List** — Per-customer: orders, AOV, total/delivered sales, last order, LTV (MET-CUST-06), preferred Brand/Category, blocked state. Read strategy: **B**. Drill-through: Customer detail / Orders. Permission: `reports.customers.view`. **V1.**
Gap classification: READY — SMALL QUERY/API REQUIRED. Known dependency: verify Crm\Customers enrichment columns (`status`/blocked-state) have executed in target environment (§5) before shipping this column — CANONICAL-DEVELOP RECONCILIATION REQUIRED.

**RPT-CUST-03 · Customer Outstanding AR** — Thin proxy into Finance. Metric: MET-FIN-02. Read strategy: **A** (`CustomerLedgerService`). Permission: `reports.customers.view` **and** `finance.ar.view`. **V1.**
Gap classification: READY — EXISTING QUERY. FINANCE DEPENDENCY (permission co-gating).

### Products

**RPT-PROD-01 · Product Performance** — Units sold, sales value, ASP, by Brand/Category. Metrics: MET-SALES-05, MET-PROD-01. Read strategy: **B**. Permission: `reports.products.view`. **V1.**
Gap classification: READY — SMALL QUERY/API REQUIRED.

**RPT-PROD-02 · Top Sellers / Slow Movers / Zero-Sale** — Rank/filter over MET-SALES-05 within a date window. Read strategy: **B**. Permission: `reports.products.view`. **V1.**
Gap classification: READY — SMALL QUERY/API REQUIRED.

**RPT-PROD-03 · Product Profitability (Operational)** — Metrics: MET-PROD-02/04/06 only (never the Accounting variants — see MET-PROD-07's known dependency). Read strategy: **B**. Permission: `reports.products.view`. **V1** (operational cut only) / accounting cut is **LATER**, blocked on Finance's own product-dimension gap.
Gap classification: READY — SMALL QUERY/API REQUIRED (operational) / FINANCE DEPENDENCY (accounting cut, deferred).

### Inventory

**RPT-INV-01 · Stock on Hand / Available / Reserved** — Metrics: MET-INV-01/02. Read strategy: **A** (Inventory already has dashboard services covering this shape). Permission: `reports.inventory.view`. **V1.**
Gap classification: READY — EXISTING QUERY.

**RPT-INV-02 · Inventory Valuation** — Metric: MET-INV-03. Read strategy: **A** (`EnterpriseCostEngine`). Permission: `reports.inventory.view`. **V1.**
Gap classification: READY — EXISTING QUERY.

**RPT-INV-03 · Stock Movements** — Movement log by type/date/warehouse. Source: `StockLedgerEntry` (canonical — never the legacy `stock_movements` table). Read strategy: **B**. Permission: `reports.inventory.view`. **V1.**
Gap classification: READY — SMALL QUERY/API REQUIRED. Known dependency: UPSTREAM DATA QUALITY DEPENDENCY if the legacy `stock_movements` read path is ever accidentally reused (`INVENTORY_CANONICAL_LEDGER_READS` flag defaults false today, gating an existing but different controller — Reporting must not touch that controller at all).

**RPT-INV-04 · Shortage & Zero-Stock** — Metrics: MET-INV-04/05. Read strategy: **A/B**. Permission: `reports.inventory.view`. **V1.**
Gap classification: READY — EXISTING QUERY (shortage, via DemandAnalysis) / READY — SMALL QUERY REQUIRED (zero-stock).

### Procurement & Suppliers

**RPT-PROC-01 · Purchasing Overview** — Metrics: MET-PROC-01/02. Read strategy: **A** (`GetSupplierSummaryStatsQuery`). Permission: `reports.procurement.view`. **V1.**
Gap classification: READY — EXISTING QUERY.

**RPT-PROC-02 · Supplier Scorecard** — Metrics: MET-PROC-03/04. Read strategy: **A** (`GetProcurementHealthQuery`, `GetSupplierAnalyticsQuery` — reuse verbatim, task §45). Permission: `reports.procurement.view`. **V1.**
Gap classification: READY — EXISTING QUERY.

**RPT-PROC-03 · Supplier Statement** — Thin proxy into Finance. Metric: MET-FIN-03. Read strategy: **A** (`SupplierLedgerService::statement()` — backend-complete, routed, never surfaced in any frontend today). Permission: `reports.procurement.view` **and** `finance.ap.view`. **V1** — this is the single highest-leverage "expose, don't build" item in the whole catalogue.
Gap classification: READY — EXISTING QUERY (backend). READY — SMALL QUERY/API REQUIRED (frontend tab never built).

### Preparation

**RPT-PREP-01 · Wave Overview** — Waves opened/completed, duration. Read strategy: **A** (`PreparationDashboardController`). Permission: `reports.preparation.view`. **V1.**
Gap classification: READY — EXISTING QUERY.

**RPT-PREP-02 · Preparation Completion & Shortages** — Metrics: MET-PREP-01/02. Read strategy: **A** (`WaveKpiCalculator`, `PreparationAnalyticsController`). Permission: `reports.preparation.view`. **V1.**
Gap classification: READY — EXISTING QUERY.

**RPT-PREP-03 · Postponed & Bottleneck Products** — Metric: MET-PREP-03 + top-shorted-product ranking (`PreparationAnalyticsController.top_shorted_products`, existing). Read strategy: **A/B**. Permission: `reports.preparation.view`. **V1.**
Gap classification: READY — EXISTING QUERY (shortages) / READY — SMALL QUERY REQUIRED (postponed count).

### Distribution & Shipping

**RPT-DIST-01 · Window/Group Utilization** — Metric: MET-DIST-02. Read strategy: **A** (`DistributionAggregationService`). Permission: `reports.distribution.view`. **V1.**
Gap classification: READY — EXISTING QUERY.

**RPT-DIST-02 · Delivery Performance** — Metrics: MET-DIST-01/03. Read strategy: **A/B**. Permission: `reports.distribution.view`. **V1.**
Gap classification: READY — SMALL QUERY/API REQUIRED.

**RPT-DIST-03 · Zone Performance** — Delivery rate/volume by Zone (`distribution_window_orders.distribution_zone_id` — never `orders.delivery_zone_id`). Read strategy: **B**. Permission: `reports.distribution.view`. **V1.**
Gap classification: READY — SMALL QUERY/API REQUIRED. Known dependency: the two-catalog "zone" trap (§14) — enforce the correct join at the query layer, not the UI layer.

**RPT-DIST-04 · Vehicle/Trip Utilization** — Trip capacity fill rate. Read strategy: **B**. Permission: `reports.distribution.view`. **V1** (Group/Trip only) — a Vehicle-identity-accurate cut is **LATER**, blocked on open blocker VP-1 (Operations\Loading's `vehicle_assignments.vehicle_id` is unconstrained/untyped against `logistics_vehicles.id`).
Gap classification: READY — SMALL QUERY/API REQUIRED (Trip-level) / UPSTREAM FEATURE REQUIRED (Vehicle-identity cut, deferred).

### Drivers

**RPT-DRV-01 · Driver Operational Summary** — Orders received/delivered, cash handled (raw), waste/damage raw counts. Metrics: MET-DRV-01/02. Read strategy: **A** (`DriverReportsReadService`). Permission: `reports.drivers.view`. **V1.**
Gap classification: READY — EXISTING QUERY.

**RPT-DRV-02 · Driver Day Settlement** — Metrics: MET-DRV-01–04 (the already-built 9-KPI set). Read strategy: **A** (`DriverDaySettlementReadService.kpis()` — reuse verbatim). Permission: `reports.drivers.view`. **V1.**
Gap classification: READY — EXISTING QUERY.

**RPT-DRV-03 · Driver Monthly Statement** — Read strategy: **A** (`DriverReportsReadService::monthlyStatement()`). Permission: `reports.drivers.view`. **V1.**
Gap classification: READY — EXISTING QUERY. Known dependency: this service's own `wallet()` method still hardcodes advances/expenses as unavailable (`no_canonical_authority`) — a stale docblock relative to `driver_trip_movements`, which now IS a real authority (confirmed live elsewhere in the same module). Flag for the owning module to reconcile before Reporting surfaces it; do not silently "fix" it from within Reporting (UPSTREAM DATA QUALITY DEPENDENCY).

### Financial (thin proxy only — see ADR-044 Decision 6)

**RPT-FIN-01 · Trial Balance** — Read strategy: **A** (`TrialBalanceService`, already routed `GET /finance/trial-balance`). Permission: `reports.finance.view` + `finance.trialbalance.view`. **V1.** Gap: READY — EXISTING QUERY.

**RPT-FIN-02 · P&L / Balance Sheet** — Read strategy: **A** (`FinancialStatementService`). Permission: `reports.finance.view` + Finance's own. **V1.** Gap: READY — EXISTING QUERY.

**RPT-FIN-03 · AR / AP Aging** — Read strategy: **A** (`ArAgingService`/`ApAgingService`). Permission: `reports.finance.view` + `finance.ar.view`/`finance.ap.view`. **V1.** Gap: READY — EXISTING QUERY.

**RPT-FIN-04 · Customer / Supplier Statement** — Read strategy: **A** (`CustomerLedgerService::statement()`/`SupplierLedgerService::statement()` — backend-complete, never surfaced). **V1** — same item as RPT-CUST-03/RPT-PROC-03; listed once here as the canonical Financial-category entry, cross-linked from Customers/Procurement. Gap: READY — EXISTING QUERY (backend) / READY — SMALL QUERY/API REQUIRED (frontend).

**RPT-FIN-05 · Profitability & Closing** — Read strategy: **A** (`ProfitabilityService`, `ClosingWorkspaceService`). **V1.** Gap: READY — EXISTING QUERY. Known dependency: product/channel profitability cuts honestly return `available:false` today — Reporting must preserve that honesty, not paper over it.

### Later Reports (deferred, with reason)

| Report | Reason deferred |
|---|---|
| Sales / Customer reporting by Sales Owner | `Sales Owner`/`Sales Rep` is not a real data field yet — ADR-024 itself lists it as "planned" (UPSTREAM FEATURE REQUIRED) |
| Unified Return/Refund reporting | No single Commerce-owned return/refund record exists — three disconnected mechanisms (`orders.status=returned` flag, POS-only `SaleReturn`, Finance-only `credit_note`) (UPSTREAM DATA CONTRACT REQUIRED) |
| Warehouse Liability / Waste financial-value reporting | `WasteInvestigation`/`WarehouseLiability` import a namespace that does not exist (`Modules\Organization\Warehouses`) — calling `->warehouse()` throws at runtime today (UPSTREAM DATA QUALITY DEPENDENCY) |
| Vehicle-identity-accurate Trip/Vehicle utilization | Open blocker VP-1 (§14) (UPSTREAM FEATURE REQUIRED) |
| Product/Channel Accounting Profitability | Finance's own ledger-dimension gap (FINANCE DEPENDENCY) |
| Multi-warehouse comparison reports | Schema-ready but only one warehouse is seeded/operated today — no real second warehouse to compare against (DEFERRED, not blocked) |
| XLSX / PDF export | No library installed or chosen yet (DEFERRED) |
| Saved views / favorite reports | Not evaluated for V1 per task §35 (DEFERRED) |
| Scheduled report delivery | Not evaluated for V1 per task §36; would require the existing mail/queue infra to be extended, not built from scratch (DEFERRED) |
| Event-fed historical rollups (Pattern D) | No current data-volume justification (DEFERRED, reserved architecture) |
| Driver shortage monetary liability | Explicitly unimplemented anywhere in the codebase (`liability_attribution_deferred`) — never fabricate this figure (UPSTREAM FEATURE REQUIRED) |

---

## 19. Source-Domain Contract Matrix

| Source module | Contract type | Notes |
|---|---|---|
| Commerce (Orders) | EXISTING QUERY (partial) + NEW SOURCE QUERY SERVICE (Sales Overview/Breakdown, Requested-Delivery Performance) | |
| Customers/CRM | NEW SOURCE QUERY SERVICE (Customer Overview/360) | |
| Products/Catalog | NEW SOURCE QUERY SERVICE (Product Performance/Top-Bottom) | |
| Inventory | EXISTING QUERY (Stock, Valuation, Shortage) + NEW SOURCE QUERY SERVICE (Stock Movements report shape) | |
| Procurement (Purchasing) | EXISTING QUERY (Scorecard, Overview) | Highest existing-capability ratio of any non-Finance domain |
| Preparation (+ DemandAnalysis) | EXISTING QUERY | |
| Distribution | EXISTING QUERY (Utilization) + NEW SOURCE QUERY SERVICE (Delivery Performance, Zone Performance, Vehicle/Trip Utilization) | |
| Shipping (Loading) | DEPENDENCY (line-level delivered-qty source only; no report of its own in V1) | |
| Finance | EXISTING QUERY (near-total) | Reporting is a consumer only — see Decision 6 |
| IAM | DEPENDENCY (permission catalog + `ScopeResolver`) | Reporting is the first adopter of `ScopeResolver` end-to-end |

## 20. DO-NOT-REIMPLEMENT

- Order pricing/status authority — `Modules\Commerce\Orders`, `OrderStatus` (ADR-042)
- Customer master authority — `Modules\Sales\Customers` / `Modules\Crm\Customers`
- Product/Brand/Category authority — `Inventory\Products` / `Organization\Brands` / `MasterData\Categories`
- Inventory balance/reservation authority — `Inventory\InventoryItems`, ADR-027
- Inventory valuation/costing — `CostManagement\EnterpriseCostEngine`
- Supplier AP authority — `Finance\Payables\SupplierLedgerService`
- Preparation Wave authority — `Operations\Preparation` (identity/FSM), `Operations\DemandAnalysis` (completion/shortage)
- Distribution Group/Trip authority — `Logistics\Distribution`, `GroupCapacityGuard`
- Driver operational + financial authority — `Logistics\Distribution` (`DriverTripMovement`, `TripSettlement`, `DriverReportsReadService`)
- `JournalEngine` — the sole writer of `finance_journal_entries`/`finance_journal_lines`, enforced by architecture test
- AR/AP allocation — `Finance\Allocation\AllocationEngine` (note: **not** ADR-022, which is Operations/Loading vehicle allocation despite the name)
- Finance Revenue/COGS/P&L/Balance Sheet — `Finance\Reporting`/`Finance\Analytics`/`Finance\Intelligence`
- Finance Closing — `Finance\Closing`
- IAM `AuthorizationGateway`/`ScopeResolver`/`permission:` middleware
- Supplier procurement scoring — `Purchasing\GetProcurementHealthQuery`/`GetSupplierAnalyticsQuery`
- CRM C5/C6 Intelligence/Executive services — read from them for CRM-scoped figures; do not re-derive

## 21. Change Process

Any addition to the Metric Dictionary or Report Catalogue after this task requires: (1) a canonical source citation with file:line evidence, (2) an explicit `OPERATIONAL`/`ACCOUNTING` classification, (3) a Gap Classification per task §38's vocabulary, (4) a version bump to this document. No metric ships without all four.

---

*Version 1.0 — 2026-09-03 — Architecture only. No code in this specification has been implemented.*

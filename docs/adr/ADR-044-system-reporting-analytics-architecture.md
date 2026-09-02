# ADR-044: System Reporting & Analytics Architecture

**Status:** Proposed — Awaiting CTO Ratification
**Version:** v1.0
**Date:** 2026-09-03
**Author:** Engineering Architecture Review
**Inputs:** TASK-ECOS-SYSTEM-REPORTING-ARCHITECTURE-001
**Related:** ADR-020 (immutable-financial-snapshot — scope note in Decision 2), ADR-023 (order-snapshot-policy), ADR-027 (reservation-ownership-policy), ADR-038 (enterprise-authorization-platform), ADR-042 (order-fsm-v3-canonical), `backend/docs/adr/ADR-021` (enterprise-snapshot-platform — a second, parallel ADR tree; see Known Documentation Gaps), `docs/architecture/ENTERPRISE-PLATFORM-SERVICES.md` (EPS Architecture Freeze, 2026-07-05 — §7 names "Analytics Platform," outside EPS-01..04, as the home for "Reporting and analytics"; this ADR ratifies that platform)
**Discharges:** TASK-ECOS-SYSTEM-REPORTING-ARCHITECTURE-001 (Task 1 of System Reporting Batch 01)
**Full evidence trail:** `docs/verification/TASK-ECOS-SYSTEM-REPORTING-ARCHITECTURE-001-REPORT.md`
**Living reference:** `docs/architecture/ENTERPRISE-REPORTING-PLATFORM.md` (Metric Dictionary, Report Catalogue, full contract matrix)

---

## 0. Canonical Freshness Notice

This ADR was authored on a **second device** (`D:\ECOS-Work\ecos-reporting`, branch `task/system-reporting`), whose `origin` remote (`E:/ECOS/ecos-develop`) is **not reachable from this machine** (no `E:` drive present). The local `origin/develop` ref (`16b0ec85`) is identical to this branch's HEAD, but that ref's own freshness relative to the true first-device `develop` tip is **NOT VERIFIED**. Every fact below is sourced from the local working tree as of 2026-09-03; where a sibling lane (`ecos-finance`, `ecos-iam`, `ecos-chat`) is known to hold uncommitted or unmerged work that could change one of these facts, it is flagged explicitly rather than assumed resolved. See the engineering report §2–§6 for the full reconciliation ledger.

---

## Context

ECOS is a Laravel 12 / MySQL 8.4 modular monolith with 23 bounded-context backend modules. A full source audit (TASK-ECOS-SYSTEM-REPORTING-ARCHITECTURE-001, Task 1) found:

1. **No system-wide Reporting bounded context exists**, but the *absence* is not the same as a blank slate: **12 of 23 modules already implement real, routed, tested reporting/analytics/dashboard capability independently** — most extensively Finance (a full F5 "Intelligence"/"Reporting" EPIC: Trial Balance, P&L, Balance Sheet, AR/AP Aging, Customer/Supplier Statements, Cash Position, Profitability, Closing reports — all live), CRM (C5 Intelligence + C6 Executive, test-enforced to never import Finance/Commerce/Operations), Logistics (Enterprise + Operational dashboards), Marketing (28 routed analytics endpoints), plus HR, Inventory, Purchasing, CostManagement, Operations/Preparation, and CustomerEngagement.
2. **The frontend already reserved, and never built, the consolidation point.** `frontend/src/config/module-navigation.ts` has carried a `reports` `ModuleId` with an empty `items: []` array since the navigation system's first commit; it is hidden today via `HIDDEN_MODULE_IDS` and its route resolves to a literal `ComingSoonPage` (`frontend/src/router/router.ts:234-237`).
3. **`docs/architecture/ENTERPRISE-PLATFORM-SERVICES.md` (frozen 2026-07-05)** — the ADR governing the four Enterprise Platform Services (Events, Timeline, Documents, Notifications) — explicitly excludes "Reporting and analytics" from EPS and names its future home as a separate **"Analytics Platform"** (§7). That platform was never specified. This ADR is that specification, deliberately kept outside the frozen EPS boundary rather than proposed as a fifth EPS service.
4. **No shared technical substrate exists to build a naive "read model layer" on**: zero database views, zero `ReadModel`/`Projection` folders anywhere in `backend/`, zero export libraries (backend or frontend), zero charting libraries in the frontend. What *does* exist and is production-proven: a live cross-module domain-event bus (`Modules\Platform\EventPlatform`, confirmed publish/subscribe sites in POS, Finance, Operations\Fulfillment, Commerce\Orders) and a queue/job infrastructure (27 `ShouldQueue` jobs across 7 modules, Supervisor-managed).
5. **Financial and operational truth are demonstrably not the same number today**, not merely "conceptually distinct": only `pos.sale.finalized` posts to the General Ledger; the delivery/COD sales channel — ECOS's primary channel — posts nothing (`Finance\Integration\Application\Bridge\EventPostingCatalog` has no `orders.delivered`/`inventory.stock.shipped` entry), a gap Finance's own architecture-lock audit and UAT-009 report both already certify as real and unresolved. A new Reporting layer that ignored this and quietly equated "order value" with "revenue" would manufacture a false number, not just a stylistic inconsistency.
6. Numerous per-domain "second source" and precision traps exist across modules (duplicate COGS figures, a dead/unpopulated `orders.payment_status` column, two unrelated "zone" concepts on Order, inconsistent monetary decimal scales across Commerce/Inventory/Purchasing vs. Finance's uniform `decimal(20,4)`) that a reporting layer must route around rather than naively aggregate over. These are catalogued exhaustively in the engineering report and the Metric Dictionary's "Known Dependency" column.

Given this evidence, Reporting is a **reconciliation and consolidation problem first, and a build problem second** — matching the fragmentation pattern `ENTERPRISE-PLATFORM-SERVICES.md:32-44` already names and warns against for other cross-cutting concerns ("identical capabilities built 12 times differently... a bug must be fixed in 12 places").

---

## Decision 1 — Reporting is a new, read-only bounded context: `Modules\Reporting`

A new backend module, `backend/Modules/Reporting/`, is established as the single canonical home for cross-domain business reporting and analytics, fulfilling the "Analytics Platform" placeholder in `ENTERPRISE-PLATFORM-SERVICES.md` §7. It is **not** registered as an EPS service (EPS remains frozen at EPS-01..04) and **not** placed under `App\Core\EnterpriseServices` (that tree is domain-agnostic infrastructure; Reporting is domain-aware by nature — it *means something* about Sales, Finance, Drivers, etc.).

On the frontend, this activates the already-reserved `reports` `ModuleId` (`frontend/src/config/module-navigation.ts:404-409`): populate its `items` with per-category navigation and remove `'reports'` from `HIDDEN_MODULE_IDS` only when a V1 report actually ships (Task 2+), not in this task. Per the task brief §5, this is **one Reports workspace with category tabs/navigation inside it** (Executive, Sales, Customers, Products, Inventory, Procurement & Suppliers, Preparation, Distribution & Shipping, Drivers, Financial) — not ten separate top-level nav entries.

Module skeleton (mirrors the Clean-Architecture layering every other module already uses):

```
backend/Modules/Reporting/
├── Domain/
│   ├── Models/           — Reporting-owned metadata ONLY: ExportJob, ReportGenerationAudit.
│   │                        No business-fact model is ever added here.
│   ├── Enums/             — ReportCategory, FreshnessClass, ExportFormat, ExportStatus
│   └── Contracts/         — SourceReportQueryContract (optional discoverability interface;
│                             see Decision 3)
├── Application/
│   ├── Queries/           — CROSS-DOMAIN COMPOSITION ONLY (e.g. ExecutiveOverviewComposer).
│   │                        Never a primary aggregate for a single-domain metric — that
│   │                        belongs in the owning module (Decision 3).
│   ├── Services/          — MetricRegistryService (reads the Metric Dictionary as versioned
│   │                        config), ExportService, ScopeApplicationService
│   └── Jobs/               — GenerateReportExportJob (ShouldQueue)
├── Infrastructure/
│   ├── Providers/          — ReportingServiceProvider
│   └── Database/Migrations/ — ONLY export_jobs, report_generation_audit. Never a business
│                                fact table (see Decision 2).
└── Presentation/
    └── Http/
        ├── Controllers/    — one per category (SalesReportController, FinanceReportController
        │                     [thin proxy — Decision 6], ExecutiveReportController, ...)
        └── Resources/
```

## Decision 2 — Read-only truth model; source authority is absolute

Reporting **never** creates, updates, or deletes a row in any table it does not itself own (`export_jobs`, `report_generation_audit`, and any narrowly-scoped derived rollup approved under Decision 3's Pattern D). It is never the system of record for any figure in the Metric Dictionary, even when it caches a computed value.

Canonical per-domain authority, established by direct source audit (full citations in the engineering report §7–§17):

| Domain | Canonical source module | Notes |
|---|---|---|
| Orders / Sales operational truth | `Modules\Commerce\Orders` | Sales module owns only Customers + ShippingPricing — no Order entity exists there |
| Customer master | `Modules\Sales\Customers` (FK-target identity) + `Modules\Crm\Customers` (CRM-360 enrichment, **same physical `customers` table**, two Eloquent models) | See Decision 2a |
| Product | `Modules\Inventory\Products` | Not MasterData, not Commerce — no `Commerce\Catalog` exists |
| Brand | `Modules\Organization\Brands` | |
| Category | `Modules\MasterData\Categories` | |
| Inventory / stock balances | `Modules\Inventory\InventoryItems` (+ `ReceiptLayers` for FIFO) | `available` is always a derived accessor, never a stored column |
| Costing / valuation | `Modules\CostManagement\EnterpriseCostEngine` | Reads Inventory's ledger/layers; explicitly documented as *the* valuation SSOT |
| Procurement / Suppliers | `Modules\Purchasing` (Suppliers, PurchaseOrders, GoodsReceipts, SupplierInvoices) | |
| Preparation identity/status | `Modules\Operations\Preparation` | Wave FSM only |
| Preparation prepared-qty / shortage data | `Modules\Operations\DemandAnalysis` | **A different module than Wave identity** — `wave_product_demand` / `wave_material_demand` / `wave_missing_materials`, not `Modules\Operations\Preparation`'s own tables |
| Distribution (Window/Group/Trip) | `Modules\Logistics\Distribution` | "Group" (`VirtualCapacitySlot`) is never a Vehicle — no `vehicle_id`/`driver_id` column exists on it, by design |
| Loading execution | `Modules\Operations\Loading` | `VehicleAssignment`/`LoadingTask`; linked to Distribution's Trip by a plain cross-module reference (`trip_id`, no DB FK), per `FOREIGN-KEY-STANDARDS.md` |
| Delivery outcome | `Modules\Logistics\Distribution` (`DeliveryStop`/`DeliveryAction`/`DeliveryProof`/`TripReturn`) | A parallel `Modules\Logistics\Delivery` stack exists but has **no driver-runtime caller** — not a valid report source |
| Driver operational + financial (advances/expenses/settlement) | `Modules\Logistics\Distribution` | Finance has **zero** code awareness of drivers (confirmed: 0 files) — this is a deliberate rule ("Distribution is the Single Cash Authority"), not a gap |
| Finance (GL / AR / AP / Revenue / COGS-posted / P&L / Balance Sheet / Closing) | `Modules\Finance` | EPICs F1–F5, live |
| Authorization / permissions / data scope | `Modules\IAM` | |

**Decision 2a (Customer table).** `Sales\Customers\Domain\Models\Customer` and `Crm\Customers\Domain\Models\Customer` both model the one physical `customers` table. Reporting must join on `customer_id` and source base-identity fields (`name`, `phone`, `email`, `is_active`) from the Sales-era columns (what `orders.customer_id` actually constrains against), and CRM-enrichment fields (segmentation, `customer_type`, `merged_into_id`, etc.) only where confirmed present — the CRM-enrichment migration is dated after this task's "as-of" date and its execution status in any given environment must be verified before Task 2 relies on it.

**Decision 2b (scope of ADR-020).** ADR-020's claim that "the snapshot IS the financial truth" is scoped to **Commerce order-economics** (`order_financial_snapshots`/`order_line_snapshots` — grand total, COGS-at-sale, margin, captured once at order-Confirm). It is not a statement about the General Ledger, and per §6.5 of the evidence, it is not currently wired to the GL at all. Every Metric Dictionary entry sourced from these snapshot tables must carry the `OPERATIONAL` classification, never `ACCOUNTING`, and must never be relabeled "Recognized Revenue."

## Decision 3 — Cross-module read strategy: query-service composition, deferred projections

Evaluated against actual repo evidence (DB engine, index/volume signals, module boundaries, and the fact that **every existing reporting surface in ECOS today is already built this exact way**):

- **Pattern A — Call the existing source-owned query/read-service** (primary pattern; used for the majority of V1 reports). Finance (`TrialBalanceService`, `ArAgingService`, `CustomerLedgerService`, ...), Purchasing (`GetSupplierAnalyticsQuery`, `GetProcurementHealthQuery`), Inventory (`InventoryDashboardService`, `VarianceAnalyticsService`), CRM (C5/C6 services), and Logistics (`DriverReportsReadService`, `EnterpriseDashboardService`) already expose exactly the read models Reporting needs. Reporting calls them; it does not re-derive their SQL.
- **Pattern B — New source-owned query service.** Where no such service exists (e.g., "Sales by Brand/Category/Channel"), the new query class is added **inside the owning module** (e.g. `Modules\Commerce\Orders\Application\Queries\SalesBreakdownQuery`), following the `Application\Queries` convention already present in Commerce, Inventory, and Purchasing. Reporting never contains a primary aggregate over another module's tables.
- **Pattern C — Reporting-owned composition only.** Reporting's own code is limited to calling one-or-more Pattern A/B services, merging results for genuinely cross-domain views (Executive), applying the IAM data-scope, and formatting for export. This is the server-side, authorized replacement for what the existing `/executive` page already does client-side today (eight endpoints across six modules, fetched and merged in the browser) — the pattern is proven useful; the client-side, unauthorized, no-caching execution of it is not (Hard Rule, task §26: never aggregate full datasets in React).
- **Pattern D — Event-fed rollups.** Reserved, **not adopted for V1**. `Modules\Platform\EventPlatform` is real and live, and Finance's own `ReportSnapshot` (immutable, narrow, six report types only) is a working precedent for this pattern — but POS's `pos_analytics_events`/`pos_customer_stats` is a cautionary counter-example in the same codebase: a fully-built, fully-indexed write path that **nothing has ever read**. No current data-volume evidence (the operation is effectively single-warehouse today) justifies pre-building event-fed rollups. Revisit in Task 3+ only if live-aggregation query profiling under Decision 7/§26 actually shows a performance ceiling.
- **Database views / materialized views:** not adopted. Zero existing precedent anywhere in the codebase; would require a raw-SQL escape hatch inconsistent with the query-builder convention every module already uses.

## Decision 4 — Metric Dictionary is the single definition authority

The Metric Dictionary (`docs/architecture/ENTERPRISE-REPORTING-PLATFORM.md`) is the canonical definition of every metric name, formula, source, date basis, and classification used anywhere in the Reports workspace. Any report added in Task 2+ must cite an existing dictionary entry or add a new one through the same architecture-review process — never ad hoc inside a controller or a frontend component. Given the confirmed precision, duality, and stale-column traps found during this audit (dead `payment_status` column, duplicate COGS figures, two unrelated "zone" concepts, inconsistent decimal scales), an un-governed, per-PR metric definition process would reproduce exactly the fragmentation this ADR exists to end.

## Decision 5 — IAM integration: category-level `reports.*` permissions; zero new authorization engine

Per task §20, evaluated options:

| Option | Verdict |
|---|---|
| A. One global `reports.view` | Rejected — too coarse; Finance/Driver/Executive reports carry sensitive figures that must not follow automatically from generic report access |
| B. Category-level reporting permissions | **Adopted** |
| C. Source-domain view permissions only | Rejected — conflates "can operate AP/AR" with "can view the AR Aging report"; Finance's own catalog already treats these as distinct (`finance.reports.view` already exists as its own resource) |
| D. Hybrid | Rejected for V1 — adds complexity the task explicitly asks to avoid ("minimum coherent V1 model") |

New permissions, following the existing `{domain}.{resource}.{action}` convention exactly (`backend/config/permissions.php`): `reports.executive.view`, `reports.sales.view`, `reports.customers.view`, `reports.products.view`, `reports.inventory.view`, `reports.procurement.view`, `reports.preparation.view`, `reports.distribution.view`, `reports.drivers.view`, `reports.finance.view`. Seeded via the migration-driven convention (the dominant one for Finance/HR/CRM/Logistics today), gated with the existing `permission:` route middleware — **no new AuthorizationGateway, no new middleware, no new engine** (task §20 hard rule). `reports.finance.view` is a documented **additional** gate layered on top of the relevant `finance.*.view` permissions, never a bypass of them.

Company/tenant scoping on Reporting's own tables (`export_jobs`, `report_generation_audit`) uses the `TenantOwnershipResolver` fail-closed global-scope pattern — the strictest of the three tenant-scoping mechanisms found in the codebase, and the one already used by Order/Product/Warehouse/Supplier. Every report *query* is scoped transitively for free, because it calls a source service that already carries the source model's own tenant scope. Finer data-scope (a Sales Rep seeing only their own customers; a Driver seeing only their own trips) reuses the existing `ScopeResolver`/`scopedTo()` engine where practical — Reporting is flagged as the **first real adopter** of that engine, since no business module uses it today despite it being fully built and role-templates already declaring scopes like `'sales.orders' => 'self'` that no code currently enforces.

## Decision 6 — Finance boundary: proxy and link, never recompute

Per task §42/§15, Reporting may call Finance's queries and display Finance's aggregates; it may not implement its own P&L, AR/AP balance, revenue recognition, COGS, or Driver financial balance. Concretely: nearly every Financial report classifies as **READY — EXISTING QUERY** (Trial Balance, Income Statement, Balance Sheet, AR/AP Aging, Cash Position, Profitability, Closing reports are all live, routed, and tested). The one genuinely missing piece is a **frontend surface** for Customer/Supplier Statements — `CustomerLedgerService::statement()` / `SupplierLedgerService::statement()` are implemented and routed today but never called from any frontend page. Reporting's Financial category in V1 is therefore overwhelmingly "expose and govern," not "build." Reporting must **not** attempt to close the delivery/COD revenue-recognition gap (Decision 2b) — that is a tracked Finance-owned gap, not a Reporting defect to route around by inventing its own number.

## Decision 7 — Freshness classes

| Class | Applies to | Mechanism |
|---|---|---|
| **LIVE / NEAR-LIVE** | Orders, preparation-wave state, distribution/delivery state, stock availability | Computed on request against live operational tables; short TTL cache only where an existing module precedent already caches (e.g. Preparation's own controller-level 30s/300s caching) |
| **EVENTUAL / AGGREGATED** | Historical trends, large date-range rollups, Executive composites | Cached with an explicit TTL; recomputed on a schedule or on demand, never silently stale without a displayed "as of" timestamp |
| **ACCOUNTING-POSTED** | All Finance statements | Inherited automatically from Finance's own `FiscalPeriod`/`PeriodStatus` gate (Open/Closed/Locked) — Reporting never recomputes a figure Finance has posted, and a Locked period's numbers are permanently frozen by Finance's own architecture, not by anything Reporting does |

## Decision 8 — Exports

V1: synchronous CSV only, generated **server-side** (moving the one working precedent — `/executive`'s hand-rolled in-browser `exportCsv()` — behind authorization and data-scope checks, which the current client-side version does not have). XLSX/PDF are **DEFERRED**; no library is installed today (`maatwebsite/excel` / `league/csv` are candidates, not yet chosen — an open dependency for Task 4). Exports above a row-count threshold (set in Task 2) queue an async job on the existing `ShouldQueue` infrastructure and notify via the existing in-app notification feed — never the unrelated `Modules\System\Engineering` CI/CD notification system (see Decision 11). Every export stamps: exact filters, `generated_at`, `generated_by`, company context, report title, and the Metric Dictionary version it was generated against (task §27 provenance rule).

## Decision 9 — Drill-through re-enters source-module authorization

Every drill-through link navigates into the **source module's own existing, authorized page** (e.g., a Sales total drills into the existing Orders workspace, filtered) rather than a Reporting-owned detail view. This makes task §22's rule ("report visibility does not automatically grant source-record visibility") true by construction: the source module's own permission and data-scope checks apply natively on arrival, with no separate authorization logic for Reporting to get wrong.

## Decision 10 — V1 scope

Bounded at roughly 35–40 reports across all 10 categories (full catalogue: `ENTERPRISE-REPORTING-PLATFORM.md`), weighted toward the **READY — EXISTING QUERY** and **READY — SMALL QUERY REQUIRED** classifications the source audit actually found. Given ~80% of the raw query capability already exists (Finance, Purchasing, Inventory, CRM, Logistics), V1 is substantially an "expose, unify, and govern" effort, not a from-scratch build. A "LATER" list captures everything explicitly deferred (saved views, scheduled reports, XLSX/PDF, event-fed rollups, real multi-warehouse comparison).

## Decision 11 — No new notification mechanism

Task §52 requires using "the existing approved ECOS notification mechanism if available" and forbids inventing one. Direct audit found: no artisan command, route, or script anywhere in the repository implements a cross-agent or cross-task "notify on completion" mechanism; `Modules\System\Engineering`'s `EngineeringNotification`/`PipelineNotificationService` is confirmed to be product code for an unrelated in-app CI/CD pipeline feature, not task-closure tooling. This ADR and its accompanying engineering report constitute the notification, as has been the de facto and only pattern used across 250+ prior task-completion reports in this repository.

---

## Consequences

### Positive
- Consolidates 12+ independently-built reporting surfaces behind one governed workspace, one permission model, and one metric vocabulary, instead of certifying a 13th independent implementation.
- Adds essentially zero new authorization, aggregation, or export infrastructure — reuses the `permission:` middleware, `TenantOwnershipResolver`, the queue system, and the event bus exactly as they already exist.
- Makes the Commerce-vs-Finance revenue gap, the duplicate-COGS problem, and the dead/trap columns (`payment_status`, `orders.delivery_zone_id`) *visible and governed* for the first time, rather than left for each future report-builder to rediscover independently.
- Fulfils a placeholder the architecture already reserved (`ENTERPRISE-PLATFORM-SERVICES.md` §7's "Analytics Platform") and a UI slot the frontend already reserved (`reports` `ModuleId`), rather than inventing a new concept.

### Negative / Trade-offs
- Category-level permissions (Decision 5) are coarser than a fully granular per-report model; a role granted `reports.sales.view` sees every Sales V1 report. Accepted as the documented minimum-coherent V1 model per task §20.
- Deferring Pattern D (event-fed rollups) means large historical trend queries in V1 run live; acceptable only because current data volume is small (effectively single-warehouse) — this must be re-evaluated, not silently carried forward, once real multi-warehouse volume lands.
- Two Financial-reporting UX pieces (Customer/Supplier Statement pages) become Reporting-workspace responsibilities even though 100% of their backend logic is Finance's — a deliberate seam, not an oversight, but one that requires care so Reporting's controller stays a thin proxy and never grows its own statement-computation logic.
- The exact number of V1 reports (and therefore Task 2/3 sizing) depends on IAM's `ScopeResolver` adoption effort, which has zero precedent to copy from — this is flagged as a real estimation risk for Task 2 planning, not hidden.

---

## Known Documentation Gaps (found during this audit, not fixed by it)

Recorded here because they affect how future ADRs/readers should navigate this repository's architecture history — not fixed as part of this task (architecture-only, no implementation):

1. At least **eight** separate ADR-numbered locations exist (`docs/adr/`, `docs/architecture/*.md` top-level, `docs/architecture/adr/`, `docs/aiop/adrs/`, `backend/docs/adr/`, `docs/engineering-cloud/`, `docs/architecture/pos/ADR-POS-*`, `docs/inventory/ADR-INV-*`), with real number collisions between the first three (e.g., three different "ADR-012"s). `docs/adr/` is the active series by behavioral evidence (most recent commits, cited by number from the newest ADRs) but no document anywhere declares this canonical. This ADR is filed in `docs/adr/` on that basis.
2. `backend/docs/adr/ADR-021-enterprise-snapshot-platform.md` — the direct continuation of ADR-020 — lives outside the top-level `docs/adr/` tree entirely and is easy to miss; ADR-020's own reference to a future "ADR-021" resolves here.
3. ADR-022 (`docs/adr/ADR-022-allocation-orchestration-engine.md`) governs **Operations/Loading vehicle-load allocation**, not Finance AR/AP allocation, despite the name collision with Finance's real `AllocationEngine`. Flagged so Task 2+ does not cite it incorrectly.
4. **ADR-044 collision risk:** the sibling `ecos-chat` lane (branch `task/chat-workstream`, local HEAD `12f8ab55`) has independently authored `ADR-044-internal-collaboration-bounded-context.md` on its own branch. Neither this ADR nor that one is merged into shared `develop` as of this writing. **Whichever merges to `develop` first keeps ADR-044; the other must be renumbered at merge time.** This is exactly the kind of cross-lane collision the canonical-freshness gap in §0 predicts, and it is recorded here rather than silently resolved.

---

## Status of Implementation

**NOT STARTED.** This ADR is Task 1 of System Reporting Batch 01 (architecture only). No endpoint, migration, permission, or UI was created. Task 2 (Reporting Foundation + Commercial Reports) begins only after CTO ratification of this ADR — see the engineering report §35 for the exact Task 2–5 plan and stop conditions.

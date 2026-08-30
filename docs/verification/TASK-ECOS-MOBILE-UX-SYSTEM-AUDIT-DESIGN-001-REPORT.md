# TASK-ECOS-MOBILE-UX-SYSTEM-AUDIT-DESIGN-001 — REPORT

**Title:** ECOS ERP Mobile / Responsive UX — System Audit & Unified Design
**Type:** Architecture & Design (audit + specification)
**Author:** Osama Fayez
**Date:** 2026-08-29
**Scope discipline:** DESIGN ONLY. No implementation. No commit / push / deploy / DEV business-data mutation.

> This document is a **design artifact**, not an implementation record. It contains no "implementation status" — its final line is a **DESIGN STATUS** verdict for CTO review. Nothing in the ECOS source tree was modified to produce it (audit is read-only / static-analysis based; live mobile-viewport inspection is authorized as inspection, not certification).

---

## 1. Executive Summary

ECOS already ships the **skeleton** of a mobile experience — an enterprise app shell with a bottom navigation bar, a full-screen RBAC-driven module menu, and a data-grid primitive (`UniversalDataGrid`) that is *capable* of rendering mobile cards. The problem is not the absence of a mobile foundation; it is that **the foundation is used by a small minority of screens**, so the app is mobile-ready in patches and desktop-only everywhere else.

The single most important measured fact:

> **`UniversalDataGrid` is consumed by 33 screens, but only 8 of them supply a `renderMobileCard`. The other 25 render an *empty container* below the `lg` breakpoint — the data is literally invisible on a phone.** The desktop `<table>` is `hidden lg:block`; the mobile branch is `renderMobileCard ? <cards/> : null`.

That is a **P0 systemic defect class**, not a per-page cosmetic issue. It concentrates in whole modules that have not been through a recent mobile pass: **Finance (all 9 grid pages), CRM, Marketing Intelligence, Suppliers, Engineering, and most of Logistics**. The modules that *do* pass `renderMobileCard` (Operations waves, Receiving, Orders, Products, Driver Settlement) are exactly the ones touched by recent operator/driver work — confirming the pattern is *adoption*, not *capability*.

A **second card-less table mechanism** compounds it: **`EntityTable` (the "Enterprise CRUD Kit" table) is used by ~22 live pages and has no card mode at all** — its docstring says "responsive," but it renders a plain `<table>` and degrades to horizontal scroll on mobile, with the row-actions column pushed off-screen. This drives the entire master-data cluster (brands, channels, business-accounts, product-mappings, categories, supplier-invoices/returns, warehouses, units, teams, companies, branches, sync-logs, HR employees/recruitment, goods-receipts). Crucially, **one component retrofit** (give `EntityTable` the same `renderMobileCard` dual-layout `UniversalDataGrid` already has) fixes all ~22 at once.

A third class: **~111 feature files render hand-rolled `<table>`** (line-editors, detail/view pages, drawers), including literal `min-w-[1600px]` / `min-w-[800px]` spreadsheets (cost-pricing, stock-ledger). At best these are cramped horizontal scroll (P1); a nastier sub-variant wraps the table in `overflow-hidden` (customers, cep-leads, marketing lists) which **clips the Actions column entirely (no scroll to reach it)** and pairs it with `opacity-0 group-hover` actions (12 files) that are **unreachable by touch** — borderline P0.

A fourth class — **bespoke desktop-only workspaces that no shared component can fix**: the **Distribution Board** (fixed two-pane drag-and-drop → order→trip assignment is impossible at 375px) and the **Unified Inbox / omnichannel** (fixed three-pane → the message thread collapses to ~0 px). These need per-surface redesigns (tap-to-assign; list↔thread view switching), not a table swap.

A fifth class: **there is no `useIsMobile` / breakpoint hook and no shared mobile component set** (`MobileDataCard`, `MobileFilterSheet`, `MobileDetailSheet`, `MobileActionBar`). The 8 screens that do have cards each **hand-roll** them, so even the "good" screens are inconsistent with each other.

**The good news** — and the reason this is a *tractable* design, not a rewrite — is that every proposed remedy is **buildable on primitives that already exist**:

- Two **component-level fixes cascade across the bulk of the debt**: (a) `UniversalDataGrid` already has the responsive table↔card seam — the fix is **adoption of one prop** (25 pages), and (b) retrofitting the *same* dual-layout onto `EntityTable` fixes ~22 master-data pages in one change. A shared `MobileDataCard` makes both cheap and consistent.
- `Sheet` already supports `side="bottom"`. `MobileFilterSheet`, `MobileDetailSheet`, and action overflow are all Sheet compositions — **no new dependency**.
- Several shared components are **already mobile-correct and serve as the model**: `WorkspaceMetricsRow` (horizontal KPI scroll-strip), `WorkspaceContextActions` (overflow `⋯` menu on mobile), `PageHeader`/`EntityToolbar` (stack), `SmartToolbar` (`hideOnMobile`), and the four Wave tabs + Receiving + Orders + Products (`renderMobileCard` reference implementations).
- Tokens already carry **RTL logical spacing** (`--space-start` / `--space-end`) and **dark mode** (`@custom-variant dark`). RTL/Arabic and theming are first-class already.
- The **enterprise shell nav is essentially done** (bottom nav + RBAC accordion menu + tablet sidebar overlay). §7 is a *refinement*, not a build.

**Recommended shape of the work:** a small set of **shared mobile components** (Workstream A) plus the **two component-level retrofits**, adopted module-by-module in severity order (Finance → CRM → Logistics → the rest), with **old and new coexisting** (the change is presentational; no business logic moves). A short, separate track handles the **~2–4 bespoke desktop-only surfaces** (Distribution Board, Unified Inbox, omnichannel, cost-pricing spreadsheet) that need real redesigns rather than a table swap. This is 7 medium workstreams, not "rewrite every page."

**DESIGN STATUS (preview; full verdict at end): READY FOR CTO REVIEW.**

---

## 2. Audit Scope & Method

### 2.1 What was audited
Per the task, the audit covers the **enterprise application** across all modules and page *types*, and assesses the **Driver App separately** (the two shells must not be merged — see §24).

Page types in scope: module landing/workspace pages, list/table pages, detail/record pages, operational workspaces (waves, distribution, dispatch, driver closing), reports/analytics, forms & editors, dialogs/drawers, filters/search/sort, export surfaces, and settings.

Modules in scope: Dashboard, Executive, Commerce (Orders/Products/Customers), Inventory (control/count/stock-ledger/raw-materials), Operations (waves/preparation/distribution/dispatch), Preparation, Distribution, Shipping/Logistics, Procurement (POs/goods-receipts/receiving/suppliers), Finance (F1–F5), Marketing (+Intelligence), CRM, HR, Engineering, Recipes/BOM/Cost, and platform surfaces (nav, header, settings).

### 2.2 Method
1. **Static source analysis (authoritative for a systemic audit).** The mobile-readiness of a screen is *deterministic in source*: whether it (a) uses `UniversalDataGrid` with `renderMobileCard`, (b) uses a raw `<table>`, (c) uses responsive breakpoint classes, and (d) handles loading/error/empty distinctly. This yields exhaustive counts, not samples.
2. **Shared-infrastructure read.** The app shell, mobile menu, bottom nav, the grid primitive, `Sheet`/`Dialog`/`Card`, the CRUD scaffolding (`PageHeader`, `EntityToolbar`, `FilterPanel`, `SearchInput`), and the Tailwind v4 theme were read in full to establish the *reusable substrate* the redesign must build on.
3. **Per-module page sweep.** Three parallel read-only exploration passes over Commerce/Procurement/Shipping, Inventory/Operations/Preparation, and Finance/Executive/Dashboard/Reports/Marketing/CRM/Admin to classify representative pages by type and mobile behavior.
4. **Live mobile-viewport inspection — authorized, best-effort.** Per §3, read-only browser inspection at a phone viewport is authorized as *inspection* (not certification, no mutation). The systemic findings do **not** depend on it — they are structural — so it is offered as optional CTO-facing evidence (see §30), not a gate on this report.

### 2.3 Detection heuristics (how severity was assigned)
| Signal in source | Mobile consequence | Severity |
|---|---|---|
| `UniversalDataGrid` **without** `renderMobileCard` | Empty container `<lg` — **data invisible** | **P0** |
| `EntityTable` (CRUD kit — no card mode) | Horizontal-scroll; actions column off-screen | **P1** |
| Hand-rolled `<table>` (no card fallback) | Horizontal-scroll / overflow; header off-screen | **P1** |
| `<table>` in `overflow-hidden` / `opacity-0 group-hover` actions | Columns clipped (no scroll); actions untouchable | **P1→P0** |
| Fixed multi-pane / drag-drop layout | Panes crammed/collapsed; task impossible | **P0** |
| `UniversalDataGrid` **with** `renderMobileCard` | Card list `<lg` | OK (verify card quality) |
| Multi-column form/editor with fixed columns / `min-w-[…]` | Fields clipped / pan-and-type | **P1–P2** |
| Inline `FilterPanel` (collapsible) | Usable single-column, not sheet-native | **P2** |
| Action row without `flex-wrap` | Buttons overflow off-screen | **P2** |

---

## 3. Design-Only Constraint — What This Task Does *Not* Do

Explicitly out of scope, by task mandate:

- **No implementation of the redesign.** No new components, hooks, or page changes were written.
- **No source modification** beyond zero (no audit instrumentation was necessary; static analysis sufficed).
- **No business-architecture change.** Fulfillment, inventory, settlement, returns, Wave lifecycle, accounting/GL, reservations, and RBAC authorities are untouched and unaddressed by this redesign (see §26).
- **No DEV business-data mutation, no commit/push/deploy.** This is an audit + specification awaiting CTO approval before any build begins.

The deliverable is this report + the shared-component and rollout **specifications** it contains.

---

## 4. Mobile UX Page Inventory

The inventory is presented at two grains: (4.1) a **systemic class table** (the reusable truth that drives the rollout), and (4.2) a **per-module representative inventory** (concrete pages, their type, current behavior, and target pattern). Page-by-page enumeration of all ~250 screens is deliberately *not* the deliverable — §25 rejects "rewrite every page," and the class table below already tells you the fix for any page from its detected pattern.

### 4.1 Systemic classes (exhaustive counts from static analysis)

| # | Class | Detected by | Count | Mobile behavior today | Severity | Target |
|---|---|---|---|---|---|---|
| C1 | Grid page **without** mobile card | `UniversalDataGrid` w/o `renderMobileCard` | **25 screens** | Empty box `<lg`; **data invisible** | **P0** | `renderMobileCard` via shared `MobileDataCard` |
| C2 | Grid page **with** mobile card | `UniversalDataGrid` + `renderMobileCard` | **8 screens** | Card list `<lg` | OK | Normalize onto shared `MobileDataCard` |
| C3a | **`EntityTable` (CRUD kit)** — no card mode | `EntityTable` usage | **~22 live pages** | H-scroll; actions column off-screen | **P1** | **Retrofit `renderMobileCard` onto `EntityTable`** (one component → all 22) |
| C3b | Hand-rolled `<table>` | lowercase `<table>` in feature files | **~111 files** | H-scroll / overflow | **P1** | `ResponsiveDataView` or `MobileDetailSheet` rows |
| C3c | `<table>` in `overflow-hidden` + `opacity-0 group-hover` actions | grep both idioms | **~5 lists + 12 hover-action files** | **Columns clipped (no scroll); actions untouchable** | **P1→P0** | Card list + always-visible tap actions |
| C4 | Multi-column forms / line-editors / spreadsheets | `*-form*.tsx`, `*-lines-editor.tsx`, `min-w-[…]` | ~20+ (incl. `min-w-[1600px]` cost-pricing, `min-w-[800px]` ledger) | Fields clipped; pan-and-type | **P1** | Single-column form + line-card editor (§15) |
| C5 | Inline filter panels | `EntityToolbar`+`FilterPanel` | app-wide | Single-col inline (works) | **P2** | `MobileFilterSheet` (Sheet bottom) |
| C6 | Header/toolbar action overflow | `PageHeader`/`EntityToolbar` action rows | app-wide | Buttons overflow | **P2** | `MobileActionBar` + overflow menu |
| C7 | **Bespoke desktop-only workspaces** | fixed multi-pane / drag-drop layouts | **~2–4 surfaces** | Panes crammed/collapsed; task impossible | **P0** | **Per-surface redesign** (not a shared-component swap) — see §4.3 |

### 4.2 Per-module representative inventory

**Legend — Mobile today:** ⛔ invisible (P0) · ▤ h-scroll table (P1) · ▥ clipped form (P1/P2) · ✅ card/mobile-native · ⚠ usable-but-suboptimal (P2).

#### Finance (F1–F5) — **worst-affected module**
| Page | Type | Desktop pattern | Mobile today | Sev | Target pattern |
|---|---|---|---|---|---|
| `accounts-receivable-page` | List/ledger | UniversalDataGrid | ⛔ | P0 | MobileDataCard (amount-forward) |
| `accounts-payable-page` | List/ledger | UniversalDataGrid | ⛔ | P0 | MobileDataCard |
| `chart-of-accounts-page` | Tree/list | UniversalDataGrid | ⛔ | P0 | MobileDataCard (indented) |
| `journals-page` | List | UniversalDataGrid | ⛔ | P0 | MobileDataCard + MobileDetailSheet |
| `financial-statements-page` | Report | UniversalDataGrid | ⛔ | P0 | MobileReportHeader + grouped rows |
| `budgets-page` | List | UniversalDataGrid | ⛔ | P0 | MobileDataCard |
| `tax-vat-page` | List/report | UniversalDataGrid | ⛔ | P0 | MobileReportHeader |
| `cash-banking-page` | Workspace | UniversalDataGrid + `<table>` | ⛔/▤ | P0 | ResponsiveDataView |
| `fiscal-closing-page` | Workspace | UniversalDataGrid + `<table>` | ⛔/▤ | P0 | Operational workspace pattern (§16) |
| ledger drawers (customer/supplier/journal) | Detail drawer | `<table>` in Sheet (full-width mobile) | ▤ | P1 | MobileDetailSheet rows |

#### Commerce — Orders / Products / Customers
| Page | Type | Desktop | Mobile today | Sev | Target |
|---|---|---|---|---|---|
| `orders-page` (`order-table`) | List | UniversalDataGrid + card | ✅ | OK | Normalize to shared card |
| `products` (`product-table`) | List | UniversalDataGrid + `product-mobile-card` | ✅ | OK | Reference implementation |
| `manual-order-form` | Form | `<table>` line editor | ▥ | P1 | Single-column form + line cards |
| `order-lines-editor` | Editor | `<table>` | ▤ | P1 | Line-item card editor |
| `customers-page` / `customer-drawer` | List + drawer | `<table>` | ▤ | P1 | ResponsiveDataView + MobileDetailSheet |
| `crm-customers-workspace-page` | Workspace | UniversalDataGrid | ⛔ | P0 | MobileDataCard |
| `crm-executive-workspace-page` | Exec dashboard | UniversalDataGrid | ⛔ | P0 | KPI cards + MobileDataCard |
| `shipping-pricing-page` | Table | `<table>` | ▤ | P1 | ResponsiveDataView |

#### Logistics / Shipping / Distribution
| Page | Type | Desktop | Mobile today | Sev | Target |
|---|---|---|---|---|---|
| `dispatch-board-page` | Operational | UniversalDataGrid | ⛔ | P0 | Operational workspace (§16) |
| `fuel-review-page` | List | UniversalDataGrid | ⛔ | P0 | MobileDataCard |
| distribution-workspace: `group-detail-section`, `group-detail-drawer`, `group-loading-execution`, `zones-review-table`, `group-loading-preparation` | Workspace components | UniversalDataGrid | ⛔ | P0 | Operational workspace + MobileDetailSheet |
| `drivers-page`, `vehicles-page`, `carrier-accounts-page`, `shipping-companies-page` | Lists | `<table>` | ▤ | P1 | ResponsiveDataView |
| `trips-table`, `trip-routing-tab`, dispatch panels | Operational | `<table>` | ▤ | P1 | Operational workspace |
| `delivery-page` / `delivery-drawer`, `fleet-*` | Mixed | `<table>` | ▤ | P1 | ResponsiveDataView / MobileDetailSheet |

#### Operations / Preparation (partly done)
| Page | Type | Desktop | Mobile today | Sev | Target |
|---|---|---|---|---|---|
| `wave-orders-page` | Workspace | UniversalDataGrid + card | ✅ | OK | Reference |
| `wave-missing-materials-page` | Workspace | UniversalDataGrid + card | ✅ | OK | Reference |
| `wave-product-demand-page` | Workspace | UniversalDataGrid + card | ✅ | OK | Reference |
| `deficit-decisions-page` | Workspace | UniversalDataGrid + card | ✅ | OK | Reference |
| `wave-raw-materials-page` | Workspace | UniversalDataGrid **no card** | ⛔ | P0 | MobileDataCard (align w/ siblings) |
| `fulfillment-wave-workspace-page` | Workspace | `<table>` | ▤ | P1 | Operational workspace |
| `wave-archive-page` | List | `<table>` | ▤ | P1 | ResponsiveDataView |

#### Procurement / Receiving / Suppliers
| Page | Type | Desktop | Mobile today | Sev | Target |
|---|---|---|---|---|---|
| `receiving-center-page` | Queue | UniversalDataGrid + card | ✅ | OK | Reference |
| `suppliers-page` | List | UniversalDataGrid **no card** | ⛔ | P0 | MobileDataCard |
| `purchases-page`, `purchase-materials-page`, `material-requests-page` | Lists | `<table>` | ▤ | P1 | ResponsiveDataView |
| `view-purchase-order-page`, `view-goods-receipt-page` | Detail | `<table>` lines | ▤ | P1 | MobileDetailSheet + line cards |
| `purchase-order-lines-editor`, `goods-receipt-lines-editor` | Editor | `<table>` | ▥ | P1 | Line-item card editor |
| `create-purchase-material-wizard` | Wizard | `<table>` | ▥ | P1/P2 | Single-column stepper |

#### Inventory (control / count / ledger / raw materials)
| Page | Type | Desktop | Mobile today | Sev | Target |
|---|---|---|---|---|---|
| `inventory-dashboard-page`, `variance-analytics`, `warehouse-performance`, `abc-classification`, `cycle-count-planner` | Dashboards/reports | `<table>` | ▤ | P1 | MobileReportHeader + cards |
| `stock-ledger-page` | Ledger | `<table>` | ▤ | P1 | ResponsiveDataView |
| `inventory-count-page`, `count-session-drawer`, `waste-investigations`, `warehouse-liability` | Workspace/drawer | `<table>` | ▤ | P1 | Operational workspace / MobileDetailSheet |
| `raw-materials` list + drawers | List + detail | `<table>` | ▤ | P1 | ResponsiveDataView + MobileDetailSheet |

#### Marketing (+ Intelligence)
| Page | Type | Desktop | Mobile today | Sev | Target |
|---|---|---|---|---|---|
| `creative-analytics-page`, `campaign-analytics-page`, `ad-analytics-page` | Analytics | UniversalDataGrid **no card** | ⛔ | P0 | MobileReportHeader + MobileDataCard |
| `campaigns-workspace`, `initiatives-workspace`, `campaign-executive-dashboard`, `marketing-assets` | Workspace | `<table>` | ▤ | P1 | ResponsiveDataView |

#### HR
| Page | Type | Desktop | Mobile today | Sev | Target |
|---|---|---|---|---|---|
| `workforce-*`, `compensation-*`, `performance-*`, `recruitment-*`, `hr-analytics`, `hr-executive`, `leave-requests`, `offers-workspace`, `commission-rules`, `attendance-workspace` | Lists/dashboards/workspaces | `<table>` | ▤ | P1 | ResponsiveDataView / MobileReportHeader |

#### Engineering (internal ops)
| Page | Type | Desktop | Mobile today | Sev | Target |
|---|---|---|---|---|---|
| `engineering-runs-page`, `engineering-findings-page` | Lists | UniversalDataGrid **no card** | ⛔ | P0 | MobileDataCard |
| `ReleaseDashboardPage`, `EnterpriseWorkspacePage`, `InboxPage`, `AgentWorkspacePage`, `TaskDrawer` | Workspace/drawer | `<table>` | ▤ | P1 | Operational workspace / MobileDetailSheet |

#### Recipes / BOM / Cost
| Page | Type | Desktop | Mobile today | Sev | Target |
|---|---|---|---|---|---|
| `recipe-workspace-page`, `recipe-detail-drawer`, `bom-workspace-page` | Workspace/detail | `<table>` | ▤ | P1 | Operational workspace / MobileDetailSheet |
| `cost-pricing-center-page`, `cost-history-page`, `product-cost-drawer` | Workspace/detail | `<table>` | ▤ | P1 | ResponsiveDataView + MobileDetailSheet |

#### Master-data CRUD cluster (all `EntityTable` — class C3a)
One retrofit fixes the whole cluster. All are P1 today (horizontal scroll; actions column off-screen).

| Pages | Module |
|---|---|
| `brands`, `channels`, `business-accounts`, `product-mappings`, `categories` | Commerce master data |
| `companies`, `teams`, `branches` | Organization/admin |
| `warehouses`, `units`, `sync-logs`, `stock-sync-logs` | Inventory master data / logs |
| `supplier-invoices`, `supplier-returns`, `goods-receipts` (list) | Procurement |
| `employees`, `recruitment-workspace` | HR |
| *(retired/unmounted — exclude:* `fulfillments`, `purchase-orders`, `boms` list*)* | — |

#### Admin / Settings / CEP (agent-sweep additions)
| Page | Type | Desktop | Mobile today | Sev | Target |
|---|---|---|---|---|---|
| `/admin/configuration` (+ company/brands) | Settings hub | KPI + card grids | ✅ | OK/P3 | Keep (responsive) |
| `/companies`, `/teams`, `/branches` | Lists | EntityTable | ▤ | P1 | EntityTable retrofit |
| `/settings/branch-coverage` | Master-detail | select + `<table>` + cards | ⚠ | P2 | Card master-detail |
| `/customer-engagement/leads` (`cep-leads`) | List | raw `<table>` in `overflow-hidden` | ⛔/▤ | P1 | Card + tap actions |
| `/executive`, `/organization`, `/dashboard` | Dashboards | responsive KPI grids | ✅ | OK/P2 | Keep (minor hero-strip density on `/dashboard`) |

> **Enrichment note:** §4.2 lists representative, high-traffic pages per module; the three per-module exploration sweeps (Commerce/Procurement/Shipping, Inventory/Operations/Preparation, Finance/Exec/Dashboards/Marketing/CRM/Admin) corroborate the systemic classes and surfaced **two additions to the static pass**: the card-less `EntityTable` mechanism (C3a) and the bespoke desktop-only workspaces (C7, §4.3). No page type falls outside C1–C7. Any page not named inherits its target from its detected class (§4.1). Retired/unmounted routes (`purchase-orders`, `fulfillments`, `boms` list, `stock-transfers`/`packaging`/`consumables`/`semi-finished` placeholders, `inventory-products-workspace`) are **excluded** — they are not live debt.

### 4.3 Bespoke desktop-only workspaces (class C7 — per-surface redesign, not a table swap)

These cannot be fixed by adopting a shared card/table component; each needs its own mobile interaction model. They are **P0** (the core task is impossible on a phone) but **few** (≈2–4), so they form a small dedicated track (§25).

| Surface | Route | Why shared components don't help | Recommended mobile model |
|---|---|---|---|
| **Distribution Board** | `/operations/distribution/board` | Fixed `w-72` OrdersPool + `flex-1` Trips, side-by-side, **drag-and-drop** assignment; no stacking | Stack panes; **tab Orders / Trips**; replace DnD with **tap-to-assign** (select order → choose trip); no business-logic change (same assign action) |
| **Unified Inbox** | `/customer-engagement` | Fixed 3-pane (`w-72` + `flex-1` + `w-64`, `h-[calc(100vh-56px)]`); thread pane → ~0 px at 375px; right sidebar overflows | **List↔thread view switch**: conversation list is the mobile screen; tap → thread screen; contact info in a `Sheet` |
| **Omnichannel Inbox** | `/omnichannel` | Same multi-pane list+thread idiom | Same list→thread drill-down |
| **Cost Pricing Center** | `/inventory/cost-management/price-review` | Literal `min-w-[1600px]` editable pricing spreadsheet (pan ~4 screen-widths to edit one price) | **Card-per-product** with an inline price-edit **sheet** (focused single-value edit) |
| **Loading-OS execution / reconciliation** | `/operations/loading/workspace` | 7-col table with per-row number inputs (pan-and-type) | **Line cards with qty input + save** (reuses §13/§15 editable line-card pattern — partially shareable) |

---

## 5. Severity Classification & Root-Cause Taxonomy

### 5.1 Priority definitions
- **P0 — Data / function inaccessible on mobile.** The user cannot see the data or complete the task on a phone. (Class C1: 25 grid pages render empty.)
- **P1 — Accessible but degraded.** Data reachable via horizontal scroll or zoom; high friction; error-prone on touch. (Classes C3, C4.)
- **P2 — Suboptimal but usable.** Works, but not mobile-native; minor overflow, non-sheet filters, small tap targets. (Classes C5, C6.)
- **P3 — Polish.** Spacing, density, typography scale, iconography consistency.

### 5.2 Root-cause tags
| Tag | Meaning | Classes | Fix locus |
|---|---|---|---|
| **RC-ADOPT** | Capability exists, screen didn't opt in | C1 (25 pages) | Add `renderMobileCard` + shared card |
| **RC-CRUDKIT** | `EntityTable` has no card mode at all | C3a (~22 pages) | **Retrofit `renderMobileCard` onto `EntityTable`** (one fix) |
| **RC-RAWTABLE** | Bypasses the grid primitive entirely | C3b (~111) | Migrate to `ResponsiveDataView` |
| **RC-CLIP** | `overflow-hidden` clips columns (no scroll) | C3c | Card list |
| **RC-HOVERACTION** | `opacity-0 group-hover` actions unreachable on touch | C3c (12 files) | Always-visible tap actions / overflow menu |
| **RC-NOSHARED** | No shared mobile card/sheet/action-bar; each screen hand-rolls | C2, all | Build Workstream-A components |
| **RC-FORMGRID** | Multi-column form/line-editor/spreadsheet assumes width | C4 | Single-column form + line-card editor |
| **RC-FILTER** | Filters are inline, not a mobile sheet | C5 | `MobileFilterSheet` |
| **RC-ACTIONBAR** | Action rows don't wrap/overflow | C6 | `MobileActionBar` |
| **RC-STATE** | Loading/Error/Empty/Zero conflated on raw-table pages | C3 subset | Adopt grid's 4-state contract (§18) |
| **RC-BESPOKE** | Fixed multi-pane / drag-drop desktop layout | C7 (~2–4) | Per-surface redesign (§4.3) |

**Distribution of effort (by root cause):** RC-ADOPT (25 pages), RC-CRUDKIT (~22 pages), and RC-RAWTABLE (~111 files) dominate. RC-CRUDKIT and RC-ADOPT are the cheapest per-page (they ride two component-level fixes); RC-RAWTABLE is volume. All three are **volume, not difficulty**. RC-NOSHARED is the *keystone*: build the shared set + the two retrofits first and the rest become cheap. RC-BESPOKE is the only class needing genuine design per surface — but it is small (≈2–4 screens).

---

## 6. Current-State Findings — Shared Infrastructure (the substrate to build on)

This section documents what *already exists*, because the design deliberately maximizes reuse.

### 6.1 Enterprise app shell — **largely mobile-complete already**
`app-shell.tsx` composes: `AppTopbar` → `ModuleRail` (`hidden md:flex`, tablet+) → `AppSidebar` (`hidden lg:block`, laptop+, collapsible) → `<main>` (with `pb` reserving the 56 px bottom-nav height) → tablet sidebar **overlay** (`Sheet`, `lg:hidden`) → `MobileMenu` (full-screen) → `MobileBottomNav`.
- `MobileBottomNav` (`md:hidden`, `h-14`): pinned **Dashboard / Orders**, **Search**, **More**. Swaps to a driver-specific set inside `/driver/*`.
- `MobileMenu` (`md:hidden`, full-screen dialog): renders `useNavigation().modules` — the **canonical RBAC authority**, same as the desktop rail — as a single-open **accordion**; company + warehouse switchers pinned at top; children come from `moduleNavLinks(mod.items)` (no hardcoded arrays).
- **Verdict:** the nav IA (§7) is a *refinement*, not a build. The gap is content-level, not chrome-level.

### 6.2 `UniversalDataGrid` — the responsive seam exists; adoption is the gap
- Renders **two trees**: `block lg:hidden` card layout and `hidden lg:block` table. The card layout is `renderMobileCard ? mapped-cards : null`. **No `renderMobileCard` ⇒ empty on mobile.**
- Honors **loading (skeleton) / error / empty** distinctly on *both* branches — so pages using the grid inherit a correct 4-state contract for free (§18); raw-table pages do not.
- Desktop overflow strategy: grab-to-scroll + Shift-wheel horizontal scroll, sticky header, pinned columns. Good on desktop; irrelevant on mobile (cards).
- **Binary breakpoint at `lg` (1024).** No distinct tablet tier: 768–1023 px gets cards *and* the module rail. (§20 addresses whether to introduce a compact-table tablet tier.)

### 6.2b `EntityTable` (CRUD kit) — the *other* table, and it has no mobile mode
- `components/crud/entity-table` is the master-data list table (~22 live pages). Despite a "responsive" docstring it renders a plain `ui/table` — **no `renderMobileCard`, no `lg:hidden` fork** — so it is horizontal-scroll-only on mobile, with the pinned actions column off-screen.
- `components/entity/entity-workspace.tsx` (a "standard CRUD workspace" composite) wraps `EntityTable` but has **0 usages** in `features/` — effectively dead; it would not help mobile as-is.
- **Highest-leverage single change:** give `EntityTable` the same dual-layout seam `UniversalDataGrid` has (a `renderMobileCard`, or an auto-card from column defs). One edit lifts the entire master-data cluster.

### 6.2c Components that are *already* mobile-correct (the model to copy)
- `WorkspaceMetricsRow`: KPI **horizontal scroll-strip** on mobile → responsive grid at `sm+`. 
- `WorkspaceContextActions`: primary action stays; secondary actions collapse into an overflow `⋯` menu (`md:hidden`). This is the `MobileActionBar` behavior, already built.
- `SmartToolbar`: supports `hideOnMobile` per action (`hidden sm:flex`) — used by the Wave pages.
- `PageHeader`, `EntityToolbar`, dashboard/intelligence **KPI cards**: stack correctly.
- Reference `renderMobileCard` implementations: the four Wave tabs (product-demand, missing, deficit-decisions, wave-orders — the last is a genuine reconciliation card: PROBLEM→IMPACT→DECISION), Receiving Center, Orders, Products, Driver Settlement. `/marketing/intelligence/reports` is a best-in-class responsive **report** layout (title → generate panel → history list).

### 6.3 Primitives available (no new dependency needed)
- `ui/`: `sheet` (**supports `side="bottom"`**; right/left are `w-full` on mobile, `md:80vw`, `lg:60vw`), `dialog`, `alert-dialog`, `card`, `badge`, `button`, `tabs`, `input`/`select`/`textarea`/`checkbox`/`switch`, `dropdown-menu`, `popover`, `scroll-area`, `skeleton`, `table` (wraps in `overflow-x-auto`, `whitespace-nowrap` cells).
- `crud/`: `PageHeader`, `EntityToolbar`, `FilterPanel`, `SearchInput`, `EmptyState`, `ErrorState`, `Pagination`.
- `ds/`: thin (only `tabs`, `toast-provider`, `quick-stat-card`) — the design system layer is under-built; most screens compose `ui/` + `crud/` directly.
- **No `useIsMobile`/`useMediaQuery`/`useBreakpoint` hook** anywhere. Responsiveness is 100% CSS-breakpoint. (For most cases this is *good* — no hydration flash — but it blocks conditional rendering like "sheet on mobile, inline on desktop," which the filter/detail redesign needs. §22 proposes a minimal `useIsMobile`.)

### 6.4 CRUD scaffolding — responsive but not mobile-native
- `PageHeader`: stacks `flex-col sm:flex-row`; **actions row has no `flex-wrap`** → overflow with 2+ actions (P2).
- `EntityToolbar`: search stacks above actions at `sm`; action row (children + Filters + Refresh + Export, all with text labels) **does not wrap** → overflow on narrow screens (P2).
- `FilterPanel`: inline collapsible, `grid sm:grid-cols-2 lg:grid-cols-3` → single column on mobile. Usable, but pushes content down and is not a dismissible sheet (P2).

### 6.5 Tokens & internationalization — first-class already
- **Tailwind v4** (`@theme` in `src/index.css`; no `tailwind.config.js`). **Default breakpoints** (sm 640 / md 768 / lg 1024 / xl 1280 / 2xl 1536).
- **RTL**: logical spacing tokens `--space-start` / `--space-end`, logical utilities (`ps-`/`pe-`/`start`/`end`), and `data-flip-rtl` on directional icons (e.g., the menu chevron). Arabic/RTL is architecturally supported.
- **Dark mode**: `@custom-variant dark (&:is(.dark *))`; radius scale via `--radius`.
- **i18n**: typed selector `t(($) => $.ns.key)`; en is the type source; en+ar parity required; no Arabic string literals in source.

---

## 7. Target Mobile Information Architecture (Enterprise)

**Principle:** keep the current shell; refine three things.

### 7.1 Bottom navigation (primary)
- Keep the 4/5-slot bar: **Home/Dashboard · Orders · Search · More**. This is the correct pattern (Shopify/Stripe-class apps use ≤5 persistent destinations).
- **Refinement R1 — role-aware pinned slots.** Today the enterprise pinned set is fixed (Dashboard/Orders). Make the two content slots resolve from the user's **primary module** (via `useNavigation().modules[0]` / a `defaultModule` preference) so a warehouse operator gets *Waves*, a finance user gets *AR/AP*, etc. Falls back to Dashboard/Orders. (No RBAC change — reads the same authority.)
- Keep the driver swap (`/driver/*`) exactly as is.

### 7.2 "More" → full module accordion (secondary)
- Keep `MobileMenu` as-is (it already renders the canonical RBAC module tree as a single-open accordion). This *is* the `MobileModuleAccordion` the task envisions.
- **Refinement R2 — recents & search-in-menu.** Add a "Recent" strip at the top (last 3–5 visited pages, client-only) and reuse the global search entry. Optional/P3.

### 7.3 Context header (per-screen)
- Standardize on a **`MobileScreenHeader`** (§8): back affordance (when in a detail/drawer), screen title, one primary action, overflow menu for the rest. Replaces ad-hoc `PageHeader` action rows on mobile.

**IA diagram (enterprise):**
```
┌───────────────────────────── Topbar (brand · company · search · avatar) ──┐
│                                                                            │
│  [main content — cards on <lg, table on lg+]                               │
│                                                                            │
├──────────────────────────── Bottom Nav (md:hidden) ───────────────────────┤
│   Home        Orders*       Search        More                              │
│  (*role-aware content slots)                              → More opens      │
└──────────────────────────────────────────────────  full-screen accordion ─┘
                                                       (RBAC module tree)
```

---

## 8. Header Standard

**`MobileScreenHeader`** (composition over `PageHeader`, mobile branch):
- Row 1: `[← back?]  Title (truncate)                     [＋ primary]  [⋯ overflow]`
- Row 2 (optional): breadcrumbs collapse to **“… / current”** (parent + current only) on `<sm`.
- Subtitle / status chips wrap to their own line.
- **Rule:** at most **one** always-visible primary action on mobile; everything else goes into the `⋯` overflow (a `dropdown-menu` or bottom `Sheet` when >6 items). This fixes the P2 action-overflow across `PageHeader` and `EntityToolbar`.
- Desktop keeps today's `PageHeader` verbatim (no regression).

---

## 9. Responsive Data Table → Card Standard

**The canonical rule (single source of truth):** *every* tabular surface renders through **one** component that owns the responsive switch. That component is `UniversalDataGrid` today; the redesign formalizes a thin wrapper **`ResponsiveDataView`** so raw-table pages have a migration target with the same ergonomics.

Three tiers (introduces the missing tablet tier):
| Tier | Width | Layout |
|---|---|---|
| Desktop | `lg+` (≥1024) | Full table (sticky header, pinned cols) |
| Tablet | `md`–`lg` (768–1023) | **Compact table** (fewer columns via `defaultVisible`) *or* cards — per page density; default cards (current behavior) |
| Mobile | `<md` (<768) | **Card list** (`MobileDataCard`) |

- **Mandate:** `renderMobileCard` becomes **required** for any grid with >3 columns. A lint/CI guard (design-time recommendation) flags a `UniversalDataGrid` **or `EntityTable`** with >3 columns and no `renderMobileCard`.
- **Two table components converge on one seam:** `EntityTable` gets the **same** `renderMobileCard` dual-layout as `UniversalDataGrid` (one retrofit → ~22 master-data pages). Longer term both can share a `ResponsiveDataView` core; short term the retrofit is the cheap win.
- **Raw tables** migrate to `ResponsiveDataView` (which internally is `UniversalDataGrid` + a default card renderer derived from column defs), so C3b pages get cards without hand-writing each one.
- **An auto-card default** (label/value pairs derived from column defs) is recommended for both grid components, so a page with no bespoke card still shows *something legible* instead of an empty box — turning the P0 failure mode into a P2 fallback even before per-page cards are authored.

---

## 10. Mobile Data Card Anatomy (`MobileDataCard`)

A single shared component so all card lists look and behave identically.

```
┌────────────────────────────────────────────┐
│ ▊ TITLE (primary identifier)      [status●] │  ← title + status badge (top-right)
│   secondary line (muted)                    │  ← subtitle / code / date
│                                             │
│   Label      Value      Label      Value    │  ← 2-col metadata grid (key facts)
│   Label      Value                          │
│                                             │
│   [primary action]              [⋯ more]    │  ← ≤1 primary + overflow; whole card tappable → detail
└────────────────────────────────────────────┘
```
- **Props (conceptual):** `title`, `subtitle?`, `status?: {label,tone}`, `fields: {label,value}[]`, `primaryAction?`, `overflowActions?`, `onOpen?`, `selection?` (checkbox support, mirrors grid).
- **Rules:** amount-forward for financial rows (put the money value large, right-aligned, tabular-nums); status is a `badge` with a tone token; whole card is the tap target for "open detail"; explicit actions never occupy more than one row.
- **RTL:** logical alignment only (`text-start`/`text-end`); the 2-col grid mirrors automatically.

---

## 11. Filter / Search / Sort Standard

- **Search:** promote to the `MobileScreenHeader` or a full-width row directly under it (already the case in `EntityToolbar`). Keep debounced `SearchInput`.
- **Filters → `MobileFilterSheet`** (`Sheet side="bottom"`): the Filters button opens a bottom sheet containing the same controls `FilterPanel` renders inline on desktop; footer has **Clear** + **Apply (n)**. On desktop, unchanged inline `FilterPanel`. Selection between the two uses the minimal `useIsMobile` hook (§22).
- **Active filters:** show removable **chips** under the header on all sizes (discoverability + one-tap remove).
- **Sort:** on mobile, expose sort as a small `dropdown-menu`/sheet ("Sort by ▾") since column-header sort is unavailable in card mode. Reuses the grid's existing `onSortChange`.

---

## 12. Action Hierarchy & Mobile Action Bar (`MobileActionBar`)

- **Screen-level:** one primary action in the header; the rest in `⋯` (§8).
- **Bulk/selection actions:** when rows are selected, a **sticky bottom `MobileActionBar`** appears above the bottom nav (`fixed`, `bottom-14`) showing "n selected" + the 1–2 most common bulk actions + overflow. Mirrors the grid's existing `selection` API.
- **Record-level (in detail/sheet):** primary action pinned to the sheet footer (`SheetFooter` already `flex-wrap justify-end` + `border-t`); destructive actions separated and confirmed via `alert-dialog`.
- **Rule:** never rely on hover; every action reachable by tap; 44×44 px minimum target (§17).

---

## 13. Detail / Record View Pattern (`MobileDetailSheet`)

- Detail today = a `Sheet side="right"` that is already **full-width on mobile** — the chrome is fine; the **content** (raw `<table>` line-items, dense two-column facts) is the problem.
- **`MobileDetailSheet`** standardizes the *content*: a header (title + status + primary action), then **stacked sections** (facts as label/value pairs, line-items as **line cards** not a table), then a footer action bar. Tabs (`ds/tabs`) for multi-section records (Overview / Lines / History / Documents).
- **Line-items rule:** a `<table>` of order/PO/GR/journal lines becomes a **vertical list of line cards** on mobile (SKU/qty/price stacked), each expandable for detail. This is the single most impactful C3 remedy (it covers every `*-lines-editor` and `view-*` page).

---

## 14. Reports Standard (`MobileReportHeader`)

- Reports/analytics (Finance statements, Marketing analytics, Inventory dashboards, HR analytics) get a **`MobileReportHeader`**: title, **period/scope selector** (the report's dimensions), and **KPI summary cards** (reuse `ds/quick-stat-card`) *above* the data.
- The tabular body follows §9 (cards on mobile). Charts get `overflow-x-auto` with a min-width and a caption; never shrink a chart below legibility.
- **Export** (§ export): on mobile, export is an action in the header overflow; the export itself is server-generated (download is a browser action, no client-side table scraping).

---

## 15. Forms Standard

- **Single-column by default** on `<md`; multi-column only `md+`. No fixed-width field grids on mobile (fixes C4/RC-FORMGRID).
- Full-width inputs; labels above inputs; native mobile input types/keyboards; grouped sections with clear headings; sticky footer for **Save/Cancel** in long forms.
- **Wizards** (e.g., `create-purchase-material-wizard`): one step per screen on mobile, progress indicator, back/next pinned to footer.
- **Line-item editors** (order/PO/GR lines): the desktop `<table>` editor becomes an **add-line flow + editable line cards** on mobile (§13 line cards, editable).

---

## 16. Operational Workspace Pattern

Operational screens (Waves, Distribution, Dispatch, Driver Closing, Fiscal Closing, Inventory Count) are **task surfaces**, not lists. They follow a fixed **four-band vertical rhythm** on mobile:

```
① CONTEXT      — what am I looking at? (wave #, date, scope, company/warehouse)
② CURRENT STATE— the numbers that matter now (KPI cards: counts, totals, readiness)
③ EXCEPTIONS   — what needs attention (blockers, shortages, variances) — surfaced, not buried
④ NEXT ACTION  — the one thing to do next (primary CTA), with secondary actions in overflow
```
- This is exactly the shape the recent Driver Closing and Wave workspaces already trend toward; the standard makes it explicit and reusable.
- Detail drill-downs use `MobileDetailSheet`; tabular sub-lists use `MobileDataCard`.
- **No business-logic change** — this reorganizes *presentation* of existing data/actions only.

---

## 17. Touch & Accessibility Standard

- **Targets:** ≥44×44 px for all interactive elements (bottom nav items already `flex-1` tall; audit small icon buttons).
- **Focus & semantics:** every actionable card exposes a button/link role; sheets are `aria-modal` with a title (already the case for `MobileMenu`/`Sheet`).
- **Contrast:** tokenized; verify status badge tones meet AA in both themes.
- **Motion:** respect `prefers-reduced-motion` for sheet transitions.
- **Screen reader:** card lists use `role="list"`/`listitem` (grid already sets `role="list"` on the mobile branch).
- **No hover-only affordances** (§12).

---

## 18. Loading / Error / Empty / Zero — Four Distinct States

The task is explicit: **Loading ≠ Error ≠ Empty ≠ Zero.**
- **Loading:** skeletons that match the card/table shape (the grid already does this on both branches).
- **Error:** `ErrorState` with a retry affordance — never an empty list.
- **Empty (no data yet / filtered to nothing):** `EmptyState` with guidance ("no results for these filters — clear filters?").
- **Zero (a *meaningful* business zero):** distinct copy — e.g., "0 shortages — wave is fully covered" is a *success*, not an absence. Zero states must be authored per operational surface (they carry meaning).
- **Mandate:** raw-table pages (C3) currently hand-roll (or omit) these — migrating to `ResponsiveDataView` gives them the grid's 4-state contract automatically; **Zero** copy is added per §16 workspace.

---

## 19. RTL / Arabic Standard

- Already architecturally supported (logical tokens, `data-flip-rtl`, en/ar parity discipline). The redesign **inherits** it:
  - All new components use logical utilities only (`ps/pe/ms/me/start/end`), never `left/right`.
  - Directional icons carry `data-flip-rtl`.
  - Card 2-col grids, sheets, and action bars mirror automatically.
  - Numerals: keep Western digits for financial tabular data unless locale dictates otherwise (existing convention); ensure `tabular-nums` for alignment.
- **New i18n keys** (card labels, filter sheet, sort menu, zero-states) land in **both** en and ar; en is the type source. No Arabic literals in source.

---

## 20. Breakpoints & Design Tokens

- **Keep Tailwind v4 defaults** (sm 640 / md 768 / lg 1024 / xl 1280). No custom breakpoints needed.
- **Formalize the three tiers** (§9): `<md` mobile (cards), `md–lg` tablet (compact table or cards), `lg+` desktop (table). This means the grid's current single `lg` switch stays for the card decision; tablet compaction is achieved via **column `defaultVisible`** (already supported), not a new breakpoint.
- **Tokens to add (design-time):** `--tap-target-min: 44px`; status-tone tokens (`--tone-success/-warning/-danger/-info` fg/bg) for consistent badges; a `--space-gutter` for card padding. All additive; no token renames.
- **`useIsMobile` hook** (§22) reads `md` (768) via `matchMedia` for the few cases that must branch in JS (filter sheet vs inline, detail sheet composition).

---

## 21. Visual System

- **Density:** cards use comfortable density on mobile (12–14 px padding, 14 px body); tables keep current compact density on desktop.
- **Typography:** title `text-lg`/`text-xl` on mobile screen headers (down from desktop `text-2xl` to avoid wrapping); tabular-nums for money.
- **Color/status:** one badge system (tone tokens), used by both `MobileDataCard` and desktop cells.
- **Elevation:** cards use `border` + subtle `bg-card`; sheets use existing shadow scale.
- **Iconography:** lucide, sized `size-4`/`size-5`; consistent metaphors (already largely consistent).
- **Brand:** ECOS visual identity retained; reference apps (Shopify/Stripe/WP) inform *patterns*, never branding (§6 of task).

---

## 22. Shared Component Architecture (conceptual specifications)

The **keystone** deliverable. Build these first (Workstream A); everything else is adoption. All are compositions of existing primitives — **no new runtime dependency**.

| Component | Built on | Responsibility | Consumers |
|---|---|---|---|
| **`useIsMobile()`** | `matchMedia('(max-width: 767px)')` | Single JS breakpoint signal (SSR-safe default) | Filter/detail branching |
| **`MobileDataCard`** | `ui/card`, `ui/badge`, `dropdown-menu` | Standard row-as-card (§10) | All C1/C2/C3 lists |
| **`ResponsiveDataView`** | wraps `UniversalDataGrid` | Table on `lg+`, auto-card on `<lg` from column defs; default card = `MobileDataCard` | Raw-table (C3) migration target |
| **`MobileFilterSheet`** | `Sheet side="bottom"` | Filters in a dismissible sheet + Apply/Clear + chips (§11) | Every filtered list |
| **`MobileActionBar`** | `fixed` bar + `dropdown-menu` | Sticky selection/bulk actions above bottom nav (§12) | Lists with selection |
| **`MobileDetailSheet`** | `Sheet side="right"` + `ds/tabs` | Standard record detail: facts + line cards + footer actions (§13) | All detail/view/drawer pages |
| **`MobileReportHeader`** | `ds/quick-stat-card` + scope selector | Report scope + KPI cards above data (§14) | All reports/analytics |
| **`MobileScreenHeader`** | wraps `PageHeader` | Back + title + 1 primary + overflow (§8) | All pages (mobile branch) |
| **`MobileModuleAccordion`** | *already exists* as `MobileMenu` | RBAC module nav | Shell (done) |

**Two component-level retrofits (not new components, but the highest-leverage changes):**
| Retrofit | Target | Effect |
|---|---|---|
| Add `renderMobileCard` (+ auto-card default) | `UniversalDataGrid` | 25 P0 pages get cards; empty-box failure mode removed platform-wide |
| Add `renderMobileCard` dual-layout | `EntityTable` (CRUD kit) | ~22 master-data pages fixed by one change |

**Out of shared-component scope (needs bespoke redesign — §4.3):** Distribution Board (tap-to-assign), Unified Inbox & omnichannel (list↔thread), Cost Pricing spreadsheet (card + edit sheet). These consume the shared components where useful (cards, sheets) but their *layout/interaction* is per-surface.

**Design invariants for all shared components:**
1. Presentation only — they receive data + callbacks; **no data fetching, no business rules**.
2. RTL-safe (logical utilities), theme-safe (tokens), i18n via typed selectors.
3. Desktop path unchanged — mobile components render only `<md`/`<lg`; the existing desktop tree is untouched, guaranteeing **coexistence** (§28).

---

## 23. Representative Wireframes

ASCII wireframes for the 9 required surfaces (mobile, `<md`, RTL mirrors automatically).

### 23.1 Enterprise navigation (bottom nav + More)
```
 ┌───────────────────────────────┐        ┌───────────────────────────────┐
 │ ☰  ECOS      🔍   ⌂  (avatar)  │        │  ✕   ECOS                     │
 ├───────────────────────────────┤        ├───────────────────────────────┤
 │ [ screen content — cards ]     │        │ [Company ▾]      [Warehouse ▾] │
 │                               │  tap   ├───────────────────────────────┤
 │                               │  More  │ WORKSPACES                     │
 │                               │  ───▶  │ ▊ Commerce            ▸        │
 │                               │        │ ▊ Operations          ▾        │
 ├───────────────────────────────┤        │     · Waves                    │
 │  ⌂     🧾      🔍      ⋯       │        │     · Distribution             │
 │ Home  Orders  Search  More     │        │ ▊ Finance             ▸        │
 └───────────────────────────────┘        └───────────────────────────────┘
```

### 23.2 Dashboard
```
┌───────────────────────────────┐
│ Dashboard                      │
│ Today · All companies ▾        │
├───────────────────────────────┤
│ ┌───────────┐ ┌───────────┐    │  KPI cards (quick-stat-card),
│ │ Orders    │ │ Revenue   │    │  2-up on mobile, tap → drill
│ │  128  ▲12%│ │ EGP 84k   │    │
│ └───────────┘ └───────────┘    │
│ ┌───────────┐ ┌───────────┐    │
│ │ Shortages │ │ Deliveries│    │
│ │   3  ⚠    │ │  41 / 52  │    │
│ └───────────┘ └───────────┘    │
├───────────────────────────────┤
│ Needs attention                │  exceptions band (§16 ③)
│ • Wave W-118 has 3 shortages ▸ │
│ • 2 payments awaiting verify ▸ │
└───────────────────────────────┘
```

### 23.3 Generic list / table (C1/C3 target)
```
┌───────────────────────────────┐
│ ← Suppliers          ＋   ⋯    │
│ 🔍 Search suppliers…           │
│ [Filters (2) ▾]  [Sort ▾]      │   → Filters opens bottom sheet
│ ‹ active ✕ › ‹ Cairo ✕ ›       │   active-filter chips
├───────────────────────────────┤
│ ┌───────────────────────────┐ │
│ │ Nile Foods Co.     [active●]│ │  MobileDataCard
│ │ SUP-0012 · Cairo           │ │
│ │ Balance   EGP 12,400        │ │
│ │ Terms     Net 30            │ │
│ │ [Open]                 ⋯    │ │
│ └───────────────────────────┘ │
│ ┌───────────────────────────┐ │
│ │ Delta Packaging   [hold ●] │ │
│ │ …                          │ │
└───────────────────────────────┘
```

### 23.4 Detail / record view (`MobileDetailSheet`)
```
┌───────────────────────────────┐
│ ← PO-2041            [Approve] │
│ Nile Foods · Approved ●        │
│ [Overview] [Lines] [History]   │  ds/tabs
├───────────────────────────────┤
│ Supplier   Nile Foods Co.      │  facts (label/value)
│ Date       2026-08-20          │
│ Total      EGP 48,200          │
│ Received   Partially           │
├───────────────────────────────┤
│ LINES (Lines tab)              │
│ ┌───────────────────────────┐ │  line cards, not a table
│ │ RM-food-flour              │ │
│ │ 200 kg × EGP 30 = 6,000    │ │
│ │ received 120 / 200         │ │
│ └───────────────────────────┘ │
├───────────────────────────────┤
│ [Approve]            [⋯ more]  │  sticky footer action bar
└───────────────────────────────┘
```

### 23.5 Operational workspace (four-band, §16)
```
┌───────────────────────────────┐
│ ← Wave W-118          ⋯        │  ① CONTEXT
│ 2026-08-29 · Main WH · ECOS    │
├───────────────────────────────┤
│ ┌────────┐┌────────┐┌────────┐ │  ② CURRENT STATE
│ │Orders  ││Prepared││Readiness│ │
│ │  52    ││ 39/52  ││  75%   │ │
│ └────────┘└────────┘└────────┘ │
├───────────────────────────────┤
│ ⚠ EXCEPTIONS (3)               │  ③ EXCEPTIONS
│ • flour short by 40kg      ▸   │
│ • 2 orders awaiting stock  ▸   │
├───────────────────────────────┤
│ NEXT: [ Release prepared (39) ]│  ④ NEXT ACTION
│        [ Review demand ]  ⋯    │
└───────────────────────────────┘
```

### 23.6 Report / analytics (`MobileReportHeader`)
```
┌───────────────────────────────┐
│ ← Financial Statements    ⋯    │
│ Period: Aug 2026 ▾  Scope: ▾   │  scope selector
├───────────────────────────────┤
│ ┌───────────┐ ┌───────────┐    │  KPI summary
│ │ Revenue   │ │ Net Income│    │
│ │ EGP 1.2M  │ │ EGP 210k  │    │
│ └───────────┘ └───────────┘    │
├───────────────────────────────┤
│ P&L                            │  grouped rows (cards), not a
│ ▸ Revenue          1,200,000   │  wide table; expandable groups
│ ▸ COGS              (640,000)   │
│ ▸ Operating Exp.    (350,000)  │
│   = Net Income        210,000  │
└───────────────────────────────┘
```

### 23.7 Form (single-column, §15)
```
┌───────────────────────────────┐
│ ← New Manual Order      ✕      │
├───────────────────────────────┤
│ Customer                       │
│ [ search customer…        ▾ ]  │
│ Channel                        │
│ [ Manual                  ▾ ]  │
│ ── Lines ────────────────────  │
│ ┌───────────────────────────┐ │  editable line cards
│ │ Product  [ … ▾ ]           │ │
│ │ Qty [  2 ]  Price [ 120 ]  │ │
│ │                     [🗑]    │ │
│ └───────────────────────────┘ │
│ [＋ Add line]                  │
├───────────────────────────────┤
│ [ Save order ]        [Cancel] │  sticky footer
└───────────────────────────────┘
```

### 23.8 Preparation (wave preparation, operator)
```
┌───────────────────────────────┐
│ ← Prepare · W-118       ⋯      │
│ Product demand ▾  (Active)     │  tab: Overview/Products/Materials
├───────────────────────────────┤
│ ┌───────────────────────────┐ │
│ │ ECOS-FG-koshari            │ │  MobileDataCard per product
│ │ demand 40 · prepared 28    │ │
│ │ [▓▓▓▓▓▓░░░] 70%            │ │  progress
│ │ [ Prepare +12 ]        ⋯   │ │
│ └───────────────────────────┘ │
│ ┌───────────────────────────┐ │
│ │ ECOS-FG-molokhia   ⚠ short │ │
│ │ demand 22 · prepared 10    │ │
│ └───────────────────────────┘ │
└───────────────────────────────┘
```

### 23.9 Driver Closing (operator settlement — enterprise side, already card-based)
```
┌───────────────────────────────┐
│ ← Driver Closing        ⋯      │
│ [Active] History               │
├───────────────────────────────┤
│ ┌───────────────────────────┐ │  DaySettlement driver card
│ │ Ahmed · TRP-277            │ │
│ │ Expected  EGP 4,200        │ │
│ │ Collected EGP 4,050        │ │
│ │ Variance  −150  ⚠          │ │
│ │ Movements 3 pending        │ │
│ │ [ Review ]             ⋯   │ │
│ └───────────────────────────┘ │
├───────────────────────────────┤
│ (tap Review → MobileDetailSheet:│
│  timeline · reconciliation ·   │
│  damage/shortage · readiness)  │
└───────────────────────────────┘
```

---

## 24. Driver App — Separate Assessment (do **not** merge)

- The Driver App uses **`DriverShell`** (mobile-first: its own bottom nav Home/Loading/Orders/Vehicle + menu sheet), a sibling of `AppShell`, base `/driver`. It is **already the exemplar** of the target mobile patterns: card lists, bottom sheets, status badges, camera-first receipt, distinct loaded/empty/error states, operational Home command center.
- **Decision (per task):** keep the two shells separate. The enterprise redesign **borrows Driver's patterns** (cards, sheets, four-band operational rhythm) but does **not** unify the shells, routing, or nav. Driver stays mobile-first; enterprise stays responsive-desktop-first with a strong mobile mode.
- The **shared components** (§22) are shell-agnostic and *may* be adopted by Driver later to reduce its bespoke card code — but that is optional and out of this task's rollout.

---

## 25. Rollout Plan — Medium Workstreams (not page-by-page)

Seven workstreams. **A is the keystone** (build shared components); B–F are **adoption** in severity order; G is hardening. Each is a shippable increment; old and new coexist throughout (§28).

| WS | Name | Content | Depends on | Rough size |
|---|---|---|---|---|
| **A** | **Shared mobile components + two retrofits** | `useIsMobile`, `MobileDataCard`, `ResponsiveDataView`, `MobileFilterSheet`, `MobileActionBar`, `MobileDetailSheet`, `MobileReportHeader`, `MobileScreenHeader` + tokens (§20); **retrofit `renderMobileCard` (+ auto-card default) onto `UniversalDataGrid` and `EntityTable`** + examples | — | Medium |
| **B** | **Finance mobile** (P0) | Adopt cards on all 9 Finance grid pages + ledger detail sheets + report headers | A | Medium |
| **C** | **CRM + Marketing Intelligence + Suppliers + Engineering + master-data CRUD cluster** (P0/P1) | Adopt cards on remaining C1 grids; author cards for the ~22 `EntityTable` pages (rides the WS-A retrofit) | A | Medium |
| **D** | **Logistics/Distribution/Dispatch** (P0/P1) | Operational workspace pattern + cards on dispatch/fuel/distribution-workspace group components + wave-raw-materials | A | Medium–Large |
| **E** | **Detail/line-editor migration** (P1) | `view-*` + `*-lines-editor` → `MobileDetailSheet` + line cards (Orders/PO/GR/Fulfillment/Journal); Loading-OS & inventory-count reconciliation line-card editors | A | Medium |
| **F** | **Forms + reports + remaining lists** (P1/P2) | Single-column form standard; `ResponsiveDataView` for remaining raw tables; report headers (Inventory/HR/Marketing) | A | Medium |
| **G** | **Filters/actions/a11y/RTL hardening** (P2/P3) | `MobileFilterSheet` everywhere; action overflow; **remove `opacity-0 group-hover` touch traps**; 44px targets; RTL & dark spot-checks; zero-state copy | A–F | Medium |
| **H** | **Bespoke surfaces** (P0, small) | Distribution Board tap-to-assign; Unified Inbox + omnichannel list↔thread; Cost Pricing card+edit-sheet. **No business-logic change** (same actions/endpoints) | A | Medium (design-heavy, low volume) |

**Sequencing rationale:** A unlocks everything (components + the two retrofits that make B–D cheap). B is first because Finance is 100% P0 and high-value. C clears the remaining P0 grids *and* the CRUD-kit cluster (both ride WS-A). D–F are P1 volume. G is cross-cutting polish (incl. the touch-unreachable action fix). H is small but design-heavy and can run in parallel with B–F once A lands. Each WS ends with tsc (`-p tsconfig.app.json`), en/ar parity, and mobile-viewport verification of its pages.

---

## 26. Business-Architecture Non-Impact Statement

This redesign is **presentational**. It does **not** alter, and must not alter:
- Fulfillment engine / order status writes (still FulfillmentEngine-only).
- Inventory, reservations (ADR-027), custody, or availability engines.
- Wave lifecycle, membership, cutoff, or carry-over rules.
- Settlement / driver-closing authorities, returns/reconciliation.
- Accounting / GL posting, subledgers, dimensions.
- RBAC authorities (`useNavigation()` remains the single canonical nav filter).
- API contracts, DTOs, or persistence.

Every shared component receives already-fetched data and existing callbacks. No component fetches data or encodes a business rule. The desktop code paths are preserved unchanged.

---

## 27. Migration & Coexistence Strategy

- **Additive, not destructive.** Mobile components render only below the breakpoint; the existing desktop tree stays. A page "migrated" to `ResponsiveDataView` keeps its exact desktop table. No duplicate business logic — the same data/handlers feed both branches.
- **Per-page opt-in.** A page joins the redesign by (a) supplying `renderMobileCard`/switching to `ResponsiveDataView`, or (b) wrapping its detail in `MobileDetailSheet`. Un-migrated pages keep today's behavior (P0/P1) until their workstream — no big-bang.
- **No route duplication.** Same routes, same components; only the sub-`lg` rendering changes.
- **Safety net:** a design-time CI guard flags new `UniversalDataGrid(>3 cols)` without `renderMobileCard` so the P0 class cannot grow while the backlog burns down (Ratchet, never cliff).
- **Verification per WS:** tsc, en/ar parity, and read-only mobile-viewport inspection of the WS's pages before it's considered done.

---

## 28. Report Compliance & Structure

This report satisfies the task's required sections: audit scope/method (§2–§3), page inventory (§4), severity + root cause (§5), current-state infra (§6), reference-informed IA (§7), header (§8), table→card (§9), data card (§10), filter/search/sort (§11), actions (§12), detail (§13), reports (§14), forms (§15), operational workspaces (§16), touch/a11y (§17), 4-state loading/error/empty/zero (§18), RTL/Arabic (§19), breakpoints/tokens (§20), visual system (§21), shared components (§22), wireframes (§23), Driver separation (§24), rollout (§25), business non-impact (§26), migration/coexistence (§27), and this compliance section (§28), followed by risks/decisions (§29) and methodology appendix (§30).

---

## 29. Risks, Open Questions & CTO Decisions Needed

| # | Item | Recommendation |
|---|---|---|
| Q1 | **Tablet tier**: cards or compact table at 768–1023? | Default **cards** (current behavior); allow per-page compact table via `defaultVisible`. |
| Q2 | **Role-aware bottom-nav slots** (R1) — desired, or keep fixed Dashboard/Orders? | Implement R1 (reads existing RBAC; low risk, high value). |
| Q3 | **`useIsMobile` hook** — acceptable to introduce (first JS breakpoint signal)? | Yes; minimal, SSR-safe; needed for filter/detail branching. |
| Q4 | **CI guard** for `renderMobileCard` on wide grids — enable now (design-time ratchet)? | Yes; prevents P0 regrowth. |
| Q5 | **Scope of E (line-editors)** — editable line cards are the most complex piece; time-box? | Start read-only (`view-*`) then editors; split E if needed. |
| Q6 | **Driver component sharing** — adopt shared set in Driver later? | Defer; out of this rollout (§24). |
| Q7 | **`EntityTable` retrofit vs migrate to `ResponsiveDataView`** — which path for the CRUD kit? | **Retrofit** now (one change, ~22 pages, zero migration risk); converge on `ResponsiveDataView` later. |
| Q8 | **Auto-card default** on both grids (label/value from columns) — ship it so un-migrated pages degrade to P2 not P0? | Yes — it removes the empty-box failure mode immediately, before per-page cards are authored. |
| Q9 | **Bespoke track (WS-H)** — approve the tap-to-assign / list↔thread / edit-sheet redesigns for the ~2–4 C7 surfaces? | Approve as a small dedicated track; confirm no business-logic change. |
| R1 | Report is from **static analysis + 3 per-module read-only sweeps** (authoritative for systemic classes); live screenshots optional | Approve method; request screenshots only if desired (§30). |

---

## 30. Appendix — Detection Methodology & Evidence

**Primary evidence (static, reproducible):**
- `UniversalDataGrid` consumers: **33 files** (grep `<UniversalDataGrid`).
- Of those, supply `renderMobileCard`: **8 pages** (driver-settlement, receiving-center, wave-orders, wave-missing-materials, wave-product-demand, deficit-decisions, product-table, order-table). ⇒ **25 P0** grid pages.
- `EntityTable` (CRUD kit) footprint: **~26 files** referencing it, **~22 live pages** (excl. retired `fulfillments`/`purchase-orders`/`boms` list and infra files); **no card mode** in `components/crud/entity-table/index.tsx`. `components/entity/entity-workspace.tsx` = **0 feature usages** (dead composite).
- Hand-rolled `<table>`: **~112 files** (grep lowercase `<table`), incl. the `ui/table` primitive; ~111 feature files. Explicit wide spreadsheets: `min-w-[1600px]` (cost-pricing), `min-w-[800px]` (stock-ledger), plus supplier-360 drawer.
- Touch-unreachable actions: `opacity-0 group-hover` in **12 files** (cost-pricing, count-session-drawer, inline-cost-editor, organization-workspace, order-location-cell, notes-tab, trip-card, custody-panel, campaign-studio, brand-shipping-tab, egypt-geography, brand-configuration); `overflow-hidden`-clipped list on customers-page.
- `useIsMobile`/`useMediaQuery`/`useBreakpoint`: **0 files** (only `matchMedia` in theme-provider + login).
- Charting: **`recharts`** used by 3 marketing-intelligence pages (width-fluid `ResponsiveContainer`); no charting elsewhere (CSS/SVG bars).
- Already-correct mobile components: `WorkspaceMetricsRow` (scroll-strip), `WorkspaceContextActions` (overflow `⋯` `md:hidden`), `SmartToolbar` (`hideOnMobile`), `PageHeader`/`EntityToolbar` (stack), dashboard KPI cards.
- `ui/` primitives incl. `sheet` (with `side="bottom"`), `dialog`, `card`, `badge`, `tabs`; `ds/` = 3 files; `crud/` = page-header, entity-toolbar, filter-panel, search-input, empty/error/pagination.
- Theme: Tailwind v4 `@theme` in `src/index.css`; default breakpoints; RTL logical tokens (`--space-start`/`--space-end`); dark via `@custom-variant`.
- Shell: `app-shell.tsx`, `mobile-menu.tsx`, `mobile-bottom-nav.tsx`, `module-rail.tsx`, `app-sidebar.tsx`, `driver-shell.tsx` (read in full).
- Retired/unmounted (excluded from debt): `purchase-orders`, `fulfillments`, `boms` list, `stock-transfers`/`packaging`/`consumables`/`semi-finished` placeholders, `inventory-products-workspace`.

**Method note:** primary evidence is static source analysis (exhaustive counts, source-deterministic), corroborated by **three parallel read-only per-module exploration sweeps** (Commerce/Procurement/Shipping; Inventory/Operations/Preparation; Finance/Executive/Dashboards/Marketing/CRM/Admin), which added the `EntityTable` (C3a) and bespoke-workspace (C7) classes.

**Live mobile-viewport inspection:** authorized as read-only inspection (no mutation). Not required for the systemic findings above (they are source-deterministic); available as optional CTO-facing screenshot evidence on request.

**Reference apps consulted for patterns only** (no branding copied): Shopify admin (bottom nav + card lists + bottom-sheet filters), Stripe dashboard (report scope + KPI-first), WordPress/WooCommerce mobile admin (list→detail sheet). Patterns informed §7–§21; ECOS visual identity is retained.

---

## DESIGN STATUS: READY FOR CTO REVIEW

A unified ECOS Mobile UX architecture is specified end-to-end:
- **Quantified audit:** 25 P0 grid pages (`UniversalDataGrid` without `renderMobileCard`), ~22 P1 master-data pages (`EntityTable` has no card mode), ~111 raw-`<table>` P1 files (incl. `min-w-[1600px]` spreadsheets and `overflow-hidden`/hover-action touch traps), and ~2–4 bespoke desktop-only P0 workspaces — against an app with no `useIsMobile` hook and no shared mobile component layer.
- **Tractable remedy:** a small shared-component set + **two component-level retrofits** (`UniversalDataGrid` and `EntityTable`) that cascade across the bulk of the debt, all buildable on existing primitives (`Sheet side="bottom"`, cards, tokens) — **no new dependency, no business-logic change**. RTL/Arabic, dark mode, and the enterprise nav shell are already in place.
- **Full specification:** page/pattern standards for every surface type (§7–§21), nine representative wireframes (§23), a conceptual shared-component architecture (§22), an eight-workstream coexistence-safe rollout in severity order (§25), and an explicit business-architecture non-impact statement (§26).

**No implementation was performed; no source, DEV data, route, or business architecture was changed.** Open decisions for the CTO are consolidated in §29 (tablet tier, role-aware nav, `useIsMobile`, auto-card default, `EntityTable` retrofit path, bespoke-surface track, CI ratchet). Awaiting CTO approval to begin Workstream A.

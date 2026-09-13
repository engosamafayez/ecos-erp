# ECOS Component Library Roadmap

**Source:** Frontend Component Audit — Foundation Sprint 01/02  
**Method:** Full audit of `frontend/src/components/` and `frontend/src/features/*/components/`

> **Superseded in part (2026-09-12, TASK-ECOS-V1.1-CORE-01-UI-01-CANONICAL-FOUNDATION-045):**
> this document predates a second component generation (`components/data-grid`, `page`, `workspace`,
> `entity`, `form` — all introduced 2026-06-29 onward) and does not mention it. The canonical
> foundation decision — `components/crud/*` + `components/ui/*`, with `UniversalDataGrid` and
> `WorkspaceHeader`/`WorkspaceBreadcrumbs`/`WorkspaceMetricCard` promoted in — plus the full
> canonical/compatibility-only/deprecated status of every component in both generations, now lives in
> `docs/ui/CANONICAL-FOUNDATION-CONTRACT.md` and the "Compatibility/Deprecation Matrix" section of
> `TASK-ECOS-V1.1-CORE-01-UI-01-CANONICAL-FOUNDATION-045-REPORT.md`. Treat those as authoritative
> over the per-component notes below where they conflict; this file is kept for its still-accurate
> narrative of the original `crud/` kit.

---

## Current State

### Status Legend

| Status | Meaning |
|--------|---------|
| ✅ Already reusable | Generic, no domain dependencies, safe to import anywhere |
| 📦 Needs extraction | Has domain logic but the core pattern is generic |
| ♻️ Duplicated | Same pattern exists elsewhere; should be consolidated |
| 🔧 Needs refactor | Poor structure, or mixes concerns |

---

## A. `components/crud/` — Enterprise CRUD Kit

All 14 components are **✅ Already reusable**.

| Component | Purpose |
|-----------|---------|
| `PageHeader` | Breadcrumbs + title + subtitle + actions slot |
| `EntityTable` | Generic data table with sorting, loading, empty/error states |
| `EntityToolbar` | Search + filters + action buttons combined toolbar |
| `EntityDrawer` | Side-sheet with header + body + sticky footer |
| `EntityForm` + `FormField` | React Hook Form wrapper + labeled field component |
| `SearchInput` | Debounced search with clear button |
| `FilterPanel` | Collapsible filter container |
| `Pagination` | Previous/Next page navigation |
| `EmptyState` | Placeholder for empty lists |
| `LoadingState` | Loading skeleton placeholder |
| `ErrorState` | Error message card |
| `ConfirmDialog` | Confirmation modal for destructive actions |
| `Combobox` | Searchable dropdown select |
| `StatusBadge` | Generic status badge (active/inactive/pending/archived) |
| `ActionMenu` | Row-level dropdown menu (View, Edit, Delete) |

---

## B. `components/ds/` — Design System Primitives

| Component | Status | Notes |
|-----------|--------|-------|
| `QuickStatCard` | ✅ Already reusable | KPI card with icon, value, click-to-filter |
| `Tabs` | ✅ Already reusable | Tab navigation wrapper |
| `ToastProvider` | ✅ Already reusable | App-level toast context |
| `useToast` / `useToastStore` | ✅ Already reusable | Toast hook |

---

## C. `components/ecos/` — Domain-Shared Components

| Component | Status | Notes |
|-----------|--------|-------|
| `PhoneCell` | ✅ Already reusable | Phone + Call/WhatsApp/Copy dropdown. No i18n dep. |
| `SyncBadge` | ✅ Already reusable | 4-state sync status badge |
| `tokens.ts` | ✅ Already reusable | Design token constants |
| `index.ts` | ✅ Already reusable | Barrel: re-exports crud + ds + ecos |

---

## D. `components/layout/`

| Component | Status | Notes |
|-----------|--------|-------|
| `app-shell.tsx` | ✅ Already reusable | App wrapper |
| `app-topbar.tsx` | ✅ Already reusable | Top navigation bar |
| `app-sidebar.tsx` | ✅ Already reusable | Left sidebar |
| `app-breadcrumbs.tsx` | ✅ Already reusable | Breadcrumb nav |
| `user-menu.tsx` | ✅ Already reusable | User dropdown |
| `workspace-nav.tsx` | ✅ Already reusable | Module workspace navigator |
| `company-switcher.tsx` | ✅ Already reusable | Workspace selector |
| `workspace-card.tsx` | ✅ Already reusable | Card in workspace picker |

---

## E. `features/products/components/`

| Component | Status | Recommendation |
|-----------|--------|---------------|
| `product-table.tsx` | 📦 Needs extraction | Extract column-resize + density pattern → `ecos/data-table-advanced` |
| `product-detail-drawer.tsx` | 📦 Needs extraction | Extract multi-tab drawer shell → `ecos/detail-drawer` |
| `product-form-drawer.tsx` | 📦 Needs extraction | Replace with EntityDrawer + EntityForm pattern |
| `product-filter-bar.tsx` | 📦 Needs extraction | Extract filter-bar builder → `ecos/filter-bar` |
| `product-quick-stats.tsx` | 📦 Needs extraction | Extract 5-card stats row → `ecos/quick-stats-row` |
| `product-empty-state.tsx` | ♻️ Duplicated | Use generic `EmptyState` from crud |
| `category-select.tsx` | 📦 Needs extraction | Move → `ecos/selects/category-select` |
| `unit-select.tsx` | 📦 Needs extraction | Move → `ecos/selects/unit-select` |
| `products-view.tsx` | 📦 Needs extraction | Embedded/headless products view |
| **Badges:** | | |
| `badges/channel-badge.tsx` | ✅ Already reusable | Move → `ecos/channel-badge` |
| `badges/sync-badge.tsx` | ✅ Already reusable | Re-exports from `ecos/sync-badge` (done) |
| `badges/stock-status-badge.tsx` | ✅ Already reusable | Keep in products (domain-specific) |
| `badges/product-type-badge.tsx` | ✅ Already reusable | Keep in products (domain-specific) |
| `badges/publish-badge.tsx` | ✅ Already reusable | Keep in products (domain-specific) |

---

## F. `features/orders/components/`

| Component | Status | Recommendation |
|-----------|--------|---------------|
| `order-table.tsx` | 📦 Needs extraction | Extract 13-col table pattern |
| `order-detail-drawer.tsx` | 📦 Needs extraction | Extract multi-tab drawer shell |
| `order-form-drawer.tsx` | 📦 Needs extraction | Extract form drawer with live totals |
| `order-status-tabs.tsx` | 📦 Needs extraction | Extract → `ecos/status-tabs` (generic status tab nav) |
| `order-smart-toolbar.tsx` | 📦 Needs extraction | Extract → `ecos/smart-toolbar` (inject ops via props) |
| `order-advanced-filters.tsx` | 📦 Needs extraction | Extract → `ecos/advanced-filter-panel` |
| `order-customer-intelligence.tsx` | 📦 Needs extraction | Extract → `ecos/entity-intelligence-filter` |
| `order-status-badge.tsx` | ✅ Already reusable | Keep in orders (13-state domain-specific) |
| `order-customer-badge.tsx` | 📦 Needs extraction | Extract stats popover → `ecos/entity-stats-popover` |
| `order-phone-cell.tsx` | ✅ Already reusable | Wraps ecos/phone-cell (done) |
| `order-address-cell.tsx` | 📦 Needs extraction | Move → `ecos/address-cell` |
| `order-lines-editor.tsx` | 📦 Needs extraction | Extract → `ecos/line-items-editor` |
| `order-totals-live.tsx` | 📦 Needs extraction | Extract → `ecos/totals-calculator` |

---

## G. Other Feature Modules

| Module | Key Components | Status | Recommendation |
|--------|---------------|--------|---------------|
| Channels | `connection-status-badge`, `platform-badge` | ✅ | Move → `ecos/badges` |
| Purchase Orders | `po-status-badge`, lines-editor, totals | 📦 | Lines editor + totals → `ecos/` |
| Goods Receipts | `gr-status-badge`, `gr-payment-status-badge` | ✅ | Move → `ecos/badges` |
| Stock Ledger | `movement-type-badge` | ✅ | Move → `ecos/badges` |
| Dashboard | `kpi-card`, `quick-actions`, `recent-activity` | 📦 | `kpi-card` → extend `QuickStatCard` |
| Customers | `customer-form-drawer`, `customer-form` | 🔧 | Redesign per UI Package 03 |

---

## Grouped Summary by Category

### Tables

| Component | Status |
|-----------|--------|
| `crud/entity-table` | ✅ |
| `products/product-table` | 📦 extract |
| `orders/order-table` | 📦 extract |
| `orders/order-lines-editor` | 📦 extract |
| `purchase-orders/lines-editor` | 📦 extract |
| `goods-receipts/lines-editor` | 📦 extract (duplicate pattern) |

### Drawers

| Component | Status |
|-----------|--------|
| `crud/entity-drawer` | ✅ |
| `products/product-detail-drawer` | 📦 multi-tab shell |
| `orders/order-detail-drawer` | 📦 multi-tab shell (duplicate) |
| All form drawers | 📦 → use EntityDrawer + EntityForm |

### Inputs

| Component | Status |
|-----------|--------|
| `crud/search-input` | ✅ |
| `crud/combobox` | ✅ |
| `crud/entity-form` + `FormField` | ✅ |
| `products/category-select` | 📦 → `ecos/selects/` |
| `products/unit-select` | 📦 → `ecos/selects/` |
| `warehouses/branch-select` | 📦 → `ecos/selects/` |
| `branches/company-select` | 📦 → `ecos/selects/` |

### Badges

| Component | Status |
|-----------|--------|
| `crud/status-badge` | ✅ |
| `ecos/sync-badge` | ✅ |
| `orders/order-status-badge` | ✅ (domain-specific, keep) |
| `products/badges/*` | ✅ (domain-specific, keep or move to ecos) |
| `channels/connection-status-badge` | 📦 → `ecos/` |
| `channels/platform-badge` | 📦 → `ecos/` |

### Toolbars

| Component | Status |
|-----------|--------|
| `crud/entity-toolbar` | ✅ |
| `orders/order-smart-toolbar` | 📦 → `ecos/smart-toolbar` |
| `products/product-filter-bar` | 📦 → `ecos/filter-bar` |

### Cards

| Component | Status |
|-----------|--------|
| `ds/quick-stat-card` | ✅ |
| `orders/order-totals-live` | 📦 → `ecos/totals-calculator` |

### Phone / Address

| Component | Status |
|-----------|--------|
| `ecos/phone-cell` | ✅ |
| `orders/order-phone-cell` | ✅ (thin wrapper — done) |
| `orders/order-address-cell` | 📦 → `ecos/address-cell` |

---

## Recommended Extraction Sequence

### Phase 1 — Immediate (Foundation Sprint 02)

| Task | Impact |
|------|--------|
| Extract `AddressCell` → `ecos/address-cell.tsx` | Used in orders; customers will need it |
| Extract `StatusTabs` → `ecos/status-tabs.tsx` | Reusable for orders, purchase orders, batches |
| Move `ChannelBadge` → `ecos/channel-badge.tsx` | Used in products; reusable elsewhere |
| Create `useWorkspaceMemory` hook | Persist search/filter/tab/sort per module |

### Phase 2 — Short-term

| Task | Impact |
|------|--------|
| Extract `SmartToolbar` → `ecos/smart-toolbar.tsx` (inject ops) | Orders → Purchase Orders → Batches |
| Extract multi-tab drawer shell → `ecos/detail-drawer.tsx` | Products, Orders, Customers all use same pattern |
| Create `ecos/selects/` (category, unit, branch, company) | Used in Products, Purchase Orders, etc. |
| Extract `EntityStatsPopover` → `ecos/entity-stats-popover.tsx` | Customer badge pattern |

### Phase 3 — Medium-term

| Task | Impact |
|------|--------|
| Extract `LineItemsEditor` → `ecos/line-items-editor.tsx` | Orders, POs, GRs, Fulfillments |
| Consolidate filter builders → `ecos/filters/` | All modules |
| Extract `TotalsCalculator` → `ecos/totals-calculator.tsx` | Orders, POs, GRs |
| Create `ecos/activity-panel.tsx` | Universal activity for all entities |

---

## Duplication Inventory

| Pattern | Occurrences | Recommendation |
|---------|-------------|---------------|
| Multi-tab detail drawer | Products, Orders, Customers | Extract → `ecos/detail-drawer` |
| Line items editor | Orders, POs, GRs, Fulfillments | Extract → `ecos/line-items-editor` |
| Totals calculator | Orders, POs, GRs | Extract → `ecos/totals-calculator` |
| Form drawer (drawer + form) | All CRUD modules | Standardize on EntityDrawer + EntityForm |
| Status badge configs | Orders, POs, GRs, Channels, Inventory | Create status badge factory |
| Empty state per module | Products, Orders | Use generic `EmptyState` from crud |

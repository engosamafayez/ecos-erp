import {
  Activity,
  AlertTriangle,
  ArrowLeftRight,
  BarChart3,
  BookOpen,
  Briefcase,
  Building2,
  CalendarDays,
  ClipboardList,
  Columns3,
  Cpu,
  DollarSign,
  DoorOpen,
  Factory,
  FileSignature,
  FlaskConical,
  Globe,
  LayoutDashboard,
  Layers,
  Layers2,
  Link2,
  ListOrdered,
  ListTree,
  Gauge,
  MapPin,
  Megaphone,
  MessageSquare,
  MessageCircle,
  Monitor,
  Network,
  Package,
  PackageCheck,
  Percent,
  PiggyBank,
  Receipt,
  Wallet,
  Bell,
  History,
  PackageOpen,
  RotateCcw,
  Zap,
  Ruler,
  Settings,
  Shield,
  ShoppingBag,
  Search,
  ShoppingCart,
  Tag,
  Tags,
  Target,
  TrendingUp,
  Truck,
  UserPlus,
  Users as UsersIcon,
  Warehouse,
  SearchCheck,
  GitBranch,
  Wifi,
  ShieldCheck,
  ListChecks,
  BarChart2,
  Bot,
  Wrench,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

import { ROUTES } from '@/router/routes';
import type enCommon from '@/i18n/locales/en/common.json';

/**
 * Navigation display text lives in the `common` namespace. Typing the keys
 * against it makes the translation the source of truth in the compiler as well
 * as at runtime: a nav item whose key has no translation is a build error, not
 * a label silently falling back to English.
 */
export type NavItemKey = keyof (typeof enCommon)['nav']['items'];
export type NavGroupKey = keyof (typeof enCommon)['nav']['groups'];

export type ModuleId =
  | 'dashboard'
  | 'collaboration'
  | 'commerce'
  | 'shipping'
  | 'pos'
  | 'inventory'
  | 'purchasing'
  | 'finance'
  | 'crm'
  | 'customerEngagement'
  | 'omnichannel'
  | 'manufacturing'
  | 'operations'
  | 'marketing'
  | 'core'
  | 'logistics'
  | 'hr'
  | 'reports'
  | 'administration'
  | 'engineering'
  | 'executive';

/**
 * A regular navigation link inside a module sidebar.
 *
 * There is no label field, optional or otherwise. Display text comes from
 * `common.nav.items`, keyed by `key`, and `NavItemKey` is derived from that
 * namespace — so a key without a translation is a compile error, not a label
 * quietly falling back to English.
 */
export type ModuleNavLink = {
  key: NavItemKey;
  path: string;
  icon: LucideIcon;
  isSection?: false;
  /**
   * Path prefix this ONE entry owns for active-module resolution.
   *
   * Normally a nav item owns its own `path` and everything under it. An entry needs a
   * `subtree` only when a module deliberately exposes ONE sidebar link for a section whose
   * other routes are tabs rather than sidebar siblings — then `path` is where the link
   * navigates, and `subtree` is the region that still belongs to this module. Defaults to
   * `path`, so every other item behaves exactly as before.
   */
  subtree?: string;
  /**
   * Canonical permissions that make this ONE entry visible — ANY of them is enough
   * (TASK-ECOS-IAM-FINAL-REMEDIATION-DIRECT-DEV-001, §17).
   *
   * WHY THIS EXISTS. Module visibility was already permission-driven (`isModuleVisible`),
   * but sidebar ITEMS were not: every item of a visible module rendered for everyone. §17
   * is written almost entirely in terms of individual pages — "HIDDEN COMPLETELY: Orders
   * page, Customers page, Products page" for Warehouse Manager, "HIDE detailed
   * Warehouse/Inventory pages" for Warehouse Workers, "Shipping Orders view/follow-up is
   * REQUIRED" for Moderation but "Do NOT grant Distribution planning" — and none of that
   * is expressible at module granularity. A role that needs one page inside a module had
   * to be shown the whole module.
   *
   * The values are CANONICAL permission names, the same tokens the backend route
   * middleware names, so a page's sidebar visibility and its API authorization can never
   * disagree. Crucially this satisfies §18's "Do not hard-code these as role names": no
   * entry mentions a role, only a capability.
   *
   * This is a UX boundary, not a security boundary — every route and every endpoint stays
   * independently permission-gated. Omit the field and the item is always visible, so
   * every entry that does not declare one behaves exactly as it did before.
   */
  permissions?: readonly string[];
};

/**
 * A section header divider (not a clickable link).
 *
 * Sections are rendered only when at least one of the items that FOLLOW them (up to the
 * next section) is visible — otherwise hiding a module's last two entries would leave a
 * dangling header over nothing.
 */
export type ModuleNavSection = {
  key: NavItemKey;
  isSection: true;
};

export type ModuleNavItem = ModuleNavLink | ModuleNavSection;

/**
 * A module in the rail.
 *
 * Display text comes from `common.nav.groups`, keyed by `id`. No label or
 * railLabel field exists: the rail and the sidebar both resolve the same group
 * key, so a second source of text could only ever disagree with the first.
 */
export type AppModule = {
  id: ModuleId;
  icon: LucideIcon;
  defaultPath: string;
  items: ModuleNavItem[];
};

/**
 * Per-item permission gates for §17's page-level visibility contract.
 *
 * ANY listed permission makes the entry visible. Every value is a CANONICAL token that
 * already exists in the `permissions` catalogue and is the same one the corresponding
 * backend route names — so an item can never appear for a user whose API calls it would
 * then reject, and no entry names a role (§18).
 */
const GATE = {
  'orders': ['sales.orders.view'],
  'products': ['inventory.products.view'],
  'customers': ['crm.customers.view'],
  'crm-leads': ['crm.sales.view'],
  'crm-pipeline': ['crm.sales.view'],
  'crm-my-work': ['crm.sales.view'],
  'inv-dashboard': ['inventory.stock.view'],
  'raw-materials': ['inventory.raw_materials.view'],
  'recipes': ['inventory.recipes.view'],
  'price-review': ['cost.price_review.view', 'inventory.price_review.view'],
  'stock-ledger': ['inventory.stock.view'],
  'inventory-count': ['inventory.count.view'],
  'waste-investigations': ['inventory.waste.view'],
  'warehouse-liabilities': ['inventory.liabilities.view'],
  'categories': ['inventory.categories.view'],
  'units': ['inventory.units.view'],
  'wave-workspace': ['preparation.waves.view', 'operations.preparation.view'],
  'logistics-distribution-plan': ['logistics.distribution.view'],
  'loading-workspace': ['loading.session.view'],
  'shipping-orders': ['logistics.shipping.view'],
  'driver-day-settlement': ['finance.driver.view'],
  'logistics-shipping-companies': ['logistics.carriers.view'],
  'logistics-vehicles': ['logistics.vehicles.view'],
  'logistics-drivers': ['logistics.drivers.view'],
  'egypt-geography': ['logistics.geography.view', 'geography.zones.view'],
  'logistics-distribution-zones': ['geography.zones.view', 'logistics.geography.view'],
  'logistics-carriers': ['logistics.carriers.view'],
  'logistics-automation': ['dispatch.manage'],
  'logistics-intelligence': ['delivery.analytics.view'],
  'logistics-fuel-review': ['fleet.fuel.record', 'fleet.view'],
  'logistics-fleet': ['fleet.view'],
  'logistics-dispatch': ['dispatch.view'],
  'logistics-dispatch-exec': ['dispatch.release'],
  'logistics-dispatch-board': ['dispatch.monitoring.view'],
  'logistics-operations': ['operations.view'],
  'logistics-ops-dashboards': ['operations.view'],
  'logistics-ops-alerts': ['operations.alert.manage'],
  'logistics-ops-activity': ['operations.audit.view'],
  'logistics-ops-readiness': ['operations.capacity.reserve', 'operations.view'],
  'logistics-enterprise': ['operations.view'],
  'logistics-delivery': ['delivery.view'],
  // Shipping OS Redesign (TASK-ECOS-SHIPPING-OS-REDESIGN-001) — six approved workspaces.
  // Each reuses the permission(s) already gating the canonical pages/data it composes;
  // no new permission slug was invented for this task.
  'shipping-control-tower': ['operations.view'],
  'shipping-dispatch-execution': ['logistics.distribution.view', 'loading.session.view'],
  'shipping-live-driver-map': ['logistics.distribution.view'],
  'shipping-returns-settlement': ['finance.driver.view', 'logistics.distribution.view'],
  'shipping-fleet-configuration': ['logistics.vehicles.view', 'logistics.drivers.view'],
  'procurement-hub': ['purchasing.purchases.view'],
  'suppliers': ['purchasing.suppliers.view'],
  'purchases': ['purchasing.purchases.view'],
  'supplier-invoices': ['purchasing.supplier_invoices.view'],
  'receiving-center': ['purchasing.receiving.view'],
  'supplier-returns': ['purchasing.supplier_returns.view'],
  'finance-executive': ['finance.executive.workspace.view'],
  'finance-coa': ['finance.coa.manage'],
  'finance-journals': ['finance.journal.create', 'finance.journal.approve'],
  'finance-statements': ['finance.reports.view'],
  'finance-ar': ['finance.ar.view'],
  'finance-ap': ['finance.ap.view'],
  'finance-treasury': ['finance.cash.view', 'finance.bank.view'],
  'finance-fiscal': ['finance.closing.workspace.view', 'finance.period.manage'],
  'finance-budgets': ['finance.budget.view'],
  'finance-tax': ['finance.tax.manage', 'finance.vat.view'],
  'finance-expenses': ['finance.expense.view'],
  'finance-costing': ['finance.cost_allocation.view', 'cost.cost_management.view'],
  'crm-customers': ['crm.customers.view'],
  'crm-executive': ['crm.executive.view'],
  'omni-inbox': ['omnichannel.inbox.view'],
  'omni-dashboard': ['omnichannel.inbox.view'],
  'omni-providers': ['omnichannel.providers.view'],
  'omni-macros': ['omnichannel.macros.view'],
  'omni-routing': ['omnichannel.routing_rules.view'],
  'mkt-dashboard': ['marketing.workspace.view'],
  'mkt-initiatives': ['marketing.initiatives.view'],
  'mkt-init-exec': ['marketing.initiatives.view'],
  'mkt-campaigns': ['marketing.campaigns.view'],
  'mkt-camp-dash': ['marketing.campaigns.view'],
  'mkt-assets': ['marketing.assets.view'],
  'mkt-connect': ['marketing.meta.view', 'marketing.providers.view'],
  'studio': ['marketing.studio.view'],
  'studio-dash': ['marketing.studio.view'],
  'studio-gov': ['marketing.workspace.manage'],
  'automation': ['marketing.automation.view'],
  'automation-segs': ['marketing.segments.view'],
  'automation-dash': ['marketing.automation.view'],
  'automation-gov': ['marketing.workflows.view'],
  'cep-inbox': ['cep.inbox.view'],
  'cep-dashboard': ['cep.inbox.view'],
  'cep-leads': ['cep.leads.view'],
  'bae-journey': ['bae.attribution.view'],
  'bae-timeline': ['bae.timeline.view'],
  'hr-workforce': ['hr.workforce.view'],
  'hr-employees': ['hr.employees.view'],
  'hr-org-chart': ['hr.org.view'],
  'hr-structure': ['hr.org.manage', 'hr.org.view'],
  'hr-attendance': ['hr.attendance.view'],
  'hr-leave': ['hr.leave.view'],
  'hr-comp': ['hr.compensation.view'],
  'hr-commission': ['hr.commission.view'],
  'hr-explain': ['hr.compensation.view'],
  'hr-perf': ['hr.performance.view'],
  'hr-recruit': ['hr.recruitment.view'],
  'hr-offers': ['hr.offers.view'],
  'hr-tags': ['hr.recruitment.tags.manage'],
  'hr-recruit-analytics': ['hr.recruitment.analytics.view'],
  'hr-exits': ['hr.exit.view'],
  'hr-exec': ['hr.executive.view'],
  'hr-analytics': ['hr.analytics.view'],
  'organization': ['organization.companies.view'],
  'companies': ['organization.companies.view'],
  'brands': ['organization.brands.view'],
  'business-accounts': ['organization.business_accounts.view'],
  'channels': ['sales.channels.view'],
  'warehouses': ['inventory.warehouses.view'],
  'branches': ['organization.branches.view'],
  'branch-coverage': ['organization.branches.view'],
  'teams': ['organization.teams.view'],
  'iam-management': ['iam.users.view', 'iam.roles.view', 'iam.role-templates.view'],
  'settings': ['configuration.settings.view'],
  'configuration-os': ['configuration.settings.view', 'configuration.company.view'],
  // TASK-...-026 — the single high-impact permission gating the whole Go-Live surface (§2 of
  // that task: one permission, not several, since every action behind it is part of the same
  // one-time operational event).
  'golive': ['admin.golive.manage'],
  'product-mappings': ['sales.channels.view'],
  'sync-logs': ['sales.channels.view'],
  'reports-board': ['reports.sales.view', 'reports.inventory.view', 'reports.finance.view', 'reports.procurement.view', 'reports.distribution.view', 'reports.customers.view', 'reports.products.view', 'reports.preparation.view', 'reports.drivers.view', 'reports.executive.view'],
} as const satisfies Record<string, readonly string[]>;

const ALL_MODULES: AppModule[] = [
  {
    id: 'dashboard',
    icon: LayoutDashboard,
    defaultPath: ROUTES.dashboard,
    items: [],
  },
  {
    // Internal Collaboration & Tasks (TASK-ECOS-COLLABORATION-WORKSPACE-DRIVER-EXPOSURE-
    // CLOSURE-005). A cross-cutting utility every employee uses, not a business-domain
    // module — its backend authorizes conversations/messages/tasks by participation and
    // ownership, not by a `collaboration.*` permission grant (no Role Template grants one
    // today), so there is no permission domain the standard MODULE_DOMAINS fallback could
    // gate on. It is deliberately in `ALWAYS_VISIBLE` (use-navigation.ts) alongside
    // `dashboard` rather than listed here in MODULE_DOMAINS. Conversations and Tasks are
    // tabs of the ONE workspace route (query-param driven), not separate sidebar entries.
    id: 'collaboration',
    icon: MessageSquare,
    defaultPath: ROUTES.collaborationWorkspace,
    items: [],
  },
  {
    // The Executive Platform board. Read-only, and every panel it draws is
    // already permission-gated per domain inside the page — a viewer who holds
    // none of those permissions sees the board with no panels, so the module
    // gate below decides whether the entry appears at all.
    id: 'executive',
    icon: Gauge,
    defaultPath: ROUTES.executiveDashboard,
    items: [{ key: 'executive-board', path: ROUTES.executiveDashboard, icon: LayoutDashboard }],
  },
  {
    id: 'pos',
    icon: Monitor,
    defaultPath: ROUTES.pos,
    items: [],
  },
  {
    id: 'commerce',
    icon: ShoppingBag,
    defaultPath: ROUTES.orders,
    items: [
      { key: 'orders', path: ROUTES.orders, icon: ShoppingBag, permissions: GATE['orders'] },
      { key: 'products', path: ROUTES.products, icon: Package, permissions: GATE['products'] },
      // CRM-01 Task 1 — the legacy Customers workspace is retired from navigation;
      // /crm/customers (below, under the `crm` module) is now the single canonical
      // Customer management surface. ROUTES.customers still resolves (redirect —
      // see router.ts) for any existing deep link. See CRM-01 Task 1 report,
      // "Customer UI".
    ],
  },
  {
    id: 'inventory',
    icon: Package,
    defaultPath: ROUTES.inventoryDashboard,
    items: [
      { key: 'inv-dashboard', path: ROUTES.inventoryDashboard, icon: LayoutDashboard, permissions: GATE['inv-dashboard'] },
      { key: 'raw-materials', path: ROUTES.rawMaterials, icon: FlaskConical, permissions: GATE['raw-materials'] },
      { key: 'recipes', path: ROUTES.recipes, icon: ListTree, permissions: GATE['recipes'] },
      { key: 'price-review', path: ROUTES.costManagementPriceReview, icon: SearchCheck, permissions: GATE['price-review'] },
      { key: 'stock-ledger', path: ROUTES.stockLedger, icon: BookOpen, permissions: GATE['stock-ledger'] },
      { key: 'inventory-count', path: ROUTES.inventoryCount, icon: ClipboardList, permissions: GATE['inventory-count'] },
      { key: 'waste-investigations', path: ROUTES.wasteInvestigations, icon: AlertTriangle, permissions: GATE['waste-investigations'] },
      { key: 'warehouse-liabilities', path: ROUTES.warehouseLiabilities, icon: Shield, permissions: GATE['warehouse-liabilities'] },
      // Phase 1.1 — Stock Transfers deferred; restore entry above to re-enable (PKG-TRANSFERS-001)
      // Master Data section
      { key: 'master-data-section', isSection: true },
      { key: 'categories', path: ROUTES.inventoryCategories, icon: Tag, permissions: GATE['categories'] },
      { key: 'units', path: ROUTES.inventoryUnits, icon: Ruler, permissions: GATE['units'] },
    ],
  },
  {
    id: 'operations',
    icon: TrendingUp,
    defaultPath: ROUTES.waveWorkspace,
    // Approved Operations ownership restored (TASK-ECOS-NAV-AND-ROUTING-RECONCILIATION-001;
    // per TASK-LOGISTICS-NAVIGATION-ARCHITECTURE-CLEANUP-002 §5-6): Distribution Planning,
    // Loading Workspace and Driver Day Settlement belong to Operations, not Shipping. The
    // pages/routes/backend already exist — this is a nav-ownership restore only.
    items: [
      // `subtree` is load-bearing (TASK-PREPARATION-UX-FIX-ARCHIVE-MISSING-001): this ONE
      // sidebar link opens Today's Preparation, but Archive and Settings (Wave Engine) are
      // tabs of the same PreparationWorkspaceLayout — not sidebar siblings. Declaring the
      // shell root /operations/preparation as the owned subtree keeps the Operations
      // contextual sidebar mounted across all three tabs and the nested Wave pages.
      { key: 'wave-workspace', path: ROUTES.waveWorkspace, subtree: ROUTES.preparationWorkspace, icon: Layers2, permissions: GATE['wave-workspace'] },
      // Points at the canonical Distribution Workspace redesign (not the retired zone-status
      // planning page); the /logistics/distribution/planning deep link redirects here.
      { key: 'logistics-distribution-plan', path: ROUTES.logisticsDistributionWorkspace, icon: ListOrdered, permissions: GATE['logistics-distribution-plan'] },
      { key: 'loading-workspace', path: ROUTES.loadingOsWorkspace, icon: PackageCheck, permissions: GATE['loading-workspace'] },
      // TASK-ECOS-OPERATIONS-SHIPPING-ORDERS-IMPLEMENTATION-002 — Architecture-001 §32's
      // approved insertion point: immediately before Driver/Day Settlement.
      { key: 'shipping-orders', path: ROUTES.shippingOrders, icon: Truck, permissions: GATE['shipping-orders'] },
      { key: 'driver-day-settlement', path: ROUTES.logisticsDriverSettlement, icon: Wallet, permissions: GATE['driver-day-settlement'] },
    ],
  },
  {
    id: 'shipping',
    icon: PackageCheck,
    // TASK-ECOS-SHIPPING-OS-REDESIGN-001 — approved final Shipping information architecture.
    // Replaces the prior 20-entry sidebar (5 sections: Settings/Carriers/Fleet/Dispatch/
    // Operations/Delivery) with the six approved primary workspaces. Control Tower is the
    // new landing page (management-by-exception), not Shipping Companies.
    //
    // Every removed nav entry below still resolves — either the underlying page is now
    // composed live inside one of the six workspaces (Fleet & Configuration's tabs embed
    // Vehicles/Drivers/Shipping Companies/Carrier Accounts/Fuel/Zones/Geography/Automation
    // verbatim), or its old URL redirects to the workspace that absorbed it (see the
    // "Shipping OS Redesign" redirect block in router.ts for the exact old→new map). No
    // page, route, backend authority or permission was deleted — see
    // E:\ECOS\reports\TASK-ECOS-SHIPPING-OS-REDESIGN-001-REPORT.md for the full
    // old-page → new-workspace reconciliation matrix.
    //
    // Activity & Audit (`logistics-ops-activity`) is deliberately NOT redirected — per the
    // task's own instruction it becomes a contextual history/audit surface rather than
    // primary navigation, and stays linked from Control Tower's Analytics tab instead.
    // Distribution Workspace, Loading Workspace, Trips Workspace and Day Settlement are
    // deliberately NOT redirected either — they remain the full-depth canonical authorities
    // that Dispatch & Execution / Returns & Settlement deep-link into (UI consolidation does
    // not mean domain-authority consolidation — task §4).
    defaultPath: ROUTES.shippingControlTower,
    items: [
      { key: 'shipping-control-tower', path: ROUTES.shippingControlTower, icon: LayoutDashboard, permissions: GATE['shipping-control-tower'] },
      { key: 'shipping-orders', path: ROUTES.shippingOrders, icon: Truck, permissions: GATE['shipping-orders'] },
      { key: 'shipping-dispatch-execution', path: ROUTES.shippingDispatchExecution, icon: Zap, permissions: GATE['shipping-dispatch-execution'] },
      { key: 'shipping-live-driver-map', path: ROUTES.shippingLiveDriverMap, icon: MapPin, permissions: GATE['shipping-live-driver-map'] },
      { key: 'shipping-returns-settlement', path: ROUTES.shippingReturnsSettlement, icon: Wallet, permissions: GATE['shipping-returns-settlement'] },
      { key: 'shipping-fleet-configuration', path: ROUTES.shippingFleetConfiguration, icon: Gauge, permissions: GATE['shipping-fleet-configuration'] },
    ],
  },
  {
    id: 'purchasing',
    icon: Truck,
    defaultPath: ROUTES.procurementHub,
    items: [
      { key: 'procurement-hub', path: ROUTES.procurementHub, icon: LayoutDashboard, permissions: GATE['procurement-hub'] },
      { key: 'suppliers', path: ROUTES.suppliers, icon: Truck, permissions: GATE['suppliers'] },
      // REALIGNMENT-001 §19 — "Material Requests" is no longer part of the approved
      // purchasing journey, so it is not offered as a navigation entry point. The route,
      // page, backend record_type and all historic rows are deliberately UNTOUCHED (no data
      // loss, deep links still resolve); only this leaf is withdrawn from the menu.
      { key: 'purchases', path: ROUTES.purchases, icon: ShoppingCart, permissions: GATE['purchases'] },
      { key: 'supplier-invoices', path: ROUTES.supplierInvoices, icon: DollarSign, permissions: GATE['supplier-invoices'] },
      { key: 'receiving-center', path: ROUTES.receivingCenter, icon: PackageOpen, permissions: GATE['receiving-center'] },
      { key: 'supplier-returns', path: ROUTES.supplierReturns, icon: RotateCcw, permissions: GATE['supplier-returns'] },
    ],
  },
  {
    id: 'finance',
    icon: DollarSign,
    defaultPath: ROUTES.accounting,
    items: [
      { key: 'finance-executive', path: ROUTES.accounting, icon: LayoutDashboard, permissions: GATE['finance-executive'] },
      { key: 'finance-coa', path: ROUTES.financeChartOfAccounts, icon: ListTree, permissions: GATE['finance-coa'] },
      { key: 'finance-journals', path: ROUTES.financeJournals, icon: BookOpen, permissions: GATE['finance-journals'] },
      { key: 'finance-statements', path: ROUTES.financeStatements, icon: BarChart3, permissions: GATE['finance-statements'] },
      { key: 'finance-ar', path: ROUTES.financeReceivables, icon: DollarSign, permissions: GATE['finance-ar'] },
      { key: 'finance-ap', path: ROUTES.financePayables, icon: ShoppingCart, permissions: GATE['finance-ap'] },
      { key: 'finance-treasury', path: ROUTES.financeCashBanking, icon: Wallet, permissions: GATE['finance-treasury'] },
      { key: 'finance-fiscal', path: ROUTES.financeFiscalClosing, icon: CalendarDays, permissions: GATE['finance-fiscal'] },
      { key: 'finance-budgets', path: ROUTES.financeBudgets, icon: PiggyBank, permissions: GATE['finance-budgets'] },
      { key: 'finance-tax', path: ROUTES.financeTaxVat, icon: Percent, permissions: GATE['finance-tax'] },
      { key: 'finance-expenses', path: ROUTES.financeExpenses, icon: Receipt, permissions: GATE['finance-expenses'] },
      { key: 'finance-costing', path: ROUTES.financeCosting, icon: TrendingUp, permissions: GATE['finance-costing'] },
    ],
  },
  {
    id: 'marketing',
    icon: Megaphone,
    defaultPath: ROUTES.marketing,
    items: [
      { key: 'mkt-dashboard', path: ROUTES.marketing, icon: LayoutDashboard, permissions: GATE['mkt-dashboard'] },
      { key: 'mkt-initiatives', path: ROUTES.marketingInitiatives, icon: Briefcase, permissions: GATE['mkt-initiatives'] },
      { key: 'mkt-init-exec', path: ROUTES.marketingInitiativeDash, icon: BarChart3, permissions: GATE['mkt-init-exec'] },
      { key: 'mkt-campaigns', path: ROUTES.marketingCampaigns, icon: TrendingUp, permissions: GATE['mkt-campaigns'] },
      { key: 'mkt-camp-dash', path: ROUTES.marketingCampaignDash, icon: TrendingUp, permissions: GATE['mkt-camp-dash'] },
      { key: 'mkt-assets', path: ROUTES.marketingAssets, icon: Zap, permissions: GATE['mkt-assets'] },
      { key: 'mkt-connect', path: ROUTES.marketingConnectMeta, icon: Link2, permissions: GATE['mkt-connect'] },
      { key: 'studio', path: ROUTES.campaignStudio, icon: Layers, permissions: GATE['studio'] },
      { key: 'studio-dash', path: ROUTES.campaignStudioDashboard, icon: BarChart3, permissions: GATE['studio-dash'] },
      { key: 'studio-gov', path: ROUTES.campaignGovernance, icon: Shield, permissions: GATE['studio-gov'] },
      // Marketing Automation Platform
      { key: 'automation', path: ROUTES.automationWorkspace, icon: GitBranch, permissions: GATE['automation'] },
      { key: 'automation-segs', path: ROUTES.audienceSegments, icon: UsersIcon, permissions: GATE['automation-segs'] },
      { key: 'automation-dash', path: ROUTES.automationDashboard, icon: Activity, permissions: GATE['automation-dash'] },
      { key: 'automation-gov', path: ROUTES.automationGovernance, icon: Shield, permissions: GATE['automation-gov'] },
      { key: 'cep-section', isSection: true },
      { key: 'cep-inbox', path: ROUTES.customerEngagement, icon: MessageSquare, permissions: GATE['cep-inbox'] },
      { key: 'cep-dashboard', path: ROUTES.cepDashboard, icon: LayoutDashboard, permissions: GATE['cep-dashboard'] },
      { key: 'cep-leads', path: ROUTES.cepLeads, icon: UserPlus, permissions: GATE['cep-leads'] },
      { key: 'bae-section-group', isSection: true },
      { key: 'bae-section', isSection: true },
      { key: 'bae-journey', path: ROUTES.businessAttribution, icon: Activity, permissions: GATE['bae-journey'] },
      { key: 'bae-timeline', path: ROUTES.baeTimeline, icon: BarChart3, permissions: GATE['bae-timeline'] },
    ],
  },
  {
    id: 'crm',
    icon: UsersIcon,
    defaultPath: ROUTES.crmMyWork,
    items: [
      // CRM-01 Task 2 — a personal landing page composed from existing
      // Lead/Opportunity/Portfolio/InternalTask authorities (no new ACL).
      { key: 'crm-my-work', path: ROUTES.crmMyWork, icon: LayoutDashboard, permissions: GATE['crm-my-work'] },
      { key: 'crm-customers', path: ROUTES.crmCustomers, icon: UsersIcon, permissions: GATE['crm-customers'] },
      // CRM-01 Task 1 — Lead 360 closure; canonical crm_leads, not CustomerEngagement's cep_leads.
      { key: 'crm-leads', path: ROUTES.crmLeads, icon: Target, permissions: GATE['crm-leads'] },
      // CRM-01 Task 2 — Pipeline board over the already-complete Opportunity backend.
      { key: 'crm-pipeline', path: ROUTES.crmPipeline, icon: Columns3, permissions: GATE['crm-pipeline'] },
      { key: 'crm-portfolio', path: ROUTES.crmPortfolio, icon: ListChecks },
      { key: 'crm-executive', path: ROUTES.crmExecutive, icon: BarChart3, permissions: GATE['crm-executive'] },
    ],
  },
  {
    id: 'manufacturing',
    icon: Factory,
    defaultPath: ROUTES.recipes,
    items: [{ key: 'production-orders', path: ROUTES.recipes, icon: ClipboardList }],
  },
  {
    id: 'omnichannel',
    icon: MessageCircle,
    defaultPath: ROUTES.omnichannelInbox,
    items: [
      { key: 'omni-inbox', path: ROUTES.omnichannelInbox, icon: MessageCircle, permissions: GATE['omni-inbox'] },
      { key: 'omni-dashboard', path: ROUTES.omnichannelDashboard, icon: LayoutDashboard, permissions: GATE['omni-dashboard'] },
      { key: 'omni-config', isSection: true },
      { key: 'omni-providers', path: ROUTES.omnichannelProviders, icon: Wifi, permissions: GATE['omni-providers'] },
      { key: 'omni-macros', path: ROUTES.omnichannelMacros, icon: Zap, permissions: GATE['omni-macros'] },
      { key: 'omni-routing', path: ROUTES.omnichannelRouting, icon: GitBranch, permissions: GATE['omni-routing'] },
    ],
  },
  {
    id: 'logistics',
    icon: Truck,
    defaultPath: ROUTES.logisticsGeography,
    items: [],
  },
  {
    // HR & Workforce OS — EPIC H1 + H2
    id: 'hr',
    icon: UsersIcon,
    defaultPath: ROUTES.hr,
    items: [
      { key: 'hr-workforce', path: ROUTES.hr, icon: Gauge, permissions: GATE['hr-workforce'] },
      { key: 'hr-people', isSection: true },
      { key: 'hr-employees', path: ROUTES.hrEmployees, icon: UsersIcon, permissions: GATE['hr-employees'] },
      { key: 'hr-org-chart', path: ROUTES.hrOrganizationChart, icon: Network, permissions: GATE['hr-org-chart'] },
      { key: 'hr-structure', path: ROUTES.hrStructure, icon: ListTree, permissions: GATE['hr-structure'] },
      { key: 'hr-time', isSection: true },
      { key: 'hr-attendance', path: ROUTES.hrAttendance, icon: ClipboardList, permissions: GATE['hr-attendance'] },
      { key: 'hr-leave', path: ROUTES.hrLeave, icon: CalendarDays, permissions: GATE['hr-leave'] },
      { key: 'hr-pay', isSection: true },
      { key: 'hr-comp', path: ROUTES.hrCompensation, icon: DollarSign, permissions: GATE['hr-comp'] },
      { key: 'hr-commission', path: ROUTES.hrCommissionRules, icon: Zap, permissions: GATE['hr-commission'] },
      { key: 'hr-explain', path: ROUTES.hrCompensationExplainability, icon: Search, permissions: GATE['hr-explain'] },
      { key: 'hr-perf', path: ROUTES.hrPerformance, icon: TrendingUp, permissions: GATE['hr-perf'] },
      { key: 'hr-talent', isSection: true },
      { key: 'hr-recruit', path: ROUTES.hrRecruitment, icon: UserPlus, permissions: GATE['hr-recruit'] },
      { key: 'hr-offers', path: ROUTES.hrOffers, icon: FileSignature, permissions: GATE['hr-offers'] },
      { key: 'hr-tags', path: ROUTES.hrApplicantTags, icon: Tags, permissions: GATE['hr-tags'] },
      { key: 'hr-recruit-analytics', path: ROUTES.hrRecruitmentAnalytics, icon: BarChart2, permissions: GATE['hr-recruit-analytics'] },
      { key: 'hr-exits', path: ROUTES.hrExits, icon: DoorOpen, permissions: GATE['hr-exits'] },
      { key: 'hr-exec', path: ROUTES.hrExecutive, icon: BarChart3, permissions: GATE['hr-exec'] },
      { key: 'hr-analytics', path: ROUTES.hrAnalytics, icon: BarChart2, permissions: GATE['hr-analytics'] },
    ],
  },
  {
    // Reporting V1 (TASK-ECOS-REPORTING-V1-USER-VISIBLE-NAVIGATION-CLOSURE-010).
    // One entry, matching the Executive Platform's own single-item shape — the
    // 10 report categories live inside the Reporting landing page itself, not
    // as separate sidebar items. Visibility is decided by the module gate
    // below (MODULE_DOMAINS.reports, any `reports.*` permission); per-report
    // execution is separately gated inside the page by that report's own
    // `reports.<category>.view` permission.
    id: 'reports',
    icon: BarChart3,
    defaultPath: ROUTES.reports,
    items: [{ key: 'reports-board', path: ROUTES.reports, icon: BarChart3, permissions: GATE['reports-board'] }],
  },
  {
    id: 'administration',
    icon: Settings,
    defaultPath: ROUTES.organization,
    items: [
      { key: 'org-section', isSection: true },
      { key: 'organization', path: ROUTES.organization, icon: Building2, permissions: GATE['organization'] },
      { key: 'companies', path: ROUTES.companies, icon: Building2, permissions: GATE['companies'] },
      { key: 'brands', path: ROUTES.brands, icon: Layers, permissions: GATE['brands'] },
      { key: 'business-accounts', path: ROUTES.businessAccounts, icon: Briefcase, permissions: GATE['business-accounts'] },
      { key: 'channels', path: ROUTES.channels, icon: Globe, permissions: GATE['channels'] },
      { key: 'warehouses', path: ROUTES.warehouses, icon: Warehouse, permissions: GATE['warehouses'] },
      { key: 'branches', path: ROUTES.branches, icon: Building2, permissions: GATE['branches'] },
      { key: 'branch-coverage', path: ROUTES.branchCoverage, icon: MapPin, permissions: GATE['branch-coverage'] },
      { key: 'teams', path: ROUTES.teams, icon: UsersIcon, permissions: GATE['teams'] },
      { key: 'users-section', isSection: true },
      // §4 — ONE IAM entry replacing the duplicate "Users" + "Roles & Permissions" pair.
      // Both used to point at the SAME IamWorkspacePage, differing only in which of its
      // three tabs opened, which is what made them read as two features. `subtree: '/admin'`
      // keeps Users, Roles & Permissions and Role Templates all resolving to this module,
      // so the contextual sidebar stays mounted while moving between the tabs.
      {
        key: 'iam-management',
        path: ROUTES.users,
        subtree: '/admin',
        icon: Shield,
        permissions: GATE['iam-management'],
      },
      { key: 'settings', path: ROUTES.settings, icon: Settings, permissions: GATE['settings'] },
      { key: 'config-section', isSection: true },
      { key: 'configuration-os', path: ROUTES.configurationOs, icon: Cpu, permissions: GATE['configuration-os'] },
      { key: 'golive-section', isSection: true },
      { key: 'golive', path: ROUTES.golive, icon: ListChecks, permissions: GATE['golive'] },
      { key: 'integrations-section', isSection: true },
      { key: 'product-mappings', path: ROUTES.productMappings, icon: Link2, permissions: GATE['product-mappings'] },
      { key: 'sync-logs', path: ROUTES.syncLogs, icon: ArrowLeftRight, permissions: GATE['sync-logs'] },
    ],
  },
  {
    id: 'engineering',
    icon: ShieldCheck,
    defaultPath: ROUTES.engineeringDashboard,
    items: [
      { key: 'eng-ws-section', isSection: true },
      { key: 'eng-workspace', path: ROUTES.engineeringWorkspace, icon: LayoutDashboard },
      { key: 'eng-section', isSection: true },
      { key: 'eng-dashboard', path: ROUTES.engineeringDashboard, icon: BarChart2 },
      { key: 'eng-runs', path: ROUTES.engineeringRuns, icon: ListChecks },
      { key: 'eng-findings', path: ROUTES.engineeringFindings, icon: AlertTriangle },
      { key: 'eng-rm-section', isSection: true },
      { key: 'eng-pipeline', path: ROUTES.engineeringPipeline, icon: Zap },
      { key: 'eng-pipeline-h', path: ROUTES.engineeringPipelineHistory, icon: History },
      { key: 'eng-analytics', path: ROUTES.engineeringAnalytics, icon: BarChart2 },
      { key: 'eng-notify', path: ROUTES.engineeringNotifications, icon: Bell },
      { key: 'eng-releases-section', isSection: true },
      { key: 'eng-releases', path: ROUTES.engineeringReleases, icon: GitBranch },
      { key: 'eng-cluster-section', isSection: true },
      { key: 'eng-cluster', path: ROUTES.engineeringCluster, icon: Cpu },
      { key: 'eng-ai-section', isSection: true },
      { key: 'eng-ai-supervisor', path: ROUTES.engineeringAiSupervisor, icon: Bot },
      { key: 'eng-repair', path: ROUTES.engineeringRepair, icon: Wrench },
    ],
  },
];

/**
 * Modules hidden from navigation for the Commerce & Operations go-live scope
 * (TASK-GOLIVE-BLOCKERS-001, BLOCKER-2). Navigation only — backends/routes are
 * untouched; a direct URL still resolves. Remove an id here once its UI is
 * production-ready.
 *   • finance      — Accounting UI not built (backend F1–F5 complete, no frontend)
 *   • crm          — Advanced CRM UI not built (service/sales/loyalty/intelligence)
 *   • engineering  — AI Platform experimental (no LLM logic wired)
 * (Stock Transfers was already removed from the Inventory sidebar.)
 */
/**
 * Modules hidden from navigation for the go-live shell.
 *
 * Hiding is navigation-only: routes still resolve by direct URL, so nothing is
 * deleted and nothing needs re-registering when a module is brought back.
 *
 *   • pos            — not in the go-live scope
 *   • manufacturing  — not in the go-live scope
 *   • engineering    — internal platform tooling, temporarily withheld
 *   • logistics      — the empty rail entry. Every Logistics workspace lives
 *                      under Shipping; this module carried no items, so it
 *                      rendered as a module with an empty sidebar
 *   • finance        — the module exists here with no items and its UI lives on
 *                      `platform-foundation`. Unhiding it on this branch would
 *                      surface an empty module, which is the orphan this EPIC
 *                      exists to remove. It is unhidden by the integration task,
 *                      not by this one.
 *
 * CRM is deliberately absent: it is now visible and carries its two workspaces.
 */
const HIDDEN_MODULE_IDS: ReadonlySet<ModuleId> = new Set<ModuleId>([
  'pos',
  'manufacturing',
  'engineering',
  'logistics',
]);

/** Navigation-visible modules (hidden ids filtered out for go-live scope). */
export const APP_MODULES: AppModule[] = ALL_MODULES.filter((m) => !HIDDEN_MODULE_IDS.has(m.id));

/**
 * Every navigable link in a module's sidebar. Section headers are dividers, not
 * destinations, so they are excluded. Recovered (TASK-ECOS-MOBILE-UX-CORE-CLOSURE-001)
 * for the mobile menu accordion so it lists a module's authorized child routes from
 * the SAME canonical metadata the desktop sidebar renders — no hardcoded child arrays.
 * Adapted to the current `ModuleNavItem` model (link | section); the preserved
 * pre-reconcile helper assumed an obsolete group/subtree model that no longer exists.
 */
export function moduleNavLinks(
  items: ModuleNavItem[],
  /**
   * Optional permission predicate (§17). When supplied, links the user may not see are
   * excluded — so the mobile launcher's accordion, its page search and its Recent list all
   * respect exactly the same page-level boundary the desktop sidebar does. Omitted, the
   * behaviour is unchanged.
   */
  can?: (permission: string) => boolean,
  overrides?: Record<string, string>,
): ModuleNavLink[] {
  const links = items.filter((item): item is ModuleNavLink => !item.isSection);

  return can ? links.filter((link) => isNavItemVisible(link, can, overrides)) : links;
}

/**
 * True when the current user may see this ONE sidebar link (ANY declared permission) AND
 * the user's role navigation settings don't hide it (User-review remediation, Batch 02,
 * item I/12).
 *
 * `overrides` defaults to `{}` — an absent third argument means "no overrides apply,"
 * identical to this function's behavior before item I existed, so every pre-existing call
 * site is unaffected.
 *
 * CRITICAL SECURITY PROPERTY (item I's own requirement, restated in code): the permission
 * check runs FIRST and can only narrow further — `overrides` is checked with `&&`, never
 * `||`, so a 'visible' override can never show an item the permission gate already refused.
 * This function is UX only; nothing it returns is consulted by any route or API guard.
 */
export function isNavItemVisible(
  item: ModuleNavItem,
  can: (permission: string) => boolean,
  overrides: Record<string, string> = {},
): boolean {
  if (item.isSection) return true;
  const required = item.permissions;
  const permitted = !required || required.length === 0 || required.some((permission) => can(permission));
  return permitted && overrides[item.key] !== 'hidden';
}

/**
 * The items of a module's sidebar the current user may actually see
 * (TASK-ECOS-IAM-FINAL-REMEDIATION-DIRECT-DEV-001, §17).
 *
 * Pure, so it is testable without React and shared by the desktop sidebar and the mobile
 * launcher — the two must never disagree about what a role can see.
 *
 * Section headers survive only when at least one link BETWEEN this header and the next one
 * is visible. Without that, hiding a module's last group would leave a header floating
 * above nothing — which reads as a broken page rather than a withheld one.
 */
export function visibleModuleItems(
  items: ModuleNavItem[],
  can: (permission: string) => boolean,
  overrides: Record<string, string> = {},
): ModuleNavItem[] {
  const kept: ModuleNavItem[] = [];

  for (let index = 0; index < items.length; index += 1) {
    const item = items[index];

    if (!item.isSection) {
      if (isNavItemVisible(item, can, overrides)) kept.push(item);
      continue;
    }

    let hasVisibleChild = false;
    for (let look = index + 1; look < items.length; look += 1) {
      const next = items[look];
      if (next.isSection) break;
      if (isNavItemVisible(next, can, overrides)) {
        hasVisibleChild = true;
        break;
      }
    }

    if (hasVisibleChild) kept.push(item);
  }

  return kept;
}

/** Find the module that owns a given pathname. */
export function findModuleByPath(pathname: string): AppModule | undefined {
  return APP_MODULES.find((m) => {
    if (m.defaultPath === pathname) return true;
    // Each link owns its `subtree` when declared, otherwise its own `path` — so a module
    // whose section routes are tabs (not sidebar siblings) still resolves for the whole
    // subtree. `subtree ?? path` is identical to the old behaviour for every plain item.
    return m.items.some((item) => {
      if (item.isSection) return false;
      const base = item.subtree ?? item.path;
      return pathname === base || pathname.startsWith(base + '/');
    });
  });
}

/**
 * Look up the nav item label for a given pathname.
 *
 * Single source of truth for label lookups — replaces the removed navigation.ts.
 * Used by AppBreadcrumbs and ComingSoonPage.
 *
 * Search order:
 *   1. Exact path match inside each module's sidebar items.
 *   2. Module defaultPath (covers modules with empty items[], e.g. Dashboard, Finance).
 */
export type NavMatch =
  | { kind: 'item'; key: NavItemKey; path: string; icon: LucideIcon }
  | { kind: 'module'; id: ModuleId; path: string; icon: LucideIcon };

export function findNavItemByPath(pathname: string): NavMatch | undefined {
  for (const mod of APP_MODULES) {
    const item = mod.items.find((i): i is ModuleNavLink => !i.isSection && i.path === pathname);
    if (item) return { kind: 'item', key: item.key, path: item.path, icon: item.icon };

    if (mod.defaultPath === pathname) {
      return { kind: 'module', id: mod.id, path: mod.defaultPath, icon: mod.icon };
    }
  }
  return undefined;
}

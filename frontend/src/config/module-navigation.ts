import {
  Activity,
  AlertTriangle,
  ArrowLeftRight,
  BarChart3,
  BookOpen,
  Briefcase,
  Building2,
  ClipboardList,
  Cpu,
  DollarSign,
  Factory,
  FlaskConical,
  Globe,
  LayoutDashboard,
  Layers,
  Layers2,
  Link2,
  ListOrdered,
  ListTree,
  Map,
  Gauge,
  MapPin,
  Megaphone,
  MessageSquare,
  MessageCircle,
  Monitor,
  Network,
  Package,
  PackageCheck,
  PackageOpen,
  Radio,
  RotateCcw,
  Ruler,
  Settings,
  Shield,
  ShoppingBag,
  ShoppingCart,
  Tag,
  TrendingUp,
  Truck,
  UserPlus,
  Users as UsersIcon,
  Warehouse,
  SearchCheck,
  GitBranch,
  Wifi,
  Zap,
  ShieldCheck,
  ListChecks,
  BarChart2,
  Bell,
  Bot,
  History,
  Wrench,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

import { ROUTES } from '@/router/routes';

export type ModuleId =
  | 'dashboard'
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
  | 'reports'
  | 'administration'
  | 'engineering';

/** A regular navigation link inside a module sidebar. */
export type ModuleNavLink = {
  key: string;
  label: string;
  path: string;
  icon: LucideIcon;
  isSection?: false;
};

/** A section header divider (not a clickable link). */
export type ModuleNavSection = {
  key: string;
  label: string;
  isSection: true;
};

export type ModuleNavItem = ModuleNavLink | ModuleNavSection;

export type AppModule = {
  id: ModuleId;
  label: string;
  railLabel: string;
  icon: LucideIcon;
  defaultPath: string;
  items: ModuleNavItem[];
};

export const APP_MODULES: AppModule[] = [
  {
    id: 'dashboard',
    label: 'Dashboard',
    railLabel: 'Home',
    icon: LayoutDashboard,
    defaultPath: ROUTES.dashboard,
    items: [],
  },
  {
    id: 'pos',
    label: 'Point of Sale',
    railLabel: 'POS',
    icon: Monitor,
    defaultPath: ROUTES.pos,
    items: [],
  },
  {
    id: 'commerce',
    label: 'Commerce',
    railLabel: 'Commerce',
    icon: ShoppingBag,
    defaultPath: ROUTES.orders,
    items: [
      { key: 'orders',    label: 'Orders',    path: ROUTES.orders,    icon: ShoppingBag  },
      { key: 'products',  label: 'Products',  path: ROUTES.products,  icon: Package      },
      { key: 'customers', label: 'Customers', path: ROUTES.customers, icon: ShoppingCart },
    ],
  },
  {
    id: 'inventory',
    label: 'Inventory',
    railLabel: 'Inventory',
    icon: Package,
    defaultPath: ROUTES.inventoryDashboard,
    items: [
      { key: 'inv-dashboard', label: 'Dashboard', path: ROUTES.inventoryDashboard, icon: LayoutDashboard },
      { key: 'raw-materials', label: 'Raw Materials', path: ROUTES.rawMaterials, icon: FlaskConical },
      { key: 'recipes', label: 'Recipes', path: ROUTES.recipes, icon: ListTree },
      { key: 'price-review', label: 'Price Review', path: ROUTES.costManagementPriceReview, icon: SearchCheck },
      { key: 'stock-ledger', label: 'Stock Ledger', path: ROUTES.stockLedger, icon: BookOpen },
      { key: 'inventory-count', label: 'Inventory Count', path: ROUTES.inventoryCount, icon: ClipboardList },
      { key: 'waste-investigations', label: 'Waste Investigations', path: ROUTES.wasteInvestigations, icon: AlertTriangle },
      { key: 'warehouse-liabilities', label: 'Warehouse Liabilities', path: ROUTES.warehouseLiabilities, icon: Shield },
      // Phase 1.1 — Stock Transfers deferred; restore entry above to re-enable (PKG-TRANSFERS-001)
      // Master Data section
      { key: 'master-data-section', label: 'Master Data', isSection: true },
      { key: 'categories', label: 'Categories', path: ROUTES.inventoryCategories, icon: Tag },
      { key: 'units', label: 'Units of Measure', path: ROUTES.inventoryUnits, icon: Ruler },
    ],
  },
  {
    id: 'operations',
    label: 'Operations',
    railLabel: 'Ops.',
    icon: TrendingUp,
    defaultPath: ROUTES.waveWorkspace,
    items: [
      { key: 'wave-workspace', label: 'Fulfillment Wave Workspace', path: ROUTES.waveWorkspace, icon: Layers2 },
    ],
  },
  {
    id: 'shipping',
    label: 'Shipping',
    railLabel: 'Shipping',
    icon: PackageCheck,
    defaultPath: ROUTES.fulfillments,
    items: [
      { key: 'fulfillments',                 label: 'Fulfillments',          path: ROUTES.fulfillments,                  icon: PackageCheck },
      { key: 'carriers-section',             label: 'Carriers',              isSection: true },
      { key: 'logistics-shipping-companies', label: 'Shipping Companies',    path: ROUTES.logisticsShippingCompanies,    icon: Truck        },
      { key: 'logistics-drivers',            label: 'Drivers',               path: ROUTES.logisticsDrivers,              icon: UsersIcon    },
      { key: 'logistics-vehicles',           label: 'Vehicles',              path: ROUTES.logisticsVehicles,             icon: Truck        },
      { key: 'fleet-section',                label: 'Fleet',                 isSection: true },
      { key: 'logistics-fleet',              label: 'Fleet Dashboard',       path: ROUTES.logisticsFleet,                icon: Gauge        },
      { key: 'network-section',              label: 'Network',               isSection: true },
      { key: 'logistics-network',            label: 'Service Areas',         path: ROUTES.logisticsNetwork,              icon: Network      },
      { key: 'dispatch-section',             label: 'Dispatch',              isSection: true },
      { key: 'logistics-dispatch',           label: 'Command Center',        path: ROUTES.logisticsDispatch,             icon: Radio        },
      { key: 'geo-section',                  label: 'Geography',             isSection: true },
      { key: 'egypt-geography',              label: 'Egypt Geography',       path: ROUTES.logisticsGeography,            icon: Map          },
      { key: 'dist-section',                 label: 'Distribution',          isSection: true },
      { key: 'logistics-distribution-zones', label: 'Distribution Zones',    path: ROUTES.logisticsDistributionZones,    icon: Network      },
      { key: 'logistics-distribution-plan',  label: 'Distribution Planning', path: ROUTES.logisticsDistributionPlanning, icon: ListOrdered  },
      { key: 'delivery-section',             label: 'Delivery',              isSection: true },
      { key: 'logistics-delivery',           label: 'Delivery & Tracking',   path: ROUTES.logisticsDelivery,             icon: MapPin       },
    ],
  },
  {
    id: 'purchasing',
    label: 'Procurement',
    railLabel: 'Procure',
    icon: Truck,
    defaultPath: ROUTES.procurementHub,
    items: [
      { key: 'procurement-hub', label: 'Procurement Hub', path: ROUTES.procurementHub, icon: LayoutDashboard },
      { key: 'suppliers', label: 'Suppliers', path: ROUTES.suppliers, icon: Truck },
      { key: 'material-requests', label: 'Material Requests', path: ROUTES.materialRequests, icon: ClipboardList },
      { key: 'purchases', label: 'Purchases', path: ROUTES.purchases, icon: ShoppingCart },
      { key: 'supplier-invoices', label: 'Supplier Invoices', path: ROUTES.supplierInvoices, icon: DollarSign },
      { key: 'receiving-center', label: 'Receiving Center', path: ROUTES.receivingCenter, icon: PackageOpen },
      { key: 'supplier-returns', label: 'Supplier Returns', path: ROUTES.supplierReturns, icon: RotateCcw },
    ],
  },
  {
    id: 'finance',
    label: 'Finance',
    railLabel: 'Finance',
    icon: DollarSign,
    defaultPath: ROUTES.accounting,
    items: [],
  },
  {
    id: 'marketing',
    label: 'Marketing OS',
    railLabel: 'Mktg.',
    icon: Megaphone,
    defaultPath: ROUTES.marketing,
    items: [
      { key: 'mkt-dashboard',    label: 'Dashboard',            path: ROUTES.marketing,                icon: LayoutDashboard },
      { key: 'mkt-initiatives',  label: 'Initiatives',          path: ROUTES.marketingInitiatives,     icon: Briefcase },
      { key: 'mkt-init-exec',    label: 'Initiative Dashboard', path: ROUTES.marketingInitiativeDash,  icon: BarChart3 },
      { key: 'mkt-campaigns',    label: 'Campaigns',            path: ROUTES.marketingCampaigns,       icon: TrendingUp },
      { key: 'mkt-camp-dash',    label: 'Campaign Dashboard',   path: ROUTES.marketingCampaignDash,    icon: TrendingUp },
      { key: 'mkt-assets',       label: 'Assets',               path: ROUTES.marketingAssets,          icon: Zap },
      { key: 'mkt-connect',      label: 'Connect Meta',         path: ROUTES.marketingConnectMeta,     icon: Link2 },
      { key: 'studio',           label: 'Campaign Studio',      path: ROUTES.campaignStudio,            icon: Layers },
      { key: 'studio-dash',      label: 'Studio Dashboard',     path: ROUTES.campaignStudioDashboard,  icon: BarChart3 },
      { key: 'studio-gov',       label: 'Governance',           path: ROUTES.campaignGovernance,       icon: Shield },
      // Marketing Automation Platform
      { key: 'automation',       label: 'Automation',           path: ROUTES.automationWorkspace,      icon: GitBranch },
      { key: 'automation-segs',  label: 'Audience Segments',    path: ROUTES.audienceSegments,         icon: UsersIcon },
      { key: 'automation-dash',  label: 'Automation Dashboard', path: ROUTES.automationDashboard,      icon: Activity },
      { key: 'automation-gov',   label: 'Auto Governance',      path: ROUTES.automationGovernance,     icon: Shield },
    ],
  },
  {
    id: 'crm',
    label: 'CRM',
    railLabel: 'CRM',
    icon: UsersIcon,
    defaultPath: ROUTES.crm,
    items: [],
  },
  {
    id: 'manufacturing',
    label: 'Manufacturing',
    railLabel: 'Mfg.',
    icon: Factory,
    defaultPath: ROUTES.recipes,
    items: [
      { key: 'production-orders', label: 'Production Orders', path: ROUTES.recipes, icon: ClipboardList },
    ],
  },
  {
    id: 'customerEngagement',
    label: 'Customer Engagement',
    railLabel: 'Engage',
    icon: MessageSquare,
    defaultPath: ROUTES.customerEngagement,
    items: [
      { key: 'cep-inbox',     label: 'Unified Inbox',  path: ROUTES.customerEngagement, icon: MessageSquare },
      { key: 'cep-dashboard', label: 'Dashboard',      path: ROUTES.cepDashboard,       icon: LayoutDashboard },
      { key: 'cep-leads',     label: 'Leads',          path: ROUTES.cepLeads,           icon: UserPlus },
    ],
  },
  {
    id: 'omnichannel',
    label: 'Omnichannel',
    railLabel: 'Omni',
    icon: MessageCircle,
    defaultPath: ROUTES.omnichannelInbox,
    items: [
      { key: 'omni-inbox',      label: 'Inbox',            path: ROUTES.omnichannelInbox,      icon: MessageCircle },
      { key: 'omni-dashboard',  label: 'Dashboard',        path: ROUTES.omnichannelDashboard,  icon: LayoutDashboard },
      { key: 'omni-config',     label: 'Configuration',    isSection: true },
      { key: 'omni-providers',  label: 'Channel Providers', path: ROUTES.omnichannelProviders, icon: Wifi },
      { key: 'omni-macros',     label: 'Macros',           path: ROUTES.omnichannelMacros,     icon: Zap },
      { key: 'omni-routing',    label: 'Routing Rules',    path: ROUTES.omnichannelRouting,    icon: GitBranch },
    ],
  },
  {
    id: 'core',
    label: 'Core Platform',
    railLabel: 'Core',
    icon: Cpu,
    defaultPath: ROUTES.businessAttribution,
    items: [
      { key: 'bae-section',  label: 'Business Attribution', isSection: true },
      { key: 'bae-journey',  label: 'Journey Explorer',     path: ROUTES.businessAttribution, icon: Activity },
      { key: 'bae-timeline', label: 'Business Timeline',    path: ROUTES.baeTimeline,         icon: BarChart3 },
    ],
  },
  {
    id: 'logistics',
    label: 'Logistics OS',
    railLabel: 'Logistics',
    icon: Truck,
    defaultPath: ROUTES.logisticsGeography,
    items: [],
  },
  {
    id: 'reports',
    label: 'Reports',
    railLabel: 'Reports',
    icon: BarChart3,
    defaultPath: ROUTES.reports,
    items: [],
  },
  {
    id: 'administration',
    label: 'Administration',
    railLabel: 'Admin',
    icon: Settings,
    defaultPath: ROUTES.organization,
    items: [
      { key: 'org-section', label: 'Organization', isSection: true },
      { key: 'organization', label: 'Overview', path: ROUTES.organization, icon: Building2 },
      { key: 'companies', label: 'Companies', path: ROUTES.companies, icon: Building2 },
      { key: 'brands', label: 'Brands', path: ROUTES.brands, icon: Layers },
      { key: 'business-accounts', label: 'Business Accounts', path: ROUTES.businessAccounts, icon: Briefcase },
      { key: 'channels', label: 'Sales Channels', path: ROUTES.channels, icon: Globe },
      { key: 'warehouses', label: 'Warehouses', path: ROUTES.warehouses, icon: Warehouse },
      { key: 'branches', label: 'Branches', path: ROUTES.branches, icon: Building2 },
      { key: 'branch-coverage', label: 'Branch Coverage', path: ROUTES.branchCoverage, icon: MapPin },
      { key: 'teams', label: 'Teams', path: ROUTES.teams, icon: UsersIcon },
      { key: 'users-section', label: 'People & Access', isSection: true },
      { key: 'users', label: 'Users', path: ROUTES.users, icon: UsersIcon },
      { key: 'roles', label: 'Roles & Permissions', path: ROUTES.roles, icon: Shield },
      { key: 'settings', label: 'Settings', path: ROUTES.settings, icon: Settings },
      { key: 'config-section', label: 'Configuration',   isSection: true },
      { key: 'configuration-os', label: 'Configuration OS', path: ROUTES.configurationOs, icon: Cpu },
      { key: 'integrations-section', label: 'External Integrations', isSection: true },
      { key: 'product-mappings', label: 'Product Mapping', path: ROUTES.productMappings, icon: Link2 },
      { key: 'sync-logs', label: 'Sync Logs', path: ROUTES.syncLogs, icon: ArrowLeftRight },
    ],
  },
  {
    id: 'engineering',
    label: 'Engineering OS',
    railLabel: 'Eng OS',
    icon: ShieldCheck,
    defaultPath: ROUTES.engineeringDashboard,
    items: [
      { key: 'eng-ws-section',   label: 'Workspace',            isSection: true },
      { key: 'eng-workspace',    label: 'Engineering Workspace', path: ROUTES.engineeringWorkspace,      icon: LayoutDashboard },
      { key: 'eng-section',      label: 'Certification',        isSection: true },
      { key: 'eng-dashboard',    label: 'Dashboard',            path: ROUTES.engineeringDashboard,       icon: BarChart2 },
      { key: 'eng-runs',         label: 'Certification Runs',   path: ROUTES.engineeringRuns,            icon: ListChecks },
      { key: 'eng-findings',     label: 'Findings',             path: ROUTES.engineeringFindings,        icon: AlertTriangle },
      { key: 'eng-rm-section',   label: 'Release Manager',      isSection: true },
      { key: 'eng-pipeline',     label: 'Release Manager',      path: ROUTES.engineeringPipeline,        icon: Zap },
      { key: 'eng-pipeline-h',   label: 'Pipeline History',     path: ROUTES.engineeringPipelineHistory, icon: History },
      { key: 'eng-analytics',    label: 'Pipeline Analytics',   path: ROUTES.engineeringAnalytics,       icon: BarChart2 },
      { key: 'eng-notify',       label: 'Notifications',        path: ROUTES.engineeringNotifications,   icon: Bell },
      { key: 'eng-releases-section', label: 'Release Orchestrator', isSection: true },
      { key: 'eng-releases',     label: 'Releases',             path: ROUTES.engineeringReleases,        icon: GitBranch },
      { key: 'eng-cluster-section', label: 'Execution',         isSection: true },
      { key: 'eng-cluster',      label: 'Execution Cluster',    path: ROUTES.engineeringCluster,         icon: Cpu },
      { key: 'eng-ai-section',   label: 'AI Supervisor',        isSection: true },
      { key: 'eng-ai-supervisor', label: 'AI Supervisor',       path: ROUTES.engineeringAiSupervisor,    icon: Bot },
      { key: 'eng-repair',       label: 'AI Repair',            path: ROUTES.engineeringRepair,          icon: Wrench },
    ],
  },
];

/** Find the module that owns a given pathname. */
export function findModuleByPath(pathname: string): AppModule | undefined {
  return APP_MODULES.find((m) => {
    if (m.defaultPath === pathname) return true;
    return m.items.some(
      (item) => !item.isSection && (pathname === item.path || pathname.startsWith(item.path + '/')),
    );
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
export function findNavItemByPath(pathname: string): ModuleNavLink | undefined {
  for (const mod of APP_MODULES) {
    const item = mod.items.find(
      (i): i is ModuleNavLink => !i.isSection && i.path === pathname,
    );
    if (item) return item;

    if (mod.defaultPath === pathname) {
      return { key: mod.id, label: mod.label, path: mod.defaultPath, icon: mod.icon };
    }
  }
  return undefined;
}

import {
  AlertTriangle,
  ArrowLeftRight,
  BarChart3,
  BookOpen,
  Briefcase,
  Building2,
  ClipboardList,
  DollarSign,
  Factory,
  FlaskConical,
  Globe,
  LayoutDashboard,
  Layers,
  Link2,
  ListTree,
  Monitor,
  Package,
  PackageCheck,
  PackageOpen,
  RotateCcw,
  Ruler,
  Settings,
  Shield,
  ShoppingBag,
  ShoppingCart,
  Tag,
  TrendingDown,
  TrendingUp,
  Truck,
  Users as UsersIcon,
  Warehouse,
  Waves,
  SearchCheck,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

import { ROUTES } from '@/router/routes';

export type ModuleId =
  | 'dashboard'
  | 'commerce'
  | 'pos'
  | 'inventory'
  | 'purchasing'
  | 'finance'
  | 'crm'
  | 'manufacturing'
  | 'operations'
  | 'reports'
  | 'administration';

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
      { key: 'orders', label: 'Orders', path: ROUTES.orders, icon: ShoppingBag },
      { key: 'fulfillments', label: 'Fulfillments', path: ROUTES.fulfillments, icon: PackageCheck },
      { key: 'customers', label: 'Customers', path: ROUTES.customers, icon: ShoppingCart },
      { key: 'product-mappings', label: 'Product Mapping', path: ROUTES.productMappings, icon: Link2 },
      { key: 'sync-logs', label: 'Sync Logs', path: ROUTES.syncLogs, icon: ArrowLeftRight },
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
      { key: 'products', label: 'Products', path: ROUTES.products, icon: Package },
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
    id: 'operations',
    label: 'Operations',
    railLabel: 'Ops.',
    icon: TrendingUp,
    defaultPath: ROUTES.preparationDashboard,
    items: [
      { key: 'prep-section',       label: 'Preparation OS',   isSection: true },
      { key: 'prep-dashboard',     label: 'Dashboard',        path: ROUTES.preparationDashboard, icon: LayoutDashboard },
      { key: 'prep-waves',         label: 'Waves',            path: ROUTES.preparationWaves,     icon: Waves },
      { key: 'prep-pool',          label: 'Prepared Pool',    path: ROUTES.preparedPool,          icon: PackageCheck },
      { key: 'prep-stations',      label: 'Stations',         path: ROUTES.preparationStations,  icon: Warehouse },
      { key: 'prep-analytics',     label: 'Analytics',        path: ROUTES.preparationAnalytics, icon: BarChart3 },
      { key: 'analysis-section',   label: 'Analysis',         isSection: true },
      { key: 'demand-analysis',    label: 'Demand Analysis',  path: ROUTES.operationsDemandAnalysis, icon: TrendingDown },
    ],
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
      { key: 'teams', label: 'Teams', path: ROUTES.teams, icon: UsersIcon },
      { key: 'users-section', label: 'People & Access', isSection: true },
      { key: 'users', label: 'Users', path: ROUTES.users, icon: UsersIcon },
      { key: 'roles', label: 'Roles & Permissions', path: ROUTES.roles, icon: Shield },
      { key: 'settings', label: 'Settings', path: ROUTES.settings, icon: Settings },
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

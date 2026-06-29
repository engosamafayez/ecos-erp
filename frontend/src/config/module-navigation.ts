import {
  Activity,
  ArrowLeftRight,
  BarChart3,
  BookOpen,
  Building2,
  CalendarClock,
  ClipboardList,
  DollarSign,
  LayoutDashboard,
  LineChart,
  Link2,
  ListTree,
  Package,
  PackageCheck,
  PackageOpen,
  Settings,
  ShoppingBag,
  ShoppingCart,
  Tag,
  TrendingDown,
  TrendingUp,
  Truck,
  Users,
  Warehouse,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

import { ROUTES } from '@/router/routes';

export type ModuleId =
  | 'dashboard'
  | 'commerce'
  | 'inventory'
  | 'purchasing'
  | 'finance'
  | 'crm'
  | 'manufacturing'
  | 'operations'
  | 'reports'
  | 'administration';

export type ModuleNavItem = {
  key: string;
  label: string;
  path: string;
  icon: LucideIcon;
};

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
    defaultPath: ROUTES.inventoryProducts,
    items: [
      { key: 'products', label: 'Products', path: ROUTES.inventoryProducts, icon: Package },
      { key: 'stock-ledger', label: 'Stock Ledger', path: ROUTES.stockLedger, icon: BookOpen },
      { key: 'inv-dashboard', label: 'Dashboard', path: ROUTES.inventoryDashboard, icon: Activity },
      { key: 'abc', label: 'ABC Classification', path: ROUTES.inventoryAbcClassifications, icon: Tag },
      { key: 'cycle-count', label: 'Cycle Planner', path: ROUTES.inventoryCycleCountPlanner, icon: CalendarClock },
      { key: 'variance', label: 'Variance Analytics', path: ROUTES.inventoryVarianceAnalytics, icon: LineChart },
      { key: 'wh-performance', label: 'WH Performance', path: ROUTES.inventoryWarehousePerformance, icon: Warehouse },
    ],
  },
  {
    id: 'purchasing',
    label: 'Purchasing',
    railLabel: 'Purchasing',
    icon: Truck,
    defaultPath: ROUTES.purchaseOrders,
    items: [
      { key: 'suppliers', label: 'Suppliers', path: ROUTES.suppliers, icon: Truck },
      { key: 'purchase-orders', label: 'Purchase Orders', path: ROUTES.purchaseOrders, icon: ClipboardList },
      { key: 'goods-receipts', label: 'Goods Receipts', path: ROUTES.goodsReceipts, icon: PackageOpen },
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
    icon: Users,
    defaultPath: ROUTES.crm,
    items: [],
  },
  {
    id: 'manufacturing',
    label: 'Manufacturing',
    railLabel: 'Mfg.',
    icon: ListTree,
    defaultPath: ROUTES.boms,
    items: [
      { key: 'boms', label: 'Bills of Materials', path: ROUTES.boms, icon: ListTree },
    ],
  },
  {
    id: 'operations',
    label: 'Operations',
    railLabel: 'Ops.',
    icon: TrendingUp,
    defaultPath: ROUTES.operationsDemandAnalysis,
    items: [
      { key: 'demand-analysis', label: 'Demand Analysis', path: ROUTES.operationsDemandAnalysis, icon: TrendingDown },
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
      { key: 'organization', label: 'Organization', path: ROUTES.organization, icon: Building2 },
      { key: 'settings', label: 'Settings', path: ROUTES.settings, icon: Settings },
    ],
  },
];

/** Find the module that owns a given pathname. */
export function findModuleByPath(pathname: string): AppModule | undefined {
  return APP_MODULES.find((m) => {
    if (m.defaultPath === pathname) return true;
    return m.items.some(
      (item) => pathname === item.path || pathname.startsWith(item.path + '/'),
    );
  });
}

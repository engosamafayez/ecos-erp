import {
  Activity,
  ArrowLeftRight,
  BarChart3,
  BookOpen,
  Building2,
  CalendarClock,
  ClipboardList,
  Contact,
  Landmark,
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
  Truck,
  Users,
  Warehouse,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

import { ROUTES } from '@/router/routes';

export type NavItem = {
  key: string;
  label: string;
  path: string;
  icon: LucideIcon;
};

export type NavGroup = {
  label: string;
  items: NavItem[];
};

export const NAV_GROUPS: NavGroup[] = [
  {
    label: 'Overview',
    items: [
      { key: 'dashboard', label: 'Dashboard', path: ROUTES.dashboard, icon: LayoutDashboard },
    ],
  },
  {
    label: 'Organization',
    items: [
      { key: 'organization', label: 'Organization', path: ROUTES.organization, icon: Building2 },
    ],
  },
  {
    label: 'Inventory',
    items: [
      { key: 'products', label: 'Products', path: ROUTES.inventoryProducts, icon: Package },
      { key: 'stock-ledger', label: 'Stock Ledger', path: ROUTES.stockLedger, icon: BookOpen },
      { key: 'inventory-dashboard', label: 'Inv. Dashboard', path: ROUTES.inventoryDashboard, icon: Activity },
      { key: 'abc-classifications', label: 'ABC Classification', path: ROUTES.inventoryAbcClassifications, icon: Tag },
      { key: 'cycle-count-planner', label: 'Cycle Planner', path: ROUTES.inventoryCycleCountPlanner, icon: CalendarClock },
      { key: 'variance-analytics', label: 'Variance Analytics', path: ROUTES.inventoryVarianceAnalytics, icon: LineChart },
      { key: 'warehouse-performance', label: 'WH Performance', path: ROUTES.inventoryWarehousePerformance, icon: Warehouse },
    ],
  },
  {
    label: 'Purchasing',
    items: [
      { key: 'suppliers', label: 'Suppliers', path: ROUTES.suppliers, icon: Truck },
      { key: 'purchase-orders', label: 'Purchase Orders', path: ROUTES.purchaseOrders, icon: ClipboardList },
      { key: 'goods-receipts', label: 'Goods Receipts', path: ROUTES.goodsReceipts, icon: PackageOpen },
    ],
  },
  {
    label: 'Sales',
    items: [
      { key: 'orders', label: 'Orders', path: ROUTES.orders, icon: ShoppingBag },
      { key: 'fulfillments', label: 'Fulfillments', path: ROUTES.fulfillments, icon: PackageCheck },
      { key: 'customers', label: 'Customers', path: ROUTES.customers, icon: ShoppingCart },
    ],
  },
  {
    label: 'Commerce',
    items: [
      { key: 'product-mappings', label: 'Product Mapping', path: ROUTES.productMappings, icon: Link2 },
      { key: 'sync-logs', label: 'Sync Logs', path: ROUTES.syncLogs, icon: ArrowLeftRight },
    ],
  },
  {
    label: 'Manufacturing',
    items: [
      { key: 'boms', label: 'Bills of Materials', path: ROUTES.boms, icon: ListTree },
    ],
  },
  {
    label: 'Operations',
    items: [
      { key: 'accounting', label: 'Accounting', path: ROUTES.accounting, icon: Landmark },
      { key: 'crm', label: 'CRM', path: ROUTES.crm, icon: Contact },
      { key: 'hr', label: 'HR', path: ROUTES.hr, icon: Users },
    ],
  },
  {
    label: 'Insights',
    items: [{ key: 'reports', label: 'Reports', path: ROUTES.reports, icon: BarChart3 }],
  },
  {
    label: 'System',
    items: [{ key: 'settings', label: 'Settings', path: ROUTES.settings, icon: Settings }],
  },
];

/** Flat list of all navigation items (for lookups by path). */
export const NAV_ITEMS: NavItem[] = NAV_GROUPS.flatMap((group) => group.items);

/** Find the navigation item matching a pathname. */
export function findNavItemByPath(pathname: string): NavItem | undefined {
  return NAV_ITEMS.find((item) => item.path === pathname);
}

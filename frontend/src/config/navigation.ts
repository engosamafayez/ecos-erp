import {
  BarChart3,
  Building2,
  Contact,
  FolderTree,
  GitBranch,
  Landmark,
  Layers,
  LayoutDashboard,
  Package,
  Receipt,
  Ruler,
  Settings,
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

/**
 * Single source of truth for the application's primary navigation.
 * Consumed by the sidebar, the router, and the breadcrumbs so there is no
 * duplicated route/label/icon definition.
 */
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
      { key: 'companies', label: 'Companies', path: ROUTES.companies, icon: Building2 },
      { key: 'branches', label: 'Branches', path: ROUTES.branches, icon: GitBranch },
    ],
  },
  {
    label: 'Inventory',
    items: [
      { key: 'products', label: 'Products', path: ROUTES.products, icon: Package },
      { key: 'raw-materials', label: 'Raw Materials', path: ROUTES.rawMaterials, icon: Layers },
      { key: 'warehouses', label: 'Warehouses', path: ROUTES.warehouses, icon: Warehouse },
      { key: 'categories', label: 'Categories', path: ROUTES.categories, icon: FolderTree },
      { key: 'units', label: 'Units', path: ROUTES.units, icon: Ruler },
    ],
  },
  {
    label: 'Purchasing',
    items: [{ key: 'suppliers', label: 'Suppliers', path: ROUTES.suppliers, icon: Truck }],
  },
  {
    label: 'Operations',
    items: [
      { key: 'sales', label: 'Sales', path: ROUTES.sales, icon: Receipt },
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

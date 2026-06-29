import type { NavigateFunction } from 'react-router-dom';
import {
  BarChart3,
  Boxes,
  Building2,
  ClipboardList,
  Download,
  Factory,
  LayoutDashboard,
  Package,
  Settings,
  ShoppingBag,
  Sparkles,
  Truck,
  Upload,
  Users,
  Warehouse,
} from 'lucide-react';

import type { Command, CommandGroup, CommandGroupMeta } from './command-types';

// ── Group display metadata ─────────────────────────────────────────────────────

export const COMMAND_GROUP_META: Record<CommandGroup, CommandGroupMeta> = {
  navigation: { label: 'Navigation',       icon: LayoutDashboard },
  actions:    { label: 'Quick Actions',    icon: ShoppingBag },
  search:     { label: 'Search',           icon: Package },
  recent:     { label: 'Recently Opened',  icon: ClipboardList },
  favorites:  { label: 'Favorites',        icon: Warehouse },
  ai:         { label: 'AI Assistant',     icon: Sparkles },
};

/** Groups shown in the empty-state (no search query). */
export const EMPTY_STATE_GROUPS: CommandGroup[] = ['recent', 'favorites', 'actions'];

/** Render order for groups when search results are displayed. */
export const SEARCH_GROUP_ORDER: CommandGroup[] = [
  'navigation',
  'actions',
  'search',
  'recent',
  'favorites',
];

// ── Default command factory ────────────────────────────────────────────────────

/**
 * Creates the default ECOS ERP command set.
 *
 * Accepts `navigate` from React Router so navigation commands can push routes,
 * and `onClose` so commands can dismiss the palette after execution.
 *
 * This factory is called inside CommandProvider (which lives inside the Router).
 *
 * Integration pattern for modules:
 *   Modules export their own command factory following the same signature:
 *
 *     export function createOrdersCommands(
 *       navigate: NavigateFunction,
 *       onClose: () => void,
 *       openCreateDrawer: () => void,
 *     ): Command[] { ... }
 *
 *   Then register at mount via useRegisterCommands('orders', createOrdersCommands(...)).
 *
 * Extension points (future):
 *   Add `context: { companyId, warehouseId, permissions }` parameter
 *   to enable permission-aware and workspace-scoped command filtering.
 */
export function createDefaultCommands(
  navigate: NavigateFunction,
  onClose: () => void,
): Command[] {
  /** Navigate to route and close the palette. */
  const go = (path: string) => () => { navigate(path); onClose(); };

  /** Stub action — closes palette. Wired to real handler when feature is implemented. */
  const stub = () => { onClose(); };

  return [
    // ── Navigation ────────────────────────────────────────────────────────────
    {
      id: 'nav.dashboard',
      title: 'Dashboard',
      description: 'Go to the main overview',
      group: 'navigation',
      icon: LayoutDashboard,
      keywords: ['home', 'overview', 'kpi'],
      action: go('/dashboard'),
    },
    {
      id: 'nav.orders',
      title: 'Orders',
      description: 'Manage sales orders',
      group: 'navigation',
      icon: ShoppingBag,
      keywords: ['sales', 'commerce', 'fulfillment'],
      action: go('/sales/orders'),
    },
    {
      id: 'nav.customers',
      title: 'Customers',
      description: 'Manage customer records',
      group: 'navigation',
      icon: Users,
      keywords: ['crm', 'clients', 'contacts'],
      action: go('/sales/customers'),
    },
    {
      id: 'nav.products',
      title: 'Products',
      description: 'Manage the product catalog',
      group: 'navigation',
      icon: Package,
      keywords: ['catalog', 'sku', 'items', 'goods'],
      action: go('/inventory/products'),
    },
    {
      id: 'nav.inventory',
      title: 'Inventory',
      description: 'View stock levels and movements',
      group: 'navigation',
      icon: Boxes,
      keywords: ['stock', 'levels', 'wh', 'stock-ledger'],
      action: go('/inventory'),
    },
    {
      id: 'nav.warehouses',
      title: 'Warehouses',
      description: 'Manage storage locations',
      group: 'navigation',
      icon: Warehouse,
      keywords: ['locations', 'storage', 'facilities'],
      action: go('/organization/warehouses'),
    },
    {
      id: 'nav.suppliers',
      title: 'Suppliers',
      description: 'Manage vendors and suppliers',
      group: 'navigation',
      icon: Truck,
      keywords: ['vendors', 'purchasing', 'procurement'],
      action: go('/purchasing/suppliers'),
    },
    {
      id: 'nav.purchase-orders',
      title: 'Purchase Orders',
      description: 'Manage procurement orders',
      group: 'navigation',
      icon: ClipboardList,
      keywords: ['po', 'procurement', 'purchasing'],
      action: go('/purchasing/purchase-orders'),
    },
    {
      id: 'nav.companies',
      title: 'Companies',
      description: 'Manage company entities',
      group: 'navigation',
      icon: Building2,
      keywords: ['org', 'entity', 'organization', 'branch'],
      action: go('/organization/companies'),
    },
    {
      id: 'nav.manufacturing',
      title: 'Manufacturing',
      description: 'BOM and production management',
      group: 'navigation',
      icon: Factory,
      keywords: ['bom', 'production', 'assembly', 'recipes'],
      action: go('/manufacturing'),
    },
    {
      id: 'nav.reports',
      title: 'Reports',
      description: 'Analytics and business reports',
      group: 'navigation',
      icon: BarChart3,
      keywords: ['analytics', 'kpi', 'metrics', 'charts'],
      action: go('/reports'),
    },
    {
      id: 'nav.settings',
      title: 'Settings',
      description: 'Application configuration',
      group: 'navigation',
      icon: Settings,
      keywords: ['config', 'preferences', 'integrations'],
      action: go('/settings'),
    },

    // ── Quick Actions ─────────────────────────────────────────────────────────
    {
      id: 'action.order.new',
      title: 'New Order',
      description: 'Create a sales order',
      group: 'actions',
      icon: ShoppingBag,
      shortcut: '⌘N',
      keywords: ['create', 'sale', 'add'],
      action: stub,
    },
    {
      id: 'action.customer.new',
      title: 'New Customer',
      description: 'Add a customer record',
      group: 'actions',
      icon: Users,
      keywords: ['create', 'add', 'crm', 'contact'],
      action: stub,
    },
    {
      id: 'action.product.new',
      title: 'New Product',
      description: 'Add a product to the catalog',
      group: 'actions',
      icon: Package,
      keywords: ['create', 'catalog', 'sku', 'add'],
      action: stub,
    },
    {
      id: 'action.supplier.new',
      title: 'New Supplier',
      description: 'Register a supplier or vendor',
      group: 'actions',
      icon: Truck,
      keywords: ['create', 'vendor', 'add'],
      action: stub,
    },
    {
      id: 'action.warehouse.new',
      title: 'New Warehouse',
      description: 'Register a storage location',
      group: 'actions',
      icon: Warehouse,
      keywords: ['create', 'location', 'add'],
      action: stub,
    },
    {
      id: 'action.company.new',
      title: 'New Company',
      description: 'Add a company entity',
      group: 'actions',
      icon: Building2,
      keywords: ['create', 'org', 'entity', 'add'],
      action: stub,
    },
    {
      id: 'action.import',
      title: 'Import Data',
      description: 'Import from CSV or Excel',
      group: 'actions',
      icon: Upload,
      keywords: ['csv', 'excel', 'upload', 'bulk'],
      action: stub,
      soon: true,
    },
    {
      id: 'action.export',
      title: 'Export Data',
      description: 'Export to CSV or Excel',
      group: 'actions',
      icon: Download,
      keywords: ['csv', 'excel', 'download', 'bulk'],
      action: stub,
      soon: true,
    },

    // ── Recently opened (mock) ────────────────────────────────────────────────
    {
      id: 'recent.order-1042',
      title: 'Order #1042',
      description: 'AED 480.00 · Pending',
      group: 'recent',
      icon: ShoppingBag,
      keywords: ['1042'],
      action: go('/sales/orders'),
    },
    {
      id: 'recent.customer-ahmed',
      title: 'Ahmed Al Rashidi',
      description: 'Customer · 14 orders',
      group: 'recent',
      icon: Users,
      keywords: ['ahmed', 'rashidi'],
      action: go('/sales/customers'),
    },
    {
      id: 'recent.product-chair',
      title: 'Office Chair Pro X',
      description: 'SKU: CHR-001 · Stock 24',
      group: 'recent',
      icon: Package,
      keywords: ['chair', 'chr-001'],
      action: go('/inventory/products'),
    },

    // ── Favorites (mock) ──────────────────────────────────────────────────────
    {
      id: 'fav.orders',
      title: 'Orders',
      description: 'Sales orders workspace',
      group: 'favorites',
      icon: ShoppingBag,
      action: go('/sales/orders'),
    },
    {
      id: 'fav.inventory',
      title: 'Inventory',
      description: 'Stock levels & locations',
      group: 'favorites',
      icon: Boxes,
      action: go('/inventory'),
    },
    {
      id: 'fav.main-warehouse',
      title: 'Main Warehouse',
      description: 'Dubai, UAE — 92% capacity',
      group: 'favorites',
      icon: Warehouse,
      action: go('/organization/warehouses'),
    },

    // ── AI — reserved ─────────────────────────────────────────────────────────
    {
      id: 'ai.assistant',
      title: 'AI Assistant',
      description: 'Ask anything about your data and operations',
      group: 'ai',
      icon: Sparkles,
      keywords: ['ai', 'assistant', 'ask', 'intelligence', 'chat', 'copilot'],
      action: stub,
      soon: true,
    },
  ];
}

import type { ComponentType } from 'react';
import {
  Boxes,
  Building2,
  ClipboardList,
  Clock,
  LayoutDashboard,
  Package,
  Pin,
  Plus,
  Settings,
  ShoppingBag,
  ShoppingCart,
  Terminal,
  Truck,
  Users,
  Warehouse,
} from 'lucide-react';

export type SearchCategory =
  | 'recent'
  | 'pinned'
  | 'commands'
  | 'orders'
  | 'products'
  | 'customers'
  | 'navigation'
  | 'settings';

export type SearchResult = {
  id: string;
  category: SearchCategory;
  label: string;
  subtitle?: string;
  href?: string;
  icon: ComponentType<{ className?: string }>;
};

// ── Empty-state sections (shown when query is blank) ──────────────────────────

export const RECENT_MOCK: SearchResult[] = [
  {
    id: 'r1',
    category: 'recent',
    label: 'Order #1042',
    subtitle: 'AED 480.00 · Pending',
    icon: ShoppingBag,
    href: '/orders',
  },
  {
    id: 'r2',
    category: 'recent',
    label: 'Ahmed Al Rashidi',
    subtitle: 'Customer · 14 orders',
    icon: Users,
    href: '/customers',
  },
  {
    id: 'r3',
    category: 'recent',
    label: 'Office Chair Pro X',
    subtitle: 'SKU: CHR-001 · Stock 24',
    icon: Package,
    href: '/products',
  },
];

export const PINNED_MOCK: SearchResult[] = [
  {
    id: 'pin1',
    category: 'pinned',
    label: 'Orders',
    subtitle: 'Sales orders workspace',
    icon: ShoppingBag,
    href: '/orders',
  },
  {
    id: 'pin2',
    category: 'pinned',
    label: 'Inventory',
    subtitle: 'Stock levels & locations',
    icon: Boxes,
    href: '/inventory',
  },
  {
    id: 'pin3',
    category: 'pinned',
    label: 'Main Warehouse',
    subtitle: 'Dubai, UAE — 92% capacity',
    icon: Warehouse,
    href: '/warehouses',
  },
];

export const COMMANDS_MOCK: SearchResult[] = [
  {
    id: 'cmd1',
    category: 'commands',
    label: 'New Order',
    subtitle: 'Create a sales order',
    icon: Plus,
  },
  {
    id: 'cmd2',
    category: 'commands',
    label: 'New Customer',
    subtitle: 'Add a customer record',
    icon: Users,
  },
  {
    id: 'cmd3',
    category: 'commands',
    label: 'New Product',
    subtitle: 'Add a product to catalog',
    icon: Package,
  },
];

// Combined for empty state
export const EMPTY_STATE_MOCK: SearchResult[] = [
  ...RECENT_MOCK,
  ...PINNED_MOCK,
  ...COMMANDS_MOCK,
];

// ── Filterable results (shown when query is non-empty) ────────────────────────

export const SEARCH_MOCK: SearchResult[] = [
  // Orders
  { id: 'o1', category: 'orders', label: 'Order #1042', subtitle: 'ECOS Retail · AED 480.00 · Pending', icon: ShoppingBag },
  { id: 'o2', category: 'orders', label: 'Order #1038', subtitle: 'ECOS Holding · AED 1,230.00 · Processing', icon: ShoppingBag },
  { id: 'o3', category: 'orders', label: 'Order #1035', subtitle: 'ECOS Logistics · AED 90.00 · Shipped', icon: ShoppingBag },
  // Products
  { id: 'p1', category: 'products', label: 'Office Chair Pro X', subtitle: 'SKU: CHR-001 · Stock: 24', icon: Package },
  { id: 'p2', category: 'products', label: 'Standing Desk 160cm', subtitle: 'SKU: DSK-016 · Stock: 8', icon: Package },
  { id: 'p3', category: 'products', label: 'Monitor Arm Dual', subtitle: 'SKU: MON-002 · Stock: 45', icon: Package },
  // Customers
  { id: 'c1', category: 'customers', label: 'Ahmed Al Rashidi', subtitle: 'Customer · 14 orders · AED 8,400', icon: Users },
  { id: 'c2', category: 'customers', label: 'Sara Al Mansoori', subtitle: 'Customer · 7 orders · AED 3,200', icon: Users },
  { id: 'c3', category: 'customers', label: 'Gulf Tech LLC', subtitle: 'Business · 31 orders · AED 42,500', icon: Users },
  // Navigation
  { id: 'n1', category: 'navigation', label: 'Dashboard', subtitle: 'Go to overview', icon: LayoutDashboard, href: '/dashboard' },
  { id: 'n2', category: 'navigation', label: 'Orders', subtitle: 'Manage sales orders', icon: ShoppingBag, href: '/orders' },
  { id: 'n3', category: 'navigation', label: 'Products', subtitle: 'Manage catalog', icon: Package, href: '/products' },
  { id: 'n4', category: 'navigation', label: 'Customers', subtitle: 'Manage CRM', icon: Users, href: '/customers' },
  { id: 'n5', category: 'navigation', label: 'Inventory', subtitle: 'Manage stock levels', icon: Boxes, href: '/inventory' },
  { id: 'n6', category: 'navigation', label: 'Warehouses', subtitle: 'Manage locations', icon: Warehouse, href: '/warehouses' },
  { id: 'n7', category: 'navigation', label: 'Suppliers', subtitle: 'Manage vendors', icon: Truck, href: '/suppliers' },
  { id: 'n8', category: 'navigation', label: 'Purchase Orders', subtitle: 'Manage procurement', icon: ClipboardList, href: '/purchase-orders' },
  { id: 'n9', category: 'navigation', label: 'Companies', subtitle: 'Manage organizations', icon: Building2, href: '/companies' },
  // Settings
  { id: 's1', category: 'settings', label: 'Appearance', subtitle: 'Theme · Display · Language', icon: Settings, href: '/settings' },
  { id: 's2', category: 'settings', label: 'Integrations', subtitle: 'WooCommerce and channels', icon: ShoppingCart, href: '/settings/channels' },
];

// ── Labels & icons for section headers ───────────────────────────────────────

export const CATEGORY_LABEL: Record<SearchCategory, string> = {
  recent: 'Recent',
  pinned: 'Pinned',
  commands: 'Commands',
  orders: 'Orders',
  products: 'Products',
  customers: 'Customers',
  navigation: 'Pages',
  settings: 'Settings',
};

export const CATEGORY_SECTION_ICON: Record<SearchCategory, ComponentType<{ className?: string }>> = {
  recent: Clock,
  pinned: Pin,
  commands: Terminal,
  orders: ShoppingBag,
  products: Package,
  customers: Users,
  navigation: LayoutDashboard,
  settings: Settings,
};

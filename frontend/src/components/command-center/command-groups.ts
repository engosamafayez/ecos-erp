import type { TFunction } from 'i18next';
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
  PackageCheck,
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
//
// Labels are stored as i18n keys (namespace `command-palette`) and resolved at
// render time by the consumer via t(meta.labelKey) — keeping this a plain module
// while staying reactive to language changes.

export const COMMAND_GROUP_META: Record<CommandGroup, CommandGroupMeta> = {
  navigation: { labelKey: 'command-palette:groups.navigation', icon: LayoutDashboard },
  actions:    { labelKey: 'command-palette:groups.actions',    icon: ShoppingBag },
  search:     { labelKey: 'command-palette:groups.search',     icon: Package },
  recent:     { labelKey: 'command-palette:groups.recent',     icon: ClipboardList },
  favorites:  { labelKey: 'command-palette:groups.favorites',  icon: Warehouse },
  ai:         { labelKey: 'command-palette:groups.ai',         icon: Sparkles },
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
 * Accepts `t` (from useTranslation('command-palette')) so every user-facing
 * title/description is resolved through the i18n layer — the resolved strings
 * are what the palette filters on, so search works in the active language.
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
 *       t: TFunction,
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
  t: TFunction,
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
      title: t($ => $["command-palette:nav"].dashboard.title),
      description: t($ => $["command-palette:nav"].dashboard.description),
      group: 'navigation',
      icon: LayoutDashboard,
      keywords: ['home', 'overview', 'kpi'],
      action: go('/dashboard'),
    },
    {
      id: 'nav.orders',
      title: t($ => $["command-palette:nav"].orders.title),
      description: t($ => $["command-palette:nav"].orders.description),
      group: 'navigation',
      icon: ShoppingBag,
      keywords: ['sales', 'commerce'],
      action: go('/orders'),
    },
    {
      id: 'nav.fulfillments',
      title: t($ => $["command-palette:nav"].fulfillments.title),
      description: t($ => $["command-palette:nav"].fulfillments.description),
      group: 'navigation',
      icon: PackageCheck,
      keywords: ['shipping', 'dispatch', 'fulfillment', 'delivery'],
      action: go('/fulfillments'),
    },
    {
      id: 'nav.customers',
      title: t($ => $["command-palette:nav"].customers.title),
      description: t($ => $["command-palette:nav"].customers.description),
      group: 'navigation',
      icon: Users,
      keywords: ['crm', 'clients', 'contacts', 'commerce'],
      action: go('/customers'),
    },
    {
      id: 'nav.products',
      title: t($ => $["command-palette:nav"].products.title),
      description: t($ => $["command-palette:nav"].products.description),
      group: 'navigation',
      icon: Package,
      keywords: ['catalog', 'sku', 'items', 'goods', 'commerce'],
      action: go('/products'),
    },
    {
      id: 'nav.inventory',
      title: t($ => $["command-palette:nav"].inventory.title),
      description: t($ => $["command-palette:nav"].inventory.description),
      group: 'navigation',
      icon: Boxes,
      keywords: ['stock', 'levels', 'wh', 'stock-ledger'],
      action: go('/inventory'),
    },
    {
      id: 'nav.warehouses',
      title: t($ => $["command-palette:nav"].warehouses.title),
      description: t($ => $["command-palette:nav"].warehouses.description),
      group: 'navigation',
      icon: Warehouse,
      keywords: ['locations', 'storage', 'facilities'],
      action: go('/warehouses'),
    },
    {
      id: 'nav.suppliers',
      title: t($ => $["command-palette:nav"].suppliers.title),
      description: t($ => $["command-palette:nav"].suppliers.description),
      group: 'navigation',
      icon: Truck,
      keywords: ['vendors', 'purchasing', 'procurement'],
      action: go('/suppliers'),
    },
    {
      id: 'nav.purchase-orders',
      title: t($ => $["command-palette:nav"].purchaseOrders.title),
      description: t($ => $["command-palette:nav"].purchaseOrders.description),
      group: 'navigation',
      icon: ClipboardList,
      keywords: ['po', 'procurement', 'purchasing'],
      action: go('/purchase-orders'),
    },
    {
      id: 'nav.companies',
      title: t($ => $["command-palette:nav"].companies.title),
      description: t($ => $["command-palette:nav"].companies.description),
      group: 'navigation',
      icon: Building2,
      keywords: ['org', 'entity', 'organization', 'branch'],
      action: go('/companies'),
    },
    {
      id: 'nav.manufacturing',
      title: t($ => $["command-palette:nav"].manufacturing.title),
      description: t($ => $["command-palette:nav"].manufacturing.description),
      group: 'navigation',
      icon: Factory,
      keywords: ['bom', 'production', 'assembly', 'recipes'],
      action: go('/manufacturing'),
    },
    {
      id: 'nav.reports',
      title: t($ => $["command-palette:nav"].reports.title),
      description: t($ => $["command-palette:nav"].reports.description),
      group: 'navigation',
      icon: BarChart3,
      keywords: ['analytics', 'kpi', 'metrics', 'charts'],
      action: go('/reports'),
    },
    {
      id: 'nav.settings',
      title: t($ => $["command-palette:nav"].settings.title),
      description: t($ => $["command-palette:nav"].settings.description),
      group: 'navigation',
      icon: Settings,
      keywords: ['config', 'preferences', 'integrations'],
      action: go('/settings'),
    },

    // ── Quick Actions ─────────────────────────────────────────────────────────
    {
      id: 'action.order.new',
      title: t($ => $["command-palette:actions"].orderNew.title),
      description: t($ => $["command-palette:actions"].orderNew.description),
      group: 'actions',
      icon: ShoppingBag,
      shortcut: '⌘N',
      keywords: ['create', 'sale', 'add'],
      action: stub,
    },
    {
      id: 'action.customer.new',
      title: t($ => $["command-palette:actions"].customerNew.title),
      description: t($ => $["command-palette:actions"].customerNew.description),
      group: 'actions',
      icon: Users,
      keywords: ['create', 'add', 'crm', 'contact'],
      action: stub,
    },
    {
      id: 'action.product.new',
      title: t($ => $["command-palette:actions"].productNew.title),
      description: t($ => $["command-palette:actions"].productNew.description),
      group: 'actions',
      icon: Package,
      keywords: ['create', 'catalog', 'sku', 'add'],
      action: stub,
    },
    {
      id: 'action.supplier.new',
      title: t($ => $["command-palette:actions"].supplierNew.title),
      description: t($ => $["command-palette:actions"].supplierNew.description),
      group: 'actions',
      icon: Truck,
      keywords: ['create', 'vendor', 'add'],
      action: stub,
    },
    {
      id: 'action.warehouse.new',
      title: t($ => $["command-palette:actions"].warehouseNew.title),
      description: t($ => $["command-palette:actions"].warehouseNew.description),
      group: 'actions',
      icon: Warehouse,
      keywords: ['create', 'location', 'add'],
      action: stub,
    },
    {
      id: 'action.company.new',
      title: t($ => $["command-palette:actions"].companyNew.title),
      description: t($ => $["command-palette:actions"].companyNew.description),
      group: 'actions',
      icon: Building2,
      keywords: ['create', 'org', 'entity', 'add'],
      action: stub,
    },
    {
      id: 'action.import',
      title: t($ => $["command-palette:actions"].import.title),
      description: t($ => $["command-palette:actions"].import.description),
      group: 'actions',
      icon: Upload,
      keywords: ['csv', 'excel', 'upload', 'bulk'],
      action: stub,
      soon: true,
    },
    {
      id: 'action.export',
      title: t($ => $["command-palette:actions"].export.title),
      description: t($ => $["command-palette:actions"].export.description),
      group: 'actions',
      icon: Download,
      keywords: ['csv', 'excel', 'download', 'bulk'],
      action: stub,
      soon: true,
    },

    // ── Favorites ─────────────────────────────────────────────────────────────
    // Real navigation favorites. User-pinned records populate this group at
    // runtime via module command registration.
    {
      id: 'fav.orders',
      title: t($ => $["command-palette:favorites"].orders.title),
      description: t($ => $["command-palette:favorites"].orders.description),
      group: 'favorites',
      icon: ShoppingBag,
      action: go('/orders'),
    },
    {
      id: 'fav.inventory',
      title: t($ => $["command-palette:favorites"].inventory.title),
      description: t($ => $["command-palette:favorites"].inventory.description),
      group: 'favorites',
      icon: Boxes,
      action: go('/inventory'),
    },

    // ── AI — reserved ─────────────────────────────────────────────────────────
    {
      id: 'ai.assistant',
      title: t($ => $["command-palette:ai"].assistant.title),
      description: t($ => $["command-palette:ai"].assistant.description),
      group: 'ai',
      icon: Sparkles,
      keywords: ['ai', 'assistant', 'ask', 'intelligence', 'chat', 'copilot'],
      action: stub,
      soon: true,
    },
  ];
}

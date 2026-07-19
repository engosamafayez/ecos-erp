import type { LucideIcon } from 'lucide-react';
import {
  ArrowLeftRight,
  ClipboardList,
  Factory,
  Package,
  ShoppingCart,
  UserPlus,
} from 'lucide-react';
import { Link } from 'react-router-dom';
import { cn } from '@/lib/utils';
import { ROUTES } from '@/router/routes';

// ── Action definitions ─────────────────────────────────────────────────────

interface QuickAction {
  id:          string;
  icon:        LucideIcon;
  title:       string;
  description: string;
  to:          string;
  color:       string;
  badge?:      string;
}

const ACTIONS: QuickAction[] = [
  {
    id:          'create-order',
    icon:        ShoppingCart,
    title:       'Create Order',
    description: 'Start a new sales order',
    to:          ROUTES.ordersNew,
    color:       'bg-indigo-500/10 text-indigo-600 dark:text-indigo-400',
  },
  {
    id:          'receive-inventory',
    icon:        Package,
    title:       'Receive Inventory',
    description: 'Record incoming stock',
    to:          ROUTES.goodsReceiptsNew,
    color:       'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
  },
  {
    id:          'launch-wave',
    icon:        Factory,
    title:       'Launch Wave',
    description: 'Start a preparation wave',
    to:          ROUTES.waveWorkspace,
    color:       'bg-violet-500/10 text-violet-600 dark:text-violet-400',
  },
  {
    id:          'transfer-stock',
    icon:        ArrowLeftRight,
    title:       'Transfer Stock',
    description: 'Move between warehouses',
    to:          ROUTES.stockTransfers,
    color:       'bg-cyan-500/10 text-cyan-600 dark:text-cyan-400',
  },
  {
    id:          'add-customer',
    icon:        UserPlus,
    title:       'Add Customer',
    description: 'Register new customer',
    to:          ROUTES.customers,
    color:       'bg-amber-500/10 text-amber-600 dark:text-amber-400',
  },
  {
    id:          'purchase-materials',
    icon:        ClipboardList,
    title:       'Purchase Materials',
    description: 'Create purchase order',
    to:          ROUTES.purchaseMaterials,
    color:       'bg-orange-500/10 text-orange-600 dark:text-orange-400',
  },
];

// ── Component ──────────────────────────────────────────────────────────────

export function WorkspaceQuickActions() {
  return (
    <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
      {ACTIONS.map((action) => (
        <Link
          key={action.id}
          to={action.to}
          className={cn(
            'group flex flex-col gap-2 rounded-xl border bg-card p-3.5 transition-all',
            'hover:shadow-md hover:border-border/80',
          )}
        >
          <div className={cn(
            'flex h-9 w-9 items-center justify-center rounded-lg transition-transform group-hover:scale-105',
            action.color,
          )}>
            <action.icon className="h-4 w-4" />
          </div>
          <div>
            <p className="text-sm font-semibold leading-tight">
              {action.title}
            </p>
            <p className="mt-0.5 text-[11px] leading-tight text-muted-foreground">
              {action.description}
            </p>
          </div>
        </Link>
      ))}
    </div>
  );
}

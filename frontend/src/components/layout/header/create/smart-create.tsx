import type { ComponentType } from 'react';
import {
  Boxes,
  Building2,
  ClipboardList,
  Package,
  Plus,
  ShoppingBag,
  Truck,
  Users,
  Warehouse,
} from 'lucide-react';

import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

// ── Action definitions ────────────────────────────────────────────────────────

type CreateAction = {
  key: string;
  label: string;
  description: string;
  icon: ComponentType<{ className?: string }>;
  shortcut?: string;
  disabled?: boolean;
  soon?: boolean;
};

type CreateGroup = {
  label: string;
  iconClass: string;
  actions: CreateAction[];
};

const CREATE_GROUPS: CreateGroup[] = [
  {
    label: 'Commerce',
    iconClass: 'bg-primary/10 text-primary',
    actions: [
      {
        key: 'order',
        label: 'New Order',
        description: 'Create a sales order',
        icon: ShoppingBag,
        shortcut: '⌘N',
      },
      {
        key: 'customer',
        label: 'New Customer',
        description: 'Add a customer record',
        icon: Users,
      },
      {
        key: 'product',
        label: 'New Product',
        description: 'Add a product to catalog',
        icon: Package,
      },
    ],
  },
  {
    label: 'Inventory',
    iconClass: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    actions: [
      {
        key: 'purchase-order',
        label: 'New Purchase Order',
        description: 'Create a procurement order',
        icon: ClipboardList,
      },
      {
        key: 'supplier',
        label: 'New Supplier',
        description: 'Register a supplier or vendor',
        icon: Truck,
      },
      {
        key: 'stock-adjustment',
        label: 'Stock Adjustment',
        description: 'Adjust inventory levels',
        icon: Boxes,
        disabled: true,
        soon: true,
      },
    ],
  },
  {
    label: 'Administration',
    iconClass: 'bg-violet-500/10 text-violet-600 dark:text-violet-400',
    actions: [
      {
        key: 'warehouse',
        label: 'New Warehouse',
        description: 'Register a storage location',
        icon: Warehouse,
      },
      {
        key: 'company',
        label: 'New Company',
        description: 'Add a company entity',
        icon: Building2,
      },
    ],
  },
];

// ── Component ─────────────────────────────────────────────────────────────────

export function SmartCreate() {
  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button
          variant="default"
          size="sm"
          className="h-9 gap-1.5 px-3"
          aria-label="Quick create"
        >
          <Plus className="size-4" aria-hidden />
          <span className="hidden lg:inline">New</span>
        </Button>
      </DropdownMenuTrigger>

      <DropdownMenuContent align="end" className="w-72 p-2">
        {CREATE_GROUPS.map((group, gi) => (
          <div key={group.label}>
            {gi > 0 ? <DropdownMenuSeparator className="my-1.5" /> : null}

            <DropdownMenuLabel className="px-1 py-1 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
              {group.label}
            </DropdownMenuLabel>

            <div className="space-y-0.5">
              {group.actions.map((action) => {
                const Icon = action.icon;
                return (
                  <button
                    key={action.key}
                    type="button"
                    role="menuitem"
                    disabled={action.disabled}
                    onClick={() => {
                      // Extension point: navigate or open drawer per action key
                    }}
                    className={cn(
                      'flex w-full items-center gap-3 rounded-md px-2 py-2 text-start transition-colors',
                      action.disabled
                        ? 'cursor-not-allowed opacity-50'
                        : 'hover:bg-accent',
                    )}
                  >
                    {/* Icon badge */}
                    <span
                      className={cn(
                        'flex size-8 shrink-0 items-center justify-center rounded-md',
                        action.disabled ? 'bg-muted text-muted-foreground' : group.iconClass,
                      )}
                      aria-hidden
                    >
                      <Icon className="size-4" />
                    </span>

                    {/* Label + description */}
                    <span className="min-w-0 flex-1">
                      <span className="block text-sm font-medium leading-tight">
                        {action.label}
                      </span>
                      <span className="block truncate text-xs text-muted-foreground">
                        {action.description}
                      </span>
                    </span>

                    {/* Shortcut or Soon badge */}
                    {action.soon ? (
                      <span className="shrink-0 rounded-full border border-primary/30 bg-primary/5 px-1.5 py-0.5 text-[9px] font-medium text-primary/70">
                        Soon
                      </span>
                    ) : action.shortcut ? (
                      <kbd
                        aria-label={`Shortcut: ${action.shortcut}`}
                        className="shrink-0 select-none rounded border bg-muted px-1.5 py-0.5 font-mono text-[10px] text-muted-foreground"
                      >
                        {action.shortcut}
                      </kbd>
                    ) : null}
                  </button>
                );
              })}
            </div>
          </div>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}

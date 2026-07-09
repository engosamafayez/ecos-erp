import { useState } from 'react';
import { Check, ChevronDown, Loader2, X } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import { usePatchOrder } from '@/features/orders/hooks/use-orders';
import type { OrderStatus } from '@/features/orders/types/order';

const STATUS_CLASS: Record<OrderStatus, string> = {
  // ── Active backend statuses ─────────────────────────────────────────────────
  pending:              'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
  in_progress:          'bg-sky-100 text-sky-800 dark:bg-sky-900/30 dark:text-sky-400',
  processing:           'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
  preparing:            'bg-teal-100 text-teal-800 dark:bg-teal-900/30 dark:text-teal-400',
  ready_for_loading:    'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/30 dark:text-indigo-400',
  awaiting_payment:     'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400',
  confirm_order:        'bg-violet-100 text-violet-800 dark:bg-violet-900/30 dark:text-violet-400',
  out_for_delivery:     'bg-cyan-100 text-cyan-800 dark:bg-cyan-900/30 dark:text-cyan-400',
  returned:             'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400',
  completed:            'bg-emerald-100 text-emerald-900 dark:bg-emerald-900/30 dark:text-emerald-300',
  cancelled:            'bg-rose-100 text-rose-800 dark:bg-rose-900/30 dark:text-rose-400',
  // ── Legacy (pre-refactor records) ──────────────────────────────────────────
  waiting_for_payment:  'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400',
  review_confirmation:  'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400',
  review:               'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400',
  confirmed:            'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400',
  shipping:             'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300',
  delivered:            'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
  delivery_delayed:     'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-300',
  rejected:             'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
  waiting_for_stock:    'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-300',
  postponed:            'bg-slate-100 text-slate-700 dark:bg-slate-800/60 dark:text-slate-400',
};

// Only show active backend statuses in the dropdown (not legacy values)
const ALL_STATUSES: OrderStatus[] = [
  'pending', 'in_progress', 'processing', 'awaiting_payment', 'confirm_order',
  'preparing', 'ready_for_loading', 'out_for_delivery', 'returned',
  'completed', 'cancelled',
];

type SaveState = 'idle' | 'saving' | 'saved' | 'failed';

type OrderInlineStatusCellProps = {
  orderId: string;
  status: OrderStatus;
};

/**
 * Part 2 — Inline status change cell.
 * Click badge to open status dropdown; selecting a status auto-saves.
 * Shows Saving… / Saved ✓ / Failed ✕ feedback per cell.
 */
export function OrderInlineStatusCell({ orderId, status }: OrderInlineStatusCellProps) {
  const { t } = useTranslation('orders');
  const [open, setOpen] = useState(false);
  const [saveState, setSaveState] = useState<SaveState>('idle');
  const patch = usePatchOrder();

  const cls = STATUS_CLASS[status] ?? STATUS_CLASS.pending;
  const label = t(`status.${status}`, { defaultValue: status });

  const handleSelect = (next: OrderStatus) => {
    setOpen(false);
    if (next === status) return;
    setSaveState('saving');
    patch.mutate(
      { id: orderId, data: { status: next } },
      {
        onSuccess: () => {
          setSaveState('saved');
          setTimeout(() => setSaveState('idle'), 2000);
        },
        onError: () => {
          setSaveState('failed');
          setTimeout(() => setSaveState('idle'), 2000);
        },
      },
    );
  };

  return (
    <DropdownMenu open={open} onOpenChange={setOpen}>
      <DropdownMenuTrigger asChild>
        <button
          type="button"
          disabled={saveState === 'saving'}
          className={cn(
            'inline-flex cursor-pointer items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium',
            'ring-1 ring-inset ring-current/20 transition-opacity hover:opacity-80',
            cls,
          )}
          aria-label={`Status: ${label}. Click to change`}
        >
          {saveState === 'saving' && <Loader2 className="size-2.5 animate-spin" />}
          {saveState === 'saved'  && <Check   className="size-2.5 text-emerald-600" />}
          {saveState === 'failed' && <X       className="size-2.5 text-red-600" />}
          {label}
          <ChevronDown className="size-2.5 opacity-60" />
        </button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="start" className="w-48 max-h-72 overflow-y-auto">
        {ALL_STATUSES.map((s) => (
          <DropdownMenuItem key={s} onClick={() => handleSelect(s)}>
            <span
              className={cn(
                'mr-2 inline-flex size-2 shrink-0 rounded-full',
                STATUS_CLASS[s].split(' ')[0],
              )}
            />
            {t(`status.${s}`, { defaultValue: s })}
            {s === status ? <Check className="ml-auto size-3.5" /> : null}
          </DropdownMenuItem>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}

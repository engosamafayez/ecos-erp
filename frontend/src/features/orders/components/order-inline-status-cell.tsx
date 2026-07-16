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
  pending:          'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
  awaiting_payment: 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400',
  processing:       'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
  awaiting_stock:   'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-300',
  confirmed:        'bg-violet-100 text-violet-800 dark:bg-violet-900/30 dark:text-violet-400',
  preparing:        'bg-teal-100 text-teal-800 dark:bg-teal-900/30 dark:text-teal-400',
  out_for_delivery: 'bg-cyan-100 text-cyan-800 dark:bg-cyan-900/30 dark:text-cyan-400',
  delivered:        'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
  completed:        'bg-emerald-100 text-emerald-900 dark:bg-emerald-900/30 dark:text-emerald-300',
  cancelled:        'bg-rose-100 text-rose-800 dark:bg-rose-900/30 dark:text-rose-400',
  review:           'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
  rescheduled:      'bg-sky-100 text-sky-800 dark:bg-sky-900/30 dark:text-sky-400',
  returned:         'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400',
};

/** Valid next statuses per current status — only show reachable transitions. */
const WORKFLOW_TRANSITIONS: Partial<Record<OrderStatus, OrderStatus[]>> = {
  pending:          ['processing', 'confirmed', 'awaiting_payment', 'cancelled'],
  awaiting_payment: ['processing', 'confirmed', 'cancelled'],
  processing:       ['preparing', 'awaiting_stock', 'review', 'cancelled'],
  awaiting_stock:   ['processing', 'cancelled'],
  confirmed:        ['preparing', 'rescheduled', 'cancelled'],
  preparing:        ['out_for_delivery', 'review', 'rescheduled', 'cancelled'],
  out_for_delivery: ['delivered', 'returned', 'review', 'rescheduled'],
  delivered:        ['completed', 'review', 'processing', 'confirmed', 'rescheduled', 'cancelled'],
  returned:         ['confirmed', 'review', 'rescheduled', 'cancelled'],
  review:           ['processing', 'rescheduled', 'cancelled'],
  rescheduled:      ['processing', 'rescheduled', 'cancelled'],
  completed:        [],
  cancelled:        [],
};

type SaveState = 'idle' | 'saving' | 'saved' | 'failed';

type OrderInlineStatusCellProps = {
  orderId: string;
  status: OrderStatus;
};

/**
 * Inline admin status override for the orders grid.
 * Direct patch — not a workflow trigger. For operations use the workflow drawer.
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
        {(WORKFLOW_TRANSITIONS[status] ?? []).map((s) => (
          <DropdownMenuItem key={s} onClick={() => handleSelect(s)}>
            <span
              className={cn(
                'mr-2 inline-flex size-2 shrink-0 rounded-full',
                STATUS_CLASS[s].split(' ')[0],
              )}
            />
            {t(`status.${s}`, { defaultValue: s })}
          </DropdownMenuItem>
        ))}
        {(WORKFLOW_TRANSITIONS[status] ?? []).length === 0 ? (
          <DropdownMenuItem disabled>No transitions available</DropdownMenuItem>
        ) : null}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}

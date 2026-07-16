import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

import type { Order } from '../types/order';

type ReservationState = {
  label: string;
  cls: string;
  detail?: string;
  qty?: string;
};

function getState(order: Order): ReservationState {
  const isReleased = Boolean(order.inventory_released_at);
  const isReserved = Boolean(order.inventory_reserved_at) && !isReleased;

  // ── Released ──────────────────────────────────────────────────────────────
  if (isReleased) {
    return {
      label: 'Released',
      cls: 'bg-muted text-muted-foreground ring-border',
    };
  }

  // ── Active reservation ────────────────────────────────────────────────────
  if (isReserved) {
    const lines       = order.lines ?? [];
    const totalQty    = lines.reduce((s, l) => s + l.quantity, 0);
    const reservedQty = lines.reduce((s, l) => s + (l.reserved_qty ?? 0), 0);
    const isPartial   = reservedQty > 0 && reservedQty < totalQty;

    const detail = new Intl.DateTimeFormat(undefined, {
      dateStyle: 'short',
      timeStyle: 'short',
    }).format(new Date(order.inventory_reserved_at!));

    if (isPartial) {
      return {
        label: 'Partially Reserved',
        cls: 'bg-amber-100 text-amber-700 ring-amber-600/20 dark:bg-amber-900/30 dark:text-amber-300',
        detail,
        qty: `${reservedQty} of ${totalQty} unit${totalQty !== 1 ? 's' : ''} reserved`,
      };
    }

    return {
      label: 'Reserved',
      cls: 'bg-emerald-100 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-900/30 dark:text-emerald-400',
      detail,
      qty: totalQty > 0 ? `${totalQty} unit${totalQty !== 1 ? 's' : ''} reserved` : undefined,
    };
  }

  // ── No reservation ────────────────────────────────────────────────────────

  // Cancelled / completed with no reservation → dash (nothing to show)
  if (order.status === 'cancelled' || order.status === 'completed') {
    return { label: '—', cls: '' };
  }

  // AwaitingStock means reservation was attempted and failed
  if (order.status === 'awaiting_stock') {
    return {
      label: 'Reservation Failed',
      cls: 'bg-red-100 text-red-700 ring-red-600/20 dark:bg-red-900/30 dark:text-red-300',
    };
  }

  return {
    label: 'Not Reserved',
    cls: 'bg-muted text-muted-foreground ring-border',
  };
}

export function OrderReservationCell({ order }: { order: Order }) {
  const state = getState(order);

  if (state.label === '—') return <span className="text-muted-foreground">—</span>;

  const badge = (
    <span
      className={cn(
        'inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-medium ring-1 ring-inset',
        state.cls,
      )}
    >
      {state.label}
    </span>
  );

  if (!state.detail) return badge;

  return (
    <TooltipProvider delayDuration={400}>
      <Tooltip>
        <TooltipTrigger asChild>
          <span className="cursor-default">{badge}</span>
        </TooltipTrigger>
        <TooltipContent side="bottom" className="text-xs space-y-0.5">
          <p className="font-medium">Inventory Reserved</p>
          {state.qty ? <p className="text-muted-foreground">{state.qty}</p> : null}
          <p className="text-muted-foreground">{state.detail}</p>
          {order.assigned_warehouse_id ? (
            <p className="font-mono text-[10px] text-muted-foreground">{order.assigned_warehouse_id}</p>
          ) : null}
        </TooltipContent>
      </Tooltip>
    </TooltipProvider>
  );
}

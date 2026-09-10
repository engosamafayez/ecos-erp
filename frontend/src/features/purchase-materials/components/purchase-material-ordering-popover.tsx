import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Package } from 'lucide-react';

import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';

import type { PurchaseMaterialLineSummary } from '../types/purchase-material';

function fmtQty(n: number): string {
  return n.toLocaleString(undefined, { maximumFractionDigits: 4 });
}

type Props = {
  count: number;
  items: PurchaseMaterialLineSummary[];
  emptyLabel: string;
  /** Ordered lines are fully committed (nothing "remaining"); Not-Yet-Ordered lines are either
   *  untouched or partially committed, so each shows its own remaining-to-order quantity
   *  (TASK-...-PURCHASE-REQUESTS-FINAL-019 §12, e.g. "Cement — 20 remaining"). */
  variant: 'ordered' | 'not_yet_ordered';
};

/**
 * TASK-...-011 §9, extended by TASK-...-PURCHASE-REQUESTS-FINAL-019 §12: Ordered / Not Yet
 * Ordered counts, with a list reachable by BOTH hover (desktop) and click/tap (reliable on any
 * device) — a controlled `open` state driven by both hover and Radix's own click toggle. The
 * list is already on `material` from PurchaseMaterialResource (no per-row fetch, unlike
 * AreaCountPopover's on-open query) — the count and its list can never disagree.
 *
 * Closing on mouseleave is delayed (standard "hover intent" pattern), not immediate: the
 * trigger button and the portalled content are not DOM neighbors, so a straight-line mouse
 * move between them briefly leaves both — an immediate close would flicker shut before the
 * pointer ever reaches the content.
 *
 * The trigger's click is force-open, not Radix's default toggle: a real click event is
 * preceded by a synthetic mouseenter (both browsers and Testing Library's userEvent fire
 * pointer-enter before the click completes), which already opens it via hover — Radix's own
 * click handler would then see "already open" and toggle it back CLOSED on the very click
 * meant to open it. `preventDefault()` here stops Radix's own composed click handler from
 * running at all (its `composeEventHandlers` skips the wrapped handler once the original
 * calls preventDefault), so this handler is the only thing deciding what a click does.
 */
const CLOSE_DELAY_MS = 150;

export function PurchaseMaterialOrderingPopover({ count, items, emptyLabel, variant }: Props) {
  const { t } = useTranslation('purchase-materials');
  const [open, setOpen] = useState(false);
  const closeTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

  function cancelClose() {
    if (closeTimer.current !== null) {
      clearTimeout(closeTimer.current);
      closeTimer.current = null;
    }
  }

  function scheduleClose() {
    cancelClose();
    closeTimer.current = setTimeout(() => setOpen(false), CLOSE_DELAY_MS);
  }

  function openNow() {
    cancelClose();
    setOpen(true);
  }

  if (count === 0) {
    return <span className="tabular-nums text-muted-foreground">0</span>;
  }

  return (
    <Popover open={open} onOpenChange={(next) => { cancelClose(); setOpen(next); }}>
      <PopoverTrigger asChild>
        <button
          type="button"
          className="tabular-nums underline-offset-2 hover:underline focus:outline-none"
          onClick={(e) => { e.preventDefault(); e.stopPropagation(); openNow(); }}
          onMouseEnter={openNow}
          onMouseLeave={scheduleClose}
        >
          {count}
        </button>
      </PopoverTrigger>
      <PopoverContent
        className="w-64 p-0"
        side="left"
        align="center"
        onClick={(e) => e.stopPropagation()}
        onMouseEnter={openNow}
        onMouseLeave={scheduleClose}
      >
        <div className="flex items-center gap-1.5 border-b px-3 py-2">
          <Package className="size-3.5 text-muted-foreground" />
          <p className="text-sm font-medium">{count}</p>
        </div>
        <div className="max-h-56 overflow-y-auto">
          {items.length === 0 ? (
            <p className="px-3 py-2.5 text-xs text-muted-foreground">{emptyLabel}</p>
          ) : (
            <ul className="py-1">
              {items.map((line) => (
                <li key={line.id} className="flex items-center justify-between gap-2 px-3 py-1.5 text-xs">
                  <span className="truncate">{line.product_name ?? line.sku ?? line.id}</span>
                  {variant === 'not_yet_ordered' ? (
                    <span className="font-mono tabular-nums text-muted-foreground shrink-0">
                      {t($ => $.purchasesPage.orderingPopover.remaining, { qty: fmtQty(line.remaining_to_order) })}
                    </span>
                  ) : (
                    <span className="font-mono tabular-nums text-muted-foreground shrink-0">
                      {fmtQty(line.ordered_qty)} / {fmtQty(line.requested_qty)}
                    </span>
                  )}
                </li>
              ))}
            </ul>
          )}
        </div>
      </PopoverContent>
    </Popover>
  );
}

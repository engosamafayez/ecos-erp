import { Package } from 'lucide-react';

import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';

import type { PurchaseMaterialLineSummary } from '../types/purchase-material';

function fmtQty(n: number): string {
  return n.toLocaleString(undefined, { maximumFractionDigits: 4 });
}

/**
 * TASK-...-011 §9: Ordered / Not Yet Ordered counts, with a hover-or-click popover listing which
 * lines. The list is already on `material` from PurchaseMaterialResource (no per-row fetch, unlike
 * AreaCountPopover's on-open query) — the count and its list can never disagree.
 */
export function PurchaseMaterialOrderingPopover({
  count,
  items,
  emptyLabel,
}: {
  count: number;
  items: PurchaseMaterialLineSummary[];
  emptyLabel: string;
}) {
  if (count === 0) {
    return <span className="tabular-nums text-muted-foreground">0</span>;
  }

  return (
    <Popover>
      <PopoverTrigger asChild>
        <button
          type="button"
          className="tabular-nums underline-offset-2 hover:underline focus:outline-none"
          onClick={(e) => e.stopPropagation()}
        >
          {count}
        </button>
      </PopoverTrigger>
      <PopoverContent className="w-64 p-0" side="left" align="center" onClick={(e) => e.stopPropagation()}>
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
                  <span className="font-mono tabular-nums text-muted-foreground shrink-0">
                    {fmtQty(line.ordered_qty)} / {fmtQty(line.requested_qty)}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </div>
      </PopoverContent>
    </Popover>
  );
}

import { useRef } from 'react';
import { useTranslation } from 'react-i18next';

import type { OrderStatus, OrderStatusCounts } from '@/features/orders/types/order';
import { STATUS_TAB_ORDER } from '@/features/orders/types/order';
import { cn } from '@/lib/utils';

type StatusTab = OrderStatus | 'all';

type OrderStatusTabsProps = {
  activeStatus: StatusTab;
  counts: OrderStatusCounts;
  onChange: (status: StatusTab) => void;
};

/**
 * DD-010 / DD-011 — horizontally scrollable, sticky status tabs.
 * Tab order is fixed per specification and must not be changed.
 */
export function OrderStatusTabs({ activeStatus, counts, onChange }: OrderStatusTabsProps) {
  const { t } = useTranslation('orders');
  const scrollRef = useRef<HTMLDivElement>(null);

  return (
    /* sticky: consumers wrap this in a position:sticky container */
    <div
      ref={scrollRef}
      role="tablist"
      aria-label={t('statusTabs.label')}
      className="flex items-end gap-0 overflow-x-auto scrollbar-none border-b bg-background"
    >
      {STATUS_TAB_ORDER.map((tab) => {
        const isActive = tab === activeStatus;
        const count = counts[tab as StatusTab] ?? 0;

        return (
          <button
            key={tab}
            role="tab"
            type="button"
            aria-selected={isActive}
            onClick={() => onChange(tab as StatusTab)}
            className={cn(
              'relative flex shrink-0 items-center gap-1.5 px-3.5 py-2.5 text-sm font-medium',
              'whitespace-nowrap transition-colors outline-none select-none',
              'after:absolute after:inset-x-0 after:-bottom-px after:h-0.5 after:rounded-full after:transition-colors',
              isActive
                ? 'text-foreground after:bg-primary'
                : 'text-muted-foreground hover:text-foreground after:bg-transparent',
            )}
          >
            {t(`statusTabs.${tab}`)}
            <span
              className={cn(
                'inline-flex h-4 min-w-4 items-center justify-center rounded-full px-1',
                'text-[10px] font-semibold tabular-nums',
                isActive
                  ? 'bg-primary/15 text-primary'
                  : 'bg-muted text-muted-foreground',
              )}
            >
              {count > 999 ? '999+' : count}
            </span>
          </button>
        );
      })}
    </div>
  );
}

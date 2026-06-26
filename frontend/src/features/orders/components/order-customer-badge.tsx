import { useEffect, useRef, useState } from 'react';
import { ExternalLink, User } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { useCustomerOrderStats } from '@/features/orders/hooks/use-orders';
import type { OrderCustomer } from '@/features/orders/types/order';
import { cn } from '@/lib/utils';

type Props = { customer: OrderCustomer };

function formatAmount(n: number) {
  return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/**
 * DD-023 Customer Badge — small clickable icon beside the customer name.
 * On click, opens a popover that lazy-fetches the customer's order stats.
 */
export function OrderCustomerBadge({ customer }: Props) {
  const { t } = useTranslation('orders');
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);

  const { data, isLoading } = useCustomerOrderStats(open ? customer.id : null);

  // close on outside click
  useEffect(() => {
    if (!open) return;
    const handle = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener('mousedown', handle);
    return () => document.removeEventListener('mousedown', handle);
  }, [open]);

  // close on Escape
  useEffect(() => {
    if (!open) return;
    const handle = (e: KeyboardEvent) => { if (e.key === 'Escape') setOpen(false); };
    document.addEventListener('keydown', handle);
    return () => document.removeEventListener('keydown', handle);
  }, [open]);

  const isVip  = (data?.total ?? 0) >= 10;
  const isFreq = (data?.total ?? 0) >= 5;

  return (
    <div ref={ref} className="relative inline-flex shrink-0">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        aria-label={`${customer.name} — ${t('customerBadge.viewStats')}`}
        aria-expanded={open}
        className={cn(
          'inline-flex items-center gap-0.5 rounded-full border px-1.5 py-0.5 text-[10px] font-medium transition-colors',
          open
            ? 'border-primary/40 bg-primary/10 text-primary'
            : 'border-border text-muted-foreground hover:border-primary/30 hover:bg-accent hover:text-foreground',
        )}
      >
        <User className="size-2.5" />
      </button>

      {open ? (
        <div
          role="dialog"
          aria-label={`${customer.name} — ${t('customerBadge.viewStats')}`}
          className="absolute start-0 top-full z-50 mt-1.5 w-56 rounded-lg border bg-popover text-popover-foreground shadow-lg"
        >
          {/* Header */}
          <div className="border-b px-3 py-2.5">
            <p className="text-xs font-semibold leading-tight">{customer.name}</p>
            <p className="font-mono text-[10px] text-muted-foreground">{customer.code}</p>
          </div>

          {/* Stats */}
          <div className="p-3">
            {isLoading ? (
              <p className="text-xs text-muted-foreground">{t('customerBadge.loading')}</p>
            ) : data ? (
              <>
                {/* Badges */}
                {(isVip || isFreq) ? (
                  <div className="mb-2.5 flex flex-wrap gap-1">
                    {isVip ? (
                      <span className="inline-flex items-center gap-0.5 rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">
                        ⭐ {t('customerBadge.vip')}
                      </span>
                    ) : null}
                    {!isVip && isFreq ? (
                      <span className="inline-flex items-center gap-0.5 rounded-full bg-blue-100 px-1.5 py-0.5 text-[10px] font-semibold text-blue-700 dark:bg-blue-900/30 dark:text-blue-400">
                        🔁 {t('customerBadge.returning')}
                      </span>
                    ) : null}
                  </div>
                ) : null}

                <dl className="grid grid-cols-2 gap-x-3 gap-y-2 text-xs">
                  <div>
                    <dt className="text-muted-foreground">{t('customerBadge.totalOrders')}</dt>
                    <dd className="font-semibold tabular-nums">{data.total}</dd>
                  </div>
                  <div>
                    <dt className="text-muted-foreground">{t('customerBadge.completedOrders')}</dt>
                    <dd className="font-semibold tabular-nums text-green-600 dark:text-green-400">{data.completed}</dd>
                  </div>
                  <div>
                    <dt className="text-muted-foreground">{t('customerBadge.cancelledOrders')}</dt>
                    <dd className="font-semibold tabular-nums text-red-500 dark:text-red-400">{data.cancelled}</dd>
                  </div>
                  <div>
                    <dt className="text-muted-foreground">{t('customerBadge.totalSpend')}</dt>
                    <dd className="font-semibold tabular-nums">{formatAmount(data.totalSpend)}</dd>
                  </div>
                  {data.lastOrderDate ? (
                    <div className="col-span-2">
                      <dt className="text-muted-foreground">{t('customerBadge.lastOrder')}</dt>
                      <dd className="font-medium">{data.lastOrderDate}</dd>
                    </div>
                  ) : null}
                </dl>
              </>
            ) : null}
          </div>

          {/* Footer */}
          <div className="border-t px-3 py-2">
            <a
              href={`/customers/${customer.id}`}
              className="inline-flex items-center gap-1 text-xs text-primary hover:underline"
            >
              <ExternalLink className="size-3" />
              {t('customerBadge.openProfile')}
            </a>
          </div>
        </div>
      ) : null}
    </div>
  );
}

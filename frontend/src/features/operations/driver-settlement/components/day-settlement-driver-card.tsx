import { useTranslation } from 'react-i18next';
import { ChevronRight, ClipboardCheck, TriangleAlert } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { useFormatter } from '@/hooks/use-formatter';
import type { DaySettlementDriverRow } from '../types/driver-settlement';

function Metric({ label, value, sub, tone }: { label: string; value: string; sub?: string; tone?: string }) {
  return (
    <div className="rounded-md border bg-muted/20 px-2 py-1.5">
      <p className="truncate text-[10px] uppercase tracking-wide text-muted-foreground">{label}</p>
      <p className={`text-sm font-semibold leading-tight tabular-nums break-words ${tone ?? ''}`}>{value}</p>
      {sub ? <p className="text-[10px] tabular-nums text-muted-foreground">{sub}</p> : null}
    </div>
  );
}

/**
 * The mobile / tablet (< lg) presentation of ONE Driver Closing custody — final CTO table UX.
 *
 * Operational summary centred on the Driver's active custody: Driver/Trip (NO vehicle), order
 * counts + values, delivery rate, the financial line (Total Sales, Transfers/Paid, Expenses,
 * Net Cash) and Goods Remaining. Vehicle, damage and shortage are NOT primary mobile metrics —
 * they live in the Settlement (تصفية) detail. The primary action opens that same canonical detail
 * (navigation, not an auto-close). Expenses / Net Cash are honest "Not available" until the
 * canonical cash-movement authority exists — never a fabricated zero.
 */
export function DaySettlementDriverCard({
  row,
  onOpen,
}: {
  row: DaySettlementDriverRow;
  onOpen: (row: DaySettlementDriverRow) => void;
}) {
  const { t } = useTranslation('logistics');
  const { money } = useFormatter();

  return (
    <div role="listitem" className="space-y-2.5 border-b p-3.5 last:border-0">
      {/* Driver / Trip (no vehicle, no Status field — §32) */}
      <div className="min-w-0">
        <p className="truncate text-sm font-semibold">
          {row.driver_name ?? (
            <span className="text-muted-foreground">{t(($) => $.driverSettlement.unknownDriver)}</span>
          )}
        </p>
        <p className="mt-0.5 font-mono text-[11px] text-muted-foreground">{row.trip_number ?? row.operational_date}</p>
      </div>

      {/* Single-active-custody invariant violation — surfaced, never hidden (§13). */}
      {row.duplicate_open_custody ? (
        <div className="flex items-center gap-1.5 rounded-md border border-orange-500/40 bg-orange-500/10 px-2 py-1 text-[11px] font-medium text-orange-700 dark:text-orange-400">
          <TriangleAlert className="h-3.5 w-3.5 shrink-0" aria-hidden />
          {t(($) => $.driverSettlement.duplicateCustody)}
        </div>
      ) : null}

      {/* Orders — total / delivered / exceptions, each count + value */}
      <div className="grid grid-cols-3 gap-2">
        <Metric label={t(($) => $.driverSettlement.columns.totalOrders)} value={String(row.orders)} sub={money(row.orders_value)} />
        <Metric label={t(($) => $.driverSettlement.columns.delivered)} value={String(row.delivered)} sub={money(row.delivered_value)} />
        <Metric
          label={t(($) => $.driverSettlement.columns.failed)}
          value={String(row.failed)}
          sub={money(row.failed_value)}
          tone={row.failed > 0 ? 'text-destructive' : undefined}
        />
      </div>

      {/* Delivery rate + goods remaining with driver */}
      <div className="grid grid-cols-2 gap-2">
        <Metric label={t(($) => $.driverSettlement.columns.deliveryPct)} value={`${row.delivery_pct}%`} />
        <Metric label={t(($) => $.driverSettlement.columns.goodsRemaining)} value={String(row.goods_on_hand)} />
      </div>

      {/* Financial — Total Sales, Transfers/Paid, Expenses + Net Cash (canonical approved movements) */}
      <div className="grid grid-cols-2 gap-2">
        <Metric label={t(($) => $.driverSettlement.columns.totalSales)} value={money(row.total_sales)} />
        <Metric label={t(($) => $.driverSettlement.columns.transfersPaid)} value={money(row.transfers_paid)} />
        <Metric
          label={t(($) => $.driverSettlement.cards.expenses)}
          value={money(row.expenses)}
          sub={row.pending_movements > 0 ? t(($) => $.driverSettlement.movements.pendingCount, { count: row.pending_movements }) : undefined}
          tone={row.expenses > 0 ? 'text-destructive' : undefined}
        />
        <Metric label={t(($) => $.driverSettlement.cards.netCash)} value={money(row.net_cash)} />
      </div>

      {/* Settlement action — navigation into the canonical closing detail (no Status field, §32) */}
      <div className="flex items-center justify-end pt-0.5">
        <Button variant="outline" size="sm" className="h-8 gap-1.5 text-xs" onClick={() => onOpen(row)}>
          <ClipboardCheck className="h-3.5 w-3.5" />
          {t(($) => $.driverSettlement.settlement)}
          <ChevronRight className="h-3.5 w-3.5" data-flip-rtl aria-hidden />
        </Button>
      </div>
    </div>
  );
}

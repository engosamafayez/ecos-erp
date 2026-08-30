import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { AlertTriangle, ArrowLeft, FileText } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { useFormatter } from '@/hooks/use-formatter';
import { cn } from '@/lib/utils';
import { ROUTES } from '@/router/routes';

import { useDriverStatement } from '../hooks/use-driver-mobile';
import type { SettlementRollupStatus } from '../types/reports';

const STATUS_TONE: Record<SettlementRollupStatus, string> = {
  needs_review: 'bg-muted text-muted-foreground',
  under_review: 'bg-amber-100 text-amber-700',
  disputed: 'bg-red-100 text-red-700',
  settled: 'bg-green-100 text-green-700',
};

function currentMonth(): string {
  const now = new Date();
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
}

/**
 * Monthly Driver Statement (§12) — a permanent, read-only monthly summary. Every figure is the
 * server-composed statement read model (orders, collections, cash, settlement status); NO Finance
 * journal, NO React aggregation. Advances/expenses are surfaced as unavailable, never fabricated.
 */
export function DriverStatementPage() {
  const { t } = useTranslation('driver-mobile');
  const { money } = useFormatter();
  const navigate = useNavigate();
  const [month, setMonth] = useState<string>(currentMonth);

  const { data, isLoading, isError, refetch } = useDriverStatement(month);

  return (
    <div className="min-h-screen bg-background pb-8">
      <div className="sticky top-0 z-10 flex items-center gap-3 border-b bg-background px-4 py-3">
        <Button variant="ghost" size="icon" aria-label={t(($) => $.nav.home)} onClick={() => navigate(ROUTES.driverWallet)}>
          <ArrowLeft className="h-5 w-5" aria-hidden="true" />
        </Button>
        <h1 className="flex items-center gap-2 text-base font-semibold">
          <FileText className="h-5 w-5" aria-hidden="true" />
          {t(($) => $.statement.title)}
        </h1>
      </div>

      <div className="space-y-4 p-4">
        <div className="flex items-center gap-2">
          <label htmlFor="statement-month" className="text-xs text-muted-foreground">{t(($) => $.statement.month)}</label>
          <Input
            id="statement-month"
            type="month"
            value={month}
            max={currentMonth()}
            onChange={(e) => setMonth(e.target.value || currentMonth())}
            className="h-9 w-auto text-xs"
          />
        </div>

        {isLoading ? (
          <>
            <Skeleton className="h-28 w-full rounded-xl" />
            <Skeleton className="h-24 w-full rounded-xl" />
          </>
        ) : isError || !data ? (
          <div className="flex flex-col items-center gap-3 py-14 text-muted-foreground">
            <AlertTriangle className="h-9 w-9 text-destructive/70" aria-hidden="true" />
            <p className="text-sm">{t(($) => $.statement.error)}</p>
            <Button variant="outline" size="sm" onClick={() => void refetch()}>{t(($) => $.statement.retry)}</Button>
          </div>
        ) : (
          <>
            {/* Orders */}
            <div className="rounded-xl border bg-card p-4">
              <p className="mb-3 text-sm font-semibold">{t(($) => $.statement.orders)}</p>
              <div className="grid grid-cols-3 gap-2 text-center">
                <Cell label={t(($) => $.reports.orders.received)} value={data.orders.received} />
                <Cell label={t(($) => $.reports.orders.delivered)} value={data.orders.delivered} tone="text-green-600" />
                <Cell label={t(($) => $.reports.orders.deliveryRate)} value={`${data.orders.delivery_rate}%`} />
              </div>
            </div>

            {/* Collections + cash */}
            <div className="rounded-xl border bg-card p-4 space-y-1.5">
              <p className="mb-1 text-sm font-semibold">{t(($) => $.wallet.collections)}</p>
              <Line label={t(($) => $.wallet.total)} value={money(data.collections.total)} />
              <Line label={t(($) => $.wallet.cash)} value={money(data.collections.cash)} />
              <Line label={t(($) => $.wallet.transfer)} value={money(data.collections.transfer)} />
              <Line label={t(($) => $.wallet.expected)} value={money(data.cash.expected)} />
              {data.cash.difference !== null && (
                <Line
                  label={t(($) => $.wallet.difference)}
                  value={money(data.cash.difference)}
                  tone={data.cash.is_balanced ? 'text-green-600' : 'text-red-600'}
                />
              )}
            </div>

            {/* Settlement + shortages */}
            <div className="rounded-xl border bg-card p-4 flex items-center justify-between">
              <div>
                <p className="text-sm font-semibold">{t(($) => $.wallet.closing)}</p>
                <p className="text-xs text-muted-foreground">
                  {t(($) => $.statement.shortages, { count: data.shortages_count })}
                </p>
              </div>
              <span className={cn('rounded-full px-2 py-0.5 text-[11px] font-medium', STATUS_TONE[data.settlement_status])}>
                {t(($) => $.wallet.settlementStatus[data.settlement_status])}
              </span>
            </div>

            {/* Advances/expenses — unavailable, honest (§5/§8). */}
            <div className="rounded-xl border border-dashed p-4 text-xs text-muted-foreground space-y-1">
              <p>{t(($) => $.wallet.advancesUnavailable)}</p>
              <p>{t(($) => $.wallet.expensesUnavailable)}</p>
            </div>
          </>
        )}
      </div>
    </div>
  );
}

function Cell({ label, value, tone }: { label: string; value: number | string; tone?: string }) {
  return (
    <div className="rounded-lg bg-muted/40 py-2">
      <p className={cn('text-lg font-bold tabular-nums leading-none', tone)}>{value}</p>
      <p className="mt-1 text-[11px] text-muted-foreground">{label}</p>
    </div>
  );
}

function Line({ label, value, tone }: { label: string; value: string; tone?: string }) {
  return (
    <div className="flex justify-between text-sm">
      <span className="text-muted-foreground">{label}</span>
      <span className={cn('tabular-nums', tone)}>{value}</span>
    </div>
  );
}

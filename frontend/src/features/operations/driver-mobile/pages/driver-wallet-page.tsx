import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { AlertTriangle, ArrowLeft, CheckCircle2, Circle, FileText, Wallet } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { useFormatter } from '@/hooks/use-formatter';
import { cn } from '@/lib/utils';
import { ROUTES } from '@/router/routes';

import { ReportPeriodFilter } from '../components/report-period-filter';
import { useDriverWallet } from '../hooks/use-driver-mobile';
import type { ReportPeriodValue, SettlementRollupStatus } from '../types/reports';

const STATUS_TONE: Record<SettlementRollupStatus, string> = {
  needs_review: 'bg-muted text-muted-foreground',
  under_review: 'bg-amber-100 text-amber-700',
  disputed: 'bg-red-100 text-red-700',
  settled: 'bg-green-100 text-green-700',
};

/**
 * Driver Wallet — the operational financial summary for a period (§2) + closing status (§13).
 * Every figure is server-derived (driver-scoped, per-trip settlement summed server-side); this
 * page renders read-only truth and never aggregates across trips itself, and never posts money.
 */
export function DriverWalletPage() {
  const { t } = useTranslation('driver-mobile');
  const { money } = useFormatter();
  const navigate = useNavigate();
  const [period, setPeriod] = useState<ReportPeriodValue>({ period: 'this_month' });

  const { data, isLoading, isError, refetch } = useDriverWallet(period);

  return (
    <div className="min-h-screen bg-background pb-8">
      <div className="sticky top-0 z-10 flex items-center gap-3 border-b bg-background px-4 py-3">
        <Button variant="ghost" size="icon" aria-label={t(($) => $.nav.home)} onClick={() => navigate(ROUTES.driverHome)}>
          <ArrowLeft className="h-5 w-5" aria-hidden="true" />
        </Button>
        <h1 className="flex items-center gap-2 text-base font-semibold">
          <Wallet className="h-5 w-5" aria-hidden="true" />
          {t(($) => $.wallet.title)}
        </h1>
      </div>

      <div className="space-y-4 p-4">
        <ReportPeriodFilter value={period} onChange={setPeriod} />

        {isLoading ? (
          <>
            <Skeleton className="h-28 w-full rounded-xl" />
            <Skeleton className="h-24 w-full rounded-xl" />
          </>
        ) : isError || !data ? (
          <div className="flex flex-col items-center gap-3 py-14 text-muted-foreground">
            <AlertTriangle className="h-9 w-9 text-destructive/70" aria-hidden="true" />
            <p className="text-sm">{t(($) => $.wallet.error)}</p>
            <Button variant="outline" size="sm" onClick={() => void refetch()}>{t(($) => $.wallet.retry)}</Button>
          </div>
        ) : (
          <>
            {/* Collections */}
            <div className="rounded-xl border bg-card p-4">
              <div className="mb-3 flex items-center justify-between">
                <p className="text-sm font-semibold">{t(($) => $.wallet.collections)}</p>
                <Badge variant="secondary">{t(($) => $.wallet.trips, { count: data.trips })}</Badge>
              </div>
              <p className="text-2xl font-bold tabular-nums">{money(data.collections.total)}</p>
              <p className="mb-3 text-xs text-muted-foreground">{t(($) => $.wallet.total)}</p>
              <div className="grid grid-cols-2 gap-2 text-sm">
                <Row label={t(($) => $.wallet.cash)} value={money(data.collections.cash)} />
                <Row label={t(($) => $.wallet.transfer)} value={money(data.collections.transfer)} />
                <Row label={t(($) => $.wallet.card)} value={money(data.collections.card)} />
                <Row label={t(($) => $.wallet.alreadyPaid)} value={money(data.collections.already_paid)} />
              </div>
            </div>

            {/* Cash reconciliation */}
            <div className="rounded-xl border bg-card p-4 space-y-1.5">
              <p className="mb-1 text-sm font-semibold">{t(($) => $.wallet.cashTitle)}</p>
              <Line label={t(($) => $.wallet.expected)} value={money(data.cash.expected)} />
              <Line
                label={t(($) => $.wallet.submitted)}
                value={data.cash.submitted === null ? t(($) => $.wallet.notSubmitted) : money(data.cash.submitted)}
              />
              {data.cash.difference !== null && (
                <Line
                  label={t(($) => $.wallet.difference)}
                  value={money(data.cash.difference)}
                  tone={data.cash.is_balanced ? 'text-green-600' : 'text-red-600'}
                />
              )}
            </div>

            {/* Settlement + closing status */}
            <div className="rounded-xl border bg-card p-4">
              <div className="mb-3 flex items-center justify-between">
                <p className="text-sm font-semibold">{t(($) => $.wallet.closing)}</p>
                <span className={cn('rounded-full px-2 py-0.5 text-[11px] font-medium', STATUS_TONE[data.settlement_status])}>
                  {t(($) => $.wallet.settlementStatus[data.settlement_status])}
                </span>
              </div>
              <div className="space-y-2">
                <Flag ok={data.closing.custody_reconciled} label={t(($) => $.wallet.closingFlags.custodyReconciled)} />
                <Flag ok={data.closing.deliveries_outstanding === 0} label={t(($) => $.wallet.closingFlags.deliveries)} />
                <Flag ok={data.closing.all_trips_closed} label={t(($) => $.wallet.closingFlags.tripsClosed)} />
                <Flag ok={data.closing.settlement_complete} label={t(($) => $.wallet.closingFlags.settlementComplete)} />
              </div>
            </div>

            {/* Monthly statement (§12) — a permanent read-model surface. */}
            <Button variant="outline" className="w-full" onClick={() => navigate(ROUTES.driverStatement)}>
              <FileText className="mr-2 h-4 w-4" aria-hidden="true" />
              {t(($) => $.wallet.statementLink)}
            </Button>

            {/* Advances / expenses — no canonical driver authority (§5/§8), surfaced honestly. */}
            {(!data.advances.available || !data.expenses.available) && (
              <div className="rounded-xl border border-dashed p-4 text-xs text-muted-foreground space-y-1">
                {!data.advances.available && <p>{t(($) => $.wallet.advancesUnavailable)}</p>}
                {!data.expenses.available && <p>{t(($) => $.wallet.expensesUnavailable)}</p>}
              </div>
            )}
          </>
        )}
      </div>
    </div>
  );
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex flex-col rounded-lg bg-muted/40 px-3 py-2">
      <span className="text-sm font-semibold tabular-nums">{value}</span>
      <span className="text-[11px] text-muted-foreground">{label}</span>
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

function Flag({ ok, label }: { ok: boolean; label: string }) {
  return (
    <div className="flex items-center gap-2 text-sm">
      {ok ? (
        <CheckCircle2 className="h-4 w-4 text-green-600" aria-hidden="true" />
      ) : (
        <Circle className="h-4 w-4 text-muted-foreground/50" aria-hidden="true" />
      )}
      <span className={ok ? '' : 'text-muted-foreground'}>{label}</span>
    </div>
  );
}

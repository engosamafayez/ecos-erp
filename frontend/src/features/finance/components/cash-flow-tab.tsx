import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { UniversalDataGrid, type DataGridColumnDef } from '@/components/data-grid';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { usePermission } from '@/features/authorization';
import { useFormatter } from '@/hooks/use-formatter';
import { cn } from '@/lib/utils';

import { FinanceIntelligenceStateCard } from './finance-intelligence-status';
import { NoAccess, Stat } from './finance-panels';
import { useCashFlowCurrent, useCashFlowForecast } from '../hooks/use-finance-intelligence';
import type {
  CashFlowForecastSchedule,
  CashFlowLiquidityMonth,
  CashFlowLiquidityProjection,
  CashFlowRiskAlert,
} from '../types/finance-intelligence';

const HORIZON_OPTIONS = [1, 3, 6, 12];

/**
 * Cash Flow — one of the "Costing & Profitability" page's tabs (assembled by
 * the page, alongside ProfitabilityTab, CostIntelligenceTab and
 * CostAllocationTab; this file is not a page).
 *
 * `current` and `forecast` do NOT share a from/to control the way
 * profitability's dimensions do — confirmed from CashFlowController:
 *   - current  takes NO query params at all (always "as of today" / month-to-date).
 *   - forecast takes `horizon` (a months count, default 3) — not from/to.
 * so this tab gives forecast its own horizon selector instead of reusing
 * FinanceIntelligenceDateRange, which would not match either endpoint's contract.
 */
export function CashFlowTab() {
  const { t } = useTranslation('finance');
  const { can } = usePermission();

  if (!can('finance.analytics.view')) {
    return <NoAccess />;
  }

  return (
    <Tabs defaultValue="current">
      <TabsList>
        <TabsTrigger value="current">{t(($) => $.cashFlow.tab.current)}</TabsTrigger>
        <TabsTrigger value="forecast">{t(($) => $.cashFlow.tab.forecast)}</TabsTrigger>
      </TabsList>

      <TabsContent value="current" className="mt-4">
        <CurrentPositionView />
      </TabsContent>
      <TabsContent value="forecast" className="mt-4">
        <ForecastView />
      </TabsContent>
    </Tabs>
  );
}

// ── Current position — REAL data; takes no params at all ────────────────────

function CurrentPositionView() {
  const { t } = useTranslation('finance');
  const fmt = useFormatter();
  const query = useCashFlowCurrent();
  const data = query.data;

  if (!data) {
    return (
      <FinanceIntelligenceStateCard
        loading={query.isLoading}
        error={query.isError}
        loadingLabel={t(($) => $.loading)}
        errorLabel={t(($) => $.cashFlow.error)}
      />
    );
  }

  return (
    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <Stat label={t(($) => $.cashFlow.field.cashPosition)} value={fmt.money(data.cash_position)} />
      <Stat label={t(($) => $.cashFlow.field.receivables)} value={fmt.money(data.receivables)} />
      <Stat label={t(($) => $.cashFlow.field.payables)} value={fmt.money(data.payables)} />
      <Stat
        label={t(($) => $.cashFlow.field.monthToDateOperating)}
        value={fmt.money(data.month_to_date_operating)}
        tone={data.month_to_date_operating < 0 ? 'danger' : undefined}
      />
    </div>
  );
}

// ── Forecast — REAL data; horizon (months), not from/to ──────────────────────

function ForecastView() {
  const { t } = useTranslation('finance');
  const [horizon, setHorizon] = useState(3);
  const query = useCashFlowForecast({ horizon });
  const data = query.data;

  return (
    <div className="space-y-4">
      <HorizonControl value={horizon} onChange={setHorizon} />

      {!data ? (
        <FinanceIntelligenceStateCard
          loading={query.isLoading}
          error={query.isError}
          loadingLabel={t(($) => $.loading)}
          errorLabel={t(($) => $.cashFlow.error)}
        />
      ) : (
        <>
          <LiquidityProjectionTable projection={data.liquidity_projection} />

          <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <ScheduleCard title={t(($) => $.cashFlow.schedule.receivable)} schedule={data.receivable_forecast} />
            <ScheduleCard title={t(($) => $.cashFlow.schedule.payable)} schedule={data.payable_forecast} />
          </div>
          <p className="text-xs text-muted-foreground">{t(($) => $.cashFlow.methodNote)}</p>

          <RiskAlertsList alerts={data.risk_alerts} />
        </>
      )}
    </div>
  );
}

function HorizonControl({ value, onChange }: { value: number; onChange: (v: number) => void }) {
  const { t } = useTranslation('finance');
  return (
    <div className="flex flex-col gap-1.5">
      <Label htmlFor="cash-flow-horizon">{t(($) => $.cashFlow.forecast.horizonLabel)}</Label>
      <Select value={String(value)} onValueChange={(v) => onChange(Number(v))}>
        <SelectTrigger id="cash-flow-horizon" className="w-32"><SelectValue /></SelectTrigger>
        <SelectContent>
          {HORIZON_OPTIONS.map((h) => (
            <SelectItem key={h} value={String(h)}>{h}</SelectItem>
          ))}
        </SelectContent>
      </Select>
    </div>
  );
}

function LiquidityProjectionTable({ projection }: { projection: CashFlowLiquidityProjection }) {
  const { t } = useTranslation('finance');
  const fmt = useFormatter();

  const columns = useMemo<DataGridColumnDef<CashFlowLiquidityMonth>[]>(() => [
    { key: 'month', label: t(($) => $.cashFlow.field.month), pin: 'left', cell: (r) => r.month },
    { key: 'operating_flow', label: t(($) => $.cashFlow.field.operatingFlow), align: 'end', cell: (r) => <span className="tabular-nums">{fmt.money(r.operating_flow)}</span> },
    { key: 'collections', label: t(($) => $.cashFlow.field.collections), align: 'end', cell: (r) => <span className="tabular-nums">{fmt.money(r.collections)}</span> },
    { key: 'payments', label: t(($) => $.cashFlow.field.payments), align: 'end', cell: (r) => <span className="tabular-nums">{fmt.money(r.payments)}</span> },
    {
      key: 'net_flow', label: t(($) => $.cashFlow.field.netFlow), align: 'end',
      cell: (r) => <span className={cn('tabular-nums font-medium', r.net_flow < 0 && 'text-red-600')}>{fmt.money(r.net_flow)}</span>,
    },
    {
      key: 'closing_cash', label: t(($) => $.cashFlow.field.closingCash), align: 'end',
      cell: (r) => <span className={cn('tabular-nums font-semibold', r.closing_cash < 0 && 'text-red-600')}>{fmt.money(r.closing_cash)}</span>,
    },
  ], [t, fmt]);

  return (
    <div className="space-y-2">
      <div className="text-sm">
        <span className="text-muted-foreground">{t(($) => $.cashFlow.field.openingCash)}: </span>
        <span className="tabular-nums font-medium">{fmt.money(projection.opening_cash)}</span>
      </div>
      <UniversalDataGrid
        data={projection.months}
        columns={columns}
        rowId={(r) => r.month}
        emptyState={<p className="py-10 text-center text-sm text-muted-foreground">{t(($) => $.cashFlow.empty)}</p>}
      />
    </div>
  );
}

function ScheduleCard({ title, schedule }: { title: string; schedule: CashFlowForecastSchedule }) {
  const fmt = useFormatter();

  return (
    <div className="rounded-lg border p-4">
      <h3 className="text-sm font-medium">{title}</h3>
      <p className="mt-1 text-2xl font-semibold tabular-nums">{fmt.money(schedule.total)}</p>
      <ul className="mt-3 space-y-1 text-sm">
        {schedule.schedule.map((m) => (
          <li key={m.month} className="flex items-center justify-between">
            <span className="text-muted-foreground">{m.month}</span>
            <span className="tabular-nums">{fmt.money(m.amount)}</span>
          </li>
        ))}
      </ul>
    </div>
  );
}

function RiskAlertsList({ alerts }: { alerts: CashFlowRiskAlert[] }) {
  const { t } = useTranslation('finance');

  return (
    <div className="rounded-lg border p-4">
      <h3 className="mb-2 text-sm font-medium">{t(($) => $.cashFlow.risk.title)}</h3>
      {alerts.length === 0 ? (
        <p className="text-sm text-muted-foreground">{t(($) => $.cashFlow.risk.empty)}</p>
      ) : (
        <ul className="space-y-2">
          {alerts.map((a, i) => (
            <li key={`${a.key}-${i}`} className="flex items-start gap-2 text-sm">
              <span className={cn('mt-1.5 size-2 shrink-0 rounded-full', a.severity === 'critical' ? 'bg-red-500' : 'bg-amber-500')} aria-hidden />
              <div>
                <div className="font-medium">
                  {a.severity === 'critical'
                    ? t(($) => $.cashFlow.risk.severity.critical)
                    : a.severity === 'warning'
                      ? t(($) => $.cashFlow.risk.severity.warning)
                      : a.severity}
                </div>
                <div className="text-muted-foreground">{a.message}</div>
              </div>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

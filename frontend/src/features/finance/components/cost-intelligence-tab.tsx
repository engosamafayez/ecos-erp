import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { UniversalDataGrid, type DataGridColumnDef } from '@/components/data-grid';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { usePermission } from '@/features/authorization';
import { useFormatter } from '@/hooks/use-formatter';

import { FinanceIntelligenceDateRange } from './finance-intelligence-date-range';
import { FinanceIntelligenceStateCard } from './finance-intelligence-status';
import { NoAccess, Stat } from './finance-panels';
import { useCostBreakdown, useCostOperational, useCostTrend } from '../hooks/use-finance-intelligence';
import type {
  CostAccountTotal,
  CostOperationalBucketKey,
  CostTrendPoint,
  FinanceIntelligenceWindowParams,
} from '../types/finance-intelligence';

const TREND_MONTH_OPTIONS = [3, 6, 12, 24];
const OPERATIONAL_BUCKET_KEYS: CostOperationalBucketKey[] = ['manufacturing', 'logistics', 'marketing', 'administrative', 'other'];
const CLASSIFIED_BUCKET_KEYS: Array<'manufacturing' | 'logistics' | 'marketing' | 'administrative'> = ['manufacturing', 'logistics', 'marketing', 'administrative'];

type CostTabKey = 'breakdown' | 'operational' | 'trend';

/**
 * Cost Intelligence — one of the "Costing & Profitability" page's tabs
 * (assembled by the page, alongside ProfitabilityTab, CashFlowTab and
 * CostAllocationTab; this file is not a page).
 *
 * breakdown and operational share ONE from/to window (both
 * CostIntelligenceController methods call financeWindow()). trend does NOT:
 * CostIntelligenceController::trend() takes a `months` count only and never
 * reads from/to — so it gets its own control, not the shared date range.
 */
export function CostIntelligenceTab() {
  const { t } = useTranslation('finance');
  const { can } = usePermission();
  const [activeTab, setActiveTab] = useState<CostTabKey>('breakdown');
  const [range, setRange] = useState<FinanceIntelligenceWindowParams>({});
  const [months, setMonths] = useState(12);

  if (!can('finance.analytics.view')) {
    return <NoAccess />;
  }

  return (
    <div className="space-y-4">
      <Tabs value={activeTab} onValueChange={(v) => setActiveTab(v as CostTabKey)}>
        <TabsList>
          <TabsTrigger value="breakdown">{t(($) => $.costIntelligence.tab.breakdown)}</TabsTrigger>
          <TabsTrigger value="operational">{t(($) => $.costIntelligence.tab.operational)}</TabsTrigger>
          <TabsTrigger value="trend">{t(($) => $.costIntelligence.tab.trend)}</TabsTrigger>
        </TabsList>

        <div className="mt-4">
          {activeTab === 'trend' ? (
            <TrendMonthsControl value={months} onChange={setMonths} />
          ) : (
            <FinanceIntelligenceDateRange idPrefix="cost-intelligence" value={range} onChange={setRange} />
          )}
        </div>

        <TabsContent value="breakdown" className="mt-4">
          <BreakdownView range={range} />
        </TabsContent>
        <TabsContent value="operational" className="mt-4">
          <OperationalView range={range} />
        </TabsContent>
        <TabsContent value="trend" className="mt-4">
          <TrendView months={months} />
        </TabsContent>
      </Tabs>
    </div>
  );
}

function TrendMonthsControl({ value, onChange }: { value: number; onChange: (v: number) => void }) {
  const { t } = useTranslation('finance');
  return (
    <div className="flex flex-col gap-1.5">
      <Label htmlFor="cost-trend-months">{t(($) => $.costIntelligence.filter.months)}</Label>
      <Select value={String(value)} onValueChange={(v) => onChange(Number(v))}>
        <SelectTrigger id="cost-trend-months" className="w-32"><SelectValue /></SelectTrigger>
        <SelectContent>
          {TREND_MONTH_OPTIONS.map((m) => (
            <SelectItem key={m} value={String(m)}>{m}</SelectItem>
          ))}
        </SelectContent>
      </Select>
    </div>
  );
}

// ── Breakdown — REAL data ─────────────────────────────────────────────────────

function BreakdownView({ range }: { range: FinanceIntelligenceWindowParams }) {
  const { t } = useTranslation('finance');
  const fmt = useFormatter();
  const query = useCostBreakdown(range);
  const data = query.data;

  const columns = useMemo<DataGridColumnDef<CostAccountTotal>[]>(() => [
    { key: 'code', label: t(($) => $.gl.coa.field.code), pin: 'left', cell: (r) => <span className="tabular-nums">{r.code}</span> },
    { key: 'name', label: t(($) => $.gl.coa.field.name), cell: (r) => r.name },
    { key: 'amount', label: t(($) => $.costIntelligence.field.amount), align: 'end', cell: (r) => <span className="tabular-nums font-medium">{fmt.money(r.amount)}</span> },
  ], [t, fmt]);

  return (
    <div className="space-y-4">
      {data ? (
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <Stat label={t(($) => $.costIntelligence.field.totalCost)} value={fmt.money(data.total_cost)} />
          <Stat label={t(($) => $.costIntelligence.field.costOfSales)} value={fmt.money(data.by_category.cost_of_sales)} />
          <Stat label={t(($) => $.costIntelligence.field.operatingExpense)} value={fmt.money(data.by_category.operating_expense)} />
          <Stat label={t(($) => $.costIntelligence.field.otherExpense)} value={fmt.money(data.by_category.other_expense)} />
        </div>
      ) : (
        <FinanceIntelligenceStateCard
          loading={query.isLoading}
          error={query.isError}
          loadingLabel={t(($) => $.loading)}
          errorLabel={t(($) => $.costIntelligence.error)}
        />
      )}

      <UniversalDataGrid
        data={data?.by_account ?? []}
        columns={columns}
        rowId={(r) => String(r.account_id)}
        loading={query.isLoading}
        error={query.isError}
        emptyState={<p className="py-10 text-center text-sm text-muted-foreground">{t(($) => $.costIntelligence.empty)}</p>}
      />
    </div>
  );
}

// ── Operational classification — REAL data (deterministic keyword rules) ────

function OperationalView({ range }: { range: FinanceIntelligenceWindowParams }) {
  const { t } = useTranslation('finance');
  const fmt = useFormatter();
  const query = useCostOperational(range);
  const data = query.data;

  if (!data) {
    return (
      <FinanceIntelligenceStateCard
        loading={query.isLoading}
        error={query.isError}
        loadingLabel={t(($) => $.loading)}
        errorLabel={t(($) => $.costIntelligence.error)}
      />
    );
  }

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
        {OPERATIONAL_BUCKET_KEYS.map((k) => (
          <Stat key={k} label={t(($) => $.costIntelligence.bucket[k], { defaultValue: k })} value={fmt.money(data.buckets[k])} />
        ))}
      </div>

      {/* The keyword rules the classifier used — explainable, not a black box. */}
      <details className="rounded-lg border p-3 text-xs text-muted-foreground">
        <summary className="cursor-pointer font-medium text-foreground">{t(($) => $.costIntelligence.rulesLabel)}</summary>
        <ul className="mt-2 space-y-1">
          {CLASSIFIED_BUCKET_KEYS.map((bucket) => (
            <li key={bucket}>
              <span className="font-medium">{t(($) => $.costIntelligence.bucket[bucket], { defaultValue: bucket })}: </span>
              {(data.rules[bucket] ?? []).join(', ')}
            </li>
          ))}
        </ul>
      </details>
    </div>
  );
}

// ── Trend — REAL data; simpler {month,value} envelope, no chart library ─────

/**
 * Same projection idea as executive-trend-panel.tsx's sparkline — this
 * codebase has no charting library and this mirrors that restraint — adapted
 * for the simpler {month,value}[] shape CostIntelligenceService::trend()
 * returns (no last/change_pct/direction/explanation; a different, simpler
 * envelope than GET /finance/intelligence/trends, so ExecutiveTrendPanel's
 * props do not fit here).
 */
function sparklinePath(points: CostTrendPoint[]): string {
  if (points.length === 0) return '';

  const values = points.map((p) => p.value);
  const max = Math.max(...values);
  const min = Math.min(...values);
  const range = max - min;

  const x = (index: number) => (points.length === 1 ? 50 : (index / (points.length - 1)) * 100);
  const y = (value: number) => (range === 0 ? 16 : 32 - ((value - min) / range) * 28 - 2);

  if (points.length === 1) return `M 0 ${y(values[0])} L 100 ${y(values[0])}`;

  return points.map((p, i) => `${i === 0 ? 'M' : 'L'} ${x(i)} ${y(p.value)}`).join(' ');
}

function TrendView({ months }: { months: number }) {
  const { t } = useTranslation('finance');
  const fmt = useFormatter();
  const query = useCostTrend({ months });
  const series = query.data?.series ?? [];

  if (query.isLoading || query.isError) {
    return (
      <FinanceIntelligenceStateCard
        loading={query.isLoading}
        error={query.isError}
        loadingLabel={t(($) => $.loading)}
        errorLabel={t(($) => $.costIntelligence.error)}
      />
    );
  }

  if (series.length === 0) {
    return <p className="py-10 text-center text-sm text-muted-foreground">{t(($) => $.costIntelligence.empty)}</p>;
  }

  return (
    <div className="space-y-4">
      <div className="rounded-lg border p-4">
        <svg viewBox="0 0 100 32" preserveAspectRatio="none" className="h-16 w-full" role="img" aria-label={t(($) => $.costIntelligence.tab.trend)}>
          <path d={sparklinePath(series)} fill="none" strokeWidth={1.5} vectorEffect="non-scaling-stroke" className="stroke-primary" />
        </svg>
      </div>

      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="text-start text-xs text-muted-foreground">
              <th className="py-1 text-start font-medium">{t(($) => $.costIntelligence.trend.month)}</th>
              <th className="py-1 text-end font-medium">{t(($) => $.costIntelligence.field.amount)}</th>
            </tr>
          </thead>
          <tbody>
            {series.map((p) => (
              <tr key={p.month} className="border-t">
                <td className="py-1.5">{p.month}</td>
                <td className="py-1.5 text-end tabular-nums">{fmt.money(p.value)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

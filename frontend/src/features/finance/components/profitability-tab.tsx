import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { UseQueryResult } from '@tanstack/react-query';

import { UniversalDataGrid, type DataGridColumnDef } from '@/components/data-grid';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { usePermission } from '@/features/authorization';
import { useFormatter } from '@/hooks/use-formatter';
import { cn } from '@/lib/utils';

import { FinanceIntelligenceDateRange } from './finance-intelligence-date-range';
import { FinanceIntelligenceStateCard, FinanceIntelligenceUnavailableCard } from './finance-intelligence-status';
import { NoAccess, Stat } from './finance-panels';
import {
  useProfitabilityBranch,
  useProfitabilityChannel,
  useProfitabilityCompany,
  useProfitabilityCostCenter,
  useProfitabilityCustomer,
  useProfitabilityProduct,
  useProfitabilityProject,
} from '../hooks/use-finance-intelligence';
import type {
  ProfitabilityByDimension,
  FinanceIntelligenceWindowParams,
  ProfitabilityCustomerRow,
  ProfitabilityDimensionRow,
  ProfitabilityUnavailable,
} from '../types/finance-intelligence';

/**
 * Profitability — one of the "Costing & Profitability" page's tabs (assembled
 * by the page, alongside CostIntelligenceTab, CashFlowTab and
 * CostAllocationTab; this file is not a page and does not render a
 * WorkspaceHeader).
 *
 * Seven dimensions, each its own certified endpoint under
 * GET /finance/intelligence/profitability/*, all sharing ONE from/to
 * reporting window — confirmed from ProfitabilityController: every one of
 * company/branch/cost-center/project/customer/product/channel calls
 * `$this->financeWindow($request)`.
 *
 * ┌─ product / channel ARE NOT A BUG ───────────────────────────────────────┐
 * │ ProfitabilityService::byUntaggedDimension() deliberately returns          │
 * │ `available:false` because the ledger does not yet tag journal lines by    │
 * │ product or channel. That is rendered as an explicit, honest "not yet      │
 * │ available" card — never hidden, never an empty chart.                     │
 * └────────────────────────────────────────────────────────────────────────┘
 */
export function ProfitabilityTab() {
  const { t } = useTranslation('finance');
  const { can } = usePermission();
  const [range, setRange] = useState<FinanceIntelligenceWindowParams>({});

  if (!can('finance.analytics.view')) {
    return <NoAccess />;
  }

  return (
    <div className="space-y-4">
      <FinanceIntelligenceDateRange idPrefix="profitability" value={range} onChange={setRange} />

      <Tabs defaultValue="company">
        <TabsList>
          <TabsTrigger value="company">{t(($) => $.profitability.tab.company)}</TabsTrigger>
          <TabsTrigger value="branch">{t(($) => $.profitability.tab.branch)}</TabsTrigger>
          <TabsTrigger value="cost-center">{t(($) => $.profitability.tab.costCenter)}</TabsTrigger>
          <TabsTrigger value="project">{t(($) => $.profitability.tab.project)}</TabsTrigger>
          <TabsTrigger value="customer">{t(($) => $.profitability.tab.customer)}</TabsTrigger>
          <TabsTrigger value="product">{t(($) => $.profitability.tab.product)}</TabsTrigger>
          <TabsTrigger value="channel">{t(($) => $.profitability.tab.channel)}</TabsTrigger>
        </TabsList>

        <TabsContent value="company" className="mt-4">
          <CompanyView range={range} />
        </TabsContent>
        <TabsContent value="branch" className="mt-4">
          <BranchView range={range} />
        </TabsContent>
        <TabsContent value="cost-center" className="mt-4">
          <CostCenterView range={range} />
        </TabsContent>
        <TabsContent value="project" className="mt-4">
          <ProjectView range={range} />
        </TabsContent>
        <TabsContent value="customer" className="mt-4">
          <CustomerView range={range} />
        </TabsContent>
        <TabsContent value="product" className="mt-4">
          <ProductView range={range} />
        </TabsContent>
        <TabsContent value="channel" className="mt-4">
          <ChannelView range={range} />
        </TabsContent>
      </Tabs>
    </div>
  );
}

// ── Company — single object, REAL data ────────────────────────────────────────

function CompanyView({ range }: { range: FinanceIntelligenceWindowParams }) {
  const { t } = useTranslation('finance');
  const fmt = useFormatter();
  const query = useProfitabilityCompany(range);
  const data = query.data;

  if (!data) {
    return (
      <FinanceIntelligenceStateCard
        loading={query.isLoading}
        error={query.isError}
        loadingLabel={t(($) => $.loading)}
        errorLabel={t(($) => $.profitability.error)}
      />
    );
  }

  return (
    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <Stat label={t(($) => $.profitability.field.revenue)} value={fmt.money(data.revenue)} />
      <Stat label={t(($) => $.profitability.field.expense)} value={fmt.money(data.expense)} />
      <Stat label={t(($) => $.profitability.field.profit)} value={fmt.money(data.profit)} tone={data.profit < 0 ? 'danger' : undefined} />
      <Stat label={t(($) => $.profitability.field.marginPct)} value={fmt.percent(data.margin_pct, false)} />
    </div>
  );
}

// ── Branch / Cost Center / Project — rows array, REAL data ───────────────────

function DimensionRowsView({
  query,
  dimensionKey,
  columnLabel,
}: {
  query: UseQueryResult<ProfitabilityByDimension>;
  dimensionKey: 'branch' | 'cost_center' | 'project';
  columnLabel: string;
}) {
  const { t } = useTranslation('finance');
  const fmt = useFormatter();
  const rows = query.data?.rows ?? [];

  const columns = useMemo<DataGridColumnDef<ProfitabilityDimensionRow>[]>(() => [
    {
      key: dimensionKey,
      label: columnLabel,
      pin: 'left',
      // Raw ledger dimension id, verbatim — branch_id/project_id are uuid
      // columns but cost_center_id is a plain integer FK (see
      // create_finance_journal_lines_table migration), so this deliberately
      // does NOT truncate the way SupplierRef/CostAllocationIdRef do for a
      // confirmed uuid — truncating a short integer id would misleadingly
      // imply hidden characters.
      cell: (r) => <span className="font-mono text-xs">{r[dimensionKey] ?? '—'}</span>,
    },
    { key: 'revenue', label: t(($) => $.profitability.field.revenue), align: 'end', cell: (r) => <span className="tabular-nums">{fmt.money(r.revenue)}</span> },
    { key: 'expense', label: t(($) => $.profitability.field.expense), align: 'end', cell: (r) => <span className="tabular-nums">{fmt.money(r.expense)}</span> },
    {
      key: 'profit', label: t(($) => $.profitability.field.profit), align: 'end',
      cell: (r) => <span className={cn('tabular-nums font-medium', r.profit < 0 && 'text-red-600')}>{fmt.money(r.profit)}</span>,
    },
    { key: 'margin_pct', label: t(($) => $.profitability.field.marginPct), align: 'end', cell: (r) => <span className="tabular-nums">{fmt.percent(r.margin_pct, false)}</span> },
  ], [t, fmt, dimensionKey, columnLabel]);

  return (
    <UniversalDataGrid
      data={rows}
      columns={columns}
      rowId={(r) => String(r[dimensionKey] ?? '')}
      loading={query.isLoading}
      error={query.isError}
      emptyState={<p className="py-10 text-center text-sm text-muted-foreground">{t(($) => $.profitability.empty)}</p>}
    />
  );
}

function BranchView({ range }: { range: FinanceIntelligenceWindowParams }) {
  const { t } = useTranslation('finance');
  const query = useProfitabilityBranch(range);
  return <DimensionRowsView query={query} dimensionKey="branch" columnLabel={t(($) => $.profitability.tab.branch)} />;
}

function CostCenterView({ range }: { range: FinanceIntelligenceWindowParams }) {
  const { t } = useTranslation('finance');
  const query = useProfitabilityCostCenter(range);
  return <DimensionRowsView query={query} dimensionKey="cost_center" columnLabel={t(($) => $.profitability.tab.costCenter)} />;
}

function ProjectView({ range }: { range: FinanceIntelligenceWindowParams }) {
  const { t } = useTranslation('finance');
  const query = useProfitabilityProject(range);
  return <DimensionRowsView query={query} dimensionKey="project" columnLabel={t(($) => $.profitability.tab.project)} />;
}

// ── Customer — AR-attributed rows, REAL data ──────────────────────────────────

function CustomerView({ range }: { range: FinanceIntelligenceWindowParams }) {
  const { t } = useTranslation('finance');
  const fmt = useFormatter();
  const query = useProfitabilityCustomer(range);
  const rows = query.data?.rows ?? [];

  const columns = useMemo<DataGridColumnDef<ProfitabilityCustomerRow>[]>(() => [
    // customer_id is a confirmed uuid / opaque party reference
    // (finance_customer_invoices.customer_id) — truncate with a full-value
    // tooltip, matching SupplierRef / CostAllocationIdRef exactly.
    { key: 'customer_id', label: t(($) => $.profitability.tab.customer), pin: 'left', cell: (r) => <span className="font-mono text-xs" title={r.customer_id}>{r.customer_id.slice(0, 8)}…</span> },
    { key: 'revenue', label: t(($) => $.profitability.field.revenue), align: 'end', cell: (r) => <span className="tabular-nums">{fmt.money(r.revenue)}</span> },
    { key: 'estimated_profit', label: t(($) => $.profitability.field.estimatedProfit), align: 'end', cell: (r) => <span className="tabular-nums font-medium">{fmt.money(r.estimated_profit)}</span> },
    { key: 'margin_pct', label: t(($) => $.profitability.field.marginPct), align: 'end', cell: (r) => <span className="tabular-nums">{fmt.percent(r.margin_pct, false)}</span> },
  ], [t, fmt]);

  return (
    <div className="space-y-2">
      {query.data && (
        <p className="text-xs text-muted-foreground">
          {t(($) => $.profitability.customer.attributionPrefix)} {query.data.attribution}
        </p>
      )}
      <UniversalDataGrid
        data={rows}
        columns={columns}
        rowId={(r) => r.customer_id}
        loading={query.isLoading}
        error={query.isError}
        emptyState={<p className="py-10 text-center text-sm text-muted-foreground">{t(($) => $.profitability.empty)}</p>}
      />
    </div>
  );
}

// ── Product / Channel — HONEST "not yet available", by backend design ───────

function UntaggedDimensionBody({ query }: { query: UseQueryResult<ProfitabilityUnavailable> }) {
  const { t } = useTranslation('finance');
  const fmt = useFormatter();
  const data = query.data;

  if (!data) {
    return (
      <FinanceIntelligenceStateCard
        loading={query.isLoading}
        error={query.isError}
        loadingLabel={t(($) => $.loading)}
        errorLabel={t(($) => $.profitability.error)}
      />
    );
  }

  return (
    <FinanceIntelligenceUnavailableCard heading={t(($) => $.profitability.unavailable.heading)} note={data.note}>
      <div className="border-t pt-3">
        <p className="mb-2 text-xs font-medium uppercase tracking-wide text-muted-foreground">
          {t(($) => $.profitability.unavailable.companyFallback)}
        </p>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <Stat label={t(($) => $.profitability.field.revenue)} value={fmt.money(data.company.revenue)} />
          <Stat label={t(($) => $.profitability.field.expense)} value={fmt.money(data.company.expense)} />
          <Stat label={t(($) => $.profitability.field.profit)} value={fmt.money(data.company.profit)} />
          <Stat label={t(($) => $.profitability.field.marginPct)} value={fmt.percent(data.company.margin_pct, false)} />
        </div>
      </div>
    </FinanceIntelligenceUnavailableCard>
  );
}

function ProductView({ range }: { range: FinanceIntelligenceWindowParams }) {
  const query = useProfitabilityProduct(range);
  return <UntaggedDimensionBody query={query} />;
}

function ChannelView({ range }: { range: FinanceIntelligenceWindowParams }) {
  const query = useProfitabilityChannel(range);
  return <UntaggedDimensionBody query={query} />;
}

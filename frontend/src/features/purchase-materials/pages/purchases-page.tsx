import { useCallback, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { Plus, Truck } from 'lucide-react';

import { WorkspaceHeader } from '@/components/workspace';
import type { DataGridColumnDef, GridPaginationConfig } from '@/components/data-grid';
import { UniversalDataGrid } from '@/components/data-grid';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { toast } from '@/components/ds/use-toast';
import { useWarehouseOptions } from '@/features/products/hooks/use-warehouse-options';
import { CompanySelect } from '@/features/branches/components/company-select';

import { PurchaseMaterialPriorityBadge } from '../components/purchase-material-priority-badge';
import { PurchaseMaterialActionMenu } from '../components/purchase-material-action-menu';
import { PurchaseMaterialOrderingPopover } from '../components/purchase-material-ordering-popover';
import { CreatePurchaseMaterialWizard } from '../components/create-purchase-material-wizard';
import { PurchaseMaterialDrawer } from '../components/purchase-material-drawer';
import {
  useDeletePurchaseMaterial,
  usePurchaseMaterialsQuery,
  usePurchaseMaterialStats,
} from '../hooks/use-purchase-materials';
import type {
  PurchaseMaterial,
  PurchaseMaterialPriority,
  PurchaseMaterialStatus,
  PurchaseSourceType,
} from '../types/purchase-material';

// ── Source class map (non-translatable styling) ───────────────────────────────

const SOURCE_CLASS_MAP: Record<PurchaseSourceType, string> = {
  material_request: 'bg-blue-50 text-blue-700 border-blue-200',
  direct:           'bg-slate-50 text-slate-700 border-slate-200',
  reorder:          'bg-violet-50 text-violet-700 border-violet-200',
  ai:               'bg-amber-50 text-amber-700 border-amber-200',
  manual:           'bg-gray-50 text-gray-600 border-gray-200',
};

function SourceBadge({ source }: { source: PurchaseSourceType | null }) {
  const { t } = useTranslation('purchase-materials');
  const tAny = t as (key: string, opts?: Record<string, unknown>) => string;

  if (!source) return <span className="text-muted-foreground text-xs">—</span>;
  const className = SOURCE_CLASS_MAP[source];
  return (
    <span className={`inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-medium ${className}`}>
      {tAny(`purchasesPage.sourceSelector.sources.${source}.label`)}
    </span>
  );
}

// TASK-PROC-PURCHASING-WORKFLOW-REALIGNMENT-001 §2/§3 — the "Select Purchase Source" dialog is
// GONE. It offered three entry points (From Material Request / Direct Purchase / Reorder) that all
// opened the SAME wizard and differed only by a cosmetic source_type string: the labels promised
// conversion / reorder-point behaviour that does not exist in the backend. "New Purchase" now
// starts the one operational purchase directly. The legacy source values are still accepted by the
// backend and still rendered as badges on historic rows (SourceBadge) — nothing is deleted.

// ── Helpers ───────────────────────────────────────────────────────────────────

function fmtDate(d: string | null | undefined): string {
  if (!d) return '—';
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(d));
}

function fmtCurrency(n: number): string {
  return n.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 0 });
}

const PER_PAGE = 15;

const VALID_STATUSES = new Set<string>([
  'draft', 'under_review', 'waiting_supplier_selection', 'approved',
  'purchasing', 'receiving', 'completed', 'on_hold', 'rejected', 'cancelled',
]);

/** Accepts a single status or a comma-separated combination (the Hub's "Requests by Status"
 *  bucket cards, e.g. ?status=under_review,waiting_supplier_selection,approved,on_hold for
 *  "Awaiting Supplier" — §5/§19) — every segment must itself be a real status. */
function isValidStatusFilter(value: string): boolean {
  return value.split(',').every((s) => VALID_STATUSES.has(s.trim()));
}

// ── Main page ─────────────────────────────────────────────────────────────────

export function PurchasesPage() {
  const { t } = useTranslation('purchase-materials');
  const tAny = t as (key: string, opts?: Record<string, unknown>) => string;

  // Deep-links from the Procurement Hub (e.g. ?status=approved, ?unowned=1) — read once on
  // mount as the initial filter state. TASK-...-011 §18: these were previously dead — this page
  // never read the URL at all, so every Hub card landed on the unfiltered "All" view.
  const [searchParams] = useSearchParams();
  const initialStatus = searchParams.get('status');

  // Widened from the single-status enum to a plain string: a bucket drill-down (§19) carries a
  // comma-separated combination the backend now understands (EloquentPurchaseMaterialRepository
  // whereIn), which no single PurchaseMaterialStatus value can represent.
  const [statusFilter, setStatusFilter] = useState<string>(
    initialStatus && isValidStatusFilter(initialStatus) ? initialStatus : 'all',
  );
  const [priorityFilter, setPriorityFilter] = useState<PurchaseMaterialPriority | 'all'>('all');
  const [search, setSearch] = useState('');
  const [warehouseFilter, setWarehouseFilter] = useState('');
  const [companyFilter, setCompanyFilter] = useState('');
  const [unownedFilter, setUnownedFilter] = useState(searchParams.get('unowned') === '1');
  const [overdueFilter, setOverdueFilter] = useState(searchParams.get('overdue') === '1');
  const [requiredSoonFilter, setRequiredSoonFilter] = useState(searchParams.get('required_soon') === '1');
  const [dateFrom, setDateFrom] = useState('');
  const [dateTo, setDateTo] = useState('');
  const [page, setPage] = useState(1);
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [drawerOpen, setDrawerOpen] = useState(false);
  const [wizardOpen, setWizardOpen] = useState(false);

  const STATUS_CHIPS: Array<{ value: PurchaseMaterialStatus | 'all'; label: string }> = [
    { value: 'all', label: t($ => $.purchasesPage.statusChips.all) },
    { value: 'draft', label: t($ => $.purchasesPage.statusChips.draft) },
    { value: 'under_review', label: t($ => $.purchasesPage.statusChips.under_review) },
    { value: 'waiting_supplier_selection', label: t($ => $.purchasesPage.statusChips.waiting_supplier_selection) },
    { value: 'approved', label: t($ => $.purchasesPage.statusChips.approved) },
    { value: 'purchasing', label: t($ => $.purchasesPage.statusChips.purchasing) },
    { value: 'receiving', label: t($ => $.purchasesPage.statusChips.receiving) },
    { value: 'completed', label: t($ => $.purchasesPage.statusChips.completed) },
    { value: 'on_hold', label: t($ => $.purchasesPage.statusChips.on_hold) },
    { value: 'rejected', label: t($ => $.purchasesPage.statusChips.rejected) },
    { value: 'cancelled', label: t($ => $.purchasesPage.statusChips.cancelled) },
  ];

  const { data: warehouseOptions } = useWarehouseOptions();

  const params = useMemo(
    () => ({
      record_type: 'purchase' as const,
      status: statusFilter === 'all' ? undefined : statusFilter,
      priority: priorityFilter === 'all' ? undefined : priorityFilter,
      search: search || undefined,
      warehouse_id: warehouseFilter || undefined,
      company_id: companyFilter || undefined,
      unowned: unownedFilter,
      overdue: overdueFilter,
      required_soon: requiredSoonFilter,
      date_from: dateFrom || undefined,
      date_to: dateTo || undefined,
      per_page: PER_PAGE,
      page,
    }),
    [
      statusFilter, priorityFilter, search, warehouseFilter, companyFilter,
      unownedFilter, overdueFilter, requiredSoonFilter, dateFrom, dateTo, page,
    ],
  );

  const { data, isLoading, isError, isFetching, refetch } = usePurchaseMaterialsQuery(params);
  const { data: stats } = usePurchaseMaterialStats({
    company_id: companyFilter || undefined,
    warehouse_id: warehouseFilter || undefined,
    // Scope the KPI cards to purchases only — without this they summed Material
    // Requests too, so MRs "appeared" on the Purchases screen via its stats.
    record_type: 'purchase',
  });
  const deleteMutation = useDeletePurchaseMaterial();

  const items = data?.items ?? [];
  const meta = data?.meta;

  const resetFilters = useCallback(() => {
    setStatusFilter('all');
    setPriorityFilter('all');
    setSearch('');
    setWarehouseFilter('');
    setCompanyFilter('');
    setUnownedFilter(false);
    setOverdueFilter(false);
    setRequiredSoonFilter(false);
    setDateFrom('');
    setDateTo('');
    setPage(1);
  }, []);

  function openDrawer(purchase: PurchaseMaterial) {
    setSelectedId(purchase.id);
    setDrawerOpen(true);
  }

  async function handleDelete(purchase: PurchaseMaterial) {
    if (!window.confirm(tAny('purchasesPage.delete.confirm', { number: purchase.request_number }))) return;
    try {
      await deleteMutation.mutateAsync(purchase.id);
      toast.success(t($ => $.purchasesPage.toast.deleted));
    } catch {
      toast.error(t($ => $.purchasesPage.toast.deleteFailed));
    }
  }

  const op = stats?.operational;
  const workload = stats?.workload;

  const opKpis: Array<{ id: string; label: string; value: number; color: string; status: PurchaseMaterialStatus }> = [
    { id: 'draft', label: t($ => $.purchasesPage.kpis.draft), value: op?.draft ?? 0, color: 'text-slate-700', status: 'draft' },
    { id: 'underReview', label: t($ => $.purchasesPage.kpis.underReview), value: op?.under_review ?? 0, color: 'text-blue-700', status: 'under_review' },
    { id: 'awaitingSupplier', label: t($ => $.purchasesPage.kpis.awaitingSupplier), value: op?.waiting_supplier_selection ?? 0, color: 'text-violet-700', status: 'waiting_supplier_selection' },
    { id: 'approved', label: t($ => $.purchasesPage.kpis.approved), value: op?.approved ?? 0, color: 'text-emerald-700', status: 'approved' },
    { id: 'purchasing', label: t($ => $.purchasesPage.kpis.purchasing), value: op?.purchasing ?? 0, color: 'text-cyan-700', status: 'purchasing' },
    { id: 'receiving', label: t($ => $.purchasesPage.kpis.receiving), value: op?.receiving ?? 0, color: 'text-teal-700', status: 'receiving' },
  ];

  // TASK-...-011 §17/§19: replaces the old "Financial" row, which summed estimated_value /
  // approved_value / purchased_value — columns no Action has ever written, so every card there
  // permanently read 0. These three are real, clickable workload filters instead.
  const workloadKpis: Array<{ id: string; label: string; value: number; color: string; onClick: () => void; active: boolean }> = [
    {
      id: 'unowned', label: t($ => $.purchasesPage.kpis.unowned), value: workload?.unowned_count ?? 0, color: 'text-amber-700',
      onClick: () => { setUnownedFilter((v) => !v); setPage(1); }, active: unownedFilter,
    },
    {
      id: 'overdue', label: t($ => $.purchasesPage.kpis.overdue), value: workload?.overdue_count ?? 0, color: 'text-red-700',
      onClick: () => { setOverdueFilter((v) => !v); setPage(1); }, active: overdueFilter,
    },
    {
      id: 'requiredSoon', label: t($ => $.purchasesPage.kpis.requiredSoon), value: workload?.required_soon_count ?? 0, color: 'text-orange-700',
      onClick: () => { setRequiredSoonFilter((v) => !v); setPage(1); }, active: requiredSoonFilter,
    },
    {
      id: 'notYetOrdered', label: t($ => $.purchasesPage.kpis.notYetOrderedLines), value: workload?.not_yet_ordered_lines ?? 0, color: 'text-cyan-700',
      onClick: () => {}, active: false,
    },
  ];

  const hasFilters = Boolean(
    search || statusFilter !== 'all' || priorityFilter !== 'all' || warehouseFilter || companyFilter ||
    unownedFilter || overdueFilter || requiredSoonFilter || dateFrom || dateTo,
  );

  // ── Canonical UniversalDataGrid columns — same fields/order the previous
  // hand-rolled <table> rendered; sort/selection/column-visibility were never a
  // capability of this page, so none is introduced here (presentation migration
  // only, matching the same principle already applied to Orders/Products/
  // Customers in UI-04 and to the list pages in UI-03).
  const columns: DataGridColumnDef<PurchaseMaterial>[] = [
    {
      key: 'requestNo', label: t($ => $.purchasesPage.columns.requestNo), alwaysVisible: true, cardRole: 'title',
      cell: (p) => <span className="font-mono font-medium text-xs">{p.request_number}</span>,
    },
    {
      key: 'source', label: t($ => $.purchasesPage.columns.source), cardRole: 'subtitle',
      cell: (p) => <SourceBadge source={p.source_type} />,
    },
    {
      key: 'company', label: t($ => $.purchasesPage.columns.company),
      cell: (p) => <span className="text-muted-foreground text-xs">{p.company?.name ?? '—'}</span>,
    },
    {
      key: 'warehouse', label: t($ => $.purchasesPage.columns.warehouse),
      cell: (p) => <span className="text-muted-foreground">{p.warehouse?.name ?? '—'}</span>,
    },
    {
      key: 'orderedItems', label: t($ => $.purchasesPage.columns.orderedItems), align: 'center',
      cell: (p) => (
        <PurchaseMaterialOrderingPopover
          variant="ordered"
          count={p.ordered_items_count ?? 0}
          items={p.ordered_items ?? []}
          emptyLabel={t($ => $.purchasesPage.orderingPopover.emptyOrdered)}
        />
      ),
    },
    {
      key: 'notYetOrdered', label: t($ => $.purchasesPage.columns.notYetOrdered), align: 'center',
      cell: (p) => (
        <PurchaseMaterialOrderingPopover
          variant="not_yet_ordered"
          count={p.not_yet_ordered_items_count ?? 0}
          items={p.not_yet_ordered_items ?? []}
          emptyLabel={t($ => $.purchasesPage.orderingPopover.emptyNotYetOrdered)}
        />
      ),
    },
    {
      key: 'estValue', label: t($ => $.purchasesPage.columns.estValue), align: 'end',
      cell: (p) => (
        // §3 — an honest sum of lines with a real latest purchase price; "~"
        // flags a partial sum, and a request with NO priced lines at all shows
        // a truthful "unavailable" rather than an indistinguishable-from-real 0.
        p.estimated_value > 0 ? (
          <span className="font-mono text-xs tabular-nums">{p.estimated_value_has_gaps ? '~' : ''}{fmtCurrency(p.estimated_value)}</span>
        ) : p.estimated_value_has_gaps ? (
          <span className="text-muted-foreground italic text-xs">{t($ => $.purchasesPage.estValueUnavailable)}</span>
        ) : <span className="text-muted-foreground text-xs">—</span>
      ),
    },
    {
      key: 'progress', label: t($ => $.purchasesPage.columns.progress),
      cell: (p) => (
        p.execution_percent !== undefined ? (
          <div className="flex items-center gap-1.5 w-32">
            <div className="h-1.5 flex-1 rounded-full bg-muted overflow-hidden">
              <div className="h-full rounded-full bg-emerald-500" style={{ width: `${Math.min(100, Math.max(0, p.execution_percent))}%` }} />
            </div>
            <span className="text-[10px] font-mono text-muted-foreground shrink-0">{Math.round(p.execution_percent)}%</span>
          </div>
        ) : <span className="text-muted-foreground text-xs">—</span>
      ),
    },
    {
      key: 'priority', label: t($ => $.purchasesPage.columns.priority),
      cell: (p) => <PurchaseMaterialPriorityBadge priority={p.priority} />,
    },
    {
      key: 'requiredBy', label: t($ => $.purchasesPage.columns.requiredBy),
      cell: (p) => <span className="text-muted-foreground text-xs">{fmtDate(p.required_date)}</span>,
    },
    {
      key: 'status', label: t($ => $.purchasesPage.columns.status), cardRole: 'status', alwaysVisible: true,
      cell: (p) => <PurchaseMaterialActionMenu material={p} />,
    },
    {
      key: 'lastUpdated', label: t($ => $.purchasesPage.columns.lastUpdated),
      cell: (p) => <span className="text-muted-foreground text-xs">{fmtDate(p.updated_at)}</span>,
    },
    {
      key: 'rowActions', label: '', align: 'end',
      cell: (p) => (
        p.status === 'draft' ? (
          <button
            type="button"
            onClick={() => void handleDelete(p)}
            className="text-xs text-muted-foreground hover:text-destructive transition-colors"
          >
            {t($ => $.purchasesPage.delete.button)}
          </button>
        ) : null
      ),
    },
  ];

  const pagination: GridPaginationConfig | undefined = meta ? {
    meta: {
      page: meta.current_page,
      perPage: meta.per_page,
      total: meta.total,
      lastPage: meta.last_page,
    },
    onPageChange: setPage,
  } : undefined;

  const emptyState = (
    <div className="flex flex-col items-center gap-2 py-4 text-center">
      <Truck className="h-8 w-8 text-muted-foreground/30" />
      <p className="text-sm text-muted-foreground">
        {hasFilters ? t($ => $.purchasesPage.empty.noMatch) : t($ => $.purchasesPage.empty.none)}
      </p>
      {!hasFilters && (
        <p className="text-xs text-muted-foreground">{t($ => $.purchasesPage.empty.createHint)}</p>
      )}
    </div>
  );

  return (
    <div className="flex flex-col h-full">
      <WorkspaceHeader
        title={t($ => $.purchasesPage.title)}
        description={t($ => $.purchasesPage.subtitle)}
        primaryAction={{
          key: 'new-purchase',
          label: t($ => $.purchasesPage.newPurchase),
          icon: Plus,
          onClick: () => setWizardOpen(true),
        }}
      />

      <div className="flex-1 overflow-auto px-6 pb-6 flex flex-col gap-4">
        {/* ── KPI Cards ─────────────────────────────────────────────── */}
        <div className="flex flex-col gap-3 pt-4">
          <div>
            <p className="text-xs font-semibold text-muted-foreground uppercase tracking-wider mb-2">
              {t($ => $.purchasesPage.operations)}
            </p>
            <div className="grid grid-cols-3 gap-2 sm:grid-cols-6">
              {opKpis.map(({ id, label, value, color, status }) => (
                <Card
                  key={id}
                  className="border shadow-none cursor-pointer hover:border-primary/40 transition-colors"
                  onClick={() => { setStatusFilter(status); setPage(1); }}
                >
                  <CardContent className="pt-3 pb-2.5 px-3">
                    <p className="text-[10px] text-muted-foreground leading-tight">{label}</p>
                    <p className={`text-2xl font-bold tabular-nums ${color}`}>{value}</p>
                  </CardContent>
                </Card>
              ))}
            </div>
          </div>

          <div>
            <p className="text-xs font-semibold text-muted-foreground uppercase tracking-wider mb-2">
              {t($ => $.purchasesPage.workload)}
            </p>
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
              {workloadKpis.map(({ id, label, value, color, onClick, active }) => (
                <Card
                  key={id}
                  className={`border shadow-none cursor-pointer hover:border-primary/40 transition-colors ${active ? 'border-primary ring-1 ring-primary/30' : ''}`}
                  onClick={onClick}
                >
                  <CardContent className="pt-3 pb-2.5 px-3">
                    <p className="text-[10px] text-muted-foreground leading-tight">{label}</p>
                    <p className={`text-xl font-bold tabular-nums ${color}`}>{value}</p>
                  </CardContent>
                </Card>
              ))}
            </div>
          </div>
        </div>

        {/* ── Smart Toolbar ──────────────────────────────────────────── */}
        <div className="flex flex-col gap-2 rounded-lg border bg-muted/20 p-3">
          <div className="flex flex-wrap gap-1.5">
            {STATUS_CHIPS.map((sf) => (
              <button
                key={sf.value}
                onClick={() => { setStatusFilter(sf.value); setPage(1); }}
                className={`px-2.5 py-0.5 rounded-full text-xs font-medium transition-colors border ${
                  statusFilter === sf.value
                    ? 'bg-primary text-primary-foreground border-primary'
                    : 'bg-background text-muted-foreground border-border hover:border-primary/50 hover:text-foreground'
                }`}
              >
                {sf.label}
              </button>
            ))}
          </div>

          <div className="flex flex-wrap gap-2 items-center">
            <Input
              className="w-48 h-8 text-sm"
              placeholder={t($ => $.purchasesPage.filters.search)}
              value={search}
              onChange={(e) => { setSearch(e.target.value); setPage(1); }}
            />

            <div className="w-44">
              <CompanySelect
                value={companyFilter || null}
                onChange={(v) => { setCompanyFilter(v ?? ''); setPage(1); }}
              />
            </div>

            <div className="w-44">
              <Select
                value={warehouseFilter || 'all'}
                onValueChange={(v) => { setWarehouseFilter(v === 'all' ? '' : v); setPage(1); }}
              >
                <SelectTrigger className="h-8 text-sm">
                  <SelectValue placeholder={t($ => $.purchasesPage.filters.allWarehouses)} />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">{t($ => $.purchasesPage.filters.allWarehouses)}</SelectItem>
                  {(warehouseOptions ?? []).map((w) => (
                    <SelectItem key={w.value} value={w.value}>{w.label}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            <div className="w-32">
              <Select
                value={priorityFilter}
                onValueChange={(v) => { setPriorityFilter(v as PurchaseMaterialPriority | 'all'); setPage(1); }}
              >
                <SelectTrigger className="h-8 text-sm">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">{t($ => $.purchasesPage.filters.allPriorities)}</SelectItem>
                  <SelectItem value="urgent">{t($ => $.purchasesPage.priority.urgent)}</SelectItem>
                  <SelectItem value="high">{t($ => $.purchasesPage.priority.high)}</SelectItem>
                  <SelectItem value="normal">{t($ => $.purchasesPage.priority.normal)}</SelectItem>
                  <SelectItem value="low">{t($ => $.purchasesPage.priority.low)}</SelectItem>
                </SelectContent>
              </Select>
            </div>

            <div className="flex items-center gap-1 text-xs text-muted-foreground">
              <span>{t($ => $.purchasesPage.filters.requiredBy)}</span>
              <Input type="date" className="h-8 w-36 text-sm" value={dateFrom} onChange={(e) => { setDateFrom(e.target.value); setPage(1); }} />
              <span>→</span>
              <Input type="date" className="h-8 w-36 text-sm" value={dateTo} onChange={(e) => { setDateTo(e.target.value); setPage(1); }} />
            </div>

            {hasFilters && (
              <Button variant="ghost" size="sm" className="h-8 text-xs" onClick={resetFilters}>
                {t($ => $.purchasesPage.filters.clearFilters)}
              </Button>
            )}
          </div>
        </div>

        {/* ── Data Grid ─────────────────────────────────────────────── */}
        <div className={`rounded-lg border overflow-hidden transition-opacity ${isFetching ? 'opacity-60' : 'opacity-100'}`}>
          <UniversalDataGrid
            data={items}
            columns={columns}
            rowId={(p) => p.id}
            loading={isLoading}
            error={isError}
            onRowClick={openDrawer}
            emptyState={emptyState}
            errorState={
              <div className="flex flex-col items-center gap-2 py-8 text-center">
                <p className="text-sm text-muted-foreground">{t($ => $.purchasesPage.loadFailed)}</p>
                <Button variant="outline" size="sm" onClick={() => void refetch()}>
                  {t($ => $.purchasesPage.retry)}
                </Button>
              </div>
            }
            pagination={pagination}
          />
        </div>

        {meta && (
          <p className="text-xs text-muted-foreground text-end">
            {tAny('purchasesPage.pagination.total', { count: meta.total })}
          </p>
        )}
      </div>

      {/* Wizard — one operational purchase, opened directly (no source selection). */}
      <CreatePurchaseMaterialWizard
        open={wizardOpen}
        onOpenChange={setWizardOpen}
        recordType="purchase"
        sourceType="direct"
      />

      {/* Detail drawer */}
      <PurchaseMaterialDrawer
        id={selectedId}
        open={drawerOpen}
        onOpenChange={setDrawerOpen}
      />
    </div>
  );
}

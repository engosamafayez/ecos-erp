import { useCallback, useMemo, useState } from 'react';

import { PageHeader } from '@/components/crud';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { toast } from '@/components/ds/use-toast';
import { useWarehouseOptions } from '@/features/products/hooks/use-warehouse-options';
import { CompanySelect } from '@/features/branches/components/company-select';

import { PurchaseMaterialStatusBadge } from '../components/purchase-material-status-badge';
import { PurchaseMaterialPriorityBadge } from '../components/purchase-material-priority-badge';
import { CreatePurchaseMaterialWizard } from '../components/create-purchase-material-wizard';
import { PurchaseMaterialDrawer } from '../components/purchase-material-drawer';
import {
  useDeletePurchaseMaterial,
  usePurchaseMaterialsQuery,
  usePurchaseMaterialStats,
} from '../hooks/use-purchase-materials';
import type { PurchaseMaterial, PurchaseMaterialPriority, PurchaseMaterialStatus } from '../types/purchase-material';

const STATUS_CHIPS: { value: PurchaseMaterialStatus | 'all'; label: string }[] = [
  { value: 'all', label: 'All' },
  { value: 'draft', label: 'Draft' },
  { value: 'under_review', label: 'Under Review' },
  { value: 'waiting_supplier_selection', label: 'Awaiting Supplier' },
  { value: 'approved', label: 'Approved' },
  { value: 'purchasing', label: 'Purchasing' },
  { value: 'receiving', label: 'Receiving' },
  { value: 'completed', label: 'Completed' },
  { value: 'on_hold', label: 'On Hold' },
  { value: 'rejected', label: 'Rejected' },
  { value: 'cancelled', label: 'Cancelled' },
];

function fmtDate(d: string | null | undefined): string {
  if (!d) return '—';
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(d));
}

function fmtCurrency(n: number): string {
  return n.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 0 });
}

const PER_PAGE = 15;

export function PurchaseMaterialsPage() {
  const [statusFilter, setStatusFilter] = useState<PurchaseMaterialStatus | 'all'>('all');
  const [priorityFilter, setPriorityFilter] = useState<PurchaseMaterialPriority | 'all'>('all');
  const [search, setSearch] = useState('');
  const [warehouseFilter, setWarehouseFilter] = useState('');
  const [companyFilter, setCompanyFilter] = useState('');
  const [buyerFilter, setBuyerFilter] = useState('');
  const [dateFrom, setDateFrom] = useState('');
  const [dateTo, setDateTo] = useState('');
  const [page, setPage] = useState(1);
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [drawerOpen, setDrawerOpen] = useState(false);
  const [wizardOpen, setWizardOpen] = useState(false);

  const { data: warehouseOptions } = useWarehouseOptions();

  const params = useMemo(
    () => ({
      status: statusFilter === 'all' ? undefined : statusFilter,
      priority: priorityFilter === 'all' ? undefined : priorityFilter,
      search: search || undefined,
      warehouse_id: warehouseFilter || undefined,
      company_id: companyFilter || undefined,
      assigned_buyer: buyerFilter || undefined,
      date_from: dateFrom || undefined,
      date_to: dateTo || undefined,
      per_page: PER_PAGE,
      page,
    }),
    [statusFilter, priorityFilter, search, warehouseFilter, companyFilter, buyerFilter, dateFrom, dateTo, page],
  );

  const { data, isLoading, isFetching } = usePurchaseMaterialsQuery(params);
  const { data: stats } = usePurchaseMaterialStats({ company_id: companyFilter || undefined, warehouse_id: warehouseFilter || undefined });
  const deleteMutation = useDeletePurchaseMaterial();

  const items = data?.items ?? [];
  const meta = data?.meta;

  const resetFilters = useCallback(() => {
    setStatusFilter('all');
    setPriorityFilter('all');
    setSearch('');
    setWarehouseFilter('');
    setCompanyFilter('');
    setBuyerFilter('');
    setDateFrom('');
    setDateTo('');
    setPage(1);
  }, []);

  function openDrawer(material: PurchaseMaterial) {
    setSelectedId(material.id);
    setDrawerOpen(true);
  }

  async function handleDelete(material: PurchaseMaterial, e: React.MouseEvent) {
    e.stopPropagation();
    if (!confirm(`Delete request ${material.request_number}?`)) return;
    try {
      await deleteMutation.mutateAsync(material.id);
      toast.success('Request deleted.');
    } catch {
      toast.error('Failed to delete request.');
    }
  }

  const op = stats?.operational;
  const fin = stats?.financial;

  return (
    <div className="flex flex-col h-full">
      <PageHeader
        title="Purchase Materials"
        subtitle="Manage procurement requests, supplier selection and purchasing decisions."
        actions={
          <Button onClick={() => setWizardOpen(true)}>New Request</Button>
        }
      />

      <div className="flex-1 overflow-auto px-6 pb-6 flex flex-col gap-4">
        {/* ── KPI Cards ─────────────────────────────────────────────── */}
        <div className="flex flex-col gap-3">
          {/* Operational group */}
          <div>
            <p className="text-xs font-semibold text-muted-foreground uppercase tracking-wider mb-2">Operational</p>
            <div className="grid grid-cols-3 gap-2 sm:grid-cols-6">
              {[
                { label: 'Draft', value: op?.draft ?? 0, color: 'text-slate-700', status: 'draft' as const },
                { label: 'Under Review', value: op?.under_review ?? 0, color: 'text-blue-700', status: 'under_review' as const },
                { label: 'Await Supplier', value: op?.waiting_supplier_selection ?? 0, color: 'text-violet-700', status: 'waiting_supplier_selection' as const },
                { label: 'Approved', value: op?.approved ?? 0, color: 'text-emerald-700', status: 'approved' as const },
                { label: 'Purchasing', value: op?.purchasing ?? 0, color: 'text-cyan-700', status: 'purchasing' as const },
                { label: 'Receiving', value: op?.receiving ?? 0, color: 'text-teal-700', status: 'receiving' as const },
              ].map(({ label, value, color, status }) => (
                <Card
                  key={label}
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

          {/* Financial group */}
          <div>
            <p className="text-xs font-semibold text-muted-foreground uppercase tracking-wider mb-2">Financial</p>
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
              {[
                { label: 'Total Requested', value: fin?.total_estimated_value ?? 0, color: 'text-slate-700' },
                { label: 'Approved Value', value: fin?.total_approved_value ?? 0, color: 'text-emerald-700' },
                { label: 'Purchased Value', value: fin?.total_purchased_value ?? 0, color: 'text-cyan-700' },
                { label: 'Outstanding', value: fin?.outstanding_value ?? 0, color: 'text-amber-700' },
              ].map(({ label, value, color }) => (
                <Card key={label} className="border shadow-none">
                  <CardContent className="pt-3 pb-2.5 px-3">
                    <p className="text-[10px] text-muted-foreground leading-tight">{label}</p>
                    <p className={`text-xl font-bold tabular-nums ${color}`}>{fmtCurrency(value)}</p>
                  </CardContent>
                </Card>
              ))}
            </div>
          </div>
        </div>

        {/* ── Smart Toolbar ──────────────────────────────────────────── */}
        <div className="flex flex-col gap-2 rounded-lg border bg-muted/20 p-3">
          {/* Status chips row */}
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

          {/* Filter row */}
          <div className="flex flex-wrap gap-2 items-center">
            <Input
              className="w-48 h-8 text-sm"
              placeholder="Search request # / notes…"
              value={search}
              onChange={(e) => { setSearch(e.target.value); setPage(1); }}
            />

            <div className="w-44">
              <CompanySelect
                value={companyFilter || null}
                onChange={(v) => { setCompanyFilter(v ?? ''); setPage(1); }}
              />
            </div>

            <select
              value={warehouseFilter}
              onChange={(e) => { setWarehouseFilter(e.target.value); setPage(1); }}
              className="h-8 w-44 rounded-md border border-input bg-background px-2 text-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
            >
              <option value="">All Warehouses</option>
              {(warehouseOptions ?? []).map((w) => (
                <option key={w.value} value={w.value}>{w.label}</option>
              ))}
            </select>

            <select
              value={priorityFilter}
              onChange={(e) => { setPriorityFilter(e.target.value as PurchaseMaterialPriority | 'all'); setPage(1); }}
              className="h-8 w-32 rounded-md border border-input bg-background px-2 text-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
            >
              <option value="all">All Priorities</option>
              <option value="urgent">Urgent</option>
              <option value="high">High</option>
              <option value="normal">Normal</option>
              <option value="low">Low</option>
            </select>

            <Input
              className="h-8 w-36 text-sm"
              placeholder="Buyer…"
              value={buyerFilter}
              onChange={(e) => { setBuyerFilter(e.target.value); setPage(1); }}
            />

            <div className="flex items-center gap-1 text-xs text-muted-foreground">
              <span>Required:</span>
              <Input type="date" className="h-8 w-36 text-sm" value={dateFrom} onChange={(e) => { setDateFrom(e.target.value); setPage(1); }} />
              <span>→</span>
              <Input type="date" className="h-8 w-36 text-sm" value={dateTo} onChange={(e) => { setDateTo(e.target.value); setPage(1); }} />
            </div>

            {(search || statusFilter !== 'all' || priorityFilter !== 'all' || warehouseFilter || companyFilter || buyerFilter || dateFrom || dateTo) && (
              <Button variant="ghost" size="sm" className="h-8 text-xs" onClick={resetFilters}>
                Clear filters
              </Button>
            )}
          </div>
        </div>

        {/* ── Data Grid ─────────────────────────────────────────────── */}
        <div className="rounded-lg border overflow-hidden">
          <div className={`transition-opacity ${isFetching ? 'opacity-60' : 'opacity-100'}`}>
            <div className="overflow-x-auto">
              <table className="w-full text-sm whitespace-nowrap">
                <thead className="bg-muted/40 border-b">
                  <tr>
                    <th className="px-3 py-3 text-left font-medium text-xs text-muted-foreground">Request #</th>
                    <th className="px-3 py-3 text-left font-medium text-xs text-muted-foreground">Company</th>
                    <th className="px-3 py-3 text-left font-medium text-xs text-muted-foreground">Channel</th>
                    <th className="px-3 py-3 text-left font-medium text-xs text-muted-foreground">Warehouse</th>
                    <th className="px-3 py-3 text-left font-medium text-xs text-muted-foreground">Requested By</th>
                    <th className="px-3 py-3 text-center font-medium text-xs text-muted-foreground">Items</th>
                    <th className="px-3 py-3 text-right font-medium text-xs text-muted-foreground">Req. Qty</th>
                    <th className="px-3 py-3 text-right font-medium text-xs text-muted-foreground">Est. Value</th>
                    <th className="px-3 py-3 text-left font-medium text-xs text-muted-foreground">Priority</th>
                    <th className="px-3 py-3 text-left font-medium text-xs text-muted-foreground">Required By</th>
                    <th className="px-3 py-3 text-left font-medium text-xs text-muted-foreground">Status</th>
                    <th className="px-3 py-3 text-left font-medium text-xs text-muted-foreground">Assigned Buyer</th>
                    <th className="px-3 py-3 text-left font-medium text-xs text-muted-foreground">Last Updated</th>
                    <th className="px-3 py-3 w-10" />
                  </tr>
                </thead>
                <tbody>
                  {isLoading ? (
                    <tr>
                      <td colSpan={14} className="px-4 py-12 text-center text-sm text-muted-foreground">
                        Loading requests…
                      </td>
                    </tr>
                  ) : items.length === 0 ? (
                    <tr>
                      <td colSpan={14} className="px-4 py-12 text-center text-sm text-muted-foreground">
                        {search || statusFilter !== 'all' ? 'No requests match your filters.' : 'No purchase material requests yet.'}
                      </td>
                    </tr>
                  ) : (
                    items.map((pm) => (
                      <tr
                        key={pm.id}
                        className="border-t hover:bg-muted/30 transition-colors cursor-pointer"
                        onClick={() => openDrawer(pm)}
                      >
                        <td className="px-3 py-2.5">
                          <span className="font-mono font-medium text-xs">{pm.request_number}</span>
                        </td>
                        <td className="px-3 py-2.5 text-muted-foreground text-xs">{pm.company?.name ?? '—'}</td>
                        <td className="px-3 py-2.5 text-muted-foreground text-xs">{pm.channel_id ?? '—'}</td>
                        <td className="px-3 py-2.5 text-muted-foreground">{pm.warehouse?.name ?? '—'}</td>
                        <td className="px-3 py-2.5 text-muted-foreground text-xs">{pm.requested_by ?? '—'}</td>
                        <td className="px-3 py-2.5 text-center tabular-nums">{pm.items_count}</td>
                        <td className="px-3 py-2.5 text-right font-mono text-xs tabular-nums">
                          {pm.total_requested_qty > 0 ? pm.total_requested_qty.toLocaleString(undefined, { maximumFractionDigits: 2 }) : '—'}
                        </td>
                        <td className="px-3 py-2.5 text-right font-mono text-xs tabular-nums">
                          {pm.estimated_value > 0 ? fmtCurrency(pm.estimated_value) : '—'}
                        </td>
                        <td className="px-3 py-2.5">
                          <PurchaseMaterialPriorityBadge priority={pm.priority} />
                        </td>
                        <td className="px-3 py-2.5 text-muted-foreground text-xs">{fmtDate(pm.required_date)}</td>
                        <td className="px-3 py-2.5">
                          <PurchaseMaterialStatusBadge status={pm.status} />
                        </td>
                        <td className="px-3 py-2.5 text-muted-foreground text-xs">{pm.assigned_buyer ?? '—'}</td>
                        <td className="px-3 py-2.5 text-muted-foreground text-xs">{fmtDate(pm.updated_at)}</td>
                        <td className="px-3 py-2.5 text-right">
                          {pm.status === 'draft' && (
                            <button
                              type="button"
                              onClick={(e) => void handleDelete(pm, e)}
                              className="text-xs text-muted-foreground hover:text-destructive transition-colors"
                            >
                              Delete
                            </button>
                          )}
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </div>
        </div>

        {/* Pagination */}
        {meta && meta.last_page > 1 && (
          <div className="flex items-center justify-between text-xs text-muted-foreground">
            <span>{meta.total} total requests</span>
            <div className="flex items-center gap-2">
              <Button size="sm" variant="outline" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
                Previous
              </Button>
              <span>Page {meta.current_page} of {meta.last_page}</span>
              <Button size="sm" variant="outline" disabled={page >= meta.last_page} onClick={() => setPage((p) => p + 1)}>
                Next
              </Button>
            </div>
          </div>
        )}
      </div>

      <CreatePurchaseMaterialWizard open={wizardOpen} onOpenChange={setWizardOpen} />
      <PurchaseMaterialDrawer id={selectedId} open={drawerOpen} onOpenChange={setDrawerOpen} />
    </div>
  );
}

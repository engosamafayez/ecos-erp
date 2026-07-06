import { useMemo, useRef, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import {
  AlertCircle,
  Building2,
  CheckCircle,
  Clock,
  CreditCard,
  Download,
  Package,
  Plus,
  Search,
  ShoppingCart,
  Trash2,
  Users,
} from 'lucide-react';

import {
  ColumnVisibilityMenu,
  SmartToolbar,
  UniversalDataGrid,
  useColumnVisibility,
  useRowSelection,
} from '@/components/data-grid';
import type { DataGridColumnDef } from '@/components/data-grid';
import {
  PageConfirmDialog,
  PageNoResultsState,
  QuickFilterChips,
  WorkspacePage,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { WorkspaceHeader } from '@/components/workspace';
import type { WorkspaceMetric } from '@/components/workspace';
import { PageEmptyState } from '@/components/page';
import { SupplierFormDrawer } from '@/features/suppliers/components/supplier-form-drawer';
import { ProcurementHealthBadge } from '@/features/suppliers/components/procurement-health-badge';
import { SupplierStatusBadge } from '@/features/suppliers/components/supplier-status-badge';
import { Supplier360Drawer } from '@/features/suppliers/components/supplier-360-drawer';
import { SupplierWizard } from '@/features/suppliers/components/supplier-wizard';
import { useDeleteSupplier, useSuppliersQuery } from '@/features/suppliers/hooks/use-suppliers';
import { useSupplierSummaryStats } from '@/features/suppliers/hooks/use-supplier-analytics';
import type { ProcurementHealthResult } from '@/features/suppliers/types/supplier-analytics';
import type {
  Supplier,
  SupplierSortField,
  SupplierStatusFilter,
} from '@/features/suppliers/types/supplier';
import { toast } from '@/components/ds/use-toast';

const PER_PAGE = 20;
const COL_STORAGE_KEY = 'suppliers-col-visibility-v2';

function fmt(n: number, decimals = 2) {
  return n.toLocaleString(undefined, { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
}

const COLUMN_META = [
  { key: 'code',             label: 'Supplier Code',            alwaysVisible: true },
  { key: 'name',             label: 'Supplier Name',            alwaysVisible: true },
  { key: 'category',         label: 'Category',                 defaultVisible: false },
  { key: 'contact_person',   label: 'Contact Person',           defaultVisible: true },
  { key: 'phone',            label: 'Phone',                    defaultVisible: true },
  { key: 'total_purchases',  label: 'Total Purchases',          defaultVisible: true },
  { key: 'total_payments',   label: 'Total Payments',           defaultVisible: false },
  { key: 'outstanding',      label: 'Outstanding Balance',      defaultVisible: true },
  { key: 'inventory_value',  label: 'Supplier Inventory Value', defaultVisible: false },
  { key: 'stock_coverage',   label: 'Stock Coverage',           defaultVisible: false },
  { key: 'active_pos',       label: 'Active POs',               defaultVisible: false },
  { key: 'last_purchase',    label: 'Last Purchase',            defaultVisible: true },
  { key: 'health',           label: 'Procurement Health',       defaultVisible: false },
  { key: 'status',           label: 'Status',                   alwaysVisible: true },
  { key: 'actions',          label: 'Actions',                  alwaysVisible: true },
];

function SupplierHealthCell({ supplierId }: { supplierId: string }) {
  const qc = useQueryClient();
  const cached = qc.getQueryData<ProcurementHealthResult>(['supplier-health', supplierId]);
  return <ProcurementHealthBadge score={cached?.tier ?? null} />;
}

function exportCsv(items: Supplier[]) {
  const headers = ['Code', 'Name', 'Contact Person', 'Phone', 'Email', 'City', 'Country',
    'Total Invoiced', 'Outstanding Balance', 'Last Purchase', 'Status', 'Created'];
  const rows = items.map((s) => [
    s.code, s.name, s.contact_person ?? '', s.phone ?? '',
    s.email ?? '', s.city ?? '', s.country ?? '',
    s.total_invoiced != null ? s.total_invoiced.toFixed(2) : '',
    s.outstanding_balance != null ? s.outstanding_balance.toFixed(2) : '',
    s.last_purchase_date ?? '',
    s.is_active ? 'Active' : 'Inactive',
    s.created_at?.slice(0, 10) ?? '',
  ]);
  const csv = [headers, ...rows]
    .map((r) => r.map((v) => `"${String(v).replace(/"/g, '""')}"`).join(','))
    .join('\n');
  const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
  const a = Object.assign(document.createElement('a'), {
    href: url,
    download: `suppliers-${new Date().toISOString().slice(0, 10)}.csv`,
  });
  a.click();
  URL.revokeObjectURL(url);
}

export function SuppliersPage() {
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState<SupplierStatusFilter>('all');
  const [activeMetric, setActiveMetric] = useState<string | null>(null);
  const [page, setPage] = useState(1);
  const [sort, setSort] = useState<{ field: SupplierSortField; direction: 'asc' | 'desc' }>({
    field: 'created_at',
    direction: 'desc',
  });

  const [viewSupplier, setViewSupplier] = useState<Supplier | null>(null);
  const [editSupplier, setEditSupplier] = useState<Supplier | null>(null);
  const [wizardOpen, setWizardOpen] = useState(false);
  const [editDrawerOpen, setEditDrawerOpen] = useState(false);
  const [deleting, setDeleting] = useState<Supplier | null>(null);

  const searchRef = useRef<HTMLInputElement>(null);

  const { visibility, toggle, reset } = useColumnVisibility(COL_STORAGE_KEY, COLUMN_META);

  const params = useMemo(
    () => ({
      search: search || undefined,
      status: statusFilter,
      page,
      per_page: PER_PAGE,
      sort_by: sort.field,
      sort_dir: sort.direction,
    }),
    [search, statusFilter, page, sort],
  );

  const { data, isLoading, isError, isFetching, refetch } = useSuppliersQuery(params);
  const { data: stats, isLoading: statsLoading } = useSupplierSummaryStats();

  const deleteSupplier = useDeleteSupplier();

  const items = data?.items ?? [];
  const meta = data?.meta;

  const selection = useRowSelection({ items, getId: (s) => s.id });

  // ── KPI metrics ───────────────────────────────────────────────────────────
  const metrics: WorkspaceMetric[] = [
    {
      id: 'total',
      icon: Building2,
      label: 'Total Suppliers',
      value: stats?.total_suppliers ?? '—',
      colorClass: 'bg-primary/10 text-primary',
      isLoading: statsLoading,
      active: activeMetric === 'total',
      onClick: () => { setActiveMetric(activeMetric === 'total' ? null : 'total'); setStatusFilter('all'); setPage(1); },
    },
    {
      id: 'active',
      icon: CheckCircle,
      label: 'Active Suppliers',
      value: stats?.active_suppliers ?? '—',
      colorClass: 'bg-emerald-500/10 text-emerald-600',
      isLoading: statsLoading,
      active: activeMetric === 'active',
      onClick: () => { setActiveMetric(activeMetric === 'active' ? null : 'active'); setStatusFilter(activeMetric === 'active' ? 'all' : 'active'); setPage(1); },
    },
    {
      id: 'new',
      icon: Users,
      label: 'New This Month',
      value: stats?.new_this_month ?? '—',
      colorClass: 'bg-blue-500/10 text-blue-600',
      isLoading: statsLoading,
    },
    {
      id: 'open_pos',
      icon: ShoppingCart,
      label: 'Open Purchase Orders',
      value: stats?.open_pos_total ?? '—',
      colorClass: 'bg-amber-500/10 text-amber-600',
      isLoading: statsLoading,
    },
    {
      id: 'outstanding',
      icon: CreditCard,
      label: 'Outstanding Payables',
      value: stats ? `$${fmt(stats.total_outstanding)}` : '—',
      colorClass: 'bg-red-500/10 text-red-600',
      isLoading: statsLoading,
    },
    {
      id: 'inventory_value',
      icon: Package,
      label: 'Supplier Inventory Value',
      value: stats ? `$${fmt(stats.total_inventory_value)}` : '—',
      colorClass: 'bg-purple-500/10 text-purple-600',
      isLoading: statsLoading,
    },
    {
      id: 'delayed',
      icon: Clock,
      label: 'Delayed Orders',
      value: stats?.delayed_pos ?? '—',
      colorClass: 'bg-orange-500/10 text-orange-600',
      isLoading: statsLoading,
    },
    {
      id: 'review',
      icon: AlertCircle,
      label: 'Needs Review',
      value: stats?.needs_review_count ?? '—',
      colorClass: 'bg-rose-500/10 text-rose-600',
      isLoading: statsLoading,
    },
  ];

  // ── Column definitions ────────────────────────────────────────────────────
  const columns = useMemo<DataGridColumnDef<Supplier>[]>(
    () => [
      {
        key: 'code',
        label: 'Supplier Code',
        alwaysVisible: true,
        pin: 'left',
        width: 120,
        sortable: true,
        skeletonClassName: 'w-20 h-4',
        cell: (s) => <span className="font-mono text-xs text-muted-foreground">{s.code}</span>,
      },
      {
        key: 'name',
        label: 'Supplier Name',
        alwaysVisible: true,
        width: 200,
        sortable: true,
        skeletonClassName: 'w-40 h-4',
        cell: (s) => (
          <button
            className="font-medium text-left hover:text-primary hover:underline underline-offset-2 transition-colors"
            onClick={() => setViewSupplier(s)}
          >
            {s.name}
          </button>
        ),
      },
      {
        key: 'category',
        label: 'Category',
        defaultVisible: false,
        cell: () => <span className="text-muted-foreground text-xs">—</span>,
      },
      {
        key: 'contact_person',
        label: 'Contact Person',
        skeletonClassName: 'w-28 h-4',
        cell: (s) => <span className="text-sm text-muted-foreground">{s.contact_person ?? '—'}</span>,
      },
      {
        key: 'phone',
        label: 'Phone',
        skeletonClassName: 'w-28 h-4',
        cell: (s) => <span className="text-sm text-muted-foreground tabular-nums">{s.phone ?? '—'}</span>,
      },
      {
        key: 'total_purchases',
        label: 'Total Purchases',
        align: 'end',
        skeletonClassName: 'w-20 h-4 ml-auto',
        cell: (s) => (
          <span className="text-sm tabular-nums">
            {s.total_invoiced != null ? `$${fmt(s.total_invoiced)}` : '—'}
          </span>
        ),
      },
      {
        key: 'total_payments',
        label: 'Total Payments',
        defaultVisible: false,
        align: 'end',
        cell: (s) => (
          <span className="text-sm tabular-nums text-muted-foreground">
            {s.total_paid != null ? `$${fmt(s.total_paid)}` : '—'}
          </span>
        ),
      },
      {
        key: 'outstanding',
        label: 'Outstanding Balance',
        align: 'end',
        skeletonClassName: 'w-20 h-4 ml-auto',
        cell: (s) => (
          s.outstanding_balance != null
            ? <span className={`text-sm tabular-nums font-medium ${s.outstanding_balance > 0 ? 'text-destructive' : 'text-muted-foreground'}`}>
                {s.outstanding_balance > 0 ? `$${fmt(s.outstanding_balance)}` : '—'}
              </span>
            : <span className="text-xs text-muted-foreground">—</span>
        ),
      },
      {
        key: 'inventory_value',
        label: 'Supplier Inventory Value',
        defaultVisible: false,
        align: 'end',
        cell: (s) => (
          <span className="text-sm tabular-nums text-muted-foreground">
            {s.inventory_cost_value != null ? `$${fmt(s.inventory_cost_value)}` : '—'}
          </span>
        ),
      },
      {
        key: 'stock_coverage',
        label: 'Stock Coverage',
        defaultVisible: false,
        cell: () => <span className="text-xs text-muted-foreground">—</span>,
      },
      {
        key: 'active_pos',
        label: 'Active POs',
        defaultVisible: false,
        align: 'center',
        cell: (s) => (
          s.active_pos_count != null && s.active_pos_count > 0
            ? <span className="inline-flex items-center rounded-full bg-amber-50 border border-amber-200 px-2 py-0.5 text-xs font-medium text-amber-700 tabular-nums">{s.active_pos_count}</span>
            : <span className="text-xs text-muted-foreground">—</span>
        ),
      },
      {
        key: 'last_purchase',
        label: 'Last Purchase',
        skeletonClassName: 'w-24 h-4',
        cell: (s) => (
          <span className="text-xs text-muted-foreground tabular-nums">
            {s.last_purchase_date ? s.last_purchase_date.slice(0, 10) : '—'}
          </span>
        ),
      },
      {
        key: 'health',
        label: 'Procurement Health',
        defaultVisible: false,
        skeletonClassName: 'w-16 h-5 rounded-full',
        cell: (s) => <SupplierHealthCell supplierId={s.id} />,
      },
      {
        key: 'status',
        label: 'Status',
        alwaysVisible: true,
        sortable: true,
        skeletonClassName: 'w-14 h-5 rounded-full',
        cell: (s) => <SupplierStatusBadge isActive={s.is_active} />,
      },
      {
        key: 'actions',
        label: 'Actions',
        alwaysVisible: true,
        pin: 'right',
        width: 80,
        align: 'end',
        cell: (s) => (
          <div className="flex items-center gap-1 justify-end">
            <Button variant="ghost" size="sm" className="h-7 px-2 text-xs"
              onClick={(e) => { e.stopPropagation(); openEdit(s); }}>
              Edit
            </Button>
            <Button variant="ghost" size="sm" className="h-7 px-2 text-xs text-destructive hover:text-destructive"
              onClick={(e) => { e.stopPropagation(); setDeleting(s); }}>
              <Trash2 className="size-3.5" />
            </Button>
          </div>
        ),
      },
    ],
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [],
  );

  const hasActiveFilters = search !== '' || statusFilter !== 'all';

  function openEdit(supplier: Supplier) {
    setEditSupplier(supplier);
    setEditDrawerOpen(true);
  }

  function confirmDelete() {
    if (!deleting) return;
    deleteSupplier.mutate(deleting.id, {
      onSuccess: () => {
        setDeleting(null);
        toast.success('Supplier deleted.');
        selection.clearSelection();
      },
    });
  }

  const statusChips = [
    { key: 'all',      label: 'All',      active: statusFilter === 'all',      onClick: () => { setStatusFilter('all');      setPage(1); } },
    { key: 'active',   label: 'Active',   active: statusFilter === 'active',   onClick: () => { setStatusFilter('active');   setPage(1); } },
    { key: 'inactive', label: 'Inactive', active: statusFilter === 'inactive', onClick: () => { setStatusFilter('inactive'); setPage(1); } },
  ];

  return (
    <>
      <WorkspaceHeader
        breadcrumbs={[{ label: 'Purchasing' }, { label: 'Suppliers' }]}
        title="Suppliers"
        description="Manage your procurement supplier network."
        primaryAction={{ key: 'new', label: 'New Supplier', icon: Plus, onClick: () => setWizardOpen(true) }}
        metrics={metrics}
        savedViews={{
          views: [
            { id: 'default',   label: 'All Suppliers', isDefault: true },
            { id: 'active',    label: 'Active' },
            { id: 'preferred', label: 'Preferred' },
          ],
          activeId: statusFilter === 'active' ? 'active' : 'default',
          onViewChange: (id) => {
            if (id === 'active') { setStatusFilter('active'); setPage(1); }
            else { setStatusFilter('all'); setPage(1); }
          },
        }}
      />

      <WorkspacePage
        toolbar={
          <SmartToolbar
            primaryAction={{ label: 'New Supplier', icon: Plus, onClick: () => setWizardOpen(true) }}
            secondaryActions={[
              { key: 'export', label: 'Export CSV', icon: Download, onClick: () => exportCsv(items), hideOnMobile: true },
            ]}
            bulkActions={
              selection.selectedCount > 0
                ? [{ key: 'delete-bulk', label: `Delete ${selection.selectedCount} selected`, onClick: () => {}, destructive: true }]
                : undefined
            }
            selectedCount={selection.selectedCount}
            onRefresh={() => void refetch()}
            isFetching={isFetching}
            viewControls={
              <div className="flex items-center gap-2">
                <div className="relative">
                  <Search className="pointer-events-none absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
                  <Input
                    ref={searchRef}
                    placeholder="Search suppliers…"
                    value={search}
                    onChange={(e) => { setSearch(e.target.value); setPage(1); }}
                    className="h-8 w-[200px] pl-8 sm:w-[240px] text-sm"
                  />
                </div>
                <ColumnVisibilityMenu columns={COLUMN_META} visibility={visibility} onToggle={toggle} onReset={reset} />
              </div>
            }
          />
        }
        quickFilters={<QuickFilterChips chips={statusChips} className="px-4 sm:px-6" />}
        pagination={
          meta ? (
            <div className="flex items-center justify-between px-4 pb-2 sm:px-6 text-xs text-muted-foreground">
              <span>
                {meta.total} supplier{meta.total !== 1 ? 's' : ''}
                {selection.selectedCount > 0 && ` · ${selection.selectedCount} selected`}
              </span>
              <div className="flex items-center gap-2">
                <Button variant="ghost" size="sm" className="h-7 px-2 text-xs"
                  disabled={meta.current_page <= 1 || isFetching} onClick={() => setPage((p) => p - 1)}>
                  Previous
                </Button>
                <span>Page {meta.current_page} of {meta.last_page}</span>
                <Button variant="ghost" size="sm" className="h-7 px-2 text-xs"
                  disabled={meta.current_page >= meta.last_page || isFetching} onClick={() => setPage((p) => p + 1)}>
                  Next
                </Button>
              </div>
            </div>
          ) : null
        }
      >
        {items.length === 0 && hasActiveFilters && !isLoading ? (
          <PageNoResultsState query={search} onClear={() => { setSearch(''); setStatusFilter('all'); setPage(1); }} />
        ) : items.length === 0 && !isLoading && !isError ? (
          <PageEmptyState
            icon={Building2}
            title="No suppliers yet"
            description="Add your first supplier to start managing your procurement network."
            action={{ label: 'New Supplier', icon: Plus, onClick: () => setWizardOpen(true) }}
          />
        ) : (
          <UniversalDataGrid
            data={items}
            columns={columns}
            rowId={(s) => s.id}
            loading={isLoading}
            error={isError}
            sort={{ field: sort.field, direction: sort.direction }}
            onSortChange={(field) => {
              setSort((prev) => ({
                field: field as SupplierSortField,
                direction: prev.field === field ? (prev.direction === 'asc' ? 'desc' : 'asc') : 'asc',
              }));
              setPage(1);
            }}
            selection={selection}
            columnVisibility={visibility}
            skeletonRows={PER_PAGE}
          />
        )}
      </WorkspacePage>

      <Supplier360Drawer
        supplier={viewSupplier}
        open={viewSupplier !== null}
        onOpenChange={(open) => { if (!open) setViewSupplier(null); }}
        onEdit={(s) => { setViewSupplier(null); openEdit(s); }}
      />

      <SupplierFormDrawer
        open={editDrawerOpen}
        onOpenChange={(open) => { setEditDrawerOpen(open); if (!open) setEditSupplier(null); }}
        supplier={editSupplier}
      />

      <SupplierWizard
        open={wizardOpen}
        onOpenChange={setWizardOpen}
        onCreated={() => void refetch()}
      />

      <PageConfirmDialog
        open={deleting !== null}
        onOpenChange={(open) => { if (!open) setDeleting(null); }}
        title="Delete Supplier"
        description={`Are you sure you want to delete "${deleting?.name ?? ''}"? This action cannot be undone.`}
        confirmLabel="Delete"
        variant="destructive"
        loading={deleteSupplier.isPending}
        onConfirm={confirmDelete}
      />
    </>
  );
}

import { useMemo, useState } from 'react';
import {
  Building2,
  CheckCircle,
  Clock,
  Download,
  Eye,
  Pencil,
  Plus,
  Search,
  Trash2,
  Upload,
  XCircle,
} from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { ActionMenu, EntityTable, StatusBadge } from '@/components/crud';
import type { ColumnDef } from '@/components/crud/types';
import {
  PageConfirmDialog,
  PageEmptyState,
  PageErrorState,
  PageLoadingState,
  PageNoResultsState,
  PagePagination,
  PageToolbar,
  QuickFilterChips,
  WorkspacePage,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { WorkspaceHeader } from '@/components/workspace';
import type { SavedView, WorkspaceMetric } from '@/components/workspace';
import { SupplierFormDrawer } from '@/features/suppliers/components/supplier-form-drawer';
import { useDeleteSupplier, useSuppliersQuery } from '@/features/suppliers/hooks/use-suppliers';
import type {
  Supplier,
  SupplierSortField,
  SupplierStatusFilter,
} from '@/features/suppliers/types/supplier';

const PER_PAGE = 10;

const METRICS: WorkspaceMetric[] = [
  {
    id: 'total',
    icon: Building2,
    label: 'Total Suppliers',
    value: 47,
    colorClass: 'bg-primary/10 text-primary',
  },
  {
    id: 'active',
    icon: CheckCircle,
    label: 'Active',
    value: 38,
    colorClass: 'bg-emerald-500/10 text-emerald-600',
  },
  {
    id: 'inactive',
    icon: XCircle,
    label: 'Inactive',
    value: 9,
    colorClass: 'bg-muted text-muted-foreground',
  },
  {
    id: 'pending',
    icon: Clock,
    label: 'Pending Approval',
    value: 3,
    colorClass: 'bg-amber-500/10 text-amber-600',
  },
];

const VIEWS: SavedView[] = [
  { id: 'default', label: 'Default', isDefault: true },
  { id: 'active', label: 'Active' },
  { id: 'inactive', label: 'Inactive' },
];

export function SuppliersPage() {
  const { t } = useTranslation('suppliers');
  const { t: tCommon } = useTranslation('common');

  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState<SupplierStatusFilter>('all');
  const [page, setPage] = useState(1);
  const [sort, setSort] = useState<{ field: SupplierSortField; direction: 'asc' | 'desc' }>({
    field: 'created_at',
    direction: 'desc',
  });
  const [drawerOpen, setDrawerOpen] = useState(false);
  const [drawerSupplier, setDrawerSupplier] = useState<Supplier | null>(null);
  const [deleting, setDeleting] = useState<Supplier | null>(null);
  const [savedView, setSavedView] = useState('default');

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
  const deleteSupplier = useDeleteSupplier();

  const items = data?.items ?? [];
  const meta = data?.meta;

  const handleSearch = (value: string) => {
    setSearch(value);
    setPage(1);
  };

  const handleSort = (field: string) => {
    setSort((current) =>
      current.field === field
        ? { field: field as SupplierSortField, direction: current.direction === 'asc' ? 'desc' : 'asc' }
        : { field: field as SupplierSortField, direction: 'asc' },
    );
    setPage(1);
  };

  const openCreate = () => {
    setDrawerSupplier(null);
    setDrawerOpen(true);
  };

  const openEdit = (supplier: Supplier) => {
    setDrawerSupplier(supplier);
    setDrawerOpen(true);
  };

  const clearFilters = () => {
    setSearch('');
    setStatusFilter('all');
    setPage(1);
  };

  const hasActiveFilters = search !== '' || statusFilter !== 'all';

  const columns: ColumnDef<Supplier>[] = [
    {
      key: 'code',
      header: t('columns.code'),
      sortable: true,
      cell: (s) => <span className="font-medium">{s.code}</span>,
    },
    { key: 'name', header: t('columns.name'), sortable: true, cell: (s) => s.name },
    {
      key: 'contact_person',
      header: t('columns.contactPerson'),
      cell: (s) => <span className="text-muted-foreground">{s.contact_person ?? '—'}</span>,
    },
    {
      key: 'phone',
      header: t('columns.phone'),
      cell: (s) => <span className="text-muted-foreground">{s.phone ?? '—'}</span>,
    },
    {
      key: 'email',
      header: t('columns.email'),
      cell: (s) => <span className="text-muted-foreground">{s.email ?? '—'}</span>,
    },
    { key: 'country', header: t('columns.country'), sortable: true, cell: (s) => s.country ?? '—' },
    {
      key: 'is_active',
      header: t('columns.status'),
      sortable: true,
      cell: (s) => <StatusBadge status={s.is_active ? 'active' : 'inactive'} />,
    },
  ];

  const confirmDelete = () => {
    if (!deleting) return;
    deleteSupplier.mutate(deleting.id, { onSuccess: () => setDeleting(null) });
  };

  const statusChips = [
    {
      key: 'all',
      label: tCommon('status.all'),
      active: statusFilter === 'all',
      onClick: () => { setStatusFilter('all'); setPage(1); },
    },
    {
      key: 'active',
      label: tCommon('status.active'),
      active: statusFilter === 'active',
      onClick: () => { setStatusFilter('active'); setPage(1); },
    },
    {
      key: 'inactive',
      label: tCommon('status.inactive'),
      active: statusFilter === 'inactive',
      onClick: () => { setStatusFilter('inactive'); setPage(1); },
    },
  ];

  return (
    <>
      <WorkspaceHeader
        breadcrumbs={[{ label: t('title') }]}
        title={t('title')}
        description={t('subtitle')}
        primaryAction={{ key: 'new', label: t('actions.new'), icon: Plus, onClick: openCreate }}
        metrics={METRICS}
        savedViews={{ views: VIEWS, activeId: savedView, onViewChange: setSavedView }}
      />

      <WorkspacePage
        toolbar={
          <PageToolbar
            className="px-4 sm:px-6"
            left={
              <div className="relative">
                <Search className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                  placeholder={t('search')}
                  value={search}
                  onChange={(e) => handleSearch(e.target.value)}
                  className="h-8 w-[200px] pl-8 sm:w-[260px]"
                />
              </div>
            }
            right={
              <>
                <Button variant="outline" size="sm" disabled>
                  <Upload className="size-4" />
                  Import
                </Button>
                <Button variant="outline" size="sm" disabled>
                  <Download className="size-4" />
                  Export
                </Button>
                <Button variant="outline" size="sm" disabled>
                  Bulk Actions
                </Button>
              </>
            }
          />
        }
        quickFilters={
          <QuickFilterChips chips={statusChips} className="px-4 sm:px-6" />
        }
        pagination={
          meta ? (
            <PagePagination
              page={meta.current_page}
              perPage={meta.per_page}
              total={meta.total}
              lastPage={meta.last_page}
              onPageChange={setPage}
              isLoading={isFetching}
              className="px-4 pb-2 sm:px-6"
            />
          ) : null
        }
      >
        {isLoading ? (
          <PageLoadingState variant="table" />
        ) : isError ? (
          <PageErrorState onRetry={() => void refetch()} />
        ) : items.length === 0 && hasActiveFilters ? (
          <PageNoResultsState query={search} onClear={clearFilters} />
        ) : items.length === 0 ? (
          <PageEmptyState
            icon={Building2}
            title="No suppliers yet"
            description="Add your first supplier to get started."
            action={{ label: t('actions.new'), icon: Plus, onClick: openCreate }}
          />
        ) : (
          <EntityTable<Supplier>
            columns={columns}
            data={items}
            getRowId={(supplier) => supplier.id}
            sort={sort}
            onSortChange={handleSort}
            rowActions={(supplier) => (
              <ActionMenu
                label={`Actions for ${supplier.name}`}
                items={[
                  {
                    key: 'view',
                    label: tCommon('actions.view'),
                    icon: Eye,
                    onSelect: () => openEdit(supplier),
                  },
                  {
                    key: 'edit',
                    label: tCommon('common.edit'),
                    icon: Pencil,
                    onSelect: () => openEdit(supplier),
                  },
                  {
                    key: 'delete',
                    label: tCommon('common.delete'),
                    icon: Trash2,
                    variant: 'destructive',
                    onSelect: () => setDeleting(supplier),
                  },
                ]}
              />
            )}
          />
        )}
      </WorkspacePage>

      <SupplierFormDrawer
        open={drawerOpen}
        onOpenChange={(open) => {
          setDrawerOpen(open);
          if (!open) setDrawerSupplier(null);
        }}
        supplier={drawerSupplier}
      />

      <PageConfirmDialog
        open={deleting !== null}
        onOpenChange={(open) => { if (!open) setDeleting(null); }}
        title={t('delete.title')}
        description={tCommon('dialogs.softDeleteMessage', { name: deleting?.name ?? '' })}
        confirmLabel={t('delete.confirm')}
        variant="destructive"
        loading={deleteSupplier.isPending}
        onConfirm={confirmDelete}
      />
    </>
  );
}

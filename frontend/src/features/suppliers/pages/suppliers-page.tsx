import { useMemo, useState } from 'react';
import { Eye, Pencil, Plus, Trash2 } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import {
  ActionMenu,
  ConfirmDialog,
  EntityTable,
  EntityToolbar,
  PageHeader,
  Pagination,
  StatusBadge,
} from '@/components/crud';
import type { ColumnDef } from '@/components/crud/types';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { SupplierFormDrawer } from '@/features/suppliers/components/supplier-form-drawer';
import { useSuppliersQuery, useDeleteSupplier } from '@/features/suppliers/hooks/use-suppliers';
import type {
  Supplier,
  SupplierSortField,
  SupplierStatusFilter,
} from '@/features/suppliers/types/supplier';
import { ROUTES } from '@/router/routes';

const PER_PAGE = 10;

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

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={t('title')}
        subtitle={t('subtitle')}
        breadcrumbs={[{ label: tCommon('home'), to: ROUTES.dashboard }, { label: t('title') }]}
        actions={
          <Button onClick={openCreate}>
            <Plus className="size-4" />
            {t('actions.new')}
          </Button>
        }
      />

      <Card>
        <CardContent className="flex flex-col gap-4 pt-6">
          <EntityToolbar
            searchPlaceholder={t('search')}
            onSearchChange={handleSearch}
            onRefresh={() => void refetch()}
            isRefreshing={isFetching}
            onExport={() => undefined}
            onClearFilters={() => { setStatusFilter('all'); setPage(1); }}
            filterPanel={
              <div className="flex flex-col gap-1.5">
                <span className="text-sm font-medium">{tCommon('filters.status')}</span>
                <select
                  value={statusFilter}
                  onChange={(event) => {
                    setStatusFilter(event.target.value as SupplierStatusFilter);
                    setPage(1);
                  }}
                  className="border-input h-9 rounded-md border bg-transparent px-3 text-sm shadow-xs"
                >
                  <option value="all">{tCommon('status.all')}</option>
                  <option value="active">{tCommon('status.active')}</option>
                  <option value="inactive">{tCommon('status.inactive')}</option>
                </select>
              </div>
            }
          />

          <EntityTable<Supplier>
            columns={columns}
            data={items}
            getRowId={(supplier) => supplier.id}
            isLoading={isLoading}
            isError={isError}
            sort={sort}
            onSortChange={handleSort}
            rowActions={(supplier) => (
              <ActionMenu
                label={`Actions for ${supplier.name}`}
                items={[
                  { key: 'view', label: tCommon('actions.view'), icon: Eye, onSelect: () => openEdit(supplier) },
                  { key: 'edit', label: tCommon('common.edit'), icon: Pencil, onSelect: () => openEdit(supplier) },
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

          {meta ? (
            <Pagination
              meta={{
                page: meta.current_page,
                perPage: meta.per_page,
                total: meta.total,
                lastPage: meta.last_page,
              }}
              onPageChange={setPage}
            />
          ) : null}
        </CardContent>
      </Card>

      <SupplierFormDrawer
        open={drawerOpen}
        onOpenChange={(open) => {
          setDrawerOpen(open);
          if (!open) setDrawerSupplier(null);
        }}
        supplier={drawerSupplier}
      />

      <ConfirmDialog
        open={deleting !== null}
        onOpenChange={(open) => { if (!open) setDeleting(null); }}
        title={t('delete.title')}
        description={tCommon('dialogs.softDeleteMessage', { name: deleting?.name ?? '' })}
        confirmLabel={t('delete.confirm')}
        variant="destructive"
        loading={deleteSupplier.isPending}
        onConfirm={confirmDelete}
      />
    </div>
  );
}

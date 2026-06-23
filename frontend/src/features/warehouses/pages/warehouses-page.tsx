import { useMemo, useState } from 'react';
import { Eye, Pencil, Plus, Trash2 } from 'lucide-react';

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
import { CompanySelect } from '@/features/branches/components/company-select';
import { WarehouseFormDrawer } from '@/features/warehouses/components/warehouse-form-drawer';
import { useWarehousesQuery, useDeleteWarehouse } from '@/features/warehouses/hooks/use-warehouses';
import type {
  Warehouse,
  WarehouseSortField,
  WarehouseStatusFilter,
} from '@/features/warehouses/types/warehouse';
import { ROUTES } from '@/router/routes';

const PER_PAGE = 10;

export function WarehousesPage() {
  const [search, setSearch] = useState('');
  const [companyFilter, setCompanyFilter] = useState<string | null>(null);
  const [statusFilter, setStatusFilter] = useState<WarehouseStatusFilter>('all');
  const [page, setPage] = useState(1);
  const [sort, setSort] = useState<{ field: WarehouseSortField; direction: 'asc' | 'desc' }>({
    field: 'created_at',
    direction: 'desc',
  });

  const [drawerOpen, setDrawerOpen] = useState(false);
  const [drawerWarehouse, setDrawerWarehouse] = useState<Warehouse | null>(null);
  const [deleting, setDeleting] = useState<Warehouse | null>(null);

  const params = useMemo(
    () => ({
      search: search || undefined,
      company_id: companyFilter || undefined,
      status: statusFilter,
      page,
      per_page: PER_PAGE,
      sort_by: sort.field,
      sort_dir: sort.direction,
    }),
    [search, companyFilter, statusFilter, page, sort],
  );

  const { data, isLoading, isError, isFetching, refetch } = useWarehousesQuery(params);
  const deleteWarehouse = useDeleteWarehouse();

  const items = data?.items ?? [];
  const meta = data?.meta;

  const handleSearch = (value: string) => {
    setSearch(value);
    setPage(1);
  };

  const handleSort = (field: string) => {
    setSort((current) =>
      current.field === field
        ? {
            field: field as WarehouseSortField,
            direction: current.direction === 'asc' ? 'desc' : 'asc',
          }
        : { field: field as WarehouseSortField, direction: 'asc' },
    );
    setPage(1);
  };

  const openCreate = () => {
    setDrawerWarehouse(null);
    setDrawerOpen(true);
  };

  const openEdit = (warehouse: Warehouse) => {
    setDrawerWarehouse(warehouse);
    setDrawerOpen(true);
  };

  const columns: ColumnDef<Warehouse>[] = [
    { key: 'company', header: 'Company', cell: (w) => w.company?.name ?? '—' },
    { key: 'branch', header: 'Branch', cell: (w) => w.branch?.name ?? '—' },
    {
      key: 'code',
      header: 'Code',
      sortable: true,
      cell: (w) => <span className="font-medium">{w.code}</span>,
    },
    { key: 'name', header: 'Name', sortable: true, cell: (w) => w.name },
    { key: 'city', header: 'City', sortable: true, cell: (w) => w.city ?? '—' },
    {
      key: 'is_active',
      header: 'Status',
      sortable: true,
      cell: (w) => <StatusBadge status={w.is_active ? 'active' : 'inactive'} />,
    },
  ];

  const confirmDelete = () => {
    if (!deleting) {
      return;
    }
    deleteWarehouse.mutate(deleting.id, { onSuccess: () => setDeleting(null) });
  };

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Warehouses"
        subtitle="Manage storage locations across companies and branches."
        breadcrumbs={[{ label: 'Home', to: ROUTES.dashboard }, { label: 'Warehouses' }]}
        actions={
          <Button onClick={openCreate}>
            <Plus className="size-4" />
            New Warehouse
          </Button>
        }
      />

      <Card>
        <CardContent className="flex flex-col gap-4 pt-6">
          <EntityToolbar
            searchPlaceholder="Search warehouses…"
            onSearchChange={handleSearch}
            onRefresh={() => void refetch()}
            isRefreshing={isFetching}
            onExport={() => undefined}
            onClearFilters={() => {
              setCompanyFilter(null);
              setStatusFilter('all');
              setPage(1);
            }}
            filterPanel={
              <>
                <div className="flex flex-col gap-1.5">
                  <span className="text-sm font-medium">Company</span>
                  <CompanySelect
                    value={companyFilter}
                    onChange={(value) => {
                      setCompanyFilter(value);
                      setPage(1);
                    }}
                    placeholder="All companies"
                  />
                </div>
                <div className="flex flex-col gap-1.5">
                  <span className="text-sm font-medium">Status</span>
                  <select
                    value={statusFilter}
                    onChange={(event) => {
                      setStatusFilter(event.target.value as WarehouseStatusFilter);
                      setPage(1);
                    }}
                    className="border-input h-9 rounded-md border bg-transparent px-3 text-sm shadow-xs"
                  >
                    <option value="all">All</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                  </select>
                </div>
              </>
            }
          />

          <EntityTable<Warehouse>
            columns={columns}
            data={items}
            getRowId={(warehouse) => warehouse.id}
            isLoading={isLoading}
            isError={isError}
            sort={sort}
            onSortChange={handleSort}
            rowActions={(warehouse) => (
              <ActionMenu
                label={`Actions for ${warehouse.name}`}
                items={[
                  { key: 'view', label: 'View', icon: Eye, onSelect: () => openEdit(warehouse) },
                  { key: 'edit', label: 'Edit', icon: Pencil, onSelect: () => openEdit(warehouse) },
                  {
                    key: 'delete',
                    label: 'Delete',
                    icon: Trash2,
                    variant: 'destructive',
                    onSelect: () => setDeleting(warehouse),
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

      <WarehouseFormDrawer
        open={drawerOpen}
        onOpenChange={(open) => {
          setDrawerOpen(open);
          if (!open) {
            setDrawerWarehouse(null);
          }
        }}
        warehouse={drawerWarehouse}
      />

      <ConfirmDialog
        open={deleting !== null}
        onOpenChange={(open) => {
          if (!open) {
            setDeleting(null);
          }
        }}
        title="Delete warehouse"
        description={
          <>
            This will soft-delete{' '}
            <span className="text-foreground font-medium">{deleting?.name}</span>. It can be
            restored later.
          </>
        }
        confirmLabel="Delete"
        variant="destructive"
        loading={deleteWarehouse.isPending}
        onConfirm={confirmDelete}
      />
    </div>
  );
}

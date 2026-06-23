import { useMemo, useState } from 'react';
import { Pencil, Plus, Trash2 } from 'lucide-react';

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
import { CustomerFormDrawer } from '@/features/customers/components/customer-form-drawer';
import { useCustomersQuery, useDeleteCustomer } from '@/features/customers/hooks/use-customers';
import type {
  Customer,
  CustomerSortField,
  CustomerStatusFilter,
} from '@/features/customers/types/customer';
import { ROUTES } from '@/router/routes';

const PER_PAGE = 10;

export function CustomersPage() {
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState<CustomerStatusFilter>('all');
  const [page, setPage] = useState(1);
  const [sort, setSort] = useState<{ field: CustomerSortField; direction: 'asc' | 'desc' }>({
    field: 'created_at',
    direction: 'desc',
  });
  const [drawerOpen, setDrawerOpen] = useState(false);
  const [drawerCustomer, setDrawerCustomer] = useState<Customer | null>(null);
  const [deleting, setDeleting] = useState<Customer | null>(null);

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

  const { data, isLoading, isError, isFetching, refetch } = useCustomersQuery(params);
  const deleteCustomer = useDeleteCustomer();

  const items = data?.items ?? [];
  const meta = data?.meta;

  const handleSort = (field: string) => {
    setSort((curr) =>
      curr.field === field
        ? { field: field as CustomerSortField, direction: curr.direction === 'asc' ? 'desc' : 'asc' }
        : { field: field as CustomerSortField, direction: 'asc' },
    );
    setPage(1);
  };

  const openCreate = () => {
    setDrawerCustomer(null);
    setDrawerOpen(true);
  };

  const openEdit = (customer: Customer) => {
    setDrawerCustomer(customer);
    setDrawerOpen(true);
  };

  const columns: ColumnDef<Customer>[] = [
    {
      key: 'code',
      header: 'Code',
      sortable: true,
      cell: (c) => <span className="font-medium">{c.code}</span>,
    },
    { key: 'name', header: 'Name', sortable: true, cell: (c) => c.name },
    {
      key: 'contact_person',
      header: 'Contact Person',
      cell: (c) => <span className="text-muted-foreground">{c.contact_person ?? '—'}</span>,
    },
    {
      key: 'phone',
      header: 'Phone',
      cell: (c) => <span className="text-muted-foreground">{c.phone ?? '—'}</span>,
    },
    {
      key: 'email',
      header: 'Email',
      cell: (c) => <span className="text-muted-foreground">{c.email ?? '—'}</span>,
    },
    { key: 'country', header: 'Country', sortable: true, cell: (c) => c.country ?? '—' },
    {
      key: 'is_active',
      header: 'Status',
      sortable: true,
      cell: (c) => <StatusBadge status={c.is_active ? 'active' : 'inactive'} />,
    },
  ];

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Customers"
        subtitle="Manage the customers you sell to."
        breadcrumbs={[{ label: 'Home', to: ROUTES.dashboard }, { label: 'Customers' }]}
        actions={
          <Button onClick={openCreate}>
            <Plus className="size-4" />
            New Customer
          </Button>
        }
      />

      <Card>
        <CardContent className="flex flex-col gap-4 pt-6">
          <EntityToolbar
            searchPlaceholder="Search customers…"
            onSearchChange={(v) => { setSearch(v); setPage(1); }}
            onRefresh={() => void refetch()}
            isRefreshing={isFetching}
            onExport={() => undefined}
            onClearFilters={() => { setStatusFilter('all'); setPage(1); }}
            filterPanel={
              <div className="flex flex-col gap-1.5">
                <span className="text-sm font-medium">Status</span>
                <select
                  value={statusFilter}
                  onChange={(e) => { setStatusFilter(e.target.value as CustomerStatusFilter); setPage(1); }}
                  className="border-input h-9 rounded-md border bg-transparent px-3 text-sm shadow-xs"
                >
                  <option value="all">All</option>
                  <option value="active">Active</option>
                  <option value="inactive">Inactive</option>
                </select>
              </div>
            }
          />

          <EntityTable<Customer>
            columns={columns}
            data={items}
            getRowId={(c) => c.id}
            isLoading={isLoading}
            isError={isError}
            sort={sort}
            onSortChange={handleSort}
            rowActions={(customer) => (
              <ActionMenu
                label={`Actions for ${customer.name}`}
                items={[
                  { key: 'edit', label: 'Edit', icon: Pencil, onSelect: () => openEdit(customer) },
                  {
                    key: 'delete',
                    label: 'Delete',
                    icon: Trash2,
                    variant: 'destructive' as const,
                    onSelect: () => setDeleting(customer),
                  },
                ]}
              />
            )}
          />

          {meta ? (
            <Pagination
              meta={{ page: meta.current_page, perPage: meta.per_page, total: meta.total, lastPage: meta.last_page }}
              onPageChange={setPage}
            />
          ) : null}
        </CardContent>
      </Card>

      <CustomerFormDrawer
        open={drawerOpen}
        onOpenChange={(open) => { setDrawerOpen(open); if (!open) setDrawerCustomer(null); }}
        customer={drawerCustomer}
      />

      <ConfirmDialog
        open={deleting !== null}
        onOpenChange={(open) => { if (!open) setDeleting(null); }}
        title="Delete customer"
        description={
          <>
            This will soft-delete{' '}
            <span className="text-foreground font-medium">{deleting?.name}</span>. It can be
            restored later.
          </>
        }
        confirmLabel="Delete"
        variant="destructive"
        loading={deleteCustomer.isPending}
        onConfirm={() => {
          if (deleting) deleteCustomer.mutate(deleting.id, { onSuccess: () => setDeleting(null) });
        }}
      />
    </div>
  );
}

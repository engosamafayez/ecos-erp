import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { CheckCircle, Eye, Plus, Trash2, XCircle } from 'lucide-react';

import {
  ActionMenu,
  ConfirmDialog,
  EntityTable,
  EntityToolbar,
  PageHeader,
  Pagination,
} from '@/components/crud';
import type { ColumnDef } from '@/components/crud/types';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { FulfillmentStatusBadge } from '@/features/fulfillments/components/fulfillment-status-badge';
import {
  useCancelFulfillment,
  useDeleteFulfillment,
  useFulfillFulfillment,
  useFulfillmentsQuery,
} from '@/features/fulfillments/hooks/use-fulfillments';
import type {
  Fulfillment,
  FulfillmentSortField,
  FulfillmentStatus,
} from '@/features/fulfillments/types/fulfillment';
import { ROUTES } from '@/router/routes';

const PER_PAGE = 10;

export function FulfillmentsPage() {
  const navigate = useNavigate();
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState<FulfillmentStatus | 'all'>('all');
  const [page, setPage] = useState(1);
  const [sort, setSort] = useState<{ field: FulfillmentSortField; direction: 'asc' | 'desc' }>({
    field: 'created_at',
    direction: 'desc',
  });
  const [deleting, setDeleting] = useState<Fulfillment | null>(null);
  const [fulfilling, setFulfilling] = useState<Fulfillment | null>(null);
  const [cancelling, setCancelling] = useState<Fulfillment | null>(null);

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

  const { data, isLoading, isError, isFetching, refetch } = useFulfillmentsQuery(params);
  const deleteFulfillment = useDeleteFulfillment();
  const fulfillMutation = useFulfillFulfillment();
  const cancelMutation = useCancelFulfillment();

  const items = data?.items ?? [];
  const meta = data?.meta;

  const handleSort = (field: string) => {
    setSort((curr) =>
      curr.field === field
        ? { field: field as FulfillmentSortField, direction: curr.direction === 'asc' ? 'desc' : 'asc' }
        : { field: field as FulfillmentSortField, direction: 'asc' },
    );
    setPage(1);
  };

  const columns: ColumnDef<Fulfillment>[] = [
    {
      key: 'fulfillment_number',
      header: 'Fulfillment #',
      sortable: true,
      cell: (f) => <span className="font-medium">{f.fulfillment_number}</span>,
    },
    {
      key: 'order',
      header: 'Order',
      cell: (f) => (
        <span className="text-muted-foreground">{f.order?.order_number ?? '—'}</span>
      ),
    },
    {
      key: 'customer',
      header: 'Customer',
      cell: (f) => (
        <span className="text-muted-foreground">{f.order?.customer?.name ?? '—'}</span>
      ),
    },
    {
      key: 'warehouse',
      header: 'Warehouse',
      cell: (f) => (
        <span className="text-muted-foreground">{f.warehouse?.name ?? '—'}</span>
      ),
    },
    {
      key: 'fulfillment_date',
      header: 'Date',
      sortable: true,
      cell: (f) => <span className="text-muted-foreground">{f.fulfillment_date}</span>,
    },
    {
      key: 'status',
      header: 'Status',
      sortable: true,
      cell: (f) => <FulfillmentStatusBadge status={f.status} />,
    },
  ];

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Fulfillments"
        subtitle="Fulfill orders and track stock-out movements."
        breadcrumbs={[{ label: 'Home', to: ROUTES.dashboard }, { label: 'Fulfillments' }]}
        actions={
          <Button onClick={() => navigate(ROUTES.fulfillmentsNew)}>
            <Plus className="size-4" />
            New Fulfillment
          </Button>
        }
      />

      <Card>
        <CardContent className="flex flex-col gap-4 pt-6">
          <EntityToolbar
            searchPlaceholder="Search by fulfillment # or order number…"
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
                  onChange={(e) => { setStatusFilter(e.target.value as FulfillmentStatus | 'all'); setPage(1); }}
                  className="border-input h-9 rounded-md border bg-transparent px-3 text-sm shadow-xs"
                >
                  <option value="all">All</option>
                  <option value="pending">Pending</option>
                  <option value="fulfilled">Fulfilled</option>
                  <option value="cancelled">Cancelled</option>
                </select>
              </div>
            }
          />

          <EntityTable<Fulfillment>
            columns={columns}
            data={items}
            getRowId={(f) => f.id}
            isLoading={isLoading}
            isError={isError}
            sort={sort}
            onSortChange={handleSort}
            rowActions={(f) => (
              <ActionMenu
                label={`Actions for ${f.fulfillment_number}`}
                items={[
                  {
                    key: 'view',
                    label: 'View',
                    icon: Eye,
                    onSelect: () => navigate(`${ROUTES.fulfillments}/${f.id}`),
                  },
                  ...(f.status === 'pending'
                    ? [
                        {
                          key: 'fulfill',
                          label: 'Fulfill',
                          icon: CheckCircle,
                          onSelect: () => setFulfilling(f),
                        },
                        {
                          key: 'cancel',
                          label: 'Cancel',
                          icon: XCircle,
                          onSelect: () => setCancelling(f),
                        },
                        {
                          key: 'delete',
                          label: 'Delete',
                          icon: Trash2,
                          variant: 'destructive' as const,
                          onSelect: () => setDeleting(f),
                        },
                      ]
                    : []),
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

      <ConfirmDialog
        open={deleting !== null}
        onOpenChange={(open) => { if (!open) setDeleting(null); }}
        title="Delete fulfillment"
        description={
          <>
            Delete{' '}
            <span className="text-foreground font-medium">{deleting?.fulfillment_number}</span>?
            Only pending fulfillments can be deleted.
          </>
        }
        confirmLabel="Delete"
        variant="destructive"
        loading={deleteFulfillment.isPending}
        onConfirm={() => {
          if (deleting) deleteFulfillment.mutate(deleting.id, { onSuccess: () => setDeleting(null) });
        }}
      />

      <ConfirmDialog
        open={fulfilling !== null}
        onOpenChange={(open) => { if (!open) setFulfilling(null); }}
        title="Fulfill shipment"
        description={
          <>
            Fulfill{' '}
            <span className="text-foreground font-medium">{fulfilling?.fulfillment_number}</span>?
            Stock will be deducted from the warehouse.
          </>
        }
        confirmLabel="Fulfill"
        loading={fulfillMutation.isPending}
        onConfirm={() => {
          if (fulfilling) fulfillMutation.mutate(fulfilling.id, { onSuccess: () => setFulfilling(null) });
        }}
      />

      <ConfirmDialog
        open={cancelling !== null}
        onOpenChange={(open) => { if (!open) setCancelling(null); }}
        title="Cancel fulfillment"
        description={
          <>
            Cancel{' '}
            <span className="text-foreground font-medium">{cancelling?.fulfillment_number}</span>?
          </>
        }
        confirmLabel="Cancel Fulfillment"
        variant="destructive"
        loading={cancelMutation.isPending}
        onConfirm={() => {
          if (cancelling) cancelMutation.mutate(cancelling.id, { onSuccess: () => setCancelling(null) });
        }}
      />
    </div>
  );
}

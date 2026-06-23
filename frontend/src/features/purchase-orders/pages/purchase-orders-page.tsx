import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Eye, Pencil, Plus, Trash2, CheckCircle, XCircle } from 'lucide-react';

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
import { PoStatusBadge } from '@/features/purchase-orders/components/po-status-badge';
import {
  useApprovePurchaseOrder,
  useCancelPurchaseOrder,
  useDeletePurchaseOrder,
  usePurchaseOrdersQuery,
} from '@/features/purchase-orders/hooks/use-purchase-orders';
import type {
  PurchaseOrder,
  PurchaseOrderSortField,
  PurchaseOrderStatus,
} from '@/features/purchase-orders/types/purchase-order';
import { ROUTES } from '@/router/routes';

const PER_PAGE = 10;

type StatusFilter = PurchaseOrderStatus | 'all';

export function PurchaseOrdersPage() {
  const navigate = useNavigate();
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState<StatusFilter>('all');
  const [page, setPage] = useState(1);
  const [sort, setSort] = useState<{ field: PurchaseOrderSortField; direction: 'asc' | 'desc' }>({
    field: 'created_at',
    direction: 'desc',
  });

  const [deleting, setDeleting] = useState<PurchaseOrder | null>(null);
  const [approving, setApproving] = useState<PurchaseOrder | null>(null);
  const [cancelling, setCancelling] = useState<PurchaseOrder | null>(null);

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

  const { data, isLoading, isError, isFetching, refetch } = usePurchaseOrdersQuery(params);
  const deletePO = useDeletePurchaseOrder();
  const approvePO = useApprovePurchaseOrder();
  const cancelPO = useCancelPurchaseOrder();

  const items = data?.items ?? [];
  const meta = data?.meta;

  const handleSearch = (value: string) => {
    setSearch(value);
    setPage(1);
  };

  const handleSort = (field: string) => {
    setSort((current) =>
      current.field === field
        ? { field: field as PurchaseOrderSortField, direction: current.direction === 'asc' ? 'desc' : 'asc' }
        : { field: field as PurchaseOrderSortField, direction: 'asc' },
    );
    setPage(1);
  };

  const columns: ColumnDef<PurchaseOrder>[] = [
    {
      key: 'po_number',
      header: 'PO Number',
      sortable: true,
      cell: (po) => <span className="font-medium">{po.po_number}</span>,
    },
    {
      key: 'supplier',
      header: 'Supplier',
      cell: (po) => po.supplier?.name ?? '—',
    },
    {
      key: 'order_date',
      header: 'Order Date',
      sortable: true,
      cell: (po) => po.order_date,
    },
    {
      key: 'expected_date',
      header: 'Expected Date',
      sortable: true,
      cell: (po) => po.expected_date ?? '—',
    },
    {
      key: 'total',
      header: 'Total',
      sortable: true,
      cell: (po) => (
        <span className="font-medium">
          {po.total.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
        </span>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      sortable: true,
      cell: (po) => <PoStatusBadge status={po.status} />,
    },
  ];

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Purchase Orders"
        subtitle="Manage purchase orders sent to suppliers."
        breadcrumbs={[{ label: 'Home', to: ROUTES.dashboard }, { label: 'Purchase Orders' }]}
        actions={
          <Button onClick={() => navigate(ROUTES.purchaseOrdersNew)}>
            <Plus className="size-4" />
            New Order
          </Button>
        }
      />

      <Card>
        <CardContent className="flex flex-col gap-4 pt-6">
          <EntityToolbar
            searchPlaceholder="Search by PO number or supplier…"
            onSearchChange={handleSearch}
            onRefresh={() => void refetch()}
            isRefreshing={isFetching}
            onExport={() => undefined}
            onClearFilters={() => {
              setStatusFilter('all');
              setPage(1);
            }}
            filterPanel={
              <div className="flex flex-col gap-1.5">
                <span className="text-sm font-medium">Status</span>
                <select
                  value={statusFilter}
                  onChange={(event) => {
                    setStatusFilter(event.target.value as StatusFilter);
                    setPage(1);
                  }}
                  className="border-input h-9 rounded-md border bg-transparent px-3 text-sm shadow-xs"
                >
                  <option value="all">All</option>
                  <option value="draft">Draft</option>
                  <option value="approved">Approved</option>
                  <option value="cancelled">Cancelled</option>
                </select>
              </div>
            }
          />

          <EntityTable<PurchaseOrder>
            columns={columns}
            data={items}
            getRowId={(po) => po.id}
            isLoading={isLoading}
            isError={isError}
            sort={sort}
            onSortChange={handleSort}
            rowActions={(po) => (
              <ActionMenu
                label={`Actions for ${po.po_number}`}
                items={[
                  {
                    key: 'view',
                    label: 'View',
                    icon: Eye,
                    onSelect: () => navigate(`${ROUTES.purchaseOrders}/${po.id}`),
                  },
                  ...(po.status === 'draft'
                    ? [
                        {
                          key: 'edit',
                          label: 'Edit',
                          icon: Pencil,
                          onSelect: () => navigate(`${ROUTES.purchaseOrders}/${po.id}/edit`),
                        },
                        {
                          key: 'approve',
                          label: 'Approve',
                          icon: CheckCircle,
                          onSelect: () => setApproving(po),
                        },
                      ]
                    : []),
                  ...(po.status !== 'cancelled'
                    ? [
                        {
                          key: 'cancel',
                          label: 'Cancel',
                          icon: XCircle,
                          variant: 'destructive' as const,
                          onSelect: () => setCancelling(po),
                        },
                      ]
                    : []),
                  ...(po.status === 'draft'
                    ? [
                        {
                          key: 'delete',
                          label: 'Delete',
                          icon: Trash2,
                          variant: 'destructive' as const,
                          onSelect: () => setDeleting(po),
                        },
                      ]
                    : []),
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

      <ConfirmDialog
        open={deleting !== null}
        onOpenChange={(open) => { if (!open) setDeleting(null); }}
        title="Delete purchase order"
        description={
          <>
            This will permanently delete{' '}
            <span className="text-foreground font-medium">{deleting?.po_number}</span>. Only draft
            orders can be deleted.
          </>
        }
        confirmLabel="Delete"
        variant="destructive"
        loading={deletePO.isPending}
        onConfirm={() => {
          if (deleting) deletePO.mutate(deleting.id, { onSuccess: () => setDeleting(null) });
        }}
      />

      <ConfirmDialog
        open={approving !== null}
        onOpenChange={(open) => { if (!open) setApproving(null); }}
        title="Approve purchase order"
        description={
          <>
            Approve <span className="text-foreground font-medium">{approving?.po_number}</span>? The order will become
            read-only after approval.
          </>
        }
        confirmLabel="Approve"
        loading={approvePO.isPending}
        onConfirm={() => {
          if (approving) approvePO.mutate(approving.id, { onSuccess: () => setApproving(null) });
        }}
      />

      <ConfirmDialog
        open={cancelling !== null}
        onOpenChange={(open) => { if (!open) setCancelling(null); }}
        title="Cancel purchase order"
        description={
          <>
            Cancel <span className="text-foreground font-medium">{cancelling?.po_number}</span>? This
            action cannot be undone.
          </>
        }
        confirmLabel="Cancel Order"
        variant="destructive"
        loading={cancelPO.isPending}
        onConfirm={() => {
          if (cancelling) cancelPO.mutate(cancelling.id, { onSuccess: () => setCancelling(null) });
        }}
      />
    </div>
  );
}

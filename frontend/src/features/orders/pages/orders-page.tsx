import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Eye, Pencil, Plus, Trash2 } from 'lucide-react';
import { useTranslation } from 'react-i18next';

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
import { OrderStatusBadge } from '@/features/orders/components/order-status-badge';
import { useDeleteOrder, useOrdersQuery } from '@/features/orders/hooks/use-orders';
import type { Order, OrderSortField, OrderStatus } from '@/features/orders/types/order';
import { ROUTES } from '@/router/routes';

const PER_PAGE = 10;

type StatusFilter = OrderStatus | 'all';

export function OrdersPage() {
  const { t } = useTranslation('orders');
  const { t: tCommon } = useTranslation('common');
  const navigate = useNavigate();
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState<StatusFilter>('all');
  const [page, setPage] = useState(1);
  const [sort, setSort] = useState<{ field: OrderSortField; direction: 'asc' | 'desc' }>({
    field: 'created_at',
    direction: 'desc',
  });
  const [deleting, setDeleting] = useState<Order | null>(null);

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

  const { data, isLoading, isError, isFetching, refetch } = useOrdersQuery(params);
  const deleteOrder = useDeleteOrder();

  const items = data?.items ?? [];
  const meta = data?.meta;

  const handleSort = (field: string) => {
    setSort((curr) =>
      curr.field === field
        ? { field: field as OrderSortField, direction: curr.direction === 'asc' ? 'desc' : 'asc' }
        : { field: field as OrderSortField, direction: 'asc' },
    );
    setPage(1);
  };

  const columns: ColumnDef<Order>[] = [
    {
      key: 'order_number',
      header: t('columns.number'),
      sortable: true,
      cell: (o) => <span className="font-medium">{o.order_number}</span>,
    },
    {
      key: 'channel',
      header: t('columns.channel'),
      cell: (o) => (
        <span className="text-muted-foreground">{o.channel?.name ?? '—'}</span>
      ),
    },
    {
      key: 'customer',
      header: t('columns.customer'),
      cell: (o) => (
        <span className="text-muted-foreground">{o.customer?.name ?? '—'}</span>
      ),
    },
    {
      key: 'order_date',
      header: t('columns.orderDate'),
      sortable: true,
      cell: (o) => <span className="text-muted-foreground">{o.order_date}</span>,
    },
    {
      key: 'status',
      header: t('columns.status'),
      sortable: true,
      cell: (o) => <OrderStatusBadge status={o.status} />,
    },
    {
      key: 'total',
      header: t('columns.total'),
      sortable: true,
      cell: (o) => (
        <span className="font-medium">
          {o.total.toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
          })}
        </span>
      ),
    },
  ];

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={t('title')}
        subtitle={t('subtitle')}
        breadcrumbs={[{ label: tCommon('home'), to: ROUTES.dashboard }, { label: t('title') }]}
        actions={
          <Button onClick={() => navigate(ROUTES.ordersNew)}>
            <Plus className="size-4" />
            {t('actions.new')}
          </Button>
        }
      />

      <Card>
        <CardContent className="flex flex-col gap-4 pt-6">
          <EntityToolbar
            searchPlaceholder={t('search')}
            onSearchChange={(v) => { setSearch(v); setPage(1); }}
            onRefresh={() => void refetch()}
            isRefreshing={isFetching}
            onExport={() => undefined}
            onClearFilters={() => { setStatusFilter('all'); setPage(1); }}
            filterPanel={
              <div className="flex flex-col gap-1.5">
                <span className="text-sm font-medium">{tCommon('filters.status')}</span>
                <select
                  value={statusFilter}
                  onChange={(e) => { setStatusFilter(e.target.value as StatusFilter); setPage(1); }}
                  className="border-input h-9 rounded-md border bg-transparent px-3 text-sm shadow-xs"
                >
                  <option value="all">{tCommon('status.all')}</option>
                  <option value="pending">{t('status.pending')}</option>
                  <option value="processing">{t('status.processing')}</option>
                  <option value="completed">{t('status.completed')}</option>
                  <option value="cancelled">{t('status.cancelled')}</option>
                </select>
              </div>
            }
          />

          <EntityTable<Order>
            columns={columns}
            data={items}
            getRowId={(o) => o.id}
            isLoading={isLoading}
            isError={isError}
            sort={sort}
            onSortChange={handleSort}
            rowActions={(order) => (
              <ActionMenu
                label={`Actions for ${order.order_number}`}
                items={[
                  {
                    key: 'view',
                    label: tCommon('actions.view'),
                    icon: Eye,
                    onSelect: () => navigate(`${ROUTES.orders}/${order.id}`),
                  },
                  {
                    key: 'edit',
                    label: tCommon('common.edit'),
                    icon: Pencil,
                    onSelect: () => navigate(`${ROUTES.orders}/${order.id}/edit`),
                  },
                  {
                    key: 'delete',
                    label: tCommon('common.delete'),
                    icon: Trash2,
                    variant: 'destructive' as const,
                    onSelect: () => setDeleting(order),
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

      <ConfirmDialog
        open={deleting !== null}
        onOpenChange={(open) => { if (!open) setDeleting(null); }}
        title={t('delete.title')}
        description={tCommon('dialogs.softDeleteMessage', { name: deleting?.order_number ?? '' })}
        confirmLabel={t('delete.confirm')}
        variant="destructive"
        loading={deleteOrder.isPending}
        onConfirm={() => {
          if (deleting) deleteOrder.mutate(deleting.id, { onSuccess: () => setDeleting(null) });
        }}
      />
    </div>
  );
}

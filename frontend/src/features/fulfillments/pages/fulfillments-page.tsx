import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { CheckCircle, Eye, Plus, Trash2, XCircle } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import {
  ActionMenu,
  ConfirmDialog,
  EntityToolbar,
  PageHeader,
} from '@/components/crud';
import type { DataGridColumnDef, GridPaginationConfig } from '@/components/data-grid';
import { UniversalDataGrid } from '@/components/data-grid';
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
  const { t } = useTranslation('fulfillments');
  const { t: tCommon } = useTranslation('common');
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

  const columns: DataGridColumnDef<Fulfillment>[] = [
    {
      key: 'fulfillment_number',
      label: t($ => $.columns.number),
      sortable: true,
      cardRole: 'title',
      cell: (f) => <span className="font-medium">{f.fulfillment_number}</span>,
    },
    {
      key: 'order',
      label: t($ => $.columns.order),
      cell: (f) => (
        <span className="text-muted-foreground">{f.order?.order_number ?? '—'}</span>
      ),
    },
    {
      key: 'customer',
      label: t($ => $.columns.customer),
      cardRole: 'subtitle',
      cell: (f) => (
        <span className="text-muted-foreground">{f.order?.customer?.name ?? '—'}</span>
      ),
    },
    {
      key: 'channel',
      label: t($ => $.columns.channel, { defaultValue: 'Channel' }),
      cell: (f) => (
        <span className="text-muted-foreground">{f.order?.channel?.name ?? '—'}</span>
      ),
    },
    {
      key: 'warehouse',
      label: t($ => $.columns.warehouse),
      cell: (f) => (
        <span className="text-muted-foreground">{f.warehouse?.name ?? '—'}</span>
      ),
    },
    {
      key: 'fulfillment_date',
      label: t($ => $.columns.fulfillmentDate),
      sortable: true,
      cell: (f) => <span className="text-muted-foreground">{f.fulfillment_date}</span>,
    },
    {
      key: 'status',
      label: t($ => $.columns.status),
      sortable: true,
      cardRole: 'status',
      cell: (f) => <FulfillmentStatusBadge status={f.status} />,
    },
    {
      key: 'actions',
      label: '',
      align: 'end',
      alwaysVisible: true,
      cell: (f) => (
        <ActionMenu
          label={`Actions for ${f.fulfillment_number}`}
          items={[
            {
              key: 'view',
              label: tCommon($ => $.actions.view),
              icon: Eye,
              onSelect: () => navigate(`${ROUTES.fulfillments}/${f.id}`),
            },
            ...(f.status === 'pending'
              ? [
                  {
                    key: 'fulfill',
                    label: t($ => $.actions.fulfill),
                    icon: CheckCircle,
                    onSelect: () => setFulfilling(f),
                  },
                  {
                    key: 'cancel',
                    label: t($ => $.actions.cancel),
                    icon: XCircle,
                    onSelect: () => setCancelling(f),
                  },
                  {
                    key: 'delete',
                    label: tCommon($ => $.common.delete),
                    icon: Trash2,
                    variant: 'destructive' as const,
                    onSelect: () => setDeleting(f),
                  },
                ]
              : []),
          ]}
        />
      ),
    },
  ];

  const pagination: GridPaginationConfig | undefined = meta
    ? {
        meta: { page: meta.current_page, perPage: meta.per_page, total: meta.total, lastPage: meta.last_page },
        onPageChange: setPage,
      }
    : undefined;

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={t($ => $.title)}
        subtitle={t($ => $.subtitle)}
        breadcrumbs={[{ label: tCommon($ => $.home), to: ROUTES.dashboard }, { label: t($ => $.title) }]}
        actions={
          <Button onClick={() => navigate(ROUTES.fulfillmentsNew)}>
            <Plus className="size-4" />
            {t($ => $.actions.new)}
          </Button>
        }
      />

      <Card>
        <CardContent className="flex flex-col gap-4 pt-6">
          <EntityToolbar
            searchPlaceholder={t($ => $.search)}
            onSearchChange={(v) => { setSearch(v); setPage(1); }}
            onRefresh={() => void refetch()}
            isRefreshing={isFetching}
            onExport={() => undefined}
            onClearFilters={() => { setStatusFilter('all'); setPage(1); }}
            filterPanel={
              <div className="flex flex-col gap-1.5">
                <span className="text-sm font-medium">{tCommon($ => $.filters.status)}</span>
                <select
                  value={statusFilter}
                  onChange={(e) => { setStatusFilter(e.target.value as FulfillmentStatus | 'all'); setPage(1); }}
                  className="border-input h-9 rounded-md border bg-transparent px-3 text-sm shadow-xs"
                >
                  <option value="all">{tCommon($ => $.status.all)}</option>
                  <option value="pending">{t($ => $.status.pending)}</option>
                  <option value="fulfilled">{t($ => $.status.fulfilled)}</option>
                  <option value="cancelled">{t($ => $.status.cancelled)}</option>
                </select>
              </div>
            }
          />

          <UniversalDataGrid<Fulfillment>
            data={items}
            columns={columns}
            rowId={(f) => f.id}
            loading={isLoading}
            error={isError}
            sort={sort}
            onSortChange={handleSort}
            pagination={pagination}
          />
        </CardContent>
      </Card>

      <ConfirmDialog
        open={deleting !== null}
        onOpenChange={(open) => { if (!open) setDeleting(null); }}
        title={t($ => $.dialogs.delete.title)}
        description={t($ => $.dialogs.delete.description, { number: deleting?.fulfillment_number ?? '' })}
        confirmLabel={t($ => $.dialogs.delete.confirm)}
        variant="destructive"
        loading={deleteFulfillment.isPending}
        onConfirm={() => {
          if (deleting) deleteFulfillment.mutate(deleting.id, { onSuccess: () => setDeleting(null) });
        }}
      />

      <ConfirmDialog
        open={fulfilling !== null}
        onOpenChange={(open) => { if (!open) setFulfilling(null); }}
        title={t($ => $.dialogs.fulfill.title)}
        description={t($ => $.dialogs.fulfill.description, { number: fulfilling?.fulfillment_number ?? '' })}
        confirmLabel={t($ => $.dialogs.fulfill.confirm)}
        loading={fulfillMutation.isPending}
        onConfirm={() => {
          if (fulfilling) fulfillMutation.mutate(fulfilling.id, { onSuccess: () => setFulfilling(null) });
        }}
      />

      <ConfirmDialog
        open={cancelling !== null}
        onOpenChange={(open) => { if (!open) setCancelling(null); }}
        title={t($ => $.dialogs.cancel.title)}
        description={t($ => $.dialogs.cancel.description, { number: cancelling?.fulfillment_number ?? '' })}
        confirmLabel={t($ => $.dialogs.cancel.confirm)}
        variant="destructive"
        loading={cancelMutation.isPending}
        onConfirm={() => {
          if (cancelling) cancelMutation.mutate(cancelling.id, { onSuccess: () => setCancelling(null) });
        }}
      />
    </div>
  );
}

import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { CheckCircle, Eye, Pencil, Plus, SendHorizonal, Trash2, XCircle } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { ActionMenu, ConfirmDialog, EntityToolbar, ErrorState } from '@/components/crud';
import { UniversalDataGrid, WorkspaceHeader } from '@/components/foundation';
import type { DataGridColumnDef } from '@/components/foundation';
import { MobileDataCard } from '@/components/mobile';
import { Card, CardContent } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { PoStatusBadge } from '@/features/purchase-orders/components/po-status-badge';
import {
  useApprovePurchaseOrder,
  useCancelPurchaseOrder,
  useDeletePurchaseOrder,
  usePurchaseOrdersQuery,
  useSubmitPurchaseOrder,
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
  const { t } = useTranslation('purchase-orders');
  const { t: tCommon } = useTranslation('common');
  const navigate = useNavigate();
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState<StatusFilter>('all');
  const [page, setPage] = useState(1);
  const [sort, setSort] = useState<{ field: PurchaseOrderSortField; direction: 'asc' | 'desc' }>({
    field: 'created_at',
    direction: 'desc',
  });

  const [deleting, setDeleting] = useState<PurchaseOrder | null>(null);
  const [submitting, setSubmitting] = useState<PurchaseOrder | null>(null);
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
  const submitPO = useSubmitPurchaseOrder();
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

  // Shared between the desktop "Actions" column and the mobile card's action
  // slot — same menu, same items, one definition (TASK-ECOS-V1.1-CORE-01-UI-03-
  // LIST-TABLE-FILTER-WORK-QUEUE-047).
  function rowActions(po: PurchaseOrder) {
    return (
      <ActionMenu
        label={`Actions for ${po.po_number}`}
        items={[
          { key: 'view', label: tCommon($ => $.actions.view), icon: Eye, onSelect: () => navigate(`${ROUTES.purchaseOrders}/${po.id}`) },
          ...(po.status === 'draft'
            ? [
                { key: 'edit', label: tCommon($ => $.common.edit), icon: Pencil, onSelect: () => navigate(`${ROUTES.purchaseOrders}/${po.id}/edit`) },
                { key: 'submit', label: t($ => $.actions.submit), icon: SendHorizonal, onSelect: () => setSubmitting(po) },
              ]
            : []),
          ...(po.status === 'submitted'
            ? [{ key: 'approve', label: t($ => $.actions.approve), icon: CheckCircle, onSelect: () => setApproving(po) }]
            : []),
          ...(!['cancelled', 'received'].includes(po.status)
            ? [{ key: 'cancel', label: tCommon($ => $.common.cancel), icon: XCircle, variant: 'destructive' as const, onSelect: () => setCancelling(po) }]
            : []),
          ...(po.status === 'draft'
            ? [{ key: 'delete', label: tCommon($ => $.common.delete), icon: Trash2, variant: 'destructive' as const, onSelect: () => setDeleting(po) }]
            : []),
        ]}
      />
    );
  }

  // Canonical list pattern (UI-03): UniversalDataGrid columns replace
  // EntityTable's ColumnDef[] — `label` (Column Manager text, unused today
  // since this page doesn't enable column visibility, but required by the
  // canonical type) is set to the same translated string as the visible
  // header. Row actions move from EntityTable's dedicated `rowActions` prop
  // into a trailing column, since UniversalDataGrid has no equivalent prop.
  const columns: DataGridColumnDef<PurchaseOrder>[] = [
    {
      key: 'po_number',
      label: t($ => $.columns.number),
      sortable: true,
      cardRole: 'title',
      cell: (po) => <span className="font-medium">{po.po_number}</span>,
    },
    {
      key: 'status',
      label: t($ => $.columns.status),
      sortable: true,
      cardRole: 'status',
      cell: (po) => <PoStatusBadge status={po.status} />,
    },
    {
      key: 'supplier',
      label: t($ => $.columns.supplier),
      cardRole: 'subtitle',
      cell: (po) => po.supplier?.name ?? '—',
    },
    { key: 'warehouse', label: t($ => $.columns.warehouse), cell: (po) => po.warehouse?.name ?? '—' },
    { key: 'order_date', label: t($ => $.columns.orderDate), sortable: true, cell: (po) => po.order_date },
    { key: 'expected_date', label: t($ => $.columns.expectedDate), sortable: true, cell: (po) => po.expected_date ?? '—' },
    {
      key: 'grand_total',
      label: t($ => $.columns.total),
      sortable: true,
      align: 'end',
      cell: (po) => (
        <span className="font-medium">
          {(po.grand_total ?? po.total).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
        </span>
      ),
    },
    {
      key: 'received_percentage',
      label: t($ => $.columns.receivedPct),
      cell: (po) => {
        if (po.received_percentage === null || po.received_percentage === undefined) return '—';
        const pct = Math.min(100, Math.max(0, po.received_percentage));
        return (
          <div className="flex items-center gap-2 min-w-[80px]">
            <div className="h-1.5 flex-1 rounded-full bg-muted overflow-hidden">
              <div className="h-full rounded-full bg-emerald-500" style={{ width: `${pct}%` }} />
            </div>
            <span className="text-xs tabular-nums">{Math.round(pct)}%</span>
          </div>
        );
      },
    },
    {
      key: 'actions',
      label: tCommon($ => $.table.actions),
      align: 'end',
      alwaysVisible: true,
      cardRole: 'hidden', // rendered via the mobile card's own `actions` slot below, not as a meta field
      cell: (po) => rowActions(po),
    },
  ];

  // Custom mobile card (rather than UniversalDataGrid's generic auto-card
  // fallback): the auto-card has no dedicated actions slot, so the "actions"
  // column above would otherwise render as a plain meta field instead of a
  // proper tap-visible action row — this preserves EntityTable's previous
  // mobile presentation instead of regressing it.
  function renderMobileCard(po: PurchaseOrder) {
    return (
      <MobileDataCard
        title={po.po_number}
        subtitle={po.supplier?.name ?? undefined}
        status={<PoStatusBadge status={po.status} />}
        fields={[
          { label: t($ => $.columns.warehouse), value: po.warehouse?.name ?? '—' },
          { label: t($ => $.columns.orderDate), value: po.order_date },
          { label: t($ => $.columns.expectedDate), value: po.expected_date ?? '—' },
          {
            label: t($ => $.columns.total),
            value: (po.grand_total ?? po.total).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }),
            align: 'end',
          },
        ]}
        actions={rowActions(po)}
      />
    );
  }

  return (
    <div className="flex flex-col gap-6">
      <WorkspaceHeader
        title={t($ => $.title)}
        description={t($ => $.subtitle)}
        breadcrumbs={[{ label: t($ => $.title) }]}
        primaryAction={{
          key: 'new',
          label: t($ => $.actions.new),
          icon: Plus,
          onClick: () => navigate(ROUTES.purchaseOrdersNew),
        }}
      />

      <Card>
        <CardContent className="flex flex-col gap-4 pt-6">
          <EntityToolbar
            searchPlaceholder={t($ => $.search)}
            onSearchChange={handleSearch}
            onRefresh={() => void refetch()}
            isRefreshing={isFetching}
            onExport={() => undefined}
            onClearFilters={() => { setStatusFilter('all'); setPage(1); }}
            filterPanel={
              <div className="flex flex-col gap-1.5">
                <span className="text-sm font-medium">{t($ => $.filters.status)}</span>
                {/* Canonical ui/select (UI-03 §5) — this was a raw native <select>
                    before; a status filter is a simple non-searchable dropdown,
                    exactly the case the filter contract reserves for ui/select
                    rather than EcosCombobox. */}
                <Select
                  value={statusFilter}
                  onValueChange={(value) => { setStatusFilter(value as StatusFilter); setPage(1); }}
                >
                  <SelectTrigger className="w-full">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="all">{tCommon($ => $.status.all)}</SelectItem>
                    <SelectItem value="draft">{t($ => $.status.draft)}</SelectItem>
                    <SelectItem value="submitted">{t($ => $.status.submitted)}</SelectItem>
                    <SelectItem value="approved">{t($ => $.status.approved)}</SelectItem>
                    <SelectItem value="partially_received">{t($ => $.status.partially_received)}</SelectItem>
                    <SelectItem value="received">{t($ => $.status.received)}</SelectItem>
                    <SelectItem value="cancelled">{t($ => $.status.cancelled)}</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            }
          />

          <UniversalDataGrid<PurchaseOrder>
            data={items}
            columns={columns}
            rowId={(po) => po.id}
            loading={isLoading}
            error={isError}
            errorState={<ErrorState onRetry={() => void refetch()} />}
            sort={sort}
            onSortChange={handleSort}
            renderMobileCard={renderMobileCard}
            pagination={
              meta
                ? {
                    meta: { page: meta.current_page, perPage: meta.per_page, total: meta.total, lastPage: meta.last_page },
                    onPageChange: setPage,
                  }
                : undefined
            }
          />
        </CardContent>
      </Card>

      <ConfirmDialog
        open={deleting !== null}
        onOpenChange={(open) => { if (!open) setDeleting(null); }}
        title={t($ => $.delete.title)}
        description={tCommon($ => $.dialogs.softDeleteMessage, { name: deleting?.po_number ?? '' })}
        confirmLabel={t($ => $.delete.confirm)}
        variant="destructive"
        loading={deletePO.isPending}
        onConfirm={() => { if (deleting) deletePO.mutate(deleting.id, { onSuccess: () => setDeleting(null) }); }}
      />

      <ConfirmDialog
        open={submitting !== null}
        onOpenChange={(open) => { if (!open) setSubmitting(null); }}
        title={t($ => $.dialogs.submit.title)}
        description={t($ => $.dialogs.submit.description, { number: submitting?.po_number ?? '' })}
        confirmLabel={t($ => $.dialogs.submit.confirm)}
        loading={submitPO.isPending}
        onConfirm={() => { if (submitting) submitPO.mutate(submitting.id, { onSuccess: () => setSubmitting(null) }); }}
      />

      <ConfirmDialog
        open={approving !== null}
        onOpenChange={(open) => { if (!open) setApproving(null); }}
        title={t($ => $.dialogs.approve.title)}
        description={t($ => $.dialogs.approve.description, { number: approving?.po_number ?? '' })}
        confirmLabel={t($ => $.dialogs.approve.confirm)}
        loading={approvePO.isPending}
        onConfirm={() => { if (approving) approvePO.mutate(approving.id, { onSuccess: () => setApproving(null) }); }}
      />

      <ConfirmDialog
        open={cancelling !== null}
        onOpenChange={(open) => { if (!open) setCancelling(null); }}
        title={t($ => $.dialogs.cancel.title)}
        description={t($ => $.dialogs.cancel.description, { number: cancelling?.po_number ?? '' })}
        confirmLabel={t($ => $.dialogs.cancel.confirm)}
        variant="destructive"
        loading={cancelPO.isPending}
        onConfirm={() => { if (cancelling) cancelPO.mutate(cancelling.id, { onSuccess: () => setCancelling(null) }); }}
      />
    </div>
  );
}

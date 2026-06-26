import { useMemo, useRef, useState } from 'react';
import {
  ChevronDown,
  Download,
  Filter,
  Plus,
  RefreshCw,
  Search,
  Upload,
  Users,
} from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Pagination } from '@/components/crud';
import { ConfirmDialog } from '@/components/crud';
import { PageHeader } from '@/components/crud';
import { useChannelOptions } from '@/features/channels/hooks/use-channel-options';
import type { AdvancedFilterValues } from '@/features/orders/components/order-advanced-filters';
import { OrderAdvancedFilters } from '@/features/orders/components/order-advanced-filters';
import { OrderCustomerIntelligence } from '@/features/orders/components/order-customer-intelligence';
import { OrderDetailDrawer } from '@/features/orders/components/order-detail-drawer';
import { OrderFormDrawer } from '@/features/orders/components/order-form-drawer';
import { OrderSmartToolbar } from '@/features/orders/components/order-smart-toolbar';
import { OrderStatusTabs } from '@/features/orders/components/order-status-tabs';
import { OrderTable } from '@/features/orders/components/order-table';
import {
  useDeleteOrder,
  useOrderStatusCounts,
  useOrdersQuery,
} from '@/features/orders/hooks/use-orders';
import type {
  CustomerIntelligenceFilter,
  Order,
  OrderSortField,
  OrderStatus,
} from '@/features/orders/types/order';
import { ROUTES } from '@/router/routes';
import { cn } from '@/lib/utils';

const PER_PAGE = 20;

type StatusFilter = OrderStatus | 'all';

const EMPTY_ADVANCED: AdvancedFilterValues = {
  productId: null,
  paymentMethod: null,
  shippingCompany: null,
  dateFrom: null,
  dateTo: null,
};

export function OrdersPage() {
  const { t } = useTranslation('orders');
  const { t: tCommon } = useTranslation('common');

  // ── Status tab ────────────────────────────────────────────────────────────────
  const [activeStatus, setActiveStatus] = useState<StatusFilter>('all');

  // ── Pagination + sort ─────────────────────────────────────────────────────────
  const [page, setPage] = useState(1);
  const [sort, setSort] = useState<{ field: OrderSortField; direction: 'asc' | 'desc' }>({
    field: 'created_at',
    direction: 'desc',
  });

  // ── Search ────────────────────────────────────────────────────────────────────
  const [searchKey, setSearchKey] = useState(0);
  const [search, setSearch] = useState('');
  const searchRef = useRef<HTMLInputElement>(null);

  // ── DD-023 Channel filter ─────────────────────────────────────────────────────
  const [channelId, setChannelId] = useState<string | null>(null);
  const { data: channelOptions = [] } = useChannelOptions();

  // ── DD-026 Advanced filters ───────────────────────────────────────────────────
  const [showAdvancedFilters, setShowAdvancedFilters] = useState(false);
  const [advancedFilters, setAdvancedFilters] = useState<AdvancedFilterValues>(EMPTY_ADVANCED);

  // ── DD-025 Customer Intelligence ──────────────────────────────────────────────
  const [showCustomerIntelligence, setShowCustomerIntelligence] = useState(false);
  const [customerFilter, setCustomerFilter] = useState<CustomerIntelligenceFilter | null>(null);

  // ── DD-031 Toolbar ops — direct query param toggles ───────────────────────────
  const [hasLocation, setHasLocation] = useState<boolean | null>(null);
  const [minShippingAttempts, setMinShippingAttempts] = useState<number | null>(null);

  // ── Selection ─────────────────────────────────────────────────────────────────
  const [selectedIds, setSelectedIds] = useState<Set<string>>(new Set());

  // ── Drawer state ──────────────────────────────────────────────────────────────
  const [viewOrder, setViewOrder] = useState<Order | null>(null);
  const [editOrder, setEditOrder] = useState<Order | null>(null);
  const [editDrawerOpen, setEditDrawerOpen] = useState(false);
  const [deletingOrder, setDeletingOrder] = useState<Order | null>(null);
  const [newOrderOpen, setNewOrderOpen] = useState(false);

  // ── Query ─────────────────────────────────────────────────────────────────────
  const params = useMemo(
    () => ({
      search: search || undefined,
      status: activeStatus === 'all' ? undefined : activeStatus,
      channel_id: channelId ?? undefined,
      product_id: advancedFilters.productId ?? undefined,
      payment_method: advancedFilters.paymentMethod ?? undefined,
      shipping_company: advancedFilters.shippingCompany ?? undefined,
      date_from: advancedFilters.dateFrom ?? undefined,
      date_to: advancedFilters.dateTo ?? undefined,
      customer_filter: customerFilter ?? undefined,
      has_location: hasLocation ?? undefined,
      min_shipping_attempts: minShippingAttempts ?? undefined,
      page,
      per_page: PER_PAGE,
      sort_by: sort.field,
      sort_dir: sort.direction,
    }),
    [search, activeStatus, channelId, advancedFilters, customerFilter, hasLocation, minShippingAttempts, page, sort],
  );

  const { data, isLoading, isError, isFetching, refetch } = useOrdersQuery(params);
  const statusCounts = useOrderStatusCounts();
  const deleteOrder = useDeleteOrder();

  const orders = data?.items ?? [];
  const meta = data?.meta;

  // ── Active filter counts (for badge on filter toggle) ─────────────────────────
  const advancedActiveCount = Object.values(advancedFilters).filter(Boolean).length
    + (channelId ? 1 : 0);
  const intelligenceActive = customerFilter !== null;

  // ── Reset helpers ─────────────────────────────────────────────────────────────
  function resetPage() { setPage(1); setSelectedIds(new Set()); }

  function handleStatusChange(status: StatusFilter) {
    setActiveStatus(status);
    resetPage();
  }

  function handleSortChange(field: OrderSortField) {
    setSort((curr) =>
      curr.field === field
        ? { field, direction: curr.direction === 'asc' ? 'desc' : 'asc' }
        : { field, direction: 'asc' },
    );
    setPage(1);
  }

  function handleSearchCommit(value: string) {
    setSearch(value);
    resetPage();
  }

  function clearSearch() {
    setSearchKey((k) => k + 1);
    setSearch('');
    resetPage();
  }

  function handleSelectRow(id: string, checked: boolean) {
    setSelectedIds((prev) => {
      const next = new Set(prev);
      if (checked) next.add(id); else next.delete(id);
      return next;
    });
  }

  function handleSelectAll(checked: boolean) {
    setSelectedIds(checked ? new Set(orders.map((o) => o.id)) : new Set());
  }

  function handleEdit(order: Order) {
    setEditOrder(order);
    setEditDrawerOpen(true);
  }

  function clearAdvancedFilters() {
    setAdvancedFilters(EMPTY_ADVANCED);
    setChannelId(null);
    resetPage();
  }

  function handleAdvancedFiltersChange(next: AdvancedFilterValues) {
    setAdvancedFilters(next);
    resetPage();
  }

  function handleCustomerFilterChange(next: CustomerIntelligenceFilter | null) {
    setCustomerFilter(next);
    resetPage();
  }

  return (
    <div className="flex h-full flex-col">
      {/* ── Page header ── */}
      <div className="border-b bg-background px-6 py-4">
        <PageHeader
          title={t('title')}
          subtitle={t('subtitle')}
          breadcrumbs={[
            { label: tCommon('home'), to: ROUTES.dashboard },
            { label: t('title') },
          ]}
          actions={
            <div className="flex items-center gap-2">
              <Button onClick={() => setNewOrderOpen(true)}>
                <Plus className="size-4" />
                {t('actions.new')}
              </Button>
              <Button variant="outline" size="sm">
                <Upload className="size-3.5" />
                {t('actions.import')}
              </Button>
              <Button variant="outline" size="sm">
                <Download className="size-3.5" />
                {t('actions.export')}
              </Button>

              {selectedIds.size > 0 ? (
                <DropdownMenu>
                  <DropdownMenuTrigger asChild>
                    <Button variant="outline" size="sm">
                      {t('actions.bulkActions')}
                      <span className="ms-1 inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-primary/15 px-1 text-[10px] font-semibold text-primary">
                        {selectedIds.size}
                      </span>
                      <ChevronDown className="size-3.5" />
                    </Button>
                  </DropdownMenuTrigger>
                  <DropdownMenuContent align="end" className="w-44">
                    <DropdownMenuItem>{t('bulk.confirm')}</DropdownMenuItem>
                    <DropdownMenuItem>{t('bulk.markShipping')}</DropdownMenuItem>
                    <DropdownMenuItem>{t('bulk.markDelivered')}</DropdownMenuItem>
                    <DropdownMenuItem variant="destructive">{t('bulk.cancel')}</DropdownMenuItem>
                  </DropdownMenuContent>
                </DropdownMenu>
              ) : null}

              <Button
                variant="ghost"
                size="icon"
                className="size-8 shrink-0"
                onClick={() => void refetch()}
                disabled={isFetching}
                aria-label={tCommon('actions.refresh')}
              >
                <RefreshCw className={`size-3.5 ${isFetching ? 'animate-spin' : ''}`} />
              </Button>
            </div>
          }
        />
      </div>

      {/* ── Sticky status tabs ── */}
      <div className="sticky top-0 z-20 bg-background">
        <OrderStatusTabs
          activeStatus={activeStatus}
          counts={statusCounts}
          onChange={handleStatusChange}
        />
      </div>

      {/* ── Filter bar: Search + Channel + toggles ── */}
      <div className="border-b bg-background px-4 py-2">
        <div className="flex flex-wrap items-center gap-2">
          {/* Search — DD-024: also covers product name / SKU */}
          <div className="relative min-w-48 flex-1">
            <Search className="pointer-events-none absolute start-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
            <input
              key={searchKey}
              ref={searchRef}
              type="search"
              placeholder={t('search')}
              defaultValue={search}
              onKeyDown={(e) => {
                if (e.key === 'Enter') handleSearchCommit(e.currentTarget.value);
                if (e.key === 'Escape') clearSearch();
              }}
              onBlur={(e) => handleSearchCommit(e.currentTarget.value)}
              className="h-8 w-full rounded-md border border-input bg-background ps-8 pe-3 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
            />
          </div>

          {/* DD-023 — Channel filter */}
          <select
            value={channelId ?? ''}
            onChange={(e) => {
              setChannelId(e.target.value || null);
              resetPage();
            }}
            aria-label={t('filters.channel')}
            className="h-8 rounded-md border border-input bg-background px-2 text-sm text-foreground focus:outline-none focus:ring-1 focus:ring-ring"
          >
            <option value="">{t('filters.allChannels')}</option>
            {channelOptions.map((c) => (
              <option key={c.value} value={c.value}>{c.label}</option>
            ))}
          </select>

          {/* Advanced Filters toggle — DD-026 */}
          <Button
            type="button"
            variant={showAdvancedFilters ? 'secondary' : 'outline'}
            size="sm"
            onClick={() => setShowAdvancedFilters((v) => !v)}
            aria-expanded={showAdvancedFilters}
            aria-label={t('filters.advanced')}
          >
            <Filter className="size-3.5" />
            {t('filters.advanced')}
            {advancedActiveCount > 0 ? (
              <span className={cn(
                'inline-flex h-4 min-w-4 items-center justify-center rounded-full px-1 text-[10px] font-semibold',
                showAdvancedFilters ? 'bg-foreground/20 text-foreground' : 'bg-primary/15 text-primary',
              )}>
                {advancedActiveCount}
              </span>
            ) : null}
          </Button>

          {/* Customer Intelligence toggle — DD-025 */}
          <Button
            type="button"
            variant={showCustomerIntelligence ? 'secondary' : 'outline'}
            size="sm"
            onClick={() => setShowCustomerIntelligence((v) => !v)}
            aria-expanded={showCustomerIntelligence}
            aria-label={t('customerIntelligence.title')}
          >
            <Users className="size-3.5" />
            {t('customerIntelligence.title')}
            {intelligenceActive ? (
              <span className={cn(
                'inline-flex h-4 min-w-4 items-center justify-center rounded-full px-1 text-[10px] font-semibold',
                showCustomerIntelligence ? 'bg-foreground/20 text-foreground' : 'bg-primary/15 text-primary',
              )}>
                1
              </span>
            ) : null}
          </Button>
        </div>
      </div>

      {/* ── DD-026 Advanced Filters panel (collapsible) ── */}
      {showAdvancedFilters ? (
        <OrderAdvancedFilters
          values={advancedFilters}
          onChange={handleAdvancedFiltersChange}
          onClear={clearAdvancedFilters}
        />
      ) : null}

      {/* ── DD-025 Customer Intelligence panel (collapsible) ── */}
      {showCustomerIntelligence ? (
        <OrderCustomerIntelligence
          value={customerFilter}
          onChange={handleCustomerFilterChange}
        />
      ) : null}

      {/* ── DD-028 / DD-029 Smart Operations Toolbar ── */}
      <OrderSmartToolbar
        activeStatus={activeStatus}
        selectedIds={selectedIds}
        orders={orders}
        advancedFilters={advancedFilters}
        setAdvancedFilters={(next) => { setAdvancedFilters(next); resetPage(); }}
        setCustomerFilter={handleCustomerFilterChange}
        setActiveStatus={handleStatusChange}
        showAdvancedFilters={showAdvancedFilters}
        setShowAdvancedFilters={setShowAdvancedFilters}
        showCustomerIntelligence={showCustomerIntelligence}
        setShowCustomerIntelligence={setShowCustomerIntelligence}
        setHasLocation={(v) => { setHasLocation(v); resetPage(); }}
        setMinShippingAttempts={(v) => { setMinShippingAttempts(v); resetPage(); }}
      />

      {/* ── Table ── */}
      <div className="flex-1 overflow-auto px-4 py-3">
        <OrderTable
          orders={orders}
          isLoading={isLoading}
          isError={isError}
          sort={sort}
          onSortChange={handleSortChange}
          selectedIds={selectedIds}
          onSelectRow={handleSelectRow}
          onSelectAll={handleSelectAll}
          onView={(order) => setViewOrder(order)}
          onEdit={handleEdit}
          onDelete={(order) => setDeletingOrder(order)}
        />

        {meta ? (
          <div className="mt-3">
            <Pagination
              meta={{
                page: meta.current_page,
                perPage: meta.per_page,
                total: meta.total,
                lastPage: meta.last_page,
              }}
              onPageChange={(p) => { setPage(p); setSelectedIds(new Set()); }}
            />
          </div>
        ) : null}
      </div>

      {/* ── Detail Drawer ── */}
      <OrderDetailDrawer
        order={viewOrder}
        open={viewOrder !== null}
        onOpenChange={(open) => { if (!open) setViewOrder(null); }}
        onEdit={handleEdit}
      />

      {/* ── Form Drawer (edit) ── */}
      <OrderFormDrawer
        open={editDrawerOpen}
        onOpenChange={(open) => { setEditDrawerOpen(open); if (!open) setEditOrder(null); }}
        order={editOrder}
      />

      {/* ── Form Drawer (new) ── */}
      <OrderFormDrawer
        open={newOrderOpen}
        onOpenChange={setNewOrderOpen}
      />

      {/* ── Delete Confirm ── */}
      <ConfirmDialog
        open={deletingOrder !== null}
        onOpenChange={(open) => { if (!open) setDeletingOrder(null); }}
        title={t('delete.title')}
        description={tCommon('dialogs.softDeleteMessage', { name: deletingOrder?.order_number ?? '' })}
        confirmLabel={t('delete.confirm')}
        variant="destructive"
        loading={deleteOrder.isPending}
        onConfirm={() => {
          if (deletingOrder) {
            deleteOrder.mutate(deletingOrder.id, { onSuccess: () => setDeletingOrder(null) });
          }
        }}
      />
    </div>
  );
}

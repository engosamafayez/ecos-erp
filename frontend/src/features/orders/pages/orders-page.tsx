import { useEffect, useMemo, useRef, useState } from 'react';
import { CalendarDays, Filter, Search, Users } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import { Pagination } from '@/components/crud';
import { ConfirmDialog } from '@/components/crud';
import { PageHeader } from '@/components/crud';
import { useColumnVisibility } from '@/components/data-grid/use-column-visibility';
import { useChannelOptions } from '@/features/channels/hooks/use-channel-options';
import type { AdvancedFilterValues } from '@/features/orders/components/order-advanced-filters';
import { OrderAdvancedFilters } from '@/features/orders/components/order-advanced-filters';
import { OrderCustomerIntelligence } from '@/features/orders/components/order-customer-intelligence';
import { OrderDetailDrawer } from '@/features/orders/components/order-detail-drawer';
import { OrderFormDrawer } from '@/features/orders/components/order-form-drawer';
import { OrderListToolbar } from '@/features/orders/components/order-list-toolbar';
import { OrderSmartToolbar } from '@/features/orders/components/order-smart-toolbar';
import { OrderStatusTabs } from '@/features/orders/components/order-status-tabs';
import { ORDER_COLUMN_META } from '@/features/orders/components/order-column-meta';
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
import { STATUS_TAB_ORDER } from '@/features/orders/types/order';
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

  // ── UI-005: Keyboard navigation ───────────────────────────────────────────────
  const [focusedRowIndex, setFocusedRowIndex] = useState<number | null>(null);

  // ── Column visibility — persisted in localStorage ─────────────────────────────
  const { visibility: columnVisibility, toggle: toggleColumn, reset: resetColumns } =
    useColumnVisibility('ecos-orders-cols', ORDER_COLUMN_META);

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

  // ── UI-005: stateRef avoids stale closures in the keyboard handler ────────────
  const stateRef = useRef({ orders, viewOrder, editDrawerOpen, newOrderOpen, focusedRowIndex });
  useEffect(() => {
    stateRef.current = { orders, viewOrder, editDrawerOpen, newOrderOpen, focusedRowIndex };
  }, [orders, viewOrder, editDrawerOpen, newOrderOpen, focusedRowIndex]);

  // Reset row focus when data changes (new page / filter applied)
  useEffect(() => { setFocusedRowIndex(null); }, [data]);

  useEffect(() => {
    const handler = (e: KeyboardEvent) => {
      const target = e.target as HTMLElement;
      const inInput = target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.isContentEditable;
      const { orders: ords, viewOrder: vo, editDrawerOpen: eo, newOrderOpen: no, focusedRowIndex: fi } = stateRef.current;

      if ((e.key === 'k' && (e.ctrlKey || e.metaKey)) || (e.key === '/' && !inInput)) {
        e.preventDefault(); searchRef.current?.focus(); searchRef.current?.select(); return;
      }
      if (e.key === 'Escape' && !inInput) {
        if (vo !== null) { setViewOrder(null); return; }
        if (eo || no) return;
        setSearchKey((k) => k + 1); setSearch(''); setPage(1); setSelectedIds(new Set()); setFocusedRowIndex(null); return;
      }
      if (e.key === 'ArrowDown' && !inInput && ords.length > 0) {
        e.preventDefault(); setFocusedRowIndex(fi === null ? 0 : Math.min(fi + 1, ords.length - 1)); return;
      }
      if (e.key === 'ArrowUp' && !inInput && ords.length > 0) {
        e.preventDefault(); setFocusedRowIndex(fi === null ? 0 : Math.max(fi - 1, 0)); return;
      }
      if (e.key === 'Enter' && !inInput && fi !== null) {
        e.preventDefault(); const order = ords[fi]; if (order) setViewOrder(order); return;
      }
      if (e.altKey && !e.ctrlKey && !e.metaKey && !inInput) {
        const num = parseInt(e.key, 10);
        if (num >= 1 && num <= 9 && num <= STATUS_TAB_ORDER.length) {
          e.preventDefault(); setActiveStatus(STATUS_TAB_ORDER[num - 1]); setPage(1); setSelectedIds(new Set());
        }
      }
    };
    document.addEventListener('keydown', handler);
    return () => document.removeEventListener('keydown', handler);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // ── Active filter counts ──────────────────────────────────────────────────────
  const advancedActiveCount =
    Object.values(advancedFilters).filter(Boolean).length + (channelId ? 1 : 0);
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

  function setDateFrom(val: string | null) {
    setAdvancedFilters((prev) => ({ ...prev, dateFrom: val }));
    resetPage();
  }

  function setDateTo(val: string | null) {
    setAdvancedFilters((prev) => ({ ...prev, dateTo: val }));
    resetPage();
  }

  // ── Page header subtitle — total count + active filter summary ────────────────
  const totalCount = meta?.total;
  const activeFilterCount =
    (search ? 1 : 0) +
    (channelId ? 1 : 0) +
    Object.values(advancedFilters).filter(Boolean).length +
    (customerFilter ? 1 : 0) +
    (hasLocation !== null ? 1 : 0) +
    (minShippingAttempts !== null ? 1 : 0);

  const headerSubtitle = (
    <span className="inline-flex items-center gap-2">
      {totalCount !== undefined ? (
        <span className="font-medium text-foreground">
          {totalCount.toLocaleString()} {t('title').toLowerCase()}
        </span>
      ) : (
        <span>{t('subtitle')}</span>
      )}
      {activeFilterCount > 0 ? (
        <span className="inline-flex items-center gap-1 rounded-full bg-primary/10 px-2 py-0.5 text-xs font-medium text-primary">
          <Filter className="size-3" />
          {activeFilterCount} {activeFilterCount === 1 ? 'filter' : 'filters'}
        </span>
      ) : null}
    </span>
  );

  return (
    <div className="flex h-full flex-col">
      {/* ── Page header ── */}
      <div className="border-b bg-background px-6 py-4">
        <PageHeader
          title={t('title')}
          subtitle={headerSubtitle}
          breadcrumbs={[
            { label: tCommon('home'), to: ROUTES.dashboard },
            { label: t('title') },
          ]}
        />
      </div>

      {/* ── Standard Actions Toolbar ── */}
      <OrderListToolbar
        selectedCount={selectedIds.size}
        isFetching={isFetching}
        columns={ORDER_COLUMN_META}
        columnVisibility={columnVisibility}
        onNew={() => setNewOrderOpen(true)}
        onRefresh={() => void refetch()}
        onColumnToggle={toggleColumn}
        onColumnReset={resetColumns}
      />

      {/* ── Sticky status tabs ── */}
      <div className="sticky top-0 z-20 bg-background">
        <OrderStatusTabs
          activeStatus={activeStatus}
          counts={statusCounts}
          onChange={handleStatusChange}
        />
      </div>

      {/* ── Filter bar: Search + Channel + Date range + toggles ── */}
      <div className="border-b bg-background px-4 py-2">
        <div className="flex flex-wrap items-center gap-2">
          {/* Search — DD-024 */}
          <div className="relative min-w-48 flex-1">
            <Search className="pointer-events-none absolute start-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
            <input
              key={searchKey}
              ref={searchRef}
              type="search"
              placeholder={`${t('search')} · / or Ctrl+K`}
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
            onChange={(e) => { setChannelId(e.target.value || null); resetPage(); }}
            aria-label={t('filters.channel')}
            className="h-8 rounded-md border border-input bg-background px-2 text-sm text-foreground focus:outline-none focus:ring-1 focus:ring-ring"
          >
            <option value="">{t('filters.allChannels')}</option>
            {channelOptions.map((c) => (
              <option key={c.value} value={c.value}>{c.label}</option>
            ))}
          </select>

          {/* Date range — always visible on sm+ */}
          <div className="hidden items-center gap-1 sm:flex">
            <CalendarDays className="size-3.5 shrink-0 text-muted-foreground" />
            <input
              type="date"
              value={advancedFilters.dateFrom ?? ''}
              onChange={(e) => setDateFrom(e.target.value || null)}
              aria-label={t('filters.dateFrom')}
              className="h-8 rounded-md border border-input bg-background px-2 text-sm text-foreground focus:outline-none focus:ring-1 focus:ring-ring"
            />
            <span className="text-xs text-muted-foreground">–</span>
            <input
              type="date"
              value={advancedFilters.dateTo ?? ''}
              onChange={(e) => setDateTo(e.target.value || null)}
              min={advancedFilters.dateFrom ?? undefined}
              aria-label={t('filters.dateTo')}
              className="h-8 rounded-md border border-input bg-background px-2 text-sm text-foreground focus:outline-none focus:ring-1 focus:ring-ring"
            />
            {(advancedFilters.dateFrom || advancedFilters.dateTo) ? (
              <button
                type="button"
                onClick={() => { setDateFrom(null); setDateTo(null); }}
                className="text-xs text-muted-foreground hover:text-foreground transition-colors"
                aria-label="Clear dates"
              >
                ✕
              </button>
            ) : null}
          </div>

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
          focusedRowId={focusedRowIndex !== null ? (orders[focusedRowIndex]?.id ?? null) : null}
          columnVisibility={columnVisibility}
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

import { useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Filter, Sparkles, Users } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { ConfirmDialog } from '@/components/crud';
import { PageHeader } from '@/components/crud';
import { SearchInput } from '@/components/crud';
import { useColumnVisibility } from '@/components/data-grid/use-column-visibility';
import { useRowSelection } from '@/components/data-grid/use-row-selection';
import type { GridPaginationConfig } from '@/components/data-grid/types';
import { MobileFilterSheet } from '@/components/mobile';
import { useIsMobile } from '@/hooks/use-is-mobile';
import { useChannelOptions } from '@/features/channels/hooks/use-channel-options';
import type { AdvancedFilterValues } from '@/features/orders/components/order-advanced-filters';
import { OrderAdvancedFilters } from '@/features/orders/components/order-advanced-filters';
import { OrderCustomerIntelligence } from '@/features/orders/components/order-customer-intelligence';
import { OrderDetailDrawer } from '@/features/orders/components/order-detail-drawer';
import type { BulkActionKey } from '@/features/orders/components/order-list-toolbar';
import {
  OrderListToolbar,
  IRREVERSIBLE_BULK_ACTIONS,
  BULK_ACTION_TARGET_LABEL,
} from '@/features/orders/components/order-list-toolbar';
import { OrderSmartToolbar } from '@/features/orders/components/order-smart-toolbar';
import { OrderStatusTabs } from '@/features/orders/components/order-status-tabs';
import { createOrderColumnMeta } from '@/features/orders/components/order-column-meta';
import { useOrderStatusLabels, useOrderBulkLabels } from '@/features/orders/hooks/use-order-labels';
import { OrderTable } from '@/features/orders/components/order-table';
import { OrderConfirmCustomerDialog } from '@/features/orders/components/order-confirm-customer-dialog';
import { EmptyState } from '@/components/crud';
import { toast } from '@/components/ds/use-toast';
import { extractApiErrorMessage } from '@/lib/api-error';
import { copyToClipboard } from '@/lib/clipboard';
import { PrintTable } from '@/features/operations/components/print-table';
import {
  buildOrderRows,
  orderFieldLabel,
  rowsToCsv,
  rowsToTsv,
  ORDER_FIELD_GETTERS,
  COPY_FIELD_KEYS,
  PRINT_PRIMARY_FIELD_KEYS,
  PRINT_DETAIL_FIELD_KEYS,
  EXPORT_FIELD_KEYS,
} from '@/features/orders/utils/order-export-fields';
import {
  useDeleteOrder,
  useOrderStatusKpis,
  useOrdersQuery,
  useBulkConfirm,
  useBulkCancel,
  useBulkMoveToPreparation,
  useBulkCompleteDelivery,
  useBulkComplete,
  useBulkDispatch,
  useBulkMarkAwaitingStock,
  useBulkResume,
  useBulkMoveToReview,
  useBulkReschedule,
  useBulkReturn,
  useBulkReturnToConfirmed,
  useBulkResumeToConfirmed,
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

// §5 print layout — explicit relative widths for PRINT_PRIMARY_FIELD_KEYS so the
// printed table gets predictable, readable column proportions (address/customer
// get more room than status/zone) instead of the browser's auto-layout squeeze.
// Sums to 100%; keyed by OrderFieldKey but left as Record<string, string> so this
// file doesn't need to import that type just for this one constant.
const PRINT_COLUMN_WIDTH: Record<string, string> = {
  order_number: '12%',
  customer: '16%',
  phone: '12%',
  status: '10%',
  delivery_date: '14%',
  payment_method: '12%',
  payment_status: '12%',
  grand_total: '12%',
};

type StatusFilter = OrderStatus | 'all';

const EMPTY_ADVANCED: AdvancedFilterValues = {
  productId: null,
  paymentMethod: null,
  paymentStatus: null,
  hasPaymentProof: null,
  reservationStatus: null,
  shippingCompany: null,
  dateFrom: null,
  dateTo: null,
  datePreset: null,
  governorate: null,
  city: null,
  zone: null,
  minAmount: null,
  maxAmount: null,
};

// ── Workspace state persistence ───────────────────────────────────────────────
// Saves active tab, sort, and filter state per user/browser via localStorage.

const WORKSPACE_KEY = 'ecos-orders-workspace-v1';

type PersistedWorkspace = {
  activeStatus?: StatusFilter;
  sort?: { field: OrderSortField; direction: 'asc' | 'desc' };
  channelId?: string | null;
  advancedFilters?: AdvancedFilterValues;
};

function loadWorkspace(): PersistedWorkspace {
  try {
    const raw = localStorage.getItem(WORKSPACE_KEY);
    return raw ? (JSON.parse(raw) as PersistedWorkspace) : {};
  } catch {
    return {};
  }
}

function saveWorkspace(state: PersistedWorkspace): void {
  try {
    localStorage.setItem(WORKSPACE_KEY, JSON.stringify(state));
  } catch {}
}

export function OrdersPage() {
  const { t } = useTranslation('orders');
  const { t: tCommon } = useTranslation('common');
  const navigate = useNavigate();
  const isMobile = useIsMobile();

  const { statusLabel, statusTabLabel } = useOrderStatusLabels();
  const { bulkLabel } = useOrderBulkLabels();
  const columnMeta = useMemo(() => createOrderColumnMeta(t), [t]);

  // Load once on mount — initialises state from persisted workspace
  const [savedWorkspace] = useState(loadWorkspace);

  // ── Status tab ────────────────────────────────────────────────────────────────
  const [activeStatus, setActiveStatus] = useState<StatusFilter>(savedWorkspace.activeStatus ?? 'all');

  // ── Pagination + sort ─────────────────────────────────────────────────────────
  const [page, setPage] = useState(1);
  const [sort, setSort] = useState<{ field: OrderSortField; direction: 'asc' | 'desc' }>(
    savedWorkspace.sort ?? { field: 'created_at', direction: 'desc' },
  );

  // ── Search ────────────────────────────────────────────────────────────────────
  const [searchKey, setSearchKey] = useState(0);
  const [search, setSearch] = useState('');
  const searchRef = useRef<HTMLInputElement>(null);

  // ── DD-023 Channel filter ─────────────────────────────────────────────────────
  const [channelId, setChannelId] = useState<string | null>(savedWorkspace.channelId ?? null);
  const { data: channelOptions = [] } = useChannelOptions();

  // ── DD-026 Advanced filters ───────────────────────────────────────────────────
  const [showAdvancedFilters, setShowAdvancedFilters] = useState(false);
  const [advancedFilters, setAdvancedFilters] = useState<AdvancedFilterValues>(
    savedWorkspace.advancedFilters ?? EMPTY_ADVANCED,
  );

  // ── DD-025 Customer Intelligence — multi-select array ────────────────────────
  const [showCustomerIntelligence, setShowCustomerIntelligence] = useState(false);
  const [customerFilters, setCustomerFilters] = useState<CustomerIntelligenceFilter[]>([]);

  // TASK-ECOS-MOBILE-POST-DEV-UX-REVIEW-001 §6 — the Smart Operations
  // Toolbar (DD-028/029) used to render unconditionally, directly beneath the
  // Filters/Customer Intelligence toggle row, on every viewport. On Mobile
  // that meant two chip-like rows stacked immediately on top of each other,
  // competing for the same narrow width. It's now a third toggle, beside
  // Customer Intelligence, hidden by default on Mobile; desktop is unchanged
  // (still always visible — this state is simply never read there).
  const [showSmartTools, setShowSmartTools] = useState(false);

  // ── DD-031 Toolbar ops — direct query param toggles ───────────────────────────
  const [hasLocation, setHasLocation] = useState<boolean | null>(null);
  const [minShippingAttempts, setMinShippingAttempts] = useState<number | null>(null);

  // ── Bulk reschedule dialog ────────────────────────────────────────────────────
  const [bulkRescheduleOpen, setBulkRescheduleOpen] = useState(false);
  const [bulkRescheduleDate, setBulkRescheduleDate] = useState('');
  const [pendingRescheduleIds, setPendingRescheduleIds] = useState<string[]>([]);

  // ── Bulk confirmation dialog ──────────────────────────────────────────────────
  const [pendingBulkAction, setPendingBulkAction] = useState<BulkActionKey | null>(null);

  // ── Drawer state ──────────────────────────────────────────────────────────────
  const [viewOrder, setViewOrder] = useState<Order | null>(null);
  const [deletingOrder, setDeletingOrder] = useState<Order | null>(null);
  const [confirmingOrder, setConfirmingOrder] = useState<Order | null>(null);

  // ── UI-005: Keyboard navigation ───────────────────────────────────────────────
  const [focusedRowIndex, setFocusedRowIndex] = useState<number | null>(null);

  // ── Column visibility — persisted in localStorage ─────────────────────────────
  const { visibility: columnVisibility, toggle: toggleColumn, reset: resetColumns } =
    useColumnVisibility('ecos-orders-cols', columnMeta);

  // ── Query ─────────────────────────────────────────────────────────────────────
  const params = useMemo(
    () => ({
      search: search || undefined,
      status: activeStatus === 'all' ? undefined : activeStatus,
      channel_id: channelId ?? undefined,
      product_id: advancedFilters.productId ?? undefined,
      payment_method: advancedFilters.paymentMethod ?? undefined,
      payment_status: advancedFilters.paymentStatus ?? undefined,
      has_payment_proof: advancedFilters.hasPaymentProof ?? undefined,
      // A8 — passed through as-is; the backend now filters `not_reserved` correctly
      // against the real reservation_status column (previously silently dropped here).
      reservation_status: advancedFilters.reservationStatus ?? undefined,
      shipping_company: advancedFilters.shippingCompany ?? undefined,
      date_from: advancedFilters.dateFrom ?? undefined,
      date_to: advancedFilters.dateTo ?? undefined,
      governorate: advancedFilters.governorate ?? undefined,
      city: advancedFilters.city ?? undefined,
      zone: advancedFilters.zone ?? undefined,
      min_amount: advancedFilters.minAmount ? Number(advancedFilters.minAmount) : undefined,
      max_amount: advancedFilters.maxAmount ? Number(advancedFilters.maxAmount) : undefined,
      customer_filter: customerFilters.length > 0 ? customerFilters.join(',') : undefined,
      has_location: hasLocation ?? undefined,
      min_shipping_attempts: minShippingAttempts ?? undefined,
      page,
      per_page: PER_PAGE,
      sort_by: sort.field,
      sort_dir: sort.direction,
    }),
    [search, activeStatus, channelId, advancedFilters, customerFilters, hasLocation, minShippingAttempts, page, sort],
  );

  const { data, isLoading, isError, isFetching, refetch } = useOrdersQuery(params);

  // ── KPI params: same filters as the main query but without status/pagination/sort ──
  const kpiParams = useMemo(
    () => ({
      search: search || undefined,
      channel_id: channelId ?? undefined,
      product_id: advancedFilters.productId ?? undefined,
      payment_method: advancedFilters.paymentMethod ?? undefined,
      payment_status: advancedFilters.paymentStatus ?? undefined,
      has_payment_proof: advancedFilters.hasPaymentProof ?? undefined,
      // A8 — passed through as-is; the backend now filters `not_reserved` correctly
      // against the real reservation_status column (previously silently dropped here).
      reservation_status: advancedFilters.reservationStatus ?? undefined,
      shipping_company: advancedFilters.shippingCompany ?? undefined,
      date_from: advancedFilters.dateFrom ?? undefined,
      date_to: advancedFilters.dateTo ?? undefined,
      governorate: advancedFilters.governorate ?? undefined,
      city: advancedFilters.city ?? undefined,
      zone: advancedFilters.zone ?? undefined,
      min_amount: advancedFilters.minAmount ? Number(advancedFilters.minAmount) : undefined,
      max_amount: advancedFilters.maxAmount ? Number(advancedFilters.maxAmount) : undefined,
      customer_filter: customerFilters.length > 0 ? customerFilters.join(',') : undefined,
      has_location: hasLocation ?? undefined,
      min_shipping_attempts: minShippingAttempts ?? undefined,
    }),
    [search, channelId, advancedFilters, customerFilters, hasLocation, minShippingAttempts],
  );

  const statusKpis = useOrderStatusKpis(kpiParams);
  const deleteOrder = useDeleteOrder();

  // ── Workspace persistence: save tab + sort + channel + filters on change ──────
  useEffect(() => {
    saveWorkspace({ activeStatus, sort, channelId, advancedFilters });
  }, [activeStatus, sort, channelId, advancedFilters]);

  // ── Bulk workflow hooks ───────────────────────────────────────────────────────
  const bulkConfirm          = useBulkConfirm();
  const bulkCancel           = useBulkCancel();
  const bulkMoveToPrep       = useBulkMoveToPreparation();
  const bulkCompleteDelivery = useBulkCompleteDelivery();
  const bulkComplete         = useBulkComplete();
  const bulkDispatch         = useBulkDispatch();
  const bulkAwaitingStock    = useBulkMarkAwaitingStock();
  const bulkResume           = useBulkResume();
  const bulkReview           = useBulkMoveToReview();
  const bulkReschedule          = useBulkReschedule();
  const bulkReturn              = useBulkReturn();
  const bulkReturnToConfirmed   = useBulkReturnToConfirmed();
  const bulkResumeToConfirmed   = useBulkResumeToConfirmed();

  // useMemo, not a bare `?? []`: the fallback minted a new array on every render
  // while the page was loading, re-running every dependent effect and memo.
  const orders = useMemo(() => data?.items ?? [], [data]);

  // ── Row selection ─────────────────────────────────────────────────────────────
  const selectionHook = useRowSelection({ items: orders, getId: (o) => o.id });
  const { selectedIds, selectedCount, clearSelection } = selectionHook;

  // Part 1: selectedOrders drives the dynamic bulk action computation in the toolbar
  const selectedOrders = useMemo(
    () => orders.filter((o) => selectedIds.has(o.id)),
    [orders, selectedIds],
  );

  const meta = data?.meta;

  // ── UI-005: stateRef avoids stale closures in the keyboard handler ────────────
  const stateRef = useRef({ orders, viewOrder, focusedRowIndex });
  useEffect(() => {
    stateRef.current = { orders, viewOrder, focusedRowIndex };
  }, [orders, viewOrder, focusedRowIndex]);

  // Reset row focus when data changes (new page / filter applied)
  useEffect(() => { setFocusedRowIndex(null); }, [data]);

  useEffect(() => {
    const handler = (e: KeyboardEvent) => {
      const target = e.target as HTMLElement;
      const inInput = target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.isContentEditable;
      const { orders: ords, viewOrder: vo, focusedRowIndex: fi } = stateRef.current;

      if ((e.key === 'k' && (e.ctrlKey || e.metaKey)) || (e.key === '/' && !inInput)) {
        e.preventDefault(); searchRef.current?.focus(); searchRef.current?.select(); return;
      }
      if (e.key === 'Escape' && !inInput) {
        if (vo !== null) { setViewOrder(null); return; }
        setSearchKey((k) => k + 1); setSearch(''); setPage(1); clearSelection(); setFocusedRowIndex(null); return;
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
          e.preventDefault(); setActiveStatus(STATUS_TAB_ORDER[num - 1]); setPage(1); clearSelection();
        }
      }
    };
    document.addEventListener('keydown', handler);
    return () => document.removeEventListener('keydown', handler);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // ── Active filter counts ──────────────────────────────────────────────────────
  const dateFilterActive = !!(advancedFilters.dateFrom || advancedFilters.dateTo);
  const advancedNonDateCount =
    (advancedFilters.productId ? 1 : 0) +
    (advancedFilters.paymentMethod ? 1 : 0) +
    (advancedFilters.paymentStatus ? 1 : 0) +
    (advancedFilters.hasPaymentProof !== null ? 1 : 0) +
    (advancedFilters.reservationStatus ? 1 : 0) +
    (advancedFilters.shippingCompany ? 1 : 0) +
    (advancedFilters.zone ? 1 : 0) +
    (advancedFilters.minAmount ? 1 : 0) +
    (advancedFilters.maxAmount ? 1 : 0);
  const advancedActiveCount = advancedNonDateCount + (dateFilterActive ? 1 : 0) + (channelId ? 1 : 0);

  // ── Reset helpers ─────────────────────────────────────────────────────────────
  function resetPage() { setPage(1); clearSelection(); }

  function handleStatusChange(status: StatusFilter) {
    setActiveStatus(status);
    resetPage();
  }

  function handleSortChange(field: string) {
    const sortField = field as OrderSortField;
    setSort((curr) =>
      curr.field === sortField
        ? { field: sortField, direction: curr.direction === 'asc' ? 'desc' : 'asc' }
        : { field: sortField, direction: 'asc' },
    );
    // Every other filter-changing handler clears selection (resetPage()); this
    // one didn't, so a selection made before a sort silently pointed at rows
    // that may no longer occupy the same page/position after re-sorting.
    setPage(1);
    clearSelection();
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

  function handleEdit(order: Order) {
    navigate(`${ROUTES.orders}/${order.id}/edit`);
  }

  // \u2500\u2500 Copy / Print / Export selection contract \u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500
  // Defects A/B/C (user review): all three previously ignored selection and
  // re-derived their own incomplete 9-field list from `orders` (whichever page
  // happened to be loaded). All three now operate ONLY on the selected Order
  // IDs when a selection exists \u2014 selectedOrders is already ID-derived (not
  // row-position-derived, see useRowSelection) \u2014 and explicitly fall back to
  // the current view (today's implicit behavior, now consistent and toasted)
  // when nothing is selected.
  const copyPrintExportOrders = selectedCount > 0 ? selectedOrders : orders;

  function handleExport() {
    const rows = buildOrderRows(copyPrintExportOrders, EXPORT_FIELD_KEYS);
    const headers = EXPORT_FIELD_KEYS.map((k) => orderFieldLabel(k, t));
    const csv = rowsToCsv(headers, rows);
    const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `orders-${new Date().toISOString().slice(0, 10)}.csv`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
    toast.success(t($ => $.actions.exportSuccess, { count: copyPrintExportOrders.length }));
  }

  function handleCopyToClipboard() {
    const rows = buildOrderRows(copyPrintExportOrders, COPY_FIELD_KEYS);
    const headers = COPY_FIELD_KEYS.map((k) => orderFieldLabel(k, t));
    // TSV, not CSV: pastes as real columns into Excel/Sheets, unlike quoted CSV
    // (which pastes as one text blob), while still reading fine as plain text.
    // Goes through the shared copyToClipboard helper (not navigator.clipboard
    // directly) — on a non-secure DEV origin `navigator.clipboard` is `undefined`,
    // so calling `.writeText` on it throws synchronously instead of settling a
    // rejected promise, which broke Copy for 1 or N selected rows with no toast
    // at all. copyToClipboard carries the execCommand('copy') fallback already
    // proven for this exact case (see lib/clipboard.ts).
    void copyToClipboard(rowsToTsv(headers, rows)).then((ok) => {
      if (ok) {
        toast.success(t($ => $.actions.copySuccess, { count: copyPrintExportOrders.length }));
      } else {
        toast.error(t($ => $.actions.copyError));
      }
    });
  }

  // A dedicated <PrintTable> (rendered below, `hidden` until printed) replaces
  // the old popup-window/HTML-string approach \u2014 same working pattern already
  // used by Operations (see print-table.tsx's own rationale: UniversalDataGrid's
  // `lg:` breakpoints don't resolve under print media, so printing the grid
  // directly produced a header with no rows).
  function handlePrint() {
    window.print();
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

  // Multi-select handler for the chip panel
  function handleCustomerFiltersChange(next: CustomerIntelligenceFilter[]) {
    setCustomerFilters(next);
    resetPage();
  }

  // Single-value handler for SmartToolbar ops (replaces current selection)
  function handleSmartToolbarCustomerFilter(f: CustomerIntelligenceFilter | null) {
    setCustomerFilters(f ? [f] : []);
    resetPage();
  }

  // ── Bulk action dispatch ──────────────────────────────────────────────────────
  // Step 1: Capture intent — open confirmation dialog (or date picker for reschedule).
  function handleBulkAction(action: BulkActionKey) {
    const ids = Array.from(selectedIds);
    if (ids.length === 0) return;
    if (action === 'reschedule') {
      setPendingRescheduleIds(ids);
      setBulkRescheduleDate('');
      setBulkRescheduleOpen(true);
      return;
    }
    setPendingBulkAction(action);
  }

  // Step 2: Execute after user confirms in the dialog.
  // A10 — every path gets the same success/failure toast; none were silent before.
  function executeBulkAction() {
    const action = pendingBulkAction;
    if (!action) return;
    const ids = Array.from(selectedIds);
    if (ids.length === 0) return;

    const feedback = {
      onSuccess: () => toast.success(t($ => $.bulk.toastSuccess, { count: ids.length })),
      onError: (err: unknown) => toast.error(t($ => $.bulk.toastError), extractApiErrorMessage(err)),
    };

    switch (action) {
      case 'confirm':
      case 'verify_payment':           bulkConfirm.mutate(ids, feedback); break;
      case 'cancel':                   bulkCancel.mutate({ ids }, feedback); break;
      case 'move_to_preparation':
      case 'return_to_preparation':    bulkMoveToPrep.mutate(ids, feedback); break;
      case 'complete_delivery':        bulkCompleteDelivery.mutate(ids, feedback); break;
      case 'complete':                 bulkComplete.mutate(ids, feedback); break;
      case 'dispatch':                 bulkDispatch.mutate(ids, feedback); break;
      case 'awaiting_stock':           bulkAwaitingStock.mutate({ ids }, feedback); break;
      case 'resume':
      case 'retry_reservation':        bulkResume.mutate(ids, feedback); break;
      case 'resume_confirmed':         bulkResumeToConfirmed.mutate(ids, feedback); break;
      case 'review':                   // on_hold action
      case 'delivery_failed':          bulkReview.mutate({ ids }, feedback); break;
      case 'return':                   bulkReturn.mutate({ ids }, feedback); break;
      case 'return_to_confirmed':
      case 'return_to_stock':          bulkReturnToConfirmed.mutate(ids, feedback); break;
      default: break;
    }
    clearSelection();
    setPendingBulkAction(null);
  }

  function confirmBulkReschedule() {
    if (!bulkRescheduleDate) return;
    const count = pendingRescheduleIds.length;
    bulkReschedule.mutate(
      { ids: pendingRescheduleIds, date: bulkRescheduleDate },
      {
        onSuccess: () => toast.success(t($ => $.bulk.toastSuccess, { count })),
        onError: (err) => toast.error(t($ => $.bulk.toastError), extractApiErrorMessage(err)),
      },
    );
    setBulkRescheduleOpen(false);
    clearSelection();
  }

  // ── Page header subtitle — total count + active filter summary ────────────────
  const totalCount = meta?.total;
  const activeFilterCount =
    (search ? 1 : 0) +
    (channelId ? 1 : 0) +
    advancedNonDateCount +
    (dateFilterActive ? 1 : 0) +
    (customerFilters.length > 0 ? 1 : 0) +
    (hasLocation !== null ? 1 : 0) +
    (minShippingAttempts !== null ? 1 : 0);

  const headerSubtitle = (
    <span className="inline-flex items-center gap-2">
      {totalCount !== undefined ? (
        <span className="font-medium text-foreground">
          {totalCount.toLocaleString()} {t($ => $.title).toLowerCase()}
        </span>
      ) : (
        <span>{t($ => $.subtitle)}</span>
      )}
      {activeFilterCount > 0 ? (
        <span className="inline-flex items-center gap-1 rounded-full bg-primary/10 px-2 py-0.5 text-xs font-medium text-primary">
          <Filter className="size-3" />
          {activeFilterCount} {activeFilterCount === 1 ? t($ => $.filters.filterSingular) : t($ => $.filters.filterPlural)}
        </span>
      ) : null}
    </span>
  );

  // §5 print column set — computed here (not inlined in JSX) so it's built once
  // per render rather than once per print, and stays visibly next to the field
  // list it's derived from. Only the compact PRIMARY fields become real table
  // columns; the rest of PRINT_FIELD_KEYS renders per-row via renderPrintDetail
  // below instead of squeezing 17 columns into one page.
  const printColumns = PRINT_PRIMARY_FIELD_KEYS.map((key) => ({
    header: orderFieldLabel(key, t),
    cell: (o: Order) => ORDER_FIELD_GETTERS[key](o),
    width: PRINT_COLUMN_WIDTH[key],
  }));

  // Full-width line rendered under each printed row — address/items/notes read
  // better wrapped across the whole page than squeezed into their own narrow
  // column. Blank fields (e.g. no GPS captured) are simply omitted.
  function renderPrintDetail(o: Order) {
    return (
      <div className="flex flex-wrap gap-x-4 gap-y-0.5">
        {PRINT_DETAIL_FIELD_KEYS.map((key) => {
          const value = ORDER_FIELD_GETTERS[key](o);
          if (!value) return null;
          return (
            <span key={key}>
              <span className="font-semibold">{orderFieldLabel(key, t)}: </span>
              {value}
            </span>
          );
        })}
      </div>
    );
  }

  return (
    <>
    <div className="flex h-full flex-col print:hidden">
      {/* ── Page header ── */}
      <div className="border-b bg-background px-6 py-4">
        <PageHeader
          title={t($ => $.title)}
          subtitle={headerSubtitle}
          breadcrumbs={[
            { label: tCommon($ => $.home), to: ROUTES.dashboard },
            { label: t($ => $.title) },
          ]}
        />
      </div>

      {/* ── Standard Actions Toolbar ── */}
      <OrderListToolbar
        selectedOrders={selectedOrders}
        selectedCount={selectedCount}
        isFetching={isFetching}
        columns={columnMeta}
        columnVisibility={columnVisibility}
        onNew={() => navigate(ROUTES.ordersNew)}
        onBulkAction={handleBulkAction}
        onRefresh={() => void refetch()}
        onColumnToggle={toggleColumn}
        onColumnReset={resetColumns}
        onExport={handleExport}
        onCopyToClipboard={handleCopyToClipboard}
        onPrint={handlePrint}
      />

      {/* ── KPI Cards (replace status tabs) ── */}
      <div className="sticky top-0 z-20 bg-background">
        <OrderStatusTabs
          activeStatus={activeStatus}
          counts={statusKpis}
          onChange={handleStatusChange}
        />
      </div>

      {/* ── Filter bar: Search + Channel + Date range + toggles ── */}
      <div className="border-b bg-background px-4 py-2">
        <div className="flex flex-wrap items-center gap-2">
          {/* Search — DD-024. A9: live, debounced (shared SearchInput), server-side authority unchanged. */}
          <div
            className="relative min-w-48 flex-1"
            onKeyDown={(e) => { if (e.key === 'Escape') clearSearch(); }}
          >
            <SearchInput
              key={searchKey}
              ref={searchRef}
              initialValue={search}
              placeholder={`${t($ => $.search)} · / or Ctrl+K`}
              onChange={handleSearchCommit}
              className="max-w-none"
            />
          </div>

          {/* DD-023 — Channel filter */}
          <select
            value={channelId ?? ''}
            onChange={(e) => { setChannelId(e.target.value || null); resetPage(); }}
            aria-label={t($ => $.filters.channel)}
            className="h-8 rounded-md border border-input bg-background px-2 text-sm text-foreground focus:outline-none focus:ring-1 focus:ring-ring"
          >
            <option value="">{t($ => $.filters.allChannels)}</option>
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
            aria-label={t($ => $.filters.advanced)}
          >
            <Filter className="size-3.5" />
            {t($ => $.filters.advanced)}
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
            aria-label={t($ => $.customerIntelligence.title)}
          >
            <Users className="size-3.5" />
            {t($ => $.customerIntelligence.title)}
            {customerFilters.length > 0 ? (
              <span className={cn(
                'inline-flex h-4 min-w-4 items-center justify-center rounded-full px-1 text-[10px] font-semibold',
                showCustomerIntelligence ? 'bg-foreground/20 text-foreground' : 'bg-primary/15 text-primary',
              )}>
                {customerFilters.length}
              </span>
            ) : null}
          </Button>

          {/* Smart Tools toggle — Mobile only (§6); on desktop the Smart
              Operations Toolbar below is always visible and this button
              simply never renders. */}
          {isMobile ? (
            <Button
              type="button"
              variant={showSmartTools ? 'secondary' : 'outline'}
              size="sm"
              onClick={() => setShowSmartTools((v) => !v)}
              aria-expanded={showSmartTools}
              aria-label={t($ => $.smartToolbar.toggleLabel)}
            >
              <Sparkles className="size-3.5" />
              {t($ => $.smartToolbar.toggleLabel)}
            </Button>
          ) : null}
        </div>
      </div>

      {/* ── DD-026 Advanced Filters panel — inline on desktop; on mobile the
          same chips used to wrap and collide against the viewport edge, so
          they open in a dismissible bottom sheet instead (§11). Filtering
          capability and the underlying state are unchanged either way — only
          where the controls render differs. ── */}
      {isMobile ? (
        <MobileFilterSheet
          open={showAdvancedFilters}
          onOpenChange={setShowAdvancedFilters}
          title={t($ => $.filters.advanced)}
          activeCount={advancedActiveCount}
          onClear={clearAdvancedFilters}
        >
          <OrderAdvancedFilters
            values={advancedFilters}
            onChange={handleAdvancedFiltersChange}
            onClear={clearAdvancedFilters}
          />
        </MobileFilterSheet>
      ) : showAdvancedFilters ? (
        <OrderAdvancedFilters
          values={advancedFilters}
          onChange={handleAdvancedFiltersChange}
          onClear={clearAdvancedFilters}
        />
      ) : null}

      {/* ── DD-025 Customer Intelligence panel — same mobile-sheet treatment,
          positioned as its own dedicated control alongside Filters rather
          than an inline chip row (§11). ── */}
      {isMobile ? (
        <MobileFilterSheet
          open={showCustomerIntelligence}
          onOpenChange={setShowCustomerIntelligence}
          title={t($ => $.customerIntelligence.title)}
          activeCount={customerFilters.length}
          onClear={() => handleCustomerFiltersChange([])}
        >
          <OrderCustomerIntelligence
            value={customerFilters}
            onChange={handleCustomerFiltersChange}
          />
        </MobileFilterSheet>
      ) : showCustomerIntelligence ? (
        <OrderCustomerIntelligence
          value={customerFilters}
          onChange={handleCustomerFiltersChange}
        />
      ) : null}

      {/* ── DD-028 / DD-029 Smart Operations Toolbar — always visible on
          desktop (unchanged); on Mobile it shows/hides behind the "Smart
          Tools" toggle above instead of always occupying its own row
          directly under the Filters/Customer Intelligence buttons (§6). ── */}
      {!isMobile || showSmartTools ? (
        <OrderSmartToolbar
          activeStatus={activeStatus}
          selectedIds={selectedIds}
          orders={orders}
          advancedFilters={advancedFilters}
          setAdvancedFilters={(next) => { setAdvancedFilters(next); resetPage(); }}
          setCustomerFilter={handleSmartToolbarCustomerFilter}
          setActiveStatus={handleStatusChange}
          showAdvancedFilters={showAdvancedFilters}
          setShowAdvancedFilters={setShowAdvancedFilters}
          showCustomerIntelligence={showCustomerIntelligence}
          setShowCustomerIntelligence={setShowCustomerIntelligence}
          setHasLocation={(v) => { setHasLocation(v); resetPage(); }}
          setMinShippingAttempts={(v) => { setMinShippingAttempts(v); resetPage(); }}
        />
      ) : null}

      {/* ── Table ── */}
      <div className="flex-1 overflow-auto px-4 py-3">
        {/* Contextual empty state — derives message from active filters */}
        {(() => {
          const hasFilters = search || activeStatus !== 'all' || channelId || customerFilters.length > 0
            || advancedFilters.productId || advancedFilters.paymentMethod || advancedFilters.paymentStatus
            || advancedFilters.hasPaymentProof !== null || advancedFilters.reservationStatus
            || advancedFilters.shippingCompany || advancedFilters.zone
            || advancedFilters.minAmount || advancedFilters.maxAmount
            || advancedFilters.dateFrom || advancedFilters.dateTo;
          const contextualEmptyState = search
            ? <EmptyState title={t($ => $.table.empty)} description={`No orders matching "${search}". Try a different search term.`} />
            : activeStatus !== 'all'
              ? <EmptyState title={t($ => $.table.empty)} description={`No orders with status "${statusTabLabel[activeStatus as OrderStatus]}".`} />
              : hasFilters
                ? <EmptyState title={t($ => $.table.empty)} description="No orders match the current filters. Try clearing some filters." />
                : undefined;
          return (
            <OrderTable
              orders={orders}
              isLoading={isLoading}
              isError={isError}
              sort={sort}
              onSortChange={handleSortChange}
              selection={selectionHook}
              onView={(order) => setViewOrder(order)}
              onEdit={handleEdit}
              onDelete={(order) => setDeletingOrder(order)}
              onStatusUpdated={() => void refetch()}
              onConfirmCustomer={(order) => setConfirmingOrder(order)}
              onTimeline={(order) => setViewOrder(order)}
              onVerifyPayment={(order) => setViewOrder(order)}
              onPrint={(order) => { void navigate(`${ROUTES.orders}/${order.id}`); }}
              focusedRowId={focusedRowIndex !== null ? (orders[focusedRowIndex]?.id ?? null) : null}
              columnVisibility={columnVisibility}
              emptyState={contextualEmptyState}
              pagination={meta ? ({
                meta: {
                  page: meta.current_page,
                  perPage: meta.per_page,
                  total: meta.total,
                  lastPage: meta.last_page,
                },
                onPageChange: (p: number) => { setPage(p); clearSelection(); },
              } satisfies GridPaginationConfig) : undefined}
            />
          );
        })()}
      </div>

      {/* ── Detail Drawer ── */}
      <OrderDetailDrawer
        order={viewOrder}
        open={viewOrder !== null}
        onOpenChange={(open) => { if (!open) setViewOrder(null); }}
        onEdit={handleEdit}
      />

      {/* ── Delete Confirm ── */}
      <ConfirmDialog
        open={deletingOrder !== null}
        onOpenChange={(open) => { if (!open) setDeletingOrder(null); }}
        title={t($ => $.delete.title)}
        description={tCommon($ => $.dialogs.softDeleteMessage, { name: deletingOrder?.order_number ?? '' })}
        confirmLabel={t($ => $.delete.confirm)}
        variant="destructive"
        loading={deleteOrder.isPending}
        onConfirm={() => {
          if (deletingOrder) {
            deleteOrder.mutate(deletingOrder.id, { onSuccess: () => setDeletingOrder(null) });
          }
        }}
      />

      {/* ── Customer Confirmation Dialog ── */}
      <OrderConfirmCustomerDialog
        order={confirmingOrder}
        open={confirmingOrder !== null}
        onOpenChange={(open) => { if (!open) setConfirmingOrder(null); }}
      />

      {/* ── Bulk Action Confirmation Dialog ── */}
      {(() => {
        const action = pendingBulkAction;
        if (!action) return null;
        const count = selectedOrders.length;
        const isIrreversible = IRREVERSIBLE_BULK_ACTIONS.has(action);
        const targetLabel = BULK_ACTION_TARGET_LABEL[action] ?? action;
        const statusDist = selectedOrders.reduce<Record<string, number>>((acc, o) => {
          acc[o.status] = (acc[o.status] ?? 0) + 1;
          return acc;
        }, {});
        return (
          <Dialog open onOpenChange={(open) => { if (!open) setPendingBulkAction(null); }}>
            <DialogContent className="sm:max-w-md">
              <DialogHeader>
                <DialogTitle>{bulkLabel[action]}</DialogTitle>
              </DialogHeader>
              <div className="space-y-3 py-1 text-sm">
                <p className="text-foreground">
                  You are about to perform this action on{' '}
                  <span className="font-semibold">{count} {count === 1 ? 'order' : 'orders'}</span>.
                </p>
                <div>
                  <p className="mb-1 text-xs font-medium uppercase tracking-wide text-muted-foreground">
                    Selected orders
                  </p>
                  <div className="flex flex-wrap gap-1.5">
                    {Object.entries(statusDist).map(([status, n]) => (
                      <span key={status} className="inline-flex items-center rounded-full bg-muted px-2 py-0.5 text-xs font-medium">
                        {n} × {statusLabel[status as OrderStatus]}
                      </span>
                    ))}
                  </div>
                </div>
                <div>
                  <p className="mb-1 text-xs font-medium uppercase tracking-wide text-muted-foreground">
                    Target state
                  </p>
                  <span className="inline-flex items-center rounded-full bg-primary/10 px-2 py-0.5 text-xs font-semibold text-primary">
                    → {targetLabel}
                  </span>
                </div>
                {isIrreversible ? (
                  <div className="rounded-md border border-destructive/30 bg-destructive/5 px-3 py-2 text-xs text-destructive">
                    This action cannot be undone.
                  </div>
                ) : null}
              </div>
              <DialogFooter>
                <Button variant="outline" size="sm" onClick={() => setPendingBulkAction(null)}>
                  {tCommon($ => $.common.cancel)}
                </Button>
                <Button
                  size="sm"
                  variant={isIrreversible ? 'destructive' : 'default'}
                  onClick={executeBulkAction}
                >
                  Confirm
                </Button>
              </DialogFooter>
            </DialogContent>
          </Dialog>
        );
      })()}

      {/* ── Bulk Reschedule Date Dialog ── */}
      <Dialog open={bulkRescheduleOpen} onOpenChange={setBulkRescheduleOpen}>
        <DialogContent className="sm:max-w-sm">
          <DialogHeader>
            <DialogTitle>{t($ => $.bulk.rescheduleTitle, { count: pendingRescheduleIds.length })}</DialogTitle>
          </DialogHeader>
          <div className="py-2">
            <label className="mb-1 block text-sm font-medium text-foreground">
              {t($ => $.bulk.rescheduleDate)}
            </label>
            <input
              type="date"
              value={bulkRescheduleDate}
              min={new Date().toISOString().slice(0, 10)}
              onChange={(e) => setBulkRescheduleDate(e.target.value)}
              className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-ring"
            />
          </div>
          <DialogFooter>
            <Button variant="outline" size="sm" onClick={() => setBulkRescheduleOpen(false)}>
              {tCommon($ => $.common.cancel)}
            </Button>
            <Button
              size="sm"
              disabled={!bulkRescheduleDate || bulkReschedule.isPending}
              onClick={confirmBulkReschedule}
            >
              {t($ => $.bulk.rescheduleConfirm)}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>

    {/* Print output (§5) — hidden on screen, shown only under print media;
        independent of the Column Manager, so hiding a column on screen never
        removes it from the printout. Same selection-or-current-view scope as
        Copy/Export. */}
    <PrintTable
      title={t($ => $.title)}
      subtitle={selectedCount > 0 ? t($ => $.actions.printSelected, { count: selectedCount }) : undefined}
      columns={printColumns}
      rows={copyPrintExportOrders}
      rowKey={(o) => o.id}
      renderDetail={renderPrintDetail}
      className="print-orders-table"
    />
    </>
  );
}

import {
  Ban,
  Copy,
  Download,
  FileText,
  MapPin,
  Pencil,
  Plus,
  Printer,
  Repeat,
  ShieldCheck,
  SlidersHorizontal,
  TrendingUp,
  Trash2,
  Users,
} from 'lucide-react';
import { useEffect, useRef, useState, useMemo} from 'react';
import { useNavigate } from 'react-router-dom';

import { PhoneCell } from '@/components/ecos/phone-cell';
import { toast } from '@/components/ds/use-toast';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { copyToClipboard } from '@/lib/clipboard';
import { useTranslation } from 'react-i18next';

import {
  ActionMenu,
  ConfirmDialog,
  EmptyState,
  ErrorState,
  PageHeader,
  Pagination,
} from '@/components/crud';
import { Combobox } from '@/components/crud/combobox';
import { QuickStatCard } from '@/components/ds/quick-stat-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { useIsMobile } from '@/hooks/use-is-mobile';
import { useBrandOptions } from '@/features/brands/hooks/use-brand-options';
import { useChannelOptions } from '@/features/channels/hooks/use-channel-options';
import { CustomerDrawer } from '@/features/customers/components/customer-drawer';
import { CustomerFormDrawer } from '@/features/customers/components/customer-form-drawer';
import { CustomerQuickActionCard } from '@/features/customers/components/customer-quick-action-card';
import { customersService } from '@/features/customers/services/customers-service';
import {
  useBlockCustomer,
  useBlockPhone,
  useCustomersQuery,
  useDeleteCustomer,
  useSalesOwnerOptions,
  useUnblockCustomer,
} from '@/features/customers/hooks/use-customers';
import { useProductOptions } from '@/features/orders/hooks/use-product-options';
import { usePermission } from '@/features/authorization/use-authorization';
import { useOrganizationContext } from '@/features/organization/context/organization-context';
import { REPEAT_ORDER_THRESHOLD } from '@/features/customers/types/customer';
import type {
  Customer,
  CustomersQuery,
  CustomerSortField,
  CustomerStatusFilter,
  OrderActivityFilter,
} from '@/features/customers/types/customer';
import { ROUTES } from '@/router/routes';
import { cn } from '@/lib/utils';

const PER_PAGE = 20;

// ── Stat queries ──────────────────────────────────────────────────────────────

function useCustomerCounts() {
  const total    = useCustomersQuery({ per_page: 1 });
  const active   = useCustomersQuery({ per_page: 1, status: 'active' });
  const inactive = useCustomersQuery({ per_page: 1, status: 'inactive' });
  return {
    total:    total.data?.meta.total,
    active:   active.data?.meta.total,
    inactive: inactive.data?.meta.total,
  };
}

// ── Sort header ───────────────────────────────────────────────────────────────

function SortTh({
  field,
  label,
  sort,
  onSort,
  align = 'start',
}: {
  field: CustomerSortField;
  label: string;
  sort: { field: CustomerSortField; direction: 'asc' | 'desc' };
  onSort: (f: CustomerSortField) => void;
  align?: 'start' | 'end';
}) {
  const isActive = sort.field === field;
  return (
    <th className={cn('px-4 py-3', align === 'end' ? 'text-end' : 'text-start')}>
      <button
        type="button"
        onClick={() => onSort(field)}
        className={cn(
          'inline-flex items-center gap-1 text-xs font-medium text-muted-foreground hover:text-foreground transition-colors',
          align === 'end' && 'flex-row-reverse',
        )}
      >
        {label}
        <span className="text-[10px]">
          {isActive ? (sort.direction === 'asc' ? '↑' : '↓') : '↕'}
        </span>
      </button>
    </th>
  );
}

// ── Row skeleton ──────────────────────────────────────────────────────────────
// Kept in sync with the table's real <th> count (checkbox, customer, phones,
// brands, sales owner, channels, orders count, total value, receiving rate,
// last order, address, top products, intelligence, actions) so loading/error/
// empty states span the actual header width instead of drifting whenever a
// column is added.
const CUSTOMER_TABLE_COLUMNS = 14;

function CustomerRowSkeleton() {
  return (
    <tr className="border-b">
      {Array.from({ length: CUSTOMER_TABLE_COLUMNS }).map((_, i) => (
        <td key={i} className="px-4 py-3">
          <Skeleton className="h-4 w-full" />
        </td>
      ))}
    </tr>
  );
}

// Compact chip list for a table cell — shows up to 2 chips inline plus a "+N" badge for the
// rest, same Badge styling already used by the Intelligence column in this table.
const CHIP_LIST_VISIBLE = 2;

function ChipList({ items }: { items: { key: string; label: string }[] }) {
  const { t } = useTranslation('customers');

  if (items.length === 0) {
    return <span className="text-xs text-muted-foreground">—</span>;
  }

  const visible = items.slice(0, CHIP_LIST_VISIBLE);
  const overflow = items.length - visible.length;

  return (
    <div className="flex flex-wrap gap-1">
      {visible.map((item) => (
        <Badge key={item.key} variant="secondary" className="h-5 px-1.5 text-[10px]">
          {item.label}
        </Badge>
      ))}
      {overflow > 0 ? (
        <Badge variant="secondary" className="h-5 px-1.5 text-[10px]">
          {t($ => $.phone.more, { count: overflow })}
        </Badge>
      ) : null}
    </div>
  );
}

// ── Main page ─────────────────────────────────────────────────────────────────

/** Presentation only — the figure is computed server-side and never re-derived here. */
function fmtMoney(n: number | null | undefined) {
  return typeof n === 'number'
    ? n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
    : '—';
}

export function CustomersPage() {
  const { t } = useTranslation('customers');
  const { t: tCommon } = useTranslation('common');
  const navigate = useNavigate();
  const searchRef = useRef<HTMLInputElement>(null);
  // TASK-...-FINAL-CLOSURE-011 (§9/§11) — reconciled from develop's already-merged
  // Mobile lane (customers-page.tsx, "feat(mobile): complete customers products and
  // orders mobile UX"). Same hook, same below-`md` card-list treatment; only the
  // Blocked-Customer wiring (§12) is new on top of it.
  const isMobile = useIsMobile();

  // ── State ──────────────────────────────────────────────────────────────────
  const [search, setSearch]               = useState('');
  const [debouncedSearch, setDebounced]   = useState('');
  const [statusFilter, setStatusFilter]   = useState<CustomerStatusFilter>('all');
  const [sort, setSort]                   = useState<{ field: CustomerSortField; direction: 'asc' | 'desc' }>({
    field: 'created_at',
    direction: 'desc',
  });
  const [page, setPage]                   = useState(1);
  const [focusedRowIndex, setFocusedRowIndex] = useState<number | null>(null);
  const [selectedIds, setSelectedIds]     = useState<Set<string>>(new Set());

  // ── Customer Intelligence filters (backend-authoritative — never a client-side
  //    filter of the current page) ──────────────────────────────────────────────
  const [repeatOnly, setRepeatOnly]           = useState(false);
  const [affinityProductId, setAffinityProductId] = useState<string | null>(null);
  const [minPurchaseCount, setMinPurchaseCount]   = useState(REPEAT_ORDER_THRESHOLD);
  const { data: productOptions = [], isLoading: loadingProducts } = useProductOptions();

  // TASK-...-FINAL-UI-CLOSURE-014-R1 (§2/§4) — Top Spenders: a real backend-
  // authoritative population SEGMENT, deliberately a SEPARATE control from the
  // existing "Highest Spend" SORT toggle below (isHighestSpendSort) — the two are
  // different UI concepts and neither is touched by the other's state.
  const [topSpenders, setTopSpenders] = useState(false);

  // ── Blocked Customers filter/segment (TASK-...-BLOCKED-CUSTOMERS-009 §40,
  //    extended to a true All/Blocked/Not Blocked classification by TASK-...-
  //    FINAL-UI-CLOSURE-014 §17 via a second, mutually-exclusive toggle rather
  //    than restructuring the original button — its click→true/click-again→
  //    undefined contract is preserved exactly). ──────────────────────────────
  const [blockedOnly, setBlockedOnly] = useState(false);
  const [notBlockedOnly, setNotBlockedOnly] = useState(false);

  // ── Additional classification filters (TASK-...-FINAL-UI-CLOSURE-014 §13-19) ──
  const { activeCompanyId } = useOrganizationContext();
  const [brandId, setBrandId] = useState<string | null>(null);
  const [salesOwnerFilter, setSalesOwnerFilter] = useState<string | null>(null);
  const [channelFilterId, setChannelFilterId] = useState<string | null>(null);
  const [orderActivity, setOrderActivity] = useState<OrderActivityFilter | null>(null);
  const { data: brandOptions = [] } = useBrandOptions(activeCompanyId);
  const { data: channelOptions = [] } = useChannelOptions();
  const { data: salesOwnerOptions = [] } = useSalesOwnerOptions();

  // ── Drawer / dialog state ──────────────────────────────────────────────────
  const [viewCustomer, setViewCustomer]     = useState<Customer | null>(null);
  const [viewDefaultTab, setViewDefaultTab] = useState('summary');
  const [drawerOpen, setDrawerOpen]         = useState(false);
  const [drawerCustomer, setDrawerCustomer] = useState<Customer | null>(null);
  const [initialPhone, setInitialPhone]     = useState('');
  const [deleting, setDeleting]             = useState<Customer | null>(null);

  // ── Blocked Customer dialogs (TASK-...-BLOCKED-CUSTOMERS-009 §11/§12/§28) ──
  const [blocking, setBlocking]         = useState<Customer | null>(null);
  const [blockReason, setBlockReason]   = useState('');
  const [unblocking, setUnblocking]     = useState<Customer | null>(null);
  const [unblockReason, setUnblockReason] = useState('');
  const [blockPhoneOpen, setBlockPhoneOpen]     = useState(false);
  const [blockPhoneValue, setBlockPhoneValue]   = useState('');
  const [blockPhoneReason, setBlockPhoneReason] = useState('');

  const { can } = usePermission();
  const canBlock = can('crm.customers.block');
  const canUnblock = can('crm.customers.unblock');
  const blockCustomer = useBlockCustomer();
  const unblockCustomer = useUnblockCustomer();
  const blockPhone = useBlockPhone();

  // ── DD-055: Auto-focus search on mount ────────────────────────────────────
  useEffect(() => {
    searchRef.current?.focus();
  }, []);

  // ── Debounce search (300ms) ───────────────────────────────────────────────
  useEffect(() => {
    const id = setTimeout(() => {
      setDebounced(search);
      setPage(1);
      setFocusedRowIndex(null);
    }, 300);
    return () => clearTimeout(id);
  }, [search]);

  // ── Queries ───────────────────────────────────────────────────────────────
  const counts = useCustomerCounts();

  // TASK-...-FINAL-UI-CLOSURE-014 (§10/§11) — the SAME params drive the live query AND
  // Print/Export, so what the user sees is always exactly what gets printed/exported.
  const queryParams: CustomersQuery = {
    search: debouncedSearch || undefined,
    status: statusFilter,
    brand_id: brandId ?? undefined,
    sales_owner_id: salesOwnerFilter && salesOwnerFilter !== 'unassigned' ? salesOwnerFilter : undefined,
    unassigned_sales_owner: salesOwnerFilter === 'unassigned' ? true : undefined,
    channel_id: channelFilterId ?? undefined,
    top_spenders: topSpenders || undefined,
    repeat_only: repeatOnly || undefined,
    product_id: affinityProductId ?? undefined,
    min_purchase_count: affinityProductId ? minPurchaseCount : undefined,
    order_activity: orderActivity ?? undefined,
    blocked_only: blockedOnly || undefined,
    not_blocked_only: notBlockedOnly || undefined,
    page,
    per_page: PER_PAGE,
    sort_by: sort.field,
    sort_dir: sort.direction,
  };

  const { data, isLoading, isError, isFetching, refetch } = useCustomersQuery(queryParams);

  const deleteCustomer = useDeleteCustomer();

  // Memoised so the empty-state fallback keeps a stable identity between renders.
  const items = useMemo(() => data?.items ?? [], [data]);
  const meta  = data?.meta;

  // Reset selection when page data changes
  useEffect(() => {
    setFocusedRowIndex(null);
  }, [data]);

  // ── DD-056: Smart search behavior ─────────────────────────────────────────
  const isSearching = debouncedSearch.length > 0;
  const singleResult = isSearching && !isLoading && items.length === 1;
  const noResults    = isSearching && !isLoading && items.length === 0;
  const multiResults = isSearching && !isLoading && items.length > 1;
  const showTable    = !isSearching || multiResults;

  // ── Handlers ──────────────────────────────────────────────────────────────
  const openCreate = (phone?: string) => {
    setDrawerCustomer(null);
    setInitialPhone(phone ?? '');
    setDrawerOpen(true);
  };

  const openEdit = (customer: Customer) => {
    setViewCustomer(null);
    setDrawerCustomer(customer);
    setInitialPhone('');
    setDrawerOpen(true);
  };

  const openView = (customer: Customer, tab = 'summary') => {
    setViewDefaultTab(tab);
    setViewCustomer(customer);
  };

  const openViewOrders = (customer: Customer) => openView(customer, 'orders');

  const toggleSelect = (id: string) => {
    setSelectedIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  function handleSortChange(field: CustomerSortField) {
    setSort((curr) =>
      curr.field === field
        ? { field, direction: curr.direction === 'asc' ? 'desc' : 'asc' }
        : { field, direction: 'asc' },
    );
    setPage(1);
    setFocusedRowIndex(null);
  }

  // TASK-...-FINAL-UI-CLOSURE-014 (§7/§8) — root cause of the "Top Spenders" bug: this
  // control is a SORT over the current (optionally filtered) population, not a segment
  // that reduces it — "shows ALL Customers" was therefore correct behavior, not a data
  // bug. The actual defect was that it had no way to turn back OFF: clicking it again
  // re-sent the identical sort, and it was excluded from hasActiveIntelligenceFilter/
  // clearIntelligenceFilters, so the button stayed permanently highlighted with no
  // "Clear filters" affordance able to reach it. Both are fixed below. No canonical
  // "Top Spenders" population/threshold exists anywhere in current source (confirmed by
  // an exhaustive repo-wide search) — per this task's own §9, that is NOT invented here.
  const isHighestSpendSort = sort.field === 'total_order_value' && sort.direction === 'desc';
  const hasActiveIntelligenceFilter = repeatOnly || affinityProductId !== null || isHighestSpendSort || topSpenders;

  function clearIntelligenceFilters() {
    setRepeatOnly(false);
    setAffinityProductId(null);
    setMinPurchaseCount(REPEAT_ORDER_THRESHOLD);
    setSort({ field: 'created_at', direction: 'desc' });
    setTopSpenders(false);
    setPage(1);
  }

  // ── Additional classification filters — active count + Clear All
  //    (TASK-...-FINAL-UI-CLOSURE-014 §13/§20) ───────────────────────────────
  const activeExtraFilterCount =
    (brandId ? 1 : 0) +
    (salesOwnerFilter ? 1 : 0) +
    (channelFilterId ? 1 : 0) +
    (orderActivity ? 1 : 0);

  function clearExtraFilters() {
    setBrandId(null);
    setSalesOwnerFilter(null);
    setChannelFilterId(null);
    setOrderActivity(null);
    setPage(1);
  }

  // ── Print / Export (TASK-...-FINAL-UI-CLOSURE-014 §10/§11) — backend-authoritative:
  //    both call the SAME /customers/export endpoint with the SAME queryParams the live
  //    table uses, so the result always matches the current filtered/sorted view, never
  //    just the current page and never a browser-side re-derivation. ─────────────────
  const [printing, setPrinting] = useState(false);
  const [exportingCsv, setExportingCsv] = useState(false);

  async function handlePrint() {
    setPrinting(true);
    try {
      const html = await customersService.exportHtml(queryParams);
      const win = window.open('', '_blank');
      if (win) {
        win.document.write(html);
        win.document.close();
        win.print();
      }
    } catch (error) {
      console.error('Failed to print customers', error);
    } finally {
      setPrinting(false);
    }
  }

  async function handleExportCsv() {
    setExportingCsv(true);
    try {
      const blob = await customersService.exportCsv(queryParams);
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `customers-${new Date().toISOString().slice(0, 10)}.csv`;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
    } catch (error) {
      console.error('Failed to export customers', error);
    } finally {
      setExportingCsv(false);
    }
  }

  // ── Keyboard navigation ───────────────────────────────────────────────────
  const stateRef = useRef({
    items,
    viewCustomer,
    drawerOpen,
    focusedRowIndex,
    selectedIds,
  });
  useEffect(() => {
    stateRef.current = { items, viewCustomer, drawerOpen, focusedRowIndex, selectedIds };
  }, [items, viewCustomer, drawerOpen, focusedRowIndex, selectedIds]);

  useEffect(() => {
    const handler = (e: KeyboardEvent) => {
      const target = e.target as HTMLElement;
      const inInput = target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.isContentEditable;
      const {
        items: rows,
        viewCustomer: vc,
        drawerOpen: fo,
        focusedRowIndex: fi,
        selectedIds: sel,
      } = stateRef.current;

      // Ctrl+K or / → focus search
      if ((e.key === 'k' && (e.ctrlKey || e.metaKey)) || (e.key === '/' && !inInput)) {
        e.preventDefault(); searchRef.current?.focus(); searchRef.current?.select(); return;
      }
      // Ctrl+N → new customer
      if (e.key === 'n' && (e.ctrlKey || e.metaKey) && !inInput) {
        e.preventDefault(); openCreate(); return;
      }
      // Escape → close drawer / clear search
      if (e.key === 'Escape' && !inInput) {
        if (vc !== null) { setViewCustomer(null); return; }
        if (fo) return;
        setSearch(''); setFocusedRowIndex(null); return;
      }
      // Arrow Down
      if (e.key === 'ArrowDown' && !inInput && rows.length > 0) {
        e.preventDefault(); setFocusedRowIndex(fi === null ? 0 : Math.min(fi + 1, rows.length - 1)); return;
      }
      // Arrow Up
      if (e.key === 'ArrowUp' && !inInput && rows.length > 0) {
        e.preventDefault(); setFocusedRowIndex(fi === null ? 0 : Math.max(fi - 1, 0)); return;
      }
      // Enter → open focused row
      if (e.key === 'Enter' && !inInput && fi !== null) {
        e.preventDefault(); const c = rows[fi]; if (c) openView(c); return;
      }
      // Space → toggle row selection
      if (e.key === ' ' && !inInput && fi !== null) {
        e.preventDefault();
        const c = rows[fi];
        if (c) {
          const next = new Set(sel);
          if (next.has(c.id)) next.delete(c.id);
          else next.add(c.id);
          setSelectedIds(next);
        }
        return;
      }
    };
    document.addEventListener('keydown', handler);
    return () => document.removeEventListener('keydown', handler);
     
  }, []);

  const allSelected = items.length > 0 && items.every((c) => selectedIds.has(c.id));

  return (
    <div className="flex flex-col gap-6">
      {/* ── Page Header ─────────────────────────────────────────────────── */}
      <PageHeader
        title={t($ => $.title)}
        subtitle={t($ => $.subtitle)}
        breadcrumbs={[
          { label: tCommon($ => $.home), to: ROUTES.dashboard },
          { label: t($ => $.title) },
        ]}
        actions={
          <Button size="sm" onClick={() => openCreate()}>
            <Plus className="size-4" />
            {t($ => $.actions.new)}
          </Button>
        }
      />

      {/* ── Quick Stats ─────────────────────────────────────────────────── */}
      <div className="grid gap-3 sm:grid-cols-3">
        <QuickStatCard
          title={t($ => $.quickStats.total)}
          value={counts.total ?? '—'}
          icon={Users}
          onClick={() => { setStatusFilter('all'); setSearch(''); }}
        />
        <QuickStatCard
          title={t($ => $.quickStats.active)}
          value={counts.active ?? '—'}
          icon={Users}
          colorClassName="text-emerald-600 bg-emerald-100"
          onClick={() => { setStatusFilter('active'); setPage(1); }}
        />
        <QuickStatCard
          title={t($ => $.quickStats.inactive)}
          value={counts.inactive ?? '—'}
          icon={Users}
          colorClassName="text-amber-600 bg-amber-100"
          onClick={() => { setStatusFilter('inactive'); setPage(1); }}
        />
      </div>

      {/* ── Smart Search (DD-055/056) ────────────────────────────────────── */}
      <div className="flex flex-col gap-3">
        <div className="flex flex-wrap items-center gap-2">
          <Input
            ref={searchRef}
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder={`${t($ => $.search)} · / or Ctrl+K`}
            className="max-w-lg"
            onKeyDown={(e) => {
              if (e.key === 'Escape') { setSearch(''); searchRef.current?.blur(); }
            }}
          />
          {isFetching && isSearching ? (
            <span className="text-xs text-muted-foreground">{tCommon($ => $.loading) ?? 'Loading…'}</span>
          ) : null}

          {/* ── Customer Intelligence panel: Highest Spend / Repeat / Product Affinity —
              every filter here is backend-authoritative (EloquentCustomerRepository::
              paginate()), never a client-side filter of the current page. */}
          <Popover>
            <PopoverTrigger asChild>
              <Button
                variant="outline"
                size="sm"
                className={cn('gap-1.5', hasActiveIntelligenceFilter && 'border-primary text-primary')}
              >
                <TrendingUp className="size-3.5" />
                {t($ => $.intelligencePanel.trigger)}
              </Button>
            </PopoverTrigger>
            <PopoverContent align="start" className="w-80 p-3">
              <div className="flex flex-col gap-3">
                <div>
                  <p className="mb-1.5 text-[11px] uppercase tracking-wide text-muted-foreground">
                    {t($ => $.intelligencePanel.segments)}
                  </p>
                  <div className="flex flex-wrap gap-1.5">
                    <Button
                      type="button"
                      size="sm"
                      variant={isHighestSpendSort ? 'default' : 'outline'}
                      className="h-7 gap-1 text-xs"
                      onClick={() => {
                        // TASK-...-FINAL-UI-CLOSURE-014 (§8) — a real toggle: it can now
                        // turn itself back off, so the button's active state can never
                        // stay permanently highlighted.
                        setSort(
                          isHighestSpendSort
                            ? { field: 'created_at', direction: 'desc' }
                            : { field: 'total_order_value', direction: 'desc' },
                        );
                        setPage(1);
                      }}
                    >
                      <TrendingUp className="size-3" />
                      {t($ => $.intelligencePanel.highestSpend)}
                    </Button>
                    {/* TASK-...-FINAL-UI-CLOSURE-014-R1 (§2-§7) — Top Spenders: a REAL
                        population segment (top 20% of eligible Customers, tenant-wide,
                        by total_order_value), computed backend-authoritatively —
                        deliberately a separate control from "Highest Spend" above,
                        which only ever re-sorts the same (unreduced) population. */}
                    <Button
                      type="button"
                      size="sm"
                      variant={topSpenders ? 'default' : 'outline'}
                      className="h-7 gap-1 text-xs"
                      title={t($ => $.intelligencePanel.topSpendersHint)}
                      onClick={() => {
                        setTopSpenders((v) => !v);
                        setPage(1);
                      }}
                    >
                      <TrendingUp className="size-3" />
                      {t($ => $.intelligencePanel.topSpenders)}
                    </Button>
                    <Button
                      type="button"
                      size="sm"
                      variant={repeatOnly ? 'default' : 'outline'}
                      className="h-7 gap-1 text-xs"
                      onClick={() => {
                        setRepeatOnly((v) => !v);
                        setPage(1);
                      }}
                    >
                      <Repeat className="size-3" />
                      {t($ => $.intelligencePanel.repeatCustomers)}
                    </Button>
                  </div>
                </div>

                <div>
                  <p className="mb-1.5 text-[11px] uppercase tracking-wide text-muted-foreground">
                    {t($ => $.intelligencePanel.productAffinity)}
                  </p>
                  <Combobox
                    options={productOptions}
                    value={affinityProductId}
                    onChange={(v) => { setAffinityProductId(v || null); setPage(1); }}
                    placeholder={t($ => $.intelligencePanel.selectProduct)}
                    loading={loadingProducts}
                    className="h-8"
                  />
                  {affinityProductId ? (
                    <div className="mt-2 flex items-center gap-2">
                      <span className="text-xs text-muted-foreground">
                        {t($ => $.intelligencePanel.minPurchases)}
                      </span>
                      <Input
                        type="number"
                        min={1}
                        className="h-7 w-16"
                        value={minPurchaseCount}
                        onChange={(e) => {
                          const n = parseInt(e.target.value, 10);
                          setMinPurchaseCount(Number.isFinite(n) && n > 0 ? n : REPEAT_ORDER_THRESHOLD);
                          setPage(1);
                        }}
                      />
                    </div>
                  ) : null}
                </div>

                {hasActiveIntelligenceFilter ? (
                  <Button type="button" size="sm" variant="ghost" className="h-7 self-start text-xs" onClick={clearIntelligenceFilters}>
                    {t($ => $.intelligencePanel.clear)}
                  </Button>
                ) : null}
              </div>
            </PopoverContent>
          </Popover>

          {/* ── Additional classification filters: Brand / Sales Owner / Channel /
              Order Activity (TASK-...-FINAL-UI-CLOSURE-014 §13-19) — every filter here
              is backend-authoritative (EloquentCustomerRepository::buildQuery()), never
              a client-side filter of the current page. ─────────────────────────────── */}
          <Popover>
            <PopoverTrigger asChild>
              <Button
                variant="outline"
                size="sm"
                className={cn('gap-1.5', activeExtraFilterCount > 0 && 'border-primary text-primary')}
              >
                <SlidersHorizontal className="size-3.5" />
                {t($ => $.filtersPanel.trigger)}
                {activeExtraFilterCount > 0 ? (
                  <Badge variant="secondary" className="h-4 min-w-4 rounded-full px-1 text-[10px]">
                    {activeExtraFilterCount}
                  </Badge>
                ) : null}
              </Button>
            </PopoverTrigger>
            <PopoverContent align="start" className="w-80 p-3">
              <div className="flex flex-col gap-3">
                <div>
                  <p className="mb-1.5 text-[11px] uppercase tracking-wide text-muted-foreground">
                    {t($ => $.filtersPanel.brand)}
                  </p>
                  <Combobox
                    options={[{ value: '', label: t($ => $.filtersPanel.allBrands) }, ...brandOptions]}
                    value={brandId ?? ''}
                    onChange={(v) => { setBrandId(v || null); setPage(1); }}
                    placeholder={t($ => $.filtersPanel.allBrands)}
                    className="h-8"
                  />
                </div>

                <div>
                  <p className="mb-1.5 text-[11px] uppercase tracking-wide text-muted-foreground">
                    {t($ => $.filtersPanel.salesOwner)}
                  </p>
                  <Combobox
                    options={[
                      { value: '', label: t($ => $.filtersPanel.allSalesOwners) },
                      { value: 'unassigned', label: t($ => $.table.unassigned) },
                      ...salesOwnerOptions,
                    ]}
                    value={salesOwnerFilter ?? ''}
                    onChange={(v) => { setSalesOwnerFilter(v || null); setPage(1); }}
                    placeholder={t($ => $.filtersPanel.allSalesOwners)}
                    className="h-8"
                  />
                </div>

                <div>
                  <p className="mb-1.5 text-[11px] uppercase tracking-wide text-muted-foreground">
                    {t($ => $.filtersPanel.channel)}
                  </p>
                  <Combobox
                    options={[{ value: '', label: t($ => $.filtersPanel.allChannels) }, ...channelOptions]}
                    value={channelFilterId ?? ''}
                    onChange={(v) => { setChannelFilterId(v || null); setPage(1); }}
                    placeholder={t($ => $.filtersPanel.allChannels)}
                    className="h-8"
                  />
                </div>

                <div>
                  <p className="mb-1.5 text-[11px] uppercase tracking-wide text-muted-foreground">
                    {t($ => $.filtersPanel.orderActivity)}
                  </p>
                  <Combobox
                    options={[
                      { value: '', label: t($ => $.filtersPanel.orderActivityAll) },
                      { value: 'no_orders', label: t($ => $.filtersPanel.noOrders) },
                      { value: 'one_time', label: t($ => $.filtersPanel.oneTimeCustomer) },
                      { value: 'repeat', label: t($ => $.filtersPanel.repeatCustomer) },
                    ]}
                    value={orderActivity ?? ''}
                    onChange={(v) => { setOrderActivity((v || null) as OrderActivityFilter | null); setPage(1); }}
                    placeholder={t($ => $.filtersPanel.orderActivityAll)}
                    className="h-8"
                  />
                </div>

                {activeExtraFilterCount > 0 ? (
                  <Button type="button" size="sm" variant="ghost" className="h-7 self-start text-xs" onClick={clearExtraFilters}>
                    {t($ => $.filtersPanel.clearAll)}
                  </Button>
                ) : null}
              </div>
            </PopoverContent>
          </Popover>

          {/* Blocked Customers filter/segment (§40). Mutually exclusive with Not Blocked
              below, but its own click→true/click-again→undefined contract is unchanged. */}
          <Button
            type="button"
            variant={blockedOnly ? 'default' : 'outline'}
            size="sm"
            className="gap-1.5"
            onClick={() => {
              setBlockedOnly((v) => {
                const next = !v;
                if (next) setNotBlockedOnly(false);
                return next;
              });
              setPage(1);
            }}
          >
            <Ban className="size-3.5" />
            {t($ => $.blocked.filter)}
          </Button>

          {/* TASK-...-FINAL-UI-CLOSURE-014 (§17) — the honest complement: All / Blocked /
              Not Blocked as two independent, mutually-exclusive toggles rather than
              restructuring the existing Blocked button (see note above). */}
          <Button
            type="button"
            variant={notBlockedOnly ? 'default' : 'outline'}
            size="sm"
            className="gap-1.5"
            onClick={() => {
              setNotBlockedOnly((v) => {
                const next = !v;
                if (next) setBlockedOnly(false);
                return next;
              });
              setPage(1);
            }}
          >
            <ShieldCheck className="size-3.5" />
            {t($ => $.blocked.notBlockedFilter)}
          </Button>

          {/* Phone-before-Customer block entry point (§12) */}
          {canBlock ? (
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="gap-1.5"
              onClick={() => { setBlockPhoneValue(''); setBlockPhoneReason(''); setBlockPhoneOpen(true); }}
            >
              <Ban className="size-3.5" />
              {t($ => $.blocked.blockPhoneAction)}
            </Button>
          ) : null}

          {/* TASK-...-FINAL-UI-CLOSURE-014 (§10/§11/§24) — Print/Export stay desktop-
              toolbar actions, matching this app's existing responsive pattern (Orders'
              own Print/Export are desktop-toolbar-only too). Both call the backend
              /customers/export endpoint with the CURRENT filter/sort state — never the
              browser's currently-rendered rows only. */}
          {!isMobile ? (
            <>
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="gap-1.5"
                onClick={() => void handlePrint()}
                disabled={printing}
              >
                <Printer className="size-3.5" />
                {t($ => $.smartOps.print)}
              </Button>
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="gap-1.5"
                onClick={() => void handleExportCsv()}
                disabled={exportingCsv}
              >
                <Download className="size-3.5" />
                {t($ => $.smartOps.export)}
              </Button>
            </>
          ) : null}
        </div>

        {/* DD-056: Single result → Quick Action Card */}
        {singleResult ? (
          <CustomerQuickActionCard
            customer={items[0]}
            onOpen={(c) => openView(c, 'summary')}
            onOpenOrders={openViewOrders}
            onEdit={openEdit}
            onCreateOrder={(c) => navigate(ROUTES.ordersNew, { state: { customerPhone: c.phone ?? undefined } })}
            onClose={() => setSearch('')}
            className="max-w-md"
          />
        ) : null}

        {/* DD-056: No result → "Customer not found" + Create CTA */}
        {noResults ? (
          <div className="flex max-w-md flex-col items-start gap-3 rounded-xl border border-dashed p-5">
            <div>
              <p className="text-sm font-medium">{t($ => $.noResults.title)}</p>
              <p className="mt-0.5 text-xs text-muted-foreground">{t($ => $.noResults.description)}</p>
            </div>
            <Button size="sm" onClick={() => openCreate(debouncedSearch)}>
              <Plus className="size-3.5" />
              {t($ => $.noResults.createWithPhone)}
            </Button>
          </div>
        ) : null}
      </div>

      {/* ── Data — cards on mobile, table on tablet+ ────────────────────────
          Reconciled from develop's already-merged Mobile lane (TASK-...-FINAL-
          CLOSURE-011 §9/§11): same below-`md` card-list treatment, same reused
          handlers (openView/openViewOrders/openEdit/setDeleting/toggleSelect) —
          no new query, no new business logic. CustomerMobileCard additionally
          receives this batch's Blocked-Customer props (onCreateOrder/onBlock/
          onUnblock/canBlock/canUnblock) so Block/Unblock parity holds on both
          layouts. */}
      {showTable && isMobile ? (
        <div role="list">
          {isLoading ? (
            Array.from({ length: 6 }).map((_, i) => (
              <div key={i} className="mb-2 animate-pulse space-y-2 rounded-xl border bg-card p-3.5 shadow-sm">
                <Skeleton className="h-4 w-32" />
                <Skeleton className="h-4 w-48" />
              </div>
            ))
          ) : isError ? (
            <ErrorState description={t($ => $.table.error)} onRetry={() => void refetch()} />
          ) : items.length === 0 ? (
            <EmptyState title={t($ => $.table.empty)} />
          ) : (
            items.map((customer, idx) => (
              <CustomerMobileCard
                key={customer.id}
                customer={customer}
                isFocused={focusedRowIndex === idx}
                isSelected={selectedIds.has(customer.id)}
                onToggleSelect={toggleSelect}
                onView={openView}
                onViewOrders={openViewOrders}
                onEdit={openEdit}
                onDelete={setDeleting}
                onCreateOrder={(c) => navigate(ROUTES.ordersNew, { state: { customerPhone: c.phone ?? undefined } })}
                onBlock={(c) => { setBlockReason(''); setBlocking(c); }}
                onUnblock={(c) => { setUnblockReason(''); setUnblocking(c); }}
                canBlock={canBlock}
                canUnblock={canUnblock}
              />
            ))
          )}
          {meta && meta.last_page > 1 ? (
            <div className="mt-2">
              <Pagination
                meta={{
                  page: meta.current_page,
                  perPage: meta.per_page,
                  total: meta.total,
                  lastPage: meta.last_page,
                }}
                onPageChange={(p) => { setPage(p); setFocusedRowIndex(null); }}
              />
            </div>
          ) : null}
        </div>
      ) : showTable ? (
        <div className="overflow-hidden rounded-xl border bg-background">
          <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="sticky top-0 z-10 border-b bg-muted/60 backdrop-blur-sm">
              <tr>
                {/* Checkbox */}
                <th className="w-10 px-3 py-3">
                  <input
                    type="checkbox"
                    className="size-4 cursor-pointer rounded border-input"
                    checked={allSelected}
                    onChange={(e) => {
                      if (e.target.checked) setSelectedIds(new Set(items.map((c) => c.id)));
                      else setSelectedIds(new Set());
                    }}
                    aria-label={t($ => $.table.selectAll)}
                  />
                </th>
                <SortTh field="name" label={t($ => $.columns.customer)} sort={sort} onSort={handleSortChange} />
                <th className="px-4 py-3 text-start text-xs font-medium text-muted-foreground">
                  {t($ => $.columns.phones)}
                </th>
                <th className="px-4 py-3 text-start text-xs font-medium text-muted-foreground">
                  {t($ => $.columns.brands)}
                </th>
                <th className="px-4 py-3 text-start text-xs font-medium text-muted-foreground">
                  {t($ => $.columns.salesOwner)}
                </th>
                <th className="px-4 py-3 text-start text-xs font-medium text-muted-foreground">
                  {t($ => $.columns.channels)}
                </th>
                <SortTh field="orders_count" label={t($ => $.columns.ordersCount)} sort={sort} onSort={handleSortChange} align="end" />
                <SortTh field="total_order_value" label={t($ => $.columns.totalOrderValue)} sort={sort} onSort={handleSortChange} align="end" />
                <th className="px-4 py-3 text-end text-xs font-medium text-muted-foreground">
                  {t($ => $.columns.receivingRate)}
                </th>
                <SortTh field="last_order_at" label={t($ => $.columns.lastOrder)} sort={sort} onSort={handleSortChange} />
                <th className="px-4 py-3 text-start text-xs font-medium text-muted-foreground">
                  {t($ => $.columns.fullAddress)}
                </th>
                <th className="px-4 py-3 text-start text-xs font-medium text-muted-foreground">
                  {t($ => $.columns.topProducts)}
                </th>
                <th className="px-4 py-3 text-start text-xs font-medium text-muted-foreground">
                  {t($ => $.columns.intelligence)}
                </th>
                <th className="w-12 px-4 py-3" />
              </tr>
            </thead>
            <tbody>
              {isLoading ? (
                Array.from({ length: 8 }).map((_, i) => <CustomerRowSkeleton key={i} />)
              ) : isError ? (
                <tr>
                  <td colSpan={CUSTOMER_TABLE_COLUMNS} className="py-12">
                    <ErrorState
                      description={t($ => $.table.error)}
                      onRetry={() => void refetch()}
                    />
                  </td>
                </tr>
              ) : items.length === 0 ? (
                <tr>
                  <td colSpan={CUSTOMER_TABLE_COLUMNS} className="py-12">
                    <EmptyState title={t($ => $.table.empty)} />
                  </td>
                </tr>
              ) : (
                items.map((customer, idx) => (
                  <CustomerRow
                    key={customer.id}
                    customer={customer}
                    isFocused={focusedRowIndex === idx}
                    isSelected={selectedIds.has(customer.id)}
                    onToggleSelect={toggleSelect}
                    onView={openView}
                    onViewOrders={openViewOrders}
                    onEdit={openEdit}
                    onDelete={setDeleting}
                    onCreateOrder={(c) => navigate(ROUTES.ordersNew, { state: { customerPhone: c.phone ?? undefined } })}
                    onBlock={(c) => { setBlockReason(''); setBlocking(c); }}
                    onUnblock={(c) => { setUnblockReason(''); setUnblocking(c); }}
                    canBlock={canBlock}
                    canUnblock={canUnblock}
                  />
                ))
              )}
            </tbody>
          </table>
          </div>

          {meta && meta.last_page > 1 ? (
            <div className="border-t px-4 py-3">
              <Pagination
                meta={{
                  page: meta.current_page,
                  perPage: meta.per_page,
                  total: meta.total,
                  lastPage: meta.last_page,
                }}
                onPageChange={(p) => { setPage(p); setFocusedRowIndex(null); }}
              />
            </div>
          ) : null}
        </div>
      ) : null}

      {/* ── Customer Profile Drawer ────────────────────────────────────── */}
      <CustomerDrawer
        customer={viewCustomer}
        open={viewCustomer !== null}
        onOpenChange={(open) => { if (!open) setViewCustomer(null); }}
        onEdit={openEdit}
        defaultTab={viewDefaultTab}
      />

      {/* ── Create / Edit Form Drawer ─────────────────────────────────── */}
      <CustomerFormDrawer
        open={drawerOpen}
        onOpenChange={(open) => {
          setDrawerOpen(open);
          if (!open) { setDrawerCustomer(null); setInitialPhone(''); }
        }}
        customer={drawerCustomer}
        initialPhone={initialPhone}
        onFoundExisting={openView}
      />

      {/* ── Delete Confirm ────────────────────────────────────────────── */}
      <ConfirmDialog
        open={deleting !== null}
        onOpenChange={(open) => { if (!open) setDeleting(null); }}
        title={t($ => $.delete.title)}
        description={tCommon($ => $.dialogs.softDeleteMessage, { name: deleting?.name ?? '' })}
        confirmLabel={t($ => $.delete.confirm)}
        variant="destructive"
        loading={deleteCustomer.isPending}
        onConfirm={() => {
          if (deleting) deleteCustomer.mutate(deleting.id, { onSuccess: () => setDeleting(null) });
        }}
      />

      {/* ── Block Confirm (§11) — mandatory reason (§7) ──────────────────── */}
      <ConfirmDialog
        open={blocking !== null}
        onOpenChange={(open) => { if (!open) setBlocking(null); }}
        title={t($ => $.blocked.blockDialog.title)}
        description={
          <>
            {t($ => $.blocked.blockDialog.description, { name: blocking?.name ?? '' })}
            <Input
              autoFocus
              placeholder={t($ => $.blocked.reasonPlaceholder)}
              value={blockReason}
              onChange={(e) => setBlockReason(e.target.value)}
              className="mt-2"
            />
          </>
        }
        confirmLabel={t($ => $.blocked.blockAction)}
        variant="destructive"
        loading={blockCustomer.isPending}
        confirmDisabled={blockReason.trim() === ''}
        onConfirm={() => {
          if (!blocking) return;
          blockCustomer.mutate(
            { id: blocking.id, reason: blockReason.trim() },
            { onSuccess: () => setBlocking(null) },
          );
        }}
      />

      {/* ── Unblock Confirm (§28) — mandatory reason (§7) ────────────────── */}
      <ConfirmDialog
        open={unblocking !== null}
        onOpenChange={(open) => { if (!open) setUnblocking(null); }}
        title={t($ => $.blocked.unblockDialog.title)}
        description={
          <>
            {t($ => $.blocked.unblockDialog.description, { name: unblocking?.name ?? '' })}
            <Input
              autoFocus
              placeholder={t($ => $.blocked.reasonPlaceholder)}
              value={unblockReason}
              onChange={(e) => setUnblockReason(e.target.value)}
              className="mt-2"
            />
          </>
        }
        confirmLabel={t($ => $.blocked.unblockAction)}
        loading={unblockCustomer.isPending}
        confirmDisabled={unblockReason.trim() === ''}
        onConfirm={() => {
          if (!unblocking || !unblocking.customer_block_id) return;
          unblockCustomer.mutate(
            { id: unblocking.id, blockId: unblocking.customer_block_id, reason: unblockReason.trim() },
            { onSuccess: () => setUnblocking(null) },
          );
        }}
      />

      {/* ── Block Phone (§12) — phone-before-Customer, never fabricates a Customer ── */}
      <ConfirmDialog
        open={blockPhoneOpen}
        onOpenChange={setBlockPhoneOpen}
        title={t($ => $.blocked.blockPhoneAction)}
        description={
          <>
            {t($ => $.blocked.blockPhoneDialog.description)}
            <Input
              autoFocus
              placeholder={t($ => $.blocked.blockPhoneDialog.phonePlaceholder)}
              value={blockPhoneValue}
              onChange={(e) => setBlockPhoneValue(e.target.value)}
              className="mt-2"
            />
            <Input
              placeholder={t($ => $.blocked.reasonPlaceholder)}
              value={blockPhoneReason}
              onChange={(e) => setBlockPhoneReason(e.target.value)}
              className="mt-2"
            />
          </>
        }
        confirmLabel={t($ => $.blocked.blockAction)}
        variant="destructive"
        loading={blockPhone.isPending}
        confirmDisabled={blockPhoneValue.trim() === '' || blockPhoneReason.trim() === ''}
        onConfirm={() => {
          blockPhone.mutate(
            { phone: blockPhoneValue.trim(), reason: blockPhoneReason.trim() },
            { onSuccess: () => setBlockPhoneOpen(false) },
          );
        }}
      />
    </div>
  );
}

// ── Customer Row ──────────────────────────────────────────────────────────────
// Shared by both CustomerRow (desktop table) and CustomerMobileCard (below-`md`
// card list, TASK-...-FINAL-CLOSURE-011 §9/§11/§14) — exported so it stays the
// single contract both layouts are built against; every field here is required
// by at least one of them, and neither layout may drop a field the other needs.

export type RowProps = {
  customer: Customer;
  isFocused: boolean;
  isSelected: boolean;
  onToggleSelect: (id: string) => void;
  onView: (c: Customer, tab?: string) => void;
  onViewOrders: (c: Customer) => void;
  onEdit: (c: Customer) => void;
  onDelete: (c: Customer) => void;
  onCreateOrder: (c: Customer) => void;
  onBlock: (c: Customer) => void;
  onUnblock: (c: Customer) => void;
  canBlock: boolean;
  canUnblock: boolean;
};

/**
 * TASK-ECOS-COMMERCE-CUSTOMERS-FINAL-USER-REVIEW-REMEDIATION-004.
 *
 * The Blocked badge alone doesn't say WHY — this reads the SAME `block_reason`/
 * `blocked_at`/`blocked_by_name` the customer detail drawer's BlockedCard already
 * shows (§33/§41 of BLOCKED-CUSTOMERS-009), just surfaced inline on the grid too,
 * so a reason is visible without opening the drawer. A short, truncated preview is
 * always visible; the popover (same primitive the Top Products cell above already
 * uses) exposes the untruncated text — the underlying value is never truncated,
 * only its rendering. Renders nothing for a non-blocked customer.
 */
function BlockedReasonHint({ customer }: { customer: Customer }) {
  const { t } = useTranslation('customers');

  if (!customer.is_blocked) {
    return null;
  }

  const reason = customer.block_reason || t($ => $.blocked.noReasonRecorded);

  return (
    <Popover>
      <PopoverTrigger asChild>
        <button
          type="button"
          onClick={(e) => e.stopPropagation()}
          className="max-w-[12rem] truncate text-start text-[10px] text-red-600/80 transition-colors hover:text-red-700 hover:underline dark:text-red-400/80"
        >
          {reason}
        </button>
      </PopoverTrigger>
      <PopoverContent align="start" className="w-72 p-3 text-xs" onClick={(e) => e.stopPropagation()}>
        <p className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
          {t($ => $.blocked.reasonLabel)}
        </p>
        <p className="whitespace-pre-wrap break-words">{reason}</p>
        {customer.blocked_by_name || customer.blocked_at ? (
          <p className="mt-2 border-t pt-2 text-[11px] text-muted-foreground">
            {customer.blocked_by_name ? `${t($ => $.drawer.blocked.blockedBy)}: ${customer.blocked_by_name}` : null}
            {customer.blocked_at
              ? `${customer.blocked_by_name ? ' · ' : ''}${new Date(customer.blocked_at).toLocaleString()}`
              : null}
          </p>
        ) : null}
      </PopoverContent>
    </Popover>
  );
}

function CustomerRow({
  customer,
  isFocused,
  isSelected,
  onToggleSelect,
  onView,
  onViewOrders,
  onEdit,
  onDelete,
  onCreateOrder,
  onBlock,
  onUnblock,
  canBlock,
  canUnblock,
}: RowProps) {
  const { t } = useTranslation('customers');
  const { t: tCommon } = useTranslation('common');

  const primaryPhone   = customer.phone;
  const secondaryPhone = customer.mobile;

  return (
    <tr
      className={cn(
        'group border-b transition-colors hover:bg-accent/30 cursor-pointer',
        isSelected && 'bg-primary/5',
        isFocused && 'outline outline-1 outline-primary/50 bg-accent/30',
      )}
      onClick={() => onView(customer)}
    >
      {/* Checkbox */}
      <td className="w-10 px-3 py-3" onClick={(e) => e.stopPropagation()}>
        <input
          type="checkbox"
          className="size-4 cursor-pointer rounded border-input"
          checked={isSelected}
          onChange={() => onToggleSelect(customer.id)}
          aria-label={customer.name}
        />
      </td>

      {/* Customer */}
      <td className="px-4 py-3">
        <div className="flex items-center gap-2.5">
          <div className="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary">
            {customer.name.slice(0, 2).toUpperCase()}
          </div>
          <div className="min-w-0">
            <p className="truncate text-sm font-medium">{customer.name}</p>
            <p className="text-xs text-muted-foreground">{customer.code}</p>
          </div>
        </div>
      </td>

      {/* Phone — the NUMBER itself is the control: Call (tel:) / WhatsApp / Copy.
          stopPropagation keeps it from opening the drawer. Reuses the shared PhoneCell. */}
      <td className="px-4 py-3" onClick={(e) => e.stopPropagation()}>
        <div className="flex flex-col gap-1">
          <PhoneCell
            phone={primaryPhone}
            labels={{
              call: t($ => $.phone.call),
              whatsapp: t($ => $.phone.whatsapp),
              copy: tCommon($ => $.common.copy),
              copySuccessTitle: t($ => $.phone.copySuccess),
              copyErrorTitle: t($ => $.phone.copyError),
            }}
          />
          {secondaryPhone ? (
            <PhoneCell
              phone={secondaryPhone}
              labels={{
                call: t($ => $.phone.call),
                whatsapp: t($ => $.phone.whatsapp),
                copy: tCommon($ => $.common.copy),
                copySuccessTitle: t($ => $.phone.copySuccess),
                copyErrorTitle: t($ => $.phone.copyError),
              }}
            />
          ) : null}
        </div>
      </td>

      {/* Brand(s) — already-canonical customer.brands (customer_brands pivot), just not
          previously rendered as a table column. Compact chips + overflow, same pattern
          the profile drawer's Summary tab already uses. */}
      <td className="px-4 py-3">
        <ChipList
          items={customer.brands.map((b) => ({ key: b.id, label: b.brand_name ?? '—' }))}
        />
      </td>

      {/* CRM Sales Owner — denormalised sales_owner_name, null until a future task adds
          the assignment action. */}
      <td className="px-4 py-3 text-xs">
        {customer.sales_owner_name ?? <span className="text-muted-foreground">{t($ => $.table.unassigned)}</span>}
      </td>

      {/* Channel(s) — derived read over this customer's own order history
          (CustomerOrderMetricsService::channelsForCustomers), most-used first. */}
      <td className="px-4 py-3">
        <ChipList
          items={customer.channels.map((c) => ({ key: c.channel_id, label: c.channel_name ?? '—' }))}
        />
      </td>

      {/* Orders Count — clicking the number opens this customer's orders. */}
      <td
        className="px-4 py-3 text-end"
        onClick={(e) => { e.stopPropagation(); onViewOrders(customer); }}
      >
        <button
          type="button"
          className="tabular-nums text-sm underline-offset-2 hover:text-primary hover:underline"
          title={t($ => $.table.viewOrders)}
        >
          {customer.orders_count}
        </button>
      </td>

      {/* Total Order Value — SUM(orders.total), server-computed. */}
      <td className="px-4 py-3 text-end tabular-nums text-xs">
        {fmtMoney(customer.total_order_value)}
      </td>

      {/* Receiving Rate — delivered / ALL orders. Em-dash when never ordered, never 0%. */}
      <td className="px-4 py-3 text-end tabular-nums text-xs">
        {customer.receiving_rate === null
          ? <span className="text-muted-foreground">—</span>
          : `${customer.receiving_rate}%`}
      </td>

      {/* Last Order — MAX(orders.created_at), server-computed. */}
      <td className="px-4 py-3 text-xs tabular-nums">
        {customer.last_order_at
          ? new Date(customer.last_order_at).toLocaleDateString()
          : <span className="text-muted-foreground">—</span>}
      </td>

      {/* Full Address + Location. The Location is the canonical `orders.google_maps_url`
          from the customer's most recent order carrying one — never derived from city. */}
      <td className="px-4 py-3">
        {/* TASK-...-FINAL-UI-CLOSURE-014 (§4) — the full formatted address (already a
            correct, canonical read-model field — CustomerController::fullAddress())
            must be directly readable, not truncated behind a hover-only tooltip. Wraps
            naturally within a fixed-but-generous column width; `title` stays only as
            supplemental copy/select-friendly hover text, never load-bearing. */}
        <div className="flex w-64 items-start gap-1.5">
          {customer.full_address ? (
            <p className="whitespace-normal break-words text-xs" title={customer.full_address}>
              {customer.full_address}
            </p>
          ) : (
            <span className="text-xs text-muted-foreground">—</span>
          )}
          {customer.location_url ? (
            <a
              href={customer.location_url}
              target="_blank"
              rel="noopener noreferrer"
              onClick={(e) => e.stopPropagation()}
              className="shrink-0 text-muted-foreground hover:text-primary"
              title={t($ => $.columns.location)}
            >
              <MapPin className="size-3.5" />
            </a>
          ) : (
            <span className="shrink-0 text-xs text-muted-foreground" title={t($ => $.columns.location)}>—</span>
          )}
        </div>
      </td>

      {/* Top Products — count of DISTINCT products; hover/click reveals the top few,
          already grouped and sorted by the database. No aggregation here. */}
      <td className="px-4 py-3" onClick={(e) => e.stopPropagation()}>
        {customer.top_products_count > 0 ? (
          <Popover>
            <PopoverTrigger asChild>
              <button
                type="button"
                className="text-xs underline-offset-2 hover:text-primary hover:underline"
                /* The number is DISTINCT products ordered — not units. The popover then
                   ranks those products by quantity. Spelled out so the two cannot be
                   read as the same thing. */
                title={t($ => $.table.distinctProductsHint, { count: customer.top_products_count })}
              >
                {customer.top_products_count}
              </button>
            </PopoverTrigger>
            <PopoverContent align="start" className="w-64 p-2">
              <p className="mb-1.5 px-1 text-[11px] uppercase tracking-wide text-muted-foreground">
                {t($ => $.table.topProductsByAffinity)}
              </p>
              <ul className="flex flex-col gap-0.5">
                {customer.top_products.map((p) => (
                  <li key={p.product_id ?? p.product_name} className="flex items-center justify-between gap-2 px-1 text-xs">
                    <span className="truncate">{p.product_name ?? '—'}</span>
                    <span className="shrink-0 tabular-nums text-muted-foreground">
                      {t($ => $.table.orderedNTimes, { count: p.orders_count })} · {p.total_quantity}
                    </span>
                  </li>
                ))}
              </ul>
              {customer.top_products_count > customer.top_products.length ? (
                <button
                  type="button"
                  onClick={() => onViewOrders(customer)}
                  className="mt-1.5 w-full px-1 text-start text-[11px] text-primary hover:underline"
                >
                  {t($ => $.table.viewAllProducts, { count: customer.top_products_count })}
                </button>
              ) : null}
            </PopoverContent>
          </Popover>
        ) : (
          <span className="text-xs text-muted-foreground">—</span>
        )}
      </td>

      {/* Customer Intelligence */}
      <td className="px-4 py-3">
        <div className="flex flex-col gap-1">
          <div className="flex flex-wrap gap-1">
            {customer.is_blocked ? (
              <Badge
                variant="secondary"
                className="h-5 gap-1 px-1.5 text-[10px] text-red-700 bg-red-100 border-red-200 dark:text-red-400 dark:bg-red-950/50 dark:border-red-800"
                title={customer.block_reason ?? undefined}
              >
                <Ban className="size-3" />
                {t($ => $.blocked.badge)}
              </Badge>
            ) : null}
            {customer.is_repeat_customer ? (
              <Badge
                variant="secondary"
                className="h-5 gap-1 px-1.5 text-[10px] text-emerald-700 bg-emerald-100 border-emerald-200 dark:text-emerald-400 dark:bg-emerald-950/50 dark:border-emerald-800"
                title={t($ => $.intelligence.repeatHint, { count: REPEAT_ORDER_THRESHOLD })}
              >
                <Repeat className="size-3" />
                {t($ => $.intelligence.repeat)}
              </Badge>
            ) : null}
            {customer.notes ? (
              <Badge
                variant="secondary"
                className="h-5 gap-1 px-1.5 text-[10px] text-amber-700 bg-amber-100 border-amber-200 dark:text-amber-400 dark:bg-amber-950/50 dark:border-amber-800"
              >
                <FileText className="size-3" />
                {t($ => $.intelligence.hasNotes)}
              </Badge>
            ) : null}
            {!customer.is_active ? (
              <Badge variant="secondary" className="h-5 px-1.5 text-[10px]">
                {t($ => $.tags.inactive)}
              </Badge>
            ) : null}
          </div>
          {/* TASK-...-FINAL-USER-REVIEW-REMEDIATION-004 — the Blocked badge above says
              THAT the customer is blocked; this says WHY, so it's visible without
              opening the drawer. See BlockedReasonHint's own docblock. */}
          <BlockedReasonHint customer={customer} />
        </div>
      </td>

      {/* Actions */}
      <td className="px-4 py-3" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-center justify-end gap-1 opacity-0 transition-opacity group-hover:opacity-100">
          <ActionMenu
            label={`Actions for ${customer.name}`}
            items={[
              {
                key: 'edit',
                label: tCommon($ => $.common.edit),
                icon: Pencil,
                onSelect: () => onEdit(customer),
              },
              {
                key: 'createOrder',
                label: t($ => $.quickCard.createOrder),
                icon: Plus,
                onSelect: () => onCreateOrder(customer),
              },
              {
                key: 'copyPhone',
                label: t($ => $.quickCard.copyPhone),
                icon: Copy,
                onSelect: () => {
                  if (!primaryPhone) return;
                  void copyToClipboard(primaryPhone).then((ok) => {
                    if (ok) toast.success(t($ => $.phone.copySuccess));
                    else toast.error(t($ => $.phone.copyError));
                  });
                },
                disabled: !primaryPhone,
              },
              ...(customer.is_blocked
                ? [{
                    key: 'unblock',
                    label: t($ => $.blocked.unblockAction),
                    icon: ShieldCheck,
                    onSelect: () => onUnblock(customer),
                    disabled: !canUnblock,
                  }]
                : [{
                    key: 'block',
                    label: t($ => $.blocked.blockAction),
                    icon: Ban,
                    onSelect: () => onBlock(customer),
                    disabled: !canBlock,
                  }]),
              {
                key: 'delete',
                label: tCommon($ => $.common.delete),
                icon: Trash2,
                variant: 'destructive' as const,
                onSelect: () => onDelete(customer),
              },
            ]}
          />
        </div>
      </td>
    </tr>
  );
}

// ── Customer Mobile Card ────────────────────────────────────────────────────
// TASK-ECOS-COMMERCE-CUSTOMERS-BATCH-02-FINAL-CLOSURE-011 (§9-§14). Reconciled
// from develop's already-merged Mobile lane (customers-page.tsx, "feat(mobile):
// complete customers products and orders mobile UX") — the below-`md` card list
// this function renders, its checkbox/name/KPI-grid/address/notes/phone-footer
// layout, and its base ActionMenu items (edit/copyPhone/delete) are that
// implementation, preserved as-is. On top of it, this batch's Blocked-Customer
// contract is wired in using RowProps' shared shape (§9): a Blocked badge
// (parity with desktop's Intelligence cell), a Repeat-Customer badge (same
// parity), and the same Block/Unblock ActionMenu behavior desktop's CustomerRow
// already has — same onBlock/onUnblock callbacks (opening the SAME page-level
// ConfirmDialogs and useBlockCustomer/useUnblockCustomer mutations, §12: no
// duplicate mutation implementation), same canBlock/canUnblock permission gate.
// A "Create Order" item is included too so `onCreateOrder` — required by the
// shared RowProps contract — has a real call site here, mirroring desktop's
// own ActionMenu ordering (edit, createOrder, copyPhone, block/unblock, delete)
// exactly rather than leaving the prop unused.
export function CustomerMobileCard({
  customer,
  isFocused,
  isSelected,
  onToggleSelect,
  onView,
  onViewOrders,
  onEdit,
  onDelete,
  onCreateOrder,
  onBlock,
  onUnblock,
  canBlock,
  canUnblock,
}: RowProps) {
  const { t } = useTranslation('customers');
  const { t: tCommon } = useTranslation('common');
  const primaryPhone = customer.phone;

  return (
    <div
      role="listitem"
      aria-selected={isSelected}
      data-focused={isFocused || undefined}
      className={cn(
        'relative mb-2 rounded-xl border p-3.5 shadow-sm transition-colors last:mb-0',
        isSelected ? 'bg-primary/5' : 'bg-card',
        isFocused && 'outline outline-1 -outline-offset-1 outline-primary/50',
      )}
    >
      <div className="absolute start-3.5 top-4">
        <input
          type="checkbox"
          checked={isSelected}
          onChange={() => onToggleSelect(customer.id)}
          className="size-4 cursor-pointer rounded accent-primary"
          aria-label={customer.name}
        />
      </div>

      <button
        type="button"
        className="block w-full min-h-11 ps-7 text-start"
        onClick={() => onView(customer)}
        aria-label={`${tCommon($ => $.actions.view)} ${customer.name}`}
      >
        {/* Row 1: Name + code, status — Blocked/Repeat/Inactive badges grouped
            together (parity with desktop's single "Intelligence" cell), wrapped
            so they stack cleanly on narrow screens instead of the single-badge
            layout Mobile had before this batch. */}
        <div className="flex items-start justify-between gap-2">
          <div className="min-w-0">
            <p className="truncate text-[15px] font-semibold leading-tight text-foreground">{customer.name}</p>
            <p className="text-xs text-muted-foreground">{customer.code}</p>
          </div>
          <div className="flex shrink-0 flex-wrap items-center justify-end gap-1">
            {customer.is_blocked ? (
              <Badge
                variant="secondary"
                className="h-5 gap-1 px-1.5 text-[10px] text-red-700 bg-red-100 border-red-200 dark:text-red-400 dark:bg-red-950/50 dark:border-red-800"
                title={customer.block_reason ?? undefined}
              >
                <Ban className="size-3" />
                {t($ => $.blocked.badge)}
              </Badge>
            ) : null}
            {customer.is_repeat_customer ? (
              <Badge
                variant="secondary"
                className="h-5 gap-1 px-1.5 text-[10px] text-emerald-700 bg-emerald-100 border-emerald-200 dark:text-emerald-400 dark:bg-emerald-950/50 dark:border-emerald-800"
                title={t($ => $.intelligence.repeatHint, { count: REPEAT_ORDER_THRESHOLD })}
              >
                <Repeat className="size-3" />
                {t($ => $.intelligence.repeat)}
              </Badge>
            ) : null}
            {!customer.is_active ? (
              <Badge variant="secondary" className="h-5 shrink-0 px-1.5 text-[10px]">
                {t($ => $.tags.inactive)}
              </Badge>
            ) : null}
          </div>
        </div>

        {/* Row 2: Orders / Total / Receiving / Last order — server-computed KPIs */}
        <dl className="mt-2.5 grid grid-cols-2 gap-x-4 gap-y-2">
          <div>
            <dt className="truncate text-[11px] uppercase tracking-wide text-muted-foreground">{t($ => $.columns.ordersCount)}</dt>
            <dd className="mt-0.5 text-sm tabular-nums">{customer.orders_count}</dd>
          </div>
          <div>
            {/* text-end on both dt and dd (§15): the label previously stayed at
                the block-start edge while the value sat at 'end', so the value
                visually detached from its own label under RTL. */}
            <dt className="truncate text-end text-[11px] uppercase tracking-wide text-muted-foreground">{t($ => $.columns.totalOrderValue)}</dt>
            <dd className="mt-0.5 text-end text-sm tabular-nums">{fmtMoney(customer.total_order_value)}</dd>
          </div>
          <div>
            <dt className="truncate text-[11px] uppercase tracking-wide text-muted-foreground">{t($ => $.columns.receivingRate)}</dt>
            <dd className="mt-0.5 text-sm tabular-nums">
              {customer.receiving_rate === null ? <span className="text-muted-foreground">—</span> : `${customer.receiving_rate}%`}
            </dd>
          </div>
          <div>
            <dt className="truncate text-end text-[11px] uppercase tracking-wide text-muted-foreground">{t($ => $.columns.lastOrder)}</dt>
            <dd className="mt-0.5 text-end text-sm tabular-nums">
              {customer.last_order_at ? new Date(customer.last_order_at).toLocaleDateString() : <span className="text-muted-foreground">—</span>}
            </dd>
          </div>
        </dl>

        {/* Row 3: Address + location. TASK-...-FINAL-UI-CLOSURE-014 (§4) — full address
            must be directly readable here too (previously truncated with no `title`
            fallback at all — worse than desktop, not just at parity). */}
        {customer.full_address || customer.location_url ? (
          <div className="mt-2 flex items-start gap-1.5 text-xs text-muted-foreground">
            {customer.full_address ? (
              <p className="min-w-0 flex-1 whitespace-normal break-words" title={customer.full_address}>
                {customer.full_address}
              </p>
            ) : null}
            {customer.location_url ? (
              <a
                href={customer.location_url}
                target="_blank"
                rel="noopener noreferrer"
                onClick={(e) => e.stopPropagation()}
                className="shrink-0 text-muted-foreground hover:text-primary"
                title={t($ => $.columns.location)}
              >
                <MapPin className="size-3.5" />
              </a>
            ) : null}
          </div>
        ) : null}

        {customer.notes ? (
          <div className="mt-2">
            <Badge
              variant="secondary"
              className="h-5 gap-1 px-1.5 text-[10px] text-amber-700 bg-amber-100 border-amber-200 dark:text-amber-400 dark:bg-amber-950/50 dark:border-amber-800"
            >
              <FileText className="size-3" />
              {t($ => $.intelligence.hasNotes)}
            </Badge>
          </div>
        ) : null}
      </button>

      {/* TASK-...-FINAL-USER-REVIEW-REMEDIATION-004 — same reason preview/popover as
          desktop's Intelligence cell (see BlockedReasonHint's own docblock). A sibling
          of the button above, not a child of it: BlockedReasonHint renders its own
          <button> trigger, and a <button> nested inside another <button> is invalid. */}
      {customer.is_blocked ? (
        <div className="mt-1 ps-7">
          <BlockedReasonHint customer={customer} />
        </div>
      ) : null}

      {/* Footer — Call/WhatsApp/Copy (PhoneCell, unchanged shared component) + View Orders + overflow */}
      <div className="mt-2.5 flex items-center justify-between gap-2 ps-7">
        <div onClick={(e) => e.stopPropagation()}>
          <PhoneCell
            phone={primaryPhone}
            labels={{
              call: t($ => $.phone.call),
              whatsapp: t($ => $.phone.whatsapp),
              copy: tCommon($ => $.common.copy),
              copySuccessTitle: t($ => $.phone.copySuccess),
              copyErrorTitle: t($ => $.phone.copyError),
            }}
          />
        </div>
        <div className="flex items-center gap-1" onClick={(e) => e.stopPropagation()}>
          {customer.orders_count > 0 ? (
            <Button variant="ghost" size="sm" className="h-7 px-2 text-xs" onClick={() => onViewOrders(customer)}>
              {t($ => $.table.viewOrders)}
            </Button>
          ) : null}
          <ActionMenu
            label={`Actions for ${customer.name}`}
            items={[
              { key: 'edit', label: tCommon($ => $.common.edit), icon: Pencil, onSelect: () => onEdit(customer) },
              {
                key: 'createOrder',
                label: t($ => $.quickCard.createOrder),
                icon: Plus,
                onSelect: () => onCreateOrder(customer),
              },
              {
                key: 'copyPhone',
                label: t($ => $.quickCard.copyPhone),
                icon: Copy,
                onSelect: () => {
                  if (!primaryPhone) return;
                  void copyToClipboard(primaryPhone).then((ok) => {
                    if (ok) toast.success(t($ => $.phone.copySuccess));
                    else toast.error(t($ => $.phone.copyError));
                  });
                },
                disabled: !primaryPhone,
              },
              ...(customer.is_blocked
                ? [{
                    key: 'unblock',
                    label: t($ => $.blocked.unblockAction),
                    icon: ShieldCheck,
                    onSelect: () => onUnblock(customer),
                    disabled: !canUnblock,
                  }]
                : [{
                    key: 'block',
                    label: t($ => $.blocked.blockAction),
                    icon: Ban,
                    onSelect: () => onBlock(customer),
                    disabled: !canBlock,
                  }]),
              { key: 'delete', label: tCommon($ => $.common.delete), icon: Trash2, variant: 'destructive' as const, onSelect: () => onDelete(customer) },
            ]}
          />
        </div>
      </div>
    </div>
  );
}

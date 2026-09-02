import {
  Copy,
  FileText,
  MapPin,
  Pencil,
  Plus,
  Trash2,
  Users,
} from 'lucide-react';
import { useEffect, useRef, useState, useMemo} from 'react';
import { useNavigate } from 'react-router-dom';

import { PhoneCell } from '@/components/ecos/phone-cell';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { useTranslation } from 'react-i18next';

import {
  ActionMenu,
  ConfirmDialog,
  EmptyState,
  ErrorState,
  PageHeader,
  Pagination,
} from '@/components/crud';
import { QuickStatCard } from '@/components/ds/quick-stat-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { useIsMobile } from '@/hooks/use-is-mobile';
import { CustomerDrawer } from '@/features/customers/components/customer-drawer';
import { CustomerFormDrawer } from '@/features/customers/components/customer-form-drawer';
import { CustomerQuickActionCard } from '@/features/customers/components/customer-quick-action-card';
import { useCustomersQuery, useDeleteCustomer } from '@/features/customers/hooks/use-customers';
import type { Customer, CustomerSortField, CustomerStatusFilter } from '@/features/customers/types/customer';
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
}: {
  field: CustomerSortField;
  label: string;
  sort: { field: CustomerSortField; direction: 'asc' | 'desc' };
  onSort: (f: CustomerSortField) => void;
}) {
  const isActive = sort.field === field;
  return (
    <th className="px-4 py-3 text-start">
      <button
        type="button"
        onClick={() => onSort(field)}
        className="inline-flex items-center gap-1 text-xs font-medium text-muted-foreground hover:text-foreground transition-colors"
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
// orders count, total value, receiving rate, last order, address, top
// products, intelligence, actions) so loading/error/empty states span the
// actual header width instead of drifting whenever a column is added.
const CUSTOMER_TABLE_COLUMNS = 11;

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

  // ── Drawer / dialog state ──────────────────────────────────────────────────
  const [viewCustomer, setViewCustomer]     = useState<Customer | null>(null);
  const [viewDefaultTab, setViewDefaultTab] = useState('summary');
  const [drawerOpen, setDrawerOpen]         = useState(false);
  const [drawerCustomer, setDrawerCustomer] = useState<Customer | null>(null);
  const [initialPhone, setInitialPhone]     = useState('');
  const [deleting, setDeleting]             = useState<Customer | null>(null);

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

  const { data, isLoading, isError, isFetching, refetch } = useCustomersQuery({
    search: debouncedSearch || undefined,
    status: statusFilter,
    page,
    per_page: PER_PAGE,
    sort_by: sort.field,
    sort_dir: sort.direction,
  });

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
        <div className="flex items-center gap-2">
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
        </div>

        {/* DD-056: Single result → Quick Action Card */}
        {singleResult ? (
          <CustomerQuickActionCard
            customer={items[0]}
            onOpen={(c) => openView(c, 'summary')}
            onOpenOrders={openViewOrders}
            onEdit={openEdit}
            // Both the Commerce and Mobile milestones independently fixed the
            // same bug: this was a literal no-op (`() => undefined`) — a
            // rendered, tappable "Create Order" button that did nothing on
            // tap (TASK-ECOS-MOBILE-UX-COMPLETION-003 §13: no placeholder
            // callbacks). Commerce's version is a strict superset — it also
            // pre-fills the customer's phone into the manual order form's own
            // phone-first lookup via router state (verified end-to-end:
            // order-workspace-page.tsx reads `state.customerPhone` and passes
            // it as `initialCustomerPhone` → `ManualOrderFormWorkspace` →
            // `initialPhone` on the phone-lookup step) — kept as canonical.
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
          The desktop table has no mobile treatment at all today (no
          `renderMobileCard`, no `useIsMobile` branch anywhere in this
          feature — design report §9). Below `md`, TASK-ECOS-MOBILE-UX-
          COMPLETION-003 replaces it with a card list built on the SAME
          Task 2 elevated `MobileDataCard` primitive every other redesigned
          list already uses, reusing the exact same data/handlers
          (openView/openViewOrders/openEdit/setDeleting/toggleSelect) —
          no new query, no new business logic. */}
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
                <th className="px-4 py-3 text-end text-xs font-medium text-muted-foreground">
                  {t($ => $.columns.ordersCount)}
                </th>
                <th className="px-4 py-3 text-end text-xs font-medium text-muted-foreground">
                  {t($ => $.columns.totalOrderValue)}
                </th>
                <th className="px-4 py-3 text-end text-xs font-medium text-muted-foreground">
                  {t($ => $.columns.receivingRate)}
                </th>
                <th className="px-4 py-3 text-start text-xs font-medium text-muted-foreground">
                  {t($ => $.columns.lastOrder)}
                </th>
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
    </div>
  );
}

// ── Customer Row ──────────────────────────────────────────────────────────────

export type RowProps = {
  customer: Customer;
  isFocused: boolean;
  isSelected: boolean;
  onToggleSelect: (id: string) => void;
  onView: (c: Customer, tab?: string) => void;
  onViewOrders: (c: Customer) => void;
  onEdit: (c: Customer) => void;
  onDelete: (c: Customer) => void;
};

function CustomerRow({
  customer,
  isFocused,
  isSelected,
  onToggleSelect,
  onView,
  onViewOrders,
  onEdit,
  onDelete,
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
            }}
          />
          {secondaryPhone ? (
            <PhoneCell
              phone={secondaryPhone}
              labels={{
                call: t($ => $.phone.call),
                whatsapp: t($ => $.phone.whatsapp),
                copy: tCommon($ => $.common.copy),
              }}
            />
          ) : null}
        </div>
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
        <div className="flex max-w-[240px] items-start gap-1.5">
          {customer.full_address ? (
            <p className="truncate text-xs" title={customer.full_address}>{customer.full_address}</p>
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
            <PopoverContent align="start" className="w-60 p-2">
              <p className="mb-1.5 px-1 text-[11px] uppercase tracking-wide text-muted-foreground">
                {t($ => $.table.topProductsByQuantity)}
              </p>
              <ul className="flex flex-col gap-0.5">
                {customer.top_products.map((p) => (
                  <li key={p.product_id ?? p.product_name} className="flex items-center justify-between gap-2 px-1 text-xs">
                    <span className="truncate">{p.product_name ?? '—'}</span>
                    <span className="shrink-0 tabular-nums text-muted-foreground">{p.total_quantity}</span>
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
        <div className="flex flex-wrap gap-1">
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
                key: 'copyPhone',
                label: t($ => $.quickCard.copyPhone),
                icon: Copy,
                onSelect: () => {
                  if (primaryPhone) void navigator.clipboard.writeText(primaryPhone);
                },
                disabled: !primaryPhone,
              },
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

// ── Customer Mobile Card (TASK-ECOS-MOBILE-UX-COMPLETION-003) ────────────────
// Same data and handlers as CustomerRow (RowProps), same canonical fields
// (server-computed by CustomerOrderMetricsService — never recomputed here).
// A bespoke card rather than a `MobileDataCard` composition, matching the
// Products/Orders mobile cards: Call/WhatsApp/Copy and the overflow ActionMenu
// need to stay always-visible tap targets (not hover-gated, unlike the desktop
// row's `opacity-0 group-hover:opacity-100` actions cell, which is unreachable
// on a touch device) and sit outside the tap-to-open target, matching
// MobileDataCard's own "no interactive element nested inside another" rule.
export function CustomerMobileCard({
  customer,
  isFocused,
  isSelected,
  onToggleSelect,
  onView,
  onViewOrders,
  onEdit,
  onDelete,
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
        {/* Row 1: Name + code, status */}
        <div className="flex items-start justify-between gap-2">
          <div className="min-w-0">
            <p className="truncate text-[15px] font-semibold leading-tight text-foreground">{customer.name}</p>
            <p className="text-xs text-muted-foreground">{customer.code}</p>
          </div>
          {!customer.is_active ? (
            <Badge variant="secondary" className="h-5 shrink-0 px-1.5 text-[10px]">
              {t($ => $.tags.inactive)}
            </Badge>
          ) : null}
        </div>

        {/* Row 2: Orders / Total / Receiving / Last order — server-computed KPIs */}
        <dl className="mt-2.5 grid grid-cols-2 gap-x-4 gap-y-2">
          <div>
            <dt className="truncate text-[11px] uppercase tracking-wide text-muted-foreground">{t($ => $.columns.ordersCount)}</dt>
            <dd className="mt-0.5 text-sm tabular-nums">{customer.orders_count}</dd>
          </div>
          <div>
            <dt className="truncate text-[11px] uppercase tracking-wide text-muted-foreground">{t($ => $.columns.totalOrderValue)}</dt>
            <dd className="mt-0.5 text-end text-sm tabular-nums">{fmtMoney(customer.total_order_value)}</dd>
          </div>
          <div>
            <dt className="truncate text-[11px] uppercase tracking-wide text-muted-foreground">{t($ => $.columns.receivingRate)}</dt>
            <dd className="mt-0.5 text-sm tabular-nums">
              {customer.receiving_rate === null ? <span className="text-muted-foreground">—</span> : `${customer.receiving_rate}%`}
            </dd>
          </div>
          <div>
            <dt className="truncate text-[11px] uppercase tracking-wide text-muted-foreground">{t($ => $.columns.lastOrder)}</dt>
            <dd className="mt-0.5 text-end text-sm tabular-nums">
              {customer.last_order_at ? new Date(customer.last_order_at).toLocaleDateString() : <span className="text-muted-foreground">—</span>}
            </dd>
          </div>
        </dl>

        {/* Row 3: Address + location */}
        {customer.full_address || customer.location_url ? (
          <div className="mt-2 flex items-start gap-1.5 text-xs text-muted-foreground">
            {customer.full_address ? <p className="min-w-0 flex-1 truncate">{customer.full_address}</p> : null}
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

      {/* Footer — Call/WhatsApp/Copy (PhoneCell, unchanged shared component) + View Orders + overflow */}
      <div className="mt-2.5 flex items-center justify-between gap-2 ps-7">
        <div onClick={(e) => e.stopPropagation()}>
          <PhoneCell
            phone={primaryPhone}
            labels={{
              call: t($ => $.phone.call),
              whatsapp: t($ => $.phone.whatsapp),
              copy: tCommon($ => $.common.copy),
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
                key: 'copyPhone',
                label: t($ => $.quickCard.copyPhone),
                icon: Copy,
                onSelect: () => { if (primaryPhone) void navigator.clipboard.writeText(primaryPhone); },
                disabled: !primaryPhone,
              },
              { key: 'delete', label: tCommon($ => $.common.delete), icon: Trash2, variant: 'destructive' as const, onSelect: () => onDelete(customer) },
            ]}
          />
        </div>
      </div>
    </div>
  );
}

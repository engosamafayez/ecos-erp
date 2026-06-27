import { Copy, MessageCircle, Pencil, Phone, Plus, Trash2, Users } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';

import {
  ActionMenu,
  ConfirmDialog,
  EmptyState,
  ErrorState,
  PageHeader,
  Pagination,
} from '@/components/crud';
import { PhoneCell } from '@/components/ecos/phone-cell';
import { QuickStatCard } from '@/components/ds/quick-stat-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { CustomerFormDrawer } from '@/features/customers/components/customer-form-drawer';
import { CustomerQuickCard } from '@/features/customers/components/customer-quick-card';
import { useCustomersQuery, useDeleteCustomer } from '@/features/customers/hooks/use-customers';
import type { Customer, CustomerStatusFilter } from '@/features/customers/types/customer';
import { ROUTES } from '@/router/routes';

const PER_PAGE = 20;

// ── Stat queries (minimal fetches for global counts) ──────────────────────────

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

// ── Row skeleton ──────────────────────────────────────────────────────────────

function CustomerRowSkeleton() {
  return (
    <tr className="border-b">
      {[1, 2, 3, 4, 5].map((i) => (
        <td key={i} className="px-4 py-3">
          <Skeleton className="h-4 w-full" />
        </td>
      ))}
    </tr>
  );
}

// ── Main page ─────────────────────────────────────────────────────────────────

export function CustomersPage() {
  const { t } = useTranslation('customers');
  const { t: tCommon } = useTranslation('common');
  const searchRef = useRef<HTMLInputElement>(null);

  // ── State ──────────────────────────────────────────────────────────────────
  const [search, setSearch]               = useState('');
  const [debouncedSearch, setDebounced]   = useState('');
  const [statusFilter]                    = useState<CustomerStatusFilter>('all');
  const [page, setPage]                   = useState(1);
  const [drawerOpen, setDrawerOpen]       = useState(false);
  const [drawerCustomer, setDrawerCustomer] = useState<Customer | null>(null);
  const [initialPhone, setInitialPhone]   = useState('');
  const [deleting, setDeleting]           = useState<Customer | null>(null);

  // ── DD-055: Auto-focus search on mount ────────────────────────────────────
  useEffect(() => {
    searchRef.current?.focus();
  }, []);

  // ── Debounce search (300ms) ───────────────────────────────────────────────
  useEffect(() => {
    const id = setTimeout(() => {
      setDebounced(search);
      setPage(1);
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
    sort_by: 'created_at',
    sort_dir: 'desc',
  });

  const deleteCustomer = useDeleteCustomer();

  const items = data?.items ?? [];
  const meta  = data?.meta;

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
    setDrawerCustomer(customer);
    setInitialPhone('');
    setDrawerOpen(true);
  };

  const openView = (customer: Customer) => {
    setDrawerCustomer(customer);
    setInitialPhone('');
    setDrawerOpen(true);
  };

  return (
    <div className="flex flex-col gap-6">
      {/* ── Page Header ─────────────────────────────────────────────────── */}
      <PageHeader
        title={t('title')}
        subtitle={t('subtitle')}
        breadcrumbs={[
          { label: tCommon('home'), to: ROUTES.dashboard },
          { label: t('title') },
        ]}
        actions={
          <div className="flex items-center gap-2">
            <Button variant="outline" size="sm">
              {t('actions.export')}
            </Button>
            <Button size="sm" onClick={() => openCreate()}>
              <Plus className="size-4" />
              {t('actions.new')}
            </Button>
          </div>
        }
      />

      {/* ── Quick Stats ─────────────────────────────────────────────────── */}
      <div className="grid gap-3 sm:grid-cols-3">
        <QuickStatCard
          title={t('quickStats.total')}
          value={counts.total ?? '—'}
          icon={Users}
          onClick={() => { setSearch(''); }}
        />
        <QuickStatCard
          title={t('quickStats.active')}
          value={counts.active ?? '—'}
          icon={Users}
          colorClassName="text-emerald-600 bg-emerald-100"
        />
        <QuickStatCard
          title={t('quickStats.inactive')}
          value={counts.inactive ?? '—'}
          icon={Users}
          colorClassName="text-amber-600 bg-amber-100"
        />
      </div>

      {/* ── Smart Search (DD-055/056) ────────────────────────────────────── */}
      <div className="flex flex-col gap-3">
        <div className="flex items-center gap-2">
          <Input
            ref={searchRef}
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder={t('search')}
            className="max-w-lg"
            onKeyDown={(e) => {
              if (e.key === 'Escape') { setSearch(''); searchRef.current?.blur(); }
            }}
          />
          {isFetching && isSearching ? (
            <span className="text-xs text-muted-foreground">{tCommon('loading') ?? 'Loading…'}</span>
          ) : null}
        </div>

        {/* ── DD-056: Single result → Quick Action Card (not table) ───── */}
        {singleResult ? (
          <CustomerQuickCard
            customer={items[0]}
            onOpen={openView}
            onCreateOrder={() => undefined}
            onClose={() => setSearch('')}
            className="max-w-md"
          />
        ) : null}

        {/* ── DD-056: No result → "Customer not found" + Create CTA ────── */}
        {noResults ? (
          <div className="flex max-w-md flex-col items-start gap-3 rounded-xl border border-dashed p-5">
            <div>
              <p className="font-medium text-sm">{t('noResults.title')}</p>
              <p className="text-xs text-muted-foreground mt-0.5">{t('noResults.description')}</p>
            </div>
            <Button
              size="sm"
              onClick={() => openCreate(debouncedSearch)}
            >
              <Plus className="size-3.5" />
              {t('noResults.createWithPhone')}
            </Button>
          </div>
        ) : null}
      </div>

      {/* ── Data Table (all customers or multiple-result search) ─────────── */}
      {showTable ? (
        <div className="overflow-hidden rounded-xl border bg-background">
          <table className="w-full text-sm">
            <thead className="border-b bg-muted/30">
              <tr>
                <th className="px-4 py-3 text-left font-medium text-muted-foreground">
                  {t('columns.customer')}
                </th>
                <th className="px-4 py-3 text-left font-medium text-muted-foreground">
                  {t('columns.phones')}
                </th>
                <th className="px-4 py-3 text-left font-medium text-muted-foreground">
                  {t('columns.address')}
                </th>
                <th className="px-4 py-3 text-left font-medium text-muted-foreground">
                  {t('columns.status')}
                </th>
                <th className="w-12 px-4 py-3" />
              </tr>
            </thead>
            <tbody>
              {isLoading ? (
                Array.from({ length: 8 }).map((_, i) => <CustomerRowSkeleton key={i} />)
              ) : isError ? (
                <tr>
                  <td colSpan={5} className="py-12">
                    <ErrorState
                      description={t('table.error')}
                      onRetry={() => void refetch()}
                    />
                  </td>
                </tr>
              ) : items.length === 0 ? (
                <tr>
                  <td colSpan={5} className="py-12">
                    <EmptyState title={t('table.empty')} />
                  </td>
                </tr>
              ) : (
                items.map((customer) => (
                  <CustomerRow
                    key={customer.id}
                    customer={customer}
                    onView={openView}
                    onEdit={openEdit}
                    onDelete={setDeleting}
                  />
                ))
              )}
            </tbody>
          </table>

          {meta && meta.last_page > 1 ? (
            <div className="border-t px-4 py-3">
              <Pagination
                meta={{
                  page: meta.current_page,
                  perPage: meta.per_page,
                  total: meta.total,
                  lastPage: meta.last_page,
                }}
                onPageChange={setPage}
              />
            </div>
          ) : null}
        </div>
      ) : null}

      {/* ── Drawers & Dialogs ─────────────────────────────────────────────── */}
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

      <ConfirmDialog
        open={deleting !== null}
        onOpenChange={(open) => { if (!open) setDeleting(null); }}
        title={t('delete.title')}
        description={tCommon('dialogs.softDeleteMessage', { name: deleting?.name ?? '' })}
        confirmLabel={t('delete.confirm')}
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

type RowProps = {
  customer: Customer;
  onView: (c: Customer) => void;
  onEdit: (c: Customer) => void;
  onDelete: (c: Customer) => void;
};

function CustomerRow({ customer, onView, onEdit, onDelete }: RowProps) {
  const { t } = useTranslation('customers');
  const { t: tCommon } = useTranslation('common');

  const primaryPhone  = customer.phone;
  const secondaryPhone = customer.mobile;
  const address = [customer.address, customer.city, customer.country].filter(Boolean).join(', ');

  return (
    <tr
      className="group border-b transition-colors hover:bg-accent/30 cursor-pointer"
      onClick={() => onView(customer)}
    >
      {/* Customer column */}
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

      {/* Phones column */}
      <td className="px-4 py-3" onClick={(e) => e.stopPropagation()}>
        <div className="flex flex-col gap-0.5">
          {primaryPhone ? (
            <div className="flex items-center gap-1.5">
              <PhoneCell
                phone={primaryPhone}
                labels={{
                  call:     t('phone.call'),
                  whatsapp: t('phone.whatsapp'),
                  copy:     t('phone.copy'),
                  copied:   t('phone.copied'),
                }}
              />
              <Badge variant="secondary" className="h-4 px-1 text-[9px] font-medium">
                {t('phone.primary')}
              </Badge>
            </div>
          ) : (
            <span className="text-muted-foreground text-xs">—</span>
          )}
          {secondaryPhone ? (
            <PhoneCell
              phone={secondaryPhone}
              labels={{
                call:     t('phone.call'),
                whatsapp: t('phone.whatsapp'),
                copy:     t('phone.copy'),
                copied:   t('phone.copied'),
              }}
            />
          ) : null}
        </div>
      </td>

      {/* Address column */}
      <td className="px-4 py-3">
        <span className="truncate text-xs text-muted-foreground max-w-[200px] inline-block">
          {address || '—'}
        </span>
      </td>

      {/* Status column */}
      <td className="px-4 py-3">
        <Badge
          variant={customer.is_active ? 'default' : 'secondary'}
          className={customer.is_active ? 'bg-emerald-100 text-emerald-700 border-emerald-200 dark:bg-emerald-950/50 dark:text-emerald-400' : ''}
        >
          {customer.is_active ? tCommon('status.active') : tCommon('status.inactive')}
        </Badge>
      </td>

      {/* Actions column */}
      <td className="px-4 py-3" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-center justify-end gap-1 opacity-0 transition-opacity group-hover:opacity-100">
          {/* Quick call */}
          {primaryPhone ? (
            <Button
              size="icon"
              variant="ghost"
              className="size-7"
              asChild
              title={t('phone.call')}
            >
              <a href={`tel:${primaryPhone.replace(/\D/g, '')}`}>
                <Phone className="size-3.5" />
              </a>
            </Button>
          ) : null}

          {/* Quick WhatsApp */}
          {primaryPhone ? (
            <Button
              size="icon"
              variant="ghost"
              className="size-7"
              asChild
              title={t('phone.whatsapp')}
            >
              <a
                href={`https://wa.me/${primaryPhone.replace(/\D/g, '')}`}
                target="_blank"
                rel="noopener noreferrer"
              >
                <MessageCircle className="size-3.5" />
              </a>
            </Button>
          ) : null}

          <ActionMenu
            label={`Actions for ${customer.name}`}
            items={[
              {
                key: 'edit',
                label: tCommon('common.edit'),
                icon: Pencil,
                onSelect: () => onEdit(customer),
              },
              {
                key: 'copyPhone',
                label: t('quickCard.copyPhone'),
                icon: Copy,
                onSelect: () => {
                  if (primaryPhone) void navigator.clipboard.writeText(primaryPhone);
                },
                disabled: !primaryPhone,
              },
              {
                key: 'delete',
                label: tCommon('common.delete'),
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

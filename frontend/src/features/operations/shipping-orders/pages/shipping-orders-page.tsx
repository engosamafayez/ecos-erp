import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Search } from 'lucide-react';

import { PageHeader, EmptyState, ErrorState } from '@/components/crud';
import { Input } from '@/components/ui/input';
import { UniversalDataGrid } from '@/components/data-grid';
import { useBrandOptions } from '@/features/brands/hooks/use-brand-options';
import { useShippingCompanies } from '@/features/logistics/shipping-companies/hooks/use-shipping-companies';
import { useDrivers } from '@/features/logistics/drivers/hooks/use-drivers';
import { useOrganizationContext } from '@/features/organization/context/organization-context';
import { cn } from '@/lib/utils';

import { createShippingOrderColumns } from '../components/shipping-order-column-defs';
import { useShippingOrdersQuery } from '../hooks/use-shipping-orders';
import type {
  ShippingOrder,
  ShippingOrderClassification,
  ShippingOrderPaymentStatus,
  ShippingOrderTabCounts,
} from '../types/shipping-order';
import { SHIPPING_ORDER_CLASSIFICATIONS } from '../types/shipping-order';

type Tab = 'all' | ShippingOrderClassification;

const EMPTY_COUNTS: ShippingOrderTabCounts = {
  all: 0, assigned_driver: 0, out_for_delivery: 0, delivered: 0,
  postponed: 0, no_answer: 0, cancelled: 0,
};

/**
 * TASK-ECOS-OPERATIONS-SHIPPING-ORDERS-IMPLEMENTATION-002. Read-only office
 * operations monitoring page (§32) — no Start Delivery/Deliver/Postpone/Cancel
 * action lives here; those remain in the existing canonical Driver flow (§33).
 * Every classification shown is backend-authoritative (§31) — this page never
 * re-derives a classification from raw fields.
 */
export function ShippingOrdersPage() {
  const { t } = useTranslation('shipping-orders');
  const { activeCompanyId } = useOrganizationContext();

  const [tab, setTab] = useState<Tab>('all');
  const [search, setSearch] = useState('');
  const [dateFrom, setDateFrom] = useState('');
  const [dateTo, setDateTo] = useState('');
  const [brandId, setBrandId] = useState('');
  const [shippingCompanyId, setShippingCompanyId] = useState('');
  const [driverId, setDriverId] = useState('');
  const [paymentStatus, setPaymentStatus] = useState<ShippingOrderPaymentStatus | ''>('');
  const [page, setPage] = useState(1);
  const perPage = 20;

  const { data: brandOptions = [] } = useBrandOptions(activeCompanyId ?? null);
  const { data: shippingCompaniesData } = useShippingCompanies({ status: 'active', per_page: 200 });
  const shippingCompanyOptions = shippingCompaniesData?.data.map((c) => ({ value: String(c.id), label: c.name })) ?? [];
  const { data: driversData } = useDrivers({ status: 'active', per_page: 200 });
  const driverOptions = driversData?.data.map((d) => ({ value: String(d.id), label: `${d.full_name} (${d.driver_code})` })) ?? [];

  const { data, isLoading, isError } = useShippingOrdersQuery({
    page,
    per_page: perPage,
    classification: tab === 'all' ? undefined : tab,
    search: search || undefined,
    date_from: dateFrom || undefined,
    date_to: dateTo || undefined,
    brand_id: brandId || undefined,
    shipping_company_id: shippingCompanyId || undefined,
    driver_id: driverId || undefined,
    payment_status: paymentStatus || undefined,
  });

  const items: ShippingOrder[] = data?.items ?? [];
  const counts = data?.meta.counts ?? EMPTY_COUNTS;

  const columns = useMemo(() => createShippingOrderColumns(t), [t]);

  const tabs: Tab[] = ['all', ...SHIPPING_ORDER_CLASSIFICATIONS];

  return (
    <div className="flex flex-col gap-4 p-4">
      <PageHeader title={t($ => $.pageTitle)} />

      {/* Tabs */}
      <div className="flex flex-wrap gap-1.5 border-b pb-2">
        {tabs.map((tabKey) => (
          <button
            key={tabKey}
            type="button"
            onClick={() => { setTab(tabKey); setPage(1); }}
            className={cn(
              'rounded-md px-3 py-1.5 text-xs font-medium transition-colors',
              tab === tabKey ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-accent',
            )}
          >
            {tabKey === 'all' ? t($ => $.tabs.all) : t($ => $.classifications[tabKey])}
            <span className="ms-1.5 tabular-nums opacity-80">{counts[tabKey]}</span>
          </button>
        ))}
      </div>

      {/* Filter toolbar */}
      <div className="flex flex-wrap items-center gap-2">
        <div className="relative w-64">
          <Search className="absolute start-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
          <Input
            value={search}
            onChange={(e) => { setSearch(e.target.value); setPage(1); }}
            placeholder={t($ => $.searchPlaceholder)}
            className="ps-8 h-9"
          />
        </div>
        <Input
          type="date"
          value={dateFrom}
          onChange={(e) => { setDateFrom(e.target.value); setPage(1); }}
          className="h-9 w-40"
          aria-label={t($ => $.filters.dateFrom)}
        />
        <Input
          type="date"
          value={dateTo}
          onChange={(e) => { setDateTo(e.target.value); setPage(1); }}
          className="h-9 w-40"
          aria-label={t($ => $.filters.dateTo)}
        />
        <select
          value={brandId}
          onChange={(e) => { setBrandId(e.target.value); setPage(1); }}
          className="h-9 rounded-md border bg-background px-2 text-sm"
        >
          <option value="">{t($ => $.filters.allBrands)}</option>
          {brandOptions.map((b) => (
            <option key={b.value} value={b.value}>{b.label}</option>
          ))}
        </select>
        <select
          value={shippingCompanyId}
          onChange={(e) => { setShippingCompanyId(e.target.value); setPage(1); }}
          className="h-9 rounded-md border bg-background px-2 text-sm"
        >
          <option value="">{t($ => $.filters.allShippingCompanies)}</option>
          {shippingCompanyOptions.map((c) => (
            <option key={c.value} value={c.value}>{c.label}</option>
          ))}
        </select>
        <select
          value={driverId}
          onChange={(e) => { setDriverId(e.target.value); setPage(1); }}
          className="h-9 rounded-md border bg-background px-2 text-sm"
        >
          <option value="">{t($ => $.filters.allDrivers)}</option>
          {driverOptions.map((d) => (
            <option key={d.value} value={d.value}>{d.label}</option>
          ))}
        </select>
        <select
          value={paymentStatus}
          onChange={(e) => { setPaymentStatus(e.target.value as ShippingOrderPaymentStatus | ''); setPage(1); }}
          className="h-9 rounded-md border bg-background px-2 text-sm"
        >
          <option value="">{t($ => $.filters.allPaymentStatuses)}</option>
          <option value="unpaid">{t($ => $.paymentStatus.unpaid)}</option>
          <option value="partially_paid">{t($ => $.paymentStatus.partially_paid)}</option>
          <option value="paid">{t($ => $.paymentStatus.paid)}</option>
        </select>
      </div>

      <UniversalDataGrid<ShippingOrder>
        data={items}
        columns={columns}
        rowId={(o) => o.id}
        loading={isLoading}
        error={isError}
        skeletonRows={8}
        emptyState={<EmptyState title={t($ => $.table.empty)} />}
        errorState={<ErrorState />}
        pagination={{
          meta: {
            page: data?.meta.current_page ?? page,
            perPage,
            total: data?.meta.total ?? 0,
            lastPage: data?.meta.last_page ?? 1,
          },
          onPageChange: setPage,
        }}
      />
    </div>
  );
}

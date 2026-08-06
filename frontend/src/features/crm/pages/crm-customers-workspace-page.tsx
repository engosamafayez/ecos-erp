import { useMemo, useState } from 'react';
import { Archive, Plus, Sparkles, UserCheck, Users } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { UniversalDataGrid } from '@/components/data-grid/universal-data-grid';
import { SmartToolbar } from '@/components/data-grid/smart-toolbar';
import type { DataGridColumnDef } from '@/components/data-grid/types';
import { StatusBadge } from '@/components/crud/status-badge';
import type { StatusVariant } from '@/components/crud/types';
import { QuickStatCard } from '@/components/ds';
import { Badge } from '@/components/ui/badge';
import { usePermission } from '@/features/authorization';
import { useCrmCustomersQuery } from '@/features/crm/hooks/use-crm-customers';
import type {
  CrmCustomer,
  CrmCustomerStatus,
  CrmCustomerType,
  CrmCustomersQuery,
} from '@/features/crm/types/crm-customer';
import type enCrm from '@/i18n/locales/en/crm.json';

/**
 * CRM Customer Workspace.
 *
 * Consumes /api/crm/customers — the CRM contract, which is NOT the Sales
 * /customers endpoint the older customers feature reads. The two return
 * different payloads and are deliberately kept apart.
 */

/** A label held as an i18next selector, so lookup tables stay type-checked. */
type CrmLabel = ($: typeof enCrm) => string;

const PER_PAGE = 25;

/** CRM status → the badge variants the design system actually defines. */
const STATUS_VARIANT: Record<CrmCustomerStatus, StatusVariant> = {
  prospect: 'pending',
  active: 'active',
  inactive: 'inactive',
  blocked: 'inactive',
  archived: 'archived',
};

const STATUS_LABEL: Record<CrmCustomerStatus, CrmLabel> = {
  prospect: ($) => $.status.prospect,
  active: ($) => $.status.active,
  inactive: ($) => $.status.inactive,
  blocked: ($) => $.status.blocked,
  archived: ($) => $.status.archived,
};

const TYPE_LABEL: Record<CrmCustomerType, CrmLabel> = {
  individual: ($) => $.type.individual,
  business: ($) => $.type.business,
};

const STATUS_FILTERS: { value: CrmCustomerStatus | 'all'; label: CrmLabel }[] = [
  { value: 'all', label: ($) => $.filters.allStatuses },
  { value: 'active', label: ($) => $.status.active },
  { value: 'prospect', label: ($) => $.status.prospect },
  { value: 'inactive', label: ($) => $.status.inactive },
  { value: 'blocked', label: ($) => $.status.blocked },
  { value: 'archived', label: ($) => $.status.archived },
];

const TYPE_FILTERS: { value: CrmCustomerType | 'all'; label: CrmLabel }[] = [
  { value: 'all', label: ($) => $.filters.allTypes },
  { value: 'individual', label: ($) => $.type.individual },
  { value: 'business', label: ($) => $.type.business },
];

export function CrmCustomersWorkspacePage() {
  const { t } = useTranslation('crm');
  const { can } = usePermission();

  const [search, setSearch] = useState('');
  const [status, setStatus] = useState<CrmCustomerStatus | 'all'>('all');
  const [type, setType] = useState<CrmCustomerType | 'all'>('all');
  const [page, setPage] = useState(1);

  const params: CrmCustomersQuery = useMemo(
    () => ({
      q: search.trim() || undefined,
      status: status === 'all' ? undefined : status,
      type: type === 'all' ? undefined : type,
      page,
      per_page: PER_PAGE,
    }),
    [search, status, type, page],
  );

  const { data, isLoading, isError, isFetching, refetch } = useCrmCustomersQuery(params);

  const rows = useMemo(() => data?.data ?? [], [data]);
  const meta = data?.meta;
  const isFiltered = Boolean(params.q || params.status || params.type);

  // Derived from the page in hand. The list endpoint returns no aggregate, and
  // inventing a second request for counts would report figures the grid below
  // cannot corroborate.
  const stats = useMemo(() => {
    return {
      total: meta?.total ?? rows.length,
      active: rows.filter((c) => c.status === 'active').length,
      prospects: rows.filter((c) => c.status === 'prospect').length,
      archived: rows.filter((c) => c.status === 'archived').length,
    };
  }, [rows, meta]);

  const columns: DataGridColumnDef<CrmCustomer>[] = useMemo(
    () => [
      {
        key: 'code',
        label: t(($) => $.columns.code),
        cell: (row) => <span className="font-mono text-xs">{row.code ?? '—'}</span>,
      },
      {
        key: 'name',
        label: t(($) => $.columns.name),
        cell: (row) => (
          <div className="flex flex-col">
            <span className="font-medium">{row.display_name}</span>
            {row.merged_into_id && (
              <Badge variant="outline" className="mt-0.5 w-fit text-[10px]">
                {t(($) => $.row.merged)}
              </Badge>
            )}
          </div>
        ),
      },
      {
        key: 'type',
        label: t(($) => $.columns.type),
        cell: (row) => (row.type ? t(TYPE_LABEL[row.type]) : '—'),
      },
      {
        key: 'status',
        label: t(($) => $.columns.status),
        cell: (row) =>
          row.status ? (
            <StatusBadge status={STATUS_VARIANT[row.status]} label={t(STATUS_LABEL[row.status])} />
          ) : (
            '—'
          ),
      },
      {
        key: 'phone',
        label: t(($) => $.columns.phone),
        cell: (row) =>
          row.primary_phone ?? (
            <span className="text-muted-foreground">{t(($) => $.row.noPhone)}</span>
          ),
      },
      {
        key: 'email',
        label: t(($) => $.columns.email),
        cell: (row) =>
          row.primary_email ?? (
            <span className="text-muted-foreground">{t(($) => $.row.noEmail)}</span>
          ),
      },
    ],
    [t],
  );

  return (
    <div className="flex flex-col gap-4 p-4 md:p-6">
      <header className="flex flex-col gap-1">
        <h1 className="text-xl font-semibold md:text-2xl">{t(($) => $.workspace.title)}</h1>
        <p className="text-sm text-muted-foreground">{t(($) => $.workspace.subtitle)}</p>
      </header>

      {/* Stacks on mobile, two-up on tablet, four-up on desktop. */}
      <section className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <QuickStatCard
          icon={Users}
          title={t(($) => $.kpi.total)}
          value={stats.total}
          active={status === 'all'}
          onClick={() => { setStatus('all'); setPage(1); }}
        />
        <QuickStatCard
          icon={UserCheck}
          title={t(($) => $.kpi.active)}
          value={stats.active}
          active={status === 'active'}
          onClick={() => { setStatus('active'); setPage(1); }}
        />
        <QuickStatCard
          icon={Sparkles}
          title={t(($) => $.kpi.prospects)}
          value={stats.prospects}
          active={status === 'prospect'}
          onClick={() => { setStatus('prospect'); setPage(1); }}
        />
        <QuickStatCard
          icon={Archive}
          title={t(($) => $.kpi.archived)}
          value={stats.archived}
          active={status === 'archived'}
          onClick={() => { setStatus('archived'); setPage(1); }}
        />
      </section>

      <SmartToolbar
        primaryAction={
          // Hidden entirely when the role cannot create — never rendered disabled.
          can('crm.customers.create')
            ? {
                label: t(($) => $.toolbar.newCustomer),
                onClick: () => undefined,
                icon: Plus,
              }
            : undefined
        }
        onRefresh={() => void refetch()}
        isFetching={isFetching}
        refreshLabel={t(($) => $.toolbar.refresh)}
        viewControls={
          <div className="flex flex-wrap items-center gap-2">
            <input
              type="search"
              value={search}
              onChange={(e) => {
                setSearch(e.target.value);
                setPage(1);
              }}
              placeholder={t(($) => $.toolbar.searchPlaceholder)}
              aria-label={t(($) => $.toolbar.searchPlaceholder)}
              className="h-9 w-full min-w-[12rem] rounded-md border bg-background px-3 text-sm sm:w-64"
            />

            <select
              value={status}
              onChange={(e) => {
                setStatus(e.target.value as CrmCustomerStatus | 'all');
                setPage(1);
              }}
              aria-label={t(($) => $.filters.status)}
              className="h-9 rounded-md border bg-background px-2 text-sm"
            >
              {STATUS_FILTERS.map((f) => (
                <option key={f.value} value={f.value}>
                  {t(f.label)}
                </option>
              ))}
            </select>

            <select
              value={type}
              onChange={(e) => {
                setType(e.target.value as CrmCustomerType | 'all');
                setPage(1);
              }}
              aria-label={t(($) => $.filters.type)}
              className="h-9 rounded-md border bg-background px-2 text-sm"
            >
              {TYPE_FILTERS.map((f) => (
                <option key={f.value} value={f.value}>
                  {t(f.label)}
                </option>
              ))}
            </select>
          </div>
        }
      />

      <UniversalDataGrid<CrmCustomer>
        data={rows}
        columns={columns}
        rowId={(row) => row.id}
        loading={isLoading}
        error={isError}
        pagination={
          meta
            ? {
                meta: {
                  page: meta.page,
                  perPage: meta.per_page,
                  total: meta.total,
                  lastPage: meta.last_page,
                },
                onPageChange: setPage,
              }
            : undefined
        }
        emptyState={
          <div className="p-8 text-center">
            <p className="font-medium">{t(($) => $.empty.title)}</p>
            <p className="mt-1 text-sm text-muted-foreground">
              {isFiltered ? t(($) => $.empty.filtered) : t(($) => $.empty.body)}
            </p>
          </div>
        }
        errorState={
          <div className="p-8 text-center">
            <p className="font-medium">{t(($) => $.error.title)}</p>
            <p className="mt-1 text-sm text-muted-foreground">{t(($) => $.error.body)}</p>
          </div>
        }
      />
    </div>
  );
}

export default CrmCustomersWorkspacePage;

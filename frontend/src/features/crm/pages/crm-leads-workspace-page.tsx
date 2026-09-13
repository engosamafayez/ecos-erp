import { useMemo, useState } from 'react';
import { Plus } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useSearchParams } from 'react-router-dom';

import { UniversalDataGrid } from '@/components/data-grid/universal-data-grid';
import { SmartToolbar } from '@/components/data-grid/smart-toolbar';
import type { DataGridColumnDef } from '@/components/data-grid/types';
import { Badge } from '@/components/ui/badge';
import { usePermission } from '@/features/authorization';
import { CrmLeadDrawer } from '@/features/crm/components/crm-lead-drawer';
import { CrmLeadFormDrawer } from '@/features/crm/components/crm-lead-form-drawer';
import { useCrmLeadsQuery } from '@/features/crm/hooks/use-crm-leads';
import type { CrmLead, CrmLeadStatus } from '@/features/crm/types/crm-lead';
import type enCrm from '@/i18n/locales/en/crm.json';

/**
 * CRM Lead Workspace (CRM-01 Task 1).
 *
 * Consumes the canonical `Crm\Sales` Lead contract (`/crm/sales/leads`) — not
 * CustomerEngagement's `/customer-engagement/leads` (a distinct, conversation-
 * sourced prospect, its own page). See CRM-01 report, "Lead authority" and
 * "Duplicate engine check".
 */

type CrmLabel = ($: typeof enCrm) => string;

const PER_PAGE = 25;

const STATUS_LABEL: Record<CrmLeadStatus, CrmLabel> = {
  new: ($) => $.leads.status.new,
  contacted: ($) => $.leads.status.contacted,
  qualified: ($) => $.leads.status.qualified,
  unqualified: ($) => $.leads.status.unqualified,
  converted: ($) => $.leads.status.converted,
};

const STATUS_FILTERS: { value: CrmLeadStatus | 'all'; label: CrmLabel }[] = [
  { value: 'all', label: ($) => $.leads.filters.allStatuses },
  { value: 'new', label: ($) => $.leads.status.new },
  { value: 'contacted', label: ($) => $.leads.status.contacted },
  { value: 'qualified', label: ($) => $.leads.status.qualified },
  { value: 'unqualified', label: ($) => $.leads.status.unqualified },
  { value: 'converted', label: ($) => $.leads.status.converted },
];

export function CrmLeadsWorkspacePage() {
  const { t } = useTranslation('crm');
  const { can } = usePermission();

  const [search, setSearch] = useState('');
  const [status, setStatus] = useState<CrmLeadStatus | 'all'>('all');
  const [page, setPage] = useState(1);
  // CRM-01 Task 2 — `?open=<id>` lets the Pipeline board's "View Lead" link (and
  // any other deep link) open a specific lead here, same mechanism Task 1 added
  // for the Customers workspace.
  const [searchParams] = useSearchParams();
  const [detailsId, setDetailsId] = useState<string | null>(() => searchParams.get('open'));
  const [formOpen, setFormOpen] = useState(false);

  const params = useMemo(
    () => ({ q: search.trim() || undefined, status, page, per_page: PER_PAGE }),
    [search, status, page],
  );

  const { data, isLoading, isError, isFetching, refetch } = useCrmLeadsQuery(params);
  const rows = useMemo(() => data?.data ?? [], [data]);
  const meta = data?.meta;
  const isFiltered = Boolean(params.q || (params.status && params.status !== 'all'));

  const columns: DataGridColumnDef<CrmLead>[] = useMemo(
    () => [
      {
        key: 'name',
        label: t(($) => $.leads.columns.name),
        cell: (row) => (
          <div className="flex flex-col">
            <span className="font-medium">{row.name}</span>
            {row.company_name && (
              <span className="text-xs text-muted-foreground">{row.company_name}</span>
            )}
          </div>
        ),
      },
      {
        key: 'status',
        label: t(($) => $.leads.columns.status),
        cell: (row) => <Badge variant="outline">{t(STATUS_LABEL[row.status])}</Badge>,
      },
      {
        key: 'source',
        label: t(($) => $.leads.columns.source),
        cell: (row) => row.source ?? <span className="text-muted-foreground">—</span>,
      },
      {
        key: 'phone',
        label: t(($) => $.leads.columns.phone),
        cell: (row) => row.phone ?? <span className="text-muted-foreground">—</span>,
      },
      {
        key: 'email',
        label: t(($) => $.leads.columns.email),
        cell: (row) => row.email ?? <span className="text-muted-foreground">—</span>,
      },
      {
        key: 'score',
        label: t(($) => $.leads.columns.score),
        align: 'end',
        cell: (row) => <span className="tabular-nums">{row.score ?? '—'}</span>,
      },
    ],
    [t],
  );

  return (
    <div className="flex flex-col gap-4 p-4 sm:p-6">
      <div>
        <h1 className="text-xl font-semibold">{t(($) => $.leads.title)}</h1>
        <p className="text-sm text-muted-foreground">{t(($) => $.leads.subtitle)}</p>
      </div>

      <section className="text-sm text-muted-foreground">
        {meta ? (
          <span>
            {isFiltered
              ? t(($) => $.summary.countFiltered, { count: meta.total })
              : t(($) => $.summary.countAll, { count: meta.total })}
          </span>
        ) : null}
      </section>

      <SmartToolbar
        primaryAction={
          can('crm.sales.manage')
            ? { label: t(($) => $.leads.toolbar.newLead), onClick: () => setFormOpen(true), icon: Plus }
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
              placeholder={t(($) => $.leads.toolbar.searchPlaceholder)}
              aria-label={t(($) => $.leads.toolbar.searchPlaceholder)}
              className="h-9 w-full min-w-[12rem] rounded-md border bg-background px-3 text-sm sm:w-64"
            />
            <select
              value={status}
              onChange={(e) => {
                setStatus(e.target.value as CrmLeadStatus | 'all');
                setPage(1);
              }}
              aria-label={t(($) => $.leads.columns.status)}
              className="h-9 rounded-md border bg-background px-2 text-sm"
            >
              {STATUS_FILTERS.map((f) => (
                <option key={f.value} value={f.value}>
                  {t(f.label)}
                </option>
              ))}
            </select>
          </div>
        }
      />

      <UniversalDataGrid<CrmLead>
        data={rows}
        columns={columns}
        rowId={(row) => row.id}
        onRowClick={(row) => setDetailsId(row.id)}
        loading={isLoading}
        error={isError}
        pagination={
          meta
            ? {
                meta: { page: meta.page, perPage: meta.per_page, total: meta.total, lastPage: meta.last_page },
                onPageChange: setPage,
              }
            : undefined
        }
        emptyState={
          <div className="p-8 text-center">
            <p className="font-medium">{t(($) => $.leads.empty.title)}</p>
            <p className="mt-1 text-sm text-muted-foreground">{t(($) => $.leads.empty.body)}</p>
          </div>
        }
        errorState={
          <div className="p-8 text-center">
            <p className="font-medium">{t(($) => $.error.title)}</p>
            <p className="mt-1 text-sm text-muted-foreground">{t(($) => $.error.body)}</p>
          </div>
        }
      />

      <CrmLeadDrawer leadId={detailsId} open={detailsId !== null} onOpenChange={(next) => !next && setDetailsId(null)} />
      <CrmLeadFormDrawer open={formOpen} onOpenChange={setFormOpen} onCreated={() => void refetch()} />
    </div>
  );
}

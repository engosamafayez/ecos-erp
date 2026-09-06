import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { UniversalDataGrid } from '@/components/data-grid/universal-data-grid';
import { SmartToolbar } from '@/components/data-grid/smart-toolbar';
import type { DataGridColumnDef } from '@/components/data-grid/types';
import { StatusBadge } from '@/components/crud/status-badge';
import type { StatusVariant } from '@/components/crud/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { usePermission } from '@/features/authorization';
import { CrmCustomerDrawer } from '@/features/crm/components/crm-customer-drawer';
import { useAssignSalesOwner, useCrmPortfolioQuery } from '@/features/crm/hooks/use-crm-portfolio';
import type {
  CrmFollowUpQueue,
  CrmPortfolioQuery,
  CrmPortfolioRow,
  CrmTaskPriority,
} from '@/features/crm/types/crm-customer';
import type enCrm from '@/i18n/locales/en/crm.json';

/**
 * CRM Portfolio (TASK-ECOS-CRM-CUSTOMER-PORTFOLIO-AND-FOLLOWUP-003).
 *
 * A read model over canonical Customers + CRM follow-up/ownership context —
 * not a second customer list. Every fact shown (owner, follow-up, blocked,
 * balance, commerce, engagement) is composed server-side; nothing here is
 * recomputed.
 */

type CrmLabel = ($: typeof enCrm) => string;

const PER_PAGE = 25;

function fmtMoney(n: number | null | undefined) {
  return typeof n === 'number'
    ? n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
    : '—';
}

const QUEUE_LABEL: Record<CrmFollowUpQueue, CrmLabel> = {
  overdue: ($) => $.portfolio.queue.overdue,
  due_today: ($) => $.portfolio.queue.dueToday,
  upcoming: ($) => $.portfolio.queue.upcoming,
  unscheduled: ($) => $.portfolio.queue.unscheduled,
};

const QUEUE_VARIANT: Record<CrmFollowUpQueue, StatusVariant> = {
  overdue: 'inactive',
  due_today: 'pending',
  upcoming: 'active',
  unscheduled: 'inactive',
};

const QUEUE_FILTERS: { value: CrmFollowUpQueue | 'all'; label: CrmLabel }[] = [
  { value: 'all', label: ($) => $.portfolio.filters.allQueues },
  { value: 'overdue', label: ($) => $.portfolio.queue.overdue },
  { value: 'due_today', label: ($) => $.portfolio.queue.dueToday },
  { value: 'upcoming', label: ($) => $.portfolio.queue.upcoming },
  { value: 'unscheduled', label: ($) => $.portfolio.queue.unscheduled },
];

const PRIORITY_FILTERS: { value: CrmTaskPriority | 'all'; label: CrmLabel }[] = [
  { value: 'all', label: ($) => $.portfolio.filters.allPriorities },
  { value: 'urgent', label: ($) => $.portfolio.priority.urgent },
  { value: 'high', label: ($) => $.portfolio.priority.high },
  { value: 'normal', label: ($) => $.portfolio.priority.normal },
  { value: 'low', label: ($) => $.portfolio.priority.low },
];

function OwnerCell({ row }: { row: CrmPortfolioRow }) {
  const { t } = useTranslation('crm');
  const { can } = usePermission();
  const [draft, setDraft] = useState('');
  const assign = useAssignSalesOwner();
  // Assigning an owner is a customer-record update — same authority as the
  // Customer 360 edit action (§6/§22: map only the permission the mutation
  // actually consumes, never invent a broader one).
  const canAssign = can('crm.customers.update');

  if (!row.is_unassigned) {
    return (
      <div className="flex flex-col">
        <span className="font-medium">{row.sales_owner_name}</span>
        {canAssign && (
          <button
            type="button"
            className="w-fit text-left text-xs text-muted-foreground underline-offset-2 hover:underline"
            onClick={() => assign.mutate({ customerId: row.id, salesOwnerId: null })}
            disabled={assign.isPending}
          >
            {t(($) => $.portfolio.owner.unassign)}
          </button>
        )}
      </div>
    );
  }

  if (!canAssign) {
    return <Badge variant="outline">{t(($) => $.portfolio.owner.unassigned)}</Badge>;
  }

  return (
    <div className="flex items-center gap-1">
      <Badge variant="outline">{t(($) => $.portfolio.owner.unassigned)}</Badge>
      <Input
        value={draft}
        onChange={(e) => setDraft(e.target.value)}
        placeholder={t(($) => $.portfolio.owner.idPlaceholder)}
        className="h-7 w-16 text-xs"
        aria-label={t(($) => $.portfolio.owner.idPlaceholder)}
      />
      <Button
        size="sm"
        variant="outline"
        className="h-7 px-2 text-xs"
        disabled={!draft.trim() || assign.isPending}
        onClick={() => assign.mutate({ customerId: row.id, salesOwnerId: draft.trim() })}
      >
        {t(($) => $.portfolio.owner.assign)}
      </Button>
    </div>
  );
}

export function CrmPortfolioPage() {
  const { t } = useTranslation('crm');

  const [search, setSearch] = useState('');
  const [unassignedOnly, setUnassignedOnly] = useState(false);
  const [blocked, setBlocked] = useState<'all' | 'true' | 'false'>('all');
  const [queue, setQueue] = useState<CrmFollowUpQueue | 'all'>('all');
  const [priority, setPriority] = useState<CrmTaskPriority | 'all'>('all');
  const [page, setPage] = useState(1);
  const [detailsId, setDetailsId] = useState<string | null>(null);

  const params: CrmPortfolioQuery = useMemo(
    () => ({
      search: search.trim() || undefined,
      unassigned: unassignedOnly || undefined,
      blocked: blocked === 'all' ? undefined : blocked === 'true',
      queue: queue === 'all' ? undefined : queue,
      priority: priority === 'all' ? undefined : priority,
      page,
      per_page: PER_PAGE,
    }),
    [search, unassignedOnly, blocked, queue, priority, page],
  );

  const { data, isLoading, isError, isFetching, refetch } = useCrmPortfolioQuery(params);

  const rows = useMemo(() => data?.data ?? [], [data]);
  const meta = data?.meta;
  const isFiltered = Boolean(
    params.search || params.unassigned || params.blocked !== undefined || params.queue || params.priority,
  );

  const columns: DataGridColumnDef<CrmPortfolioRow>[] = useMemo(
    () => [
      {
        key: 'name',
        label: t(($) => $.portfolio.columns.customer),
        cell: (row) => (
          <div className="flex flex-col">
            <span className="font-medium">{row.name}</span>
            <span className="font-mono text-xs text-muted-foreground">{row.code ?? '—'}</span>
          </div>
        ),
      },
      {
        key: 'owner',
        label: t(($) => $.portfolio.columns.owner),
        cell: (row) => <OwnerCell row={row} />,
      },
      {
        key: 'follow_up',
        label: t(($) => $.portfolio.columns.nextFollowUp),
        cell: (row) => {
          const next = row.crm.next_follow_up;
          if (!next) {
            return <span className="text-muted-foreground">{t(($) => $.portfolio.followUp.none)}</span>;
          }

          return (
            <div className="flex flex-col gap-1">
              <span className="text-sm">{next.title}</span>
              <div className="flex items-center gap-1">
                {next.queue && (
                  <StatusBadge
                    status={QUEUE_VARIANT[next.queue]}
                    label={t(QUEUE_LABEL[next.queue])}
                    className="text-[10px]"
                  />
                )}
                {next.due_at && (
                  <span className="text-xs text-muted-foreground">
                    {new Date(next.due_at).toLocaleString()}
                  </span>
                )}
              </div>
            </div>
          );
        },
      },
      {
        key: 'open_follow_ups_count',
        label: t(($) => $.portfolio.columns.openCount),
        align: 'end',
        cell: (row) => <span className="tabular-nums">{row.crm.open_follow_ups_count}</span>,
      },
      {
        key: 'blocked',
        label: t(($) => $.portfolio.columns.blocked),
        cell: (row) =>
          row.blocked.is_blocked ? (
            <StatusBadge status="inactive" label={t(($) => $.status.blocked)} />
          ) : (
            <span className="text-muted-foreground">—</span>
          ),
      },
      {
        key: 'balance',
        label: t(($) => $.portfolio.columns.balance),
        align: 'end',
        cell: (row) => <span className="tabular-nums">{fmtMoney(row.finance.balance)}</span>,
      },
      {
        key: 'orders',
        label: t(($) => $.portfolio.columns.orders),
        align: 'end',
        cell: (row) => <span className="tabular-nums">{row.commerce.orders_count}</span>,
      },
      {
        key: 'last_conversation_at',
        label: t(($) => $.portfolio.columns.lastEngagement),
        cell: (row) =>
          row.engagement.last_conversation_at ? (
            <span className="text-xs tabular-nums">
              {new Date(row.engagement.last_conversation_at).toLocaleDateString()}
            </span>
          ) : (
            <span className="text-muted-foreground">—</span>
          ),
      },
    ],
    [t],
  );

  return (
    <div className="flex flex-col gap-4 p-4 md:p-6">
      <header className="flex flex-col gap-1">
        <h1 className="text-xl font-semibold md:text-2xl">{t(($) => $.portfolio.title)}</h1>
        <p className="text-sm text-muted-foreground">{t(($) => $.portfolio.subtitle)}</p>
      </header>

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

            <label className="flex h-9 items-center gap-1.5 rounded-md border bg-background px-2 text-sm">
              <input
                type="checkbox"
                checked={unassignedOnly}
                onChange={(e) => {
                  setUnassignedOnly(e.target.checked);
                  setPage(1);
                }}
              />
              {t(($) => $.portfolio.filters.unassignedOnly)}
            </label>

            <select
              value={blocked}
              onChange={(e) => {
                setBlocked(e.target.value as typeof blocked);
                setPage(1);
              }}
              aria-label={t(($) => $.portfolio.filters.blockedState)}
              className="h-9 rounded-md border bg-background px-2 text-sm"
            >
              <option value="all">{t(($) => $.portfolio.filters.allBlocked)}</option>
              <option value="true">{t(($) => $.status.blocked)}</option>
              <option value="false">{t(($) => $.portfolio.filters.notBlocked)}</option>
            </select>

            <select
              value={queue}
              onChange={(e) => {
                setQueue(e.target.value as typeof queue);
                setPage(1);
              }}
              aria-label={t(($) => $.portfolio.filters.dueState)}
              className="h-9 rounded-md border bg-background px-2 text-sm"
            >
              {QUEUE_FILTERS.map((f) => (
                <option key={f.value} value={f.value}>
                  {t(f.label)}
                </option>
              ))}
            </select>

            <select
              value={priority}
              onChange={(e) => {
                setPriority(e.target.value as typeof priority);
                setPage(1);
              }}
              aria-label={t(($) => $.portfolio.filters.priority)}
              className="h-9 rounded-md border bg-background px-2 text-sm"
            >
              {PRIORITY_FILTERS.map((f) => (
                <option key={f.value} value={f.value}>
                  {t(f.label)}
                </option>
              ))}
            </select>
          </div>
        }
      />

      <UniversalDataGrid<CrmPortfolioRow>
        data={rows}
        columns={columns}
        rowId={(row) => row.id}
        onRowClick={(row) => setDetailsId(row.id)}
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
            <p className="font-medium">{t(($) => $.portfolio.empty.title)}</p>
            <p className="mt-1 text-sm text-muted-foreground">
              {isFiltered ? t(($) => $.empty.filtered) : t(($) => $.portfolio.empty.body)}
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

      <CrmCustomerDrawer
        customerId={detailsId}
        open={detailsId !== null}
        onOpenChange={(next) => !next && setDetailsId(null)}
      />
    </div>
  );
}

export default CrmPortfolioPage;

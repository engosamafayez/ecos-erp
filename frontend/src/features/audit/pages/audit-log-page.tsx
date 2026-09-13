import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { EmptyState, EntityToolbar } from '@/components/crud';
import type { DataGridColumnDef, GridPaginationConfig } from '@/components/data-grid';
import { UniversalDataGrid } from '@/components/data-grid';
import { Card, CardContent } from '@/components/ui/card';
import { EcosCombobox } from '@/components/ui/ecos-combobox';
import { WorkspaceHeader } from '@/components/workspace';
import { useUsersQuery } from '@/features/iam-admin/hooks/use-users';
import { AuditLogDetailDrawer } from '@/features/audit/components/audit-log-detail-drawer';
import { useAuditLogsQuery } from '@/features/audit/hooks/use-audit-logs';
import type { AuditLogEntry } from '@/features/audit/types/audit-log';

const PER_PAGE = 25;

/**
 * CORE-02 Task 2 §5 — the canonical Audit Activity workspace. Reads only from the central
 * AuditQueryService/audit_logs authority (via `/api/audit`); company scope and the
 * system-actor exception are enforced entirely server-side, not re-derived here.
 */
export function AuditLogPage() {
  const { t } = useTranslation('audit');

  const [actorId, setActorId] = useState('');
  const [actorSearch, setActorSearch] = useState('');
  const [action, setAction] = useState('');
  const [entityType, setEntityType] = useState('');
  const [dateFrom, setDateFrom] = useState('');
  const [dateTo, setDateTo] = useState('');
  const [page, setPage] = useState(1);
  const [selected, setSelected] = useState<AuditLogEntry | null>(null);
  const [detailOpen, setDetailOpen] = useState(false);

  const actorOptions = useUsersQuery({ q: actorSearch || undefined, per_page: 20 });

  const params = useMemo(
    () => ({
      user_id: actorId ? Number(actorId) : undefined,
      action: action.trim() || undefined,
      entity_type: entityType.trim() || undefined,
      date_from: dateFrom || undefined,
      date_to: dateTo || undefined,
      page,
      per_page: PER_PAGE,
    }),
    [actorId, action, entityType, dateFrom, dateTo, page],
  );

  const { data, isLoading, isError, isFetching, refetch } = useAuditLogsQuery(params);

  const items = data?.items ?? [];
  const meta = data?.meta;

  const openDetail = (entry: AuditLogEntry) => {
    setSelected(entry);
    setDetailOpen(true);
  };

  const columns: DataGridColumnDef<AuditLogEntry>[] = [
    {
      key: 'occurred_at',
      label: t($ => $.columns.date),
      cell: (row) => (row.occurred_at ? new Date(row.occurred_at).toLocaleString() : '—'),
    },
    {
      key: 'actor',
      label: t($ => $.columns.actor),
      cardRole: 'title',
      cell: (row) => (
        <span className="font-medium">{row.actor ? row.actor.name : t($ => $.systemActor)}</span>
      ),
    },
    {
      key: 'action',
      label: t($ => $.columns.action),
      cardRole: 'subtitle',
      cell: (row) => <span className="font-mono text-xs">{row.action}</span>,
    },
    {
      key: 'entity',
      label: t($ => $.columns.entity),
      cell: (row) => (
        <span className="text-muted-foreground text-xs">
          {row.entity_type} · {row.entity_id.slice(0, 8)}
        </span>
      ),
    },
  ];

  const pagination: GridPaginationConfig | undefined = meta
    ? {
        meta: { page: meta.current_page, perPage: meta.per_page, total: meta.total, lastPage: meta.last_page },
        onPageChange: setPage,
      }
    : undefined;

  return (
    <div className="flex flex-col gap-6 p-4 sm:p-6">
      <WorkspaceHeader title={t($ => $.title)} description={t($ => $.subtitle)} />

      <Card>
        <CardContent className="flex flex-col gap-4 pt-6">
          <EntityToolbar
            searchPlaceholder={t($ => $.filters.action)}
            onSearchChange={() => undefined}
            onRefresh={() => void refetch()}
            isRefreshing={isFetching}
            onClearFilters={() => {
              setActorId('');
              setActorSearch('');
              setAction('');
              setEntityType('');
              setDateFrom('');
              setDateTo('');
              setPage(1);
            }}
            filterPanel={
              <div className="flex flex-col gap-3">
                <div className="flex flex-col gap-1.5">
                  <span className="text-sm font-medium">{t($ => $.filters.actor)}</span>
                  <EcosCombobox
                    value={actorId || null}
                    onChange={(v) => { setActorId(v); setPage(1); }}
                    onSearchChange={setActorSearch}
                    filterClientSide={false}
                    placeholder={t($ => $.filters.allActors)}
                    options={(actorOptions.data?.data ?? []).map((u) => ({ value: String(u.id), label: u.name }))}
                    loading={actorOptions.isLoading}
                  />
                </div>

                <div className="flex flex-col gap-1.5">
                  <span className="text-sm font-medium">{t($ => $.filters.action)}</span>
                  <input
                    value={action}
                    onChange={(e) => { setAction(e.target.value); setPage(1); }}
                    placeholder={t($ => $.filters.actionPlaceholder)}
                    className="border-input h-9 rounded-md border bg-transparent px-3 text-sm shadow-xs"
                  />
                </div>

                <div className="flex flex-col gap-1.5">
                  <span className="text-sm font-medium">{t($ => $.filters.entityType)}</span>
                  <input
                    value={entityType}
                    onChange={(e) => { setEntityType(e.target.value); setPage(1); }}
                    placeholder={t($ => $.filters.entityTypePlaceholder)}
                    className="border-input h-9 rounded-md border bg-transparent px-3 text-sm shadow-xs"
                  />
                </div>

                <div className="flex flex-col gap-1.5">
                  <span className="text-sm font-medium">{t($ => $.filters.dateFrom)}</span>
                  <input
                    type="date"
                    value={dateFrom}
                    onChange={(e) => { setDateFrom(e.target.value); setPage(1); }}
                    className="border-input h-9 rounded-md border bg-transparent px-3 text-sm shadow-xs"
                  />
                </div>

                <div className="flex flex-col gap-1.5">
                  <span className="text-sm font-medium">{t($ => $.filters.dateTo)}</span>
                  <input
                    type="date"
                    value={dateTo}
                    onChange={(e) => { setDateTo(e.target.value); setPage(1); }}
                    className="border-input h-9 rounded-md border bg-transparent px-3 text-sm shadow-xs"
                  />
                </div>
              </div>
            }
          />

          <UniversalDataGrid<AuditLogEntry>
            data={items}
            columns={columns}
            rowId={(row) => row.id}
            loading={isLoading}
            error={isError}
            pagination={pagination}
            onRowClick={openDetail}
            emptyState={<EmptyState title={t($ => $.empty.title)} description={t($ => $.empty.description)} />}
          />
        </CardContent>
      </Card>

      <AuditLogDetailDrawer entry={selected} open={detailOpen} onOpenChange={setDetailOpen} />
    </div>
  );
}

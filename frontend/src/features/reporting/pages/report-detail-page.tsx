import { useMemo } from 'react';
import { useParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { FileBarChart, RefreshCw } from 'lucide-react';

import { EmptyState, ErrorState, LoadingState } from '@/components/crud';
import { Card, CardContent } from '@/components/ui/card';
import {
  Table,
  TableBody,
  TableCell,
  TableFooter,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { WorkspaceHeader } from '@/components/workspace';
import { useFormatter, type Formatters } from '@/hooks/use-formatter';
import { ROUTES } from '@/router/routes';
import { ReportForbiddenState } from '../components/report-forbidden-state';
import {
  isForbiddenError,
  useCanRunReport,
  useMetricDictionaryQuery,
  useReportCatalogueEntry,
  useReportExecutionQuery,
} from '../hooks/use-reporting';

/**
 * A single report's execution surface (TASK-ECOS-REPORTING-V1-USER-VISIBLE-
 * NAVIGATION-CLOSURE-010 §4/§5). Renders exactly the canonical `ReportResult`
 * contract — KPIs, rows, totals, period — with no client-side recomputation of
 * any metric. Rows have no fixed schema across the 35 reports, so columns are
 * derived from the result's own keys rather than a per-report layout.
 */

function humanize(key: string): string {
  return key
    .replace(/_/g, ' ')
    .replace(/\b\w/g, (c) => c.toUpperCase());
}

function formatScalar(value: unknown, fmt: Formatters): string {
  if (value === null || value === undefined) return '—';
  if (typeof value === 'number') return fmt.number(value, Number.isInteger(value) ? 0 : 2);
  if (typeof value === 'boolean') return value ? '✓' : '—';
  if (typeof value === 'string') return value;

  return JSON.stringify(value);
}

export function ReportDetailPage() {
  const { reportId } = useParams<{ reportId: string }>();
  const { t } = useTranslation('reporting');
  const fmt = useFormatter();

  const { entry, isPending: cataloguePending, isError: catalogueError, refetch: refetchCatalogue } =
    useReportCatalogueEntry(reportId);
  const canRun = useCanRunReport(entry);
  const metricDictionary = useMetricDictionaryQuery();
  const execution = useReportExecutionQuery(reportId, canRun);

  const metricName = useMemo(() => {
    const map = new Map<string, string>();
    (metricDictionary.data?.metrics ?? []).forEach((m) => map.set(m.id, m.name));
    return (id: string) => map.get(id) ?? id;
  }, [metricDictionary.data]);

  const result = execution.data;

  const columns = useMemo(() => {
    const seen = new Set<string>();
    const cols: string[] = [];
    (result?.rows ?? []).forEach((row) => {
      Object.keys(row).forEach((key) => {
        if (!seen.has(key)) {
          seen.add(key);
          cols.push(key);
        }
      });
    });
    return cols;
  }, [result]);

  const breadcrumbs = [{ label: t(($) => $.title), to: ROUTES.reports }];

  // ── Catalogue not yet resolved (loading / failed / unknown id) ────────────
  if (cataloguePending) {
    return (
      <div className="flex flex-col">
        <WorkspaceHeader breadcrumbs={breadcrumbs} title={t(($) => $.detail.loadingTitle)} />
        <div className="p-4 sm:p-6">
          <LoadingState />
        </div>
      </div>
    );
  }

  if (catalogueError) {
    return (
      <div className="flex flex-col">
        <WorkspaceHeader breadcrumbs={breadcrumbs} title={t(($) => $.detail.loadingTitle)} />
        <div className="p-4 sm:p-6">
          <ErrorState onRetry={() => void refetchCatalogue()} />
        </div>
      </div>
    );
  }

  if (!entry) {
    return (
      <div className="flex flex-col">
        <WorkspaceHeader breadcrumbs={breadcrumbs} title={t(($) => $.detail.notFoundTitle)} />
        <div className="p-4 sm:p-6">
          <EmptyState icon={FileBarChart} title={t(($) => $.detail.notFoundTitle)} description={t(($) => $.detail.notFoundDescription)} />
        </div>
      </div>
    );
  }

  return (
    <div className="flex flex-col">
      <WorkspaceHeader
        breadcrumbs={breadcrumbs}
        title={entry.name}
        description={entry.notes ?? undefined}
        secondaryActions={[
          {
            key: 'refresh',
            label: t(($) => $.detail.refresh),
            icon: RefreshCw,
            onClick: () => void execution.refetch(),
            disabled: !canRun || execution.isFetching,
          },
        ]}
      />

      <div className="flex flex-col gap-6 p-4 sm:p-6">
        {!canRun || (execution.isError && isForbiddenError(execution.error)) ? (
          <ReportForbiddenState
            title={t(($) => $.detail.forbiddenTitle)}
            description={t(($) => $.detail.forbiddenDescription, { permission: entry.permission })}
          />
        ) : execution.isPending ? (
          <LoadingState />
        ) : execution.isError ? (
          <ErrorState onRetry={() => void execution.refetch()} />
        ) : !result || (Object.keys(result.kpis).length === 0 && result.rows.length === 0) ? (
          <EmptyState icon={FileBarChart} title={t(($) => $.detail.emptyTitle)} description={t(($) => $.detail.emptyDescription)} />
        ) : (
          <>
            {(result.period.from || result.period.to) ? (
              <p className="text-muted-foreground text-xs">
                {t(($) => $.detail.period, {
                  from: result.period.from ?? '—',
                  to: result.period.to ?? '—',
                })}
              </p>
            ) : null}

            {Object.keys(result.kpis).length > 0 ? (
              <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {Object.entries(result.kpis).map(([id, value]) => (
                  <Card key={id}>
                    <CardContent className="pt-6">
                      <p className="text-muted-foreground text-xs">{metricName(id)}</p>
                      <p className="mt-1 text-2xl font-semibold">{formatScalar(value, fmt)}</p>
                    </CardContent>
                  </Card>
                ))}
              </div>
            ) : null}

            {result.rows.length > 0 ? (
              <div className="overflow-x-auto rounded-md border">
                <Table>
                  <TableHeader>
                    <TableRow>
                      {columns.map((col) => (
                        <TableHead key={col}>{humanize(col)}</TableHead>
                      ))}
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {result.rows.map((row, i) => (
                      <TableRow key={i}>
                        {columns.map((col) => (
                          <TableCell key={col}>{formatScalar(row[col], fmt)}</TableCell>
                        ))}
                      </TableRow>
                    ))}
                  </TableBody>
                  {Object.keys(result.totals).length > 0 ? (
                    <TableFooter>
                      <TableRow>
                        {columns.map((col) => (
                          <TableCell key={col} className="font-semibold">
                            {col in result.totals ? formatScalar(result.totals[col], fmt) : ''}
                          </TableCell>
                        ))}
                      </TableRow>
                    </TableFooter>
                  ) : null}
                </Table>
              </div>
            ) : null}
          </>
        )}
      </div>
    </div>
  );
}

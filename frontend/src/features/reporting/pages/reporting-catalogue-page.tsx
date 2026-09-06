import { useMemo, useState } from 'react';
import { useNavigate, generatePath } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { ChevronRight, FileBarChart, Lock } from 'lucide-react';

import { EmptyState, ErrorState, LoadingState, SearchInput } from '@/components/crud';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { WorkspaceHeader } from '@/components/workspace';
import { usePermission } from '@/features/authorization';
import { ROUTES } from '@/router/routes';
import { useReportCatalogueQuery } from '../hooks/use-reporting';
import type { ReportCatalogueEntry } from '../types';

/**
 * The Reporting V1 landing surface (TASK-ECOS-REPORTING-V1-USER-VISIBLE-
 * NAVIGATION-CLOSURE-010 §4). One page for all 10 categories — the catalogue
 * endpoint returns every V1 report to any authenticated user (platform
 * metadata, not business content); execution is what the per-category
 * `reports.<category>.view` permission actually gates, so an unauthorized
 * report is still listed here but shown locked rather than hidden, matching
 * the backend's own "no misleading accessible content" boundary: nothing here
 * claims to be openable when it is not.
 */

/** Declared order of `Modules\Reporting\Domain\Enums\ReportCategory` — not API order. */
const CATEGORY_ORDER = [
  'executive',
  'sales',
  'customers',
  'products',
  'inventory',
  'procurement',
  'preparation',
  'distribution',
  'drivers',
  'finance',
] as const;

export function ReportingCataloguePage() {
  const { t } = useTranslation('reporting');
  const navigate = useNavigate();
  const { can } = usePermission();
  const [search, setSearch] = useState('');

  const query = useReportCatalogueQuery();

  const categoryLabel = useMemo<Record<string, string>>(
    () => ({
      executive: t(($) => $.categories.executive),
      sales: t(($) => $.categories.sales),
      customers: t(($) => $.categories.customers),
      products: t(($) => $.categories.products),
      inventory: t(($) => $.categories.inventory),
      procurement: t(($) => $.categories.procurement),
      preparation: t(($) => $.categories.preparation),
      distribution: t(($) => $.categories.distribution),
      drivers: t(($) => $.categories.drivers),
      finance: t(($) => $.categories.finance),
    }),
    [t],
  );

  const groups = useMemo(() => {
    const reports = query.data?.reports ?? [];
    const term = search.trim().toLowerCase();
    const filtered = term
      ? reports.filter(
          (r) => r.name.toLowerCase().includes(term) || r.id.toLowerCase().includes(term),
        )
      : reports;

    return CATEGORY_ORDER.map((category) => ({
      category,
      label: categoryLabel[category] ?? category,
      reports: filtered
        .filter((r) => r.category === category)
        .sort((a, b) => a.name.localeCompare(b.name)),
    })).filter((group) => group.reports.length > 0);
  }, [query.data, search, categoryLabel]);

  const openReport = (report: ReportCatalogueEntry) => {
    if (!can(report.permission)) return;
    navigate(generatePath(ROUTES.reportDetail, { reportId: report.id }));
  };

  return (
    <div className="flex flex-col">
      <WorkspaceHeader title={t(($) => $.title)} description={t(($) => $.subtitle)} />

      <div className="flex flex-col gap-6 p-4 sm:p-6">
        <SearchInput onChange={setSearch} placeholder={t(($) => $.searchPlaceholder)} />

        {query.isPending ? (
          <LoadingState />
        ) : query.isError ? (
          <ErrorState
            description={query.error instanceof Error ? query.error.message : undefined}
            onRetry={() => void query.refetch()}
          />
        ) : groups.length === 0 ? (
          <EmptyState icon={FileBarChart} title={t(($) => $.empty.title)} description={t(($) => $.empty.description)} />
        ) : (
          <div className="flex flex-col gap-6">
            {groups.map((group) => (
              <section key={group.category} className="flex flex-col gap-3">
                <h2 className="text-sm font-semibold">{group.label}</h2>
                <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                  {group.reports.map((report) => {
                    const authorized = can(report.permission);

                    return (
                      <Card
                        key={report.id}
                        role={authorized ? 'button' : undefined}
                        tabIndex={authorized ? 0 : undefined}
                        onClick={authorized ? () => openReport(report) : undefined}
                        onKeyDown={
                          authorized
                            ? (e) => {
                                if (e.key === 'Enter' || e.key === ' ') openReport(report);
                              }
                            : undefined
                        }
                        className={
                          authorized
                            ? 'hover:border-primary/50 cursor-pointer transition-colors'
                            : 'opacity-60'
                        }
                      >
                        <CardContent className="flex items-center justify-between gap-2 pt-6">
                          <div className="flex flex-col gap-1">
                            <p className="text-sm font-medium">{report.name}</p>
                            <Badge variant="outline" className="w-fit font-mono text-[10px]">
                              {report.id}
                            </Badge>
                          </div>
                          {authorized ? (
                            <ChevronRight className="text-muted-foreground size-4 shrink-0" />
                          ) : (
                            <span
                              className="text-muted-foreground flex shrink-0 items-center gap-1 text-xs"
                              title={t(($) => $.restrictedTooltip)}
                            >
                              <Lock className="size-3.5" />
                            </span>
                          )}
                        </CardContent>
                      </Card>
                    );
                  })}
                </div>
              </section>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}

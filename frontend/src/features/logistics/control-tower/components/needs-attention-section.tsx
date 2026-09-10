import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { BellRing, CheckCircle2, PackageSearch, Repeat2 } from 'lucide-react';

import { EmptyState, ErrorState } from '@/components/crud';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';

import { SeverityIcon, SourceBadge } from '@/features/logistics/operations/components/operations-badges';
import { ExceptionDrawer } from '@/features/logistics/operations/components/exception-drawer';
import type {
  ExceptionSeverity,
  HealthOverview,
  OperationalAlert,
} from '@/features/logistics/operations/types/operations';
import { useReasonLabel } from '@/features/operations/loading-os/lib/loading-labels';
import type { LoadingSessionOverviewRow } from '@/features/operations/loading-os/types/loading-os';
import { ROUTES } from '@/router/routes';

export const NEEDS_ATTENTION_SECTION_ID = 'control-tower-needs-attention';

type SeverityFilter = ExceptionSeverity | 'all';

/**
 * Live operational exceptions — reuses the EXACT `useAlerts()` data (and the
 * canonical `ExceptionDrawer` for the action surface) that back the
 * now-redirected Alert Center's own Live tab. No second alert engine, no
 * invented business-impact text: only fields `OperationalAlert` actually
 * carries are rendered, per the task's card shape. TASK-ECOS-SHIPPING-OS-
 * REDESIGN-002 §6 adds two real, previously-unused signals alongside it: the
 * operations module's own health headline (`HealthOverview`) and a real
 * preview of loading sessions in the backend-computed `needs_review` bucket.
 */
export function NeedsAttentionSection({
  alerts,
  isLoading,
  isError,
  severityFilter,
  onSeverityFilterChange,
  health,
  loadingNeedsReview,
}: {
  alerts: OperationalAlert[];
  isLoading: boolean;
  isError: boolean;
  severityFilter: SeverityFilter;
  onSeverityFilterChange: (severity: SeverityFilter) => void;
  health: { data: HealthOverview | null; isLoading: boolean; isError: boolean };
  loadingNeedsReview: {
    count: number | null;
    sessions: LoadingSessionOverviewRow[];
    isLoading: boolean;
    isError: boolean;
  };
}) {
  const { t } = useTranslation('control-tower');
  const navigate = useNavigate();
  const reasonLabel = useReasonLabel();
  const [openExceptionId, setOpenExceptionId] = useState<string | null>(null);

  const rows = useMemo(
    () =>
      severityFilter === 'all' ? alerts : alerts.filter((a) => a.severity === severityFilter),
    [alerts, severityFilter],
  );

  const filters: { key: SeverityFilter; label: string; count: number }[] = [
    { key: 'all', label: t(($) => $.needsAttention.severity.all), count: alerts.length },
    {
      key: 'critical',
      label: t(($) => $.needsAttention.severity.critical),
      count: alerts.filter((a) => a.severity === 'critical').length,
    },
    {
      key: 'warning',
      label: t(($) => $.needsAttention.severity.warning),
      count: alerts.filter((a) => a.severity === 'warning').length,
    },
    {
      key: 'info',
      label: t(($) => $.needsAttention.severity.info),
      count: alerts.filter((a) => a.severity === 'info').length,
    },
  ];

  return (
    <section id={NEEDS_ATTENTION_SECTION_ID} className="scroll-mt-4 space-y-3">
      <div>
        <h2 className="text-lg font-semibold">{t(($) => $.needsAttention.title)}</h2>
        <p className="text-sm text-muted-foreground">{t(($) => $.needsAttention.subtitle)}</p>
      </div>

      {health.data ? (
        <div
          className="flex flex-wrap gap-x-5 gap-y-1 rounded-lg border bg-muted/30 px-3 py-2 text-sm"
          data-testid="control-tower-health-headline"
        >
          <span>
            <span className="font-semibold tabular-nums">{health.data.headline.open_exceptions}</span>{' '}
            <span className="text-muted-foreground">{t(($) => $.needsAttention.headline.openExceptions)}</span>
          </span>
          <span>
            <span className="font-semibold tabular-nums">{health.data.headline.unhealthy_pools}</span>{' '}
            <span className="text-muted-foreground">{t(($) => $.needsAttention.headline.unhealthyPools)}</span>
          </span>
          <span>
            <span className="font-semibold tabular-nums">{health.data.headline.overdue_escalations}</span>{' '}
            <span className="text-muted-foreground">{t(($) => $.needsAttention.headline.overdueEscalations)}</span>
          </span>
        </div>
      ) : null}

      <div className="flex flex-wrap items-center gap-1.5">
        {filters.map((f) => (
          <Button
            key={f.key}
            size="sm"
            variant={severityFilter === f.key ? 'secondary' : 'ghost'}
            className="h-8 text-xs"
            onClick={() => onSeverityFilterChange(f.key)}
            data-testid={`needs-attention-filter-${f.key}`}
          >
            {f.label}
            <span className="ms-1.5 tabular-nums opacity-80">{f.count}</span>
          </Button>
        ))}
      </div>

      {isLoading ? (
        <div className="space-y-2">
          <Skeleton className="h-16 w-full" />
          <Skeleton className="h-16 w-full" />
          <Skeleton className="h-16 w-full" />
        </div>
      ) : isError ? (
        <ErrorState title={t(($) => $.needsAttention.errorTitle)} />
      ) : rows.length === 0 ? (
        (() => {
          // Truly quiet (icon + title both say so) only when the UNFILTERED
          // operation itself reports is_quiet — an empty row list caused
          // merely by narrowing to a severity with zero matches must not
          // borrow the "operation is healthy" signal (same contradiction
          // class as task §16: two elements on screen disagreeing).
          const isGenuinelyQuiet = severityFilter === 'all' && Boolean(health.data?.is_quiet);
          return (
            <EmptyState
              icon={isGenuinelyQuiet ? CheckCircle2 : BellRing}
              title={
                isGenuinelyQuiet
                  ? t(($) => $.needsAttention.empty.quietTitle)
                  : t(($) => $.needsAttention.empty.title)
              }
              description={t(($) => $.needsAttention.empty.hint)}
            />
          );
        })()
      ) : (
        <ul className="divide-y overflow-hidden rounded-lg border bg-card" data-testid="needs-attention-list">
          {rows.map((alert) => (
            <li key={alert.exception_id} className="flex items-start gap-3 p-3">
              <SeverityIcon severity={alert.severity} />

              <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-1.5">
                  <span className="text-sm font-medium">{alert.title}</span>
                  <SourceBadge source={alert.source} />
                  {alert.rule ? (
                    <Badge variant="outline" className="text-[10px]">
                      {alert.rule}
                    </Badge>
                  ) : null}
                  {alert.occurrence_count > 1 ? (
                    <Badge variant="outline" className="gap-1 text-[10px]">
                      <Repeat2 className="size-2.5" aria-hidden />
                      {t(($) => $.needsAttention.occurrences, { count: alert.occurrence_count })}
                    </Badge>
                  ) : null}
                  {alert.is_overdue ? (
                    <Badge variant="destructive" className="text-[10px]">
                      {t(($) => $.needsAttention.overdue)}
                    </Badge>
                  ) : null}
                </div>

                <p className="mt-0.5 text-xs text-muted-foreground">
                  {t(($) => $.needsAttention.ageMinutes, { minutes: alert.age_minutes })}
                  {alert.unacknowledged_minutes !== null
                    ? t(($) => $.needsAttention.unacknowledgedFor, {
                        minutes: alert.unacknowledged_minutes,
                      })
                    : t(($) => $.needsAttention.acknowledged)}
                  {alert.escalation_level > 0
                    ? t(($) => $.needsAttention.escalationLevel, { level: alert.escalation_level })
                    : ''}
                </p>
              </div>

              <Button
                size="sm"
                variant="outline"
                className="h-7 shrink-0 text-xs"
                onClick={() => setOpenExceptionId(alert.exception_id)}
                data-testid={`needs-attention-open-${alert.exception_id}`}
              >
                {t(($) => $.needsAttention.open)}
              </Button>
            </li>
          ))}
        </ul>
      )}

      {/* TASK-ECOS-SHIPPING-OS-REDESIGN-002 §6 — a real, backend-computed exception
          bucket (LoadingWorkspaceBucket.NeedsReview), not an invented threshold. Shown
          only when there is something real to show, per §16 (never a decorative zero). */}
      {loadingNeedsReview.count !== null && loadingNeedsReview.count > 0 ? (
        <div className="space-y-2" data-testid="control-tower-loading-needs-review">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <h3 className="text-sm font-semibold">
              {t(($) => $.needsAttention.loadingNeedsReview.title, { count: loadingNeedsReview.count })}
            </h3>
            <Button
              size="sm"
              variant="outline"
              className="h-7 text-xs"
              onClick={() => navigate(`${ROUTES.loadingOsWorkspace}?tab=needs_review`)}
              data-testid="control-tower-loading-needs-review-open"
            >
              {t(($) => $.needsAttention.loadingNeedsReview.open)}
            </Button>
          </div>

          {loadingNeedsReview.isLoading ? (
            <Skeleton className="h-12 w-full" />
          ) : loadingNeedsReview.isError ? (
            <ErrorState title={t(($) => $.needsAttention.loadingNeedsReview.errorTitle)} />
          ) : (
            <ul className="divide-y overflow-hidden rounded-lg border bg-card">
              {loadingNeedsReview.sessions.slice(0, 5).map((session) => (
                <li
                  key={session.session_id}
                  className="flex items-start gap-3 p-2.5 text-sm"
                  data-testid={`control-tower-loading-needs-review-${session.session_id}`}
                >
                  <PackageSearch className="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden />
                  <div className="min-w-0 flex-1">
                    <span className="font-medium">{session.session_number}</span>
                    <p className="text-xs text-muted-foreground">
                      {session.reasons.map((reason) => reasonLabel(reason)).join(' · ')}
                    </p>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </div>
      ) : null}

      <ExceptionDrawer
        exceptionId={openExceptionId}
        open={openExceptionId !== null}
        onOpenChange={(open) => {
          if (!open) setOpenExceptionId(null);
        }}
      />
    </section>
  );
}

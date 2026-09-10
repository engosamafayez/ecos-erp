import { useTranslation } from 'react-i18next';
import { AlertTriangle, CheckCircle2, Cpu, Gauge, TrendingUp, XCircle } from 'lucide-react';

import { WorkspaceHeader } from '@/components/workspace/header/workspace-header';
import { WorkspacePage } from '@/components/page/layout/workspace-page';
import { SmartToolbar } from '@/components/data-grid/smart-toolbar';
import { Badge } from '@/components/ui/badge';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';

import type enLogistics from '@/i18n/locales/en/logistics.json';

import { useExecutiveDashboard, useOperationsDashboard } from '../hooks/use-enterprise';
import type { ModuleStatus } from '../types/readiness';
import type { Severity } from '../types/enterprise';

/**
 * A label held as an i18next selector rather than a key string.
 *
 * Selector mode has no type for a key chosen at runtime, so a table of
 * key strings can never type-check. The selector is the same expression
 * the compiler validates at an inline call site, kept in the table.
 */
type LogisticsLabel = ($: typeof enLogistics) => string;

// ── Shared vocabulary ────────────────────────────────────────────────────────

const STATUS: Record<ModuleStatus, { labelKey: LogisticsLabel; className: string }> = {
  ready: {
    labelKey: ($) => $.operations.enterpriseWorkspace.status.ready,
    className: 'bg-emerald-600 text-white',
  },
  degraded: {
    labelKey: ($) => $.operations.enterpriseWorkspace.status.degraded,
    className: 'bg-amber-500 text-white',
  },
  not_ready: {
    labelKey: ($) => $.operations.enterpriseWorkspace.status.notReady,
    className: 'bg-destructive text-destructive-foreground',
  },
};

const SEVERITY: Record<Severity, string> = {
  critical: 'bg-destructive text-destructive-foreground',
  high: 'bg-amber-500 text-white',
  medium: 'bg-sky-600 text-white',
  low: 'bg-muted text-muted-foreground',
};

/** Severity → its shared, generic display label. */
const SEVERITY_LABEL: Record<Severity, LogisticsLabel> = {
  critical: ($) => $.common.critical,
  high: ($) => $.common.high,
  medium: ($) => $.common.medium,
  low: ($) => $.common.low,
};

/** A forecast/level word → tone, so it reads at a glance and by screen reader. */
const LEVEL_TONE: Record<string, string> = {
  exhausted: 'text-destructive',
  severe: 'text-destructive',
  at_risk: 'text-amber-600',
  high: 'text-amber-600',
  tightening: 'text-amber-600',
  moderate: 'text-amber-600',
  comfortable: 'text-emerald-600',
  low: 'text-emerald-600',
  no_data: 'text-muted-foreground',
};

/** Same keys as LEVEL_TONE — the translated word behind each forecast level. */
const LEVEL_LABEL: Record<string, LogisticsLabel> = {
  exhausted: ($) => $.operations.enterpriseWorkspace.forecastLevel.exhausted,
  severe: ($) => $.operations.enterpriseWorkspace.forecastLevel.severe,
  at_risk: ($) => $.operations.enterpriseWorkspace.forecastLevel.atRisk,
  high: ($) => $.operations.enterpriseWorkspace.forecastLevel.high,
  tightening: ($) => $.operations.enterpriseWorkspace.forecastLevel.tightening,
  moderate: ($) => $.operations.enterpriseWorkspace.forecastLevel.moderate,
  comfortable: ($) => $.operations.enterpriseWorkspace.forecastLevel.comfortable,
  low: ($) => $.operations.enterpriseWorkspace.forecastLevel.low,
  no_data: ($) => $.operations.enterpriseWorkspace.forecastLevel.noData,
};

function StatusBadge({ status }: { status: ModuleStatus }) {
  const { t } = useTranslation('logistics');
  const { labelKey, className } = STATUS[status];
  return <Badge className={`text-xs ${className}`}>{t(labelKey)}</Badge>;
}

function scoreTone(score: number): string {
  if (score >= 75) return 'text-emerald-600';
  if (score >= 40) return 'text-amber-600';
  return 'text-destructive';
}

function Panel({
  title,
  children,
  labelledBy,
}: {
  title: string;
  children: React.ReactNode;
  labelledBy: string;
}) {
  return (
    <section aria-labelledby={labelledBy} className="rounded-lg border bg-card p-4">
      <h3 id={labelledBy} className="mb-3 text-sm font-medium">
        {title}
      </h3>
      {children}
    </section>
  );
}

// ── Executive ────────────────────────────────────────────────────────────────

function ExecutiveTab() {
  const { t } = useTranslation('logistics');
  const { data, isLoading } = useExecutiveDashboard();

  if (isLoading || !data)
    return (
      <Skeleton
        className="h-96 w-full"
        aria-label={t(($) => $.operations.enterpriseWorkspace.loadingExecutive)}
      />
    );

  const headlineItems = [
    {
      id: 'criticalAlerts',
      label: t(($) => $.operations.enterpriseWorkspace.headline.criticalAlerts),
      value: data.headline.critical_alerts,
      bad: data.headline.critical_alerts > 0,
    },
    {
      id: 'openExceptions',
      label: t(($) => $.operations.enterpriseWorkspace.headline.openExceptions),
      value: data.headline.open_exceptions,
      warn: data.headline.open_exceptions > 0,
    },
    {
      id: 'unhealthyPools',
      label: t(($) => $.operations.enterpriseWorkspace.headline.unhealthyPools),
      value: data.headline.unhealthy_pools,
      warn: data.headline.unhealthy_pools > 0,
    },
    {
      id: 'exhaustedSlots',
      label: t(($) => $.operations.enterpriseWorkspace.headline.exhaustedSlots),
      value: data.headline.exhausted_capacity_slots,
      warn: data.headline.exhausted_capacity_slots > 0,
    },
    {
      id: 'canFieldToday',
      label: t(($) => $.operations.enterpriseWorkspace.headline.canFieldToday),
      value: data.headline.fieldable_units,
      good: true,
    },
    {
      id: 'overdueEscalations',
      label: t(($) => $.operations.enterpriseWorkspace.headline.overdueEscalations),
      value: data.headline.overdue_escalations,
      bad: data.headline.overdue_escalations > 0,
    },
  ];

  const statusLabel = t(STATUS[data.health.overall_status].labelKey);

  return (
    <div className="space-y-4">
      <Panel title={t(($) => $.operations.enterpriseWorkspace.panel.health)} labelledBy="exec-health">
        <div className="flex flex-wrap items-center gap-6">
          <div
            role="img"
            aria-label={t(($) => $.operations.enterpriseWorkspace.healthScoreAria, {
              score: data.health.score,
              grade: data.health.grade,
              status: statusLabel,
            })}
            className="flex flex-col items-center"
          >
            <span className={`text-4xl font-semibold tabular-nums ${scoreTone(data.health.score)}`}>
              {data.health.score}
            </span>
            <span className="text-xs text-muted-foreground">
              {t(($) => $.operations.enterpriseWorkspace.gradeLabel, { grade: data.health.grade })}
            </span>
          </div>
          <div className="space-y-2">
            <StatusBadge status={data.health.overall_status} />
            {/* TASK-ECOS-SHIPPING-OS-REDESIGN-002 §16 — `is_quiet` (zero critical
                alerts/escalations) and `overall_status` (degraded/not_ready can be
                driven by unrelated causes, e.g. unhealthy pools) are independent
                fields and can genuinely disagree. Showing "the operation is
                healthy" next to a Degraded/Not ready badge is exactly the
                contradictory messaging the task calls out by name — gate the
                quiet message on the status agreeing with it. */}
            {data.is_quiet && data.health.overall_status === 'ready' && (
              <p className="text-sm text-emerald-600">
                {t(($) => $.operations.enterpriseWorkspace.quietMessage)}
              </p>
            )}
          </div>
        </div>
      </Panel>

      <Panel title={t(($) => $.operations.enterpriseWorkspace.panel.headline)} labelledBy="exec-headline">
        <dl className="grid gap-3 sm:grid-cols-3">
          {headlineItems.map((item) => (
            <div key={item.id} className="rounded-md border p-3">
              <dt className="text-[11px] uppercase tracking-wide text-muted-foreground">
                {item.label}
              </dt>
              <dd
                className={`mt-1 text-2xl tabular-nums ${
                  item.bad ? 'text-destructive' : item.warn ? 'text-amber-600' : item.good ? 'text-emerald-600' : ''
                }`}
              >
                {item.value}
              </dd>
            </div>
          ))}
        </dl>
      </Panel>

      <div className="grid gap-4 md:grid-cols-2">
        <Panel title={t(($) => $.operations.enterpriseWorkspace.panel.topDecision)} labelledBy="exec-decision">
          {data.decisions.top_priority === null ? (
            <p className="py-6 text-center text-sm text-muted-foreground">
              {t(($) => $.operations.enterpriseWorkspace.decision.empty)}
            </p>
          ) : (
            <div className="space-y-2">
              <div className="flex flex-wrap items-center gap-2">
                <Badge className={`text-xs ${SEVERITY[data.decisions.top_priority.severity]}`}>
                  {t(SEVERITY_LABEL[data.decisions.top_priority.severity])}
                </Badge>
                <span className="text-sm font-medium">{data.decisions.top_priority.title}</span>
              </div>
              <p className="text-xs text-muted-foreground">{data.decisions.top_priority.detail}</p>
              <p className="text-xs">
                <span className="font-medium">{t(($) => $.operations.enterpriseWorkspace.decision.action)}</span>{' '}
                {data.decisions.top_priority.action}
              </p>
              <p className="text-[11px] text-muted-foreground">
                {t(($) => $.operations.enterpriseWorkspace.decision.total, { count: data.decisions.total })}
              </p>
            </div>
          )}
        </Panel>

        <Panel title={t(($) => $.operations.enterpriseWorkspace.panel.forecasts)} labelledBy="exec-forecasts">
          <dl className="space-y-2 text-sm">
            {[
              {
                id: 'capacity',
                label: t(($) => $.operations.enterpriseWorkspace.forecast.capacity),
                value: data.forecasts.capacity,
                icon: Gauge,
              },
              {
                id: 'dispatchPressure',
                label: t(($) => $.operations.enterpriseWorkspace.forecast.dispatchPressure),
                value: data.forecasts.dispatch_pressure,
                icon: TrendingUp,
              },
              {
                id: 'workload',
                label: t(($) => $.operations.enterpriseWorkspace.forecast.workload),
                value: data.forecasts.workload,
                icon: Cpu,
              },
            ].map((f) => (
              <div key={f.id} className="flex items-center justify-between">
                <dt className="flex items-center gap-2 text-muted-foreground">
                  <f.icon className="size-4" aria-hidden="true" />
                  {f.label}
                </dt>
                <dd className={`font-medium capitalize ${LEVEL_TONE[f.value] ?? ''}`}>
                  {t(LEVEL_LABEL[f.value])}
                </dd>
              </div>
            ))}
          </dl>
          <p className="mt-3 text-[11px] text-muted-foreground">
            {t(($) => $.operations.enterpriseWorkspace.forecast.note)}
          </p>
        </Panel>
      </div>
    </div>
  );
}

// ── Operations ───────────────────────────────────────────────────────────────

function SeverityIcon({ severity }: { severity: Severity }) {
  if (severity === 'critical') return <XCircle className="size-3.5 shrink-0 text-destructive" aria-hidden="true" />;
  if (severity === 'high' || severity === 'medium')
    return <AlertTriangle className="size-3.5 shrink-0 text-amber-600" aria-hidden="true" />;
  return <CheckCircle2 className="size-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />;
}

function OperationsTab() {
  const { t } = useTranslation('logistics');
  const { data, isLoading } = useOperationsDashboard();

  if (isLoading || !data)
    return (
      <Skeleton
        className="h-96 w-full"
        aria-label={t(($) => $.operations.enterpriseWorkspace.loadingOperations)}
      />
    );

  return (
    <div className="space-y-4">
      <Panel title={t(($) => $.operations.enterpriseWorkspace.panel.moduleReadiness)} labelledBy="ops-modules">
        <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {data.modules.map((module) => (
            <li key={module.module} className="rounded-md border p-3">
              <div className="flex items-center justify-between">
                <span className="text-sm font-medium">{module.label}</span>
                <StatusBadge status={module.status} />
              </div>
              {module.headline && (
                <p className="mt-1 text-xs text-amber-600">{module.headline}</p>
              )}
            </li>
          ))}
        </ul>
      </Panel>

      {data.bottleneck && (
        <Panel title={t(($) => $.operations.enterpriseWorkspace.panel.bindingConstraint)} labelledBy="ops-bottleneck">
          <div className="flex items-start gap-2">
            <AlertTriangle className="mt-0.5 size-4 shrink-0 text-amber-600" aria-hidden="true" />
            <div>
              <p className="text-sm font-medium capitalize">
                {data.bottleneck.module}: {data.bottleneck.reason}
              </p>
              <p className="text-xs text-muted-foreground">{data.bottleneck.action}</p>
            </div>
          </div>
        </Panel>
      )}

      <Panel title={t(($) => $.operations.enterpriseWorkspace.panel.topSuggestions)} labelledBy="ops-suggestions">
        {data.suggestions.length === 0 ? (
          <p className="py-6 text-center text-sm text-muted-foreground">
            {t(($) => $.operations.enterpriseWorkspace.suggestionsEmpty)}
          </p>
        ) : (
          <ul className="space-y-2">
            {data.suggestions.map((suggestion, index) => (
              <li key={`${suggestion.title}-${index}`} className="flex items-start gap-2">
                <SeverityIcon severity={suggestion.severity} />
                <div className="min-w-0 flex-1">
                  <p className="text-sm font-medium">{suggestion.title}</p>
                  <p className="text-xs text-muted-foreground">{suggestion.suggestion}</p>
                </div>
                <Badge variant="outline" className="text-[10px] capitalize">
                  {suggestion.owning_module}
                </Badge>
              </li>
            ))}
          </ul>
        )}
      </Panel>

      <div className="grid gap-4 md:grid-cols-2">
        <Panel title={t(($) => $.operations.enterpriseWorkspace.panel.capacityWarnings)} labelledBy="ops-warnings">
          {data.capacity_warnings.length === 0 ? (
            <p className="py-4 text-center text-sm text-muted-foreground">
              {t(($) => $.operations.enterpriseWorkspace.capacityWarningsEmpty)}
            </p>
          ) : (
            <ul className="space-y-1.5">
              {data.capacity_warnings.map((warning, index) => (
                <li key={index} className="flex items-center gap-2 text-sm">
                  <span
                    className={`inline-block size-2 rounded-full ${
                      warning.level === 'critical' ? 'bg-destructive' : 'bg-amber-500'
                    }`}
                    aria-hidden="true"
                  />
                  {warning.message}
                </li>
              ))}
            </ul>
          )}
        </Panel>

        <Panel title={t(($) => $.operations.enterpriseWorkspace.panel.automation)} labelledBy="ops-automation">
          <dl className="space-y-1.5 text-sm">
            <div className="flex items-center justify-between">
              <dt className="text-muted-foreground">{t(($) => $.operations.enterpriseWorkspace.eventConsumers)}</dt>
              <dd className="tabular-nums">{data.automation.consumer_count}</dd>
            </div>
            <div className="flex items-center justify-between">
              <dt className="text-muted-foreground">{t(($) => $.operations.enterpriseWorkspace.activePolicies)}</dt>
              <dd className="tabular-nums">{data.automation.policy_count}</dd>
            </div>
          </dl>
          <p className="mt-2 text-[11px] text-muted-foreground">
            {t(($) => $.operations.enterpriseWorkspace.automationNote)}
          </p>
        </Panel>
      </div>
    </div>
  );
}

// ── Page ─────────────────────────────────────────────────────────────────────

/**
 * Enterprise Workspace.
 *
 * The production completion surface: an executive dashboard and an operations
 * dashboard, each an aggregated read that composes the readiness, intelligence
 * and automation layers — one request per dashboard.
 */
export function EnterpriseWorkspacePage() {
  const { t } = useTranslation('logistics');
  const { data, refetch, isFetching } = useExecutiveDashboard();

  const metrics = [
    {
      id: 'score',
      icon: CheckCircle2,
      label: t(($) => $.operations.enterpriseWorkspace.metricHealthScore),
      value: data?.health.score ?? 0,
      isLoading: !data,
      colorClass: data ? scoreTone(data.health.score) : undefined,
    },
    {
      id: 'grade',
      icon: TrendingUp,
      label: t(($) => $.operations.enterpriseWorkspace.metricGrade),
      value: data?.health.grade ?? '—',
      isLoading: !data,
    },
    {
      id: 'decisions',
      icon: Cpu,
      label: t(($) => $.operations.enterpriseWorkspace.metricRecommendations),
      value: data?.decisions.total ?? 0,
      isLoading: !data,
    },
  ];

  return (
    <>
      <WorkspaceHeader
        breadcrumbs={[
          { label: t(($) => $.operations.enterpriseWorkspace.breadcrumbRoot) },
          { label: t(($) => $.operations.enterpriseWorkspace.breadcrumbSection) },
        ]}
        title={t(($) => $.operations.enterpriseWorkspace.title)}
        description={t(($) => $.operations.enterpriseWorkspace.description)}
        metrics={metrics}
      />

      <WorkspacePage
        toolbar={
          <div className="px-4 sm:px-6">
            <SmartToolbar onRefresh={() => refetch()} isFetching={isFetching} />
          </div>
        }
      >
        <div className="px-4 pb-6 sm:px-6">
          <Tabs defaultValue="executive" className="w-full">
            <TabsList aria-label={t(($) => $.operations.enterpriseWorkspace.tabsAriaLabel)}>
              <TabsTrigger value="executive">{t(($) => $.operations.enterpriseWorkspace.tabExecutive)}</TabsTrigger>
              <TabsTrigger value="operations">{t(($) => $.operations.enterpriseWorkspace.tabOperations)}</TabsTrigger>
            </TabsList>

            <TabsContent value="executive" className="pt-4">
              <ExecutiveTab />
            </TabsContent>
            <TabsContent value="operations" className="pt-4">
              <OperationsTab />
            </TabsContent>
          </Tabs>
        </div>
      </WorkspacePage>
    </>
  );
}

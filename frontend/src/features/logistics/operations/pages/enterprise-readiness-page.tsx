import { AlertTriangle, CheckCircle2, XCircle } from 'lucide-react';

import { WorkspaceHeader } from '@/components/workspace/header/workspace-header';
import { WorkspacePage } from '@/components/page/layout/workspace-page';
import { SmartToolbar } from '@/components/data-grid/smart-toolbar';
import { Badge } from '@/components/ui/badge';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';

import {
  useDiagnostics,
  useExecutiveSummary,
  useFleetSummary,
  useHealthScore,
  useReadinessChecklist,
  useReadinessDashboard,
  useTodaySummary,
} from '../hooks/use-readiness';
import type { ChecklistItem, ModuleStatus } from '../types/readiness';

// ── Status vocabulary ────────────────────────────────────────────────────────

const STATUS: Record<ModuleStatus, { label: string; className: string }> = {
  ready: { label: 'Ready', className: 'bg-emerald-600 hover:bg-emerald-600 text-white' },
  degraded: { label: 'Degraded', className: 'bg-amber-500 hover:bg-amber-500 text-white' },
  not_ready: {
    label: 'Not ready',
    className: 'bg-destructive hover:bg-destructive text-destructive-foreground',
  },
};

function StatusBadge({ status }: { status: ModuleStatus }) {
  const { label, className } = STATUS[status];
  return <Badge className={`text-xs ${className}`}>{label}</Badge>;
}

function scoreTone(score: number): string {
  if (score >= 75) return 'text-emerald-600';
  if (score >= 40) return 'text-amber-600';
  return 'text-destructive';
}

function Panel({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div className="rounded-lg border bg-card p-4">
      <h3 className="mb-3 text-sm font-medium">{title}</h3>
      {children}
    </div>
  );
}

/** A ring showing the health score — the single number a manager wants. */
function HealthRing({ score, grade }: { score: number; grade: string }) {
  const circumference = 2 * Math.PI * 42;
  const offset = circumference * (1 - Math.min(100, Math.max(0, score)) / 100);
  const stroke =
    score >= 75 ? 'stroke-emerald-600' : score >= 40 ? 'stroke-amber-500' : 'stroke-destructive';

  return (
    <div className="relative size-32 shrink-0">
      <svg viewBox="0 0 100 100" className="size-full -rotate-90">
        <circle cx="50" cy="50" r="42" className="fill-none stroke-muted" strokeWidth="8" />
        <circle
          cx="50"
          cy="50"
          r="42"
          className={`fill-none ${stroke}`}
          strokeWidth="8"
          strokeLinecap="round"
          strokeDasharray={circumference}
          strokeDashoffset={offset}
        />
      </svg>
      <div className="absolute inset-0 flex flex-col items-center justify-center">
        <span className={`text-3xl font-semibold tabular-nums ${scoreTone(score)}`}>{score}</span>
        <span className="text-xs text-muted-foreground">Grade {grade}</span>
      </div>
    </div>
  );
}

function CheckIcon({ item }: { item: ChecklistItem }) {
  if (item.passed) return <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-emerald-600" />;
  if (item.severity === 'blocking') return <XCircle className="mt-0.5 size-4 shrink-0 text-destructive" />;
  return <AlertTriangle className="mt-0.5 size-4 shrink-0 text-amber-600" />;
}

// ── Readiness tab ────────────────────────────────────────────────────────────

function ReadinessTab() {
  const { data, isLoading } = useReadinessDashboard();
  const { data: checklist } = useReadinessChecklist();

  if (isLoading || !data) return <Skeleton className="h-96 w-full" />;

  return (
    <div className="space-y-4">
      <Panel title="Logistics health score">
        <div className="flex flex-wrap items-center gap-6">
          <HealthRing score={data.health_score} grade={gradeFor(data.health_score)} />
          <div className="space-y-2">
            <StatusBadge status={data.overall_status} />
            <div className="flex gap-4 text-xs text-muted-foreground">
              <span className="text-emerald-600">{data.ready_count} ready</span>
              <span className="text-amber-600">{data.degraded_count} degraded</span>
              <span className="text-destructive">{data.not_ready_count} not ready</span>
            </div>
            <p className="max-w-md text-xs text-muted-foreground">
              A weighted roll-up of the five authorities&apos; own signals. Ready scores full,
              degraded half, not-ready zero — this layer computes no readiness itself.
            </p>
          </div>
        </div>
      </Panel>

      <Panel title="Module readiness">
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {data.modules.map((module) => (
            <div key={module.module} className="rounded-md border p-3">
              <div className="flex items-center justify-between">
                <span className="text-sm font-medium">{module.label}</span>
                <StatusBadge status={module.status} />
              </div>
              <p className="mt-1 text-xs text-muted-foreground">
                {module.passed_checks}/{module.total_checks} checks · weight {module.weight}
              </p>
              {module.headline && (
                <p className="mt-1 text-xs text-amber-600">{module.headline}</p>
              )}
            </div>
          ))}
        </div>
      </Panel>

      <Panel title="Operational readiness checklist">
        {checklist && checklist.blocking_failures.length > 0 && (
          <div className="mb-3 rounded-md border border-destructive/30 bg-destructive/5 p-2.5 text-xs text-destructive">
            {checklist.blocking_failures.length} blocking check
            {checklist.blocking_failures.length === 1 ? '' : 's'} must clear before go-live.
          </div>
        )}
        <ul className="space-y-1.5">
          {data.checklist.map((item) => (
            <li key={item.id} className="flex items-start gap-2 text-sm">
              <CheckIcon item={item} />
              <div className="min-w-0 flex-1">
                <span className={item.passed ? '' : 'font-medium'}>
                  {item.module_label}: {item.label}
                </span>
                <span className="ml-1.5 text-xs text-muted-foreground">{item.detail}</span>
              </div>
            </li>
          ))}
        </ul>
      </Panel>
    </div>
  );
}

function gradeFor(score: number): string {
  if (score >= 90) return 'A';
  if (score >= 75) return 'B';
  if (score >= 60) return 'C';
  if (score >= 40) return 'D';
  return 'F';
}

// ── Diagnostics tab ──────────────────────────────────────────────────────────

function DiagnosticsTab() {
  const { data, isLoading } = useDiagnostics();

  if (isLoading || !data) return <Skeleton className="h-96 w-full" />;

  const projections: Array<{ key: string; label: string; status: ModuleStatus }> = [
    { key: 'queue', label: 'Queue', status: data.queue.status },
    { key: 'capacity', label: 'Capacity', status: data.capacity.status },
    { key: 'dispatch', label: 'Dispatch', status: data.dispatch.status },
    { key: 'exceptions', label: 'Exceptions', status: data.exceptions.status },
  ];

  return (
    <div className="space-y-4">
      <Panel title="System health">
        <div className="flex flex-wrap items-center gap-4">
          <StatusBadge status={data.system.status} />
          {data.system.is_quiet && (
            <span className="text-sm text-emerald-600">Quiet — nothing needs a person.</span>
          )}
          <div className="flex gap-4 text-xs text-muted-foreground">
            <span>{data.system.modules_ready} ready</span>
            <span>{data.system.modules_degraded} degraded</span>
            <span>{data.system.modules_not_ready} not ready</span>
          </div>
        </div>
      </Panel>

      <Panel title="Dependency health">
        <div className="space-y-1.5">
          {data.dependencies.dependencies.map((dep) => (
            <div key={dep.name} className="flex items-start justify-between gap-3 text-sm">
              <div className="min-w-0">
                <span className="font-medium">{dep.label}</span>
                {dep.reason && (
                  <span className="ml-2 text-xs text-muted-foreground">{dep.reason}</span>
                )}
              </div>
              <StatusBadge status={dep.status} />
            </div>
          ))}
        </div>
      </Panel>

      <Panel title="Projections">
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          {projections.map((p) => (
            <div key={p.key} className="rounded-md border p-3">
              <div className="flex items-center justify-between">
                <span className="text-sm font-medium">{p.label}</span>
                <StatusBadge status={p.status} />
              </div>
            </div>
          ))}
        </div>
      </Panel>
    </div>
  );
}

// ── Summary tab ──────────────────────────────────────────────────────────────

function SummaryStat({ label, value }: { label: string; value: string | number }) {
  return (
    <div className="rounded-lg border bg-card p-4">
      <p className="text-[11px] uppercase tracking-wide text-muted-foreground">{label}</p>
      <p className="mt-1 text-2xl tabular-nums">{value}</p>
    </div>
  );
}

function pct(value: number | null | undefined): string {
  if (value === null || value === undefined) return '—';
  return `${Math.round(value * 100)}%`;
}

function SummaryTab() {
  const { data: exec } = useExecutiveSummary();
  const { data: today } = useTodaySummary();
  const { data: fleet } = useFleetSummary();

  if (!exec || !today || !fleet) return <Skeleton className="h-96 w-full" />;

  return (
    <div className="space-y-4">
      <Panel title="Executive">
        <div className="grid gap-4 md:grid-cols-3">
          <SummaryStat label="Health score" value={`${exec.health_score} (${exec.grade})`} />
          <SummaryStat label="Critical alerts" value={exec.headline.critical_alerts} />
          <SummaryStat label="Can field today" value={exec.headline.fieldable_units} />
        </div>
      </Panel>

      <Panel title="Today's operations">
        <div className="grid gap-4 md:grid-cols-4">
          <SummaryStat label="Active sessions" value={today.sessions_active} />
          <SummaryStat label="Confirmed" value={today.allocations_confirmed} />
          <SummaryStat label="Confirmation rate" value={pct(today.confirmation_rate)} />
          <SummaryStat label="Queue depth" value={today.queue_depth} />
        </div>
      </Panel>

      <Panel title="Fleet">
        <div className="grid gap-4 md:grid-cols-4">
          <SummaryStat label="Assignable vehicles" value={fleet.vehicles.assignable} />
          <SummaryStat label="Available drivers" value={fleet.drivers.available} />
          <SummaryStat label="Fieldable units" value={fleet.fieldable_units} />
          <SummaryStat label="Vehicle use now" value={pct(fleet.vehicles.utilisation_now)} />
        </div>
      </Panel>
    </div>
  );
}

// ── Page ─────────────────────────────────────────────────────────────────────

/**
 * Enterprise Readiness.
 *
 * The completion layer: is the whole operation ready, why or why not, and the
 * executive digest. Every number belongs to Fleet, Network, Dispatch or
 * Operations — reported and interpreted here, never recomputed.
 */
export function EnterpriseReadinessPage() {
  const { data: score, refetch, isFetching } = useHealthScore();

  const metrics = [
    {
      id: 'score',
      icon: CheckCircle2,
      label: 'Health Score',
      value: score?.score ?? 0,
      isLoading: !score,
      colorClass: score ? scoreTone(score.score) : undefined,
    },
    {
      id: 'status',
      icon: score?.overall_status === 'ready' ? CheckCircle2 : AlertTriangle,
      label: 'Overall',
      value: score ? STATUS[score.overall_status].label : '—',
      isLoading: !score,
    },
    {
      id: 'grade',
      icon: CheckCircle2,
      label: 'Grade',
      value: score?.grade ?? '—',
      isLoading: !score,
    },
  ];

  return (
    <>
      <WorkspaceHeader
        breadcrumbs={[{ label: 'Logistics OS' }, { label: 'Operations' }]}
        title="Enterprise Readiness"
        description="Readiness score, cross-module validation, diagnostics and the executive summary"
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
          <Tabs defaultValue="readiness" className="w-full">
            <TabsList>
              <TabsTrigger value="readiness">Readiness</TabsTrigger>
              <TabsTrigger value="diagnostics">Diagnostics</TabsTrigger>
              <TabsTrigger value="summary">Summary</TabsTrigger>
            </TabsList>

            <TabsContent value="readiness" className="pt-4">
              <ReadinessTab />
            </TabsContent>
            <TabsContent value="diagnostics" className="pt-4">
              <DiagnosticsTab />
            </TabsContent>
            <TabsContent value="summary" className="pt-4">
              <SummaryTab />
            </TabsContent>
          </Tabs>
        </div>
      </WorkspacePage>
    </>
  );
}

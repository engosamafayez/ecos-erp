import { useNavigate, generatePath } from 'react-router-dom';
import {
  Building2,
  CheckCircle2,
  Clock,
  Snowflake,
  Package,
  PackageCheck,
  PlayCircle,
  ShoppingCart,
  TriangleAlert,
  Loader2,
} from 'lucide-react';

import { Badge }    from '@/components/ui/badge';
import { Button }   from '@/components/ui/button';
import { Progress } from '@/components/ui/progress';
import { useToastStore } from '@/components/ds/use-toast';
import { useTodaySessions, useStartSession } from '../hooks/use-preparation';
import type { SessionStatus, TodaySessionWarehouse } from '../types/preparation';
import { ROUTES } from '@/router/routes';

const STATUS_BADGE: Record<string, { label: string; className: string }> = {
  draft:       { label: 'Scheduled',        className: 'bg-sky-100 text-sky-700' },
  planning:    { label: 'Planning',          className: 'bg-blue-100 text-blue-700' },
  in_progress: { label: 'Preparing',         className: 'bg-purple-100 text-purple-700' },
  paused:      { label: 'Paused',            className: 'bg-amber-100 text-amber-700' },
  frozen:      { label: 'Frozen',            className: 'bg-cyan-100 text-cyan-700' },
  completed:   { label: 'Ready for Loading', className: 'bg-green-100 text-green-700' },
  approved:    { label: 'Approved',          className: 'bg-emerald-100 text-emerald-700' },
  closed:      { label: 'Closed',            className: 'bg-slate-100 text-slate-700' },
  cancelled:   { label: 'Cancelled',         className: 'bg-red-100 text-red-700' },
};

// ── Freeze Status helpers ─────────────────────────────────────────────────────

function freezeStyle(status: string): string {
  if (status === 'frozen')                                         return 'bg-cyan-50 border-cyan-200';
  if (status === 'completed' || status === 'approved' || status === 'closed') return 'bg-green-50 border-green-200';
  if (status === 'in_progress' || status === 'planning')           return 'bg-purple-50 border-purple-200';
  return 'bg-muted/30 border-border/40';
}

function FreezeStatusContent({ status }: { status: string }) {
  if (status === 'frozen') {
    return (
      <>
        <div className="flex items-center gap-1.5">
          <Snowflake className="h-3.5 w-3.5 text-cyan-600 shrink-0" />
          <span className="text-sm font-semibold text-cyan-700">Frozen</span>
        </div>
        <p className="text-[10px] text-cyan-600 mt-0.5 leading-snug">
          New orders will move to the next preparation session.
        </p>
      </>
    );
  }
  if (status === 'completed' || status === 'approved' || status === 'closed') {
    return (
      <div className="flex items-center gap-1.5">
        <PackageCheck className="h-3.5 w-3.5 text-green-600 shrink-0" />
        <span className="text-sm font-semibold text-green-700">Ready for Loading</span>
      </div>
    );
  }
  if (status === 'in_progress' || status === 'planning' || status === 'paused') {
    return (
      <div className="flex items-center gap-1.5">
        <Package className="h-3.5 w-3.5 text-purple-600 shrink-0" />
        <span className="text-sm font-semibold text-purple-700">Preparing</span>
      </div>
    );
  }
  return (
    <span className="text-sm text-muted-foreground">Scheduled</span>
  );
}

// ── Warehouse Card ────────────────────────────────────────────────────────────

function WarehouseCard({ item }: { item: TodaySessionWarehouse }) {
  const navigate  = useNavigate();
  const toast     = useToastStore();
  const startMut  = useStartSession();

  const { session, kpis, warehouse_name } = item;
  const status = session?.status ?? null;

  async function handleStart() {
    if (!session) return;
    try {
      await startMut.mutateAsync(session.id);
      toast.success(`Preparation started for ${warehouse_name}.`);
    } catch {
      toast.error('Failed to start preparation.');
    }
  }

  function handleNavigate() {
    if (session) navigate(generatePath(ROUTES.preparationSessionDetail, { id: session.id }));
  }

  const badge = status ? STATUS_BADGE[status] : null;

  return (
    <div className="rounded-xl border border-border/60 bg-card p-5 flex flex-col gap-4 hover:shadow-sm transition-shadow">
      {/* Header */}
      <div className="flex items-start justify-between gap-2">
        <div className="flex items-center gap-2.5">
          <Building2 className="h-4 w-4 text-muted-foreground shrink-0" />
          <span className="font-semibold text-sm">{warehouse_name}</span>
        </div>
        {badge ? (
          <Badge className={`text-xs font-medium ${badge.className}`}>{badge.label}</Badge>
        ) : (
          <Badge className="text-xs font-medium bg-gray-100 text-gray-400">Not Scheduled</Badge>
        )}
      </div>

      {/* KPI row */}
      {session ? (
        <div className="grid grid-cols-5 gap-2 text-center">
          <KpiCell icon={<ShoppingCart  className="h-3.5 w-3.5" />} label="Orders"    value={kpis.orders} />
          <KpiCell icon={<Package       className="h-3.5 w-3.5" />} label="Products"  value={kpis.products} />
          <KpiCell icon={<CheckCircle2  className="h-3.5 w-3.5" />} label="Prepared"  value={kpis.prepared} />
          <KpiCell icon={<TriangleAlert className="h-3.5 w-3.5" />} label="Blocked"   value={kpis.blocked} accent={kpis.blocked > 0 ? 'warn' : undefined} />
          <KpiCell icon={<Clock         className="h-3.5 w-3.5" />} label="Remaining" value={kpis.remaining} />
        </div>
      ) : (
        <div className="h-12 flex items-center justify-center text-xs text-muted-foreground">
          Preparation will begin at 06:00
        </div>
      )}

      {/* Overall Progress */}
      {session && (
        <div>
          <div className="flex items-center justify-between mb-1">
            <span className="text-[10px] text-muted-foreground font-medium">Preparation Progress</span>
            <span className="text-xs text-muted-foreground tabular-nums">{kpis.prepared_pct.toFixed(0)}%</span>
          </div>
          <Progress value={kpis.prepared_pct} className="h-1.5" />
        </div>
      )}

      {/* Operational Status: Ready for Loading + Freeze Status */}
      {session && (
        <div className="grid grid-cols-2 gap-2">
          {/* Ready for Loading */}
          <div className="rounded-lg bg-muted/30 border border-border/40 px-3 py-2 space-y-1">
            <p className="text-[10px] font-medium text-muted-foreground uppercase tracking-wider">
              Ready for Loading
            </p>
            <div className="flex items-baseline gap-1.5">
              <span className="text-base font-bold tabular-nums leading-none">{kpis.prepared}</span>
              <span className="text-xs text-muted-foreground">/ {kpis.products} products</span>
            </div>
            <div className="flex items-center gap-1.5">
              <Progress
                value={kpis.prepared_pct}
                className={`h-1 flex-1 ${kpis.prepared_pct >= 100 ? '[&>div]:bg-green-500' : ''}`}
              />
              <span className="text-[10px] tabular-nums font-medium">
                {kpis.prepared_pct.toFixed(0)}%
              </span>
            </div>
          </div>

          {/* Freeze Status */}
          <div className={`rounded-lg border px-3 py-2 ${freezeStyle(status ?? '')}`}>
            <p className="text-[10px] font-medium uppercase tracking-wider text-muted-foreground mb-1">
              Freeze Status
            </p>
            <FreezeStatusContent status={status ?? 'draft'} />
          </div>
        </div>
      )}

      {/* Action */}
      <div className="flex items-center justify-between gap-2 pt-1 border-t border-border/40">
        {session && (
          <span className="text-xs text-muted-foreground font-mono">
            {session.session_number}
            {session.auto_created && (
              <span className="ml-1.5 text-sky-600">· Auto</span>
            )}
          </span>
        )}
        <div className="flex gap-2 ml-auto">
          {status === 'draft' && (
            <Button size="sm" onClick={handleStart} disabled={startMut.isPending}>
              {startMut.isPending
                ? <Loader2 className="h-3.5 w-3.5 animate-spin mr-1.5" />
                : <PlayCircle className="h-3.5 w-3.5 mr-1.5" />
              }
              Start Preparation
            </Button>
          )}
          {status === 'in_progress' && (
            <Button size="sm" onClick={handleNavigate}>
              <PlayCircle className="h-3.5 w-3.5 mr-1.5" />
              Continue Preparation
            </Button>
          )}
          {status === 'paused' && (
            <Button size="sm" variant="outline" onClick={handleNavigate}>
              Resume Preparation
            </Button>
          )}
          {status === 'frozen' && (
            <Button size="sm" variant="outline" onClick={handleNavigate} className="text-cyan-700 border-cyan-300">
              <Snowflake className="h-3.5 w-3.5 mr-1.5" />
              Loading Handoff
            </Button>
          )}
          {(status === 'completed' || status === 'approved') && (
            <Button size="sm" variant="outline" onClick={handleNavigate} className="text-green-700 border-green-300">
              <PackageCheck className="h-3.5 w-3.5 mr-1.5" />
              View Report
            </Button>
          )}
          {status === 'closed' && (
            <Button size="sm" variant="outline" onClick={handleNavigate}>View Report</Button>
          )}
          {!session && (
            <Button size="sm" variant="outline" disabled>Not Yet Scheduled</Button>
          )}
        </div>
      </div>
    </div>
  );
}

function KpiCell({
  icon,
  label,
  value,
  accent,
}: {
  icon: React.ReactNode;
  label: string;
  value: number;
  accent?: 'warn';
}) {
  return (
    <div className="flex flex-col items-center gap-0.5">
      <span className={`${accent === 'warn' && value > 0 ? 'text-amber-500' : 'text-muted-foreground'}`}>
        {icon}
      </span>
      <span className={`text-base font-semibold tabular-nums leading-none ${accent === 'warn' && value > 0 ? 'text-amber-600' : ''}`}>
        {value}
      </span>
      <span className="text-[10px] text-muted-foreground">{label}</span>
    </div>
  );
}

// ── Page ──────────────────────────────────────────────────────────────────────

export function TodayPreparationPage() {
  const { data, isLoading } = useTodaySessions();

  const warehouses = data?.data ?? [];
  const today      = data?.date ?? new Date().toLocaleDateString('en-CA');

  return (
    <div className="flex flex-col h-full">
      {/* Header */}
      <div className="px-6 pt-5 pb-4 border-b border-border/60">
        <h1 className="text-lg font-semibold">Today's Preparation</h1>
        <p className="text-sm text-muted-foreground mt-0.5">
          {new Date(today).toLocaleDateString(undefined, {
            weekday: 'long', year: 'numeric', month: 'long', day: 'numeric',
          })}
        </p>
      </div>

      {/* Content */}
      <div className="flex-1 overflow-auto p-6">
        {isLoading ? (
          <div className="flex items-center justify-center h-48 text-muted-foreground gap-2">
            <Loader2 className="h-4 w-4 animate-spin" />
            <span className="text-sm">Loading today's preparation…</span>
          </div>
        ) : warehouses.length === 0 ? (
          <div className="flex flex-col items-center justify-center h-48 gap-2 text-muted-foreground">
            <Building2 className="h-8 w-8 opacity-40" />
            <p className="text-sm">No warehouses configured for this company.</p>
          </div>
        ) : (
          <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
            {warehouses.map((item) => (
              <WarehouseCard key={item.warehouse_id} item={item} />
            ))}
          </div>
        )}
      </div>
    </div>
  );
}

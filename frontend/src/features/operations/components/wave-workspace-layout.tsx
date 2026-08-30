import { memo, useEffect, useState, type ReactNode } from 'react';
import { Link, Outlet, useLocation, useSearchParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import {
  AlertTriangle,
  CheckCircle2,
  Layers2,
  LayoutDashboard,
  Loader2,
  Package,
  PackageX,
  Play,
  RefreshCw,
  ShoppingCart,
  TrendingUp,
  Waves,
} from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ROUTES } from '@/router/routes';
import { usePreparationWave, useAdvanceWave, useCurrentWave, useWaveKpis } from '../hooks/use-preparation';
import type { PreparationWave, WaveStatus } from '../types/preparation';

// ── Status colors (CSS only, no translated labels) ─────────────────────────────

const STATUS_COLORS: Record<WaveStatus, string> = {
  draft:            'bg-gray-100 text-gray-700',
  collecting:       'bg-cyan-100 text-cyan-700',
  planning:         'bg-blue-100 text-blue-700',
  shortage_blocked: 'bg-amber-100 text-amber-700',
  preparing:        'bg-purple-100 text-purple-700',
  completed:        'bg-green-100 text-green-700',
  closed:           'bg-slate-100 text-slate-600',
  cancelled:        'bg-red-100 text-red-700',
};

// ── Tab definitions (keys only, labels come from i18n) ────────────────────────

// TASK-PREPARATION-OPERATIONS-UX-002 §2/§28 — the daily preparation workspace has four
// operational tabs: النشط | المواد المفقودة | الطلبات | قرارات العجز. Archive and Settings
// are top-level Preparation destinations (in the sidebar), NOT tabs here.
const WORKSPACE_TABS = [
  { key: 'active',           path: ROUTES.waveProductDemand,    Icon: Package      },
  { key: 'missing',          path: ROUTES.waveMissingMaterials, Icon: PackageX     },
  { key: 'orders',           path: ROUTES.waveOrders,           Icon: ShoppingCart },
  { key: 'deficitDecisions', path: ROUTES.waveDeficitDecisions, Icon: AlertTriangle },
  { key: 'overview',         path: ROUTES.waveOverview,         Icon: LayoutDashboard },
] as const;

// ── Countdown (isolated to prevent Outlet re-renders every second) ─────────────

const CountdownTimer = memo(function CountdownTimer({ wave }: { wave: PreparationWave | undefined }) {
  const { t } = useTranslation('operations');
  const [remaining, setRemaining] = useState(0);

  // Pulled out so the countdown depends on the two fields it actually reads,
  // rather than on the whole wave object changing identity every refetch.
  const planningDate = wave?.planning_date;
  const status = wave?.status;

  useEffect(() => {
    if (!planningDate || !status || !['draft', 'planning'].includes(status)) {
      setRemaining(0);
      return;
    }
    const target = new Date(`${planningDate}T08:00:00`);
    if (target <= new Date()) { setRemaining(0); return; }
    const update = () => setRemaining(Math.max(0, target.getTime() - Date.now()));
    update();
    const id = setInterval(update, 1000);
    return () => clearInterval(id);
  }, [planningDate, status]);

  if (!wave) return null;

  if (remaining > 0) {
    const s   = Math.floor(remaining / 1000);
    const h   = Math.floor(s / 3600);
    const m   = Math.floor((s % 3600) / 60);
    const sec = s % 60;
    const formatted = h > 0
      ? `${h}:${String(m).padStart(2, '0')}:${String(sec).padStart(2, '0')}`
      : `${String(m).padStart(2, '0')}:${String(sec).padStart(2, '0')}`;
    return (
      <div className="flex flex-col items-end shrink-0">
        <span className="text-[10px] text-muted-foreground leading-none">{t($ => $.wave.workspace.prepStartsIn)}</span>
        <span className="text-sm font-mono font-bold tabular-nums leading-tight">{formatted}</span>
      </div>
    );
  }

  // TASK-PREPARATION-OPERATIONS-UX-003 §1 — countdown ONLY. This used to fall through
  // to a dot + stage label, which meant the header rendered the wave status a second
  // time ("• Collecting") next to the real status badge. The badge is the single source
  // of status in the header; when there is no countdown to show, render nothing.
  return null;
});

// ── Header KPI chip ───────────────────────────────────────────────────────────

function KpiChip({
  icon,
  label,
  value,
  accent,
}: {
  icon: React.ReactNode;
  label: string;
  value: number | string;
  accent?: 'danger' | 'warn' | 'success';
}) {
  const cls =
    accent === 'danger'  ? 'text-red-700 bg-red-50 border-red-200' :
    accent === 'warn'    ? 'text-amber-700 bg-amber-50 border-amber-200' :
    accent === 'success' ? 'text-emerald-700 bg-emerald-50 border-emerald-200' :
    'text-muted-foreground bg-muted/50 border-border/60';
  return (
    <div className={`flex items-center gap-1.5 rounded-md border px-2 py-1 text-xs shrink-0 ${cls}`}>
      {icon}
      <span className="font-semibold tabular-nums">{value}</span>
      <span className="text-[10px] opacity-75">{label}</span>
    </div>
  );
}

// ── Summary bar item ──────────────────────────────────────────────────────────


function fmtN(n: number) {
  return n.toLocaleString(undefined, { maximumFractionDigits: 0 });
}

function safeDate(val: string | null | undefined): Date | null {
  if (!val) return null;
  // Normalize MySQL datetime format (space → T) for cross-browser compatibility
  const d = new Date(val.includes(' ') && !val.includes('T') ? val.replace(' ', 'T') : val);
  return isNaN(d.getTime()) ? null : d;
}


function fmtLocalDate(val: string | null | undefined): string {
  const d = safeDate(val);
  return d ? d.toLocaleDateString(undefined, { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' }) : '—';
}

// ── Layout ────────────────────────────────────────────────────────────────────

export function WaveWorkspaceLayout() {
  const { t } = useTranslation('operations');
  const tAny = t as (key: string, opts?: Record<string, unknown>) => string;
  const { pathname }   = useLocation();
  const [searchParams, setSearchParams] = useSearchParams();
  const waveId         = searchParams.get('wave_id');
  const advance        = useAdvanceWave();

  // ── Canonical current-wave resolution (§3-§6) ──────────────────────────────
  // The URL wave id is honoured ONLY while it is genuinely among the active waves; a stale
  // id (its wave ended) is ignored so Today's Preparation resolves the new current wave
  // from server state (§4) rather than trusting browser state.
  const current = useCurrentWave();
  const activeWaves = current.data?.waves ?? [];
  const activeCount = current.data?.active_count ?? 0;
  const waveIdIsActive = !!waveId && activeWaves.some((w) => w.id === waveId);

  useEffect(() => {
    if (!current.data || waveIdIsActive) return;
    // Exactly one active wave → open it automatically, replacing any stale id in the URL.
    if (current.data.active_count === 1 && current.data.wave) {
      const id = current.data.wave.id;
      setSearchParams((p) => { p.set('wave_id', id); return p; }, { replace: true });
    }
  }, [current.data, waveIdIsActive, setSearchParams]);

  // The wave-scoped reads only fire once we hold a valid, active wave id.
  const resolvedId = waveIdIsActive ? waveId : null;
  const { data: wave, isFetching } = usePreparationWave(resolvedId);

  // Missing / completion come from the canonical wave KPI, computed live from
  // wave_product_demand + wave_material_demand — the SAME source the product rows derive
  // from, so the header can never disagree with the product list (§19-§21).
  const { data: waveKpis } = useWaveKpis(resolvedId);
  const missingCount  = waveKpis?.missing_materials_count ?? 0;
  const totalRequired = waveKpis?.total_units_required ?? wave?.total_units_required ?? 0;
  const productsCount = waveKpis?.products_count ?? wave?.products_count ?? 0;
  const completionPct = waveKpis?.completion_pct ?? wave?.completion_pct ?? 0;

  // Preserve ?wave_id= when navigating between tabs
  function tabHref(path: string) {
    return waveId ? `${path}?wave_id=${encodeURIComponent(waveId)}` : path;
  }

  // ── Resolution states (§5/§6/§26) — shown INSTEAD of the tab content when there is no
  // single valid active wave to work on. The header keeps the picker so the operator can
  // still choose a wave; a failed read is an explicit error, never a false empty. ──────────
  const showResolutionState = !waveIdIsActive;
  let resolutionState: ReactNode = null;
  if (showResolutionState) {
    if (current.isLoading || (activeCount === 1 && !current.isError)) {
      resolutionState = (
        <div className="flex items-center justify-center h-64 gap-2 text-muted-foreground">
          <Loader2 className="h-4 w-4 animate-spin" />
          <span className="text-sm">{t($ => $.wave.workspace.resolvingWave)}</span>
        </div>
      );
    } else if (current.isError) {
      resolutionState = (
        <div className="flex flex-col items-center justify-center h-64 gap-3 text-muted-foreground">
          <AlertTriangle className="h-8 w-8 text-destructive/70" />
          <p className="text-sm">{t($ => $.wave.workspace.loadError)}</p>
          <Button variant="outline" size="sm" onClick={() => void current.refetch()}>
            {t($ => $.wave.workspace.retry)}
          </Button>
        </div>
      );
    } else if (activeCount === 0) {
      resolutionState = (
        <div className="flex flex-col items-center justify-center h-64 gap-3 text-muted-foreground">
          <Waves className="h-10 w-10 opacity-30" />
          <p className="text-sm font-medium">{t($ => $.wave.workspace.noActiveWave.title)}</p>
          <p className="text-xs">{t($ => $.wave.workspace.noActiveWave.hint)}</p>
        </div>
      );
    } else {
      // More than one active wave — a read-state invariant. Never silently pick one (§6):
      // surface the conflicting waves and let the operator choose via the picker above.
      resolutionState = (
        <div className="flex flex-col items-center justify-center h-64 gap-3 px-6 text-center text-muted-foreground">
          <AlertTriangle className="h-8 w-8 text-amber-500" />
          <p className="text-sm font-medium">{t($ => $.wave.workspace.multipleActiveWaves.title)}</p>
          <p className="text-xs">{t($ => $.wave.workspace.multipleActiveWaves.hint)}</p>
          <div className="flex flex-wrap items-center justify-center gap-1.5 pt-1">
            {activeWaves.map((w) => (
              <button
                key={w.id}
                type="button"
                onClick={() => setSearchParams((p) => { p.set('wave_id', w.id); return p; }, { replace: true })}
                className="rounded-md border px-2.5 py-1 font-mono text-xs hover:bg-accent"
              >
                {w.wave_number}
              </button>
            ))}
          </div>
        </div>
      );
    }
  }

  return (
    <div className="flex flex-col h-full">

      {/* ── Shared Workspace Header ──────────────────────────────────────────── */}
      {/*
        TASK-PREPARATION-OPERATIONS-UX-003 §7 — ONE balanced row instead of two.
        LEFT  = wave identity (number + planning date) followed inline by the KPI summary.
        RIGHT = status, countdown, Start Preparation, wave selector.
        The KPI chips used to occupy a full-width second row while the identity block left
        most of its row empty; merging them reclaims that vertical space without adding
        height. Every element still renders exactly once — no status, selector or KPI is
        duplicated, and no business behaviour changes.
      */}
      <header className="border-b border-border/60 bg-card shrink-0">
        <div className="flex items-center justify-between gap-x-4 gap-y-2 px-4 py-2 flex-wrap">

          {/* LEFT — wave number, planning date, KPI summary */}
          <div className="flex items-center gap-3 min-w-0 flex-wrap">
            <div className="flex items-center gap-2.5 min-w-0">
              <Layers2 className="h-4 w-4 text-muted-foreground shrink-0" />
              <div className="min-w-0">
                {wave ? (
                  <>
                    <span className="text-sm font-semibold font-mono leading-none block truncate">
                      {wave.wave_number}
                    </span>
                    <span className="text-[10px] text-muted-foreground mt-0.5 block">
                      {fmtLocalDate(wave.planning_date)}
                    </span>
                  </>
                ) : (
                  <span className="text-sm font-medium text-muted-foreground">{t($ => $.wave.workspace.title)}</span>
                )}
              </div>
            </div>

            {wave && (
              <div className="flex items-center gap-2 overflow-x-auto">
                <KpiChip
                  icon={<ShoppingCart className="h-3 w-3" />}
                  label={t($ => $.wave.workspace.kpis.orders)}
                  value={wave.orders_count}
                />
                <KpiChip
                  icon={<Package className="h-3 w-3" />}
                  label={t($ => $.wave.workspace.kpis.products)}
                  value={productsCount}
                />
                <KpiChip
                  icon={<CheckCircle2 className="h-3 w-3" />}
                  label={t($ => $.wave.workspace.kpis.required)}
                  value={fmtN(totalRequired)}
                />
                <KpiChip
                  icon={<PackageX className="h-3 w-3" />}
                  label={t($ => $.wave.workspace.kpis.missing)}
                  value={missingCount}
                  accent={missingCount > 0 ? 'danger' : undefined}
                />
                {/* Quantity-weighted wave completion from the live KPI (single source, §21). */}
                <KpiChip
                  icon={<TrendingUp className="h-3 w-3" />}
                  label={t($ => $.wave.workspace.kpis.complete)}
                  value={`${completionPct.toFixed(0)}%`}
                  accent={completionPct >= 100 ? 'success' : undefined}
                />
              </div>
            )}
          </div>

          {/* RIGHT — status indicators and wave controls */}
          <div className="flex items-center gap-2.5 shrink-0">
            {wave && (
              <Badge className={`text-[10px] h-4 px-1.5 ${STATUS_COLORS[wave.status]}`}>
                {tAny(`wave.stageLabels.${wave.status}`)}
              </Badge>
            )}
            {wave?.shortage_detected && (
              <Badge className="text-[10px] h-4 px-1.5 bg-amber-100 text-amber-700">
                {t($ => $.wave.workspace.shortage)}
              </Badge>
            )}
            {isFetching && (
              <span className="flex items-center gap-1 text-[10px] text-muted-foreground">
                <RefreshCw className="h-2.5 w-2.5 animate-spin" />
                {t($ => $.wave.workspace.syncing)}
              </span>
            )}
            {wave && <CountdownTimer wave={wave} />}
            {wave?.status === 'collecting' && (
              <Button
                size="sm"
                variant="default"
                className="h-7 text-xs gap-1.5"
                disabled={advance.isPending}
                onClick={() => waveId && advance.mutate(waveId)}
              >
                {advance.isPending
                  ? <Loader2 className="h-3 w-3 animate-spin" />
                  : <Play className="h-3 w-3" />}
                {t($ => $.wave.workspace.startPreparation)}
              </Button>
            )}
            {/* TASK-PREPARATION-CURRENT-WAVE-RUNTIME-CLOSURE-001 §3 — the legacy
                "Select a wave…" control is removed from Today's Preparation entirely, in
                every state. The current wave is resolved automatically; when several are
                active the body offers an explicit choice, and historical waves are browsed
                only in Archive. No selector is restored as an error fallback. */}
          </div>
        </div>
      </header>

      {/* ── Workspace Tab Navigation ─────────────────────────────────────────── */}
      {/* Tabs are meaningful only against a resolved wave; hidden while resolving / on the
          no-wave and multiple-wave states so the operator is not offered empty tabs. */}
      {waveIdIsActive && (
        <nav
          className="flex items-center border-b border-border/60 bg-background shrink-0 overflow-x-auto"
          aria-label="Wave workspace views"
        >
          {WORKSPACE_TABS.map(({ key, path, Icon }) => {
            const active = pathname === path;
            return (
              <Link
                key={key}
                to={tabHref(path)}
                className={`flex items-center gap-1.5 px-3.5 py-2.5 text-xs font-medium whitespace-nowrap border-b-2 transition-colors ${
                  active
                    ? 'border-primary text-primary'
                    : 'border-transparent text-muted-foreground hover:text-foreground hover:border-border'
                }`}
                aria-current={active ? 'page' : undefined}
              >
                <Icon className="h-3.5 w-3.5" />
                {tAny(`wave.workspace.tabs.${key}`)}
              </Link>
            );
          })}
        </nav>
      )}

      {/* ── Page Content ────────────────────────────────────────────────────── */}
      <div className="flex-1 overflow-hidden">
        {waveIdIsActive ? <Outlet /> : resolutionState}
      </div>
    </div>
  );
}

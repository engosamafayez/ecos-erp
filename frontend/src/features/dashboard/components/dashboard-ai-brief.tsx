import { Sparkles, TrendingDown, TrendingUp, Minus } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { ExecutiveDashboardData } from '../services/executive-dashboard.service';

// ── Types ──────────────────────────────────────────────────────────────────

type InsightLevel = 'alert' | 'positive' | 'info' | 'tip';

interface Insight {
  level:   InsightLevel;
  message: string;
}

// ── Insight derivation ─────────────────────────────────────────────────────

function deriveInsights(data: ExecutiveDashboardData): Insight[] {
  const out: Insight[] = [];
  const { sales: s, shipping: sh, marketing: mk } = data;

  if (s.revenue_trend_pct !== null && s.revenue_trend_pct > 20)
    out.push({ level: 'positive', message: `Revenue is up ${s.revenue_trend_pct.toFixed(1)}% compared to yesterday — strong performance across all channels.` });
  else if (s.revenue_trend_pct !== null && s.revenue_trend_pct < -20)
    out.push({ level: 'alert',    message: `Revenue is down ${Math.abs(s.revenue_trend_pct).toFixed(1)}% vs yesterday. Review the order pipeline and active campaigns.` });

  if (s.cancelled_today > 0 && s.orders_today > 0) {
    const rate = ((s.cancelled_today / s.orders_today) * 100).toFixed(0);
    out.push({ level: s.cancelled_today / s.orders_today > 0.1 ? 'alert' : 'info',
      message: `${s.cancelled_today} orders cancelled today (${rate}% cancellation rate).` });
  }

  if (mk.roas !== null && mk.roas > 4)
    out.push({ level: 'positive', message: `ROAS reached ${mk.roas}× this month — campaigns are outperforming target. Consider increasing budget.` });
  else if (mk.roas !== null && mk.roas < 1 && mk.spend_this_month > 0)
    out.push({ level: 'alert',    message: `Marketing ROAS is below 1× — ad spend is exceeding campaign revenue. Pause underperforming campaigns.` });

  if (sh.failed_today > 0) {
    const rate = sh.shipments_today > 0 ? ((sh.failed_today / sh.shipments_today) * 100).toFixed(0) : '100';
    out.push({ level: sh.failed_today >= 3 ? 'alert' : 'tip',
      message: `${sh.failed_today} failed ${sh.failed_today === 1 ? 'delivery' : 'deliveries'} today (${rate}% failure rate). Review driver assignments in the Logistics workspace.` });
  }

  if (sh.cod_pending > 10_000)
    out.push({ level: 'tip', message: `EGP ${sh.cod_pending.toLocaleString('en-US', { maximumFractionDigits: 0 })} in pending COD collections. Schedule driver settlement runs.` });

  if (s.pending_count > 50)
    out.push({ level: 'tip', message: `${s.pending_count} orders are waiting in the Pending queue. Assign them to preparation waves to clear the backlog.` });

  if (out.length === 0) {
    out.push(s.orders_today === 0
      ? { level: 'info', message: 'No orders recorded yet today. Dashboard will update as orders come in.' }
      : { level: 'info', message: `${s.orders_today} orders processed today with EGP ${s.revenue_today.toLocaleString('en-US', { maximumFractionDigits: 0 })} in revenue. All operations on track.` });
  }

  return out.slice(0, 5);
}

// ── Visual config ──────────────────────────────────────────────────────────

const LEVEL_CONFIG: Record<InsightLevel, {
  icon:   React.ComponentType<{ className?: string }>;
  dot:    string;
  text:   string;
}> = {
  alert:    { icon: TrendingDown, dot: 'bg-rose-500',    text: 'text-rose-600 dark:text-rose-400' },
  positive: { icon: TrendingUp,   dot: 'bg-emerald-500', text: 'text-foreground/90' },
  tip:      { icon: Minus,        dot: 'bg-amber-400',   text: 'text-foreground/80' },
  info:     { icon: Minus,        dot: 'bg-indigo-400',  text: 'text-foreground/70' },
};

// ── Component ──────────────────────────────────────────────────────────────

interface Props {
  data?:    ExecutiveDashboardData;
  loading?: boolean;
}

export function DashboardAiBrief({ data, loading }: Props) {
  const insights = data ? deriveInsights(data) : null;
  const alertCount = insights?.filter(i => i.level === 'alert').length ?? 0;

  return (
    <div className="flex gap-4">
      {/* Left accent bar */}
      <div className="w-0.5 shrink-0 self-stretch rounded-full bg-violet-500/40" />

      <div className="flex-1 min-w-0">
        {/* Header */}
        <div className="mb-3 flex items-center gap-2.5">
          <Sparkles className="h-3.5 w-3.5 shrink-0 text-violet-500" />
          <span className="text-[10px] font-bold uppercase tracking-[0.12em] text-violet-600 dark:text-violet-400">
            AI Executive Brief
          </span>
          {alertCount > 0 && (
            <span className="rounded-full bg-rose-500 px-1.5 py-0.5 text-[9px] font-bold leading-none text-white">
              {alertCount} {alertCount === 1 ? 'alert' : 'alerts'}
            </span>
          )}
          <span className="ml-auto text-[10px] text-muted-foreground/50">Rule-based · updates every 5 min</span>
        </div>

        {/* Insights */}
        {loading || !insights ? (
          <div className="animate-pulse space-y-2.5">
            {[160, 200, 140, 180].map((w, i) => (
              <div key={i} className="flex items-start gap-2.5">
                <div className="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-muted" />
                <div className="h-3.5 rounded bg-muted" style={{ width: w }} />
              </div>
            ))}
          </div>
        ) : (
          <ol className="space-y-2">
            {insights.map((ins, i) => {
              const cfg  = LEVEL_CONFIG[ins.level];
              const Icon = cfg.icon;
              return (
                <li key={i} className="flex items-start gap-2.5">
                  <span className={cn('mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full', cfg.dot)} />
                  <span className={cn('text-sm leading-relaxed', cfg.text)}>
                    {ins.message}
                  </span>
                  {ins.level === 'alert' && (
                    <Icon className="mt-1 h-3.5 w-3.5 shrink-0 text-rose-400" />
                  )}
                </li>
              );
            })}
          </ol>
        )}
      </div>
    </div>
  );
}

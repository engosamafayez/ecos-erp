import { Sparkles } from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import type { ExecutiveDashboardData } from '@/features/dashboard/services/executive-dashboard.service';

interface Props {
  data?: ExecutiveDashboardData;
}

interface Insight {
  type:    'alert' | 'tip' | 'info';
  message: string;
}

function deriveInsights(data: ExecutiveDashboardData): Insight[] {
  const insights: Insight[] = [];
  const s = data.sales;
  const sh = data.shipping;
  const mk = data.marketing;

  // Revenue trend alert
  if (s.revenue_trend_pct !== null && s.revenue_trend_pct < -20) {
    insights.push({ type: 'alert', message: `Revenue is down ${Math.abs(s.revenue_trend_pct).toFixed(1)}% vs yesterday — review order pipeline.` });
  } else if (s.revenue_trend_pct !== null && s.revenue_trend_pct > 20) {
    insights.push({ type: 'info', message: `Strong day: revenue up ${s.revenue_trend_pct.toFixed(1)}% vs yesterday.` });
  }

  // High cancellations
  if (s.orders_today > 0 && s.cancelled_today / s.orders_today > 0.1) {
    insights.push({ type: 'alert', message: `${s.cancelled_today} cancellations today (${((s.cancelled_today / s.orders_today) * 100).toFixed(0)}% of orders) — investigate root cause.` });
  }

  // Shipping failures
  if (sh.failed_today > 0) {
    const failRate = sh.shipments_today > 0 ? (sh.failed_today / sh.shipments_today) * 100 : 100;
    insights.push({ type: failRate > 15 ? 'alert' : 'tip', message: `${sh.failed_today} failed deliveries today (${failRate.toFixed(0)}% failure rate). Review driver assignments.` });
  }

  // High ROAS signal
  if (mk.roas !== null && mk.roas > 4) {
    insights.push({ type: 'tip', message: `ROAS is ${mk.roas}× this month — consider increasing ad budget to capture more demand.` });
  } else if (mk.roas !== null && mk.roas < 1 && mk.spend_this_month > 0) {
    insights.push({ type: 'alert', message: `Marketing ROAS is below 1× — campaigns are losing money. Pause underperformers.` });
  }

  // COD pending
  if (sh.cod_pending > 10_000) {
    insights.push({ type: 'tip', message: `EGP ${sh.cod_pending.toLocaleString()} in pending COD collections — schedule driver settlements.` });
  }

  // Pending orders accumulating
  if (s.pending_count > 50) {
    insights.push({ type: 'tip', message: `${s.pending_count} orders in Pending state — assign to preparation waves to clear the queue.` });
  }

  // No data fallback
  if (insights.length === 0) {
    if (s.orders_today === 0) {
      insights.push({ type: 'info', message: 'No orders yet today. Dashboard will update as orders flow in.' });
    } else {
      insights.push({ type: 'info', message: `${s.orders_today} orders today, EGP ${s.revenue_today.toLocaleString()} revenue. Operations on track.` });
    }
  }

  return insights.slice(0, 4);
}

const BADGE: Record<Insight['type'], { label: string; cls: string }> = {
  alert: { label: 'Alert', cls: 'bg-rose-500/10 text-rose-600 dark:text-rose-400 border-rose-500/20' },
  tip:   { label: 'Tip',   cls: 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/20' },
  info:  { label: 'Info',  cls: 'bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 border-indigo-500/20' },
};

export function AiInsightsCard({ data }: Props) {
  const insights = data ? deriveInsights(data) : null;

  return (
    <Card className="h-full">
      <CardHeader className="pb-3">
        <CardTitle className="flex items-center gap-2 text-sm font-semibold">
          <Sparkles className="h-4 w-4 text-violet-500" />
          AI Operational Insights
          <Badge variant="outline" className="ml-auto text-[10px] text-violet-600 dark:text-violet-400 border-violet-500/30">
            Rule-based
          </Badge>
        </CardTitle>
      </CardHeader>
      <CardContent className="space-y-2.5">
        {!data ? (
          <div className="animate-pulse space-y-2.5">
            {[1, 2, 3].map(i => (
              <div key={i} className="flex items-start gap-2.5 rounded-lg bg-muted/40 p-3">
                <div className="h-4 w-12 shrink-0 rounded bg-muted" />
                <div className="h-4 w-full rounded bg-muted" />
              </div>
            ))}
          </div>
        ) : (
          insights!.map((insight, i) => {
            const b = BADGE[insight.type];
            return (
              <div key={i} className="flex items-start gap-2.5 rounded-lg bg-muted/30 p-2.5 text-xs leading-relaxed">
                <span className={`mt-0.5 shrink-0 rounded-full border px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wider ${b.cls}`}>
                  {b.label}
                </span>
                <span className="text-foreground/80">{insight.message}</span>
              </div>
            );
          })
        )}
      </CardContent>
    </Card>
  );
}

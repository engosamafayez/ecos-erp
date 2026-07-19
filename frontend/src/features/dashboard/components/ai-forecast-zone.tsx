import { Brain, FlaskConical, TrendingUp, Zap } from 'lucide-react';
import { Badge } from '@/components/ui/badge';

// ── Planned features ───────────────────────────────────────────────────────

const FEATURES = [
  { icon: TrendingUp, label: 'Demand Forecast',          description: 'Predict order volume by SKU, channel, and region' },
  { icon: Zap,        label: 'Purchase Suggestions',     description: 'Auto-generate POs based on reorder thresholds' },
  { icon: Brain,      label: 'Inventory Optimization',   description: 'Reduce overstock and stockouts using ML' },
  { icon: FlaskConical, label: 'Campaign Recommendations', description: 'Identify best-performing creatives and audiences' },
  { icon: TrendingUp, label: 'Cash Flow Prediction',     description: 'Forecast 30 / 60 / 90-day cash position' },
];

// ── Component ──────────────────────────────────────────────────────────────

export function AiForecastZone() {
  return (
    <div className="rounded-xl border border-dashed border-violet-500/20 bg-violet-500/[0.02] p-5">
      {/* Header */}
      <div className="mb-4 flex items-center gap-2.5">
        <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-violet-500/10">
          <Brain className="h-4 w-4 text-violet-500" />
        </div>
        <div className="flex-1">
          <div className="flex items-center gap-2">
            <h3 className="text-sm font-semibold">AI Intelligence Layer</h3>
            <Badge variant="outline" className="border-violet-500/30 text-[10px] text-violet-600 dark:text-violet-400">
              Reserved
            </Badge>
          </div>
          <p className="text-[11px] text-muted-foreground">
            Predictive intelligence powered by operational data — in development.
          </p>
        </div>
      </div>

      {/* Features grid */}
      <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-5">
        {FEATURES.map((f) => (
          <div
            key={f.label}
            className="flex items-start gap-2.5 rounded-lg border border-dashed border-violet-500/10 bg-background/40 p-3"
          >
            <div className="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded bg-violet-500/10">
              <f.icon className="h-3 w-3 text-violet-500/60" />
            </div>
            <div>
              <p className="text-xs font-medium text-foreground/70">{f.label}</p>
              <p className="mt-0.5 text-[10px] leading-relaxed text-muted-foreground/60">{f.description}</p>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

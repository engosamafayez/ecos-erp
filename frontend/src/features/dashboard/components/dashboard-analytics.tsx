import { Brain, Sparkles } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { MonthlyProgress }  from './monthly-progress';
import { OperationsCenter } from './operations-center';
import type { ExecutiveDashboardData } from '../services/executive-dashboard.service';

// ── AI Reserved zone ───────────────────────────────────────────────────────

const AI_FEATURES = [
  { label: 'Demand Forecast',         desc: 'Predict stock needs 14 days ahead' },
  { label: 'Purchase Suggestions',    desc: 'Auto-generate reorder recommendations' },
  { label: 'Revenue Prediction',      desc: 'Forecast next-week revenue by channel' },
  { label: 'Campaign Recommendations',desc: 'Optimal budget allocation across ads' },
  { label: 'Cash Flow Prediction',    desc: 'Weekly COD + inbound cash model' },
  { label: 'Customer Churn Risk',     desc: 'Flag high-value customers going quiet' },
  { label: 'Inventory Optimisation',  desc: 'Rebalance stock across warehouses' },
];

function AiReservedZone() {
  return (
    <div className="rounded-xl border border-dashed border-violet-500/20 bg-violet-500/[0.02] p-5">
      <div className="mb-4 flex items-center gap-2">
        <Brain className="h-4 w-4 text-violet-500" />
        <span className="text-[10px] font-bold uppercase tracking-[0.12em] text-violet-600 dark:text-violet-400">
          AI Intelligence Layer
        </span>
        <Badge variant="outline" className="border-violet-500/30 text-[10px] text-violet-600 dark:text-violet-400">
          Planned
        </Badge>
      </div>
      <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
        {AI_FEATURES.map((f) => (
          <div
            key={f.label}
            className="rounded-lg border border-dashed border-violet-500/15 bg-violet-500/[0.02] p-3 opacity-60"
          >
            <div className="flex items-center gap-1.5 mb-1">
              <Sparkles className="h-3 w-3 text-violet-400" />
              <p className="text-[11px] font-semibold text-foreground/70">{f.label}</p>
            </div>
            <p className="text-[10px] text-muted-foreground">{f.desc}</p>
          </div>
        ))}
      </div>
    </div>
  );
}

// ── Component ──────────────────────────────────────────────────────────────

interface Props {
  data?:    ExecutiveDashboardData;
  loading?: boolean;
}

export function DashboardAnalytics({ data, loading }: Props) {
  return (
    <div className="space-y-6">
      {/* Monthly progress */}
      <MonthlyProgress data={data?.monthly} loading={loading} />

      {/* Operations center */}
      <OperationsCenter />

      {/* AI reserved space */}
      <AiReservedZone />
    </div>
  );
}

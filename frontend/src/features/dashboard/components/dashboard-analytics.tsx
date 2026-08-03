import { Brain, Sparkles } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { useTranslation } from 'react-i18next';
import { MonthlyProgress }  from './monthly-progress';
import type { ExecutiveDashboardData } from '../services/executive-dashboard.service';

// ── AI Reserved zone ───────────────────────────────────────────────────────

function AiReservedZone() {
  const { t } = useTranslation('dashboard');

  const AI_FEATURES = [
    { labelKey: 'analytics.features.demandForecast'      as const, descKey: 'analytics.features.demandForecastDesc'      as const },
    { labelKey: 'analytics.features.purchaseSuggestions' as const, descKey: 'analytics.features.purchaseSuggestionsDesc' as const },
    { labelKey: 'analytics.features.revenuePrediction'   as const, descKey: 'analytics.features.revenuePredictionDesc'   as const },
    { labelKey: 'analytics.features.campaignRec'         as const, descKey: 'analytics.features.campaignRecDesc'         as const },
    { labelKey: 'analytics.features.cashFlow'            as const, descKey: 'analytics.features.cashFlowDesc'            as const },
    { labelKey: 'analytics.features.churnRisk'           as const, descKey: 'analytics.features.churnRiskDesc'           as const },
    { labelKey: 'analytics.features.inventoryOpt'        as const, descKey: 'analytics.features.inventoryOptDesc'        as const },
  ];

  return (
    <div className="rounded-xl border border-dashed border-violet-500/20 bg-violet-500/[0.02] p-5">
      <div className="mb-4 flex items-center gap-2">
        <Brain className="h-4 w-4 text-violet-500" />
        <span className="text-[10px] font-bold uppercase tracking-[0.12em] text-violet-600 dark:text-violet-400">
          {t($ => $.analytics.aiLayer)}
        </span>
        <Badge variant="outline" className="border-violet-500/30 text-[10px] text-violet-600 dark:text-violet-400">
          {t($ => $.analytics.aiPlanned)}
        </Badge>
      </div>
      <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
        {AI_FEATURES.map((f) => (
          <div
            key={f.labelKey}
            className="rounded-lg border border-dashed border-violet-500/15 bg-violet-500/[0.02] p-3 opacity-60"
          >
            <div className="flex items-center gap-1.5 mb-1">
              <Sparkles className="h-3 w-3 text-violet-400" />
              <p className="text-[11px] font-semibold text-foreground/70">{t(f.labelKey)}</p>
            </div>
            <p className="text-[10px] text-muted-foreground">{t(f.descKey)}</p>
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

      {/* AI reserved space */}
      <AiReservedZone />
    </div>
  );
}

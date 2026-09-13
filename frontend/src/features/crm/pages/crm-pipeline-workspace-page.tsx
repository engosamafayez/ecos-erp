import { useMemo, useState } from 'react';
import { Plus } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { SmartToolbar } from '@/components/data-grid/smart-toolbar';
import { usePermission } from '@/features/authorization';
import { CrmOpportunityCard } from '@/features/crm/components/crm-opportunity-card';
import { CrmOpportunityFormDrawer } from '@/features/crm/components/crm-opportunity-form-drawer';
import {
  useCrmOpportunitiesQuery,
  useCrmPipelinesQuery,
} from '@/features/crm/hooks/use-crm-pipeline';
import type { CrmOpportunity, CrmOpportunityStatus } from '@/features/crm/types/crm-pipeline';
import type enCrm from '@/i18n/locales/en/crm.json';

type CrmLabel = ($: typeof enCrm) => string;

const STATUS_FILTERS: { value: CrmOpportunityStatus | 'all'; label: CrmLabel }[] = [
  { value: 'open', label: ($) => $.pipeline.status.open },
  { value: 'won', label: ($) => $.pipeline.status.won },
  { value: 'lost', label: ($) => $.pipeline.status.lost },
  { value: 'all', label: ($) => $.leads.filters.allStatuses },
];

/**
 * CRM-01 Task 2 — Pipeline workspace: a board over the already-complete
 * `Crm\Sales` Pipeline/Opportunity backend. Stage movement calls ONLY the
 * canonical, now company/pipeline-scoped `moveStage` transition — see
 * CrmOpportunityCard and the CRM-01 Task 2 report.
 */
export function CrmPipelineWorkspacePage() {
  const { t } = useTranslation('crm');
  const { can } = usePermission();

  const { data: pipelines, isLoading: pipelinesLoading } = useCrmPipelinesQuery();
  const [pipelineId, setPipelineId] = useState<string | null>(null);
  const [status, setStatus] = useState<CrmOpportunityStatus | 'all'>('open');
  const [search, setSearch] = useState('');
  const [formOpen, setFormOpen] = useState(false);

  const activePipeline = useMemo(() => {
    if (!pipelines || pipelines.length === 0) return null;
    if (pipelineId) return pipelines.find((p) => p.id === pipelineId) ?? pipelines[0];
    return pipelines.find((p) => p.is_default) ?? pipelines[0];
  }, [pipelines, pipelineId]);

  const params = useMemo(
    () => ({
      pipeline_id: activePipeline?.id,
      status,
      q: search.trim() || undefined,
    }),
    [activePipeline?.id, status, search],
  );

  const { data: opportunities, isLoading, isError, isFetching, refetch } = useCrmOpportunitiesQuery(
    params,
    Boolean(activePipeline),
  );

  const stages = useMemo(() => activePipeline?.stages ?? [], [activePipeline]);
  const byStage = useMemo(() => {
    const map = new Map<string, CrmOpportunity[]>();
    for (const stage of stages) map.set(stage.id, []);
    for (const o of opportunities ?? []) {
      if (o.stage_id === null) continue;
      const bucket = map.get(o.stage_id);
      if (bucket) bucket.push(o);
      else map.set(o.stage_id, [o]);
    }
    return map;
  }, [opportunities, stages]);

  return (
    <div className="flex flex-col gap-4 p-4 sm:p-6">
      <div>
        <h1 className="text-xl font-semibold">{t(($) => $.pipeline.title)}</h1>
        <p className="text-sm text-muted-foreground">{t(($) => $.pipeline.subtitle)}</p>
      </div>

      <SmartToolbar
        primaryAction={
          can('crm.sales.manage') && activePipeline
            ? { label: t(($) => $.pipeline.toolbar.newOpportunity), onClick: () => setFormOpen(true), icon: Plus }
            : undefined
        }
        onRefresh={() => void refetch()}
        isFetching={isFetching}
        refreshLabel={t(($) => $.toolbar.refresh)}
        viewControls={
          <div className="flex flex-wrap items-center gap-2">
            {pipelines && pipelines.length > 1 && (
              <select
                value={activePipeline?.id ?? ''}
                onChange={(e) => setPipelineId(e.target.value)}
                aria-label={t(($) => $.pipeline.selectPipeline)}
                className="h-9 rounded-md border bg-background px-2 text-sm"
              >
                {pipelines.map((p) => (
                  <option key={p.id} value={p.id}>
                    {p.name}
                  </option>
                ))}
              </select>
            )}
            <input
              type="search"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder={t(($) => $.pipeline.toolbar.searchPlaceholder)}
              aria-label={t(($) => $.pipeline.toolbar.searchPlaceholder)}
              className="h-9 w-full min-w-[12rem] rounded-md border bg-background px-3 text-sm sm:w-64"
            />
            <select
              value={status}
              onChange={(e) => setStatus(e.target.value as CrmOpportunityStatus | 'all')}
              aria-label={t(($) => $.pipeline.status.open)}
              className="h-9 rounded-md border bg-background px-2 text-sm"
            >
              {STATUS_FILTERS.map((f) => (
                <option key={f.value} value={f.value}>
                  {t(f.label)}
                </option>
              ))}
            </select>
          </div>
        }
      />

      {pipelinesLoading ? (
        <p className="py-8 text-center text-sm text-muted-foreground">{t(($) => $.leads.loading)}</p>
      ) : !activePipeline ? (
        <div className="p-8 text-center">
          <p className="font-medium">{t(($) => $.pipeline.empty.noPipeline)}</p>
        </div>
      ) : isError ? (
        <div className="p-8 text-center">
          <p className="font-medium">{t(($) => $.error.title)}</p>
          <p className="mt-1 text-sm text-muted-foreground">{t(($) => $.error.body)}</p>
        </div>
      ) : isLoading ? (
        <p className="py-8 text-center text-sm text-muted-foreground">{t(($) => $.leads.loading)}</p>
      ) : (opportunities ?? []).length === 0 ? (
        <div className="p-8 text-center">
          <p className="font-medium">{t(($) => $.pipeline.empty.title)}</p>
          <p className="mt-1 text-sm text-muted-foreground">{t(($) => $.pipeline.empty.body)}</p>
        </div>
      ) : (
        <div className="flex gap-4 overflow-x-auto pb-2">
          {stages.map((stage) => {
            const rows = byStage.get(stage.id) ?? [];
            const otherStages = stages.filter((s) => s.id !== stage.id);

            return (
              <div key={stage.id} className="flex w-72 shrink-0 flex-col gap-2 rounded-lg border bg-muted/30 p-2">
                <div className="flex items-center justify-between px-1">
                  <h3 className="text-sm font-semibold">{stage.name}</h3>
                  <span className="text-xs text-muted-foreground">{rows.length}</span>
                </div>
                <div className="flex flex-col gap-2">
                  {rows.map((o) => (
                    <CrmOpportunityCard key={o.id} opportunity={o} otherStages={otherStages} />
                  ))}
                </div>
              </div>
            );
          })}
        </div>
      )}

      <CrmOpportunityFormDrawer
        open={formOpen}
        onOpenChange={setFormOpen}
        pipelineId={activePipeline?.id ?? null}
        onCreated={() => void refetch()}
      />
    </div>
  );
}

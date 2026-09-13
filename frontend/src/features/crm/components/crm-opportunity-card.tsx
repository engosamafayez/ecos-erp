import { useTranslation } from 'react-i18next';

import { Badge } from '@/components/ui/badge';
import { usePermission } from '@/features/authorization';
import { useMoveCrmOpportunityStage } from '@/features/crm/hooks/use-crm-pipeline';
import { ROUTES } from '@/router/routes';
import type { CrmOpportunity, CrmOpportunityStatus, CrmPipelineStage } from '@/features/crm/types/crm-pipeline';
import type enCrm from '@/i18n/locales/en/crm.json';

type CrmLabel = ($: typeof enCrm) => string;

const STATUS_LABEL: Record<CrmOpportunityStatus, CrmLabel> = {
  open: ($) => $.pipeline.status.open,
  won: ($) => $.pipeline.status.won,
  lost: ($) => $.pipeline.status.lost,
};

function fmtMoney(n: number) {
  return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

type Props = {
  opportunity: CrmOpportunity;
  /** Every OTHER stage in the same pipeline — the only valid move targets. */
  otherStages: CrmPipelineStage[];
};

/**
 * CRM-01 Task 2 — one Pipeline board card. Moving it calls ONLY the canonical
 * `moveStage` transition (OpportunityService::moveToStage) — nothing here
 * touches Lead/Customer/Task state; a win/lose stage's own canonical
 * `is_won`/`is_lost` semantics are what the backend already uses to decide
 * whether a move also closes the deal (see CRM-01 Task 2 report, "Board
 * movement semantics").
 */
export function CrmOpportunityCard({ opportunity: o, otherStages }: Props) {
  const { t } = useTranslation('crm');
  const { can } = usePermission();
  const move = useMoveCrmOpportunityStage();

  return (
    <div className="flex flex-col gap-2 rounded-md border bg-card p-3 text-sm shadow-sm">
      <div className="flex items-start justify-between gap-2">
        <span className="font-medium">{o.name}</span>
        {o.status !== 'open' && (
          <Badge variant={o.status === 'won' ? 'secondary' : 'outline'} className="text-[10px]">
            {t(STATUS_LABEL[o.status])}
          </Badge>
        )}
      </div>

      <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
        <span className="tabular-nums">{fmtMoney(o.amount)} {o.currency}</span>
        {o.expected_close_date && <span>{new Date(o.expected_close_date).toLocaleDateString()}</span>}
      </div>

      <div className="flex flex-wrap items-center gap-1.5">
        {o.lead_id && (
          <a
            className="text-xs text-primary underline"
            href={`${ROUTES.crmLeads}?open=${o.lead_id}`}
          >
            {t(($) => $.pipeline.card.viewLead)}
          </a>
        )}
        {o.customer_id && (
          <a
            className="text-xs text-primary underline"
            href={`${ROUTES.crmCustomers}?open=${o.customer_id}`}
          >
            {t(($) => $.pipeline.card.viewCustomer)}
          </a>
        )}
      </div>

      {o.owner_id !== null && (
        <span className="text-[11px] text-muted-foreground">
          {t(($) => $.pipeline.card.owner)}: {o.owner_id}
        </span>
      )}

      {o.status === 'open' && can('crm.sales.manage') && otherStages.length > 0 && (
        <select
          className="h-8 rounded-md border bg-background px-2 text-xs"
          value=""
          onChange={(e) => {
            if (e.target.value) move.mutate({ id: o.id, stageId: e.target.value });
          }}
          disabled={move.isPending}
          aria-label={t(($) => $.pipeline.card.moveTo)}
        >
          <option value="">{t(($) => $.pipeline.card.moveTo)}</option>
          {otherStages.map((s) => (
            <option key={s.id} value={s.id}>
              {s.name}
            </option>
          ))}
        </select>
      )}
    </div>
  );
}

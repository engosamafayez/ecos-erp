import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { EntityDrawer } from '@/components/crud/entity-drawer';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { usePermission } from '@/features/authorization';
import { ROUTES } from '@/router/routes';
import {
  useCancelCrmLeadActivity,
  useCompleteCrmLeadActivity,
  useConvertCrmLead,
  useCreateCrmLeadActivity,
  useCrmLeadActivitiesQuery,
  useCrmLeadQuery,
  useSetCrmLeadStatus,
} from '@/features/crm/hooks/use-crm-leads';
import type { CrmLeadStatus, CrmSalesActivity } from '@/features/crm/types/crm-lead';
import type enCrm from '@/i18n/locales/en/crm.json';

type CrmLabel = ($: typeof enCrm) => string;

const STATUS_LABEL: Record<CrmLeadStatus, CrmLabel> = {
  new: ($) => $.leads.status.new,
  contacted: ($) => $.leads.status.contacted,
  qualified: ($) => $.leads.status.qualified,
  unqualified: ($) => $.leads.status.unqualified,
  converted: ($) => $.leads.status.converted,
};

function Field({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-0.5">
      <span className="text-[11px] uppercase tracking-wide text-muted-foreground">{label}</span>
      <span className="text-sm">{value}</span>
    </div>
  );
}

function ActivityRow({ activity, leadId }: { activity: CrmSalesActivity; leadId: string }) {
  const { t } = useTranslation('crm');
  const { can } = usePermission();
  const complete = useCompleteCrmLeadActivity(leadId);
  const cancel = useCancelCrmLeadActivity(leadId);
  const canManage = can('crm.sales.manage');

  return (
    <li className="flex flex-wrap items-center justify-between gap-2 rounded-md border p-3">
      <div className="flex flex-col gap-1">
        <span className="text-sm font-medium">{activity.title}</span>
        <div className="flex flex-wrap items-center gap-1.5">
          <Badge variant="outline" className="text-[10px]">
            {activity.activity_type}
          </Badge>
          {activity.due_at && (
            <span className="text-xs text-muted-foreground">
              {new Date(activity.due_at).toLocaleString()}
            </span>
          )}
        </div>
      </div>
      {activity.status === 'planned' && canManage && (
        <div className="flex gap-1.5">
          <Button size="sm" variant="outline" disabled={complete.isPending} onClick={() => complete.mutate(activity.id)}>
            {t(($) => $.leads.activity.complete)}
          </Button>
          <Button size="sm" variant="ghost" disabled={cancel.isPending} onClick={() => cancel.mutate(activity.id)}>
            {t(($) => $.leads.activity.cancel)}
          </Button>
        </div>
      )}
    </li>
  );
}

function ConvertForm({ leadId, onDone }: { leadId: string; onDone: (customerId: string) => void }) {
  const { t } = useTranslation('crm');
  const [name, setName] = useState('');
  const [amount, setAmount] = useState('');
  const convert = useConvertCrmLead(leadId);

  return (
    <form
      className="flex flex-col gap-2 rounded-md border border-dashed p-3"
      onSubmit={(e) => {
        e.preventDefault();
        convert.mutate(
          { opportunity_name: name || null, amount: amount ? Number(amount) : null },
          { onSuccess: (result) => onDone(result.customer_id) },
        );
      }}
    >
      <p className="text-sm font-medium">{t(($) => $.leads.convert.title)}</p>
      <Input
        value={name}
        onChange={(e) => setName(e.target.value)}
        placeholder={t(($) => $.leads.convert.opportunityName)}
        className="h-9"
      />
      <Input
        type="number"
        value={amount}
        onChange={(e) => setAmount(e.target.value)}
        placeholder={t(($) => $.leads.convert.amount)}
        className="h-9"
      />
      <Button type="submit" disabled={convert.isPending}>
        {t(($) => $.leads.convert.action)}
      </Button>
    </form>
  );
}

function CreateActivityForm({ leadId }: { leadId: string }) {
  const { t } = useTranslation('crm');
  const [title, setTitle] = useState('');
  const [dueAt, setDueAt] = useState('');
  const create = useCreateCrmLeadActivity(leadId);

  return (
    <form
      className="flex flex-wrap items-end gap-2 rounded-md border border-dashed p-3"
      onSubmit={(e) => {
        e.preventDefault();
        if (!title.trim()) return;
        create.mutate(
          { activity_type: 'call', title: title.trim(), due_at: dueAt || null },
          { onSuccess: () => { setTitle(''); setDueAt(''); } },
        );
      }}
    >
      <div className="flex flex-1 flex-col gap-1">
        <label className="text-[11px] uppercase tracking-wide text-muted-foreground">
          {t(($) => $.leads.activity.newTitle)}
        </label>
        <Input value={title} onChange={(e) => setTitle(e.target.value)} className="h-9" />
      </div>
      <div className="flex flex-col gap-1">
        <label className="text-[11px] uppercase tracking-wide text-muted-foreground">
          {t(($) => $.leads.activity.newDue)}
        </label>
        <Input type="datetime-local" value={dueAt} onChange={(e) => setDueAt(e.target.value)} className="h-9" />
      </div>
      <Button type="submit" disabled={!title.trim() || create.isPending}>
        {t(($) => $.leads.activity.create)}
      </Button>
    </form>
  );
}

type Props = {
  leadId: string | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
};

/**
 * Lead 360 (CRM-01 Task 1) — identity, source, status, activities and the
 * post-conversion link into the canonical Customer 360, against the
 * already-complete `Crm\Sales\LeadController` contract. No new Lead engine,
 * no cep_leads bridging (see CRM-01 report, "Lead authority").
 */
export function CrmLeadDrawer({ leadId, open, onOpenChange }: Props) {
  const { t } = useTranslation('crm');
  const { can } = usePermission();
  const [showConvert, setShowConvert] = useState(false);

  const { data: lead, isLoading } = useCrmLeadQuery(open ? leadId : null);
  const { data: activities, isLoading: activitiesLoading } = useCrmLeadActivitiesQuery(leadId, open);
  const setStatus = useSetCrmLeadStatus(leadId ?? '');
  const canManage = can('crm.sales.manage');

  return (
    <EntityDrawer
      open={open}
      onOpenChange={(next) => {
        onOpenChange(next);
        if (!next) setShowConvert(false);
      }}
      title={lead?.name ?? t(($) => $.leads.detailsTitle)}
      description={lead?.company_name ?? undefined}
    >
      {isLoading || !lead ? (
        <p className="py-6 text-center text-sm text-muted-foreground">{t(($) => $.leads.loading)}</p>
      ) : (
        <div className="flex flex-col gap-5">
          <div className="flex flex-wrap items-center gap-2">
            <Badge variant={lead.status === 'converted' ? 'secondary' : 'outline'}>
              {t(STATUS_LABEL[lead.status])}
            </Badge>
            {lead.source && <Badge variant="outline">{lead.source}</Badge>}
          </div>

          {lead.status === 'converted' && lead.customer_id ? (
            <div className="rounded-md border border-emerald-500/30 bg-emerald-500/5 p-3 text-sm">
              <p className="font-medium">{t(($) => $.leads.convertedNotice)}</p>
              <a
                className="text-primary underline"
                href={`${ROUTES.crmCustomers}?open=${lead.customer_id}`}
              >
                {t(($) => $.leads.viewCustomer)}
              </a>
            </div>
          ) : (
            canManage && (
              <div className="flex flex-wrap gap-1.5">
                {(['contacted', 'qualified', 'unqualified'] as const)
                  .filter((s) => s !== lead.status)
                  .map((s) => (
                    <Button
                      key={s}
                      size="sm"
                      variant="outline"
                      disabled={setStatus.isPending}
                      onClick={() => setStatus.mutate(s)}
                    >
                      {t(STATUS_LABEL[s])}
                    </Button>
                  ))}
                {lead.status === 'qualified' && (
                  <Button size="sm" onClick={() => setShowConvert((v) => !v)}>
                    {t(($) => $.leads.convert.action)}
                  </Button>
                )}
              </div>
            )
          )}

          {showConvert && lead.status === 'qualified' && (
            <ConvertForm leadId={lead.id} onDone={() => setShowConvert(false)} />
          )}

          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <Field label={t(($) => $.leads.fields.phone)} value={lead.phone ?? '—'} />
            <Field label={t(($) => $.leads.fields.email)} value={lead.email ?? '—'} />
            <Field label={t(($) => $.leads.fields.company)} value={lead.company_name ?? '—'} />
            <Field label={t(($) => $.leads.fields.owner)} value={lead.owner_id ?? t(($) => $.portfolio.owner.unassigned)} />
            <Field label={t(($) => $.leads.fields.score)} value={lead.score ?? '—'} />
          </div>

          <section>
            <h3 className="mb-2 text-sm font-semibold">{t(($) => $.leads.activity.title)}</h3>
            {activitiesLoading ? (
              <p className="py-4 text-center text-sm text-muted-foreground">{t(($) => $.leads.loading)}</p>
            ) : !activities || activities.length === 0 ? (
              <p className="py-4 text-center text-sm text-muted-foreground">{t(($) => $.leads.activity.none)}</p>
            ) : (
              <ul className="flex flex-col gap-2">
                {activities.map((a) => (
                  <ActivityRow key={a.id} activity={a} leadId={lead.id} />
                ))}
              </ul>
            )}
          </section>

          {canManage && <CreateActivityForm leadId={lead.id} />}
        </div>
      )}
    </EntityDrawer>
  );
}

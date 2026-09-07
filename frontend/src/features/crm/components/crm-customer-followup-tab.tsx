import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { usePermission } from '@/features/authorization';
import {
  useCancelCrmTask,
  useCompleteCrmTask,
  useCreateCrmTask,
  useCrmCustomerTasksQuery,
} from '@/features/crm/hooks/use-crm-customers';
import type {
  CrmBlockedState,
  CrmEngagementSummary,
  CrmFinanceSummary,
  CrmFollowUpQueue,
  CrmPortfolioSection,
  CrmTask,
} from '@/features/crm/types/crm-customer';
import type enCrm from '@/i18n/locales/en/crm.json';

type CrmLabel = ($: typeof enCrm) => string;

const QUEUE_LABEL: Record<CrmFollowUpQueue, CrmLabel> = {
  overdue: ($) => $.portfolio.queue.overdue,
  due_today: ($) => $.portfolio.queue.dueToday,
  upcoming: ($) => $.portfolio.queue.upcoming,
  unscheduled: ($) => $.portfolio.queue.unscheduled,
};

function fmtMoney(n: number | null | undefined) {
  return typeof n === 'number'
    ? n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
    : '—';
}

function TaskRow({ task, customerId }: { task: CrmTask; customerId: string }) {
  const { t } = useTranslation('crm');
  const { can } = usePermission();
  const complete = useCompleteCrmTask(customerId);
  const cancel = useCancelCrmTask(customerId);
  const canManage = can('crm.engagement.task.manage');

  return (
    <li className="flex flex-wrap items-center justify-between gap-2 rounded-md border p-3">
      <div className="flex flex-col gap-1">
        <span className="text-sm font-medium">{task.title}</span>
        <div className="flex flex-wrap items-center gap-1.5">
          {task.queue && (
            <Badge variant="outline" className="text-[10px]">
              {t(QUEUE_LABEL[task.queue])}
            </Badge>
          )}
          {task.priority_valid ? (
            <Badge variant="secondary" className="text-[10px]">
              {task.priority}
            </Badge>
          ) : (
            task.priority && (
              <Badge variant="outline" className="text-[10px]" title={t(($) => $.followUp.unknownPriority)}>
                {task.priority}
              </Badge>
            )
          )}
          {task.due_at && (
            <span className="text-xs text-muted-foreground">
              {new Date(task.due_at).toLocaleString()}
            </span>
          )}
        </div>
      </div>
      {task.status === 'open' && canManage && (
        <div className="flex gap-1.5">
          <Button
            size="sm"
            variant="outline"
            disabled={complete.isPending}
            onClick={() => complete.mutate(task.id)}
          >
            {t(($) => $.followUp.complete)}
          </Button>
          <Button
            size="sm"
            variant="ghost"
            disabled={cancel.isPending}
            onClick={() => cancel.mutate(task.id)}
          >
            {t(($) => $.followUp.cancel)}
          </Button>
        </div>
      )}
    </li>
  );
}

function CreateFollowUpForm({ customerId }: { customerId: string }) {
  const { t } = useTranslation('crm');
  const [title, setTitle] = useState('');
  const [dueAt, setDueAt] = useState('');
  const create = useCreateCrmTask(customerId);

  return (
    <form
      className="flex flex-wrap items-end gap-2 rounded-md border border-dashed p-3"
      onSubmit={(e) => {
        e.preventDefault();
        if (!title.trim()) return;
        create.mutate(
          { task_type: 'follow_up', title: title.trim(), due_at: dueAt || null },
          { onSuccess: () => { setTitle(''); setDueAt(''); } },
        );
      }}
    >
      <div className="flex flex-1 flex-col gap-1">
        <label htmlFor={`followup-title-${customerId}`} className="text-[11px] uppercase tracking-wide text-muted-foreground">
          {t(($) => $.followUp.newTitle)}
        </label>
        <Input
          id={`followup-title-${customerId}`}
          value={title}
          onChange={(e) => setTitle(e.target.value)}
          className="h-9"
        />
      </div>
      <div className="flex flex-col gap-1">
        <label htmlFor={`followup-due-${customerId}`} className="text-[11px] uppercase tracking-wide text-muted-foreground">
          {t(($) => $.followUp.newDue)}
        </label>
        <Input
          id={`followup-due-${customerId}`}
          type="datetime-local"
          value={dueAt}
          onChange={(e) => setDueAt(e.target.value)}
          className="h-9"
        />
      </div>
      <Button type="submit" disabled={!title.trim() || create.isPending}>
        {t(($) => $.followUp.create)}
      </Button>
    </form>
  );
}

type Props = {
  customerId: string;
  crm: CrmPortfolioSection;
  finance: CrmFinanceSummary;
  blocked: CrmBlockedState;
  engagement: CrmEngagementSummary;
};

/**
 * The bounded CRM section of Customer 360 (TASK-ECOS-CRM-CUSTOMER-PORTFOLIO-
 * AND-FOLLOWUP-003 §15/§18) — a summary with an action entry point, not a
 * duplicate of the full Portfolio page. Also surfaces Gate B's finance/
 * blocked/engagement facts, which had no frontend consumer until now.
 */
export function CrmCustomerFollowUpTab({ customerId, crm, finance, blocked, engagement }: Props) {
  const { t } = useTranslation('crm');
  const { can } = usePermission();
  const { data: tasks, isLoading } = useCrmCustomerTasksQuery(customerId, true);
  const openTasks = (tasks ?? []).filter((task) => task.status === 'open');

  return (
    <div className="flex flex-col gap-5">
      {blocked.is_blocked && (
        <div className="rounded-md border border-destructive/40 bg-destructive/5 p-3 text-sm">
          <p className="font-medium text-destructive">{t(($) => $.status.blocked)}</p>
          {blocked.reason && <p className="text-muted-foreground">{blocked.reason}</p>}
        </div>
      )}

      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
        <div className="rounded-md border p-3">
          <p className="text-[11px] uppercase tracking-wide text-muted-foreground">
            {t(($) => $.followUp.owner)}
          </p>
          <p className="mt-1 text-sm font-semibold">
            {crm.owner.name ?? t(($) => $.portfolio.owner.unassigned)}
          </p>
        </div>
        <div className="rounded-md border p-3">
          <p className="text-[11px] uppercase tracking-wide text-muted-foreground">
            {t(($) => $.followUp.balance)}
          </p>
          <p className="mt-1 text-sm font-semibold tabular-nums">{fmtMoney(finance.balance)}</p>
        </div>
        <div className="rounded-md border p-3">
          <p className="text-[11px] uppercase tracking-wide text-muted-foreground">
            {t(($) => $.followUp.lastEngagement)}
          </p>
          <p className="mt-1 text-sm font-semibold">
            {engagement.last_conversation_at
              ? new Date(engagement.last_conversation_at).toLocaleDateString()
              : '—'}
          </p>
        </div>
      </div>

      <section>
        <h3 className="mb-2 text-sm font-semibold">{t(($) => $.followUp.openFollowUps)}</h3>
        {isLoading ? (
          <p className="py-4 text-center text-sm text-muted-foreground">{t(($) => $.followUp.loading)}</p>
        ) : openTasks.length === 0 ? (
          <p className="py-4 text-center text-sm text-muted-foreground">{t(($) => $.followUp.none)}</p>
        ) : (
          <ul className="flex flex-col gap-2">
            {openTasks.map((task) => (
              <TaskRow key={task.id} task={task} customerId={customerId} />
            ))}
          </ul>
        )}
      </section>

      {can('crm.engagement.task.manage') && <CreateFollowUpForm customerId={customerId} />}
    </div>
  );
}

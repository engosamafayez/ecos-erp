import { useTranslation } from 'react-i18next';

import { Badge } from '@/components/ui/badge';
import { useCrmMyWorkQuery } from '@/features/crm/hooks/use-crm-my-work';
import { ROUTES } from '@/router/routes';
function Section({ title, count, children }: { title: string; count: number; children: React.ReactNode }) {
  return (
    <section className="flex flex-col gap-2 rounded-lg border p-4">
      <div className="flex items-center justify-between">
        <h2 className="text-sm font-semibold">{title}</h2>
        <Badge variant="outline">{count}</Badge>
      </div>
      {children}
    </section>
  );
}

function Empty({ message }: { message: string }) {
  return <p className="py-4 text-center text-sm text-muted-foreground">{message}</p>;
}

function Row({ children, href }: { children: React.ReactNode; href?: string }) {
  const body = <div className="rounded-md border p-2.5 text-sm">{children}</div>;
  return href ? (
    <a href={href} className="block hover:bg-accent/50">
      {body}
    </a>
  ) : (
    body
  );
}

/**
 * CRM-01 Task 2 — "My Work": the current user's assigned CRM work, composed
 * read-only from existing authorities (see MyWorkController). This is a
 * workflow filter over the same rows the Leads/Opportunities/Portfolio/Tasks
 * screens already show — not a new access boundary.
 */
export function CrmMyWorkPage() {
  const { t } = useTranslation('crm');
  const { data, isLoading, isError } = useCrmMyWorkQuery();

  if (isLoading) {
    return <p className="p-6 text-center text-sm text-muted-foreground">{t(($) => $.leads.loading)}</p>;
  }

  if (isError || !data) {
    return (
      <div className="p-8 text-center">
        <p className="font-medium">{t(($) => $.error.title)}</p>
        <p className="mt-1 text-sm text-muted-foreground">{t(($) => $.error.body)}</p>
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-4 p-4 sm:p-6">
      <div>
        <h1 className="text-xl font-semibold">{t(($) => $.myWork.title)}</h1>
        <p className="text-sm text-muted-foreground">{t(($) => $.myWork.subtitle)}</p>
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Section title={t(($) => $.myWork.sections.leads)} count={data.leads.length}>
          {data.leads.length === 0 ? (
            <Empty message={t(($) => $.myWork.empty.leads)} />
          ) : (
            <div className="flex flex-col gap-1.5">
              {data.leads.map((l) => (
                <Row key={l.id} href={`${ROUTES.crmLeads}?open=${l.id}`}>
                  <div className="flex items-center justify-between gap-2">
                    <span className="font-medium">{l.name}</span>
                    <Badge variant="outline" className="text-[10px]">{l.status}</Badge>
                  </div>
                </Row>
              ))}
            </div>
          )}
        </Section>

        <Section title={t(($) => $.myWork.sections.opportunities)} count={data.opportunities.length}>
          {data.opportunities.length === 0 ? (
            <Empty message={t(($) => $.myWork.empty.opportunities)} />
          ) : (
            <div className="flex flex-col gap-1.5">
              {data.opportunities.map((o) => (
                <Row key={o.id} href={ROUTES.crmPipeline}>
                  <div className="flex items-center justify-between gap-2">
                    <span className="font-medium">{o.name}</span>
                    <span className="tabular-nums text-muted-foreground">{o.amount.toLocaleString()}</span>
                  </div>
                </Row>
              ))}
            </div>
          )}
        </Section>

        <Section title={t(($) => $.myWork.sections.dueActivities)} count={data.due_activities.length}>
          {data.due_activities.length === 0 ? (
            <Empty message={t(($) => $.myWork.empty.dueActivities)} />
          ) : (
            <div className="flex flex-col gap-1.5">
              {data.due_activities.map((a) => (
                <Row
                  key={a.id}
                  href={
                    a.subject_type === 'lead' ? `${ROUTES.crmLeads}?open=${a.subject_id}` : ROUTES.crmPipeline
                  }
                >
                  <div className="flex items-center justify-between gap-2">
                    <span className="font-medium">{a.title}</span>
                    {a.due_at && (
                      <span className="text-xs text-muted-foreground">
                        {new Date(a.due_at).toLocaleString()}
                      </span>
                    )}
                  </div>
                </Row>
              ))}
            </div>
          )}
        </Section>

        <Section title={t(($) => $.myWork.sections.customerFollowUps)} count={data.customer_follow_ups.length}>
          {data.customer_follow_ups.length === 0 ? (
            <Empty message={t(($) => $.myWork.empty.customerFollowUps)} />
          ) : (
            <div className="flex flex-col gap-1.5">
              {data.customer_follow_ups.map((c) => (
                <Row key={c.id} href={`${ROUTES.crmCustomers}?open=${c.id}`}>
                  <span className="font-medium">{c.name}</span>
                </Row>
              ))}
            </div>
          )}
        </Section>

        <Section title={t(($) => $.myWork.sections.internalTasks)} count={data.internal_tasks.length}>
          {data.internal_tasks.length === 0 ? (
            <Empty message={t(($) => $.myWork.empty.internalTasks)} />
          ) : (
            <div className="flex flex-col gap-1.5">
              {data.internal_tasks.map((task) => (
                <Row key={task.id}>
                  <div className="flex items-center justify-between gap-2">
                    <span className="font-medium">{task.title}</span>
                    <Badge variant="outline" className="text-[10px]">{task.priority}</Badge>
                  </div>
                </Row>
              ))}
            </div>
          )}
        </Section>
      </div>

      <p className="text-xs text-muted-foreground">{t(($) => $.myWork.internalTasksNote)}</p>
    </div>
  );
}

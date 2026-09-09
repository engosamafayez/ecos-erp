import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { AlertTriangle, ArrowLeft, ListChecks } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { TaskDetailDrawer } from '@/features/collaboration/components/task-detail-drawer';
import { TaskPriorityBadge } from '@/features/collaboration/components/task-priority-badge';
import { TaskStatusBadge } from '@/features/collaboration/components/task-status-badge';
import { useTasks } from '@/features/collaboration/hooks/use-tasks';
import { cn } from '@/lib/utils';
import { ROUTES } from '@/router/routes';

/**
 * Driver-facing internal tasks — the EXISTING DriverShell's own nav gains one entry
 * here; DriverShell itself and its ownership are untouched (per the CTO's explicit
 * boundary). A driver assignee reaches TaskDetailDrawer's exact same canonical status
 * transitions as any employee assignee — TransitionTaskStatusAction never branches on
 * participant type (see its own docblock), so no separate driver task authority exists
 * to duplicate here.
 */
export function DriverTasksPage() {
  const { t } = useTranslation('driver-mobile');
  const { t: tc } = useTranslation('collaboration');
  const navigate = useNavigate();
  const { data: tasks = [], isLoading, isError, refetch } = useTasks({ scope: 'assigned' });
  const [activeTaskId, setActiveTaskId] = useState<string | null>(null);

  return (
    <div className="min-h-screen bg-background pb-8">
      <div className="sticky top-0 z-10 flex items-center gap-3 border-b bg-background px-4 py-3">
        <Button variant="ghost" size="icon" aria-label={t(($) => $.shell.nav.home)} onClick={() => navigate(ROUTES.driverHome)}>
          <ArrowLeft className="h-5 w-5 rtl:rotate-180" aria-hidden="true" />
        </Button>
        <h1 className="flex items-center gap-2 text-base font-semibold">
          <ListChecks className="h-5 w-5" aria-hidden="true" />
          {t(($) => $.shell.nav.tasks)}
        </h1>
      </div>

      <div className="space-y-3 p-4">
        {isLoading ? (
          <>
            <Skeleton className="h-20 w-full rounded-xl" />
            <Skeleton className="h-20 w-full rounded-xl" />
          </>
        ) : isError ? (
          <div className="flex flex-col items-center gap-3 py-14 text-muted-foreground">
            <AlertTriangle className="h-9 w-9 text-destructive/70" aria-hidden="true" />
            <p className="text-sm">{tc(($) => $.tasks.list.error)}</p>
            <Button variant="outline" size="sm" onClick={() => void refetch()}>{tc(($) => $.tasks.list.retry)}</Button>
          </div>
        ) : tasks.length === 0 ? (
          <div className="flex flex-col items-center gap-2 py-14 text-center text-muted-foreground">
            <ListChecks className="h-9 w-9 opacity-50" aria-hidden="true" />
            <p className="text-sm font-medium">{tc(($) => $.tasks.list.empty.title)}</p>
            <p className="text-xs">{tc(($) => $.tasks.list.empty.subtitle)}</p>
          </div>
        ) : (
          tasks.map((task) => (
            <button
              key={task.id}
              type="button"
              onClick={() => setActiveTaskId(task.id)}
              className="flex w-full flex-col gap-2 rounded-xl border bg-card p-4 text-start"
            >
              <div className="flex items-start justify-between gap-2">
                <span className="min-w-0 flex-1 truncate text-sm font-semibold">{task.title}</span>
                <TaskStatusBadge status={task.status} />
              </div>
              <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                <TaskPriorityBadge priority={task.priority} />
                {task.due_at ? (
                  <span className={cn(task.is_overdue && 'font-medium text-destructive')}>
                    {tc(($) => $.tasks.dueAt, { date: new Date(task.due_at).toLocaleDateString() })}
                  </span>
                ) : null}
              </div>
            </button>
          ))
        )}
      </div>

      <TaskDetailDrawer
        taskId={activeTaskId}
        open={!!activeTaskId}
        onOpenChange={(open) => { if (!open) setActiveTaskId(null); }}
        onViewSourceConversation={() => { /* drivers have no Collaboration Conversations surface (per DriverShell's scope) */ }}
      />
    </div>
  );
}

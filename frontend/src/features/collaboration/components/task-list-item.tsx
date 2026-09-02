import { useTranslation } from 'react-i18next';
import { AlertTriangle } from 'lucide-react';

import { cn } from '@/lib/utils';

import type { Task } from '../types';
import { TaskPriorityBadge } from './task-priority-badge';
import { TaskStatusBadge } from './task-status-badge';

export function TaskListItem({ task, isActive, onSelect }: { task: Task; isActive: boolean; onSelect: () => void }) {
  const { t } = useTranslation('collaboration');

  return (
    <button
      type="button"
      onClick={onSelect}
      aria-current={isActive ? 'true' : undefined}
      className={cn(
        'flex w-full flex-col gap-1.5 rounded-md border px-3 py-2.5 text-start transition-colors',
        isActive ? 'border-primary bg-accent' : 'hover:bg-accent/50',
      )}
    >
      <div className="flex items-start justify-between gap-2">
        <span className="min-w-0 flex-1 truncate text-sm font-medium">{task.title}</span>
        <TaskStatusBadge status={task.status} />
      </div>

      <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
        <TaskPriorityBadge priority={task.priority} />
        {task.assignee_name ? <span className="truncate">{task.assignee_name}</span> : null}
        {task.due_at ? (
          <span className={cn('flex items-center gap-1', task.is_overdue && 'font-medium text-destructive')}>
            {task.is_overdue ? <AlertTriangle className="size-3" /> : null}
            {t(($) => $.tasks.dueAt, { date: new Date(task.due_at).toLocaleDateString() })}
          </span>
        ) : null}
      </div>
    </button>
  );
}

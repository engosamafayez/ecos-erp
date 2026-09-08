import { useTranslation } from 'react-i18next';
import { AlertTriangle, CheckSquare, MessageCircle, Paperclip, Users } from 'lucide-react';

import { cn } from '@/lib/utils';

import type { Task } from '../types';
import { TaskLabelBadge } from './task-label-badge';
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
        'flex w-full flex-col gap-1.5 rounded-md border bg-card px-3 py-2.5 text-start shadow-sm transition-colors',
        isActive ? 'border-primary ring-1 ring-primary' : 'hover:bg-accent/50',
      )}
    >
      {task.labels && task.labels.length > 0 ? (
        <div className="flex flex-wrap gap-1">
          {task.labels.map((label) => (
            <TaskLabelBadge key={label.id} label={label} />
          ))}
        </div>
      ) : null}

      <div className="flex items-start justify-between gap-2">
        <span className="min-w-0 flex-1 truncate text-sm font-medium">{task.title}</span>
        <TaskStatusBadge status={task.status} />
      </div>

      <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
        <TaskPriorityBadge priority={task.priority} />
        {task.list_name ? <span className="truncate">{task.list_name}</span> : null}
        {task.assignee_name ? <span className="truncate">{task.assignee_name}</span> : null}
        {task.due_at ? (
          <span className={cn('flex items-center gap-1', task.is_overdue && 'font-medium text-destructive')}>
            {task.is_overdue ? <AlertTriangle className="size-3" /> : null}
            {t(($) => $.tasks.dueAt, {
              date: new Date(task.due_at).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }),
            })}
          </span>
        ) : null}
        {task.checklist_progress && task.checklist_progress.total > 0 ? (
          <span className="flex items-center gap-1">
            <CheckSquare className="size-3.5" />
            {t(($) => $.tasks.checklist.progress, task.checklist_progress)}
          </span>
        ) : null}
        {task.comments_count ? (
          <span className="flex items-center gap-1">
            <MessageCircle className="size-3.5" />
            {task.comments_count}
          </span>
        ) : null}
        {task.attachments_count ? (
          <span className="flex items-center gap-1">
            <Paperclip className="size-3.5" />
            {task.attachments_count}
          </span>
        ) : null}
        {task.followers_count ? (
          <span className="flex items-center gap-1">
            <Users className="size-3.5" />
            {task.followers_count}
          </span>
        ) : null}
      </div>
    </button>
  );
}

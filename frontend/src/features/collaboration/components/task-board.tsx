import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { AlertTriangle, MessageSquareText, Plus } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { toast } from '@/components/ds/use-toast';
import { EmptyState, LoadingState } from '@/components/crud';
import { cn } from '@/lib/utils';

import { useMoveTaskStatus, useTasks } from '../hooks/use-tasks';
import { allowedTaskStatusTransitions, TASK_STATUS_ORDER } from '../lib/task-meta';
import type { Task, TaskFilters as TaskFiltersValue, TaskStatus } from '../types';
import { TaskFilters } from './task-filters';
import { TaskPriorityBadge } from './task-priority-badge';
import { TaskStatusBadge } from './task-status-badge';

type Props = {
  activeTaskId: string | null;
  onSelect: (task: Task) => void;
  onCreate: () => void;
};

/**
 * Trello-like board: one column per canonical TaskStatus (brief §16). Drag/drop
 * uses the native HTML5 DnD API (no new dependency, brief §16 — lightweight, not
 * a Jira replacement) and only ever calls the same transitionTaskStatus endpoint
 * TaskDetailDrawer's status control already uses — never a client-only status
 * write (brief §20). An invalid drop (not in allowedTaskStatusTransitions) is
 * rejected before any request is sent; opening a card always remains the
 * accessible non-drag way to change status (brief §20).
 */
export function TaskBoard({ activeTaskId, onSelect, onCreate }: Props) {
  const { t } = useTranslation('collaboration');
  const [filters, setFilters] = useState<TaskFiltersValue>({ scope: 'mine' });
  const { data: tasks = [], isLoading, isError, refetch } = useTasks(filters);
  const moveStatus = useMoveTaskStatus();
  const [draggingId, setDraggingId] = useState<string | null>(null);
  const [dragOverColumn, setDragOverColumn] = useState<TaskStatus | null>(null);

  function handleDrop(task: Task, targetStatus: TaskStatus) {
    setDragOverColumn(null);
    setDraggingId(null);
    if (task.status === targetStatus) return;
    if (!allowedTaskStatusTransitions(task.status).includes(targetStatus)) {
      toast.error(t(($) => $.tasks.board.invalidMove));
      return;
    }
    moveStatus.mutate(
      { taskId: task.id, status: targetStatus },
      { onError: () => toast.error(t(($) => $.errors.generic)) },
    );
  }

  return (
    <div className="flex h-full flex-col gap-3 p-3">
      <div className="flex items-center justify-between gap-2">
        <TaskFilters value={filters} onChange={setFilters} />
        <Button size="sm" className="shrink-0 gap-1.5" onClick={onCreate}>
          <Plus className="size-3.5" />
          {t(($) => $.tasks.create)}
        </Button>
      </div>

      {isLoading ? (
        <LoadingState />
      ) : isError ? (
        <EmptyState
          title={t(($) => $.tasks.list.error)}
          action={<Button size="sm" variant="outline" onClick={() => refetch()}>{t(($) => $.tasks.list.retry)}</Button>}
        />
      ) : tasks.length === 0 ? (
        <EmptyState title={t(($) => $.tasks.list.empty.title)} description={t(($) => $.tasks.list.empty.subtitle)} />
      ) : (
        <div className="flex flex-1 gap-3 overflow-x-auto pb-1">
          {TASK_STATUS_ORDER.map((status) => {
            const columnTasks = tasks.filter((task) => task.status === status);
            const isDropTarget = dragOverColumn === status;

            return (
              <div
                key={status}
                className={cn(
                  'flex w-64 shrink-0 flex-col gap-2 rounded-lg border bg-muted/30 p-2',
                  isDropTarget && 'ring-2 ring-primary',
                )}
                onDragOver={(e) => {
                  e.preventDefault();
                  setDragOverColumn(status);
                }}
                onDragLeave={() => setDragOverColumn((current) => (current === status ? null : current))}
                onDrop={(e) => {
                  e.preventDefault();
                  const taskId = e.dataTransfer.getData('text/plain');
                  const task = tasks.find((t) => t.id === taskId);
                  if (task) handleDrop(task, status);
                }}
              >
                <div className="flex items-center justify-between px-1 pt-1">
                  <TaskStatusBadge status={status} />
                  <span className="text-xs text-muted-foreground">{columnTasks.length}</span>
                </div>

                <div className="flex flex-1 flex-col gap-2 overflow-y-auto">
                  {columnTasks.map((task) => (
                    <button
                      key={task.id}
                      type="button"
                      draggable
                      onDragStart={(e) => {
                        e.dataTransfer.setData('text/plain', task.id);
                        setDraggingId(task.id);
                      }}
                      onDragEnd={() => setDraggingId(null)}
                      onClick={() => onSelect(task)}
                      aria-current={task.id === activeTaskId ? 'true' : undefined}
                      className={cn(
                        'flex flex-col gap-1.5 rounded-md border bg-background px-2.5 py-2 text-start shadow-sm transition-opacity',
                        task.id === activeTaskId && 'border-primary',
                        draggingId === task.id && 'opacity-50',
                      )}
                    >
                      <div className="flex items-start justify-between gap-2">
                        <span className="min-w-0 flex-1 truncate text-sm font-medium">{task.title}</span>
                        {task.source_conversation_id ? (
                          <MessageSquareText
                            className="size-3.5 shrink-0 text-muted-foreground"
                            aria-label={t(($) => $.tasks.board.linkedConversation)}
                          />
                        ) : null}
                      </div>

                      <div className="flex flex-wrap items-center gap-1.5">
                        <TaskPriorityBadge priority={task.priority} />
                        {task.assignee_name ? (
                          <span className="truncate text-xs text-muted-foreground">{task.assignee_name}</span>
                        ) : null}
                      </div>

                      {task.due_at ? (
                        <span
                          className={cn(
                            'flex items-center gap-1 text-xs text-muted-foreground',
                            task.is_overdue && 'font-medium text-destructive',
                          )}
                        >
                          {task.is_overdue ? <AlertTriangle className="size-3" /> : null}
                          {t(($) => $.tasks.dueAt, { date: new Date(task.due_at).toLocaleDateString() })}
                        </span>
                      ) : null}
                    </button>
                  ))}
                </div>
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}

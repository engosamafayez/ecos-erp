import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import { EmptyState, LoadingState } from '@/components/crud';

import { useTasks } from '../hooks/use-tasks';
import type { Task, TaskFilters as TaskFiltersValue } from '../types';
import { TaskListItem } from './task-list-item';

type Props = {
  filters: TaskFiltersValue;
  activeTaskId: string | null;
  onSelect: (task: Task) => void;
};

export function TaskList({ filters, activeTaskId, onSelect }: Props) {
  const { t } = useTranslation('collaboration');
  const { data: tasks = [], isLoading, isError, refetch } = useTasks(filters);

  return (
    <div className="h-full overflow-y-auto p-3">
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
        <div className="flex flex-col gap-2">
          {tasks.map((task) => (
            <TaskListItem key={task.id} task={task} isActive={task.id === activeTaskId} onSelect={() => onSelect(task)} />
          ))}
        </div>
      )}
    </div>
  );
}

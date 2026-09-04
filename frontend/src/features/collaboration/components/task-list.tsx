import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Plus } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { EmptyState, LoadingState } from '@/components/crud';

import { useTasks } from '../hooks/use-tasks';
import type { Task, TaskFilters as TaskFiltersValue } from '../types';
import { TaskFilters } from './task-filters';
import { TaskListItem } from './task-list-item';

type Props = {
  activeTaskId: string | null;
  onSelect: (task: Task) => void;
  onCreate: () => void;
};

export function TaskList({ activeTaskId, onSelect, onCreate }: Props) {
  const { t } = useTranslation('collaboration');
  const [filters, setFilters] = useState<TaskFiltersValue>({ scope: 'mine' });
  const { data: tasks = [], isLoading, isError, refetch } = useTasks(filters);

  return (
    <div className="flex h-full flex-col gap-3 p-3">
      <div className="flex items-center justify-between gap-2">
        <TaskFilters value={filters} onChange={setFilters} />
        <Button size="sm" className="shrink-0 gap-1.5" onClick={onCreate}>
          <Plus className="size-3.5" />
          {t(($) => $.tasks.create)}
        </Button>
      </div>

      <div className="flex-1 overflow-y-auto">
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
    </div>
  );
}

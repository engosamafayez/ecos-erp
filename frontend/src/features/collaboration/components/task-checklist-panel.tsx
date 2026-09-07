import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Plus, Trash2 } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { toast } from '@/components/ds/use-toast';
import { LoadingState } from '@/components/crud';
import { cn } from '@/lib/utils';

import {
  useAddTaskChecklistItem,
  useCreateTaskChecklist,
  useDeleteTaskChecklistItem,
  useTaskChecklists,
  useUpdateTaskChecklistItem,
} from '../hooks/use-tasks';
import type { TaskChecklist } from '../types';

export function TaskChecklistPanel({ taskId }: { taskId: string }) {
  const { t } = useTranslation('collaboration');
  const { data: checklists = [], isLoading } = useTaskChecklists(taskId);
  const createChecklist = useCreateTaskChecklist(taskId);
  const [newChecklistTitle, setNewChecklistTitle] = useState('');

  if (isLoading) return <LoadingState />;

  return (
    <div className="flex flex-col gap-4">
      {checklists.map((checklist) => (
        <ChecklistBlock key={checklist.id} taskId={taskId} checklist={checklist} />
      ))}

      <form
        className="flex gap-2"
        onSubmit={(e) => {
          e.preventDefault();
          const title = newChecklistTitle.trim();
          if (!title) return;
          createChecklist.mutate(title, {
            onSuccess: () => setNewChecklistTitle(''),
            onError: () => toast.error(t(($) => $.errors.generic)),
          });
        }}
      >
        <Input
          value={newChecklistTitle}
          onChange={(e) => setNewChecklistTitle(e.target.value)}
          placeholder={t(($) => $.tasks.checklist.titlePlaceholder)}
          className="h-8 flex-1 text-xs"
        />
        <Button type="submit" size="sm" variant="outline" disabled={!newChecklistTitle.trim() || createChecklist.isPending}>
          <Plus className="size-3.5" />
          {t(($) => $.tasks.checklist.add)}
        </Button>
      </form>
    </div>
  );
}

function ChecklistBlock({ taskId, checklist }: { taskId: string; checklist: TaskChecklist }) {
  const { t } = useTranslation('collaboration');
  const addItem = useAddTaskChecklistItem(taskId);
  const updateItem = useUpdateTaskChecklistItem(taskId);
  const deleteItem = useDeleteTaskChecklistItem(taskId);
  const [newItemTitle, setNewItemTitle] = useState('');

  const total = checklist.items.length;
  const completed = checklist.items.filter((item) => item.is_completed).length;

  return (
    <div className="flex flex-col gap-2 rounded-md border p-3">
      <div className="flex items-center justify-between">
        <span className="text-sm font-medium">{checklist.title}</span>
        {total > 0 ? (
          <span className="text-xs text-muted-foreground">{t(($) => $.tasks.checklist.progress, { completed, total })}</span>
        ) : null}
      </div>

      {total > 0 ? (
        <div className="h-1.5 w-full overflow-hidden rounded-full bg-muted">
          <div
            className="h-full rounded-full bg-primary transition-all"
            style={{ width: `${Math.round((completed / total) * 100)}%` }}
          />
        </div>
      ) : null}

      <div className="flex flex-col gap-1.5">
        {checklist.items.length === 0 ? (
          <p className="text-xs text-muted-foreground">{t(($) => $.tasks.checklist.empty)}</p>
        ) : (
          checklist.items.map((item) => (
            <div key={item.id} className="group flex items-center gap-2">
              <Checkbox
                checked={item.is_completed}
                onCheckedChange={(checked) =>
                  updateItem.mutate(
                    { checklistId: checklist.id, itemId: item.id, changes: { is_completed: checked } },
                    { onError: () => toast.error(t(($) => $.errors.generic)) },
                  )
                }
                aria-label={item.title}
              />
              <span className={cn('flex-1 text-sm', item.is_completed && 'text-muted-foreground line-through')}>
                {item.title}
              </span>
              <button
                type="button"
                className="text-muted-foreground opacity-0 transition-opacity hover:text-destructive group-hover:opacity-100 focus-visible:opacity-100"
                aria-label={t(($) => $.tasks.checklist.delete)}
                onClick={() =>
                  deleteItem.mutate(
                    { checklistId: checklist.id, itemId: item.id },
                    { onError: () => toast.error(t(($) => $.errors.generic)) },
                  )
                }
              >
                <Trash2 className="size-3.5" />
              </button>
            </div>
          ))
        )}
      </div>

      <form
        className="flex gap-2"
        onSubmit={(e) => {
          e.preventDefault();
          const title = newItemTitle.trim();
          if (!title) return;
          addItem.mutate(
            { checklistId: checklist.id, title },
            { onSuccess: () => setNewItemTitle(''), onError: () => toast.error(t(($) => $.errors.generic)) },
          );
        }}
      >
        <Input
          value={newItemTitle}
          onChange={(e) => setNewItemTitle(e.target.value)}
          placeholder={t(($) => $.tasks.checklist.itemPlaceholder)}
          className="h-7 flex-1 text-xs"
        />
        <Button type="submit" size="sm" variant="ghost" className="h-7 px-2" disabled={!newItemTitle.trim() || addItem.isPending}>
          <Plus className="size-3.5" />
        </Button>
      </form>
    </div>
  );
}

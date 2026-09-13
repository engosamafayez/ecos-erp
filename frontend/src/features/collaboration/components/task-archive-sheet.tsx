import { useTranslation } from 'react-i18next';
import { List, Loader2, RotateCcw } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { EmptyState, EntityDrawer, LoadingState } from '@/components/crud';
import { toast } from '@/components/ds/use-toast';

import { useRestoreTask, useRestoreTaskBoardList, useTaskBoardLists, useTasks } from '../hooks/use-tasks';

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onOpenTask: (taskId: string) => void;
};

/**
 * Archive view (brief §5) — distinguishes archived Lists from archived Tasks
 * (two clearly separate sections, never merged into one undifferentiated
 * list) and offers Restore for each. Reuses the existing `GET task-lists`
 * (already returns archived rows, see TaskBoardListController::index) and a
 * new `archived=1` filter on the existing tasks endpoint — no new read
 * endpoints. Restore always lands the item back in a valid position (see
 * RestoreTaskBoardListAction/RestoreTaskAction); never a silent delete.
 */
export function TaskArchiveSheet({ open, onOpenChange, onOpenTask }: Props) {
  const { t } = useTranslation('collaboration');
  const { data: lists = [], isLoading: listsLoading } = useTaskBoardLists();
  const { data: archivedTasks = [], isLoading: tasksLoading } = useTasks({ scope: 'mine', archived: true });
  const restoreList = useRestoreTaskBoardList();
  const restoreTask = useRestoreTask();

  const archivedLists = lists.filter((list) => list.archived_at);
  const isLoading = listsLoading || tasksLoading;

  return (
    <EntityDrawer
      open={open}
      onOpenChange={onOpenChange}
      title={t(($) => $.tasks.archive.title)}
      className="sm:w-[40vw] sm:min-w-[400px] sm:max-w-md"
    >
      {isLoading ? (
        <LoadingState />
      ) : (
        <div className="flex flex-col gap-5">
          <section className="flex flex-col gap-2">
            <h3 className="text-xs font-semibold uppercase text-muted-foreground">{t(($) => $.tasks.board.archivedLists)}</h3>
            {archivedLists.length === 0 ? (
              <p className="text-sm italic text-muted-foreground">{t(($) => $.tasks.board.noArchivedLists)}</p>
            ) : (
              <ul className="flex flex-col gap-1.5">
                {archivedLists.map((list) => (
                  <li key={list.id} className="flex items-center gap-2 rounded-md border px-3 py-2 text-sm">
                    <List className="size-3.5 shrink-0 text-muted-foreground" />
                    <div className="min-w-0 flex-1">
                      <p className="truncate font-medium">{list.name}</p>
                      {list.archived_at ? (
                        <p className="text-xs text-muted-foreground">
                          {t(($) => $.tasks.archive.archivedAt, { date: new Date(list.archived_at).toLocaleString() })}
                        </p>
                      ) : null}
                    </div>
                    <Button
                      size="sm"
                      variant="outline"
                      className="h-7 shrink-0 gap-1 text-xs"
                      disabled={restoreList.isPending}
                      onClick={() => restoreList.mutate(list.id, { onError: () => toast.error(t(($) => $.errors.generic)) })}
                    >
                      <RotateCcw className="size-3" />
                      {t(($) => $.tasks.board.restoreList)}
                    </Button>
                  </li>
                ))}
              </ul>
            )}
          </section>

          <section className="flex flex-col gap-2">
            <h3 className="text-xs font-semibold uppercase text-muted-foreground">{t(($) => $.tasks.archive.tasks)}</h3>
            {archivedTasks.length === 0 ? (
              <EmptyState title={t(($) => $.tasks.archive.noTasks)} />
            ) : (
              <ul className="flex flex-col gap-1.5">
                {archivedTasks.map((task) => (
                  <li key={task.id} className="flex items-center gap-2 rounded-md border px-3 py-2 text-sm">
                    <div className="min-w-0 flex-1">
                      <button
                        type="button"
                        className="truncate font-medium hover:underline"
                        onClick={() => onOpenTask(task.id)}
                      >
                        {task.title}
                      </button>
                      {task.archived_at ? (
                        <p className="text-xs text-muted-foreground">
                          {t(($) => $.tasks.archive.archivedAt, { date: new Date(task.archived_at).toLocaleString() })}
                        </p>
                      ) : null}
                    </div>
                    <Button
                      size="sm"
                      variant="outline"
                      className="h-7 shrink-0 gap-1 text-xs"
                      disabled={restoreTask.isPending}
                      onClick={() =>
                        restoreTask.mutate(task.id, {
                          onError: () => toast.error(t(($) => $.errors.generic)),
                          onSuccess: () => toast.success(t(($) => $.tasks.archive.restored)),
                        })
                      }
                    >
                      {restoreTask.isPending ? <Loader2 className="size-3 animate-spin" /> : <RotateCcw className="size-3" />}
                      {t(($) => $.tasks.archive.restoreTask)}
                    </Button>
                  </li>
                ))}
              </ul>
            )}
          </section>
        </div>
      )}
    </EntityDrawer>
  );
}

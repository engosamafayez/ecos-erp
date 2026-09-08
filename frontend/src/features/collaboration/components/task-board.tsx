import { useState, type DragEvent } from 'react';
import { useTranslation } from 'react-i18next';
import {
  AlertTriangle,
  Archive,
  CheckSquare,
  GripVertical,
  MessageSquareText,
  MoreHorizontal,
  Paperclip,
  MessageCircle,
  Plus,
  Users,
} from 'lucide-react';

import { Avatar, AvatarFallback, getInitials } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuSub,
  DropdownMenuSubContent,
  DropdownMenuSubTrigger,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { toast } from '@/components/ds/use-toast';
import { EmptyState, LoadingState } from '@/components/crud';
import { cn } from '@/lib/utils';

import {
  useArchiveTask,
  useArchiveTaskBoardList,
  useCreateTaskBoardList,
  useMoveTaskCard,
  useRenameTaskBoardList,
  useReorderTaskBoardLists,
  useTaskBoardLists,
  useTasks,
} from '../hooks/use-tasks';
import type { Task, TaskBoardList, TaskFilters as TaskFiltersValue } from '../types';
import { TaskLabelBadge } from './task-label-badge';
import { TaskPriorityBadge } from './task-priority-badge';
import { TaskStatusBadge } from './task-status-badge';

type Props = {
  filters: TaskFiltersValue;
  activeTaskId: string | null;
  onSelect: (task: Task) => void;
};

/** A drag-in-progress card's intended drop position within one column. */
type CardDropTarget = { listId: string; index: number };

const LIST_DND_TYPE = 'application/x-ecos-task-list-id';

/**
 * Trello-style board: one column per company-customizable TaskBoardList
 * (brief §1/§2) — never a second TaskStatus authority; the canonical
 * lifecycle badge is still shown on every card. Drag/drop uses the native
 * HTML5 DnD API (no new dependency, brief §2 — lightweight, not a Jira
 * replacement).
 *
 * Two independent drag kinds share this component, disambiguated by a
 * distinct dataTransfer MIME type (`LIST_DND_TYPE` vs the card path's plain
 * `text/plain`) so a column's dragover/drop handlers never confuse a list
 * reorder with a card move: readable during `dragover` via `types` (values
 * are only readable at `drop`, a native DnD restriction).
 *
 * Card position is always precise now — every drop (drag or the "Move
 * to…"/"Move up"/"Move down" non-drag actions) computes an explicit target
 * index and calls the same moveTaskCard endpoint, never a client-only
 * placement write. Opening a card remains the accessible way to change its
 * canonical status.
 */
export function TaskBoard({ filters, activeTaskId, onSelect }: Props) {
  const { t } = useTranslation('collaboration');
  const { data: lists = [], isLoading: listsLoading, isError: listsError, refetch: refetchLists } = useTaskBoardLists();
  const { data: tasks = [], isLoading: tasksLoading, isError: tasksError, refetch: refetchTasks } = useTasks(filters);
  const moveCard = useMoveTaskCard();
  const archiveTask = useArchiveTask();
  const reorderLists = useReorderTaskBoardLists();
  const [draggingCardId, setDraggingCardId] = useState<string | null>(null);
  const [cardDropTarget, setCardDropTarget] = useState<CardDropTarget | null>(null);
  const [draggingListId, setDraggingListId] = useState<string | null>(null);
  const [listDragOverId, setListDragOverId] = useState<string | null>(null);
  const [addingList, setAddingList] = useState(false);
  const [newListName, setNewListName] = useState('');
  const createList = useCreateTaskBoardList();

  const isLoading = listsLoading || tasksLoading;
  const isError = listsError || tasksError;

  function moveTaskTo(task: Task, targetList: TaskBoardList, position: number) {
    setCardDropTarget(null);
    setDraggingCardId(null);
    moveCard.mutate(
      { taskId: task.id, taskListId: targetList.id, position },
      { onError: () => toast.error(t(($) => $.tasks.board.moveCardFailed)) },
    );
  }

  function dropList(targetList: TaskBoardList) {
    setListDragOverId(null);
    const draggedId = draggingListId;
    setDraggingListId(null);
    if (!draggedId || draggedId === targetList.id) return;

    const fromIndex = activeLists.findIndex((l) => l.id === draggedId);
    const toIndex = activeLists.findIndex((l) => l.id === targetList.id);
    if (fromIndex === -1 || toIndex === -1) return;

    const reordered = [...activeLists];
    const [moved] = reordered.splice(fromIndex, 1);
    reordered.splice(toIndex, 0, moved);

    reorderLists.mutate(
      reordered.map((l) => l.id),
      { onError: () => toast.error(t(($) => $.errors.generic)) },
    );
  }

  if (isLoading) return <LoadingState />;

  if (isError) {
    return (
      <EmptyState
        title={t(($) => $.tasks.list.error)}
        action={
          <Button
            size="sm"
            variant="outline"
            onClick={() => {
              refetchLists();
              refetchTasks();
            }}
          >
            {t(($) => $.tasks.list.retry)}
          </Button>
        }
      />
    );
  }

  const activeLists = lists.filter((list) => !list.archived_at);

  return (
    <div className="flex h-full gap-3 overflow-x-auto bg-muted/20 p-3">
      {activeLists.map((list) => {
        const columnTasks = tasks
          .filter((task) => task.task_list_id === list.id)
          .sort((a, b) => (a.board_position ?? 0) - (b.board_position ?? 0));
        const isCardDropTarget = cardDropTarget?.listId === list.id;
        const isListDropTarget = listDragOverId === list.id && draggingListId !== null && draggingListId !== list.id;

        return (
          <div
            key={list.id}
            className={cn(
              'flex w-64 shrink-0 flex-col gap-2 rounded-lg border bg-card p-2 shadow-sm transition-shadow',
              isCardDropTarget && 'ring-2 ring-primary',
              isListDropTarget && 'ring-2 ring-offset-2 ring-primary/70',
              draggingListId === list.id && 'opacity-50',
            )}
            onDragOver={(e) => {
              e.preventDefault();
              if (e.dataTransfer.types.includes(LIST_DND_TYPE)) {
                setListDragOverId(list.id);
                return;
              }
              // Card drag over empty column space (below the last card): land at the end.
              if (cardDropTarget?.listId !== list.id) {
                setCardDropTarget({ listId: list.id, index: columnTasks.length });
              }
            }}
            onDragLeave={(e) => {
              if (e.currentTarget.contains(e.relatedTarget as Node)) return;
              setListDragOverId((current) => (current === list.id ? null : current));
              setCardDropTarget((current) => (current?.listId === list.id ? null : current));
            }}
            onDrop={(e) => {
              e.preventDefault();
              const listId = e.dataTransfer.getData(LIST_DND_TYPE);
              if (listId) {
                dropList(list);
                return;
              }
              const taskId = e.dataTransfer.getData('text/plain');
              const task = tasks.find((t) => t.id === taskId);
              if (task) moveTaskTo(task, list, cardDropTarget?.index ?? columnTasks.length);
              setCardDropTarget(null);
            }}
          >
            <BoardListHeader
              list={list}
              count={columnTasks.length}
              onDragStart={() => setDraggingListId(list.id)}
              onDragEnd={() => {
                setDraggingListId(null);
                setListDragOverId(null);
              }}
            />

            <div className="flex flex-1 flex-col gap-1.5 overflow-y-auto">
              {columnTasks.map((task, index) => (
                <div key={task.id} className="flex flex-col">
                  <DropIndicator show={isCardDropTarget && cardDropTarget?.index === index} />
                  <TaskCard
                    task={task}
                    lists={activeLists}
                    isActive={task.id === activeTaskId}
                    isDragging={draggingCardId === task.id}
                    isFirst={index === 0}
                    isLast={index === columnTasks.length - 1}
                    onSelect={() => onSelect(task)}
                    onDragStart={() => setDraggingCardId(task.id)}
                    onDragEnd={() => {
                      setDraggingCardId(null);
                      setCardDropTarget(null);
                    }}
                    onDragOverCard={(e) => {
                      e.stopPropagation();
                      if (!draggingCardId || draggingCardId === task.id) return;
                      const rect = e.currentTarget.getBoundingClientRect();
                      const before = e.clientY < rect.top + rect.height / 2;
                      setCardDropTarget({ listId: list.id, index: before ? index : index + 1 });
                    }}
                    onMoveTo={(targetList) => moveTaskTo(task, targetList, tasks.filter((x) => x.task_list_id === targetList.id).length)}
                    onMoveUp={() => moveTaskTo(task, list, index - 1)}
                    onMoveDown={() => moveTaskTo(task, list, index + 1)}
                    onArchive={() =>
                      archiveTask.mutate(task.id, { onError: () => toast.error(t(($) => $.errors.generic)) })
                    }
                  />
                </div>
              ))}
              <DropIndicator show={isCardDropTarget && cardDropTarget?.index === columnTasks.length} />
            </div>
          </div>
        );
      })}

      <div className="w-64 shrink-0">
        {addingList ? (
          <form
            className="flex flex-col gap-2 rounded-lg border bg-card p-2 shadow-sm"
            onSubmit={(e) => {
              e.preventDefault();
              const name = newListName.trim();
              if (!name) return;
              createList.mutate(name, {
                onSuccess: () => {
                  setNewListName('');
                  setAddingList(false);
                },
                onError: () => toast.error(t(($) => $.errors.generic)),
              });
            }}
          >
            <Input
              autoFocus
              value={newListName}
              onChange={(e) => setNewListName(e.target.value)}
              placeholder={t(($) => $.tasks.board.newListPlaceholder)}
              className="h-8 text-sm"
              onKeyDown={(e) => {
                if (e.key === 'Escape') setAddingList(false);
              }}
            />
            <div className="flex gap-2">
              <Button type="submit" size="sm" disabled={!newListName.trim() || createList.isPending}>
                {t(($) => $.tasks.board.addList)}
              </Button>
              <Button type="button" size="sm" variant="ghost" onClick={() => setAddingList(false)}>
                {t(($) => $.conversations.newDirectDialog.cancel)}
              </Button>
            </div>
          </form>
        ) : (
          <Button variant="outline" className="w-full justify-start gap-1.5 text-muted-foreground" onClick={() => setAddingList(true)}>
            <Plus className="size-4" />
            {t(($) => $.tasks.board.addList)}
          </Button>
        )}
      </div>
    </div>
  );
}

function DropIndicator({ show }: { show: boolean }) {
  return <div className={cn('mx-1 rounded-full bg-primary transition-all', show ? 'my-1 h-0.5' : 'h-0')} />;
}

function BoardListHeader({
  list,
  count,
  onDragStart,
  onDragEnd,
}: {
  list: TaskBoardList;
  count: number;
  onDragStart: () => void;
  onDragEnd: () => void;
}) {
  const { t } = useTranslation('collaboration');
  const [editing, setEditing] = useState(false);
  const [name, setName] = useState(list.name);
  const rename = useRenameTaskBoardList();
  const archive = useArchiveTaskBoardList();

  if (editing) {
    return (
      <form
        className="px-1 pt-1"
        onSubmit={(e) => {
          e.preventDefault();
          const trimmed = name.trim();
          if (!trimmed || trimmed === list.name) {
            setEditing(false);
            return;
          }
          rename.mutate({ id: list.id, name: trimmed }, { onError: () => toast.error(t(($) => $.errors.generic)) });
          setEditing(false);
        }}
      >
        <Input
          autoFocus
          value={name}
          onChange={(e) => setName(e.target.value)}
          onBlur={() => setEditing(false)}
          className="h-7 text-sm font-semibold"
        />
      </form>
    );
  }

  return (
    <div
      draggable
      onDragStart={(e) => {
        e.dataTransfer.setData(LIST_DND_TYPE, list.id);
        e.dataTransfer.effectAllowed = 'move';
        onDragStart();
      }}
      onDragEnd={onDragEnd}
      className="flex cursor-grab items-center justify-between gap-1 rounded-md px-1 pt-1 active:cursor-grabbing"
    >
      <GripVertical className="size-3.5 shrink-0 text-muted-foreground/60" aria-hidden="true" />
      <button type="button" className="min-w-0 flex-1 truncate text-start text-sm font-semibold hover:underline" onClick={() => setEditing(true)}>
        {list.name}
      </button>
      <div className="flex items-center gap-1">
        <span className="text-xs text-muted-foreground">{count}</span>
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button size="icon" variant="ghost" className="size-6" aria-label={t(($) => $.tasks.board.renameList)}>
              <MoreHorizontal className="size-3.5" />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            <DropdownMenuItem onSelect={() => setEditing(true)}>{t(($) => $.tasks.board.renameList)}</DropdownMenuItem>
            <DropdownMenuItem
              onSelect={() => archive.mutate(list.id, { onError: () => toast.error(t(($) => $.errors.generic)) })}
            >
              {t(($) => $.tasks.board.archiveList)}
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </div>
    </div>
  );
}

function TaskCard({
  task,
  lists,
  isActive,
  isDragging,
  isFirst,
  isLast,
  onSelect,
  onDragStart,
  onDragEnd,
  onDragOverCard,
  onMoveTo,
  onMoveUp,
  onMoveDown,
  onArchive,
}: {
  task: Task;
  lists: TaskBoardList[];
  isActive: boolean;
  isDragging: boolean;
  isFirst: boolean;
  isLast: boolean;
  onSelect: () => void;
  onDragStart: () => void;
  onDragEnd: () => void;
  onDragOverCard: (e: DragEvent<HTMLDivElement>) => void;
  onMoveTo: (list: TaskBoardList) => void;
  onMoveUp: () => void;
  onMoveDown: () => void;
  onArchive: () => void;
}) {
  const { t } = useTranslation('collaboration');
  const assigneeCount = 1 + (task.additional_assignees?.length ?? 0);

  return (
    <div
      draggable
      data-testid={`task-card-${task.id}`}
      onDragStart={(e) => {
        e.dataTransfer.setData('text/plain', task.id);
        e.dataTransfer.effectAllowed = 'move';
        onDragStart();
      }}
      onDragEnd={onDragEnd}
      onDragOver={(e) => {
        e.preventDefault();
        onDragOverCard(e);
      }}
      aria-current={isActive ? 'true' : undefined}
      className={cn(
        'group flex cursor-grab flex-col gap-1.5 rounded-md border bg-background px-2.5 py-2 shadow-sm transition-opacity active:cursor-grabbing',
        isActive && 'border-primary ring-1 ring-primary',
        isDragging && 'opacity-40',
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
        <button type="button" onClick={onSelect} className="min-w-0 flex-1 text-start text-sm font-medium hover:underline">
          {task.title}
        </button>
        <div className="flex shrink-0 items-center gap-1">
          {task.source_conversation_id ? (
            <MessageSquareText className="size-3.5 text-muted-foreground" aria-label={t(($) => $.tasks.board.linkedConversation)} />
          ) : null}
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button
                size="icon"
                variant="ghost"
                className="size-5 opacity-0 group-hover:opacity-100 focus-visible:opacity-100"
                aria-label={t(($) => $.tasks.board.moveTo)}
              >
                <MoreHorizontal className="size-3.5" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuItem disabled={isFirst} onSelect={onMoveUp}>
                {t(($) => $.tasks.board.moveUp)}
              </DropdownMenuItem>
              <DropdownMenuItem disabled={isLast} onSelect={onMoveDown}>
                {t(($) => $.tasks.board.moveDown)}
              </DropdownMenuItem>
              <DropdownMenuSub>
                <DropdownMenuSubTrigger>{t(($) => $.tasks.board.moveTo)}</DropdownMenuSubTrigger>
                <DropdownMenuSubContent>
                  {lists.map((list) => (
                    <DropdownMenuItem key={list.id} disabled={list.id === task.task_list_id} onSelect={() => onMoveTo(list)}>
                      {list.name}
                    </DropdownMenuItem>
                  ))}
                </DropdownMenuSubContent>
              </DropdownMenuSub>
              <DropdownMenuSeparator />
              <DropdownMenuItem onSelect={onSelect}>{t(($) => $.tasks.detail.title)}</DropdownMenuItem>
              <DropdownMenuItem onSelect={onArchive}>
                <Archive className="me-2 size-3.5" />
                {t(($) => $.tasks.board.archiveTask)}
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </div>

      <div className="flex flex-wrap items-center gap-1.5">
        <TaskStatusBadge status={task.status} />
        <TaskPriorityBadge priority={task.priority} />
      </div>

      {task.due_at ? (
        <span className={cn('flex items-center gap-1 text-xs text-muted-foreground', task.is_overdue && 'font-medium text-destructive')}>
          {task.is_overdue ? <AlertTriangle className="size-3" /> : null}
          {t(($) => $.tasks.dueAt, {
            date: new Date(task.due_at).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }),
          })}
        </span>
      ) : null}

      <div className="flex items-center justify-between gap-2">
        <div className="flex items-center gap-2 text-xs text-muted-foreground">
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

        {task.assignee_name ? (
          <div className="flex -space-x-1.5 rtl:space-x-reverse">
            <Avatar className="size-5 border border-background" title={task.assignee_name}>
              <AvatarFallback className="text-[10px]">{getInitials(task.assignee_name)}</AvatarFallback>
            </Avatar>
            {assigneeCount > 1 ? (
              <span
                className="flex size-5 items-center justify-center rounded-full border border-background bg-muted text-[9px] font-medium text-muted-foreground"
                title={task.additional_assignees?.map((a) => a.name).join(', ')}
              >
                +{assigneeCount - 1}
              </span>
            ) : null}
          </div>
        ) : null}
      </div>
    </div>
  );
}

import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
  AlertTriangle,
  CheckSquare,
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
  useArchiveTaskBoardList,
  useCreateTaskBoardList,
  useMoveTaskCard,
  useRenameTaskBoardList,
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

/**
 * Trello-style board: one column per company-customizable TaskBoardList
 * (brief §1/§2) — never a second TaskStatus authority; the canonical
 * lifecycle badge is still shown on every card. Drag/drop uses the native
 * HTML5 DnD API (no new dependency, brief §2 — lightweight, not a Jira
 * replacement) and only ever calls the same moveTaskCard endpoint the
 * per-card "Move to…" menu uses — never a client-only placement write. That
 * menu is the accessible non-drag way to move a card (brief §20/§22);
 * opening a card remains the accessible way to change its canonical status.
 */
export function TaskBoard({ filters, activeTaskId, onSelect }: Props) {
  const { t } = useTranslation('collaboration');
  const { data: lists = [], isLoading: listsLoading, isError: listsError, refetch: refetchLists } = useTaskBoardLists();
  const { data: tasks = [], isLoading: tasksLoading, isError: tasksError, refetch: refetchTasks } = useTasks(filters);
  const moveCard = useMoveTaskCard();
  const [draggingId, setDraggingId] = useState<string | null>(null);
  const [dragOverListId, setDragOverListId] = useState<string | null>(null);
  const [addingList, setAddingList] = useState(false);
  const [newListName, setNewListName] = useState('');
  const createList = useCreateTaskBoardList();

  const isLoading = listsLoading || tasksLoading;
  const isError = listsError || tasksError;

  function handleDrop(task: Task, targetList: TaskBoardList, position: number) {
    setDragOverListId(null);
    setDraggingId(null);
    moveCard.mutate(
      { taskId: task.id, taskListId: targetList.id, position },
      { onError: () => toast.error(t(($) => $.tasks.board.moveCardFailed)) },
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
    <div className="flex h-full gap-3 overflow-x-auto p-3">
      {activeLists.map((list) => {
        const columnTasks = tasks
          .filter((task) => task.task_list_id === list.id)
          .sort((a, b) => (a.board_position ?? 0) - (b.board_position ?? 0));
        const isDropTarget = dragOverListId === list.id;

        return (
          <div
            key={list.id}
            className={cn(
              'flex w-64 shrink-0 flex-col gap-2 rounded-lg border bg-muted/30 p-2',
              isDropTarget && 'ring-2 ring-primary',
            )}
            onDragOver={(e) => {
              e.preventDefault();
              setDragOverListId(list.id);
            }}
            onDragLeave={() => setDragOverListId((current) => (current === list.id ? null : current))}
            onDrop={(e) => {
              e.preventDefault();
              const taskId = e.dataTransfer.getData('text/plain');
              const task = tasks.find((t) => t.id === taskId);
              if (task) handleDrop(task, list, columnTasks.length);
            }}
          >
            <BoardListHeader list={list} count={columnTasks.length} />

            <div className="flex flex-1 flex-col gap-2 overflow-y-auto">
              {columnTasks.map((task) => (
                <TaskCard
                  key={task.id}
                  task={task}
                  lists={activeLists}
                  isActive={task.id === activeTaskId}
                  isDragging={draggingId === task.id}
                  onSelect={() => onSelect(task)}
                  onDragStart={() => setDraggingId(task.id)}
                  onDragEnd={() => setDraggingId(null)}
                  onMoveTo={(targetList) => handleDrop(task, targetList, 0)}
                />
              ))}
            </div>
          </div>
        );
      })}

      <div className="w-64 shrink-0">
        {addingList ? (
          <form
            className="flex flex-col gap-2 rounded-lg border bg-muted/30 p-2"
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

function BoardListHeader({ list, count }: { list: TaskBoardList; count: number }) {
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
    <div className="flex items-center justify-between px-1 pt-1">
      <button type="button" className="truncate text-sm font-semibold hover:underline" onClick={() => setEditing(true)}>
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
  onSelect,
  onDragStart,
  onDragEnd,
  onMoveTo,
}: {
  task: Task;
  lists: TaskBoardList[];
  isActive: boolean;
  isDragging: boolean;
  onSelect: () => void;
  onDragStart: () => void;
  onDragEnd: () => void;
  onMoveTo: (list: TaskBoardList) => void;
}) {
  const { t } = useTranslation('collaboration');

  return (
    <div
      draggable
      data-testid={`task-card-${task.id}`}
      onDragStart={(e) => {
        e.dataTransfer.setData('text/plain', task.id);
        onDragStart();
      }}
      onDragEnd={onDragEnd}
      aria-current={isActive ? 'true' : undefined}
      className={cn(
        'group flex flex-col gap-1.5 rounded-md border bg-background px-2.5 py-2 shadow-sm transition-opacity',
        isActive && 'border-primary',
        isDragging && 'opacity-50',
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
          <Avatar className="size-5" title={task.assignee_name}>
            <AvatarFallback className="text-[10px]">{getInitials(task.assignee_name)}</AvatarFallback>
          </Avatar>
        ) : null}
      </div>
    </div>
  );
}

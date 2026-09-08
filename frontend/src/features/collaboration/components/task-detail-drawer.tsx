import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Download, Loader2, Paperclip, Plus, Upload } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Textarea } from '@/components/ui/textarea';
import { EmptyState, LoadingState } from '@/components/crud';
import { toast } from '@/components/ds/use-toast';
import { useAuthStore } from '@/features/auth/store/auth-store';

import { downloadTaskAttachment } from '../hooks/use-secure-media';
import {
  useAddTaskAttachment,
  useAddTaskComment,
  useAttachTaskContext,
  useDetachTaskLabel,
  useReassignTask,
  useTask,
  useTaskAttachments,
  useTaskComments,
  useTaskContextLinks,
  useTransitionTaskStatus,
} from '../hooks/use-tasks';
import { allowedTaskStatusTransitions } from '../lib/task-meta';
import type { AddressableUser, OperationalContextType, TaskStatus } from '../types';
import { TaskAssigneesPanel } from './task-assignees-panel';
import { TaskChecklistPanel } from './task-checklist-panel';
import { TaskFollowersPanel } from './task-followers-panel';
import { TaskLabelBadge } from './task-label-badge';
import { TaskLabelPicker } from './task-label-picker';
import { TaskPriorityBadge } from './task-priority-badge';
import { TaskStatusBadge } from './task-status-badge';
import { UserPicker } from './user-picker';

const CONTEXT_TYPES: OperationalContextType[] = ['order', 'distribution_group', 'trip', 'driver'];

type TransitionLabelKey = 'start' | 'complete' | 'cancel' | 'reopen';

/**
 * Keyed by [current status][target status] — a button's label depends on BOTH ends of
 * the transition, not the target alone: `in_progress` is reached as "start" from `todo`
 * but as "reopen" from `done`, so a single target->label map cannot distinguish them.
 * Every entry here mirrors an edge in `allowedTaskStatusTransitions()` exactly; there is
 * intentionally no entry this component would ever look up that isn't listed.
 */
const TRANSITION_LABEL: Record<TaskStatus, Partial<Record<TaskStatus, TransitionLabelKey>>> = {
  todo: { in_progress: 'start', cancelled: 'cancel' },
  in_progress: { done: 'complete', cancelled: 'cancel' },
  done: { in_progress: 'reopen' },
  cancelled: {},
};

type Props = {
  taskId: string | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onViewSourceConversation: (conversationId: string) => void;
};

export function TaskDetailDrawer({ taskId, open, onOpenChange, onViewSourceConversation }: Props) {
  const { t } = useTranslation('collaboration');
  const currentUserId = useAuthStore((s) => s.user?.id);
  const [tab, setTab] = useState('overview');

  const { data: task, isLoading, isError } = useTask(taskId);
  const transition = useTransitionTaskStatus(taskId ?? '');
  const reassign = useReassignTask(taskId ?? '');

  if (!taskId) return null;

  const isCreator = task?.creator_user_id === currentUserId;
  const isOwnerOrAssignee = task && (task.creator_user_id === currentUserId || task.assignee_user_id === currentUserId);
  const nextStatuses = task ? allowedTaskStatusTransitions(task.status) : [];

  function doTransition(status: TaskStatus) {
    transition.mutate(status, { onError: () => toast.error(t(($) => $.errors.generic)) });
  }

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent side="right" className="flex flex-col gap-0 p-0 sm:!max-w-[560px]">
        {isLoading || !task ? (
          <div className="p-5">{isError ? <EmptyState title={t(($) => $.tasks.detail.loadFailed)} /> : <LoadingState />}</div>
        ) : (
          <>
            <SheetHeader className="shrink-0 gap-2 border-b px-5 py-4">
              <SheetTitle className="line-clamp-2 text-base leading-snug">{task.title}</SheetTitle>
              <div className="flex flex-wrap items-center gap-2">
                <TaskStatusBadge status={task.status} />
                <TaskPriorityBadge priority={task.priority} />
                {task.due_at ? (
                  <span className="text-xs text-muted-foreground">
                    {t(($) => $.tasks.dueAt, {
                      date: new Date(task.due_at).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }),
                    })}
                  </span>
                ) : null}
              </div>

              {isOwnerOrAssignee && nextStatuses.length > 0 ? (
                <div className="flex flex-wrap gap-1.5 pt-1">
                  {nextStatuses.map((target) => {
                    const labelKey = TRANSITION_LABEL[task.status][target] ?? 'start';
                    return (
                      <Button key={target} size="sm" variant="outline" className="h-7 text-xs" disabled={transition.isPending} onClick={() => doTransition(target)}>
                        {t(($) => $.tasks.detail.transitions[labelKey])}
                      </Button>
                    );
                  })}
                </div>
              ) : null}
            </SheetHeader>

            <Tabs value={tab} onValueChange={setTab} className="flex min-h-0 flex-1 flex-col">
              <TabsList className="h-10 w-full shrink-0 justify-start gap-0 rounded-none border-b bg-transparent px-5">
                <TabsTrigger value="overview" className="rounded-none border-b-2 border-transparent px-3 text-xs data-[state=active]:border-primary data-[state=active]:bg-transparent">
                  {t(($) => $.tasks.detail.title)}
                </TabsTrigger>
                <TabsTrigger value="checklist" className="rounded-none border-b-2 border-transparent px-3 text-xs data-[state=active]:border-primary data-[state=active]:bg-transparent">
                  {t(($) => $.tasks.detail.checklists)}
                </TabsTrigger>
                <TabsTrigger value="comments" className="rounded-none border-b-2 border-transparent px-3 text-xs data-[state=active]:border-primary data-[state=active]:bg-transparent">
                  {t(($) => $.tasks.detail.comments)}
                </TabsTrigger>
                <TabsTrigger value="attachments" className="rounded-none border-b-2 border-transparent px-3 text-xs data-[state=active]:border-primary data-[state=active]:bg-transparent">
                  {t(($) => $.tasks.detail.attachments)}
                </TabsTrigger>
                <TabsTrigger value="activity" className="rounded-none border-b-2 border-transparent px-3 text-xs data-[state=active]:border-primary data-[state=active]:bg-transparent">
                  {t(($) => $.tasks.detail.activity)}
                </TabsTrigger>
              </TabsList>

              <ScrollArea className="flex-1">
                <TabsContent value="overview" className="m-0 flex flex-col gap-4 p-5">
                  <OverviewTab task={task} isCreator={!!isCreator} onReassign={(user) => reassign.mutate(user.id, { onError: () => toast.error(t(($) => $.errors.generic)) })} onViewSourceConversation={onViewSourceConversation} />
                </TabsContent>
                <TabsContent value="checklist" className="m-0 p-5">
                  <TaskChecklistPanel taskId={task.id} />
                </TabsContent>
                <TabsContent value="comments" className="m-0 p-5">
                  <CommentsTab taskId={task.id} />
                </TabsContent>
                <TabsContent value="attachments" className="m-0 p-5">
                  <AttachmentsTab taskId={task.id} />
                </TabsContent>
                <TabsContent value="activity" className="m-0 p-5">
                  <ActivityTab taskId={task.id} />
                </TabsContent>
              </ScrollArea>
            </Tabs>
          </>
        )}
      </SheetContent>
    </Sheet>
  );
}

function OverviewTab({
  task,
  isCreator,
  onReassign,
  onViewSourceConversation,
}: {
  task: NonNullable<ReturnType<typeof useTask>['data']>;
  isCreator: boolean;
  onReassign: (user: AddressableUser) => void;
  onViewSourceConversation: (conversationId: string) => void;
}) {
  const { t } = useTranslation('collaboration');
  const [reassigning, setReassigning] = useState(false);
  const contextLinks = useTaskContextLinks(task.id);
  const attachContext = useAttachTaskContext(task.id);
  const detachLabel = useDetachTaskLabel(task.id);
  const [contextType, setContextType] = useState<OperationalContextType>('order');
  const [contextId, setContextId] = useState('');

  return (
    <div className="flex flex-col gap-4 text-sm">
      <div className="flex flex-wrap items-center gap-1.5">
        {(task.labels ?? []).map((label) => (
          <TaskLabelBadge
            key={label.id}
            label={label}
            onRemove={() => detachLabel.mutate(label.id, { onError: () => toast.error(t(($) => $.errors.generic)) })}
          />
        ))}
        <TaskLabelPicker task={task} />
      </div>

      <div>
        <p className="mb-1 text-xs font-semibold uppercase text-muted-foreground">{t(($) => $.tasks.detail.description)}</p>
        <p className="whitespace-pre-wrap text-foreground/80">{task.description || <span className="italic text-muted-foreground">—</span>}</p>
      </div>

      <div className="divide-y rounded-md border text-sm">
        <div className="flex justify-between px-3 py-2">
          <span className="text-muted-foreground">{t(($) => $.tasks.detail.creator)}</span>
          <span className="font-medium">{task.creator_name ?? task.creator_user_id}</span>
        </div>
        <div className="flex items-center justify-between gap-2 px-3 py-2">
          <span className="text-muted-foreground">{t(($) => $.tasks.detail.assignee)}</span>
          {reassigning ? (
            <UserPicker
              value={null}
              onChange={(user) => { if (user) { onReassign(user); setReassigning(false); } }}
              className="max-w-48"
            />
          ) : (
            <span className="flex items-center gap-2 font-medium">
              {task.assignee_name ?? task.assignee_user_id}
              {isCreator ? (
                <button type="button" className="text-xs font-normal text-primary hover:underline" onClick={() => setReassigning(true)}>
                  {t(($) => $.tasks.detail.reassign)}
                </button>
              ) : null}
            </span>
          )}
        </div>
      </div>

      <div>
        <p className="mb-1 text-xs font-semibold uppercase text-muted-foreground">{t(($) => $.tasks.assignees.title)}</p>
        <TaskAssigneesPanel task={task} />
      </div>

      <div>
        <p className="mb-1 text-xs font-semibold uppercase text-muted-foreground">{t(($) => $.tasks.followers.title)}</p>
        <TaskFollowersPanel task={task} />
      </div>

      {task.source_message_id ? (
        <div>
          <p className="mb-1 text-xs font-semibold uppercase text-muted-foreground">{t(($) => $.tasks.detail.sourceMessage)}</p>
          {task.source_message_snapshot !== null ? (
            <div className="rounded-md border px-3 py-2">
              <p className="text-foreground/80">{task.source_message_snapshot}</p>
              <button
                type="button"
                className="mt-1 text-xs text-primary hover:underline"
                onClick={() => task.source_conversation_id && onViewSourceConversation(task.source_conversation_id)}
              >
                {t(($) => $.tasks.detail.viewSource)}
              </button>
            </div>
          ) : (
            <p className="rounded-md border border-dashed px-3 py-2 text-xs italic text-muted-foreground">
              {t(($) => $.tasks.detail.sourceUnavailable)}
            </p>
          )}
        </div>
      ) : null}

      <div>
        <p className="mb-1 text-xs font-semibold uppercase text-muted-foreground">{t(($) => $.tasks.detail.context)}</p>
        <div className="flex flex-col gap-1.5">
          {(contextLinks.data ?? []).map((link) => (
            <div key={link.id} className="flex items-center gap-2 rounded-md border px-2.5 py-1.5 text-xs">
              <span className="font-medium">{t(($) => $.tasks.context[link.context_type])}</span>
              <span className="truncate text-muted-foreground">{link.context_id}</span>
            </div>
          ))}
          <div className="flex gap-1.5">
            <Select value={contextType} onValueChange={(v) => setContextType(v as OperationalContextType)}>
              <SelectTrigger className="h-8 w-36 text-xs"><SelectValue /></SelectTrigger>
              <SelectContent>
                {CONTEXT_TYPES.map((type) => (
                  <SelectItem key={type} value={type}>{t(($) => $.tasks.context[type])}</SelectItem>
                ))}
              </SelectContent>
            </Select>
            <Input
              value={contextId}
              onChange={(e) => setContextId(e.target.value)}
              className="h-8 flex-1 text-xs"
              placeholder={t(($) => $.tasks.detail.contextIdPlaceholder)}
            />
            <Button
              size="icon"
              variant="outline"
              className="h-8 w-8 shrink-0"
              aria-label={t(($) => $.tasks.detail.context)}
              disabled={!contextId.trim() || attachContext.isPending}
              onClick={() => {
                attachContext.mutate(
                  { contextType, contextId: contextId.trim() },
                  { onSuccess: () => setContextId(''), onError: () => toast.error(t(($) => $.errors.generic)) },
                );
              }}
            >
              {attachContext.isPending ? <Loader2 className="size-3.5 animate-spin" /> : <Plus className="size-3.5" />}
            </Button>
          </div>
        </div>
      </div>
    </div>
  );
}

function CommentsTab({ taskId }: { taskId: string }) {
  const { t } = useTranslation('collaboration');
  const { data: comments = [], isLoading } = useTaskComments(taskId);
  const addComment = useAddTaskComment(taskId);
  const [body, setBody] = useState('');

  function submit() {
    const value = body.trim();
    if (!value) return;
    addComment.mutate(value, { onSuccess: () => setBody(''), onError: () => toast.error(t(($) => $.errors.generic)) });
  }

  return (
    <div className="flex flex-col gap-4">
      {isLoading ? (
        <LoadingState />
      ) : comments.length === 0 ? (
        <p className="text-sm italic text-muted-foreground">{t(($) => $.tasks.detail.noComments)}</p>
      ) : (
        <div className="flex flex-col gap-3">
          {comments.map((comment) => (
            <div key={comment.id} className="flex flex-col gap-0.5 rounded-md border px-3 py-2">
              <div className="flex items-center gap-2">
                <span className="text-sm font-medium">{comment.author_name ?? comment.author_user_id}</span>
                <span className="text-xs text-muted-foreground">{new Date(comment.created_at).toLocaleString()}</span>
              </div>
              <p className="whitespace-pre-wrap text-sm text-foreground/80">{comment.body}</p>
            </div>
          ))}
        </div>
      )}

      <div className="flex flex-col gap-2">
        <Textarea value={body} onChange={(e) => setBody(e.target.value)} rows={3} placeholder={t(($) => $.tasks.detail.addCommentPlaceholder)} />
        <Button size="sm" className="self-end" onClick={submit} disabled={!body.trim() || addComment.isPending}>
          {addComment.isPending ? <Loader2 className="me-1.5 size-3.5 animate-spin" /> : null}
          {t(($) => $.tasks.detail.postComment)}
        </Button>
      </div>
    </div>
  );
}

function AttachmentsTab({ taskId }: { taskId: string }) {
  const { t } = useTranslation('collaboration');
  const { data: attachments = [], isLoading } = useTaskAttachments(taskId);
  const upload = useAddTaskAttachment(taskId);
  const [downloadingId, setDownloadingId] = useState<string | null>(null);

  async function download(id: string, name: string) {
    setDownloadingId(id);
    try {
      await downloadTaskAttachment(taskId, id, name);
    } catch {
      toast.error(t(($) => $.errors.generic));
    } finally {
      setDownloadingId(null);
    }
  }

  return (
    <div className="flex flex-col gap-3">
      <label className="w-fit">
        <Button variant="outline" size="sm" className="gap-1.5" asChild>
          <span>
            {upload.isPending ? <Loader2 className="size-3.5 animate-spin" /> : <Upload className="size-3.5" />}
            {t(($) => $.tasks.detail.addAttachment)}
          </span>
        </Button>
        <input
          type="file"
          className="hidden"
          onChange={(e) => {
            const file = e.target.files?.[0];
            e.target.value = '';
            if (file) upload.mutate(file, { onError: () => toast.error(t(($) => $.errors.generic)) });
          }}
        />
      </label>

      {isLoading ? (
        <LoadingState />
      ) : attachments.length === 0 ? (
        <p className="text-sm italic text-muted-foreground">{t(($) => $.tasks.detail.noAttachments)}</p>
      ) : (
        <ul className="divide-y rounded-md border">
          {attachments.map((attachment) => (
            <li key={attachment.id} className="flex items-center gap-2 px-3 py-2 text-sm">
              <Paperclip className="size-3.5 shrink-0 text-muted-foreground" />
              <span className="min-w-0 flex-1 truncate">{attachment.name}</span>
              <button
                type="button"
                onClick={() => download(attachment.id, attachment.name)}
                disabled={downloadingId === attachment.id}
                className="shrink-0 text-muted-foreground hover:text-foreground"
              >
                {downloadingId === attachment.id ? <Loader2 className="size-4 animate-spin" /> : <Download className="size-4" />}
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

/** Every selector call here is static (compile-time key-checked); the dynamic
 *  `event_type` string only ever indexes this already-resolved lookup map, never
 *  the selector itself — an unrecognized event_type falls back to its raw value
 *  rather than breaking the typed-selector contract. */
function useActivityEventLabel(): (eventType: string) => string {
  const { t } = useTranslation('collaboration');
  const labels: Record<string, string> = {
    created: t(($) => $.tasks.activityEvents.created),
    assigned: t(($) => $.tasks.activityEvents.assigned),
    status_changed: t(($) => $.tasks.activityEvents.status_changed),
    priority_changed: t(($) => $.tasks.activityEvents.priority_changed),
    due_date_changed: t(($) => $.tasks.activityEvents.due_date_changed),
    comment_added: t(($) => $.tasks.activityEvents.comment_added),
    updated: t(($) => $.tasks.activityEvents.updated),
    list_changed: t(($) => $.tasks.activityEvents.list_changed),
    label_added: t(($) => $.tasks.activityEvents.label_added),
    label_removed: t(($) => $.tasks.activityEvents.label_removed),
  };
  return (eventType) => labels[eventType] ?? eventType;
}

function ActivityTab({ taskId }: { taskId: string }) {
  const { t } = useTranslation('collaboration');
  const eventLabel = useActivityEventLabel();
  const { data: task, isLoading } = useTask(taskId);
  const entries = task?.activity ?? [];

  if (isLoading) return <LoadingState />;
  if (entries.length === 0) return <p className="text-sm italic text-muted-foreground">{t(($) => $.tasks.detail.noActivity)}</p>;

  return (
    <ol className="flex flex-col gap-3">
      {entries.map((entry) => (
        <li key={entry.id} className="flex gap-2 text-sm">
          <div className="min-w-0 flex-1">
            <p className="text-foreground/90">
              <span className="font-medium">{entry.actor_name ?? entry.actor_user_id}</span>{' '}
              <span className="text-muted-foreground">{eventLabel(entry.event_type)}</span>
            </p>
            <p className="text-xs text-muted-foreground">{new Date(entry.created_at).toLocaleString()}</p>
          </div>
        </li>
      ))}
    </ol>
  );
}

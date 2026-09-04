import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Search } from 'lucide-react';

import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { EmptyState, LoadingState } from '@/components/crud';

import { useSearchMessages } from '../hooks/use-messages';
import { useSearchTasks } from '../hooks/use-tasks';
import { TaskPriorityBadge } from './task-priority-badge';
import { TaskStatusBadge } from './task-status-badge';

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onOpenConversation: (conversationId: string) => void;
  onOpenTask: (taskId: string) => void;
};

export function CollaborationSearch({ open, onOpenChange, onOpenConversation, onOpenTask }: Props) {
  const { t } = useTranslation('collaboration');
  const [query, setQuery] = useState('');
  const [tab, setTab] = useState<'messages' | 'tasks'>('messages');

  const messages = useSearchMessages(query);
  const tasks = useSearchTasks(query);

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>{t(($) => $.workspace.title)}</DialogTitle>
        </DialogHeader>

        <div className="relative">
          <Search className="absolute start-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
          <Input autoFocus value={query} onChange={(e) => setQuery(e.target.value)} placeholder={t(($) => $.search.placeholder)} className="ps-9" />
        </div>

        <Tabs value={tab} onValueChange={(v) => setTab(v as 'messages' | 'tasks')}>
          <TabsList className="w-full">
            <TabsTrigger value="messages" className="flex-1">{t(($) => $.search.messagesTab)}</TabsTrigger>
            <TabsTrigger value="tasks" className="flex-1">{t(($) => $.search.tasksTab)}</TabsTrigger>
          </TabsList>

          <TabsContent value="messages" className="max-h-80 overflow-y-auto">
            {query.trim().length === 0 ? null : messages.isLoading ? (
              <LoadingState />
            ) : messages.isError ? (
              <EmptyState title={t(($) => $.search.error)} />
            ) : (messages.data ?? []).length === 0 ? (
              <EmptyState title={t(($) => $.search.noResults)} />
            ) : (
              <ul className="flex flex-col gap-1">
                {(messages.data ?? []).map((message) => (
                  <li key={message.id}>
                    <button
                      type="button"
                      onClick={() => onOpenConversation(message.conversation_id)}
                      className="flex w-full flex-col items-start gap-0.5 rounded-md px-2.5 py-2 text-start hover:bg-accent"
                    >
                      <span className="text-xs font-medium text-muted-foreground">{message.sender_name}</span>
                      <span className="truncate text-sm">{message.body}</span>
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </TabsContent>

          <TabsContent value="tasks" className="max-h-80 overflow-y-auto">
            {query.trim().length === 0 ? null : tasks.isLoading ? (
              <LoadingState />
            ) : tasks.isError ? (
              <EmptyState title={t(($) => $.search.error)} />
            ) : (tasks.data ?? []).length === 0 ? (
              <EmptyState title={t(($) => $.search.noResults)} />
            ) : (
              <ul className="flex flex-col gap-1">
                {(tasks.data ?? []).map((task) => (
                  <li key={task.id}>
                    <button
                      type="button"
                      onClick={() => onOpenTask(task.id)}
                      className="flex w-full items-center gap-2 rounded-md px-2.5 py-2 text-start hover:bg-accent"
                    >
                      <span className="min-w-0 flex-1 truncate text-sm">{task.title}</span>
                      <TaskPriorityBadge priority={task.priority} />
                      <TaskStatusBadge status={task.status} />
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </TabsContent>
        </Tabs>
      </DialogContent>
    </Dialog>
  );
}

import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useSearchParams } from 'react-router-dom';
import { Search } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { EmptyState } from '@/components/crud';
import { cn } from '@/lib/utils';

import { CollaborationSearch } from '../components/collaboration-search';
import { ConversationInfoPanel } from '../components/conversation-info-panel';
import { ConversationList } from '../components/conversation-list';
import { ConversationThread } from '../components/conversation-thread';
import { CreateTaskDialog } from '../components/create-task-dialog';
import { NewDirectDialog } from '../components/new-direct-dialog';
import { NewGroupDialog } from '../components/new-group-dialog';
import { TaskDetailDrawer } from '../components/task-detail-drawer';
import { TaskList } from '../components/task-list';
import { useConversation } from '../hooks/use-conversations';
import type { Conversation, Message, Task } from '../types';

type WorkspaceTab = 'conversations' | 'tasks';

/** Conversations + Tasks live as tabs of ONE workspace route, state-driven by query
 *  params — not two separate routes — so a task's "view in conversation" link and a
 *  message's "create task" flow can deep-link cleanly without a route change. */
export function CollaborationWorkspacePage() {
  const { t } = useTranslation('collaboration');
  const [searchParams, setSearchParams] = useSearchParams();

  const tab: WorkspaceTab = searchParams.get('tab') === 'tasks' ? 'tasks' : 'conversations';
  const conversationId = searchParams.get('conversationId');
  const taskId = searchParams.get('taskId');

  const { data: activeConversation } = useConversation(conversationId);

  const [infoOpen, setInfoOpen] = useState(false);
  const [newDirectOpen, setNewDirectOpen] = useState(false);
  const [newGroupOpen, setNewGroupOpen] = useState(false);
  const [searchOpen, setSearchOpen] = useState(false);
  const [createTaskOpen, setCreateTaskOpen] = useState(false);
  const [sourceMessage, setSourceMessage] = useState<{ id: string; body: string | null } | null>(null);

  function setTab(next: WorkspaceTab) {
    setSearchParams((prev) => {
      const params = new URLSearchParams(prev);
      params.set('tab', next);
      return params;
    });
  }

  function selectConversation(conversation: Conversation) {
    setSearchParams((prev) => {
      const params = new URLSearchParams(prev);
      params.set('tab', 'conversations');
      params.set('conversationId', conversation.id);
      params.delete('taskId');
      return params;
    });
  }

  function clearConversation() {
    setSearchParams((prev) => {
      const params = new URLSearchParams(prev);
      params.delete('conversationId');
      return params;
    });
  }

  function openConversationById(id: string) {
    setSearchParams((prev) => {
      const params = new URLSearchParams(prev);
      params.set('tab', 'conversations');
      params.set('conversationId', id);
      params.delete('taskId');
      return params;
    });
  }

  function selectTask(task: Task) {
    openTaskById(task.id);
  }

  function openTaskById(id: string) {
    setSearchParams((prev) => {
      const params = new URLSearchParams(prev);
      params.set('tab', 'tasks');
      params.set('taskId', id);
      return params;
    });
  }

  function closeTask() {
    setSearchParams((prev) => {
      const params = new URLSearchParams(prev);
      params.delete('taskId');
      return params;
    });
  }

  return (
    <div className="flex h-full flex-col">
      <div className="flex items-center justify-between gap-3 border-b px-4 py-2.5">
        <h1 className="text-base font-semibold">{t(($) => $.workspace.title)}</h1>
        <Button variant="outline" size="sm" className="gap-1.5" onClick={() => setSearchOpen(true)}>
          <Search className="size-3.5" />
          {t(($) => $.search.placeholder)}
        </Button>
      </div>

      <Tabs value={tab} onValueChange={(v) => setTab(v as WorkspaceTab)} className="flex min-h-0 flex-1 flex-col">
        <TabsList className="mx-4 mt-2 w-fit">
          <TabsTrigger value="conversations">{t(($) => $.workspace.tabs.conversations)}</TabsTrigger>
          <TabsTrigger value="tasks">{t(($) => $.workspace.tabs.tasks)}</TabsTrigger>
        </TabsList>

        <TabsContent value="conversations" className="m-0 min-h-0 flex-1">
          <div className="flex h-full">
            <div className={cn('w-full flex-col border-e md:flex md:w-80', activeConversation ? 'hidden' : 'flex')}>
              <ConversationList
                activeConversationId={conversationId}
                onSelect={selectConversation}
                onNewDirect={() => setNewDirectOpen(true)}
                onNewGroup={() => setNewGroupOpen(true)}
              />
            </div>

            {activeConversation ? (
              <div className="flex min-w-0 flex-1 flex-col">
                <ConversationThread
                  conversation={activeConversation}
                  onOpenInfo={() => setInfoOpen(true)}
                  onBack={clearConversation}
                  onCreateTaskFromMessage={(message: Message) => {
                    setSourceMessage({ id: message.id, body: message.body });
                    setCreateTaskOpen(true);
                  }}
                />
              </div>
            ) : (
              <div className="hidden flex-1 items-center justify-center md:flex">
                <EmptyState title={t(($) => $.conversations.empty.title)} description={t(($) => $.conversations.empty.subtitle)} />
              </div>
            )}
          </div>
        </TabsContent>

        <TabsContent value="tasks" className="m-0 min-h-0 flex-1">
          <TaskList
            activeTaskId={taskId}
            onSelect={selectTask}
            onCreate={() => {
              setSourceMessage(null);
              setCreateTaskOpen(true);
            }}
          />
        </TabsContent>
      </Tabs>

      <ConversationInfoPanel conversation={activeConversation ?? null} open={infoOpen} onOpenChange={setInfoOpen} onLeft={clearConversation} />
      <NewDirectDialog open={newDirectOpen} onOpenChange={setNewDirectOpen} onCreated={selectConversation} />
      <NewGroupDialog open={newGroupOpen} onOpenChange={setNewGroupOpen} onCreated={selectConversation} />
      <CreateTaskDialog
        open={createTaskOpen}
        onOpenChange={setCreateTaskOpen}
        sourceMessage={sourceMessage}
        onCreated={selectTask}
      />
      <TaskDetailDrawer
        taskId={taskId}
        open={!!taskId}
        onOpenChange={(open) => { if (!open) closeTask(); }}
        onViewSourceConversation={openConversationById}
      />
      <CollaborationSearch
        open={searchOpen}
        onOpenChange={setSearchOpen}
        onOpenConversation={(id) => { openConversationById(id); setSearchOpen(false); }}
        onOpenTask={(id) => { openTaskById(id); setSearchOpen(false); }}
      />
    </div>
  );
}

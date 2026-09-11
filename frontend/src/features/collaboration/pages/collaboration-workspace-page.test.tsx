import '@testing-library/jest-dom/vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { CollaborationSearch } from '../components/collaboration-search';
import type { ConversationInfoPanel } from '../components/conversation-info-panel';
import type { TaskDetailDrawer } from '../components/task-detail-drawer';
import type { Conversation, Message, Task } from '../types';

// Selector-mode i18n → resolve `t($ => $.a.b.c)` to the dotted path string.
function pathProxy(path: string): unknown {
  const target = () => path;
  return new Proxy(target, {
    get(_t, prop) {
      if (prop === Symbol.toPrimitive || prop === 'toString' || prop === 'valueOf') return () => path;
      return pathProxy(path ? `${path}.${String(prop)}` : String(prop));
    },
  });
}
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (sel: unknown) => (typeof sel === 'function' ? String((sel as (p: unknown) => unknown)(pathProxy(''))) : String(sel)),
    i18n: { language: 'en', exists: () => true },
  }),
}));

// Radix Tabs uses roving tabindex + pointer capture, which doesn't activate under
// fireEvent.click in jsdom — swap in a minimal accessible equivalent. Note this mock
// (unlike real Radix Tabs) renders BOTH TabsContent blocks unconditionally; the active
// tab is read from the wrapper div's data-value attribute instead.
// Local minimal prop types: this hand-rolled fake doesn't mirror Radix's real (mostly
// optional) prop API, so it declares just the shape it actually reads/calls.
interface MockTabsProps {
  value: string;
  onValueChange: (value: string) => void;
  children?: React.ReactNode;
}
interface MockTabsListProps {
  children?: React.ReactNode;
}
interface MockTabsTriggerProps {
  value: string;
  children?: React.ReactNode;
}
interface MockTabsContentProps {
  value: string;
  children?: React.ReactNode;
  className?: string;
}
vi.mock('@/components/ui/tabs', async () => {
  const React = await import('react');
  const Ctx = React.createContext<(v: string) => void>(() => {});
  return {
    Tabs: ({ value, onValueChange, children }: MockTabsProps) =>
      React.createElement(Ctx.Provider, { value: onValueChange }, React.createElement('div', { 'data-value': value }, children)),
    TabsList: ({ children }: MockTabsListProps) => React.createElement('div', { role: 'tablist' }, children),
    TabsTrigger: ({ value, children }: MockTabsTriggerProps) => {
      const onValueChange = React.useContext(Ctx);
      return React.createElement('button', { type: 'button', role: 'tab', onClick: () => onValueChange(value) }, children);
    },
    TabsContent: ({ value, children, ...props }: MockTabsContentProps) => React.createElement('div', { 'data-tab-content': value, ...props }, children),
  };
});

const mockUseConversation = vi.fn();
vi.mock('../hooks/use-conversations', () => ({ useConversation: (id: string | null) => mockUseConversation(id) }));

interface ConversationListMockProps {
  activeConversationId: string | null;
  onSelect: (conversation: Pick<Conversation, 'id' | 'type' | 'participants'>) => void;
  onNewDirect: () => void;
  onNewGroup: () => void;
}
vi.mock('../components/conversation-list', () => ({
  ConversationList: (props: ConversationListMockProps) => (
    <div data-testid="conv-list" data-active={props.activeConversationId ?? ''}>
      <button type="button" data-testid="conv-list-select" onClick={() => props.onSelect({ id: 'c1', type: 'direct', participants: [] })}>
        select-c1
      </button>
      <button type="button" data-testid="conv-list-new-direct" onClick={() => props.onNewDirect()}>new-direct</button>
      <button type="button" data-testid="conv-list-new-group" onClick={() => props.onNewGroup()}>new-group</button>
    </div>
  ),
}));

interface ConversationThreadMockProps {
  conversation: Pick<Conversation, 'id'>;
  onOpenInfo: () => void;
  onCreateTaskFromMessage: (message: Pick<Message, 'id' | 'body'>) => void;
  onBack?: () => void;
}
vi.mock('../components/conversation-thread', () => ({
  ConversationThread: (props: ConversationThreadMockProps) => (
    <div data-testid="conv-thread" data-id={props.conversation.id}>
      {/* eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- mock button label for test plumbing, not real UI copy */}
      <button type="button" data-testid="thread-open-info" onClick={() => props.onOpenInfo()}>info</button>
      {/* eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- mock button label for test plumbing, not real UI copy */}
      <button type="button" data-testid="thread-back" onClick={() => props.onBack?.()}>back</button>
      <button
        type="button"
        data-testid="thread-create-task"
        onClick={() => props.onCreateTaskFromMessage({ id: 'm1', body: 'From the thread' })}
      >
        create-task-from-message
      </button>
    </div>
  ),
}));

interface TaskListMockProps {
  activeTaskId: string | null;
  onSelect: (task: Pick<Task, 'id'>) => void;
  onCreate: () => void;
}
vi.mock('../components/task-list', () => ({
  TaskList: (props: TaskListMockProps) => (
    <div data-testid="task-list" data-active={props.activeTaskId ?? ''}>
      <button type="button" data-testid="task-list-select" onClick={() => props.onSelect({ id: 't1' })}>select-t1</button>
      {/* eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- mock button label for test plumbing, not real UI copy */}
      <button type="button" data-testid="task-list-create" onClick={() => props.onCreate()}>create</button>
    </div>
  ),
}));

// Both mount unconditionally on every render regardless of which tab is active (this file's
// own Tabs mock renders every TabsContent at once — see the comment above) and both call real,
// unmocked React Query hooks that need a QueryClientProvider this harness doesn't set up. No
// assertion here touches Board or Archive content, so trivial stand-ins are enough.
vi.mock('../components/task-board', () => ({ TaskBoard: () => <div data-testid="task-board" /> }));
vi.mock('../components/task-archive-sheet', () => ({ TaskArchiveSheet: () => <div data-testid="task-archive-sheet" /> }));

vi.mock('../components/conversation-info-panel', () => ({
  ConversationInfoPanel: (props: React.ComponentProps<typeof ConversationInfoPanel>) => (
    <div data-testid="info-panel" data-open={String(props.open)} data-conversation-id={props.conversation?.id ?? ''}>
      {/* eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- mock button label for test plumbing, not real UI copy */}
      <button type="button" data-testid="info-panel-leave" onClick={() => props.onLeft()}>leave</button>
    </div>
  ),
}));

interface NewConversationDialogMockProps {
  open: boolean;
  onCreated: (conversation: Pick<Conversation, 'id' | 'type' | 'participants'>) => void;
}
vi.mock('../components/new-direct-dialog', () => ({
  NewDirectDialog: (props: NewConversationDialogMockProps) => (
    <div data-testid="new-direct-dialog" data-open={String(props.open)}>
      {/* eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- mock button label for test plumbing, not real UI copy */}
      <button type="button" data-testid="new-direct-create" onClick={() => props.onCreated({ id: 'c-new', type: 'direct', participants: [] })}>
        create
      </button>
    </div>
  ),
}));

vi.mock('../components/new-group-dialog', () => ({
  NewGroupDialog: (props: NewConversationDialogMockProps) => (
    <div data-testid="new-group-dialog" data-open={String(props.open)}>
      {/* eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- mock button label for test plumbing, not real UI copy */}
      <button type="button" data-testid="new-group-create" onClick={() => props.onCreated({ id: 'g-new', type: 'group', participants: [] })}>
        create
      </button>
    </div>
  ),
}));

interface CreateTaskDialogMockProps {
  open: boolean;
  sourceMessage?: { id: string } | null;
  onCreated: (task: Pick<Task, 'id'>) => void;
}
vi.mock('../components/create-task-dialog', () => ({
  CreateTaskDialog: (props: CreateTaskDialogMockProps) => (
    <div data-testid="create-task-dialog" data-open={String(props.open)} data-source-message-id={props.sourceMessage?.id ?? ''}>
      {/* eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- mock button label for test plumbing, not real UI copy */}
      <button type="button" data-testid="create-task-submit" onClick={() => props.onCreated({ id: 't-new' })}>create</button>
    </div>
  ),
}));

vi.mock('../components/task-detail-drawer', () => ({
  TaskDetailDrawer: (props: React.ComponentProps<typeof TaskDetailDrawer>) => (
    <div data-testid="task-detail-drawer" data-open={String(props.open)} data-task-id={props.taskId ?? ''}>
      {/* eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- mock button label for test plumbing, not real UI copy */}
      <button type="button" data-testid="task-detail-close" onClick={() => props.onOpenChange(false)}>close</button>
      <button type="button" data-testid="task-detail-view-source" onClick={() => props.onViewSourceConversation('c-from-task')}>
        view-source
      </button>
    </div>
  ),
}));

vi.mock('../components/collaboration-search', () => ({
  CollaborationSearch: (props: React.ComponentProps<typeof CollaborationSearch>) => (
    <div data-testid="collab-search" data-open={String(props.open)}>
      <button type="button" data-testid="collab-search-open-conv" onClick={() => props.onOpenConversation('c-from-search')}>
        open-conv
      </button>
      <button type="button" data-testid="collab-search-open-task" onClick={() => props.onOpenTask('t-from-search')}>
        open-task
      </button>
    </div>
  ),
}));

import { CollaborationWorkspacePage } from './collaboration-workspace-page';

function renderPage(initialEntries: string[]) {
  return render(
    <MemoryRouter initialEntries={initialEntries}>
      <CollaborationWorkspacePage />
    </MemoryRouter>,
  );
}

/** The Tabs mock tags its wrapper div with the currently active tab value. */
function activeTab(): string | null {
  return document.querySelector('[data-value]')?.getAttribute('data-value') ?? null;
}

describe('CollaborationWorkspacePage', () => {
  beforeEach(() => {
    mockUseConversation.mockReset();
    mockUseConversation.mockImplementation((id: string | null) => ({
      data: id ? { id, type: 'direct', participants: [] } : undefined,
    }));
  });

  it('defaults to the conversations tab when no query params are present', () => {
    renderPage(['/collaboration']);

    expect(activeTab()).toBe('conversations');
    expect(screen.getByTestId('conv-list')).toBeInTheDocument();
    // No active conversation yet — the thread stub (which requires a conversation) is absent.
    expect(screen.queryByTestId('conv-thread')).not.toBeInTheDocument();
  });

  it('shows the tasks tab content when ?tab=tasks', () => {
    renderPage(['/collaboration?tab=tasks']);

    expect(activeTab()).toBe('tasks');
    // Board is the default task view (added after this test was first written — see the
    // List/Board toggle test below for the List path specifically).
    expect(screen.getByTestId('task-board')).toBeInTheDocument();
  });

  it('passes ?conversationId through to useConversation and down to the thread stub', () => {
    renderPage(['/collaboration?conversationId=c1']);

    expect(mockUseConversation).toHaveBeenCalledWith('c1');
    expect(screen.getByTestId('conv-list').getAttribute('data-active')).toBe('c1');
    expect(screen.getByTestId('conv-thread').getAttribute('data-id')).toBe('c1');
  });

  it('selecting a conversation from the list updates state so the thread stub shows that conversation', () => {
    renderPage(['/collaboration']);

    expect(screen.queryByTestId('conv-thread')).not.toBeInTheDocument();

    fireEvent.click(screen.getByTestId('conv-list-select'));

    expect(screen.getByTestId('conv-thread').getAttribute('data-id')).toBe('c1');
    expect(screen.getByTestId('conv-list').getAttribute('data-active')).toBe('c1');
  });

  it('selecting a task from the list opens the TaskDetailDrawer stub for that task', () => {
    renderPage(['/collaboration']);

    expect(screen.getByTestId('task-detail-drawer').getAttribute('data-open')).toBe('false');

    // Board is the default task view; switch to List to test the List-specific selection path.
    fireEvent.click(screen.getByText('tasks.view.list'));
    fireEvent.click(screen.getByTestId('task-list-select'));

    expect(screen.getByTestId('task-detail-drawer').getAttribute('data-open')).toBe('true');
    expect(screen.getByTestId('task-detail-drawer').getAttribute('data-task-id')).toBe('t1');
    // Selecting a task also switches the workspace to the Tasks tab.
    expect(activeTab()).toBe('tasks');
  });

  it('clicking the header search button opens the CollaborationSearch stub', () => {
    renderPage(['/collaboration']);

    expect(screen.getByTestId('collab-search').getAttribute('data-open')).toBe('false');

    fireEvent.click(screen.getByText('search.placeholder'));

    expect(screen.getByTestId('collab-search').getAttribute('data-open')).toBe('true');
  });

  it('opening a conversation from search navigates to it and closes the search dialog', () => {
    renderPage(['/collaboration']);

    fireEvent.click(screen.getByText('search.placeholder'));
    expect(screen.getByTestId('collab-search').getAttribute('data-open')).toBe('true');

    fireEvent.click(screen.getByTestId('collab-search-open-conv'));

    expect(screen.getByTestId('conv-thread').getAttribute('data-id')).toBe('c-from-search');
    expect(screen.getByTestId('collab-search').getAttribute('data-open')).toBe('false');
  });

  it('opening a task from search navigates to the Tasks tab and closes the search dialog', () => {
    renderPage(['/collaboration']);

    fireEvent.click(screen.getByText('search.placeholder'));
    fireEvent.click(screen.getByTestId('collab-search-open-task'));

    expect(activeTab()).toBe('tasks');
    expect(screen.getByTestId('task-detail-drawer').getAttribute('data-task-id')).toBe('t-from-search');
    expect(screen.getByTestId('collab-search').getAttribute('data-open')).toBe('false');
  });

  it('creating a task from a thread message prefills the source message on CreateTaskDialog', () => {
    renderPage(['/collaboration?conversationId=c1']);

    fireEvent.click(screen.getByTestId('thread-create-task'));

    expect(screen.getByTestId('create-task-dialog').getAttribute('data-open')).toBe('true');
    expect(screen.getByTestId('create-task-dialog').getAttribute('data-source-message-id')).toBe('m1');
  });

  it('closing the TaskDetailDrawer clears the active task', () => {
    renderPage(['/collaboration?tab=tasks&taskId=t1']);

    expect(screen.getByTestId('task-detail-drawer').getAttribute('data-open')).toBe('true');

    fireEvent.click(screen.getByTestId('task-detail-close'));

    expect(screen.getByTestId('task-detail-drawer').getAttribute('data-open')).toBe('false');
  });

  it('going back from the thread clears the active conversation', () => {
    renderPage(['/collaboration?conversationId=c1']);

    expect(screen.getByTestId('conv-thread')).toBeInTheDocument();

    fireEvent.click(screen.getByTestId('thread-back'));

    expect(screen.queryByTestId('conv-thread')).not.toBeInTheDocument();
  });
});

import '@testing-library/jest-dom/vitest';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

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
// fireEvent.click in jsdom — swap in a minimal accessible equivalent.
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

const mockUseSearchMessages = vi.fn();
vi.mock('../hooks/use-messages', () => ({ useSearchMessages: (q: string) => mockUseSearchMessages(q) }));

const mockUseSearchTasks = vi.fn();
vi.mock('../hooks/use-tasks', () => ({ useSearchTasks: (q: string) => mockUseSearchTasks(q) }));

import { CollaborationSearch } from './collaboration-search';
import type { Message, Task } from '../types';

const BASE_MESSAGE: Message = {
  id: 'm1',
  conversation_id: 'c1',
  sender_user_id: 2,
  sender_name: 'Alice Nabil',
  type: 'text',
  body: 'Can you check the invoice?',
  reply_to_message_id: null,
  attachment: null,
  created_at: '2026-08-30T10:00:00Z',
};

const BASE_TASK: Task = {
  id: 't1',
  company_id: 'co1',
  // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- test fixture value for a Task.title field, not UI copy
  title: 'Fix the printer',
  description: null,
  creator_user_id: 1,
  assignee_user_id: 2,
  team_id: null,
  priority: 'high',
  status: 'todo',
  due_at: null,
  is_overdue: false,
  completed_at: null,
  cancelled_at: null,
  source_conversation_id: null,
  source_message_id: null,
  source_message_snapshot: null,
  created_at: '2026-08-30T10:00:00Z',
  updated_at: '2026-08-30T10:00:00Z',
};

function messagesTab() {
  return document.querySelector('[data-tab-content="messages"]') as HTMLElement;
}
function tasksTab() {
  return document.querySelector('[data-tab-content="tasks"]') as HTMLElement;
}

function renderSearch(overrides: Partial<Parameters<typeof CollaborationSearch>[0]> = {}) {
  const props = {
    open: true,
    onOpenChange: vi.fn(),
    onOpenConversation: vi.fn(),
    onOpenTask: vi.fn(),
    ...overrides,
  };
  render(<CollaborationSearch {...props} />);
  return props;
}

describe('CollaborationSearch', () => {
  beforeEach(() => {
    mockUseSearchMessages.mockReset();
    mockUseSearchTasks.mockReset();
    // Defaults look like a state that WOULD render something if the empty-query guard
    // were missing — this makes the "empty query renders nothing" test meaningful.
    mockUseSearchMessages.mockReturnValue({ data: [BASE_MESSAGE], isLoading: true, isError: false });
    mockUseSearchTasks.mockReturnValue({ data: [BASE_TASK], isLoading: true, isError: false });
  });

  it('shows no results/empty-state/loading content in either tab while the query is empty', () => {
    renderSearch();

    expect(screen.queryByText('Alice Nabil')).not.toBeInTheDocument();
    expect(screen.queryByText('Fix the printer')).not.toBeInTheDocument();
    expect(screen.queryByText('loading')).not.toBeInTheDocument();
    expect(screen.queryByText('search.noResults')).not.toBeInTheDocument();
    expect(screen.queryByText('search.error')).not.toBeInTheDocument();
  });

  it('lists matching messages (sender + body) and opens the conversation on click', () => {
    mockUseSearchMessages.mockReturnValue({ data: [BASE_MESSAGE], isLoading: false, isError: false });
    mockUseSearchTasks.mockReturnValue({ data: [], isLoading: false, isError: false });
    const props = renderSearch();

    fireEvent.change(screen.getByPlaceholderText('search.placeholder'), { target: { value: 'invoice' } });

    expect(mockUseSearchMessages).toHaveBeenCalledWith('invoice');
    const messages = within(messagesTab());
    expect(messages.getByText('Alice Nabil')).toBeInTheDocument();
    expect(messages.getByText('Can you check the invoice?')).toBeInTheDocument();

    fireEvent.click(messages.getByText('Can you check the invoice?'));
    expect(props.onOpenConversation).toHaveBeenCalledWith('c1');
    expect(props.onOpenTask).not.toHaveBeenCalled();
  });

  it('switches to the Tasks tab and lists matching tasks with status + priority badges, opening the task on click', () => {
    mockUseSearchMessages.mockReturnValue({ data: [], isLoading: false, isError: false });
    mockUseSearchTasks.mockReturnValue({ data: [BASE_TASK], isLoading: false, isError: false });
    const props = renderSearch();

    fireEvent.change(screen.getByPlaceholderText('search.placeholder'), { target: { value: 'printer' } });
    fireEvent.click(screen.getByText('search.tasksTab'));

    expect(mockUseSearchTasks).toHaveBeenCalledWith('printer');
    const tasks = within(tasksTab());
    expect(tasks.getByText('Fix the printer')).toBeInTheDocument();
    expect(tasks.getByText('tasks.priority.high')).toBeInTheDocument();
    expect(tasks.getByText('tasks.status.todo')).toBeInTheDocument();

    fireEvent.click(tasks.getByText('Fix the printer'));
    expect(props.onOpenTask).toHaveBeenCalledWith('t1');
    expect(props.onOpenConversation).not.toHaveBeenCalled();
  });

  it('shows a loading indicator independently in each tab while its search is in flight', () => {
    mockUseSearchMessages.mockReturnValue({ data: undefined, isLoading: true, isError: false });
    mockUseSearchTasks.mockReturnValue({ data: undefined, isLoading: false, isError: false });
    renderSearch();

    fireEvent.change(screen.getByPlaceholderText('search.placeholder'), { target: { value: 'ab' } });

    expect(within(messagesTab()).getByText('loading')).toBeInTheDocument();
    expect(within(tasksTab()).queryByText('loading')).not.toBeInTheDocument();
    expect(within(tasksTab()).getByText('search.noResults')).toBeInTheDocument();
  });

  it('shows an error message independently in each tab when its search fails', () => {
    mockUseSearchMessages.mockReturnValue({ data: undefined, isLoading: false, isError: false });
    mockUseSearchTasks.mockReturnValue({ data: undefined, isLoading: false, isError: true });
    renderSearch();

    fireEvent.change(screen.getByPlaceholderText('search.placeholder'), { target: { value: 'ab' } });
    fireEvent.click(screen.getByText('search.tasksTab'));

    expect(within(tasksTab()).getByText('search.error')).toBeInTheDocument();
    expect(within(messagesTab()).queryByText('search.error')).not.toBeInTheDocument();
    expect(within(messagesTab()).getByText('search.noResults')).toBeInTheDocument();
  });
});

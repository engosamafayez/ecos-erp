import '@testing-library/jest-dom/vitest';
import type { ReactNode } from 'react';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi, beforeEach } from 'vitest';

// Selector-mode i18n → resolve t($ => $.a.b.c) to the dotted path string, with
// interpolation collapsed onto the path so `tasks.checklist.progress` assertions
// stay stable regardless of the {completed, total} values passed in.
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

// jsdom lacks the pointer-capture/portal behavior Radix DropdownMenu needs (same
// reasoning as the Select/Tabs mocks elsewhere in this feature) — render every
// menu's content unconditionally and inline so its items are directly queryable.
vi.mock('@/components/ui/dropdown-menu', async () => {
  const React = await import('react');
  return {
    DropdownMenu: ({ children }: { children: ReactNode }) => React.createElement(React.Fragment, null, children),
    DropdownMenuTrigger: ({ children }: { children: ReactNode }) => React.createElement(React.Fragment, null, children),
    DropdownMenuContent: ({ children }: { children: ReactNode }) => React.createElement('div', null, children),
    DropdownMenuItem: ({ children, onSelect, disabled }: { children: ReactNode; onSelect?: () => void; disabled?: boolean }) =>
      React.createElement('button', { type: 'button', disabled, onClick: () => !disabled && onSelect?.() }, children),
    DropdownMenuSeparator: () => null,
    DropdownMenuSub: ({ children }: { children: ReactNode }) => React.createElement(React.Fragment, null, children),
    DropdownMenuSubTrigger: ({ children }: { children: ReactNode }) => React.createElement('div', null, children),
    DropdownMenuSubContent: ({ children }: { children: ReactNode }) => React.createElement('div', null, children),
  };
});

vi.mock('@/components/ds/use-toast', () => ({
  toast: { success: vi.fn(), error: vi.fn(), warning: vi.fn(), info: vi.fn() },
}));

const mocks = vi.hoisted(() => ({
  useTaskBoardLists: vi.fn(),
  useTasks: vi.fn(),
  useMoveTaskCard: vi.fn(),
  useCreateTaskBoardList: vi.fn(),
  useRenameTaskBoardList: vi.fn(),
  useArchiveTaskBoardList: vi.fn(),
  useReorderTaskBoardLists: vi.fn(),
  useArchiveTask: vi.fn(),
}));
vi.mock('../hooks/use-tasks', () => ({
  useTaskBoardLists: () => mocks.useTaskBoardLists(),
  useTasks: (filters: unknown) => mocks.useTasks(filters),
  useMoveTaskCard: () => mocks.useMoveTaskCard(),
  useCreateTaskBoardList: () => mocks.useCreateTaskBoardList(),
  useRenameTaskBoardList: () => mocks.useRenameTaskBoardList(),
  useArchiveTaskBoardList: () => mocks.useArchiveTaskBoardList(),
  useReorderTaskBoardLists: () => mocks.useReorderTaskBoardLists(),
  useArchiveTask: () => mocks.useArchiveTask(),
}));

import { TaskBoard } from './task-board';
import type { Task, TaskBoardList } from '../types';

const LIST_A: TaskBoardList = { id: 'l1', name: 'To Do', position: 0, archived_at: null };
const LIST_B: TaskBoardList = { id: 'l2', name: 'Done', position: 1, archived_at: null };
const ARCHIVED_LIST: TaskBoardList = { id: 'l3', name: 'Old', position: 2, archived_at: '2026-01-01T00:00:00Z' };

function makeTask(overrides: Partial<Task> = {}): Task {
  return {
    id: 't1',
    company_id: 'c1',
    // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- test fixture value for a Task.title field, not UI copy
    title: 'Card One',
    description: null,
    creator_user_id: 1,
    creator_name: 'Creator',
    assignee_user_id: 1,
    assignee_name: 'Creator',
    team_id: null,
    priority: 'normal',
    status: 'todo',
    due_at: null,
    is_overdue: false,
    completed_at: null,
    cancelled_at: null,
    source_conversation_id: null,
    source_message_id: null,
    source_message_snapshot: null,
    task_list_id: 'l1',
    board_position: 0,
    checklist_progress: null,
    created_at: '2026-08-01T00:00:00Z',
    updated_at: '2026-08-01T00:00:00Z',
    ...overrides,
  };
}

let moveCardMutate: ReturnType<typeof vi.fn>;
let createListMutate: ReturnType<typeof vi.fn>;
let archiveListMutate: ReturnType<typeof vi.fn>;

function setup(lists: TaskBoardList[], tasks: Task[]) {
  mocks.useTaskBoardLists.mockReturnValue({ data: lists, isLoading: false, isError: false, refetch: vi.fn() });
  mocks.useTasks.mockReturnValue({ data: tasks, isLoading: false, isError: false, refetch: vi.fn() });
}

beforeEach(() => {
  vi.clearAllMocks();
  moveCardMutate = vi.fn();
  mocks.useMoveTaskCard.mockReturnValue({ mutate: moveCardMutate });
  createListMutate = vi.fn();
  mocks.useCreateTaskBoardList.mockReturnValue({ mutate: createListMutate, isPending: false });
  mocks.useRenameTaskBoardList.mockReturnValue({ mutate: vi.fn(), isPending: false });
  archiveListMutate = vi.fn();
  mocks.useArchiveTaskBoardList.mockReturnValue({ mutate: archiveListMutate, isPending: false });
  mocks.useReorderTaskBoardLists.mockReturnValue({ mutate: vi.fn(), isPending: false });
  mocks.useArchiveTask.mockReturnValue({ mutate: vi.fn(), isPending: false });
});

describe('TaskBoard', () => {
  it('shows a loading state while lists or tasks are loading', () => {
    mocks.useTaskBoardLists.mockReturnValue({ data: undefined, isLoading: true, isError: false, refetch: vi.fn() });
    mocks.useTasks.mockReturnValue({ data: undefined, isLoading: true, isError: false, refetch: vi.fn() });
    render(<TaskBoard filters={{}} activeTaskId={null} onSelect={() => {}} />);
    expect(screen.getByText('loading')).toBeInTheDocument();
  });

  it('renders one column per active (non-archived) board list, in position order, excluding archived lists', () => {
    // useTaskBoardLists always returns position-ordered data in real usage (the
    // backend orders every list-returning query by `position`) — the mock
    // mirrors that rather than testing a client-side sort TaskBoard doesn't do.
    setup([LIST_A, LIST_B, ARCHIVED_LIST], []);
    render(<TaskBoard filters={{}} activeTaskId={null} onSelect={() => {}} />);
    const headings = screen.getAllByText(/^(To Do|Done|Old)$/);
    expect(headings.map((h) => h.textContent)).toEqual(['To Do', 'Done']);
  });

  it('places each task under its own task_list_id, ordered by board_position', () => {
    setup(
      [LIST_A],
      [
        // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- test fixture value for a Task.title field, not UI copy
        makeTask({ id: 't1', title: 'Second', task_list_id: 'l1', board_position: 1 }),
        // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- test fixture value for a Task.title field, not UI copy
        makeTask({ id: 't2', title: 'First', task_list_id: 'l1', board_position: 0 }),
      ],
    );
    render(<TaskBoard filters={{}} activeTaskId={null} onSelect={() => {}} />);
    const titles = screen.getAllByText(/^(First|Second)$/).map((el) => el.textContent);
    expect(titles).toEqual(['First', 'Second']);
  });

  it('calls onSelect with the task when a card title is clicked', () => {
    const onSelect = vi.fn();
    const task = makeTask();
    setup([LIST_A], [task]);
    render(<TaskBoard filters={{}} activeTaskId={null} onSelect={onSelect} />);
    fireEvent.click(screen.getByText('Card One'));
    expect(onSelect).toHaveBeenCalledWith(task);
  });

  it('renders card metadata: priority/status badges, due date, checklist progress, comment/attachment/follower counts, and assignee initials', () => {
    setup(
      [LIST_A],
      [
        makeTask({
          priority: 'urgent',
          status: 'in_progress',
          due_at: '2026-09-20T14:30:00Z',
          checklist_progress: { completed: 2, total: 4 },
          comments_count: 3,
          attachments_count: 5,
          followers_count: 7,
          assignee_name: 'Jane Doe',
          labels: [{ id: 'lb1', name: 'Blocked', color: 'orange' }],
        }),
      ],
    );
    render(<TaskBoard filters={{}} activeTaskId={null} onSelect={() => {}} />);
    const card = within(screen.getByTestId('task-card-t1'));

    expect(card.getByText('tasks.priority.urgent')).toBeInTheDocument();
    expect(card.getByText('tasks.status.in_progress')).toBeInTheDocument();
    expect(card.getByText('tasks.dueAt')).toBeInTheDocument();
    expect(card.getByText('tasks.checklist.progress')).toBeInTheDocument();
    expect(card.getByText('3')).toBeInTheDocument();
    expect(card.getByText('5')).toBeInTheDocument();
    expect(card.getByText('7')).toBeInTheDocument();
    expect(card.getByText('JD')).toBeInTheDocument();
    expect(card.getByText('Blocked')).toBeInTheDocument();
  });

  it('shows the linked-conversation icon only when the task has a source_conversation_id', () => {
    setup([LIST_A], [makeTask({ source_conversation_id: 'conv-1' })]);
    render(<TaskBoard filters={{}} activeTaskId={null} onSelect={() => {}} />);
    expect(screen.getByLabelText('tasks.board.linkedConversation')).toBeInTheDocument();
  });

  it('lets a card be moved to another list via the accessible "Move to…" menu, not just drag/drop', () => {
    setup([LIST_A, LIST_B], [makeTask({ task_list_id: 'l1' })]);
    render(<TaskBoard filters={{}} activeTaskId={null} onSelect={() => {}} />);

    const card = screen.getByTestId('task-card-t1');
    fireEvent.click(within(card).getByText('Done'));
    expect(moveCardMutate).toHaveBeenCalledWith({ taskId: 't1', taskListId: 'l2', position: 0 }, expect.anything());
  });

  it('disables the current list in the "Move to…" menu', () => {
    setup([LIST_A, LIST_B], [makeTask({ task_list_id: 'l1' })]);
    render(<TaskBoard filters={{}} activeTaskId={null} onSelect={() => {}} />);
    const card = screen.getByTestId('task-card-t1');
    expect(within(card).getByText('To Do').closest('button')).toBeDisabled();
  });

  it('creates a new list via the "Add list" affordance', () => {
    setup([LIST_A], []);
    render(<TaskBoard filters={{}} activeTaskId={null} onSelect={() => {}} />);

    fireEvent.click(screen.getByText('tasks.board.addList'));
    const input = screen.getByPlaceholderText('tasks.board.newListPlaceholder');
    fireEvent.change(input, { target: { value: 'Blocked' } });
    fireEvent.submit(input);

    expect(createListMutate).toHaveBeenCalledWith('Blocked', expect.anything());
  });

  it('renames a list by clicking its title and submitting the inline edit form', () => {
    const renameMutate = vi.fn();
    mocks.useRenameTaskBoardList.mockReturnValue({ mutate: renameMutate, isPending: false });
    setup([LIST_A], []);
    render(<TaskBoard filters={{}} activeTaskId={null} onSelect={() => {}} />);

    fireEvent.click(screen.getByText('To Do'));
    const input = screen.getByDisplayValue('To Do');
    fireEvent.change(input, { target: { value: 'Backlog' } });
    fireEvent.submit(input);

    expect(renameMutate).toHaveBeenCalledWith({ id: 'l1', name: 'Backlog' }, expect.anything());
  });

  it('archives a list via its menu', () => {
    setup([LIST_A], []);
    render(<TaskBoard filters={{}} activeTaskId={null} onSelect={() => {}} />);

    fireEvent.click(screen.getByText('tasks.board.archiveList'));
    expect(archiveListMutate).toHaveBeenCalledWith('l1', expect.anything());
  });
});

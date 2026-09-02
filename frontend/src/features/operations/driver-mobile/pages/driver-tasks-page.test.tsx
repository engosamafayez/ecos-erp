import '@testing-library/jest-dom/vitest';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

// Selector-mode i18n → resolve `t($ => $.a.b.c)` to the dotted path string, regardless
// of which namespace useTranslation was called with (this page uses two: driver-mobile
// and collaboration).
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

const navigateMock = vi.hoisted(() => vi.fn());
vi.mock('react-router-dom', async (importOriginal) => {
  const actual = await importOriginal<typeof import('react-router-dom')>();
  return { ...actual, useNavigate: () => navigateMock };
});

const mockUseTasks = vi.fn();
vi.mock('@/features/collaboration/hooks/use-tasks', () => ({ useTasks: (filters: unknown) => mockUseTasks(filters) }));

vi.mock('@/features/collaboration/components/task-detail-drawer', () => ({
  TaskDetailDrawer: (props: React.ComponentProps<typeof TaskDetailDrawer>) => (
    <div data-testid="task-detail-drawer" data-open={String(props.open)} data-task-id={props.taskId ?? ''} />
  ),
}));

import { ROUTES } from '@/router/routes';
import type { TaskDetailDrawer } from '@/features/collaboration/components/task-detail-drawer';
import type { Task } from '@/features/collaboration/types';
import { DriverTasksPage } from './driver-tasks-page';

const OVERDUE_TASK: Task = {
  id: 't1',
  company_id: 'co1',
  // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- test fixture value for a Task.title field, not UI copy
  title: 'Deliver pallet to Zone 3',
  description: null,
  creator_user_id: 1,
  assignee_user_id: 9,
  team_id: null,
  priority: 'high',
  status: 'in_progress',
  due_at: '2026-08-20T00:00:00Z',
  is_overdue: true,
  completed_at: null,
  cancelled_at: null,
  source_conversation_id: null,
  source_message_id: null,
  source_message_snapshot: null,
  created_at: '2026-08-01T00:00:00Z',
  updated_at: '2026-08-01T00:00:00Z',
};

const ON_TIME_TASK: Task = {
  ...OVERDUE_TASK,
  id: 't2',
  // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- test fixture value for a Task.title field, not UI copy
  title: 'Confirm handoff at branch',
  priority: 'normal',
  status: 'todo',
  is_overdue: false,
};

const NO_DUE_DATE_TASK: Task = {
  ...OVERDUE_TASK,
  id: 't3',
  // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- test fixture value for a Task.title field, not UI copy
  title: 'Report vehicle inspection',
  priority: 'low',
  status: 'done',
  due_at: null,
  is_overdue: false,
};

function mockTasksState(over: Partial<{ data: Task[]; isLoading: boolean; isError: boolean; refetch: () => void }> = {}) {
  mockUseTasks.mockReturnValue({
    data: over.data ?? [],
    isLoading: over.isLoading ?? false,
    isError: over.isError ?? false,
    refetch: over.refetch ?? vi.fn(),
  });
}

function cardFor(title: string): HTMLElement {
  const node = screen.getByText(title).closest('button');
  if (!node) throw new Error(`No card button found for "${title}"`);
  return node as HTMLElement;
}

describe('DriverTasksPage', () => {
  beforeEach(() => {
    navigateMock.mockClear();
    mockUseTasks.mockReset();
  });

  it('queries assigned tasks and shows a loading skeleton while the read is in flight', () => {
    mockTasksState({ isLoading: true });
    render(<DriverTasksPage />);

    expect(mockUseTasks).toHaveBeenCalledWith({ scope: 'assigned' });
    expect(document.querySelectorAll('[data-slot="skeleton"]').length).toBeGreaterThan(0);
    expect(screen.queryByText('tasks.list.error')).not.toBeInTheDocument();
    expect(screen.queryByText('tasks.list.empty.title')).not.toBeInTheDocument();
  });

  it('shows an error state and retries through refetch', () => {
    const refetch = vi.fn();
    mockTasksState({ isError: true, refetch });
    render(<DriverTasksPage />);

    expect(screen.getByText('tasks.list.error')).toBeInTheDocument();
    expect(document.querySelectorAll('[data-slot="skeleton"]').length).toBe(0);

    fireEvent.click(screen.getByText('tasks.list.retry'));
    expect(refetch).toHaveBeenCalledTimes(1);
  });

  it('shows an empty state when there are no assigned tasks', () => {
    mockTasksState({ data: [] });
    render(<DriverTasksPage />);

    expect(screen.getByText('tasks.list.empty.title')).toBeInTheDocument();
    expect(screen.getByText('tasks.list.empty.subtitle')).toBeInTheDocument();
  });

  it('renders one card per task with title, status badge, priority badge, and an overdue-flagged due date', () => {
    mockTasksState({ data: [OVERDUE_TASK, ON_TIME_TASK, NO_DUE_DATE_TASK] });
    render(<DriverTasksPage />);

    // Titles
    expect(screen.getByText(OVERDUE_TASK.title)).toBeInTheDocument();
    expect(screen.getByText(ON_TIME_TASK.title)).toBeInTheDocument();
    expect(screen.getByText(NO_DUE_DATE_TASK.title)).toBeInTheDocument();

    // Status + priority badges per card
    expect(cardFor(OVERDUE_TASK.title).textContent).toContain('tasks.status.in_progress');
    expect(cardFor(OVERDUE_TASK.title).textContent).toContain('tasks.priority.high');
    expect(cardFor(ON_TIME_TASK.title).textContent).toContain('tasks.status.todo');
    expect(cardFor(ON_TIME_TASK.title).textContent).toContain('tasks.priority.normal');
    expect(cardFor(NO_DUE_DATE_TASK.title).textContent).toContain('tasks.status.done');
    expect(cardFor(NO_DUE_DATE_TASK.title).textContent).toContain('tasks.priority.low');

    // Overdue task's due date is visually flagged (destructive styling)...
    const overdueDue = within(cardFor(OVERDUE_TASK.title)).getByText('tasks.dueAt');
    expect(overdueDue.className).toContain('text-destructive');

    // ...an on-time task's due date renders too but WITHOUT the destructive flag...
    const onTimeDue = within(cardFor(ON_TIME_TASK.title)).getByText('tasks.dueAt');
    expect(onTimeDue.className).not.toContain('text-destructive');

    // ...and a task with no due date renders no due-date element at all.
    expect(within(cardFor(NO_DUE_DATE_TASK.title)).queryByText('tasks.dueAt')).not.toBeInTheDocument();
  });

  it('clicking a task card opens the TaskDetailDrawer stub for that task', () => {
    mockTasksState({ data: [OVERDUE_TASK] });
    render(<DriverTasksPage />);

    expect(screen.getByTestId('task-detail-drawer').getAttribute('data-open')).toBe('false');

    fireEvent.click(screen.getByText(OVERDUE_TASK.title));

    expect(screen.getByTestId('task-detail-drawer').getAttribute('data-open')).toBe('true');
    expect(screen.getByTestId('task-detail-drawer').getAttribute('data-task-id')).toBe(OVERDUE_TASK.id);
  });

  it('clicking the header back button navigates to the driver home route', () => {
    mockTasksState({ data: [] });
    render(<DriverTasksPage />);

    fireEvent.click(screen.getByLabelText('shell.nav.home'));

    expect(navigateMock).toHaveBeenCalledWith(ROUTES.driverHome);
  });
});

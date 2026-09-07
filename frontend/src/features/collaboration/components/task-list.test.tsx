import '@testing-library/jest-dom/vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi, beforeEach } from 'vitest';

// Selector-mode i18n → resolve t($ => $.a.b.c) to the dotted path string.
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

const useTasksMock = vi.hoisted(() => vi.fn());
vi.mock('../hooks/use-tasks', () => ({ useTasks: (filters: unknown) => useTasksMock(filters) }));

import { TaskList } from './task-list';
import type { Task } from '../types';

const TASK_1: Task = {
  id: 't1',
  company_id: 'c1',
  // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- test fixture value for a Task.title field, not UI copy
  title: 'Task One',
  description: null,
  creator_user_id: 1,
  creator_name: 'Creator',
  assignee_user_id: 2,
  assignee_name: 'Assignee',
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
  created_at: '2026-08-01T00:00:00Z',
  updated_at: '2026-08-01T00:00:00Z',
};

// eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- test fixture value for a Task.title field, not UI copy
const TASK_2: Task = { ...TASK_1, id: 't2', title: 'Task Two' };

function mockUseTasks(over: { data?: Task[]; isLoading?: boolean; isError?: boolean; refetch?: () => void } = {}) {
  useTasksMock.mockReturnValue({
    data: over.data,
    isLoading: over.isLoading ?? false,
    isError: over.isError ?? false,
    refetch: over.refetch ?? vi.fn(),
  });
}

describe('TaskList', () => {
  beforeEach(() => {
    useTasksMock.mockReset();
  });

  it('passes the filters prop straight through to useTasks', () => {
    mockUseTasks({ data: [] });
    render(<TaskList filters={{ scope: 'mine', status: 'in_progress' }} activeTaskId={null} onSelect={() => {}} />);
    expect(useTasksMock).toHaveBeenCalledWith({ scope: 'mine', status: 'in_progress' });
  });

  it('shows the loading state while isLoading is true', () => {
    mockUseTasks({ isLoading: true });
    render(<TaskList filters={{ scope: 'mine' }} activeTaskId={null} onSelect={() => {}} />);
    expect(screen.getByText('loading')).toBeInTheDocument();
  });

  it('shows an error state with a retry button that calls refetch', () => {
    const refetch = vi.fn();
    mockUseTasks({ isError: true, refetch });
    render(<TaskList filters={{ scope: 'mine' }} activeTaskId={null} onSelect={() => {}} />);
    expect(screen.getByText('tasks.list.error')).toBeInTheDocument();
    fireEvent.click(screen.getByText('tasks.list.retry'));
    expect(refetch).toHaveBeenCalledTimes(1);
  });

  it('shows an empty state when there are no tasks', () => {
    mockUseTasks({ data: [] });
    render(<TaskList filters={{ scope: 'mine' }} activeTaskId={null} onSelect={() => {}} />);
    expect(screen.getByText('tasks.list.empty.title')).toBeInTheDocument();
    expect(screen.getByText('tasks.list.empty.subtitle')).toBeInTheDocument();
  });

  it('renders one TaskListItem per task and calls onSelect with the clicked task', () => {
    const onSelect = vi.fn();
    mockUseTasks({ data: [TASK_1, TASK_2] });
    render(<TaskList filters={{ scope: 'mine' }} activeTaskId={null} onSelect={onSelect} />);

    expect(screen.getByText('Task One')).toBeInTheDocument();
    expect(screen.getByText('Task Two')).toBeInTheDocument();

    fireEvent.click(screen.getByText('Task Two'));
    expect(onSelect).toHaveBeenCalledWith(TASK_2);
  });
});

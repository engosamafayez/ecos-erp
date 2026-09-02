import '@testing-library/jest-dom/vitest';
import type { ReactNode } from 'react';
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

// jsdom lacks the pointer-capture APIs Radix Select needs — TaskFilters uses Select internally.
vi.mock('@/components/ui/select', () => ({
  Select: ({ value, onValueChange, children }: { value: string; onValueChange: (v: string) => void; children: ReactNode }) => (
    <select data-testid="select" value={value} onChange={(e) => onValueChange(e.target.value)}>{children}</select>
  ),
  SelectTrigger: ({ children }: { children: ReactNode }) => <>{children}</>,
  SelectValue: () => null,
  SelectContent: ({ children }: { children: ReactNode }) => <>{children}</>,
  SelectItem: ({ value, children }: { value: string; children: ReactNode }) => <option value={value}>{children}</option>,
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

  it('shows the loading state while isLoading is true', () => {
    mockUseTasks({ isLoading: true });
    render(<TaskList activeTaskId={null} onSelect={() => {}} onCreate={() => {}} />);
    expect(screen.getByText('loading')).toBeInTheDocument();
  });

  it('shows an error state with a retry button that calls refetch', () => {
    const refetch = vi.fn();
    mockUseTasks({ isError: true, refetch });
    render(<TaskList activeTaskId={null} onSelect={() => {}} onCreate={() => {}} />);
    expect(screen.getByText('tasks.list.error')).toBeInTheDocument();
    fireEvent.click(screen.getByText('tasks.list.retry'));
    expect(refetch).toHaveBeenCalledTimes(1);
  });

  it('shows an empty state when there are no tasks', () => {
    mockUseTasks({ data: [] });
    render(<TaskList activeTaskId={null} onSelect={() => {}} onCreate={() => {}} />);
    expect(screen.getByText('tasks.list.empty.title')).toBeInTheDocument();
    expect(screen.getByText('tasks.list.empty.subtitle')).toBeInTheDocument();
  });

  it('renders one TaskListItem per task and calls onSelect with the clicked task', () => {
    const onSelect = vi.fn();
    mockUseTasks({ data: [TASK_1, TASK_2] });
    render(<TaskList activeTaskId={null} onSelect={onSelect} onCreate={() => {}} />);

    expect(screen.getByText('Task One')).toBeInTheDocument();
    expect(screen.getByText('Task Two')).toBeInTheDocument();

    fireEvent.click(screen.getByText('Task Two'));
    expect(onSelect).toHaveBeenCalledWith(TASK_2);
  });

  it('calls onCreate when "New Task" is clicked', () => {
    const onCreate = vi.fn();
    mockUseTasks({ data: [] });
    render(<TaskList activeTaskId={null} onSelect={() => {}} onCreate={onCreate} />);
    fireEvent.click(screen.getByText('tasks.create'));
    expect(onCreate).toHaveBeenCalledTimes(1);
  });

  it('passes updated filters to useTasks when a filter changes', () => {
    mockUseTasks({ data: [] });
    render(<TaskList activeTaskId={null} onSelect={() => {}} onCreate={() => {}} />);

    expect(useTasksMock).toHaveBeenLastCalledWith({ scope: 'mine' });

    const statusSelect = screen.getAllByTestId('select')[1];
    fireEvent.change(statusSelect, { target: { value: 'in_progress' } });

    expect(useTasksMock).toHaveBeenLastCalledWith({ scope: 'mine', status: 'in_progress' });
  });
});

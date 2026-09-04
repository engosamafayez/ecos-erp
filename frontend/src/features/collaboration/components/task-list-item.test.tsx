import '@testing-library/jest-dom/vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

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

import { TaskListItem } from './task-list-item';
import type { Task } from '../types';

const BASE_TASK: Task = {
  id: 't1',
  company_id: 'c1',
  // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- test fixture value for a Task.title field, not UI copy
  title: 'Follow up with supplier',
  description: null,
  creator_user_id: 1,
  creator_name: 'Creator One',
  assignee_user_id: 2,
  assignee_name: 'Assignee Two',
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

describe('TaskListItem', () => {
  it('renders the title, status badge and priority badge', () => {
    render(<TaskListItem task={BASE_TASK} isActive={false} onSelect={() => {}} />);
    expect(screen.getByText('Follow up with supplier')).toBeInTheDocument();
    expect(screen.getByText('tasks.status.todo')).toBeInTheDocument();
    expect(screen.getByText('tasks.priority.normal')).toBeInTheDocument();
  });

  it('renders the assignee name when set', () => {
    render(<TaskListItem task={BASE_TASK} isActive={false} onSelect={() => {}} />);
    expect(screen.getByText('Assignee Two')).toBeInTheDocument();
  });

  it('omits the assignee line when assignee_name is not set', () => {
    render(<TaskListItem task={{ ...BASE_TASK, assignee_name: null }} isActive={false} onSelect={() => {}} />);
    expect(screen.queryByText('Assignee Two')).not.toBeInTheDocument();
  });

  it('shows a formatted due-date line when due_at is set, without overdue treatment', () => {
    render(<TaskListItem task={{ ...BASE_TASK, due_at: '2026-09-10T00:00:00Z', is_overdue: false }} isActive={false} onSelect={() => {}} />);
    const dueLine = screen.getByText('tasks.dueAt');
    expect(dueLine).toBeInTheDocument();
    expect(dueLine.className).not.toContain('text-destructive');
    expect(dueLine.querySelector('svg')).not.toBeInTheDocument();
  });

  it('gives the due-date line an overdue visual treatment (icon + destructive styling) when is_overdue is true', () => {
    render(<TaskListItem task={{ ...BASE_TASK, due_at: '2026-08-01T00:00:00Z', is_overdue: true }} isActive={false} onSelect={() => {}} />);
    const dueLine = screen.getByText('tasks.dueAt');
    expect(dueLine.className).toContain('text-destructive');
    expect(dueLine.querySelector('svg')).toBeInTheDocument();
  });

  it('renders no due-date line when due_at is null', () => {
    render(<TaskListItem task={BASE_TASK} isActive={false} onSelect={() => {}} />);
    expect(screen.queryByText('tasks.dueAt')).not.toBeInTheDocument();
  });

  it('calls onSelect when clicked', () => {
    const onSelect = vi.fn();
    render(<TaskListItem task={BASE_TASK} isActive={false} onSelect={onSelect} />);
    fireEvent.click(screen.getByRole('button'));
    expect(onSelect).toHaveBeenCalledTimes(1);
  });

  it('sets aria-current when isActive is true, and omits it when false', () => {
    const { rerender } = render(<TaskListItem task={BASE_TASK} isActive={true} onSelect={() => {}} />);
    expect(screen.getByRole('button')).toHaveAttribute('aria-current', 'true');

    rerender(<TaskListItem task={BASE_TASK} isActive={false} onSelect={() => {}} />);
    expect(screen.getByRole('button')).not.toHaveAttribute('aria-current');
  });
});

import '@testing-library/jest-dom/vitest';
import type { ReactNode } from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi, beforeEach } from 'vitest';

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

// jsdom lacks the pointer-capture/portal behavior Radix Popover needs — render
// content unconditionally, same posture as the DropdownMenu mock in task-board.test.tsx.
vi.mock('@/components/ui/popover', async () => {
  const React = await import('react');
  return {
    Popover: ({ children }: { children: ReactNode }) => React.createElement(React.Fragment, null, children),
    PopoverTrigger: ({ children }: { children: ReactNode }) => React.createElement(React.Fragment, null, children),
    PopoverContent: ({ children }: { children: ReactNode }) => React.createElement('div', null, children),
  };
});

vi.mock('@/components/ds/use-toast', () => ({
  toast: { success: vi.fn(), error: vi.fn(), warning: vi.fn(), info: vi.fn() },
}));

const mocks = vi.hoisted(() => ({
  useTaskLabels: vi.fn(),
  useAttachTaskLabel: vi.fn(),
  useDetachTaskLabel: vi.fn(),
  useCreateTaskLabel: vi.fn(),
}));
vi.mock('../hooks/use-tasks', () => ({
  useTaskLabels: () => mocks.useTaskLabels(),
  useAttachTaskLabel: () => mocks.useAttachTaskLabel(),
  useDetachTaskLabel: () => mocks.useDetachTaskLabel(),
  useCreateTaskLabel: () => mocks.useCreateTaskLabel(),
}));

import { TaskLabelPicker } from './task-label-picker';
import type { Task } from '../types';

const LABEL_URGENT = { id: 'l1', name: 'Urgent', color: 'red' as const };
const LABEL_BLOCKED = { id: 'l2', name: 'Blocked', color: 'orange' as const };

function makeTask(overrides: Partial<Task> = {}): Task {
  return {
    id: 't1',
    company_id: 'c1',
    title: 'x',
    description: null,
    creator_user_id: 1,
    assignee_user_id: 1,
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
    ...overrides,
  };
}

let attachMutate: ReturnType<typeof vi.fn>;
let detachMutate: ReturnType<typeof vi.fn>;
let createMutate: ReturnType<typeof vi.fn>;

beforeEach(() => {
  vi.clearAllMocks();
  mocks.useTaskLabels.mockReturnValue({ data: [LABEL_URGENT, LABEL_BLOCKED] });
  attachMutate = vi.fn();
  mocks.useAttachTaskLabel.mockReturnValue({ mutate: attachMutate, isPending: false });
  detachMutate = vi.fn();
  mocks.useDetachTaskLabel.mockReturnValue({ mutate: detachMutate, isPending: false });
  createMutate = vi.fn((_vars, opts) => opts?.onSuccess?.({ id: 'l3', name: 'New', color: 'blue' }));
  mocks.useCreateTaskLabel.mockReturnValue({ mutate: createMutate, isPending: false });
});

describe('TaskLabelPicker', () => {
  it('lists every company label and marks attached ones', () => {
    render(<TaskLabelPicker task={makeTask({ labels: [LABEL_URGENT] })} />);
    expect(screen.getByText('Urgent')).toBeInTheDocument();
    expect(screen.getByText('Blocked')).toBeInTheDocument();
  });

  it('attaches an unattached label on click', () => {
    render(<TaskLabelPicker task={makeTask({ labels: [] })} />);
    fireEvent.click(screen.getByText('Blocked'));
    expect(attachMutate).toHaveBeenCalledWith('l2', expect.anything());
  });

  it('detaches an already-attached label on click', () => {
    render(<TaskLabelPicker task={makeTask({ labels: [LABEL_URGENT] })} />);
    fireEvent.click(screen.getByText('Urgent'));
    expect(detachMutate).toHaveBeenCalledWith('l1', expect.anything());
  });

  it('creates a new label with the selected color and attaches it', () => {
    render(<TaskLabelPicker task={makeTask({ labels: [] })} />);
    fireEvent.change(screen.getByPlaceholderText('tasks.labels.namePlaceholder'), { target: { value: 'New' } });
    fireEvent.click(screen.getByLabelText('tasks.labels.colors.blue'));
    fireEvent.click(screen.getByText('tasks.labels.create'));

    expect(createMutate).toHaveBeenCalledWith({ name: 'New', color: 'blue' }, expect.anything());
    expect(attachMutate).toHaveBeenCalledWith('l3');
  });

  it('shows the "no labels" note when the company has none yet', () => {
    mocks.useTaskLabels.mockReturnValue({ data: [] });
    render(<TaskLabelPicker task={makeTask({ labels: [] })} />);
    expect(screen.getByText('tasks.labels.none')).toBeInTheDocument();
  });
});

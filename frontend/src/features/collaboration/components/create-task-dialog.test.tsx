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

vi.mock('@/components/ui/select', () => ({
  Select: ({ value, onValueChange, children }: { value: string; onValueChange: (v: string) => void; children: ReactNode }) => (
    <select data-testid="select" value={value} onChange={(e) => onValueChange(e.target.value)}>{children}</select>
  ),
  SelectTrigger: ({ children }: { children: ReactNode }) => <>{children}</>,
  SelectValue: () => null,
  SelectContent: ({ children }: { children: ReactNode }) => <>{children}</>,
  SelectItem: ({ value, children }: { value: string; children: ReactNode }) => <option value={value}>{children}</option>,
}));

// create-task-dialog imports UserPicker from the sibling './user-picker' (this test file
// already lives in the components dir).
vi.mock('./user-picker', () => ({
  UserPicker: ({ onChange }: { onChange: (user: AddressableUser | null) => void }) => (
    <button type="button" onClick={() => onChange({ id: 42, name: 'Picked User', job_title: null, is_driver: false })}>pick-user</button>
  ),
}));

vi.mock('@/components/ds/use-toast', () => ({
  toast: { success: vi.fn(), error: vi.fn(), warning: vi.fn(), info: vi.fn() },
}));

type MockAuthState = { user: { id: number; name: string } | null };
const authState = vi.hoisted(() => ({ user: { id: 7, name: 'Current User' } as { id: number; name: string } | null }));
vi.mock('@/features/auth/store/auth-store', () => ({
  useAuthStore: (selector: (state: MockAuthState) => unknown) => selector({ user: authState.user }),
}));

const mutateMock = vi.hoisted(() => vi.fn());
const useCreateTaskMock = vi.hoisted(() => vi.fn(() => ({ mutate: mutateMock, isPending: false })));
vi.mock('../hooks/use-tasks', () => ({ useCreateTask: () => useCreateTaskMock() }));

import { CreateTaskDialog } from './create-task-dialog';
import type { AddressableUser, Task } from '../types';

function fakeTask(): Task {
  return {
    id: 'new-task',
    company_id: 'c1',
    // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- test fixture value for a Task.title field, not UI copy
    title: 'New Task',
    description: null,
    creator_user_id: 7,
    creator_name: 'Current User',
    assignee_user_id: 7,
    assignee_name: 'Current User',
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
    created_at: '2026-09-02T00:00:00Z',
    updated_at: '2026-09-02T00:00:00Z',
  };
}

describe('CreateTaskDialog', () => {
  beforeEach(() => {
    mutateMock.mockReset();
    useCreateTaskMock.mockReturnValue({ mutate: mutateMock, isPending: false });
    authState.user = { id: 7, name: 'Current User' };
  });

  it('starts with an empty title and a disabled submit button when there is no sourceMessage', () => {
    render(<CreateTaskDialog open={true} onOpenChange={() => {}} onCreated={() => {}} />);
    const titleInput = screen.getByPlaceholderText('tasks.createDialog.titlePlaceholder') as HTMLInputElement;
    expect(titleInput.value).toBe('');
    expect(screen.getByText('tasks.createDialog.submit').closest('button')).toBeDisabled();
  });

  it('enables submit once a title is entered', () => {
    render(<CreateTaskDialog open={true} onOpenChange={() => {}} onCreated={() => {}} />);
    const titleInput = screen.getByPlaceholderText('tasks.createDialog.titlePlaceholder');
    fireEvent.change(titleInput, { target: { value: 'Call the supplier' } });
    expect(screen.getByText('tasks.createDialog.submit').closest('button')).not.toBeDisabled();
  });

  it('auto-fills the title from sourceMessage.body (truncated to 120 chars) and shows a "from message" note', () => {
    const longBody = 'x'.repeat(150);
    render(
      <CreateTaskDialog
        open={true}
        onOpenChange={() => {}}
        onCreated={() => {}}
        sourceMessage={{ id: 'm1', body: longBody }}
      />,
    );
    const titleInput = screen.getByPlaceholderText('tasks.createDialog.titlePlaceholder') as HTMLInputElement;
    expect(titleInput.value).toBe('x'.repeat(120));
    expect(titleInput.value.length).toBe(120);

    // The "from message" note shows the full (untruncated) body text.
    expect(screen.getByText((_, el) => el?.textContent === `tasks.createDialog.fromMessage: ${longBody}`)).toBeInTheDocument();
  });

  it('submits with sourceMessageId set to the sourceMessage id when present', () => {
    render(
      <CreateTaskDialog
        open={true}
        onOpenChange={() => {}}
        onCreated={() => {}}
        sourceMessage={{ id: 'm1', body: 'please follow up' }}
      />,
    );
    fireEvent.click(screen.getByText('tasks.createDialog.submit'));
    expect(mutateMock).toHaveBeenCalledTimes(1);
    expect(mutateMock.mock.calls[0][0]).toMatchObject({ sourceMessageId: 'm1' });
  });

  it('submits with sourceMessageId null when there is no sourceMessage', () => {
    render(<CreateTaskDialog open={true} onOpenChange={() => {}} onCreated={() => {}} />);
    fireEvent.change(screen.getByPlaceholderText('tasks.createDialog.titlePlaceholder'), { target: { value: 'Do the thing' } });
    fireEvent.click(screen.getByText('tasks.createDialog.submit'));
    expect(mutateMock.mock.calls[0][0]).toMatchObject({ sourceMessageId: null });
  });

  it('defaults assigneeUserId to the current user id when no assignee is picked', () => {
    render(<CreateTaskDialog open={true} onOpenChange={() => {}} onCreated={() => {}} />);
    fireEvent.change(screen.getByPlaceholderText('tasks.createDialog.titlePlaceholder'), { target: { value: 'Do the thing' } });
    fireEvent.click(screen.getByText('tasks.createDialog.submit'));
    expect(mutateMock.mock.calls[0][0]).toMatchObject({ assigneeUserId: 7 });
  });

  it('uses the picked user id as assigneeUserId when a user is picked via UserPicker', () => {
    render(<CreateTaskDialog open={true} onOpenChange={() => {}} onCreated={() => {}} />);
    fireEvent.change(screen.getByPlaceholderText('tasks.createDialog.titlePlaceholder'), { target: { value: 'Do the thing' } });
    fireEvent.click(screen.getByText('pick-user'));
    fireEvent.click(screen.getByText('tasks.createDialog.submit'));
    expect(mutateMock.mock.calls[0][0]).toMatchObject({ assigneeUserId: 42 });
  });

  it('renders the due field as a datetime-local input and submits an ISO due_at including the time', () => {
    render(<CreateTaskDialog open={true} onOpenChange={() => {}} onCreated={() => {}} />);
    fireEvent.change(screen.getByPlaceholderText('tasks.createDialog.titlePlaceholder'), { target: { value: 'Do the thing' } });

    const dueInput = screen.getByText('tasks.createDialog.dueLabel').parentElement?.querySelector('input[type="datetime-local"]');
    expect(dueInput).not.toBeNull();
    fireEvent.change(dueInput as HTMLInputElement, { target: { value: '2026-09-20T14:30' } });

    fireEvent.click(screen.getByText('tasks.createDialog.submit'));
    const dueAt = mutateMock.mock.calls[0][0].dueAt as string;
    expect(new Date(dueAt).getHours()).toBe(14);
    expect(new Date(dueAt).getMinutes()).toBe(30);
  });

  it('submits dueAt null when no due date/time is entered', () => {
    render(<CreateTaskDialog open={true} onOpenChange={() => {}} onCreated={() => {}} />);
    fireEvent.change(screen.getByPlaceholderText('tasks.createDialog.titlePlaceholder'), { target: { value: 'Do the thing' } });
    fireEvent.click(screen.getByText('tasks.createDialog.submit'));
    expect(mutateMock.mock.calls[0][0]).toMatchObject({ dueAt: null });
  });

  it('calls onCreated and closes the dialog when the mutation succeeds', () => {
    const onCreated = vi.fn();
    const onOpenChange = vi.fn();
    render(<CreateTaskDialog open={true} onOpenChange={onOpenChange} onCreated={onCreated} />);
    fireEvent.change(screen.getByPlaceholderText('tasks.createDialog.titlePlaceholder'), { target: { value: 'Do the thing' } });
    fireEvent.click(screen.getByText('tasks.createDialog.submit'));

    const task = fakeTask();
    const onSuccess = mutateMock.mock.calls[0][1].onSuccess as (t: Task) => void;
    onSuccess(task);

    expect(onCreated).toHaveBeenCalledWith(task);
    expect(onOpenChange).toHaveBeenCalledWith(false);
  });
});

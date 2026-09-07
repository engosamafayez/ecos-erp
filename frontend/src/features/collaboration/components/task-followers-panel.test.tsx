import '@testing-library/jest-dom/vitest';
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

vi.mock('@/components/ds/use-toast', () => ({
  toast: { success: vi.fn(), error: vi.fn(), warning: vi.fn(), info: vi.fn() },
}));

vi.mock('./user-picker', () => ({
  UserPicker: ({ onChange }: { onChange: (user: { id: number; name: string; is_driver: boolean } | null) => void }) => (
    <button type="button" onClick={() => onChange({ id: 55, name: 'New Watcher', is_driver: false })}>pick-follower</button>
  ),
}));

type MockAuthState = { user: { id: number; name: string } | null };
const authState = vi.hoisted(() => ({ userId: 1 as number | undefined }));
vi.mock('@/features/auth/store/auth-store', () => ({
  useAuthStore: (selector: (state: MockAuthState) => unknown) =>
    selector({ user: authState.userId === undefined ? null : { id: authState.userId, name: 'Current User' } }),
}));

const mocks = vi.hoisted(() => ({ useFollowTask: vi.fn(), useUnfollowTask: vi.fn() }));
vi.mock('../hooks/use-tasks', () => ({
  useFollowTask: () => mocks.useFollowTask(),
  useUnfollowTask: () => mocks.useUnfollowTask(),
}));

import { TaskFollowersPanel } from './task-followers-panel';
import type { Task } from '../types';

function makeTask(overrides: Partial<Task> = {}): Task {
  return {
    id: 't1',
    company_id: 'c1',
    title: 'x',
    description: null,
    creator_user_id: 1,
    assignee_user_id: 2,
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

let followMutate: ReturnType<typeof vi.fn>;
let unfollowMutate: ReturnType<typeof vi.fn>;

beforeEach(() => {
  vi.clearAllMocks();
  authState.userId = 1;
  followMutate = vi.fn();
  mocks.useFollowTask.mockReturnValue({ mutate: followMutate, isPending: false });
  unfollowMutate = vi.fn();
  mocks.useUnfollowTask.mockReturnValue({ mutate: unfollowMutate, isPending: false });
});

describe('TaskFollowersPanel', () => {
  it('shows the "no followers" note when there are none', () => {
    render(<TaskFollowersPanel task={makeTask({ followers: [] })} />);
    expect(screen.getByText('tasks.followers.none')).toBeInTheDocument();
  });

  it('lists each follower by name, showing "You" for the current user', () => {
    render(<TaskFollowersPanel task={makeTask({ followers: [{ user_id: 1, name: 'Current User' }, { user_id: 9, name: 'Other Person' }] })} />);
    expect(screen.getByText('tasks.followers.you')).toBeInTheDocument();
    expect(screen.getByText('Other Person')).toBeInTheDocument();
  });

  it('offers a self-follow button when the current user is not already following', () => {
    render(<TaskFollowersPanel task={makeTask({ followers: [] })} />);
    fireEvent.click(screen.getByText('tasks.followers.follow'));
    expect(followMutate).toHaveBeenCalledWith(undefined, expect.anything());
  });

  it('hides the self-follow button once the current user is already following', () => {
    render(<TaskFollowersPanel task={makeTask({ followers: [{ user_id: 1, name: 'Current User' }] })} />);
    expect(screen.queryByText('tasks.followers.follow')).not.toBeInTheDocument();
  });

  it('lets the creator add someone else as a follower via the picker', () => {
    render(<TaskFollowersPanel task={makeTask({ creator_user_id: 1, followers: [] })} />);
    fireEvent.click(screen.getByText('pick-follower'));
    expect(followMutate).toHaveBeenCalledWith(55, expect.anything());
  });

  it('does not show the add-follower picker to a non-creator', () => {
    authState.userId = 2; // assignee, not creator
    render(<TaskFollowersPanel task={makeTask({ creator_user_id: 1, assignee_user_id: 2, followers: [] })} />);
    expect(screen.queryByText('pick-follower')).not.toBeInTheDocument();
  });

  it('lets a follower remove themselves', () => {
    render(<TaskFollowersPanel task={makeTask({ creator_user_id: 9, followers: [{ user_id: 1, name: 'Current User' }] })} />);
    fireEvent.click(screen.getByLabelText('tasks.followers.unfollow'));
    expect(unfollowMutate).toHaveBeenCalledWith(1, expect.anything());
  });
});

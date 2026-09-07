import '@testing-library/jest-dom/vitest';
import { act, fireEvent, render, screen } from '@testing-library/react';
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

// Each click picks a new fixed user, keyed off how many are already excluded (i.e. already
// added) — so two sequential clicks in a test yield two distinct users.
vi.mock('./user-picker', () => ({
  UserPicker: ({ onChange, excludeIds }: { onChange: (u: { id: number; name: string; is_driver: boolean } | null) => void; excludeIds?: number[] }) => {
    const n = (excludeIds?.length ?? 0) + 1;
    return (
      <button type="button" onClick={() => onChange({ id: n, name: `User ${n}`, is_driver: false })}>
        pick-user
      </button>
    );
  },
}));

import { useCreateGroupConversation } from '../hooks/use-conversations';
vi.mock('../hooks/use-conversations', () => ({ useCreateGroupConversation: vi.fn() }));

import { NewGroupDialog } from './new-group-dialog';
import type { Conversation } from '../types';

const mockUseCreate = vi.mocked(useCreateGroupConversation);

const FAKE_CONVERSATION: Conversation = {
  id: 'c-new',
  company_id: 'co1',
  type: 'group',
  // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- test fixture value for a Conversation.title field, not UI copy
  title: 'New Group',
  created_by_user_id: 1,
  team_id: null,
  last_message_at: null,
  unread_count: 0,
  my_role: 'owner',
  my_muted: false,
  created_at: '2026-09-01',
};

function setup() {
  const mutate = vi.fn();
  mockUseCreate.mockReturnValue({ mutate, isPending: false } as unknown as ReturnType<typeof useCreateGroupConversation>);
  const onOpenChange = vi.fn();
  const onCreated = vi.fn();
  render(<NewGroupDialog open onOpenChange={onOpenChange} onCreated={onCreated} />);
  return { mutate, onOpenChange, onCreated };
}

describe('NewGroupDialog', () => {
  beforeEach(() => {
    mockUseCreate.mockReset();
  });

  it('disables Submit until the title is non-empty', () => {
    setup();
    const submit = screen.getByText('conversations.newGroupDialog.submit');
    expect(submit).toBeDisabled();

    fireEvent.change(screen.getByPlaceholderText('conversations.newGroupDialog.namePlaceholder'), { target: { value: 'Ops Team' } });
    expect(submit).not.toBeDisabled();
  });

  it('adds two picked members as chips, and removes one via its remove button', () => {
    setup();

    fireEvent.click(screen.getByText('pick-user'));
    expect(screen.getByText('User 1')).toBeInTheDocument();

    fireEvent.click(screen.getByText('pick-user'));
    expect(screen.getByText('User 2')).toBeInTheDocument();

    const removeButtons = screen.getAllByLabelText('conversations.info.removeMember');
    expect(removeButtons).toHaveLength(2);

    fireEvent.click(removeButtons[0]);
    expect(screen.queryByText('User 1')).not.toBeInTheDocument();
    expect(screen.getByText('User 2')).toBeInTheDocument();
  });

  it('submits the title and participant ids, then closes + reports onCreated on success', () => {
    const { mutate, onOpenChange, onCreated } = setup();

    fireEvent.change(screen.getByPlaceholderText('conversations.newGroupDialog.namePlaceholder'), { target: { value: '  Ops Team  ' } });
    fireEvent.click(screen.getByText('pick-user'));
    fireEvent.click(screen.getByText('pick-user'));

    fireEvent.click(screen.getByText('conversations.newGroupDialog.submit'));

    expect(mutate).toHaveBeenCalledWith(
      // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- test fixture value for the mutation's title argument, not UI copy
      { title: 'Ops Team', participantUserIds: [1, 2] },
      expect.objectContaining({ onSuccess: expect.any(Function), onError: expect.any(Function) }),
    );

    const onSuccess = mutate.mock.calls[0][1].onSuccess as (c: Conversation) => void;
    act(() => onSuccess(FAKE_CONVERSATION));

    expect(onCreated).toHaveBeenCalledWith(FAKE_CONVERSATION);
    expect(onOpenChange).toHaveBeenCalledWith(false);
  });
});

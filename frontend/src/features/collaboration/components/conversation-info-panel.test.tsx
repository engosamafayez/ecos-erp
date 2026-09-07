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

vi.mock('@/features/auth/store/auth-store', () => ({
  useAuthStore: (selector: (s: { user: { id: number; name: string } } | { user: null }) => unknown) =>
    selector({ user: { id: 1, name: 'Me' } }),
}));

vi.mock('./user-picker', () => ({
  UserPicker: ({ onChange }: { onChange: (u: { id: number; name: string; is_driver: boolean } | null) => void }) => (
    <button type="button" onClick={() => onChange({ id: 99, name: 'New Person', is_driver: false })}>pick-user</button>
  ),
}));

import { useAddParticipant, useMuteConversation, useRemoveParticipant } from '../hooks/use-conversations';
vi.mock('../hooks/use-conversations', () => ({
  useAddParticipant: vi.fn(),
  useRemoveParticipant: vi.fn(),
  useMuteConversation: vi.fn(),
}));

vi.mock('../hooks/use-conversation-media', () => ({
  useConversationMedia: vi.fn(() => ({ data: [], isLoading: false, isError: false })),
}));

import { ConversationInfoPanel } from './conversation-info-panel';
import type { Conversation } from '../types';

const mockUseAdd = vi.mocked(useAddParticipant);
const mockUseRemove = vi.mocked(useRemoveParticipant);
const mockUseMute = vi.mocked(useMuteConversation);

const OWNER_CONVERSATION: Conversation = {
  id: 'c1',
  company_id: 'co1',
  type: 'group',
  // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- test fixture value for a Conversation.title field, not UI copy
  title: 'Ops Team',
  created_by_user_id: 1,
  team_id: null,
  last_message_at: null,
  unread_count: 0,
  my_role: 'owner',
  my_muted: false,
  participants: [
    { id: 'p1', conversation_id: 'c1', user_id: 1, name: 'Me', role: 'owner', joined_at: '2026-01-01', left_at: null, last_read_at: null, last_read_message_id: null, muted_at: null },
    { id: 'p2', conversation_id: 'c1', user_id: 2, name: 'Other', role: 'member', joined_at: '2026-01-01', left_at: null, last_read_at: null, last_read_message_id: null, muted_at: null },
  ],
  created_at: '2026-01-01',
};

const MEMBER_CONVERSATION: Conversation = {
  ...OWNER_CONVERSATION,
  my_role: 'member',
};

function setupMutations() {
  const addMutate = vi.fn();
  const removeMutate = vi.fn();
  mockUseAdd.mockReturnValue({ mutate: addMutate, isPending: false } as unknown as ReturnType<typeof useAddParticipant>);
  mockUseRemove.mockReturnValue({ mutate: removeMutate, isPending: false } as unknown as ReturnType<typeof useRemoveParticipant>);
  mockUseMute.mockReturnValue({ mutate: vi.fn(), isPending: false } as unknown as ReturnType<typeof useMuteConversation>);
  return { addMutate, removeMutate };
}

describe('ConversationInfoPanel', () => {
  beforeEach(() => {
    mockUseAdd.mockReset();
    mockUseRemove.mockReset();
  });

  it('renders nothing (no crash, no participant list) when conversation is null', () => {
    setupMutations();
    const { container } = render(<ConversationInfoPanel conversation={null} open onOpenChange={vi.fn()} onLeft={vi.fn()} />);
    expect(container).toBeEmptyDOMElement();
    expect(screen.queryByText('Other')).not.toBeInTheDocument();
  });

  it('renders participant names', () => {
    setupMutations();
    render(<ConversationInfoPanel conversation={OWNER_CONVERSATION} open onOpenChange={vi.fn()} onLeft={vi.fn()} />);
    expect(screen.getByText('Other')).toBeInTheDocument();
    // The current user's own row appends a "(you)" suffix, so match the full label.
    expect(screen.getByText('Me (conversations.list.you)')).toBeInTheDocument();
  });

  it('shows an Add member control for the owner that toggles the user picker', () => {
    setupMutations();
    render(<ConversationInfoPanel conversation={OWNER_CONVERSATION} open onOpenChange={vi.fn()} onLeft={vi.fn()} />);

    expect(screen.queryByText('pick-user')).not.toBeInTheDocument();
    fireEvent.click(screen.getByText('conversations.info.addMember'));
    expect(screen.getByText('pick-user')).toBeInTheDocument();

    fireEvent.click(screen.getByText('conversations.info.addMember'));
    expect(screen.queryByText('pick-user')).not.toBeInTheDocument();
  });

  it('shows no add/remove controls for a non-owner member', () => {
    setupMutations();
    render(<ConversationInfoPanel conversation={MEMBER_CONVERSATION} open onOpenChange={vi.fn()} onLeft={vi.fn()} />);

    expect(screen.queryByText('conversations.info.addMember')).not.toBeInTheDocument();
    expect(screen.queryByLabelText('conversations.info.removeMember')).not.toBeInTheDocument();
  });

  it('calls the remove mutation with the target user id when removing a non-self member', () => {
    const { removeMutate } = setupMutations();
    render(<ConversationInfoPanel conversation={OWNER_CONVERSATION} open onOpenChange={vi.fn()} onLeft={vi.fn()} />);

    // Only the non-self member ("Other", user_id 2) gets a remove control.
    const removeButtons = screen.getAllByLabelText('conversations.info.removeMember');
    expect(removeButtons).toHaveLength(1);
    fireEvent.click(removeButtons[0]);

    expect(removeMutate).toHaveBeenCalledWith(2, expect.objectContaining({ onError: expect.any(Function) }));
  });

  it('leaving the group calls remove with the current user id and closes the panel on success', () => {
    const { removeMutate } = setupMutations();
    const onOpenChange = vi.fn();
    const onLeft = vi.fn();
    render(<ConversationInfoPanel conversation={OWNER_CONVERSATION} open onOpenChange={onOpenChange} onLeft={onLeft} />);

    fireEvent.click(screen.getByText('conversations.info.leaveGroup'));

    expect(removeMutate).toHaveBeenCalledWith(1, expect.objectContaining({ onSuccess: expect.any(Function), onError: expect.any(Function) }));

    const onSuccess = removeMutate.mock.calls[0][1].onSuccess as () => void;
    act(() => onSuccess());

    expect(onOpenChange).toHaveBeenCalledWith(false);
    expect(onLeft).toHaveBeenCalledTimes(1);
  });
});

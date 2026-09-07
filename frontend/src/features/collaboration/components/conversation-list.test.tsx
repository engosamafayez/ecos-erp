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

vi.mock('@/features/auth/store/auth-store', () => ({
  useAuthStore: (selector: (s: { user: { id: number; name: string } } | { user: null }) => unknown) =>
    selector({ user: { id: 1, name: 'Current User' } }),
}));

// Radix DropdownMenu needs pointer-capture interactions jsdom doesn't fully support.
// Render trigger + content children directly so item clicks are deterministic.
vi.mock('@/components/ui/dropdown-menu', () => ({
  DropdownMenu: ({ children }: { children?: ReactNode }) => <>{children}</>,
  DropdownMenuTrigger: ({ children }: { children?: ReactNode }) => <>{children}</>,
  DropdownMenuContent: ({ children }: { children?: ReactNode }) => <div>{children}</div>,
  DropdownMenuItem: ({ children, onSelect }: { children?: ReactNode; onSelect?: () => void }) => (
    <button type="button" onClick={() => onSelect?.()}>{children}</button>
  ),
}));

import { useConversations } from '../hooks/use-conversations';
vi.mock('../hooks/use-conversations', () => ({ useConversations: vi.fn() }));

import { ConversationList } from './conversation-list';
import type { Conversation } from '../types';

const mockUseConversations = vi.mocked(useConversations);

const GROUP: Conversation = {
  id: 'c1',
  company_id: 'co1',
  type: 'group',
  // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- test fixture value for a Conversation.title field, not UI copy
  title: 'Team Alpha',
  created_by_user_id: 1,
  team_id: null,
  last_message_at: null,
  unread_count: 2,
  my_role: 'owner',
  my_muted: false,
  participants: [
    { id: 'p1', conversation_id: 'c1', user_id: 1, name: 'Current User', role: 'owner', joined_at: '2026-01-01', left_at: null, last_read_at: null, last_read_message_id: null, muted_at: null },
    { id: 'p2', conversation_id: 'c1', user_id: 2, name: 'Bob', role: 'member', joined_at: '2026-01-01', left_at: null, last_read_at: null, last_read_message_id: null, muted_at: null },
  ],
  created_at: '2026-01-01',
};

const DIRECT: Conversation = {
  id: 'c2',
  company_id: 'co1',
  type: 'direct',
  title: null,
  created_by_user_id: 1,
  team_id: null,
  last_message_at: null,
  unread_count: 0,
  my_role: 'member',
  my_muted: false,
  participants: [
    { id: 'p3', conversation_id: 'c2', user_id: 1, name: 'Current User', role: 'member', joined_at: '2026-01-01', left_at: null, last_read_at: null, last_read_message_id: null, muted_at: null },
    { id: 'p4', conversation_id: 'c2', user_id: 3, name: 'Jane Doe', role: 'member', joined_at: '2026-01-01', left_at: null, last_read_at: null, last_read_message_id: null, muted_at: null },
  ],
  created_at: '2026-01-01',
};

function withConversations(over: Partial<{ data: Conversation[]; isLoading: boolean; isError: boolean; refetch: () => void }> = {}) {
  mockUseConversations.mockReturnValue({
    data: over.data ?? [GROUP, DIRECT],
    isLoading: over.isLoading ?? false,
    isError: over.isError ?? false,
    refetch: over.refetch ?? vi.fn(),
  } as unknown as ReturnType<typeof useConversations>);
}

describe('ConversationList', () => {
  beforeEach(() => {
    mockUseConversations.mockReset();
  });

  it('shows the loading state while conversations are loading', () => {
    withConversations({ isLoading: true });
    render(<ConversationList activeConversationId={null} onSelect={vi.fn()} onNewDirect={vi.fn()} onNewGroup={vi.fn()} />);
    expect(screen.getByText('loading')).toBeInTheDocument();
    expect(screen.queryByText('Team Alpha')).not.toBeInTheDocument();
  });

  it('shows an error empty-state and retries via refetch', () => {
    const refetch = vi.fn();
    withConversations({ isError: true, refetch });
    render(<ConversationList activeConversationId={null} onSelect={vi.fn()} onNewDirect={vi.fn()} onNewGroup={vi.fn()} />);
    expect(screen.getByText('conversations.list.error')).toBeInTheDocument();
    fireEvent.click(screen.getByText('conversations.list.retry'));
    expect(refetch).toHaveBeenCalledTimes(1);
  });

  it('shows the empty state when there are no conversations', () => {
    withConversations({ data: [] });
    render(<ConversationList activeConversationId={null} onSelect={vi.fn()} onNewDirect={vi.fn()} onNewGroup={vi.fn()} />);
    expect(screen.getByText('conversations.list.empty.title')).toBeInTheDocument();
    expect(screen.getByText('conversations.list.empty.subtitle')).toBeInTheDocument();
  });

  it('renders one item per conversation and calls onSelect with the clicked conversation', () => {
    withConversations();
    const onSelect = vi.fn();
    render(<ConversationList activeConversationId={null} onSelect={onSelect} onNewDirect={vi.fn()} onNewGroup={vi.fn()} />);

    expect(screen.getByText('Team Alpha')).toBeInTheDocument();
    expect(screen.getByText('Jane Doe')).toBeInTheDocument();

    fireEvent.click(screen.getByText('Jane Doe'));
    expect(onSelect).toHaveBeenCalledTimes(1);
    expect(onSelect).toHaveBeenCalledWith(DIRECT);
  });

  it('filters the visible list by the search box using the display title', () => {
    withConversations();
    render(<ConversationList activeConversationId={null} onSelect={vi.fn()} onNewDirect={vi.fn()} onNewGroup={vi.fn()} />);

    const search = screen.getByPlaceholderText('conversations.title');
    fireEvent.change(search, { target: { value: 'jane' } });

    expect(screen.getByText('Jane Doe')).toBeInTheDocument();
    expect(screen.queryByText('Team Alpha')).not.toBeInTheDocument();
  });

  it('shows the trigger button and calls onNewDirect / onNewGroup from the menu items', () => {
    withConversations();
    const onNewDirect = vi.fn();
    const onNewGroup = vi.fn();
    render(<ConversationList activeConversationId={null} onSelect={vi.fn()} onNewDirect={onNewDirect} onNewGroup={onNewGroup} />);

    // The icon-only trigger button carries the aria-label; the menu items carry visible text.
    expect(screen.getByLabelText('conversations.newDirect')).toBeInTheDocument();

    fireEvent.click(screen.getByText('conversations.newDirect'));
    expect(onNewDirect).toHaveBeenCalledTimes(1);

    fireEvent.click(screen.getByText('conversations.newGroup'));
    expect(onNewGroup).toHaveBeenCalledTimes(1);
  });
});

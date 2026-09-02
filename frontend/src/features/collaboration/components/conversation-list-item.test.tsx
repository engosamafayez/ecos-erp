import '@testing-library/jest-dom/vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

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

import { ConversationListItem } from './conversation-list-item';
import type { Conversation } from '../types';

const GROUP: Conversation = {
  id: 'c1',
  company_id: 'co1',
  type: 'group',
  // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- test fixture value for a Conversation.title field, not UI copy
  title: 'Team Alpha',
  created_by_user_id: 1,
  team_id: null,
  last_message_at: null,
  unread_count: 0,
  my_role: 'owner',
  participants: [
    { id: 'p1', conversation_id: 'c1', user_id: 1, name: 'Current User', role: 'owner', joined_at: '2026-01-01', left_at: null, last_read_at: null, last_read_message_id: null },
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
  participants: [
    { id: 'p3', conversation_id: 'c2', user_id: 1, name: 'Current User', role: 'member', joined_at: '2026-01-01', left_at: null, last_read_at: null, last_read_message_id: null },
    { id: 'p4', conversation_id: 'c2', user_id: 3, name: 'Jane Doe', role: 'member', joined_at: '2026-01-01', left_at: null, last_read_at: null, last_read_message_id: null },
  ],
  created_at: '2026-01-01',
};

describe('ConversationListItem', () => {
  it('renders the group title for a group conversation', () => {
    render(<ConversationListItem conversation={GROUP} currentUserId={1} isActive={false} onSelect={vi.fn()} />);
    expect(screen.getByText('Team Alpha')).toBeInTheDocument();
  });

  it('resolves the other participant name for a direct conversation', () => {
    render(<ConversationListItem conversation={DIRECT} currentUserId={1} isActive={false} onSelect={vi.fn()} />);
    expect(screen.getByText('Jane Doe')).toBeInTheDocument();
  });

  it('shows no unread badge when unread_count is 0', () => {
    render(<ConversationListItem conversation={GROUP} currentUserId={1} isActive={false} onSelect={vi.fn()} />);
    expect(screen.queryByText('0')).not.toBeInTheDocument();
  });

  it('shows the unread badge when unread_count > 0', () => {
    render(<ConversationListItem conversation={{ ...GROUP, unread_count: 4 }} currentUserId={1} isActive={false} onSelect={vi.fn()} />);
    expect(screen.getByText('4')).toBeInTheDocument();
  });

  it('caps the unread badge display at "99+"', () => {
    render(<ConversationListItem conversation={{ ...GROUP, unread_count: 140 }} currentUserId={1} isActive={false} onSelect={vi.fn()} />);
    expect(screen.getByText('99+')).toBeInTheDocument();
    expect(screen.queryByText('140')).not.toBeInTheDocument();
  });

  it('sets aria-current when active', () => {
    render(<ConversationListItem conversation={GROUP} currentUserId={1} isActive onSelect={vi.fn()} />);
    expect(screen.getByRole('button')).toHaveAttribute('aria-current', 'true');
  });

  it('does not set aria-current when inactive', () => {
    render(<ConversationListItem conversation={GROUP} currentUserId={1} isActive={false} onSelect={vi.fn()} />);
    expect(screen.getByRole('button')).not.toHaveAttribute('aria-current');
  });

  it('calls onSelect when clicked', () => {
    const onSelect = vi.fn();
    render(<ConversationListItem conversation={GROUP} currentUserId={1} isActive={false} onSelect={onSelect} />);
    fireEvent.click(screen.getByRole('button'));
    expect(onSelect).toHaveBeenCalledTimes(1);
  });
});

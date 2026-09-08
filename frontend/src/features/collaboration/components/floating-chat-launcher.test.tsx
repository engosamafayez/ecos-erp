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

vi.mock('@/features/auth/store/auth-store', () => ({
  useAuthStore: (selector: (s: { user: { id: number; name: string } } | { user: null }) => unknown) =>
    selector({ user: { id: 1, name: 'Current User' } }),
}));

const mockNavigate = vi.fn();
let mockPathname = '/dashboard';
vi.mock('react-router-dom', () => ({
  useNavigate: () => mockNavigate,
  useLocation: () => ({ pathname: mockPathname }),
}));

import { useConversation, useConversations } from '../hooks/use-conversations';
vi.mock('../hooks/use-conversations', () => ({ useConversations: vi.fn(), useConversation: vi.fn() }));

vi.mock('./conversation-thread', () => ({
  ConversationThread: ({ onOpenInfo, onCreateTaskFromMessage }: { onOpenInfo: () => void; onCreateTaskFromMessage: () => void }) => (
    <div>
      <span>thread-open</span>
      <button type="button" onClick={onOpenInfo}>trigger-info</button>
      <button type="button" onClick={() => onCreateTaskFromMessage()}>trigger-create-task</button>
    </div>
  ),
}));

import { FloatingChatLauncher } from './floating-chat-launcher';
import type { Conversation } from '../types';
import { toast } from '@/components/ds/use-toast';

const mockUseConversations = vi.mocked(useConversations);
const mockUseConversation = vi.mocked(useConversation);
const mockToastInfo = vi.mocked(toast.info);

const DIRECT: Conversation = {
  id: 'c2',
  company_id: 'co1',
  type: 'direct',
  title: null,
  created_by_user_id: 1,
  team_id: null,
  last_message_at: null,
  unread_count: 3,
  my_role: 'member',
  my_muted: false,
  participants: [
    { id: 'p3', conversation_id: 'c2', user_id: 1, name: 'Current User', role: 'member', joined_at: '2026-01-01', left_at: null, last_read_at: null, last_read_message_id: null, muted_at: null },
    { id: 'p4', conversation_id: 'c2', user_id: 3, name: 'Jane Doe', role: 'member', joined_at: '2026-01-01', left_at: null, last_read_at: null, last_read_message_id: null, muted_at: null },
  ],
  created_at: '2026-01-01',
};

describe('FloatingChatLauncher (Quick Chat)', () => {
  beforeEach(() => {
    mockPathname = '/dashboard';
    mockNavigate.mockReset();
    mockToastInfo.mockReset();
    mockUseConversations.mockReturnValue({ data: [DIRECT] } as unknown as ReturnType<typeof useConversations>);
    mockUseConversation.mockReturnValue({ data: DIRECT, isLoading: false, isError: false, refetch: vi.fn() } as unknown as ReturnType<typeof useConversation>);
  });

  it('renders nothing while already inside the Collaboration workspace', () => {
    mockPathname = '/collaboration';
    const { container } = render(<FloatingChatLauncher />);
    expect(container).toBeEmptyDOMElement();
  });

  it('shows the unread badge summed across conversations', () => {
    render(<FloatingChatLauncher />);
    expect(screen.getByText('3')).toBeInTheDocument();
  });

  it('opens the Quick Chat drawer (conversation list) on click, without navigating', () => {
    render(<FloatingChatLauncher />);
    fireEvent.click(screen.getByLabelText('launcher.ariaLabelUnread'));
    expect(screen.getByText('launcher.quickChatTitle')).toBeInTheDocument();
    expect(mockNavigate).not.toHaveBeenCalled();
  });

  it('selecting a conversation shows its thread inside the drawer, still without navigating', () => {
    render(<FloatingChatLauncher />);
    fireEvent.click(screen.getByLabelText('launcher.ariaLabelUnread'));
    fireEvent.click(screen.getByText('Jane Doe'));
    expect(screen.getByText('thread-open')).toBeInTheDocument();
    expect(mockNavigate).not.toHaveBeenCalled();
  });

  it('only the "Open Full Chat" action navigates — the info/create-task actions inside the reused thread just nudge instead', () => {
    render(<FloatingChatLauncher />);
    fireEvent.click(screen.getByLabelText('launcher.ariaLabelUnread'));
    fireEvent.click(screen.getByText('Jane Doe'));

    fireEvent.click(screen.getByText('trigger-info'));
    fireEvent.click(screen.getByText('trigger-create-task'));
    expect(mockNavigate).not.toHaveBeenCalled();
    expect(mockToastInfo).toHaveBeenCalledTimes(2);

    fireEvent.click(screen.getByText('launcher.openFullChat'));
    expect(mockNavigate).toHaveBeenCalledTimes(1);
    expect(mockNavigate).toHaveBeenCalledWith(expect.stringContaining('conversationId=c2'));
  });

  it('"Open Full Chat" from the list view (no conversation selected) navigates to the plain workspace route', () => {
    render(<FloatingChatLauncher />);
    fireEvent.click(screen.getByLabelText('launcher.ariaLabelUnread'));
    fireEvent.click(screen.getByText('launcher.openFullChat'));
    expect(mockNavigate).toHaveBeenCalledWith(expect.not.stringContaining('conversationId'));
  });
});

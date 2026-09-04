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

vi.mock('./user-picker', () => ({
  UserPicker: ({ onChange }: { onChange: (u: { id: number; name: string; is_driver: boolean } | null) => void }) => (
    <button type="button" onClick={() => onChange({ id: 42, name: 'Picked User', is_driver: false })}>pick-user</button>
  ),
}));

import { useStartDirectConversation } from '../hooks/use-conversations';
vi.mock('../hooks/use-conversations', () => ({ useStartDirectConversation: vi.fn() }));

import { NewDirectDialog } from './new-direct-dialog';
import type { Conversation } from '../types';

const mockUseStart = vi.mocked(useStartDirectConversation);

const FAKE_CONVERSATION: Conversation = {
  id: 'c-new',
  company_id: 'co1',
  type: 'direct',
  title: null,
  created_by_user_id: 1,
  team_id: null,
  last_message_at: null,
  unread_count: 0,
  my_role: 'member',
  created_at: '2026-09-01',
};

describe('NewDirectDialog', () => {
  beforeEach(() => {
    mockUseStart.mockReset();
  });

  it('disables Submit until a user is picked, then enables it', () => {
    const mutate = vi.fn();
    mockUseStart.mockReturnValue({ mutate, isPending: false } as unknown as ReturnType<typeof useStartDirectConversation>);
    render(<NewDirectDialog open onOpenChange={vi.fn()} onCreated={vi.fn()} />);

    const submit = screen.getByText('conversations.newDirectDialog.submit');
    expect(submit).toBeDisabled();

    fireEvent.click(screen.getByText('pick-user'));
    expect(submit).not.toBeDisabled();
  });

  it('submits the picked recipient id and closes + reports onCreated on success', () => {
    const mutate = vi.fn();
    mockUseStart.mockReturnValue({ mutate, isPending: false } as unknown as ReturnType<typeof useStartDirectConversation>);
    const onOpenChange = vi.fn();
    const onCreated = vi.fn();
    render(<NewDirectDialog open onOpenChange={onOpenChange} onCreated={onCreated} />);

    fireEvent.click(screen.getByText('pick-user'));
    fireEvent.click(screen.getByText('conversations.newDirectDialog.submit'));

    expect(mutate).toHaveBeenCalledWith(42, expect.objectContaining({ onSuccess: expect.any(Function), onError: expect.any(Function) }));

    const onSuccess = mutate.mock.calls[0][1].onSuccess as (c: Conversation) => void;
    act(() => onSuccess(FAKE_CONVERSATION));

    expect(onCreated).toHaveBeenCalledWith(FAKE_CONVERSATION);
    expect(onOpenChange).toHaveBeenCalledWith(false);
  });
});

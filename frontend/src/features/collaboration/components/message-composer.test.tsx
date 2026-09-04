import '@testing-library/jest-dom/vitest';
import type { ComponentProps } from 'react';
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
    selector({ user: { id: 1, name: 'Current User' } }),
}));

vi.mock('./voice-recorder', () => ({
  VoiceRecorder: ({ onCancel, onSend }: { onCancel: () => void; onSend: (blob: Blob, durationSeconds: number) => void }) => (
    <div>
      <button type="button" onClick={onCancel}>cancel-rec</button>
      <button type="button" onClick={() => onSend(new Blob(['x']), 5)}>send-rec</button>
    </div>
  ),
}));

import { useSendMessage } from '../hooks/use-messages';
vi.mock('../hooks/use-messages', () => ({ useSendMessage: vi.fn() }));

import { MessageComposer } from './message-composer';
import type { ConversationParticipant, Message } from '../types';

const mockUseSendMessage = vi.mocked(useSendMessage);

const PARTICIPANTS: ConversationParticipant[] = [
  { id: 'p1', conversation_id: 'c1', user_id: 1, name: 'Current User', role: 'member', joined_at: '2026-01-01', left_at: null, last_read_at: null, last_read_message_id: null },
  { id: 'p2', conversation_id: 'c1', user_id: 2, name: 'Jane Doe', role: 'member', joined_at: '2026-01-01', left_at: null, last_read_at: null, last_read_message_id: null },
];

const REPLY_TARGET: Message = {
  id: 'm1',
  conversation_id: 'c1',
  sender_user_id: 2,
  sender_name: 'Jane Doe',
  type: 'text',
  body: 'Original message',
  reply_to_message_id: null,
  mentioned_user_ids: [],
  attachment: null,
  created_at: '2026-09-01T10:00:00Z',
};

function setup(props: Partial<ComponentProps<typeof MessageComposer>> = {}) {
  const mutate = vi.fn();
  mockUseSendMessage.mockReturnValue({ mutate, isPending: false } as unknown as ReturnType<typeof useSendMessage>);
  const onCancelReply = vi.fn();
  const utils = render(
    <MessageComposer
      conversationId="c1"
      participants={PARTICIPANTS}
      replyingTo={null}
      onCancelReply={onCancelReply}
      {...props}
    />,
  );
  const textarea = utils.container.querySelector('textarea') as HTMLTextAreaElement;
  return { ...utils, mutate, onCancelReply, textarea };
}

describe('MessageComposer', () => {
  beforeEach(() => {
    mockUseSendMessage.mockReset();
  });

  it('sends a trimmed text message when the Send button is clicked, and onSuccess clears the textarea', () => {
    const { mutate, textarea } = setup();

    fireEvent.change(textarea, { target: { value: '  Hello world  ' } });
    fireEvent.click(screen.getByLabelText('message.send'));

    expect(mutate).toHaveBeenCalledTimes(1);
    expect(mutate).toHaveBeenCalledWith(
      { type: 'text', body: 'Hello world', replyToMessageId: null, mentionedUserIds: [] },
      expect.objectContaining({ onSuccess: expect.any(Function), onError: expect.any(Function) }),
    );

    const onSuccess = mutate.mock.calls[0][1].onSuccess as () => void;
    act(() => onSuccess());
    expect(textarea.value).toBe('');
  });

  it('sends the message when pressing Enter without Shift', () => {
    const { mutate, textarea } = setup();
    fireEvent.change(textarea, { target: { value: 'Quick message' } });
    fireEvent.keyDown(textarea, { key: 'Enter', shiftKey: false });

    expect(mutate).toHaveBeenCalledWith(
      { type: 'text', body: 'Quick message', replyToMessageId: null, mentionedUserIds: [] },
      expect.anything(),
    );
  });

  it('does not send when pressing Shift+Enter', () => {
    const { mutate, textarea } = setup();
    fireEvent.change(textarea, { target: { value: 'Quick message' } });
    fireEvent.keyDown(textarea, { key: 'Enter', shiftKey: true });

    expect(mutate).not.toHaveBeenCalled();
  });

  it('shows a reply preview bar, includes replyToMessageId in the payload, and cancels via the X button', () => {
    const { mutate, textarea, onCancelReply } = setup({ replyingTo: REPLY_TARGET });

    expect(screen.getByText('message.replyingTo:')).toBeInTheDocument();
    expect(screen.getByText('Original message')).toBeInTheDocument();

    fireEvent.change(textarea, { target: { value: 'A reply' } });
    fireEvent.click(screen.getByLabelText('message.send'));

    expect(mutate).toHaveBeenCalledWith(
      { type: 'text', body: 'A reply', replyToMessageId: 'm1', mentionedUserIds: [] },
      expect.anything(),
    );

    fireEvent.click(screen.getByLabelText('message.cancelReply'));
    expect(onCancelReply).toHaveBeenCalledTimes(1);
  });

  it('shows a mention dropdown for a partial @name match, inserts the name, and includes the user id when sent', () => {
    const { mutate, textarea } = setup();

    fireEvent.change(textarea, { target: { value: 'Hello @Ja' } });
    expect(screen.getByText('Jane Doe')).toBeInTheDocument();

    fireEvent.click(screen.getByText('Jane Doe'));
    expect(textarea.value).toBe('Hello @Jane Doe ');

    fireEvent.click(screen.getByLabelText('message.send'));
    expect(mutate).toHaveBeenCalledWith(
      { type: 'text', body: 'Hello @Jane Doe', replyToMessageId: null, mentionedUserIds: [2] },
      expect.anything(),
    );
  });

  it('does not offer the current user as a mention candidate', () => {
    setup();
    const textarea = screen.getByPlaceholderText('message.typePlaceholder');
    fireEvent.change(textarea, { target: { value: '@Current' } });
    expect(screen.queryByText('Current User')).not.toBeInTheDocument();
  });

  it('sends an image attachment when a file is chosen from the hidden image input', () => {
    const { mutate, container } = setup();
    const file = new File(['(binary)'], 'photo.png', { type: 'image/png' });
    const fileInputs = Array.from(container.querySelectorAll('input[type="file"]')) as HTMLInputElement[];
    const imageInput = fileInputs.find((el) => (el.getAttribute('accept') ?? '').includes('image/'));
    expect(imageInput).toBeDefined();

    fireEvent.change(imageInput as HTMLInputElement, { target: { files: [file] } });

    expect(mutate).toHaveBeenCalledWith(
      { type: 'image', file, replyToMessageId: null },
      expect.objectContaining({ onError: expect.any(Function) }),
    );
  });

  it('sends a file attachment when a file is chosen from the hidden file input', () => {
    const { mutate, container } = setup();
    const file = new File(['(binary)'], 'report.pdf', { type: 'application/pdf' });
    const fileInputs = Array.from(container.querySelectorAll('input[type="file"]')) as HTMLInputElement[];
    const genericInput = fileInputs.find((el) => (el.getAttribute('accept') ?? '').includes('.pdf'));
    expect(genericInput).toBeDefined();

    fireEvent.change(genericInput as HTMLInputElement, { target: { files: [file] } });

    expect(mutate).toHaveBeenCalledWith(
      { type: 'file', file, replyToMessageId: null },
      expect.objectContaining({ onError: expect.any(Function) }),
    );
  });

  it('swaps in the voice recorder when the mic button is clicked and sends a voice message from it', () => {
    const { mutate } = setup();

    fireEvent.click(screen.getByLabelText('message.recordVoice'));
    expect(screen.getByText('send-rec')).toBeInTheDocument();
    // The normal composer textarea is no longer present while recording.
    expect(screen.queryByPlaceholderText('message.typePlaceholder')).not.toBeInTheDocument();

    fireEvent.click(screen.getByText('send-rec'));

    expect(mutate).toHaveBeenCalledWith(
      { type: 'voice', file: expect.any(Blob), voiceDurationSeconds: 5, replyToMessageId: null },
      expect.objectContaining({ onSuccess: expect.any(Function), onError: expect.any(Function) }),
    );
  });

  it('returns to the normal composer when the voice recorder is cancelled', () => {
    setup();
    fireEvent.click(screen.getByLabelText('message.recordVoice'));
    fireEvent.click(screen.getByText('cancel-rec'));
    expect(screen.getByPlaceholderText('message.typePlaceholder')).toBeInTheDocument();
  });

  it('disables the Send button when the textarea is empty', () => {
    setup();
    expect(screen.getByLabelText('message.send')).toBeDisabled();
  });
});

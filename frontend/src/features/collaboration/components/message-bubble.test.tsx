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

import { useMessageAttachmentUrl, downloadMessageAttachment } from '../hooks/use-secure-media';
vi.mock('../hooks/use-secure-media', () => ({
  useMessageAttachmentUrl: vi.fn(),
  downloadMessageAttachment: vi.fn(),
}));

import { MessageBubble } from './message-bubble';
import type { Message } from '../types';

const mockUseAttachmentUrl = vi.mocked(useMessageAttachmentUrl);
const mockDownload = vi.mocked(downloadMessageAttachment);

const BASE: Message = {
  id: 'm1',
  conversation_id: 'c1',
  sender_user_id: 2,
  sender_name: 'Jane Doe',
  type: 'text',
  body: 'Hello there',
  reply_to_message_id: null,
  mentioned_user_ids: [],
  attachment: null,
  created_at: '2026-09-01T10:00:00Z',
};

describe('MessageBubble', () => {
  beforeEach(() => {
    mockUseAttachmentUrl.mockReturnValue({ url: null, isLoading: false, isError: false });
    mockDownload.mockReset();
  });

  it('renders the body of a text message', () => {
    render(<MessageBubble message={BASE} isOwn={false} onReply={vi.fn()} onCreateTask={vi.fn()} />);
    expect(screen.getByText('Hello there')).toBeInTheDocument();
  });

  it('renders an image when the attachment URL is loaded', () => {
    mockUseAttachmentUrl.mockReturnValue({ url: 'blob:fake-image', isLoading: false, isError: false });
    const message: Message = { ...BASE, type: 'image', body: null, attachment: { name: 'photo.png', mime_type: 'image/png', file_size: 1024 } };
    render(<MessageBubble message={message} isOwn={false} onReply={vi.fn()} onCreateTask={vi.fn()} />);
    const img = screen.getByRole('img') as HTMLImageElement;
    expect(img).toBeInTheDocument();
    expect(img.src).toContain('blob:fake-image');
  });

  it('shows a loading placeholder (no img) while the image attachment is loading', () => {
    mockUseAttachmentUrl.mockReturnValue({ url: null, isLoading: true, isError: false });
    const message: Message = { ...BASE, type: 'image', body: null, attachment: { name: 'photo.png', mime_type: 'image/png', file_size: 1024 } };
    render(<MessageBubble message={message} isOwn={false} onReply={vi.fn()} onCreateTask={vi.fn()} />);
    expect(screen.queryByRole('img')).not.toBeInTheDocument();
  });

  it('shows an error message instead of the image when it fails to load', () => {
    mockUseAttachmentUrl.mockReturnValue({ url: null, isLoading: false, isError: true });
    const message: Message = { ...BASE, type: 'image', body: null, attachment: { name: 'photo.png', mime_type: 'image/png', file_size: 1024 } };
    render(<MessageBubble message={message} isOwn={false} onReply={vi.fn()} onCreateTask={vi.fn()} />);
    expect(screen.queryByRole('img')).not.toBeInTheDocument();
    expect(screen.getByText('message.uploadFailed')).toBeInTheDocument();
  });

  it('renders an audio element for a voice message when the URL is loaded', () => {
    mockUseAttachmentUrl.mockReturnValue({ url: 'blob:fake-audio', isLoading: false, isError: false });
    const message: Message = { ...BASE, type: 'voice', body: null, attachment: { name: 'voice.webm', mime_type: 'audio/webm', file_size: 2048, duration_seconds: 12 } };
    const { container } = render(<MessageBubble message={message} isOwn={false} onReply={vi.fn()} onCreateTask={vi.fn()} />);
    const audio = container.querySelector('audio');
    expect(audio).not.toBeNull();
    expect(audio).toHaveAttribute('src', 'blob:fake-audio');
  });

  it('shows a voice playback error message when the attachment fails', () => {
    mockUseAttachmentUrl.mockReturnValue({ url: null, isLoading: false, isError: true });
    const message: Message = { ...BASE, type: 'voice', body: null, attachment: { name: 'voice.webm', mime_type: 'audio/webm', file_size: 2048 } };
    const { container } = render(<MessageBubble message={message} isOwn={false} onReply={vi.fn()} onCreateTask={vi.fn()} />);
    expect(container.querySelector('audio')).toBeNull();
    expect(screen.getByText('voice.playbackUnauthorized')).toBeInTheDocument();
  });

  it('renders the filename for a file message and downloads it on click', async () => {
    mockDownload.mockResolvedValue(undefined);
    const message: Message = { ...BASE, type: 'file', body: null, attachment: { name: 'report.pdf', mime_type: 'application/pdf', file_size: 5000 } };
    render(<MessageBubble message={message} isOwn={false} onReply={vi.fn()} onCreateTask={vi.fn()} />);

    expect(screen.getByText('report.pdf')).toBeInTheDocument();
    fireEvent.click(screen.getByText('report.pdf'));

    expect(mockDownload).toHaveBeenCalledWith('m1', 'report.pdf');
  });

  it('renders a system message body', () => {
    const message: Message = { ...BASE, type: 'system', body: 'Jane Doe left the group' };
    render(<MessageBubble message={message} isOwn={false} onReply={vi.fn()} onCreateTask={vi.fn()} />);
    expect(screen.getByText('Jane Doe left the group')).toBeInTheDocument();
  });

  it('renders the reply preview sender label and snippet when provided', () => {
    render(
      <MessageBubble
        message={BASE}
        isOwn={false}
        replyPreview={{ senderLabel: 'Alice', snippet: 'Original snippet text' }}
        onReply={vi.fn()}
        onCreateTask={vi.fn()}
      />,
    );
    expect(screen.getByText('Alice')).toBeInTheDocument();
    expect(screen.getByText('Original snippet text')).toBeInTheDocument();
  });

  it('does not render a reply preview when it is null', () => {
    render(<MessageBubble message={BASE} isOwn={false} replyPreview={null} onReply={vi.fn()} onCreateTask={vi.fn()} />);
    expect(screen.queryByText('Original snippet text')).not.toBeInTheDocument();
  });

  it('calls onReply with the message when the reply control is clicked', () => {
    const onReply = vi.fn();
    render(<MessageBubble message={BASE} isOwn={false} onReply={onReply} onCreateTask={vi.fn()} />);
    fireEvent.click(screen.getByText('message.replyingTo'));
    expect(onReply).toHaveBeenCalledWith(BASE);
  });

  it('calls onCreateTask with the message when the create-task control is clicked', () => {
    const onCreateTask = vi.fn();
    render(<MessageBubble message={BASE} isOwn={false} onReply={vi.fn()} onCreateTask={onCreateTask} />);
    fireEvent.click(screen.getByText('message.createTask'));
    expect(onCreateTask).toHaveBeenCalledWith(BASE);
  });
});

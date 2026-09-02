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

import { VoiceRecorder } from './voice-recorder';

const getUserMedia = vi.fn();

class FakeMediaRecorder {
  static isTypeSupported = () => true;
  state = 'recording';
  ondataavailable: ((e: { data: Blob }) => void) | null = null;
  onstop: (() => void) | null = null;
  onerror: (() => void) | null = null;
  mimeType = 'audio/webm';
  start() {}
  stop() {
    this.state = 'inactive';
    this.ondataavailable?.({ data: new Blob(['x'], { type: 'audio/webm' }) });
    this.onstop?.();
  }
}

// Minimal shape of the globals VoiceRecorder touches — just enough to stub/delete them without `any`.
interface GlobalThisWithMediaRecorder {
  MediaRecorder?: typeof FakeMediaRecorder;
}

interface UrlObjectStatics {
  createObjectURL: (obj: Blob) => string;
  revokeObjectURL: (url: string) => void;
}

function fakeStream() {
  return { getTracks: () => [{ stop: vi.fn() }] } as unknown as MediaStream;
}

beforeEach(() => {
  getUserMedia.mockReset();
  Object.defineProperty(globalThis.navigator, 'mediaDevices', {
    value: { getUserMedia },
    configurable: true,
  });
  (globalThis as unknown as GlobalThisWithMediaRecorder).MediaRecorder = FakeMediaRecorder;
  (globalThis.URL as unknown as UrlObjectStatics).createObjectURL = vi.fn(() => 'blob:mock-url');
  (globalThis.URL as unknown as UrlObjectStatics).revokeObjectURL = vi.fn();
});

describe('VoiceRecorder', () => {
  it('shows the unsupported message and lets the user discard when MediaRecorder does not exist', async () => {
    delete (globalThis as unknown as GlobalThisWithMediaRecorder).MediaRecorder;
    const onCancel = vi.fn();
    render(<VoiceRecorder onCancel={onCancel} onSend={vi.fn()} />);

    expect(await screen.findByText('voice.unsupported')).toBeInTheDocument();
    fireEvent.click(screen.getByText('voice.discard'));
    expect(onCancel).toHaveBeenCalledTimes(1);
  });

  it('shows the mic-denied message when getUserMedia rejects with NotAllowedError', async () => {
    getUserMedia.mockRejectedValue(new DOMException('nope', 'NotAllowedError'));
    render(<VoiceRecorder onCancel={vi.fn()} onSend={vi.fn()} />);

    expect(await screen.findByText('voice.micDenied')).toBeInTheDocument();
  });

  it('shows the mic-unavailable message when getUserMedia rejects with NotFoundError', async () => {
    getUserMedia.mockRejectedValue(new DOMException('nope', 'NotFoundError'));
    render(<VoiceRecorder onCancel={vi.fn()} onSend={vi.fn()} />);

    expect(await screen.findByText('voice.micUnavailable')).toBeInTheDocument();
  });

  it('records, stops, previews, and sends the recorded blob with a duration', async () => {
    getUserMedia.mockResolvedValue(fakeStream());
    const onSend = vi.fn();
    render(<VoiceRecorder onCancel={vi.fn()} onSend={onSend} />);

    expect(await screen.findByText('voice.stop')).toBeInTheDocument();

    fireEvent.click(screen.getByText('voice.stop'));

    const sendButton = await screen.findByText('voice.send');
    expect(document.querySelector('audio')).not.toBeNull();

    fireEvent.click(sendButton);
    expect(onSend).toHaveBeenCalledTimes(1);
    const [blob, duration] = onSend.mock.calls[0];
    expect(blob).toBeInstanceOf(Blob);
    expect(typeof duration).toBe('number');
  });

  it('discards during recording via the trash icon and calls onCancel', async () => {
    getUserMedia.mockResolvedValue(fakeStream());
    const onCancel = vi.fn();
    render(<VoiceRecorder onCancel={onCancel} onSend={vi.fn()} />);

    await screen.findByText('voice.stop');
    fireEvent.click(screen.getByLabelText('voice.discard'));
    expect(onCancel).toHaveBeenCalledTimes(1);
  });

  it('discards during preview via the trash icon and calls onCancel', async () => {
    getUserMedia.mockResolvedValue(fakeStream());
    const onCancel = vi.fn();
    render(<VoiceRecorder onCancel={onCancel} onSend={vi.fn()} />);

    await screen.findByText('voice.stop');
    fireEvent.click(screen.getByText('voice.stop'));
    await screen.findByText('voice.send');

    fireEvent.click(screen.getByLabelText('voice.discard'));
    expect(onCancel).toHaveBeenCalledTimes(1);
  });
});

import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import type { AssistantLanguageKey } from '@/features/ai-assistant/types/assistant';

import { useAssistantVoice } from './use-assistant-voice';

/**
 * TASK-ECOS-V1.1-FINAL-AI-ASSISTANT-PERSONALIZED-COMPANION-046 FINAL CLOSURE
 * §1/§2/§6 — jsdom implements neither SpeechRecognition nor
 * SpeechSynthesisUtterance (see use-assistant-voice.ts's own docblock and
 * test-setup.ts's speechSynthesis stub), so these tests drive a minimal,
 * fully-controllable stand-in for the real browser API by hand instead of
 * depending on an actual microphone.
 */
class MockSpeechRecognition implements SpeechRecognition {
  static instances: MockSpeechRecognition[] = [];

  lang = '';
  continuous = false;
  interimResults = false;
  maxAlternatives = 1;
  onresult: ((this: SpeechRecognition, ev: SpeechRecognitionEvent) => unknown) | null = null;
  onerror: ((this: SpeechRecognition, ev: SpeechRecognitionErrorEvent) => unknown) | null = null;
  onend: ((this: SpeechRecognition, ev: Event) => unknown) | null = null;
  onstart: ((this: SpeechRecognition, ev: Event) => unknown) | null = null;
  stopped = false;
  startCallCount = 0;

  constructor() {
    MockSpeechRecognition.instances.push(this);
  }

  start(): void {
    this.startCallCount += 1;
    this.stopped = false;
  }

  stop(): void {
    this.stopped = true;
  }

  abort(): void {
    this.stopped = true;
  }

  addEventListener(): void {}
  removeEventListener(): void {}
  dispatchEvent(): boolean {
    return true;
  }

  /** Simulates the recognizer producing a final transcript for the current (single) result. */
  emitResult(transcript: string): void {
    const item = { transcript, confidence: 1 };
    const itemList = {
      length: 1,
      item: () => item,
      0: item,
    } as unknown as SpeechRecognitionResultItemList;
    const event = {
      resultIndex: 0,
      results: {
        length: 1,
        item: () => itemList,
        0: itemList,
      } as unknown as SpeechRecognitionResultList,
    } as unknown as SpeechRecognitionEvent;
    this.onresult?.call(this, event);
  }

  emitError(error: string): void {
    this.onerror?.call(this, { error, message: '' } as unknown as SpeechRecognitionErrorEvent);
  }

  emitEnd(): void {
    this.onend?.call(this, {} as unknown as Event);
  }

  static latest(): MockSpeechRecognition {
    const instance = MockSpeechRecognition.instances[MockSpeechRecognition.instances.length - 1];
    if (!instance) throw new Error('No SpeechRecognition instance was constructed');
    return instance;
  }

  static reset(): void {
    MockSpeechRecognition.instances = [];
  }
}

type VoiceArgs = Parameters<typeof useAssistantVoice>[0];

function baseArgs(overrides: Partial<VoiceArgs> = {}): VoiceArgs {
  return {
    voiceInputEnabled: true,
    spokenResponsesEnabled: false,
    wakeByNameEnabled: false,
    assistantName: 'Nour',
    language: 'en' as AssistantLanguageKey,
    voiceChoice: null,
    onTranscript: vi.fn(),
    ...overrides,
  };
}

describe('useAssistantVoice — FINAL CLOSURE §1 (Continuous Voice Conversation) and §2 (voice input/spoken output independence)', () => {
  beforeEach(() => {
    MockSpeechRecognition.reset();
    window.SpeechRecognition = MockSpeechRecognition as unknown as new () => SpeechRecognition;
    delete window.webkitSpeechRecognition;

    class MockSpeechSynthesisUtterance {
      text: string;
      lang = '';
      voice: SpeechSynthesisVoice | null = null;
      onstart: (() => void) | null = null;
      onend: (() => void) | null = null;
      onerror: (() => void) | null = null;
      constructor(text: string) {
        this.text = text;
      }
    }
    vi.stubGlobal('SpeechSynthesisUtterance', MockSpeechSynthesisUtterance);

    window.speechSynthesis.getVoices = () => [];
    // Synchronously "plays" the utterance so conversation-mode tests can prove
    // resumeConversationListening() is only invoked once playback finishes —
    // real playback timing is exercised by the browser, not by this hook.
    window.speechSynthesis.speak = vi.fn((utterance: SpeechSynthesisUtterance) => {
      utterance.onstart?.({} as unknown as SpeechSynthesisEvent);
      utterance.onend?.({} as unknown as SpeechSynthesisEvent);
    });
    window.speechSynthesis.cancel = vi.fn();
  });

  afterEach(() => {
    delete window.SpeechRecognition;
    delete window.webkitSpeechRecognition;
    vi.unstubAllGlobals();
  });

  // 1. Continuous mode can start.
  it('starts Continuous Voice Conversation Mode explicitly, as a single-utterance (non-continuous) recognition session', () => {
    const { result } = renderHook(() => useAssistantVoice(baseArgs()));

    act(() => result.current.startConversation());

    expect(result.current.isConversationActive).toBe(true);
    expect(result.current.micState).toBe('listening');
    expect(MockSpeechRecognition.instances).toHaveLength(1);
    expect(MockSpeechRecognition.latest().continuous).toBe(false);
  });

  // 2. Transcript uses the canonical assistant submit path.
  it('hands a conversation-mode transcript to onTranscript — the exact same path startPushToTalk uses, never a second engine', () => {
    const onTranscript = vi.fn();
    const { result } = renderHook(() => useAssistantVoice(baseArgs({ onTranscript })));

    act(() => result.current.startConversation());
    act(() => MockSpeechRecognition.latest().emitResult('what is my order status'));

    expect(onTranscript).toHaveBeenCalledTimes(1);
    expect(onTranscript).toHaveBeenCalledWith('what is my order status');
    expect(result.current.micState).toBe('processing');
  });

  // 3. A successful response returns continuous mode to listening.
  it('returns to listening once resumeConversationListening() is called after a response arrives', () => {
    const { result } = renderHook(() => useAssistantVoice(baseArgs()));

    act(() => result.current.startConversation());
    act(() => MockSpeechRecognition.latest().emitResult('hello'));
    expect(result.current.micState).toBe('processing');

    act(() => result.current.resumeConversationListening());

    expect(result.current.micState).toBe('listening');
    expect(result.current.isConversationActive).toBe(true);
    expect(MockSpeechRecognition.instances).toHaveLength(2);
  });

  it('when spoken responses are on, resumes listening only after TTS playback finishes — not immediately on submit', () => {
    const { result } = renderHook(() =>
      useAssistantVoice(baseArgs({ spokenResponsesEnabled: true })),
    );

    act(() => result.current.startConversation());
    act(() => MockSpeechRecognition.latest().emitResult('hello'));
    expect(MockSpeechRecognition.instances).toHaveLength(1);

    let resumed = false;
    act(() =>
      result.current.speak('Here is your answer', () => {
        resumed = true;
        result.current.resumeConversationListening();
      }),
    );

    expect(window.speechSynthesis.speak).toHaveBeenCalledTimes(1);
    expect(resumed).toBe(true);
    expect(MockSpeechRecognition.instances).toHaveLength(2);
  });

  // 4. Mute stops active listening without destroying conversation state.
  it('mute stops active listening without ending the conversation', () => {
    const { result } = renderHook(() => useAssistantVoice(baseArgs()));

    act(() => result.current.startConversation());
    const first = MockSpeechRecognition.latest();
    act(() => result.current.muteConversation());

    expect(first.stopped).toBe(true);
    expect(result.current.isConversationActive).toBe(true);
    expect(result.current.isConversationMuted).toBe(true);
    expect(result.current.micState).toBe('off');
  });

  // 5. Unmute can resume.
  it('unmute resumes listening for a still-active conversation', () => {
    const { result } = renderHook(() => useAssistantVoice(baseArgs()));

    act(() => result.current.startConversation());
    act(() => result.current.muteConversation());
    const countBeforeUnmute = MockSpeechRecognition.instances.length;

    act(() => result.current.unmuteConversation());

    expect(result.current.isConversationMuted).toBe(false);
    expect(MockSpeechRecognition.instances.length).toBe(countBeforeUnmute + 1);
    expect(result.current.micState).toBe('listening');
  });

  // 6. End Voice Conversation stops recognition/audio.
  it('End Voice Conversation stops recognition and cancels any pending speech', () => {
    const { result } = renderHook(() =>
      useAssistantVoice(baseArgs({ spokenResponsesEnabled: true })),
    );

    act(() => result.current.startConversation());
    const recognition = MockSpeechRecognition.latest();

    act(() => result.current.endConversation());

    expect(recognition.stopped).toBe(true);
    expect(result.current.isConversationActive).toBe(false);
    expect(result.current.isConversationMuted).toBe(false);
    expect(window.speechSynthesis.cancel).toHaveBeenCalled();
    expect(result.current.micState).toBe('off');
  });

  it('a hard microphone denial ends the conversation instead of spin-looping restarts', () => {
    const { result } = renderHook(() => useAssistantVoice(baseArgs()));

    act(() => result.current.startConversation());
    act(() => MockSpeechRecognition.latest().emitError('not-allowed'));

    expect(result.current.isConversationActive).toBe(false);
    expect(result.current.micState).toBe('off');
  });

  // 7a. Route/context changes must never create duplicate listeners — only one
  // live SpeechRecognition instance exists across push-to-talk, Wake by Name,
  // and conversation mode.
  it('starting a conversation tears down an already-active Wake by Name listener first — never two live sessions at once', () => {
    const { result } = renderHook(() => useAssistantVoice(baseArgs({ wakeByNameEnabled: true })));

    // Wake by Name auto-starts once voice input + the preference are on.
    expect(MockSpeechRecognition.instances).toHaveLength(1);
    const wakeInstance = MockSpeechRecognition.latest();
    expect(wakeInstance.continuous).toBe(true);

    act(() => result.current.startConversation());

    expect(wakeInstance.stopped).toBe(true);
    expect(MockSpeechRecognition.instances).toHaveLength(2);
    expect(MockSpeechRecognition.latest().stopped).toBe(false);
  });

  // 7b. A re-render caused by unrelated prop churn (the kind a route/context
  // change on an AppShell-persistent component would cause) must not spawn a
  // second listener alongside an active conversation.
  it('re-rendering with unrelated prop changes while a conversation is active spawns no second listener', () => {
    const { result, rerender } = renderHook((props: VoiceArgs) => useAssistantVoice(props), {
      initialProps: baseArgs(),
    });

    act(() => result.current.startConversation());
    expect(MockSpeechRecognition.instances).toHaveLength(1);

    rerender(baseArgs({ voiceChoice: 'Google US English' }));

    expect(MockSpeechRecognition.instances).toHaveLength(1);
    expect(MockSpeechRecognition.latest().stopped).toBe(false);
    expect(result.current.isConversationActive).toBe(true);
  });

  // 8. Wake by Name works when spoken TTS responses are OFF.
  it('keeps Wake by Name listening when spoken responses are off — the two preferences are independent', () => {
    const { result } = renderHook(() =>
      useAssistantVoice(baseArgs({ wakeByNameEnabled: true, spokenResponsesEnabled: false })),
    );

    expect(MockSpeechRecognition.instances).toHaveLength(1);
    expect(MockSpeechRecognition.latest().continuous).toBe(true);
    expect(result.current.micState).toBe('wake-listening');
  });

  it('never starts Wake by Name when voice input itself is off, even if the wake preference is on', () => {
    renderHook(() =>
      useAssistantVoice(baseArgs({ wakeByNameEnabled: true, voiceInputEnabled: false })),
    );

    expect(MockSpeechRecognition.instances).toHaveLength(0);
  });

  // 9. Wake phrase alone cannot execute an action.
  it('hearing the assistant name alone only flips to a single push-to-talk listening session — it never itself submits a transcript', () => {
    const onTranscript = vi.fn();
    renderHook(() => useAssistantVoice(baseArgs({ wakeByNameEnabled: true, onTranscript })));

    const wakeInstance = MockSpeechRecognition.latest();
    act(() => wakeInstance.emitResult('Nour, what is my order status'));

    expect(onTranscript).not.toHaveBeenCalled();
    expect(MockSpeechRecognition.instances).toHaveLength(2);
    expect(MockSpeechRecognition.latest().continuous).toBe(false);
  });

  // 10. Logout/session cleanup.
  it('unmounting (logout, session expiry, or AppShell teardown) stops recognition and cancels any pending speech', () => {
    const { result, unmount } = renderHook(() =>
      useAssistantVoice(baseArgs({ spokenResponsesEnabled: true })),
    );

    act(() => result.current.startConversation());
    const recognition = MockSpeechRecognition.latest();

    unmount();

    expect(recognition.stopped).toBe(true);
    expect(window.speechSynthesis.cancel).toHaveBeenCalled();
  });

  // 11. Unsupported browser falls back to text safely.
  it('falls back safely to text-only when the browser has no SpeechRecognition support, never throwing or faking capability', () => {
    delete window.SpeechRecognition;
    delete window.webkitSpeechRecognition;

    const onTranscript = vi.fn();
    const { result } = renderHook(() =>
      useAssistantVoice(baseArgs({ wakeByNameEnabled: true, onTranscript })),
    );

    expect(result.current.isSttSupported).toBe(false);
    expect(() => act(() => result.current.startConversation())).not.toThrow();
    expect(() => act(() => result.current.startPushToTalk())).not.toThrow();

    expect(result.current.isConversationActive).toBe(false);
    expect(MockSpeechRecognition.instances).toHaveLength(0);
    expect(onTranscript).not.toHaveBeenCalled();
  });
});

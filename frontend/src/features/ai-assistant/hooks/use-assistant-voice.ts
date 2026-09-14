import { useCallback, useEffect, useRef, useState } from 'react';

import { matchesWakePhrase } from '@/features/ai-assistant/lib/wake-phrase';
import type { AssistantLanguageKey } from '@/features/ai-assistant/types/assistant';

export type MicState = 'off' | 'wake-listening' | 'listening' | 'processing';

type UseAssistantVoiceArgs = {
  /** The user's own `voice_enabled` preference — gates TTS output only (STT push-to-talk always works when supported; the mic button itself is only rendered when this is on — see AssistantDrawer). */
  voiceEnabled: boolean;
  wakeByNameEnabled: boolean;
  assistantName: string;
  language: AssistantLanguageKey;
  voiceChoice: string | null;
  /** Called with the final transcript of a push-to-talk (or wake-triggered) utterance. The caller sends it through the EXACT SAME sendMessage() path a typed message uses (§8 of the override) — this hook never talks to the assistant API itself. */
  onTranscript: (transcript: string) => void;
};

function getSpeechRecognitionCtor(): (new () => SpeechRecognition) | null {
  if (typeof window === 'undefined') return null;
  return window.SpeechRecognition ?? window.webkitSpeechRecognition ?? null;
}

/** Real feature detection — reused by the settings panel so it never offers a control the browser can't back. */
export function isSpeechRecognitionSupported(): boolean {
  return getSpeechRecognitionCtor() !== null;
}

export function isSpeechSynthesisSupported(): boolean {
  return typeof window !== 'undefined' && 'speechSynthesis' in window;
}

function recognitionLangFor(language: AssistantLanguageKey): string {
  if (language === 'ar') return 'ar-EG';
  if (language === 'en') return 'en-US';
  // Bilingual: lean on the browser's own locale as the best real-world signal
  // available, rather than guessing — never a fabricated "auto-detect" mode
  // (Web Speech has none).
  const browserLocale = typeof navigator !== 'undefined' ? navigator.language : 'en-US';
  return browserLocale.toLowerCase().startsWith('ar') ? 'ar-EG' : 'en-US';
}

/**
 * TASK-ECOS-V1.1-FINAL-AI-ASSISTANT-PERSONALIZED-COMPANION-046 (CTO voice scope
 * override) — push-to-talk STT, spoken TTS responses, and opt-in Wake by Name,
 * all built on the browser's native Web Speech API (SpeechRecognition/
 * speechSynthesis) — no external provider, no credential, no new backend
 * authority. Feature-detected throughout: `isSttSupported`/`isTtsSupported` are
 * real capability checks, never assumed true (§ platform rule: "do not fake
 * capabilities").
 *
 * DISCLOSED LIMITATIONS (real browser/OS behaviour, not a bug in this hook):
 * - SpeechRecognition is Chromium-only in practice (absent in Firefox and most
 *   non-Chromium browsers) and, in Chrome specifically, sends audio to
 *   Google's speech service to produce a transcript — it is browser-native but
 *   not offline/private.
 * - Continuous ("Wake by Name") recognition can be stopped by the browser after
 *   a silence/idle timeout or by OS-level tab suspension (e.g. a backgrounded
 *   mobile browser tab); this hook auto-restarts it when that happens while the
 *   preference is still on, but cannot override an OS suspending the page
 *   entirely.
 * - Available synthesis voices come only from `speechSynthesis.getVoices()` on
 *   the current device — a `voice_choice` saved on one device may not exist on
 *   another, in which case this hook falls back to the browser's own default
 *   voice for the resolved language rather than failing.
 */
export function useAssistantVoice({
  voiceEnabled,
  wakeByNameEnabled,
  assistantName,
  language,
  voiceChoice,
  onTranscript,
}: UseAssistantVoiceArgs) {
  const [micState, setMicState] = useState<MicState>('off');
  const [isSpeaking, setIsSpeaking] = useState(false);

  const activeRecognitionRef = useRef<SpeechRecognition | null>(null);
  const wakeByNameEnabledRef = useRef(wakeByNameEnabled);
  const onTranscriptRef = useRef(onTranscript);
  const assistantNameRef = useRef(assistantName);
  /** Breaks the startPushToTalk <-> startWakeListening circular reference — always holds the latest startWakeListening, updated during render (never read during render itself, only from event handlers/effects below). */
  const startWakeListeningRef = useRef<() => void>(() => {});

  useEffect(() => {
    wakeByNameEnabledRef.current = wakeByNameEnabled;
  }, [wakeByNameEnabled]);
  useEffect(() => {
    onTranscriptRef.current = onTranscript;
  }, [onTranscript]);
  useEffect(() => {
    assistantNameRef.current = assistantName;
  }, [assistantName]);

  const RecognitionCtor = getSpeechRecognitionCtor();
  const isSttSupported = RecognitionCtor !== null;
  const isTtsSupported = typeof window !== 'undefined' && 'speechSynthesis' in window;
  const recognitionLang = recognitionLangFor(language);

  const teardownActiveRecognition = useCallback(() => {
    const current = activeRecognitionRef.current;
    if (current) {
      current.onresult = null;
      current.onerror = null;
      current.onend = null;
      current.stop();
      activeRecognitionRef.current = null;
    }
  }, []);

  /** Starts a single-utterance push-to-talk session (§ "same canonical AI conversation" — the transcript is handed to onTranscript, never processed here). */
  const startPushToTalk = useCallback(() => {
    if (!RecognitionCtor) return;
    teardownActiveRecognition();

    const recognition = new RecognitionCtor();
    recognition.lang = recognitionLang;
    recognition.continuous = false;
    recognition.interimResults = false;
    recognition.maxAlternatives = 1;

    recognition.onresult = (event) => {
      const lastResult = event.results[event.results.length - 1];
      const transcript = lastResult.item(0).transcript.trim();
      setMicState('processing');
      if (transcript !== '') {
        onTranscriptRef.current(transcript);
      }
    };
    recognition.onerror = () => {
      activeRecognitionRef.current = null;
      setMicState(wakeByNameEnabledRef.current ? 'wake-listening' : 'off');
      if (wakeByNameEnabledRef.current) startWakeListeningRef.current();
    };
    recognition.onend = () => {
      activeRecognitionRef.current = null;
      setMicState((current) => (current === 'listening' ? 'off' : current));
      if (wakeByNameEnabledRef.current) startWakeListeningRef.current();
    };

    activeRecognitionRef.current = recognition;
    setMicState('listening');
    recognition.start();
  }, [RecognitionCtor, recognitionLang, teardownActiveRecognition]);

  const stopPushToTalk = useCallback(() => {
    teardownActiveRecognition();
    setMicState(wakeByNameEnabledRef.current ? 'wake-listening' : 'off');
  }, [teardownActiveRecognition]);

  /** Continuous, opt-in listening for the user's own configured assistant name ONLY (never a business phrase) — a match ONLY flips to push-to-talk listening state. */
  const startWakeListening = useCallback(() => {
    if (!RecognitionCtor || !wakeByNameEnabledRef.current || assistantNameRef.current.trim() === '') return;
    teardownActiveRecognition();

    const recognition = new RecognitionCtor();
    recognition.lang = recognitionLang;
    recognition.continuous = true;
    recognition.interimResults = true;

    recognition.onresult = (event) => {
      for (let i = event.resultIndex; i < event.results.length; i++) {
        const transcript = event.results[i].item(0).transcript;
        if (matchesWakePhrase(transcript, assistantNameRef.current)) {
          startPushToTalk();
          return;
        }
      }
    };
    recognition.onend = () => {
      // Real browsers stop continuous recognition after a silence/idle timeout —
      // restart it automatically while the preference is still on (disclosed
      // limitation: OS/tab suspension can still stop this for good). Goes
      // through the ref (not a direct self-reference) for the same reason
      // startPushToTalk does above.
      if (wakeByNameEnabledRef.current && activeRecognitionRef.current === recognition) {
        activeRecognitionRef.current = null;
        startWakeListeningRef.current();
      }
    };
    recognition.onerror = () => {
      // A hard denial (e.g. 'not-allowed') must not spin-loop; leave the mic off.
      if (activeRecognitionRef.current === recognition) {
        activeRecognitionRef.current = null;
        setMicState('off');
      }
    };

    activeRecognitionRef.current = recognition;
    setMicState('wake-listening');
    recognition.start();
  }, [RecognitionCtor, recognitionLang, teardownActiveRecognition, startPushToTalk]);

  // Always keep the ref pointed at the latest startWakeListening (see its
  // declaration comment above) — updated in an effect, never during render,
  // per this codebase's react-hooks/refs rule.
  useEffect(() => {
    startWakeListeningRef.current = startWakeListening;
  }, [startWakeListening]);

  const stopWakeListening = useCallback(() => {
    teardownActiveRecognition();
    setMicState('off');
  }, [teardownActiveRecognition]);

  // Turning "Wake by Name" on/off starts/stops the continuous listener — a real
  // browser-API side effect (starting/stopping a microphone stream), which is
  // exactly what useEffect is for; the resulting setMicState calls reflect that
  // external system's state, not state derivable from props during render.
  useEffect(() => {
    if (wakeByNameEnabled && isSttSupported && assistantName.trim() !== '') {
      startWakeListening();
    } else if (!wakeByNameEnabled) {
      // eslint-disable-next-line react-hooks/set-state-in-effect -- stopping a real SpeechRecognition session and reflecting its resulting mic state; not derivable from props/state during render
      stopWakeListening();
    }
    // Intentionally NOT re-running on every assistantName keystroke while the
    // settings form is open — only on the enabled toggle and support/mount.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [wakeByNameEnabled, isSttSupported]);

  // §10 of the override — logout/session-expiry/unmount stops every listener
  // and releases the microphone/speech-synthesis resources.
  useEffect(() => {
    return () => {
      teardownActiveRecognition();
      if (isTtsSupported) window.speechSynthesis.cancel();
    };
  }, [teardownActiveRecognition, isTtsSupported]);

  const speak = useCallback(
    (text: string) => {
      if (!isTtsSupported || !voiceEnabled || text.trim() === '') return;

      window.speechSynthesis.cancel();
      const utterance = new SpeechSynthesisUtterance(text);
      utterance.lang = recognitionLang;

      if (voiceChoice) {
        const match = window.speechSynthesis.getVoices().find((v) => v.name === voiceChoice);
        if (match) utterance.voice = match;
        // No match on this device: honestly fall back to the browser's own
        // default voice for `utterance.lang` rather than failing silently.
      }

      utterance.onstart = () => setIsSpeaking(true);
      utterance.onend = () => setIsSpeaking(false);
      utterance.onerror = () => setIsSpeaking(false);
      window.speechSynthesis.speak(utterance);
    },
    [isTtsSupported, voiceEnabled, recognitionLang, voiceChoice],
  );

  const stopSpeaking = useCallback(() => {
    if (isTtsSupported) window.speechSynthesis.cancel();
    setIsSpeaking(false);
  }, [isTtsSupported]);

  return {
    micState,
    isSpeaking,
    isSttSupported,
    isTtsSupported,
    startPushToTalk,
    stopPushToTalk,
    speak,
    stopSpeaking,
  };
}

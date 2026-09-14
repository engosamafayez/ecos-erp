import { useCallback, useEffect, useRef, useState } from 'react';

import { matchesWakePhrase } from '@/features/ai-assistant/lib/wake-phrase';
import type { AssistantLanguageKey } from '@/features/ai-assistant/types/assistant';

export type MicState = 'off' | 'wake-listening' | 'listening' | 'processing';

type UseAssistantVoiceArgs = {
  /**
   * FINAL CLOSURE §2 — the user's own `voice_input_enabled` preference: the
   * SOLE prerequisite for the push-to-talk mic button, Wake by Name, and
   * Continuous Voice Conversation. Independent of `spokenResponsesEnabled`.
   */
  voiceInputEnabled: boolean;
  /** Gates TTS output ONLY (renamed from the old combined `voiceEnabled` — see FINAL CLOSURE §2). Never gates voice input in any way. */
  spokenResponsesEnabled: boolean;
  wakeByNameEnabled: boolean;
  assistantName: string;
  language: AssistantLanguageKey;
  voiceChoice: string | null;
  /** Called with the final transcript of a push-to-talk, wake-triggered, or conversation-mode utterance. The caller sends it through the EXACT SAME sendMessage() path a typed message uses (§8 of the override) — this hook never talks to the assistant API itself. */
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
 *
 * FINAL CLOSURE (same task 046) §1/§2 — adds Continuous Voice Conversation
 * Mode (`startConversation`/`muteConversation`/`unmuteConversation`/
 * `endConversation`/`resumeConversationListening`), a real, user-started,
 * looping listen→submit→process→respond→optional-speak→listen-again cycle,
 * DISTINCT from both push-to-talk and Wake by Name — and splits the old single
 * `voiceEnabled` switch into independent `voiceInputEnabled` (mic/STT/Wake by
 * Name/conversation prerequisite) and `spokenResponsesEnabled` (TTS only)
 * preferences, so e.g. voice input + Wake by Name can be on while spoken
 * output stays off. `activeRecognitionRef` remains the ONE shared instance
 * across push-to-talk, Wake by Name, and conversation mode — whichever mode
 * starts next always tears down whatever came before it, so only one
 * SpeechRecognition session (and therefore one live microphone stream) can
 * ever exist at a time.
 */
export function useAssistantVoice({
  voiceInputEnabled,
  spokenResponsesEnabled,
  wakeByNameEnabled,
  assistantName,
  language,
  voiceChoice,
  onTranscript,
}: UseAssistantVoiceArgs) {
  const [micState, setMicState] = useState<MicState>('off');
  const [isSpeaking, setIsSpeaking] = useState(false);
  const [isConversationActive, setIsConversationActive] = useState(false);
  const [isConversationMuted, setIsConversationMuted] = useState(false);

  const activeRecognitionRef = useRef<SpeechRecognition | null>(null);
  const wakeByNameEnabledRef = useRef(wakeByNameEnabled);
  const onTranscriptRef = useRef(onTranscript);
  const assistantNameRef = useRef(assistantName);
  /** Breaks the startPushToTalk <-> startWakeListening circular reference — always holds the latest startWakeListening, updated during render (never read during render itself, only from event handlers/effects below). */
  const startWakeListeningRef = useRef<() => void>(() => {});
  /** Same purpose as startWakeListeningRef, for beginConversationListening's own self-reference from its onend/onerror handlers. */
  const beginConversationListeningRef = useRef<() => void>(() => {});
  /** Same purpose as startWakeListeningRef — lets beginConversationListening's onerror call the not-yet-declared endConversation without a "used before declared" lint violation. */
  const endConversationRef = useRef<() => void>(() => {});
  /** Mirrors isConversationActive/isConversationMuted for synchronous reads inside recognition event handlers and imperative action functions — set directly (not via effect) by the functions that own this state, since those are event-handler-time writes, not render-time ones. */
  const conversationActiveRef = useRef(false);
  const conversationMutedRef = useRef(false);
  /** True from the moment a conversation-mode transcript is submitted until resumeConversationListening() is called — prevents beginConversationListening's own onend from auto-resuming while an assistant reply is still in flight (and possibly about to be spoken, which the mic must not pick back up as feedback). */
  const awaitingConversationResponseRef = useRef(false);

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
    if (!RecognitionCtor || !voiceInputEnabled) return;
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
  }, [RecognitionCtor, voiceInputEnabled, recognitionLang, teardownActiveRecognition]);

  const stopPushToTalk = useCallback(() => {
    teardownActiveRecognition();
    setMicState(wakeByNameEnabledRef.current ? 'wake-listening' : 'off');
  }, [teardownActiveRecognition]);

  /** Continuous, opt-in listening for the user's own configured assistant name ONLY (never a business phrase) — a match ONLY flips to push-to-talk listening state. */
  const startWakeListening = useCallback(() => {
    if (
      !RecognitionCtor ||
      !voiceInputEnabled ||
      !wakeByNameEnabledRef.current ||
      assistantNameRef.current.trim() === ''
    )
      return;
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
  }, [
    RecognitionCtor,
    voiceInputEnabled,
    recognitionLang,
    teardownActiveRecognition,
    startPushToTalk,
  ]);

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

  // Turning "Wake by Name" on/off (or voice input itself off) starts/stops the
  // continuous listener — a real browser-API side effect (starting/stopping a
  // microphone stream), which is exactly what useEffect is for; the resulting
  // setMicState calls reflect that external system's state, not state
  // derivable from props during render.
  useEffect(() => {
    if (wakeByNameEnabled && voiceInputEnabled && isSttSupported && assistantName.trim() !== '') {
      startWakeListening();
    } else if (!wakeByNameEnabled || !voiceInputEnabled) {
      // eslint-disable-next-line react-hooks/set-state-in-effect -- stopping a real SpeechRecognition session and reflecting its resulting mic state; not derivable from props/state during render
      stopWakeListening();
    }
    // Intentionally NOT re-running on every assistantName keystroke while the
    // settings form is open — only on the enabled toggle and support/mount.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [wakeByNameEnabled, voiceInputEnabled, isSttSupported]);

  // §10 of the override — logout/session-expiry/unmount stops every listener
  // and releases the microphone/speech-synthesis resources.
  useEffect(() => {
    return () => {
      teardownActiveRecognition();
      if (isTtsSupported) window.speechSynthesis.cancel();
    };
  }, [teardownActiveRecognition, isTtsSupported]);

  /**
   * `onEnd` fires once playback finishes (or immediately, synchronously via
   * queueMicrotask, when spoken responses are off/unsupported/blank) so a
   * caller sequencing Continuous Voice Conversation can always resume
   * listening exactly once per turn, whether or not anything was actually
   * spoken aloud — resuming the mic only AFTER speech ends (rather than
   * immediately) avoids the mic picking up the assistant's own TTS playback.
   */
  const speak = useCallback(
    (text: string, onEnd?: () => void) => {
      if (!isTtsSupported || !spokenResponsesEnabled || text.trim() === '') {
        if (onEnd) queueMicrotask(onEnd);
        return;
      }

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
      utterance.onend = () => {
        setIsSpeaking(false);
        onEnd?.();
      };
      utterance.onerror = () => {
        setIsSpeaking(false);
        onEnd?.();
      };
      window.speechSynthesis.speak(utterance);
    },
    [isTtsSupported, spokenResponsesEnabled, recognitionLang, voiceChoice],
  );

  const stopSpeaking = useCallback(() => {
    if (isTtsSupported) window.speechSynthesis.cancel();
    setIsSpeaking(false);
  }, [isTtsSupported]);

  // ── FINAL CLOSURE §1 — Continuous Voice Conversation Mode ──────────────────
  // A real, user-started, looping listen→submit→process→respond→optional-
  // speak→listen-again cycle. Distinct from Wake by Name (which only ever
  // triggers a SINGLE push-to-talk exchange before reverting to wake-
  // listening — left completely unmodified above) and from ordinary push-to-
  // talk (single exchange, no loop). Transcripts flow through the exact same
  // onTranscript → sendMessage() → POST /api/ai/assistant/message path as
  // every other voice/typed input — this hook never talks to the assistant
  // API directly.

  /**
   * Starts ONE listening turn of an active conversation. Deliberately does
   * NOT auto-loop on its own `onend` — unlike Wake by Name's continuous
   * recognition, each turn is single-utterance (continuous=false) and the
   * NEXT turn only begins when resumeConversationListening() is explicitly
   * called (by AssistantDrawer, once the assistant's reply — and, if spoken
   * responses are on, its TTS playback — has finished). This is what prevents
   * the microphone from picking up the assistant's own voice as feedback.
   */
  const beginConversationListening = useCallback(() => {
    if (
      !RecognitionCtor ||
      !voiceInputEnabled ||
      !conversationActiveRef.current ||
      conversationMutedRef.current
    )
      return;
    teardownActiveRecognition();

    const recognition = new RecognitionCtor();
    recognition.lang = recognitionLang;
    recognition.continuous = false;
    recognition.interimResults = false;
    recognition.maxAlternatives = 1;

    recognition.onresult = (event) => {
      const lastResult = event.results[event.results.length - 1];
      const transcript = lastResult.item(0).transcript.trim();
      if (transcript !== '') {
        awaitingConversationResponseRef.current = true;
        setMicState('processing');
        onTranscriptRef.current(transcript);
      }
    };
    recognition.onerror = (event) => {
      activeRecognitionRef.current = null;
      if (!conversationActiveRef.current) return;
      // A hard denial (e.g. 'not-allowed'/'service-not-allowed') must not
      // spin-loop against a permanently blocked microphone — end the
      // conversation cleanly, same as Wake by Name's own denial handling.
      if (event.error === 'not-allowed' || event.error === 'service-not-allowed') {
        endConversationRef.current();
        return;
      }
      // A transient error (e.g. no-speech/network/aborted) with nothing
      // submitted: safe to listen again immediately, since there is no
      // assistant reply pending that might be spoken and re-captured by the
      // mic. 'no-speech' in particular fires routinely on ordinary pauses —
      // retrying it is what makes the mode feel "continuous".
      if (!awaitingConversationResponseRef.current && !conversationMutedRef.current) {
        beginConversationListeningRef.current();
      }
    };
    recognition.onend = () => {
      activeRecognitionRef.current = null;
      if (!conversationActiveRef.current) {
        setMicState('off');
        return;
      }
      if (!awaitingConversationResponseRef.current && !conversationMutedRef.current) {
        beginConversationListeningRef.current();
      }
      // else: a reply is in flight (or the user just muted) — stay as-is
      // until resumeConversationListening() (or unmuteConversation()) is
      // called externally.
    };

    activeRecognitionRef.current = recognition;
    setMicState('listening');
    recognition.start();
  }, [RecognitionCtor, voiceInputEnabled, recognitionLang, teardownActiveRecognition]);

  useEffect(() => {
    beginConversationListeningRef.current = beginConversationListening;
  }, [beginConversationListening]);

  /** §1 — the explicit "Start Voice Conversation" control. */
  const startConversation = useCallback(() => {
    if (!RecognitionCtor || !voiceInputEnabled) return;
    conversationActiveRef.current = true;
    conversationMutedRef.current = false;
    awaitingConversationResponseRef.current = false;
    setIsConversationActive(true);
    setIsConversationMuted(false);
    beginConversationListening();
  }, [RecognitionCtor, voiceInputEnabled, beginConversationListening]);

  /** To be called once the assistant's reply (and, if spoken aloud, its TTS playback) has finished — resumes listening for the next turn. A no-op when the conversation isn't active or is muted. */
  const resumeConversationListening = useCallback(() => {
    awaitingConversationResponseRef.current = false;
    if (!conversationActiveRef.current || conversationMutedRef.current) return;
    beginConversationListeningRef.current();
  }, []);

  /** §1 — "Mute": stops active listening WITHOUT ending the conversation (conversation state, e.g. isConversationActive, is preserved). */
  const muteConversation = useCallback(() => {
    if (!conversationActiveRef.current) return;
    conversationMutedRef.current = true;
    setIsConversationMuted(true);
    teardownActiveRecognition();
    setMicState('off');
  }, [teardownActiveRecognition]);

  /** §1 — "Unmute": resumes listening immediately unless a reply is still in flight (in which case resumeConversationListening() will pick it up once that reply lands). */
  const unmuteConversation = useCallback(() => {
    if (!conversationActiveRef.current) return;
    conversationMutedRef.current = false;
    setIsConversationMuted(false);
    if (!awaitingConversationResponseRef.current) {
      beginConversationListening();
    }
  }, [beginConversationListening]);

  /** §1 — "End Voice Conversation": full teardown of recognition AND any pending/active speech, then resumes Wake by Name if that preference is still separately on. */
  const endConversation = useCallback(() => {
    conversationActiveRef.current = false;
    conversationMutedRef.current = false;
    awaitingConversationResponseRef.current = false;
    setIsConversationActive(false);
    setIsConversationMuted(false);
    teardownActiveRecognition();
    if (isTtsSupported) window.speechSynthesis.cancel();
    setIsSpeaking(false);
    if (wakeByNameEnabledRef.current && voiceInputEnabled) {
      startWakeListeningRef.current();
    } else {
      setMicState('off');
    }
  }, [teardownActiveRecognition, isTtsSupported, voiceInputEnabled]);

  useEffect(() => {
    endConversationRef.current = endConversation;
  }, [endConversation]);

  return {
    micState,
    isSpeaking,
    isSttSupported,
    isTtsSupported,
    startPushToTalk,
    stopPushToTalk,
    speak,
    stopSpeaking,
    isConversationActive,
    isConversationMuted,
    startConversation,
    muteConversation,
    unmuteConversation,
    endConversation,
    resumeConversationListening,
  };
}

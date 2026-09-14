import { useCallback, useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Bot, Mic, MicOff, PhoneOff, RotateCcw, Send, Settings, Volume2, VolumeX } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '@/components/ui/sheet';
import { EmptyState } from '@/components/crud';
import { useAssistant } from '@/features/ai-assistant/hooks/use-assistant';
import { useAssistantPreferencesQuery } from '@/features/ai-assistant/hooks/use-assistant-preferences';
import { useAssistantVoice } from '@/features/ai-assistant/hooks/use-assistant-voice';
import { AssistantAvatarIcon } from '@/features/ai-assistant/components/assistant-avatars';
import { AssistantContextChip } from '@/features/ai-assistant/components/assistant-context-chip';
import { AssistantMessage } from '@/features/ai-assistant/components/assistant-message';
import { AssistantSuggestedPrompts } from '@/features/ai-assistant/components/assistant-suggested-prompts';
import {
  isAuthError,
  isRateLimitedError,
  isValidationError,
} from '@/features/ai-assistant/services/assistant-service';

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** TASK-...-046 §8 — the drawer's own "Customize" quick-access entry. Optional so existing/test call sites that don't need it keep compiling unchanged. */
  onCustomize?: () => void;
};

/**
 * §5 — the contextual drawer. Stays anchored to the current ECOS screen (a
 * Sheet, not a full-screen workspace); the user never navigates away just to
 * ask a question.
 *
 * TASK-ECOS-V1.1-FINAL-AI-ASSISTANT-PERSONALIZED-COMPANION-046 §6B — the header
 * now shows the user's own chosen avatar + assistant name (falling back to the
 * generic Bot icon + "ECOS AI" title while preferences are loading), and a
 * settings-gear "Customize" button next to the existing reset control.
 *
 * CTO voice scope override (same task) — a mic button appears in the composer
 * only when the user turned voice input on AND the browser actually supports
 * SpeechRecognition; a spoken response only ever plays the EXACT text already
 * rendered from the canonical AIAssistantResponse (§8 — same authority, same
 * content, just also read aloud).
 *
 * FINAL CLOSURE (same task 046) §1/§2 — voice input and spoken output are
 * independent preferences (a user can talk to ECOS and get text-only replies,
 * or type and still hear replies spoken). Adds Continuous Voice Conversation
 * Mode controls (Start/Mute/Unmute/End) — a real looping voice exchange,
 * distinct from the single-shot push-to-talk button above and from Wake by
 * Name. Every transcript, spoken or typed, still flows through the one
 * `submit()` → `sendMessage()` → canonical assistant path below.
 */
export function AssistantDrawer({ open, onOpenChange, onCustomize }: Props) {
  const { t } = useTranslation('ai-assistant');
  const { context, conversation, sendMessage, reset, isPending, error } = useAssistant();
  const preferencesQuery = useAssistantPreferencesQuery();
  const [draft, setDraft] = useState('');
  const scrollEndRef = useRef<HTMLDivElement>(null);
  const lastProcessedTurnId = useRef<string | null>(null);
  const lastHandledErrorRef = useRef<unknown>(null);
  const assistantName = preferencesQuery.data?.name;

  const submit = useCallback(
    (message: string) => {
      setDraft('');
      sendMessage(message);
    },
    [sendMessage],
  );

  const voice = useAssistantVoice({
    voiceInputEnabled: preferencesQuery.data?.voice_input_enabled ?? false,
    spokenResponsesEnabled: preferencesQuery.data?.spoken_responses_enabled ?? false,
    wakeByNameEnabled: preferencesQuery.data?.wake_by_name_enabled ?? false,
    assistantName: assistantName ?? '',
    language: preferencesQuery.data?.language ?? 'bilingual',
    voiceChoice: preferencesQuery.data?.voice_choice ?? null,
    onTranscript: submit,
  });

  useEffect(() => {
    scrollEndRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [conversation.length, isPending]);

  // FINAL CLOSURE §1/§2 — every new assistant turn (whatever its status) both
  // (a) speaks it aloud, but ONLY when spoken responses are on — entirely
  // independent of whether the turn arrived via voice or typing — and (b)
  // resumes Continuous Voice Conversation listening for the next turn, either
  // immediately (spoken responses off) or once playback finishes (on), so the
  // mic never re-engages while the assistant's own voice is still playing.
  useEffect(() => {
    const lastTurn = conversation[conversation.length - 1];
    if (!lastTurn || lastTurn.role !== 'assistant' || lastTurn.id === lastProcessedTurnId.current) return;

    lastProcessedTurnId.current = lastTurn.id;
    const resume = () => {
      if (voice.isConversationActive) voice.resumeConversationListening();
    };

    const shouldSpeak =
      preferencesQuery.data?.spoken_responses_enabled === true &&
      (lastTurn.status === 'ok' || lastTurn.status === 'tool_limit_reached') &&
      lastTurn.content.trim() !== '';

    if (shouldSpeak) {
      voice.speak(lastTurn.content, resume);
    } else {
      resume();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps -- voice.speak/voice.isConversationActive/voice.resumeConversationListening intentionally excluded: they're stable per their own useCallback deps (or, for isConversationActive, read fresh at call time) and including the whole `voice` object would re-run this on every micState change.
  }, [conversation, preferencesQuery.data?.spoken_responses_enabled]);

  // A transport error (network/rate-limit/etc.) never appends an assistant
  // turn to `conversation` (see useAssistant()), so the effect above alone
  // would leave an active voice conversation stuck in "processing" forever —
  // resume listening here too once the failure is visible to the user.
  useEffect(() => {
    if (!error || error === lastHandledErrorRef.current) return;
    lastHandledErrorRef.current = error;
    if (voice.isConversationActive) voice.resumeConversationListening();
    // eslint-disable-next-line react-hooks/exhaustive-deps -- same rationale as above.
  }, [error]);

  const transportErrorText = (() => {
    if (!error) return null;
    if (isValidationError(error)) return t($ => $.status.validationError);
    if (isRateLimitedError(error)) return t($ => $.status.rateLimited);
    if (isAuthError(error)) return t($ => $.status.authError);

    return t($ => $.status.genericError);
  })();

  const voiceStatusText = (() => {
    if (voice.isSpeaking) return t($ => $.voice.status.speaking);
    if (voice.isConversationActive && voice.isConversationMuted) return t($ => $.voice.status.conversationMuted);
    if (voice.isConversationActive && voice.micState === 'listening') return t($ => $.voice.status.conversationListening);
    if (voice.micState === 'listening') return t($ => $.voice.status.listening);
    if (voice.micState === 'processing') return t($ => $.voice.status.processing);
    if (voice.micState === 'wake-listening') return t($ => $.voice.status.wakeListening);
    if (voice.isConversationActive) return t($ => $.voice.status.conversationActive);
    return null;
  })();

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent side="right" className="flex w-full flex-col gap-0 p-0 sm:max-w-sm">
        <SheetHeader className="flex-row items-center justify-between gap-2 space-y-0 border-b px-3 py-2.5">
          <div className="flex min-w-0 items-center gap-2">
            {preferencesQuery.data ? (
              <AssistantAvatarIcon avatarKey={preferencesQuery.data.avatar_key} className="size-7 shrink-0" />
            ) : null}
            <div className="min-w-0">
              <SheetTitle className="truncate text-sm">{assistantName ?? t($ => $.drawer.title)}</SheetTitle>
              <SheetDescription className="sr-only">{t($ => $.drawer.subtitle)}</SheetDescription>
              <AssistantContextChip context={context} />
            </div>
          </div>
          <div className="flex shrink-0 items-center gap-1">
            {onCustomize ? (
              <Button
                variant="ghost"
                size="icon"
                className="size-8"
                onClick={onCustomize}
                aria-label={t($ => $.customize.ariaLabel)}
              >
                <Settings className="size-4" />
              </Button>
            ) : null}
            <Button
              variant="ghost"
              size="icon"
              className="size-8"
              onClick={reset}
              aria-label={t($ => $.drawer.newConversation)}
              disabled={conversation.length === 0}
            >
              <RotateCcw className="size-4" />
            </Button>
          </div>
        </SheetHeader>

        {voiceStatusText ? (
          <div
            role="status"
            className="flex items-center gap-2 border-b bg-muted/40 px-3 py-1.5 text-xs text-muted-foreground"
          >
            <span
              className={`size-1.5 shrink-0 rounded-full ${voice.isSpeaking || voice.micState === 'listening' ? 'animate-pulse bg-primary' : 'bg-muted-foreground'}`}
            />
            <span className="truncate">{voiceStatusText}</span>
            <div className="ms-auto flex shrink-0 items-center gap-1">
              {voice.isSpeaking ? (
                <Button variant="ghost" size="sm" className="h-6 px-2 text-xs" onClick={voice.stopSpeaking}>
                  {t($ => $.voice.stopSpeaking)}
                </Button>
              ) : null}
              {voice.isConversationActive ? (
                <>
                  <Button
                    variant="ghost"
                    size="icon"
                    className="size-6"
                    onClick={() => (voice.isConversationMuted ? voice.unmuteConversation() : voice.muteConversation())}
                    aria-label={voice.isConversationMuted ? t($ => $.voice.conversation.unmute) : t($ => $.voice.conversation.mute)}
                    aria-pressed={voice.isConversationMuted}
                  >
                    {voice.isConversationMuted ? <VolumeX className="size-3.5" /> : <Volume2 className="size-3.5" />}
                  </Button>
                  <Button
                    variant="ghost"
                    size="icon"
                    className="size-6 text-destructive hover:text-destructive"
                    onClick={voice.endConversation}
                    aria-label={t($ => $.voice.conversation.end)}
                  >
                    <PhoneOff className="size-3.5" />
                  </Button>
                </>
              ) : null}
            </div>
          </div>
        ) : null}

        <ScrollArea className="min-h-0 flex-1">
          <div className="flex flex-col gap-3 p-3">
            {conversation.length === 0 ? (
              <EmptyState icon={Bot} title={t($ => $.empty.title)} description={t($ => $.empty.description)} />
            ) : (
              conversation.map((turn) => <AssistantMessage key={turn.id} turn={turn} />)
            )}

            {isPending ? (
              <div className="flex items-center gap-2 text-xs text-muted-foreground" role="status">
                <span className="size-1.5 animate-pulse rounded-full bg-muted-foreground" />
                {t($ => $.status.working)}
              </div>
            ) : null}

            {transportErrorText ? (
              <div className="rounded-md border border-destructive/30 bg-destructive/10 px-3 py-2 text-xs text-destructive">
                {transportErrorText}
              </div>
            ) : null}

            <div ref={scrollEndRef} />
          </div>
        </ScrollArea>

        {conversation.length === 0 ? <AssistantSuggestedPrompts context={context} onSelect={submit} /> : null}

        <div className="flex items-end gap-2 border-t p-2.5">
          <label className="sr-only" htmlFor="assistant-composer">{t($ => $.drawer.composerLabel)}</label>
          <textarea
            id="assistant-composer"
            value={draft}
            onChange={(e) => setDraft(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                if (draft.trim() !== '') submit(draft);
              }
            }}
            placeholder={t($ => $.drawer.composerPlaceholder)}
            rows={1}
            className="max-h-24 min-h-9 flex-1 resize-none rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs"
          />
          {preferencesQuery.data?.voice_input_enabled && voice.isSttSupported && !voice.isConversationActive ? (
            <Button
              type="button"
              variant={voice.micState === 'listening' ? 'default' : 'outline'}
              size="icon"
              className="size-9 shrink-0"
              onClick={() => (voice.micState === 'listening' ? voice.stopPushToTalk() : voice.startPushToTalk())}
              aria-label={voice.micState === 'listening' ? t($ => $.voice.stopListening) : t($ => $.voice.startListening)}
              aria-pressed={voice.micState === 'listening'}
            >
              {voice.micState === 'listening' ? <Mic className="size-4" /> : <MicOff className="size-4" />}
            </Button>
          ) : null}
          {/* FINAL CLOSURE §1 — Continuous Voice Conversation Mode's own explicit
              Start control; text input/send above remain available regardless
              (the required always-on fallback). Ends via the status row's End
              control once active, never this same button (avoids an accidental
              double-tap immediately re-starting a just-ended conversation). */}
          {preferencesQuery.data?.voice_input_enabled && voice.isSttSupported && !voice.isConversationActive ? (
            <Button
              type="button"
              variant="outline"
              size="icon"
              className="size-9 shrink-0"
              onClick={voice.startConversation}
              aria-label={t($ => $.voice.conversation.start)}
            >
              <Volume2 className="size-4" />
            </Button>
          ) : null}
          <Button
            size="icon"
            className="size-9 shrink-0"
            onClick={() => draft.trim() !== '' && submit(draft)}
            disabled={isPending || draft.trim() === ''}
            aria-label={t($ => $.drawer.send)}
          >
            <Send className="size-4" />
          </Button>
        </div>
      </SheetContent>
    </Sheet>
  );
}

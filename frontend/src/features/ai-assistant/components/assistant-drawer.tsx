import { useCallback, useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Bot, Mic, MicOff, RotateCcw, Send, Settings } from 'lucide-react';

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
 * only when the user turned voice on AND the browser actually supports
 * SpeechRecognition; a spoken response only ever plays the EXACT text already
 * rendered from the canonical AIAssistantResponse (§8 — same authority, same
 * content, just also read aloud).
 */
export function AssistantDrawer({ open, onOpenChange, onCustomize }: Props) {
  const { t } = useTranslation('ai-assistant');
  const { context, conversation, sendMessage, reset, isPending, error } = useAssistant();
  const preferencesQuery = useAssistantPreferencesQuery();
  const [draft, setDraft] = useState('');
  const scrollEndRef = useRef<HTMLDivElement>(null);
  const lastSpokenTurnId = useRef<string | null>(null);
  const assistantName = preferencesQuery.data?.name;

  const submit = useCallback(
    (message: string) => {
      setDraft('');
      sendMessage(message);
    },
    [sendMessage],
  );

  const voice = useAssistantVoice({
    voiceEnabled: preferencesQuery.data?.voice_enabled ?? false,
    wakeByNameEnabled: preferencesQuery.data?.wake_by_name_enabled ?? false,
    assistantName: assistantName ?? '',
    language: preferencesQuery.data?.language ?? 'bilingual',
    voiceChoice: preferencesQuery.data?.voice_choice ?? null,
    onTranscript: submit,
  });

  useEffect(() => {
    scrollEndRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [conversation.length, isPending]);

  // Speak the newest assistant turn exactly once, only when voice is on.
  useEffect(() => {
    if (!preferencesQuery.data?.voice_enabled) return;
    const lastTurn = conversation[conversation.length - 1];
    if (!lastTurn || lastTurn.role !== 'assistant' || lastTurn.id === lastSpokenTurnId.current) return;
    if (lastTurn.status !== 'ok' && lastTurn.status !== 'tool_limit_reached') return;
    if (lastTurn.content.trim() === '') return;

    lastSpokenTurnId.current = lastTurn.id;
    voice.speak(lastTurn.content);
    // eslint-disable-next-line react-hooks/exhaustive-deps -- voice.speak is intentionally excluded: it's stable per its own useCallback deps and including the whole `voice` object would re-run this on every micState change.
  }, [conversation, preferencesQuery.data?.voice_enabled]);

  const transportErrorText = (() => {
    if (!error) return null;
    if (isValidationError(error)) return t($ => $.status.validationError);
    if (isRateLimitedError(error)) return t($ => $.status.rateLimited);
    if (isAuthError(error)) return t($ => $.status.authError);

    return t($ => $.status.genericError);
  })();

  const voiceStatusText = (() => {
    if (voice.isSpeaking) return t($ => $.voice.status.speaking);
    if (voice.micState === 'listening') return t($ => $.voice.status.listening);
    if (voice.micState === 'processing') return t($ => $.voice.status.processing);
    if (voice.micState === 'wake-listening') return t($ => $.voice.status.wakeListening);
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
              className={`size-1.5 rounded-full ${voice.isSpeaking || voice.micState === 'listening' ? 'animate-pulse bg-primary' : 'bg-muted-foreground'}`}
            />
            {voiceStatusText}
            {voice.isSpeaking ? (
              <Button variant="ghost" size="sm" className="h-6 ms-auto px-2 text-xs" onClick={voice.stopSpeaking}>
                {t($ => $.voice.stopSpeaking)}
              </Button>
            ) : null}
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
          {preferencesQuery.data?.voice_enabled && voice.isSttSupported ? (
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

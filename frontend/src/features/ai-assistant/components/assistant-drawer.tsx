import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Bot, RotateCcw, Send } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '@/components/ui/sheet';
import { EmptyState } from '@/components/crud';
import { useAssistant } from '@/features/ai-assistant/hooks/use-assistant';
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
};

/**
 * §5 — the contextual drawer. Stays anchored to the current ECOS screen (a
 * Sheet, not a full-screen workspace); the user never navigates away just to
 * ask a question.
 */
export function AssistantDrawer({ open, onOpenChange }: Props) {
  const { t } = useTranslation('ai-assistant');
  const { context, conversation, sendMessage, reset, isPending, error } = useAssistant();
  const [draft, setDraft] = useState('');
  const scrollEndRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    scrollEndRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [conversation.length, isPending]);

  const transportErrorText = (() => {
    if (!error) return null;
    if (isValidationError(error)) return t($ => $.status.validationError);
    if (isRateLimitedError(error)) return t($ => $.status.rateLimited);
    if (isAuthError(error)) return t($ => $.status.authError);

    return t($ => $.status.genericError);
  })();

  function submit(message: string) {
    setDraft('');
    sendMessage(message);
  }

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent side="right" className="flex w-full flex-col gap-0 p-0 sm:max-w-sm">
        <SheetHeader className="flex-row items-center justify-between gap-2 space-y-0 border-b px-3 py-2.5">
          <div className="min-w-0">
            <SheetTitle className="truncate text-sm">{t($ => $.drawer.title)}</SheetTitle>
            <SheetDescription className="sr-only">{t($ => $.drawer.subtitle)}</SheetDescription>
            <AssistantContextChip context={context} />
          </div>
          <Button
            variant="ghost"
            size="icon"
            className="size-8 shrink-0"
            onClick={reset}
            aria-label={t($ => $.drawer.newConversation)}
            disabled={conversation.length === 0}
          >
            <RotateCcw className="size-4" />
          </Button>
        </SheetHeader>

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

import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import { ExternalLink } from 'lucide-react';

import { cn } from '@/lib/utils';
import type { AssistantConversationTurn } from '@/features/ai-assistant/types/assistant';

/**
 * §14/§15/§16 — renders exactly what the backend reported, never upgrading a
 * non-success status into a generic answer. Entity links are built ONLY from
 * server-generated {@see AssistantEntityReference}.route values (§14/§24) —
 * never from parsing the assistant's own free-text message.
 */
export function AssistantMessage({ turn }: { turn: AssistantConversationTurn }) {
  const { t } = useTranslation('ai-assistant');
  const isUser = turn.role === 'user';

  // denied/unavailable never carry real model content from the backend
  // (§16) — replace the bubble outright with an honest, localized status
  // line rather than showing nothing or a raw backend string. tool_limit_reached
  // is different: the backend still hands back whatever real (possibly partial)
  // answer the model produced before the cap (see AIAssistantService::handle()),
  // so that content is worth keeping — it just gets an honest "partial" note
  // alongside it instead of being discarded (§27: "the user still gets whatever
  // ... earlier rounds already collected").
  const statusText = (() => {
    switch (turn.status) {
      case 'denied':
        return t($ => $.status.denied);
      case 'unavailable':
        return t($ => $.status.unavailable);
      default:
        return null;
    }
  })();

  return (
    <div className={cn('flex flex-col gap-1', isUser ? 'items-end' : 'items-start')}>
      <div
        className={cn(
          'max-w-[85%] rounded-lg px-3 py-2 text-sm whitespace-pre-wrap',
          isUser ? 'bg-primary text-primary-foreground' : 'bg-muted',
        )}
      >
        {isUser ? turn.content : (statusText ?? turn.content)}
      </div>

      {!isUser && turn.status === 'tool_limit_reached' ? (
        <span className="text-[10px] text-muted-foreground">{t($ => $.status.toolLimitReached)}</span>
      ) : null}

      {!isUser && turn.references && turn.references.length > 0 ? (
        <div className="flex max-w-[85%] flex-col gap-1 rounded-md border bg-muted/30 p-2">
          <span className="text-[10px] font-medium text-muted-foreground">{t($ => $.references.title)}</span>
          <div className="flex flex-wrap gap-1.5">
            {turn.references.map((ref) => (
              <Link
                key={`${ref.type}-${ref.id}`}
                to={ref.route}
                className="inline-flex items-center gap-1 rounded-md border bg-background px-2 py-1 text-xs hover:bg-accent"
              >
                {ref.label}
                <ExternalLink className="size-3" />
              </Link>
            ))}
          </div>
        </div>
      ) : null}
    </div>
  );
}

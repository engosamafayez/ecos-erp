import { useEffect, useRef, useState } from 'react';
import { useMutation } from '@tanstack/react-query';

import { useOrganizationContext } from '@/features/organization/context/organization-context';
import { assistantService } from '@/features/ai-assistant/services/assistant-service';
import { useAssistantContext } from '@/features/ai-assistant/hooks/use-assistant-context';
import type { AssistantConversationTurn, AssistantHistoryTurn } from '@/features/ai-assistant/types/assistant';

/**
 * Kept comfortably under the backend's own default (config('ai.max_recent_messages'),
 * 12) so a normal session never hits the 422 bound in practice — the backend
 * remains the real, authoritative limit (§9/§26).
 */
const MAX_CLIENT_HISTORY = 10;

let turnId = 0;
function nextId(): string {
  turnId += 1;
  return `turn-${turnId}`;
}

/**
 * §9 — bounded, client/session-only conversation state. Nothing here is
 * persisted (no localStorage, no server thread) — a page refresh or a company/
 * Brand switch legitimately starts fresh (§8).
 */
export function useAssistant() {
  const context = useAssistantContext();
  const { activeCompanyId } = useOrganizationContext();
  const [conversation, setConversation] = useState<AssistantConversationTurn[]>([]);
  const lastCompanyId = useRef(activeCompanyId);

  // §8 — a company switch while the drawer is open must never let the next
  // message ride on a stale company's conversation.
  useEffect(() => {
    if (lastCompanyId.current !== activeCompanyId) {
      lastCompanyId.current = activeCompanyId;
      setConversation([]);
    }
  }, [activeCompanyId]);

  const mutation = useMutation({
    mutationFn: (message: string) => {
      const history: AssistantHistoryTurn[] = conversation
        .slice(-MAX_CLIENT_HISTORY)
        .map((turn) => ({ role: turn.role, content: turn.content }));

      return assistantService.sendMessage({ message, ...context, history });
    },
  });

  function sendMessage(message: string) {
    const trimmed = message.trim();
    if (trimmed === '') return;

    setConversation((prev) => [...prev, { id: nextId(), role: 'user', content: trimmed }]);

    mutation.mutate(trimmed, {
      onSuccess: (response) => {
        setConversation((prev) => [
          ...prev,
          {
            id: nextId(),
            role: 'assistant',
            content: response.message ?? '',
            status: response.status,
            references: response.references,
          },
        ]);
      },
    });
  }

  function reset() {
    setConversation([]);
    mutation.reset();
  }

  return {
    context,
    conversation,
    sendMessage,
    reset,
    isPending: mutation.isPending,
    error: mutation.error,
  };
}

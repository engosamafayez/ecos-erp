import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import {
  listMessages,
  removeMessageReaction,
  searchMessages,
  sendMessage,
  setMessageReaction,
  type SendMessagePayload,
} from '../services/collaboration-service';
import { useRealtimeStatus } from './use-realtime-status';

const messagesKey = (conversationId: string) => ['collaboration', 'conversations', conversationId, 'messages'] as const;

/** Polls every 5s unless a real realtime connection is active (brief §16/§17). */
export function useMessages(conversationId: string | null) {
  const realtime = useRealtimeStatus();

  return useQuery({
    queryKey: messagesKey(conversationId ?? ''),
    queryFn: () => listMessages(conversationId as string),
    enabled: !!conversationId,
    refetchInterval: realtime === 'connected' ? false : 5_000,
  });
}

export function useSendMessage(conversationId: string) {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (payload: SendMessagePayload) => sendMessage(conversationId, payload),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: messagesKey(conversationId) });
      qc.invalidateQueries({ queryKey: ['collaboration', 'conversations'] });
    },
  });
}

/** Reacting again with a different emoji replaces the previous one (server-side upsert,
 *  one reaction per user per message) — both hooks take the owning conversationId purely
 *  to invalidate that conversation's message list, mirroring useSendMessage. */
export function useSetMessageReaction(conversationId: string) {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: ({ messageId, emoji }: { messageId: string; emoji: string }) => setMessageReaction(messageId, emoji),
    onSuccess: () => qc.invalidateQueries({ queryKey: messagesKey(conversationId) }),
  });
}

export function useRemoveMessageReaction(conversationId: string) {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (messageId: string) => removeMessageReaction(messageId),
    onSuccess: () => qc.invalidateQueries({ queryKey: messagesKey(conversationId) }),
  });
}

/** `conversationId` omitted searches globally (unchanged, existing callers);
 *  given, scopes the search to just that one conversation (architecture
 *  report §19 in-conversation search). */
export function useSearchMessages(query: string, conversationId?: string) {
  return useQuery({
    queryKey: ['collaboration', 'search', 'messages', query, conversationId ?? null],
    queryFn: () => searchMessages(query, 20, conversationId),
    enabled: query.trim().length > 0,
  });
}

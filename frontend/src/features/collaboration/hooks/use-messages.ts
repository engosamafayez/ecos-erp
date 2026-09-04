import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { listMessages, searchMessages, sendMessage, type SendMessagePayload } from '../services/collaboration-service';
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

export function useSearchMessages(query: string) {
  return useQuery({
    queryKey: ['collaboration', 'search', 'messages', query],
    queryFn: () => searchMessages(query),
    enabled: query.trim().length > 0,
  });
}

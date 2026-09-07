import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import {
  addParticipant,
  createGroupConversation,
  getConversation,
  listConversations,
  markConversationRead,
  muteConversation,
  removeParticipant,
  startDirectConversation,
} from '../services/collaboration-service';
import { useRealtimeStatus } from './use-realtime-status';

const conversationsKey = ['collaboration', 'conversations'] as const;
const conversationKey = (id: string) => ['collaboration', 'conversations', id] as const;

/** Polls every 15s unless a real realtime connection is active (brief §16/§17). */
export function useConversations() {
  const realtime = useRealtimeStatus();

  return useQuery({
    queryKey: conversationsKey,
    queryFn: listConversations,
    refetchInterval: realtime === 'connected' ? false : 15_000,
  });
}

export function useConversation(id: string | null) {
  return useQuery({
    queryKey: conversationKey(id ?? ''),
    queryFn: () => getConversation(id as string),
    enabled: !!id,
  });
}

export function useStartDirectConversation() {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: startDirectConversation,
    onSuccess: () => qc.invalidateQueries({ queryKey: conversationsKey }),
  });
}

export function useCreateGroupConversation() {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: createGroupConversation,
    onSuccess: () => qc.invalidateQueries({ queryKey: conversationsKey }),
  });
}

export function useAddParticipant(conversationId: string) {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (userId: number) => addParticipant(conversationId, userId),
    onSuccess: () => qc.invalidateQueries({ queryKey: conversationKey(conversationId) }),
  });
}

export function useRemoveParticipant(conversationId: string) {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (userId: number) => removeParticipant(conversationId, userId),
    onSuccess: () => qc.invalidateQueries({ queryKey: conversationKey(conversationId) }),
  });
}

/** Read state is server-confirmed only — no client-only unread authority (brief §17). */
export function useMarkConversationRead(conversationId: string) {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (lastReadMessageId?: string) => markConversationRead(conversationId, lastReadMessageId),
    onSuccess: () => qc.invalidateQueries({ queryKey: conversationsKey }),
  });
}

/** Notification preference only (architecture report §21) — never a second
 *  read/unread authority; invalidates both the single conversation and the
 *  list so the muted indicator updates everywhere it's shown. */
export function useMuteConversation(conversationId: string) {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (muted: boolean) => muteConversation(conversationId, muted),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: conversationKey(conversationId) });
      qc.invalidateQueries({ queryKey: conversationsKey });
    },
  });
}

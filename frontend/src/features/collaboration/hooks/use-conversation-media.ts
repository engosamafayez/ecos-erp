import { useQuery } from '@tanstack/react-query';

import { getConversationMedia } from '../services/collaboration-service';
import type { ConversationMediaType } from '../types';

/** Backs the conversation-info Media/Links/Documents tabs (architecture report
 *  §20) — `enabled` so a tab only fetches once it's actually opened. */
export function useConversationMedia(conversationId: string, type: ConversationMediaType, enabled: boolean) {
  return useQuery({
    queryKey: ['collaboration', 'conversations', conversationId, 'media', type],
    queryFn: () => getConversationMedia(conversationId, type),
    enabled: enabled && !!conversationId,
  });
}

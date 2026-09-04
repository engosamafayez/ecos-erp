import type { Conversation } from '../types';

/** The conversation's display title — a group's own title, or the other active
 *  participant's name for a direct conversation. Never fabricates a title: falls
 *  back to null when participants weren't loaded, so callers can render their own
 *  placeholder instead of a misleading label. */
export function conversationDisplayTitle(conversation: Conversation, currentUserId: number | undefined): string | null {
  if (conversation.type === 'group') {
    return conversation.title;
  }

  const other = conversation.participants?.find((p) => p.user_id !== currentUserId);
  return other?.name ?? null;
}

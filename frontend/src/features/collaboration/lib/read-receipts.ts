import type { Conversation, Message } from '../types';

export type SeenState = 'sent' | 'seen';

/**
 * Derives a WhatsApp-equivalent sent/seen tick entirely from data the
 * existing conversation endpoints already return — each participant's own
 * read cursor (`last_read_at`) — with no new backend model (architecture
 * report §22: "cheaper than it looks"). "Seen" means every OTHER active
 * participant has read up to at least this message's timestamp; for a
 * direct conversation that other participant is the only one, giving
 * ordinary single/double-tick semantics for free.
 */
export function computeSeenState(message: Message, conversation: Conversation, currentUserId: number | undefined): SeenState {
  const others = (conversation.participants ?? []).filter((p) => p.user_id !== currentUserId);
  if (others.length === 0) return 'sent';

  const messageTime = new Date(message.created_at).getTime();
  const seenByAll = others.every((p) => p.last_read_at !== null && new Date(p.last_read_at).getTime() >= messageTime);

  return seenByAll ? 'seen' : 'sent';
}

/** Group-only: names of fellow participants who have read up to this message
 *  — rendered as a "Seen by …" line under the latest own message only, to
 *  avoid cluttering every bubble (mirrors how most chat apps surface group
 *  read state). */
export function seenByNames(message: Message, conversation: Conversation, currentUserId: number | undefined): string[] {
  const others = (conversation.participants ?? []).filter((p) => p.user_id !== currentUserId);
  const messageTime = new Date(message.created_at).getTime();

  return others
    .filter((p) => p.last_read_at !== null && new Date(p.last_read_at).getTime() >= messageTime)
    .map((p) => p.name)
    .filter((name): name is string => !!name);
}

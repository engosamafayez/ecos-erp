import type { Message } from '../types';

export type DisplayItem =
  | { type: 'separator'; id: string; dateKey: string }
  | { type: 'message'; message: Message; isGrouped: boolean };

const GROUP_WINDOW_MS = 5 * 60 * 1000;

function dayKey(iso: string): string {
  const d = new Date(iso);
  return `${d.getFullYear()}-${d.getMonth()}-${d.getDate()}`;
}

/**
 * Buckets already-loaded messages into day-separated, sender-grouped display
 * items — a pure function, no new data fetched (architecture report §18/§31).
 * A message is "grouped" with the one above it when it shares the same
 * sender and lands within a short window of it on the same day (WhatsApp-
 * style consecutive-message collapsing: no repeated sender name, tighter
 * spacing) — never across a day separator.
 */
export function groupMessagesForDisplay(messages: Message[]): DisplayItem[] {
  const items: DisplayItem[] = [];
  let previousMessage: Message | null = null;
  let previousDayKey: string | null = null;

  for (const message of messages) {
    const currentDayKey = dayKey(message.created_at);

    if (currentDayKey !== previousDayKey) {
      items.push({ type: 'separator', id: `sep-${currentDayKey}`, dateKey: message.created_at });
      previousMessage = null;
    }

    const isGrouped =
      previousMessage !== null &&
      previousMessage.sender_user_id === message.sender_user_id &&
      message.type !== 'system' &&
      previousMessage.type !== 'system' &&
      new Date(message.created_at).getTime() - new Date(previousMessage.created_at).getTime() < GROUP_WINDOW_MS;

    items.push({ type: 'message', message, isGrouped });

    previousMessage = message;
    previousDayKey = currentDayKey;
  }

  return items;
}

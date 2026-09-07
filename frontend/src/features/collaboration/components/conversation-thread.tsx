import { useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ArrowLeft, BellOff, Info, Search, Users, Wifi, WifiOff } from 'lucide-react';

import { Avatar, AvatarFallback, getInitials } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { EmptyState, LoadingState } from '@/components/crud';
import { toast } from '@/components/ds/use-toast';
import { useAuthStore } from '@/features/auth/store/auth-store';

import { useMarkConversationRead } from '../hooks/use-conversations';
import { useMessageTypeLabel } from '../hooks/use-message-type-label';
import { useMessages } from '../hooks/use-messages';
import { useRealtimeStatus } from '../hooks/use-realtime-status';
import { conversationDisplayTitle } from '../lib/conversation-display';
import { groupMessagesForDisplay } from '../lib/message-grouping';
import { computeSeenState, seenByNames as computeSeenByNames } from '../lib/read-receipts';
import type { Conversation, Message } from '../types';
import { ConversationSearchPanel } from './conversation-search-panel';
import { MessageBubble } from './message-bubble';
import { MessageComposer } from './message-composer';

type Props = {
  conversation: Conversation;
  onOpenInfo: () => void;
  onCreateTaskFromMessage: (message: Message) => void;
  /** Mobile-only "back to list" affordance — the list pane is hidden by CSS once a
   *  conversation is active below the `md` breakpoint, so this is its only way back. */
  onBack?: () => void;
};

export function ConversationThread({ conversation, onOpenInfo, onCreateTaskFromMessage, onBack }: Props) {
  const { t, i18n } = useTranslation('collaboration');
  const { t: tCommon } = useTranslation('common');
  const currentUserId = useAuthStore((s) => s.user?.id);
  const realtime = useRealtimeStatus();
  const mediaTypeLabel = useMessageTypeLabel();
  const { data: messages = [], isLoading } = useMessages(conversation.id);
  const markRead = useMarkConversationRead(conversation.id);
  const [replyingTo, setReplyingTo] = useState<Message | null>(null);
  const [searchOpen, setSearchOpen] = useState(false);
  const [highlightedId, setHighlightedId] = useState<string | null>(null);
  const scrollRef = useRef<HTMLDivElement>(null);
  const bubbleRefs = useRef(new Map<string, HTMLDivElement>());

  const title = conversationDisplayTitle(conversation, currentUserId) ?? t(($) => $.conversations.list.groupLabel);
  const byId = useMemo(() => new Map(messages.map((m) => [m.id, m])), [messages]);
  const items = useMemo(() => groupMessagesForDisplay(messages), [messages]);
  const lastOwnMessageId = useMemo(
    () => [...messages].reverse().find((m) => m.sender_user_id === currentUserId)?.id,
    [messages, currentUserId],
  );

  useEffect(() => {
    scrollRef.current?.scrollTo({ top: scrollRef.current.scrollHeight });
  }, [messages.length]);

  const lastMessageId = messages.at(-1)?.id;
  useEffect(() => {
    if (lastMessageId) markRead.mutate(lastMessageId);
    // Marking read is idempotent server-side; only re-fire when the newest message changes.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [lastMessageId]);

  function replyPreviewFor(message: Message) {
    if (!message.reply_to_message_id) return null;
    const original = byId.get(message.reply_to_message_id);
    if (!original) return null;
    const senderLabel = original.sender_user_id === currentUserId ? t(($) => $.conversations.list.you).trim() : (original.sender_name ?? '');
    const snippet = original.body ?? mediaTypeLabel(original.type);
    return { senderLabel, snippet };
  }

  function jumpToMessage(messageId: string) {
    setSearchOpen(false);
    const el = bubbleRefs.current.get(messageId);
    if (!el) {
      // Only the recently-loaded window is rendered (cursor pagination) — an older
      // match genuinely isn't in the DOM yet. Honest fallback rather than a fake jump.
      toast.error(t(($) => $.search.notInView));
      return;
    }
    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    setHighlightedId(messageId);
    window.setTimeout(() => setHighlightedId((current) => (current === messageId ? null : current)), 1600);
  }

  function formatDateSeparator(dateKey: string): string {
    const date = new Date(dateKey);
    const now = new Date();
    const startOfDay = (d: Date) => new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime();
    const diffDays = Math.round((startOfDay(now) - startOfDay(date)) / 86_400_000);

    if (diffDays === 0) return t(($) => $.conversations.dateSeparators.today);
    if (diffDays === 1) return t(($) => $.conversations.dateSeparators.yesterday);

    return date.toLocaleDateString(i18n.language, {
      weekday: 'long',
      month: 'long',
      day: 'numeric',
      year: date.getFullYear() === now.getFullYear() ? undefined : 'numeric',
    });
  }

  return (
    <div className="flex h-full flex-col">
      <div className="flex items-center gap-3 border-b px-4 py-2.5">
        {onBack ? (
          <Button variant="ghost" size="icon" className="size-8 shrink-0 md:hidden" onClick={onBack} aria-label={tCommon(($) => $.actions.back)}>
            <ArrowLeft className="size-4" />
          </Button>
        ) : null}
        <Avatar className="size-8">
          <AvatarFallback>{conversation.type === 'group' ? <Users className="size-4" /> : getInitials(title)}</AvatarFallback>
        </Avatar>
        <div className="min-w-0 flex-1">
          <p className="truncate text-sm font-medium">{title}</p>
        </div>
        {conversation.my_muted ? (
          <span title={t(($) => $.conversations.mute.muted)} className="text-muted-foreground">
            <BellOff className="size-3.5" />
          </span>
        ) : null}
        <span
          title={realtime === 'connected' ? t(($) => $.conversations.realtime.live) : t(($) => $.conversations.realtime.polling)}
          className="text-muted-foreground"
        >
          {realtime === 'connected' ? <Wifi className="size-3.5" /> : <WifiOff className="size-3.5" />}
        </span>
        <Button
          variant="ghost"
          size="icon"
          className="size-8"
          onClick={() => setSearchOpen((v) => !v)}
          aria-label={t(($) => $.search.inConversationTitle)}
        >
          <Search className="size-4" />
        </Button>
        <Button variant="ghost" size="icon" className="size-8" onClick={onOpenInfo} aria-label={t(($) => $.conversations.info.title)}>
          <Info className="size-4" />
        </Button>
      </div>

      {searchOpen ? (
        <ConversationSearchPanel conversationId={conversation.id} onClose={() => setSearchOpen(false)} onJumpToMessage={jumpToMessage} />
      ) : null}

      <div ref={scrollRef} className="flex-1 overflow-y-auto p-4">
        {isLoading ? (
          <LoadingState />
        ) : messages.length === 0 ? (
          <EmptyState title={t(($) => $.conversations.empty.title)} description={t(($) => $.conversations.empty.subtitle)} />
        ) : (
          <div className="flex flex-col">
            {items.map((item) =>
              item.type === 'separator' ? (
                <div key={item.id} className="my-3 flex items-center justify-center">
                  <span className="rounded-full bg-muted px-3 py-1 text-[11px] font-medium text-muted-foreground">
                    {formatDateSeparator(item.dateKey)}
                  </span>
                </div>
              ) : (
                <MessageBubble
                  key={item.message.id}
                  message={item.message}
                  isOwn={item.message.sender_user_id === currentUserId}
                  isGrouped={item.isGrouped}
                  seenState={item.message.sender_user_id === currentUserId ? computeSeenState(item.message, conversation, currentUserId) : null}
                  seenByNames={
                    conversation.type === 'group' && item.message.id === lastOwnMessageId
                      ? computeSeenByNames(item.message, conversation, currentUserId)
                      : undefined
                  }
                  highlighted={highlightedId === item.message.id}
                  replyPreview={replyPreviewFor(item.message)}
                  onReply={setReplyingTo}
                  onCreateTask={onCreateTaskFromMessage}
                  bubbleRef={(el) => {
                    if (el) bubbleRefs.current.set(item.message.id, el);
                    else bubbleRefs.current.delete(item.message.id);
                  }}
                />
              ),
            )}
          </div>
        )}
      </div>

      <MessageComposer
        conversationId={conversation.id}
        participants={conversation.participants ?? []}
        replyingTo={replyingTo}
        onCancelReply={() => setReplyingTo(null)}
      />
    </div>
  );
}

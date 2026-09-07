import { useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ArrowLeft, Info, Users, Wifi, WifiOff } from 'lucide-react';

import { Avatar, AvatarFallback, getInitials } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { EmptyState, LoadingState } from '@/components/crud';
import { useAuthStore } from '@/features/auth/store/auth-store';

import { useMarkConversationRead } from '../hooks/use-conversations';
import { useMessageTypeLabel } from '../hooks/use-message-type-label';
import { useMessages } from '../hooks/use-messages';
import { useRealtimeStatus } from '../hooks/use-realtime-status';
import { conversationDisplayTitle } from '../lib/conversation-display';
import type { Conversation, Message } from '../types';
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
  const { t } = useTranslation('collaboration');
  const { t: tCommon } = useTranslation('common');
  const currentUserId = useAuthStore((s) => s.user?.id);
  const realtime = useRealtimeStatus();
  const mediaTypeLabel = useMessageTypeLabel();
  const { data: messages = [], isLoading } = useMessages(conversation.id);
  const markRead = useMarkConversationRead(conversation.id);
  const [replyingTo, setReplyingTo] = useState<Message | null>(null);
  const scrollRef = useRef<HTMLDivElement>(null);

  const title = conversationDisplayTitle(conversation, currentUserId) ?? t(($) => $.conversations.list.groupLabel);
  const byId = useMemo(() => new Map(messages.map((m) => [m.id, m])), [messages]);

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
        <span
          title={realtime === 'connected' ? t(($) => $.conversations.realtime.live) : t(($) => $.conversations.realtime.polling)}
          className="text-muted-foreground"
        >
          {realtime === 'connected' ? <Wifi className="size-3.5" /> : <WifiOff className="size-3.5" />}
        </span>
        <Button variant="ghost" size="icon" className="size-8" onClick={onOpenInfo} aria-label={t(($) => $.conversations.info.title)}>
          <Info className="size-4" />
        </Button>
      </div>

      <div ref={scrollRef} className="flex-1 overflow-y-auto p-4">
        {isLoading ? (
          <LoadingState />
        ) : messages.length === 0 ? (
          <EmptyState title={t(($) => $.conversations.empty.title)} description={t(($) => $.conversations.empty.subtitle)} />
        ) : (
          <div className="flex flex-col gap-3">
            {messages.map((message) => (
              <MessageBubble
                key={message.id}
                message={message}
                isOwn={message.sender_user_id === currentUserId}
                replyPreview={replyPreviewFor(message)}
                onReply={setReplyingTo}
                onCreateTask={onCreateTaskFromMessage}
              />
            ))}
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

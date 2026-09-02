import { useTranslation } from 'react-i18next';
import { Users } from 'lucide-react';

import { Avatar, AvatarFallback, getInitials } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

import { conversationDisplayTitle } from '../lib/conversation-display';
import type { Conversation } from '../types';

type Props = {
  conversation: Conversation;
  currentUserId: number | undefined;
  isActive: boolean;
  onSelect: () => void;
};

export function ConversationListItem({ conversation, currentUserId, isActive, onSelect }: Props) {
  const { t } = useTranslation('collaboration');
  const title = conversationDisplayTitle(conversation, currentUserId) ?? t(($) => $.conversations.list.groupLabel);
  const unread = conversation.unread_count ?? 0;

  return (
    <button
      type="button"
      onClick={onSelect}
      aria-current={isActive ? 'true' : undefined}
      className={cn(
        'flex w-full items-center gap-3 rounded-md px-2.5 py-2 text-start transition-colors',
        isActive ? 'bg-accent text-accent-foreground' : 'hover:bg-accent/50',
      )}
    >
      <Avatar className="size-9 shrink-0">
        <AvatarFallback>{conversation.type === 'group' ? <Users className="size-4" /> : getInitials(title)}</AvatarFallback>
      </Avatar>

      <div className="min-w-0 flex-1">
        <div className="flex items-center justify-between gap-2">
          <span className={cn('truncate text-sm', unread > 0 ? 'font-semibold' : 'font-medium')}>{title}</span>
          {conversation.last_message_at ? (
            <span className="shrink-0 text-[10px] text-muted-foreground">
              {new Date(conversation.last_message_at).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })}
            </span>
          ) : null}
        </div>
      </div>

      {unread > 0 ? (
        <Badge variant="default" className="h-5 min-w-5 shrink-0 justify-center rounded-full px-1.5 text-[10px]">
          {unread > 99 ? '99+' : unread}
        </Badge>
      ) : null}
    </button>
  );
}

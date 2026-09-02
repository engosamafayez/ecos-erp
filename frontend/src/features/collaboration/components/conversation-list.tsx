import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { MessageSquarePlus, Plus, Search, UsersRound } from 'lucide-react';

import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { EmptyState, LoadingState } from '@/components/crud';
import { useAuthStore } from '@/features/auth/store/auth-store';

import { useConversations } from '../hooks/use-conversations';
import { conversationDisplayTitle } from '../lib/conversation-display';
import type { Conversation } from '../types';
import { ConversationListItem } from './conversation-list-item';

type Props = {
  activeConversationId: string | null;
  onSelect: (conversation: Conversation) => void;
  onNewDirect: () => void;
  onNewGroup: () => void;
};

export function ConversationList({ activeConversationId, onSelect, onNewDirect, onNewGroup }: Props) {
  const { t } = useTranslation('collaboration');
  const currentUserId = useAuthStore((s) => s.user?.id);
  const { data: conversations = [], isLoading, isError, refetch } = useConversations();
  const [query, setQuery] = useState('');

  const visible = useMemo(() => {
    const term = query.trim().toLowerCase();
    if (!term) return conversations;
    return conversations.filter((c) => (conversationDisplayTitle(c, currentUserId) ?? '').toLowerCase().includes(term));
  }, [conversations, query, currentUserId]);

  return (
    <div className="flex h-full flex-col">
      <div className="flex items-center gap-2 border-b p-3">
        <div className="relative flex-1">
          <Search className="absolute start-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" aria-hidden />
          <Input
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder={t(($) => $.conversations.title)}
            className="h-8 ps-8 text-sm"
          />
        </div>
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button size="icon" variant="outline" className="size-8 shrink-0" aria-label={t(($) => $.conversations.newDirect)}>
              <Plus className="size-4" />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            <DropdownMenuItem onSelect={onNewDirect} className="gap-2">
              <MessageSquarePlus className="size-4" />
              {t(($) => $.conversations.newDirect)}
            </DropdownMenuItem>
            <DropdownMenuItem onSelect={onNewGroup} className="gap-2">
              <UsersRound className="size-4" />
              {t(($) => $.conversations.newGroup)}
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </div>

      <div className="flex-1 overflow-y-auto p-2">
        {isLoading ? (
          <LoadingState />
        ) : isError ? (
          <EmptyState
            title={t(($) => $.conversations.list.error)}
            action={<Button size="sm" variant="outline" onClick={() => refetch()}>{t(($) => $.conversations.list.retry)}</Button>}
          />
        ) : visible.length === 0 ? (
          <EmptyState
            title={t(($) => $.conversations.list.empty.title)}
            description={t(($) => $.conversations.list.empty.subtitle)}
          />
        ) : (
          <div className="flex flex-col gap-0.5">
            {visible.map((conversation) => (
              <ConversationListItem
                key={conversation.id}
                conversation={conversation}
                currentUserId={currentUserId}
                isActive={conversation.id === activeConversationId}
                onSelect={() => onSelect(conversation)}
              />
            ))}
          </div>
        )}
      </div>
    </div>
  );
}

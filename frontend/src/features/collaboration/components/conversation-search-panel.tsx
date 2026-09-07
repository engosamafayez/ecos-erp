import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Loader2, X } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { EmptyState } from '@/components/crud';

import { useSearchMessages } from '../hooks/use-messages';

type Props = {
  conversationId: string;
  onClose: () => void;
  onJumpToMessage: (messageId: string) => void;
};

/**
 * In-conversation search (architecture report §19): debounced client-side,
 * scoped server-side via SearchMessagesAction's optional `conversationId` —
 * the SAME MySQL FULLTEXT search endpoint the global search dialog already
 * uses, just narrowed to this one conversation instead of every conversation
 * the caller participates in.
 */
export function ConversationSearchPanel({ conversationId, onClose, onJumpToMessage }: Props) {
  const { t } = useTranslation('collaboration');
  const [query, setQuery] = useState('');
  const [debounced, setDebounced] = useState('');

  useEffect(() => {
    const id = setTimeout(() => setDebounced(query.trim()), 300);
    return () => clearTimeout(id);
  }, [query]);

  const { data = [], isFetching, isError } = useSearchMessages(debounced, conversationId);

  return (
    <div className="flex flex-col border-b bg-background">
      <div className="flex items-center gap-2 px-4 py-2">
        <Input
          autoFocus
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          placeholder={t(($) => $.search.inConversationPlaceholder)}
          className="h-8 flex-1 text-sm"
        />
        {isFetching ? <Loader2 className="size-4 shrink-0 animate-spin text-muted-foreground" aria-hidden /> : null}
        <Button type="button" variant="ghost" size="icon" className="size-8 shrink-0" onClick={onClose} aria-label={t(($) => $.search.close)}>
          <X className="size-4" />
        </Button>
      </div>

      {debounced.length === 0 ? null : isError ? (
        <div className="px-4 pb-2">
          <EmptyState title={t(($) => $.search.error)} />
        </div>
      ) : data.length === 0 && !isFetching ? (
        <div className="px-4 pb-2">
          <EmptyState title={t(($) => $.search.noResults)} />
        </div>
      ) : (
        <ul className="flex max-h-52 flex-col gap-0.5 overflow-y-auto px-2 pb-2">
          {data.map((message) => (
            <li key={message.id}>
              <button
                type="button"
                onClick={() => onJumpToMessage(message.id)}
                className="flex w-full flex-col items-start gap-0.5 rounded-md px-2.5 py-1.5 text-start hover:bg-accent"
              >
                <span className="text-xs font-medium text-muted-foreground">{message.sender_name}</span>
                <span className="truncate text-sm">{message.body}</span>
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

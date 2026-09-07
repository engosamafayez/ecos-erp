import { useTranslation } from 'react-i18next';
import { Download, FileText, Link as LinkIcon, Loader2 } from 'lucide-react';

import { EmptyState, LoadingState } from '@/components/crud';

import { useConversationMedia } from '../hooks/use-conversation-media';
import { downloadMessageAttachment, useMessageAttachmentUrl } from '../hooks/use-secure-media';
import type { ConversationMediaItem, ConversationMediaType } from '../types';

function formatBytes(bytes: number | null): string {
  if (!bytes || bytes <= 0) return '';
  const kb = bytes / 1024;
  return kb < 1024 ? `${Math.round(kb)} KB` : `${(kb / 1024).toFixed(1)} MB`;
}

type Props = {
  conversationId: string;
  type: ConversationMediaType;
  active: boolean;
};

/** One tab body, reused for Media (images)/Links/Documents — same secure,
 *  authorization-gated fetch path messages already use (each item's `id` is
 *  its source message's id), never a public/guessable URL. */
export function ConversationMediaTab({ conversationId, type, active }: Props) {
  const { t } = useTranslation('collaboration');
  const { data = [], isLoading, isError } = useConversationMedia(conversationId, type, active);

  if (!active) return null;
  if (isLoading) return <LoadingState />;
  if (isError) return <EmptyState title={t(($) => $.search.error)} />;

  if (data.length === 0) {
    return (
      <EmptyState
        title={t(($) =>
          type === 'image' ? $.conversations.media.emptyImages : type === 'file' ? $.conversations.media.emptyDocuments : $.conversations.media.emptyLinks,
        )}
      />
    );
  }

  if (type === 'image') {
    return (
      <div className="grid grid-cols-3 gap-1.5">
        {data.map((item) => (
          <MediaThumb key={item.id} item={item} />
        ))}
      </div>
    );
  }

  if (type === 'link') {
    return (
      <ul className="flex flex-col gap-1">
        {data.map((item) => (
          <li key={item.id}>
            <a
              href={item.url ?? '#'}
              target="_blank"
              rel="noreferrer"
              className="flex flex-col gap-0.5 rounded-md px-2 py-1.5 text-sm hover:bg-accent"
            >
              <span className="flex items-center gap-1.5 truncate text-primary">
                <LinkIcon className="size-3.5 shrink-0" aria-hidden />
                {item.url}
              </span>
              {item.body && item.body !== item.url ? <span className="truncate text-xs text-muted-foreground">{item.body}</span> : null}
            </a>
          </li>
        ))}
      </ul>
    );
  }

  return (
    <ul className="flex flex-col gap-1">
      {data.map((item) => (
        <FileRow key={item.id} item={item} />
      ))}
    </ul>
  );
}

function MediaThumb({ item }: { item: ConversationMediaItem }) {
  const { url, isLoading } = useMessageAttachmentUrl(item.id);

  if (isLoading || !url) {
    return (
      <div className="flex aspect-square items-center justify-center rounded-md bg-black/10">
        <Loader2 className="size-4 animate-spin opacity-60" aria-hidden />
      </div>
    );
  }

  return (
    <a href={url} target="_blank" rel="noreferrer" className="block aspect-square overflow-hidden rounded-md bg-black/5">
      <img src={url} alt={item.attachment?.name ?? 'media'} className="size-full object-cover" />
    </a>
  );
}

function FileRow({ item }: { item: ConversationMediaItem }) {
  const { t } = useTranslation('collaboration');

  async function download() {
    if (!item.attachment) return;
    await downloadMessageAttachment(item.id, item.attachment.name);
  }

  return (
    <li>
      <button type="button" onClick={download} className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-start hover:bg-accent">
        <FileText className="size-4 shrink-0 text-muted-foreground" aria-hidden />
        <span className="min-w-0 flex-1">
          <span className="block truncate text-sm">{item.attachment?.name ?? t(($) => $.message.types.file)}</span>
          <span className="block text-xs text-muted-foreground">{formatBytes(item.attachment?.file_size ?? null)}</span>
        </span>
        <Download className="size-4 shrink-0 text-muted-foreground" aria-hidden />
      </button>
    </li>
  );
}

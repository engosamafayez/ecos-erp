import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Check, CheckCheck, Download, FileText, ListPlus, Loader2, Reply } from 'lucide-react';

import { toast } from '@/components/ds/use-toast';
import { cn } from '@/lib/utils';

import { downloadMessageAttachment, useMessageAttachmentUrl } from '../hooks/use-secure-media';
import type { Message, MessageReaction } from '../types';
import type { SeenState } from '../lib/read-receipts';
import { EmojiPicker } from './emoji-picker';

function formatBytes(bytes: number | null): string {
  if (!bytes || bytes <= 0) return '';
  const kb = bytes / 1024;
  return kb < 1024 ? `${Math.round(kb)} KB` : `${(kb / 1024).toFixed(1)} MB`;
}

function formatDuration(seconds: number | null | undefined): string {
  const total = Math.max(0, Math.round(seconds ?? 0));
  return `${Math.floor(total / 60)}:${(total % 60).toString().padStart(2, '0')}`;
}

type Props = {
  message: Message;
  isOwn: boolean;
  /** Same sender as the previous message, within a short window, same day
   *  (architecture report §31) — collapses the repeated sender name/avatar
   *  and tightens spacing, WhatsApp-style. Optional (default false, i.e. the
   *  original ungrouped rendering) — only ConversationThread ever needs to
   *  pass true, having already done the day/sender bucketing. */
  isGrouped?: boolean;
  /** Only ever set for `isOwn` messages — derived client-side from the other
   *  participant(s)' read cursors (architecture report §22), no backend change. */
  seenState?: SeenState | null;
  /** Group-only, and only for the latest own message — see read-receipts.ts. */
  seenByNames?: string[];
  /** Briefly true right after an in-conversation search result is clicked. */
  highlighted?: boolean;
  /** `id: null` = the original exists but isn't loaded right now — shown as an honest, non-clickable placeholder. */
  replyPreview?: { id: string | null; senderLabel: string; snippet: string } | null;
  onReply: (message: Message) => void;
  onJumpToReply: (messageId: string) => void;
  onCreateTask: (message: Message) => void;
  onSetReaction: (emoji: string) => void;
  onRemoveReaction: () => void;
  bubbleRef?: (el: HTMLDivElement | null) => void;
};

export function MessageBubble({
  message,
  isOwn,
  isGrouped = false,
  seenState,
  seenByNames,
  highlighted,
  replyPreview,
  onReply,
  onJumpToReply,
  onCreateTask,
  onSetReaction,
  onRemoveReaction,
  bubbleRef,
}: Props) {
  const { t } = useTranslation('collaboration');

  return (
    <div className={cn('group flex scroll-mt-16', isOwn ? 'justify-end' : 'justify-start', isGrouped ? 'mt-0.5' : 'mt-2.5')}>
      <div ref={bubbleRef} className={cn('flex max-w-[75%] flex-col gap-1', isOwn ? 'items-end' : 'items-start')}>
        {!isOwn && !isGrouped && message.sender_name ? (
          <span className="px-1 text-xs font-medium text-muted-foreground">{message.sender_name}</span>
        ) : null}

        <div
          className={cn(
            'rounded-2xl px-3 py-2 text-sm transition-shadow',
            isOwn ? 'bg-primary text-primary-foreground' : 'bg-muted text-foreground',
            highlighted ? 'ring-2 ring-offset-2 ring-primary' : null,
          )}
        >
          {replyPreview ? (
            <ReplyQuote isOwn={isOwn} preview={replyPreview} onJumpToReply={onJumpToReply} />
          ) : null}

          <MessageBody message={message} isOwn={isOwn} />
        </div>

        {message.reactions && message.reactions.length > 0 ? (
          <ReactionBar reactions={message.reactions} onSetReaction={onSetReaction} onRemoveReaction={onRemoveReaction} />
        ) : null}

        <div className="flex items-center gap-1.5 px-1">
          <span className="text-[10px] text-muted-foreground">
            {new Date(message.created_at).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })}
          </span>
          {isOwn && seenState ? (
            seenState === 'seen' ? (
              <CheckCheck className="size-3 text-sky-500" aria-label={t(($) => $.message.seen)} />
            ) : (
              <Check className="size-3 text-muted-foreground" aria-label={t(($) => $.message.sent)} />
            )
          ) : null}
          <span className="flex items-center gap-2 opacity-70 transition-opacity md:opacity-0 md:group-hover:opacity-100">
            <button
              type="button"
              onClick={() => onReply(message)}
              className="text-[10px] text-muted-foreground hover:text-foreground"
            >
              <Reply className="inline size-3" aria-hidden /> {t(($) => $.message.reply)}
            </button>
            <button
              type="button"
              onClick={() => onCreateTask(message)}
              className="text-[10px] text-muted-foreground hover:text-foreground"
            >
              <ListPlus className="inline size-3" aria-hidden /> {t(($) => $.message.createTask)}
            </button>
            <EmojiPicker onSelect={onSetReaction} triggerClassName="size-5 [&_span]:text-xs" />
          </span>
        </div>

        {isOwn && seenByNames && seenByNames.length > 0 ? (
          <span className="px-1 text-[10px] text-muted-foreground">{t(($) => $.message.seenBy, { names: seenByNames.join(', ') })}</span>
        ) : null}
      </div>
    </div>
  );
}

/** Clicking the quote scrolls to and briefly highlights the original message when it's
 *  loaded (`id` set); a not-currently-loaded original renders as a plain, non-clickable
 *  placeholder rather than a dead button. */
function ReplyQuote({
  isOwn,
  preview,
  onJumpToReply,
}: {
  isOwn: boolean;
  preview: { id: string | null; senderLabel: string; snippet: string };
  onJumpToReply: (messageId: string) => void;
}) {
  const className = cn(
    'mb-1.5 w-full rounded-md border-s-2 px-2 py-1 text-start text-xs opacity-80',
    isOwn ? 'border-s-primary-foreground/40' : 'border-s-primary/40',
  );
  const content = (
    <>
      {preview.senderLabel ? <p className="font-medium">{preview.senderLabel}</p> : null}
      <p className="truncate">{preview.snippet}</p>
    </>
  );

  if (!preview.id) {
    return <div className={cn(className, 'italic')}>{content}</div>;
  }

  return (
    <button type="button" onClick={() => onJumpToReply(preview.id!)} className={cn(className, 'block hover:opacity-100')}>
      {content}
    </button>
  );
}

/** One pill per distinct emoji (server-aggregated); clicking your own active
 *  pill removes it, clicking any other pill sets/switches your reaction to
 *  that emoji (§13 — one reaction per user per message, upsert semantics). */
function ReactionBar({
  reactions,
  onSetReaction,
  onRemoveReaction,
}: {
  reactions: MessageReaction[];
  onSetReaction: (emoji: string) => void;
  onRemoveReaction: () => void;
}) {
  return (
    <div className="mt-1 flex flex-wrap gap-1">
      {reactions.map((reaction) => (
        <button
          key={reaction.emoji}
          type="button"
          onClick={() => (reaction.reacted_by_me ? onRemoveReaction() : onSetReaction(reaction.emoji))}
          className={cn(
            'flex items-center gap-1 rounded-full border px-1.5 py-0.5 text-xs leading-none transition-colors',
            reaction.reacted_by_me ? 'border-primary bg-primary/10' : 'border-border bg-background/60 hover:bg-accent',
          )}
        >
          <span>{reaction.emoji}</span>
          <span className="text-[10px] text-muted-foreground tabular-nums">{reaction.count}</span>
        </button>
      ))}
    </div>
  );
}

function MessageBody({ message, isOwn }: { message: Message; isOwn: boolean }) {
  if (message.type === 'image') {
    return <ImageBody message={message} />;
  }

  if (message.type === 'voice') {
    return <VoiceBody message={message} />;
  }

  if (message.type === 'file') {
    return <FileBody message={message} isOwn={isOwn} />;
  }

  if (message.type === 'system') {
    return <p className="italic text-muted-foreground">{message.body}</p>;
  }

  return <p className="whitespace-pre-wrap break-words">{message.body}</p>;
}

function ImageBody({ message }: { message: Message }) {
  const { t } = useTranslation('collaboration');
  const { url, isLoading, isError } = useMessageAttachmentUrl(message.id);

  if (isError) return <p className="text-xs italic opacity-80">{t(($) => $.message.uploadFailed)}</p>;
  if (isLoading || !url) {
    return (
      <div className="flex h-40 w-56 items-center justify-center rounded-md bg-black/10">
        <Loader2 className="size-5 animate-spin opacity-60" aria-hidden />
      </div>
    );
  }

  return (
    <a href={url} target="_blank" rel="noreferrer" className="block overflow-hidden rounded-md">
      <img src={url} alt={message.attachment?.name ?? 'image'} className="max-h-72 max-w-full object-contain" />
    </a>
  );
}

function VoiceBody({ message }: { message: Message }) {
  const { t } = useTranslation('collaboration');
  const { url, isLoading, isError } = useMessageAttachmentUrl(message.id);

  if (isError) return <p className="text-xs italic opacity-80">{t(($) => $.voice.playbackUnauthorized)}</p>;
  if (isLoading || !url) {
    return (
      <div className="flex h-9 w-56 items-center gap-2">
        <Loader2 className="size-4 animate-spin opacity-60" aria-hidden />
      </div>
    );
  }

  return (
    <div className="flex items-center gap-2">
      <audio controls src={url} className="h-9 max-w-[220px]" />
      {message.attachment?.duration_seconds != null ? (
        <span className="text-xs opacity-70 tabular-nums">{formatDuration(message.attachment.duration_seconds)}</span>
      ) : null}
    </div>
  );
}

function FileBody({ message, isOwn }: { message: Message; isOwn: boolean }) {
  const { t } = useTranslation('collaboration');
  const [downloading, setDownloading] = useState(false);

  async function download() {
    if (!message.attachment) return;
    setDownloading(true);
    try {
      await downloadMessageAttachment(message.id, message.attachment.name);
    } catch {
      toast.error(t(($) => $.errors.generic));
    } finally {
      setDownloading(false);
    }
  }

  return (
    <button
      type="button"
      onClick={download}
      disabled={downloading}
      className={cn(
        'flex items-center gap-2 rounded-md px-1 py-0.5 text-start hover:underline',
        isOwn ? 'text-primary-foreground' : 'text-foreground',
      )}
    >
      <FileText className="size-5 shrink-0" aria-hidden />
      <span className="min-w-0 flex-1">
        <span className="block truncate text-sm font-medium">{message.attachment?.name ?? 'file'}</span>
        <span className="block text-xs opacity-70">{formatBytes(message.attachment?.file_size ?? null)}</span>
      </span>
      {downloading ? <Loader2 className="size-4 shrink-0 animate-spin" aria-hidden /> : <Download className="size-4 shrink-0" aria-hidden />}
    </button>
  );
}

import { useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Image as ImageIcon, Loader2, Mic, Paperclip, Send, X } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { toast } from '@/components/ds/use-toast';
import { useAuthStore } from '@/features/auth/store/auth-store';

import { useMessageTypeLabel } from '../hooks/use-message-type-label';
import { useSendMessage } from '../hooks/use-messages';
import type { ConversationParticipant, Message } from '../types';
import { VoiceRecorder } from './voice-recorder';

const IMAGE_ACCEPT = 'image/jpeg,image/png,image/webp,image/gif';
const FILE_ACCEPT = '.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.zip,.jpg,.jpeg,.png';

type Props = {
  conversationId: string;
  participants: ConversationParticipant[];
  replyingTo: Message | null;
  onCancelReply: () => void;
};

export function MessageComposer({ conversationId, participants, replyingTo, onCancelReply }: Props) {
  const { t } = useTranslation('collaboration');
  const currentUserId = useAuthStore((s) => s.user?.id);
  const mediaTypeLabel = useMessageTypeLabel();
  const send = useSendMessage(conversationId);

  const [text, setText] = useState('');
  const [mentionedIds, setMentionedIds] = useState<Set<number>>(new Set());
  const [recording, setRecording] = useState(false);
  const imageRef = useRef<HTMLInputElement>(null);
  const fileRef = useRef<HTMLInputElement>(null);

  const mentionCandidates = useMemo(
    () => participants.filter((p) => p.user_id !== currentUserId && p.name),
    [participants, currentUserId],
  );

  const mentionQuery = useMemo(() => {
    const match = /(?:^|\s)@([^\s@]*)$/.exec(text);
    return match ? match[1] ?? '' : null;
  }, [text]);

  const mentionMatches = mentionQuery === null
    ? []
    : mentionCandidates.filter((p) => p.name!.toLowerCase().includes(mentionQuery.toLowerCase())).slice(0, 6);

  function pickMention(participant: ConversationParticipant) {
    setText((current) => current.replace(/(?:^|\s)@([^\s@]*)$/, (whole) => `${whole.startsWith(' ') ? ' ' : ''}@${participant.name} `));
    setMentionedIds((current) => new Set(current).add(participant.user_id));
  }

  function reset() {
    setText('');
    setMentionedIds(new Set());
    onCancelReply();
  }

  function submitFailed() {
    toast.error(t(($) => $.message.sendFailed));
  }

  function sendText() {
    const body = text.trim();
    if (!body || send.isPending) return;

    send.mutate(
      { type: 'text', body, replyToMessageId: replyingTo?.id ?? null, mentionedUserIds: Array.from(mentionedIds) },
      { onSuccess: reset, onError: submitFailed },
    );
  }

  function sendFile(file: File, type: 'image' | 'file') {
    send.mutate(
      { type, file, replyToMessageId: replyingTo?.id ?? null },
      { onError: () => toast.error(t(($) => $.message.uploadFailed)) },
    );
  }

  function onKeyDown(e: React.KeyboardEvent<HTMLTextAreaElement>) {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      sendText();
    }
  }

  if (recording) {
    return (
      <div className="border-t p-3">
        <VoiceRecorder
          onCancel={() => setRecording(false)}
          onSend={(blob, durationSeconds) => {
            setRecording(false);
            send.mutate(
              { type: 'voice', file: blob, voiceDurationSeconds: durationSeconds, replyToMessageId: replyingTo?.id ?? null },
              { onSuccess: onCancelReply, onError: () => toast.error(t(($) => $.message.uploadFailed)) },
            );
          }}
        />
      </div>
    );
  }

  return (
    <div className="border-t p-3">
      {replyingTo ? (
        <div className="mb-2 flex items-center gap-2 rounded-md bg-muted px-3 py-1.5 text-xs">
          <span className="flex-1 truncate">
            <span className="font-medium">{t(($) => $.message.replyingTo)}: </span>
            {replyingTo.body ?? mediaTypeLabel(replyingTo.type)}
          </span>
          <button type="button" onClick={onCancelReply} aria-label={t(($) => $.message.cancelReply)}>
            <X className="size-3.5" aria-hidden />
          </button>
        </div>
      ) : null}

      <div className="relative flex items-end gap-2">
        <input ref={imageRef} type="file" accept={IMAGE_ACCEPT} className="hidden" onChange={(e) => { const f = e.target.files?.[0]; e.target.value = ''; if (f) sendFile(f, 'image'); }} />
        <input ref={fileRef} type="file" accept={FILE_ACCEPT} className="hidden" onChange={(e) => { const f = e.target.files?.[0]; e.target.value = ''; if (f) sendFile(f, 'file'); }} />

        <Button type="button" variant="ghost" size="icon" className="size-9 shrink-0" onClick={() => imageRef.current?.click()} aria-label={t(($) => $.message.attachImage)}>
          <ImageIcon className="size-4" />
        </Button>
        <Button type="button" variant="ghost" size="icon" className="size-9 shrink-0" onClick={() => fileRef.current?.click()} aria-label={t(($) => $.message.attachFile)}>
          <Paperclip className="size-4" />
        </Button>
        <Button type="button" variant="ghost" size="icon" className="size-9 shrink-0" onClick={() => setRecording(true)} aria-label={t(($) => $.message.recordVoice)}>
          <Mic className="size-4" />
        </Button>

        <div className="relative flex-1">
          {mentionMatches.length > 0 ? (
            <div className="absolute bottom-full mb-1 w-56 overflow-hidden rounded-md border bg-popover shadow-md">
              {mentionMatches.map((p) => (
                <button
                  key={p.user_id}
                  type="button"
                  onClick={() => pickMention(p)}
                  className="block w-full truncate px-2.5 py-1.5 text-start text-sm hover:bg-accent"
                >
                  {p.name}
                </button>
              ))}
            </div>
          ) : null}

          <Textarea
            value={text}
            onChange={(e) => setText(e.target.value)}
            onKeyDown={onKeyDown}
            placeholder={t(($) => $.message.typePlaceholder)}
            rows={1}
            className="max-h-32 min-h-9 resize-none py-2"
          />
        </div>

        <Button type="button" size="icon" className="size-9 shrink-0" onClick={sendText} disabled={!text.trim() || send.isPending} aria-label={t(($) => $.message.send)}>
          {send.isPending ? <Loader2 className="size-4 animate-spin" /> : <Send className="size-4" />}
        </Button>
      </div>
    </div>
  );
}

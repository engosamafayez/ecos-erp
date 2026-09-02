import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LogOut, Users, X } from 'lucide-react';

import { Avatar, AvatarFallback, getInitials } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { toast } from '@/components/ds/use-toast';
import { useAuthStore } from '@/features/auth/store/auth-store';

import { useAddParticipant, useRemoveParticipant } from '../hooks/use-conversations';
import { conversationDisplayTitle } from '../lib/conversation-display';
import type { AddressableUser, Conversation } from '../types';
import { UserPicker } from './user-picker';

type Props = {
  conversation: Conversation | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onLeft: () => void;
};

export function ConversationInfoPanel({ conversation, open, onOpenChange, onLeft }: Props) {
  const { t } = useTranslation('collaboration');
  const currentUserId = useAuthStore((s) => s.user?.id);
  const [pickerOpen, setPickerOpen] = useState(false);

  const conversationId = conversation?.id ?? '';
  const add = useAddParticipant(conversationId);
  const remove = useRemoveParticipant(conversationId);

  if (!conversation) return null;

  const isOwner = conversation.my_role === 'owner';
  const isGroup = conversation.type === 'group';
  const title = conversationDisplayTitle(conversation, currentUserId) ?? t(($) => $.conversations.list.groupLabel);
  const participants = conversation.participants ?? [];

  function addMember(user: AddressableUser | null) {
    if (!user) return;
    setPickerOpen(false);
    add.mutate(user.id, { onError: () => toast.error(t(($) => $.conversations.newGroupDialog.failed)) });
  }

  function removeMember(userId: number) {
    remove.mutate(userId, { onError: () => toast.error(t(($) => $.errors.generic)) });
  }

  function leaveGroup() {
    if (!currentUserId) return;
    remove.mutate(currentUserId, {
      onSuccess: () => {
        onOpenChange(false);
        onLeft();
      },
      onError: () => toast.error(t(($) => $.errors.generic)),
    });
  }

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent side="right" className="flex flex-col gap-4">
        <SheetHeader>
          <SheetTitle>{title}</SheetTitle>
        </SheetHeader>

        <div className="flex flex-col gap-2">
          <div className="flex items-center justify-between">
            <Label className="text-xs uppercase text-muted-foreground">{t(($) => $.conversations.info.participants)}</Label>
            {isGroup && isOwner ? (
              <Button size="sm" variant="outline" onClick={() => setPickerOpen((v) => !v)}>
                {t(($) => $.conversations.info.addMember)}
              </Button>
            ) : null}
          </div>

          {pickerOpen ? <UserPicker value={null} onChange={addMember} excludeIds={participants.map((p) => p.user_id)} /> : null}

          <ul className="flex flex-col gap-1">
            {participants.map((participant) => (
              <li key={participant.id} className="flex items-center gap-2 rounded-md px-1 py-1.5">
                <Avatar className="size-7">
                  <AvatarFallback className="text-[11px]">
                    {participant.name ? getInitials(participant.name) : <Users className="size-3.5" />}
                  </AvatarFallback>
                </Avatar>
                <span className="flex-1 truncate text-sm">
                  {participant.name}
                  {participant.user_id === currentUserId ? ` (${t(($) => $.conversations.list.you).trim()})` : ''}
                </span>
                {participant.role === 'owner' ? (
                  <span className="text-[10px] uppercase text-muted-foreground">{t(($) => $.conversations.info.type.group)}</span>
                ) : null}
                {isGroup && isOwner && participant.user_id !== currentUserId ? (
                  <button
                    type="button"
                    onClick={() => removeMember(participant.user_id)}
                    aria-label={t(($) => $.conversations.info.removeMember)}
                    className="text-muted-foreground hover:text-destructive"
                  >
                    <X className="size-3.5" />
                  </button>
                ) : null}
              </li>
            ))}
          </ul>
        </div>

        {isGroup ? (
          <Button variant="outline" className="gap-2 text-destructive hover:text-destructive" onClick={leaveGroup}>
            <LogOut className="size-4" />
            {t(($) => $.conversations.info.leaveGroup)}
          </Button>
        ) : null}
      </SheetContent>
    </Sheet>
  );
}

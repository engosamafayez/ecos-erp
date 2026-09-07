import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { BellOff, LogOut, Users, X } from 'lucide-react';

import { Avatar, AvatarFallback, getInitials } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Switch } from '@/components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { toast } from '@/components/ds/use-toast';
import { useAuthStore } from '@/features/auth/store/auth-store';

import { useAddParticipant, useMuteConversation, useRemoveParticipant } from '../hooks/use-conversations';
import { conversationDisplayTitle } from '../lib/conversation-display';
import type { AddressableUser, Conversation } from '../types';
import { ConversationMediaTab } from './conversation-media-tab';
import { UserPicker } from './user-picker';

type Props = {
  conversation: Conversation | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onLeft: () => void;
};

type InfoTab = 'info' | 'media' | 'links' | 'documents';

/**
 * Details panel — Info (participants + mute + leave) plus the WhatsApp-style
 * Media/Links/Documents aggregation tabs (architecture report §20). Group and
 * direct conversations share this one panel; the group-only affordances
 * (add/remove member, leave) are simply hidden for a direct conversation.
 */
export function ConversationInfoPanel({ conversation, open, onOpenChange, onLeft }: Props) {
  const { t } = useTranslation('collaboration');
  const currentUserId = useAuthStore((s) => s.user?.id);
  const [pickerOpen, setPickerOpen] = useState(false);
  const [tab, setTab] = useState<InfoTab>('info');

  const conversationId = conversation?.id ?? '';
  const add = useAddParticipant(conversationId);
  const remove = useRemoveParticipant(conversationId);
  const mute = useMuteConversation(conversationId);

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

  function toggleMute(nextMuted: boolean) {
    mute.mutate(nextMuted, { onError: () => toast.error(t(($) => $.errors.generic)) });
  }

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent side="right" className="flex flex-col gap-3">
        <SheetHeader>
          <SheetTitle>{title}</SheetTitle>
        </SheetHeader>

        <Tabs value={tab} onValueChange={(v) => setTab(v as InfoTab)} className="flex min-h-0 flex-1 flex-col gap-3">
          <TabsList className="w-full">
            <TabsTrigger value="info" className="flex-1">
              {t(($) => $.conversations.info.title)}
            </TabsTrigger>
            <TabsTrigger value="media" className="flex-1">
              {t(($) => $.conversations.media.mediaTab)}
            </TabsTrigger>
            <TabsTrigger value="links" className="flex-1">
              {t(($) => $.conversations.media.linksTab)}
            </TabsTrigger>
            <TabsTrigger value="documents" className="flex-1">
              {t(($) => $.conversations.media.documentsTab)}
            </TabsTrigger>
          </TabsList>

          <TabsContent value="info" className="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto">
            <div className="flex items-center justify-between rounded-md border px-3 py-2">
              <div className="flex items-center gap-2">
                <BellOff className="size-4 text-muted-foreground" aria-hidden />
                <span className="text-sm">{t(($) => $.conversations.mute.label)}</span>
              </div>
              <Switch checked={conversation.my_muted} onCheckedChange={toggleMute} disabled={mute.isPending} />
            </div>

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
                      <span className="text-[10px] uppercase text-muted-foreground">{t(($) => $.conversations.info.owner)}</span>
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
          </TabsContent>

          <TabsContent value="media" className="min-h-0 flex-1 overflow-y-auto">
            <ConversationMediaTab conversationId={conversationId} type="image" active={tab === 'media'} />
          </TabsContent>
          <TabsContent value="links" className="min-h-0 flex-1 overflow-y-auto">
            <ConversationMediaTab conversationId={conversationId} type="link" active={tab === 'links'} />
          </TabsContent>
          <TabsContent value="documents" className="min-h-0 flex-1 overflow-y-auto">
            <ConversationMediaTab conversationId={conversationId} type="file" active={tab === 'documents'} />
          </TabsContent>
        </Tabs>
      </SheetContent>
    </Sheet>
  );
}

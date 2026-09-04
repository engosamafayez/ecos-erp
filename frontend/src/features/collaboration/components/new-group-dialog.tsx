import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Loader2, X } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { toast } from '@/components/ds/use-toast';

import { useCreateGroupConversation } from '../hooks/use-conversations';
import type { AddressableUser, Conversation } from '../types';
import { UserPicker } from './user-picker';

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onCreated: (conversation: Conversation) => void;
};

export function NewGroupDialog({ open, onOpenChange, onCreated }: Props) {
  const { t } = useTranslation('collaboration');
  const [title, setTitle] = useState('');
  const [members, setMembers] = useState<AddressableUser[]>([]);
  const create = useCreateGroupConversation();

  function close() {
    setTitle('');
    setMembers([]);
    onOpenChange(false);
  }

  function addMember(user: AddressableUser | null) {
    if (!user) return;
    setMembers((current) => (current.some((m) => m.id === user.id) ? current : [...current, user]));
  }

  function removeMember(id: number) {
    setMembers((current) => current.filter((m) => m.id !== id));
  }

  function submit() {
    if (!title.trim()) return;
    create.mutate(
      { title: title.trim(), participantUserIds: members.map((m) => m.id) },
      {
        onSuccess: (conversation) => {
          close();
          onCreated(conversation);
        },
        onError: () => toast.error(t(($) => $.conversations.newGroupDialog.failed)),
      },
    );
  }

  return (
    <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{t(($) => $.conversations.newGroupDialog.title)}</DialogTitle>
        </DialogHeader>

        <p className="text-xs text-muted-foreground">{t(($) => $.conversations.newGroupDialog.hint)}</p>

        <div className="flex flex-col gap-3 py-2">
          <div className="flex flex-col gap-1.5">
            <Label>{t(($) => $.conversations.newGroupDialog.name)}</Label>
            <Input
              value={title}
              onChange={(e) => setTitle(e.target.value)}
              placeholder={t(($) => $.conversations.newGroupDialog.namePlaceholder)}
            />
          </div>

          <div className="flex flex-col gap-1.5">
            <Label>{t(($) => $.conversations.newGroupDialog.members)}</Label>
            <UserPicker value={null} onChange={addMember} excludeIds={members.map((m) => m.id)} />
            {members.length > 0 ? (
              <ul className="flex flex-wrap gap-1.5 pt-1">
                {members.map((member) => (
                  <li key={member.id} className="flex items-center gap-1 rounded-full bg-muted px-2.5 py-1 text-xs">
                    {member.name}
                    <button type="button" onClick={() => removeMember(member.id)} aria-label={t(($) => $.conversations.info.removeMember)}>
                      <X className="size-3" />
                    </button>
                  </li>
                ))}
              </ul>
            ) : null}
          </div>
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={close}>{t(($) => $.conversations.newGroupDialog.cancel)}</Button>
          <Button onClick={submit} disabled={!title.trim() || create.isPending}>
            {create.isPending ? <Loader2 className="me-1.5 size-4 animate-spin" /> : null}
            {t(($) => $.conversations.newGroupDialog.submit)}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

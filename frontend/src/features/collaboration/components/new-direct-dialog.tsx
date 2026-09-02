import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Loader2 } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { toast } from '@/components/ds/use-toast';

import { useStartDirectConversation } from '../hooks/use-conversations';
import type { AddressableUser, Conversation } from '../types';
import { UserPicker } from './user-picker';

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onCreated: (conversation: Conversation) => void;
};

export function NewDirectDialog({ open, onOpenChange, onCreated }: Props) {
  const { t } = useTranslation('collaboration');
  const [recipient, setRecipient] = useState<AddressableUser | null>(null);
  const start = useStartDirectConversation();

  function close() {
    setRecipient(null);
    onOpenChange(false);
  }

  function submit() {
    if (!recipient) return;
    start.mutate(recipient.id, {
      onSuccess: (conversation) => {
        close();
        onCreated(conversation);
      },
      onError: () => toast.error(t(($) => $.conversations.newDirectDialog.failed)),
    });
  }

  return (
    <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{t(($) => $.conversations.newDirectDialog.title)}</DialogTitle>
        </DialogHeader>

        <div className="flex flex-col gap-1.5 py-2">
          <Label>{t(($) => $.conversations.newDirectDialog.recipient)}</Label>
          <UserPicker value={recipient} onChange={setRecipient} />
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={close}>{t(($) => $.conversations.newDirectDialog.cancel)}</Button>
          <Button onClick={submit} disabled={!recipient || start.isPending}>
            {start.isPending ? <Loader2 className="me-1.5 size-4 animate-spin" /> : null}
            {t(($) => $.conversations.newDirectDialog.submit)}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

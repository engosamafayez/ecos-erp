import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Loader2 } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { toast } from '@/components/ds/use-toast';
import { useAuthStore } from '@/features/auth/store/auth-store';

import { useCreateTask } from '../hooks/use-tasks';
import { TASK_PRIORITY_ORDER } from '../lib/task-meta';
import type { AddressableUser, Task, TaskPriority } from '../types';
import { UserPicker } from './user-picker';

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onCreated: (task: Task) => void;
  /** Message -> Create Task variant: prefills the title and links the source message. */
  sourceMessage?: { id: string; body: string | null } | null;
};

export function CreateTaskDialog({ open, onOpenChange, onCreated, sourceMessage }: Props) {
  const { t } = useTranslation('collaboration');
  const currentUser = useAuthStore((s) => s.user);
  const create = useCreateTask();

  const [title, setTitle] = useState('');
  const [description, setDescription] = useState('');
  const [assignee, setAssignee] = useState<AddressableUser | null>(null);
  const [priority, setPriority] = useState<TaskPriority>('normal');
  const [dueAt, setDueAt] = useState('');

  useEffect(() => {
    if (open && sourceMessage) {
      // eslint-disable-next-line react-hooks/set-state-in-effect -- prefilling from a prop that only changes when the dialog opens for a new message, mirroring TaskDrawer.tsx's own open-triggered reset
      setTitle((sourceMessage.body ?? '').slice(0, 120));
    }
  }, [open, sourceMessage]);

  function reset() {
    setTitle('');
    setDescription('');
    setAssignee(null);
    setPriority('normal');
    setDueAt('');
  }

  function close() {
    reset();
    onOpenChange(false);
  }

  function submit() {
    if (!title.trim()) return;

    create.mutate(
      {
        title: title.trim(),
        description: description.trim() || null,
        assigneeUserId: assignee?.id ?? currentUser?.id ?? null,
        priority,
        dueAt: dueAt ? new Date(dueAt).toISOString() : null,
        sourceMessageId: sourceMessage?.id ?? null,
      },
      {
        onSuccess: (task) => {
          toast.success(t(($) => $.tasks.createDialog.success));
          close();
          onCreated(task);
        },
        onError: () => toast.error(t(($) => $.tasks.createDialog.failed)),
      },
    );
  }

  return (
    <Dialog open={open} onOpenChange={(next) => (next ? onOpenChange(true) : close())}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{t(($) => $.tasks.createDialog.title)}</DialogTitle>
        </DialogHeader>

        {sourceMessage ? (
          <p className="rounded-md bg-muted px-2.5 py-1.5 text-xs text-muted-foreground">
            {t(($) => $.tasks.createDialog.fromMessage)}: {sourceMessage.body ?? '—'}
          </p>
        ) : null}

        <div className="flex flex-col gap-3 py-1">
          <div className="flex flex-col gap-1.5">
            <Label>{t(($) => $.tasks.createDialog.titleLabel)}</Label>
            <Input value={title} onChange={(e) => setTitle(e.target.value)} placeholder={t(($) => $.tasks.createDialog.titlePlaceholder)} />
          </div>

          <div className="flex flex-col gap-1.5">
            <Label>{t(($) => $.tasks.createDialog.descriptionLabel)}</Label>
            <Textarea value={description} onChange={(e) => setDescription(e.target.value)} rows={3} />
          </div>

          <div className="flex flex-col gap-1.5">
            <Label>{t(($) => $.tasks.createDialog.assigneeLabel)}</Label>
            <UserPicker value={assignee} onChange={setAssignee} placeholder={t(($) => $.tasks.createDialog.assigneeDefault)} />
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div className="flex flex-col gap-1.5">
              <Label>{t(($) => $.tasks.createDialog.priorityLabel)}</Label>
              <Select value={priority} onValueChange={(v) => setPriority(v as TaskPriority)}>
                <SelectTrigger><SelectValue /></SelectTrigger>
                <SelectContent>
                  {TASK_PRIORITY_ORDER.map((p) => (
                    <SelectItem key={p} value={p}>{t(($) => $.tasks.priority[p])}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            <div className="flex flex-col gap-1.5">
              <Label>{t(($) => $.tasks.createDialog.dueLabel)}</Label>
              <Input type="datetime-local" value={dueAt} onChange={(e) => setDueAt(e.target.value)} />
            </div>
          </div>
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={close}>{t(($) => $.tasks.createDialog.cancel)}</Button>
          <Button onClick={submit} disabled={!title.trim() || create.isPending}>
            {create.isPending ? <Loader2 className="me-1.5 size-4 animate-spin" /> : null}
            {t(($) => $.tasks.createDialog.submit)}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

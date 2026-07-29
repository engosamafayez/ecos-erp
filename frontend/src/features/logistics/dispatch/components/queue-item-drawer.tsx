import { useState } from 'react';

import { PageDrawer } from '@/components/page/drawer/page-drawer';
import { useToast } from '@/components/ds/use-toast';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';

import { useDeferItem, usePrioritiseItem } from '../hooks/use-dispatch-ops';
import type { QueueItem, QueuePriority } from '../types/dispatch-ops';
import { PriorityBadge, QueueStatusBadge } from './dispatch-status-badges';

const PRIORITIES: QueuePriority[] = ['critical', 'high', 'normal', 'low'];

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="space-y-0.5">
      <p className="text-[11px] uppercase tracking-wide text-muted-foreground">{label}</p>
      <div className="text-sm">{children}</div>
    </div>
  );
}

/**
 * One queue item, with the two things a dispatcher does to it without
 * allocating: move it up, or put it aside.
 *
 * Both demand a reason. Ordering that cannot be explained gets worked around.
 */
export function QueueItemDrawer({
  item,
  open,
  onOpenChange,
}: {
  item: QueueItem | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const { toast } = useToast();
  const [priority, setPriority] = useState<QueuePriority>('normal');
  const [reason, setReason] = useState('');

  const prioritise = usePrioritiseItem();
  const defer = useDeferItem();

  if (item === null) return null;

  const canReorder = item.status === 'waiting' || item.status === 'deferred';

  return (
    <PageDrawer
      open={open}
      onOpenChange={onOpenChange}
      title={item.trip_number ?? 'Queue item'}
      description="Queue position and hold"
      size="lg"
    >
      <div className="space-y-5">
        <div className="grid grid-cols-2 gap-4">
          <Field label="Status">
            <QueueStatusBadge status={item.status} />
          </Field>
          <Field label="Priority">
            <PriorityBadge priority={item.priority} />
          </Field>
          <Field label="Rank">{item.rank}</Field>
          <Field label="Waiting">{item.waiting_minutes} min</Field>
          <Field label="Attempts">{item.attempt_count}</Field>
          <Field label="Claimed by">{item.claimed_by ?? '—'}</Field>
          <Field label="Trip capacity">{item.trip_capacity ?? '—'}</Field>
          <Field label="Queued at">
            {item.queued_at ? new Date(item.queued_at).toLocaleString() : '—'}
          </Field>
        </div>

        {item.priority_reason && (
          <Field label="Why this priority">{item.priority_reason}</Field>
        )}

        {item.is_stuck && (
          <Alert variant="destructive">
            <AlertDescription className="text-xs">
              This item has failed {item.attempt_count} times.
              {item.last_failure_reason ? ` Last reason: ${item.last_failure_reason}.` : ''} It
              needs a decision, not another attempt.
            </AlertDescription>
          </Alert>
        )}

        <Separator />

        {!canReorder ? (
          <p className="text-xs text-muted-foreground">
            This item is {item.status_label.toLowerCase()} — its position is no longer editable.
          </p>
        ) : (
          <div className="space-y-3">
            <div className="grid grid-cols-2 gap-3">
              <div className="space-y-1.5">
                <Label className="text-xs">Priority</Label>
                <Select
                  value={priority}
                  onValueChange={(v) => setPriority(v as QueuePriority)}
                >
                  <SelectTrigger className="h-9">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {PRIORITIES.map((p) => (
                      <SelectItem key={p} value={p} className="capitalize">
                        {p}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-1.5">
                <Label className="text-xs">Reason</Label>
                <Input
                  value={reason}
                  onChange={(e) => setReason(e.target.value)}
                  placeholder="Why this changes"
                  className="h-9"
                />
              </div>
            </div>

            <div className="flex gap-2">
              <Button
                size="sm"
                className="h-8 text-xs"
                disabled={reason.trim().length === 0 || prioritise.isPending}
                onClick={() =>
                  prioritise.mutate(
                    { itemId: item.id, priority, reason: reason.trim() },
                    {
                      onSuccess: () => {
                        toast({ title: 'Priority updated.' });
                        setReason('');
                        onOpenChange(false);
                      },
                      onError: () =>
                        toast({
                          title: 'The priority could not be changed.',
                          variant: 'destructive',
                        }),
                    },
                  )
                }
              >
                Set priority
              </Button>

              <Button
                size="sm"
                variant="outline"
                className="h-8 text-xs"
                disabled={defer.isPending}
                onClick={() =>
                  defer.mutate(
                    { itemId: item.id, reason: reason.trim() || undefined },
                    {
                      onSuccess: () => {
                        toast({ title: 'Item deferred.' });
                        onOpenChange(false);
                      },
                      onError: () =>
                        toast({ title: 'The item could not be deferred.', variant: 'destructive' }),
                    },
                  )
                }
              >
                Defer
              </Button>
            </div>
          </div>
        )}
      </div>
    </PageDrawer>
  );
}

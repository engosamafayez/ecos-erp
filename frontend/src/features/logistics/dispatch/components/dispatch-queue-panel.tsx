import { AlertTriangle, ListOrdered, PlayCircle } from 'lucide-react';

import { useToast } from '@/components/ds/use-toast';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';

import { useBuildQueue, useClaimNext, useQueue } from '../hooks/use-dispatch-ops';
import type { QueueItem } from '../types/dispatch-ops';
import { PriorityBadge, QueueStatusBadge } from './dispatch-status-badges';

function QueueSkeleton() {
  return (
    <div className="space-y-2">
      {Array.from({ length: 5 }).map((_, i) => (
        <Skeleton key={i} className="h-12 w-full" />
      ))}
    </div>
  );
}

/**
 * The dispatch queue for one board.
 *
 * Rank is server-computed and never re-sorted here — the order a dispatcher
 * sees has to be the order the claim endpoint will hand out, or claiming the
 * "top" item stops meaning anything.
 */
export function DispatchQueuePanel({
  boardId,
  sessionId,
  onSelectItem,
}: {
  boardId: string | null;
  sessionId: string | null;
  onSelectItem: (item: QueueItem) => void;
}) {
  const { toast } = useToast();
  const { data: items, isLoading } = useQueue(boardId);
  const build = useBuildQueue();
  const claimNext = useClaimNext();

  if (boardId === null) {
    return (
      <div className="rounded-lg border bg-card py-16 text-center">
        <ListOrdered className="mx-auto mb-3 size-10 text-muted-foreground/20" />
        <p className="text-sm font-medium">Pick a board to work</p>
        <p className="mt-1 text-xs text-muted-foreground">
          Boards are planned on the Dispatch board surface. The queue is built from one.
        </p>
      </div>
    );
  }

  if (isLoading) return <QueueSkeleton />;

  const rows = items ?? [];

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center gap-2">
        <Button
          size="sm"
          variant="outline"
          className="h-8 text-xs"
          disabled={build.isPending}
          onClick={() =>
            build.mutate(boardId, {
              onSuccess: (r) =>
                toast({
                  title:
                    r.added === 0
                      ? 'Nothing new to queue.'
                      : `${r.added} trip${r.added === 1 ? '' : 's'} queued.`,
                }),
              onError: () => toast({ title: 'The queue could not be built.', variant: 'destructive' }),
            })
          }
        >
          Build queue
        </Button>

        <Button
          size="sm"
          className="h-8 text-xs"
          // Claiming needs an open session — that is where the lock is held.
          disabled={sessionId === null || claimNext.isPending}
          onClick={() =>
            sessionId &&
            claimNext.mutate(sessionId, {
              onSuccess: (item) =>
                item === null
                  ? toast({ title: 'The queue is empty.' })
                  : onSelectItem(item),
              onError: () => toast({ title: 'Nothing could be claimed.', variant: 'destructive' }),
            })
          }
        >
          <PlayCircle className="mr-1 size-3.5" />
          Claim next
        </Button>

        {sessionId === null && (
          <span className="text-xs text-muted-foreground">Open a session to claim work.</span>
        )}
      </div>

      {rows.length === 0 ? (
        <div className="rounded-lg border bg-card py-16 text-center">
          <ListOrdered className="mx-auto mb-3 size-10 text-muted-foreground/20" />
          <p className="text-sm font-medium">The queue is empty</p>
          <p className="mt-1 text-xs text-muted-foreground">
            Build it from the board&apos;s released trips.
          </p>
        </div>
      ) : (
        <div className="overflow-x-auto rounded-lg border bg-card">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b bg-muted/60 text-left text-xs uppercase tracking-wide text-muted-foreground">
                <th className="h-10 w-12 px-3 text-right font-medium">#</th>
                <th className="h-10 px-3 font-medium">Trip</th>
                <th className="h-10 px-3 font-medium">Status</th>
                <th className="h-10 px-3 font-medium">Priority</th>
                <th className="h-10 px-3 text-right font-medium">Waiting</th>
                <th className="h-10 px-3 text-right font-medium">Attempts</th>
                <th className="h-10 px-3 font-medium">Claimed by</th>
              </tr>
            </thead>
            <tbody className="divide-y">
              {rows.map((item, index) => (
                <tr
                  key={item.id}
                  className="cursor-pointer hover:bg-muted/40"
                  onClick={() => onSelectItem(item)}
                >
                  <td className="px-3 py-2.5 text-right tabular-nums text-muted-foreground">
                    {index + 1}
                  </td>
                  <td className="px-3 py-2.5">
                    <div className="flex items-center gap-1.5">
                      <span className="font-medium">{item.trip_number ?? '—'}</span>
                      {/* Repeated failure needs a human, not another retry. */}
                      {item.is_stuck && (
                        <AlertTriangle
                          className="size-3.5 text-amber-600"
                          aria-label="Repeatedly failed"
                        />
                      )}
                    </div>
                    {item.last_failure_reason && (
                      <div className="text-xs text-muted-foreground">
                        {item.last_failure_reason}
                      </div>
                    )}
                  </td>
                  <td className="px-3 py-2.5">
                    <QueueStatusBadge status={item.status} />
                  </td>
                  <td className="px-3 py-2.5">
                    <PriorityBadge priority={item.priority} />
                    {item.priority_reason && (
                      <div className="mt-0.5 text-[11px] text-muted-foreground">
                        {item.priority_reason}
                      </div>
                    )}
                  </td>
                  <td className="px-3 py-2.5 text-right tabular-nums">
                    {item.waiting_minutes} min
                  </td>
                  <td className="px-3 py-2.5 text-right tabular-nums">{item.attempt_count}</td>
                  <td className="px-3 py-2.5 text-xs text-muted-foreground">
                    {item.claimed_by ?? '—'}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}

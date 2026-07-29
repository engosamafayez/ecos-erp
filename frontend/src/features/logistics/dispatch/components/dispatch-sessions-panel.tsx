import { useState } from 'react';
import { Lock, Unlock } from 'lucide-react';

import { useToast } from '@/components/ds/use-toast';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';

import {
  useBreakLock,
  useCloseSession,
  useHeldLocks,
  useSessions,
} from '../hooks/use-dispatch-ops';
import { SessionStatusBadge } from './dispatch-status-badges';

function Panel({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div className="rounded-lg border bg-card p-4">
      <h3 className="mb-3 text-sm font-medium">{title}</h3>
      {children}
    </div>
  );
}

function HeldLocks() {
  const { toast } = useToast();
  const { data: locks } = useHeldLocks();
  const [breaking, setBreaking] = useState<string | null>(null);
  const [reason, setReason] = useState('');
  const breakLock = useBreakLock();

  if (!locks || locks.length === 0) {
    return <p className="py-8 text-center text-sm text-muted-foreground">Nothing is held.</p>;
  }

  return (
    <div className="space-y-2">
      {locks.map((lock) => (
        <div key={lock.id} className="rounded-md border p-2.5">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <div className="flex items-center gap-2 text-xs">
              <Lock className="size-3.5 text-muted-foreground" />
              <span className="font-medium">{lock.resource}</span>
              <span className="text-muted-foreground">held by {lock.held_by ?? 'unknown'}</span>
              {!lock.is_effective && (
                <Badge variant="outline" className="text-[10px]">
                  Lapsed
                </Badge>
              )}
            </div>
            <div className="flex items-center gap-2">
              {/* Every lock expires on its own; the countdown is the safety net
                  that keeps a crashed session from freezing a vehicle. */}
              <span className="tabular-nums text-xs text-muted-foreground">
                {Math.max(0, Math.round(lock.remaining_seconds / 60))} min left
              </span>
              <Button
                size="sm"
                variant="ghost"
                className="h-7 text-xs"
                onClick={() => setBreaking(breaking === lock.id ? null : lock.id)}
              >
                <Unlock className="mr-1 size-3.5" />
                Break
              </Button>
            </div>
          </div>

          {breaking === lock.id && (
            <div className="mt-2 flex flex-wrap items-center gap-2 border-t pt-2">
              <Input
                value={reason}
                onChange={(e) => setReason(e.target.value)}
                placeholder="Why this is being taken"
                className="h-8 max-w-xs text-xs"
              />
              <Button
                size="sm"
                variant="destructive"
                className="h-8 text-xs"
                // Taking a resource from a colleague mid-decision is always audited.
                disabled={reason.trim().length === 0 || breakLock.isPending}
                onClick={() =>
                  breakLock.mutate(
                    { id: lock.id, reason: reason.trim() },
                    {
                      onSuccess: () => {
                        toast({ title: 'Lock broken and recorded.' });
                        setBreaking(null);
                        setReason('');
                      },
                      onError: () =>
                        toast({ title: 'The lock could not be broken.', variant: 'destructive' }),
                    },
                  )
                }
              >
                Confirm
              </Button>
            </div>
          )}
        </div>
      ))}
    </div>
  );
}

export function DispatchSessionsPanel({ activeSessionId }: { activeSessionId: string | null }) {
  const { toast } = useToast();
  const { data, isLoading } = useSessions();
  const close = useCloseSession();

  if (isLoading) return <Skeleton className="h-64 w-full" />;

  const sessions = data?.data ?? [];

  return (
    <div className="space-y-4">
      <Panel title="Sessions">
        {sessions.length === 0 ? (
          <p className="py-8 text-center text-sm text-muted-foreground">No sessions yet.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-xs">
              <thead>
                <tr className="border-b text-left text-muted-foreground">
                  <th className="py-1.5 font-normal">Operator</th>
                  <th className="py-1.5 font-normal">Status</th>
                  <th className="py-1.5 font-normal">Mode</th>
                  <th className="py-1.5 text-right font-normal">Assigned</th>
                  <th className="py-1.5 text-right font-normal">Released</th>
                  <th className="py-1.5 text-right font-normal">Conflicts</th>
                  <th className="py-1.5 text-right font-normal">Duration</th>
                  <th className="py-1.5" />
                </tr>
              </thead>
              <tbody className="divide-y">
                {sessions.map((session) => (
                  <tr key={session.id} className={session.id === activeSessionId ? 'bg-muted/40' : ''}>
                    <td className="py-2">
                      {session.operator_name ?? '—'}
                      {/* An idle open session is holding locks nobody is using. */}
                      {session.is_idle && (
                        <Badge variant="outline" className="ml-1.5 border-amber-500 text-[10px]">
                          Idle
                        </Badge>
                      )}
                    </td>
                    <td className="py-2">
                      <SessionStatusBadge status={session.status} />
                    </td>
                    <td className="py-2 capitalize text-muted-foreground">{session.mode}</td>
                    <td className="py-2 text-right tabular-nums">{session.assigned_count}</td>
                    <td className="py-2 text-right tabular-nums">{session.released_count}</td>
                    <td className="py-2 text-right tabular-nums">{session.conflict_count}</td>
                    <td className="py-2 text-right tabular-nums text-muted-foreground">
                      {session.duration_minutes !== null ? `${session.duration_minutes}m` : '—'}
                    </td>
                    <td className="py-2 text-right">
                      {session.is_active && (
                        <Button
                          size="sm"
                          variant="ghost"
                          className="h-7 text-xs"
                          disabled={close.isPending}
                          onClick={() =>
                            close.mutate(
                              { id: session.id },
                              {
                                onSuccess: () =>
                                  toast({ title: 'Session closed and locks released.' }),
                                onError: () =>
                                  toast({
                                    title: 'The session could not be closed.',
                                    variant: 'destructive',
                                  }),
                              },
                            )
                          }
                        >
                          Close
                        </Button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Panel>

      <Panel title="Held resources">
        <HeldLocks />
      </Panel>
    </div>
  );
}

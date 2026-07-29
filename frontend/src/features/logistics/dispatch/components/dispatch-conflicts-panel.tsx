import { useState } from 'react';
import { CheckCircle2, ShieldAlert, ShieldCheck } from 'lucide-react';

import { useToast } from '@/components/ds/use-toast';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';

import {
  useApproveReview,
  useAssignmentHealth,
  useConflicts,
  useOverrideConflict,
  usePendingReviews,
  useRejectReview,
  useResolveConflict,
} from '../hooks/use-dispatch-ops';
import type { DispatchConflict } from '../types/dispatch-ops';
import { AuthorityBadge, SeverityIcon } from './dispatch-status-badges';

function ConflictRow({ conflict }: { conflict: DispatchConflict }) {
  const { toast } = useToast();
  const [reason, setReason] = useState('');
  const [expanded, setExpanded] = useState(false);

  const resolve = useResolveConflict();
  const override = useOverrideConflict();

  // Dispatch may only override its own facts. Everything else is fixed where it
  // is owned — offering the button would be a lie about what will happen.
  const canOverride = conflict.authority === 'dispatch';

  return (
    <div className="rounded-md border p-3">
      <button
        type="button"
        className="flex w-full items-start gap-2 text-left"
        onClick={() => setExpanded((v) => !v)}
      >
        <SeverityIcon severity={conflict.severity} />
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-1.5">
            <span className="text-sm font-medium">{conflict.conflict_label}</span>
            <AuthorityBadge authority={conflict.authority} />
            {conflict.blocks_release && (
              <Badge variant="destructive" className="text-[10px]">
                Blocks release
              </Badge>
            )}
          </div>
          <p className="mt-0.5 text-xs text-muted-foreground">{conflict.description}</p>
          <p className="mt-0.5 text-[11px] text-muted-foreground">
            Open {conflict.age_minutes} min · {conflict.status_label}
          </p>
        </div>
      </button>

      {expanded && (
        <div className="mt-3 space-y-2 border-t pt-3">
          {!canOverride && (
            <p className="text-[11px] text-muted-foreground">
              {/* Named plainly so the dispatcher goes to the right module. */}
              This fact belongs to {conflict.authority}. Dispatch can record that it was handled,
              but the underlying problem has to be fixed there.
            </p>
          )}

          <Input
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            placeholder="What was done about it"
            className="h-8 text-xs"
          />

          <div className="flex gap-2">
            <Button
              size="sm"
              variant="outline"
              className="h-8 text-xs"
              disabled={resolve.isPending}
              onClick={() =>
                resolve.mutate(
                  { id: conflict.id, resolution: 'resolved', reason: reason.trim() || undefined },
                  {
                    onSuccess: () => toast({ title: 'Conflict closed.' }),
                    onError: () =>
                      toast({ title: 'The conflict could not be closed.', variant: 'destructive' }),
                  },
                )
              }
            >
              <CheckCircle2 className="mr-1 size-3.5" />
              Resolved
            </Button>

            {canOverride && (
              <Button
                size="sm"
                variant="destructive"
                className="h-8 text-xs"
                disabled={reason.trim().length === 0 || override.isPending}
                onClick={() =>
                  override.mutate(
                    { id: conflict.id, reason: reason.trim() },
                    {
                      onSuccess: () => toast({ title: 'Override recorded.' }),
                      onError: () =>
                        toast({ title: 'The override was refused.', variant: 'destructive' }),
                    },
                  )
                }
              >
                Override
              </Button>
            )}
          </div>
        </div>
      )}
    </div>
  );
}

function PendingReviews() {
  const { toast } = useToast();
  const { data: reviews } = usePendingReviews();
  const [reasons, setReasons] = useState<Record<string, string>>({});

  const approve = useApproveReview();
  const reject = useRejectReview();

  if (!reviews || reviews.length === 0) {
    return (
      <p className="py-8 text-center text-sm text-muted-foreground">
        No assignment is waiting on a human.
      </p>
    );
  }

  return (
    <div className="space-y-2">
      {reviews.map((review) => {
        const reason = reasons[review.id] ?? '';

        return (
          <div key={review.id} className="rounded-md border p-3">
            <div className="flex items-start gap-2">
              <ShieldAlert className="mt-0.5 size-4 shrink-0 text-amber-600" />
              <div className="min-w-0 flex-1">
                <p className="text-sm font-medium">{review.trigger_reason ?? review.trigger}</p>
                <p className="mt-0.5 text-[11px] text-muted-foreground">
                  Waiting {review.waiting_minutes} min
                </p>
              </div>
            </div>

            <div className="mt-2.5 flex flex-wrap items-center gap-2">
              <Input
                value={reason}
                onChange={(e) => setReasons((r) => ({ ...r, [review.id]: e.target.value }))}
                placeholder="Decision note"
                className="h-8 max-w-xs text-xs"
              />
              <Button
                size="sm"
                className="h-8 text-xs"
                disabled={approve.isPending}
                onClick={() =>
                  approve.mutate(
                    { id: review.id, reason: reason.trim() || undefined },
                    {
                      onSuccess: () => toast({ title: 'Approved.' }),
                      onError: () =>
                        toast({ title: 'The approval failed.', variant: 'destructive' }),
                    },
                  )
                }
              >
                <ShieldCheck className="mr-1 size-3.5" />
                Approve
              </Button>
              <Button
                size="sm"
                variant="outline"
                className="h-8 text-xs"
                // A rejection without a reason is unactionable for whoever asked.
                disabled={reason.trim().length === 0 || reject.isPending}
                onClick={() =>
                  reject.mutate(
                    { id: review.id, reason: reason.trim() },
                    {
                      onSuccess: () => toast({ title: 'Rejected.' }),
                      onError: () =>
                        toast({ title: 'The rejection failed.', variant: 'destructive' }),
                    },
                  )
                }
              >
                Reject
              </Button>
            </div>
          </div>
        );
      })}
    </div>
  );
}

function Panel({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div className="rounded-lg border bg-card p-4">
      <h3 className="mb-3 text-sm font-medium">{title}</h3>
      {children}
    </div>
  );
}

/**
 * Everything that needs a human, ordered by who can actually act.
 */
export function DispatchConflictsPanel() {
  const { data: conflicts, isLoading } = useConflicts(true);
  const { data: health } = useAssignmentHealth();

  if (isLoading) return <Skeleton className="h-64 w-full" />;

  const items = conflicts ?? [];
  const byAuthority = health?.conflicts_by_authority ?? {};
  const elsewhere = Object.entries(byAuthority).filter(([a]) => a !== 'dispatch');

  return (
    <div className="space-y-4">
      {elsewhere.length > 0 && (
        <Alert>
          <ShieldAlert className="size-4" />
          <AlertDescription className="text-xs">
            Owned by another module —{' '}
            {elsewhere.map(([authority, count]) => `${authority}: ${count}`).join(', ')}. Dispatch
            cannot override these; they clear when the owning module changes the underlying fact.
          </AlertDescription>
        </Alert>
      )}

      <Panel title={`Conflicts (${items.length})`}>
        {items.length === 0 ? (
          <p className="py-8 text-center text-sm text-muted-foreground">Nothing outstanding.</p>
        ) : (
          <div className="space-y-2">
            {items.map((conflict) => (
              <ConflictRow key={conflict.id} conflict={conflict} />
            ))}
          </div>
        )}
      </Panel>

      <Panel title={`Awaiting review (${health?.pending_reviews ?? 0})`}>
        <PendingReviews />
      </Panel>
    </div>
  );
}

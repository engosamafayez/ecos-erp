import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ArrowDownCircle, ArrowUpCircle, Check, Loader2, Paperclip, Wallet, X } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { useToast } from '@/components/ds/use-toast';
import { useFormatter } from '@/hooks/use-formatter';
import { cn } from '@/lib/utils';
import { useReviewDriverMovement } from '../hooks/use-driver-settlement';
import { driverSettlementService } from '../services/driver-settlement-service';
import type {
  DaySettlementMovement,
  DaySettlementMovements,
  MovementCategory,
  MovementStatus,
} from '../types/driver-settlement';

const STATUS_TONE: Record<MovementStatus, string> = {
  pending: 'bg-amber-100 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300',
  approved: 'bg-green-100 text-green-700 dark:bg-green-950/40 dark:text-green-300',
  rejected: 'bg-red-100 text-red-700 dark:bg-red-950/40 dark:text-red-300',
  settled: 'bg-blue-100 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300',
};

/**
 * OPERATIONS review of a driver's trip cash movements — TASK-OPERATIONS-DRIVER-TRIP-MOVEMENT-APPROVAL-001 §7–§10.
 *
 * Lists the movements of the driver's ONE active custody with the canonical direction (cash in/out),
 * amount and status. For a Pending movement an authorized operator can Approve or Reject (reason
 * required); evidence is fetched through the secure, tenant-scoped receipt endpoint (never a raw
 * path). All decisions go through the canonical review action; this never mutates status directly.
 */
export function DriverMovementReview({
  movements,
  assignmentId,
  date,
  canReview,
}: {
  movements: DaySettlementMovements;
  assignmentId: number | null;
  date: string;
  canReview: boolean;
}) {
  const { t } = useTranslation('logistics');
  const { money } = useFormatter();
  const { toast } = useToast();
  const { approve, reject } = useReviewDriverMovement(assignmentId, date);

  const [rejecting, setRejecting] = useState<DaySettlementMovement | null>(null);
  const [reason, setReason] = useState('');
  const [receiptLoadingId, setReceiptLoadingId] = useState<string | null>(null);

  const categoryLabel = (c: MovementCategory): string =>
    t(($) => $.driverSettlement.movements.category[c as 'fuel']);

  async function viewReceipt(movementId: string) {
    setReceiptLoadingId(movementId);
    try {
      const blob = await driverSettlementService.movementReceipt(movementId);
      const url = URL.createObjectURL(blob);
      window.open(url, '_blank', 'noopener,noreferrer');
      // Revoke after a beat so the new tab has time to load it.
      setTimeout(() => URL.revokeObjectURL(url), 60_000);
    } catch {
      toast({ title: t(($) => $.driverSettlement.movements.receiptFailed), variant: 'destructive' });
    } finally {
      setReceiptLoadingId(null);
    }
  }

  function onApprove(m: DaySettlementMovement) {
    approve.mutate(
      { movementId: m.id },
      {
        onSuccess: () => toast({ title: t(($) => $.driverSettlement.movements.approved) }),
        onError: () => toast({ title: t(($) => $.driverSettlement.movements.reviewFailed), variant: 'destructive' }),
      },
    );
  }

  function submitReject() {
    if (rejecting === null || reason.trim() === '') return;
    reject.mutate(
      { movementId: rejecting.id, reason: reason.trim() },
      {
        onSuccess: () => {
          toast({ title: t(($) => $.driverSettlement.movements.rejected) });
          setRejecting(null);
          setReason('');
        },
        onError: () => toast({ title: t(($) => $.driverSettlement.movements.reviewFailed), variant: 'destructive' }),
      },
    );
  }

  return (
    <section>
      <div className="mb-2 flex items-center justify-between">
        <p className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
          <Wallet className="h-3.5 w-3.5" aria-hidden />
          {t(($) => $.driverSettlement.movements.title)}
        </p>
        {movements.pending_count > 0 ? (
          <Badge variant="secondary" className="bg-amber-100 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300">
            {t(($) => $.driverSettlement.movements.pendingCount, { count: movements.pending_count })}
          </Badge>
        ) : null}
      </div>

      {movements.items.length === 0 ? (
        <p className="rounded-md border border-dashed p-4 text-center text-xs text-muted-foreground">
          {t(($) => $.driverSettlement.movements.empty)}
        </p>
      ) : (
        <div className="space-y-2">
          {movements.items.map((m) => {
            const cashIn = m.direction === 'cash_in';
            const isPending = m.status === 'pending';
            return (
              <div key={m.id} className="rounded-lg border bg-card p-3">
                <div className="flex items-start justify-between gap-2">
                  <div className="flex min-w-0 items-center gap-2">
                    {cashIn ? (
                      <ArrowDownCircle className="h-5 w-5 shrink-0 text-emerald-600" aria-hidden />
                    ) : (
                      <ArrowUpCircle className="h-5 w-5 shrink-0 text-destructive" aria-hidden />
                    )}
                    <div className="min-w-0">
                      <p className="text-sm font-medium">{categoryLabel(m.category)}</p>
                      <p className="text-[11px] text-muted-foreground">
                        {t(($) => (cashIn ? $.driverSettlement.movements.direction.cash_in : $.driverSettlement.movements.direction.cash_out))}
                      </p>
                    </div>
                  </div>
                  <div className="shrink-0 text-end">
                    <p className={cn('text-sm font-semibold tabular-nums', cashIn ? 'text-emerald-600' : 'text-destructive')}>
                      {cashIn ? '+' : '-'}
                      {money(m.amount)}
                    </p>
                    <Badge variant="secondary" className={cn('mt-1 text-[10px]', STATUS_TONE[m.status])}>
                      {t(($) => $.driverSettlement.movements.status[m.status as 'pending'])}
                    </Badge>
                  </div>
                </div>

                {m.note ? <p className="mt-2 text-xs text-muted-foreground">{m.note}</p> : null}

                <div className="mt-2 flex flex-wrap items-center gap-2">
                  {m.has_receipt ? (
                    <Button
                      variant="outline"
                      size="sm"
                      className="h-7 gap-1 text-xs"
                      disabled={receiptLoadingId === m.id}
                      onClick={() => void viewReceipt(m.id)}
                    >
                      {receiptLoadingId === m.id ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Paperclip className="h-3.5 w-3.5" />}
                      {t(($) => $.driverSettlement.movements.viewReceipt)}
                    </Button>
                  ) : null}

                  {canReview && isPending ? (
                    <div className="ms-auto flex gap-2">
                      <Button
                        variant="outline"
                        size="sm"
                        className="h-7 gap-1 text-xs text-destructive"
                        disabled={reject.isPending || approve.isPending}
                        onClick={() => {
                          setRejecting(m);
                          setReason('');
                        }}
                      >
                        <X className="h-3.5 w-3.5" />
                        {t(($) => $.driverSettlement.movements.reject)}
                      </Button>
                      <Button
                        size="sm"
                        className="h-7 gap-1 text-xs"
                        disabled={approve.isPending || reject.isPending}
                        onClick={() => onApprove(m)}
                      >
                        <Check className="h-3.5 w-3.5" />
                        {t(($) => $.driverSettlement.movements.approve)}
                      </Button>
                    </div>
                  ) : null}
                </div>
              </div>
            );
          })}
        </div>
      )}

      {/* Reject reason dialog (§10 — a reason is required and the record is preserved). */}
      <Dialog open={rejecting !== null} onOpenChange={(o) => { if (!o) { setRejecting(null); setReason(''); } }}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t(($) => $.driverSettlement.movements.rejectTitle)}</DialogTitle>
            <DialogDescription>{t(($) => $.driverSettlement.movements.rejectHint)}</DialogDescription>
          </DialogHeader>
          <textarea
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            rows={3}
            maxLength={500}
            className="w-full rounded-md border bg-background px-3 py-2 text-sm outline-none focus-visible:ring-1 focus-visible:ring-ring"
            placeholder={t(($) => $.driverSettlement.movements.rejectPlaceholder)}
          />
          <DialogFooter className="gap-2">
            <Button variant="outline" onClick={() => { setRejecting(null); setReason(''); }}>
              {t(($) => $.driverSettlement.movements.cancel)}
            </Button>
            <Button
              variant="destructive"
              disabled={reason.trim() === '' || reject.isPending}
              onClick={submitReject}
            >
              {reject.isPending ? t(($) => $.driverSettlement.movements.rejecting) : t(($) => $.driverSettlement.movements.confirmReject)}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </section>
  );
}

import { useState } from 'react';
import {
  Activity,
  AlertTriangle,
  ArrowRightCircle,
  Box,
  CheckCircle2,
  Loader2,
  RotateCcw,
  XCircle,
} from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  useOrderWorkflowConfirm,
  useOrderWorkflowMarkAwaitingStock,
  useOrderWorkflowCancel,
  useOrderWorkflowMoveToReview,
  useOrderWorkflowReturnToPending,
  useOrderWorkflowRevertToConfirmed,
  useOrderWorkflowReturnToProcessing,
  useOrderWorkflowMoveToPreparation,
} from '@/features/orders/hooks/use-orders';
import type { Order, OrderLine } from '@/features/orders/types/order';

// ── Status badge ──────────────────────────────────────────────────────────────

const STATUS_COLORS: Record<string, string> = {
  pending:          'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
  awaiting_payment: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-400',
  processing:       'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-400',
  awaiting_stock:   'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-400',
  confirmed:        'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-400',
  preparing:        'bg-violet-100 text-violet-700 dark:bg-violet-900/40 dark:text-violet-400',
  out_for_delivery: 'bg-cyan-100 text-cyan-700 dark:bg-cyan-900/40 dark:text-cyan-400',
  delivered:        'bg-teal-100 text-teal-700 dark:bg-teal-900/40 dark:text-teal-400',
  completed:        'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400',
  cancelled:        'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-400',
  review:           'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-400',
  rescheduled:      'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-400',
  returned:         'bg-rose-100 text-rose-700 dark:bg-rose-900/40 dark:text-rose-400',
};

// ── Action definitions ─────────────────────────────────────────────────────────

type ActionDef = {
  key: string;
  label: string;
  icon: React.ComponentType<{ className?: string }>;
  variant: 'default' | 'outline' | 'destructive';
  requiresReason?: boolean;
  reasonPlaceholder?: string;
};

const confirm_action: ActionDef       = { key: 'confirm',           label: 'Confirm Order',          icon: CheckCircle2,     variant: 'default' };
const cancel_action: ActionDef        = { key: 'cancel',            label: 'Cancel Order',           icon: XCircle,          variant: 'destructive', requiresReason: true, reasonPlaceholder: 'Reason for cancellation…' };
const return_pending: ActionDef       = { key: 'return_to_pending', label: 'Return to Pending',      icon: RotateCcw,        variant: 'outline' };
const awaiting_stock: ActionDef       = { key: 'awaiting_stock',    label: 'Mark Awaiting Stock',    icon: Box,              variant: 'outline' };
const move_to_review: ActionDef       = { key: 'review',            label: 'Move to Review',         icon: Activity,         variant: 'outline', requiresReason: true, reasonPlaceholder: 'Describe the issue…' };
const revert_confirmed: ActionDef     = { key: 'revert_confirmed',  label: 'Return to Confirmed',    icon: RotateCcw,        variant: 'outline' };
const return_processing: ActionDef    = { key: 'return_processing', label: 'Return to Processing',   icon: ArrowRightCircle, variant: 'outline' };
const move_to_preparation: ActionDef  = { key: 'prepare',           label: 'Move to Preparing',      icon: ArrowRightCircle, variant: 'default' };

// Per-status action map for the edit form (spec: TASK-ORDER-WORKFLOW-ACTIONS-001)
const EDIT_WORKFLOW_ACTIONS: Record<string, ActionDef[]> = {
  pending:          [confirm_action, cancel_action],
  awaiting_payment: [confirm_action, cancel_action],
  confirmed:        [return_pending, awaiting_stock, move_to_review, cancel_action],
  awaiting_stock:   [revert_confirmed, cancel_action],
  review:           [revert_confirmed, awaiting_stock, cancel_action],
  // processing: computed dynamically — see getActions()
  preparing:        [return_processing],
  out_for_delivery: [],
  delivered:        [],
  completed:        [],
  cancelled:        [],
  rescheduled:      [],
  returned:         [],
};

// ── Helper: is preparation started? ──────────────────────────────────────────

function prepStarted(lines: OrderLine[] | undefined): boolean {
  if (!lines || lines.length === 0) return false;
  return lines.some((l) => (l.prepared_qty ?? 0) > 0);
}

function getActions(order: Order): ActionDef[] {
  if (order.status === 'processing') {
    if (prepStarted(order.lines)) {
      // Preparation in progress — no backward transition allowed
      return [move_to_preparation, move_to_review, cancel_action];
    }
    return [revert_confirmed, move_to_review, awaiting_stock, cancel_action];
  }
  return EDIT_WORKFLOW_ACTIONS[order.status] ?? [];
}

// ── Component ─────────────────────────────────────────────────────────────────

type Props = {
  order: Order;
  onSuccess?: () => void;
};

export function OrderWorkflowActionsPanel({ order, onSuccess }: Props) {
  const confirm          = useOrderWorkflowConfirm();
  const markAwaitingStock = useOrderWorkflowMarkAwaitingStock();
  const cancelOrder      = useOrderWorkflowCancel();
  const moveToReview     = useOrderWorkflowMoveToReview();
  const returnToPending  = useOrderWorkflowReturnToPending();
  const revertConfirmed  = useOrderWorkflowRevertToConfirmed();
  const returnProcessing = useOrderWorkflowReturnToProcessing();
  const moveToPrep       = useOrderWorkflowMoveToPreparation();

  const [activeReason, setActiveReason] = useState<string | null>(null);
  const [reasonText, setReasonText] = useState('');

  const actions = getActions(order);
  const isPending = [
    confirm, markAwaitingStock, cancelOrder, moveToReview,
    returnToPending, revertConfirmed, returnProcessing, moveToPrep,
  ].some((m) => m.isPending);

  function handleAction(key: string) {
    const def = [...Object.values(EDIT_WORKFLOW_ACTIONS).flat(),
                  revert_confirmed, return_processing, move_to_preparation].find(a => a.key === key);

    if (def?.requiresReason && activeReason !== key) {
      setActiveReason(key);
      setReasonText('');
      return;
    }

    const reason = reasonText.trim() || undefined;
    const done = () => { setActiveReason(null); setReasonText(''); onSuccess?.(); };

    switch (key) {
      case 'confirm':           confirm.mutate(order.id, { onSuccess: done }); break;
      case 'cancel':            cancelOrder.mutate({ id: order.id, reason }, { onSuccess: done }); break;
      case 'return_to_pending': returnToPending.mutate(order.id, { onSuccess: done }); break;
      case 'awaiting_stock':    markAwaitingStock.mutate({ id: order.id }, { onSuccess: done }); break;
      case 'review':            moveToReview.mutate({ id: order.id, reason }, { onSuccess: done }); break;
      case 'revert_confirmed':  revertConfirmed.mutate(order.id, { onSuccess: done }); break;
      case 'return_processing': returnProcessing.mutate(order.id, { onSuccess: done }); break;
      case 'prepare':           moveToPrep.mutate(order.id, { onSuccess: done }); break;
    }
  }

  // Only Completed is terminal — Cancelled orders are recoverable via the V2 workflow.
  const isTerminal = order.status === 'completed';

  return (
    <div className="rounded-lg border bg-card">
      {/* Header */}
      <div className="flex items-center justify-between border-b px-3 py-2.5">
        <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
          Order Status
        </span>
        <span
          className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${STATUS_COLORS[order.status] ?? 'bg-muted text-muted-foreground'}`}
        >
          {order.status_label ?? order.status}
        </span>
      </div>

      {/* Actions */}
      <div className="p-3">
        {isTerminal ? (
          <p className="text-xs text-muted-foreground">
            This order is financially closed and cannot be modified.
          </p>
        ) : actions.length === 0 ? (
          <p className="text-xs text-muted-foreground">
            No workflow actions available. Use the Order Drawer for advanced actions.
          </p>
        ) : (
          <div className="flex flex-col gap-1.5">
            {order.status === 'processing' && prepStarted(order.lines) && (
              <div className="mb-1 flex items-center gap-1.5 rounded-md border border-amber-200 bg-amber-50 px-2.5 py-1.5 text-xs text-amber-700 dark:border-amber-800/50 dark:bg-amber-950/20 dark:text-amber-400">
                <AlertTriangle className="size-3 shrink-0" />
                Preparation started — cannot return to Confirmed
              </div>
            )}
            {actions.map((action) => {
              const isActive = activeReason === action.key;
              return (
                <div key={action.key}>
                  <Button
                    type="button"
                    variant={action.variant}
                    size="sm"
                    className="w-full justify-start gap-2 text-xs"
                    disabled={isPending}
                    onClick={() => {
                      if (isActive) {
                        setActiveReason(null);
                        setReasonText('');
                      } else {
                        handleAction(action.key);
                      }
                    }}
                  >
                    {isPending && activeReason === action.key ? (
                      <Loader2 className="size-3.5 animate-spin" />
                    ) : (
                      <action.icon className="size-3.5" />
                    )}
                    {action.label}
                  </Button>

                  {/* Inline reason input */}
                  {isActive && action.requiresReason && (
                    <div className="mt-1.5 flex flex-col gap-1.5 rounded-md border bg-muted/30 p-2">
                      <Input
                        autoFocus
                        placeholder={action.reasonPlaceholder ?? 'Enter reason…'}
                        value={reasonText}
                        onChange={(e) => setReasonText(e.target.value)}
                        className="h-7 text-xs"
                        onKeyDown={(e) => {
                          if (e.key === 'Enter') handleAction(action.key);
                          if (e.key === 'Escape') { setActiveReason(null); setReasonText(''); }
                        }}
                      />
                      <div className="flex gap-1.5">
                        <Button
                          type="button"
                          size="sm"
                          variant={action.variant}
                          className="h-6 flex-1 text-xs"
                          disabled={isPending}
                          onClick={() => handleAction(action.key)}
                        >
                          {isPending ? <Loader2 className="size-3 animate-spin" /> : 'Confirm'}
                        </Button>
                        <Button
                          type="button"
                          size="sm"
                          variant="ghost"
                          className="h-6 text-xs"
                          onClick={() => { setActiveReason(null); setReasonText(''); }}
                        >
                          Cancel
                        </Button>
                      </div>
                    </div>
                  )}
                </div>
              );
            })}
          </div>
        )}
      </div>
    </div>
  );
}

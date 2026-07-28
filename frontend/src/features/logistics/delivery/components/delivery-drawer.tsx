import { useState } from 'react';
import {
  AlertTriangle,
  Ban,
  CheckCircle,
  Clock,
  FileCheck2,
  Loader2,
  MapPin,
  RotateCcw,
  ShieldCheck,
  Undo2,
  Wallet,
  XCircle,
} from 'lucide-react';

import { PageDrawer } from '@/components/page/drawer/page-drawer';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/ecos-select';
import { Separator } from '@/components/ui/separator';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { useToast } from '@/components/ds/use-toast';

import {
  useCancelDelivery,
  useDelivery,
  useDeliveryOptions,
  useDeliveryTimeline,
  useFailAttempt,
  useMarkAddressCorrected,
  useRetryDelivery,
  useValidatePod,
} from '../hooks/use-deliveries';
import type { DeliveryAttempt, DeliveryReturn, ProofOfDelivery } from '../types/delivery';
import { DeliveryStatusBadge } from './delivery-status-badge';

function formatDateTime(value: string | null): string {
  if (!value) return '—';
  return new Date(value).toLocaleString(undefined, {
    day: '2-digit',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
  });
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="space-y-0.5">
      <p className="text-[11px] uppercase tracking-wide text-muted-foreground">{label}</p>
      <div className="text-sm">{children}</div>
    </div>
  );
}

// ── Overview ─────────────────────────────────────────────────────────────────

function Overview({ deliveryId }: { deliveryId: string }) {
  const { data: delivery } = useDelivery(deliveryId);
  const retry = useRetryDelivery();
  const addressCorrected = useMarkAddressCorrected();
  const cancel = useCancelDelivery();
  const { toast } = useToast();
  const [cancelReason, setCancelReason] = useState('');

  if (!delivery) return <Skeleton className="h-64 w-full" />;

  const cod = delivery.cod_record;

  async function run(fn: () => Promise<unknown>, success: string) {
    try {
      await fn();
      toast({ title: success });
    } catch (error) {
      const message =
        (error as { response?: { data?: { message?: string } } }).response?.data?.message ??
        'The action could not be completed.';
      toast({ title: 'Action refused', description: message, variant: 'destructive' });
    }
  }

  return (
    <div className="space-y-5">
      <div className="grid grid-cols-2 gap-4">
        <Field label="Status">
          <DeliveryStatusBadge status={delivery.status} />
        </Field>
        <Field label="Order">
          <span className="font-mono text-xs">{delivery.order_id}</span>
        </Field>
        <Field label="Attempts">
          {delivery.attempt_count} of {delivery.max_attempts}
          <span className="ml-1 text-xs text-muted-foreground">
            ({delivery.remaining_attempts} left)
          </span>
        </Field>
        <Field label="Promised">{formatDateTime(delivery.promised_at)}</Field>
        <Field label="Delivered">{formatDateTime(delivery.delivered_at)}</Field>
        <Field label="Escalation level">{delivery.escalation_level}</Field>
      </div>

      {delivery.sla_breached && (
        <Alert variant="destructive">
          <Clock className="size-4" />
          <AlertDescription>
            SLA breached
            {delivery.minutes_late !== null && ` by ${delivery.minutes_late} minutes`}.
          </AlertDescription>
        </Alert>
      )}

      {delivery.requires_manual_review && (
        <Alert>
          <AlertTriangle className="size-4" />
          <AlertDescription>
            Three or more failures recorded — this delivery needs a supervisor decision.
          </AlertDescription>
        </Alert>
      )}

      {/* COD is a completion report. Settlement lives in Distribution. */}
      {cod && (
        <div className="rounded-lg border p-3">
          <div className="mb-2 flex items-center gap-2">
            <Wallet className="size-4 text-muted-foreground" />
            <span className="text-sm font-medium">Cash on delivery</span>
            <Badge variant="outline" className="text-xs">
              {cod.status_label}
            </Badge>
          </div>
          <div className="grid grid-cols-3 gap-3">
            <Field label="Due">
              {cod.amount_due.toLocaleString()} {cod.currency}
            </Field>
            <Field label="Collected">
              {cod.amount_collected.toLocaleString()} {cod.currency}
            </Field>
            <Field label="Shortfall">
              {cod.shortfall > 0 ? (
                <span className="text-destructive">
                  {cod.shortfall.toLocaleString()} {cod.currency}
                </span>
              ) : (
                '—'
              )}
            </Field>
          </div>
          <p className="mt-2 text-[11px] text-muted-foreground">
            Reconciliation and trip cash balances are handled in Distribution.
          </p>
        </div>
      )}

      <Separator />

      <div className="space-y-2">
        <p className="text-sm font-medium">Retry</p>
        {delivery.can_retry ? (
          <p className="text-xs text-muted-foreground">
            This delivery is eligible for another attempt.
          </p>
        ) : (
          <ul className="space-y-1">
            {delivery.retry_blockers.map((blocker) => (
              <li key={blocker} className="flex items-start gap-1.5 text-xs text-muted-foreground">
                <XCircle className="mt-0.5 size-3 shrink-0 text-destructive" />
                {blocker}
              </li>
            ))}
          </ul>
        )}

        <div className="flex flex-wrap gap-2 pt-1">
          <Button
            size="sm"
            className="gap-1.5"
            disabled={!delivery.can_retry || retry.isPending}
            onClick={() => run(() => retry.mutateAsync(delivery.id), 'Retry scheduled.')}
          >
            {retry.isPending ? <Loader2 className="size-3.5 animate-spin" /> : <RotateCcw className="size-3.5" />}
            Schedule retry
          </Button>

          {delivery.requires_address_correction && !delivery.address_corrected_at && (
            <Button
              size="sm"
              variant="outline"
              className="gap-1.5"
              disabled={addressCorrected.isPending}
              onClick={() =>
                run(() => addressCorrected.mutateAsync(delivery.id), 'Address marked as corrected.')
              }
            >
              <MapPin className="size-3.5" />
              Mark address corrected
            </Button>
          )}
        </div>
      </div>

      {!delivery.is_terminal && (
        <>
          <Separator />
          <div className="space-y-2">
            <Label htmlFor="cancel-reason" className="text-sm font-medium">
              Cancel delivery
            </Label>
            <Textarea
              id="cancel-reason"
              rows={2}
              placeholder="Why is this delivery being cancelled?"
              value={cancelReason}
              onChange={(e) => setCancelReason(e.target.value)}
            />
            <Button
              size="sm"
              variant="destructive"
              className="gap-1.5"
              disabled={cancel.isPending}
              onClick={() =>
                run(
                  () => cancel.mutateAsync({ id: delivery.id, reason: cancelReason || undefined }),
                  'Delivery cancelled.',
                )
              }
            >
              <Ban className="size-3.5" />
              Cancel delivery
            </Button>
          </div>
        </>
      )}
    </div>
  );
}

// ── Attempts ─────────────────────────────────────────────────────────────────

function PodSummary({
  deliveryId,
  attempt,
  pod,
}: {
  deliveryId: string;
  attempt: DeliveryAttempt;
  pod: ProofOfDelivery;
}) {
  const validate = useValidatePod();
  const { toast } = useToast();
  const missing = pod.missing_artifacts ?? [];

  return (
    <div className="mt-2 rounded-md border bg-muted/30 p-2">
      <div className="flex items-center gap-2">
        <FileCheck2 className="size-3.5 text-muted-foreground" />
        <span className="text-xs font-medium">Proof of delivery</span>
        <Badge variant="outline" className="text-[10px]">
          {pod.status_label}
        </Badge>
      </div>

      <div className="mt-1.5 flex flex-wrap gap-1">
        {(pod.artifacts ?? []).map((artifact) => (
          <Badge key={artifact.id} variant="secondary" className="text-[10px]">
            {artifact.kind_label}
          </Badge>
        ))}
      </div>

      {missing.length > 0 && (
        <p className="mt-1.5 text-[11px] text-destructive">
          Missing: {missing.join(', ')}
        </p>
      )}

      {!pod.is_validated && (
        <Button
          size="sm"
          variant="outline"
          className="mt-2 h-7 gap-1.5 text-xs"
          disabled={missing.length > 0 || validate.isPending}
          onClick={async () => {
            try {
              await validate.mutateAsync({ id: deliveryId, attemptId: attempt.id });
              toast({ title: 'Proof validated.' });
            } catch (error) {
              const message =
                (error as { response?: { data?: { message?: string } } }).response?.data?.message ??
                'The proof could not be validated.';
              toast({ title: 'Validation refused', description: message, variant: 'destructive' });
            }
          }}
        >
          <ShieldCheck className="size-3.5" />
          Validate proof
        </Button>
      )}
    </div>
  );
}

function Attempts({ deliveryId }: { deliveryId: string }) {
  const { data: delivery } = useDelivery(deliveryId);
  const { data: options } = useDeliveryOptions();
  const fail = useFailAttempt();
  const { toast } = useToast();
  const [reasonCode, setReasonCode] = useState('');

  if (!delivery) return <Skeleton className="h-64 w-full" />;

  const attempts = delivery.attempts ?? [];
  const openAttempt = attempts.find((a) => a.is_open);

  return (
    <div className="space-y-4">
      {attempts.length === 0 && (
        <p className="py-8 text-center text-sm text-muted-foreground">
          No attempts yet. An attempt opens when the driver reaches the stop.
        </p>
      )}

      {attempts.map((attempt) => (
        <div key={attempt.id} className="rounded-lg border p-3">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-2">
              <span className="text-sm font-medium">Attempt {attempt.attempt_no}</span>
              <Badge variant={attempt.is_open ? 'default' : 'secondary'} className="text-[10px]">
                {attempt.status_label}
              </Badge>
            </div>
            <span className="text-xs text-muted-foreground">
              {formatDateTime(attempt.closed_at ?? attempt.started_at ?? attempt.created_at)}
            </span>
          </div>

          <div className="mt-2 grid grid-cols-3 gap-3">
            <Field label="Arrived">{formatDateTime(attempt.arrived_at)}</Field>
            <Field label="Dwell">
              {attempt.dwell_minutes !== null ? `${attempt.dwell_minutes} min` : '—'}
            </Field>
            <Field label="Stop">{attempt.stop_id ?? '—'}</Field>
          </div>

          {attempt.failure && (
            <Alert variant="destructive" className="mt-2 py-2">
              <XCircle className="size-4" />
              <AlertDescription className="text-xs">
                <span className="font-medium">{attempt.failure.reason_label}</span>
                {' · '}
                {attempt.failure.category_label}
                {attempt.failure.is_retryable ? ' · retryable' : ' · not retryable'}
                {attempt.failure.description && <span className="block">{attempt.failure.description}</span>}
              </AlertDescription>
            </Alert>
          )}

          {attempt.pod && <PodSummary deliveryId={deliveryId} attempt={attempt} pod={attempt.pod} />}
        </div>
      ))}

      {openAttempt && (
        <>
          <Separator />
          <div className="space-y-2">
            <Label className="text-sm font-medium">Record a failure</Label>
            <p className="text-xs text-muted-foreground">
              Retryability follows the reason you pick — it is decided by the taxonomy, not entered by hand.
            </p>
            <Select value={reasonCode} onValueChange={setReasonCode}>
              <SelectTrigger className="h-8 text-sm">
                <SelectValue placeholder="Select a failure reason" />
              </SelectTrigger>
              <SelectContent>
                {(options?.failure_reasons ?? []).map((reason) => (
                  <SelectItem key={reason.value} value={reason.value}>
                    {reason.label} · {reason.is_retryable ? 'retryable' : 'final'}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <Button
              size="sm"
              variant="destructive"
              className="gap-1.5"
              disabled={!reasonCode || fail.isPending}
              onClick={async () => {
                try {
                  await fail.mutateAsync({
                    id: deliveryId,
                    attemptId: openAttempt.id,
                    payload: { reason_code: reasonCode },
                  });
                  setReasonCode('');
                  toast({ title: 'Failure recorded.' });
                } catch (error) {
                  const message =
                    (error as { response?: { data?: { message?: string } } }).response?.data?.message ??
                    'The failure could not be recorded.';
                  toast({ title: 'Action refused', description: message, variant: 'destructive' });
                }
              }}
            >
              <XCircle className="size-3.5" />
              Record failure
            </Button>
          </div>
        </>
      )}
    </div>
  );
}

// ── Returns ──────────────────────────────────────────────────────────────────

function ReturnCard({ deliveryReturn }: { deliveryReturn: DeliveryReturn }) {
  return (
    <div className="rounded-lg border p-3">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-2">
          <Undo2 className="size-3.5 text-muted-foreground" />
          <span className="text-sm font-medium">{deliveryReturn.status_label}</span>
          {deliveryReturn.has_discrepancy && (
            <Badge variant="destructive" className="text-[10px]">
              Discrepancy
            </Badge>
          )}
        </div>
        <span className="text-xs text-muted-foreground">
          {formatDateTime(deliveryReturn.initiated_at)}
        </span>
      </div>

      {deliveryReturn.reason && (
        <p className="mt-1 text-xs text-muted-foreground">{deliveryReturn.reason}</p>
      )}

      <table className="mt-2 w-full text-xs">
        <thead>
          <tr className="border-b text-muted-foreground">
            <th className="py-1 text-left font-normal">Product</th>
            <th className="py-1 text-right font-normal">Returned</th>
            <th className="py-1 text-right font-normal">Counted</th>
            <th className="py-1 text-right font-normal">Difference</th>
          </tr>
        </thead>
        <tbody className="divide-y">
          {(deliveryReturn.lines ?? []).map((line) => (
            <tr key={line.id}>
              <td className="py-1">{line.product_name ?? '—'}</td>
              <td className="py-1 text-right tabular-nums">{line.returned_qty}</td>
              <td className="py-1 text-right tabular-nums">
                {line.warehouse_confirmed_qty ?? '—'}
              </td>
              <td
                className={`py-1 text-right tabular-nums ${
                  line.has_discrepancy ? 'text-destructive' : ''
                }`}
              >
                {line.discrepancy_qty ?? '—'}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function Returns({ deliveryId }: { deliveryId: string }) {
  const { data: delivery } = useDelivery(deliveryId);

  if (!delivery) return <Skeleton className="h-48 w-full" />;

  const returns = delivery.returns ?? [];

  if (returns.length === 0) {
    return (
      <p className="py-8 text-center text-sm text-muted-foreground">
        Nothing has been returned for this delivery.
      </p>
    );
  }

  return (
    <div className="space-y-3">
      {returns.map((item) => (
        <ReturnCard key={item.id} deliveryReturn={item} />
      ))}
    </div>
  );
}

// ── Timeline ─────────────────────────────────────────────────────────────────

function Timeline({ deliveryId }: { deliveryId: string }) {
  const { data: events, isLoading } = useDeliveryTimeline(deliveryId);

  if (isLoading) return <Skeleton className="h-48 w-full" />;
  if (!events || events.length === 0) {
    return <p className="py-8 text-center text-sm text-muted-foreground">No events recorded yet.</p>;
  }

  return (
    <ol className="space-y-3">
      {events.map((event) => (
        <li key={event.id} className="flex gap-3">
          <div className="mt-1 flex flex-col items-center">
            <CheckCircle className="size-3.5 text-muted-foreground" />
            <span className="mt-1 w-px flex-1 bg-border" />
          </div>
          <div className="flex-1 pb-1">
            <div className="flex items-center gap-2">
              <span className="text-sm font-medium">{event.title}</span>
              {event.customer_visible && (
                <Badge variant="outline" className="text-[10px]">
                  Customer visible
                </Badge>
              )}
            </div>
            {event.description && (
              <p className="text-xs text-muted-foreground">{event.description}</p>
            )}
            <p className="mt-0.5 text-[11px] text-muted-foreground">
              {formatDateTime(event.occurred_at)}
              {event.actor_name && ` · ${event.actor_name}`}
            </p>
          </div>
        </li>
      ))}
    </ol>
  );
}

// ── Drawer ───────────────────────────────────────────────────────────────────

export function DeliveryDrawer({
  deliveryId,
  open,
  onOpenChange,
}: {
  deliveryId: string | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const { data: delivery } = useDelivery(open ? deliveryId : null);

  return (
    <PageDrawer
      open={open}
      onOpenChange={onOpenChange}
      size="2xl"
      title={delivery ? `Delivery · ${delivery.order_id}` : 'Delivery'}
      description={
        delivery
          ? `${delivery.attempt_count} of ${delivery.max_attempts} attempts used`
          : undefined
      }
    >
      {!deliveryId ? null : (
        <Tabs defaultValue="overview" className="w-full">
          <TabsList>
            <TabsTrigger value="overview">Overview</TabsTrigger>
            <TabsTrigger value="attempts">
              Attempts
              {delivery?.attempts_count ? ` (${delivery.attempts_count})` : ''}
            </TabsTrigger>
            <TabsTrigger value="returns">
              Returns
              {delivery?.returns_count ? ` (${delivery.returns_count})` : ''}
            </TabsTrigger>
            <TabsTrigger value="timeline">Timeline</TabsTrigger>
          </TabsList>

          <TabsContent value="overview" className="pt-4">
            <Overview deliveryId={deliveryId} />
          </TabsContent>
          <TabsContent value="attempts" className="pt-4">
            <Attempts deliveryId={deliveryId} />
          </TabsContent>
          <TabsContent value="returns" className="pt-4">
            <Returns deliveryId={deliveryId} />
          </TabsContent>
          <TabsContent value="timeline" className="pt-4">
            <Timeline deliveryId={deliveryId} />
          </TabsContent>
        </Tabs>
      )}
    </PageDrawer>
  );
}

import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useFormatter } from '@/hooks/use-formatter';
import { useParams, useNavigate } from 'react-router-dom';
import { ArrowLeft, Phone, MessageCircle, MapPin, Package, DollarSign, Upload, Pencil, Check, AlertTriangle } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet';
import { useToast } from '@/components/ds/use-toast';
import { cn } from '@/lib/utils';
import { ROUTES } from '@/router/routes';
import {
  useDriverStopDetail,
  useDriverTrip,
  useSubmitDeliveryAction,
  useSubmitStopDelivery,
  useStartDelivery,
  useUploadDeliveryProof,
  useUploadPaymentProof,
  useChangePaymentMethod,
} from '../hooks/use-driver-mobile';
import { acceptsDeliveryExecution } from '../lib/trip-lifecycle';
import type { StopDeliveryLine } from '../services/driver-mobile-service';
import { DeliveryActionForm } from '../components/delivery-action-form';
import { DeliveryProofUploadForm } from '../components/delivery-proof-upload-form';
import { PaymentProofUploadForm } from '../components/payment-proof-upload-form';
import { StopStatusBadge } from '../components/stop-status-badge';
import type { DeliveryActionType, StopOrderLine } from '../types/driver-mobile';

type SheetMode = 'action' | 'payment-proof' | 'delivery-proof' | 'change-method' | null;

// The five canonical order payment methods (§5) — the ONLY selectable values; no aliases.
const CANONICAL_METHODS = ['cod', 'instapay', 'mobile_wallet', 'credit_card', 'bank_transfer'] as const;
type CanonicalMethod = (typeof CANONICAL_METHODS)[number];
// Methods whose brand policy requires a payment proof (BrandPolicy payment_proof_policy).
const PROOF_REQUIRED_METHODS: string[] = ['instapay', 'bank_transfer', 'mobile_wallet'];

// NON-delivery outcomes only. The DELIVERED path is the canonical quantity card below
// (POST /deliver → RecordProductDeliveryAction), so 'completed' is intentionally NOT a
// status button — marking Delivered without recording quantities would bypass the
// canonical writer. 'partial' is likewise absent (delivered quantities are the card's job).
const ACTION_BUTTONS: { type: DeliveryActionType; variant: 'default' | 'outline' | 'destructive' }[] = [
  { type: 'refused',       variant: 'destructive' },
  { type: 'not_available', variant: 'outline'     },
  { type: 'delay',         variant: 'outline'     },
  { type: 'wrong_address', variant: 'outline'     },
  { type: 'unreachable',   variant: 'outline'     },
];

export function DriverStopDetailPage() {
  const { t } = useTranslation('driver-mobile');
  const { money } = useFormatter();
  const { tripId = '', stopId = '' } = useParams<{ tripId: string; stopId: string }>();
  const navigate = useNavigate();

  const { data: stop, isLoading } = useDriverStopDetail(tripId, stopId);
  // Trip lifecycle is the FIRST gate for every delivery control. The stop detail carries no
  // trip status, so the canonical trip summary is read here and interpreted through the shared
  // `acceptsDeliveryExecution` predicate (the mirror of the backend on-the-road guard).
  const { data: trip } = useDriverTrip(tripId);
  const actionMutation  = useSubmitDeliveryAction(stopId);
  const startMutation   = useStartDelivery(tripId, stopId);
  const proofMutation   = useUploadPaymentProof(tripId, stopId);
  const podMutation     = useUploadDeliveryProof(tripId, stopId);
  const deliverMutation = useSubmitStopDelivery(tripId, stopId);
  const changeMethodMutation = useChangePaymentMethod(tripId, stopId);
  const { toast } = useToast();

  const [sheetMode,   setSheetMode]   = useState<SheetMode>(null);
  const [actionType,  setActionType]  = useState<DeliveryActionType>('refused');
  // The method chosen in the change sheet; seeded from the current method when it opens.
  const [methodDraft, setMethodDraft] = useState<string>('');
  // Draft cumulative delivered totals, keyed by order_line_id. Empty = "unedited" → the input
  // falls back to the canonical delivered_qty; cleared after a successful record so it re-syncs.
  const [drafts, setDrafts] = useState<Record<string, string>>({});

  function openAction(type: DeliveryActionType) {
    setActionType(type);
    setSheetMode('action');
  }

  const isDone    = ['delivered', 'partial', 'failed', 'returned', 'skipped'].includes(stop?.status ?? '');

  if (isLoading) {
    return (
      <div className="p-4 space-y-4">
        <Skeleton className="h-10 w-full" />
        <Skeleton className="h-40 w-full" />
        <Skeleton className="h-32 w-full" />
      </div>
    );
  }

  if (!stop) {
    return <div className="p-4 text-center text-muted-foreground">{t(($) => $.stop.notFound)}</div>;
  }

  const { order } = stop;
  // Consume the order-level GPS fix when present — turn the address into a maps deep-link.
  const mapsHref = order?.gps ? `https://maps.google.com/?q=${order.gps.lat},${order.gps.lng}` : null;
  const addressText = [order?.address, order?.area, order?.city, order?.governorate]
    .filter(Boolean)
    .join(', ');

  // TRIP-LEVEL gate (mirrors backend DeliveryService::assertTripOnTheRoad). Delivery execution
  // is legal ONLY while the trip is on the road; a stop-level status can never expose an action
  // the parent trip cannot perform. Fail-closed: until the trip summary loads, treat as not-yet
  // on the road (the backend would reject anyway). Every gate below folds this in.
  const tripAcceptsDelivery = acceptsDeliveryExecution(trip?.status);

  // §6 customer-contact privacy: the phone action is exposed only AFTER the canonical Start
  // Delivery (the stop leaves 'pending') AND while the trip is on the road. Before that the
  // driver has not begun this stop, so contacting the customer is out of the approved flow.
  // Address/maps stay visible — the driver still needs to know WHERE to go — but phone/WhatsApp
  // wait for out-for-delivery on an on-the-road trip.
  const deliveryStarted = tripAcceptsDelivery && stop.status !== 'pending';
  const rawMethod = (order?.payment_method ?? '').toLowerCase().replace(/[^a-z_]/g, '');
  const knownMethods = ['cash', 'cod', 'instapay', 'bank_transfer', 'card', 'already_paid', 'wallet', 'mobile_wallet', 'credit_card'];
  const paymentLabel = knownMethods.includes(rawMethod)
    ? t(($) => $.stop.payment.methods[rawMethod as 'cash'])
    : (order?.payment_method ?? '—');
  // §10/§11 — the payment method is editable ONLY while the stop is actively out-for-delivery
  // (in_progress) on an on-the-road trip; before Start Delivery and after a settled outcome it
  // is read-only.
  const canEditMethod = tripAcceptsDelivery && stop.status === 'in_progress';
  // §9 — surface the proof requirement when the (current) method needs a payment proof.
  const methodRequiresProof = PROOF_REQUIRED_METHODS.includes(rawMethod);
  const openChangeMethod = () => {
    setMethodDraft(CANONICAL_METHODS.includes(rawMethod as CanonicalMethod) ? rawMethod : '');
    setSheetMode('change-method');
  };
  const submitMethod = () => {
    if (!methodDraft || methodDraft === rawMethod || changeMethodMutation.isPending) return;
    changeMethodMutation.mutate(methodDraft, { onSuccess: () => setSheetMode(null) });
  };

  // ── Delivered-quantity recording (cumulative absolute; backend is authoritative) ──
  const lines: StopOrderLine[] = order?.lines ?? [];
  // Delivery quantities can be recorded ONLY while the stop is actively out-for-delivery
  // (in_progress) AND the trip is on the road. Before Start Delivery (pending) the card is
  // read-only — the delivery CTA is hidden per §1; after the stop is resolved it is no longer
  // editable. This same gate governs the failed-outcome buttons below.
  const canDeliver = tripAcceptsDelivery && stop.status === 'in_progress';
  const draftFor = (l: StopOrderLine) => drafts[l.order_line_id] ?? String(l.delivered_qty ?? 0);
  // Only lines whose cumulative total actually changed are sent (idempotent, minimal).
  const changedLines = lines.filter((l) => {
    const v = Number(draftFor(l));
    return Number.isFinite(v) && Math.abs(v - (l.delivered_qty ?? 0)) > 1e-6;
  });
  // A total must stay between what is already delivered and the required quantity.
  const hasInvalid = lines.some((l) => {
    const v = Number(draftFor(l));
    return !Number.isFinite(v) || v < (l.delivered_qty ?? 0) - 1e-6 || v > l.ordered_qty + 1e-6;
  });
  const deliverAll = () =>
    setDrafts(Object.fromEntries(lines.map((l) => [l.order_line_id, String(l.ordered_qty)])));
  const recordDelivery = () => {
    if (changedLines.length === 0 || hasInvalid || deliverMutation.isPending) return;
    const payload: StopDeliveryLine[] = changedLines.map((l) => ({
      order_line_id: l.order_line_id,
      delivered_qty: Number(draftFor(l)),
    }));
    deliverMutation.mutate(payload, {
      onSuccess: () => {
        setDrafts({}); // re-sync inputs to the refreshed canonical delivered_qty
        toast({ title: t(($) => $.stop.delivery.recorded) });
      },
    });
  };

  return (
    <div className="min-h-screen bg-background pb-8">
      {/* Header */}
      <div className="sticky top-0 z-10 bg-background border-b px-4 py-3 flex items-center gap-3">
        <Button
          variant="ghost"
          size="icon"
          onClick={() => navigate(ROUTES.driverTripStops.replace(':tripId', tripId))}
        >
          <ArrowLeft className="h-5 w-5" />
        </Button>
        <div className="flex-1 min-w-0">
          <h1 className="font-semibold text-base">{t(($) => $.stop.sequence, { sequence: stop.sequence })}</h1>
          <p className="text-xs text-muted-foreground truncate">{order?.order_number}</p>
        </div>
        <StopStatusBadge status={stop.status} />
      </div>

      <div className="p-4 space-y-4">
        {/* Customer */}
        <div className="rounded-xl border p-4 space-y-2">
          <p className="font-semibold">{order?.customer_name ?? t(($) => $.stop.noName)}</p>
          {order?.phone && deliveryStarted && (
            <div className="flex items-center gap-4">
              <a
                href={`tel:${order.phone}`}
                className="flex items-center gap-2 text-blue-600 text-sm"
              >
                <Phone className="h-4 w-4" />
                {order.phone}
              </a>
              {/* WhatsApp — canonical customer phone, digits only, after Start Delivery (§2). */}
              <a
                href={`https://wa.me/${order.phone.replace(/[^0-9]/g, '')}`}
                target="_blank"
                rel="noreferrer"
                className="flex items-center gap-2 text-green-600 text-sm"
              >
                <MessageCircle className="h-4 w-4" />
                {t(($) => $.stop.whatsapp)}
              </a>
            </div>
          )}
          {order?.phone && !deliveryStarted && (
            <p className="flex items-center gap-2 text-xs text-muted-foreground">
              <Phone className="h-3.5 w-3.5" aria-hidden="true" />
              {t(($) => $.stop.contactAfterStart)}
            </p>
          )}
          {addressText && (
            mapsHref ? (
              <a
                href={mapsHref}
                target="_blank"
                rel="noreferrer"
                className="flex items-start gap-2 text-sm text-blue-600 hover:underline"
              >
                <MapPin className="h-4 w-4 mt-0.5 shrink-0" />
                <span>{addressText}</span>
              </a>
            ) : (
              <div className="flex items-start gap-2 text-sm text-muted-foreground">
                <MapPin className="h-4 w-4 mt-0.5 shrink-0" />
                <span>{addressText}</span>
              </div>
            )
          )}
          {order?.delivery_notes && (
            <p className="text-xs text-amber-700 bg-amber-50 rounded p-2">
              {order.delivery_notes}
            </p>
          )}
        </div>

        {/* Items + delivery — per line Required / Delivered / Remaining, and (while the stop is
            open) a CUMULATIVE delivered-total input. Quantities go through the canonical writer
            (POST /deliver); the stop is settled Delivered by the BACKEND only when every line is
            complete — the UI invents no lifecycle and shows no optimistic state. */}
        {lines.length > 0 && (
          <div className="rounded-xl border p-4 space-y-3">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-1.5 font-semibold text-sm">
                <Package className="h-4 w-4" />
                {t(($) => $.stop.products, { count: lines.length })}
              </div>
              {canDeliver && (
                <Button variant="ghost" size="sm" className="h-7 text-xs" onClick={deliverAll}>
                  {t(($) => $.stop.delivery.deliverAll)}
                </Button>
              )}
            </div>

            <div className="space-y-3">
              {lines.map((line) => {
                const delivered = line.delivered_qty ?? 0;
                const remaining = line.remaining_qty ?? Math.max(0, line.ordered_qty - delivered);
                const complete = remaining <= 1e-6;
                const partial = !complete && delivered > 1e-6;
                return (
                  <div key={line.order_line_id} className="space-y-1.5 border-b pb-3 last:border-b-0 last:pb-0">
                    <div className="flex justify-between text-sm">
                      <span className="font-medium">{line.product_name}</span>
                      <span className="text-muted-foreground">{money(Number(line.line_total))}</span>
                    </div>
                    <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                      <span className="text-muted-foreground">
                        {t(($) => $.stop.delivery.required)}:{' '}
                        <span className="font-medium text-foreground">{line.ordered_qty}</span>
                      </span>
                      <span className="text-muted-foreground">
                        {t(($) => $.stop.delivery.delivered)}:{' '}
                        <span className={`font-medium ${complete ? 'text-green-600' : 'text-foreground'}`}>{delivered}</span>
                      </span>
                      <span className="text-muted-foreground">
                        {t(($) => $.stop.delivery.remaining)}:{' '}
                        <span className={`font-medium ${remaining > 1e-6 ? 'text-amber-600' : 'text-muted-foreground'}`}>{remaining}</span>
                      </span>
                      {complete && (
                        <Badge variant="secondary" className="ml-auto bg-green-100 text-green-700">
                          {t(($) => $.stop.delivery.complete)}
                        </Badge>
                      )}
                      {partial && (
                        <Badge variant="secondary" className="ml-auto bg-amber-100 text-amber-700">
                          {t(($) => $.stop.delivery.partial)}
                        </Badge>
                      )}
                    </div>
                    {canDeliver && (
                      <div className="flex items-center gap-2 pt-0.5">
                        <span className="text-xs text-muted-foreground">{t(($) => $.stop.delivery.deliveredInput)}</span>
                        <Input
                          type="number"
                          inputMode="decimal"
                          min={delivered}
                          max={line.ordered_qty}
                          step="any"
                          value={draftFor(line)}
                          onChange={(e) => setDrafts((prev) => ({ ...prev, [line.order_line_id]: e.target.value }))}
                          className="h-8 w-24"
                          aria-label={t(($) => $.stop.delivery.deliveredInput)}
                        />
                        <span className="text-xs text-muted-foreground">/ {line.ordered_qty}</span>
                      </div>
                    )}
                  </div>
                );
              })}
            </div>

            {canDeliver && (
              <div className="space-y-2">
                {hasInvalid && <p className="text-xs text-destructive">{t(($) => $.stop.delivery.maxHint)}</p>}
                {deliverMutation.isError && (
                  <p className="text-xs text-destructive">
                    {(deliverMutation.error as Error | null)?.message ?? t(($) => $.stop.delivery.failed)}
                  </p>
                )}
                <Button
                  className="w-full"
                  disabled={changedLines.length === 0 || hasInvalid || deliverMutation.isPending}
                  onClick={recordDelivery}
                >
                  {deliverMutation.isPending ? t(($) => $.stop.delivery.recording) : t(($) => $.stop.delivery.record)}
                </Button>
              </div>
            )}
          </div>
        )}

        {/* Payment summary */}
        <div className="rounded-xl border p-4 space-y-1.5">
          <div className="flex items-center gap-1.5 font-semibold text-sm mb-2">
            <DollarSign className="h-4 w-4" />
            {t(($) => $.stop.payment.title)}
          </div>
          <div className="flex justify-between items-center text-sm">
            <span className="text-muted-foreground">{t(($) => $.stop.payment.method)}</span>
            <span className="flex items-center gap-1.5">
              <span>{paymentLabel}</span>
              {canEditMethod && (
                <Button
                  variant="ghost"
                  size="sm"
                  className="h-6 px-2 text-xs"
                  onClick={openChangeMethod}
                >
                  <Pencil className="mr-1 h-3 w-3" />
                  {t(($) => $.stop.changeMethod.button)}
                </Button>
              )}
            </span>
          </div>
          <div className="flex justify-between text-sm">
            <span className="text-muted-foreground">{t(($) => $.stop.payment.total)}</span>
            <span>{money(Number(order?.grand_total ?? 0))}</span>
          </div>
          <div className="flex justify-between text-sm">
            <span className="text-muted-foreground">{t(($) => $.stop.payment.deposit)}</span>
            <span className="text-green-600">
              - {money(Number(order?.deposit_paid ?? 0))}
            </span>
          </div>
          <div className="flex justify-between text-sm font-semibold border-t pt-1.5">
            <span>{t(($) => $.stop.payment.balance)}</span>
            <span>
              {money(Number(order?.remaining_balance ?? 0))}
            </span>
          </div>
        </div>

        {/* §9 — the current method needs a payment proof; surface it so the driver captures
            it (the upload button is directly below). This preserves the Phase-4 proof path;
            it does not itself finalize delivery. */}
        {deliveryStarted && methodRequiresProof && (
          <div className="flex items-start gap-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-xs text-amber-800">
            <AlertTriangle className="h-4 w-4 shrink-0" aria-hidden="true" />
            <span>{t(($) => $.stop.changeMethod.proofRequired)}</span>
          </div>
        )}

        {/* Payment-transfer proof upload — revealed only after Start Delivery (§1). Driver
            uploads only; verify/settle is an operator capability on a separate surface. */}
        {deliveryStarted && (
          <Button
            variant="outline"
            className="w-full"
            onClick={() => setSheetMode('payment-proof')}
          >
            <Upload className="mr-2 h-4 w-4" />
            {t(($) => $.stop.paymentProof.button)}
          </Button>
        )}

        {/* Delivery action summary (if done) */}
        {isDone && stop.delivery_type && (
          <div className="rounded-xl border bg-muted/30 p-4 space-y-1.5">
            <p className="text-sm font-semibold">{t(($) => $.stop.recordedAction)}</p>
            <Badge variant="secondary">
              {/* Guarded non-null by the enclosing `stop.delivery_type &&`; the cast only
                  re-states that for the selector closure, which TS does not narrow into. */}
              {t(($) => $.actions[stop.delivery_type as DeliveryActionType])}
            </Badge>
            {stop.notes && <p className="text-xs text-muted-foreground">{stop.notes}</p>}
          </div>
        )}

        {/* START DELIVERY — the ONLY execution control before the stop is out-for-delivery
            (§1). Exposed only when the stop is pending AND the trip is on the road: the backend
            (assertTripOnTheRoad) rejects a start while the trip is still Loading / Ready, so the
            UI must not offer it. It calls the canonical lifecycle (useStartDelivery); the UI
            never writes Order.status. Everything else — outcomes, delivery quantities, payment
            proof, POD, phone, WhatsApp — is gated on `deliveryStarted` and only appears after. */}
        {stop.status === 'pending' && tripAcceptsDelivery && (
          <Button
            className="h-12 w-full text-base font-semibold"
            disabled={startMutation.isPending}
            onClick={() => startMutation.mutate()}
          >
            {startMutation.isPending ? t(($) => $.stop.starting) : t(($) => $.stop.startDelivery)}
          </Button>
        )}

        {/* Pending stop, trip NOT yet on the road — explain why Start Delivery is unavailable
            instead of silently hiding it. Shown only once the trip summary has loaded so it
            never flashes before the status is known. */}
        {stop.status === 'pending' && trip && !tripAcceptsDelivery && (
          <p className="flex items-start gap-2 rounded-lg border border-dashed p-3 text-xs text-muted-foreground">
            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
            {t(($) => $.stop.startBlockedTripNotOnRoad)}
          </p>
        )}

        {/* FAILED-DELIVERY OUTCOMES — classified (§11), revealed only once out-for-delivery on
            an on-the-road trip (same `canDeliver` gate as the quantity card). Each opens the
            reason/notes form; the backend catalogue owns which reasons exist. */}
        {canDeliver && (
          <div className="space-y-2">
            <p className="text-sm font-semibold">{t(($) => $.stop.reportOutcome)}</p>
            <div className="grid grid-cols-2 gap-2">
              {ACTION_BUTTONS.map((btn) => (
                <Button
                  key={btn.type}
                  variant={btn.variant}
                  size="sm"
                  onClick={() => openAction(btn.type)}
                  className="text-xs"
                >
                  {t(($) => $.actions[btn.type])}
                </Button>
              ))}
            </div>
          </div>
        )}

        {/* Proof of delivery — TASK-DRIVER-APP-FINAL-CLOSURE-002 Part 2.

            This control was deliberately withheld while the only POD endpoint accepted
            client-supplied path strings. That contract is now replaced by the certified
            secure upload (server-generated private path, MIME and size validation,
            tenant-scoped retrieval), so the capture is exposed here for the first time.
            Upload only: the driver can attach evidence, never read or name a storage path.
            Revealed only after Start Delivery (§1). */}
        {deliveryStarted && (
          <Button
            variant="outline"
            className="w-full"
            onClick={() => setSheetMode('delivery-proof')}
          >
            <Upload className="mr-2 h-4 w-4" />
            {t(($) => $.stop.deliveryProof.button)}
          </Button>
        )}
      </div>

      {/* Action sheet */}
      <Sheet open={sheetMode === 'action'} onOpenChange={(o) => !o && setSheetMode(null)}>
        <SheetContent side="bottom" className="max-h-[85vh] overflow-y-auto">
          <SheetHeader className="mb-4">
            <SheetTitle>{t(($) => $.stop.deliveryAction)}</SheetTitle>
          </SheetHeader>
          <DeliveryActionForm
            actionType={actionType}
            isLoading={actionMutation.isPending}
            onSubmit={(payload) => {
              actionMutation.mutate(payload, {
                onSuccess: () => setSheetMode(null),
              });
            }}
            onCancel={() => setSheetMode(null)}
          />
        </SheetContent>
      </Sheet>

      {/* Proof-of-delivery sheet — secure multipart upload */}
      <Sheet open={sheetMode === 'delivery-proof'} onOpenChange={(o) => !o && setSheetMode(null)}>
        <SheetContent side="bottom" className="max-h-[85vh] overflow-y-auto">
          <SheetHeader className="mb-4">
            <SheetTitle>{t(($) => $.stop.deliveryProof.title)}</SheetTitle>
          </SheetHeader>
          <DeliveryProofUploadForm
            isLoading={podMutation.isPending}
            onSubmit={(input) => {
              podMutation.mutate(input, {
                onSuccess: () => setSheetMode(null),
              });
            }}
            onCancel={() => setSheetMode(null)}
          />
        </SheetContent>
      </Sheet>

      {/* Payment-transfer proof sheet */}
      <Sheet open={sheetMode === 'payment-proof'} onOpenChange={(o) => !o && setSheetMode(null)}>
        <SheetContent side="bottom" className="max-h-[80vh] overflow-y-auto">
          <SheetHeader className="mb-4">
            <SheetTitle>{t(($) => $.stop.paymentProof.title)}</SheetTitle>
          </SheetHeader>
          <PaymentProofUploadForm
            isLoading={proofMutation.isPending}
            onSubmit={(file) => {
              proofMutation.mutate(file, {
                onSuccess: () => setSheetMode(null),
              });
            }}
            onCancel={() => setSheetMode(null)}
          />
        </SheetContent>
      </Sheet>

      {/* Change payment method — TASK-DRIVER-APP-PHASE-4-PAYMENT-METHOD-CLOSURE-001. Bridges into
          the canonical order authority; the UI never writes fulfilment/Order.status. Offers only
          the five canonical methods (§5); on backend rejection the hook reloads canonical truth. */}
      <Sheet open={sheetMode === 'change-method'} onOpenChange={(o) => !o && setSheetMode(null)}>
        <SheetContent side="bottom" className="max-h-[80vh] overflow-y-auto">
          <SheetHeader className="mb-2">
            <SheetTitle>{t(($) => $.stop.changeMethod.title)}</SheetTitle>
          </SheetHeader>
          <p className="mb-4 text-xs text-muted-foreground">{t(($) => $.stop.changeMethod.hint)}</p>
          <div className="space-y-2">
            {CANONICAL_METHODS.map((m) => {
              const selected = methodDraft === m;
              return (
                <button
                  key={m}
                  type="button"
                  onClick={() => setMethodDraft(m)}
                  className={cn(
                    'flex w-full items-center justify-between rounded-xl border p-3.5 text-sm font-medium transition-colors',
                    selected ? 'border-primary bg-primary/5 text-primary' : 'hover:bg-accent/40',
                  )}
                >
                  <span>{t(($) => $.stop.payment.methods[m as 'cod'])}</span>
                  {selected && <Check className="h-4 w-4" aria-hidden="true" />}
                </button>
              );
            })}
          </div>
          {PROOF_REQUIRED_METHODS.includes(methodDraft) && (
            <p className="mt-3 flex items-start gap-2 text-xs text-amber-700">
              <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
              {t(($) => $.stop.changeMethod.proofRequired)}
            </p>
          )}
          <div className="mt-5 flex gap-2">
            <Button variant="outline" className="flex-1" onClick={() => setSheetMode(null)}>
              {t(($) => $.stop.changeMethod.cancel)}
            </Button>
            <Button
              className="flex-1"
              disabled={!methodDraft || methodDraft === rawMethod || changeMethodMutation.isPending}
              onClick={submitMethod}
            >
              {changeMethodMutation.isPending ? t(($) => $.stop.changeMethod.updating) : t(($) => $.stop.changeMethod.submit)}
            </Button>
          </div>
        </SheetContent>
      </Sheet>
    </div>
  );
}

import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { MapPin, Package } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { useFormatter } from '@/hooks/use-formatter';

import { ROUTES } from '@/router/routes';
import type { DeliveryStop } from '../types/driver-mobile';
import { StopStatusBadge } from './stop-status-badge';
import { DriverPhoneCell } from './driver-phone-cell';
import { useStartDelivery } from '../hooks/use-driver-mobile';
import { acceptsDeliveryExecution } from '../lib/trip-lifecycle';
import { buildMapsUrl } from '../lib/maps-url';
import { resolvePaymentMethodLabel } from '../lib/payment-method-label';

interface DeliveryStopCardProps {
  stop: DeliveryStop;
  tripId: string;
  /**
   * The owning trip's status — Start Delivery must never be offered on a trip that
   * isn't on the road yet, the exact mirror of the backend guard (assertTripOnTheRoad).
   * Optional: `driver-stop-list-page.tsx` (a separate, out-of-scope page reached via
   * :tripId with no trip-status data of its own) also renders this card without it —
   * omitting it there preserves that page's exact current behavior (no Start Delivery
   * affordance), rather than changing a page this task does not touch.
   */
  tripStatus?: string | null;
}

const STATUS_BORDER: Record<string, string> = {
  pending:     'border-l-gray-300',
  in_progress: 'border-l-blue-500',
  delivered:   'border-l-green-500',
  partial:     'border-l-green-500',
  failed:      'border-l-red-500',
  returned:    'border-l-purple-500',
  skipped:     'border-l-gray-200',
};

export function DeliveryStopCard({ stop, tripId, tripStatus = null }: DeliveryStopCardProps) {
  const { t } = useTranslation('driver-mobile');
  const { t: tOrders } = useTranslation('orders');
  const { money } = useFormatter();
  const navigate = useNavigate();
  const startMutation = useStartDelivery(tripId, stop.id);

  const goToDetail = () => {
    navigate(
      ROUTES.driverTripStop
        .replace(':tripId', tripId)
        .replace(':stopId', stop.id),
    );
  };

  const order = stop.order;
  const phone = order?.phone ?? null;
  const mapsHref = buildMapsUrl(order?.gps ?? null);
  const paymentLabel = resolvePaymentMethodLabel(order?.payment_method ?? null, tOrders);

  // Same eligibility the Stop Detail page enforces (assertTripOnTheRoad + not-settled) —
  // reusing the canonical action/authority, never a Driver-specific status or engine.
  const canStart = stop.status === 'pending' && acceptsDeliveryExecution(tripStatus);

  return (
    <div
      className={`cursor-pointer rounded-xl border border-l-4 bg-card p-4 shadow-sm hover:shadow-md transition-shadow ${STATUS_BORDER[stop.status] ?? 'border-l-gray-300'}`}
      onClick={goToDetail}
      role="button"
      tabIndex={0}
      onKeyDown={(e) => e.key === 'Enter' && goToDetail()}
    >
      {/* Header — sequence + order number (secondary) + status */}
      <div className="flex items-start justify-between gap-2">
        <div className="flex items-center gap-2">
          <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-bold">
            {stop.sequence}
          </span>
          <p className="text-xs text-muted-foreground">
            {order?.order_number ?? t(($) => $.stop.sequence, { sequence: stop.sequence })}
          </p>
        </div>
        <StopStatusBadge status={stop.status} />
      </div>

      {/* Customer name — the dominant element on the card (§3). */}
      <p className="mt-2 text-lg font-bold leading-snug text-foreground">
        {order?.customer_name ?? t(($) => $.stop.noName)}
      </p>

      {/* Full address — never truncated, wraps naturally (§4). */}
      {order?.address && (
        <p className="mt-1 flex items-start gap-1.5 text-sm leading-relaxed text-muted-foreground">
          <MapPin className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
          <span>{order.address}</span>
        </p>
      )}

      {/* Contact / Location actions (§5) — reuse canonical authorities; an absent
          field renders a neutral, non-interactive state rather than a fake action. */}
      <div className="mt-2 flex items-center gap-3" onClick={(e) => e.stopPropagation()}>
        <DriverPhoneCell phone={phone} variant="icon" ariaLabel={t(($) => $.stop.phoneActions.call)} />
        {!phone && (
          <span className="text-xs text-muted-foreground/70">{t(($) => $.stop.contactAfterStart)}</span>
        )}
        {mapsHref ? (
          <a
            href={mapsHref}
            target="_blank"
            rel="noreferrer"
            aria-label={t(($) => $.stop.locationAction)}
            className="inline-flex size-7 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
          >
            <MapPin className="size-3.5" aria-hidden="true" />
          </a>
        ) : (
          <span
            aria-label={t(($) => $.stop.locationUnavailable)}
            title={t(($) => $.stop.locationUnavailable)}
            className="inline-flex size-7 items-center justify-center rounded-md text-muted-foreground/40"
          >
            <MapPin className="size-3.5" aria-hidden="true" />
          </span>
        )}
      </div>

      {/* Commercial summary (§6) — canonical, pre-aggregated read-model fields only;
          no frontend recomputation, no line-by-line counting. */}
      {order && (
        <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 border-t pt-2 text-sm">
          <span className="font-semibold text-foreground">{money(order.grand_total)}</span>
          <span className="text-muted-foreground">{paymentLabel}</span>
          <span className="flex items-center gap-1 text-muted-foreground">
            <Package className="h-3.5 w-3.5" aria-hidden="true" />
            {t(($) => $.stop.itemsCount, { count: order.items_count })}
          </span>
        </div>
      )}

      {/* Start Delivery (§10) — the SAME canonical transition the Stop Detail page uses
          (useStartDelivery → POST /driver/stops/{id}/start), never a Driver-specific
          status or a second lifecycle. Visible only while eligible; navigates into the
          canonical Stop Detail flow on success, matching the approved entry point. */}
      {canStart && (
        <div className="mt-3" onClick={(e) => e.stopPropagation()}>
          <Button
            className="h-10 w-full text-sm font-semibold"
            disabled={startMutation.isPending}
            onClick={() => startMutation.mutate(undefined, { onSuccess: goToDetail })}
          >
            {startMutation.isPending ? t(($) => $.stop.starting) : t(($) => $.stop.startDelivery)}
          </Button>
        </div>
      )}
    </div>
  );
}

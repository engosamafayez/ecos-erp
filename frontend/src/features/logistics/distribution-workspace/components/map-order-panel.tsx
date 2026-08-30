import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';

import { ExternalLink } from 'lucide-react';

import { useToast } from '@/components/ds/use-toast';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';

import {
  useChangeOrderZone,
  useResolveOrderLocationAction,
} from '../hooks/use-distribution-workspace';
import { isAddressResolvable } from '../lib/order-location';
import type { DistributionOrder, MapGroup, MapOrder, MapZone } from '../types';
import { DistributionOrderDetail } from './distribution-order-detail';
import { OrderAddressCell } from './order-address-cell';

/**
 * TASK-DISTRIBUTION-MAP-…-002 — the panel a map pin (or a search result) opens.
 *
 * It is a distribution ACTION surface, not a second order-detail implementation:
 * for the full enterprise view it hands off to the canonical
 * `DistributionOrderDetail` (→ `OrderDetailDrawer`). What it adds is the one
 * thing the map exists to offer — moving this single order to another Zone.
 *
 * The resolved `DistributionOrder` (which carries `assignment_id`, address and
 * status) is supplied by the tab, which already reads the window's canonical
 * order aggregate for search. No new endpoint, no backend field.
 *
 * OPTION C. There is no Group selector: the order FOLLOWS its Zone. The two
 * legitimate refusals — destination Group full, or the window closed to manual
 * assignment — are the server's, surfaced verbatim rather than pre-guessed.
 */
export function MapOrderPanel({
  order,
  detail,
  detailLoading,
  zones,
  groups,
  onOpenChange,
}: {
  /** The clicked pin. Null keeps the panel closed. */
  order: MapOrder | null;
  /** The canonical order for `order`, resolved by the tab; null while resolving or absent. */
  detail: DistributionOrder | null;
  detailLoading: boolean;
  zones: MapZone[];
  groups: MapGroup[];
  onOpenChange: (open: boolean) => void;
}) {
  const { t } = useTranslation('logistics');
  const { toast } = useToast();

  const open = order !== null;

  const [destinationZoneId, setDestinationZoneId] = useState('');
  const [detailOpen, setDetailOpen] = useState(false);

  const changeZone = useChangeOrderZone();
  const resolve = useResolveOrderLocationAction();

  // A stale resolve result must not leak onto the next order the panel shows.
  useEffect(() => {
    resolve.reset();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [order?.order_id]);

  // Location is resolved ONLY by the explicit button below — never on open. A point
  // already exists, the address can be resolved, or it cannot: three honest states.
  //
  // When a point exists, its coordinates become a Google Maps link built dynamically
  // from THIS order's lat/lng (no hardcoded point, no API key, no extra order data).
  const coords =
    detail != null && detail.latitude != null && detail.longitude != null
      ? (() => {
          const lat = Number(detail.latitude).toFixed(6);
          const lng = Number(detail.longitude).toFixed(6);
          return {
            lat,
            lng,
            url: `https://www.google.com/maps/search/?api=1&query=${lat},${lng}`,
          };
        })()
      : null;
  const locationResolvable =
    detail != null &&
    isAddressResolvable({
      address: detail.shipping_address.street,
      building: detail.shipping_address.building,
      apartment: detail.shipping_address.apartment,
      area: detail.shipping_address.area,
      city_name: detail.city_name ?? detail.shipping_address.city,
      city_text: detail.city_text,
      governorate_name: detail.governorate_name ?? detail.shipping_address.governorate,
    });

  function resolveLocation() {
    if (detail === null) {
      return;
    }
    resolve.mutate(detail.order_id, {
      onSuccess: (result) => {
        switch (result.status) {
          case 'resolved_from_address':
            // The map + panel refresh via the hook's workspace invalidation.
            toast({
              title: t(($) => $.distributionWorkspace.map.orderPanel.location.resolved, {
                number: detail.order_number,
              }),
            });
            break;
          case 'geocoding_failed':
            toast({
              title: t(($) => $.distributionWorkspace.map.orderPanel.location.geocodingFailed),
            });
            break;
          case 'not_configured':
            toast({
              title: t(($) => $.distributionWorkspace.map.orderPanel.location.notConfigured),
            });
            break;
          case 'address_unavailable':
            toast({
              title: t(($) => $.distributionWorkspace.map.orderPanel.location.addressUnavailable),
            });
            break;
          default:
            break;
        }
      },
      onError: () => {
        toast({
          title: t(($) => $.distributionWorkspace.map.orderPanel.location.geocodingFailed),
        });
      },
    });
  }

  const currentZoneId = detail?.zone_id ?? order?.zone_id ?? null;

  const currentGroup = useMemo(() => {
    const slotId = detail?.virtual_slot_id ?? order?.slot_id ?? null;
    if (slotId === null) {
      return null;
    }
    return groups.find((g) => g.slot_id === slotId) ?? null;
  }, [groups, detail?.virtual_slot_id, order?.slot_id]);

  // Every zone except the one the order is already in — moving to the same zone
  // is not a move, so it is never offered.
  const destinationZones = useMemo(
    () => zones.filter((z) => z.zone_id !== currentZoneId),
    [zones, currentZoneId],
  );

  const serverMessage = (changeZone.error as { response?: { data?: { message?: string } } } | null)
    ?.response?.data?.message;

  function close() {
    setDestinationZoneId('');
    changeZone.reset();
    onOpenChange(false);
  }

  function submit() {
    if (detail === null || destinationZoneId === '') {
      return;
    }

    changeZone.mutate(
      {
        assignmentId: detail.assignment_id,
        zoneId: Number(destinationZoneId),
        reason: 'Manual zone change from Distribution map',
      },
      {
        onSuccess: () => {
          const zone = zones.find((z) => z.zone_id === Number(destinationZoneId));
          toast({
            title: t(($) => $.distributionWorkspace.map.changeZone.success, {
              number: detail.order_number,
              zone: zone?.zone_name ?? zone?.zone_code ?? `#${destinationZoneId}`,
            }),
          });
          close();
        },
      },
    );
  }

  const currentZone = zones.find((z) => z.zone_id === currentZoneId) ?? null;
  const canSubmit = detail !== null && destinationZoneId !== '' && !changeZone.isPending;

  return (
    <Sheet open={open} onOpenChange={(next) => (next ? undefined : close())}>
      <SheetContent
        side="right"
        className="flex flex-col gap-0 p-0 sm:w-[48vw] sm:min-w-[480px] sm:max-w-[820px]"
        data-testid="map-order-panel"
      >
        {/* Canonical ECOS drawer header — bordered, order number in mono, status badge. */}
        <SheetHeader className="border-b px-4 py-3 pe-10">
          <SheetTitle className="flex items-center gap-2 font-mono text-base">
            <span className="truncate">{order?.order_number ?? order?.order_id ?? ''}</span>
            {detail ? (
              <Badge variant="outline" className="shrink-0">
                {detail.order_status}
              </Badge>
            ) : null}
          </SheetTitle>
          <SheetDescription className="truncate text-xs">
            {detail?.customer_name ?? order?.customer_name ?? '—'}
          </SheetDescription>
        </SheetHeader>

        {detailLoading ? (
          <div className="min-h-0 flex-1 space-y-2 overflow-y-auto p-4">
            {Array.from({ length: 4 }).map((_, i) => (
              <Skeleton key={i} className="h-10 w-full rounded-md" />
            ))}
          </div>
        ) : detail === null ? (
          <p
            className="min-h-0 flex-1 overflow-y-auto p-4 text-sm text-muted-foreground"
            data-testid="map-order-panel-missing"
          >
            {t(($) => $.distributionWorkspace.map.orderPanel.notInWindow)}
          </p>
        ) : (
          <div className="min-h-0 flex-1 space-y-4 overflow-y-auto p-4">
            <dl className="space-y-3 text-sm">
              <Field label={t(($) => $.distributionWorkspace.map.orderPanel.phone)}>
                {detail.phone ?? '—'}
              </Field>
              {/* COMPLETE delivery address — the full shipping address the payload
                  already carries, not the (often-null) billing line. Includes city /
                  governorate, so the separate rows are no longer duplicated here. */}
              <Field label={t(($) => $.distributionWorkspace.map.orderPanel.address)}>
                <OrderAddressCell address={detail.shipping_address} />
              </Field>
              <Field label={t(($) => $.distributionWorkspace.map.orderPanel.orderValue)}>
                {detail.total.toLocaleString()}
              </Field>
              <Field label={t(($) => $.distributionWorkspace.map.orderPanel.currentZone)}>
                {currentZone?.zone_name ??
                  currentZone?.zone_code ??
                  detail.zone_name ??
                  t(($) => $.distributionWorkspace.map.orderPanel.noZone)}
              </Field>
              <Field label={t(($) => $.distributionWorkspace.map.orderPanel.currentGroup)}>
                {currentGroup
                  ? (currentGroup.name ?? currentGroup.code)
                  : t(($) => $.distributionWorkspace.map.orderPanel.noGroup)}
              </Field>
            </dl>

            {/* ── Location — resolved ONLY by explicit action, never on open ── */}
            <div className="rounded-lg border p-3" data-testid="map-order-location">
              <h4 className="text-sm font-medium">
                {t(($) => $.distributionWorkspace.map.orderPanel.location.label)}
              </h4>
              {coords ? (
                // The ACTUAL coordinates (existing captured/geocoded values) as a clickable
                // Google Maps link built from THIS order's lat/lng. Never invented; no source
                // field ships in the distribution payload, so coordinates alone are shown.
                <a
                  href={coords.url}
                  target="_blank"
                  rel="noopener noreferrer"
                  dir="ltr"
                  className="mt-1 inline-flex items-center gap-1 font-mono text-sm tabular-nums text-primary hover:underline"
                  data-testid="map-order-coords"
                  aria-label={t(($) => $.distributionWorkspace.map.orderPanel.location.openInMaps)}
                >
                  {coords.lat}, {coords.lng}
                  <ExternalLink className="size-3.5 shrink-0" aria-hidden />
                </a>
              ) : locationResolvable ? (
                <>
                  <p className="mt-1 text-xs text-muted-foreground">
                    {t(($) => $.distributionWorkspace.map.orderPanel.location.notResolved)}
                  </p>
                  <Button
                    variant="outline"
                    size="sm"
                    className="mt-2 w-full"
                    onClick={resolveLocation}
                    disabled={resolve.isPending}
                    data-testid="map-order-resolve-location"
                  >
                    {resolve.isPending
                      ? t(($) => $.distributionWorkspace.map.orderPanel.location.resolving)
                      : t(($) => $.distributionWorkspace.map.orderPanel.location.resolve)}
                  </Button>
                </>
              ) : (
                <p className="mt-1 text-xs text-muted-foreground">
                  {t(($) => $.distributionWorkspace.map.orderPanel.location.addressUnavailable)}
                </p>
              )}
            </div>

            <Button
              variant="outline"
              size="sm"
              className="w-full"
              onClick={() => setDetailOpen(true)}
              data-testid="map-order-view-full"
            >
              {t(($) => $.distributionWorkspace.map.orderPanel.viewFullDetails)}
            </Button>

            {/* ── Change Zone (Option C) ── */}
            <div className="rounded-lg border p-3" data-testid="map-change-zone">
              <h4 className="text-sm font-medium">
                {t(($) => $.distributionWorkspace.map.changeZone.title)}
              </h4>
              <p className="mt-1 text-xs text-muted-foreground">
                {t(($) => $.distributionWorkspace.map.changeZone.hint)}
              </p>

              {destinationZones.length === 0 ? (
                <p className="mt-3 text-xs text-muted-foreground" data-testid="map-change-zone-none">
                  {t(($) => $.distributionWorkspace.map.changeZone.noOtherZones)}
                </p>
              ) : (
                <div className="mt-3 space-y-2">
                  <Label htmlFor="map-change-zone-select">
                    {t(($) => $.distributionWorkspace.map.changeZone.selectLabel)}
                  </Label>
                  <Select value={destinationZoneId} onValueChange={setDestinationZoneId}>
                    <SelectTrigger id="map-change-zone-select" data-testid="map-change-zone-select">
                      <SelectValue
                        placeholder={t(($) => $.distributionWorkspace.map.changeZone.selectPlaceholder)}
                      />
                    </SelectTrigger>
                    <SelectContent>
                      {destinationZones.map((z) => (
                        <SelectItem key={z.zone_id} value={String(z.zone_id)}>
                          {z.zone_name ?? z.zone_code ?? `#${z.zone_id}`}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>

                  {changeZone.isError ? (
                    <p className="text-sm text-destructive" data-testid="map-change-zone-error">
                      {serverMessage ?? t(($) => $.distributionWorkspace.map.changeZone.failed)}
                    </p>
                  ) : null}

                  <Button
                    size="sm"
                    className="w-full"
                    onClick={submit}
                    disabled={!canSubmit}
                    data-testid="map-change-zone-submit"
                  >
                    {changeZone.isPending
                      ? t(($) => $.distributionWorkspace.map.changeZone.submitting)
                      : t(($) => $.distributionWorkspace.map.changeZone.confirm)}
                  </Button>
                </div>
              )}
            </div>
          </div>
        )}
      </SheetContent>

      {/* Full enterprise detail — the canonical drawer, not a re-implementation. */}
      <DistributionOrderDetail
        orderId={detailOpen ? (order?.order_id ?? null) : null}
        open={detailOpen}
        onOpenChange={setDetailOpen}
      />
    </Sheet>
  );
}

function Field({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="flex flex-col gap-0.5">
      <dt className="text-xs font-medium text-muted-foreground">{label}</dt>
      <dd className="text-sm">{children}</dd>
    </div>
  );
}

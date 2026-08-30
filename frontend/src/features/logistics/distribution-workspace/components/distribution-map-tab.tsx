import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { AlertTriangle, Layers, MapPin, Search } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

import {
  useDistributionMap,
  useDistributionOrders,
} from '../hooks/use-distribution-workspace';
import type { DistributionOrder, MapData, MapGroup, MapOrder, MapZone } from '../types';
import { DistributionLeafletMap } from './distribution-leaflet-map';
import { MapClusterPanel } from './map-cluster-panel';
import { MapOrderPanel } from './map-order-panel';

/**
 * The Map tab — a REAL geographic view of the cycle being planned.
 *
 * ┌─ WHERE EVERY POSITION COMES FROM ────────────────────────────────────────┐
 * │ Map     Leaflet + OpenStreetMap tiles — actual streets and places, not an │
 * │         SVG scatter over a bounding box.                                  │
 * │ Orders  `orders.google_maps_lat/lng`, captured from the maps link on the  │
 * │         order's own address. One pin each, at its real location.          │
 * │ Zones   NO stored geometry. A zone's AREA is derived live (see            │
 * │         `lib/zone-geometry`) from the coordinates of its own orders, so it │
 * │         recomputes whenever membership changes — never a centroid circle.  │
 * │ Groups  the zones attached to them — a group has no position of its own.   │
 * └────────────────────────────────────────────────────────────────────────────┘
 *
 * NOTHING IS INVENTED. An order with no recorded location is listed by name
 * under "no recorded location" — never dropped and never given a substitute
 * position. It is not passed to the map at all.
 *
 * MOSTLY READ. Selecting a zone (map or panel) zooms and highlights it; a
 * search or a pin click opens a panel from which the order's Zone can be changed
 * (Option C — the order follows its Zone into that Zone's Group). Every mutation
 * goes through the existing manual-assignment endpoint; the tab computes no
 * membership itself, and no Group / Trip / Shipment behaviour is touched.
 */

/** Fallback for a zone whose colour was never set. */
const NEUTRAL = '#94a3b8';

type Selection =
  | { kind: 'zone'; id: number }
  | { kind: 'group'; id: string }
  | null;

function GroupLegend({
  groups,
  selection,
  onSelect,
  colorFor,
}: {
  groups: MapGroup[];
  selection: Selection;
  onSelect: (s: Selection) => void;
  colorFor: (slotId: string | null) => string;
}) {
  const { t } = useTranslation('logistics');

  return (
    <div className="flex flex-wrap items-center gap-2" data-testid="map-legend">
      <span className="text-xs uppercase text-muted-foreground">
        {t(($) => $.distributionWorkspace.map.legendTitle)}
      </span>

      {groups.map((g) => {
        const active = selection?.kind === 'group' && selection.id === g.slot_id;

        return (
          <button
            key={g.slot_id}
            type="button"
            onClick={() => onSelect(active ? null : { kind: 'group', id: g.slot_id })}
            className={cn(
              'flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs transition-colors',
              active ? 'border-primary bg-primary/10' : 'hover:bg-muted',
            )}
            data-testid={`map-group-${g.code}`}
          >
            <span
              className="size-2.5 rounded-full"
              style={{ backgroundColor: colorFor(g.slot_id) }}
              aria-hidden
            />
            <span className="font-medium">{g.code}</span>
            <span className="text-muted-foreground">{g.orders_count}</span>
          </button>
        );
      })}
    </div>
  );
}

function ZoneList({
  zones,
  selection,
  onSelect,
  groupLabelBySlot,
}: {
  zones: MapZone[];
  selection: Selection;
  onSelect: (s: Selection) => void;
  /** slot_id → the group's canonical name/code, from the SAME map payload. */
  groupLabelBySlot: Map<string, string>;
}) {
  const { t } = useTranslation('logistics');

  return (
    <ul className="divide-y" data-testid="map-zone-list">
      {zones.map((z) => {
        const active = selection?.kind === 'zone' && selection.id === z.zone_id;

        // The zone's actual Group NAMES (not a count), resolved from the map payload's
        // own groups. Missing lookups are skipped rather than guessed.
        const groupLabels = z.slot_ids
          .map((slotId) => groupLabelBySlot.get(slotId))
          .filter((label): label is string => label != null && label !== '');
        const groupsText = groupLabels.join(' · ');

        return (
          <li key={z.zone_id}>
            <button
              type="button"
              onClick={() => onSelect(active ? null : { kind: 'zone', id: z.zone_id })}
              className={cn(
                'flex w-full flex-col gap-0.5 px-3 py-2 text-start text-sm transition-colors',
                active ? 'bg-primary/10' : 'hover:bg-muted',
              )}
              data-testid={`map-zone-${z.zone_id}`}
            >
              <div className="flex w-full items-center gap-2">
                <span
                  className="size-2.5 shrink-0 rounded-full"
                  style={{ backgroundColor: z.color ?? NEUTRAL }}
                  aria-hidden
                />

                <span className="min-w-0 flex-1 truncate">
                  {z.zone_name ?? z.zone_code ?? `#${z.zone_id}`}
                </span>

                <span className="shrink-0 text-xs text-muted-foreground">
                  {t(($) => $.distributionWorkspace.map.zoneOrders, { count: z.order_count })}
                </span>
              </div>

              {/* Group NAMES, not a count. Truncated with a full-text tooltip so long
                  or multiple names never break the fixed-width zone panel. */}
              <span
                className="min-w-0 truncate ps-[18px] text-xs text-muted-foreground"
                title={groupsText || undefined}
              >
                {groupLabels.length > 0
                  ? groupsText
                  : t(($) => $.distributionWorkspace.map.noGroup)}
              </span>
            </button>
          </li>
        );
      })}
    </ul>
  );
}

function UnlocatedOrders({
  orders,
  onOpen,
}: {
  orders: MapOrder[];
  /** Opens the order's detail. No pan — there is nowhere to pan to. */
  onOpen: (order: MapOrder) => void;
}) {
  const { t } = useTranslation('logistics');

  if (orders.length === 0) return null;

  return (
    <Card className="p-4" data-testid="map-unlocated">
      <div className="flex items-center gap-2">
        <AlertTriangle className="size-4 shrink-0 text-amber-600" aria-hidden />
        <h4 className="text-sm font-medium">
          {t(($) => $.distributionWorkspace.map.unlocatedTitle, { count: orders.length })}
        </h4>
      </div>

      <ul className="mt-2 flex flex-wrap gap-1.5">
        {orders.map((o) => (
          <li key={o.order_id}>
            {/*
              Clickable, because an order with no coordinates is the one an operator
              most needs to open: the reason it has no pin is in its address fields,
              and the canonical drawer is where those live.
            */}
            <button
              type="button"
              onClick={() => onOpen(o)}
              className="rounded border px-2 py-0.5 text-xs text-muted-foreground hover:bg-muted hover:text-foreground"
              title={t(($) => $.distributionWorkspace.map.unlocated)}
              data-testid={`map-unlocated-${o.order_number ?? o.order_id}`}
            >
              {o.order_number ?? o.order_id}
              {o.city ? <span className="opacity-70"> · {o.city}</span> : null}
            </button>
          </li>
        ))}
      </ul>
    </Card>
  );
}

/**
 * COMPACT ZONE ORDERS — the strip under the map, shown only while a zone is selected.
 *
 * ┌─ WHY THIS IS A STRIP AND NOT A GRID ─────────────────────────────────────┐
 * │ The map is the primary surface. A table under it would take the space the  │
 * │ map needs and turn this tab into a second orders workspace — which already │
 * │ exists elsewhere. So: one line per order, name and number only, capped,    │
 * │ with the remainder counted rather than rendered.                          │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * Clicking an order highlights and pans to it. It deliberately does NOT open the
 * drawer: locating an order on the map and reading its full record are different
 * intentions, and opening a drawer over the map on every click would fight the first.
 * The drawer is one further click, from the pin or the panel.
 *
 * Orders with no coordinates still appear — they belong to the zone — but are marked
 * and cannot pan anywhere. Hiding them would make the count disagree with the list.
 */
function ZoneOrdersStrip({
  zoneName,
  orders,
  selectedOrderId,
  onSelect,
}: {
  zoneName: string;
  orders: MapOrder[];
  selectedOrderId: string | null;
  onSelect: (order: MapOrder) => void;
}) {
  const { t } = useTranslation('logistics');
  const [expanded, setExpanded] = useState(false);

  const VISIBLE = 12;
  const shown = expanded ? orders : orders.slice(0, VISIBLE);
  const hidden = orders.length - shown.length;

  if (orders.length === 0) {
    return (
      <Card className="p-3" data-testid="map-zone-orders-empty">
        <p className="text-xs text-muted-foreground">
          {t(($) => $.distributionWorkspace.map.zoneOrdersPanel.empty, { zone: zoneName })}
        </p>
      </Card>
    );
  }

  return (
    <Card className="p-3" data-testid="map-zone-orders">
      <div className="flex items-center justify-between gap-2">
        <h4 className="truncate text-sm font-medium">{zoneName}</h4>
        <span className="shrink-0 text-xs text-muted-foreground">
          {t(($) => $.distributionWorkspace.map.zoneOrdersPanel.count, { count: orders.length })}
        </span>
      </div>

      <ul className="mt-2 flex flex-wrap gap-1.5">
        {shown.map((o) => {
          const isSelected = o.order_id === selectedOrderId;

          return (
            <li key={o.order_id}>
              <button
                type="button"
                onClick={() => onSelect(o)}
                className={
                  isSelected
                    ? 'rounded border border-primary bg-primary/10 px-2 py-1 text-xs font-medium'
                    : 'rounded border px-2 py-1 text-xs hover:bg-muted'
                }
                data-testid={`map-zone-order-${o.order_number ?? o.order_id}`}
              >
                <span dir="ltr">{o.order_number ?? o.order_id}</span>
                {o.customer_name ? (
                  <span className="text-muted-foreground"> · {o.customer_name}</span>
                ) : null}
                {/* Says why clicking will not move the map, rather than doing nothing. */}
                {!o.has_location ? (
                  <span className="ms-1 text-amber-700 dark:text-amber-400">
                    {t(($) => $.distributionWorkspace.map.zoneOrdersPanel.noPin)}
                  </span>
                ) : null}
              </button>
            </li>
          );
        })}
      </ul>

      {hidden > 0 ? (
        <button
          type="button"
          onClick={() => setExpanded(true)}
          className="mt-2 text-xs text-muted-foreground underline"
          data-testid="map-zone-orders-more"
        >
          {t(($) => $.distributionWorkspace.map.zoneOrdersPanel.more, { count: hidden })}
        </button>
      ) : null}
    </Card>
  );
}

export function DistributionMapTab({
  windowId,
  warehouseId,
  active,
  focusGroupId,
  showToolbar = true,
}: {
  windowId: string | undefined;
  warehouseId: string | null;
  /** Only fetch while the tab is open — one request per opened tab, not per render. */
  active: boolean;
  /**
   * Optional: open focused on this Group (by slot_id) so its zones are pre-selected
   * and highlighted. ADDITIVE — the standalone Map tab passes nothing and its
   * selection still starts null. This only seeds the existing selection state; it
   * triggers no fetch, no geocoding and changes no map behaviour, clustering or
   * coordinate handling. Re-focus by remounting (key) when the selected group changes.
   */
  focusGroupId?: string;
  /**
   * The standalone Map tab shows the search / all-zones / fit-all / GROUPS toolbar
   * (default true). The embedded Group-detail map passes false — the group is already
   * selected, so it starts directly at the map viewport. ADDITIVE: no second map
   * implementation, no change to data scoping, clustering or geocoding.
   */
  showToolbar?: boolean;
}) {
  const { t } = useTranslation('logistics');
  const [selection, setSelection] = useState<Selection>(
    focusGroupId ? { kind: 'group', id: focusGroupId } : null,
  );
  const [selectedOrder, setSelectedOrder] = useState<MapOrder | null>(null);
  const [clusterOrders, setClusterOrders] = useState<MapOrder[] | null>(null);
  const [query, setQuery] = useState('');
  const [focusPoint, setFocusPoint] = useState<{ lat: number; lng: number } | null>(null);
  const [fitAllToken, setFitAllToken] = useState(0);
  const [mobileZonesOpen, setMobileZonesOpen] = useState(false);

  const { data, isLoading } = useDistributionMap(windowId, warehouseId, active);

  // The canonical window order aggregate — the source for client-side search
  // (it carries phone) and for the order panel's assignment id / address. Fetched
  // once while the tab is open, and shared with every other Distribution surface.
  const ordersQuery = useDistributionOrders(windowId, warehouseId, null, null, active);

  // GROUP-SCOPED MAP (TASK-DISTRIBUTION-PHASE1-GROUP-DETAIL-MAP-FINAL-UX-001).
  // When embedded in a Group's Detail Section, `focusGroupId` must make the map
  // show ONLY that group's data — not the whole window with the group merely
  // highlighted. We filter the already-fetched payload client-side (no new fetch,
  // no backend change): the group's own orders (by slot_id), its zones, itself.
  // Everything downstream — pins, clusters, zone boundaries, legend and viewport
  // fit — then operates on this scoped set. Without `focusGroupId` (the standalone
  // Map tab) the full payload is used unchanged.
  const map: MapData | undefined = useMemo(() => {
    if (!data || !focusGroupId) return data;
    const grp = data.groups.find((g) => g.slot_id === focusGroupId);
    const zoneIds = new Set(grp?.zone_ids ?? []);
    const scopedOrders = data.orders.filter((o) => o.slot_id === focusGroupId);
    const scopedZones = data.zones.filter((z) => zoneIds.has(z.zone_id));
    const plotted = scopedOrders.filter((o) => o.has_location).length;
    const zonesPlotted = scopedZones.filter((z) => z.has_location).length;
    return {
      zones: scopedZones,
      groups: grp ? [grp] : [],
      orders: scopedOrders,
      summary: {
        orders_total: scopedOrders.length,
        orders_plotted: plotted,
        orders_without_location: scopedOrders.length - plotted,
        zones_total: scopedZones.length,
        zones_plotted: zonesPlotted,
      },
    };
  }, [data, focusGroupId]);

  const ordersById = useMemo(() => {
    const m = new Map<string, DistributionOrder>();
    for (const o of ordersQuery.data ?? []) {
      m.set(o.order_id, o);
    }
    return m;
  }, [ordersQuery.data]);

  // ── READ-ONLY MAP MOUNT (TASK-DISTRIBUTION-MAP-EXPLICIT-GEOCODING-GATE-001) ──
  // Opening the map resolves NOTHING. It plots only coordinates that already exist;
  // an order with no captured point stays under "No recorded location" until an
  // operator explicitly runs "Resolve location" from the order panel. No geocoding,
  // no persistence, no mutation is triggered by mounting or rendering this tab.
  const effectiveOrders: MapOrder[] = map?.orders ?? [];

  /** Zone id → its own colour, for pins and boundaries (colour by Zone). */
  const zoneColorById = useMemo(() => {
    const m = new Map<number, string>();
    for (const z of map?.zones ?? []) {
      m.set(z.zone_id, z.color ?? NEUTRAL);
    }
    return m;
  }, [map]);

  const zoneNameById = useMemo(() => {
    const m = new Map<number, string>();
    for (const z of map?.zones ?? []) {
      m.set(z.zone_id, z.zone_name ?? z.zone_code ?? `#${z.zone_id}`);
    }
    return m;
  }, [map]);

  /** slot_id → the group's canonical name/code, from the SAME map payload's groups. */
  const groupLabelBySlot = useMemo(() => {
    const m = new Map<string, string>();
    for (const g of map?.groups ?? []) {
      m.set(g.slot_id, g.name ?? g.code);
    }
    return m;
  }, [map]);

  /**
   * A group's colour is the colour of its FIRST zone, so the legend agrees with
   * the map without inventing a second palette.
   */
  const colorFor = useMemo(() => {
    const byZone = new Map<number, string>(
      (map?.zones ?? []).map((z) => [z.zone_id, z.color ?? NEUTRAL]),
    );

    const bySlot = new Map<string, string>();
    for (const g of map?.groups ?? []) {
      const first = g.zone_ids.find((id) => byZone.has(id));
      bySlot.set(g.slot_id, first === undefined ? NEUTRAL : (byZone.get(first) ?? NEUTRAL));
    }

    return (slotId: string | null) => (slotId === null ? NEUTRAL : (bySlot.get(slotId) ?? NEUTRAL));
  }, [map]);

  const zoneIdsInSelection = useMemo(() => {
    if (map === undefined || selection === null) return null;
    if (selection.kind === 'zone') return new Set([selection.id]);

    const group = map.groups.find((g) => g.slot_id === selection.id);
    return new Set(group?.zone_ids ?? []);
  }, [map, selection]);

  const selectedZoneId = selection?.kind === 'zone' ? selection.id : null;

  const searchResults = useMemo(() => {
    const q = query.trim().toLowerCase();
    if (q === '') return [] as MapOrder[];

    return effectiveOrders
      .filter((o) => {
        const detail = ordersById.get(o.order_id);
        const zoneName = o.zone_id === null ? '' : (zoneNameById.get(o.zone_id) ?? '');
        return [o.order_number, o.customer_name, o.city, zoneName, detail?.phone].some(
          (v) => v != null && String(v).toLowerCase().includes(q),
        );
      })
      .slice(0, 8);
  }, [query, effectiveOrders, ordersById, zoneNameById]);

  function openOrder(order: MapOrder, focus: boolean) {
    setSelectedOrder(order);
    if (focus && order.has_location && order.latitude !== null && order.longitude !== null) {
      setFocusPoint({ lat: order.latitude, lng: order.longitude });
    }
  }

  function clearSelection() {
    setSelection(null);
    setFitAllToken((n) => n + 1);
  }

  if (!windowId) {
    return (
      <Card className="p-6" data-testid="distribution-map">
        <p className="text-sm text-muted-foreground">
          {t(($) => $.distributionWorkspace.map.noWindow)}
        </p>
      </Card>
    );
  }

  if (isLoading || map === undefined) {
    return <Skeleton className="h-96 w-full" data-testid="distribution-map-loading" />;
  }

  const plottedOrders = effectiveOrders.filter((o) => o.has_location);
  const unlocated = effectiveOrders.filter((o) => !o.has_location);
  const panelDetail = selectedOrder ? (ordersById.get(selectedOrder.order_id) ?? null) : null;
  const panelLoading = selectedOrder !== null && ordersQuery.isLoading;

  const zonePanel = (
    <Card className="flex h-full flex-col overflow-hidden p-0">
      <div className="border-b px-3 py-2 text-xs uppercase text-muted-foreground">
        {t(($) => $.distributionWorkspace.map.selectionZone)}
      </div>
      <div className="min-h-0 flex-1 overflow-y-auto">
        <ZoneList
          zones={map.zones}
          selection={selection}
          onSelect={(s) => {
            setSelection(s);
            setMobileZonesOpen(false);
          }}
          groupLabelBySlot={groupLabelBySlot}
        />
      </div>
    </Card>
  );

  return (
    <div className="space-y-4" data-testid="distribution-map">
      <Card className="p-4 sm:p-6">
        {showToolbar ? (
          <h3 className="font-medium">{t(($) => $.distributionWorkspace.map.title)}</h3>
        ) : null}

        {/* ── Toolbar — hidden in the Group-detail embed (showToolbar=false) ── */}
        {showToolbar ? (
        <div className="mt-4 flex flex-wrap items-center gap-2">
          <div className="relative min-w-0 flex-1 sm:max-w-xs">
            <Search
              className="pointer-events-none absolute start-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
              aria-hidden
            />
            <Input
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder={t(($) => $.distributionWorkspace.map.searchPlaceholder)}
              className="ps-8"
              data-testid="map-search"
            />

            {query.trim() !== '' ? (
              <div
                className="absolute z-[1000] mt-1 max-h-72 w-full overflow-y-auto rounded-md border bg-popover shadow-md"
                data-testid="map-search-results"
              >
                {searchResults.length === 0 ? (
                  <p className="px-3 py-2 text-sm text-muted-foreground">
                    {t(($) => $.distributionWorkspace.map.noResults)}
                  </p>
                ) : (
                  <ul>
                    {searchResults.map((o) => (
                      <li key={o.order_id}>
                        <button
                          type="button"
                          className="flex w-full items-center gap-2 px-3 py-2 text-start text-sm hover:bg-muted"
                          onClick={() => {
                            openOrder(o, true);
                            setQuery('');
                          }}
                          data-testid={`map-search-result-${o.order_number ?? o.order_id}`}
                        >
                          <MapPin
                            className={cn(
                              'size-3.5 shrink-0',
                              o.has_location ? 'text-primary' : 'text-muted-foreground',
                            )}
                            aria-hidden
                          />
                          <span className="font-medium">{o.order_number ?? o.order_id}</span>
                          <span className="truncate text-muted-foreground">{o.customer_name}</span>
                        </button>
                      </li>
                    ))}
                  </ul>
                )}
              </div>
            ) : null}
          </div>

          <Button
            variant={selection === null ? 'secondary' : 'outline'}
            size="sm"
            onClick={clearSelection}
            data-testid="map-all-zones"
          >
            {t(($) => $.distributionWorkspace.map.allZones)}
          </Button>

          <Button
            variant="outline"
            size="sm"
            onClick={() => setFitAllToken((n) => n + 1)}
            data-testid="map-fit-all"
          >
            {t(($) => $.distributionWorkspace.map.fitAll)}
          </Button>

          <Button
            variant="outline"
            size="sm"
            className="gap-1.5 lg:hidden"
            onClick={() => setMobileZonesOpen(true)}
            data-testid="map-open-zones"
          >
            <Layers className="size-4" aria-hidden />
            {t(($) => $.distributionWorkspace.map.zonesPanel)}
          </Button>
        </div>
        ) : null}

        {showToolbar && map.groups.length > 0 ? (
          <div className="mt-4">
            <GroupLegend
              groups={map.groups}
              selection={selection}
              onSelect={setSelection}
              colorFor={colorFor}
            />
          </div>
        ) : null}

        <div className="mt-4 grid gap-4 lg:grid-cols-[1fr_18rem]">
          <div>
            {plottedOrders.length === 0 ? (
              // No point in the whole cycle has a location. Say so plainly instead
              // of drawing an empty map that looks broken.
              <div
                className="flex h-64 items-center justify-center rounded-lg border bg-muted/30 p-6"
                data-testid="map-nothing-plottable"
              >
                <p className="max-w-md text-center text-sm text-muted-foreground">
                  {t(($) => $.distributionWorkspace.map.nothingPlottable)}
                </p>
              </div>
            ) : (
              /*
                `isolate` gives the map its OWN stacking context, so Leaflet's panes,
                controls, tooltips and popups (z-index up to 1000) can never paint above
                the order drawer (a z-50 portal Sheet). This is the containment fix for
                the map/drawer overlap — not an arbitrary z-index bump (Problem C).
              */
              <div className="relative isolate h-[60vh] min-h-[420px] overflow-hidden rounded-lg border">
                <DistributionLeafletMap
                  orders={plottedOrders}
                  zones={map.zones}
                  zoneColorById={zoneColorById}
                  zoneIdsInSelection={zoneIdsInSelection}
                  selectedZoneId={selectedZoneId}
                  onSelectZone={(id) =>
                    setSelection((prev) =>
                      prev?.kind === 'zone' && prev.id === id ? null : { kind: 'zone', id },
                    )
                  }
                  onSelectOrder={(o) => openOrder(o, false)}
                  onSelectCluster={(orders) => setClusterOrders(orders)}
                  clusterTooltip={(count) =>
                    t(($) => $.distributionWorkspace.map.cluster.markerTitle, { count })
                  }
                  selectedOrderId={selectedOrder?.order_id ?? null}
                  focusPoint={focusPoint}
                  fitAllToken={fitAllToken}
                />
              </div>
            )}
          </div>

          {/* Desktop: inline zone panel. Mobile: a bottom sheet (below). */}
          <div className="hidden lg:block">{zonePanel}</div>
        </div>
      </Card>

      {/*
        Directly below the map and only while a zone is selected — clearing the
        selection removes it, which is what makes it a selection surface rather than a
        permanent second table.
      */}
      {selectedZoneId !== null ? (
        <ZoneOrdersStrip
          zoneName={zoneNameById.get(selectedZoneId) ?? `#${selectedZoneId}`}
          orders={effectiveOrders.filter((o) => o.zone_id === selectedZoneId)}
          selectedOrderId={selectedOrder?.order_id ?? null}
          onSelect={(o) => openOrder(o, true)}
        />
      ) : null}

      <UnlocatedOrders orders={unlocated} onOpen={(o) => openOrder(o, false)} />

      {/* The panel a pin or a search result opens — order detail + Change Zone. */}
      <MapOrderPanel
        order={selectedOrder}
        detail={panelDetail}
        detailLoading={panelLoading}
        zones={map.zones}
        groups={map.groups}
        onOpenChange={(open) => !open && setSelectedOrder(null)}
      />

      {/*
        The panel an AGGREGATED marker opens — every order at one shared coordinate.
        Selecting a row hands off to the per-order panel above (Problem B, §9).
      */}
      <MapClusterPanel
        orders={clusterOrders}
        ordersById={ordersById}
        onOpenChange={(open) => !open && setClusterOrders(null)}
        onOpenOrder={(o) => {
          setClusterOrders(null);
          openOrder(o, true);
        }}
      />

      {/* Mobile zones — a bottom sheet, so the panel does not crowd the map. */}
      <Sheet open={mobileZonesOpen} onOpenChange={setMobileZonesOpen}>
        <SheetContent side="bottom" className="max-h-[70vh]" data-testid="map-zones-sheet">
          <SheetHeader>
            <SheetTitle>{t(($) => $.distributionWorkspace.map.zonesPanel)}</SheetTitle>
          </SheetHeader>
          <div className="mt-2 overflow-y-auto">
            <ZoneList
              zones={map.zones}
              selection={selection}
              onSelect={(s) => {
                setSelection(s);
                setMobileZonesOpen(false);
              }}
              groupLabelBySlot={groupLabelBySlot}
            />
          </div>
        </SheetContent>
      </Sheet>
    </div>
  );
}

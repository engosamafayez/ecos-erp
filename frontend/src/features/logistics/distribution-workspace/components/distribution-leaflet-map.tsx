import { useEffect, useRef } from 'react';
import L from 'leaflet';

import 'leaflet/dist/leaflet.css';

import type { MapOrder, MapZone } from '../types';
import { clusterByCoordinate } from '../lib/order-location';
import { pointDisc, zoneArea, type LatLng } from '../lib/zone-geometry';

/**
 * TASK-DISTRIBUTION-MAP-REAL-MAP-AND-DYNAMIC-ZONES-002 — the real map.
 *
 * A genuine geographic map (Leaflet + OpenStreetMap tiles), not an SVG scatter
 * over a bounding box. It is driven imperatively: React owns the data and the
 * selection, this component reflects them onto Leaflet layers. That keeps the
 * dependency surface to `leaflet` alone — no react-leaflet, no tile SDK, no key.
 *
 * ┌─ WHAT IS DRAWN, AND FROM WHERE ──────────────────────────────────────────┐
 * │ Pins    one `circleMarker` per located order, at its real captured        │
 * │         lat/lng, coloured by its Zone. `circleMarker` is SVG, so no        │
 * │         marker-icon asset is needed and none can go missing in the bundle. │
 * │ Zones   a polygon whose shape is DERIVED live from the zone's own order    │
 * │         coordinates (`lib/zone-geometry`) — never a stored polygon and     │
 * │         never a centroid circle. It recomputes whenever membership changes.│
 * └────────────────────────────────────────────────────────────────────────────┘
 *
 * Orders without coordinates are never given a substitute position: they are not
 * passed here at all (the tab lists them separately), so nothing on this map is
 * a fake location.
 */

const OSM_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
const OSM_ATTRIBUTION = '&copy; OpenStreetMap contributors';
const NEUTRAL = '#94a3b8';

/**
 * EXPLICIT STACKING, not add-order luck.
 *
 * Every vector layer on Leaflet's default `overlayPane` shares one SVG root, so what
 * ends up on top is whatever happened to be appended last. That held only because the
 * draw effect happened to add polygons before pins; any reordering would have put a
 * zone fill over the pins inside it, which is exactly the overlap this task is about.
 *
 * Three named panes make it a declared property instead: zones below, pins above them,
 * and the selected order above everything so it is never buried by its own zone.
 * Leaflet's own overlayPane sits at 400 and markerPane at 600, so these slot between.
 */
const PANE_ZONES = 'ecosZones';
const PANE_PINS = 'ecosPins';
const PANE_SELECTED = 'ecosSelectedPin';
const PANE_Z = { [PANE_ZONES]: 410, [PANE_PINS]: 450, [PANE_SELECTED]: 460 } as const;



/** A reasonable default view (Egypt) for the brief moment before bounds are fit. */
const DEFAULT_CENTER: [number, number] = [26.8, 30.8];
const DEFAULT_ZOOM = 5;

type Props = {
  /** Located orders only — every one has real coordinates. */
  orders: MapOrder[];
  zones: MapZone[];
  zoneColorById: Map<number, string>;
  /** Zones considered "in the current selection"; null = nothing selected. */
  zoneIdsInSelection: Set<number> | null;
  /** The single selected zone (for its own emphasis and auto-fit), or null. */
  selectedZoneId: number | null;
  onSelectZone: (zoneId: number) => void;
  onSelectOrder: (order: MapOrder) => void;
  /**
   * A marker holding MORE THAN ONE order was clicked — the tab opens a panel listing
   * all of them. A single-order marker uses `onSelectOrder` instead.
   */
  onSelectCluster: (orders: MapOrder[]) => void;
  /** Localised tooltip for an aggregated marker, e.g. "6 orders here". */
  clusterTooltip: (count: number) => string;
  /** The order to emphasise — drawn larger, on its own pane, with a visible label. */
  selectedOrderId: string | null;
  /** Set to a point to pan/zoom there (search result); cleared to null. */
  focusPoint: LatLng | null;
  /** Increment to request a "fit all orders" — All Zones / Fit All buttons. */
  fitAllToken: number;
};

export function DistributionLeafletMap({
  orders,
  zones,
  zoneColorById,
  zoneIdsInSelection,
  selectedZoneId,
  onSelectZone,
  onSelectOrder,
  onSelectCluster,
  clusterTooltip,
  selectedOrderId,
  focusPoint,
  fitAllToken,
}: Props) {
  const containerRef = useRef<HTMLDivElement | null>(null);
  const mapRef = useRef<L.Map | null>(null);
  const zoneLayerRef = useRef<L.LayerGroup | null>(null);
  const markerLayerRef = useRef<L.LayerGroup | null>(null);

  // Latest callbacks, read inside Leaflet event handlers without re-binding them.
  const onSelectZoneRef = useRef(onSelectZone);
  const onSelectOrderRef = useRef(onSelectOrder);
  const onSelectClusterRef = useRef(onSelectCluster);
  const clusterTooltipRef = useRef(clusterTooltip);
  useEffect(() => {
    onSelectZoneRef.current = onSelectZone;
    onSelectOrderRef.current = onSelectOrder;
    onSelectClusterRef.current = onSelectCluster;
    clusterTooltipRef.current = clusterTooltip;
  });

  // ── Create the map once ────────────────────────────────────────────────────
  useEffect(() => {
    if (containerRef.current === null || mapRef.current !== null) {
      return;
    }

    const map = L.map(containerRef.current, {
      center: DEFAULT_CENTER,
      zoom: DEFAULT_ZOOM,
      zoomControl: true,
      attributionControl: true,
    });

    L.tileLayer(OSM_URL, { attribution: OSM_ATTRIBUTION, maxZoom: 19 }).addTo(map);

    // Declared stacking order — see PANE_Z.
    for (const name of [PANE_ZONES, PANE_PINS, PANE_SELECTED] as const) {
      const pane = map.createPane(name);
      pane.style.zIndex = String(PANE_Z[name]);
      // Panes above the zone fill must not swallow map drags; only their own
      // shapes take pointer events.
      pane.style.pointerEvents = 'none';
    }

    zoneLayerRef.current = L.layerGroup().addTo(map);
    markerLayerRef.current = L.layerGroup().addTo(map);
    mapRef.current = map;

    // Leaflet miscomputes size when its container was laid out after creation
    // (tab panels, flex/grid). Recompute once now and whenever it resizes.
    const invalidate = () => map.invalidateSize();
    const raf = requestAnimationFrame(invalidate);
    const observer = new ResizeObserver(invalidate);
    observer.observe(containerRef.current);

    return () => {
      cancelAnimationFrame(raf);
      observer.disconnect();
      map.remove();
      mapRef.current = null;
      zoneLayerRef.current = null;
      markerLayerRef.current = null;
    };
  }, []);

  // ── Draw zones + pins whenever the data or the selection changes ────────────
  useEffect(() => {
    const zoneLayer = zoneLayerRef.current;
    const markerLayer = markerLayerRef.current;
    if (zoneLayer === null || markerLayer === null) {
      return;
    }

    zoneLayer.clearLayers();
    markerLayer.clearLayers();

    // Group located orders by zone so each zone's shape comes from its own pins.
    const byZone = new Map<number, LatLng[]>();
    for (const o of orders) {
      if (o.zone_id === null || o.latitude === null || o.longitude === null) {
        continue;
      }
      const list = byZone.get(o.zone_id) ?? [];
      list.push({ lat: o.latitude, lng: o.longitude });
      byZone.set(o.zone_id, list);
    }

    const isDimmed = (zoneId: number | null): boolean =>
      zoneIdsInSelection !== null && (zoneId === null || !zoneIdsInSelection.has(zoneId));

    // Zones first, so pins draw on top of their areas.
    for (const zone of zones) {
      const pts = byZone.get(zone.zone_id) ?? [];
      const area = zoneArea(pts);
      const color = zoneColorById.get(zone.zone_id) ?? NEUTRAL;
      const dimmed = isDimmed(zone.zone_id);
      const isSelected = selectedZoneId === zone.zone_id;

      let ring: LatLng[] | null = null;
      if (area.kind === 'polygon') {
        ring = area.ring;
      } else if (area.kind === 'point' && isSelected) {
        // A one-order zone shows only a marker — except while selected, when a
        // small disc makes the zone's location legible on the map.
        ring = pointDisc(area.center);
      }

      if (ring !== null) {
        L.polygon(
          ring.map((p) => [p.lat, p.lng] as [number, number]),
          {
            pane: PANE_ZONES,
            color,
            weight: isSelected ? 3 : 1.5,
            opacity: dimmed ? 0.2 : 0.9,
            fillColor: color,
            // A zone must never hide the pins inside it, so the fill stays light even
            // when selected and the pins live on a pane above it.
            fillOpacity: dimmed ? 0.05 : isSelected ? 0.25 : 0.14,
            // Its own shape stays clickable even though the pane does not.
            interactive: true,
            bubblingMouseEvents: false,
          },
        )
          .on('click', () => onSelectZoneRef.current(zone.zone_id))
          .addTo(zoneLayer);
      }
    }

    // Orders sharing an EXACT coordinate become ONE marker (with a count) instead of a
    // stack of overlapping pins where only the top one is reachable. Aggregation is by
    // coordinate only — every order is preserved and reached through the marker.
    const clusters = clusterByCoordinate(orders);

    for (const cluster of clusters) {
      const zoneIds = new Set(cluster.orders.map((o) => o.zone_id));
      const uniformZone = zoneIds.size === 1 ? [...zoneIds][0] : null;
      const color =
        uniformZone === null || uniformZone === undefined
          ? NEUTRAL
          : (zoneColorById.get(uniformZone) ?? NEUTRAL);
      // Dimmed only when NONE of the cluster's orders are in the current selection.
      const dimmed = cluster.orders.every((o) => isDimmed(o.zone_id));
      const hasSelected =
        selectedOrderId !== null && cluster.orders.some((o) => o.order_id === selectedOrderId);

      // ── A single order at this point — the existing pin, unchanged ──
      if (cluster.orders.length === 1) {
        const o = cluster.orders[0];
        const isSelectedOrder = hasSelected;

        const marker = L.circleMarker([cluster.lat, cluster.lng], {
          // Selected sits on its own pane, above every zone fill and every other pin.
          pane: isSelectedOrder ? PANE_SELECTED : PANE_PINS,
          radius: isSelectedOrder ? 10 : 6,
          color: isSelectedOrder ? '#0f172a' : '#ffffff',
          weight: isSelectedOrder ? 3 : 1.5,
          fillColor: color,
          fillOpacity: dimmed && !isSelectedOrder ? 0.25 : 1,
          opacity: dimmed && !isSelectedOrder ? 0.35 : 1,
          interactive: true,
          bubblingMouseEvents: false,
        });

        const label = [o.order_number, o.customer_name, o.city].filter(Boolean).join(' · ');
        if (label !== '') {
          // LABELS ARE SELECTIVE. Hover shows one; the SELECTED order keeps its label
          // pinned. Permanent labels on every pin is the thing that made the map
          // unreadable, so nothing else gets one.
          marker.bindTooltip(label, {
            direction: 'top',
            permanent: isSelectedOrder,
            className: isSelectedOrder ? 'font-medium' : undefined,
          });
        }

        marker.on('click', () => onSelectOrderRef.current(o));
        marker.addTo(markerLayer);
        continue;
      }

      // ── Multiple orders at one point — ONE aggregated marker showing the count ──
      const count = cluster.orders.length;
      const faded = dimmed && !hasSelected;

      // A divIcon (HTML) rather than a circleMarker, because only HTML can carry the
      // count. Styled to match the pins: a filled, zone-coloured disc with a white
      // border. It lives in the default markerPane (pointer events on), so it is
      // clickable, and — like every pin — inside the map's own isolated stacking
      // context, so it can never paint above the order drawer.
      const icon = L.divIcon({
        className: 'ecos-map-cluster-icon', // NOT leaflet-div-icon → no default box
        html:
          `<div style="` +
          `display:flex;align-items:center;justify-content:center;` +
          `width:30px;height:30px;border-radius:9999px;` +
          `background:${color};color:#ffffff;` +
          `border:2px solid ${hasSelected ? '#0f172a' : '#ffffff'};` +
          `box-shadow:0 1px 4px rgba(15,23,42,0.45);` +
          `font-weight:700;font-size:12px;line-height:1;` +
          `text-shadow:0 1px 1px rgba(0,0,0,0.4);` +
          `opacity:${faded ? '0.4' : '1'};` +
          `">${count}</div>`,
        iconSize: [30, 30],
        iconAnchor: [15, 15],
      });

      const marker = L.marker([cluster.lat, cluster.lng], {
        icon,
        interactive: true,
        bubblingMouseEvents: false,
      });

      marker.bindTooltip(clusterTooltipRef.current(count), { direction: 'top' });
      marker.on('click', () => onSelectClusterRef.current(cluster.orders));
      marker.addTo(markerLayer);
    }
  }, [orders, zones, zoneColorById, zoneIdsInSelection, selectedZoneId, selectedOrderId]);

  // ── Fit to all located orders (initial load, All Zones, Fit All) ────────────
  useEffect(() => {
    const map = mapRef.current;
    if (map === null) {
      return;
    }

    const coords = orders
      .filter((o) => o.latitude !== null && o.longitude !== null)
      .map((o) => [o.latitude as number, o.longitude as number] as [number, number]);

    if (coords.length === 0) {
      return;
    }
    if (coords.length === 1) {
      map.setView(coords[0], 14);
      return;
    }
    map.fitBounds(L.latLngBounds(coords), { padding: [40, 40], maxZoom: 15 });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [fitAllToken]);

  // ── Fit to the selected zone's own orders ───────────────────────────────────
  useEffect(() => {
    const map = mapRef.current;
    if (map === null || selectedZoneId === null) {
      return;
    }

    const coords = orders
      .filter((o) => o.zone_id === selectedZoneId && o.latitude !== null && o.longitude !== null)
      .map((o) => [o.latitude as number, o.longitude as number] as [number, number]);

    if (coords.length === 0) {
      return;
    }
    if (coords.length === 1) {
      map.setView(coords[0], 14);
      return;
    }
    map.fitBounds(L.latLngBounds(coords), { padding: [60, 60], maxZoom: 15 });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedZoneId]);

  // ── Pan/zoom to a searched order ────────────────────────────────────────────
  useEffect(() => {
    const map = mapRef.current;
    if (map === null || focusPoint === null) {
      return;
    }
    map.setView([focusPoint.lat, focusPoint.lng], 16, { animate: true });
  }, [focusPoint]);

  return <div ref={containerRef} className="h-full w-full" data-testid="distribution-leaflet-map" />;
}

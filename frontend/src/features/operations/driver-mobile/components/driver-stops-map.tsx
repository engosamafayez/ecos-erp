import { useEffect, useRef } from 'react';
import L from 'leaflet';

import 'leaflet/dist/leaflet.css';

import type { DeliveryStop, DeliveryStopStatus } from '../types/driver-mobile';

/**
 * TASK-DRIVER-APP-ORDERS-MAP-001 — the driver's current-trip stop map.
 *
 * A genuine geographic map (Leaflet + OpenStreetMap tiles), driven imperatively —
 * React owns the data/selection, this component reflects them onto Leaflet layers.
 * The pattern (create-once effect, `L.tileLayer(OSM)`, SVG `circleMarker` pins to
 * sidestep the default marker-icon bundling pitfall, `fitBounds`, `invalidateSize`
 * + `ResizeObserver`) is the one proven in the Distribution map. No react-leaflet,
 * no tile SDK, no API key — OSM raster tiles need none.
 *
 * IT PLOTS ONLY LOCATED STOPS. The caller passes stops whose canonical `order.gps`
 * is present; a stop without a coordinate is never given a substitute position —
 * it is listed separately by the page, never faked onto the map. One pin = one
 * canonical delivery stop; the map never reorders or re-sequences anything.
 */

const OSM_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
const OSM_ATTRIBUTION = '&copy; OpenStreetMap contributors';

/** A reasonable default view (Egypt) for the brief moment before bounds are fit. */
const DEFAULT_CENTER: [number, number] = [26.8, 30.8];
const DEFAULT_ZOOM = 5;

/**
 * Pin fill per CANONICAL stop status — the SAME vocabulary the status badge uses
 * (`STOP_STATUS_COLORS`), expressed as hex for Leaflet's SVG markers. No second,
 * map-only status vocabulary is introduced.
 */
const STATUS_PIN_COLOR: Record<DeliveryStopStatus, string> = {
  pending:     '#6b7280', // gray-500
  in_progress: '#2563eb', // blue-600 — "out for delivery"
  delivered:   '#16a34a', // green-600
  partial:     '#d97706', // amber-600
  failed:      '#dc2626', // red-600
  returned:    '#9333ea', // purple-600
  skipped:     '#9ca3af', // gray-400
};

const DRIVER_POS_COLOR = '#0ea5e9'; // sky-500 — the driver's own location marker

type LatLng = { lat: number; lng: number };

interface DriverStopsMapProps {
  /** LOCATED stops only — every one has a non-null `order.gps`. The caller filters. */
  stops: DeliveryStop[];
  /** The stop to emphasise (larger, dark ring, label pinned), or null. */
  selectedStopId: string | null;
  /** A pin was tapped — the page opens the compact stop preview. */
  onSelectStop: (stopId: string) => void;
  /** Increment to request a re-fit to all located stops (e.g. a "recentre" control). */
  fitToken: number;
  /** The driver's own device position, if they opted to show it. Presentation only. */
  driverPosition: LatLng | null;
  /** Localised tooltip label for a pin, e.g. "Stop 3 · ORD-00014". */
  pinLabel: (stop: DeliveryStop) => string;
}

export function DriverStopsMap({
  stops,
  selectedStopId,
  onSelectStop,
  fitToken,
  driverPosition,
  pinLabel,
}: DriverStopsMapProps) {
  const containerRef = useRef<HTMLDivElement | null>(null);
  const mapRef = useRef<L.Map | null>(null);
  const markerLayerRef = useRef<L.LayerGroup | null>(null);
  const driverLayerRef = useRef<L.LayerGroup | null>(null);

  // Latest callbacks, read inside Leaflet handlers without re-binding them.
  const onSelectStopRef = useRef(onSelectStop);
  const pinLabelRef = useRef(pinLabel);
  useEffect(() => {
    onSelectStopRef.current = onSelectStop;
    pinLabelRef.current = pinLabel;
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

    markerLayerRef.current = L.layerGroup().addTo(map);
    driverLayerRef.current = L.layerGroup().addTo(map);
    mapRef.current = map;

    // Leaflet miscomputes size when its container is laid out after creation
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
      markerLayerRef.current = null;
      driverLayerRef.current = null;
    };
  }, []);

  // ── Draw a pin per located stop whenever the data or selection changes ──────
  useEffect(() => {
    const markerLayer = markerLayerRef.current;
    if (markerLayer === null) {
      return;
    }
    markerLayer.clearLayers();

    for (const stop of stops) {
      const gps = stop.order?.gps;
      if (!gps) {
        continue; // never place a fake pin — located stops only
      }
      const isSelected = stop.id === selectedStopId;
      const color = STATUS_PIN_COLOR[stop.status] ?? STATUS_PIN_COLOR.pending;

      const marker = L.circleMarker([gps.lat, gps.lng], {
        radius: isSelected ? 11 : 7,
        color: isSelected ? '#0f172a' : '#ffffff',
        weight: isSelected ? 3 : 1.5,
        fillColor: color,
        fillOpacity: 1,
        interactive: true,
        bubblingMouseEvents: false,
      });

      const label = pinLabelRef.current(stop);
      if (label !== '') {
        marker.bindTooltip(label, { direction: 'top', permanent: isSelected });
      }
      marker.on('click', () => onSelectStopRef.current(stop.id));
      marker.addTo(markerLayer);
    }
  }, [stops, selectedStopId]);

  // ── Reflect the driver's own position (opt-in) ──────────────────────────────
  useEffect(() => {
    const driverLayer = driverLayerRef.current;
    if (driverLayer === null) {
      return;
    }
    driverLayer.clearLayers();
    if (driverPosition === null) {
      return;
    }
    L.circleMarker([driverPosition.lat, driverPosition.lng], {
      radius: 8,
      color: '#ffffff',
      weight: 3,
      fillColor: DRIVER_POS_COLOR,
      fillOpacity: 1,
      interactive: false,
    }).addTo(driverLayer);
  }, [driverPosition]);

  // ── Fit to all located stops (initial load + explicit re-fit) ───────────────
  useEffect(() => {
    const map = mapRef.current;
    if (map === null) {
      return;
    }

    const coords: [number, number][] = [];
    for (const stop of stops) {
      const gps = stop.order?.gps;
      if (gps) {
        coords.push([gps.lat, gps.lng]);
      }
    }
    if (driverPosition !== null) {
      coords.push([driverPosition.lat, driverPosition.lng]);
    }

    if (coords.length === 0) {
      return;
    }
    if (coords.length === 1) {
      map.setView(coords[0], 14);
      return;
    }
    map.fitBounds(L.latLngBounds(coords), { padding: [48, 48], maxZoom: 15 });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [fitToken, stops]);

  return <div ref={containerRef} className="h-full w-full" data-testid="driver-stops-map" />;
}

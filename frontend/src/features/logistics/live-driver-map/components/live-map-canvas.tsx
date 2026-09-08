import { useEffect, useRef } from 'react';
import L from 'leaflet';

import 'leaflet/dist/leaflet.css';

import type { LiveMapTrip } from '../types/live-map';

/**
 * TASK-ECOS-SHIPPING-OS-REDESIGN-004 §5 — the multi-driver Live Map.
 *
 * Same proven pattern as the Driver App's own map (driver-stops-map.tsx):
 * raw Leaflet + OSM tiles, imperative ref-driven layers, `circleMarker` pins
 * to avoid the default marker-icon bundling pitfall, `ResizeObserver` +
 * `requestAnimationFrame` for correct sizing inside a flex/tab layout. No
 * react-leaflet, no tile SDK, no API key, no secrets in frontend source.
 *
 * IT PLOTS ONLY TRIPS WITH A REAL RECORDED LOCATION. `trips` already comes
 * from the backend pre-filtered to currently trackable Trips (§4); a trip
 * with `location: null` (no sample yet) is never given a substitute
 * position — the caller lists it separately, never as a fake marker.
 */

const OSM_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
const OSM_ATTRIBUTION = '&copy; OpenStreetMap contributors';

const DEFAULT_CENTER: [number, number] = [26.8, 30.8];
const DEFAULT_ZOOM = 5;

const FRESH_COLOR = '#16a34a'; // emerald-600 — Live
const STALE_COLOR = '#9ca3af'; // gray-400 — Stale, never presented as live
const EXCEPTION_RING = '#dc2626'; // red-600 — a real exception is attached to this trip

interface LiveMapCanvasProps {
  /** Trackable trips — the caller is responsible for filtering; this draws whatever it gets. */
  trips: LiveMapTrip[];
  selectedTripId: string | null;
  onSelectTrip: (tripId: string) => void;
  /** Increment to request an explicit re-fit to all located trips (a "Fit all" control). */
  fitToken: number;
  markerLabel: (trip: LiveMapTrip) => string;
}

export function LiveMapCanvas({
  trips,
  selectedTripId,
  onSelectTrip,
  fitToken,
  markerLabel,
}: LiveMapCanvasProps) {
  const containerRef = useRef<HTMLDivElement | null>(null);
  const mapRef = useRef<L.Map | null>(null);
  const markerLayerRef = useRef<L.LayerGroup | null>(null);
  const markerByTripRef = useRef<Map<string, L.CircleMarker>>(new Map());
  const hasAutoFitRef = useRef(false);

  const onSelectTripRef = useRef(onSelectTrip);
  const markerLabelRef = useRef(markerLabel);
  useEffect(() => {
    onSelectTripRef.current = onSelectTrip;
    markerLabelRef.current = markerLabel;
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
    mapRef.current = map;

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
      markerByTripRef.current = new Map();
    };
  }, []);

  // ── Draw a pin per located trip whenever data or selection changes ─────────
  useEffect(() => {
    const map = mapRef.current;
    const markerLayer = markerLayerRef.current;
    if (map === null || markerLayer === null) {
      return;
    }
    markerLayer.clearLayers();
    markerByTripRef.current = new Map();

    const coords: [number, number][] = [];
    for (const trip of trips) {
      const loc = trip.location;
      if (loc === null) {
        continue; // Unknown — never place a substitute pin (§4/§24)
      }
      coords.push([loc.lat, loc.lng]);

      const isSelected = trip.trip_id === selectedTripId;
      const marker = L.circleMarker([loc.lat, loc.lng], {
        radius: isSelected ? 11 : 8,
        color: trip.has_exception ? EXCEPTION_RING : isSelected ? '#0f172a' : '#ffffff',
        weight: isSelected || trip.has_exception ? 3 : 1.5,
        fillColor: loc.freshness === 'fresh' ? FRESH_COLOR : STALE_COLOR,
        // Stale locations read visibly duller, never mistaken for live (§6).
        fillOpacity: loc.freshness === 'fresh' ? 1 : 0.5,
        interactive: true,
        bubblingMouseEvents: false,
      });

      const label = markerLabelRef.current(trip);
      if (label !== '') {
        marker.bindTooltip(label, { direction: 'top', permanent: isSelected });
      }
      marker.on('click', () => onSelectTripRef.current(trip.trip_id));
      marker.addTo(markerLayer);
      markerByTripRef.current.set(trip.trip_id, marker);
    }

    // Auto-fit exactly once — the first time any located trip appears. Later
    // polls (every 15s, §7) must NOT keep re-centring the map under a
    // dispatcher who is mid-interaction; only the explicit fitToken does.
    if (!hasAutoFitRef.current && coords.length > 0) {
      hasAutoFitRef.current = true;
      if (coords.length === 1) {
        map.setView(coords[0], 14);
      } else {
        map.fitBounds(L.latLngBounds(coords), { padding: [48, 48], maxZoom: 15 });
      }
    }
  }, [trips, selectedTripId]);

  // ── Selected-driver emphasis: gently pan to a list-driven selection ────────
  useEffect(() => {
    const map = mapRef.current;
    if (map === null || selectedTripId === null) {
      return;
    }
    const marker = markerByTripRef.current.get(selectedTripId);
    if (marker !== undefined) {
      map.panTo(marker.getLatLng());
    }
  }, [selectedTripId]);

  // ── Explicit re-fit ("Fit all" control) ─────────────────────────────────────
  useEffect(() => {
    const map = mapRef.current;
    if (map === null || fitToken === 0) {
      return;
    }
    const coords: [number, number][] = [];
    for (const trip of trips) {
      if (trip.location !== null) {
        coords.push([trip.location.lat, trip.location.lng]);
      }
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
  }, [fitToken]);

  return <div ref={containerRef} className="h-full w-full" data-testid="live-map-canvas" />;
}

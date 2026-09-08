import { useEffect, useRef } from 'react';
import L from 'leaflet';

import 'leaflet/dist/leaflet.css';

import type { RouteHistorySample, RouteHistoryStop } from '../types/live-map';

/**
 * TASK-ECOS-SHIPPING-OS-REDESIGN-004 §17/§18 — the single-trip Route
 * History map. Same Leaflet + OSM pattern as LiveMapCanvas/driver-stops-map.
 *
 * The polyline connects ONLY the real recorded samples, in the order the
 * backend already returned them (chronological) — never road-snapped, never
 * interpolated. Small dots at every vertex make real reporting gaps visible
 * as long straight segments rather than implying a smooth continuous drive.
 */

const OSM_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
const OSM_ATTRIBUTION = '&copy; OpenStreetMap contributors';

const DEFAULT_CENTER: [number, number] = [26.8, 30.8];
const DEFAULT_ZOOM = 5;

const PATH_COLOR = '#2563eb'; // blue-600 — the raw recorded GPS path
const REPLAY_POS_COLOR = '#0ea5e9'; // sky-500 — current replay position

/** Same vocabulary/colours as driver-stops-map.tsx's STATUS_PIN_COLOR. */
const STOP_STATUS_COLOR: Record<string, string> = {
  pending: '#6b7280',
  in_progress: '#2563eb',
  delivered: '#16a34a',
  partial: '#16a34a',
  failed: '#dc2626',
  returned: '#9333ea',
  skipped: '#9ca3af',
};

interface RouteHistoryMapProps {
  samples: RouteHistorySample[];
  stops: RouteHistoryStop[];
  /** Index into `samples` for the current replay position; null shows the full static path only. */
  replayIndex: number | null;
  stopLabel: (stop: RouteHistoryStop) => string;
}

export function RouteHistoryMap({ samples, stops, replayIndex, stopLabel }: RouteHistoryMapProps) {
  const containerRef = useRef<HTMLDivElement | null>(null);
  const mapRef = useRef<L.Map | null>(null);
  const pathLayerRef = useRef<L.LayerGroup | null>(null);
  const stopLayerRef = useRef<L.LayerGroup | null>(null);
  const replayLayerRef = useRef<L.LayerGroup | null>(null);
  const hasFitRef = useRef(false);

  const stopLabelRef = useRef(stopLabel);
  useEffect(() => {
    stopLabelRef.current = stopLabel;
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
    pathLayerRef.current = L.layerGroup().addTo(map);
    stopLayerRef.current = L.layerGroup().addTo(map);
    replayLayerRef.current = L.layerGroup().addTo(map);
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
      pathLayerRef.current = null;
      stopLayerRef.current = null;
      replayLayerRef.current = null;
    };
  }, []);

  // ── The recorded path + stop pins ───────────────────────────────────────────
  useEffect(() => {
    const map = mapRef.current;
    const pathLayer = pathLayerRef.current;
    const stopLayer = stopLayerRef.current;
    if (map === null || pathLayer === null || stopLayer === null) {
      return;
    }
    pathLayer.clearLayers();
    stopLayer.clearLayers();

    const sampleCoords: [number, number][] = samples.map((s) => [s.lat, s.lng]);
    if (sampleCoords.length > 0) {
      L.polyline(sampleCoords, { color: PATH_COLOR, weight: 3, opacity: 0.85 }).addTo(pathLayer);
      for (const c of sampleCoords) {
        L.circleMarker(c, {
          radius: 2,
          color: PATH_COLOR,
          fillColor: PATH_COLOR,
          fillOpacity: 0.8,
          interactive: false,
        }).addTo(pathLayer);
      }
    }

    const stopCoords: [number, number][] = [];
    for (const stop of stops) {
      if (stop.location === null) {
        continue; // never a substitute pin — a "No location" stop is listed, never plotted (§10)
      }
      stopCoords.push([stop.location.lat, stop.location.lng]);

      const marker = L.circleMarker([stop.location.lat, stop.location.lng], {
        radius: 8,
        color: '#ffffff',
        weight: 2,
        fillColor: STOP_STATUS_COLOR[stop.status] ?? STOP_STATUS_COLOR.pending,
        fillOpacity: 1,
        interactive: true,
      });
      const label = stopLabelRef.current(stop);
      if (label !== '') {
        marker.bindTooltip(label, { direction: 'top' });
      }
      marker.addTo(stopLayer);
    }

    if (!hasFitRef.current) {
      const all = [...sampleCoords, ...stopCoords];
      if (all.length > 0) {
        hasFitRef.current = true;
        if (all.length === 1) {
          map.setView(all[0], 14);
        } else {
          map.fitBounds(L.latLngBounds(all), { padding: [48, 48], maxZoom: 16 });
        }
      }
    }
  }, [samples, stops]);

  // ── Current replay position ─────────────────────────────────────────────────
  useEffect(() => {
    const replayLayer = replayLayerRef.current;
    if (replayLayer === null) {
      return;
    }
    replayLayer.clearLayers();

    const sample = replayIndex === null ? undefined : samples[replayIndex];
    if (sample === undefined) {
      return;
    }
    L.circleMarker([sample.lat, sample.lng], {
      radius: 9,
      color: '#ffffff',
      weight: 3,
      fillColor: REPLAY_POS_COLOR,
      fillOpacity: 1,
      interactive: false,
    }).addTo(replayLayer);
  }, [replayIndex, samples]);

  return <div ref={containerRef} className="h-full w-full" data-testid="route-history-map" />;
}

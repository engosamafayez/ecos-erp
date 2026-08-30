/**
 * TASK-DISTRIBUTION-MAP-REAL-MAP-AND-DYNAMIC-ZONES-002 — §4 Zone geometry.
 *
 * A Zone has NO stored polygon. Its shape on the real (Leaflet) map is derived,
 * live, from the geographic coordinates of its own orders — so it recomputes
 * whenever an order joins or leaves the zone, with nothing persisted.
 *
 * ┌─ THE RULE, RESTATED FROM §4 ─────────────────────────────────────────────┐
 * │ 1 order   → a point (the caller shows a marker, and MAY draw a small disc │
 * │             around it only while the zone is selected).                   │
 * │ 2 orders  → a real AREA around the two points (a buffered capsule), never │
 * │             a bare line — the "2 points = line" rule is explicitly gone.  │
 * │ 3+ orders → the convex hull of the points, buffered outward so it reads   │
 * │             as a filled region that clears the pins.                      │
 * └────────────────────────────────────────────────────────────────────────────┘
 *
 * HOW THE AREA IS BUILT (dependency-free): a Minkowski sum with a disc. Every
 * order point is expanded into a small circle, and the convex hull of ALL those
 * circle points is taken. That single construction yields a disc for one point,
 * a rounded capsule for two, and a padded rounded hull for many — so every case
 * is a genuine area, and none is a line.
 *
 * Coordinates are treated as planar (x = lng, y = lat). At a city / governorate
 * scale that distortion is invisible, and the module stays pure and testable —
 * no map library, no projection, no turf.
 */

import { convexHull, type Point } from './zone-hull';

export type LatLng = { lat: number; lng: number };

export type ZoneArea =
  /** No plotted order — nothing to draw. */
  | { kind: 'none' }
  /** Exactly one plotted order — a marker; the caller may add a disc on select. */
  | { kind: 'point'; center: LatLng }
  /** Two or more — a real buffered region (closed ring of lat/lng points). */
  | { kind: 'polygon'; ring: LatLng[] };

/** Segments used to approximate each buffering disc. Higher = rounder, heavier. */
const DISC_SEGMENTS = 20;

/**
 * Degrees are scaled to integers before hulling because the shared `convexHull`
 * de-duplicates by rounding to whole units (it was written for screen pixels).
 * At 1e5, one unit ≈ 1e-5° ≈ ~1 m, so distinct coordinates stay distinct.
 */
const SCALE = 100_000;

/** De-duplicate to ~1 metre so identical addresses do not distort the buffer. */
function distinct(points: readonly LatLng[]): LatLng[] {
  const seen = new Set<string>();
  const out: LatLng[] = [];

  for (const p of points) {
    const key = `${p.lat.toFixed(5)}:${p.lng.toFixed(5)}`;
    if (seen.has(key)) {
      continue;
    }
    seen.add(key);
    out.push(p);
  }

  return out;
}

/**
 * The buffer radius, in degrees, scaled to how spread out the points are.
 *
 * A tight pair gets a small halo; a wide zone gets proportionally more padding
 * so the boundary clears its pins. Clamped so a two-point zone is always a
 * visible area (floor) and a huge zone does not balloon (ceiling).
 */
function bufferRadiusDeg(planar: Point[]): number {
  const xs = planar.map((p) => p.x);
  const ys = planar.map((p) => p.y);
  const diagonal = Math.hypot(Math.max(...xs) - Math.min(...xs), Math.max(...ys) - Math.min(...ys));

  const MIN = 0.0035; // ~0.35 km — a two-point zone still reads as a region
  const MAX = 0.05; //  ~5 km   — keep a sprawling zone from swallowing the map
  return Math.min(MAX, Math.max(MIN, diagonal * 0.18));
}

/** A regular polygon approximating a disc of radius `r` centred on `c`. */
function disc(c: Point, r: number): Point[] {
  const pts: Point[] = [];
  for (let i = 0; i < DISC_SEGMENTS; i += 1) {
    const angle = (2 * Math.PI * i) / DISC_SEGMENTS;
    pts.push({ x: c.x + r * Math.cos(angle), y: c.y + r * Math.sin(angle) });
  }
  return pts;
}

/**
 * Build a Zone's area from its orders' coordinates.
 *
 * Returns lat/lng geometry ready to hand to Leaflet — `ring` is a closed
 * boundary (the first point is NOT repeated at the end; Leaflet closes it).
 */
export function zoneArea(points: readonly LatLng[]): ZoneArea {
  const unique = distinct(points);

  if (unique.length === 0) {
    return { kind: 'none' };
  }

  if (unique.length === 1) {
    return { kind: 'point', center: unique[0] };
  }

  const planar: Point[] = unique.map((p) => ({ x: p.lng, y: p.lat }));
  const radius = bufferRadiusDeg(planar);

  // Minkowski sum with a disc, in the scaled integer space the hull expects:
  // every point becomes a small circle, and the hull of all circle points is
  // the buffered region. Scale back to degrees on the way out.
  const cloud: Point[] = [];
  for (const p of planar) {
    cloud.push(...disc({ x: p.x * SCALE, y: p.y * SCALE }, radius * SCALE));
  }

  const hull = convexHull(cloud);
  const ring = hull.map((pt) => ({ lat: pt.y / SCALE, lng: pt.x / SCALE }));

  return { kind: 'polygon', ring };
}

/**
 * A small disc (as a lat/lng ring) for a single-order zone, drawn only when the
 * zone is selected. `radiusDeg` defaults to the buffer floor so it matches the
 * scale of multi-order zone areas.
 */
export function pointDisc(center: LatLng, radiusDeg = 0.0035): LatLng[] {
  return disc({ x: center.lng, y: center.lat }, radiusDeg).map((pt) => ({
    lat: pt.y,
    lng: pt.x,
  }));
}

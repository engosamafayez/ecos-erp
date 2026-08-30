/**
 * TASK-DISTRIBUTION-MAP-ORDER-POINTS-DYNAMIC-ZONES-001 — §4 Step 2.
 *
 * A Zone's boundary on the map is DERIVED from the projected positions of the
 * Zone's own plotted orders — never from stored geometry, and never from an
 * average coordinate standing in for a cluster. This module turns a set of
 * already-projected points into that boundary and nothing else.
 *
 * ┌─ WHY IT LIVES HERE, PURE ────────────────────────────────────────────────┐
 * │ No React, no DOM, no dependency. It takes screen-space points and returns │
 * │ screen-space points, so it is unit-testable in isolation and a Leaflet /  │
 * │ Turf-style library is never pulled in for a convex hull that is a dozen   │
 * │ lines of arithmetic.                                                      │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * NEVER SYNTHESISES AREA THE POINTS DO NOT IMPLY. One point is a point, two
 * points are a line; only three or more non-collinear points describe a
 * polygon. Each case is reported honestly so the surface draws exactly what the
 * order positions justify.
 */

export type Point = { x: number; y: number };

/**
 * The boundary a Zone's points describe.
 *
 * - `none`   — fewer than two distinct points; there is nothing to outline, only
 *              the pin (and the Zone label) should be drawn.
 * - `line`   — exactly two distinct points; the boundary is the segment between
 *              them (padded outward at both ends).
 * - `polygon`— three or more points; the boundary is their padded convex hull.
 */
export type ZoneBoundary =
  | { kind: 'none' }
  | { kind: 'line'; points: [Point, Point] }
  | { kind: 'polygon'; points: Point[] };

/** Distinct points, in a stable order, so hull output does not depend on input order. */
function distinct(points: readonly Point[]): Point[] {
  const seen = new Set<string>();
  const out: Point[] = [];

  for (const p of points) {
    // Round to a whole pixel before de-duplicating: two orders at the same
    // address project to coordinates that differ only in floating-point noise,
    // and treating those as distinct would draw a hair-thin sliver of a hull.
    const key = `${Math.round(p.x)}:${Math.round(p.y)}`;
    if (seen.has(key)) {
      continue;
    }
    seen.add(key);
    out.push(p);
  }

  return out;
}

/** 2-D cross product of OA × OB. > 0 = counter-clockwise turn. */
function cross(o: Point, a: Point, b: Point): number {
  return (a.x - o.x) * (b.y - o.y) - (a.y - o.y) * (b.x - o.x);
}

/**
 * Andrew's monotone-chain convex hull.
 *
 * Returns the hull vertices in counter-clockwise order, without repeating the
 * first vertex at the end. Collinear points are dropped (`<= 0`), so a set of
 * points that all lie on one line collapses to its two extremes — which the
 * caller renders as a `line`, not a zero-area polygon.
 */
export function convexHull(points: readonly Point[]): Point[] {
  const pts = distinct(points).sort((a, b) => a.x - b.x || a.y - b.y);

  if (pts.length <= 2) {
    return pts;
  }

  const lower: Point[] = [];
  for (const p of pts) {
    while (lower.length >= 2 && cross(lower[lower.length - 2], lower[lower.length - 1], p) <= 0) {
      lower.pop();
    }
    lower.push(p);
  }

  const upper: Point[] = [];
  for (let i = pts.length - 1; i >= 0; i--) {
    const p = pts[i];
    while (upper.length >= 2 && cross(upper[upper.length - 2], upper[upper.length - 1], p) <= 0) {
      upper.pop();
    }
    upper.push(p);
  }

  // Drop each chain's last point: it is the first point of the other chain.
  lower.pop();
  upper.pop();

  const hull = lower.concat(upper);

  // All points collinear: the chains degenerate. Report the two EXTREMES of the
  // sorted set — the endpoints of the line the points lie on — not an arbitrary
  // adjacent pair, so the caller draws the full segment.
  return hull.length >= 3 ? hull : [pts[0], pts[pts.length - 1]];
}

/**
 * Push every vertex outward from the shape's centroid by `padding` pixels, so
 * the boundary clears the pins it encloses rather than clipping through them.
 */
function padOutward(points: Point[], padding: number): Point[] {
  if (points.length === 0 || padding <= 0) {
    return points;
  }

  const cx = points.reduce((s, p) => s + p.x, 0) / points.length;
  const cy = points.reduce((s, p) => s + p.y, 0) / points.length;

  return points.map((p) => {
    const dx = p.x - cx;
    const dy = p.y - cy;
    const len = Math.hypot(dx, dy) || 1;
    return { x: p.x + (dx / len) * padding, y: p.y + (dy / len) * padding };
  });
}

/**
 * Build the boundary for one Zone from its plotted order points.
 *
 * `padding` is the outward offset in pixels; pass 0 to get the raw hull.
 */
export function zoneBoundary(points: readonly Point[], padding = 12): ZoneBoundary {
  const unique = distinct(points);

  if (unique.length < 2) {
    return { kind: 'none' };
  }

  if (unique.length === 2) {
    const [a, b] = padOutward(unique, padding);
    return { kind: 'line', points: [a, b] };
  }

  const hull = convexHull(unique);

  if (hull.length === 2) {
    const [a, b] = padOutward(hull, padding);
    return { kind: 'line', points: [a, b] };
  }

  return { kind: 'polygon', points: padOutward(hull, padding) };
}

/**
 * The screen-space anchor for a Zone's label: the mean of its own plotted
 * points. This is a LABEL POSITION derived from the very pins on screen, not a
 * substitute geographic coordinate — the map draws no zone at a place its orders
 * do not put it. Null when the Zone has no plotted point to anchor to.
 */
export function labelAnchor(points: readonly Point[]): Point | null {
  if (points.length === 0) {
    return null;
  }

  const cx = points.reduce((s, p) => s + p.x, 0) / points.length;
  const cy = points.reduce((s, p) => s + p.y, 0) / points.length;
  return { x: cx, y: cy };
}

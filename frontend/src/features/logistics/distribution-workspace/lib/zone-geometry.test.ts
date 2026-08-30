import { describe, expect, it } from 'vitest';

import { pointDisc, zoneArea, type LatLng } from './zone-geometry';

/** Signed area of a lat/lng ring in planar (lng,lat) space — |value| > 0 ⇒ real area. */
function ringArea(ring: LatLng[]): number {
  let sum = 0;
  for (let i = 0; i < ring.length; i += 1) {
    const a = ring[i];
    const b = ring[(i + 1) % ring.length];
    sum += a.lng * b.lat - b.lng * a.lat;
  }
  return Math.abs(sum) / 2;
}

describe('zoneArea', () => {
  it('is "none" for no points', () => {
    expect(zoneArea([])).toEqual({ kind: 'none' });
  });

  it('is a bare point for a single order (marker only)', () => {
    expect(zoneArea([{ lat: 30.04, lng: 31.23 }])).toEqual({
      kind: 'point',
      center: { lat: 30.04, lng: 31.23 },
    });
  });

  it('collapses identical addresses to a single point', () => {
    expect(
      zoneArea([
        { lat: 30.04, lng: 31.23 },
        { lat: 30.040001, lng: 31.230002 },
      ]),
    ).toEqual({ kind: 'point', center: { lat: 30.04, lng: 31.23 } });
  });

  it('makes TWO orders a real area, never a line', () => {
    const area = zoneArea([
      { lat: 30.04, lng: 31.23 },
      { lat: 30.06, lng: 31.25 },
    ]);

    expect(area.kind).toBe('polygon');
    if (area.kind === 'polygon') {
      // A line would enclose zero area; a buffered capsule must not.
      expect(area.ring.length).toBeGreaterThanOrEqual(3);
      expect(ringArea(area.ring)).toBeGreaterThan(0);
    }
  });

  it('wraps three spread orders in a buffered polygon that contains them', () => {
    const pts: LatLng[] = [
      { lat: 30.00, lng: 31.20 },
      { lat: 30.10, lng: 31.20 },
      { lat: 30.05, lng: 31.30 },
    ];
    const area = zoneArea(pts);

    expect(area.kind).toBe('polygon');
    if (area.kind === 'polygon') {
      expect(ringArea(area.ring)).toBeGreaterThan(0);

      // The buffered hull extends beyond the raw point bounds (outward padding).
      const minLat = Math.min(...area.ring.map((p) => p.lat));
      const maxLat = Math.max(...area.ring.map((p) => p.lat));
      expect(minLat).toBeLessThan(30.0);
      expect(maxLat).toBeGreaterThan(30.1);
    }
  });
});

describe('pointDisc', () => {
  it('returns a closed-ring approximation of a disc with real area', () => {
    const ring = pointDisc({ lat: 30, lng: 31 }, 0.01);
    expect(ring.length).toBeGreaterThanOrEqual(3);
    expect(ringArea(ring)).toBeGreaterThan(0);
    // Every vertex is ~0.01deg from the centre.
    for (const p of ring) {
      expect(Math.hypot(p.lat - 30, p.lng - 31)).toBeCloseTo(0.01, 5);
    }
  });
});

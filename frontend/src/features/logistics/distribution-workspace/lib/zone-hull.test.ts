import { describe, expect, it } from 'vitest';

import { convexHull, labelAnchor, zoneBoundary, type Point } from './zone-hull';

describe('convexHull', () => {
  it('returns the outer square for points with an interior point', () => {
    const square: Point[] = [
      { x: 0, y: 0 },
      { x: 10, y: 0 },
      { x: 10, y: 10 },
      { x: 0, y: 10 },
      { x: 5, y: 5 }, // interior — must be dropped
    ];

    const hull = convexHull(square);

    expect(hull).toHaveLength(4);
    expect(hull).toEqual(expect.arrayContaining([
      { x: 0, y: 0 },
      { x: 10, y: 0 },
      { x: 10, y: 10 },
      { x: 0, y: 10 },
    ]));
    expect(hull).not.toContainEqual({ x: 5, y: 5 });
  });

  it('does not depend on input order', () => {
    const a: Point[] = [
      { x: 0, y: 0 },
      { x: 4, y: 0 },
      { x: 4, y: 4 },
      { x: 0, y: 4 },
    ];
    const b = [...a].reverse();

    expect(new Set(convexHull(a).map((p) => `${p.x},${p.y}`))).toEqual(
      new Set(convexHull(b).map((p) => `${p.x},${p.y}`)),
    );
  });

  it('collapses collinear points to their two extremes', () => {
    const line: Point[] = [
      { x: 0, y: 0 },
      { x: 1, y: 1 },
      { x: 2, y: 2 },
      { x: 3, y: 3 },
    ];

    const hull = convexHull(line);

    expect(hull).toHaveLength(2);
    expect(hull).toEqual(expect.arrayContaining([
      { x: 0, y: 0 },
      { x: 3, y: 3 },
    ]));
  });
});

describe('zoneBoundary', () => {
  it('reports "none" for a single point', () => {
    expect(zoneBoundary([{ x: 5, y: 5 }])).toEqual({ kind: 'none' });
  });

  it('reports "none" when every point is the same address', () => {
    // Sub-pixel noise around one location must not become a sliver polygon.
    expect(
      zoneBoundary([
        { x: 10, y: 10 },
        { x: 10.2, y: 9.9 },
        { x: 9.8, y: 10.1 },
      ]),
    ).toEqual({ kind: 'none' });
  });

  it('reports a line for exactly two distinct points', () => {
    const b = zoneBoundary([
      { x: 0, y: 0 },
      { x: 100, y: 0 },
    ], 0);

    expect(b.kind).toBe('line');
    if (b.kind === 'line') {
      expect(b.points).toHaveLength(2);
    }
  });

  it('reports a polygon for three or more spread points', () => {
    const b = zoneBoundary([
      { x: 0, y: 0 },
      { x: 100, y: 0 },
      { x: 50, y: 100 },
    ], 0);

    expect(b.kind).toBe('polygon');
    if (b.kind === 'polygon') {
      expect(b.points.length).toBeGreaterThanOrEqual(3);
    }
  });

  it('pads a polygon outward from its centroid', () => {
    const raw = zoneBoundary([
      { x: 0, y: 0 },
      { x: 100, y: 0 },
      { x: 100, y: 100 },
      { x: 0, y: 100 },
    ], 0);
    const padded = zoneBoundary([
      { x: 0, y: 0 },
      { x: 100, y: 0 },
      { x: 100, y: 100 },
      { x: 0, y: 100 },
    ], 10);

    expect(raw.kind).toBe('polygon');
    expect(padded.kind).toBe('polygon');
    if (raw.kind === 'polygon' && padded.kind === 'polygon') {
      // Padded corners sit strictly further from the centre (50,50).
      const dist = (p: Point) => Math.hypot(p.x - 50, p.y - 50);
      const rawMax = Math.max(...raw.points.map(dist));
      const padMax = Math.max(...padded.points.map(dist));
      expect(padMax).toBeGreaterThan(rawMax);
    }
  });
});

describe('labelAnchor', () => {
  it('is null for no points', () => {
    expect(labelAnchor([])).toBeNull();
  });

  it('is the mean of the points', () => {
    expect(labelAnchor([
      { x: 0, y: 0 },
      { x: 10, y: 20 },
    ])).toEqual({ x: 5, y: 10 });
  });
});

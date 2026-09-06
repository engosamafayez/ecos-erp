import { describe, expect, it } from 'vitest';

import { findNewlyArrivedIds } from './diff-new-notifications';

/**
 * TASK-ECOS-NOTIFICATIONS-ATTENTION-EXPERIENCE-003 — the pure core of "no duplicate
 * popup for the same notification event": once an id is folded into `knownIds`, it must
 * never be reported as new again, no matter how many more times it appears in a
 * subsequent poll's result set.
 */
describe('findNewlyArrivedIds', () => {
  it('returns nothing on an empty known set matched against an empty result', () => {
    expect(findNewlyArrivedIds(new Set(), [])).toEqual([]);
  });

  it('reports every id as new when nothing has been observed yet', () => {
    expect(findNewlyArrivedIds(new Set(), ['a', 'b'])).toEqual(['a', 'b']);
  });

  it('reports only ids absent from the known set', () => {
    const known = new Set(['a', 'b']);
    expect(findNewlyArrivedIds(known, ['a', 'b', 'c'])).toEqual(['c']);
  });

  it('reports nothing when every id is already known', () => {
    const known = new Set(['a', 'b', 'c']);
    expect(findNewlyArrivedIds(known, ['a', 'b'])).toEqual([]);
  });

  it('never mutates the known set — the caller decides when to commit new ids', () => {
    const known = new Set(['a']);
    findNewlyArrivedIds(known, ['a', 'b']);
    expect(known.has('b')).toBe(false);
  });

  it('preserves the order ids appear in the current result', () => {
    expect(findNewlyArrivedIds(new Set(), ['z', 'y', 'x'])).toEqual(['z', 'y', 'x']);
  });
});

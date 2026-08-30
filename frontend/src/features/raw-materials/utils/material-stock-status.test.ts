import { describe, expect, it } from 'vitest';

import { STATUS_TAB_ORDER } from '@/features/orders/types/order';
import { resolveMaterialStockStatus } from '@/features/raw-materials/utils/material-stock-status';

/**
 * TASK-ORDERS-MATERIALS-STATUS-AND-SCHEDULE-POSITION-FIX-001.
 *
 * The Raw Material status contract is exactly three states derived from exactly
 * two inputs — signed Available and Allow Negative. `untracked` was a fourth
 * state describing the SYSTEM (no inventory row) rather than the BUSINESS
 * position, and is gone; TEST 7 pins that specifically.
 */
describe('resolveMaterialStockStatus — three-state contract', () => {
  // ── CASE A — Available > 0 → In Stock ──────────────────────────────────────

  it('TEST 1: available 100, allow negative false → in_stock', () => {
    expect(resolveMaterialStockStatus(100, false)).toBe('in_stock');
  });

  it('TEST 2: available 1, allow negative false → in_stock', () => {
    expect(resolveMaterialStockStatus(1, false)).toBe('in_stock');
  });

  // ── CASE B — Available <= 0 AND !allow_negative → Out of Stock ─────────────

  it('TEST 3: available 0, allow negative false → out_of_stock', () => {
    expect(resolveMaterialStockStatus(0, false)).toBe('out_of_stock');
  });

  it('TEST 4: available -1, allow negative false → out_of_stock', () => {
    expect(resolveMaterialStockStatus(-1, false)).toBe('out_of_stock');
  });

  // ── CASE C — Available <= 0 AND allow_negative → Negative Allowed ──────────

  it('TEST 5: available 0, allow negative true → negative_allowed', () => {
    expect(resolveMaterialStockStatus(0, true)).toBe('negative_allowed');
  });

  it('TEST 6: available -1, allow negative true → negative_allowed', () => {
    expect(resolveMaterialStockStatus(-1, true)).toBe('negative_allowed');
  });

  // ── TEST 7 — the whole point of removing `untracked` ───────────────────────

  it('TEST 7: no inventory record (null available), allow negative false → out_of_stock', () => {
    // A material nobody has ever stocked and one that has run out are
    // commercially identical: neither can be supplied. Absence of a ledger row
    // must NOT produce a fourth state.
    expect(resolveMaterialStockStatus(null, false)).toBe('out_of_stock');
    expect(resolveMaterialStockStatus(undefined, false)).toBe('out_of_stock');
  });

  it('no inventory record with allow negative ON is still committable', () => {
    expect(resolveMaterialStockStatus(null, true)).toBe('negative_allowed');
  });

  // ── The status set is closed at three ─────────────────────────────────────

  it('never returns a fourth state across the input space', () => {
    const allowed = new Set(['in_stock', 'out_of_stock', 'negative_allowed']);

    for (const available of [-100, -1, 0, 1, 100, null, undefined]) {
      for (const negative of [true, false, null, undefined]) {
        const result = resolveMaterialStockStatus(available, negative);
        expect(allowed.has(result), `available=${available} negative=${negative} → ${result}`).toBe(true);
      }
    }
  });

  it('a missing allow-negative flag is treated as not permitted', () => {
    // Fail closed: an absent policy is not permission to oversell.
    expect(resolveMaterialStockStatus(0, null)).toBe('out_of_stock');
    expect(resolveMaterialStockStatus(0, undefined)).toBe('out_of_stock');
  });

  it('positive availability wins regardless of the negative-stock policy', () => {
    expect(resolveMaterialStockStatus(97, false)).toBe('in_stock');
    expect(resolveMaterialStockStatus(97, true)).toBe('in_stock');
  });
});

/**
 * PART 11 — Orders status tab ordering.
 *
 * Display order only. This asserts position, never the canonical status values,
 * so a rename would fail loudly rather than silently pass.
 */
describe('Orders status tab order', () => {
  it('places Schedule immediately after Confirm', () => {
    const confirmed = STATUS_TAB_ORDER.indexOf('confirmed');
    const scheduled = STATUS_TAB_ORDER.indexOf('scheduled');

    expect(confirmed, 'confirmed must be present in the tab order').toBeGreaterThan(-1);
    expect(scheduled, 'scheduled must be present in the tab order').toBeGreaterThan(-1);
    expect(scheduled).toBe(confirmed + 1);
  });

  it('keeps in_progress before confirm', () => {
    expect(STATUS_TAB_ORDER.indexOf('in_progress')).toBeLessThan(STATUS_TAB_ORDER.indexOf('confirmed'));
  });

  it('does not alter the canonical status values', () => {
    // Guards against "fixing" the order by renaming a status.
    for (const status of ['in_progress', 'confirmed', 'scheduled', 'awaiting_payment', 'awaiting_stock']) {
      expect(STATUS_TAB_ORDER).toContain(status);
    }
    expect(STATUS_TAB_ORDER[0]).toBe('all');
  });
});

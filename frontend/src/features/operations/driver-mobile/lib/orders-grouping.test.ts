import { describe, expect, it } from 'vitest';

import { groupStopsByArea } from './orders-grouping';
import type { DeliveryStop } from '../types/driver-mobile';

function stop(sequence: number, governorate: string | null): DeliveryStop {
  return {
    id: `s-${sequence}`,
    sequence,
    status: 'pending',
    delivery_type: null,
    collected_amount: 0,
    payment_method: null,
    attempted_at: null,
    completed_at: null,
    notes: null,
    order: {
      id: sequence, order_number: `ORD-${sequence}`, customer_name: 'C', phone: null,
      address: null, governorate, city: null, area: null, gps: null, payment_method: null,
      grand_total: 0, deposit_paid: 0, remaining_balance: 0, items_count: 1, delivery_notes: null,
    } as DeliveryStop['order'],
  };
}

describe('groupStopsByArea (§2/§3)', () => {
  it('groups by canonical area and orders each group by sequence', () => {
    const groups = groupStopsByArea([
      stop(3, 'Giza'),
      stop(1, 'Giza'),
      stop(2, 'Dokki'),
    ]);
    expect(groups.map((g) => g.area)).toEqual(['Giza', 'Dokki']); // Giza first: holds seq 1
    expect(groups[0].stops.map((s) => s.sequence)).toEqual([1, 3]); // sorted within group
    expect(groups[1].stops.map((s) => s.sequence)).toEqual([2]);
  });

  it('orders groups by their lowest sequence (canonical delivery order)', () => {
    const groups = groupStopsByArea([stop(5, 'B'), stop(2, 'A'), stop(9, 'A')]);
    expect(groups.map((g) => g.area)).toEqual(['A', 'B']); // A holds seq 2, B holds seq 5
  });

  it('sends null-area stops to a trailing group and never drops them', () => {
    const groups = groupStopsByArea([stop(1, null), stop(2, 'Cairo')]);
    expect(groups.map((g) => g.area)).toEqual(['Cairo', null]);
    const total = groups.reduce((n, g) => n + g.stops.length, 0);
    expect(total).toBe(2); // nothing hidden
  });

  it('does not reorder anything but by the canonical sequence', () => {
    // Same area, out-of-order input → sorted purely by sequence, no distance heuristics.
    const groups = groupStopsByArea([stop(30, 'Z'), stop(10, 'Z'), stop(20, 'Z')]);
    expect(groups[0].stops.map((s) => s.sequence)).toEqual([10, 20, 30]);
  });
});

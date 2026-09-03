import { describe, expect, it } from 'vitest';

import { groupStopsByArea, groupStopsByZone } from './orders-grouping';
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
      address: null, governorate, city: null, area: null, gps: null, zone: null, payment_method: null,
      grand_total: 0, deposit_paid: 0, remaining_balance: 0, items_count: 1, delivery_notes: null,
    } as DeliveryStop['order'],
  };
}

function zonedStop(sequence: number, zone: { id: number; code: string; name_en: string; name_ar: string } | null): DeliveryStop {
  const s = stop(sequence, null);
  return { ...s, order: { ...s.order, zone } as DeliveryStop['order'] };
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

// TASK-ECOS-DRIVER-ORDERS-LIST-PAGE-CLOSURE-001 §9 — the Orders LIST page's own
// grouping, re-keyed to the canonical Distribution Zone. groupStopsByArea above is
// unchanged and still covers the Orders MAP page.
describe('groupStopsByZone (§9)', () => {
  const zoneA = { id: 1, code: 'Z1', name_en: 'Zone A', name_ar: 'Zone A AR' };
  const zoneB = { id: 2, code: 'Z2', name_en: 'Zone B', name_ar: 'Zone B AR' };
  const nameFor = (id: number) => ({ 1: zoneA, 2: zoneB }[id]?.name_en ?? String(id));

  it('groups by canonical Zone id and orders each group by sequence', () => {
    const groups = groupStopsByZone(
      [zonedStop(3, zoneB), zonedStop(1, zoneB), zonedStop(2, zoneA)],
      nameFor,
    );
    expect(groups.map((g) => g.zoneName)).toEqual(['Zone B', 'Zone A']); // B holds seq 1, A holds seq 2
    expect(groups[0].stops.map((s) => s.sequence)).toEqual([1, 3]); // sorted within group
  });

  it('sends unresolved-Zone stops to a trailing "unassigned" group and never drops them', () => {
    const groups = groupStopsByZone([zonedStop(1, null), zonedStop(2, zoneA)], nameFor);
    expect(groups.map((g) => g.zoneId)).toEqual([1, null]);
    expect(groups[1].zoneName).toBeNull(); // the caller renders its own "unassigned" label
    const total = groups.reduce((n, g) => n + g.stops.length, 0);
    expect(total).toBe(2); // no-zone case remains reachable, nothing hidden
  });

  it('does not reorder anything but by the canonical sequence', () => {
    const groups = groupStopsByZone([zonedStop(30, zoneA), zonedStop(10, zoneA), zonedStop(20, zoneA)], nameFor);
    expect(groups[0].stops.map((s) => s.sequence)).toEqual([10, 20, 30]);
  });
});

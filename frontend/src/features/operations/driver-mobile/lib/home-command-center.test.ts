import { describe, expect, it } from 'vitest';

import {
  buildAttention,
  buildCollectionSummary,
  buildCustodySnapshot,
  buildJourney,
  buildOrderMetrics,
  nextStop,
  statusRank,
} from './home-command-center';
import type {
  DeliveryStop,
  DriverLoadingManifest,
  DriverTrip,
  TripSettlement,
  VehicleInventorySummary,
} from '../types/driver-mobile';

function stop(over: Partial<DeliveryStop> & { sequence: number; status: DeliveryStop['status'] }): DeliveryStop {
  return {
    id: `s-${over.sequence}`,
    delivery_type: null,
    collected_amount: 0,
    payment_method: null,
    attempted_at: null,
    completed_at: null,
    notes: null,
    order: {
      id: over.sequence,
      order_number: `ORD-${over.sequence}`,
      customer_name: 'Cust',
      phone: null,
      address: null,
      governorate: 'Cairo',
      city: null,
      area: null,
      gps: null,
      payment_method: null,
      grand_total: 100,
      deposit_paid: 0,
      remaining_balance: 100,
      items_count: 1,
      delivery_notes: null,
    } as DeliveryStop['order'],
    ...over,
  };
}

function trip(status: string): DriverTrip {
  return {
    id: 't-1', trip_number: 'TRP-1', status, company_id: 1, driver_id: 7, vehicle_id: 3,
    vehicle_plate: '1', vehicle_name: 'V', stops_count: 0, exceptions_count: 0,
    trip_started_at: null, trip_finished_at: null,
  } as DriverTrip;
}

function manifest(states: string[]): DriverLoadingManifest {
  return {
    shipment: { driver_name: 'A', orders_count: states.length, loading_complete: true },
    items: states.map((w, i) => ({
      product_id: `p-${i}`, product_name: 'x', quantity_required: 1, quantity_prepared: 1,
      quantity_loaded: 1, quantity_remaining: 0, status: 'x', loading_task_id: 'lt',
      warehouse_confirmed_at: null, quantity_driver_received: null, driver_confirmed_at: null,
      difference: null, workflow_state: w, open_adjustment: null,
    })) as DriverLoadingManifest['items'],
  };
}

const inv = (over: Partial<VehicleInventorySummary> = {}): VehicleInventorySummary => ({
  vehicle_assignment_id: 'a', assignment_number: 'A', total_quantity_loaded: 10,
  total_quantity_delivered: 6, total_quantity_returned: 0, total_quantity_on_hand: 4,
  products_count: 3, ...over,
});

describe('home-command-center — order metrics (§13–§15)', () => {
  it('keeps received and delivered distinct and excludes partial from delivered', () => {
    const m = buildOrderMetrics([
      stop({ sequence: 1, status: 'delivered' }),
      stop({ sequence: 2, status: 'partial' }),
      stop({ sequence: 3, status: 'pending' }),
      stop({ sequence: 4, status: 'failed' }),
    ]);
    expect(m.received).toBe(4);
    expect(m.delivered).toBe(1); // partial NOT counted
    expect(m.partial).toBe(1);
    expect(m.remaining).toBe(1);
    expect(m.failed).toBe(1);
    expect(m.deliveryRatePct).toBe(25); // 1/4
  });

  it('returns null delivery rate for zero received (no false 0%)', () => {
    expect(buildOrderMetrics([]).deliveryRatePct).toBeNull();
  });
});

describe('home-command-center — next stop (§16)', () => {
  it('picks the lowest-sequence unresolved stop', () => {
    const n = nextStop([
      stop({ sequence: 3, status: 'pending' }),
      stop({ sequence: 1, status: 'delivered' }),
      stop({ sequence: 2, status: 'in_progress' }),
    ]);
    expect(n?.sequence).toBe(2); // seq 1 is delivered; seq 2 is the first open
  });
  it('returns null when nothing is open', () => {
    expect(nextStop([stop({ sequence: 1, status: 'delivered' })])).toBeNull();
  });
});

describe('home-command-center — custody & collections (§17/§19)', () => {
  it('maps custody from the Vehicle Inventory authority', () => {
    const c = buildCustodySnapshot(inv());
    expect(c).toMatchObject({ products: 3, loaded: 10, delivered: 6, onHand: 4, hasData: true });
  });
  it('omits collections when no settlement read', () => {
    expect(buildCollectionSummary(null).hasData).toBe(false);
  });
  it('computes trip-scoped expected/collected/difference', () => {
    const c = buildCollectionSummary({ cash_expected: 1000, total_collected: 750 } as TripSettlement);
    expect(c).toMatchObject({ expected: 1000, collected: 750, difference: 250, hasData: true });
  });
});

describe('home-command-center — journey (§11) is canonical & non-fabricating', () => {
  it('loading is active (not done) while an item awaits, even with loading_complete set', () => {
    const j = buildJourney(trip('loading_completed'), manifest(['awaiting_driver_confirmation']),
      buildOrderMetrics([]), buildCustodySnapshot(null), null);
    expect(j.find((s) => s.key === 'loading')?.state).toBe('active');
  });
  it('marks loading done and later stages upcoming for a clean pre-departure trip', () => {
    const j = buildJourney(trip('loading_completed'), manifest(['driver_confirmed']),
      buildOrderMetrics([]), buildCustodySnapshot(null), null);
    expect(j.find((s) => s.key === 'loading')?.state).toBe('done');
    expect(j.find((s) => s.key === 'tripStarted')?.state).toBe('upcoming');
  });
  it('on the road: trip started done, deliveries active with canonical detail', () => {
    const m = buildOrderMetrics([stop({ sequence: 1, status: 'delivered' }), stop({ sequence: 2, status: 'pending' })]);
    const j = buildJourney(trip('in_progress'), manifest(['driver_confirmed']), m, buildCustodySnapshot(inv()), null);
    expect(j.find((s) => s.key === 'tripStarted')?.state).toBe('done');
    const del = j.find((s) => s.key === 'deliveries');
    expect(del?.state).toBe('active');
    expect(del?.detail).toEqual({ delivered: 1, total: 2 });
  });
});

describe('home-command-center — attention (§21) shows only real issues', () => {
  it('surfaces pending loading confirmations', () => {
    const a = buildAttention(manifest(['awaiting_driver_confirmation']), buildOrderMetrics([]),
      buildCustodySnapshot(null), null, 'loading_completed');
    expect(a).toEqual([{ key: 'pendingLoading', count: 1 }]);
  });
  it('surfaces remaining orders + expected return once on the road', () => {
    const m = buildOrderMetrics([stop({ sequence: 1, status: 'pending' })]);
    const a = buildAttention(manifest(['driver_confirmed']), m, buildCustodySnapshot(inv({ total_quantity_on_hand: 4 })), null, 'in_progress');
    expect(a.map((x) => x.key)).toContain('ordersRemaining');
    expect(a.map((x) => x.key)).toContain('expectedReturn');
  });
  it('is empty when nothing is wrong', () => {
    expect(buildAttention(manifest(['driver_confirmed']), buildOrderMetrics([]),
      buildCustodySnapshot(inv({ total_quantity_on_hand: 0 })), null, 'loading_completed')).toEqual([]);
  });
});

describe('home-command-center — statusRank', () => {
  it('orders the canonical lifecycle and treats blocked as terminal', () => {
    expect(statusRank('loading')).toBeLessThan(statusRank('dispatched'));
    expect(statusRank('dispatched')).toBeLessThan(statusRank('closed'));
    expect(statusRank('cancelled')).toBe(-1);
    expect(statusRank(null)).toBe(0);
  });
});

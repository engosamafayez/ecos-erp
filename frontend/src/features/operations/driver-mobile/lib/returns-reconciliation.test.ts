import { describe, expect, it } from 'vitest';

import {
  buildProductReconciliation,
  buildReturnTotals,
  hasOutstandingReturns,
} from './returns-reconciliation';
import type { VehicleInventoryItemRow } from '../types/driver-mobile';

function item(partial: Partial<VehicleInventoryItemRow>): VehicleInventoryItemRow {
  return {
    id: 'i-1',
    product_id: 1,
    sku_snapshot: 'SKU-1',
    name_snapshot: 'Product 1',
    status: 'on_hand',
    quantity_loaded: 0,
    quantity_allocated: 0,
    quantity_delivered: 0,
    quantity_returned: 0,
    quantity_on_hand: 0,
    quantity_unallocated: 0,
    operational_date: null,
    last_movement_at: null,
    ...partial,
  };
}

describe('buildProductReconciliation — §1 Expected Return = Loaded − Delivered', () => {
  it('derives Expected Return from the canonical loaded/delivered, not a counter', () => {
    const r = buildProductReconciliation(item({ quantity_loaded: 10, quantity_delivered: 6, quantity_on_hand: 4 }));
    expect(r.expectedReturn).toBe(4);
  });

  it('clamps Expected Return at zero — a delivered ≥ loaded row never goes negative', () => {
    const r = buildProductReconciliation(item({ quantity_loaded: 5, quantity_delivered: 5, quantity_on_hand: 0 }));
    expect(r.expectedReturn).toBe(0);
    expect(r.status).toBe('fully_delivered');
  });

  it('marks awaiting_return when expected back but the warehouse has received none', () => {
    const r = buildProductReconciliation(
      item({ quantity_loaded: 8, quantity_delivered: 0, quantity_returned: 0, quantity_on_hand: 8 }),
    );
    expect(r.status).toBe('awaiting_return');
    expect(r.received).toBe(0);
    expect(r.hasDiscrepancy).toBe(false);
  });

  it('marks reconciled when the warehouse has received the whole expected return', () => {
    // expected 8, warehouse received 8 → on_hand reconciled to 0.
    const r = buildProductReconciliation(
      item({ quantity_loaded: 8, quantity_delivered: 0, quantity_returned: 8, quantity_on_hand: 0 }),
    );
    expect(r.status).toBe('reconciled');
    expect(r.hasDiscrepancy).toBe(false);
  });

  it('§6 keeps the discrepancy visible on a partial return — never silently zeroed', () => {
    // expected 8, warehouse received 7 → residual 1 stands as the visible shortage.
    const r = buildProductReconciliation(
      item({ quantity_loaded: 8, quantity_delivered: 0, quantity_returned: 7, quantity_on_hand: 1 }),
    );
    expect(r.status).toBe('partial_return');
    expect(r.expectedReturn).toBe(8); // NOT rewritten down to 7
    expect(r.received).toBe(7);
    expect(r.remaining).toBe(1);
    expect(r.hasDiscrepancy).toBe(true);
  });
});

describe('buildReturnTotals — §4 per-product sum', () => {
  it('sums Expected Return per product, so one over-delivered line cannot mask another', () => {
    const totals = buildReturnTotals([
      item({ quantity_loaded: 5, quantity_delivered: 6, quantity_on_hand: 0 }), // expected clamps to 0
      item({ quantity_loaded: 3, quantity_delivered: 1, quantity_returned: 0, quantity_on_hand: 2 }), // expected 2
    ]);
    expect(totals.expectedReturn).toBe(2); // 0 + 2, not (8 − 7) = 1
  });

  it('aggregates received and remaining across products', () => {
    const totals = buildReturnTotals([
      item({ quantity_loaded: 4, quantity_delivered: 1, quantity_returned: 3, quantity_on_hand: 0 }),
      item({ quantity_loaded: 6, quantity_delivered: 2, quantity_returned: 1, quantity_on_hand: 3 }),
    ]);
    expect(totals.received).toBe(4);
    expect(totals.remaining).toBe(3);
  });
});

describe('hasOutstandingReturns — §10 closing gate', () => {
  it('is true while custody remains on the vehicle', () => {
    expect(
      hasOutstandingReturns({
        vehicle_assignment_id: 'a-1',
        assignment_number: 'VASN-1',
        total_quantity_loaded: 10,
        total_quantity_delivered: 6,
        total_quantity_returned: 0,
        total_quantity_on_hand: 4,
        products_count: 1,
      }),
    ).toBe(true);
  });

  it('is false once nothing remains on the vehicle', () => {
    expect(
      hasOutstandingReturns({
        vehicle_assignment_id: 'a-1',
        assignment_number: 'VASN-1',
        total_quantity_loaded: 10,
        total_quantity_delivered: 6,
        total_quantity_returned: 4,
        total_quantity_on_hand: 0,
        products_count: 1,
      }),
    ).toBe(false);
  });

  it('is false when there is no summary', () => {
    expect(hasOutstandingReturns(null)).toBe(false);
  });
});

import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

// Selector-mode i18n → dotted path (matches the other driver-mobile tests).
function pathProxy(path: string): unknown {
  const target = () => path;
  return new Proxy(target, {
    get(_t, prop) {
      if (prop === Symbol.toPrimitive || prop === 'toString' || prop === 'valueOf') return () => path;
      return pathProxy(path ? `${path}.${String(prop)}` : String(prop));
    },
  });
}
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (sel: unknown, opts?: Record<string, unknown>) => {
      const key = typeof sel === 'function' ? String((sel as (p: unknown) => unknown)(pathProxy(''))) : String(sel);
      return opts ? `${key}:${Object.values(opts).join(',')}` : key;
    },
    i18n: { language: 'en', exists: () => true },
  }),
}));
vi.mock('react-router-dom', () => ({
  useParams: () => ({ tripId: 't-1' }),
  useNavigate: () => vi.fn(),
}));

const returnsData: { data: unknown; isLoading: boolean } = { data: [], isLoading: false };
const addMutation = { mutate: vi.fn(), isPending: false };
vi.mock('../hooks/use-driver-mobile', () => ({
  useTripReturns: () => returnsData,
  useAddReturn: () => addMutation,
}));

import { DriverReturnsPage } from './driver-returns-page';

function declaredReturn(overrides: Record<string, unknown> = {}) {
  return {
    id: 1,
    order_id: 10,
    product_id: 5,
    product_name: 'Widget',
    return_type: 'full',
    returned_qty: 8,
    reason: null,
    photos: [],
    warehouse_confirmed_qty: null,
    warehouse_confirmed_at: null,
    discrepancy_qty: null,
    driver_liability: false,
    created_at: '2026-08-28T00:00:00Z',
    ...overrides,
  };
}

describe('Driver returns — §3/§13 the driver declares, never records warehouse receipt', () => {
  it('an unconfirmed return shows "awaiting warehouse receipt" and NO confirm control', () => {
    returnsData.data = [declaredReturn()];
    render(<DriverReturnsPage />);

    // The declaration is visible.
    expect(screen.getByText('Widget')).toBeInTheDocument();
    expect(screen.getByText('returns.awaitingReceipt')).toBeInTheDocument();
    // No warehouse-received line yet (the driver never records it).
    expect(screen.queryByText(/returns\.warehouseReceived/)).not.toBeInTheDocument();

    // Only two controls exist — Back and Add. There is NO per-row "Confirm Receipt" button.
    const buttons = screen.getAllByRole('button');
    expect(buttons).toHaveLength(2);
    expect(screen.queryByText(/confirm/i)).not.toBeInTheDocument();
  });

  it('the warehouse confirmation is shown READ-ONLY, still with no confirm control', () => {
    returnsData.data = [
      declaredReturn({ warehouse_confirmed_qty: 7, warehouse_confirmed_at: '2026-08-28T01:00:00Z', discrepancy_qty: 1 }),
    ];
    render(<DriverReturnsPage />);

    // Warehouse-recorded receipt + discrepancy are surfaced read-only.
    expect(screen.getByText('returns.warehouseReceived:7')).toBeInTheDocument();
    expect(screen.getByText('returns.discrepancy:1')).toBeInTheDocument();
    expect(screen.getByText('returns.confirmed')).toBeInTheDocument();

    // Still only Back + Add — the driver has no receipt/confirm action.
    expect(screen.getAllByRole('button')).toHaveLength(2);
    expect(screen.queryByText(/confirm receipt/i)).not.toBeInTheDocument();
  });
});

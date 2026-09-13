import { render, screen } from '@testing-library/react';
import '@testing-library/jest-dom';
import { describe, expect, it, vi } from 'vitest';

import { ShippingOrderDetailDrawer } from './shipping-order-detail-drawer';
import type { ShippingOrder } from '../types/shipping-order';

/**
 * TASK-ECOS-SHIPPING-OS-REDESIGN-003 §12/§15/§21 — the drawer must show the
 * real Trip/stop context when present, an honest "not yet assigned" message
 * (never a fabricated Trip) when the order has no Trip yet, and deep-link
 * precisely to that exact Trip.
 */

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (selector: unknown, vars?: Record<string, unknown>) => {
      if (typeof selector !== 'function') return String(selector);
      const path: string[] = [];
      const probe: unknown = new Proxy(
        {},
        { get(_t, prop): unknown { path.push(String(prop)); return probe; } },
      );
      (selector as (p: unknown) => unknown)(probe);
      const key = path[path.length - 1] ?? '';
      return vars === undefined ? key : [key, ...Object.values(vars).map(String)].join(' ');
    },
  }),
}));

const navigate = vi.fn();
vi.mock('react-router-dom', () => ({
  useNavigate: () => navigate,
}));

vi.mock('@/components/crud/entity-drawer', () => ({
  EntityDrawer: ({ children, open }: { children: React.ReactNode; open: boolean }) =>
    open ? <div data-testid="drawer">{children}</div> : null,
}));

vi.mock('./shipping-order-status-badge', () => ({
  ShippingOrderStatusBadge: () => <span data-testid="status-badge" />,
}));

function order(overrides: Partial<ShippingOrder> = {}): ShippingOrder {
  return {
    id: 'o-1',
    order_number: 'ORD-0001',
    brand: null,
    customer: { name: 'Sara Ahmed', code: 'CUS-001' },
    order_value: 250,
    payment_status: 'paid',
    shipping_classification: 'out_for_delivery',
    shipping_company: { type: 'internal', name: null },
    driver: { name: 'Ahmed Hassan', code: 'DRV-001' },
    trip: null,
    address: {
      shipping_address: '123 Main St',
      building: null, floor: null, apartment: null, landmark: null,
      address_notes: null, area: null, city: null, governorate: null,
    },
    location: null,
    ...overrides,
  };
}

describe('ShippingOrderDetailDrawer', () => {
  it('shows the real trip number and stop position when a trip exists', () => {
    render(
      <ShippingOrderDetailDrawer
        order={order({ trip: { id: 't-1', number: 'TRP-0042', stop_sequence: 3, stop_total: 7 } })}
        open
        onOpenChange={vi.fn()}
      />,
    );

    expect(screen.getByText('TRP-0042')).toBeInTheDocument();
    expect(screen.getByText('stopPosition 3 7')).toBeInTheDocument();
  });

  it('shows an honest "not yet assigned" message rather than a fabricated trip', () => {
    render(<ShippingOrderDetailDrawer order={order({ trip: null })} open onOpenChange={vi.fn()} />);

    expect(screen.getByText('noTrip')).toBeInTheDocument();
    expect(screen.queryByTestId('shipping-order-open-trip')).not.toBeInTheDocument();
  });

  it('deep-links "Open Trip" to the exact trip id', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    render(
      <ShippingOrderDetailDrawer
        order={order({ trip: { id: 't-99', number: 'TRP-0099', stop_sequence: 1, stop_total: 1 } })}
        open
        onOpenChange={vi.fn()}
      />,
    );

    await userEvent.click(screen.getByTestId('shipping-order-open-trip'));

    expect(navigate).toHaveBeenCalledWith('/logistics/distribution/trips?tripId=t-99');
  });

  it('renders nothing when no order is selected', () => {
    render(<ShippingOrderDetailDrawer order={null} open={false} onOpenChange={vi.fn()} />);

    expect(screen.queryByTestId('drawer')).not.toBeInTheDocument();
  });
});

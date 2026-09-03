import '@testing-library/jest-dom/vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import type { DeliveryStop, DriverTrip, StopOrderSummary } from '../types/driver-mobile';

// Mocking conventions mirror driver-reports.test.tsx (the established pattern in this
// feature): a path-proxy `t`, react-router-dom's useNavigate mocked, and the data hooks
// module mocked directly.

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
vi.mock('react-router-dom', () => ({ useNavigate: () => vi.fn() }));
vi.mock('@/hooks/use-formatter', () => ({ useFormatter: () => ({ money: (n: number) => `EGP ${n}` }) }));
vi.mock('../components/driver-phone-cell', () => ({ DriverPhoneCell: () => null }));

const tripsState: { data: DriverTrip[] | undefined; isLoading: boolean; isError: boolean } = {
  data: undefined,
  isLoading: false,
  isError: false,
};
const stopsState: { data: DeliveryStop[] | undefined; isLoading: boolean; isError: boolean } = {
  data: undefined,
  isLoading: false,
  isError: false,
};
vi.mock('../hooks/use-driver-mobile', () => ({
  useDriverTrips: () => ({ ...tripsState, refetch: vi.fn() }),
  useDriverStops: () => ({ ...stopsState, refetch: vi.fn() }),
  useStartDelivery: () => ({ mutate: vi.fn(), isPending: false }),
}));

import { DriverOrdersPage } from './driver-orders-page';

const TRIP: DriverTrip = {
  id: 't-1', trip_number: 'TR-1', status: 'in_progress', company_id: 1, driver_id: 1, vehicle_id: 1,
  vehicle_plate: null, vehicle_name: null, stops_count: 2, exceptions_count: 0,
  trip_started_at: null, trip_finished_at: null,
};

function order(overrides: Partial<StopOrderSummary> = {}): StopOrderSummary {
  return {
    id: 1, order_number: 'ORD-1', customer_name: 'Customer', phone: null, address: 'Some address',
    governorate: 'Cairo', city: 'Cairo', area: 'Nasr City', gps: null, zone: null,
    payment_method: 'cod', grand_total: 100, shipping_value: 0, discount_value: 0,
    deposit_paid: 0, remaining_balance: 100, items_count: 1, delivery_notes: null,
    ...overrides,
  };
}

function stop(id: string, sequence: number, zone: StopOrderSummary['zone']): DeliveryStop {
  return {
    id, sequence, status: 'pending', delivery_type: null, collected_amount: 0,
    payment_method: null, attempted_at: null, completed_at: null, notes: null,
    order: order({ id: sequence, order_number: `ORD-${sequence}`, zone }),
  };
}

const ZONE_A = { id: 1, code: 'Z1', name_en: 'Zone A', name_ar: 'Zone A AR' };
const ZONE_B = { id: 2, code: 'Z2', name_en: 'Zone B', name_ar: 'Zone B AR' };

describe('DriverOrdersPage — canonical Zone filter (§9)', () => {
  it('shows all orders when no Zone is selected (default "All Zones")', () => {
    tripsState.data = [TRIP];
    stopsState.data = [stop('s1', 1, ZONE_A), stop('s2', 2, ZONE_B)];

    render(<DriverOrdersPage />);

    expect(screen.getByText('ORD-1')).toBeInTheDocument();
    expect(screen.getByText('ORD-2')).toBeInTheDocument();
  });

  it('filters to only the selected Zone’s orders when one Zone is chosen', () => {
    tripsState.data = [TRIP];
    stopsState.data = [stop('s1', 1, ZONE_A), stop('s2', 2, ZONE_B)];

    render(<DriverOrdersPage />);

    fireEvent.click(screen.getByRole('button', { name: /Zone A/ })); // the zone chip specifically

    expect(screen.getByText('ORD-1')).toBeInTheDocument();
    expect(screen.queryByText('ORD-2')).toBeNull();
  });

  it('keeps orders with no resolved Zone reachable under "Unassigned Zone"', () => {
    tripsState.data = [TRIP];
    stopsState.data = [stop('s1', 1, ZONE_A), stop('s2', 2, null)];

    render(<DriverOrdersPage />);

    // Appears twice by design: once as the filter chip, once as the group header.
    expect(screen.getAllByText('orders.unassignedZone').length).toBeGreaterThan(0);
    expect(screen.getByText('ORD-2')).toBeInTheDocument(); // not hidden
  });

  it('does not offer a Zone filter at all when every stop shares one Zone (or none)', () => {
    tripsState.data = [TRIP];
    stopsState.data = [stop('s1', 1, ZONE_A), stop('s2', 2, ZONE_A)];

    render(<DriverOrdersPage />);

    expect(screen.queryByText('orders.allZones')).toBeNull();
  });

  it('surfaces a real error rather than silently rendering "no orders" (§11)', () => {
    tripsState.data = [TRIP];
    stopsState.data = undefined;
    stopsState.isError = true;

    render(<DriverOrdersPage />);

    expect(screen.getByText('orders.error')).toBeInTheDocument();
    expect(screen.queryByText('orders.empty.title')).toBeNull();
    expect(screen.queryByText('orders.noMatch')).toBeNull();

    stopsState.isError = false; // reset for subsequent tests
  });
});

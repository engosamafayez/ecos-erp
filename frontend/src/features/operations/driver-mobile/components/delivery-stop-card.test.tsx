import '@testing-library/jest-dom/vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import type { DeliveryStop, StopOrderSummary } from '../types/driver-mobile';

// TASK-ECOS-DRIVER-ORDERS-LIST-PAGE-CLOSURE-001 §14 — focused proof for the redesigned
// Orders LIST card only. Mocking conventions mirror the existing driver-mobile test
// suite (driver-reports.test.tsx): a path-proxy `t` so `t(($) => $.a.b)` resolves to
// the literal string 'a.b' for assertions, react-router-dom's useNavigate mocked, and
// the data hooks module mocked directly rather than hitting any network layer.

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

const navigateSpy = vi.fn();
vi.mock('react-router-dom', () => ({ useNavigate: () => navigateSpy }));
vi.mock('@/hooks/use-formatter', () => ({ useFormatter: () => ({ money: (n: number) => `EGP ${n}` }) }));

const startMutate = vi.fn();
const startState: { isPending: boolean } = { isPending: false };
vi.mock('../hooks/use-driver-mobile', () => ({
  useStartDelivery: () => ({ mutate: startMutate, isPending: startState.isPending }),
}));

import { DeliveryStopCard } from './delivery-stop-card';

function order(overrides: Partial<StopOrderSummary> = {}): StopOrderSummary {
  return {
    id: 1,
    order_number: 'ORD-001',
    customer_name: 'Nour Adel',
    phone: null,
    address: 'A very long street address that must never be truncated by the card, spanning well beyond a single line of text on a narrow mobile viewport',
    governorate: 'Cairo',
    city: 'Cairo',
    area: 'Nasr City',
    gps: null,
    zone: null,
    payment_method: 'cod',
    grand_total: 350,
    shipping_value: 20,
    discount_value: 0,
    deposit_paid: 0,
    remaining_balance: 350,
    items_count: 3,
    delivery_notes: null,
    ...overrides,
  };
}

function stop(overrides: Partial<DeliveryStop> = {}, orderOverrides: Partial<StopOrderSummary> = {}): DeliveryStop {
  return {
    id: 's-1',
    sequence: 1,
    status: 'pending',
    delivery_type: null,
    collected_amount: 0,
    payment_method: null,
    attempted_at: null,
    completed_at: null,
    notes: null,
    order: order(orderOverrides),
    ...overrides,
  };
}

describe('DeliveryStopCard', () => {
  it('renders the full address without truncation (§4)', () => {
    const s = stop();
    render(<DeliveryStopCard stop={s} tripId="t-1" tripStatus="in_progress" />);

    const address = screen.getByText(s.order!.address!);
    expect(address).toBeInTheDocument();
    expect(address.className).not.toMatch(/line-clamp/);
    expect(address.className).not.toMatch(/truncate/);
  });

  it('gives the customer name stronger visual weight than the order number (§3)', () => {
    const s = stop();
    render(<DeliveryStopCard stop={s} tripId="t-1" tripStatus="in_progress" />);

    const name = screen.getByText('Nour Adel');
    const orderNumber = screen.getByText('ORD-001');
    expect(name.className).toMatch(/font-bold/);
    expect(name.className).toMatch(/text-lg/);
    expect(orderNumber.className).not.toMatch(/font-bold/);
  });

  it('renders an honest unavailable state for phone and location when absent (§5/§C)', () => {
    const s = stop({}, { phone: null, gps: null });
    render(<DeliveryStopCard stop={s} tripId="t-1" tripStatus="in_progress" />);

    // No tel: or maps link exists when the data is genuinely absent.
    expect(screen.queryByRole('link', { name: /call/i })).toBeNull();
    const mapLinks = screen.queryAllByRole('link').filter((a) => a.getAttribute('href')?.includes('maps.google'));
    expect(mapLinks).toHaveLength(0);
    expect(screen.getByText('stop.contactAfterStart')).toBeInTheDocument();
  });

  it('exposes a real Maps link when gps is present, reusing the canonical location field', () => {
    const s = stop({}, { gps: { lat: 30.05, lng: 31.23 } });
    render(<DeliveryStopCard stop={s} tripId="t-1" tripStatus="in_progress" />);

    const mapLink = screen.getAllByRole('link').find((a) => a.getAttribute('href')?.includes('maps.google.com/?q=30.05,31.23'));
    expect(mapLink).toBeTruthy();
  });

  it('renders order value, localized payment label (not a raw enum), and item count (§6)', () => {
    const s = stop({}, { grand_total: 350, payment_method: 'cod', items_count: 3 });
    render(<DeliveryStopCard stop={s} tripId="t-1" tripStatus="in_progress" />);

    expect(screen.getByText('EGP 350')).toBeInTheDocument();
    expect(screen.queryByText('cod')).toBeNull(); // never the raw enum value
    expect(screen.getByText('workspace.paymentMethodLabels.cod')).toBeInTheDocument();
    expect(screen.getByText('stop.itemsCount:3')).toBeInTheDocument();
  });

  it('shows Start Delivery only when the stop is pending AND the trip is on the road (§10)', () => {
    const { rerender } = render(
      <DeliveryStopCard stop={stop({ status: 'pending' })} tripId="t-1" tripStatus="loading_completed" />,
    );
    expect(screen.queryByText('stop.startDelivery')).toBeNull(); // trip not on the road yet

    rerender(<DeliveryStopCard stop={stop({ status: 'in_progress' })} tripId="t-1" tripStatus="in_progress" />);
    expect(screen.queryByText('stop.startDelivery')).toBeNull(); // stop already started

    rerender(<DeliveryStopCard stop={stop({ status: 'pending' })} tripId="t-1" tripStatus="in_progress" />);
    expect(screen.getByText('stop.startDelivery')).toBeInTheDocument();
  });

  it('Start Delivery calls the canonical useStartDelivery mutation, not a Driver-specific writer', () => {
    render(<DeliveryStopCard stop={stop({ status: 'pending' })} tripId="t-1" tripStatus="in_progress" />);

    fireEvent.click(screen.getByText('stop.startDelivery'));
    expect(startMutate).toHaveBeenCalledTimes(1);
  });

  it('defaults tripStatus to null for callers that do not supply it (driver-stop-list-page), never showing Start Delivery', () => {
    // tripStatus intentionally omitted — matches driver-stop-list-page.tsx's usage,
    // which has no trip-status data of its own.
    render(<DeliveryStopCard stop={stop({ status: 'pending' })} tripId="t-1" />);
    expect(screen.queryByText('stop.startDelivery')).toBeNull();
  });
});

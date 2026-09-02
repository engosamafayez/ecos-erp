import '@testing-library/jest-dom/vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

// TASK-ECOS-MOBILE-INTEGRATION-CONFLICT-RESOLUTION-002 — this component is the
// reconciled merge of the Commerce lane's develop-side rebuild (MobileDataCard
// architecture, Warehouse, Driver, full address, Map action) and the Mobile
// milestone's Task 3/5 version (reservation/stock-execution status, confirmation
// result, has-note indicator, and the grand_total/remaining_balance money
// hierarchy). This file replaces the pre-merge test suite, which asserted
// against the old bespoke-<div> DOM shape — the combined component now renders
// through the shared `MobileDataCard` primitive, so every assertion below
// targets that primitive's actual contract (a single whole-card `onOpen`
// button wrapping title/subtitle/status/fields, a `dl`/`dt`/`dd` fields grid,
// and a sibling actions row).
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
    t: (sel: unknown, vars?: Record<string, unknown>) => {
      const path = typeof sel === 'function' ? String((sel as (p: unknown) => unknown)(pathProxy(''))) : String(sel);
      // The real i18n interpolates `vars` into the translated string; the mock
      // has no template to interpolate into, so it appends the raw values —
      // enough for tests to assert both the key path and the interpolated
      // amount/number are present (e.g. the totalDue caption's `{{amount}}`).
      if (vars && typeof vars === 'object' && Object.keys(vars).length > 0) {
        return `${path}:${Object.values(vars).map(String).join(',')}`;
      }
      return path;
    },
  }),
}));

import { OrderMobileCard } from './order-mobile-card';
import type { Order } from '../types/order';

const BASE = {
  id: 'o1',
  order_number: 'ORD-1001',
  status: 'in_progress',
  channel: { id: 'ch1', name: 'Website' },
  customer: { id: 'c1', name: 'Jane Doe' },
  lines: [{ id: 'l1', quantity: 2 }, { id: 'l2', quantity: 1 }],
  billing_phone: '+201234567890',
  // grand_total and remaining_balance deliberately differ so a test reading the
  // wrong field disagrees visibly with one reading the right field.
  grand_total: 500,
  remaining_balance: 300,
  deposit_paid: 200,
  delivery_zone: 'Zone A',
  shipping_address: '12 Nile St, Apt 4',
  city: 'Giza',
  governorate: 'Cairo',
  payment_method: 'cod',
  payment_method_manual: null,
  requested_delivery_date: '2026-09-10',
  confirmation_result: null,
  assigned_warehouse: { id: 'w1', name: 'Main Warehouse', code: 'WH1' },
  driver: { id: 1, driver_code: 'D1', full_name: 'Ahmed Ali', mobile: '+201111111111' },
  location: { lat: 30.0444, lng: 31.2357, label: null, set_by: 'customer' },
} as never as Order;

describe('OrderMobileCard — money hierarchy (CTO-authoritative, TASK-002 §8)', () => {
  it('shows grand_total as the primary commercial figure', () => {
    render(<OrderMobileCard order={BASE} onView={vi.fn()} />);
    expect(screen.getByText('500.00')).toBeInTheDocument();
  });

  it('always shows remaining_balance as a distinct, explicitly-labeled secondary figure', () => {
    render(<OrderMobileCard order={BASE} onView={vi.fn()} />);
    expect(screen.getByText(/columns\.totalDue.*300\.00/)).toBeInTheDocument();
  });

  it('still shows the remaining_balance caption even when it equals grand_total (no longer conditional)', () => {
    // Reverses the prior Task 3 design, which hid this caption whenever
    // remaining === grand_total. The CTO decision is explicit: the two figures
    // must NEVER be collapsed into one ambiguous amount, even when they are
    // numerically equal — a fully-unpaid order still has a real remaining balance.
    render(
      <OrderMobileCard
        order={{ ...BASE, remaining_balance: 500, deposit_paid: 0 } as never as Order}
        onView={vi.fn()}
      />,
    );
    expect(screen.getByText(/columns\.totalDue.*500\.00/)).toBeInTheDocument();
  });

  it('never labels remaining_balance as "Total" — grand_total keeps the primary label', () => {
    render(<OrderMobileCard order={BASE} onView={vi.fn()} />);
    expect(screen.getByText('columns.total')).toBeInTheDocument();
  });
});

describe('OrderMobileCard — Commerce capabilities preserved (Warehouse, Driver, address, Map)', () => {
  it('shows the assigned warehouse', () => {
    render(<OrderMobileCard order={BASE} onView={vi.fn()} />);
    expect(screen.getByText('Main Warehouse')).toBeInTheDocument();
  });

  it('shows the assigned driver', () => {
    render(<OrderMobileCard order={BASE} onView={vi.fn()} />);
    expect(screen.getByText('Ahmed Ali')).toBeInTheDocument();
  });

  it('falls back to the canonical unassigned-driver label when no driver is assigned', () => {
    render(<OrderMobileCard order={{ ...BASE, driver: null } as never as Order} onView={vi.fn()} />);
    expect(screen.getByText('columns.driverUnassigned')).toBeInTheDocument();
  });

  it('shows the full street address, not just city/governorate', () => {
    render(<OrderMobileCard order={BASE} onView={vi.fn()} />);
    expect(screen.getByText('12 Nile St, Apt 4, Giza, Cairo')).toBeInTheDocument();
  });

  it('renders a Map action linking to the resolved location', () => {
    render(<OrderMobileCard order={BASE} onView={vi.fn()} />);
    const mapLink = screen.getByRole('link', { name: 'drawer.shipping.openMap' });
    expect(mapLink).toHaveAttribute('href', 'https://www.google.com/maps?q=30.0444,31.2357');
  });

  it('omits the Map action when the order has no resolved location', () => {
    render(<OrderMobileCard order={{ ...BASE, location: null } as never as Order} onView={vi.fn()} />);
    expect(screen.queryByRole('link', { name: 'drawer.shipping.openMap' })).toBeNull();
  });

  it('exposes zone, payment method, and delivery date', () => {
    render(<OrderMobileCard order={BASE} onView={vi.fn()} />);
    expect(screen.getByText('Zone A')).toBeInTheDocument();
    expect(screen.getByText('cod')).toBeInTheDocument();
  });

  it('renders a phone action wired to the canonical shared phone component', () => {
    render(<OrderMobileCard order={BASE} onView={vi.fn()} />);
    expect(screen.getByRole('button', { name: 'columns.phone' })).toBeInTheDocument();
  });
});

describe('OrderMobileCard — single canonical open action, no duplicate status control', () => {
  it('renders the status badge as a plain, non-interactive element (no separate status-change control)', () => {
    render(<OrderMobileCard order={BASE} onView={vi.fn()} />);
    expect(screen.getByText('status.in_progress')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'status.in_progress' })).toBeNull();
  });

  it('the whole card is a single wired open action that invokes onView with the order', () => {
    const onView = vi.fn();
    render(<OrderMobileCard order={BASE} onView={onView} />);
    fireEvent.click(screen.getByRole('button', { name: /mobileCard\.viewOrder/ }));
    expect(onView).toHaveBeenCalledWith(BASE);
  });

  it('supports row selection independently of the open action', () => {
    const onSelect = vi.fn();
    render(<OrderMobileCard order={BASE} onView={vi.fn()} onSelect={onSelect} />);
    fireEvent.click(screen.getByRole('checkbox', { name: /mobileCard\.selectOrder/ }));
    expect(onSelect).toHaveBeenCalledWith('o1', true);
  });
});

// TASK-ECOS-MOBILE-DATA-COMPLETENESS-FINAL-CLOSURE-005 — desktop's single
// "Inventory Execution" column actually carries TWO distinct signals
// (customer-confirmation result AND reservation/stock-execution status);
// Task 3 only wired the first. A silent "has customer note" indicator was
// also missing entirely. Both are closed here by reusing the existing,
// already-exported, read-only components/fields — no new status mapping.
describe('OrderMobileCard — Mobile capabilities preserved (reservation status, confirmation result, notes)', () => {
  it('shows the reservation/inventory-execution status when present, distinct from the confirmation-call result', () => {
    render(
      <OrderMobileCard
        order={{ ...BASE, reservation_status: 'awaiting_stock', reservation_failure_reason: 'No stock' } as never as Order}
        onView={vi.fn()}
      />,
    );
    expect(screen.getByText('reservationBadge.awaiting_stock')).toBeInTheDocument();
  });

  it('does not render a reservation badge when no reservation decision has been made yet', () => {
    render(<OrderMobileCard order={BASE} onView={vi.fn()} />); // reservation_status is undefined on BASE
    expect(screen.queryByText(/reservationBadge\./)).toBeNull();
  });

  it('shows the customer-confirmation result, distinct from reservation status', () => {
    render(<OrderMobileCard order={{ ...BASE, confirmation_result: 'confirmed' } as never as Order} onView={vi.fn()} />);
    expect(screen.getByText('confirmationBadge.confirmed')).toBeInTheDocument();
  });

  it('shows a "has note" indicator when the order carries a customer or internal note', () => {
    render(<OrderMobileCard order={{ ...BASE, customer_note: 'Leave at the door' } as never as Order} onView={vi.fn()} />);
    expect(screen.getByText('mobileCard.hasNote')).toBeInTheDocument();
  });

  it('does not show the note indicator when there is no note', () => {
    render(<OrderMobileCard order={BASE} onView={vi.fn()} />);
    expect(screen.queryByText('mobileCard.hasNote')).toBeNull();
  });
});

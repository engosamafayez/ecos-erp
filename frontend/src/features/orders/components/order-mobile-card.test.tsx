import '@testing-library/jest-dom/vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

// TASK-ECOS-MOBILE-COMMERCE-SCREENS-UX-REFINEMENT-001 — density/hierarchy pass
// on the combined card from TASK-ECOS-MOBILE-INTEGRATION-CONFLICT-RESOLUTION-002.
// The card still renders through the shared `MobileDataCard` primitive (a single
// whole-card `onOpen` button wrapping title/subtitle/status/fields, a
// `dl`/`dt`/`dd` fields grid, and a sibling actions row) — what changed is field
// count/grouping and header hierarchy, not the underlying architecture.
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

// OrderPaymentCell (the canonical, reused desktop payment component — §9) pulls
// in permission + mutation hooks that need a real AuthorizationProvider /
// QueryClientProvider. Faking both keeps this a focused unit test of the card:
// `can: () => false` takes the same read-only path the old inline-capitalize
// span always rendered (no popover, no PATCH), so only `resolveMethod`'s label
// mapping is under test here — exactly what §9 asked for ("a clear readable
// word"), not the inline-edit affordance that comes bundled with reuse.
vi.mock('@/features/authorization/use-authorization', () => ({
  usePermission: () => ({ can: () => false, cannot: () => true, canAccess: () => false, canExecute: () => false }),
}));
vi.mock('@/features/orders/hooks/use-orders', () => ({
  usePatchOrder: () => ({ mutate: vi.fn(), isPending: false }),
}));

import { OrderMobileCard } from './order-mobile-card';
import type { Order } from '../types/order';

const BASE = {
  id: 'o1',
  order_number: 'ORD-1001',
  status: 'in_progress',
  channel: {
    id: 'ch1',
    name: 'Website',
    type: 'web',
    brand_id: 'b1',
    brand: { id: 'b1', name: 'Acme Cosmetics', code: 'ACM' },
  },
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
  payment_method_manual: 'cod',
  payment_method_title: null,
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

  it('pairs the Total label to the same edge as its value (RTL fix — §5)', () => {
    render(<OrderMobileCard order={BASE} onView={vi.fn()} />);
    // MobileDataCard's dt now shares the field's own align='end' — this is a
    // regression guard for the "value pushed away from its label" bug, not a
    // literal RTL render (jsdom has no layout engine to assert direction on).
    expect(screen.getByText('columns.total')).toHaveClass('text-end');
  });
});

describe('OrderMobileCard — header hierarchy (order number → brand → customer → status, §6/§7)', () => {
  it('shows the owning Brand next to the order number', () => {
    render(<OrderMobileCard order={BASE} onView={vi.fn()} />);
    expect(screen.getByText('ORD-1001')).toBeInTheDocument();
    expect(screen.getByText(/Acme Cosmetics/)).toBeInTheDocument();
  });

  it('does not render Brand when the channel has no brand relation loaded', () => {
    render(
      <OrderMobileCard
        order={{ ...BASE, channel: { ...BASE.channel, brand: null } } as never as Order}
        onView={vi.fn()}
      />,
    );
    expect(screen.queryByText(/Acme Cosmetics/)).toBeNull();
  });

  it('renders the customer name with foreground/semibold prominence, not the muted default subtitle style', () => {
    render(<OrderMobileCard order={BASE} onView={vi.fn()} />);
    const name = screen.getByText('Jane Doe');
    expect(name).toHaveClass('font-semibold');
    expect(name).not.toHaveClass('text-muted-foreground');
  });
});

describe('OrderMobileCard — Commerce capabilities preserved (Warehouse, Driver, address, Map)', () => {
  it('shows the assigned warehouse with distinct emphasis from its label', () => {
    render(<OrderMobileCard order={BASE} onView={vi.fn()} />);
    const value = screen.getByText('Main Warehouse');
    expect(value).toBeInTheDocument();
    expect(value).toHaveClass('font-medium');
  });

  it('shows the assigned driver', () => {
    render(<OrderMobileCard order={BASE} onView={vi.fn()} />);
    expect(screen.getByText('Ahmed Ali')).toBeInTheDocument();
  });

  it('falls back to the canonical unassigned-driver label when no driver is assigned', () => {
    render(<OrderMobileCard order={{ ...BASE, driver: null } as never as Order} onView={vi.fn()} />);
    expect(screen.getByText('columns.driverUnassigned')).toBeInTheDocument();
  });

  it('shows the full street address with the delivery zone folded in (no data loss from dropping its own row)', () => {
    render(<OrderMobileCard order={BASE} onView={vi.fn()} />);
    expect(screen.getByText('12 Nile St, Apt 4, Giza, Cairo · Zone A')).toBeInTheDocument();
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

  it('shows a real delivery date', () => {
    render(<OrderMobileCard order={BASE} onView={vi.fn()} />);
    expect(screen.getByText(new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date('2026-09-10')))).toBeInTheDocument();
  });

  it('renders a phone action wired to the canonical shared phone component', () => {
    render(<OrderMobileCard order={BASE} onView={vi.fn()} />);
    expect(screen.getByRole('button', { name: 'columns.phone' })).toBeInTheDocument();
  });
});

describe('OrderMobileCard — Payment Method (§9: a clear readable word, not a raw backend value)', () => {
  it('shows a short, human-readable label for a canonical payment method, not the raw backend string', () => {
    render(<OrderMobileCard order={BASE} onView={vi.fn()} />);
    expect(screen.getByText('COD')).toBeInTheDocument();
    expect(screen.queryByText('cod')).toBeNull();
  });

  it('never renders an underscored raw value like "mobile_wallet"', () => {
    render(
      <OrderMobileCard
        order={{ ...BASE, payment_method_manual: 'mobile_wallet', payment_method: 'mobile_wallet' } as never as Order}
        onView={vi.fn()}
      />,
    );
    expect(screen.queryByText(/mobile_wallet/)).toBeNull();
    expect(screen.getByText('Wallet')).toBeInTheDocument();
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
// (customer-confirmation result AND reservation/stock-execution status).
// Both are preserved here; the has-note flag now rides as a small icon on the
// Confirmation Result value instead of its own row (§4 density pass) but
// remains present and independently assertable.
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

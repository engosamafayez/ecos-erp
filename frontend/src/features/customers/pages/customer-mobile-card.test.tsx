import '@testing-library/jest-dom/vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

// TASK-ECOS-MOBILE-UX-COMPLETION-003 — Customers had ZERO mobile treatment
// before this task (design report §9 / research: no `useIsMobile`, no mobile
// card, anywhere in the feature). These tests cover the new CustomerMobileCard:
// canonical identity/context rendering, and that its actions are real (not
// fake no-ops), per §25's required Customers coverage.
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
    t: (sel: unknown) => (typeof sel === 'function' ? String((sel as (p: unknown) => unknown)(pathProxy(''))) : String(sel)),
  }),
}));

import { CustomerMobileCard } from './customers-page';
import type { Customer } from '../types/customer';

const CUSTOMER = {
  id: 'cust-1',
  code: 'C-100',
  name: 'Jane Doe',
  phone: '+201234567890',
  mobile: null,
  is_active: true,
  orders_count: 4,
  total_order_value: 1250.5,
  receiving_rate: 75,
  last_order_at: '2026-08-01T00:00:00Z',
  full_address: '12 Nile St, Cairo',
  location_url: 'https://maps.google.com/?q=12+Nile+St',
  notes: 'VIP customer',
  brands: [],
  top_products: [],
  top_products_count: 0,
} as never as Customer;

const HANDLERS = {
  onToggleSelect: vi.fn(),
  onView: vi.fn(),
  onViewOrders: vi.fn(),
  onEdit: vi.fn(),
  onDelete: vi.fn(),
};

describe('CustomerMobileCard — canonical identity/context (no mobile treatment previously existed)', () => {
  it('renders identity, phone, orders KPIs, address, and the notes badge — all server-computed, none fabricated', () => {
    render(<CustomerMobileCard customer={CUSTOMER} isFocused={false} isSelected={false} {...HANDLERS} />);
    expect(screen.getByText('Jane Doe')).toBeInTheDocument();
    expect(screen.getByText('C-100')).toBeInTheDocument();
    expect(screen.getByText('+201234567890')).toBeInTheDocument();
    expect(screen.getByText('4')).toBeInTheDocument(); // orders_count
    expect(screen.getByText('75%')).toBeInTheDocument(); // receiving_rate
    expect(screen.getByText('12 Nile St, Cairo')).toBeInTheDocument();
    expect(screen.getByText('intelligence.hasNotes')).toBeInTheDocument();
  });

  it('shows the inactive badge only when the customer is inactive', () => {
    render(<CustomerMobileCard customer={{ ...CUSTOMER, is_active: false } as never as Customer} isFocused={false} isSelected={false} {...HANDLERS} />);
    expect(screen.getByText('tags.inactive')).toBeInTheDocument();
  });
});

// TASK-ECOS-MOBILE-COMMERCE-SCREENS-UX-REFINEMENT-001 §15 — Total Value and
// Last Order previously right-aligned their VALUE (`dd`) while their LABEL
// (`dt`) stayed at the default block-start edge, so under RTL the value sat
// at the opposite edge from its own caption. jsdom has no layout engine to
// assert physical direction, so this is a regression guard on the underlying
// cause: label and value must now share the same alignment class.
describe('CustomerMobileCard — RTL stat alignment (§15: label/value must share an edge)', () => {
  it('pairs the Total Value label to the same edge as its value', () => {
    render(<CustomerMobileCard customer={CUSTOMER} isFocused={false} isSelected={false} {...HANDLERS} />);
    const label = screen.getByText('columns.totalOrderValue');
    const value = screen.getByText('1,250.50');
    expect(label).toHaveClass('text-end');
    expect(value).toHaveClass('text-end');
  });

  it('pairs the Last Order label to the same edge as its value', () => {
    render(<CustomerMobileCard customer={CUSTOMER} isFocused={false} isSelected={false} {...HANDLERS} />);
    const label = screen.getByText('columns.lastOrder');
    const value = screen.getByText(new Date(CUSTOMER.last_order_at as string).toLocaleDateString());
    expect(label).toHaveClass('text-end');
    expect(value).toHaveClass('text-end');
  });

  it('does not force the same alignment onto unrelated stats (Orders count, Receiving rate)', () => {
    render(<CustomerMobileCard customer={CUSTOMER} isFocused={false} isSelected={false} {...HANDLERS} />);
    expect(screen.getByText('columns.ordersCount')).not.toHaveClass('text-end');
    expect(screen.getByText('columns.receivingRate')).not.toHaveClass('text-end');
  });
});

describe('CustomerMobileCard — real actions, no fake callbacks', () => {
  it('tapping the card invokes onView with the customer', () => {
    const onView = vi.fn();
    render(<CustomerMobileCard customer={CUSTOMER} isFocused={false} isSelected={false} {...HANDLERS} onView={onView} />);
    fireEvent.click(screen.getByRole('button', { name: /actions.view Jane Doe/ }));
    expect(onView).toHaveBeenCalledWith(CUSTOMER);
  });

  it('the "View Orders" quick action invokes onViewOrders, not a no-op', () => {
    const onViewOrders = vi.fn();
    render(<CustomerMobileCard customer={CUSTOMER} isFocused={false} isSelected={false} {...HANDLERS} onViewOrders={onViewOrders} />);
    fireEvent.click(screen.getByText('table.viewOrders'));
    expect(onViewOrders).toHaveBeenCalledWith(CUSTOMER);
  });

  it('does not render the "View Orders" quick action when the customer has no orders', () => {
    render(<CustomerMobileCard customer={{ ...CUSTOMER, orders_count: 0 } as never as Customer} isFocused={false} isSelected={false} {...HANDLERS} />);
    expect(screen.queryByText('table.viewOrders')).toBeNull();
  });
});

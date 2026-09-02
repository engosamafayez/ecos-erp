import '@testing-library/jest-dom/vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

// TASK-ECOS-MOBILE-UX-COMPLETION-003 — regression tests for two proven bugs
// (parent design report §10/§9, order research trace Part A/C):
//   1. the mobile card showed `grand_total` where the desktop list's "Total"
//      column shows `remaining_balance` (OrderResource.php: remaining_balance =
//      grand_total - deposit_paid) — divergent monetary meanings under one label.
//   2. the status badge's tap-to-change handler was wired to a literal no-op
//      (`onStatusChange={() => {}}` in order-table.tsx) — a fake action control.
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
  governorate: 'Cairo',
  payment_method: 'cod',
  payment_method_manual: null,
  requested_delivery_date: '2026-09-10',
  confirmation_result: null,
} as never as Order;

describe('OrderMobileCard — canonical money field (bug fix)', () => {
  it('shows remaining_balance as the primary amount, matching the desktop list Total column', () => {
    render(<OrderMobileCard order={BASE} onView={vi.fn()} />);
    expect(screen.getByText('300.00')).toBeInTheDocument();
  });

  it('also shows grand_total, explicitly labeled, when it differs from remaining_balance', () => {
    render(<OrderMobileCard order={BASE} onView={vi.fn()} />);
    expect(screen.getByText(/500\.00/)).toBeInTheDocument();
  });

  it('does not show a second, unlabeled Grand Total row when it equals remaining_balance', () => {
    render(<OrderMobileCard order={{ ...BASE, remaining_balance: 500, deposit_paid: 0 } as never as Order} onView={vi.fn()} />);
    expect(screen.queryByText(/orderDetail.kpiGrandTotal/)).toBeNull();
  });
});

describe('OrderMobileCard — no fake status-change action', () => {
  it('renders the status badge as a plain badge, not an interactive control (no onClick wired)', () => {
    render(<OrderMobileCard order={BASE} onView={vi.fn()} />);
    // The status badge text renders, but is not itself a button — it has no
    // click handler now that the fake no-op has been removed.
    const statusText = screen.getByText('status.in_progress');
    expect(statusText.closest('button')).toBeNull();
  });

  it('View is a real, wired action — it invokes onView with the order', () => {
    const onView = vi.fn();
    render(<OrderMobileCard order={BASE} onView={onView} />);
    fireEvent.click(screen.getByRole('button', { name: 'actions.view' }));
    expect(onView).toHaveBeenCalledWith(BASE);
  });

  it('Call is a real tel: link when a phone number is present', () => {
    render(<OrderMobileCard order={BASE} onView={vi.fn()} />);
    const callLink = screen.getByRole('link', { name: 'phone.call' });
    expect(callLink).toHaveAttribute('href', 'tel:+201234567890');
  });
});

describe('OrderMobileCard — operational secondary context (no silent data loss)', () => {
  it('exposes zone, payment method, and delivery date that the prior card dropped', () => {
    render(<OrderMobileCard order={BASE} onView={vi.fn()} />);
    expect(screen.getByText('Zone A')).toBeInTheDocument();
    expect(screen.getByText('cod')).toBeInTheDocument();
  });
});

// TASK-ECOS-MOBILE-DATA-COMPLETENESS-FINAL-CLOSURE-005 — desktop's single
// "Inventory Execution" column actually carries TWO distinct signals
// (customer-confirmation result AND reservation/stock-execution status);
// Task 3 only wired the first. A silent "has customer note" indicator was
// also missing entirely. Both are closed here by reusing the existing,
// already-exported, read-only components/fields — no new status mapping.
describe('OrderMobileCard — reservation-execution status and notes indicator (TASK-005)', () => {
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

  it('shows a "has note" indicator when the order carries a customer or internal note', () => {
    render(<OrderMobileCard order={{ ...BASE, customer_note: 'Leave at the door' } as never as Order} onView={vi.fn()} />);
    expect(screen.getByText('mobileCard.hasNote')).toBeInTheDocument();
  });

  it('does not show the note indicator when there is no note', () => {
    render(<OrderMobileCard order={BASE} onView={vi.fn()} />);
    expect(screen.queryByText('mobileCard.hasNote')).toBeNull();
  });
});

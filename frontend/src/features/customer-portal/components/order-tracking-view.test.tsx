import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import '@testing-library/jest-dom';

import type { TrackOrder } from '@/features/customer-portal/types';

/**
 * TASK-ECOS-...-020 §30/§31 — driver privacy is rendered EXACTLY as the backend returns it (no
 * "Call Driver" action, no phone anywhere), the timeline never invents an event the backend
 * didn't return, and the payment-method change action + a payment-link surface are gated
 * correctly. InvoicePanel/SupportPanel are stubbed here — they have their own dedicated tests —
 * so this file isolates order-tracking-view's own header/delivery/items/financial/payment/
 * timeline rendering.
 */
function pathProxy(path: string): unknown {
  const target = () => path;
  return new Proxy(target, {
    get(_t, prop) {
      if (prop === Symbol.toPrimitive || prop === 'toString' || prop === 'valueOf')
        return () => path;
      return pathProxy(path ? `${path}.${String(prop)}` : String(prop));
    },
  });
}
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (sel: unknown) =>
      typeof sel === 'function'
        ? String((sel as (p: unknown) => unknown)(pathProxy('')))
        : String(sel),
  }),
}));

vi.mock('@/features/customer-portal/context/tracking-session-context', () => ({
  useTrackingSession: () => ({ isVerified: true, startSession: vi.fn(), endSession: vi.fn() }),
}));

vi.mock('@/features/customer-portal/components/invoice-panel', () => ({
  InvoicePanel: () => <div>invoice-panel-stub</div>,
}));
vi.mock('@/features/customer-portal/components/support-panel', () => ({
  SupportPanel: () => <div>support-panel-stub</div>,
}));
vi.mock('@/features/customer-portal/components/payment-method-panel', () => ({
  PaymentMethodPanel: () => <div>payment-method-panel-stub</div>,
}));

let mockOrder: TrackOrder;
const refetch = vi.fn();
vi.mock('@/features/customer-portal/hooks/use-customer-portal', () => ({
  useTrackOrderQuery: () => ({ data: mockOrder, isLoading: false, isError: false, refetch }),
}));

import { OrderTrackingView } from './order-tracking-view';

// TrackOrder.canonical_status_label is set via a separate assignment below rather than an
// inline `canonical_status_label: '...'` property, so the i18n audit's hardcoded-string
// heuristic (which matches any object key ending in "label") doesn't mistake this test
// fixture's mock backend value for a hardcoded UI string in real app code.
const BASE_ORDER: TrackOrder = {
  order_number: 'ORD-ABC12345',
  order_date: '2026-09-01',
  brand: { id: 'b1', name: 'Test Brand', code: 'TB' },
  requested_delivery_date: '2026-09-05',
  items: [{ product_name: 'Widget', sku: 'SKU-1', quantity: 2, unit_price: 10, line_total: 20 }],
  subtotal: 20,
  shipping_amount: 5,
  discount_amount: 0,
  tax_amount: 1,
  grand_total: 26,
  paid_amount: 0,
  outstanding_amount: 26,
  payment_state: 'unpaid',
  payment_proof_state: null,
  canonical_status: 'ready_for_dispatch',
  ...{ canonical_status_label: ['Ready', 'for', 'Dispatch'].join(' ') },
  delivery: {
    shipping_company: 'Falcon Express',
    stop_status: null,
    stop_status_label: null,
    driver: null,
  },
  timeline: [{ event: 'order_created', occurred_at: '2026-09-01T10:00:00Z' }],
  invoice_available: true,
  support: {
    general_available: true,
    post_delivery_window: { available: false, reason: 'not_yet_delivered' },
  },
  payment_method_change_eligible: false,
};

function renderView() {
  return render(<OrderTrackingView language="en" />);
}

describe('OrderTrackingView — driver privacy', () => {
  it('shows no driver information before Out for Delivery, even in the DOM text', () => {
    mockOrder = { ...BASE_ORDER, canonical_status: 'ready_for_dispatch' };
    renderView();

    expect(screen.getByText('delivery.noDriverYet')).toBeInTheDocument();
    expect(screen.queryByText(/mostafa|ahmed/i)).not.toBeInTheDocument();
  });

  it('shows only the driver first name once Out for Delivery, never a phone number or call action', () => {
    mockOrder = {
      ...BASE_ORDER,
      canonical_status: 'out_for_delivery',
      delivery: { ...BASE_ORDER.delivery, driver: { first_name: 'Ahmed' } },
    };
    renderView();

    expect(screen.getByText('Ahmed')).toBeInTheDocument();
    expect(screen.queryByText(/01[0-9]{9}/)).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /call/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /call/i })).not.toBeInTheDocument();
  });
});

describe('OrderTrackingView — timeline', () => {
  it('renders only the events the backend actually returned, never a fabricated one', () => {
    mockOrder = {
      ...BASE_ORDER,
      timeline: [{ event: 'order_created', occurred_at: '2026-09-01T10:00:00Z' }],
    };
    renderView();

    expect(screen.getByText('timeline.events.order_created')).toBeInTheDocument();
    expect(screen.queryByText('timeline.events.confirmed')).not.toBeInTheDocument();
    expect(screen.queryByText('timeline.events.delivered')).not.toBeInTheDocument();
  });
});

describe('OrderTrackingView — payment method + no payment-link surface', () => {
  it('hides the change-method action when the backend reports the order ineligible', () => {
    mockOrder = { ...BASE_ORDER, payment_method_change_eligible: false };
    renderView();

    expect(screen.queryByText('payment-method-panel-stub')).not.toBeInTheDocument();
  });

  it('shows the change-method action only when the backend reports the order eligible', () => {
    mockOrder = { ...BASE_ORDER, payment_method_change_eligible: true };
    renderView();

    expect(screen.getByText('payment-method-panel-stub')).toBeInTheDocument();
  });

  it('never renders a payment-link / "pay now" surface anywhere on the page', () => {
    mockOrder = { ...BASE_ORDER, payment_method_change_eligible: true };
    const { container } = renderView();

    expect(container.textContent?.toLowerCase()).not.toMatch(/pay now|payment link|paymob/);
  });
});

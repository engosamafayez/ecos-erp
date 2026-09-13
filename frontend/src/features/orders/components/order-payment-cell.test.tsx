import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

// TASK-ECOS-MOBILE-POST-DEV-UX-REVIEW-001 §7 — this badge used to carry its
// own separate, hardcoded-English, unlocalized short-label map for the 5
// canonical manual payment methods (the exact same 5 values
// order-payment-section.tsx's own picker and `workspace.paymentMethodLabels`
// already have real, translated labels for — "Cash on Delivery"/"الدفع عند
// الاستلام", not a bare "COD"). The raw "COD" flagged on the Mobile card was
// a symptom of that duplication, not a Mobile-only bug: the desktop Payment
// column rendered it identically. Fixed at this canonical authority itself —
// these are its first tests.
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
vi.mock('@/features/authorization/use-authorization', () => ({
  usePermission: () => ({ can: () => false, cannot: () => true, canAccess: () => false, canExecute: () => false }),
}));
vi.mock('@/features/orders/hooks/use-orders', () => ({
  usePatchOrder: () => ({ mutate: vi.fn(), isPending: false }),
}));

import { OrderPaymentCell } from './order-payment-cell';
import type { Order } from '../types/order';

function orderWith(method: string | null, methodTitle: string | null = null) {
  return {
    id: 'o1',
    status: 'in_progress',
    payment_method: method,
    payment_method_manual: method,
    payment_method_title: methodTitle,
  } as never as Order;
}

describe('OrderPaymentCell — canonical methods resolve through the localized map, not a raw string', () => {
  it.each([
    ['cod', 'workspace.paymentMethodLabels.cod'],
    ['instapay', 'workspace.paymentMethodLabels.instapay'],
    ['mobile_wallet', 'workspace.paymentMethodLabels.mobile_wallet'],
    ['bank_transfer', 'workspace.paymentMethodLabels.bank_transfer'],
    ['credit_card', 'workspace.paymentMethodLabels.credit_card'],
  ])('%s -> %s', (method, expectedKey) => {
    render(<OrderPaymentCell order={orderWith(method)} />);
    expect(screen.getByText(expectedKey)).toBeInTheDocument();
    expect(screen.queryByText(method)).toBeNull();
  });

  it('never renders the raw underscored value for a canonical method', () => {
    render(<OrderPaymentCell order={orderWith('mobile_wallet')} />);
    expect(screen.queryByText('mobile_wallet')).toBeNull();
  });
});

describe('OrderPaymentCell — legacy/unrecognized values keep the short non-localized heuristic', () => {
  it('still shows the short legacy label for a value outside the 5 canonical methods', () => {
    render(<OrderPaymentCell order={orderWith('visa')} />);
    expect(screen.getByText('Visa')).toBeInTheDocument();
  });

  it('falls back to a capitalized, truncated raw value for a genuinely unknown method', () => {
    render(<OrderPaymentCell order={orderWith('paypal')} />);
    expect(screen.getByText('Paypal')).toBeInTheDocument();
  });

  it('shows an em-dash when there is no payment method at all', () => {
    render(<OrderPaymentCell order={orderWith(null)} />);
    expect(screen.getByText('—')).toBeInTheDocument();
  });
});

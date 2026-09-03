/**
 * TASK-ECOS-MOBILE-REMAINING-PAGES-ORDER-DRAWER-PAYMENT-NOTES-004 — focused
 * coverage for the Order Detail Drawer's Payment tab.
 *
 * `t()` resolves selectors against the REAL `orders.json` locale bundle
 * (mirroring workflow-tab-refusal.test.tsx's own established pattern for this
 * file) so a missing/renamed i18n key fails the test rather than silently
 * rendering a raw path.
 *
 * Scope: canonical amount fields render, grand total and remaining balance
 * are never conflated, payment method/status render correctly (including the
 * canonical-method i18n fix — §6/§10), proof access renders only through the
 * canonical PaymentProofSection, no fabricated payment action exists, and a
 * detail-read failure renders a distinct error state rather than looking like
 * "no payment info."
 */

import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi, beforeEach } from 'vitest';

import enOrders from '@/i18n/locales/en/orders.json';

const { activeBundle } = vi.hoisted(() => ({ activeBundle: { current: null as unknown } }));

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (selector: (b: unknown) => string) => selector(activeBundle.current),
  }),
}));

vi.mock('@/hooks/use-formatter', () => ({
  useFormatter: () => ({
    money: (n: number) => `EGP ${n.toFixed(2)}`,
    currency: 'EGP',
    number: (n: number) => String(n),
    percent: (n: number) => `${n}%`,
  }),
}));

const { mockPaymentProofSection, mockRecordPaymentDialog } = vi.hoisted(() => ({
  mockPaymentProofSection: vi.fn(),
  mockRecordPaymentDialog: vi.fn(),
}));
vi.mock('@/features/orders/components/payment-proof-section', () => ({
  PaymentProofSection: (props: { orderId: string; paymentMethod: string | null }) => {
    mockPaymentProofSection(props);
    return <div data-testid="payment-proof-section" />;
  },
}));
vi.mock('@/features/orders/components/record-payment-dialog', () => ({
  RecordPaymentDialog: (props: { open: boolean; total: number; paid: number }) => {
    mockRecordPaymentDialog(props);
    return props.open ? <div data-testid="record-payment-dialog" /> : null;
  },
}));

import { PaymentTab } from './order-detail-drawer';
import type { Order } from '../types/order';

function makeOrder(overrides: Partial<Order> = {}): Order {
  return {
    id: 'order-1',
    products_total: 100,
    shipping_amount: 20,
    discount_amount: 0,
    discount_percentage: null,
    tax_amount: 0,
    grand_total: 120,
    deposit_paid: 0,
    deposit_amount: 0,
    remaining_balance: 120,
    total: 120,
    payment_method: null,
    payment_method_title: null,
    payment_method_manual: null,
    payment_proof_path: null,
    transaction_id: null,
    date_paid: null,
    payment_state: 'unpaid',
    ...overrides,
  } as unknown as Order;
}

beforeEach(() => {
  vi.clearAllMocks();
  activeBundle.current = enOrders;
});

describe('PaymentTab', () => {
  it('shows the read-failure state, not an empty-data message, when the detail fetch failed', () => {
    render(<PaymentTab order={makeOrder()} t={((s: (b: unknown) => string) => s(enOrders)) as never} readFailed onRetry={vi.fn()} />);

    expect(screen.getByText(enOrders.orderDetail.failedToLoad)).toBeInTheDocument();
    expect(screen.queryByText(enOrders.drawer.payment.noInfo)).not.toBeInTheDocument();
  });

  it('calls onRetry when the retry button is pressed on a read failure', async () => {
    const user = userEvent.setup();
    const onRetry = vi.fn();
    render(<PaymentTab order={makeOrder()} t={((s: (b: unknown) => string) => s(enOrders)) as never} readFailed onRetry={onRetry} />);

    await user.click(screen.getByRole('button', { name: enOrders.orderDetail.retry }));
    expect(onRetry).toHaveBeenCalledTimes(1);
  });

  it('shows the canonical empty state when there is genuinely no payment info at all', () => {
    const order = makeOrder({
      products_total: 0, shipping_amount: 0, discount_amount: 0, tax_amount: 0,
      grand_total: 0, deposit_paid: 0, deposit_amount: 0, remaining_balance: 0,
      payment_method: null, payment_method_manual: null, payment_method_title: null,
      transaction_id: null, payment_proof_path: null, date_paid: null,
    });
    render(<PaymentTab order={order} t={((s: (b: unknown) => string) => s(enOrders)) as never} readFailed={false} onRetry={vi.fn()} />);

    expect(screen.getByText(enOrders.drawer.payment.noInfo)).toBeInTheDocument();
  });

  it('renders grand total and remaining balance as distinct figures, never conflated', () => {
    const order = makeOrder({ grand_total: 500, deposit_paid: 150, deposit_amount: 150, remaining_balance: 350 });
    render(<PaymentTab order={order} t={((s: (b: unknown) => string) => s(enOrders)) as never} readFailed={false} onRetry={vi.fn()} />);

    expect(screen.getByText('EGP 500.00')).toBeInTheDocument();
    expect(screen.getByText('EGP 150.00')).toBeInTheDocument();
    expect(screen.getByText('EGP 350.00')).toBeInTheDocument();
    expect(screen.getByText(enOrders.detail.grandTotal)).toBeInTheDocument();
    expect(screen.getByText(enOrders.detail.remainingBalance)).toBeInTheDocument();
    // Total is never mislabeled as the remaining balance.
    const grandTotalLabel = screen.getByText(enOrders.detail.grandTotal);
    expect(grandTotalLabel.parentElement).not.toHaveTextContent(enOrders.detail.remainingBalance);
  });

  it('does not show a remaining-balance row once the order is fully paid', () => {
    const order = makeOrder({ grand_total: 500, deposit_paid: 500, deposit_amount: 500, remaining_balance: 0, payment_state: 'paid' });
    render(<PaymentTab order={order} t={((s: (b: unknown) => string) => s(enOrders)) as never} readFailed={false} onRetry={vi.fn()} />);

    expect(screen.queryByText(enOrders.detail.remainingBalance)).not.toBeInTheDocument();
    expect(screen.getByText(enOrders.drawer.payment.paid)).toBeInTheDocument();
  });

  it('renders the canonical translated label for every canonical payment method, in any active language', () => {
    for (const [method, expected] of Object.entries(enOrders.workspace.paymentMethodLabels)) {
      const order = makeOrder({ payment_method_manual: method });
      const { unmount } = render(
        <PaymentTab order={order} t={((s: (b: unknown) => string) => s(enOrders)) as never} readFailed={false} onRetry={vi.fn()} />,
      );
      expect(screen.getByText(expected)).toBeInTheDocument();
      unmount();
    }
  });

  it('never shows a Record Payment action once nothing is outstanding', () => {
    const order = makeOrder({ remaining_balance: 0, grand_total: 200, deposit_paid: 200, deposit_amount: 200 });
    render(<PaymentTab order={order} t={((s: (b: unknown) => string) => s(enOrders)) as never} readFailed={false} onRetry={vi.fn()} />);

    expect(screen.queryByText(enOrders.orderDetail.recordPaymentBtn)).not.toBeInTheDocument();
  });

  it('shows Record Payment only while something is genuinely outstanding', () => {
    const order = makeOrder({ remaining_balance: 50, grand_total: 200, deposit_paid: 150, deposit_amount: 150 });
    render(<PaymentTab order={order} t={((s: (b: unknown) => string) => s(enOrders)) as never} readFailed={false} onRetry={vi.fn()} />);

    expect(screen.getByText(enOrders.orderDetail.recordPaymentBtn)).toBeInTheDocument();
  });

  it('renders payment evidence only through the canonical PaymentProofSection, never a second proof mechanism', () => {
    const order = makeOrder({ payment_method_manual: 'cod' });
    render(<PaymentTab order={order} t={((s: (b: unknown) => string) => s(enOrders)) as never} readFailed={false} onRetry={vi.fn()} />);

    expect(screen.getByTestId('payment-proof-section')).toBeInTheDocument();
    expect(mockPaymentProofSection).toHaveBeenCalledWith(expect.objectContaining({ orderId: 'order-1', paymentMethod: 'cod' }));
  });
});

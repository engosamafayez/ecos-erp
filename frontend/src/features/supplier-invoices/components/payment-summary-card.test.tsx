import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

// Selector-mode i18n → resolve t($ => $.a.b.c) to the dotted path string.
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
    i18n: { language: 'en' },
  }),
}));
vi.mock('@/hooks/use-formatter', () => ({ useFormatter: () => ({ money: (n: number) => `EGP ${n}`, currency: 'EGP' }) }));

import { PaymentSummaryCard } from './payment-summary-card';
import type { SupplierInvoicePayment } from '@/features/supplier-invoices/types/supplier-invoice';

const BASE: SupplierInvoicePayment = {
  total: 380,
  paid: 150,
  remaining: 230,
  payment_status: 'partially_paid',
  billed: true,
  bill_number: 'SI-1',
  due_date: '2026-09-01',
  history: [],
};

describe('PaymentSummaryCard', () => {
  it('shows Total, Paid, Remaining, Due and Status derived from the read-model', () => {
    render(<PaymentSummaryCard payment={BASE} />);
    expect(screen.getByText('detail.payment.total')).toBeInTheDocument();
    expect(screen.getByText('EGP 380')).toBeInTheDocument();
    expect(screen.getByText('EGP 150')).toBeInTheDocument();
    expect(screen.getByText('EGP 230')).toBeInTheDocument();
    expect(screen.getByText('detail.payment.due')).toBeInTheDocument();
    expect(screen.getByText('2026-09-01')).toBeInTheDocument();
    expect(screen.getByText('detail.payment.statuses.partially_paid')).toBeInTheDocument();
  });

  it('renders the canonical payment history (number + amount) when payments are allocated', () => {
    render(
      <PaymentSummaryCard
        payment={{
          ...BASE,
          history: [
            { payment_number: 'PAY-HIST-1', payment_date: '2026-08-15', amount: 150, payment_status: 'posted' },
          ],
        }}
      />,
    );
    expect(screen.getByTestId('payment-history')).toBeInTheDocument();
    expect(screen.getByText('PAY-HIST-1')).toBeInTheDocument();
    // Amount is rendered through the formatter; the "no history" note is absent.
    expect(screen.queryByText('detail.payment.noHistory')).not.toBeInTheDocument();
  });

  it('shows the empty-state note when nothing has been paid through Accounts Payable', () => {
    render(<PaymentSummaryCard payment={BASE} />);
    expect(screen.getByText('detail.payment.noHistory')).toBeInTheDocument();
    expect(screen.queryByTestId('payment-history')).not.toBeInTheDocument();
    // The AP boundary note is always present — recording a payment is a Finance/AP action.
    expect(screen.getByText('detail.payment.apNote')).toBeInTheDocument();
  });
});

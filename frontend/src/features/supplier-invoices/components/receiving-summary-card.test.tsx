import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

// Selector-mode i18n → resolve t($ => $.a.b.c) to the dotted path string (matches
// payment-summary-card.test.tsx, the sibling component this test mirrors).
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

import { ReceivingSummaryCard } from './receiving-summary-card';
import type { SupplierInvoiceReceiving } from '@/features/supplier-invoices/types/supplier-invoice';

const BASE: SupplierInvoiceReceiving = {
  status: 'partially_received',
  receipt_id: 'receipt-1',
  receipt_number: 'GR-2026-0007',
  receipt_status: 'draft',
  ready_to_post: false,
  lines: [
    {
      line_id: 'l1', product_id: 'p1', product_name: 'Widget', sku: 'W-1',
      expected_qty: 10, accepted_qty: 8, variance: -2, unit_price: 5, final_landed_unit_cost: null,
    },
  ],
};

describe('ReceivingSummaryCard', () => {
  it('shows the linked receipt number, status and per-line invoiced vs accepted qty + variance', () => {
    render(<ReceivingSummaryCard receiving={BASE} onOpenReceipt={vi.fn()} />);

    expect(screen.getByText('GR-2026-0007')).toBeInTheDocument();
    expect(screen.getByText('detail.receiving.statuses.partially_received')).toBeInTheDocument();
    expect(screen.getByText('Widget')).toBeInTheDocument();
    // Label and value render as siblings (see receiving-summary-card.tsx); RTL's getByText only
    // matches an element's own direct text-node children, so each bare value is its own match.
    expect(screen.getByText('10')).toBeInTheDocument();
    expect(screen.getByText('8')).toBeInTheDocument();
    expect(screen.getByText('-2')).toBeInTheDocument();
  });

  it('calls onOpenReceipt with the receipt id when "Open Receipt" is clicked', async () => {
    const user = userEvent.setup();
    const onOpenReceipt = vi.fn();
    render(<ReceivingSummaryCard receiving={BASE} onOpenReceipt={onOpenReceipt} />);

    await user.click(screen.getByText('detail.receiving.openReceipt'));
    expect(onOpenReceipt).toHaveBeenCalledWith('receipt-1');
  });

  it('shows the not-ready hint only while the backend has not cleared the receipt for posting', () => {
    const { rerender } = render(<ReceivingSummaryCard receiving={BASE} onOpenReceipt={vi.fn()} />);
    expect(screen.getByText('detail.receiving.notReadyHint')).toBeInTheDocument();

    rerender(<ReceivingSummaryCard receiving={{ ...BASE, status: 'reconciled', ready_to_post: true }} onOpenReceipt={vi.fn()} />);
    expect(screen.queryByText('detail.receiving.notReadyHint')).not.toBeInTheDocument();
  });

  it('shows the landed cost only once the receipt has posted and stamped a final unit cost', () => {
    render(
      <ReceivingSummaryCard
        receiving={{ ...BASE, lines: [{ ...BASE.lines[0], final_landed_unit_cost: 5.75 }] }}
        onOpenReceipt={vi.fn()}
      />,
    );
    expect(screen.getByText('detail.receiving.landedCost')).toBeInTheDocument();
  });
});

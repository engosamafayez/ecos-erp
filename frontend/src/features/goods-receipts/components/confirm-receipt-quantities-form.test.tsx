import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi, beforeEach } from 'vitest';

// Selector-mode i18n → resolve t($ => $.a.b.c) to the dotted path string (matches
// payment-summary-card.test.tsx's established idiom for a plain presentational component).
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

// eslint-disable-next-line @typescript-eslint/no-unused-vars -- signature must accept a permission arg; cannot()/canAccess() forward it
const mockCan = vi.hoisted(() => vi.fn((_permission?: string) => true));
vi.mock('@/features/authorization/use-authorization', () => ({
  usePermission: () => ({ can: mockCan, cannot: (p: string) => !mockCan(p), canAccess: mockCan, canExecute: mockCan }),
}));

const mockMutateAsync = vi.hoisted(() => vi.fn());
const mockIsPending = vi.hoisted(() => ({ value: false }));
vi.mock('@/features/goods-receipts/hooks/use-goods-receipts', () => ({
  useConfirmReceiptQuantities: () => ({ mutateAsync: mockMutateAsync, isPending: mockIsPending.value }),
}));

const mockToastSuccess = vi.hoisted(() => vi.fn());
const mockToastError = vi.hoisted(() => vi.fn());
vi.mock('@/components/ds/use-toast', () => ({ toast: { success: mockToastSuccess, error: mockToastError } }));
vi.mock('@/lib/api-error', () => ({ extractApiErrorMessage: (e: unknown) => String(e) }));

import { ConfirmReceiptQuantitiesForm } from './confirm-receipt-quantities-form';
import type { GoodsReceipt, GoodsReceiptLine } from '@/features/goods-receipts/types/goods-receipt';

function line(overrides: Partial<GoodsReceiptLine> = {}): GoodsReceiptLine {
  return {
    id: 'line-1',
    purchase_order_line_id: null,
    purchase_material_line_id: null,
    supplier_invoice_line_id: 'inv-line-1',
    product_id: 'p1',
    product: { id: 'p1', sku: 'W-1', name: 'Widget' },
    uom_id_snapshot: null,
    uom_name_snapshot: null,
    uom_symbol_snapshot: null,
    ordered_quantity: 10,
    gross_received_quantity: 0,
    net_received_quantity: 0,
    variance_quantity: 0,
    remaining_quantity: 10,
    unit_price: 5,
    landed_unit_cost: null,
    weight_photo_path: null,
    weight_photo_url: null,
    notes: null,
    ...overrides,
  };
}

function receipt(overrides: Partial<GoodsReceipt> = {}): GoodsReceipt {
  return {
    id: 'gr-1',
    receipt_number: 'GR-2026-0007',
    purchase_order_id: null,
    purchase_order: null,
    is_invoice_originated: true,
    supplier_invoice: { id: 'inv-1', invoice_number: 'INV-2026-001' },
    warehouse_id: 'wh-1',
    warehouse: { id: 'wh-1', code: 'WH1', name: 'Main WH' },
    receipt_date: '2026-09-08',
    status: 'draft',
    notes: null,
    supplier_invoice_number: null,
    supplier_invoice_date: null,
    invoice_attachment_path: null,
    invoice_attachment_url: null,
    invoice_total_amount: 0,
    paid_amount: 0,
    outstanding_amount: 0,
    freight_amount: 0,
    tax_amount: 0,
    additional_costs: 0,
    total_landed_costs: 0,
    payment_status: 'unpaid',
    payment_status_label: 'Unpaid',
    payment_method: null,
    payment_method_label: null,
    payment_terms_days: null,
    payment_due_date: null,
    posted_by: null,
    posted_at: null,
    lines: [line()],
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

beforeEach(() => {
  vi.clearAllMocks();
  mockCan.mockReturnValue(true);
  mockIsPending.value = false;
});

describe('ConfirmReceiptQuantitiesForm', () => {
  it('shows the invoiced (expected) qty read-only, and updates variance as the accepted qty is typed', async () => {
    const user = userEvent.setup();
    render(<ConfirmReceiptQuantitiesForm receipt={receipt()} />);

    expect(screen.getByText('Widget')).toBeInTheDocument();
    expect(screen.getByText('10')).toBeInTheDocument(); // expected qty, read-only

    const input = screen.getByPlaceholderText('0');
    await user.type(input, '8');
    expect(screen.getByText('-2')).toBeInTheDocument(); // 8 - 10
  });

  it('submits the accepted quantities against the confirm-quantities endpoint, not the original invoiced qty', async () => {
    const user = userEvent.setup();
    mockMutateAsync.mockResolvedValue(receipt());
    render(<ConfirmReceiptQuantitiesForm receipt={receipt()} />);

    await user.type(screen.getByPlaceholderText('0'), '8');
    await user.click(screen.getByRole('button', { name: /confirmQuantities.submit/i }));

    // Exact payload shape — only { line_id, accepted_qty } per line, never the original qty.
    expect(mockMutateAsync).toHaveBeenCalledWith({
      id: 'gr-1',
      lines: [{ line_id: 'line-1', accepted_qty: 8 }],
    });
  });

  it('blocks submission and flags the row when accepted qty exceeds the invoiced qty', async () => {
    const user = userEvent.setup();
    render(<ConfirmReceiptQuantitiesForm receipt={receipt()} />);

    await user.type(screen.getByPlaceholderText('0'), '15');
    expect(screen.getByText('confirmQuantities.exceedsExpected')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /confirmQuantities.submit/i })).toBeDisabled();
  });

  it('disables input and submit, and explains why, when the user lacks the receiving permission', () => {
    mockCan.mockReturnValue(false);
    render(<ConfirmReceiptQuantitiesForm receipt={receipt()} />);

    expect(screen.getByPlaceholderText('0')).toBeDisabled();
    expect(screen.getByRole('button', { name: /confirmQuantities.submit/i })).toBeDisabled();
    expect(screen.getByText('confirmQuantities.noPermission')).toBeInTheDocument();
  });

  it('shows the empty state when the receipt has no lines to receive', () => {
    render(<ConfirmReceiptQuantitiesForm receipt={receipt({ lines: [] })} />);
    expect(screen.getByText('confirmQuantities.empty')).toBeInTheDocument();
  });
});

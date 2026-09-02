import '@testing-library/jest-dom/vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi, beforeEach } from 'vitest';

// Selector-mode i18n → resolve t($ => $.a.b.c) to the dotted path string
// (mirrors receiving-center-page.test.tsx / driver-settlement-detail-page.test.tsx).
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
    i18n: { language: 'en', exists: () => true },
  }),
}));

vi.mock('@/hooks/use-formatter', () => ({
  useFormatter: () => ({
    money: (v: number | null | undefined) => (v == null ? '—' : `EGP ${v}`),
    date: (v: string | null | undefined) => v ?? '—',
    dateTime: (v: string | null | undefined) => v ?? '—',
  }),
}));

const { mockCan } = vi.hoisted(() => ({ mockCan: vi.fn().mockReturnValue(true) }));
vi.mock('@/features/authorization', () => ({ usePermission: () => ({ can: mockCan }) }));

const { mockUseArReceipt, mockAllocateMutate, mockAutoAllocateMutate, mockReverseMutate } = vi.hoisted(() => ({
  mockUseArReceipt: vi.fn(),
  mockAllocateMutate: vi.fn(),
  mockAutoAllocateMutate: vi.fn(),
  mockReverseMutate: vi.fn(),
}));

vi.mock('../hooks/use-finance-ar', () => ({
  useArReceipt: mockUseArReceipt,
  useAllocateReceipt: () => ({ mutate: mockAllocateMutate, isPending: false, isError: false, error: null }),
  useAutoAllocateReceipt: () => ({ mutate: mockAutoAllocateMutate, isPending: false, isError: false, error: null }),
  useReverseReceiptPosting: () => ({ mutate: mockReverseMutate, isPending: false, isError: false, error: null }),
}));

import { ReceiptDetailDrawer } from './receipt-detail-drawer';
import type { ArReceipt } from '../types/finance-ar';

const RECEIPT: ArReceipt = {
  id: 'rec-1',
  customer_id: 'cus-1',
  number: 'RCT-001',
  receipt_date: '2026-08-01',
  amount: 800,
  currency: 'EGP',
  status: 'posted',
  unallocated: 200,
  journal_entry_id: 77,
  posted_at: '2026-08-01T11:00:00Z',
  source_type: null,
  source_id: null,
};

function withReceipt(receipt: ArReceipt | undefined, over: Partial<{ isLoading: boolean; isError: boolean }> = {}) {
  mockUseArReceipt.mockReturnValue({
    data: receipt,
    isLoading: over.isLoading ?? false,
    isError: over.isError ?? false,
  });
}

describe('ReceiptDetailDrawer', () => {
  beforeEach(() => {
    // mockReset (not mockClear) so a mockImplementation set by one test — e.g.
    // to simulate a mutation's onSuccess firing — can never leak into the next.
    mockCan.mockReset().mockReturnValue(true);
    mockAllocateMutate.mockReset();
    mockAutoAllocateMutate.mockReset();
    mockReverseMutate.mockReset();
  });

  it('renders the receipt number, amount and unallocated balance when found in the cached list', () => {
    withReceipt(RECEIPT);
    render(<ReceiptDetailDrawer receiptId="rec-1" open onOpenChange={vi.fn()} />);

    expect(screen.getByText('RCT-001')).toBeInTheDocument();
    expect(screen.getByText('EGP 800')).toBeInTheDocument();
    expect(screen.getByText('EGP 200')).toBeInTheDocument();
  });

  it('shows the source_type / source_id row for a COD-collection receipt (Task 6), and omits it otherwise', () => {
    withReceipt({ ...RECEIPT, source_type: 'cod_record', source_id: 'cod-42' });
    const { rerender } = render(<ReceiptDetailDrawer receiptId="rec-1" open onOpenChange={vi.fn()} />);
    expect(screen.getByText('cod_record · cod-42')).toBeInTheDocument();

    withReceipt(RECEIPT); // source_type/source_id both null
    rerender(<ReceiptDetailDrawer receiptId="rec-1" open onOpenChange={vi.fn()} />);
    expect(screen.queryByText('ar.detail.source')).not.toBeInTheDocument();
  });

  it('shows a not-found message when the id is absent from the receipts list (no single-receipt GET exists)', () => {
    withReceipt(undefined);
    render(<ReceiptDetailDrawer receiptId="rec-x" open onOpenChange={vi.fn()} />);

    expect(screen.getByText('ar.detail.notFound')).toBeInTheDocument();
  });

  it('hides Reverse Posting and the Allocate panel without finance.journal.post / finance.allocation.manage', () => {
    mockCan.mockReturnValue(false);
    withReceipt(RECEIPT);
    render(<ReceiptDetailDrawer receiptId="rec-1" open onOpenChange={vi.fn()} />);

    expect(screen.queryByText('gl.actions.reverse')).not.toBeInTheDocument();
    expect(screen.queryByText('ar.detail.allocationTitle')).not.toBeInTheDocument();
  });

  it('shows Reverse Posting and the Allocate panel when posted and permitted', () => {
    withReceipt(RECEIPT);
    render(<ReceiptDetailDrawer receiptId="rec-1" open onOpenChange={vi.fn()} />);

    expect(screen.getByText('gl.actions.reverse')).toBeInTheDocument();
    expect(screen.getByText('ar.detail.allocationTitle')).toBeInTheDocument();
  });

  it('hides Reverse Posting and the Allocate panel when the receipt is not posted, even with permission', () => {
    withReceipt({ ...RECEIPT, status: 'draft', unallocated: null });
    render(<ReceiptDetailDrawer receiptId="rec-1" open onOpenChange={vi.fn()} />);

    expect(screen.queryByText('gl.actions.reverse')).not.toBeInTheDocument();
    expect(screen.queryByText('ar.detail.allocationTitle')).not.toBeInTheDocument();
    expect(screen.queryByText('ar.receipt.unallocated')).not.toBeInTheDocument();
  });

  it('toggles the reversing reason box, gates Confirm on a non-empty reason, and reverses with the trimmed reason', () => {
    withReceipt(RECEIPT);
    render(<ReceiptDetailDrawer receiptId="rec-1" open onOpenChange={vi.fn()} />);

    fireEvent.click(screen.getByText('gl.actions.reverse'));
    const confirmBtn = screen.getByText('gl.actions.confirmReverse');
    expect(confirmBtn).toBeDisabled();

    fireEvent.change(screen.getByPlaceholderText('ar.detail.reverseReason'), { target: { value: '  Duplicate receipt  ' } });
    expect(confirmBtn).not.toBeDisabled();

    fireEvent.click(confirmBtn);
    expect(mockReverseMutate).toHaveBeenCalledWith(
      { uuid: 'rec-1', reason: 'Duplicate receipt' },
      expect.objectContaining({ onSuccess: expect.any(Function) }),
    );
  });

  it('Close resets the reversing toggle and calls onOpenChange(false)', () => {
    withReceipt(RECEIPT);
    const onOpenChange = vi.fn();
    render(<ReceiptDetailDrawer receiptId="rec-1" open onOpenChange={onOpenChange} />);

    fireEvent.click(screen.getByText('gl.actions.reverse'));
    fireEvent.click(screen.getByText('gl.actions.close'));

    expect(onOpenChange).toHaveBeenCalledWith(false);
  });

  it('disables Allocate until an invoice id and a positive amount are both entered, then allocates', () => {
    withReceipt(RECEIPT);
    render(<ReceiptDetailDrawer receiptId="rec-1" open onOpenChange={vi.fn()} />);

    const allocateBtn = screen.getByText('ar.action.allocate');
    expect(allocateBtn).toBeDisabled();

    fireEvent.change(screen.getByLabelText('ar.detail.invoiceId'), { target: { value: 'inv-9' } });
    expect(allocateBtn).toBeDisabled();

    fireEvent.change(screen.getByLabelText('ar.detail.allocateAmount'), { target: { value: '120' } });
    expect(allocateBtn).not.toBeDisabled();

    fireEvent.click(allocateBtn);
    expect(mockAllocateMutate).toHaveBeenCalledWith(
      { uuid: 'rec-1', invoiceId: 'inv-9', amount: 120 },
      expect.objectContaining({ onSuccess: expect.any(Function) }),
    );
  });

  it('clears the allocate mini-form after a successful allocation', () => {
    withReceipt(RECEIPT);
    mockAllocateMutate.mockImplementation((_vars, opts) => opts?.onSuccess?.());
    render(<ReceiptDetailDrawer receiptId="rec-1" open onOpenChange={vi.fn()} />);

    fireEvent.change(screen.getByLabelText('ar.detail.invoiceId'), { target: { value: 'inv-9' } });
    fireEvent.change(screen.getByLabelText('ar.detail.allocateAmount'), { target: { value: '120' } });
    fireEvent.click(screen.getByText('ar.action.allocate'));

    expect((screen.getByLabelText('ar.detail.invoiceId') as HTMLInputElement).value).toBe('');
    expect((screen.getByLabelText('ar.detail.allocateAmount') as HTMLInputElement).value).toBe('');
  });

  it('calls auto-allocate with the receipt id and shows the result note on success', () => {
    withReceipt(RECEIPT);
    mockAutoAllocateMutate.mockImplementation((_id, opts) => opts?.onSuccess?.({ allocations: 1, receipt_unallocated: 0 }));
    render(<ReceiptDetailDrawer receiptId="rec-1" open onOpenChange={vi.fn()} />);

    fireEvent.click(screen.getByText('ar.action.autoAllocate'));

    expect(mockAutoAllocateMutate).toHaveBeenCalledWith('rec-1', expect.objectContaining({ onSuccess: expect.any(Function) }));
    expect(screen.getByText('ar.detail.autoAllocateResult')).toBeInTheDocument();
  });
});

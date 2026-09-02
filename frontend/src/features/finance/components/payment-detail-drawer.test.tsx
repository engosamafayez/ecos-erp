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

const { mockUseApPayment, mockAllocateMutate, mockAutoAllocateMutate, mockReverseMutate } = vi.hoisted(() => ({
  mockUseApPayment: vi.fn(),
  mockAllocateMutate: vi.fn(),
  mockAutoAllocateMutate: vi.fn(),
  mockReverseMutate: vi.fn(),
}));

vi.mock('../hooks/use-finance-ap', () => ({
  useApPayment: mockUseApPayment,
  useAllocatePayment: () => ({ mutate: mockAllocateMutate, isPending: false, isError: false, error: null }),
  useAutoAllocatePayment: () => ({ mutate: mockAutoAllocateMutate, isPending: false, isError: false, error: null }),
  useReversePaymentPosting: () => ({ mutate: mockReverseMutate, isPending: false, isError: false, error: null }),
}));

import { PaymentDetailDrawer } from './payment-detail-drawer';
import type { ApPayment } from '../types/finance-ap';

const PAYMENT: ApPayment = {
  id: 'pay-1',
  supplier_id: 'sup-1',
  number: 'PMT-001',
  payment_date: '2026-08-01',
  amount: 1000,
  currency: 'EGP',
  status: 'posted',
  unallocated: 400,
  journal_entry_id: 55,
  approved_by: 3,
  approved_at: '2026-08-01T10:00:00Z',
  posted_at: '2026-08-01T11:00:00Z',
};

function withPayment(payment: ApPayment | undefined, over: Partial<{ isLoading: boolean; isError: boolean }> = {}) {
  mockUseApPayment.mockReturnValue({
    data: payment,
    isLoading: over.isLoading ?? false,
    isError: over.isError ?? false,
  });
}

describe('PaymentDetailDrawer', () => {
  beforeEach(() => {
    // mockReset (not mockClear) so a mockImplementation set by one test — e.g.
    // to simulate a mutation's onSuccess firing — can never leak into the next.
    mockCan.mockReset().mockReturnValue(true);
    mockAllocateMutate.mockReset();
    mockAutoAllocateMutate.mockReset();
    mockReverseMutate.mockReset();
  });

  it('renders the payment number, amount and unallocated balance when found in the cached list', () => {
    withPayment(PAYMENT);
    render(<PaymentDetailDrawer paymentId="pay-1" open onOpenChange={vi.fn()} />);

    expect(screen.getByText('PMT-001')).toBeInTheDocument();
    expect(screen.getByText('EGP 1000')).toBeInTheDocument();
    expect(screen.getByText('EGP 400')).toBeInTheDocument();
  });

  it('shows a not-found message when the id is absent from the payments list (no single-payment GET exists)', () => {
    withPayment(undefined);
    render(<PaymentDetailDrawer paymentId="pay-x" open onOpenChange={vi.fn()} />);

    expect(screen.getByText('ap.detail.notFound')).toBeInTheDocument();
  });

  it('shows the loading and error states from the underlying payments-list query', () => {
    withPayment(undefined, { isLoading: true });
    const { rerender } = render(<PaymentDetailDrawer paymentId="pay-1" open onOpenChange={vi.fn()} />);
    expect(screen.getByText('loading')).toBeInTheDocument();

    withPayment(undefined, { isError: true });
    rerender(<PaymentDetailDrawer paymentId="pay-1" open onOpenChange={vi.fn()} />);
    expect(screen.getByText('error')).toBeInTheDocument();
  });

  it('hides Reverse Posting and the Allocate panel without finance.journal.post / finance.allocation.manage', () => {
    mockCan.mockReturnValue(false);
    withPayment(PAYMENT);
    render(<PaymentDetailDrawer paymentId="pay-1" open onOpenChange={vi.fn()} />);

    expect(screen.queryByText('gl.actions.reverse')).not.toBeInTheDocument();
    expect(screen.queryByText('ap.detail.allocationTitle')).not.toBeInTheDocument();
  });

  it('shows Reverse Posting and the Allocate panel when posted and permitted', () => {
    withPayment(PAYMENT);
    render(<PaymentDetailDrawer paymentId="pay-1" open onOpenChange={vi.fn()} />);

    expect(screen.getByText('gl.actions.reverse')).toBeInTheDocument();
    expect(screen.getByText('ap.detail.allocationTitle')).toBeInTheDocument();
  });

  it('hides Reverse Posting and the Allocate panel when the payment is not posted, even with permission', () => {
    withPayment({ ...PAYMENT, status: 'approved', unallocated: null });
    render(<PaymentDetailDrawer paymentId="pay-1" open onOpenChange={vi.fn()} />);

    expect(screen.queryByText('gl.actions.reverse')).not.toBeInTheDocument();
    expect(screen.queryByText('ap.detail.allocationTitle')).not.toBeInTheDocument();
    expect(screen.queryByText('ap.payment.unallocated')).not.toBeInTheDocument();
  });

  it('toggles the reversing reason box, gates Confirm on a non-empty reason, and reverses with the trimmed reason', () => {
    withPayment(PAYMENT);
    render(<PaymentDetailDrawer paymentId="pay-1" open onOpenChange={vi.fn()} />);

    fireEvent.click(screen.getByText('gl.actions.reverse'));
    const confirmBtn = screen.getByText('gl.actions.confirmReverse');
    expect(confirmBtn).toBeDisabled();

    fireEvent.change(screen.getByPlaceholderText('ap.detail.reverseReason'), { target: { value: '  Wrong bank  ' } });
    expect(confirmBtn).not.toBeDisabled();

    fireEvent.click(confirmBtn);
    expect(mockReverseMutate).toHaveBeenCalledWith(
      { uuid: 'pay-1', reason: 'Wrong bank' },
      expect.objectContaining({ onSuccess: expect.any(Function) }),
    );
  });

  it('Close resets the reversing toggle and calls onOpenChange(false)', () => {
    withPayment(PAYMENT);
    const onOpenChange = vi.fn();
    render(<PaymentDetailDrawer paymentId="pay-1" open onOpenChange={onOpenChange} />);

    fireEvent.click(screen.getByText('gl.actions.reverse'));
    fireEvent.click(screen.getByText('gl.actions.close'));

    expect(onOpenChange).toHaveBeenCalledWith(false);
  });

  it('disables Allocate until a bill id and a positive amount are both entered, then allocates', () => {
    withPayment(PAYMENT);
    render(<PaymentDetailDrawer paymentId="pay-1" open onOpenChange={vi.fn()} />);

    const allocateBtn = screen.getByText('ap.action.allocate');
    expect(allocateBtn).toBeDisabled();

    fireEvent.change(screen.getByLabelText('ap.detail.billId'), { target: { value: 'bill-9' } });
    expect(allocateBtn).toBeDisabled();

    fireEvent.change(screen.getByLabelText('ap.detail.allocateAmount'), { target: { value: '150' } });
    expect(allocateBtn).not.toBeDisabled();

    fireEvent.click(allocateBtn);
    expect(mockAllocateMutate).toHaveBeenCalledWith(
      { uuid: 'pay-1', billId: 'bill-9', amount: 150 },
      expect.objectContaining({ onSuccess: expect.any(Function) }),
    );
  });

  it('clears the allocate mini-form after a successful allocation', () => {
    withPayment(PAYMENT);
    mockAllocateMutate.mockImplementation((_vars, opts) => opts?.onSuccess?.());
    render(<PaymentDetailDrawer paymentId="pay-1" open onOpenChange={vi.fn()} />);

    fireEvent.change(screen.getByLabelText('ap.detail.billId'), { target: { value: 'bill-9' } });
    fireEvent.change(screen.getByLabelText('ap.detail.allocateAmount'), { target: { value: '150' } });
    fireEvent.click(screen.getByText('ap.action.allocate'));

    expect((screen.getByLabelText('ap.detail.billId') as HTMLInputElement).value).toBe('');
    expect((screen.getByLabelText('ap.detail.allocateAmount') as HTMLInputElement).value).toBe('');
  });

  it('calls auto-allocate with the payment id and shows the result note on success', () => {
    withPayment(PAYMENT);
    mockAutoAllocateMutate.mockImplementation((_id, opts) => opts?.onSuccess?.({ allocations: 2, payment_unallocated: 100 }));
    render(<PaymentDetailDrawer paymentId="pay-1" open onOpenChange={vi.fn()} />);

    fireEvent.click(screen.getByText('ap.action.autoAllocate'));

    expect(mockAutoAllocateMutate).toHaveBeenCalledWith('pay-1', expect.objectContaining({ onSuccess: expect.any(Function) }));
    expect(screen.getByText('ap.detail.autoAllocateResult')).toBeInTheDocument();
  });
});

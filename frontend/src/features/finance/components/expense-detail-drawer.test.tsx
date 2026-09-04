/**
 * Expense detail drawer (TASK-ECOS-FINANCE-UX-REPORTING-CLOSURE-008). Pins
 * TASK §16 segregation of duties: approve/post/reverse are each hidden
 * (never merely disabled) without finance.expense.approve, regardless of who
 * created the expense — mirrors JournalDetailDrawer's own approve/reverse
 * test intent, adapted to Expense's draft→approved→posted(+reverse)
 * lifecycle (no discard step exists for Expense).
 */
import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi, beforeEach } from 'vitest';

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
    money: (n: number | null | undefined) => (n == null ? '—' : `EGP ${n}`),
    date: (d: string | null | undefined) => d ?? '—',
    dateTime: (d: string | null | undefined) => d ?? '—',
  }),
}));

// eslint-disable-next-line @typescript-eslint/no-unused-vars -- signature must accept perm; reassigned per-test below
const { canRef } = vi.hoisted(() => ({ canRef: { current: (_perm: string): boolean => true } }));
vi.mock('@/features/authorization', () => ({
  usePermission: () => ({ can: (perm: string) => canRef.current(perm) }),
}));

const approveMutate = vi.fn();
const postMutate = vi.fn();
const reverseMutate = vi.fn();

import { useApproveExpense, useExpense, usePostExpense, useReverseExpensePosting } from '../hooks/use-finance-expense';
vi.mock('../hooks/use-finance-expense', () => ({
  useExpense: vi.fn(),
  useApproveExpense: vi.fn(),
  usePostExpense: vi.fn(),
  useReverseExpensePosting: vi.fn(),
}));

import { ExpenseDetailDrawer } from './expense-detail-drawer';
import type { Expense } from '../types/finance-expense';

const mockExpense = useExpense as unknown as ReturnType<typeof vi.fn>;
const mockApprove = useApproveExpense as unknown as ReturnType<typeof vi.fn>;
const mockPost = usePostExpense as unknown as ReturnType<typeof vi.fn>;
const mockReverse = useReverseExpensePosting as unknown as ReturnType<typeof vi.fn>;

function expense(over: Partial<Expense> = {}): Expense {
  return {
    id: 'exp-1',
    expense_category_id: 'cat-1',
    number: 'EXP-1001',
    expense_date: '2026-08-01',
    amount: 250,
    currency: 'EGP',
    status: 'draft',
    source_type: null,
    source_id: null,
    journal_entry_id: null,
    approved_by: null,
    approved_at: null,
    posted_at: null,
    ...over,
  };
}

beforeEach(() => {
  vi.clearAllMocks();
  canRef.current = () => true;
  mockApprove.mockReturnValue({ mutate: approveMutate, isPending: false });
  mockPost.mockReturnValue({ mutate: postMutate, isPending: false });
  mockReverse.mockReturnValue({ mutate: reverseMutate, isPending: false });
});

function renderDrawer(status: Expense['status']) {
  mockExpense.mockReturnValue({ data: expense({ status }), isLoading: false, isError: false });
  return render(<ExpenseDetailDrawer expenseId="exp-1" open onOpenChange={() => {}} />);
}

describe('ExpenseDetailDrawer', () => {
  it('shows Approve for a draft expense when the viewer can approve', () => {
    renderDrawer('draft');
    expect(screen.getByRole('button', { name: 'gl.actions.approve' })).toBeInTheDocument();
  });

  it('hides Approve for a draft expense without finance.expense.approve — regardless of who created it', () => {
    canRef.current = () => false;
    renderDrawer('draft');
    expect(screen.queryByRole('button', { name: 'gl.actions.approve' })).not.toBeInTheDocument();
  });

  it('shows Post for an approved expense, not Approve', () => {
    renderDrawer('approved');
    expect(screen.queryByRole('button', { name: 'gl.actions.approve' })).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'expense.detail.post' })).toBeInTheDocument();
  });

  it('hides Post without finance.expense.approve', () => {
    canRef.current = () => false;
    renderDrawer('approved');
    expect(screen.queryByRole('button', { name: 'expense.detail.post' })).not.toBeInTheDocument();
  });

  it('shows Reverse only for a posted expense, and requires a reason before confirming', async () => {
    const user = userEvent.setup();
    renderDrawer('posted');

    expect(screen.queryByRole('button', { name: 'expense.detail.post' })).not.toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'gl.actions.reverse' }));

    const confirm = screen.getByRole('button', { name: 'gl.actions.confirmReverse' });
    expect(confirm).toBeDisabled();

    await user.type(screen.getByPlaceholderText('expense.detail.reverseReason'), 'Entered in error');
    expect(confirm).not.toBeDisabled();

    await user.click(confirm);
    expect(reverseMutate).toHaveBeenCalledWith(
      { uuid: 'exp-1', reason: 'Entered in error' },
      expect.objectContaining({ onSuccess: expect.any(Function) }),
    );
  });

  it('hides Reverse for a posted expense without finance.expense.approve', () => {
    canRef.current = () => false;
    renderDrawer('posted');
    expect(screen.queryByRole('button', { name: 'gl.actions.reverse' })).not.toBeInTheDocument();
  });

  it('offers no lifecycle action for a void expense', () => {
    renderDrawer('void');
    expect(screen.queryByRole('button', { name: 'gl.actions.approve' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'expense.detail.post' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'gl.actions.reverse' })).not.toBeInTheDocument();
  });
});

/**
 * Expenses page (TASK-ECOS-FINANCE-UX-REPORTING-CLOSURE-008). Mirrors the
 * mocking convention used across this feature's other new tests (selector-
 * mode i18n resolved to the dotted path string, UniversalDataGrid stubbed to
 * a plain table, the feature's own hooks module mocked directly).
 */
import '@testing-library/jest-dom/vitest';
import type { ReactNode } from 'react';
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

vi.mock('@/components/data-grid/universal-data-grid', () => ({
  UniversalDataGrid: ({
    data,
    columns,
    rowId,
    onRowClick,
    emptyState,
  }: {
    data: unknown[];
    columns: { key: string; cell: (r: unknown) => ReactNode }[];
    rowId: (r: unknown) => string;
    onRowClick?: (r: unknown) => void;
    emptyState: ReactNode;
  }) =>
    data.length === 0 ? (
      <>{emptyState}</>
    ) : (
      <table>
        <tbody>
          {data.map((row) => (
            <tr key={rowId(row)} data-testid="expense-row" onClick={() => onRowClick?.(row)}>
              {columns.map((c) => <td key={c.key}>{c.cell(row)}</td>)}
            </tr>
          ))}
        </tbody>
      </table>
    ),
}));

vi.mock('@/hooks/use-formatter', () => ({
  useFormatter: () => ({
    money: (n: number | null | undefined) => (n == null ? '—' : `EGP ${n}`),
    date: (d: string | null | undefined) => d ?? '—',
    dateTime: (d: string | null | undefined) => d ?? '—',
  }),
}));

const { canRef } = vi.hoisted(() => ({ canRef: { current: (_perm: string) => true } }));
vi.mock('@/features/authorization', () => ({
  usePermission: () => ({ can: (perm: string) => canRef.current(perm) }),
}));

// The create drawer, category dialog, and detail drawer are covered by their own concerns;
// here we only need to see whether the page asked to open them.
vi.mock('../components/expense-form-drawer', () => ({
  ExpenseFormDrawer: ({ open }: { open: boolean }) => (open ? <div data-testid="create-drawer-open" /> : null),
}));
vi.mock('../components/expense-category-form-dialog', () => ({
  ExpenseCategoryFormDialog: ({ open }: { open: boolean }) => (open ? <div data-testid="category-dialog-open" /> : null),
}));
vi.mock('../components/expense-detail-drawer', () => ({
  ExpenseDetailDrawer: ({ open, expenseId }: { open: boolean; expenseId: string | null }) =>
    open ? <div data-testid="detail-drawer-open">{expenseId}</div> : null,
}));

import { useExpenses } from '../hooks/use-finance-expense';
vi.mock('../hooks/use-finance-expense', () => ({
  useExpenses: vi.fn(),
}));

import { ExpensesPage } from './expenses-page';
import type { Expense } from '../types/finance-expense';

const mockExpenses = useExpenses as unknown as ReturnType<typeof vi.fn>;

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
  mockExpenses.mockReturnValue({ data: [expense()], isLoading: false, isError: false });
});

describe('ExpensesPage', () => {
  it('renders an expense row with its amount and status', () => {
    render(<ExpensesPage />);
    expect(screen.getByText('EXP-1001')).toBeInTheDocument();
    expect(screen.getByText('EGP 250')).toBeInTheDocument();
  });

  it('shows the empty state when there are no expenses', () => {
    mockExpenses.mockReturnValue({ data: [], isLoading: false, isError: false });
    render(<ExpensesPage />);
    expect(screen.getByText('expense.empty')).toBeInTheDocument();
  });

  it('hides the whole page behind NoAccess without finance.expense.view', () => {
    canRef.current = () => false;
    render(<ExpensesPage />);
    expect(screen.queryByTestId('expense-row')).not.toBeInTheDocument();
    expect(screen.queryByText('expense.action.new')).not.toBeInTheDocument();
  });

  it('shows New Expense only when the viewer can create expenses', () => {
    canRef.current = (perm) => perm !== 'finance.expense.create';
    render(<ExpensesPage />);
    expect(screen.queryByRole('button', { name: /expense.action.new/ })).not.toBeInTheDocument();
  });

  it('shows New Category only when the viewer can manage expense categories', () => {
    canRef.current = (perm) => perm !== 'finance.expense.category.manage';
    render(<ExpensesPage />);
    expect(screen.queryByRole('button', { name: /expense.action.newCategory/ })).not.toBeInTheDocument();
  });

  it('opens the create drawer from New Expense', async () => {
    const user = userEvent.setup();
    render(<ExpensesPage />);
    await user.click(screen.getByRole('button', { name: /expense.action.new/ }));
    expect(screen.getByTestId('create-drawer-open')).toBeInTheDocument();
  });

  it('opens the category dialog from New Category', async () => {
    const user = userEvent.setup();
    render(<ExpensesPage />);
    await user.click(screen.getByRole('button', { name: /expense.action.newCategory/ }));
    expect(screen.getByTestId('category-dialog-open')).toBeInTheDocument();
  });

  it('opens the detail drawer for the clicked expense', async () => {
    const user = userEvent.setup();
    render(<ExpensesPage />);
    await user.click(screen.getByTestId('expense-row'));
    expect(screen.getByTestId('detail-drawer-open')).toHaveTextContent('exp-1');
  });
});

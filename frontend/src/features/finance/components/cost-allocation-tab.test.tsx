/**
 * Cost Allocation tab (Costing & Profitability). Pins the properties the brief calls out
 * explicitly:
 *   1. The destination is a raw Brand/Profit-Center reference id, shown verbatim — never a
 *      fabricated name lookup (Finance has no Brand directory).
 *   2. Reverse is hidden for a row that is itself a reversal, and for a row that has already
 *      been reversed by another row — an informational guard only, mirrored client-side.
 *   3. Reversal requires a non-empty reason before Confirm is enabled, exactly like
 *      JournalDetailDrawer's reversing-toggle+reason pattern.
 *
 * The i18n mock resolves selector-mode t($ => $.a.b.c) calls to the dotted path string rather
 * than against the real finance.json bundle, because costAllocation.* is delivered as a
 * fragment for a separate merge and does not exist in the real bundle yet (see
 * receiving-center-page.test.tsx for the same convention).
 */
import '@testing-library/jest-dom/vitest';
import type { ReactNode } from 'react';
import { render, screen, waitFor } from '@testing-library/react';
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
    emptyState,
  }: {
    data: unknown[];
    columns: { key: string; cell: (r: unknown) => ReactNode }[];
    rowId: (r: unknown) => string;
    emptyState: ReactNode;
  }) =>
    data.length === 0 ? (
      <>{emptyState}</>
    ) : (
      <table>
        <tbody>
          {data.map((row) => (
            <tr key={rowId(row)} data-testid="allocation-row">
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
    number: (n: number | null | undefined, decimals = 2) => (n == null ? '—' : n.toFixed(decimals)),
    percent: (n: number | null | undefined) => (n == null ? '—' : `${n}%`),
  }),
}));

// eslint-disable-next-line @typescript-eslint/no-unused-vars -- signature must accept perm; reassigned per-test below
const { canRef } = vi.hoisted(() => ({ canRef: { current: (_perm: string): boolean => true } }));
vi.mock('@/features/authorization', () => ({
  usePermission: () => ({ can: (perm: string) => canRef.current(perm) }),
}));

const toastSpy = vi.fn();
vi.mock('@/components/ds/use-toast', () => ({
  useToast: () => ({ toast: toastSpy }),
}));

// ActionMenu (a dropdown) is not under test here — render its items as plain buttons so
// "Reverse" is directly reachable without driving a Radix popover open.
vi.mock('@/components/crud', () => ({
  ActionMenu: ({ items }: { items: { key: string; label: string; onSelect: () => void }[] }) => (
    <>
      {items.map((item) => (
        <button key={item.key} type="button" onClick={item.onSelect}>{item.label}</button>
      ))}
    </>
  ),
}));

// The create drawer's own fields are covered by its own tests; here we only need to see
// whether it was asked to open.
vi.mock('./cost-allocation-form-drawer', () => ({
  CostAllocationFormDrawer: ({ open }: { open: boolean }) => (open ? <div data-testid="create-drawer-open" /> : null),
}));

import { useCostAllocations, useReverseCostAllocation } from '../hooks/use-finance-cost-allocation';
vi.mock('../hooks/use-finance-cost-allocation', () => ({
  useCostAllocations: vi.fn(),
  useReverseCostAllocation: vi.fn(),
}));

import { CostAllocationTab } from './cost-allocation-tab';
import type { CostAllocation } from '../types/finance-cost-allocation';

const mockList = useCostAllocations as unknown as ReturnType<typeof vi.fn>;
const mockReverse = useReverseCostAllocation as unknown as ReturnType<typeof vi.fn>;
const reverseMutateAsync = vi.fn();

function allocation(over: Partial<CostAllocation> = {}): CostAllocation {
  return {
    id: 'ca-1',
    source_type: 'expense',
    source_id: 'exp1234-aaaa-bbbb-cccc-dddddddddddd',
    source_amount: 1000,
    method: 'fixed',
    destination_profit_center_id: 'brand-01',
    allocated_amount: 400,
    percentage: null,
    reverses_allocation_id: null,
    created_at: '2026-08-01',
    ...over,
  };
}

beforeEach(() => {
  vi.clearAllMocks();
  canRef.current = () => true;
  mockList.mockReturnValue({ data: [allocation()], isLoading: false, isError: false });
  mockReverse.mockReturnValue({ mutateAsync: reverseMutateAsync, isPending: false });
  reverseMutateAsync.mockResolvedValue(allocation({ id: 'ca-1-rev' }));
});

describe('CostAllocationTab', () => {
  it('renders an allocation with its method badge and the destination shown verbatim', () => {
    render(<CostAllocationTab />);
    expect(screen.getByText('costAllocation.method.fixed')).toBeInTheDocument();
    expect(screen.getByText('EGP 400')).toBeInTheDocument();
    // No Brand directory exists — the destination is the raw reference id, never a looked-up name.
    expect(screen.getByText('brand-01')).toBeInTheDocument();
  });

  it('shows the allocation percentage for a percentage-method row', () => {
    mockList.mockReturnValue({
      data: [allocation({ method: 'percentage', percentage: 25, allocated_amount: 250 })],
      isLoading: false,
      isError: false,
    });
    render(<CostAllocationTab />);
    expect(screen.getByText('costAllocation.method.percentage')).toBeInTheDocument();
    expect(screen.getByText('25%')).toBeInTheDocument();
  });

  it('shows the empty state when there are no allocations', () => {
    mockList.mockReturnValue({ data: [], isLoading: false, isError: false });
    render(<CostAllocationTab />);
    expect(screen.getByText('costAllocation.empty')).toBeInTheDocument();
  });

  it('hides the whole tab behind NoAccess without finance.cost_allocation.view', () => {
    canRef.current = (perm) => perm !== 'finance.cost_allocation.view';
    render(<CostAllocationTab />);
    expect(screen.queryByTestId('allocation-row')).not.toBeInTheDocument();
    expect(screen.queryByText('costAllocation.action.new')).not.toBeInTheDocument();
  });

  it('shows New Allocation only when the viewer can manage cost allocations', () => {
    canRef.current = (perm) => perm !== 'finance.cost_allocation.manage';
    render(<CostAllocationTab />);
    expect(screen.queryByRole('button', { name: 'costAllocation.action.new' })).not.toBeInTheDocument();
  });

  it('opens the create drawer from New Allocation', async () => {
    const user = userEvent.setup();
    render(<CostAllocationTab />);
    await user.click(screen.getByRole('button', { name: 'costAllocation.action.new' }));
    expect(screen.getByTestId('create-drawer-open')).toBeInTheDocument();
  });

  it('never offers Reverse on a row that is itself a reversal', () => {
    mockList.mockReturnValue({
      data: [allocation({ id: 'ca-2', reverses_allocation_id: 'ca-1' })],
      isLoading: false,
      isError: false,
    });
    render(<CostAllocationTab />);
    expect(screen.queryByRole('button', { name: 'gl.actions.reverse' })).not.toBeInTheDocument();
    // The reversal shows what it reverses (shortened id ref) instead of a dash.
    expect(screen.getByText('ca-1…')).toBeInTheDocument();
  });

  it('never offers Reverse a second time on a row that has already been reversed', () => {
    mockList.mockReturnValue({
      data: [
        allocation({ id: 'ca-1' }),
        allocation({ id: 'ca-1-rev', reverses_allocation_id: 'ca-1', allocated_amount: -400 }),
      ],
      isLoading: false,
      isError: false,
    });
    render(<CostAllocationTab />);
    // ca-1 is already reversed by ca-1-rev, and ca-1-rev is itself a reversal — neither qualifies.
    expect(screen.queryByRole('button', { name: 'gl.actions.reverse' })).not.toBeInTheDocument();
  });

  it('keeps Confirm Reversal disabled until a reason is entered, then submits it', async () => {
    const user = userEvent.setup();
    render(<CostAllocationTab />);

    await user.click(screen.getByRole('button', { name: 'gl.actions.reverse' }));
    expect(screen.getByText('costAllocation.reverse.title')).toBeInTheDocument();

    const confirmButton = screen.getByRole('button', { name: 'gl.actions.confirmReverse' });
    expect(confirmButton).toBeDisabled();

    await user.type(screen.getByPlaceholderText('costAllocation.reverse.reasonPlaceholder'), 'Wrong brand');
    expect(confirmButton).not.toBeDisabled();

    await user.click(confirmButton);

    await waitFor(() =>
      expect(reverseMutateAsync).toHaveBeenCalledWith({ uuid: 'ca-1', reason: 'Wrong brand' }),
    );
    // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- i18n key asserted on the toast call, not rendered UI copy
    expect(toastSpy).toHaveBeenCalledWith(expect.objectContaining({ title: 'costAllocation.toast.reversed' }));
    // The dialog closes on success.
    await waitFor(() => expect(screen.queryByText('costAllocation.reverse.title')).not.toBeInTheDocument());
  });

  it('shows a destructive toast and keeps the dialog open when the reversal fails', async () => {
    // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- mocked API error message fixture, not rendered UI copy
    reverseMutateAsync.mockRejectedValueOnce({ response: { data: { message: 'Already reversed.' } } });
    const user = userEvent.setup();
    render(<CostAllocationTab />);

    await user.click(screen.getByRole('button', { name: 'gl.actions.reverse' }));
    await user.type(screen.getByPlaceholderText('costAllocation.reverse.reasonPlaceholder'), 'Wrong brand');
    await user.click(screen.getByRole('button', { name: 'gl.actions.confirmReverse' }));

    await waitFor(() =>
      expect(toastSpy).toHaveBeenCalledWith(
        expect.objectContaining({
          // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- i18n key asserted on the toast call, not rendered UI copy
          title: 'costAllocation.reverse.failed',
          // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- mocked API error message fixture, not rendered UI copy
          description: 'Already reversed.',
          variant: 'destructive',
        }),
      ),
    );
    expect(screen.getByText('costAllocation.reverse.title')).toBeInTheDocument();
  });
});

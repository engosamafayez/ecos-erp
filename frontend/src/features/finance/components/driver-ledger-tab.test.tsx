/**
 * Driver Ledger tab (Costing & Profitability). Pins the properties the brief calls out
 * explicitly:
 *   1. Strictly read-only — there is no create/edit affordance anywhere in this tab.
 *   2. `driverId` is an opaque, typed/pasted reference (no directory/picker) fetched on demand
 *      only after "Load" — mirroring useSupplierLedger's lazy-query pattern.
 *   3. The sign of `balance`/`amount` is given an explicit meaning in the UI, not just a bare
 *      colored number.
 *
 * The i18n mock resolves selector-mode t($ => $.a.b.c) calls to the dotted path string rather
 * than against the real finance.json bundle, because driverLedger.* is delivered as a fragment
 * for a separate merge and does not exist in the real bundle yet (see
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
            <tr key={rowId(row)} data-testid="ledger-row">
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

const { canRef } = vi.hoisted(() => ({ canRef: { current: (_perm: string): boolean => true } }));
vi.mock('@/features/authorization', () => ({
  usePermission: () => ({ can: (perm: string) => canRef.current(perm) }),
}));

import { useDriverLedger } from '../hooks/use-finance-driver-ledger';
vi.mock('../hooks/use-finance-driver-ledger', () => ({
  useDriverLedger: vi.fn(),
  useDriverBalance: vi.fn(),
}));

import { DriverLedgerTab } from './driver-ledger-tab';
import type { DriverLedgerEntry } from '../types/finance-driver-ledger';

const mockLedger = useDriverLedger as unknown as ReturnType<typeof vi.fn>;

function entry(over: Partial<DriverLedgerEntry> = {}): DriverLedgerEntry {
  return {
    id: 'e-1',
    entry_date: '2026-08-01',
    entry_type: 'advance',
    amount: 500,
    running_balance: 500,
    source_type: null,
    source_id: null,
    journal_entry_id: null,
    description: null,
    ...over,
  };
}

async function loadDriver(user: ReturnType<typeof userEvent.setup>, id = 'drv-1') {
  await user.type(screen.getByLabelText('driverLedger.field.driverId'), id);
  await user.click(screen.getByRole('button', { name: 'driverLedger.lookup.load' }));
}

beforeEach(() => {
  vi.clearAllMocks();
  canRef.current = () => true;
  mockLedger.mockReturnValue({ data: undefined, isLoading: false, isError: false });
});

describe('DriverLedgerTab', () => {
  it('hides the tab behind NoAccess without finance.driver.view', () => {
    canRef.current = () => false;
    render(<DriverLedgerTab />);
    expect(screen.queryByLabelText('driverLedger.field.driverId')).not.toBeInTheDocument();
  });

  it('keeps Load disabled until an id is typed, and fetches nothing before it is clicked', () => {
    render(<DriverLedgerTab />);
    expect(screen.getByRole('button', { name: 'driverLedger.lookup.load' })).toBeDisabled();
    expect(screen.queryByTestId('ledger-row')).not.toBeInTheDocument();
    expect(screen.queryByText('driverLedger.balance')).not.toBeInTheDocument();
  });

  it('loads the ledger only for the id that was entered', async () => {
    const user = userEvent.setup();
    mockLedger.mockReturnValue({
      data: { driver_id: 'drv-1', balance: 500, entries: [entry()] },
      isLoading: false,
      isError: false,
    });
    render(<DriverLedgerTab />);

    await loadDriver(user, 'drv-1');

    // useDriverLedger is called on every render; the LAST call must carry the committed id.
    await waitFor(() => expect(mockLedger).toHaveBeenLastCalledWith('drv-1'));
    expect(screen.getByTestId('ledger-row')).toBeInTheDocument();
  });

  it('explains what a positive balance means, not just a bare number', async () => {
    const user = userEvent.setup();
    mockLedger.mockReturnValue({ data: { driver_id: 'drv-1', balance: 500, entries: [] }, isLoading: false, isError: false });
    render(<DriverLedgerTab />);
    await loadDriver(user);

    expect(screen.getByText('EGP 500')).toBeInTheDocument();
    expect(screen.getByText('driverLedger.balanceMeaning.owesCompany')).toBeInTheDocument();
  });

  it('explains a negative balance as owed BY the company, not the driver', async () => {
    const user = userEvent.setup();
    mockLedger.mockReturnValue({ data: { driver_id: 'drv-1', balance: -200, entries: [] }, isLoading: false, isError: false });
    render(<DriverLedgerTab />);
    await loadDriver(user);

    expect(screen.getByText('driverLedger.balanceMeaning.owedByCompany')).toBeInTheDocument();
  });

  it('explains a zero balance as settled', async () => {
    const user = userEvent.setup();
    mockLedger.mockReturnValue({ data: { driver_id: 'drv-1', balance: 0, entries: [] }, isLoading: false, isError: false });
    render(<DriverLedgerTab />);
    await loadDriver(user);

    expect(screen.getByText('driverLedger.balanceMeaning.settled')).toBeInTheDocument();
  });

  it('highlights an advance/shortage (positive amount) distinctly from an expense/settlement (negative amount)', async () => {
    const user = userEvent.setup();
    mockLedger.mockReturnValue({
      data: {
        driver_id: 'drv-1',
        balance: 450,
        entries: [
          // amount and running_balance are deliberately all-distinct numbers so each
          // getByText below is unambiguous.
          entry({ id: 'e-1', entry_type: 'advance', amount: 500, running_balance: 650 }),
          entry({ id: 'e-2', entry_type: 'settlement', amount: -200, running_balance: 450 }),
        ],
      },
      isLoading: false,
      isError: false,
    });
    render(<DriverLedgerTab />);
    await loadDriver(user);

    expect(screen.getByText('EGP 500')).toHaveClass('text-red-600');
    expect(screen.getByText('EGP -200')).toHaveClass('text-emerald-600');
  });

  it('never renders a create/edit action anywhere (strictly read-only)', async () => {
    const user = userEvent.setup();
    mockLedger.mockReturnValue({
      data: { driver_id: 'drv-1', balance: 500, entries: [entry()] },
      isLoading: false,
      isError: false,
    });
    render(<DriverLedgerTab />);
    await loadDriver(user);

    expect(screen.queryByRole('button', { name: /new|create|edit|add entry/i })).not.toBeInTheDocument();
  });

  it('shows the empty state when the driver has no entries', async () => {
    const user = userEvent.setup();
    mockLedger.mockReturnValue({ data: { driver_id: 'drv-1', balance: 0, entries: [] }, isLoading: false, isError: false });
    render(<DriverLedgerTab />);
    await loadDriver(user);

    expect(screen.getByText('driverLedger.empty')).toBeInTheDocument();
  });
});

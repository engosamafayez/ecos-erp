import '@testing-library/jest-dom/vitest';
import type { ReactNode } from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

// ── Shared mocks ─────────────────────────────────────────────────────────────
// Selector-mode i18n: resolve `t($ => $.a.b.c)` to the dotted path string so
// assertions are stable without booting the real i18next resources.
function pathProxy(path: string): unknown {
  const target = () => path;
  return new Proxy(target, {
    get(_t, prop) {
      if (prop === Symbol.toPrimitive || prop === 'toString' || prop === 'valueOf') {
        return () => path;
      }
      const next = path ? `${path}.${String(prop)}` : String(prop);
      return pathProxy(next);
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
    money: (v: number) => `EGP ${v}`,
    currency: 'EGP',
    number: (v: number) => String(v),
    percent: (v: number) => `${v}%`,
  }),
}));
const navigate = vi.fn();
vi.mock('react-router-dom', () => ({ useNavigate: () => navigate }));
// Keep the grid honest but light: render each column's cell so column defs are exercised.
vi.mock('@/components/data-grid/universal-data-grid', () => ({
  UniversalDataGrid: ({
    data,
    columns,
    emptyState,
    rowId,
  }: {
    data: unknown[];
    columns: { key: string; cell: (r: unknown) => ReactNode }[];
    emptyState: ReactNode;
    rowId: (r: unknown) => string;
  }) =>
    data.length === 0 ? (
      emptyState
    ) : (
      <table>
        <tbody>
          {data.map((r) => (
            <tr key={rowId(r)} data-testid="driver-row">
              {columns.map((c) => (
                <td key={c.key}>{c.cell(r)}</td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    ),
}));
vi.mock('@/components/data-grid/smart-toolbar', () => ({ SmartToolbar: () => <div data-testid="toolbar" /> }));
// Radix Tabs uses roving tabindex + pointer capture, so its triggers don't activate under
// fireEvent.click in jsdom. Swap in a minimal accessible equivalent whose trigger drives
// onValueChange, so scope switching (Active ↔ History) is deterministic.
vi.mock('@/components/ui/tabs', async () => {
  const React = await import('react');
  const Ctx = React.createContext<(v: string) => void>(() => {});
  return {
    Tabs: ({ value, onValueChange, children }: { value: string; onValueChange: (v: string) => void; children: ReactNode }) =>
      React.createElement(Ctx.Provider, { value: onValueChange }, React.createElement('div', { 'data-value': value }, children)),
    TabsList: ({ children }: { children: ReactNode }) => React.createElement('div', { role: 'tablist' }, children),
    TabsTrigger: ({ value, children }: { value: string; children: ReactNode }) => {
      const onValueChange = React.useContext(Ctx);
      return React.createElement('button', { type: 'button', role: 'tab', onClick: () => onValueChange(value) }, children);
    },
  };
});
// Radix Select depends on pointer-capture APIs jsdom lacks. Swap in a faithful native <select>
// so a preset change is drivable and we can assert the resulting canonical server request.
vi.mock('@/components/ui/select', () => ({
  Select: ({ value, onValueChange, children }: { value: string; onValueChange: (v: string) => void; children: ReactNode }) => (
    <select data-testid="preset-select" value={value} onChange={(e) => onValueChange(e.target.value)}>
      {children}
    </select>
  ),
  SelectTrigger: () => null,
  SelectValue: () => null,
  SelectContent: ({ children }: { children: ReactNode }) => <>{children}</>,
  SelectItem: ({ value, children }: { value: string; children: ReactNode }) => <option value={value}>{children}</option>,
}));

import { useDriverSettlementBoard } from '../hooks/use-driver-settlement';
vi.mock('../hooks/use-driver-settlement', () => ({ useDriverSettlementBoard: vi.fn() }));

import { DriverSettlementWorkspacePage } from './driver-settlement-workspace-page';
import type { DaySettlementDriverRow } from '../types/driver-settlement';

const mockBoard = useDriverSettlementBoard as unknown as ReturnType<typeof vi.fn>;

const DRIVER: DaySettlementDriverRow = {
  assignment_id: 12,
  operational_date: '2026-08-24',
  trip_id: 'trip-12',
  trip_number: 'TRP-012',
  trip_status: 'in_progress',
  custody_started_at: '2026-08-24T09:00:00Z',
  duplicate_open_custody: false,
  finalized_at: null,
  driver_id: 7,
  driver_name: 'Ahmed Samir',
  vehicle_id: 3,
  vehicle_plate: 'ABC-123',
  trip_ids: ['t-1'],
  orders: 18,
  delivered: 17,
  partial: 0,
  failed: 1,
  delivery_pct: 94,
  returns: 1,
  cash_expected: 4850,
  transfers: 1200,
  orders_value: 18500,
  delivered_value: 13200,
  failed_value: 4100,
  total_sales: 13200,
  transfers_paid: 1500,
  difference: 0,
  damaged_qty: 0,
  shortage_qty: 0,
  goods_on_hand: 3,
  cash_collected: 0,
  expenses: 0,
  cash_in: 0,
  net_cash: 0,
  pending_movements: 0,
  reconciliation_status: null,
  settlement_status: 'under_review',
  closing_stage: 'ready_for_closing',
};

let refetchMock = vi.fn();

function withData(over: Partial<{ drivers: DaySettlementDriverRow[]; loading: boolean; error: boolean }> = {}) {
  refetchMock = vi.fn();
  // A failed OR still-loading read has no board payload yet — mirrors react-query, and lets the
  // page prove it never treats an absent read as a successful (zero) one.
  const pending = Boolean(over.error) || Boolean(over.loading);
  mockBoard.mockReturnValue({
    data: pending
      ? undefined
      : {
          scope: 'active',
          // Distinct from the DRIVER row's own cell values so KPI-strip text does not collide with
          // column-cell text (both render in this suite).
          kpis: {
            total_orders: 99,
            total_delivered: 50,
            total_failed: 49,
            delivery_rate: 50,
            total_sales: 77777,
            total_transfers_paid: 88888,
            total_expenses: null,
            net_cash: null,
          },
          drivers: over.drivers ?? [DRIVER],
        },
    isLoading: over.loading ?? false,
    isFetching: false,
    isError: over.error ?? false,
    refetch: refetchMock,
  });
}

/** a precedes b in document order. */
function isBefore(a: Element, b: Element): boolean {
  return Boolean(a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING);
}

/** The params of the most recent board request. */
function lastBoardArg(): Record<string, unknown> {
  const calls = mockBoard.mock.calls;
  return calls[calls.length - 1][0] as Record<string, unknown>;
}

describe('DriverSettlementWorkspacePage', () => {
  it('renders a driver row centred on Driver/Trip with order and financial totals (no vehicle/cash columns)', () => {
    withData();
    render(<DriverSettlementWorkspacePage />);
    expect(screen.getByText('Ahmed Samir')).toBeInTheDocument();
    expect(screen.getByText('TRP-012')).toBeInTheDocument(); // Trip reference, not vehicle
    expect(screen.queryByText('ABC-123')).not.toBeInTheDocument(); // vehicle column removed (§1)
    expect(screen.getByText('94%')).toBeInTheDocument(); // delivery rate — percentage only, no fraction
    expect(screen.getByText('EGP 18500')).toBeInTheDocument(); // total orders value
    expect(screen.getByText('EGP 1500')).toBeInTheDocument(); // transfers / paid
    expect(screen.getByTestId('driver-row')).toBeInTheDocument();
  });

  it('renders the canonical KPI strip when the read succeeds', () => {
    withData();
    render(<DriverSettlementWorkspacePage />);
    expect(screen.getByText('driverSettlement.kpis.totalOrders')).toBeInTheDocument();
    expect(screen.getByText('driverSettlement.kpis.netCash')).toBeInTheDocument();
    // Loaded — neither the loading skeleton nor the error placeholder.
    expect(screen.queryByTestId('kpi-loading')).not.toBeInTheDocument();
    expect(screen.queryByTestId('kpi-error')).not.toBeInTheDocument();
  });

  it('shows the empty state when no drivers have open custody (a successful zero-row read)', () => {
    withData({ drivers: [] });
    render(<DriverSettlementWorkspacePage />);
    expect(screen.getByText('driverSettlement.empty')).toBeInTheDocument();
    expect(screen.queryByTestId('driver-row')).not.toBeInTheDocument();
    // Empty is distinct from Error: the read succeeded, so KPIs are the real strip, not the error state.
    expect(screen.queryByTestId('kpi-error')).not.toBeInTheDocument();
  });

  it('renders a distinct KPI error state on a failed read — never an indefinite skeleton or false zeros (§2)', () => {
    withData({ error: true });
    render(<DriverSettlementWorkspacePage />);
    // Error placeholder is shown…
    expect(screen.getByTestId('kpi-error')).toBeInTheDocument();
    // …NOT the loading skeleton (the reported "stuck skeleton" symptom)…
    expect(screen.queryByTestId('kpi-loading')).not.toBeInTheDocument();
    // …and NOT the loaded strip masquerading as a successful (all-zero) read.
    expect(screen.queryByText('driverSettlement.kpis.totalOrders')).not.toBeInTheDocument();
  });

  it('surfaces the error message and retries through the existing read flow (§2)', () => {
    withData({ error: true });
    render(<DriverSettlementWorkspacePage />);
    expect(screen.getByText('driverSettlement.loadError')).toBeInTheDocument();
    fireEvent.click(screen.getByText('driverSettlement.retry'));
    expect(refetchMock).toHaveBeenCalledTimes(1);
  });

  it('shows the loading skeleton (not the error state) while the read is in flight', () => {
    withData({ loading: true });
    render(<DriverSettlementWorkspacePage />);
    expect(screen.getByText('driverSettlement.loading')).toBeInTheDocument();
    expect(screen.getByTestId('kpi-loading')).toBeInTheDocument();
    expect(screen.queryByTestId('kpi-error')).not.toBeInTheDocument();
  });

  it('Active exposes no historical date filter, and Search sits above the KPIs (§6)', () => {
    withData();
    render(<DriverSettlementWorkspacePage />);
    // Active is the default scope — no preset / date-range control.
    expect(screen.queryByTestId('preset-select')).not.toBeInTheDocument();
    expect(lastBoardArg()).toMatchObject({ scope: 'active' });
    // Order: Search → KPIs.
    const search = screen.getByPlaceholderText('driverSettlement.searchPlaceholder');
    const kpi = screen.getByText('driverSettlement.kpis.totalOrders');
    expect(isBefore(search, kpi)).toBe(true);
  });

  it('History places the date filter above Search, and both above the KPI strip (§5)', () => {
    withData();
    render(<DriverSettlementWorkspacePage />);
    fireEvent.click(screen.getByText('driverSettlement.tabsHistory'));

    const preset = screen.getByTestId('preset-select');
    const search = screen.getByPlaceholderText('driverSettlement.searchPlaceholder');
    const kpi = screen.getByText('driverSettlement.kpis.totalOrders');

    // Expected order (§5): Date Preset → Search → KPIs.
    expect(isBefore(preset, search)).toBe(true);
    expect(isBefore(search, kpi)).toBe(true);
  });

  it('a History date-preset change issues a canonical server request with the new range (§8/§10)', () => {
    withData();
    render(<DriverSettlementWorkspacePage />);
    fireEvent.click(screen.getByText('driverSettlement.tabsHistory'));

    const before = lastBoardArg();
    expect(before).toMatchObject({ scope: 'history' });
    // The date window is sent to the server (server-side), not applied in React.
    expect(before.from).toBeTruthy();
    expect(before.to).toBeTruthy();

    fireEvent.change(screen.getByTestId('preset-select'), { target: { value: 'previous_month' } });

    const after = lastBoardArg();
    expect(after).toMatchObject({ scope: 'history', page: 1 });
    // A different preset produces a different server range — proving the preset drives the request,
    // not a client-side filter over an already-fetched page.
    expect(after.from).not.toBe(before.from);
  });
});

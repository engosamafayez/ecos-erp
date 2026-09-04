import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi, beforeEach } from 'vitest';

/**
 * CashFlowTab tests. Same conventions as profitability-tab.test.tsx:
 * react-i18next mocked in selector mode, use-finance-intelligence.ts's hooks
 * mocked directly.
 *
 * current/forecast are asserted as genuinely independent views: `current`
 * never receives a horizon (it takes no params at all), and switching the
 * horizon control only affects `forecast` — this file does not assert the
 * exact query args (that lives with the hook, not the component), only that
 * each view renders its own endpoint's shape correctly.
 */

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (sel: unknown) => {
      if (typeof sel !== 'function') return String(sel);
      const path: string[] = [];
      const proxy: unknown = new Proxy({}, {
        get: (_t, prop: string) => { path.push(prop); return proxy; },
      });
      (sel as (p: unknown) => unknown)(proxy);
      return path[path.length - 1] ?? '';
    },
  }),
}));

vi.mock('@/hooks/use-formatter', () => ({
  useFormatter: () => ({
    money: (v: number | null | undefined) => (v == null ? '—' : `$${v}`),
    percent: (v: number | null | undefined) => (v == null ? '—' : `${v}%`),
    number: (v: number) => String(v),
    date: (v: string) => String(v),
  }),
}));

const mockCan = vi.hoisted(() => vi.fn());
vi.mock('@/features/authorization', () => ({
  usePermission: () => ({ can: mockCan }),
}));

const mockCurrent = vi.hoisted(() => vi.fn());
const mockForecast = vi.hoisted(() => vi.fn());

vi.mock('../hooks/use-finance-intelligence', () => ({
  useCashFlowCurrent: mockCurrent,
  useCashFlowForecast: mockForecast,
}));

import { CashFlowTab } from './cash-flow-tab';

const IDLE = { data: undefined, isLoading: false, isError: false };

beforeEach(() => {
  vi.clearAllMocks();
  mockCan.mockReturnValue(true);
  mockCurrent.mockReturnValue(IDLE);
  mockForecast.mockReturnValue(IDLE);
});

describe('CashFlowTab', () => {
  it('renders current cash position figures exactly as the server returned them', () => {
    mockCurrent.mockReturnValue({
      data: { cash_position: 50000, receivables: 12000, payables: 8000, month_to_date_operating: 3000 },
      isLoading: false,
      isError: false,
    });

    render(<CashFlowTab />);

    expect(screen.getByText('$50000')).toBeInTheDocument();
    expect(screen.getByText('$12000')).toBeInTheDocument();
    expect(screen.getByText('$8000')).toBeInTheDocument();
    expect(screen.getByText('$3000')).toBeInTheDocument();
  });

  it('shows a loading state before the current-position endpoint responds', () => {
    mockCurrent.mockReturnValue({ data: undefined, isLoading: true, isError: false });
    render(<CashFlowTab />);
    expect(screen.getByText('loading')).toBeInTheDocument();
  });

  it('shows an error state when the current-position endpoint fails', () => {
    mockCurrent.mockReturnValue({ data: undefined, isLoading: false, isError: true });
    render(<CashFlowTab />);
    expect(screen.getByText('error')).toBeInTheDocument();
  });

  it('renders the forecast liquidity projection, schedules and risk alerts', async () => {
    const user = userEvent.setup();
    mockForecast.mockReturnValue({
      data: {
        liquidity_projection: {
          opening_cash: 50000,
          months: [{ month: '2026-10', operating_flow: 1000, collections: 2000, payments: 1500, net_flow: 1500, closing_cash: 51500 }],
        },
        // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- mock server response fixture, not rendered UI copy
        receivable_forecast: { label: 'expected_collection', total: 2000, schedule: [{ month: '2026-10', amount: 2000 }], method: 'aging_bucket_schedule' },
        // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- mock server response fixture, not rendered UI copy
        payable_forecast: { label: 'expected_payment', total: 1500, schedule: [{ month: '2026-10', amount: 1500 }], method: 'aging_bucket_schedule' },
        // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- mock server response fixture, not rendered UI copy
        risk_alerts: [{ key: 'low_liquidity_cover', severity: 'warning', message: 'Cash is below outstanding payables.' }],
      },
      isLoading: false,
      isError: false,
    });

    render(<CashFlowTab />);
    await user.click(screen.getByText('forecast'));

    // Liquidity projection row.
    expect(screen.getByText('$51500')).toBeInTheDocument();
    // Both schedule cards.
    expect(screen.getAllByText('2026-10').length).toBeGreaterThan(0);
    // The risk alert's exact server message, not a paraphrase.
    expect(screen.getByText('Cash is below outstanding payables.')).toBeInTheDocument();
  });

  it('shows the risk-alerts empty state when the server sends none — distinct from an error', async () => {
    const user = userEvent.setup();
    mockForecast.mockReturnValue({
      data: {
        // months has one entry (not []) so the liquidity grid's OWN empty
        // state does not also render here — this test isolates the
        // risk-alerts empty message specifically, not the grid's.
        liquidity_projection: {
          opening_cash: 50000,
          months: [{ month: '2026-10', operating_flow: 1000, collections: 0, payments: 0, net_flow: 1000, closing_cash: 51000 }],
        },
        // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- mock server response fixture, not rendered UI copy
        receivable_forecast: { label: 'expected_collection', total: 0, schedule: [], method: 'aging_bucket_schedule' },
        // eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings -- mock server response fixture, not rendered UI copy
        payable_forecast: { label: 'expected_payment', total: 0, schedule: [], method: 'aging_bucket_schedule' },
        risk_alerts: [],
      },
      isLoading: false,
      isError: false,
    });

    render(<CashFlowTab />);
    await user.click(screen.getByText('forecast'));

    expect(screen.getByText('empty')).toBeInTheDocument(); // cashFlow.risk.empty
  });

  it('renders NoAccess without finance.analytics.view', () => {
    mockCan.mockReturnValue(false);
    mockCurrent.mockReturnValue({
      data: { cash_position: 50000, receivables: 12000, payables: 8000, month_to_date_operating: 3000 },
      isLoading: false,
      isError: false,
    });

    render(<CashFlowTab />);

    expect(screen.getByText('noAccess')).toBeInTheDocument();
    expect(screen.queryByText('$50000')).not.toBeInTheDocument();
  });
});

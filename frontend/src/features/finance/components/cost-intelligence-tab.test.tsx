import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi, beforeEach } from 'vitest';

/**
 * CostIntelligenceTab tests. Same conventions as profitability-tab.test.tsx:
 * react-i18next mocked in selector mode, use-finance-intelligence.ts's hooks
 * mocked directly.
 *
 * The trend case is the load-bearing test here: CostIntelligenceService::
 * trend() returns a plain {month,value}[] series — a different, simpler
 * envelope than GET /finance/intelligence/trends (no last/change_pct/
 * direction/explanation) — so this asserts the tab renders THAT shape, not
 * the richer executive-trends one.
 */

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (sel: unknown, _opts?: unknown) => {
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

const mockBreakdown = vi.hoisted(() => vi.fn());
const mockOperational = vi.hoisted(() => vi.fn());
const mockTrend = vi.hoisted(() => vi.fn());

vi.mock('../hooks/use-finance-intelligence', () => ({
  useCostBreakdown: mockBreakdown,
  useCostOperational: mockOperational,
  useCostTrend: mockTrend,
}));

import { CostIntelligenceTab } from './cost-intelligence-tab';

const IDLE = { data: undefined, isLoading: false, isError: false };

beforeEach(() => {
  vi.clearAllMocks();
  mockCan.mockReturnValue(true);
  mockBreakdown.mockReturnValue(IDLE);
  mockOperational.mockReturnValue(IDLE);
  mockTrend.mockReturnValue(IDLE);
});

describe('CostIntelligenceTab', () => {
  it('renders breakdown totals and per-account rows exactly as the server returned them', () => {
    mockBreakdown.mockReturnValue({
      data: {
        total_cost: 9000,
        by_category: { cost_of_sales: 5000, operating_expense: 3500, other_expense: 500 },
        by_account: [{ account_id: 1, code: '5100', name: 'Freight Out', amount: 1200 }],
      },
      isLoading: false,
      isError: false,
    });

    render(<CostIntelligenceTab />);

    expect(screen.getByText('$9000')).toBeInTheDocument();
    expect(screen.getByText('5100')).toBeInTheDocument();
    expect(screen.getByText('Freight Out')).toBeInTheDocument();
    expect(screen.getByText('$1200')).toBeInTheDocument();
  });

  it('shows a loading state before the breakdown endpoint responds', () => {
    mockBreakdown.mockReturnValue({ data: undefined, isLoading: true, isError: false });
    render(<CostIntelligenceTab />);
    expect(screen.getByText('loading')).toBeInTheDocument();
  });

  it('renders the operational buckets and the deterministic classification rules', async () => {
    const user = userEvent.setup();
    mockOperational.mockReturnValue({
      data: {
        buckets: { manufacturing: 100, logistics: 200, marketing: 50, administrative: 400, other: 10 },
        rules: { manufacturing: ['factory'], logistics: ['freight'], marketing: ['campaign'], administrative: ['payroll'] },
        method: 'deterministic_keyword_classification',
      },
      isLoading: false,
      isError: false,
    });

    render(<CostIntelligenceTab />);
    await user.click(screen.getByText('operational'));

    expect(screen.getByText('$200')).toBeInTheDocument(); // logistics bucket amount
    // The keyword rule behind the split is rendered — explainable, not a black box.
    expect(screen.getByText(/factory/)).toBeInTheDocument();
    expect(screen.getByText(/freight/)).toBeInTheDocument();
  });

  it('renders the cost trend as a plain {month,value} series, not the richer executive-trend shape', async () => {
    const user = userEvent.setup();
    mockTrend.mockReturnValue({
      data: { series: [{ month: '2026-07', value: 1000 }, { month: '2026-08', value: 1500 }] },
      isLoading: false,
      isError: false,
    });

    render(<CostIntelligenceTab />);
    await user.click(screen.getByText('trend'));

    expect(screen.getByText('2026-07')).toBeInTheDocument();
    expect(screen.getByText('2026-08')).toBeInTheDocument();
    expect(screen.getByText('$1000')).toBeInTheDocument();
    expect(screen.getByText('$1500')).toBeInTheDocument();
    // One sparkline path is drawn from exactly the two points supplied.
    expect(document.querySelectorAll('svg[role="img"] path')).toHaveLength(1);
  });

  it('renders NoAccess without finance.analytics.view', () => {
    mockCan.mockReturnValue(false);
    mockBreakdown.mockReturnValue({
      data: { total_cost: 9000, by_category: { cost_of_sales: 5000, operating_expense: 3500, other_expense: 500 }, by_account: [] },
      isLoading: false,
      isError: false,
    });

    render(<CostIntelligenceTab />);

    expect(screen.getByText('noAccess')).toBeInTheDocument();
    expect(screen.queryByText('$9000')).not.toBeInTheDocument();
  });
});
